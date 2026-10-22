<?php

namespace App\Services\Fingerprint;

use App\Enums\EmployeeStatus;
use App\Enums\ImportBatchStatus;
use App\Enums\MatchingStatus;
use App\Models\Employee;
use App\Models\FingerprintImportBatch;
use App\Models\FingerprintLog;
use App\Models\Unit;
use App\Models\WorkScheduleCategory;
use App\Services\Attendance\AttendanceProcessingService;
use App\Services\EmployeeMatchResult;
use App\Services\EmployeeMatchingService;
use App\Services\NameNormalizer;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class FingerprintImportService
{
    private const ALLOWED_EXTENSIONS = ['xls', 'xlsx'];

    public function __construct(
        private readonly FingerprintExcelReader $reader,
        private readonly EmployeeMatchingService $matching,
        private readonly FingerprintCategoryResolver $categoryResolver,
        private readonly NameNormalizer $normalizer,
        private readonly AttendanceProcessingService $attendanceProcessing,
    ) {}

    public function preview(UploadedFile $file, int $unitId): array
    {
        $context = $this->inspect($file, $unitId);
        $matchResults = $this->matching->findByFingerprints($unitId, $context['fingerprint_ids']);
        $errors = [];
        $parsedTimestampCount = 0;
        $importableTimestampCount = 0;
        $rawTimestampCount = 0;
        $invalidTimestampCount = 0;
        $verificationRows = [];
        $categoryAssignments = [];

        foreach ($context['rows'] as $row) {
            $parsedTimestampCount += count($row['timestamps']);
            $rawTimestampCount += $row['raw_timestamp_count'] ?? count($row['timestamps']);
            $invalidTimestampCount += $row['invalid_timestamp_count'] ?? 0;

            if ($row['attendance_date'] !== null && $row['fingerprint_id'] !== '') {
                $importableTimestampCount += count($row['timestamps']);
            }

            if ($row['errors'] !== []) {
                $errors[] = ['row' => $row['source_row'], 'messages' => $row['errors']];
            }

            $result = $matchResults[$row['fingerprint_id']] ?? EmployeeMatchResult::fromCandidates(new Collection);
            $verification = $this->categoryResolver->resolve($row, $result);
            $assignmentKey = $row['fingerprint_id'].':'.mb_strtolower(trim((string) $verification['employee_department_raw']));
            $categoryAssignments[$assignmentKey] ??= [
                'fingerprint_id' => $row['fingerprint_id'],
                'employee_name' => $row['employee_name_raw'],
                'department' => $verification['employee_department_raw'],
                'source_category' => $verification['category_name'],
                'source_start_time' => $verification['category_start_time'],
                'source_end_time' => $verification['category_end_time'],
                'employee_category' => $verification['employee_category_name'],
                'employee_start_time' => $verification['employee_category_start_time'],
                'employee_end_time' => $verification['employee_category_end_time'],
                'needs_verification' => $verification['needs_verification'],
                'message' => $verification['verification_note'],
            ];

            if ($verification['needs_verification']) {
                $key = $row['source_row'].':'.$row['fingerprint_id'];
                $verificationRows[$key] ??= [
                    'row' => $row['source_row'],
                    'fingerprint_id' => $row['fingerprint_id'],
                    'employee_name' => $row['employee_name_raw'],
                    'department' => $verification['employee_department_raw'],
                    'source_category' => $verification['category_name'],
                    'source_start_time' => $verification['category_start_time'],
                    'source_end_time' => $verification['category_end_time'],
                    'employee_category' => $verification['employee_category_name'],
                    'employee_start_time' => $verification['employee_category_start_time'],
                    'employee_end_time' => $verification['employee_category_end_time'],
                    'message' => $verification['verification_note'],
                ];
            }
        }

        $matchCounts = [
            MatchingStatus::Matched->value => 0,
            MatchingStatus::Unmatched->value => 0,
            MatchingStatus::Ambiguous->value => 0,
        ];

        foreach ($matchResults as $result) {
            $matchCounts[$result->status->value]++;
        }

        return [
            'filename' => $context['filename'],
            'file_hash' => $context['file_hash'],
            'unit' => ['id' => $context['unit']->id, 'code' => $context['unit']->code, 'name' => $context['unit']->name],
            'detected_sheet' => $context['sheet'],
            'period_start' => $context['period_start'],
            'period_end' => $context['period_end'],
            'total_rows' => count($context['rows']),
            'employees_detected' => $context['employees_detected'],
            'unique_fingerprint_ids' => count($context['fingerprint_ids']),
            'raw_timestamps_detected' => $rawTimestampCount,
            'valid_timestamps' => $parsedTimestampCount,
            'invalid_timestamps' => $invalidTimestampCount,
            'raw_attendance_entries' => $importableTimestampCount,
            'parsed_timestamp_count' => $parsedTimestampCount,
            'matched_employees' => $matchCounts[MatchingStatus::Matched->value],
            'unmatched_employees' => $matchCounts[MatchingStatus::Unmatched->value],
            'ambiguous_employees' => $matchCounts[MatchingStatus::Ambiguous->value],
            'invalid_rows' => count($errors),
            'parsing_errors' => $errors,
            'needs_verification_count' => count($verificationRows),
            'verification_issues' => array_values($verificationRows),
            'sync_conflicts' => $this->findSyncConflicts($unitId, $context['rows']),
            'category_assignments' => array_values($categoryAssignments),
            'already_imported' => $this->findExistingBatch($unitId, $context['file_hash']) !== null,
            'existing_batch_id' => $this->findExistingBatch($unitId, $context['file_hash'])?->id,
        ];
    }

    public function syncEmployees(UploadedFile $file, int $unitId): array
    {
        $context = $this->inspect($file, $unitId);
        $summary = $this->syncEmployeesAndCategories($unitId, $context['rows']);

        return [
            'filename' => $context['filename'],
            'unit' => ['id' => $context['unit']->id, 'code' => $context['unit']->code, 'name' => $context['unit']->name],
            'total_rows' => count($context['rows']),
            'employees_detected' => $context['employees_detected'],
            'unique_fingerprint_ids' => count($context['fingerprint_ids']),
            'employees_created' => $summary['created'],
            'employees_existing' => $summary['existing'],
            'employees_skipped' => $summary['skipped'],
            'categories_created' => $summary['categories_created'],
            'conflicts' => $summary['conflicts'],
            'sync_conflicts' => $summary['conflicts'],
        ];
    }

    public function import(UploadedFile $file, int $unitId, ?int $importedBy = null): FingerprintImportBatch
    {
        $context = $this->inspect($file, $unitId);
        $existingBatch = $this->findExistingBatch($unitId, $context['file_hash']);

        if ($existingBatch !== null) {
            return $this->reprocessImportBatch($existingBatch, $importedBy);
        }

        $this->assertNotImported($unitId, $context['file_hash']);
        $this->syncEmployeesAndCategories($unitId, $context['rows']);
        $matchResults = $this->matching->findByFingerprints($unitId, $context['fingerprint_ids']);
        $batch = FingerprintImportBatch::create([
            'unit_id' => $unitId,
            'filename' => $context['filename'],
            'file_hash' => $context['file_hash'],
            'imported_by' => $importedBy,
            'status' => ImportBatchStatus::Pending,
            'total_rows' => count($context['rows']),
            'period_start' => $context['period_start'],
            'period_end' => $context['period_end'],
        ]);

        try {
            return DB::transaction(function () use ($batch, $context, $matchResults): FingerprintImportBatch {
                $batch->update([
                    'status' => ImportBatchStatus::Processing,
                    'imported_at' => now(),
                ]);

                $successRows = 0;
                $errorRows = 0;
                $logRows = [];
                $now = now();

                foreach ($context['rows'] as $row) {
                    $rowIsImportable = $row['fingerprint_id'] !== ''
                        && $row['attendance_date'] !== null
                        && $row['timestamps'] !== [];

                    if (! $rowIsImportable) {
                        $errorRows++;

                        continue;
                    }

                    $result = $matchResults[$row['fingerprint_id']];
                    $verification = $this->categoryResolver->resolve($row, $result);

                    foreach ($row['timestamps'] as $timestamp) {
                        $verificationNote = $verification['verification_note'];
                        $needsVerification = $verification['needs_verification'];

                        $logRows[] = [
                            'import_batch_id' => $batch->id,
                            'unit_id' => $context['unit']->id,
                            'employee_id' => $result->employee?->id,
                            'fingerprint_id' => $row['fingerprint_id'],
                            'employee_name_raw' => $row['employee_name_raw'],
                            'employee_department_raw' => $verification['employee_department_raw'],
                            'source_category_id' => $verification['source_category_id'],
                            'attendance_date' => $row['attendance_date'],
                            'attendance_time' => $timestamp['time'].':00',
                            'raw_value' => $timestamp['raw_value'],
                            'source_sheet' => $context['sheet'],
                            'source_row' => $row['source_row'],
                            'source_cell' => $timestamp['source_cell'],
                            'matching_status' => $result->status->value,
                            'needs_verification' => $needsVerification,
                            'verification_note' => $verificationNote,
                            'created_at' => $now,
                            'updated_at' => $now,
                        ];

                        if (count($logRows) >= 500) {
                            DB::table('fingerprint_logs')->insert($logRows);
                            $logRows = [];
                        }
                    }

                    if ($row['errors'] === []) {
                        $successRows++;
                    } else {
                        $errorRows++;
                    }
                }

                if ($logRows !== []) {
                    DB::table('fingerprint_logs')->insert($logRows);
                }

                $batch->update([
                    'status' => ImportBatchStatus::Completed,
                    'success_rows' => $successRows,
                    'error_rows' => $errorRows,
                ]);

                return $batch->refresh();
            });
        } catch (Throwable $exception) {
            if ($this->alreadyImported($unitId, $context['file_hash'])) {
                $batch->delete();

                throw new FingerprintDuplicateImportException('File ini sudah pernah diimport untuk unit tersebut.', previous: $exception);
            }

            try {
                $batch->refresh()->update([
                    'status' => ImportBatchStatus::Failed,
                    'error_rows' => $batch->total_rows,
                ]);
            } catch (Throwable $statusException) {
                Log::error('Failed to mark fingerprint import batch as failed.', [
                    'batch_id' => $batch->id,
                    'exception' => $statusException,
                ]);
            }

            Log::error('Fingerprint import transaction failed.', [
                'batch_id' => $batch->id,
                'exception' => $exception,
            ]);

            throw new FingerprintImportException('Fingerprint import failed. No partial logs were committed.', previous: $exception);
        }
    }

    private function inspect(UploadedFile $file, int $unitId): array
    {
        $unit = Unit::query()->find($unitId);

        if ($unit === null) {
            throw new FingerprintImportException('A valid unit (TK or SD) must be selected.');
        }

        $extension = strtolower($file->getClientOriginalExtension());

        if (! in_array($extension, self::ALLOWED_EXTENSIONS, true)) {
            throw new FingerprintImportException('Only .xls and .xlsx Excel files are supported.');
        }

        $path = $file->getRealPath();

        if ($path === false || ! is_file($path) || ! is_readable($path)) {
            throw new FingerprintImportException('The uploaded file could not be read.');
        }

        $fileHash = hash_file('sha256', $path);

        if ($fileHash === false) {
            throw new FingerprintImportException('The uploaded file could not be hashed.');
        }

        $workbook = $this->reader->read($path);
        $rows = $workbook['rows'];
        $dates = array_values(array_filter(array_column($rows, 'attendance_date')));

        if ($dates === []) {
            throw new FingerprintImportException('No valid attendance dates were found in "Lap. Log Absen".');
        }

        $periodStart = $workbook['period_start'] ?? min($dates);
        $periodEnd = $workbook['period_end'] ?? max($dates);

        $fingerprintIds = array_values(array_unique(array_filter(array_map(
            static fn (array $row): string => $row['fingerprint_id'],
            $rows,
        ), static fn (string $id): bool => $id !== '')));

        return [
            'unit' => $unit,
            'filename' => $this->safeFilename($file->getClientOriginalName()),
            'file_hash' => $fileHash,
            'sheet' => $workbook['sheet'],
            'rows' => $rows,
            'fingerprint_ids' => $fingerprintIds,
            'period_start' => $periodStart,
            'period_end' => $periodEnd,
            'employees_detected' => $workbook['employees_detected'] ?? count(array_unique(array_filter(array_column($rows, 'fingerprint_id')))),
        ];
    }

    private function syncEmployeesAndCategories(int $unitId, array $rows): array
    {
        return DB::transaction(fn (): array => $this->syncEmployeesAndCategoriesWithinTransaction($unitId, $rows));
    }

    private function syncEmployeesAndCategoriesWithinTransaction(int $unitId, array $rows): array
    {
        $seen = [];
        $created = 0;
        $existing = 0;
        $skipped = 0;
        $categoriesCreated = 0;
        $conflicts = [];

        foreach ($rows as $row) {
            $fingerprintId = trim((string) ($row['fingerprint_id'] ?? ''));

            if ($fingerprintId === '' || isset($seen[$fingerprintId])) {
                continue;
            }

            $seen[$fingerprintId] = true;

            $employee = Employee::query()->where('unit_id', $unitId)->where('fingerprint_id', $fingerprintId)->first();
            if ($employee !== null) {
                $existing++;

                $name = trim((string) ($row['employee_name_raw'] ?? ''));
                if ($name !== '' && $employee->namafinger !== null && trim((string) $employee->namafinger) !== '' && $this->normalizer->normalize((string) $employee->namafinger) !== $this->normalizer->normalize($name)) {
                    $conflicts[] = [
                        'fingerprint_id' => $fingerprintId,
                        'master_name' => $employee->namafinger,
                        'file_name' => $name,
                        'reason' => 'Nama fingerprint akan diperbarui dari file; data manual pegawai tetap dipertahankan.',
                    ];
                }

                $this->updateFingerprintName($employee, $name);

                continue;
            }

            $name = trim((string) ($row['employee_name_raw'] ?? ''));
            $department = trim((string) ($row['employee_department_raw'] ?? ''));
            $categoryId = null;

            if ($department !== '') {
                $categoryId = $this->resolveCategoryId($department, $categoriesCreated);
            }

            Employee::query()->create([
                'unit_id' => $unitId,
                'category_id' => $categoryId,
                'fingerprint_id' => $fingerprintId,
                'namafinger' => $name === '' ? null : $name,
                'nama_lengkap' => null,
                'position' => null,
                'status' => EmployeeStatus::Active->value,
            ]);

            $created++;
        }

        return [
            'created' => $created,
            'existing' => $existing,
            'skipped' => $skipped,
            'categories_created' => $categoriesCreated,
            'conflicts' => $conflicts,
        ];
    }

    private function resolveCategoryId(string $department, ?int &$categoriesCreated = null): ?int
    {
        $normalizedDepartment = $this->normalizer->normalize($department);

        if ($normalizedDepartment === '') {
            return null;
        }

        $category = WorkScheduleCategory::query()
            ->whereRaw('LOWER(name) = ?', [mb_strtolower(trim($department), 'UTF-8')])
            ->first();

        if ($category !== null) {
            return $category->id;
        }

        $created = WorkScheduleCategory::query()->create([
            'name' => trim($department),
            'start_time' => null,
            'end_time' => null,
            'is_active' => true,
        ]);

        if ($categoriesCreated !== null) {
            $categoriesCreated++;
        }

        return $created->id;
    }

    private function findSyncConflicts(int $unitId, array $rows): array
    {
        $seen = [];
        $conflicts = [];

        foreach ($rows as $row) {
            $fingerprintId = trim((string) ($row['fingerprint_id'] ?? ''));

            if ($fingerprintId === '' || isset($seen[$fingerprintId])) {
                continue;
            }

            $seen[$fingerprintId] = true;

            $employee = Employee::query()->where('unit_id', $unitId)->where('fingerprint_id', $fingerprintId)->first();
            if ($employee === null) {
                continue;
            }

            $name = trim((string) ($row['employee_name_raw'] ?? ''));
            if ($name === '' || $employee->namafinger === null || trim((string) $employee->namafinger) === '') {
                continue;
            }

            if ($this->normalizer->normalize((string) $employee->namafinger) !== $this->normalizer->normalize($name)) {
                $conflicts[] = [
                    'fingerprint_id' => $fingerprintId,
                    'master_name' => $employee->namafinger,
                    'file_name' => $name,
                    'message' => 'Fingerprint ID '.$fingerprintId.': Master = '.trim((string) $employee->namafinger).' | File = '.$name,
                ];
            }
        }

        return $conflicts;
    }

    public function reprocessImportBatch(FingerprintImportBatch $batch, ?int $importedBy = null): FingerprintImportBatch
    {
        $unitId = $batch->unit_id;

        try {
            return DB::transaction(function () use ($batch, $unitId, $importedBy): FingerprintImportBatch {
                $batch->refresh();
                $batch->update([
                    'status' => ImportBatchStatus::Processing,
                    'imported_by' => $importedBy ?? $batch->imported_by,
                    'imported_at' => $batch->imported_at ?? now(),
                ]);

                $this->syncEmployeesAndCategoriesFromBatch($batch);

                $fingerprintIds = $batch->logs()
                    ->select('fingerprint_id')
                    ->distinct()
                    ->pluck('fingerprint_id')
                    ->filter()
                    ->values()
                    ->all();

                $matchResults = $this->matching->findByFingerprints($unitId, $fingerprintIds);

                foreach ($batch->logs()->orderBy('id')->get() as $log) {
                    $matchResult = $matchResults[$log->fingerprint_id] ?? EmployeeMatchResult::fromCandidates(new Collection);
                    $employeeId = $matchResult->employee?->id;
                    $updates = [
                        'matching_status' => $matchResult->status->value,
                    ];

                    if ($employeeId !== null) {
                        $updates['employee_id'] = $employeeId;
                    }

                    $log->fill($updates);
                    $log->save();
                }

                $startDate = $batch->period_start?->format('Y-m-d');
                $endDate = $batch->period_end?->format('Y-m-d');
                $processingSummary = null;

                if ($startDate !== null && $endDate !== null) {
                    $processingSummary = $this->attendanceProcessing->processRange($unitId, $startDate, $endDate, null, $importedBy);
                }

                $batch->refresh();
                $batch->update([
                    'status' => ImportBatchStatus::Completed,
                ]);

                $batch->refresh();
                $batch->setAttribute('processing_summary', $processingSummary);

                return $batch;
            });
        } catch (Throwable $exception) {
            try {
                $batch->refresh()->update([
                    'status' => ImportBatchStatus::Failed,
                ]);
            } catch (Throwable $statusException) {
                Log::error('Failed to mark fingerprint batch reprocess as failed.', [
                    'batch_id' => $batch->id,
                    'exception' => $statusException,
                ]);
            }

            Log::error('Fingerprint batch reprocess failed.', [
                'batch_id' => $batch->id,
                'unit_id' => $unitId,
                'exception' => $exception,
            ]);

            throw new FingerprintImportException('Fingerprint batch reprocess failed. Raw logs were preserved.', previous: $exception);
        }
    }

    private function syncEmployeesAndCategoriesFromBatch(FingerprintImportBatch $batch): void
    {
        $unitId = $batch->unit_id;
        $logs = $batch->logs()->orderBy('id')->get();
        $seen = [];

        foreach ($logs as $log) {
            $fingerprintId = trim((string) ($log->fingerprint_id ?? ''));

            if ($fingerprintId === '' || isset($seen[$fingerprintId])) {
                continue;
            }

            $seen[$fingerprintId] = true;

            $employee = Employee::query()->where('unit_id', $unitId)->where('fingerprint_id', $fingerprintId)->first();
            if ($employee !== null) {
                $this->updateFingerprintName($employee, trim((string) ($log->employee_name_raw ?? '')));

                continue;
            }

            $name = trim((string) ($log->employee_name_raw ?? ''));
            $department = trim((string) ($log->employee_department_raw ?? ''));
            $categoryId = $department !== '' ? $this->resolveCategoryId($department) : null;

            Employee::query()->create([
                'unit_id' => $unitId,
                'category_id' => $categoryId,
                'fingerprint_id' => $fingerprintId,
                'namafinger' => $name === '' ? null : $name,
                'nama_lengkap' => null,
                'position' => null,
                'status' => EmployeeStatus::Active->value,
            ]);
        }
    }

    private function updateFingerprintName(Employee $employee, string $name): void
    {
        if ($name === '') {
            return;
        }

        $employee->namafinger = $name;
        $employee->save();
    }

    private function assertNotImported(int $unitId, string $fileHash): void
    {
        if ($this->alreadyImported($unitId, $fileHash)) {
            throw new FingerprintDuplicateImportException('File ini sudah pernah diimport untuk unit tersebut.');
        }
    }

    private function findExistingBatch(int $unitId, string $fileHash): ?FingerprintImportBatch
    {
        return FingerprintImportBatch::query()
            ->where('unit_id', $unitId)
            ->where('file_hash', $fileHash)
            ->where('status', ImportBatchStatus::Completed->value)
            ->first();
    }

    private function alreadyImported(int $unitId, string $fileHash): bool
    {
        return $this->findExistingBatch($unitId, $fileHash) !== null;
    }

    private function safeFilename(string $filename): string
    {
        $filename = basename(str_replace('\\', '/', $filename));

        return mb_substr($filename, 0, 255, 'UTF-8');
    }
}
