<?php

namespace App\Services\Reports;

use App\Enums\AttendanceStatus;
use App\Enums\AttendanceIntervalType;
use App\Models\AttendanceRecord;
use App\Models\Employee;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

class AttendancePeriodReportService
{
    public function __construct(private readonly AttendanceRecapCalculator $recapCalculator) {}

    public function generate(int $unitId, string $startDate, string $endDate, ?array $employeeIds = null): array
    {
        $start = CarbonImmutable::parse($startDate)->startOfDay();
        $end = CarbonImmutable::parse($endDate)->endOfDay();

        if ($end->lt($start)) {
            throw ValidationException::withMessages([
                'end_date' => ['The end date must be on or after the start date.'],
            ]);
        }

        $employees = Employee::query()
            ->where('unit_id', $unitId)
            ->when($employeeIds !== null, fn ($query) => $query->whereIn('id', $employeeIds))
            ->with('unit')
            ->orderByDisplayName()
            ->get();

        $records = AttendanceRecord::query()
            ->where('unit_id', $unitId)
            ->whereBetween('attendance_date', [$start->toDateString(), $end->toDateString()])
            ->when($employeeIds !== null, fn ($query) => $query->whereIn('employee_id', $employeeIds))
            ->with(['employee.unit', 'intervals', 'leaveRequest'])
            ->get()
            ->groupBy('employee_id');

        $data = [];
        $workingDays = $this->countWorkingDays($start, $end);

        foreach ($employees as $employee) {
            $employeeRecords = $records->get($employee->id, collect());
            $totals = $this->emptyTotals();
            $recap = [
                'hadir' => 0,
                'terlambat' => 0,
                'pulang_awal' => 0,
                'total_finger' => 0,
            ];

            foreach ($employeeRecords as $record) {
                $dailyRecap = $this->recapCalculator->calculate($record);
                $recap['hadir'] += $dailyRecap['hadir'];
                $recap['terlambat'] += $dailyRecap['terlambat'];
                $recap['pulang_awal'] += $dailyRecap['pulang_awal'];
                $recap['total_finger'] += $dailyRecap['total_finger'];
                $totals['izin_keluar'] += $dailyRecap['izin_keluar'];

                $status = $record->status?->value ?? $record->status;

                if ($status === AttendanceStatus::Absent->value) {
                    $totals['alpha']++;
                }

                if ($status === AttendanceStatus::NeedsVerification->value) {
                    $totals['perlu_verifikasi']++;
                }

                if ($status === AttendanceStatus::Permission->value) {
                    $totals['izin']++;
                }

                if ($status === AttendanceStatus::Sick->value) {
                    $totals['sakit']++;
                }

                if ($status === AttendanceStatus::OfficialDuty->value) {
                    $totals['dinas']++;
                }

                $totals['total_exit_minutes'] += (int) $record->intervals
                    ->filter(fn ($interval) => $interval->type === AttendanceIntervalType::TemporaryExit)
                    ->sum('duration_minutes');
            }

            $data[] = [
                'employee_id' => $employee->id,
                'employee' => $employee->displayName(),
                'period_start' => $start->toDateString(),
                'period_end' => $end->toDateString(),
                'hari_kerja' => $workingDays,
                'unit_id' => $employee->unit_id,
                'nip' => $employee->nip,
                'name' => $employee->displayName(),
                'unit' => $employee->unit?->code ?? $employee->unit?->name,
                ...$recap,
                'alpha' => $totals['alpha'],
                'perlu_verifikasi' => $totals['perlu_verifikasi'],
                'izin' => $totals['izin'],
                'sakit' => $totals['sakit'],
                'dinas' => $totals['dinas'],
                'izin_keluar' => $totals['izin_keluar'],
                'total_exit_minutes' => $totals['total_exit_minutes'],
                'total_attendance_days' => $employeeRecords->isNotEmpty() ? 1 : 0,
            ];
        }

        return [
            'summary' => [
                'total_employees' => $employees->count(),
                'start_date' => $start->toDateString(),
                'end_date' => $end->toDateString(),
            ],
            'data' => $data,
        ];
    }

    private function emptyTotals(): array
    {
        return [
            'alpha' => 0,
            'perlu_verifikasi' => 0,
            'izin' => 0,
            'sakit' => 0,
            'dinas' => 0,
            'izin_keluar' => 0,
            'total_exit_minutes' => 0,
        ];
    }

    private function countWorkingDays(CarbonImmutable $start, CarbonImmutable $end): int
    {
        $workingDays = 0;

        for ($date = $start->startOfDay(); $date <= $end->startOfDay(); $date = $date->addDay()) {
            if ($date->dayOfWeekIso < 7) {
                $workingDays++;
            }
        }

        return $workingDays;
    }
}
