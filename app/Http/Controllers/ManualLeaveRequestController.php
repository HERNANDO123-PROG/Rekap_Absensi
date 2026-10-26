<?php

namespace App\Http\Controllers;

use App\Enums\LeaveApprovalStatus;
use App\Http\Requests\StoreManualLeaveRequest;
use App\Models\LeaveRequest;
use App\Models\User;
use App\Services\Leave\ManualLeaveRequestDuplicateException;
use App\Services\Leave\ManualLeaveRequestService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

class ManualLeaveRequestController extends Controller
{
    public function __construct(private readonly ManualLeaveRequestService $leaveRequests) {}

    public function store(StoreManualLeaveRequest $request): JsonResponse
    {
        try {
            $user = $request->user();
            if (! $user instanceof User) {
                return response()->json(['message' => 'Unauthenticated.'], 401);
            }

            $result = $this->leaveRequests->create(
                $request->validated(),
                $user,
                $request->ip(),
                $request->userAgent(),
            );

            return response()->json([
                'message' => 'Data izin berhasil disimpan dan menunggu konfirmasi.',
                'data' => $this->responseData($result),
            ], 201);
        } catch (ManualLeaveRequestDuplicateException|ValidationException $exception) {
            return $this->knownFailure($exception);
        } catch (ModelNotFoundException $exception) {
            return $this->notFound($exception);
        } catch (Throwable $exception) {
            return $this->unexpectedFailure('creation', $exception);
        }
    }

    public function update(StoreManualLeaveRequest $request, LeaveRequest $leaveRequest): JsonResponse
    {
        try {
            $user = $request->user();
            if (! $user instanceof User) {
                return response()->json(['message' => 'Unauthenticated.'], 401);
            }
            $result = $this->leaveRequests->update(
                $leaveRequest->id,
                $request->validated(),
                $user,
                $request->ip(),
                $request->userAgent(),
            );

            return response()->json([
                'message' => $result['request']->approval_status === LeaveApprovalStatus::Pending
                    ? 'Data izin berhasil diperbarui dan menunggu konfirmasi.'
                    : 'Data izin berhasil diperbarui.',
                'data' => $this->responseData($result),
            ]);
        } catch (ManualLeaveRequestDuplicateException|ValidationException $exception) {
            return $this->knownFailure($exception);
        } catch (ModelNotFoundException $exception) {
            return $this->notFound($exception);
        } catch (Throwable $exception) {
            return $this->unexpectedFailure('update', $exception);
        }
    }

    public function confirm(Request $request, LeaveRequest $leaveRequest): JsonResponse
    {
        try {
            $user = $request->user();
            if (! $user instanceof User) {
                return response()->json(['message' => 'Unauthenticated.'], 401);
            }

            $result = $this->leaveRequests->confirm(
                $leaveRequest->id,
                $user,
                $request->ip(),
                $request->userAgent(),
            );

            $message = match ($result['attendance']['status'] ?? null) {
                'skipped_ineligible_employee' => 'Izin dikonfirmasi, tetapi absensi tidak diproses karena karyawan tidak eligible: '
                    .($result['attendance']['ineligibility_reason'] ?? 'alasan tidak tersedia.'),
                'preserved_for_review' => 'Izin dikonfirmasi, tetapi absensi yang ada dipertahankan untuk peninjauan.',
                default => 'Izin manual berhasil dikonfirmasi dan absensi diproses.',
            };

            return response()->json([
                'message' => $message,
                'data' => $this->responseData($result),
            ]);
        } catch (ManualLeaveRequestDuplicateException|ValidationException $exception) {
            return $this->knownFailure($exception);
        } catch (ModelNotFoundException $exception) {
            return $this->notFound($exception);
        } catch (Throwable $exception) {
            return $this->unexpectedFailure('confirmation', $exception);
        }
    }

    public function destroy(Request $request, LeaveRequest $leaveRequest): JsonResponse
    {
        try {
            $user = $request->user();
            if (! $user instanceof User) {
                return response()->json(['message' => 'Unauthenticated.'], 401);
            }
            $result = $this->leaveRequests->delete(
                $leaveRequest->id,
                $user,
                $request->ip(),
                $request->userAgent(),
            );

            return response()->json([
                'message' => 'Data izin berhasil dihapus.',
                'data' => $result,
            ]);
        } catch (ManualLeaveRequestDuplicateException|ValidationException $exception) {
            return $this->knownFailure($exception);
        } catch (ModelNotFoundException $exception) {
            return $this->notFound($exception);
        } catch (Throwable $exception) {
            return $this->unexpectedFailure('deletion', $exception);
        }
    }

    private function responseData(array $result): array
    {
        $leaveRequest = $result['request'];

        return [
            'id' => $leaveRequest->id,
            'employee_id' => $leaveRequest->employee_id,
            'unit_id' => $leaveRequest->unit_id,
            'request_date' => $leaveRequest->request_date->format('Y-m-d'),
            'leave_type' => $leaveRequest->leave_type->value,
            'source' => $leaveRequest->source,
            'matching_status' => $leaveRequest->matching_status->value,
            'approval_status' => $leaveRequest->approval_status->value,
            'confirmed_by' => $leaveRequest->confirmed_by,
            'confirmed_at' => $leaveRequest->confirmed_at?->toISOString(),
            'created_by' => $leaveRequest->created_by,
            'attendance_reprocessed' => $result['attendance_reprocessed'],
            'attendance_status' => $result['attendance']['status'] ?? null,
        ];
    }

    private function knownFailure(ManualLeaveRequestDuplicateException|ValidationException $exception): JsonResponse
    {
        if ($exception instanceof ManualLeaveRequestDuplicateException) {
            return response()->json(['message' => $exception->getMessage()], 409);
        }

        return response()->json([
            'message' => 'Periksa kembali data izin.',
            'errors' => $exception->errors(),
        ], 422);
    }

    private function unexpectedFailure(string $operation, Throwable $exception): JsonResponse
    {
        Log::error("Manual leave request {$operation} failed.", ['exception' => $exception]);

        return response()->json([
            'message' => 'Data izin tidak dapat diproses. Tidak ada perubahan yang diterapkan.',
        ], 500);
    }

    private function notFound(ModelNotFoundException $exception): JsonResponse
    {
        return response()->json(['message' => 'Data izin manual tidak ditemukan.'], 404);
    }
}
