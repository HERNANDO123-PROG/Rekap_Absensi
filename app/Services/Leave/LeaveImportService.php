<?php

namespace App\Services\Leave;

use App\Enums\AbsenceReason;
use App\Enums\ImportBatchStatus;
use App\Enums\LeaveType;
use App\Enums\MatchingStatus;
use App\Models\LeaveImportBatch;
use App\Models\Unit;
use App\Services\EmployeeMatchingService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class LeaveImportService
{
    private const ALLOWED_EXTENSIONS = ['xls', 'xlsx', 'csv'];

    public function __construct(
        private readonly LeaveFormFileReader $reader,
        private readonly LeaveFormRowParser $rowParser,
        private readonly EmployeeMatchingService $matching,
    ) {}

    public function preview(UploadedFile $file, int $unitId): array
    {
        $context = $this->inspect($file, $unitId);
        $validRows = array_values(array_filter($context['rows'], static fn (array $row): bool => $row['errors'] === []));
        $matchResults = $this->matching->findByNames($unitId, array_column($validRows, 'normalized_name'));
        $matchingCounts = [
            MatchingStatus::Matched->value => 0,
            MatchingStatus::Unmatched->value => 0,
            MatchingStatus::Ambiguous->value => 0,
        ];
        $leaveTypeDistribution = array_fill_keys(array_map(static fn (LeaveType $type): string => $type->value, LeaveType::cases()), 0);
        $absenceReasonDistribution = array_fill_keys(array_map(static fn (AbsenceReason $reason): string => $reason->value, AbsenceReason::cases()), 0);
        $validationErrors = [];

        foreach ($context['rows'] as $row) {
            if ($row['errors'] !== []) {
                $validationErrors[] = [
                    'row' => $row['source_row'],
                    'messages' => $row['errors'],
                    'raw_name' => $row['raw_name'],
                    'raw_date' => $row['raw_date'],
                    'raw_time' => $row['raw_time'],
                    'raw_leave_type' => $row['raw_leave_type'],
                ];

                continue;
            }

            $match = $matchResults[$row['normalized_name']];
            $matchingCounts[$match->status->value]++;
            $leaveTypeDistribution[$row['leave_type']]++;

            if ($row['absence_reason'] !== null) {
                $absenceReasonDistribution[$row['absence_reason']]++;
            }
        }

        $duplicateHeaderFields = array_values(array_intersect(
            $context['header_mapping']['ambiguous_fields'],
            ['raw_name', 'position', 'request_date', 'request_time', 'leave_type', 'description'],
        ));

        return [
            'filename' => $context['filename'],
            'unit' => ['id' => $context['unit']->id, 'code' => $context['unit']->code, 'name' => $context['unit']->name],
            'detected_headers' => $context['header_mapping']['detected_headers'],
            'mapped_fields' => $context['header_mapping']['mapped_fields'],
            'missing_fields' => $context['header_mapping']['missing_fields'],
            'ambiguous_fields' => $context['header_mapping']['ambiguous_fields'],
            'ignored_additional_columns' => $context['header_mapping']['additional_headers'],
            'sheet_name' => $context['sheet_name'],
            'period_start' => $context['period_start'],
            'period_end' => $context['period_end'],
            'total_rows' => count($context['rows']),
            'valid_rows' => count($validRows),
            'invalid_rows' => count($context['rows']) - count($validRows),
            'matched' => $matchingCounts[MatchingStatus::Matched->value],
            'unmatched' => $matchingCounts[MatchingStatus::Unmatched->value],
            'ambiguous' => $matchingCounts[MatchingStatus::Ambiguous->value],
            'leave_type_distribution' => $leaveTypeDistribution,
            'absence_reason_distribution' => $absenceReasonDistribution,
            'validation_errors' => $validationErrors,
            'warnings' => $duplicateHeaderFields === [] ? [] : ['Optional or required headers are ambiguous: '.implode(', ', $duplicateHeaderFields)],
            'already_imported' => $this->alreadyImported($unitId, $context['file_hash']),
        ];
    }

    public function import(UploadedFile $file, int $unitId, ?int $importedBy = null): LeaveImportBatch
    {
        $context = $this->inspect($file, $unitId);
        $this->assertNotImported($unitId, $context['file_hash']);

        $validRows = array_values(array_filter($context['rows'], static fn (array $row): bool => $row['errors'] === []));
        $matchResults = $this->matching->findByNames($unitId, array_column($validRows, 'normalized_name'));
        $batch = null;

        try {
            return DB::transaction(function () use ($context, $matchResults, $importedBy, &$batch): LeaveImportBatch {
                $batch = LeaveImportBatch::create([
                    'unit_id' => $context['unit']->id,
                    'filename' => $context['filename'],
                    'file_hash' => $context['file_hash'],
                    'imported_by' => $importedBy,
                    'imported_at' => now(),
                    'period_start' => $context['period_start'],
                    'period_end' => $context['period_end'],
                    'status' => ImportBatchStatus::Processing,
                    'total_rows' => count($context['rows']),
                ]);

                $successRows = 0;
                $requests = [];
                $errorDetails = [];
                $now = now();

                foreach ($context['rows'] as $row) {
                    if ($row['errors'] !== []) {
                        $errorDetails[] = $this->errorDetail($row);

                        continue;
                    }

                    $match = $matchResults[$row['normalized_name']];
                    $requests[] = [
                        'import_batch_id' => $batch->id,
                        'unit_id' => $context['unit']->id,
                        'employee_id' => $match->employee?->id,
                        'raw_name' => $row['raw_name'],
                        'normalized_name' => $row['normalized_name'],
                        'position' => $row['position'],
                        'request_date' => $row['request_date'],
                        'request_time' => $row['request_time'].':00',
                        'leave_type' => $row['leave_type'],
                        'raw_leave_type' => $row['raw_leave_type'],
                        'description' => $row['description'],
                        'attachment' => $row['attachment'],
                        'matching_status' => $match->status->value,
                        'absence_reason' => $row['absence_reason'],
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                    $successRows++;

                    if (count($requests) >= 500) {
                        DB::table('leave_requests')->insert($requests);
                        $requests = [];
                    }
                }

                if ($requests !== []) {
                    DB::table('leave_requests')->insert($requests);
                }

                $batch->update([
                    'status' => ImportBatchStatus::Completed,
                    'success_rows' => $successRows,
                    'error_rows' => count($errorDetails),
                    'error_details' => $errorDetails,
                ]);

                return $batch->refresh();
            });
        } catch (Throwable $exception) {
            if ($this->alreadyImported($unitId, $context['file_hash'])) {
                throw new LeaveDuplicateImportException('File ini sudah pernah diimport untuk unit tersebut.', previous: $exception);
            }

            if ($batch !== null) {
                try {
                    $batch->refresh()->update([
                        'status' => ImportBatchStatus::Failed,
                        'error_rows' => $batch->total_rows,
                    ]);
                } catch (Throwable $statusException) {
                    Log::error('Failed to mark leave import batch as failed.', [
                        'batch_id' => $batch->id,
                        'exception' => $statusException,
                    ]);
                }
            }

            Log::error('Leave import transaction failed.', [
                'batch_id' => $batch?->id,
                'exception' => $exception,
            ]);

            throw new LeaveImportException('Leave import failed. No partial requests were committed.', previous: $exception);
        }
    }

    private function inspect(UploadedFile $file, int $unitId): array
    {
        $unit = Unit::query()->find($unitId);

        if ($unit === null) {
            throw new LeaveImportException('A valid unit (TK or SD) must be selected.');
        }

        $extension = strtolower($file->getClientOriginalExtension());

        if (! in_array($extension, self::ALLOWED_EXTENSIONS, true)) {
            throw new LeaveImportException('Only .xls, .xlsx, and .csv files are supported.');
        }

        $path = $file->getRealPath();

        if ($path === false || ! is_file($path) || ! is_readable($path)) {
            throw new LeaveImportException('The uploaded file could not be read.');
        }

        $fileHash = hash_file('sha256', $path);

        if ($fileHash === false) {
            throw new LeaveImportException('The uploaded file could not be hashed.');
        }

        $data = $this->reader->read($path, $extension);
        $requiredHeaderFields = ['raw_name', 'position', 'request_date', 'request_time', 'leave_type', 'description'];
        $missingRequired = array_values(array_diff($requiredHeaderFields, array_keys($data['header_mapping']['mapping'])));
        $ambiguousRequired = array_values(array_intersect($requiredHeaderFields, $data['header_mapping']['ambiguous_fields']));

        if ($missingRequired !== [] || $ambiguousRequired !== []) {
            throw new LeaveImportException('Required Google Form headers are missing or ambiguous.', [
                'missing_fields' => $missingRequired,
                'ambiguous_fields' => $ambiguousRequired,
                'detected_headers' => $data['header_mapping']['detected_headers'],
            ]);
        }

        $rows = [];
        $dates = [];

        foreach ($data['rows'] as $index => $sourceRow) {
            $parsed = $this->rowParser->parse($sourceRow, $data['header_row'] + $index + 1, $data['header_mapping']['mapping']);

            if ($parsed === null) {
                continue;
            }

            $rows[] = $parsed;

            if ($parsed['request_date'] !== null) {
                $dates[] = $parsed['request_date'];
            }
        }

        if ($dates === []) {
            throw new LeaveImportException('No valid Tanggal Pengajuan Izin values were found.', [
                'detected_headers' => $data['header_mapping']['detected_headers'],
            ]);
        }

        return [
            'unit' => $unit,
            'filename' => $this->safeFilename($file->getClientOriginalName()),
            'file_hash' => $fileHash,
            'sheet_name' => $data['sheet_name'],
            'header_mapping' => $data['header_mapping'],
            'rows' => $rows,
            'period_start' => min($dates),
            'period_end' => max($dates),
        ];
    }

    private function errorDetail(array $row): array
    {
        return [
            'row' => $row['source_row'],
            'messages' => $row['errors'],
            'raw_name' => $row['raw_name'],
            'raw_leave_type' => $row['raw_leave_type'],
            'raw_date' => $row['raw_date'],
            'raw_time' => $row['raw_time'],
            'description' => $row['description'],
        ];
    }

    private function assertNotImported(int $unitId, string $fileHash): void
    {
        if ($this->alreadyImported($unitId, $fileHash)) {
            throw new LeaveDuplicateImportException('File ini sudah pernah diimport untuk unit tersebut.');
        }
    }

    private function alreadyImported(int $unitId, string $fileHash): bool
    {
        return LeaveImportBatch::query()
            ->where('unit_id', $unitId)
            ->where('file_hash', $fileHash)
            ->where('status', ImportBatchStatus::Completed->value)
            ->exists();
    }

    private function safeFilename(string $filename): string
    {
        return mb_substr(basename(str_replace('\\', '/', $filename)), 0, 255, 'UTF-8');
    }
}
