<?php

namespace Tests\Feature;

use App\Enums\AbsenceReason;
use App\Enums\AttendanceAnomalyType;
use App\Enums\AttendanceIntervalSource;
use App\Enums\AttendanceIntervalType;
use App\Enums\AttendanceStatus;
use App\Enums\EmployeeStatus;
use App\Enums\ImportBatchStatus;
use App\Enums\LeaveType;
use App\Enums\MatchingStatus;
use App\Enums\WorkCalendarType;
use App\Models\AttendanceRecord;
use App\Models\Employee;
use App\Models\FingerprintImportBatch;
use App\Models\FingerprintLog;
use App\Models\LeaveImportBatch;
use App\Models\LeaveRequest;
use App\Models\Unit;
use App\Models\User;
use App\Models\WorkCalendar;
use App\Models\WorkSchedule;
use App\Models\WorkScheduleCategory;
use App\Models\WorkScheduleProfile;
use App\Models\WorkScheduleProfileRule;
use App\Services\Attendance\AttendanceIntervalBuilder;
use App\Services\Attendance\AttendanceProcessingService;
use App\Services\Calendar\CalendarResolver;
use App\Services\Fingerprint\FingerprintImportService;
use App\Services\Reports\AttendanceRecapCalculator;
use App\Services\WorkScheduleCategoryService;
use App\Services\WorkScheduleProfileService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AttendanceProcessingTest extends TestCase
{
    use RefreshDatabase;

    private const DATE = '2026-08-03';

    private int $categorySequence = 0;

    public function test_employee_category_controls_attendance_thresholds_instead_of_weekly_hours(): void
    {
        $unit = $this->createUnit('TK');
        $category = $this->createCategory('Kategori A', '06:55', '16:00');
        $employee = $this->createEmployee($unit, category: $category);
        $this->createSchedule($unit, self::DATE, true, '07:30', '14:00');
        $this->createFingerprintLogs($unit, $employee, self::DATE, ['07:05', '15:40']);

        $record = $this->process($unit, $employee);

        $this->assertSame('06:55', substr($record->scheduled_in, 0, 5));
        $this->assertSame('16:00', substr($record->scheduled_out, 0, 5));
        $this->assertSame(10, $record->late_minutes);
        $this->assertSame(20, $record->early_leave_minutes);
        $this->assertSame(AttendanceStatus::Late, $record->status);
    }

    public function test_arrival_before_category_start_is_not_late_and_category_b_controls_checkout(): void
    {
        $unit = $this->createUnit('SD');
        $category = $this->createCategory('Kategori B', '06:55', '15:40');
        $employee = $this->createEmployee($unit, category: $category);
        $this->createSchedule($unit, self::DATE, true, '08:00', '17:00');
        $this->createFingerprintLogs($unit, $employee, self::DATE, ['06:50', '15:40']);

        $record = $this->process($unit, $employee);

        $this->assertSame('06:55', substr($record->scheduled_in, 0, 5));
        $this->assertSame('15:40', substr($record->scheduled_out, 0, 5));
        $this->assertSame(0, $record->late_minutes);
        $this->assertSame(0, $record->early_leave_minutes);
        $this->assertSame(AttendanceStatus::Present, $record->status);
    }

    public function test_working_employee_without_a_category_requires_verification(): void
    {
        $unit = $this->createUnit('TK');
        $employee = $this->createEmployee($unit, assignCategory: false);
        $this->createSchedule($unit, self::DATE);
        $this->createFingerprintLogs($unit, $employee, self::DATE, ['07:05', '15:00']);

        $record = $this->process($unit, $employee);

        $this->assertSame(AttendanceStatus::NeedsVerification, $record->status);
        $this->assertTrue($record->needs_verification);
        $this->assertNull($record->scheduled_in);
        $this->assertNull($record->scheduled_out);
        $this->assertSame(0, $record->late_minutes);
        $this->assertSame(0, $record->early_leave_minutes);
        $this->assertDatabaseHas('attendance_anomalies', [
            'attendance_record_id' => $record->id,
            'type' => AttendanceAnomalyType::MissingSchedule->value,
            'message' => 'Karyawan belum memiliki kategori jam kerja aktif.',
        ]);
    }

    public function test_working_employee_with_active_category_uses_category_when_unit_schedule_is_missing(): void
    {
        $unit = $this->createUnit('TK');
        $category = $this->createCategory('Kategori fallback', '06:55', '15:40');
        $employee = $this->createEmployee($unit, category: $category);
        $this->createFingerprintLogs($unit, $employee, self::DATE, ['07:05', '15:30']);

        $record = $this->process($unit, $employee);

        $this->assertSame('06:55', substr($record->scheduled_in, 0, 5));
        $this->assertSame('15:40', substr($record->scheduled_out, 0, 5));
        $this->assertSame(10, $record->late_minutes);
        $this->assertSame(10, $record->early_leave_minutes);
        $this->assertSame(AttendanceStatus::Late, $record->status);
        $this->assertFalse($record->needs_verification);
    }

    public function test_individual_profile_overrides_category_profile_and_weekly_schedule_for_new_attendance(): void
    {
        $unit = $this->createUnit('TK');
        $category = $this->createCategory('Individual schedule', '06:55', '15:40');
        $categoryProfile = $this->createProfile($category, 'Category normal', '06:55', '15:40');
        app(WorkScheduleCategoryService::class)->setNormalProfile($category, $categoryProfile);
        $individualProfile = $this->createProfile($category, 'Individual', '08:00', '14:00');
        $employee = $this->createEmployee($unit, category: $category);
        $employee->update(['work_schedule_profile_id' => $individualProfile->id]);
        $this->createSchedule($unit, self::DATE, true, '07:00', '15:30');
        $this->createFingerprintLogs($unit, $employee, self::DATE, ['08:05', '14:00']);

        $record = $this->process($unit, $employee);

        $this->assertSame('08:00', substr($record->scheduled_in, 0, 5));
        $this->assertSame('14:00', substr($record->scheduled_out, 0, 5));
        $this->assertSame(5, $record->late_minutes);
        $this->assertSame(0, $record->early_leave_minutes);
        $this->assertFalse($record->needs_verification);
    }

    public function test_individual_assignment_does_not_rewrite_existing_attendance_snapshot(): void
    {
        $unit = $this->createUnit('TK');
        $category = $this->createCategory('Historical snapshot', '06:55', '15:40');
        $employee = $this->createEmployee($unit, category: $category);
        $this->createFingerprintLogs($unit, $employee, self::DATE, ['07:05', '15:30']);
        $record = $this->process($unit, $employee);
        $savedSnapshot = [$record->scheduled_in, $record->scheduled_out, $record->processed_at?->toDateTimeString()];

        $profile = $this->createProfile($category, 'Assigned after processing', '08:00', '14:00');
        $employee->update(['work_schedule_profile_id' => $profile->id]);

        $record->refresh();
        $this->assertSame($savedSnapshot, [
            $record->scheduled_in,
            $record->scheduled_out,
            $record->processed_at?->toDateTimeString(),
        ]);
    }

    public function test_individual_profile_respects_calendar_holiday_and_explicit_calendar_hours(): void
    {
        $unit = $this->createUnit('SD');
        $category = $this->createCategory('Calendar precedence', '06:55', '15:40');
        $profile = $this->createProfile($category, 'Individual', '08:00', '14:00');
        $employee = $this->createEmployee($unit, category: $category);
        $employee->update(['work_schedule_profile_id' => $profile->id]);

        $this->createCalendar($unit, self::DATE, false, WorkCalendarType::Holiday);
        $this->createFingerprintLogs($unit, $employee, self::DATE, ['08:00', '14:00']);
        $holiday = $this->process($unit, $employee);

        $this->assertSame(AttendanceStatus::Holiday, $holiday->status);
        $this->assertNull($holiday->scheduled_in);

        $nextDate = '2026-08-04';
        $this->createCalendar($unit, $nextDate, true, WorkCalendarType::SpecialWorkday, '09:00', '13:00');
        $this->createFingerprintLogs($unit, $employee, $nextDate, ['09:00', '13:00']);
        $calendarWorkday = $this->process($unit, $employee, $nextDate);

        $this->assertSame('09:00', substr($calendarWorkday->scheduled_in, 0, 5));
        $this->assertSame('13:00', substr($calendarWorkday->scheduled_out, 0, 5));
    }

    public function test_calendar_workday_without_hours_uses_valid_individual_profile(): void
    {
        $unit = $this->createUnit('TK');
        $category = $this->createCategory('Calendar fallback', '06:55', '15:40');
        $profile = $this->createProfile($category, 'Individual', '08:00', '14:00');
        $employee = $this->createEmployee($unit, category: $category);
        $employee->update(['work_schedule_profile_id' => $profile->id]);
        $this->createCalendar($unit, self::DATE, true, WorkCalendarType::SpecialWorkday);
        $this->createFingerprintLogs($unit, $employee, self::DATE, ['08:00', '14:00']);

        $record = $this->process($unit, $employee);

        $this->assertSame('08:00', substr($record->scheduled_in, 0, 5));
        $this->assertSame('14:00', substr($record->scheduled_out, 0, 5));
        $this->assertFalse($record->needs_verification);
    }

    public function test_individual_profile_overrides_unit_weekly_non_working_day(): void
    {
        $unit = $this->createUnit('SD');
        $category = $this->createCategory('Weekly rest precedence', '06:55', '15:40');
        $profile = $this->createProfile($category, 'Individual', '08:00', '14:00');
        $employee = $this->createEmployee($unit, category: $category);
        $employee->update(['work_schedule_profile_id' => $profile->id]);
        $this->createSchedule($unit, self::DATE, isWorkingDay: false, start: null, end: null);
        $this->createFingerprintLogs($unit, $employee, self::DATE, ['08:00', '14:00']);

        $record = $this->process($unit, $employee);

        $this->assertSame(AttendanceStatus::Present, $record->status);
        $this->assertSame('08:00', substr($record->scheduled_in, 0, 5));
    }

    public function test_invalid_individual_profiles_require_verification_without_category_fallback(): void
    {
        $unit = $this->createUnit('TK');
        $category = $this->createCategory('Invalid individual', '06:55', '15:40');
        $otherCategory = $this->createCategory('Other individual', '07:30', '16:00');
        $inactiveProfile = $this->createProfile($category, 'Inactive individual', '08:00', '14:00');
        $inactiveProfile->update(['is_active' => false]);
        $foreignProfile = $this->createProfile($otherCategory, 'Foreign individual', '08:00', '14:00');
        $invalidRulesProfile = $this->createProfile($category, 'Invalid rules individual', '08:00', '14:00');
        WorkScheduleProfileRule::query()
            ->where('work_schedule_profile_id', $invalidRulesProfile->id)
            ->where('day_of_week', 1)
            ->update(['end_time' => '07:00']);

        foreach ([$inactiveProfile, $foreignProfile, $invalidRulesProfile] as $index => $profile) {
            $employee = $this->createEmployee(
                $unit,
                name: 'Invalid profile employee '.$index,
                category: $category,
            );
            $employee->update(['work_schedule_profile_id' => $profile->id]);
            $this->createFingerprintLogs($unit, $employee, self::DATE, ['07:05', '15:30']);

            $record = $this->process($unit, $employee);

            $this->assertSame(AttendanceStatus::NeedsVerification, $record->status);
            $this->assertNull($record->scheduled_in);
            $this->assertTrue($record->needs_verification);
        }

        $inactiveCategory = $this->createCategory('Inactive owner', '06:55', '15:40');
        $profile = $this->createProfile($inactiveCategory, 'Inactive owner profile', '08:00', '14:00');
        $employee = $this->createEmployee($unit, category: $inactiveCategory);
        $employee->update(['work_schedule_profile_id' => $profile->id]);
        $inactiveCategory->update(['is_active' => false]);
        $this->createFingerprintLogs($unit, $employee, self::DATE, ['07:05', '15:30']);

        $record = $this->process($unit, $employee);

        $this->assertSame(AttendanceStatus::NeedsVerification, $record->status);
        $this->assertNull($record->scheduled_in);
        $this->assertTrue($record->needs_verification);
    }

    public function test_calendar_from_another_unit_or_date_does_not_override_individual_profile(): void
    {
        $unit = $this->createUnit('TK');
        $otherUnit = $this->createUnit('SD');
        $category = $this->createCategory('Calendar scope', '06:55', '15:40');
        $profile = $this->createProfile($category, 'Individual', '08:00', '14:00');
        $employee = $this->createEmployee($unit, category: $category);
        $employee->update(['work_schedule_profile_id' => $profile->id]);
        $this->createCalendar($otherUnit, self::DATE, false, WorkCalendarType::Holiday);
        $this->createCalendar($unit, '2026-08-04', false, WorkCalendarType::Holiday);
        $this->createFingerprintLogs($unit, $employee, self::DATE, ['08:00', '14:00']);

        $record = $this->process($unit, $employee);

        $this->assertSame('08:00', substr($record->scheduled_in, 0, 5));
        $this->assertSame('14:00', substr($record->scheduled_out, 0, 5));
        $this->assertFalse($record->is_holiday);
    }

    public function test_assigned_inactive_profile_supplies_attendance_schedule_instead_of_category_legacy_times(): void
    {
        $unit = $this->createUnit('TK');
        $category = $this->createCategory('Kategori profile', '06:55', '15:40');
        $profile = WorkScheduleProfile::query()->create([
            'work_schedule_category_id' => $category->id,
            'name' => 'Ramadhan',
            'start_time' => '07:00',
            'end_time' => '14:00',
            'is_active' => true,
        ]);
        app(WorkScheduleCategoryService::class)->update($category, [
            'name' => $category->name,
            'start_time' => '06:55',
            'end_time' => '15:40',
            'work_schedule_profile_id' => $profile->id,
        ]);
        $profile->update(['is_active' => false]);
        $employee = $this->createEmployee($unit, category: $category);
        $this->createFingerprintLogs($unit, $employee, self::DATE, ['07:05', '13:55']);

        $record = $this->process($unit, $employee);

        $this->assertSame('07:00', substr($record->scheduled_in, 0, 5));
        $this->assertSame('14:00', substr($record->scheduled_out, 0, 5));
    }

    public function test_reprocessing_uses_the_current_category_profile_and_keeps_saved_snapshot_until_reprocessing(): void
    {
        $unit = $this->createUnit('TK');
        $category = WorkScheduleCategory::query()->create(['name' => 'Guru jadwal harian']);
        $profiles = app(WorkScheduleProfileService::class);
        $normalRules = $this->profileRules('06:55', '15:40');
        $normalRules[5] = ['is_working_day' => true, 'start_time' => '06:55', 'end_time' => '15:00'];
        $normalRules[6] = ['is_working_day' => true, 'start_time' => '08:00', 'end_time' => '11:00'];
        $normal = $profiles->saveForCategory($category, [
            'name' => 'Normal',
            'is_normal' => '1',
            'rules' => $normalRules,
        ]);
        $alternative = $profiles->saveForCategory($category, [
            'name' => 'Ramadan',
            'is_normal' => '0',
            'rules' => $this->profileRules('07:30', '14:00'),
        ]);
        $employee = $this->createEmployee($unit, category: $category);
        $logs = $this->createFingerprintLogs($unit, $employee, self::DATE, ['07:05', '14:00']);
        $batch = FingerprintImportBatch::query()->findOrFail($logs[0]->import_batch_id);
        $batch->update(['period_start' => self::DATE, 'period_end' => self::DATE]);

        $record = $this->process($unit, $employee);
        $recordId = $record->id;
        $this->assertSame('06:55', substr($record->scheduled_in, 0, 5));
        $this->assertSame($normal->id, $category->fresh()->work_schedule_profile_id);

        app(WorkScheduleCategoryService::class)->setNormalProfile($category, $alternative);

        $this->assertSame('06:55', substr($record->fresh()->scheduled_in, 0, 5));
        $resolver = app(CalendarResolver::class);
        $resolved = $resolver->resolve($unit->id, self::DATE, $category->id);

        $this->assertSame('category_normal', $resolved['source']);
        $this->assertSame('07:30', $resolved['start_time']);
        $this->assertSame('14:00', $resolved['end_time']);

        app(FingerprintImportService::class)->reprocessImportBatch($batch);
        $record = $employee->attendanceRecords()->sole();
        $this->assertSame('07:30', substr($record->scheduled_in, 0, 5));
        $this->assertSame('14:00', substr($record->scheduled_out, 0, 5));
        $this->assertSame($recordId, $record->id);
        $this->assertSame(1, $employee->attendanceRecords()->count());

        $this->createCalendar($unit, self::DATE, false, WorkCalendarType::Holiday);
        $holiday = $this->process($unit, $employee);
        $this->assertTrue($holiday->is_holiday);
        $this->assertSame(AttendanceStatus::Holiday, $holiday->status);
    }

    public function test_normal_profiles_remain_scoped_to_their_category(): void
    {
        $unit = $this->createUnit('TK');
        $otherUnit = $this->createUnit('SD');
        $category = WorkScheduleCategory::query()->create(['name' => 'Kategori cakupan A']);
        $otherCategory = WorkScheduleCategory::query()->create(['name' => 'Kategori cakupan B']);
        $profiles = app(WorkScheduleProfileService::class);
        $normal = $profiles->saveForCategory($category, [
            'name' => 'Normal A',
            'is_normal' => '1',
            'rules' => $this->profileRules('06:55', '15:40'),
        ]);
        $otherNormal = $profiles->saveForCategory($otherCategory, [
            'name' => 'Normal B',
            'is_normal' => '1',
            'rules' => $this->profileRules('08:00', '16:00'),
        ]);

        $resolver = app(CalendarResolver::class);
        $selectedScope = $resolver->resolve($unit->id, self::DATE, $category->id);
        $otherCategoryScope = $resolver->resolve($unit->id, self::DATE, $otherCategory->id);
        $otherUnitScope = $resolver->resolve($otherUnit->id, self::DATE, $category->id);

        $this->assertSame('06:55', $selectedScope['start_time']);
        $this->assertSame('08:00', $otherCategoryScope['start_time']);
        $this->assertSame($otherNormal->id, $otherCategory->fresh()->work_schedule_profile_id);
        $this->assertSame('06:55', $otherUnitScope['start_time']);
        $this->assertSame($normal->id, $category->fresh()->work_schedule_profile_id);
    }

    public function test_inactive_employee_category_requires_verification(): void
    {
        $unit = $this->createUnit('SD');
        $category = $this->createCategory('Inactive category', '06:55', '15:40');
        $employee = $this->createEmployee($unit, category: $category);
        $category->update(['is_active' => false]);
        $this->createSchedule($unit, self::DATE);
        $this->createFingerprintLogs($unit, $employee, self::DATE, ['07:05', '15:00']);

        $record = $this->process($unit, $employee);

        $this->assertSame(AttendanceStatus::NeedsVerification, $record->status);
        $this->assertTrue($record->needs_verification);
        $this->assertNull($record->scheduled_in);
        $this->assertNull($record->scheduled_out);
        $this->assertSame(0, $record->late_minutes);
        $this->assertSame(0, $record->early_leave_minutes);
    }

    public function test_holiday_does_not_require_an_employee_category(): void
    {
        $unit = $this->createUnit('TK');
        $employee = $this->createEmployee($unit, assignCategory: false);
        $this->createCalendar($unit, self::DATE, false, WorkCalendarType::Holiday);
        $this->createFingerprintLogs($unit, $employee, self::DATE, ['07:05', '15:00']);

        $record = $this->process($unit, $employee);

        $this->assertSame(AttendanceStatus::Holiday, $record->status);
        $this->assertFalse($record->needs_verification);
        $this->assertTrue($record->activity_on_holiday);
    }

    public function test_working_day_with_normal_fingerprint_is_present(): void
    {
        $unit = $this->createUnit('TK');
        $employee = $this->createEmployee($unit);
        $this->createSchedule($unit, self::DATE);
        $this->createFingerprintLogs($unit, $employee, self::DATE, ['07:00', '15:30']);

        $record = $this->process($unit, $employee);

        $this->assertSame(AttendanceStatus::Present, $record->status);
        $this->assertSame('07:00', substr($record->actual_in, 0, 5));
        $this->assertSame('15:30', substr($record->actual_out, 0, 5));
        $this->assertSame(0, $record->late_minutes);
        $this->assertSame(0, $record->early_leave_minutes);
    }

    public function test_late_arrival_is_calculated_from_configured_schedule(): void
    {
        $unit = $this->createUnit('TK');
        $employee = $this->createEmployee($unit);
        $this->createSchedule($unit, self::DATE);
        $this->createFingerprintLogs($unit, $employee, self::DATE, ['07:02']);

        $record = $this->process($unit, $employee);

        $this->assertSame(AttendanceStatus::Late, $record->status);
        $this->assertSame(2, $record->late_minutes);
        $this->assertNull($record->actual_out);
    }

    public function test_early_departure_uses_actual_fingerprint_out(): void
    {
        $unit = $this->createUnit('TK');
        $employee = $this->createEmployee($unit);
        $this->createSchedule($unit, self::DATE);
        $this->createFingerprintLogs($unit, $employee, self::DATE, ['07:00', '15:00']);

        $record = $this->process($unit, $employee);

        $this->assertSame(AttendanceStatus::EarlyDeparture, $record->status);
        $this->assertSame(30, $record->early_leave_minutes);
    }

    public function test_late_and_early_metrics_are_preserved_together(): void
    {
        $unit = $this->createUnit('TK');
        $employee = $this->createEmployee($unit);
        $this->createSchedule($unit, self::DATE);
        $this->createFingerprintLogs($unit, $employee, self::DATE, ['07:05', '15:00']);

        $record = $this->process($unit, $employee);

        $this->assertSame(AttendanceStatus::Late, $record->status);
        $this->assertSame(5, $record->late_minutes);
        $this->assertSame(30, $record->early_leave_minutes);
    }

    public function test_late_and_early_leave_requests_use_fingerprint_as_actual_time(): void
    {
        $unit = $this->createUnit('TK');
        $lateEmployee = $this->createEmployee($unit, 'Late employee');
        $earlyEmployee = $this->createEmployee($unit, 'Early employee');
        $this->createSchedule($unit, self::DATE);
        $lateRequest = $this->createLeave($unit, $lateEmployee, self::DATE, LeaveType::Late);
        $earlyRequest = $this->createLeave($unit, $earlyEmployee, self::DATE, LeaveType::EarlyDeparture);
        $this->createFingerprintLogs($unit, $lateEmployee, self::DATE, ['07:02', '15:30']);
        $this->createFingerprintLogs($unit, $earlyEmployee, self::DATE, ['07:00', '15:00']);

        $lateRecord = $this->process($unit, $lateEmployee);
        $earlyRecord = $this->process($unit, $earlyEmployee);

        $this->assertSame('07:02', substr($lateRecord->actual_in, 0, 5));
        $this->assertSame(2, $lateRecord->late_minutes);
        $this->assertSame($lateRequest->id, $lateRecord->leave_request_id);
        $this->assertSame('15:00', substr($earlyRecord->actual_out, 0, 5));
        $this->assertSame(30, $earlyRecord->early_leave_minutes);
        $this->assertSame($earlyRequest->id, $earlyRecord->leave_request_id);
    }

    #[DataProvider('absenceReasonCases')]
    public function test_absence_requests_always_resolve_to_alpha_and_preserve_known_reason(string $reason): void
    {
        $unit = $this->createUnit('TK');
        $employee = $this->createEmployee($unit);
        $this->createSchedule($unit, self::DATE);
        $this->createLeave($unit, $employee, self::DATE, LeaveType::Absent, $reason);

        $record = $this->process($unit, $employee);

        $this->assertSame(AttendanceStatus::Absent, $record->status);
        $this->assertFalse($record->needs_verification);
        $this->assertSame($reason, $record->absence_reason?->value);
    }

    public static function absenceReasonCases(): array
    {
        return [
            'sick' => [AbsenceReason::Sick->value],
            'permission' => [AbsenceReason::Permission->value],
            'unknown' => [AbsenceReason::Unknown->value],
        ];
    }

    public function test_absence_request_conflicting_with_fingerprint_stays_alpha_and_keeps_anomaly(): void
    {
        $unit = $this->createUnit('TK');
        $employee = $this->createEmployee($unit);
        $this->createSchedule($unit, self::DATE);
        $this->createLeave($unit, $employee, self::DATE, LeaveType::Absent, AbsenceReason::Sick->value);
        $this->createFingerprintLogs($unit, $employee, self::DATE, ['07:00']);

        $record = $this->process($unit, $employee);

        $this->assertSame(AttendanceStatus::Absent, $record->status);
        $this->assertFalse($record->needs_verification);
        $this->assertSame($record->leaveRequest?->id, $record->leave_request_id);
        $this->assertDatabaseHas('attendance_anomalies', [
            'attendance_record_id' => $record->id,
            'type' => AttendanceAnomalyType::LeaveAbsenceConflictWithFingerprint->value,
        ]);
    }

    public function test_dinas_and_absence_override_fingerprint_counts_on_workdays(): void
    {
        $unit = $this->createUnit('TK');
        $this->createSchedule($unit, self::DATE);
        $scenarios = [
            [LeaveType::OfficialDuty, []],
            [LeaveType::OfficialDuty, ['07:05']],
            [LeaveType::OfficialDuty, ['15:00']],
            [LeaveType::OfficialDuty, ['07:05', '15:00']],
            [LeaveType::Absent, []],
            [LeaveType::Absent, ['07:05']],
            [LeaveType::Absent, ['15:00']],
            [LeaveType::Absent, ['07:05', '15:00']],
        ];

        foreach ($scenarios as $index => [$type, $times]) {
            $employee = $this->createEmployee($unit, 'Leave override '.$index);
            $this->createLeave(
                $unit,
                $employee,
                self::DATE,
                $type,
                $type === LeaveType::Absent ? AbsenceReason::Unknown->value : null,
            );
            if ($times !== []) {
                $this->createFingerprintLogs($unit, $employee, self::DATE, $times);
            }

            $record = $this->process($unit, $employee);
            $this->assertSame(
                $type === LeaveType::OfficialDuty ? AttendanceStatus::OfficialDuty : AttendanceStatus::Absent,
                $record->status,
            );
            $this->assertFalse($record->needs_verification);
            $this->assertSame($times[0] ?? null, $record->actual_in ? substr($record->actual_in, 0, 5) : null);
            $this->assertSame(count($times) > 1 ? $times[array_key_last($times)] : null, $record->actual_out ? substr($record->actual_out, 0, 5) : null);
        }
    }

    public function test_leave_status_overrides_missing_schedule_but_not_calendar_holidays(): void
    {
        $unit = $this->createUnit('TK');
        $employee = $this->createEmployee($unit, 'Duty without schedule', assignCategory: false);
        $this->createLeave($unit, $employee, self::DATE, LeaveType::OfficialDuty);
        $this->createFingerprintLogs($unit, $employee, self::DATE, ['07:05', '15:00']);

        $this->assertSame(AttendanceStatus::OfficialDuty, $this->process($unit, $employee)->status);

        $holidayEmployee = $this->createEmployee($unit, 'Holiday duty');
        $this->createCalendar($unit, self::DATE, false, WorkCalendarType::Holiday);
        $this->createLeave($unit, $holidayEmployee, self::DATE, LeaveType::OfficialDuty);
        $this->createFingerprintLogs($unit, $holidayEmployee, self::DATE, ['07:05', '15:00']);

        $holiday = $this->process($unit, $holidayEmployee);
        $this->assertSame(AttendanceStatus::Holiday, $holiday->status);
        $this->assertTrue($holiday->activity_on_holiday);
    }

    public function test_calendar_holiday_precedes_each_leave_type(): void
    {
        $unit = $this->createUnit('TK');
        $this->createCalendar($unit, self::DATE, false, WorkCalendarType::Holiday);
        $scenarios = [
            [LeaveType::OfficialDuty, ['07:05', '15:00']],
            [LeaveType::Absent, ['07:05', '15:00']],
            [LeaveType::TemporaryExit, ['07:00', '10:00', '12:00', '15:30']],
        ];

        foreach ($scenarios as $index => [$type, $times]) {
            $employee = $this->createEmployee($unit, 'Holiday leave '.$index);
            $this->createLeave(
                $unit,
                $employee,
                self::DATE,
                $type,
                $type === LeaveType::Absent ? AbsenceReason::Unknown->value : null,
            );
            $this->createFingerprintLogs($unit, $employee, self::DATE, $times);

            $record = $this->process($unit, $employee);

            $this->assertSame(AttendanceStatus::Holiday, $record->status);
            $this->assertTrue($record->activity_on_holiday);
        }
    }

    public function test_official_duty_applies_with_or_without_fingerprint(): void
    {
        $unit = $this->createUnit('SD');
        $withoutFingerprint = $this->createEmployee($unit, 'Duty without fingerprint');
        $withFingerprint = $this->createEmployee($unit, 'Duty with fingerprint');
        $this->createSchedule($unit, self::DATE);
        $this->createLeave($unit, $withoutFingerprint, self::DATE, LeaveType::OfficialDuty);
        $this->createLeave($unit, $withFingerprint, self::DATE, LeaveType::OfficialDuty);
        $this->createFingerprintLogs($unit, $withFingerprint, self::DATE, ['07:00', '15:30']);

        $this->assertSame(AttendanceStatus::OfficialDuty, $this->process($unit, $withoutFingerprint)->status);
        $this->assertSame(AttendanceStatus::OfficialDuty, $this->process($unit, $withFingerprint)->status);
    }

    public function test_keluar_with_four_timestamps_builds_middle_exit_interval_and_keeps_raw_logs(): void
    {
        $unit = $this->createUnit('TK');
        $employee = $this->createEmployee($unit);
        $this->createSchedule($unit, self::DATE);
        $this->createLeave($unit, $employee, self::DATE, LeaveType::TemporaryExit);
        $logs = $this->createFingerprintLogs($unit, $employee, self::DATE, ['07:02', '10:10', '12:05', '15:30']);

        $record = $this->process($unit, $employee);
        $interval = $record->intervals()->sole();

        $this->assertSame('07:02', substr($record->actual_in, 0, 5));
        $this->assertSame('15:30', substr($record->actual_out, 0, 5));
        $this->assertSame(AttendanceIntervalType::TemporaryExit, $interval->type);
        $this->assertSame('10:10', substr($interval->start_time, 0, 5));
        $this->assertSame('12:05', substr($interval->end_time, 0, 5));
        $this->assertSame(115, $interval->duration_minutes);
        $this->assertSame(AttendanceIntervalSource::FingerprintAndLeaveRequest, $interval->source);
        $this->assertSame(4, FingerprintLog::query()->where('employee_id', $employee->id)->count());
        $this->assertSame(AttendanceStatus::Late, $record->status);
        $this->assertSame(2, $record->late_minutes);
    }

    public function test_keluar_with_two_timestamps_can_form_one_interval(): void
    {
        $unit = $this->createUnit('TK');
        $employee = $this->createEmployee($unit);
        $this->createSchedule($unit, self::DATE);
        $this->createLeave($unit, $employee, self::DATE, LeaveType::TemporaryExit);
        $this->createFingerprintLogs($unit, $employee, self::DATE, ['10:10', '12:05']);

        $record = $this->process($unit, $employee);

        $this->assertSame(AttendanceStatus::TemporaryExit, $record->status);
        $this->assertNull($record->actual_in);
        $this->assertNull($record->actual_out);
        $this->assertSame(115, $record->intervals()->sole()->duration_minutes);
    }

    public function test_exit_interval_threshold_is_explicit_and_over_fifteen_minute_interval_is_fingerprint_backed(): void
    {
        $unit = $this->createUnit('TK');
        $employee = $this->createEmployee($unit);
        $this->createSchedule($unit, self::DATE);
        $this->createLeave($unit, $employee, self::DATE, LeaveType::TemporaryExit);
        $this->createFingerprintLogs($unit, $employee, self::DATE, ['07:00', '10:10', '12:05', '15:30']);

        $record = $this->process($unit, $employee);
        $interval = $record->intervals()->sole();

        $this->assertSame(15, AttendanceIntervalBuilder::MIN_EXIT_INTERVAL_MINUTES);
        $this->assertSame(115, $interval->duration_minutes);
        $this->assertSame(AttendanceIntervalSource::FingerprintAndLeaveRequest, $interval->source);
        $this->assertStringContainsString('didukung timestamp fingerprint', $interval->notes);
    }

    public function test_duplicate_timestamps_in_keluar_timeline_do_not_shift_interval_pairing(): void
    {
        $unit = $this->createUnit('TK');
        $employee = $this->createEmployee($unit);
        $this->createSchedule($unit, self::DATE);
        $this->createLeave($unit, $employee, self::DATE, LeaveType::TemporaryExit);
        $this->createFingerprintLogs($unit, $employee, self::DATE, ['07:00', '10:10', '10:10', '12:05', '15:30']);

        $record = $this->process($unit, $employee);

        $this->assertSame(5, FingerprintLog::query()->where('employee_id', $employee->id)->count());
        $this->assertSame(1, $record->intervals()->count());
        $this->assertSame('10:10', substr($record->intervals()->sole()->start_time, 0, 5));
        $this->assertSame('12:05', substr($record->intervals()->sole()->end_time, 0, 5));
        $this->assertDatabaseHas('attendance_anomalies', [
            'attendance_record_id' => $record->id,
            'type' => AttendanceAnomalyType::DuplicateTimestamp->value,
        ]);
    }

    public function test_odd_keluar_timeline_keeps_known_interval_and_requires_verification(): void
    {
        $unit = $this->createUnit('TK');
        $employee = $this->createEmployee($unit);
        $this->createSchedule($unit, self::DATE);
        $this->createLeave($unit, $employee, self::DATE, LeaveType::TemporaryExit);
        $this->createFingerprintLogs($unit, $employee, self::DATE, ['07:02', '10:10', '12:05']);

        $record = $this->process($unit, $employee);

        $this->assertSame(AttendanceStatus::NeedsVerification, $record->status);
        $this->assertSame('07:02', substr($record->actual_in, 0, 5));
        $this->assertNull($record->actual_out);
        $this->assertSame(115, $record->intervals()->sole()->duration_minutes);
        $this->assertDatabaseHas('attendance_anomalies', [
            'attendance_record_id' => $record->id,
            'type' => AttendanceAnomalyType::OddFingerprintCountForExit->value,
        ]);
    }

    public function test_keluar_without_fingerprint_requires_verification(): void
    {
        $unit = $this->createUnit('TK');
        $employee = $this->createEmployee($unit);
        $this->createSchedule($unit, self::DATE);
        $this->createLeave($unit, $employee, self::DATE, LeaveType::TemporaryExit);

        $record = $this->process($unit, $employee);

        $this->assertSame(AttendanceStatus::NeedsVerification, $record->status);
        $this->assertSame(0, $record->intervals()->count());
        $this->assertDatabaseHas('attendance_anomalies', [
            'attendance_record_id' => $record->id,
            'type' => AttendanceAnomalyType::LeaveRequestWithoutFingerprint->value,
        ]);
    }

    public function test_keluar_with_one_fingerprint_keeps_existing_incomplete_scan_verification(): void
    {
        $unit = $this->createUnit('TK');
        $employee = $this->createEmployee($unit);
        $this->createSchedule($unit, self::DATE);
        $this->createLeave($unit, $employee, self::DATE, LeaveType::TemporaryExit);
        $this->createFingerprintLogs($unit, $employee, self::DATE, ['15:00']);

        $record = $this->process($unit, $employee);

        $this->assertSame(AttendanceStatus::NeedsVerification, $record->status);
        $this->assertTrue($record->needs_verification);
        $this->assertSame('15:00', substr($record->actual_in, 0, 5));
        $this->assertNull($record->actual_out);
        $this->assertSame(480, $record->late_minutes);
        $this->assertSame(0, $record->early_leave_minutes);
    }

    #[DataProvider('leaveTypesRequiringFingerprint')]
    public function test_late_early_and_exit_requests_without_fingerprint_require_verification(LeaveType $type): void
    {
        $unit = $this->createUnit('TK');
        $employee = $this->createEmployee($unit);
        $this->createSchedule($unit, self::DATE);
        $this->createLeave($unit, $employee, self::DATE, $type);

        $record = $this->process($unit, $employee);

        $this->assertSame(AttendanceStatus::NeedsVerification, $record->status);
        $this->assertTrue($record->needs_verification);
        $this->assertDatabaseHas('attendance_anomalies', [
            'attendance_record_id' => $record->id,
            'type' => AttendanceAnomalyType::LeaveRequestWithoutFingerprint->value,
        ]);
    }

    public static function leaveTypesRequiringFingerprint(): array
    {
        return [
            'late' => [LeaveType::Late],
            'early departure' => [LeaveType::EarlyDeparture],
            'temporary exit' => [LeaveType::TemporaryExit],
        ];
    }

    public function test_exit_reentry_without_leave_request_is_not_authorized_automatically(): void
    {
        $unit = $this->createUnit('TK');
        $employee = $this->createEmployee($unit);
        $this->createSchedule($unit, self::DATE);
        $this->createFingerprintLogs($unit, $employee, self::DATE, ['07:00', '10:00', '12:00', '15:30']);

        $record = $this->process($unit, $employee);

        $this->assertSame(AttendanceStatus::NeedsVerification, $record->status);
        $this->assertSame('07:00', substr($record->actual_in, 0, 5));
        $this->assertSame('15:30', substr($record->actual_out, 0, 5));
        $this->assertSame(0, $record->intervals()->count());
        $this->assertDatabaseHas('attendance_anomalies', [
            'attendance_record_id' => $record->id,
            'type' => AttendanceAnomalyType::ExitReentryWithoutLeaveRequest->value,
        ]);
    }

    public function test_duplicate_timestamps_are_logically_deduplicated_but_raw_logs_remain(): void
    {
        $unit = $this->createUnit('TK');
        $employee = $this->createEmployee($unit);
        $this->createSchedule($unit, self::DATE);
        $logs = $this->createFingerprintLogs($unit, $employee, self::DATE, ['07:00', '07:00', '12:00', '15:30']);

        $record = $this->process($unit, $employee);

        $this->assertCount(4, $logs);
        $this->assertDatabaseCount('fingerprint_logs', 4);
        $this->assertSame('07:00', substr($record->actual_in, 0, 5));
        $this->assertSame('15:30', substr($record->actual_out, 0, 5));
        $this->assertSame(0, $record->intervals()->count());
        $this->assertDatabaseHas('attendance_anomalies', [
            'attendance_record_id' => $record->id,
            'type' => AttendanceAnomalyType::DuplicateTimestamp->value,
            'severity' => 'info',
        ]);
    }

    public function test_no_fingerprint_and_no_leave_on_working_day_is_alpha(): void
    {
        $unit = $this->createUnit('TK');
        $employee = $this->createEmployee($unit);
        $this->createSchedule($unit, self::DATE);

        $this->assertSame(AttendanceStatus::Absent, $this->process($unit, $employee)->status);
    }

    public function test_non_working_calendar_day_is_holiday_without_fingerprint(): void
    {
        $unit = $this->createUnit('TK');
        $employee = $this->createEmployee($unit);
        $this->createCalendar($unit, self::DATE, false, WorkCalendarType::Holiday);

        $record = $this->process($unit, $employee);

        $this->assertSame(AttendanceStatus::Holiday, $record->status);
        $this->assertTrue($record->is_holiday);
        $this->assertFalse($record->activity_on_holiday);
    }

    public function test_fingerprint_on_holiday_stays_holiday_and_is_marked_as_activity(): void
    {
        $unit = $this->createUnit('TK');
        $employee = $this->createEmployee($unit);
        $this->createCalendar($unit, self::DATE, false, WorkCalendarType::Holiday);
        $this->createFingerprintLogs($unit, $employee, self::DATE, ['07:00', '12:00']);

        $record = $this->process($unit, $employee);

        $this->assertSame(AttendanceStatus::Holiday, $record->status);
        $this->assertTrue($record->activity_on_holiday);
        $this->assertSame('07:00', substr($record->actual_in, 0, 5));
        $this->assertSame('12:00', substr($record->actual_out, 0, 5));
    }

    public function test_calendar_workday_overrides_weekly_non_working_schedule(): void
    {
        $unit = $this->createUnit('TK');
        $employee = $this->createEmployee($unit);
        $this->createSchedule($unit, self::DATE, isWorkingDay: false);
        $this->createCalendar($unit, self::DATE, true, WorkCalendarType::SpecialEvent, '07:30', '14:00');
        $this->createFingerprintLogs($unit, $employee, self::DATE, ['07:30', '14:00']);

        $record = $this->process($unit, $employee);

        $this->assertSame(AttendanceStatus::Present, $record->status);
        $this->assertSame('07:30', substr($record->scheduled_in, 0, 5));
        $this->assertSame('14:00', substr($record->scheduled_out, 0, 5));
        $this->assertSame(0, $record->late_minutes);
        $this->assertFalse($record->is_holiday);
    }

    public function test_special_workday_calendar_override_processes_fingerprint_on_weekly_rest_day(): void
    {
        $unit = $this->createUnit('SD');
        $employee = $this->createEmployee($unit);
        $this->createSchedule($unit, self::DATE, isWorkingDay: false, start: null, end: null);
        $this->createCalendar($unit, self::DATE, true, WorkCalendarType::SpecialWorkday, '08:00', '12:00');
        $this->createFingerprintLogs($unit, $employee, self::DATE, ['08:00', '12:00']);

        $record = $this->process($unit, $employee);

        $this->assertSame(AttendanceStatus::Present, $record->status);
        $this->assertFalse($record->is_holiday);
        $this->assertSame('08:00', substr($record->scheduled_in, 0, 5));
        $this->assertSame('12:00', substr($record->scheduled_out, 0, 5));
        $this->assertSame(0, $record->late_minutes);
    }

    public function test_weekly_non_working_schedule_is_holiday_even_without_calendar_entry(): void
    {
        $unit = $this->createUnit('TK');
        $employee = $this->createEmployee($unit, assignCategory: false);
        $this->createSchedule($unit, self::DATE, isWorkingDay: false, start: null, end: null);
        $this->createFingerprintLogs($unit, $employee, self::DATE, ['08:00', '12:00']);

        $record = $this->process($unit, $employee);

        $this->assertSame(AttendanceStatus::Holiday, $record->status);
        $this->assertTrue($record->is_holiday);
        $this->assertTrue($record->activity_on_holiday);
    }

    public function test_weekly_schedule_is_used_when_no_calendar_override_exists(): void
    {
        $unit = $this->createUnit('SD');
        $employee = $this->createEmployee($unit);
        $this->createSchedule($unit, self::DATE, true, '07:00', '14:00');
        $this->createFingerprintLogs($unit, $employee, self::DATE, ['07:00', '15:30']);

        $record = $this->process($unit, $employee);

        $this->assertSame(AttendanceStatus::Present, $record->status);
        $this->assertSame('15:30', substr($record->scheduled_out, 0, 5));
    }

    public function test_missing_work_schedule_requires_verification_instead_of_alpha(): void
    {
        $unit = $this->createUnit('TK');
        $employee = $this->createEmployee($unit, assignCategory: false);

        $record = $this->process($unit, $employee);

        $this->assertSame(AttendanceStatus::NeedsVerification, $record->status);
        $this->assertDatabaseHas('attendance_anomalies', [
            'attendance_record_id' => $record->id,
            'type' => AttendanceAnomalyType::MissingSchedule->value,
        ]);
    }

    public function test_incomplete_weekly_hours_do_not_override_valid_employee_category(): void
    {
        $unit = $this->createUnit('TK');
        $employee = $this->createEmployee($unit);
        WorkSchedule::create([
            'unit_id' => $unit->id,
            'day_of_week' => CarbonImmutable::parse(self::DATE)->dayOfWeek,
            'is_working_day' => true,
            'start_time' => null,
            'end_time' => '15:30',
        ]);
        $this->createFingerprintLogs($unit, $employee, self::DATE, ['07:00', '15:30']);

        $record = $this->process($unit, $employee);

        $this->assertSame(AttendanceStatus::Present, $record->status);
        $this->assertSame('07:00', substr($record->scheduled_in, 0, 5));
        $this->assertSame('15:30', substr($record->scheduled_out, 0, 5));
        $this->assertDatabaseMissing('attendance_anomalies', [
            'attendance_record_id' => $record->id,
            'type' => AttendanceAnomalyType::InvalidSchedule->value,
        ]);
    }

    public function test_unmatched_fingerprint_is_reported_but_never_assigned_to_employee(): void
    {
        $unit = $this->createUnit('TK');
        $employee = $this->createEmployee($unit);
        $this->createSchedule($unit, self::DATE);
        $this->createFingerprintLogs($unit, $employee, self::DATE, ['07:00'], MatchingStatus::Unmatched, '99', null);

        $summary = app(AttendanceProcessingService::class)->processRange($unit->id, self::DATE, self::DATE, userId: null);

        $this->assertSame(1, $summary['unmatched_fingerprint_logs']);
        $this->assertSame(AttendanceStatus::Absent, $employee->attendanceRecords()->sole()->status);
        $this->assertNull(FingerprintLog::query()->first()->employee_id);
    }

    public function test_ambiguous_fingerprint_is_counted_and_not_assigned_to_any_employee(): void
    {
        $unit = $this->createUnit('TK');
        $employee = $this->createEmployee($unit);
        $this->createSchedule($unit, self::DATE);
        $log = $this->createFingerprintLogs($unit, $employee, self::DATE, ['07:00'], MatchingStatus::Ambiguous, '88', null)[0];

        $summary = app(AttendanceProcessingService::class)->processRange($unit->id, self::DATE, self::DATE);

        $this->assertSame(1, $summary['unmatched_fingerprint_logs']);
        $this->assertNull($log->refresh()->employee_id);
        $this->assertSame(0, AttendanceRecord::query()->where('employee_id', 88)->count());
    }

    public function test_unmatched_leave_is_not_linked_or_force_matched(): void
    {
        $unit = $this->createUnit('TK');
        $employee = $this->createEmployee($unit, 'Same Name');
        $this->createSchedule($unit, self::DATE);
        $leave = $this->createLeave($unit, null, self::DATE, LeaveType::Absent, AbsenceReason::Sick->value, 'Same Name', MatchingStatus::Unmatched);

        $record = $this->process($unit, $employee);

        $this->assertSame(AttendanceStatus::Absent, $record->status);
        $this->assertNull($record->leave_request_id);
        $this->assertNull($leave->refresh()->employee_id);
    }

    public function test_multiple_matched_leave_requests_require_verification(): void
    {
        $unit = $this->createUnit('TK');
        $employee = $this->createEmployee($unit);
        $this->createSchedule($unit, self::DATE);
        $this->createLeave($unit, $employee, self::DATE, LeaveType::Late);
        $this->createLeave($unit, $employee, self::DATE, LeaveType::EarlyDeparture);
        $this->createFingerprintLogs($unit, $employee, self::DATE, ['07:02', '15:00']);

        $record = $this->process($unit, $employee);

        $this->assertSame(AttendanceStatus::NeedsVerification, $record->status);
        $this->assertNull($record->leave_request_id);
        $this->assertSame(2, $employee->leaveRequests()->count());
        $this->assertDatabaseHas('attendance_anomalies', [
            'attendance_record_id' => $record->id,
            'type' => AttendanceAnomalyType::MultipleLeaveRequests->value,
            'severity' => 'critical',
        ]);
    }

    public function test_processing_same_date_is_idempotent_and_updates_the_existing_record(): void
    {
        $unit = $this->createUnit('TK');
        $employee = $this->createEmployee($unit);
        $this->createSchedule($unit, self::DATE);
        $this->createFingerprintLogs($unit, $employee, self::DATE, ['07:05', '15:30']);
        $service = app(AttendanceProcessingService::class);

        $first = $service->processRange($unit->id, self::DATE, self::DATE);
        $recordId = $employee->attendanceRecords()->sole()->id;
        $second = $service->processRange($unit->id, self::DATE, self::DATE);

        $this->assertSame(1, $first['created']);
        $this->assertSame(1, $second['updated']);
        $this->assertSame($recordId, $employee->attendanceRecords()->sole()->id);
        $this->assertSame(1, AttendanceRecord::query()->count());
    }

    public function test_reprocessing_recalculates_metrics_after_employee_category_change(): void
    {
        $unit = $this->createUnit('TK');
        $employee = $this->createEmployee($unit);
        $this->createSchedule($unit, self::DATE, true, '07:00', '15:30');
        $this->createFingerprintLogs($unit, $employee, self::DATE, ['07:05', '15:30']);
        $service = app(AttendanceProcessingService::class);
        $service->processEmployeeDate($unit->id, $employee->id, self::DATE);
        $this->assertSame(5, $employee->attendanceRecords()->sole()->late_minutes);

        $employee->category->update(['start_time' => '07:10']);
        $service->processEmployeeDate($unit->id, $employee->id, self::DATE);

        $record = $employee->attendanceRecords()->sole();
        $this->assertSame(0, $record->late_minutes);
        $this->assertSame(AttendanceStatus::Present, $record->status);
    }

    public function test_actual_in_and_actual_out_are_derived_from_the_first_and_last_raw_scans_only(): void
    {
        $unit = $this->createUnit('TK');
        $employee = $this->createEmployee($unit);
        $this->createSchedule($unit, self::DATE, true, '07:00', '15:30');
        $this->createFingerprintLogs($unit, $employee, self::DATE, ['14:00', '06:55', '14:01', '06:56']);
        $before = FingerprintLog::query()->count();

        $service = app(AttendanceProcessingService::class);
        $service->processEmployeeDate($unit->id, $employee->id, self::DATE);

        $record = $employee->attendanceRecords()->sole();

        $this->assertSame('06:55', substr($record->actual_in, 0, 5));
        $this->assertSame('14:01', substr($record->actual_out, 0, 5));
        $this->assertSame($before, FingerprintLog::query()->count());
        $this->assertSame(1, AttendanceRecord::query()->where('employee_id', $employee->id)->whereDate('attendance_date', self::DATE)->count());
    }

    public function test_recap_counts_at_most_two_endpoints_for_multiple_fingerprint_scans(): void
    {
        $unit = $this->createUnit('TK');
        $category = $this->createCategory('Recap test', '07:00', '15:40');
        $employee = $this->createEmployee($unit, category: $category);
        $this->createSchedule($unit, self::DATE, true, '07:00', '15:40');
        $this->createFingerprintLogs($unit, $employee, self::DATE, ['06:48', '06:49', '12:00', '15:40']);

        $record = $this->process($unit, $employee);
        $result = app(AttendanceRecapCalculator::class)->calculate($record);

        $this->assertSame('06:48', $result['actual_in']);
        $this->assertSame('15:40', $result['actual_out']);
        $this->assertSame(1, $result['hadir']);
        $this->assertSame(0, $result['terlambat']);
        $this->assertSame(0, $result['pulang_awal']);
        $this->assertSame(2, $result['total_finger']);
    }

    public function test_actual_times_are_split_by_employee_and_date_without_crossing_day_boundaries(): void
    {
        $unit = $this->createUnit('TK');
        $employee = $this->createEmployee($unit);
        $this->createSchedule($unit, self::DATE, true, '07:00', '15:30');
        $this->createFingerprintLogs($unit, $employee, self::DATE, ['06:55', '14:01']);
        $nextDate = '2026-08-04';
        $this->createFingerprintLogs($unit, $employee, $nextDate, ['06:57', '14:00']);
        $service = app(AttendanceProcessingService::class);

        $service->processRange($unit->id, self::DATE, $nextDate, $employee->id);

        $records = $employee->attendanceRecords()->orderBy('attendance_date')->get();
        $this->assertCount(2, $records);
        $this->assertSame('06:55', substr($records[0]->actual_in, 0, 5));
        $this->assertSame('14:01', substr($records[0]->actual_out, 0, 5));
        $this->assertSame('06:57', substr($records[1]->actual_in, 0, 5));
        $this->assertSame('14:00', substr($records[1]->actual_out, 0, 5));
    }

    public function test_reprocessing_keeps_a_single_record_when_actual_times_are_recomputed_from_raw_logs(): void
    {
        $unit = $this->createUnit('TK');
        $employee = $this->createEmployee($unit);
        $this->createSchedule($unit, self::DATE, true, '07:00', '15:30');
        $this->createFingerprintLogs($unit, $employee, self::DATE, ['06:55', '14:01']);
        $service = app(AttendanceProcessingService::class);

        $service->processEmployeeDate($unit->id, $employee->id, self::DATE);
        $service->processEmployeeDate($unit->id, $employee->id, self::DATE);

        $this->assertSame(1, AttendanceRecord::query()->where('employee_id', $employee->id)->whereDate('attendance_date', self::DATE)->count());
        $this->assertSame('06:55', substr($employee->attendanceRecords()->sole()->actual_in, 0, 5));
        $this->assertSame('14:01', substr($employee->attendanceRecords()->sole()->actual_out, 0, 5));
    }

    public function test_record_marked_for_verification_is_preserved_during_reprocessing(): void
    {
        $unit = $this->createUnit('TK');
        $employee = $this->createEmployee($unit);
        $this->createSchedule($unit, self::DATE);
        $this->createFingerprintLogs($unit, $employee, self::DATE, ['07:00', '10:00', '12:00', '15:30']);
        $service = app(AttendanceProcessingService::class);
        $result = $service->processEmployeeDate($unit->id, $employee->id, self::DATE);
        $record = AttendanceRecord::query()->findOrFail($result['attendance_record_id']);

        $this->assertSame(AttendanceAnomalyType::ExitReentryWithoutLeaveRequest, $record->anomalies()->sole()->type);
        $original = $record->fresh()->toArray();
        $leave = $this->createLeave($unit, $employee, self::DATE, LeaveType::TemporaryExit);
        $reprocessResult = $service->processEmployeeDate($unit->id, $employee->id, self::DATE);

        $this->assertTrue($reprocessResult['preserved_for_review']);
        $this->assertSame($original, $record->fresh()->toArray());
        $this->assertNull($record->leave_request_id);
        $this->assertSame(0, $record->intervals()->count());
        $this->assertSame(1, $record->anomalies()->where('type', AttendanceAnomalyType::ExitReentryWithoutLeaveRequest->value)->count());

        $service->processEmployeeDate($unit->id, $employee->id, self::DATE);
        $this->assertSame($original, $record->refresh()->toArray());
        $this->assertSame(1, AttendanceRecord::query()->where('employee_id', $employee->id)->whereDate('attendance_date', self::DATE)->count());
    }

    public function test_units_use_their_own_schedules_and_same_fingerprint_id_does_not_cross_match(): void
    {
        $tk = $this->createUnit('TK');
        $sd = $this->createUnit('SD');
        $tkCategory = $this->createCategory('TK Category', '07:00', '15:30');
        $sdCategory = $this->createCategory('SD Category', '08:00', '14:00');
        $tkEmployee = $this->createEmployee($tk, 'TK Employee', '12', category: $tkCategory);
        $sdEmployee = $this->createEmployee($sd, 'SD Employee', '12', category: $sdCategory);
        $this->createSchedule($tk, self::DATE, true, '07:00', '15:30');
        $this->createSchedule($sd, self::DATE, true, '08:00', '14:00');
        $this->createFingerprintLogs($tk, $tkEmployee, self::DATE, ['07:05', '15:30'], fingerprintId: '12');
        $this->createFingerprintLogs($sd, $sdEmployee, self::DATE, ['08:00', '14:00'], fingerprintId: '12');

        $tkRecord = $this->process($tk, $tkEmployee);
        $sdRecord = $this->process($sd, $sdEmployee);

        $this->assertSame(5, $tkRecord->late_minutes);
        $this->assertSame(0, $sdRecord->late_minutes);
        $this->assertSame($tk->id, $tkRecord->unit_id);
        $this->assertSame($sd->id, $sdRecord->unit_id);
    }

    public function test_employee_filter_rejects_an_employee_from_another_unit(): void
    {
        $tk = $this->createUnit('TK');
        $sd = $this->createUnit('SD');
        $employee = $this->createEmployee($sd);

        try {
            app(AttendanceProcessingService::class)->processRange($tk->id, self::DATE, self::DATE, $employee->id);
            $this->fail('An employee from another unit must not be processed.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('employee_id', $exception->errors());
        }
    }

    public function test_inactive_employee_is_skipped_and_does_not_receive_alpha(): void
    {
        $unit = $this->createUnit('TK');
        $employee = $this->createEmployee($unit, 'Inactive', status: EmployeeStatus::Inactive);

        $summary = app(AttendanceProcessingService::class)->processRange($unit->id, self::DATE, self::DATE);

        $this->assertSame(0, $summary['processed']);
        $this->assertSame(0, $employee->attendanceRecords()->count());
    }

    public function test_inactive_employee_processing_preserves_and_reports_existing_attendance(): void
    {
        $unit = $this->createUnit('TK');
        $employee = $this->createEmployee($unit, 'Inactive history', status: EmployeeStatus::Inactive);
        $record = AttendanceRecord::create([
            'employee_id' => $employee->id,
            'unit_id' => $unit->id,
            'attendance_date' => self::DATE,
            'status' => AttendanceStatus::Present,
            'scheduled_in' => '07:00',
            'actual_in' => '07:00',
            'scheduled_out' => '15:30',
            'actual_out' => '15:30',
            'late_minutes' => 0,
            'early_leave_minutes' => 0,
            'is_holiday' => false,
            'activity_on_holiday' => false,
            'needs_verification' => false,
        ]);
        $original = $record->fresh()->toArray();
        $service = app(AttendanceProcessingService::class);

        $singleDate = $service->processEmployeeDate($unit->id, $employee->id, self::DATE);
        $summary = $service->processRange($unit->id, self::DATE, self::DATE);

        $this->assertSame('skipped_ineligible_employee', $singleDate['status']);
        $this->assertTrue($singleDate['preserved']);
        $this->assertSame($record->id, $singleDate['attendance_record_id']);
        $this->assertSame(1, $summary['skipped_ineligible_employee']);
        $this->assertSame(1, $summary['preserved_ineligible_attendance']);
        $this->assertSame(0, $summary['removed_ineligible_attendance']);
        $this->assertSame([
            [
                'employee_id' => $employee->id,
                'attendance_date' => self::DATE,
                'reason' => 'inactive',
                'attendance_record_id' => $record->id,
            ],
        ], $summary['review_cases']);
        $this->assertSame($original, $record->fresh()->toArray());
    }

    public function test_reprocessing_preserves_existing_attendance_marked_for_verification(): void
    {
        $unit = $this->createUnit('TK');
        $employee = $this->createEmployee($unit, 'Needs review');
        $this->createSchedule($unit, self::DATE);
        $this->createFingerprintLogs($unit, $employee, self::DATE, ['07:00', '15:30']);
        $record = AttendanceRecord::create([
            'employee_id' => $employee->id,
            'unit_id' => $unit->id,
            'attendance_date' => self::DATE,
            'status' => AttendanceStatus::NeedsVerification,
            'scheduled_in' => '07:00',
            'actual_in' => '07:15',
            'scheduled_out' => '15:30',
            'actual_out' => '15:30',
            'late_minutes' => 15,
            'early_leave_minutes' => 0,
            'needs_verification' => true,
            'verification_note' => 'Review this historical correction.',
        ]);
        $original = $record->fresh()->toArray();

        $summary = app(AttendanceProcessingService::class)->processRange($unit->id, self::DATE, self::DATE);

        $this->assertSame(0, $summary['processed']);
        $this->assertSame(1, $summary['preserved_for_review']);
        $this->assertSame('attendance_record_needs_verification', $summary['review_cases'][0]['reason']);
        $this->assertSame($original, $record->fresh()->toArray());
    }

    public function test_invalid_range_is_rejected(): void
    {
        $unit = $this->createUnit('TK');

        try {
            app(AttendanceProcessingService::class)->processRange($unit->id, '2026-08-04', '2026-08-03');
            $this->fail('A reversed date range must be rejected.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('date_to', $exception->errors());
        }

        try {
            app(AttendanceProcessingService::class)->processRange($unit->id, '03/08/2026', '2026-08-04');
            $this->fail('A non-ISO date must be rejected.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('date', $exception->errors());
        }
    }

    public function test_processing_transaction_rolls_back_record_interval_and_anomaly_on_failure(): void
    {
        $unit = $this->createUnit('TK');
        $employee = $this->createEmployee($unit);
        $this->createSchedule($unit, self::DATE);
        $this->createLeave($unit, $employee, self::DATE, LeaveType::TemporaryExit);
        $this->createFingerprintLogs($unit, $employee, self::DATE, ['07:00', '10:00', '12:00', '15:30']);
        $injected = false;

        DB::listen(function (QueryExecuted $query) use (&$injected): void {
            $sql = strtolower(trim($query->sql));
            if (! $injected && str_starts_with($sql, 'insert') && str_contains($sql, 'attendance_intervals')) {
                $injected = true;
                throw new QueryException($query->connectionName, $query->sql, $query->bindings, new \RuntimeException('Injected interval failure.'));
            }
        });

        try {
            $this->process($unit, $employee);
            $this->fail('The injected interval failure should roll back processing.');
        } catch (QueryException) {
            $this->assertTrue(true);
        }

        $this->assertTrue($injected);
        $this->assertDatabaseCount('attendance_records', 0);
        $this->assertDatabaseCount('attendance_intervals', 0);
        $this->assertDatabaseCount('attendance_anomalies', 0);
        $this->assertDatabaseCount('fingerprint_logs', 4);
    }

    public function test_processing_endpoint_requires_auth_validates_dates_and_returns_summary(): void
    {
        $unit = $this->createUnit('TK');
        $employee = $this->createEmployee($unit);
        $this->createSchedule($unit, self::DATE);

        $this->postJson('/attendance/process', [])->assertUnauthorized();

        $this->actingAs(User::factory()->create())
            ->postJson('/attendance/process', [
                'unit_id' => $unit->id,
                'date_from' => '2026-08-04',
                'date_to' => self::DATE,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('date_to');

        $this->createFingerprintLogs($unit, $employee, self::DATE, ['07:00', '15:30']);

        $this->postJson('/attendance/process', [
            'unit_id' => $unit->id,
            'date_from' => self::DATE,
            'date_to' => self::DATE,
            'employee_id' => $employee->id,
        ])
            ->assertOk()
            ->assertJsonPath('data.processed', 1)
            ->assertJsonPath('data.created', 1)
            ->assertJsonPath('data.verification', 0);
    }

    public function test_range_processing_returns_summary_and_audits_the_action(): void
    {
        $unit = $this->createUnit('SD');
        $present = $this->createEmployee($unit, 'Present');
        $absent = $this->createEmployee($unit, 'No fingerprint');
        $this->createSchedule($unit, self::DATE, true, '07:00', '14:00');
        $this->createFingerprintLogs($unit, $present, self::DATE, ['07:00', '15:30']);
        $user = User::factory()->create();

        $summary = app(AttendanceProcessingService::class)->processRange($unit->id, self::DATE, self::DATE, userId: $user->id);

        $this->assertSame(2, $summary['processed']);
        $this->assertSame(2, $summary['created']);
        $this->assertSame(0, $summary['failed']);
        $this->assertSame(AttendanceStatus::Present, $present->attendanceRecords()->sole()->status);
        $this->assertSame(AttendanceStatus::Absent, $absent->attendanceRecords()->sole()->status);
        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $user->id,
            'action' => 'attendance.process',
            'module' => 'attendance',
        ]);
    }

    private function process(Unit $unit, Employee $employee, string $date = self::DATE): AttendanceRecord
    {
        $result = app(AttendanceProcessingService::class)->processEmployeeDate($unit->id, $employee->id, $date);

        return AttendanceRecord::query()->findOrFail($result['attendance_record_id']);
    }

    private function createUnit(string $code): Unit
    {
        return Unit::create(['name' => $code, 'code' => $code]);
    }

    private function createEmployee(
        Unit $unit,
        string $name = 'Test Employee',
        ?string $fingerprintId = null,
        EmployeeStatus $status = EmployeeStatus::Active,
        ?WorkScheduleCategory $category = null,
        bool $assignCategory = true,
    ): Employee {
        $category ??= $assignCategory
            ? $this->createCategory('Default '.(++$this->categorySequence), '07:00', '15:30')
            : null;

        $employee = Employee::create([
            'unit_id' => $unit->id,
            'category_id' => $category?->id,
            'fingerprint_id' => $fingerprintId,
            'nama_lengkap' => $name,
            'status' => $status,
        ]);

        return $employee;
    }

    private function createCategory(string $name, string $start, string $end): WorkScheduleCategory
    {
        return WorkScheduleCategory::create([
            'name' => $name,
            'start_time' => $start,
            'end_time' => $end,
        ]);
    }

    private function createProfile(
        WorkScheduleCategory $category,
        string $name,
        string $start,
        string $end,
    ): WorkScheduleProfile {
        return app(WorkScheduleProfileService::class)->saveForCategory($category, [
            'name' => $name,
            'is_normal' => '0',
            'rules' => $this->profileRules($start, $end),
        ]);
    }

    private function profileRules(string $start, string $end): array
    {
        $rules = [];
        foreach (range(0, 6) as $day) {
            $rules[$day] = [
                'is_working_day' => $day !== 0,
                'start_time' => $day === 0 ? '' : $start,
                'end_time' => $day === 0 ? '' : $end,
            ];
        }

        return $rules;
    }

    private function createSchedule(
        Unit $unit,
        string $date,
        bool $isWorkingDay = true,
        ?string $start = '07:00',
        ?string $end = '15:30',
    ): WorkSchedule {
        return WorkSchedule::create([
            'unit_id' => $unit->id,
            'day_of_week' => CarbonImmutable::parse($date)->dayOfWeek,
            'is_working_day' => $isWorkingDay,
            'start_time' => $start,
            'end_time' => $end,
        ]);
    }

    private function createCalendar(
        Unit $unit,
        string $date,
        bool $isWorkingDay,
        WorkCalendarType $type,
        ?string $start = null,
        ?string $end = null,
    ): WorkCalendar {
        return WorkCalendar::create([
            'unit_id' => $unit->id,
            'date' => $date,
            'type' => $type,
            'is_working_day' => $isWorkingDay,
            'start_time' => $start,
            'end_time' => $end,
        ]);
    }

    private function createFingerprintLogs(
        Unit $unit,
        Employee $employee,
        string $date,
        array $times,
        MatchingStatus $matchingStatus = MatchingStatus::Matched,
        ?string $fingerprintId = null,
        ?int $employeeId = null,
    ): array {
        $batch = FingerprintImportBatch::create([
            'unit_id' => $unit->id,
            'filename' => 'fixture.xls',
            'file_hash' => hash('sha256', random_bytes(32)),
            'status' => ImportBatchStatus::Completed,
        ]);
        $logs = [];

        foreach ($times as $index => $time) {
            $logs[] = FingerprintLog::create([
                'import_batch_id' => $batch->id,
                'unit_id' => $unit->id,
                'employee_id' => $employeeId ?? ($matchingStatus === MatchingStatus::Matched ? $employee->id : null),
                'fingerprint_id' => $fingerprintId ?? $employee->fingerprint_id ?? '12',
                'attendance_date' => $date,
                'attendance_time' => $time.':00',
                'raw_value' => $time,
                'matching_status' => $matchingStatus,
            ]);
        }

        return $logs;
    }

    private function createLeave(
        Unit $unit,
        ?Employee $employee,
        string $date,
        LeaveType $type,
        ?string $absenceReason = null,
        string $rawName = 'Test Employee',
        MatchingStatus $matchingStatus = MatchingStatus::Matched,
    ): LeaveRequest {
        $batch = LeaveImportBatch::create([
            'unit_id' => $unit->id,
            'filename' => 'fixture.xlsx',
            'file_hash' => hash('sha256', random_bytes(32)),
            'status' => ImportBatchStatus::Completed,
        ]);

        return LeaveRequest::create([
            'import_batch_id' => $batch->id,
            'unit_id' => $unit->id,
            'employee_id' => $matchingStatus === MatchingStatus::Matched ? $employee?->id : null,
            'raw_name' => $rawName,
            'normalized_name' => mb_strtolower($rawName, 'UTF-8'),
            'request_date' => $date,
            'request_time' => '10:00:00',
            'leave_type' => $type,
            'raw_leave_type' => $type->value,
            'description' => $absenceReason === null ? 'Test description.' : 'Test absence reason.',
            'matching_status' => $matchingStatus,
            'absence_reason' => $absenceReason,
        ]);
    }
}
