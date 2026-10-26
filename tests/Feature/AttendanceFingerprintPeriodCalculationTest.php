<?php

namespace Tests\Feature;

use App\Data\Reports\AttendanceFingerprintPeriodReportFilters;
use App\Enums\AttendanceIntervalSource;
use App\Enums\AttendanceStatus;
use App\Enums\AttendanceIntervalType;
use App\Enums\EmployeeStatus;
use App\Enums\ImportBatchStatus;
use App\Enums\LeaveApprovalStatus;
use App\Enums\LeaveType;
use App\Enums\MatchingStatus;
use App\Enums\WorkCalendarType;
use App\Models\AttendanceRecord;
use App\Models\AttendanceInterval;
use App\Models\Employee;
use App\Models\FingerprintImportBatch;
use App\Models\FingerprintLog;
use App\Models\LeaveImportBatch;
use App\Models\LeaveRequest;
use App\Models\Unit;
use App\Models\WorkCalendar;
use App\Models\WorkSchedule;
use App\Models\WorkScheduleCategory;
use App\Services\Reports\AttendanceFingerprintPeriodReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AttendanceFingerprintPeriodCalculationTest extends TestCase
{
    use RefreshDatabase;

    public function test_calendar_overrides_weekly_schedule_and_fingerprint_inference(): void
    {
        $unit = $this->unit();
        $employee = $this->employee($unit, 'One');
        $this->weekly($unit, true, '07:00', '15:00');
        $this->calendar($unit, '2026-09-01', false);
        $this->logs($unit, $employee, '2026-09-01', ['07:00']);

        $row = $this->report($unit)['data'][0];

        $this->assertSame('non_working', $row['daily'][0]['day_status']);
        $this->assertSame('calendar', $row['daily'][0]['source']);
        $this->assertSame(0, $row['hadir']);
        $this->assertSame(0, $row['total_finger']);
    }

    public function test_calendar_workday_remains_working_without_collective_fingerprints(): void
    {
        $unit = $this->unit();
        $this->employee($unit, 'Absent');
        $this->calendar($unit, '2026-09-01', true, '07:00', '15:00');

        $row = $this->report($unit)['data'][0];

        $this->assertSame('working', $row['daily'][0]['day_status']);
        $this->assertSame('calendar', $row['daily'][0]['source']);
        $this->assertSame(1, $row['hari_kerja']);
        $this->assertSame(2, $row['total_finger']);
        $this->assertSame(1, $row['tidak_hadir']);
    }

    public function test_weekly_schedule_sets_day_status_when_calendar_is_absent(): void
    {
        $unit = $this->unit();
        $employee = $this->employee($unit, 'Weekly');
        $this->weekly($unit, true, '07:00', '15:00');

        $row = $this->report($unit)['data'][0];

        $this->assertSame('working', $row['daily'][0]['day_status']);
        $this->assertSame('weekly_schedule', $row['daily'][0]['source']);
        $this->assertTrue($row['history_limited']);
        $this->assertSame(1, $row['tidak_hadir']);
    }

    public function test_collective_inference_uses_more_than_half_of_unique_unit_employees(): void
    {
        $unit = $this->unit();
        $employees = [];
        for ($index = 1; $index <= 10; $index++) {
            $employees[] = $this->employee($unit, 'Employee '.$index);
        }
        for ($index = 0; $index < 6; $index++) {
            $this->logs($unit, $employees[$index], '2026-09-01', ['07:00', '07:01', '07:02']);
        }

        $result = $this->report($unit);

        $this->assertSame(1, $result['summary']['provisional_day_count']);
        $this->assertSame('working', $result['data'][0]['daily'][0]['day_status']);
        $this->assertSame('fingerprint_inference', $result['data'][0]['daily'][0]['source']);
        $this->assertTrue($result['data'][0]['daily'][0]['is_provisional']);
    }

    public function test_exactly_half_and_below_half_leave_day_unknown(): void
    {
        $unit = $this->unit();
        $employees = [];
        for ($index = 1; $index <= 10; $index++) {
            $employees[] = $this->employee($unit, 'Employee '.$index);
        }
        for ($index = 0; $index < 4; $index++) {
            $this->logs($unit, $employees[$index], '2026-09-01', ['07:00']);
        }

        $belowHalf = $this->report($unit);
        $this->assertSame('unknown', $belowHalf['data'][0]['daily'][0]['day_status']);
        $this->assertNull($belowHalf['data'][0]['hari_kerja']);
        $this->assertNull($belowHalf['data'][0]['total_finger']);

        $this->logs($unit, $employees[4], '2026-09-01', ['07:00']);
        $exactlyHalf = $this->report($unit);
        $this->assertSame('unknown', $exactlyHalf['data'][0]['daily'][0]['day_status']);
        $this->assertSame(1, $exactlyHalf['summary']['unknown_date_count']);
    }

    public function test_many_scans_from_one_employee_count_once_for_inference(): void
    {
        $unit = $this->unit();
        $employees = [];
        for ($index = 1; $index <= 10; $index++) {
            $employees[] = $this->employee($unit, 'Employee '.$index);
        }
        $this->logs($unit, $employees[0], '2026-09-01', ['07:00', '07:01', '07:02', '07:03']);
        for ($index = 1; $index < 5; $index++) {
            $this->logs($unit, $employees[$index], '2026-09-01', ['07:00']);
        }

        $result = $this->report($unit);

        $this->assertSame('unknown', $result['data'][0]['daily'][0]['day_status']);
        $this->assertSame(0, $result['summary']['provisional_day_count']);
    }

    public function test_category_filter_does_not_change_inference_denominator(): void
    {
        $unit = $this->unit();
        $targetCategory = WorkScheduleCategory::query()->create(['name' => 'Target category']);
        $otherCategory = WorkScheduleCategory::query()->create(['name' => 'Other category']);
        $employees = [];
        for ($index = 1; $index <= 10; $index++) {
            $category = $index <= 2 ? $targetCategory : $otherCategory;
            $employees[] = $this->employee($unit, 'Employee '.$index, $category);
        }
        for ($index = 0; $index < 6; $index++) {
            $this->logs($unit, $employees[$index], '2026-09-01', ['07:00']);
        }
        $filters = AttendanceFingerprintPeriodReportFilters::fromValidated([
            'unit_id' => $unit->id,
            'start_date' => '2026-09-01',
            'end_date' => '2026-09-01',
            'category_id' => $targetCategory->id,
        ]);

        $result = app(AttendanceFingerprintPeriodReportService::class)->generate($filters);

        $this->assertCount(2, $result['data']);
        $this->assertSame(1, $result['summary']['provisional_day_count']);
        $this->assertSame('fingerprint_inference', $result['data'][0]['daily'][0]['source']);
    }

    public function test_empty_denominator_leaves_day_unknown(): void
    {
        $result = $this->report($this->unit());

        $this->assertSame([], $result['data']);
        $this->assertSame(1, $result['summary']['unknown_date_count']);
        $this->assertSame(0, $result['summary']['provisional_day_count']);
    }

    public function test_calendar_scan_candidates_use_nearest_schedule_and_early_tie_break(): void
    {
        $unit = $this->unit();
        $employee = $this->employee($unit, 'Tie');
        $this->calendar($unit, '2026-09-01', true, '09:00', '17:00');
        $this->logs($unit, $employee, '2026-09-01', ['08:50', '09:10']);

        $day = $this->report($unit)['data'][0]['daily'][0];

        $this->assertSame('08:50', $day['selected_in']);
        $this->assertSame('09:10', $day['selected_out']);
        $this->assertSame(0, $day['metrics']['terlambat']);
        $this->assertSame(1, $day['metrics']['pulang_awal']);
    }

    public function test_deduplication_uses_first_timestamp_and_does_not_chain_groups(): void
    {
        $unit = $this->unit();
        $employee = $this->employee($unit, 'Dedupe');
        $this->calendar($unit, '2026-09-01', true, '07:00', '15:00');
        $logs = $this->logs($unit, $employee, '2026-09-01', ['06:55', '06:57', '06:59', '15:00']);
        $logs->each(fn (FingerprintLog $log) => $log->refresh());
        $before = $logs->map(fn (FingerprintLog $log) => $log->getAttributes())->all();

        $day = $this->report($unit)['data'][0]['daily'][0];

        $this->assertSame(3, $day['distinct_time_count']);
        $this->assertSame('06:59', $day['selected_in']);
        $this->assertSame('15:00', $day['selected_out']);
        $this->assertEquals($before, $logs->map(fn (FingerprintLog $log) => $log->fresh()->getAttributes())->all());
    }

    public function test_scan_more_than_two_minutes_from_group_start_starts_a_new_group(): void
    {
        $unit = $this->unit();
        $employee = $this->employee($unit, 'Boundary');
        $this->calendar($unit, '2026-09-01', true, '07:00', '15:00');
        $this->logs($unit, $employee, '2026-09-01', ['06:55', '06:58', '15:00']);

        $day = $this->report($unit)['data'][0]['daily'][0];

        $this->assertSame(3, $day['distinct_time_count']);
        $this->assertSame('06:58', $day['selected_in']);
    }

    public function test_single_scan_is_used_for_only_one_endpoint_and_missing_metrics_are_null(): void
    {
        $unit = $this->unit();
        $employee = $this->employee($unit, 'Single');
        $this->calendar($unit, '2026-09-01', true, '07:00', '15:00');
        $this->logs($unit, $employee, '2026-09-01', ['07:10']);

        $day = $this->report($unit)['data'][0]['daily'][0];

        $this->assertSame(1, $day['metrics']['hadir']);
        $this->assertSame('07:10', $day['selected_in']);
        $this->assertNull($day['selected_out']);
        $this->assertSame(1, $day['metrics']['terlambat']);
        $this->assertNull($day['metrics']['pulang_awal']);
    }

    public function test_single_scan_equal_distance_to_both_schedules_is_ambiguous(): void
    {
        $unit = $this->unit();
        $employee = $this->employee($unit, 'Ambiguous');
        $this->calendar($unit, '2026-09-01', true, '07:00', '15:00');
        $this->logs($unit, $employee, '2026-09-01', ['11:00']);

        $row = $this->report($unit)['data'][0];

        $this->assertSame(1, $row['hadir']);
        $this->assertTrue($row['is_ambiguous']);
        $this->assertNull($row['terlambat']);
        $this->assertNull($row['pulang_awal']);
    }

    public function test_start_scan_exactly_on_or_before_schedule_is_not_late(): void
    {
        $unit = $this->unit();
        $employee = $this->employee($unit, 'Start candidates');
        $this->calendar($unit, '2026-09-01', true, '07:00', '15:00');
        $this->calendar($unit, '2026-09-02', true, '07:00', '15:00');
        $this->logs($unit, $employee, '2026-09-01', ['07:00', '15:00']);
        $this->logs($unit, $employee, '2026-09-02', ['06:50', '15:00']);
        $filters = AttendanceFingerprintPeriodReportFilters::fromValidated([
            'unit_id' => $unit->id,
            'start_date' => '2026-09-01',
            'end_date' => '2026-09-02',
        ]);

        $days = app(AttendanceFingerprintPeriodReportService::class)
            ->generate($filters)['data'][0]['daily'];

        $this->assertSame('07:00', $days[0]['selected_in']);
        $this->assertSame(0, $days[0]['metrics']['terlambat']);
        $this->assertSame('06:50', $days[1]['selected_in']);
        $this->assertSame(0, $days[1]['metrics']['terlambat']);
    }

    public function test_single_scan_near_end_does_not_create_an_in_candidate(): void
    {
        $unit = $this->unit();
        $employee = $this->employee($unit, 'End candidate');
        $this->calendar($unit, '2026-09-01', true, '07:00', '15:00');
        $this->logs($unit, $employee, '2026-09-01', ['14:55']);

        $day = $this->report($unit)['data'][0]['daily'][0];

        $this->assertSame(1, $day['metrics']['hadir']);
        $this->assertNull($day['selected_in']);
        $this->assertSame('14:55', $day['selected_out']);
        $this->assertNull($day['metrics']['terlambat']);
    }

    public function test_endpoint_candidate_collision_uses_next_ranked_out_candidate(): void
    {
        $unit = $this->unit();
        $employee = $this->employee($unit, 'Shared candidate');
        $this->calendar($unit, '2026-09-01', true, '07:00', '08:00');
        $this->logs($unit, $employee, '2026-09-01', ['06:00', '07:30', '09:00']);

        $day = $this->report($unit)['data'][0]['daily'][0];

        $this->assertSame('07:30', $day['selected_in']);
        $this->assertSame('09:00', $day['selected_out']);
        $this->assertSame('selected', $day['candidate_status']);
        $this->assertFalse($day['is_ambiguous']);
        $this->assertSame(1, $day['metrics']['terlambat']);
        $this->assertSame(0, $day['metrics']['pulang_awal']);
    }

    public function test_official_holiday_fingerprint_does_not_count_as_presence(): void
    {
        $unit = $this->unit();
        $employee = $this->employee($unit, 'Holiday');
        $this->calendar($unit, '2026-09-01', false);
        $this->logs($unit, $employee, '2026-09-01', ['07:00', '15:00']);

        $row = $this->report($unit)['data'][0];

        $this->assertSame(0, $row['hadir']);
        $this->assertSame(0, $row['total_finger']);
        $this->assertSame(0, $row['hari_kerja']);
    }

    public function test_unverifiable_duty_is_not_used_as_attendance_or_absence_exemption(): void
    {
        $unit = $this->unit();
        $employee = $this->employee($unit, 'Unverified duty');
        $this->calendar($unit, '2026-09-01', true, '07:00', '15:00');
        LeaveRequest::query()->create([
            'import_batch_id' => null,
            'source' => 'manual',
            'unit_id' => $unit->id,
            'employee_id' => $employee->id,
            'raw_name' => $employee->nama_lengkap,
            'normalized_name' => strtolower($employee->nama_lengkap),
            'request_date' => '2026-09-01',
            'leave_type' => LeaveType::OfficialDuty,
            'raw_leave_type' => LeaveType::OfficialDuty->value,
            'matching_status' => MatchingStatus::Matched,
        ]);

        $row = $this->report($unit)['data'][0];

        $this->assertSame(0, $row['hadir']);
        $this->assertSame(1, $row['tidak_hadir']);
        $this->assertNull($row['dinas']);
        $this->assertTrue($row['is_ambiguous']);
        $this->assertSame('unverifiable', $row['daily'][0]['leave_status']);
        $this->assertNotNull($row['daily'][0]['reason_or_warning']);
    }

    public function test_confirmed_manual_absence_and_duty_are_verified_without_fingerprint(): void
    {
        $unit = $this->unit();
        $this->calendar($unit, '2026-09-01', true, '07:00', '15:00');
        $absentEmployee = $this->employee($unit, 'Confirmed absent');
        $dutyEmployee = $this->employee($unit, 'Confirmed duty');
        $absentLeave = $this->manualLeave(
            $unit,
            $absentEmployee,
            LeaveType::Absent,
            '2026-09-01',
            LeaveApprovalStatus::Confirmed,
        );
        $dutyLeave = $this->manualLeave(
            $unit,
            $dutyEmployee,
            LeaveType::OfficialDuty,
            '2026-09-01',
            LeaveApprovalStatus::Confirmed,
        );
        $this->attendance($unit, $absentEmployee, $absentLeave, '2026-09-01', AttendanceStatus::Absent);
        $this->attendance($unit, $dutyEmployee, $dutyLeave, '2026-09-01', AttendanceStatus::OfficialDuty);

        $result = $this->report($unit);
        $absentDay = collect($result['data'])->firstWhere('employee_id', $absentEmployee->id)['daily'][0];
        $dutyDay = collect($result['data'])->firstWhere('employee_id', $dutyEmployee->id)['daily'][0];

        $this->assertSame('no_fingerprint', $absentDay['candidate_status']);
        $this->assertSame('confirmed', $absentDay['leave_approval_status']);
        $this->assertSame('verified', $absentDay['leave_status']);
        $this->assertSame(1, $absentDay['metrics']['tidak_hadir']);
        $this->assertFalse($absentDay['is_ambiguous']);

        $this->assertSame('no_fingerprint', $dutyDay['candidate_status']);
        $this->assertSame('confirmed', $dutyDay['leave_approval_status']);
        $this->assertSame('verified', $dutyDay['leave_status']);
        $this->assertSame(1, $dutyDay['metrics']['dinas']);
        $this->assertFalse($dutyDay['is_ambiguous']);
    }

    public function test_pending_manual_leave_is_not_verified_even_with_a_matching_attendance_record(): void
    {
        $unit = $this->unit();
        $employee = $this->employee($unit, 'Pending duty');
        $this->calendar($unit, '2026-09-01', true, '07:00', '15:00');
        $leave = $this->manualLeave(
            $unit,
            $employee,
            LeaveType::OfficialDuty,
            '2026-09-01',
            LeaveApprovalStatus::Pending,
        );
        $this->attendance($unit, $employee, $leave, '2026-09-01', AttendanceStatus::OfficialDuty);

        $day = $this->report($unit)['data'][0]['daily'][0];

        $this->assertSame('pending', $day['leave_approval_status']);
        $this->assertSame('unverifiable', $day['leave_status']);
        $this->assertSame(1, $day['metrics']['tidak_hadir']);
        $this->assertNull($day['metrics']['dinas']);
    }

    public function test_confirmed_leave_with_a_mismatched_attendance_link_is_not_verified(): void
    {
        $unit = $this->unit();
        $employee = $this->employee($unit, 'Mismatched attendance');
        $this->calendar($unit, '2026-09-01', true, '07:00', '15:00');
        $leaveForReportDate = $this->manualLeave(
            $unit,
            $employee,
            LeaveType::Absent,
            '2026-09-01',
            LeaveApprovalStatus::Confirmed,
        );
        $otherDateLeave = $this->manualLeave(
            $unit,
            $employee,
            LeaveType::Absent,
            '2026-09-02',
            LeaveApprovalStatus::Confirmed,
        );
        $this->attendance($unit, $employee, $otherDateLeave, '2026-09-01', AttendanceStatus::Absent);

        $day = $this->report($unit)['data'][0]['daily'][0];

        $this->assertNotSame($leaveForReportDate->id, $otherDateLeave->id);
        $this->assertSame('confirmed', $day['leave_approval_status']);
        $this->assertSame('unverifiable', $day['leave_status']);
        $this->assertTrue($day['is_ambiguous']);
    }

    public function test_confirmed_temporary_exit_requires_a_fingerprint_backed_interval(): void
    {
        $unit = $this->unit();
        $this->calendar($unit, '2026-09-01', true, '07:00', '15:00');
        $verifiedEmployee = $this->employee($unit, 'Exit with evidence');
        $unverifiedEmployee = $this->employee($unit, 'Exit without evidence');
        $verifiedLeave = $this->manualLeave(
            $unit,
            $verifiedEmployee,
            LeaveType::TemporaryExit,
            '2026-09-01',
            LeaveApprovalStatus::Confirmed,
        );
        $unverifiedLeave = $this->manualLeave(
            $unit,
            $unverifiedEmployee,
            LeaveType::TemporaryExit,
            '2026-09-01',
            LeaveApprovalStatus::Confirmed,
        );
        $verifiedRecord = $this->attendance(
            $unit,
            $verifiedEmployee,
            $verifiedLeave,
            '2026-09-01',
            AttendanceStatus::TemporaryExit,
        );
        $this->attendance(
            $unit,
            $unverifiedEmployee,
            $unverifiedLeave,
            '2026-09-01',
            AttendanceStatus::TemporaryExit,
        );
        AttendanceInterval::query()->create([
            'attendance_record_id' => $verifiedRecord->id,
            'type' => AttendanceIntervalType::TemporaryExit,
            'start_time' => '10:00',
            'end_time' => '10:30',
            'duration_minutes' => 30,
            'source' => AttendanceIntervalSource::FingerprintAndLeaveRequest,
        ]);

        $result = $this->report($unit);
        $verifiedDay = collect($result['data'])->firstWhere('employee_id', $verifiedEmployee->id)['daily'][0];
        $unverifiedDay = collect($result['data'])->firstWhere('employee_id', $unverifiedEmployee->id)['daily'][0];

        $this->assertSame('verified', $verifiedDay['leave_status']);
        $this->assertSame(1, $verifiedDay['metrics']['izin_keluar']);
        $this->assertSame('unverifiable', $unverifiedDay['leave_status']);
        $this->assertNull($unverifiedDay['metrics']['izin_keluar']);
    }

    public function test_imported_leave_keeps_its_existing_unverifiable_report_behavior(): void
    {
        $unit = $this->unit();
        $employee = $this->employee($unit, 'Imported leave');
        $this->calendar($unit, '2026-09-01', true, '07:00', '15:00');
        $batch = LeaveImportBatch::query()->create([
            'unit_id' => $unit->id,
            'filename' => 'leave.xlsx',
            'file_hash' => hash('sha256', 'leave-import'),
            'status' => ImportBatchStatus::Completed,
            'total_rows' => 1,
            'success_rows' => 1,
        ]);
        $leave = LeaveRequest::query()->create([
            'import_batch_id' => $batch->id,
            'source' => 'import',
            'unit_id' => $unit->id,
            'employee_id' => $employee->id,
            'raw_name' => $employee->nama_lengkap,
            'normalized_name' => strtolower($employee->nama_lengkap),
            'request_date' => '2026-09-01',
            'leave_type' => LeaveType::Absent,
            'raw_leave_type' => LeaveType::Absent->value,
            'matching_status' => MatchingStatus::Matched,
            'approval_status' => LeaveApprovalStatus::Confirmed,
        ]);
        $this->attendance($unit, $employee, $leave, '2026-09-01', AttendanceStatus::Absent);

        $day = $this->report($unit)['data'][0]['daily'][0];

        $this->assertSame('import', $day['leave_approval_status']);
        $this->assertSame('unverifiable', $day['leave_status']);
        $this->assertTrue($day['is_ambiguous']);
    }

    public function test_unmatched_or_incomplete_batch_logs_are_not_used_for_inference(): void
    {
        $unit = $this->unit();
        $employees = [];
        for ($index = 1; $index <= 2; $index++) {
            $employees[] = $this->employee($unit, 'Employee '.$index);
        }
        $this->logs($unit, $employees[0], '2026-09-01', ['07:00'], MatchingStatus::Unmatched);
        $this->logs($unit, $employees[1], '2026-09-01', ['07:00'], MatchingStatus::Matched, ImportBatchStatus::Pending);

        $result = $this->report($unit);

        $this->assertSame('unknown', $result['data'][0]['daily'][0]['day_status']);
        $this->assertSame(0, $result['summary']['provisional_day_count']);
    }

    public function test_logs_with_unit_or_batch_mismatch_are_not_used_for_inference(): void
    {
        $unit = $this->unit();
        $otherUnit = Unit::query()->create(['name' => 'Other school', 'code' => 'TK']);
        $employee = $this->employee($unit, 'Unit mismatch');
        $batch = FingerprintImportBatch::query()->create([
            'unit_id' => $otherUnit->id,
            'filename' => 'mismatched-batch.xlsx',
            'file_hash' => hash('sha256', 'mismatched-batch'),
            'status' => ImportBatchStatus::Completed,
            'total_rows' => 2,
        ]);

        foreach ([$unit->id, $otherUnit->id] as $logUnitId) {
            FingerprintLog::query()->create([
                'import_batch_id' => $batch->id,
                'unit_id' => $logUnitId,
                'employee_id' => $employee->id,
                'fingerprint_id' => $employee->fingerprint_id,
                'attendance_date' => '2026-09-01',
                'attendance_time' => '07:00:00',
                'matching_status' => MatchingStatus::Matched,
            ]);
        }

        $result = $this->report($unit);

        $this->assertSame('unknown', $result['data'][0]['daily'][0]['day_status']);
        $this->assertSame(0, $result['summary']['provisional_day_count']);
    }

    public function test_overnight_schedule_does_not_produce_endpoint_metrics(): void
    {
        $unit = $this->unit();
        $employee = $this->employee($unit, 'Overnight');
        $this->calendar($unit, '2026-09-01', true, '22:00', '06:00');
        $this->logs($unit, $employee, '2026-09-01', ['22:00', '23:00']);

        $day = $this->report($unit)['data'][0]['daily'][0];

        $this->assertSame(1, $day['metrics']['hadir']);
        $this->assertNull($day['metrics']['terlambat']);
        $this->assertNull($day['metrics']['pulang_awal']);
        $this->assertSame('overnight_shift_undetermined', $day['candidate_status']);
    }

    public function test_service_does_not_write_source_data_or_attendance_snapshots(): void
    {
        $unit = $this->unit();
        $employee = $this->employee($unit, 'Read only');
        $this->calendar($unit, '2026-09-01', true, '07:00', '15:00');
        $this->logs($unit, $employee, '2026-09-01', ['07:00', '15:00']);
        $before = DB::table('fingerprint_logs')->orderBy('id')->get()->toArray();
        $writes = [];
        DB::listen(function ($query) use (&$writes): void {
            if (preg_match('/^\s*(insert|update|delete|replace)\b/i', $query->sql) === 1) {
                $writes[] = $query->sql;
            }
        });

        $this->report($unit);

        $this->assertSame([], $writes);
        $this->assertEquals($before, DB::table('fingerprint_logs')->orderBy('id')->get()->toArray());
        $this->assertDatabaseCount('attendance_records', 0);
    }

    private function report(Unit $unit): array
    {
        return app(AttendanceFingerprintPeriodReportService::class)->generate(
            AttendanceFingerprintPeriodReportFilters::fromValidated([
                'unit_id' => $unit->id,
                'start_date' => '2026-09-01',
                'end_date' => '2026-09-01',
            ]),
        );
    }

    private function manualLeave(
        Unit $unit,
        Employee $employee,
        LeaveType $type,
        string $date,
        LeaveApprovalStatus $approvalStatus,
    ): LeaveRequest {
        return LeaveRequest::query()->create([
            'import_batch_id' => null,
            'source' => 'manual',
            'unit_id' => $unit->id,
            'employee_id' => $employee->id,
            'raw_name' => $employee->nama_lengkap,
            'normalized_name' => strtolower($employee->nama_lengkap),
            'request_date' => $date,
            'leave_type' => $type,
            'raw_leave_type' => $type->value,
            'matching_status' => MatchingStatus::Matched,
            'approval_status' => $approvalStatus,
        ]);
    }

    private function attendance(
        Unit $unit,
        Employee $employee,
        LeaveRequest $leave,
        string $date,
        AttendanceStatus $status,
    ): AttendanceRecord {
        return AttendanceRecord::query()->create([
            'unit_id' => $unit->id,
            'employee_id' => $employee->id,
            'attendance_date' => $date,
            'status' => $status,
            'leave_request_id' => $leave->id,
            'processed_at' => now(),
        ]);
    }

    private function unit(): Unit
    {
        return Unit::query()->create(['name' => 'School', 'code' => 'SD']);
    }

    private function employee(
        Unit $unit,
        string $name,
        ?WorkScheduleCategory $category = null,
    ): Employee {
        static $id = 0;
        $id++;

        return Employee::query()->create([
            'unit_id' => $unit->id,
            'category_id' => $category?->id,
            'fingerprint_id' => 'FP-'.$id,
            'nama_lengkap' => $name,
            'status' => EmployeeStatus::Active,
        ]);
    }

    private function weekly(Unit $unit, bool $working, ?string $start, ?string $end): void
    {
        WorkSchedule::query()->create([
            'unit_id' => $unit->id,
            'day_of_week' => 2,
            'is_working_day' => $working,
            'start_time' => $start,
            'end_time' => $end,
        ]);
    }

    private function calendar(
        Unit $unit,
        string $date,
        bool $working,
        ?string $start = null,
        ?string $end = null,
    ): void {
        WorkCalendar::query()->create([
            'unit_id' => $unit->id,
            'date' => $date,
            'type' => $working ? WorkCalendarType::Workday : WorkCalendarType::Holiday,
            'is_working_day' => $working,
            'start_time' => $start,
            'end_time' => $end,
        ]);
    }

    private function logs(
        Unit $unit,
        Employee $employee,
        string $date,
        array $times,
        MatchingStatus $matching = MatchingStatus::Matched,
        ImportBatchStatus $batchStatus = ImportBatchStatus::Completed,
    ): Collection {
        $batch = FingerprintImportBatch::query()->create([
            'unit_id' => $unit->id,
            'filename' => 'fixture-'.$employee->id.'-'.$date.'-'.uniqid().'.xlsx',
            'file_hash' => hash('sha256', uniqid('', true)),
            'status' => $batchStatus,
            'total_rows' => count($times),
        ]);
        $logs = collect();
        foreach ($times as $time) {
            $logs->push(FingerprintLog::query()->create([
                'import_batch_id' => $batch->id,
                'unit_id' => $unit->id,
                'employee_id' => $employee->id,
                'fingerprint_id' => $employee->fingerprint_id,
                'attendance_date' => $date,
                'attendance_time' => $time.':00',
                'matching_status' => $matching,
            ]));
        }

        return $logs;
    }
}
