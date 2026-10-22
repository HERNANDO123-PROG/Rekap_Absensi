<?php

namespace Tests\Feature;

use App\Enums\AttendanceStatus;
use App\Enums\EmployeeStatus;
use App\Enums\ImportBatchStatus;
use App\Enums\MatchingStatus;
use App\Models\AttendanceRecord;
use App\Models\Employee;
use App\Models\FingerprintImportBatch;
use App\Models\FingerprintLog;
use App\Models\Unit;
use App\Models\WorkSchedule;
use App\Models\WorkScheduleCategory;
use App\Services\Attendance\AttendanceProcessingService;
use App\Services\EmployeeEligibilityService;
use App\Services\Fingerprint\FingerprintImportService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class EmployeeEligibilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_employee_schema_has_no_employment_period_table_or_application_relation(): void
    {
        foreach (['employee_code', 'name', 'normalized_name', 'effective_from', 'effective_until'] as $legacyColumn) {
            $this->assertFalse(Schema::hasColumn('employees', $legacyColumn), $legacyColumn.' must not exist on employees.');
        }

        foreach (['fingerprint_id', 'active_fingerprint_id', 'namafinger', 'nama_lengkap', 'nip', 'position'] as $column) {
            $this->assertTrue(Schema::hasColumn('employees', $column));
        }

        $this->assertFalse(Schema::hasTable('employee_employment_periods'));
        $employee = $this->createEmployee($this->createUnit('TK'));

        $this->assertFalse(method_exists($employee, 'employmentPeriods'));
        $this->assertTrue(app(EmployeeEligibilityService::class)->isEligibleOnDate($employee, '2026-08-10'));
    }

    public function test_inactive_status_rejects_eligibility_without_period_data(): void
    {
        $employee = $this->createEmployee($this->createUnit('TK'), status: EmployeeStatus::Inactive);

        $this->assertFalse(app(EmployeeEligibilityService::class)->isEligibleOnDate($employee, '2026-08-03'));
        $this->assertSame('inactive', app(EmployeeEligibilityService::class)->ineligibilityReason($employee, '2026-08-03'));
    }

    public function test_active_employee_without_period_is_processed_without_changing_raw_logs(): void
    {
        $unit = $this->createUnit('TK');
        $employee = $this->createEmployee($unit);
        $this->createSchedule($unit, '2026-08-20');
        $log = $this->createFingerprintLog($unit, $employee, '2026-08-20', '10:10');

        $summary = app(AttendanceProcessingService::class)->processRange($unit->id, '2026-08-20', '2026-08-20');

        $this->assertSame(1, $summary['processed']);
        $this->assertSame(0, $summary['skipped_ineligible_employee']);
        $this->assertSame(1, $employee->attendanceRecords()->count());
        $this->assertDatabaseHas('fingerprint_logs', [
            'id' => $log->id,
            'raw_value' => '10:10',
            'verification_note' => null,
        ]);
    }

    public function test_reprocessing_processes_active_employee_without_period_and_preserves_historical_marker(): void
    {
        $unit = $this->createUnit('SD');
        $employee = $this->createEmployee($unit);
        $date = '2026-08-20';
        $this->createSchedule($unit, $date);
        $batch = FingerprintImportBatch::create([
            'unit_id' => $unit->id,
            'filename' => 'period-reprocess.xls',
            'file_hash' => hash('sha256', random_bytes(32)),
            'status' => ImportBatchStatus::Completed,
            'period_start' => $date,
            'period_end' => $date,
        ]);
        $log = FingerprintLog::create([
            'import_batch_id' => $batch->id,
            'unit_id' => $unit->id,
            'employee_id' => $employee->id,
            'fingerprint_id' => $employee->fingerprint_id,
            'employee_name_raw' => $employee->namafinger,
            'attendance_date' => $date,
            'attendance_time' => '07:00:00',
            'raw_value' => '07:00',
            'matching_status' => MatchingStatus::Matched,
            'needs_verification' => true,
            'verification_note' => '[periode-kerja] Masa kerja belum ditetapkan.',
        ]);
        app(AttendanceProcessingService::class)->processEmployeeDate($unit->id, $employee->id, $date);

        $reprocessed = app(FingerprintImportService::class)->reprocessImportBatch($batch);

        $this->assertSame(AttendanceStatus::Present, $employee->attendanceRecords()->sole()->status);
        $this->assertSame(0, $reprocessed->processing_summary['failed']);
        $this->assertDatabaseHas('fingerprint_logs', [
            'id' => $log->id,
            'needs_verification' => true,
            'verification_note' => '[periode-kerja] Masa kerja belum ditetapkan.',
        ]);
    }

    private function createUnit(string $code): Unit
    {
        return Unit::create(['name' => $code, 'code' => $code]);
    }

    private function createEmployee(
        Unit $unit,
        string $name = 'Eligibility employee',
        EmployeeStatus $status = EmployeeStatus::Active,
    ): Employee {
        $category = WorkScheduleCategory::create([
            'name' => 'Eligibility category '.WorkScheduleCategory::query()->count(),
            'start_time' => '07:00',
            'end_time' => '15:30',
        ]);

        return Employee::create([
            'unit_id' => $unit->id,
            'category_id' => $category->id,
            'fingerprint_id' => 'FP-'.$unit->id,
            'namafinger' => $name,
            'status' => $status,
        ]);
    }

    private function createSchedule(Unit $unit, string $date): WorkSchedule
    {
        return WorkSchedule::create([
            'unit_id' => $unit->id,
            'day_of_week' => CarbonImmutable::parse($date)->dayOfWeek,
            'is_working_day' => true,
            'start_time' => '07:00',
            'end_time' => '15:30',
        ]);
    }

    private function createFingerprintLog(Unit $unit, Employee $employee, string $date, string $time): FingerprintLog
    {
        $batch = FingerprintImportBatch::create([
            'unit_id' => $unit->id,
            'filename' => 'employment-period.xls',
            'file_hash' => hash('sha256', random_bytes(32)),
            'status' => ImportBatchStatus::Completed,
            'period_start' => $date,
            'period_end' => $date,
        ]);

        return FingerprintLog::create([
            'import_batch_id' => $batch->id,
            'unit_id' => $unit->id,
            'employee_id' => $employee->id,
            'fingerprint_id' => $employee->fingerprint_id,
            'employee_name_raw' => $employee->namafinger,
            'attendance_date' => $date,
            'attendance_time' => $time.':00',
            'raw_value' => $time,
            'matching_status' => MatchingStatus::Matched,
        ]);
    }
}
