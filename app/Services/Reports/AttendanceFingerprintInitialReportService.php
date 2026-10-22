<?php

namespace App\Services\Reports;

use App\Enums\ImportBatchStatus;
use App\Enums\MatchingStatus;
use App\Models\FingerprintLog;

class AttendanceFingerprintInitialReportService
{
    public function generate(
        ?int $unitId,
        ?int $categoryId,
        string $startDate,
        string $endDate,
        string $search = '',
    ): array {
        $searchTerm = trim($search);

        $employees = FingerprintLog::query()
            ->join('employees as matched_employees', 'matched_employees.id', '=', 'fingerprint_logs.employee_id')
            ->join('fingerprint_import_batches as completed_batches', 'completed_batches.id', '=', 'fingerprint_logs.import_batch_id')
            ->where('fingerprint_logs.matching_status', MatchingStatus::Matched->value)
            ->whereNotNull('fingerprint_logs.employee_id')
            ->whereNotNull('fingerprint_logs.attendance_date')
            ->whereNotNull('fingerprint_logs.attendance_time')
            ->whereColumn('fingerprint_logs.unit_id', 'matched_employees.unit_id')
            ->whereColumn('fingerprint_logs.unit_id', 'completed_batches.unit_id')
            ->where('completed_batches.status', ImportBatchStatus::Completed->value)
            ->whereBetween('fingerprint_logs.attendance_date', [$startDate, $endDate])
            ->when($unitId !== null, fn ($query) => $query->where('matched_employees.unit_id', $unitId))
            ->when($categoryId !== null, fn ($query) => $query->where('matched_employees.category_id', $categoryId))
            ->when($searchTerm !== '', function ($query) use ($searchTerm): void {
                $term = '%'.$searchTerm.'%';
                $query->where(function ($query) use ($term): void {
                    $query->where('matched_employees.namafinger', 'like', $term)
                        ->orWhere('matched_employees.nama_lengkap', 'like', $term)
                        ->orWhere('matched_employees.nip', 'like', $term)
                        ->orWhere('matched_employees.fingerprint_id', 'like', $term);
                });
            })
            ->select('fingerprint_logs.employee_id')
            ->selectRaw('COUNT(*) AS matched_log_count')
            ->selectRaw('SUM(CASE WHEN fingerprint_logs.needs_verification = 1 THEN 1 ELSE 0 END) AS verification_flagged_log_count')
            ->groupBy('fingerprint_logs.employee_id')
            ->get()
            ->keyBy('employee_id');

        return [
            'matched_log_count' => (int) $employees->sum('matched_log_count'),
            'employees_with_fingerprint' => $employees->count(),
            'verification_flagged_log_count' => (int) $employees->sum('verification_flagged_log_count'),
            'per_employee' => $employees->map(fn (object $row): array => [
                'matched_log_count' => (int) $row->matched_log_count,
                'verification_flagged_log_count' => (int) $row->verification_flagged_log_count,
            ]),
        ];
    }
}
