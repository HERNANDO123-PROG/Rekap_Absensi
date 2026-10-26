<?php

namespace App\Services\Leave;

use App\Enums\EmployeeStatus;
use App\Enums\LeaveApprovalStatus;
use App\Enums\LeaveType;
use App\Enums\MatchingStatus;
use App\Models\AttendanceRecord;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\User;
use App\Services\Attendance\AttendanceProcessingService;
use App\Services\NameNormalizer;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ManualLeaveRequestService
{
    public function __construct(
        private readonly NameNormalizer $normalizer,
        private readonly AttendanceProcessingService $attendance,
    ) {}

    /** @return array{request: LeaveRequest, attendance_reprocessed: bool} */
    public function create(array $attributes, User $user, ?string $ipAddress, ?string $userAgent): array
    {
        return DB::transaction(function () use ($attributes, $user, $ipAddress, $userAgent): array {
            $employee = $this->lockActiveEmployee((int) $attributes['employee_id'], (int) $attributes['unit_id']);

            $hasExistingRequest = LeaveRequest::query()
                ->where('employee_id', $employee->id)
                ->whereDate('request_date', $attributes['request_date'])
                ->lockForUpdate()
                ->exists();

            if ($hasExistingRequest) {
                throw new ManualLeaveRequestDuplicateException(
                    'Sudah ada pengajuan izin untuk karyawan ini pada tanggal tersebut.',
                );
            }

            $leaveType = LeaveType::from($attributes['leave_type']);
            $rawLeaveType = match ($leaveType) {
                LeaveType::Absent => 'Tidak Hadir',
                LeaveType::OfficialDuty => 'Dinas',
                LeaveType::TemporaryExit => 'Keluar',
                default => throw ValidationException::withMessages([
                    'leave_type' => 'Jenis izin manual tidak valid.',
                ]),
            };
            $name = $employee->displayName();

            $leaveRequest = LeaveRequest::query()->create([
                'import_batch_id' => null,
                'source' => 'manual',
                'created_by' => $user->id,
                'unit_id' => $employee->unit_id,
                'employee_id' => $employee->id,
                'raw_name' => $name,
                'normalized_name' => $this->normalizer->normalize($name),
                'position' => $employee->position,
                'request_date' => $attributes['request_date'],
                'request_time' => null,
                'leave_type' => $leaveType,
                'raw_leave_type' => $rawLeaveType,
                'description' => null,
                'attachment' => null,
                'matching_status' => MatchingStatus::Matched,
                'absence_reason' => null,
                'approval_status' => LeaveApprovalStatus::Pending,
                'confirmed_by' => null,
                'confirmed_at' => null,
            ]);

            AuditLog::query()->create([
                'user_id' => $user->id,
                'action' => 'leave.manual.created',
                'module' => 'leave_requests',
                'record_type' => LeaveRequest::class,
                'record_id' => $leaveRequest->id,
                'description' => json_encode([
                    'unit_id' => $employee->unit_id,
                    'employee_id' => $employee->id,
                    'request_date' => $attributes['request_date'],
                    'leave_type' => $leaveType->value,
                ], JSON_THROW_ON_ERROR),
                'ip_address' => $ipAddress,
                'user_agent' => $userAgent,
            ]);

            return [
                'request' => $leaveRequest,
                'attendance_reprocessed' => false,
            ];
        });
    }

    /** @return array{request: LeaveRequest, attendance_reprocessed: bool} */
    public function update(
        int $leaveRequestId,
        array $attributes,
        User $user,
        ?string $ipAddress,
        ?string $userAgent,
    ): array {
        return DB::transaction(function () use ($leaveRequestId, $attributes, $user, $ipAddress, $userAgent): array {
            $initial = LeaveRequest::query()->findOrFail($leaveRequestId);
            $this->assertManual($initial);
            $employeeIds = collect([$initial->employee_id, (int) $attributes['employee_id']])
                ->unique()
                ->sort()
                ->values();
            $employees = Employee::query()
                ->whereIn('id', $employeeIds)
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            $targetEmployee = $employees->get((int) $attributes['employee_id']);
            if (
                $targetEmployee === null
                || $targetEmployee->unit_id !== (int) $attributes['unit_id']
                || $targetEmployee->status !== EmployeeStatus::Active
            ) {
                throw ValidationException::withMessages([
                    'employee_id' => 'Karyawan tidak aktif atau tidak berada pada unit yang dipilih.',
                ]);
            }

            $leaveRequest = LeaveRequest::query()->lockForUpdate()->findOrFail($leaveRequestId);
            $this->assertManual($leaveRequest);
            if (! $employeeIds->contains($leaveRequest->employee_id)) {
                throw new ManualLeaveRequestDuplicateException(
                    'Izin berubah bersamaan. Muat ulang data lalu coba lagi.',
                );
            }

            $oldUnitId = $leaveRequest->unit_id;
            $oldEmployeeId = $leaveRequest->employee_id;
            $oldDate = $leaveRequest->request_date->format('Y-m-d');
            $oldLeaveType = $leaveRequest->leave_type;
            $oldApprovalStatus = $leaveRequest->approval_status;
            $this->assertNoDuplicate($targetEmployee->id, $attributes['request_date'], $leaveRequest->id);
            $leaveType = LeaveType::from($attributes['leave_type']);
            $name = $targetEmployee->displayName();
            $substantiveChange = $oldUnitId !== $targetEmployee->unit_id
                || $oldEmployeeId !== $targetEmployee->id
                || $oldDate !== $attributes['request_date']
                || $oldLeaveType !== $leaveType;

            $leaveRequest->fill([
                'unit_id' => $targetEmployee->unit_id,
                'employee_id' => $targetEmployee->id,
                'raw_name' => $name,
                'normalized_name' => $this->normalizer->normalize($name),
                'position' => $targetEmployee->position,
                'request_date' => $attributes['request_date'],
                'leave_type' => $leaveType,
                'raw_leave_type' => $this->rawLeaveType($leaveType),
                'matching_status' => MatchingStatus::Matched,
                'absence_reason' => null,
            ]);
            if ($substantiveChange && $oldApprovalStatus === LeaveApprovalStatus::Confirmed) {
                $leaveRequest->fill([
                    'approval_status' => LeaveApprovalStatus::Pending,
                    'confirmed_by' => null,
                    'confirmed_at' => null,
                ]);
            }
            $leaveRequest->save();

            $this->audit($user, 'leave.manual.updated', $leaveRequest, [
                'old_unit_id' => $oldUnitId,
                'old_employee_id' => $oldEmployeeId,
                'old_request_date' => $oldDate,
                'old_leave_type' => $oldLeaveType->value,
                'old_approval_status' => $oldApprovalStatus->value,
                'unit_id' => $targetEmployee->unit_id,
                'employee_id' => $targetEmployee->id,
                'request_date' => $attributes['request_date'],
                'leave_type' => $leaveType->value,
                'approval_status' => $leaveRequest->approval_status->value,
            ], $ipAddress, $userAgent);

            $reprocessed = $this->reprocessIfPreviouslyProcessed($oldUnitId, $oldEmployeeId, $oldDate, $user->id);
            if ($oldUnitId !== $targetEmployee->unit_id
                || $oldEmployeeId !== $targetEmployee->id
                || $oldDate !== $attributes['request_date']) {
                $reprocessed = $this->reprocessIfPreviouslyProcessed(
                    $targetEmployee->unit_id,
                    $targetEmployee->id,
                    $attributes['request_date'],
                    $user->id,
                ) || $reprocessed;
            }

            return ['request' => $leaveRequest, 'attendance_reprocessed' => $reprocessed];
        });
    }

    /** @return array{request: LeaveRequest, attendance_reprocessed: bool, attendance: array|null} */
    public function confirm(
        int $leaveRequestId,
        User $user,
        ?string $ipAddress,
        ?string $userAgent,
    ): array {
        return DB::transaction(function () use ($leaveRequestId, $user, $ipAddress, $userAgent): array {
            $leaveRequest = LeaveRequest::query()->lockForUpdate()->findOrFail($leaveRequestId);
            $this->assertManual($leaveRequest);
            if ($leaveRequest->approval_status !== LeaveApprovalStatus::Pending) {
                throw ValidationException::withMessages([
                    'approval_status' => 'Izin manual ini sudah dikonfirmasi.',
                ]);
            }

            $leaveRequest->fill([
                'approval_status' => LeaveApprovalStatus::Confirmed,
                'confirmed_by' => $user->id,
                'confirmed_at' => now(),
            ])->save();

            $this->audit($user, 'leave.manual.confirmed', $leaveRequest, [
                'unit_id' => $leaveRequest->unit_id,
                'employee_id' => $leaveRequest->employee_id,
                'request_date' => $leaveRequest->request_date->format('Y-m-d'),
                'leave_type' => $leaveRequest->leave_type->value,
                'approval_status' => $leaveRequest->approval_status->value,
            ], $ipAddress, $userAgent);

            $attendance = $this->attendance->processEmployeeDate(
                $leaveRequest->unit_id,
                $leaveRequest->employee_id,
                $leaveRequest->request_date->format('Y-m-d'),
                $user->id,
            );

            return [
                'request' => $leaveRequest,
                'attendance_reprocessed' => ! ($attendance['status'] === 'skipped_ineligible_employee'
                    || ($attendance['preserved_for_review'] ?? false)),
                'attendance' => $attendance,
            ];
        });
    }

    /** @return array{attendance_reprocessed: bool} */
    public function delete(
        int $leaveRequestId,
        User $user,
        ?string $ipAddress,
        ?string $userAgent,
    ): array {
        return DB::transaction(function () use ($leaveRequestId, $user, $ipAddress, $userAgent): array {
            $initial = LeaveRequest::query()->findOrFail($leaveRequestId);
            $this->assertManual($initial);
            Employee::query()
                ->whereKey($initial->employee_id)
                ->lockForUpdate()
                ->firstOrFail();

            $leaveRequest = LeaveRequest::query()->lockForUpdate()->findOrFail($leaveRequestId);
            $this->assertManual($leaveRequest);
            if ($leaveRequest->employee_id !== $initial->employee_id) {
                throw new ManualLeaveRequestDuplicateException(
                    'Izin berubah bersamaan. Muat ulang data lalu coba lagi.',
                );
            }

            $unitId = $leaveRequest->unit_id;
            $employeeId = $leaveRequest->employee_id;
            $date = $leaveRequest->request_date->format('Y-m-d');
            $wasConfirmed = $leaveRequest->approval_status === LeaveApprovalStatus::Confirmed;
            $this->audit($user, 'leave.manual.deleted', $leaveRequest, [
                'unit_id' => $unitId,
                'employee_id' => $employeeId,
                'request_date' => $date,
                'leave_type' => $leaveRequest->leave_type->value,
                'approval_status' => $leaveRequest->approval_status->value,
            ], $ipAddress, $userAgent);
            $leaveRequest->delete();

            return [
                'attendance_reprocessed' => $wasConfirmed
                    ? $this->reprocessIfPreviouslyProcessed($unitId, $employeeId, $date, $user->id)
                    : false,
            ];
        });
    }

    private function lockActiveEmployee(int $employeeId, int $unitId): Employee
    {
        $employee = Employee::query()
            ->whereKey($employeeId)
            ->lockForUpdate()
            ->first();

        if ($employee === null || $employee->unit_id !== $unitId || $employee->status !== EmployeeStatus::Active) {
            throw ValidationException::withMessages([
                'employee_id' => 'Karyawan tidak aktif atau tidak berada pada unit yang dipilih.',
            ]);
        }

        return $employee;
    }

    private function assertNoDuplicate(int $employeeId, string $date, ?int $exceptId = null): void
    {
        $query = LeaveRequest::query()
            ->where('employee_id', $employeeId)
            ->whereDate('request_date', $date);
        if ($exceptId !== null) {
            $query->where('id', '!=', $exceptId);
        }

        if ($query->lockForUpdate()->exists()) {
            throw new ManualLeaveRequestDuplicateException(
                'Sudah ada pengajuan izin untuk karyawan ini pada tanggal tersebut.',
            );
        }
    }

    private function assertManual(LeaveRequest $leaveRequest): void
    {
        if ($leaveRequest->source !== 'manual' || $leaveRequest->employee_id === null) {
            throw (new ModelNotFoundException)->setModel(LeaveRequest::class, [$leaveRequest->id]);
        }
    }

    private function rawLeaveType(LeaveType $leaveType): string
    {
        return match ($leaveType) {
            LeaveType::Absent => 'Tidak Hadir',
            LeaveType::OfficialDuty => 'Dinas',
            LeaveType::TemporaryExit => 'Keluar',
            default => throw ValidationException::withMessages([
                'leave_type' => 'Jenis izin manual tidak valid.',
            ]),
        };
    }

    private function audit(
        User $user,
        string $action,
        LeaveRequest $leaveRequest,
        array $details,
        ?string $ipAddress,
        ?string $userAgent,
    ): void {
        AuditLog::query()->create([
            'user_id' => $user->id,
            'action' => $action,
            'module' => 'leave_requests',
            'record_type' => LeaveRequest::class,
            'record_id' => $leaveRequest->id,
            'description' => json_encode($details, JSON_THROW_ON_ERROR),
            'ip_address' => $ipAddress,
            'user_agent' => $userAgent,
        ]);
    }

    private function reprocessIfPreviouslyProcessed(int $unitId, int $employeeId, string $date, int $userId): bool
    {
        $wasProcessed = AttendanceRecord::query()
            ->where('unit_id', $unitId)
            ->where('employee_id', $employeeId)
            ->whereDate('attendance_date', $date)
            ->exists();

        if (! $wasProcessed) {
            return false;
        }

        $this->attendance->processEmployeeDate($unitId, $employeeId, $date, $userId);

        return true;
    }
}
