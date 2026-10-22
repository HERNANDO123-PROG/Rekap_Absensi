<?php

namespace Tests\Feature;

use App\Enums\AttendanceStatus;
use App\Enums\EmployeeStatus;
use App\Enums\WorkCalendarType;
use App\Models\AttendanceRecord;
use App\Models\Employee;
use App\Models\Unit;
use App\Models\User;
use App\Models\WorkCalendar;
use App\Models\WorkSchedule;
use App\Services\Calendar\CalendarResolver;
use App\Services\WorkCalendarService;
use App\Services\WorkScheduleService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class WorkCalendarManagementTest extends TestCase
{
    use RefreshDatabase;

    private const DATE = '2026-08-17';

    public function test_weekly_schedules_can_be_created_and_are_scoped_to_tk_and_sd(): void
    {
        $tk = $this->createUnit('TK');
        $sd = $this->createUnit('SD');
        $service = app(WorkScheduleService::class);
        $tkSchedule = $service->create($this->scheduleData($tk, self::DATE));
        $sdSchedule = $service->create($this->scheduleData($sd, self::DATE, true, '08:00', '14:00'));

        $this->assertTrue($tkSchedule->unit->is($tk));
        $this->assertTrue($sdSchedule->unit->is($sd));
        $this->assertSame('07:00', $tkSchedule->start_time);
        $this->assertSame('08:00', $sdSchedule->start_time);
        $this->assertCount(1, $service->list(['unit_id' => $tk->id]));
        $this->assertCount(1, $service->list(['unit_id' => $sd->id]));
    }

    public function test_work_schedule_validates_required_and_non_working_times(): void
    {
        $unit = $this->createUnit('TK');
        $service = app(WorkScheduleService::class);

        $this->assertDatabaseScheduleValidation($service, $this->scheduleData($unit, self::DATE, true, null, null), 'start_time');

        $nonWorking = $service->create($this->scheduleData($unit, self::DATE, false, null, null));
        $this->assertFalse($nonWorking->is_working_day);
        $this->assertNull($nonWorking->start_time);
        $this->assertNull($nonWorking->end_time);
    }

    public function test_invalid_times_equal_times_and_overnight_schedule_are_rejected(): void
    {
        $unit = $this->createUnit('SD');
        $service = app(WorkScheduleService::class);

        foreach ([
            ['start_time' => '7:00', 'end_time' => '15:00', 'field' => 'start_time'],
            ['start_time' => '07:00', 'end_time' => '07:00', 'field' => 'end_time'],
            ['start_time' => '22:00', 'end_time' => '06:00', 'field' => 'end_time'],
        ] as $case) {
            $data = $this->scheduleData($unit, self::DATE);
            $data['start_time'] = $case['start_time'];
            $data['end_time'] = $case['end_time'];
            $this->assertDatabaseScheduleValidation($service, $data, $case['field']);
        }
    }

    public function test_duplicate_unit_day_schedule_is_rejected_but_other_unit_is_allowed(): void
    {
        $tk = $this->createUnit('TK');
        $sd = $this->createUnit('SD');
        $service = app(WorkScheduleService::class);
        $service->create($this->scheduleData($tk, self::DATE));
        $otherUnitSchedule = $service->create($this->scheduleData($sd, self::DATE));

        $this->assertSame($sd->id, $otherUnitSchedule->unit_id);
        $this->assertDatabaseScheduleValidation($service, $this->scheduleData($tk, self::DATE), 'day_of_week');
        $this->assertDatabaseCount('work_schedules', 2);
    }

    public function test_schedule_update_and_delete_are_audited_without_removing_derived_attendance(): void
    {
        $unit = $this->createUnit('TK');
        $employee = Employee::create([
            'unit_id' => $unit->id,
            'nama_lengkap' => 'Calendar employee',
            'status' => EmployeeStatus::Active,
        ]);
        $record = AttendanceRecord::create([
            'employee_id' => $employee->id,
            'unit_id' => $unit->id,
            'attendance_date' => self::DATE,
            'status' => AttendanceStatus::Present,
        ]);
        $user = User::factory()->create();
        $service = app(WorkScheduleService::class);
        $schedule = $service->create($this->scheduleData($unit, self::DATE), $user->id);
        $updated = $service->update($schedule, $this->scheduleData($unit, self::DATE, true, '07:30', '12:00'), $user->id);
        $affected = $service->delete($updated, $user->id);

        $this->assertSame('07:30', substr((string) $updated->start_time, 0, 5));
        $this->assertSame($unit->id, $affected['affected_unit_id']);
        $this->assertSame(0, WorkSchedule::query()->count());
        $this->assertDatabaseHas('attendance_records', ['id' => $record->id]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'work_schedule.created']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'work_schedule.updated']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'work_schedule.deleted']);
    }

    public function test_holiday_calendar_is_non_working_unique_and_updatable(): void
    {
        $unit = $this->createUnit('TK');
        $otherUnit = $this->createUnit('SD');
        $user = User::factory()->create();
        $service = app(WorkCalendarService::class);
        $data = $this->calendarData($unit, self::DATE, WorkCalendarType::Holiday, false, null, null);
        $data['title'] = 'Hari Libur';
        $holiday = $service->create($data, $user->id);
        $sameDateOtherUnit = $service->create($this->calendarData($otherUnit, self::DATE, WorkCalendarType::Holiday, false));

        $this->assertFalse($holiday->is_working_day);
        $this->assertSame($unit->id, $holiday->unit_id);
        $this->assertSame($otherUnit->id, $sameDateOtherUnit->unit_id);
        $this->assertDatabaseCalendarValidation(
            $service,
            $this->calendarData($unit, self::DATE, WorkCalendarType::Holiday, false),
            'date',
        );

        $updated = $service->update($holiday, [
            ...$this->calendarData($unit, self::DATE, WorkCalendarType::Holiday, false),
            'title' => 'Hari Libur Updated',
        ], $user->id);
        $this->assertSame('Hari Libur Updated', $updated->title);
        $this->assertDatabaseHas('audit_logs', ['action' => 'work_calendar.created']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'work_calendar.updated']);
    }

    public function test_special_workday_and_special_event_support_explicit_schedule_values(): void
    {
        $unit = $this->createUnit('SD');
        $service = app(WorkCalendarService::class);
        $specialWorkday = $service->create($this->calendarData(
            $unit,
            self::DATE,
            WorkCalendarType::SpecialWorkday,
            true,
            '08:00',
            '12:00',
        ));
        $specialEvent = $service->create($this->calendarData(
            $unit,
            '2026-08-18',
            WorkCalendarType::SpecialEvent,
            true,
            '09:00',
            '10:00',
        ));

        $this->assertSame(WorkCalendarType::SpecialWorkday, $specialWorkday->type);
        $this->assertSame(WorkCalendarType::SpecialEvent, $specialEvent->type);

        $this->assertDatabaseCalendarValidation(
            $service,
            $this->calendarData($unit, '2026-08-19', WorkCalendarType::SpecialWorkday, false),
            'is_working_day',
        );
    }

    public function test_calendar_validation_rejects_holiday_workday_missing_hours_invalid_and_overnight(): void
    {
        $unit = $this->createUnit('TK');
        $service = app(WorkCalendarService::class);

        $this->assertDatabaseCalendarValidation(
            $service,
            $this->calendarData($unit, self::DATE, WorkCalendarType::Holiday, true, '07:00', '15:00'),
            'is_working_day',
        );
        $this->assertDatabaseCalendarValidation(
            $service,
            $this->calendarData($unit, self::DATE, WorkCalendarType::SpecialEvent, true, null, null),
            'start_time',
        );
        $this->assertDatabaseCalendarValidation(
            $service,
            $this->calendarData($unit, self::DATE, WorkCalendarType::SpecialEvent, true, '22:00', '06:00'),
            'end_time',
        );

        $invalid = $this->calendarData($unit, self::DATE, WorkCalendarType::SpecialEvent, true, '7:00', '15:00');
        $this->assertDatabaseCalendarValidation($service, $invalid, 'start_time');
    }

    public function test_calendar_bulk_upsert_creates_then_updates_without_duplicates(): void
    {
        $unit = $this->createUnit('TK');
        $user = User::factory()->create();
        $service = app(WorkCalendarService::class);
        $bulk = [
            'unit_id' => $unit->id,
            'date_from' => '2026-08-01',
            'date_to' => '2026-08-03',
            'type' => WorkCalendarType::Holiday->value,
            'title' => 'Holiday range',
            'is_working_day' => false,
            'start_time' => null,
            'end_time' => null,
        ];

        $created = $service->bulkUpsert($bulk, $user->id);
        $updated = $service->bulkUpsert([...$bulk, 'title' => 'Updated holiday range'], $user->id);

        $this->assertSame(3, $created['created']);
        $this->assertSame(3, $updated['updated']);
        $this->assertSame(0, $updated['created']);
        $this->assertSame(3, WorkCalendar::query()->count());
        $this->assertSame('Updated holiday range', WorkCalendar::query()->first()->title);
        $this->assertDatabaseHas('audit_logs', ['action' => 'work_calendar.bulk_updated']);
    }

    public function test_calendar_upsert_uses_unit_date_key_and_delete_does_not_remove_attendance(): void
    {
        $unit = $this->createUnit('TK');
        $calendarService = app(WorkCalendarService::class);
        $first = $calendarService->upsert($this->calendarData($unit, self::DATE, WorkCalendarType::Holiday, false));
        $second = $calendarService->upsert([
            ...$this->calendarData($unit, self::DATE, WorkCalendarType::SpecialEvent, true, '07:00', '12:00'),
            'title' => 'Special day',
        ]);

        $employee = Employee::create([
            'unit_id' => $unit->id,
            'nama_lengkap' => 'Attendance employee',
            'status' => EmployeeStatus::Active,
        ]);
        $record = AttendanceRecord::create([
            'employee_id' => $employee->id,
            'unit_id' => $unit->id,
            'attendance_date' => self::DATE,
            'status' => AttendanceStatus::Holiday,
            'is_holiday' => true,
        ]);
        $calendarService->delete($second['data'], null);

        $this->assertSame($first['data']->id, $second['data']->id);
        $this->assertDatabaseCount('work_calendars', 0);
        $this->assertDatabaseHas('attendance_records', ['id' => $record->id]);
    }

    public function test_calendar_resolver_prefers_calendar_override_then_weekly_and_never_crosses_units(): void
    {
        $tk = $this->createUnit('TK');
        $sd = $this->createUnit('SD');
        app(WorkScheduleService::class)->create($this->scheduleData($tk, self::DATE, true, '07:00', '12:00'));
        app(WorkScheduleService::class)->create($this->scheduleData($sd, self::DATE, true, '08:00', '14:00'));
        $resolver = app(CalendarResolver::class);

        $tkWeekly = $resolver->resolve($tk->id, self::DATE);
        $this->assertSame('weekly_schedule', $tkWeekly['source']);
        $this->assertSame('07:00', $tkWeekly['start_time']);

        app(WorkCalendarService::class)->create($this->calendarData(
            $tk,
            self::DATE,
            WorkCalendarType::Holiday,
            false,
            null,
            null,
        ));
        $tkHoliday = $resolver->resolve($tk->id, self::DATE);
        $sdSchedule = $resolver->resolve($sd->id, self::DATE);

        $this->assertSame('calendar', $tkHoliday['source']);
        $this->assertFalse($tkHoliday['is_working_day']);
        $this->assertTrue($tkHoliday['is_holiday']);
        $this->assertNull($tkHoliday['start_time']);
        $this->assertSame('weekly_schedule', $sdSchedule['source']);
        $this->assertSame('08:00', $sdSchedule['start_time']);
        $this->assertSame($sd->id, $sdSchedule['unit_id']);
    }

    public function test_special_workday_overrides_weekly_non_working_day_and_missing_schedule_is_explicit(): void
    {
        $unit = $this->createUnit('SD');
        app(WorkScheduleService::class)->create($this->scheduleData($unit, self::DATE, false, null, null));
        app(WorkCalendarService::class)->create($this->calendarData(
            $unit,
            self::DATE,
            WorkCalendarType::SpecialWorkday,
            true,
            '08:00',
            '12:00',
        ));
        $resolver = app(CalendarResolver::class);
        $special = $resolver->resolve($unit->id, self::DATE);
        $missing = $resolver->resolve($unit->id, '2026-08-18');

        $this->assertSame('calendar', $special['source']);
        $this->assertTrue($special['is_working_day']);
        $this->assertSame('08:00', $special['start_time']);
        $this->assertFalse($special['is_holiday']);
        $this->assertNull($missing['source']);
        $this->assertNull($missing['is_working_day']);
        $this->assertTrue($missing['missing_schedule']);
    }

    public function test_delete_endpoints_are_authenticated_and_return_not_found_for_unknown_ids(): void
    {
        $this->getJson('/work-schedules')->assertUnauthorized();
        $this->postJson('/work-schedules')->assertUnauthorized();
        $this->putJson('/work-schedules/999999')->assertUnauthorized();
        $this->deleteJson('/work-schedules/999999')->assertUnauthorized();
        $this->getJson('/work-calendars')->assertUnauthorized();
        $this->postJson('/work-calendars')->assertUnauthorized();
        $this->putJson('/work-calendars/999999')->assertUnauthorized();
        $this->deleteJson('/work-calendars/999999')->assertUnauthorized();
        $this->postJson('/work-calendars/upsert')->assertUnauthorized();
        $this->postJson('/work-calendars/bulk-upsert')->assertUnauthorized();

        $this->actingAs(User::factory()->create())
            ->deleteJson('/work-schedules/999999')
            ->assertNotFound();
    }

    public function test_authenticated_schedule_and_calendar_endpoints_validate_and_return_affected_ranges(): void
    {
        $unit = $this->createUnit('TK');
        $this->actingAs(User::factory()->create());
        $schedulePayload = $this->scheduleData($unit, self::DATE);

        $scheduleId = $this->postJson('/work-schedules', $schedulePayload)
            ->assertCreated()
            ->assertJsonPath('data.unit.code', 'TK')
            ->assertJsonPath('affected.0.unit_id', $unit->id)
            ->json('data.id');

        $this->postJson('/work-schedules', $schedulePayload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('day_of_week');

        $this->putJson('/work-schedules/'.$scheduleId, $this->scheduleData($unit, self::DATE, true, '07:30', '12:00'))
            ->assertOk()
            ->assertJsonPath('attendance_reprocessing_recommended', true)
            ->assertJsonPath('affected.0.day_of_week', CarbonImmutable::parse(self::DATE)->dayOfWeek);

        $calendarPayload = $this->calendarData($unit, self::DATE, WorkCalendarType::Holiday, false);
        $calendarId = $this->postJson('/work-calendars', $calendarPayload)
            ->assertCreated()
            ->assertJsonPath('affected_dates.0', self::DATE)
            ->json('data.id');

        $this->postJson('/work-calendars/upsert', [
            ...$this->calendarData($unit, self::DATE, WorkCalendarType::SpecialEvent, true, '09:00', '11:00'),
            'title' => 'Event',
        ])
            ->assertOk()
            ->assertJsonPath('data.data.id', $calendarId);

        $this->postJson('/work-calendars/bulk-upsert', [
            'unit_id' => $unit->id,
            'date_from' => '2026-08-18',
            'date_to' => '2026-08-19',
            'type' => WorkCalendarType::Holiday->value,
            'is_working_day' => false,
        ])
            ->assertOk()
            ->assertJsonPath('data.created', 2)
            ->assertJsonPath('data.updated', 0);

        $this->putJson('/work-calendars/'.$calendarId, [
            ...$this->calendarData($unit, '2026-08-20', WorkCalendarType::SpecialWorkday, true, '08:00', '12:00'),
        ])
            ->assertOk()
            ->assertJsonPath('affected_dates.0', self::DATE)
            ->assertJsonPath('affected_dates.1', '2026-08-20');

        $this->deleteJson('/work-calendars/'.$calendarId)->assertOk();
        $this->deleteJson('/work-schedules/'.$scheduleId)->assertOk();
    }

    private function createUnit(string $code): Unit
    {
        return Unit::create(['name' => $code, 'code' => $code]);
    }

    private function scheduleData(
        Unit $unit,
        string $date,
        bool $isWorkingDay = true,
        ?string $start = '07:00',
        ?string $end = '15:30',
    ): array {
        return [
            'unit_id' => $unit->id,
            'day_of_week' => CarbonImmutable::parse($date)->dayOfWeek,
            'is_working_day' => $isWorkingDay,
            'start_time' => $start,
            'end_time' => $end,
        ];
    }

    private function calendarData(
        Unit $unit,
        string $date,
        WorkCalendarType $type,
        bool $isWorkingDay,
        ?string $start = null,
        ?string $end = null,
    ): array {
        return [
            'unit_id' => $unit->id,
            'date' => $date,
            'type' => $type->value,
            'title' => null,
            'description' => null,
            'is_working_day' => $isWorkingDay,
            'start_time' => $start,
            'end_time' => $end,
        ];
    }

    private function assertDatabaseScheduleValidation(WorkScheduleService $service, array $data, string $field): void
    {
        try {
            $service->create($data);
            $this->fail("Invalid work schedule should be rejected on {$field}.");
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey($field, $exception->errors());
        }
    }

    private function assertDatabaseCalendarValidation(WorkCalendarService $service, array $data, string $field): void
    {
        try {
            $service->create($data);
            $this->fail("Invalid calendar entry should be rejected on {$field}.");
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey($field, $exception->errors());
        }
    }
}
