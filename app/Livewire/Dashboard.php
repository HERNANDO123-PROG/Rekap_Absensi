<?php

namespace App\Livewire;

use App\Enums\AttendanceStatus;
use App\Enums\EmployeeStatus;
use App\Models\AttendanceRecord;
use App\Models\Employee;
use App\Models\Unit;
use Livewire\Component;

class Dashboard extends Component
{
    public function render()
    {
        $selectedUnitId = request()->query('unit_id', 'all');
        $selectedDate = request()->query('date', now()->toDateString());

        $query = AttendanceRecord::query()->whereDate('attendance_date', $selectedDate);

        if ($selectedUnitId !== 'all' && ! empty($selectedUnitId)) {
            $query->where('unit_id', $selectedUnitId);
        }

        $units = Unit::query()->orderBy('name')->get();

        $totalEmployees = Employee::query()
            ->when($selectedUnitId !== 'all' && ! empty($selectedUnitId), fn ($q) => $q->where('unit_id', $selectedUnitId))
            ->where('status', EmployeeStatus::Active->value)
            ->count();

        $stats = [
            'totalEmployees' => $totalEmployees,
            'hadir' => (clone $query)->where('status', AttendanceStatus::Present->value)->count(),
            'terlambat' => (clone $query)->where('status', AttendanceStatus::Late->value)->count(),
            'izin' => (clone $query)->where('status', AttendanceStatus::Permission->value)->count(),
            'sakit' => (clone $query)->where('status', AttendanceStatus::Sick->value)->count(),
            'dinas' => (clone $query)->where('status', AttendanceStatus::OfficialDuty->value)->count(),
            'alpha' => (clone $query)->where('status', AttendanceStatus::Absent->value)->count(),
            'perlu_verifikasi' => (clone $query)->where('status', AttendanceStatus::NeedsVerification->value)->count(),
        ];

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
