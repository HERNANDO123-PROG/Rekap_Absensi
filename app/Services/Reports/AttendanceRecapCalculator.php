<?php

namespace App\Services\Reports;

use App\Enums\AttendanceIntervalType;
use App\Enums\AttendanceStatus;
use App\Enums\LeaveType;
use App\Models\AttendanceRecord;
use Carbon\CarbonImmutable;

class AttendanceRecapCalculator
{
    public function calculate(AttendanceRecord $record): array
    {
        $date = CarbonImmutable::parse($record->attendance_date);
        $actualIn = $this->time($record->getRawOriginal('actual_in'));
        $actualOut = $this->time($record->getRawOriginal('actual_out'));
        $scheduledIn = $this->time($record->getRawOriginal('scheduled_in'));
        $scheduledOut = $this->time($record->getRawOriginal('scheduled_out'));
        $status = $record->status?->value ?? $record->status;
        $isWorkday = $date->dayOfWeekIso < 7
            && ! $record->is_holiday
            && $status !== AttendanceStatus::Holiday->value;
        $isNonAttendanceStatus = in_array($status, [
            AttendanceStatus::OfficialDuty->value,
            AttendanceStatus::Absent->value,
            AttendanceStatus::Holiday->value,
        ], true);
        $leaveRequest = $record->relationLoaded('leaveRequest') ? $record->leaveRequest : null;
        $hasExitRequest = $leaveRequest?->leave_type === LeaveType::TemporaryExit
            || $status === AttendanceStatus::TemporaryExit->value
            || $record->intervals->contains('type', AttendanceIntervalType::TemporaryExit);
        $hasBothEndpoints = $actualIn !== null && $actualOut !== null;
        $hasAttendance = $hasExitRequest
            ? $hasBothEndpoints
            : ($actualIn !== null || $actualOut !== null);
        $totalFinger = (int) ($actualIn !== null) + (int) ($actualOut !== null);

        $late = ! $isNonAttendanceStatus
            && $isWorkday
            && $actualIn !== null
            && $scheduledIn !== null
            && ($hasExitRequest || $actualOut !== null)
            && $actualIn > $scheduledIn;

        $earlyLeave = ! $isNonAttendanceStatus
            && $isWorkday
            && $hasAttendance
            && (
                ($actualIn !== null && $actualOut === null)
                || ($actualOut !== null && $scheduledOut !== null && $actualOut < $scheduledOut)
            );

        return [
            'is_workday' => $isWorkday,
            'hadir' => (int) ($isWorkday && ! $isNonAttendanceStatus && $hasAttendance),
            'terlambat' => (int) $late,
            'pulang_awal' => (int) $earlyLeave,
            'total_finger' => $isWorkday ? $totalFinger : 0,
            'izin_keluar' => (int) $hasExitRequest,
            'actual_in' => $actualIn,
            'actual_out' => $actualOut,
            'scheduled_in' => $scheduledIn,
            'scheduled_out' => $scheduledOut,
        ];
    }

    private function time(mixed $value): ?string
    {
        return $value === null ? null : substr((string) $value, 0, 5);
    }
}
