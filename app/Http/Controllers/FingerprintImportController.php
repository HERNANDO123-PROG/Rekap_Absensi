<?php

namespace App\Http\Controllers;

use App\Enums\ImportBatchStatus;
use App\Http\Requests\FingerprintImportRequest;
use App\Models\FingerprintImportBatch;
use App\Models\FingerprintLog;
use App\Services\Fingerprint\FingerprintDuplicateImportException;
use App\Services\Fingerprint\FingerprintImportException;
use App\Services\Fingerprint\FingerprintImportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;
use Throwable;

class FingerprintImportController extends Controller
{
    public function __construct(private readonly FingerprintImportService $imports) {}

    public function index(): View
    {
        $batches = FingerprintImportBatch::query()
            ->with('unit', 'importedBy')
            ->orderByDesc('imported_at')
            ->orderByDesc('id')
            ->get();

        return view('fingerprint-imports.index', ['batches' => $batches]);
    }

    public function show(FingerprintImportBatch $batch): View
    {
        $batch->load('unit', 'importedBy');
        $summary = [
            'employee_count' => $batch->logs()->distinct('employee_id')->count('employee_id'),
            'raw_scan_count' => $batch->logs()->count(),
            'unique_dates' => $batch->logs()->distinct('attendance_date')->count('attendance_date'),
            'historical_verification_note_count' => $batch->logs()
                ->where('verification_note', 'like', '%'.FingerprintLog::EMPLOYMENT_PERIOD_NOTE_MARKER.'%')
                ->count(),
            'status' => $batch->status,
        ];
        $historicalVerificationLogs = $batch->logs()
            ->with('employee')
            ->where('verification_note', 'like', '%'.FingerprintLog::EMPLOYMENT_PERIOD_NOTE_MARKER.'%')
            ->orderBy('attendance_date')
            ->orderBy('attendance_time')
            ->get([
                'id',
                'employee_id',
                'fingerprint_id',
                'employee_name_raw',
                'attendance_date',
                'attendance_time',
                'verification_note',
            ]);

        return view('fingerprint-imports.show', [
            'batch' => $batch,
            'summary' => $summary,
            'historicalVerificationLogs' => $historicalVerificationLogs,
        ]);
    }

    public function preview(FingerprintImportRequest $request): JsonResponse
    {
        try {
            return response()->json([
                'data' => $this->imports->preview($request->file('file'), (int) $request->validated('unit_id')),
            ]);
        } catch (FingerprintImportException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        } catch (Throwable $exception) {
            Log::error('Fingerprint import preview failed.', ['exception' => $exception]);

            return response()->json(['message' => 'Fingerprint preview failed. Check the uploaded workbook and try again.'], 500);
        }
    }

    public function sync(FingerprintImportRequest $request): JsonResponse
    {
        try {
            $result = $this->imports->syncEmployees(
                $request->file('file'),
                (int) $request->validated('unit_id'),
            );

            return response()->json([
                'message' => 'Sinkronisasi pegawai dan kategori berhasil.',
                'data' => $result,
            ], 200);
        } catch (FingerprintImportException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        } catch (Throwable $exception) {
            Log::error('Fingerprint employee sync request failed.', ['exception' => $exception]);

            return response()->json(['message' => 'Sinkronisasi pegawai gagal. Tidak ada data absensi atau batch yang dibuat.'], 500);
        }
    }

    public function store(FingerprintImportRequest $request): JsonResponse
    {
        try {
            $batch = $this->imports->import(
                $request->file('file'),
                (int) $request->validated('unit_id'),
                $request->user()?->id,
            );

            return response()->json(['data' => $batch->load('unit')], 201);
        } catch (FingerprintDuplicateImportException $exception) {
            return response()->json(['message' => $exception->getMessage()], 409);
        } catch (FingerprintImportException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        } catch (Throwable $exception) {
            Log::error('Fingerprint import request failed.', ['exception' => $exception]);

            return response()->json(['message' => 'Fingerprint import failed. No partial logs were committed.'], 500);
        }
    }

    public function reprocess(FingerprintImportBatch $batch)
    {
        try {
            $batch = $this->imports->reprocessImportBatch($batch, auth()->id());
            $processingSummary = $batch->getAttribute('processing_summary');

            if (request()->expectsJson() || request()->wantsJson()) {
                return response()->json([
                    'data' => $batch->load('unit'),
                    'processing_summary' => $processingSummary,
                ], 200);
            }

            $statusMessage = $this->reprocessingStatusMessage($processingSummary);

            return redirect()->route('fingerprint-imports.show', $batch)
                ->with('status', $statusMessage)
                ->with('reprocessing_summary', $processingSummary);
        } catch (FingerprintImportException $exception) {
            if (request()->expectsJson() || request()->wantsJson()) {
                return response()->json(['message' => $exception->getMessage()], 422);
            }

            return back()->withErrors(['batch' => $exception->getMessage()]);
        } catch (Throwable $exception) {
            Log::error('Fingerprint batch reprocess failed.', ['batch_id' => $batch->id, 'exception' => $exception]);

            if (request()->expectsJson() || request()->wantsJson()) {
                return response()->json(['message' => 'Fingerprint batch reprocess failed. Raw logs were preserved.'], 500);
            }

            return back()->withErrors(['batch' => 'Fingerprint batch reprocess failed. Raw logs were preserved.']);
        }
    }

    private function reprocessingStatusMessage(?array $summary): string
    {
        if ($summary === null) {
            return 'Batch disinkronkan. Tidak ada rentang tanggal batch untuk pemrosesan absensi.';
        }

        if (
            ($summary['failed'] ?? 0) > 0
            || ($summary['skipped_ineligible_employee'] ?? 0) > 0
            || ($summary['review_cases'] ?? []) !== []
        ) {
            return 'Pemrosesan ulang batch selesai sebagian atau memerlukan pemeriksaan. Rincian hasil ditampilkan di bawah.';
        }

        return 'Batch telah disinkronkan dan diproses ulang tanpa kegagalan.';
    }
}
