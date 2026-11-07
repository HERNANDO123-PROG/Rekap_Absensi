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
use App\Services\WorkCalendarService;
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

    public function test_national_calendar_sync_is_idempotent_and_applies_holidays_to_operational_calendar(): void
    {
        $unit = $this->unit('SD');
        WorkSchedule::query()->create([
            'unit_id' => $unit->id,
            'day_of_week' => 4,
            'is_working_day' => true,
            'start_time' => '08:00',
            'end_time' => '14:00',
        ]);
        $url = 'https://indonesian-holidays.ahrulsyamil.com/holidays/2026';
        Http::fake([$url => Http::response($this->apiResponse([
            $this->apiEvent(),
            $this->apiJointLeaveEvent(),
        ]), 200)]);
        $service = app(NationalCalendarSyncService::class);

        $first = $service->sync(2026);
        $second = $service->sync(2026);
        $this->assertSame(2, $first['created']);
        $this->assertSame(2, $second['updated']);
        $this->assertDatabaseCount('national_calendar_events', 2);
        $resolvedHoliday = app(CalendarResolver::class)->resolve($unit->id, '2026-01-01');
        $this->assertSame('calendar', $resolvedHoliday['source']);
        $this->assertFalse($resolvedHoliday['is_working_day']);
        $resolvedJointLeave = app(CalendarResolver::class)->resolve($unit->id, '2026-02-16');
        $this->assertSame('calendar', $resolvedJointLeave['source']);
        $this->assertFalse($resolvedJointLeave['is_working_day']);
        $this->assertSame(2, $first['operational_created']);
        $this->assertSame(0, $second['operational_created']);

        $event = NationalCalendarEvent::query()->whereDate('date', '2026-01-01')->firstOrFail();
        $event->update(['verification_status' => 'verified', 'local_name' => 'Nama manual terverifikasi']);
        Http::fake([$url => Http::response($this->apiResponse([[
            ...$this->apiEvent(),
            'name' => 'Nama API terbaru',
            'description' => 'New Year holiday',
        ]]), 200)]);
        $third = $service->sync(2026);

        $this->assertSame(1, $third['preserved_verified']);
        $this->assertSame('Nama manual terverifikasi', $event->fresh()->local_name);
        $this->assertSame('verified', $event->fresh()->verification_status);
        $this->assertSame(2, WorkCalendar::query()->count());
        $this->assertSame(
            'Nama manual terverifikasi',
            WorkCalendar::query()->firstOrFail()->title,
        );
        $this->assertSame($event->id, WorkCalendar::query()->firstOrFail()->national_calendar_event_id);

        $calendar = WorkCalendar::query()->firstOrFail();
        app(WorkCalendarService::class)->update($calendar, [
            'unit_id' => $unit->id,
            'date' => '2026-01-01',
            'type' => WorkCalendarType::Holiday->value,
            'title' => 'Libur yang diubah sekolah',
            'description' => 'Keputusan operasional manual',
            'is_working_day' => false,
            'start_time' => null,
            'end_time' => null,
        ]);
        $fourth = $service->sync(2026);

        $this->assertSame(1, $fourth['operational_replaced_manual']);
        $this->assertSame($event->id, $calendar->fresh()->national_calendar_event_id);
        $this->assertSame('Nama manual terverifikasi', $calendar->fresh()->title);
    }

    public function test_national_sync_replaces_manual_entries_with_holidays_and_keeps_previous_notes(): void
    {
        $unit = $this->unit('SD');
        WorkCalendar::query()->create([
            'unit_id' => $unit->id,
            'date' => '2026-01-01',
            'type' => WorkCalendarType::SpecialWorkday,
            'title' => 'Kegiatan sekolah yang sudah ditetapkan',
            'is_working_day' => true,
            'start_time' => '08:00',
            'end_time' => '12:00',
        ]);
        Http::fake([
            'https://indonesian-holidays.ahrulsyamil.com/holidays/2026' => Http::response(
                $this->apiResponse([$this->apiEvent()]),
                200,
            ),
        ]);

        $result = app(NationalCalendarSyncService::class)->sync(2026);

        $calendar = WorkCalendar::query()->firstOrFail();
        $this->assertSame(0, $result['operational_conflicts']);
        $this->assertSame(1, $result['operational_replaced_manual']);
        $this->assertSame(0, $result['operational_updated']);
        $this->assertSame('Tahun Baru Masehi', $calendar->title);
        $this->assertFalse($calendar->is_working_day);
        $this->assertStringContainsString('Kegiatan sekolah yang sudah ditetapkan', $calendar->description);
        $this->assertNotNull($calendar->national_calendar_event_id);
    }

    public function test_national_sync_reuses_pending_legacy_event_and_keeps_its_operational_link(): void
    {
        $unit = $this->unit('SD');
        $legacyEvent = NationalCalendarEvent::query()->create([
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
        $calendar = WorkCalendar::query()->create([
            'unit_id' => $unit->id,
            'date' => '2026-01-01',
            'national_calendar_event_id' => $legacyEvent->id,
            'type' => WorkCalendarType::Holiday,
            'title' => 'Tahun Baru Masehi',
            'is_working_day' => false,
        ]);
        Http::fake([
            'https://indonesian-holidays.ahrulsyamil.com/holidays/2026' => Http::response(
                $this->apiResponse([$this->apiEvent()]),
                200,
            ),
        ]);

        $result = app(NationalCalendarSyncService::class)->sync(2026);

        $this->assertSame(0, $result['created']);
        $this->assertSame(1, $result['updated']);
        $this->assertDatabaseCount('national_calendar_events', 1);
        $this->assertSame('SKB 3 Menteri', $legacyEvent->fresh()->source);
        $this->assertSame($legacyEvent->id, $calendar->fresh()->national_calendar_event_id);
        $this->assertSame('Tahun Baru Masehi', $calendar->fresh()->title);
    }

    public function test_sync_response_reports_manual_replacements_separately_from_api_updates(): void
    {
        $unit = $this->unit('SD');
        WorkCalendar::query()->create([
            'unit_id' => $unit->id,
            'date' => '2026-01-01',
            'type' => WorkCalendarType::SpecialWorkday,
            'title' => 'Kegiatan sekolah',
            'is_working_day' => true,
            'start_time' => '08:00',
            'end_time' => '12:00',
        ]);
        Http::fake([
            'https://indonesian-holidays.ahrulsyamil.com/holidays/2026' => Http::response(
                $this->apiResponse([$this->apiEvent()]),
                200,
            ),
        ]);

        $this->withoutMiddleware()
            ->actingAs(User::factory()->create())
            ->postJson('/national-calendar-events/sync', ['year' => 2026])
            ->assertOk()
            ->assertJsonPath(
                'message',
                'Sinkronisasi selesai: 0 agenda libur/cuti bersama baru ditambahkan, 1 agenda manual diganti dengan libur nasional, 0 agenda diperbarui.',
            );
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
        $unit = $this->unit('SD');
        WorkCalendar::query()->create([
            'unit_id' => $unit->id,
            'date' => '2026-01-01',
            'type' => WorkCalendarType::Holiday,
            'title' => 'Tahun Baru',
            'is_working_day' => false,
        ]);
        WorkCalendar::query()->create([
            'unit_id' => $this->unit('TK')->id,
            'date' => '2026-01-01',
            'type' => WorkCalendarType::Holiday,
            'title' => 'Tahun Baru',
            'is_working_day' => false,
        ]);

        $response = $this->withoutMiddleware()
            ->actingAs(User::factory()->create())
            ->get(route('work-calendar.index'));

        $response->assertOk()
            ->assertSeeText('Libur Nasional & Cuti Bersama')
            ->assertSee('Sinkronkan API')
            ->assertDontSee('Nager.Date')
            ->assertSee('Tambah Agenda / Hari Libur')
            ->assertSee('calendar-agenda-table')
            ->assertSee('Semua unit')
            ->assertSee('calendar-row-actions__button--edit')
            ->assertSee('calendar-row-actions__button--delete')
            ->assertSee('aria-label="Edit agenda Tahun Baru untuk TK"', false)
            ->assertSee('aria-label="Hapus agenda Tahun Baru untuk SD"', false)
            ->assertSee('calendar-row-actions__icon')
            ->assertDontSee('calendar-row-actions__unit-name')
            ->assertDontSee('calendar-action-button--menu')
            ->assertDontSee('<summary')
            ->assertDontSee('Jadwal khusus periode')
            ->assertDontSee('Tambah Jadwal Khusus Periode')
            ->assertDontSee('Atur operasional')
            ->assertDontSee('Catat verifikasi');

        $this->assertSame(1, substr_count($response->getContent(), 'class="calendar-agenda-row"'));
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
            'source' => 'SKB 3 Menteri',
            'source_url' => 'https://www.kemenkopmk.go.id/keputusan',
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
            'name' => 'Tahun Baru Masehi',
            'type' => 'national_holiday',
            'is_national' => true,
            'description' => "New Year's Day",
        ];
    }

    private function apiJointLeaveEvent(): array
    {
        return [
            'date' => '2026-02-16',
            'name' => 'Cuti Bersama Tahun Baru Imlek 2577 Kongzili',
            'type' => 'joint_leave',
            'is_national' => false,
            'description' => null,
        ];
    }

    private function apiResponse(array $events): array
    {
        return [
            'meta' => [
                'year' => 2026,
                'sources' => [[
                    'year' => 2026,
                    'type' => 'skb',
                    'url' => 'https://www.kemenkopmk.go.id/keputusan',
                ]],
            ],
            'data' => $events,
        ];
    }

    private function unit(string $code): Unit
    {
        return Unit::query()->create(['name' => $code, 'code' => $code]);
    }
}
