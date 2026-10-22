<?php

namespace App\Services\Attendance;

use App\Enums\AnomalySeverity;
use App\Enums\AttendanceAnomalyType;
use App\Models\AttendanceRecord;

class AttendanceAnomalyService
{
    private const PROCESSING_TYPES = [
        AttendanceAnomalyType::UnmatchedEmployee,
        AttendanceAnomalyType::AmbiguousEmployee,
        AttendanceAnomalyType::MissingFingerprint,
        AttendanceAnomalyType::UnexpectedExit,
        AttendanceAnomalyType::DuplicateData,
        AttendanceAnomalyType::InvalidTime,
        AttendanceAnomalyType::ExitReentryWithoutLeaveRequest,
        AttendanceAnomalyType::LeaveRequestWithoutFingerprint,
        AttendanceAnomalyType::OddFingerprintCountForExit,
        AttendanceAnomalyType::MultipleLeaveRequests,
        AttendanceAnomalyType::LeaveAbsenceConflictWithFingerprint,
        AttendanceAnomalyType::DuplicateTimestamp,
        AttendanceAnomalyType::InvalidSchedule,
        AttendanceAnomalyType::MissingSchedule,
    ];

    public function clearProcessingAnomalies(AttendanceRecord $record): void
    {
        $record->anomalies()->whereIn('type', array_map(
            static fn (AttendanceAnomalyType $type): string => $type->value,
            self::PROCESSING_TYPES,
        ))->delete();
    }

    public function record(
        AttendanceRecord $record,
        AttendanceAnomalyType $type,
        string $message,
        AnomalySeverity $severity = AnomalySeverity::Warning,
    ): void {
        $record->anomalies()->create([
            'type' => $type,
            'message' => $message,
            'severity' => $severity,
            'is_resolved' => false,
        ]);
    }
}
