<?php

namespace Tests\Feature;

use App\Data\Reports\AttendanceFingerprintPeriodReportFilters;
use App\Enums\EmployeeStatus;
use App\Enums\WorkCalendarType;
use App\Models\AttendanceRecord;
use App\Models\Employee;
use App\Models\NationalCalendarEvent;
use App\Models\Unit;
use App\Models\User;
use App\Models\WorkCalendar;
use App\Models\WorkSchedule;
use App\Models\WorkScheduleCategory;
use App\Models\WorkScheduleProfile;
use App\Models\WorkScheduleProfileRule;
use App\Services\Attendance\AttendanceProcessingService;
use App\Services\Calendar\CalendarResolver;
use App\Services\NationalCalendarSyncService;
use App\Services\Reports\AttendanceFingerprintPeriodReportService;
use App\Services\WorkSchedulePeriodService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class OperationalCalendarFeatureTest extends TestCase
{
    use RefreshDatabase;

    public function test_tk_weekend_and_sd_sunday_and_fifth_saturday_are_routine_holidays(): void
    {
        $tk = $this->unit('TK');
        $sd = $this->unit('SD');
        WorkSchedule::query()->create([
            'unit_id' => $tk->id,
            'day_of_week' => 5,
            'is_working_day' => true,
            'start_time' => '07:00',
            'end_time' => '12:00',
        ]);
        WorkSchedule::query()->create([
            'unit_id' => $sd->id,
            'day_of_week' => 6,
            'is_working_day' => true,
            'start_time' => '07:00',
            'end_time' => '12:00',
        ]);
        $resolver = app(CalendarResolver::class);

        $this->assertTrue($resolver->resolve($tk->id, '2026-08-28')['is_working_day']);
        $this->assertFalse($resolver->resolve($tk->id, '2026-08-29')['is_working_day']);
        $this->assertFalse($resolver->resolve($tk->id, '2026-08-30')['is_working_day']);
        $this->assertTrue($resolver->resolve($sd->id, '2026-08-22')['is_working_day']);
        $fifthSaturday = $resolver->resolve($sd->id, '2026-08-29');
        $this->assertFalse($fifthSaturday['is_working_day']);
        $this->assertSame('Sabtu kelima', $fifthSaturday['title']);
        $this->assertFalse($resolver->resolve($sd->id, '2026-08-30')['is_working_day']);
    }

    public function test_schedule_period_overrides_normal_profile_only_for_its_dates_and_manual_calendar_wins(): void
    {
        $unit = $this->unit('TK');
        [$category, $normal, $ramadan] = $this->profiles();
        $employee = Employee::query()->create([
            'unit_id' => $unit->id,
            'category_id' => $category->id,
            'nama_lengkap' => 'Period employee',
            'status' => EmployeeStatus::Active,
        ]);
        $period = app(WorkSchedulePeriodService::class)->create([
            'unit_id' => $unit->id,
            'work_schedule_category_id' => $category->id,
            'work_schedule_profile_id' => $ramadan->id,
            'name' => 'Ramadan 2026',
            'start_date' => '2026-03-01',
            'end_date' => '2026-03-15',
            'is_active' => true,
        ]);
        $resolver = app(CalendarResolver::class);

        $duringPeriod = $resolver->resolve($unit->id, '2026-03-09', employee: $employee);
        $afterPeriod = $resolver->resolve($unit->id, '2026-03-16', employee: $employee);
        $this->assertSame($period->id, $duringPeriod['work_schedule_period_id']);
        $this->assertSame('07:30', $duringPeriod['start_time']);
        $this->assertSame($normal->id, $afterPeriod['work_schedule_profile_id']);
        $this->assertSame('08:00', $afterPeriod['start_time']);

        app(AttendanceProcessingService::class)->processEmployeeDate($unit->id, $employee->id, '2026-03-09');
        $savedRecord = AttendanceRecord::query()
            ->where('employee_id', $employee->id)
            ->whereDate('attendance_date', '2026-03-09')
            ->firstOrFail();
        $this->assertSame('07:30', substr((string) $savedRecord->scheduled_in, 0, 5));

        WorkCalendar::query()->create([
            'unit_id' => $unit->id,
            'date' => '2026-03-10',
            'type' => WorkCalendarType::Holiday,
            'title' => 'Libur sekolah',
            'is_working_day' => false,
        ]);
        $this->assertSame('calendar', $resolver->resolve($unit->id, '2026-03-10', employee: $employee)['source']);
        $report = app(AttendanceFingerprintPeriodReportService::class)->generate(
            AttendanceFingerprintPeriodReportFilters::fromValidated([
                'unit_id' => $unit->id,
                'start_date' => '2026-03-10',
                'end_date' => '2026-03-10',
            ]),
        );
        $this->assertSame('non_working', $report['data'][0]['daily'][0]['day_status']);
        $this->assertSame('calendar', $report['data'][0]['daily'][0]['source']);
        $this->assertNull($report['data'][0]['daily'][0]['schedule_start']);

        $period->update(['end_date' => '2026-03-08']);
        $this->assertSame('07:30', substr((string) $savedRecord->fresh()->scheduled_in, 0, 5));

        $this->assertDatabaseHas('work_schedule_periods', ['id' => $period->id]);
    }

    public function test_category_profiles_allow_teacher_and_admin_hours_to_override_unit_weekly_day_off(): void
    {
        $unit = $this->unit('SD');
        WorkSchedule::query()->create([
            'unit_id' => $unit->id,
            'day_of_week' => 1,
            'is_working_day' => false,
        ]);
        $teacherCategory = WorkScheduleCategory::query()->create(['name' => 'Guru']);
        $adminCategory = WorkScheduleCategory::query()->create(['name' => 'Administrasi']);
        $teacherProfile = $this->profile($teacherCategory, 'Normal Guru', '07:00', '13:00');
        $adminProfile = $this->profile($adminCategory, 'Normal Administrasi', '08:00', '16:00');
        $teacherCategory->update(['work_schedule_profile_id' => $teacherProfile->id]);
        $adminCategory->update(['work_schedule_profile_id' => $adminProfile->id]);
        $teacher = Employee::query()->create([
            'unit_id' => $unit->id,
            'category_id' => $teacherCategory->id,
            'nama_lengkap' => 'Teacher',
            'status' => EmployeeStatus::Active,
        ]);
        $administrator = Employee::query()->create([
            'unit_id' => $unit->id,
            'category_id' => $adminCategory->id,
            'nama_lengkap' => 'Administrator',
            'status' => EmployeeStatus::Active,
        ]);
        $resolver = app(CalendarResolver::class);

        $this->assertSame('07:00', $resolver->resolve($unit->id, '2026-03-09', employee: $teacher)['start_time']);
        $this->assertSame('08:00', $resolver->resolve($unit->id, '2026-03-09', employee: $administrator)['start_time']);

        $report = app(AttendanceFingerprintPeriodReportService::class)->generate(
            AttendanceFingerprintPeriodReportFilters::fromValidated([
                'unit_id' => $unit->id,
                'start_date' => '2026-03-09',
                'end_date' => '2026-03-09',
            ]),
        );
        $rows = collect($report['data'])->keyBy('employee_id');
        $this->assertSame('working', $rows[$teacher->id]['daily'][0]['day_status']);
        $this->assertSame('07:00', $rows[$teacher->id]['daily'][0]['schedule_start']);
        $this->assertSame('working', $rows[$administrator->id]['daily'][0]['day_status']);
        $this->assertSame('08:00', $rows[$administrator->id]['daily'][0]['schedule_start']);
    }

    public function test_overlapping_active_schedule_periods_are_rejected_instead_of_silently_prioritized(): void
    {
        $unit = $this->unit('SD');
        [$category, , $ramadan] = $this->profiles();
        $service = app(WorkSchedulePeriodService::class);
        $payload = [
            'unit_id' => $unit->id,
            'work_schedule_category_id' => $category->id,
            'work_schedule_profile_id' => $ramadan->id,
            'name' => 'Ramadan',
            'start_date' => '2026-03-01',
            'end_date' => '2026-03-20',
            'is_active' => true,
        ];
        $service->create($payload);

        try {
            $service->create([...$payload, 'name' => 'Periode bertabrakan', 'start_date' => '2026-03-15']);
            $this->fail('Expected overlapping active schedule periods to be rejected.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('start_date', $exception->errors());
        }
    }

    public function test_national_calendar_sync_is_idempotent_and_preserves_verified_events_without_applying_them(): void
    {
        $unit = $this->unit('SD');
        WorkSchedule::query()->create([
            'unit_id' => $unit->id,
            'day_of_week' => 4,
            'is_working_day' => true,
            'start_time' => '08:00',
            'end_time' => '14:00',
        ]);
        $url = 'https://date.nager.at/api/v3/PublicHolidays/2026/ID';
        Http::fake([$url => Http::response([$this->apiEvent()], 200)]);
        $service = app(NationalCalendarSyncService::class);

        $first = $service->sync(2026);
        $second = $service->sync(2026);
        $this->assertSame(1, $first['created']);
        $this->assertSame(1, $second['updated']);
        $this->assertDatabaseCount('national_calendar_events', 1);
        $this->assertSame(
            'weekly_schedule',
            app(CalendarResolver::class)->resolve($unit->id, '2026-01-01')['source'],
        );

        $event = NationalCalendarEvent::query()->firstOrFail();
        $event->update(['verification_status' => 'verified', 'local_name' => 'Nama manual terverifikasi']);
        Http::fake([$url => Http::response([[
            ...$this->apiEvent(),
            'localName' => 'Nama API terbaru',
        ]], 200)]);
        $third = $service->sync(2026);

        $this->assertSame(1, $third['preserved_verified']);
        $this->assertSame('Nama manual terverifikasi', $event->fresh()->local_name);
        $this->assertSame('verified', $event->fresh()->verification_status);
        $this->assertSame(0, WorkCalendar::query()->count());
    }

    public function test_national_sync_route_requires_authentication_and_manual_operational_decisions_are_explicit(): void
    {
        $this->postJson('/national-calendar-events/sync', ['year' => 2026])->assertUnauthorized();

        $user = User::factory()->create();
        $this->actingAs($user);
        $this->getJson('/national-calendar-events?year=2026')->assertOk()->assertJsonCount(0, 'data');

        $this->assertDatabaseCount('work_calendars', 0);
    }

    public function test_calendar_page_renders_calendar_controls_and_candidate_sources(): void
    {
        $this->actingAs(User::factory()->create())
            ->get(route('work-calendar.index'))
            ->assertOk()
            ->assertSee('Kalender nasional (Nager.Date)')
            ->assertSee('Jadwal khusus periode')
            ->assertSee('Sinkronkan API')
            ->assertSee('Simpan agenda');
    }

    public function test_verification_requires_an_official_source_reference_and_does_not_apply_a_holiday(): void
    {
        $event = NationalCalendarEvent::query()->create([
            'country_code' => 'ID',
            'date' => '2026-01-01',
            'local_name' => 'Tahun Baru Masehi',
            'name' => "New Year's Day",
            'types' => ['Public'],
            'verification_status' => 'pending',
            'source' => 'Nager.Date',
            'source_url' => 'https://date.nager.at/api/v3/PublicHolidays/2026/ID',
            'last_synced_at' => now(),
        ]);
        $user = User::factory()->create();
        $this->actingAs($user);

        $this->postJson("/national-calendar-events/{$event->id}/verify")
            ->assertUnprocessable()
            ->assertJsonValidationErrors('verification_source_url');
        $this->postJson("/national-calendar-events/{$event->id}/verify", [
            'verification_source_url' => 'https://example.com/official-calendar',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('verification_source_url');
        $this->postJson("/national-calendar-events/{$event->id}/verify", [
            'verification_source_url' => 'https://setneg.go.id/',
        ])
            ->assertOk()
            ->assertJsonPath('data.verification_status', 'verified')
            ->assertJsonPath('data.verification_source_url', 'https://setneg.go.id/')
            ->assertJsonPath('message', 'Sumber kalender diverifikasi. Aturan ini belum diterapkan pada absensi; buat keputusan di kalender operasional sekolah.');

        $this->assertDatabaseHas('national_calendar_events', [
            'id' => $event->id,
            'verified_by' => $user->id,
        ]);
        $this->assertDatabaseCount('work_calendars', 0);
    }

    private function profiles(): array
    {
        $category = WorkScheduleCategory::query()->create(['name' => 'Guru']);
        $normal = $this->profile($category, 'Normal', '08:00', '15:00');
        $ramadan = $this->profile($category, 'Ramadan', '07:30', '13:00');
        $category->update(['work_schedule_profile_id' => $normal->id]);

        return [$category, $normal, $ramadan];
    }

    private function profile(
        WorkScheduleCategory $category,
        string $name,
        string $start,
        string $end,
    ): WorkScheduleProfile {
        $profile = WorkScheduleProfile::query()->create([
            'work_schedule_category_id' => $category->id,
            'name' => $name,
            'start_time' => $start,
            'end_time' => $end,
            'is_active' => true,
        ]);
        for ($day = 0; $day <= 6; $day++) {
            $working = $day >= 1 && $day <= 5;
            WorkScheduleProfileRule::query()->create([
                'work_schedule_profile_id' => $profile->id,
                'day_of_week' => $day,
                'is_working_day' => $working,
                'start_time' => $working ? $start : null,
                'end_time' => $working ? $end : null,
            ]);
        }

        return $profile;
    }

    private function apiEvent(): array
    {
        return [
            'date' => '2026-01-01',
            'localName' => 'Tahun Baru Masehi',
            'name' => "New Year's Day",
            'countryCode' => 'ID',
            'types' => ['Public'],
        ];
    }

    private function unit(string $code): Unit
    {
        return Unit::query()->create(['name' => $code, 'code' => $code]);
    }
}
