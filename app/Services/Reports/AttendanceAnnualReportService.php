<?php

namespace App\Services\Reports;

use App\Enums\AttendanceStatus;
use App\Models\AttendanceRecord;
use App\Models\Employee;
use Carbon\CarbonImmutable;

class AttendanceAnnualReportService
{
    private const MONTHS = [
        'January' => 1,
        'February' => 2,
        'March' => 3,
        'April' => 4,
        'May' => 5,
        'June' => 6,
        'July' => 7,
        'August' => 8,
        'September' => 9,
        'October' => 10,
        'November' => 11,
        'December' => 12,
    ];

    public function __construct(private readonly AttendanceRecapCalculator $recapCalculator) {}

    public function generate(int $unitId, int $year, ?array $employeeIds = null): array
    {
        $employees = Employee::query()
            ->where('unit_id', $unitId)
            ->when($employeeIds !== null, fn ($query) => $query->whereIn('id', $employeeIds))
            ->with('unit')
            ->orderByDisplayName()
            ->get();

        $records = AttendanceRecord::query()
            ->where('unit_id', $unitId)
            ->whereYear('attendance_date', $year)
            ->when($employeeIds !== null, fn ($query) => $query->whereIn('employee_id', $employeeIds))
            ->with(['intervals', 'leaveRequest'])
            ->get()
            ->groupBy('employee_id');

        $data = [];

        foreach ($employees as $employee) {
            $employeeRecords = $records->get($employee->id, collect());
            $annual = $this->emptyTotals();
            $monthly = [];

            foreach (self::MONTHS as $month => $monthNumber) {
                $monthly[$month] = $this->emptyTotals();
                $monthly[$month]['hari_kerja'] = $this->countWorkingDays($year, $monthNumber);
            }

            foreach ($employeeRecords as $record) {
                $status = $record->status?->value ?? $record->status;
                $monthName = CarbonImmutable::parse($record->attendance_date)->format('F');
                $dailyRecap = $this->recapCalculator->calculate($record);

                $this->increment($annual, $status);
                $this->increment($monthly[$monthName], $status);

                foreach (['hadir', 'terlambat', 'pulang_awal', 'total_finger', 'izin_keluar'] as $metric) {
                    $monthly[$monthName][$metric] += $dailyRecap[$metric];
                }
            }

            foreach ($monthly as $monthlyTotals) {
                foreach (['hari_kerja', 'hadir', 'terlambat', 'pulang_awal', 'total_finger', 'izin_keluar'] as $metric) {
                    $annual[$metric] += $monthlyTotals[$metric];
                }
            }

            $data[] = [
                'employee_id' => $employee->id,
                'unit_id' => $employee->unit_id,
                'nip' => $employee->nip,
                'name' => $employee->displayName(),
                'unit' => $employee->unit?->code ?? $employee->unit?->name,
                'annual' => $annual,
                'monthly' => $monthly,
            ];
        }

        return ['data' => $data];
    }

    private function increment(array &$totals, mixed $status): void
    {
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

    }

    private function emptyTotals(): array
    {
        return [
            'hari_kerja' => 0,
            'hadir' => 0,
            'terlambat' => 0,
            'pulang_awal' => 0,
            'total_finger' => 0,
            'alpha' => 0,
            'perlu_verifikasi' => 0,
            'izin' => 0,
            'sakit' => 0,
            'dinas' => 0,
            'izin_keluar' => 0,
        ];
    }

    private function countWorkingDays(int $year, int $month): int
    {
        $date = CarbonImmutable::create($year, $month, 1);
        $end = $date->endOfMonth();
        $workingDays = 0;

        for (; $date <= $end; $date = $date->addDay()) {
            if ($date->dayOfWeekIso < 7) {
                $workingDays++;
            }
        }

        return $workingDays;
    }
}
