<?php

namespace App\Http\Controllers;

use App\Http\Requests\LeaveImportRequest;
use App\Services\Leave\LeaveDuplicateImportException;
use App\Services\Leave\LeaveImportException;
use App\Services\Leave\LeaveImportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Throwable;

class LeaveImportController extends Controller
{
    public function __construct(private readonly LeaveImportService $imports) {}

    public function preview(LeaveImportRequest $request): JsonResponse
    {
        try {
            return response()->json([
                'data' => $this->imports->preview($request->file('file'), (int) $request->validated('unit_id')),
            ]);
        } catch (LeaveImportException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
                'details' => $exception->details,
            ], 422);
        } catch (Throwable $exception) {
            Log::error('Leave import preview failed.', ['exception' => $exception]);

            return response()->json(['message' => 'Leave preview failed. Check the uploaded file and try again.'], 500);
        }
    }

    public function store(LeaveImportRequest $request): JsonResponse
    {
        try {
            $batch = $this->imports->import(
                $request->file('file'),
                (int) $request->validated('unit_id'),
                $request->user()?->id,
            );

            return response()->json(['data' => $batch->load('unit')], 201);
        } catch (LeaveDuplicateImportException $exception) {
            return response()->json(['message' => $exception->getMessage()], 409);
        } catch (LeaveImportException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
                'details' => $exception->details,
            ], 422);
        } catch (Throwable $exception) {
            Log::error('Leave import request failed.', ['exception' => $exception]);

            return response()->json(['message' => 'Leave import failed. No partial requests were committed.'], 500);
        }
    }
}
