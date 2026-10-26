<?php

namespace Tests\Feature;

use App\Enums\AnomalySeverity;
use App\Enums\AttendanceAnomalyType;
use App\Enums\AttendanceIntervalSource;
use App\Enums\AttendanceIntervalType;
use App\Enums\AttendanceStatus;
use App\Enums\EmployeeStatus;
use App\Enums\ImportBatchStatus;
use App\Enums\LeaveType;
use App\Enums\MatchingStatus;
use App\Enums\WorkCalendarType;
use App\Models\AttendanceAnomaly;
use App\Models\AttendanceInterval;
use App\Models\AttendanceRecord;
use App\Models\Employee;
use App\Models\FingerprintImportBatch;
use App\Models\FingerprintLog;
use App\Models\LeaveImportBatch;
use App\Models\LeaveRequest;
use App\Models\Unit;
use App\Models\WorkCalendar;
use App\Models\WorkSchedule;
use App\Models\WorkScheduleCategory;
use App\Models\WorkScheduleProfile;
use App\Models\WorkScheduleProfileRule;
use App\Services\Attendance\AttendanceProcessingService;
use App\Services\Reports\AttendanceFingerprintPreviewService;
use App\Services\WorkScheduleProfileService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class AttendanceFingerprintPreviewTest extends TestCase
{
    use RefreshDatabase;

    public function test_preview_service_calculates_separately_without_writing_official_attendance(): void
    {
        $unit = Unit::create(['name' => 'SD', 'code' => 'SD']);
        $category = $this->createBtqCategory();
        $employee = $this->createEmployee($unit, $category, 'BTQ preview employee');
        $batch = $this->createBatch($unit, 'preview-fixture', 6);

        $this->createLogs($batch, $employee, $category, '2026-09-01', ['07:00', '15:30']);
        $this->createLogs($batch, $employee, $category, '2026-09-02', ['07:10', '16:10']);
        $this->createLogs($batch, $employee, $category, '2026-09-06', ['08:00', '12:00']);
        $recordsBefore = AttendanceRecord::query()->count();
        $logsBefore = FingerprintLog::query()->count();

        $preview = app(AttendanceFingerprintPreviewService::class)->generate(
            $unit->id,
            $category->id,
            '2026-09-01',
            '2026-09-30',
            [$employee->id],
        )[0];

        $this->assertSame(6, $preview['raw_log_count']);
        $this->assertSame(3, $preview['logged_day_count']);
        $this->assertSame(2, $preview['working_days_with_logs']);
        $this->assertSame(2, $preview['conditional_late_days']);
        $this->assertSame(1, $preview['conditional_early_leave_days']);
        $this->assertSame(['2026-09-01', '2026-09-02', '2026-09-06'], array_column($preview['days'], 'date'));
        $this->assertSame('Libur', $preview['days'][2]['schedule_status']);
        $this->assertTrue($preview['all_logged_days_eligible']);
        $this->assertSame($recordsBefore, AttendanceRecord::query()->count());
        $this->assertSame($logsBefore, FingerprintLog::query()->count());
    }

    public function test_null_individual_profile_preserves_category_legacy_fallback(): void
    {
        $unit = Unit::create(['name' => 'SD', 'code' => 'SD']);
        $category = WorkScheduleCategory::create([
            'name' => 'BTQ/Madin',
            'start_time' => '06:00',
            'end_time' => '15:00',
            'is_active' => true,
        ]);
        $employee = $this->createEmployee($unit, $category, 'BTQ without profile');
        $batch = $this->createBatch($unit, 'without-profile', 2);
        $this->createLogs($batch, $employee, $category, '2026-09-07', ['07:00', '14:00']);

        $preview = app(AttendanceFingerprintPreviewService::class)->generate(
            $unit->id,
            $category->id,
            '2026-09-01',
            '2026-09-30',
            [$employee->id],
        )[0];

        $this->assertSame('Hari kerja', $preview['days'][0]['schedule_status']);
        $this->assertSame('06:00', $preview['days'][0]['schedule_start']);
        $this->assertSame('15:00', $preview['days'][0]['schedule_end']);
        $this->assertSame(true, $preview['days'][0]['late_estimate']);
        $this->assertSame(true, $preview['days'][0]['early_leave_estimate']);
        $this->assertSame(1, $preview['conditional_late_days']);
        $this->assertSame(1, $preview['conditional_early_leave_days']);
    }

    public function test_null_individual_profile_falls_back_to_unit_weekly_schedule_after_category_fallback_is_absent(): void
    {
        $unit = Unit::create(['name' => 'SD', 'code' => 'SD']);
        $category = WorkScheduleCategory::create([
            'name' => 'BTQ/Madin',
            'is_active' => true,
        ]);
        $employee = $this->createEmployee($unit, $category, 'BTQ unit schedule fallback');
        WorkSchedule::create([
            'unit_id' => $unit->id,
            'day_of_week' => 2,
            'is_working_day' => true,
            'start_time' => '07:30',
            'end_time' => '14:30',
        ]);
        $batch = $this->createBatch($unit, 'unit-fallback', 2);
        $this->createLogs($batch, $employee, $category, '2026-09-01', ['07:35', '14:30']);

        $day = app(AttendanceFingerprintPreviewService::class)->generate(
            $unit->id,
            $category->id,
            '2026-09-01',
            '2026-09-30',
            [$employee->id],
        )[0]['days'][0];

        $this->assertSame('weekly_schedule', $day['schedule_source']);
        $this->assertSame('07:30', $day['schedule_start']);
        $this->assertSame('14:30', $day['schedule_end']);
        $this->assertTrue($day['late_estimate']);
    }

    public function test_individual_profile_is_used_and_preview_schedule_matches_official_processing(): void
    {
        $unit = Unit::create(['name' => 'SD', 'code' => 'SD']);
        $category = $this->createBtqCategory();
        $individual = $this->createProfile($category, 'Individual', '08:00', '14:00');
        $employee = $this->createEmployee($unit, $category, 'BTQ individual preview');
        $employee->update(['work_schedule_profile_id' => $individual->id]);
        WorkSchedule::create([
            'unit_id' => $unit->id,
            'day_of_week' => 2,
            'is_working_day' => true,
            'start_time' => '07:00',
            'end_time' => '17:00',
        ]);
        $batch = $this->createBatch($unit, 'individual-preview', 2);
        $this->createLogs($batch, $employee, $category, '2026-09-01', ['08:10', '13:50']);

        $preview = app(AttendanceFingerprintPreviewService::class)->generate(
            $unit->id,
            $category->id,
            '2026-09-01',
            '2026-09-30',
            [$employee->id],
        )[0]['days'][0];

        $this->assertSame('employee_profile', $preview['schedule_source']);
        $this->assertSame('08:00', $preview['schedule_start']);
        $this->assertSame('14:00', $preview['schedule_end']);
        $this->assertSame('terlambat', $preview['status_estimate']);
        $this->assertTrue($preview['late_estimate']);
        $this->assertTrue($preview['early_leave_estimate']);

        app(AttendanceProcessingService::class)->processEmployeeDate(
            $unit->id,
            $employee->id,
            '2026-09-01',
        );
        $record = $employee->attendanceRecords()->sole();

        $this->assertSame($record->scheduled_in, $preview['schedule_start']);
        $this->assertSame($record->scheduled_out, $preview['schedule_end']);
        $this->assertSame($record->status->value, $preview['status_estimate']);
        $this->assertSame($record->late_minutes > 0, $preview['late_estimate']);
        $this->assertSame($record->early_leave_minutes > 0, $preview['early_leave_estimate']);
    }

    public function test_preview_status_respects_matched_leave_without_processing_data(): void
    {
        $unit = Unit::create(['name' => 'SD', 'code' => 'SD']);
        $category = $this->createBtqCategory();
        $employee = $this->createEmployee($unit, $category, 'BTQ leave preview');
        $batch = $this->createBatch($unit, 'leave-preview', 2);
        $this->createLogs($batch, $employee, $category, '2026-09-01', ['07:00', '15:30']);
        $leaveBatch = LeaveImportBatch::create([
            'unit_id' => $unit->id,
            'filename' => 'leave-preview.xlsx',
            'file_hash' => hash('sha256', 'leave-preview'),
            'status' => ImportBatchStatus::Completed,
        ]);
        LeaveRequest::create([
            'import_batch_id' => $leaveBatch->id,
            'unit_id' => $unit->id,
            'employee_id' => $employee->id,
            'raw_name' => $employee->nama_lengkap,
            'normalized_name' => mb_strtolower($employee->nama_lengkap),
            'request_date' => '2026-09-01',
            'request_time' => '10:00:00',
            'leave_type' => LeaveType::OfficialDuty,
            'raw_leave_type' => LeaveType::OfficialDuty->value,
            'description' => 'Matched duty fixture',
            'matching_status' => MatchingStatus::Matched,
        ]);

        $day = app(AttendanceFingerprintPreviewService::class)->generate(
            $unit->id,
            $category->id,
            '2026-09-01',
            '2026-09-30',
            [$employee->id],
        )[0]['days'][0];

        $this->assertSame('dinas', $day['status_estimate']);
        $this->assertNull($day['late_estimate']);
        $this->assertNull($day['early_leave_estimate']);
        $this->assertSame(0, AttendanceRecord::query()->count());
    }

    public function test_calendar_holiday_and_special_workday_hours_follow_official_resolver(): void
    {
        $unit = Unit::create(['name' => 'SD', 'code' => 'SD']);
        $category = $this->createBtqCategory();
        $employee = $this->createEmployee($unit, $category, 'BTQ calendar preview');
        $batch = $this->createBatch($unit, 'calendar-preview', 4);
        WorkCalendar::create([
            'unit_id' => $unit->id,
            'date' => '2026-09-01',
            'type' => WorkCalendarType::Holiday,
            'is_working_day' => false,
        ]);
        WorkCalendar::create([
            'unit_id' => $unit->id,
            'date' => '2026-09-02',
            'type' => WorkCalendarType::SpecialWorkday,
            'is_working_day' => true,
            'start_time' => '09:00',
            'end_time' => '13:00',
        ]);
        $this->createLogs($batch, $employee, $category, '2026-09-01', ['07:00', '15:00']);
        $this->createLogs($batch, $employee, $category, '2026-09-02', ['09:00', '13:00']);

        $days = app(AttendanceFingerprintPreviewService::class)->generate(
            $unit->id,
            $category->id,
            '2026-09-01',
            '2026-09-30',
            [$employee->id],
        )[0]['days'];

        $this->assertSame('Libur', $days[0]['schedule_status']);
        $this->assertSame('libur', $days[0]['status_estimate']);
        $this->assertNull($days[0]['schedule_start']);
        $this->assertFalse($days[0]['late_estimate']);
        $this->assertSame('calendar', $days[1]['schedule_source']);
        $this->assertSame('09:00', $days[1]['schedule_start']);
        $this->assertSame('13:00', $days[1]['schedule_end']);
    }

    public function test_individual_profile_overrides_unit_weekly_nonwork_on_regular_weekdays(): void
    {
        $unit = Unit::create(['name' => 'SD', 'code' => 'SD']);
        $category = $this->createBtqCategory();
        $individual = $this->createProfile($category, 'Individual no calendar hours', '08:00', '14:00');
        $employee = $this->createEmployee($unit, $category, 'BTQ calendar fallback');
        $employee->update(['work_schedule_profile_id' => $individual->id]);
        $batch = $this->createBatch($unit, 'calendar-fallback', 4);
        WorkCalendar::create([
            'unit_id' => $unit->id,
            'date' => '2026-09-01',
            'type' => WorkCalendarType::SpecialWorkday,
            'is_working_day' => true,
        ]);
        WorkSchedule::create([
            'unit_id' => $unit->id,
            'day_of_week' => 3,
            'is_working_day' => false,
        ]);
        $this->createLogs($batch, $employee, $category, '2026-09-01', ['08:00', '14:00']);
        $this->createLogs($batch, $employee, $category, '2026-09-02', ['08:00', '14:00']);

        $days = app(AttendanceFingerprintPreviewService::class)->generate(
            $unit->id,
            $category->id,
            '2026-09-01',
            '2026-09-30',
            [$employee->id],
        )[0]['days'];

        $this->assertSame('08:00', $days[0]['schedule_start']);
        $this->assertSame('14:00', $days[0]['schedule_end']);
        $this->assertSame('calendar', $days[0]['schedule_source']);
        $this->assertSame('Hari kerja', $days[1]['schedule_status']);
        $this->assertSame('employee_profile', $days[1]['schedule_source']);
    }

    public function test_calendar_from_another_unit_or_date_does_not_affect_preview_schedule(): void
    {
        $unit = Unit::create(['name' => 'SD', 'code' => 'SD']);
        $otherUnit = Unit::create(['name' => 'TK', 'code' => 'TK']);
        $category = $this->createBtqCategory();
        $individual = $this->createProfile($category, 'Scoped calendar individual', '08:00', '14:00');
        $employee = $this->createEmployee($unit, $category, 'BTQ scoped calendar');
        $employee->update(['work_schedule_profile_id' => $individual->id]);
        $batch = $this->createBatch($unit, 'scoped-calendar', 2);
        WorkCalendar::create([
            'unit_id' => $otherUnit->id,
            'date' => '2026-09-01',
            'type' => WorkCalendarType::Holiday,
            'is_working_day' => false,
        ]);
        WorkCalendar::create([
            'unit_id' => $unit->id,
            'date' => '2026-09-02',
            'type' => WorkCalendarType::Holiday,
            'is_working_day' => false,
        ]);
        $this->createLogs($batch, $employee, $category, '2026-09-01', ['08:00', '14:00']);

        $day = app(AttendanceFingerprintPreviewService::class)->generate(
            $unit->id,
            $category->id,
            '2026-09-01',
            '2026-09-30',
            [$employee->id],
        )[0]['days'][0];

        $this->assertSame('employee_profile', $day['schedule_source']);
        $this->assertSame('08:00', $day['schedule_start']);
        $this->assertSame('Hari kerja', $day['schedule_status']);
    }

    public function test_invalid_individual_profile_requires_verification_without_fallback(): void
    {
        $unit = Unit::create(['name' => 'SD', 'code' => 'SD']);
        $category = $this->createBtqCategory();
        $foreignCategory = WorkScheduleCategory::create(['name' => 'Other category', 'is_active' => true]);
        $profiles = [
            $this->createProfile($category, 'Inactive individual', '08:00', '14:00'),
            $this->createProfile($foreignCategory, 'Foreign individual', '08:00', '14:00'),
            $this->createProfile($category, 'Invalid rules individual', '08:00', '14:00'),
        ];
        $profiles[0]->update(['is_active' => false]);
        $profiles[2]->rules()->where('day_of_week', 2)->update(['end_time' => '07:00']);
        $days = [];

        foreach ($profiles as $index => $profile) {
            $employee = $this->createEmployee($unit, $category, 'BTQ invalid individual '.$index);
            $batch = $this->createBatch($unit, 'invalid-individual-'.$index, 2);
            $employee->update(['work_schedule_profile_id' => $profile->id]);
            $this->createLogs($batch, $employee, $category, '2026-09-01', ['07:00', '15:00']);
            $preview = app(AttendanceFingerprintPreviewService::class)->generate(
                $unit->id,
                $category->id,
                '2026-09-01',
                '2026-09-30',
                [$employee->id],
            )[0];
            $days[] = $preview['days'][0];
        }

        foreach ($days as $day) {
            $this->assertSame('Jadwal tidak valid', $day['schedule_status']);
            $this->assertTrue($day['needs_verification']);
            $this->assertSame('perlu_verifikasi', $day['status_estimate']);
            $this->assertNull($day['schedule_start']);
            $this->assertNull($day['late_estimate']);
        }
    }

    public function test_preview_marks_missing_schedule_and_nonactive_category_employee_without_inventing_estimates(): void
    {
        $unit = Unit::create(['name' => 'SD', 'code' => 'SD']);
        $category = WorkScheduleCategory::create([
            'name' => 'BTQ/Madin',
            'is_active' => true,
        ]);
        $employee = $this->createEmployee($unit, $category, 'BTQ missing schedule');
        $batch = $this->createBatch($unit, 'missing-schedule', 2);
        $this->createLogs($batch, $employee, $category, '2026-09-01', ['07:00', '15:00']);

        $preview = app(AttendanceFingerprintPreviewService::class)->generate(
            $unit->id,
            $category->id,
            '2026-09-01',
            '2026-09-30',
            [$employee->id],
        )[0]['days'][0];

        $this->assertSame('Jadwal belum tersedia', $preview['schedule_status']);
        $this->assertTrue($preview['needs_verification']);
        $this->assertSame('perlu_verifikasi', $preview['status_estimate']);
        $this->assertNull($preview['late_estimate']);

        $category->update(['is_active' => false]);
        $this->expectException(ValidationException::class);
        app(AttendanceFingerprintPreviewService::class)->generate(
            $unit->id,
            $category->id,
            '2026-09-01',
            '2026-09-30',
            [$employee->id],
        );
    }

    public function test_preview_does_not_modify_attendance_records_intervals_anomalies_logs_or_batches(): void
    {
        $unit = Unit::create(['name' => 'SD', 'code' => 'SD']);
        $category = $this->createBtqCategory();
        $employee = $this->createEmployee($unit, $category, 'BTQ read-only preview');
        $batch = $this->createBatch($unit, 'read-only-preview', 2);
        $this->createLogs($batch, $employee, $category, '2026-09-01', ['07:00', '15:00']);
        $record = AttendanceRecord::create([
            'employee_id' => $employee->id,
            'unit_id' => $unit->id,
            'attendance_date' => '2026-09-01',
            'status' => AttendanceStatus::Present,
            'scheduled_in' => '06:55',
            'actual_in' => '07:00',
            'scheduled_out' => '16:00',
            'actual_out' => '15:00',
            'late_minutes' => 5,
            'early_leave_minutes' => 60,
            'is_holiday' => false,
            'activity_on_holiday' => false,
            'needs_verification' => false,
        ]);
        AttendanceInterval::create([
            'attendance_record_id' => $record->id,
            'type' => AttendanceIntervalType::Attendance,
            'start_time' => '07:00',
            'end_time' => '15:00',
            'duration_minutes' => 480,
            'source' => AttendanceIntervalSource::Fingerprint,
        ]);
        AttendanceAnomaly::create([
            'attendance_record_id' => $record->id,
            'type' => AttendanceAnomalyType::DuplicateTimestamp,
            'message' => 'Existing fixture anomaly',
            'severity' => AnomalySeverity::Info,
            'is_resolved' => false,
        ]);
        $before = [
            'records' => AttendanceRecord::query()->count(),
            'intervals' => AttendanceInterval::query()->count(),
            'anomalies' => AttendanceAnomaly::query()->count(),
            'record_rows' => AttendanceRecord::query()->orderBy('id')->get()->toArray(),
            'interval_rows' => AttendanceInterval::query()->orderBy('id')->get()->toArray(),
            'anomaly_rows' => AttendanceAnomaly::query()->orderBy('id')->get()->toArray(),
            'logs' => FingerprintLog::query()->orderBy('id')->get()->toArray(),
            'batch' => $batch->fresh()->toArray(),
        ];

        app(AttendanceFingerprintPreviewService::class)->generate(
            $unit->id,
            $category->id,
            '2026-09-01',
            '2026-09-30',
            [$employee->id],
        );

        $this->assertSame($before['records'], AttendanceRecord::query()->count());
        $this->assertSame($before['intervals'], AttendanceInterval::query()->count());
        $this->assertSame($before['anomalies'], AttendanceAnomaly::query()->count());
        $this->assertSame($before['record_rows'], AttendanceRecord::query()->orderBy('id')->get()->toArray());
        $this->assertSame($before['interval_rows'], AttendanceInterval::query()->orderBy('id')->get()->toArray());
        $this->assertSame($before['anomaly_rows'], AttendanceAnomaly::query()->orderBy('id')->get()->toArray());
        $this->assertSame($before['logs'], FingerprintLog::query()->orderBy('id')->get()->toArray());
        $this->assertSame($before['batch'], $batch->fresh()->toArray());
    }

    public function test_preview_service_marks_currently_inactive_employee_ineligible(): void
    {
        $unit = Unit::create(['name' => 'SD', 'code' => 'SD']);
        $category = $this->createBtqCategory();
        $employee = $this->createEmployee($unit, $category, 'Inactive BTQ preview');
        $employee->update(['status' => EmployeeStatus::Inactive]);
        $batch = $this->createBatch($unit, 'inactive-preview', 2);
        $this->createLogs($batch, $employee, $category, '2026-09-07', ['07:00', '14:00']);

        $preview = app(AttendanceFingerprintPreviewService::class)->generate(
            $unit->id,
            $category->id,
            '2026-09-01',
            '2026-09-30',
            [$employee->id],
        )[0];

        $this->assertFalse($preview['days'][0]['eligible_for_official_processing']);
        $this->assertFalse($preview['all_logged_days_eligible']);
        $this->assertNull($preview['days'][0]['late_estimate']);
        $this->assertNull($preview['days'][0]['early_leave_estimate']);
        $this->assertFalse($preview['days'][0]['needs_verification']);
    }

    private function createBtqCategory(): WorkScheduleCategory
    {
        $category = WorkScheduleCategory::create([
            'name' => 'BTQ/Madin',
            'is_active' => true,
        ]);
        $profile = WorkScheduleProfile::create([
            'work_schedule_category_id' => $category->id,
            'name' => 'normal',
            'start_time' => '06:55',
            'end_time' => '16:00',
            'is_active' => true,
        ]);
        $category->update(['work_schedule_profile_id' => $profile->id]);

        for ($day = 0; $day <= 6; $day++) {
            WorkScheduleProfileRule::create([
                'work_schedule_profile_id' => $profile->id,
                'day_of_week' => $day,
                'is_working_day' => $day !== 0,
                'start_time' => $day === 0 ? null : '06:55',
                'end_time' => $day === 0 ? null : '16:00',
            ]);
        }

        return $category;
    }

    private function createProfile(
        WorkScheduleCategory $category,
        string $name,
        string $start,
        string $end,
    ): WorkScheduleProfile {
        $rules = [];
        foreach (range(0, 6) as $day) {
            $rules[$day] = [
                'is_working_day' => $day !== 0,
                'start_time' => $day === 0 ? '' : $start,
                'end_time' => $day === 0 ? '' : $end,
            ];
        }

        return app(WorkScheduleProfileService::class)->saveForCategory($category, [
            'name' => $name,
            'is_normal' => '0',
            'rules' => $rules,
        ]);
    }

    private function createEmployee(Unit $unit, WorkScheduleCategory $category, string $name): Employee
    {
        return Employee::create([
            'unit_id' => $unit->id,
            'category_id' => $category->id,
            'fingerprint_id' => 'FP-'.$name,
            'nama_lengkap' => $name,
            'status' => EmployeeStatus::Active,
        ]);
    }

    private function createBatch(Unit $unit, string $name, int $rows): FingerprintImportBatch
    {
        return FingerprintImportBatch::create([
            'unit_id' => $unit->id,
            'filename' => $name.'.xlsx',
            'file_hash' => hash('sha256', $name),
            'status' => ImportBatchStatus::Completed,
            'period_start' => '2026-09-01',
            'period_end' => '2026-09-30',
            'total_rows' => $rows,
            'success_rows' => $rows,
            'error_rows' => 0,
        ]);
    }

    private function createLogs(
        FingerprintImportBatch $batch,
        Employee $employee,
        WorkScheduleCategory $category,
        string $date,
        array $times,
    ): void {
        foreach ($times as $time) {
            FingerprintLog::create([
                'import_batch_id' => $batch->id,
                'unit_id' => $employee->unit_id,
                'employee_id' => $employee->id,
                'fingerprint_id' => $employee->fingerprint_id,
                'source_category_id' => $category->id,
                'attendance_date' => $date,
                'attendance_time' => $time.':00',
                'raw_value' => $time,
                'matching_status' => MatchingStatus::Matched,
                'needs_verification' => true,
                'verification_note' => '[periode-kerja] test fixture',
            ]);
        }
    }
}
