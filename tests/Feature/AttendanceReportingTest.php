<?php

namespace Tests\Feature;

use App\Enums\AbsenceReason;
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
use App\Models\LeaveRequest;
use App\Models\Unit;
use App\Models\User;
use App\Models\WorkCalendar;
use App\Models\WorkSchedule;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AttendanceReportingTest extends TestCase
{
    use RefreshDatabase;

    public function test_period_report_returns_employee_rows_and_filters_by_unit(): void
    {
        $tk = $this->createUnit('TK');
        $sd = $this->createUnit('SD');
        $tkEmployee = $this->createEmployee($tk, 'TK-001', 'A');
        $sdEmployee = $this->createEmployee($sd, 'SD-001', 'B');

        $this->createAttendanceRecord($tkEmployee, '2026-08-10', AttendanceStatus::Present, '07:00', '15:40', 0, 0);
        $lateRecord = $this->createAttendanceRecord($tkEmployee, '2026-08-11', AttendanceStatus::Late, '07:20', '15:40', 20, 0);
        $lateRecord->update(['scheduled_in' => '07:00']);
        $earlyRecord = $this->createAttendanceRecord($tkEmployee, '2026-08-12', AttendanceStatus::EarlyDeparture, '07:00', '12:00', 0, 30);
        $earlyRecord->update(['scheduled_out' => '15:40']);
        $this->createAttendanceRecord($td = $sdEmployee, '2026-08-11', AttendanceStatus::OfficialDuty, '09:00', '12:00', 0, 0);

        $this->actingAs(User::factory()->create());

        $response = $this->getJson('/reports/period?unit_id='.$tk->id.'&start_date=2026-08-01&end_date=2026-08-31');
        $response->assertOk();
        $this->assertSame(1, $response->json('summary.total_employees'));
        $this->assertSame(3, $response->json('data.0.hadir'));
        $this->assertSame(1, $response->json('data.0.terlambat'));
        $this->assertSame(1, $response->json('data.0.pulang_awal'));
        $this->assertSame(26, $response->json('data.0.hari_kerja'));
        $this->assertSame(6, $response->json('data.0.total_finger'));
        $this->assertSame('A', $response->json('data.0.employee'));
        $this->assertSame('2026-08-01', $response->json('data.0.period_start'));
        $this->assertSame('2026-08-31', $response->json('data.0.period_end'));
        $this->assertSame(1, $response->json('data.0.total_attendance_days'));
        $this->assertSame('TK', $response->json('data.0.unit'));
        $this->assertSame(0, $response->json('data.0.alpha'));

        $this->getJson('/reports/period?unit_id='.$sd->id.'&start_date=2026-08-01&end_date=2026-08-31')
            ->assertOk()
            ->assertJsonPath('data.0.employee_id', $sdEmployee->id);
    }

    public function test_period_recap_aggregates_26_complete_workdays_to_52_endpoints(): void
    {
        $employee = $this->createSeptemberEmployee('Complete employee');

        foreach ($this->septemberWorkdays() as $date) {
            $this->createRecapRecord($employee, $date, '07:00', '15:40');
        }

        $row = $this->periodRecap($employee);

        $this->assertSame('Complete employee', $row['employee']);
        $this->assertSame('2026-09-01', $row['period_start']);
        $this->assertSame('2026-09-30', $row['period_end']);
        $this->assertSame(26, $row['hari_kerja']);
        $this->assertSame(26, $row['hadir']);
        $this->assertSame(52, $row['total_finger']);
    }

    public function test_period_recap_counts_one_endpoint_day_as_one_finger_and_early_leave(): void
    {
        $employee = $this->createSeptemberEmployee('One endpoint employee');
        $workdays = $this->septemberWorkdays();

        foreach ($workdays as $index => $date) {
            $this->createRecapRecord($employee, $date, '07:00', $index === 0 ? null : '15:40');
        }

        $row = $this->periodRecap($employee);

        $this->assertSame(26, $row['hari_kerja']);
        $this->assertSame(26, $row['hadir']);
        $this->assertSame(1, $row['pulang_awal']);
        $this->assertSame(51, $row['total_finger']);
    }

    public function test_period_recap_uses_two_endpoints_even_when_four_raw_fingerprints_exist(): void
    {
        $employee = $this->createSeptemberEmployee('Multiple scan employee');
        $workdays = $this->septemberWorkdays();

        foreach ($workdays as $index => $date) {
            $this->createRecapRecord(
                $employee,
                $date,
                $index === 0 ? '06:48' : '07:00',
                '15:40',
            );
        }

        $batch = FingerprintImportBatch::create([
            'unit_id' => $employee->unit_id,
            'filename' => 'recap-test.xlsx',
            'file_hash' => hash('sha256', 'recap-test'),
            'status' => ImportBatchStatus::Completed,
        ]);

        foreach (['06:48', '06:49', '12:00', '15:40'] as $time) {
            FingerprintLog::create([
                'import_batch_id' => $batch->id,
                'unit_id' => $employee->unit_id,
                'employee_id' => $employee->id,
                'fingerprint_id' => $employee->fingerprint_id,
                'attendance_date' => $workdays[0],
                'attendance_time' => $time,
                'matching_status' => MatchingStatus::Matched,
            ]);
        }

        $row = $this->periodRecap($employee);

        $this->assertSame(26, $row['hari_kerja']);
        $this->assertSame(26, $row['hadir']);
        $this->assertSame(52, $row['total_finger']);
        $this->assertSame(4, FingerprintLog::query()->where('employee_id', $employee->id)->count());
    }

    public function test_period_recap_ignores_six_workdays_without_attendance_endpoints(): void
    {
        $employee = $this->createSeptemberEmployee('Partial attendance employee');
        $workdays = $this->septemberWorkdays();

        foreach (array_slice($workdays, 0, 20) as $date) {
            $this->createRecapRecord($employee, $date, '07:00', '15:40');
        }

        foreach (array_slice($workdays, 20) as $date) {
            $this->createRecapRecord($employee, $date, null, null, AttendanceStatus::Absent);
        }

        $row = $this->periodRecap($employee);

        $this->assertSame(26, $row['hari_kerja']);
        $this->assertSame(20, $row['hadir']);
        $this->assertSame(40, $row['total_finger']);
    }

    public function test_period_recap_excludes_records_outside_exact_period_bounds_and_counts_each_month_workdays(): void
    {
        $employee = $this->createSeptemberEmployee('Boundary employee');
        $this->createRecapRecord($employee, '2026-08-31', '06:00', '15:40');
        $this->createRecapRecord($employee, '2026-09-15', '07:00', '15:40');
        $this->createRecapRecord($employee, '2026-10-01', '06:00', '15:40');
        $this->createRecapRecord($employee, '2026-10-30', '07:00', '15:40');
        $this->createRecapRecord($employee, '2026-11-02', '07:00', '15:40');

        $service = app(\App\Services\Reports\AttendancePeriodReportService::class);
        $september = $service->generate($employee->unit_id, '2026-09-01', '2026-09-30', [$employee->id])['data'][0];
        $october = $service->generate($employee->unit_id, '2026-10-01', '2026-10-31', [$employee->id])['data'][0];
        $november = $service->generate($employee->unit_id, '2026-11-01', '2026-11-30', [$employee->id])['data'][0];

        $this->assertSame(26, $september['hari_kerja']);
        $this->assertSame(1, $september['hadir']);
        $this->assertSame(2, $september['total_finger']);
        $this->assertSame('2026-09-01', $september['period_start']);
        $this->assertSame('2026-09-30', $september['period_end']);
        $this->assertSame(27, $october['hari_kerja']);
        $this->assertSame(2, $october['hadir']);
        $this->assertSame(4, $october['total_finger']);
        $this->assertSame(25, $november['hari_kerja']);
        $this->assertSame(1, $november['hadir']);
    }

    public function test_period_annual_reports_and_export_keep_using_records_for_inactive_employees(): void
    {
        $unit = $this->createUnit('TK');
        $employee = $this->createEmployee($unit, 'TK-INACTIVE', 'Inactive history');
        $employee->update(['status' => EmployeeStatus::Inactive]);
        $this->createAttendanceRecord($employee, '2026-08-10', AttendanceStatus::Present, '07:00', '15:40', 0, 0);

        $period = app(\App\Services\Reports\AttendancePeriodReportService::class)
            ->generate($unit->id, '2026-08-01', '2026-08-31', [$employee->id])['data'][0];
        $annual = app(\App\Services\Reports\AttendanceAnnualReportService::class)
            ->generate($unit->id, 2026, [$employee->id])['data'][0];
        $spreadsheet = app(\App\Services\Exports\AttendancePeriodExportService::class)
            ->buildSpreadsheet($unit->id, '2026-08-01', '2026-08-31');

        $this->assertSame(1, $period['hadir']);
        $this->assertSame(1, $annual['annual']['hadir']);
        $this->assertSame('1', (string) $spreadsheet->getActiveSheet()->getCell('F6')->getValue());
    }

    public function test_period_report_rejects_invalid_dates_and_counts_leave_exit_and_verification(): void
    {
        $unit = $this->createUnit('TK');
        $employee = $this->createEmployee($unit, 'TK-002', 'C');
        $this->createAttendanceRecord($employee, '2026-08-16', AttendanceStatus::Absent, null, null, 0, 0);
        $this->createAttendanceRecord($employee, '2026-08-17', AttendanceStatus::NeedsVerification, '07:00', '12:00', 0, 0);
        $this->createAttendanceRecord($employee, '2026-08-18', AttendanceStatus::Permission, '07:00', '12:00', 0, 0);
        $this->createAttendanceRecord($employee, '2026-08-19', AttendanceStatus::Sick, '07:00', '12:00', 0, 0);
        $this->createAttendanceRecord($employee, '2026-08-20', AttendanceStatus::OfficialDuty, '07:00', '12:00', 0, 0);
        $this->createAttendanceRecord($employee, '2026-08-21', AttendanceStatus::TemporaryExit, '07:00', '12:00', 0, 0);
        $this->createAttendanceInterval($employee->attendanceRecords()->whereDate('attendance_date', '2026-08-21')->first(), AttendanceIntervalType::TemporaryExit, '10:00', '10:30', 30, AttendanceIntervalSource::Fingerprint);

        $this->actingAs(User::factory()->create());

        $this->getJson('/reports/period?unit_id='.$unit->id.'&start_date=2026-08-31&end_date=2026-08-01')
            ->assertStatus(422)
            ->assertJsonValidationErrors('end_date');

        $response = $this->getJson('/reports/period?unit_id='.$unit->id.'&start_date=2026-08-01&end_date=2026-08-31');
        $response->assertOk();
        $this->assertSame(1, $response->json('data.0.alpha'));
        $this->assertSame(1, $response->json('data.0.perlu_verifikasi'));
        $this->assertSame(1, $response->json('data.0.izin'));
        $this->assertSame(1, $response->json('data.0.sakit'));
        $this->assertSame(1, $response->json('data.0.dinas'));
        $this->assertSame(1, $response->json('data.0.izin_keluar'));
        $this->assertSame(30, $response->json('data.0.total_exit_minutes'));
    }

    public function test_employee_detail_report_returns_daily_summary_and_intervals(): void
    {
        $unit = $this->createUnit('TK');
        $employee = $this->createEmployee($unit, 'TK-003', 'D');
        $record = $this->createAttendanceRecord($employee, '2026-08-25', AttendanceStatus::Late, '07:00', '08:00', 60, 15);
        $this->createAttendanceInterval($record, AttendanceIntervalType::TemporaryExit, '12:00', '12:30', 30, AttendanceIntervalSource::Leave);
        $this->createAttendanceAnomaly($record, 'duplicate_timestamp', 'A duplicate was found', 'warning');

        $this->actingAs(User::factory()->create());

        $response = $this->getJson('/employees/'.$employee->id.'/attendance?start_date=2026-08-20&end_date=2026-08-30');
        $response->assertOk();
        $this->assertSame('TK', $response->json('employee.unit'));
        $this->assertSame(1, $response->json('summary.terlambat'));
        $this->assertSame(1, $response->json('summary.pulang_awal'));
        $this->assertSame(30, $response->json('summary.total_exit_minutes'));
        $this->assertSame('2026-08-25', $response->json('daily.0.date'));
        $this->assertSame('07:00', $response->json('daily.0.scheduled_in'));
        $this->assertSame('08:00', $response->json('daily.0.actual_in'));
        $this->assertSame(60, $response->json('daily.0.late_minutes'));
        $this->assertCount(1, $response->json('daily.0.intervals'));
        $this->assertCount(1, $response->json('daily.0.anomalies'));
    }

    public function test_annual_report_returns_months_and_aggregates_attendance_records(): void
    {
        $unit = $this->createUnit('TK');
        $employee = $this->createEmployee($unit, 'TK-004', 'E');
        $this->createAttendanceRecord($employee, '2026-08-03', AttendanceStatus::Present, '07:00', '15:00', 0, 0);
        $this->createAttendanceRecord($employee, '2026-09-15', AttendanceStatus::Sick, '07:00', '15:00', 0, 0);
        $this->createAttendanceRecord($employee, '2026-11-10', AttendanceStatus::Absent, '07:00', '15:00', 0, 0);

        $this->actingAs(User::factory()->create());

        $response = $this->getJson('/reports/annual?year=2026&unit_id='.$unit->id);
        $response->assertOk();
        $this->assertCount(12, $response->json('data.0.monthly'));
        $this->assertSame(2, $response->json('data.0.annual.hadir'));
        $this->assertSame(1, $response->json('data.0.annual.alpha'));
        $this->assertSame(1, $response->json('data.0.annual.sakit'));
        $this->assertSame(1, $response->json('data.0.monthly.August.hadir'));
        $this->assertSame(26, $response->json('data.0.monthly.August.hari_kerja'));
        $this->assertSame(2, $response->json('data.0.monthly.August.total_finger'));
        $this->assertSame($employee->id, $response->json('data.0.employee_id'));
        $this->assertArrayNotHasKey('effective_from', $response->json('data.0'));
        $this->assertArrayNotHasKey('effective_until', $response->json('data.0'));
    }

    public function test_annual_report_monthly_metrics_use_daily_calculator_and_keep_legacy_status_totals(): void
    {
        $unit = $this->createUnit('TK');
        $complete = $this->createEmployee($unit, 'ANNUAL-COMPLETE', 'Complete');
        $singleEndpoint = $this->createEmployee($unit, 'ANNUAL-SINGLE', 'Single endpoint');
        $late = $this->createEmployee($unit, 'ANNUAL-LATE', 'Late');
        $lateEarly = $this->createEmployee($unit, 'ANNUAL-LATE-EARLY', 'Late and early');
        $partial = $this->createEmployee($unit, 'ANNUAL-PARTIAL', 'Partial');
        $multipleScans = $this->createEmployee($unit, 'ANNUAL-MULTIPLE', 'Multiple scans');
        $workdays = $this->septemberWorkdays();

        foreach ($workdays as $index => $date) {
            $this->createRecapRecord($complete, $date, '07:00', '15:40');
            $this->createRecapRecord($singleEndpoint, $date, '06:50', $index === 0 ? null : '15:40');
            $this->createRecapRecord(
                $late,
                $date,
                $index < 2 ? '07:15' : '07:00',
                '15:40',
                $index < 2 ? AttendanceStatus::Late : AttendanceStatus::Present,
            );
            $this->createRecapRecord(
                $lateEarly,
                $date,
                $index === 0 ? '07:15' : '07:00',
                $index === 0 ? '15:30' : '15:40',
                $index === 0 ? AttendanceStatus::Late : AttendanceStatus::Present,
            );
            if ($index < 20) {
                $this->createRecapRecord($partial, $date, '07:00', '15:40');
            } else {
                $this->createRecapRecord($partial, $date, null, null, AttendanceStatus::Absent);
            }
            $this->createRecapRecord(
                $multipleScans,
                $date,
                '06:48',
                '15:40',
                $index === 0 ? AttendanceStatus::NeedsVerification : AttendanceStatus::Present,
            );
        }

        $this->createRecapRecord($complete, '2026-09-06', '07:00', '15:40');
        $this->createRawFingerprintLogs($multipleScans, $workdays[0], ['06:48', '06:49', '12:00', '15:40']);

        $report = app(\App\Services\Reports\AttendanceAnnualReportService::class)->generate($unit->id, 2026);
        $rows = collect($report['data'])->keyBy('employee_id');
        $september = fn (Employee $employee): array => $rows->get($employee->id)['monthly']['September'];

        $this->assertSame([
            'hari_kerja' => 26,
            'hadir' => 26,
            'terlambat' => 0,
            'pulang_awal' => 0,
            'total_finger' => 52,
        ], $this->annualRecapMetrics($september($complete)));
        $this->assertSame([
            'hari_kerja' => 26,
            'hadir' => 26,
            'terlambat' => 0,
            'pulang_awal' => 1,
            'total_finger' => 51,
        ], $this->annualRecapMetrics($september($singleEndpoint)));
        $this->assertSame([
            'hari_kerja' => 26,
            'hadir' => 26,
            'terlambat' => 2,
            'pulang_awal' => 0,
            'total_finger' => 52,
        ], $this->annualRecapMetrics($september($late)));
        $this->assertSame([
            'hari_kerja' => 26,
            'hadir' => 26,
            'terlambat' => 1,
            'pulang_awal' => 1,
            'total_finger' => 52,
        ], $this->annualRecapMetrics($september($lateEarly)));
        $this->assertSame([
            'hari_kerja' => 26,
            'hadir' => 20,
            'terlambat' => 0,
            'pulang_awal' => 0,
            'total_finger' => 40,
        ], $this->annualRecapMetrics($september($partial)));
        $this->assertSame(6, $september($partial)['alpha']);
        $this->assertSame([
            'hari_kerja' => 26,
            'hadir' => 26,
            'terlambat' => 0,
            'pulang_awal' => 0,
            'total_finger' => 52,
        ], $this->annualRecapMetrics($september($multipleScans)));
        $this->assertSame(1, $september($multipleScans)['perlu_verifikasi']);
        $this->assertSame(4, FingerprintLog::query()->where('employee_id', $multipleScans->id)->count());

        $annual = $rows->get($lateEarly->id)['annual'];
        $this->assertSame(313, $annual['hari_kerja']);
        $this->assertSame(26, $annual['hadir']);
        $this->assertSame(1, $annual['terlambat']);
        $this->assertSame(1, $annual['pulang_awal']);
        $this->assertSame(52, $annual['total_finger']);
    }

    public function test_annual_report_counts_each_month_workdays_and_annual_metrics_sum_monthly_metrics(): void
    {
        $unit = $this->createUnit('TK');
        $employee = $this->createEmployee($unit, 'ANNUAL-SUM', 'Annual sum');

        $this->createRecapRecord($employee, '2026-08-31', '07:00', '15:40');
        foreach ($this->septemberWorkdays() as $date) {
            $this->createRecapRecord($employee, $date, '07:00', '15:40');
        }

        foreach ($this->monthWorkdays(2026, 10) as $index => $date) {
            $this->createRecapRecord(
                $employee,
                $date,
                $index === 0 ? '07:15' : '07:00',
                $index === 0 ? '15:30' : '15:40',
                $index === 0 ? AttendanceStatus::Late : AttendanceStatus::Present,
            );
        }

        $this->createRecapRecord($employee, '2026-11-01', '07:00', '15:40');
        $result = app(\App\Services\Reports\AttendanceAnnualReportService::class)->generate($unit->id, 2026)['data'][0];
        $metrics = ['hari_kerja', 'hadir', 'terlambat', 'pulang_awal', 'total_finger'];

        $this->assertSame(26, $result['monthly']['September']['hari_kerja']);
        $this->assertSame(27, $result['monthly']['October']['hari_kerja']);
        $this->assertSame(25, $result['monthly']['November']['hari_kerja']);
        $this->assertSame(26, $result['monthly']['September']['hadir']);
        $this->assertSame(52, $result['monthly']['September']['total_finger']);
        $this->assertSame(27, $result['monthly']['October']['hadir']);
        $this->assertSame(1, $result['monthly']['October']['terlambat']);
        $this->assertSame(1, $result['monthly']['October']['pulang_awal']);
        $this->assertSame(54, $result['monthly']['October']['total_finger']);
        $this->assertSame(0, $result['monthly']['November']['hadir']);

        foreach ($metrics as $metric) {
            $monthlyTotal = array_sum(array_column($result['monthly'], $metric));
            $this->assertSame($monthlyTotal, $result['annual'][$metric], $metric.' annual total must equal its monthly sum.');
        }
    }

    public function test_period_and_annual_reports_match_for_september_scenarios_and_month_boundaries(): void
    {
        $unit = $this->createUnit('TK');
        $complete = $this->createEmployee($unit, 'MATCH-COMPLETE', 'Complete');
        $single = $this->createEmployee($unit, 'MATCH-SINGLE', 'Single endpoint');
        $late = $this->createEmployee($unit, 'MATCH-LATE', 'Late');
        $lateEarly = $this->createEmployee($unit, 'MATCH-LATE-EARLY', 'Late and early');
        $partial = $this->createEmployee($unit, 'MATCH-PARTIAL', 'Partial');
        $multiple = $this->createEmployee($unit, 'MATCH-MULTIPLE', 'Multiple scans');
        $morning = $this->createEmployee($unit, 'MATCH-MORNING', 'Single morning');
        $afternoon = $this->createEmployee($unit, 'MATCH-AFTERNOON', 'Single afternoon');
        $noAttendance = $this->createEmployee($unit, 'MATCH-NONE', 'No attendance');
        $sundayOnly = $this->createEmployee($unit, 'MATCH-SUNDAY', 'Sunday only');
        $boundary = $this->createEmployee($unit, 'MATCH-BOUNDARY', 'Boundary');
        $workdays = $this->septemberWorkdays();

        foreach ($workdays as $index => $date) {
            $this->createRecapRecord($complete, $date, '07:00', '15:40');
            $this->createRecapRecord($single, $date, '07:00', $index === 0 ? null : '15:40');
            $this->createRecapRecord(
                $late,
                $date,
                $index < 2 ? '07:15' : '07:00',
                '15:40',
                $index < 2 ? AttendanceStatus::Late : AttendanceStatus::Present,
            );
            $this->createRecapRecord(
                $lateEarly,
                $date,
                $index === 0 ? '07:15' : '07:00',
                $index === 0 ? '15:30' : '15:40',
                $index === 0 ? AttendanceStatus::Late : AttendanceStatus::Present,
            );

            if ($index < 20) {
                $this->createRecapRecord($partial, $date, '07:00', '15:40');
            } else {
                $this->createRecapRecord($partial, $date, null, null, AttendanceStatus::Absent);
            }
        }

        $this->createRecapRecord($multiple, $workdays[0], '06:48', '15:40');
        $this->createRawFingerprintLogs($multiple, $workdays[0], ['06:48', '06:49', '12:00', '15:40']);
        $this->createRecapRecord($morning, '2026-09-01', '06:50', null);
        $this->createRecapRecord($afternoon, '2026-09-01', '15:40', null);
        $this->createRecapRecord($sundayOnly, '2026-09-06', '07:15', '15:30', AttendanceStatus::Late);
        $this->createRecapRecord($boundary, '2026-08-31', '07:00', '15:40');
        $this->createRecapRecord($boundary, '2026-09-01', '07:00', '15:40');
        $this->createRecapRecord($boundary, '2026-09-30', '07:00', '15:40');
        $this->createRecapRecord($boundary, '2026-10-01', '07:00', '15:40');

        $periodService = app(\App\Services\Reports\AttendancePeriodReportService::class);
        $annualRows = collect(
            app(\App\Services\Reports\AttendanceAnnualReportService::class)->generate($unit->id, 2026)['data'],
        )->keyBy('employee_id');
        $septemberRows = collect(
            $periodService->generate($unit->id, '2026-09-01', '2026-09-30')['data'],
        )->keyBy('employee_id');

        $expected = [
            $complete->id => [26, 26, 0, 0, 52],
            $single->id => [26, 26, 0, 1, 51],
            $late->id => [26, 26, 2, 0, 52],
            $lateEarly->id => [26, 26, 1, 1, 52],
            $partial->id => [26, 20, 0, 0, 40],
            $multiple->id => [26, 1, 0, 0, 2],
            $morning->id => [26, 1, 0, 1, 1],
            $afternoon->id => [26, 1, 0, 1, 1],
            $noAttendance->id => [26, 0, 0, 0, 0],
            $sundayOnly->id => [26, 0, 0, 0, 0],
            $boundary->id => [26, 2, 0, 0, 4],
        ];

        foreach ($expected as $employeeId => $values) {
            $periodMetrics = $this->periodRecapMetrics($septemberRows->get($employeeId));
            $annualMetrics = $this->annualRecapMetrics($annualRows->get($employeeId)['monthly']['September']);

            $this->assertSame($values, array_values($periodMetrics), 'Period metrics for employee '.$employeeId);
            $this->assertSame($periodMetrics, $annualMetrics, 'September reports must match for employee '.$employeeId);
        }

        $october = $periodService->generate($unit->id, '2026-10-01', '2026-10-31', [$boundary->id])['data'][0];
        $this->assertSame(27, $october['hari_kerja']);
        $this->assertSame(1, $october['hadir']);
        $this->assertSame(2, $october['total_finger']);
        $this->assertSame(27, $annualRows->get($boundary->id)['monthly']['October']['hari_kerja']);
        $this->assertSame(1, $annualRows->get($boundary->id)['monthly']['October']['hadir']);
        $this->assertSame(2, $annualRows->get($boundary->id)['monthly']['October']['total_finger']);

        $metrics = ['hari_kerja', 'hadir', 'terlambat', 'pulang_awal', 'total_finger'];
        foreach ($annualRows as $annualRow) {
            foreach ($metrics as $metric) {
                $this->assertSame(
                    array_sum(array_column($annualRow['monthly'], $metric)),
                    $annualRow['annual'][$metric],
                    $metric.' annual total must equal its monthly sum.',
                );
            }
        }

        $this->assertSame(4, FingerprintLog::query()->where('employee_id', $multiple->id)->count());
    }

    public function test_reporting_is_read_only_and_ignores_missing_attendance(): void
    {
        $unit = $this->createUnit('TK');
        $employee = $this->createEmployee($unit, 'TK-005', 'F');
        $record = $this->createAttendanceRecord($employee, '2026-08-20', AttendanceStatus::Present, '07:00', '15:00', 0, 0);
        $original = $record->getAttributes();

        $this->actingAs(User::factory()->create());

        $freshRecord = $record->fresh();

        $this->getJson('/reports/period?unit_id='.$unit->id.'&start_date=2026-08-01&end_date=2026-08-31')->assertOk();
        $this->assertInstanceOf(\App\Enums\AttendanceStatus::class, $freshRecord->status);
        $this->assertSame($original['status'], $freshRecord->status->value);
        $this->assertSame($original['actual_out'], $freshRecord->actual_out);
        $this->assertSame(0, $employee->attendanceRecords()->whereKeyNot($record->id)->count());
    }

    public function test_period_and_annual_reports_keep_leave_categories_and_attendance_metrics_consistent(): void
    {
        $unit = $this->createUnit('TK');
        $employee = $this->createEmployee($unit, 'TK-LEAVE-001', 'Leave recap');

        $dinas = $this->createAttendanceRecord($employee, '2026-08-10', AttendanceStatus::OfficialDuty, '07:20', '15:00', 20, 40);
        $dinas->update(['scheduled_in' => '07:00', 'scheduled_out' => '15:40']);
        $dinas->update(['leave_request_id' => $this->createManualLeave($employee, '2026-08-10', LeaveType::OfficialDuty)->id]);

        $alpha = $this->createAttendanceRecord($employee, '2026-08-11', AttendanceStatus::Absent, '07:20', '15:00', 20, 40);
        $alpha->update(['scheduled_in' => '07:00', 'scheduled_out' => '15:40']);
        $alpha->update(['leave_request_id' => $this->createManualLeave($employee, '2026-08-11', LeaveType::Absent)->id]);

        $exit = $this->createAttendanceRecord($employee, '2026-08-12', AttendanceStatus::Late, '07:20', '15:00', 20, 40);
        $exit->update([
            'scheduled_in' => '07:00',
            'scheduled_out' => '15:40',
            'leave_request_id' => $this->createManualLeave($employee, '2026-08-12', LeaveType::TemporaryExit)->id,
        ]);

        $partialExit = $this->createAttendanceRecord($employee, '2026-08-13', AttendanceStatus::NeedsVerification, '07:20', null, 20, 0);
        $partialExit->update([
            'scheduled_in' => '07:00',
            'scheduled_out' => '15:40',
            'leave_request_id' => $this->createManualLeave($employee, '2026-08-13', LeaveType::TemporaryExit)->id,
        ]);

        $period = app(\App\Services\Reports\AttendancePeriodReportService::class)
            ->generate($unit->id, '2026-08-01', '2026-08-31', [$employee->id])['data'][0];
        $annual = app(\App\Services\Reports\AttendanceAnnualReportService::class)
            ->generate($unit->id, 2026, [$employee->id])['data'][0];
        $august = $annual['monthly']['August'];

        $this->assertSame(1, $period['dinas']);
        $this->assertSame(1, $period['alpha']);
        $this->assertSame(2, $period['izin_keluar']);
        $this->assertSame(1, $period['hadir']);
        $this->assertSame(2, $period['terlambat']);
        $this->assertSame(1, $period['pulang_awal']);
        $this->assertSame($period['hadir'], $august['hadir']);
        $this->assertSame($period['terlambat'], $august['terlambat']);
        $this->assertSame($period['pulang_awal'], $august['pulang_awal']);
        $this->assertSame($period['alpha'], $august['alpha']);
        $this->assertSame($period['dinas'], $august['dinas']);
        $this->assertSame($period['izin_keluar'], $august['izin_keluar']);
        $this->assertSame(1, $august['perlu_verifikasi']);
        $this->assertSame(1, $annual['annual']['alpha']);
        $this->assertSame(1, $annual['annual']['dinas']);
        $this->assertSame(2, $annual['annual']['izin_keluar']);
    }

    private function createUnit(string $code): Unit
    {
        return Unit::create(['name' => $code, 'code' => $code]);
    }

    private function createEmployee(Unit $unit, string $code, string $name): Employee
    {
        $employee = Employee::create([
            'unit_id' => $unit->id,
            'fingerprint_id' => $code,
            'nama_lengkap' => $name,
            'position' => 'Guru',
            'status' => EmployeeStatus::Active,
        ]);
        return $employee;
    }

    private function createManualLeave(Employee $employee, string $date, LeaveType $type): LeaveRequest
    {
        return LeaveRequest::query()->create([
            'import_batch_id' => null,
            'source' => 'manual',
            'unit_id' => $employee->unit_id,
            'employee_id' => $employee->id,
            'raw_name' => $employee->displayName(),
            'normalized_name' => strtolower($employee->displayName()),
            'request_date' => $date,
            'leave_type' => $type,
            'raw_leave_type' => $type->value,
            'matching_status' => MatchingStatus::Matched,
        ]);
    }

    private function createSeptemberEmployee(string $name): Employee
    {
        $unit = $this->createUnit('TK');

        return $this->createEmployee($unit, 'RECAP-'.str_replace(' ', '-', $name), $name);
    }

    private function periodRecap(Employee $employee): array
    {
        $report = app(\App\Services\Reports\AttendancePeriodReportService::class)->generate(
            $employee->unit_id,
            '2026-09-01',
            '2026-09-30',
            [$employee->id],
        );

        return $report['data'][0];
    }

    private function septemberWorkdays(): array
    {
        return $this->monthWorkdays(2026, 9);
    }

    private function monthWorkdays(int $year, int $month): array
    {
        $dates = [];
        $date = CarbonImmutable::create($year, $month, 1);
        $end = $date->endOfMonth();

        for (; $date <= $end; $date = $date->addDay()) {
            if ($date->dayOfWeekIso < 7) {
                $dates[] = $date->toDateString();
            }
        }

        return $dates;
    }

    private function annualRecapMetrics(array $monthly): array
    {
        return [
            'hari_kerja' => $monthly['hari_kerja'],
            'hadir' => $monthly['hadir'],
            'terlambat' => $monthly['terlambat'],
            'pulang_awal' => $monthly['pulang_awal'],
            'total_finger' => $monthly['total_finger'],
        ];
    }

    private function periodRecapMetrics(array $period): array
    {
        return [
            'hari_kerja' => $period['hari_kerja'],
            'hadir' => $period['hadir'],
            'terlambat' => $period['terlambat'],
            'pulang_awal' => $period['pulang_awal'],
            'total_finger' => $period['total_finger'],
        ];
    }

    private function createRawFingerprintLogs(Employee $employee, string $date, array $times): void
    {
        $batch = FingerprintImportBatch::create([
            'unit_id' => $employee->unit_id,
            'filename' => 'annual-recap-test.xlsx',
            'file_hash' => hash('sha256', 'annual-recap-'.$employee->id),
            'status' => ImportBatchStatus::Completed,
        ]);

        foreach ($times as $time) {
            FingerprintLog::create([
                'import_batch_id' => $batch->id,
                'unit_id' => $employee->unit_id,
                'employee_id' => $employee->id,
                'fingerprint_id' => $employee->fingerprint_id,
                'attendance_date' => $date,
                'attendance_time' => $time.':00',
                'matching_status' => MatchingStatus::Matched,
            ]);
        }
    }

    private function createRecapRecord(
        Employee $employee,
        string $date,
        ?string $actualIn,
        ?string $actualOut,
        AttendanceStatus $status = AttendanceStatus::Present,
    ): AttendanceRecord {
        return AttendanceRecord::create([
            'employee_id' => $employee->id,
            'unit_id' => $employee->unit_id,
            'attendance_date' => $date,
            'status' => $status->value,
            'scheduled_in' => '07:00',
            'actual_in' => $actualIn,
            'scheduled_out' => '15:40',
            'actual_out' => $actualOut,
            'late_minutes' => $actualIn !== null && $actualIn > '07:00' ? 15 : 0,
            'early_leave_minutes' => $actualOut !== null && $actualOut < '15:40' ? 10 : 0,
        ]);
    }

    private function createAttendanceRecord(Employee $employee, string $date, AttendanceStatus $status, ?string $in, ?string $out, int $late, int $early): AttendanceRecord
    {
        return AttendanceRecord::create([
            'employee_id' => $employee->id,
            'unit_id' => $employee->unit_id,
            'attendance_date' => $date,
            'status' => $status->value,
            'scheduled_in' => $in,
            'actual_in' => $in,
            'scheduled_out' => $out,
            'actual_out' => $out,
            'late_minutes' => $late,
            'early_leave_minutes' => $early,
            'absence_reason' => $status === AttendanceStatus::Permission ? AbsenceReason::Permission->value : null,
            'is_holiday' => false,
            'activity_on_holiday' => false,
            'needs_verification' => $status === AttendanceStatus::NeedsVerification,
            'verification_note' => $status === AttendanceStatus::NeedsVerification ? 'Check data' : null,
        ]);
    }

    private function createAttendanceInterval(AttendanceRecord $record, AttendanceIntervalType $type, string $start, string $end, int $minutes, AttendanceIntervalSource $source): AttendanceInterval
    {
        return AttendanceInterval::create([
            'attendance_record_id' => $record->id,
            'type' => $type->value,
            'start_time' => $start,
            'end_time' => $end,
            'duration_minutes' => $minutes,
            'source' => $source->value,
            'notes' => 'interval',
        ]);
    }

    private function createAttendanceAnomaly(AttendanceRecord $record, string $type, string $message, string $severity): AttendanceAnomaly
    {
        return AttendanceAnomaly::create([
            'attendance_record_id' => $record->id,
            'type' => $type,
            'message' => $message,
            'severity' => $severity,
            'is_resolved' => false,
        ]);
    }
}
