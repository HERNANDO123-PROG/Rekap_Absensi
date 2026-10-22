<?php

namespace App\Services\Attendance;

use App\Enums\AnomalySeverity;
use App\Enums\AttendanceAnomalyType;
use App\Enums\AttendanceStatus;
use App\Enums\LeaveType;
use App\Enums\MatchingStatus;
use App\Models\AttendanceRecord;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\FingerprintLog;
use App\Models\LeaveRequest;
use App\Models\Unit;
use App\Services\EmployeeEligibilityService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

class AttendanceProcessingService
{
    public function __construct(
        private readonly AttendanceDateResolver $dateResolver,
        private readonly AttendanceFingerprintAnalyzer $fingerprintAnalyzer,
        private readonly AttendanceLeaveResolver $leaveResolver,
        private readonly AttendanceIntervalBuilder $intervalBuilder,
        private readonly AttendanceStatusResolver $statusResolver,
        private readonly AttendanceAnomalyService $anomalies,
        private readonly EmployeeEligibilityService $eligibility,
    ) {}

    public function processEmployeeDate(int $unitId, int $employeeId, string $date, ?int $userId = null): array
    {
        Unit::query()->findOrFail($unitId);
        $date = $this->parseDate($date)->format('Y-m-d');
        $employee = Employee::query()
            ->with(['category.workScheduleProfile.rules', 'workScheduleProfile.rules'])
            ->whereKey($employeeId)
            ->first();

        if ($employee === null || $employee->unit_id !== $unitId) {
            throw ValidationException::withMessages(['employee_id' => 'The selected employee does not belong to this unit.']);
        }

        $ineligibilityReason = $this->eligibility->ineligibilityReason($employee, $date);
        if ($ineligibilityReason !== null) {
            return $this->preserveIneligibleAttendance($employee, $date, $ineligibilityReason);
        }

        $schedule = $this->resolveEmployeeSchedule($employee, $unitId, $date);
        $logs = FingerprintLog::query()
            ->where('unit_id', $unitId)
            ->where('employee_id', $employeeId)
            ->whereDate('attendance_date', $date)
            ->where('matching_status', MatchingStatus::Matched->value)
            ->orderBy('attendance_time')
            ->orderBy('id')
            ->get();
        $leaveRequests = LeaveRequest::query()
            ->where('unit_id', $unitId)
            ->where('employee_id', $employeeId)
            ->whereDate('request_date', $date)
            ->where('matching_status', MatchingStatus::Matched->value)
            ->orderBy('id')
            ->get();

        $result = $this->processPrepared($employee, $date, $schedule, $logs, $leaveRequests);
        $auditDate = CarbonImmutable::parse($date);
        $this->recordAudit($userId, $unitId, $auditDate, $auditDate, $result);

        return $result;
    }

    public function processRange(
        int $unitId,
        string $dateFrom,
        string $dateTo,
        ?int $employeeId = null,
        ?int $userId = null,
    ): array {
        Unit::query()->findOrFail($unitId);
        $from = $this->parseDate($dateFrom);
        $to = $this->parseDate($dateTo);

        if ($from > $to) {
            throw ValidationException::withMessages(['date_to' => 'The end date must be on or after the start date.']);
        }

        $employeeQuery = Employee::query()
            ->with(['category.workScheduleProfile.rules', 'workScheduleProfile.rules'])
            ->where('unit_id', $unitId)
            ->orderBy('id');

        if ($employeeId !== null) {
            $employee = Employee::query()->whereKey($employeeId)->first();

            if ($employee === null || $employee->unit_id !== $unitId) {
                throw ValidationException::withMessages(['employee_id' => 'The selected employee does not belong to this unit.']);
            }

            $employeeQuery->whereKey($employeeId);
        }

        $summary = $this->emptySummary($unitId, $from, $to);

        for ($date = $from; $date <= $to; $date = $date->addDay()) {
            $dateValue = $date->format('Y-m-d');
            $logsByEmployee = FingerprintLog::query()
                ->where('unit_id', $unitId)
                ->whereDate('attendance_date', $dateValue)
                ->whereNotNull('employee_id')
                ->where('matching_status', MatchingStatus::Matched->value)
                ->orderBy('attendance_time')
                ->orderBy('id')
                ->get()
                ->groupBy('employee_id');
            $leaveRequestsByEmployee = LeaveRequest::query()
                ->where('unit_id', $unitId)
                ->whereDate('request_date', $dateValue)
                ->whereNotNull('employee_id')
                ->where('matching_status', MatchingStatus::Matched->value)
                ->orderBy('id')
                ->get()
                ->groupBy('employee_id');

            $summary['unmatched_fingerprint_logs'] += FingerprintLog::query()
                ->where('unit_id', $unitId)
                ->whereDate('attendance_date', $dateValue)
                ->where(function ($query): void {
                    $query->whereNull('employee_id')
                        ->orWhere('matching_status', '!=', MatchingStatus::Matched->value);
                })
                ->count();
            $summary['unmatched_leave_requests'] += LeaveRequest::query()
                ->where('unit_id', $unitId)
                ->whereDate('request_date', $dateValue)
                ->where(function ($query): void {
                    $query->whereNull('employee_id')
                        ->orWhere('matching_status', '!=', MatchingStatus::Matched->value);
                })
                ->count();

            $employeeQuery->chunkById(100, function (Collection $employees) use (
                $dateValue,
                $unitId,
                $logsByEmployee,
                $leaveRequestsByEmployee,
                &$summary,
            ): void {
                foreach ($employees as $employee) {
                    $ineligibilityReason = $this->eligibility->ineligibilityReason($employee, $dateValue);
                    if ($ineligibilityReason !== null) {
                        $preserved = $this->preserveIneligibleAttendance($employee, $dateValue, $ineligibilityReason);
                        $summary['skipped_ineligible_employee']++;
                        $summary['preserved_ineligible_attendance'] += $preserved['preserved'] ? 1 : 0;
                        if ($preserved['review_case'] !== null) {
                            $summary['review_cases'][] = $preserved['review_case'];
                        }

                        continue;
                    }

                    try {
                        $employeeSchedule = $this->resolveEmployeeSchedule($employee, $unitId, $dateValue);
                        $result = $this->processPrepared(
                            $employee,
                            $dateValue,
                            $employeeSchedule,
                            $logsByEmployee->get($employee->id, new Collection),
                            $leaveRequestsByEmployee->get($employee->id, new Collection),
                        );
                        if ($result['preserved_for_review']) {
                            $summary['preserved_for_review']++;
                            $summary['review_cases'][] = [
                                'employee_id' => $employee->id,
                                'attendance_date' => $dateValue,
                                'reason' => 'attendance_record_needs_verification',
                                'attendance_record_id' => $result['attendance_record_id'],
                            ];

                            continue;
                        }

                        $summary['processed']++;
                        $summary[$result['created'] ? 'created' : 'updated']++;
                        $summary['verification'] += $result['needs_verification'] ? 1 : 0;
                        $summary['anomalies'] += $result['anomalies'];
                    } catch (Throwable $exception) {
                        $summary['failed']++;
                        $summary['failed_cases'][] = [
                            'employee_id' => $employee->id,
                            'attendance_date' => $dateValue,
                        ];
                        Log::error('Attendance date processing failed.', [
                            'unit_id' => $employee->unit_id,
                            'employee_id' => $employee->id,
                            'attendance_date' => $dateValue,
                            'exception' => $exception,
                        ]);
                    }
                }
            });
        }

        $this->recordAudit($userId, $unitId, $from, $to, $summary);

        return $summary;
    }

    private function processPrepared(
        Employee $employee,
        string $date,
        array $schedule,
        Collection $logs,
        Collection $leaveRequests,
    ): array {
        return DB::transaction(function () use ($employee, $date, $schedule, $logs, $leaveRequests): array {
            $fingerprints = $this->fingerprintAnalyzer->analyze($logs);
            $resolvedLeaves = $this->leaveResolver->resolve($leaveRequests);
            $leave = $resolvedLeaves['primary'];
            $isAuthorizedExit = $leave?->leave_type->value === LeaveType::TemporaryExit->value;
            $exitTimeline = $isAuthorizedExit
                ? $this->intervalBuilder->build($fingerprints['times'], $date)
                : null;
            $decision = $this->statusResolver->resolve($schedule, $fingerprints, $resolvedLeaves, $exitTimeline);
            $anomalyList = $this->createAnomalyList($schedule, $fingerprints, $resolvedLeaves, $exitTimeline);
            $record = AttendanceRecord::query()
                ->where('employee_id', $employee->id)
                ->whereDate('attendance_date', $date)
                ->lockForUpdate()
                ->first();

            if (
                $record !== null
                && ($record->needs_verification || $record->status === AttendanceStatus::NeedsVerification)
            ) {
                return [
                    'employee_id' => $employee->id,
                    'attendance_date' => $date,
                    'status' => 'preserved_for_review',
                    'created' => false,
                    'updated' => false,
                    'needs_verification' => true,
                    'anomalies' => 0,
                    'attendance_record_id' => $record->id,
                    'preserved_for_review' => true,
                ];
            }

            $created = $record === null;
            $record ??= new AttendanceRecord;
            $record->fill([
                'employee_id' => $employee->id,
                'unit_id' => $employee->unit_id,
                'attendance_date' => $date,
                'status' => $decision['status'],
                'scheduled_in' => $decision['scheduled_in'],
                'actual_in' => $decision['actual_in'],
                'scheduled_out' => $decision['scheduled_out'],
                'actual_out' => $decision['actual_out'],
                'late_minutes' => $decision['late_minutes'],
                'early_leave_minutes' => $decision['early_leave_minutes'],
                'absence_reason' => $decision['absence_reason'],
                'leave_request_id' => $decision['leave_request_id'],
                'is_holiday' => $decision['is_holiday'],
                'activity_on_holiday' => $decision['activity_on_holiday'],
                'needs_verification' => $decision['needs_verification'],
                'verification_note' => $decision['verification_note'],
                'processed_at' => now(),
            ]);
            $record->save();

            $record->intervals()->delete();
            $this->anomalies->clearProcessingAnomalies($record);

            if ($isAuthorizedExit && $exitTimeline !== null) {
                foreach ($exitTimeline['intervals'] as $interval) {
                    $record->intervals()->create($interval);
                }

            }

            foreach ($anomalyList as [$type, $message, $severity]) {
                $this->anomalies->record($record, $type, $message, $severity);
            }

            return [
                'employee_id' => $employee->id,
                'attendance_date' => $date,
                'status' => $decision['status']->value,
                'created' => $created,
                'updated' => ! $created,
                'needs_verification' => $decision['needs_verification'],
                'anomalies' => count($anomalyList),
                'attendance_record_id' => $record->id,
                'preserved_for_review' => false,
            ];
        });
    }

    private function resolveEmployeeSchedule(Employee $employee, int $unitId, string $date): array
    {
        return $this->dateResolver->resolve(
            $unitId,
            $date,
            $employee->category_id === null ? null : (int) $employee->category_id,
            $employee,
        );
    }

    private function createAnomalyList(
        array $schedule,
        array $fingerprints,
        array $leaves,
        ?array $exitTimeline,
    ): array {
        $anomalies = [];

        if ($schedule['missing_schedule']) {
            $anomalies[] = [
                AttendanceAnomalyType::MissingSchedule,
                'Tidak ditemukan kalender override atau jadwal mingguan untuk unit dan tanggal ini.',
                AnomalySeverity::Critical,
            ];
        } elseif ($schedule['missing_category'] ?? false) {
            $anomalies[] = [
                AttendanceAnomalyType::MissingSchedule,
                'Karyawan belum memiliki kategori jam kerja aktif.',
                AnomalySeverity::Critical,
            ];
        } elseif ($schedule['invalid_schedule']) {
            $anomalies[] = [
                AttendanceAnomalyType::InvalidSchedule,
                'Jadwal hari kerja tidak memiliki jam mulai dan selesai yang lengkap.',
                AnomalySeverity::Critical,
            ];
        }

        foreach ($fingerprints['duplicates'] as $duplicate) {
            $anomalies[] = [
                AttendanceAnomalyType::DuplicateTimestamp,
                "Timestamp {$duplicate['time']} tercatat {$duplicate['count']} kali; raw logs tetap dipertahankan.",
                AnomalySeverity::Info,
            ];
        }

        if ($leaves['has_conflict']) {
            $anomalies[] = [
                AttendanceAnomalyType::MultipleLeaveRequests,
                'Terdapat beberapa leave request yang cocok untuk pegawai dan tanggal yang sama.',
                AnomalySeverity::Critical,
            ];
        }

        $leave = $leaves['primary'];

        if ($leave !== null) {
            $leaveType = $leave->leave_type->value;
            $needsFingerprint = in_array($leaveType, [
                LeaveType::Late->value,
                LeaveType::EarlyDeparture->value,
                LeaveType::TemporaryExit->value,
            ], true);

            if ($needsFingerprint && $fingerprints['distinct_count'] === 0) {
                $anomalies[] = [
                    AttendanceAnomalyType::LeaveRequestWithoutFingerprint,
                    'Jenis pengajuan ini memerlukan fingerprint untuk memverifikasi waktu aktual.',
                    AnomalySeverity::Warning,
                ];
            }

            if ($leaveType === LeaveType::Absent->value && $fingerprints['distinct_count'] > 0) {
                $anomalies[] = [
                    AttendanceAnomalyType::LeaveAbsenceConflictWithFingerprint,
                    'Pengajuan Tidak Hadir bertentangan dengan fingerprint pada tanggal yang sama.',
                    AnomalySeverity::Critical,
                ];
            }

            if ($leaveType === LeaveType::TemporaryExit->value
                && $fingerprints['distinct_count'] > 0
                && ($exitTimeline['odd_count'] ?? false)) {
                $anomalies[] = [
                    AttendanceAnomalyType::OddFingerprintCountForExit,
                    'Jumlah timestamp fingerprint tidak membentuk timeline Keluar yang lengkap.',
                    AnomalySeverity::Warning,
                ];
            }
        }

        if ($schedule['is_holiday']) {
            return $anomalies;
        }

        if ($leave === null && $fingerprints['possible_exit_reentry']) {
            $anomalies[] = [
                AttendanceAnomalyType::ExitReentryWithoutLeaveRequest,
                'Fingerprint menunjukkan kemungkinan keluar dan kembali tanpa pengajuan Keluar.',
                AnomalySeverity::Warning,
            ];
        } elseif ($leave !== null
            && $leave->leave_type->value !== LeaveType::TemporaryExit->value
            && $fingerprints['possible_exit_reentry']) {
            $anomalies[] = [
                AttendanceAnomalyType::ExitReentryWithoutLeaveRequest,
                'Fingerprint menunjukkan kemungkinan keluar dan kembali, tetapi tidak ada pengajuan Keluar yang sesuai.',
                AnomalySeverity::Warning,
            ];
        }

        return $anomalies;
    }

    private function emptySummary(int $unitId, CarbonImmutable $from, CarbonImmutable $to): array
    {
        return [
            'unit_id' => $unitId,
            'date_from' => $from->format('Y-m-d'),
            'date_to' => $to->format('Y-m-d'),
            'processed' => 0,
            'created' => 0,
            'updated' => 0,
            'verification' => 0,
            'anomalies' => 0,
            'failed' => 0,
            'failed_cases' => [],
            'unmatched_fingerprint_logs' => 0,
            'unmatched_leave_requests' => 0,
            'skipped_ineligible_employee' => 0,
            'preserved_ineligible_attendance' => 0,
            'preserved_for_review' => 0,
            'removed_ineligible_attendance' => 0,
            'review_cases' => [],
        ];
    }

    private function preserveIneligibleAttendance(Employee $employee, string $date, string $reason): array
    {
        $record = AttendanceRecord::query()
            ->where('employee_id', $employee->id)
            ->whereDate('attendance_date', $date)
            ->first();

        return [
            'status' => 'skipped_ineligible_employee',
            'processed' => 0,
            'created' => 0,
            'updated' => 0,
            'preserved' => $record !== null,
            'ineligibility_reason' => $reason,
            'attendance_record_id' => $record?->id,
            'review_case' => $record === null ? null : [
                'employee_id' => $employee->id,
                'attendance_date' => $date,
                'reason' => $reason,
                'attendance_record_id' => $record->id,
            ],
        ];
    }

    private function parseDate(string $date): CarbonImmutable
    {
        try {
            $parsed = CarbonImmutable::createFromFormat('!Y-m-d', $date);
        } catch (Throwable) {
            $parsed = false;
        }

        if ($parsed === false || $parsed->format('Y-m-d') !== $date) {
            throw ValidationException::withMessages(['date' => 'Dates must use the YYYY-MM-DD format.']);
        }

        return $parsed;
    }

    private function recordAudit(?int $userId, int $unitId, CarbonImmutable $from, CarbonImmutable $to, array $summary): void
    {
        AuditLog::create([
            'user_id' => $userId,
            'action' => ($summary['updated'] ?? 0) > 0 ? 'attendance.reprocess' : 'attendance.process',
            'module' => 'attendance',
            'record_type' => 'attendance_range',
            'description' => json_encode([
                'unit_id' => $unitId,
                'date_from' => $from->format('Y-m-d'),
                'date_to' => $to->format('Y-m-d'),
                'processed' => $summary['processed'] ?? 0,
                'created' => $summary['created'] ?? 0,
                'updated' => $summary['updated'] ?? 0,
                'failed' => $summary['failed'] ?? 0,
                'skipped_ineligible_employee' => $summary['skipped_ineligible_employee'] ?? 0,
                'preserved_ineligible_attendance' => $summary['preserved_ineligible_attendance'] ?? 0,
                'preserved_for_review' => $summary['preserved_for_review'] ?? 0,
            ], JSON_THROW_ON_ERROR),
        ]);
    }
}
