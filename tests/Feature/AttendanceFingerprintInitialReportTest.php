<?php

namespace Tests\Feature;

use App\Enums\EmployeeStatus;
use App\Enums\ImportBatchStatus;
use App\Enums\MatchingStatus;
use App\Models\Employee;
use App\Models\FingerprintImportBatch;
use App\Models\FingerprintLog;
use App\Models\Unit;
use App\Models\User;
use App\Models\WorkScheduleCategory;
use App\Services\Reports\AttendanceFingerprintInitialReportService;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AttendanceFingerprintInitialReportTest extends TestCase
{
    use RefreshDatabase;

    public function test_summary_counts_only_matched_logs_from_completed_batches_and_valid_employee_unit_relations(): void
    {
        $sd = Unit::create(['name' => 'SD', 'code' => 'SD']);
        $tk = Unit::create(['name' => 'TK', 'code' => 'TK']);
        $category = WorkScheduleCategory::create(['name' => 'Staff', 'is_active' => true]);
        $employee = $this->createEmployee($sd, $category, 'FP-001', 'Employee one');
        $otherEmployee = $this->createEmployee($sd, $category, 'FP-002', 'Employee two');
        $completedBatch = $this->createBatch($sd, 'completed');
        $otherUnitBatch = $this->createBatch($tk, 'other-unit');
        $pendingBatch = $this->createBatch($sd, 'pending', ImportBatchStatus::Pending);

        $this->createLog($completedBatch, $sd, $employee, '2026-09-01', '07:00:00', MatchingStatus::Matched, true);
        $this->createLog($completedBatch, $sd, $employee, '2026-09-01', '07:00:00', MatchingStatus::Matched);
        $this->createLog($completedBatch, $sd, $employee, '2026-09-30', '15:00:00', MatchingStatus::Matched);
        $this->createLog($completedBatch, $sd, $employee, '2026-09-02', '07:00:00', MatchingStatus::Unmatched);
        $this->createLog($completedBatch, $sd, $otherEmployee, '2026-09-03', '07:00:00', MatchingStatus::Ambiguous);
        $this->createLog($pendingBatch, $sd, $employee, '2026-09-04', '07:00:00', MatchingStatus::Matched);
        $this->createLog($completedBatch, $sd, $employee, '2026-08-31', '07:00:00', MatchingStatus::Matched);
        $this->createLog($completedBatch, $tk, $employee, '2026-09-05', '07:00:00', MatchingStatus::Matched);
        $this->createLog($otherUnitBatch, $tk, null, '2026-09-06', '07:00:00', MatchingStatus::Matched);

        $summary = app(AttendanceFingerprintInitialReportService::class)->generate(
            $sd->id,
            $category->id,
            '2026-09-01',
            '2026-09-30',
        );

        $this->assertSame(3, $summary['matched_log_count']);
        $this->assertSame(1, $summary['employees_with_fingerprint']);
        $this->assertSame(1, $summary['verification_flagged_log_count']);
        $this->assertSame([
            'matched_log_count' => 3,
            'verification_flagged_log_count' => 1,
        ], $summary['per_employee']->get($employee->id));
        $this->assertNull($summary['per_employee']->get($otherEmployee->id));

        $searchedSummary = app(AttendanceFingerprintInitialReportService::class)->generate(
            $sd->id,
            $category->id,
            '2026-09-01',
            '2026-09-30',
            'Employee two',
        );
        $this->assertSame(0, $searchedSummary['matched_log_count']);
        $this->assertSame(0, $searchedSummary['employees_with_fingerprint']);
    }

    public function test_period_page_does_not_display_or_query_initial_fingerprint_counts_before_filters(): void
    {
        $this->actingAs(User::factory()->create());
        $this->mock(AttendanceFingerprintInitialReportService::class, function ($mock): void {
            $mock->shouldNotReceive('generate');
        });

        $fingerprintReads = [];
        DB::listen(function (QueryExecuted $query) use (&$fingerprintReads): void {
            if (
                preg_match('/^\s*select\b/i', $query->sql) === 1
                && preg_match('/\bfingerprint_logs\b/i', $query->sql) === 1
            ) {
                $fingerprintReads[] = $query->sql;
            }
        });

        $this->get('/rekap-periode')
            ->assertOk()
            ->assertSee('Pilih unit dan rentang tanggal')
            ->assertDontSee('Rekap Awal Fingerprint')
            ->assertDontSee('Log matched valid');

        $this->assertSame([], $fingerprintReads);
    }

    private function createEmployee(
        Unit $unit,
        WorkScheduleCategory $category,
        string $fingerprintId,
        string $name,
    ): Employee {
        return Employee::create([
            'unit_id' => $unit->id,
            'category_id' => $category->id,
            'fingerprint_id' => $fingerprintId,
            'nama_lengkap' => $name,
            'status' => EmployeeStatus::Active,
        ]);
    }

    private function createBatch(
        Unit $unit,
        string $key,
        ImportBatchStatus $status = ImportBatchStatus::Completed,
    ): FingerprintImportBatch {
        return FingerprintImportBatch::create([
            'unit_id' => $unit->id,
            'filename' => $key.'.xlsx',
            'file_hash' => hash('sha256', $key),
            'status' => $status,
            'period_start' => '2026-09-01',
            'period_end' => '2026-09-30',
            'total_rows' => 1,
            'success_rows' => 1,
            'error_rows' => 0,
        ]);
    }

    private function createLog(
        FingerprintImportBatch $batch,
        Unit $unit,
        ?Employee $employee,
        string $date,
        string $time,
        MatchingStatus $matchingStatus,
        bool $needsVerification = false,
    ): FingerprintLog {
        return FingerprintLog::create([
            'import_batch_id' => $batch->id,
            'unit_id' => $unit->id,
            'employee_id' => $employee?->id,
            'fingerprint_id' => $employee?->fingerprint_id ?? 'UNMATCHED',
            'attendance_date' => $date,
            'attendance_time' => $time,
            'matching_status' => $matchingStatus,
            'needs_verification' => $needsVerification,
        ]);
    }
}
