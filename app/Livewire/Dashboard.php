<?php

namespace App\Livewire;

use App\Data\Reports\AttendanceFingerprintPeriodReportFilters;
use App\Enums\AttendanceStatus;
use App\Enums\EmployeeStatus;
use App\Models\AttendanceRecord;
use App\Models\Employee;
use App\Models\Unit;
use App\Services\Reports\AttendanceFingerprintPeriodReportService;
use Livewire\Component;

class Dashboard extends Component
{
    public function render()
    {
        $selectedUnitId = request()->query('unit_id', 'all');
        $selectedDate = request()->query('date', now()->toDateString());

        $units = Unit::query()->orderBy('name')->get();
        $attendanceRecords = AttendanceRecord::query()
            ->whereDate('attendance_date', $selectedDate)
            ->when(
                $selectedUnitId !== 'all' && ! empty($selectedUnitId),
                fn ($query) => $query->where('unit_id', $selectedUnitId),
            )
            ->get();

        $totalEmployees = Employee::query()
            ->when($selectedUnitId !== 'all' && ! empty($selectedUnitId), fn ($q) => $q->where('unit_id', $selectedUnitId))
            ->where('status', EmployeeStatus::Active->value)
            ->count();

        $stats = [
            'totalEmployees' => $totalEmployees,
            'hadir' => 0,
            'terlambat' => 0,
            'izin' => 0,
            'sakit' => 0,
            'dinas' => 0,
            'alpha' => 0,
        ];

        foreach ($attendanceRecords as $record) {
            match ($record->status) {
                AttendanceStatus::Permission => $stats['izin']++,
                AttendanceStatus::Sick => $stats['sakit']++,
                AttendanceStatus::OfficialDuty => $stats['dinas']++,
                AttendanceStatus::Absent => $stats['alpha']++,
                default => null,
            };
        }

        $unitIds = $selectedUnitId === 'all' || empty($selectedUnitId)
            ? $units->modelKeys()
            : [(int) $selectedUnitId];
        $periodReport = app(AttendanceFingerprintPeriodReportService::class);

        foreach ($unitIds as $unitId) {
            $employeeCount = Employee::query()->where('unit_id', $unitId)->count();
            if ($employeeCount === 0) {
                continue;
            }

            $report = $periodReport->generate(AttendanceFingerprintPeriodReportFilters::fromValidated([
                'unit_id' => $unitId,
                'start_date' => $selectedDate,
                'end_date' => $selectedDate,
                'page' => 1,
                'per_page' => $employeeCount,
            ]));

            foreach ($report['data'] as $employee) {
                $metrics = $employee['daily'][0]['metrics'];
                $stats['hadir'] += (int) ($metrics['hadir'] ?? 0);
                $stats['terlambat'] += (int) ($metrics['terlambat'] ?? 0);
            }
        }

        $selectedUnitName = 'Semua Unit';
        if ($selectedUnitId !== 'all' && ! empty($selectedUnitId)) {
            $selectedUnit = $units->firstWhere('id', $selectedUnitId);
            $selectedUnitName = $selectedUnit?->name ?? 'Unit';
        }

        return view('livewire.dashboard', [
            'stats' => $stats,
            'units' => $units,
            'selectedUnitId' => $selectedUnitId,
            'selectedDate' => $selectedDate,
            'selectedUnitName' => $selectedUnitName,
        ]);
    }
}
