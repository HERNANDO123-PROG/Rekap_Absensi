<?php

namespace App\Services\Reports;

use App\Enums\AttendanceIntervalType;
use App\Enums\AttendanceStatus;
use App\Models\AttendanceRecord;
use App\Models\Employee;
use Carbon\CarbonImmutable;

class EmployeeAttendanceDetailService
{
    public function generate(Employee $employee, string $startDate, string $endDate): array
    {
        $start = CarbonImmutable::parse($startDate)->startOfDay();
        $end = CarbonImmutable::parse($endDate)->endOfDay();

        $records = AttendanceRecord::query()
            ->where('employee_id', $employee->id)
            ->whereBetween('attendance_date', [$start->toDateString(), $end->toDateString()])
            ->with(['intervals', 'anomalies'])
            ->orderBy('attendance_date')
            ->get();

        $summary = [
            'terlambat' => 0,
            'pulang_awal' => 0,
            'total_exit_minutes' => 0,
        ];

        $daily = [];

        foreach ($records as $record) {
            if ($record->status === AttendanceStatus::Late) {
                $summary['terlambat']++;
            }

            if ($record->status === AttendanceStatus::EarlyDeparture || (int) $record->early_leave_minutes > 0) {
                $summary['pulang_awal']++;
            }

            $summary['total_exit_minutes'] += (int) $record->intervals
                ->filter(fn ($interval) => $interval->type === AttendanceIntervalType::TemporaryExit)
                ->sum('duration_minutes');

            $daily[] = [
                'date' => $record->attendance_date->format('Y-m-d'),
                'scheduled_in' => $record->scheduled_in,
                'scheduled_out' => $record->scheduled_out,
                'actual_in' => $record->actual_in,
                'actual_out' => $record->actual_out,
                'late_minutes' => (int) $record->late_minutes,
                'early_leave_minutes' => (int) $record->early_leave_minutes,
                'status' => $record->status?->value ?? $record->status,
                'is_holiday' => (bool) $record->is_holiday,
                'activity_on_holiday' => (bool) $record->activity_on_holiday,
                'intervals' => $record->intervals->map(fn ($interval) => [
                    'type' => $interval->type?->value ?? $interval->type,
                    'start_time' => $interval->start_time,
                    'end_time' => $interval->end_time,
                    'duration_minutes' => (int) $interval->duration_minutes,
                    'source' => $interval->source?->value ?? $interval->source,
                    'notes' => $interval->notes,
                ])->values()->all(),
                'anomalies' => $record->anomalies->map(fn ($anomaly) => [
                    'type' => $anomaly->type?->value ?? $anomaly->type,
                    'message' => $anomaly->message,
                    'severity' => $anomaly->severity?->value ?? $anomaly->severity,
                    'is_resolved' => (bool) $anomaly->is_resolved,
                ])->values()->all(),
            ];
        }

        return [
            'employee' => [
                'id' => $employee->id,
                'nip' => $employee->nip,
                'name' => $employee->displayName(),
                'position' => $employee->position,
                'status' => $employee->status?->value ?? $employee->status,
                'unit' => $employee->unit?->code ?? $employee->unit?->name,
            ],
            'summary' => $summary,
            'daily' => $daily,
        ];
    }
}
