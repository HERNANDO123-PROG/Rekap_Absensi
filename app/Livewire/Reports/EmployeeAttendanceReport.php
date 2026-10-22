<?php

namespace App\Livewire\Reports;

use App\Models\Employee;
use App\Services\Reports\AttendancePeriodReportService;
use App\Services\Reports\EmployeeAttendanceDetailService;
use Carbon\CarbonImmutable;
use Livewire\Attributes\Locked;
use Livewire\Component;

class EmployeeAttendanceReport extends Component
{
    #[Locked]
    public int $employeeId;

    #[Locked]
    public string $unitId = '';

    public string $startDate;

    public string $endDate;

    public function mount(int $employeeId): void
    {
        $this->employeeId = $employeeId;
        $this->unitId = (string) request()->query('unit_id', '');
        $this->startDate = request()->query('start_date', now()->startOfMonth()->toDateString());
        $this->endDate = request()->query('end_date', now()->endOfMonth()->toDateString());
    }

    public function applyFilters(): void
    {
        $this->validate([
            'startDate' => ['required', 'date'],
            'endDate' => ['required', 'date', 'after_or_equal:startDate'],
        ]);
    }

    public function render()
    {
        $employee = Employee::query()->with('unit')->findOrFail($this->employeeId);

        abort_unless($this->unitId !== '' && (int) $this->unitId === (int) $employee->unit_id, 403);

        $validRange = $this->hasValidRange();
        $detail = ['employee' => [], 'summary' => [], 'daily' => []];
        $summary = [];

        if ($validRange) {
            $detail = app(EmployeeAttendanceDetailService::class)->generate($employee, $this->startDate, $this->endDate);
            $period = app(AttendancePeriodReportService::class)->generate(
                (int) $employee->unit_id,
                $this->startDate,
                $this->endDate,
                [$employee->id],
            );
            $summary = $period['data'][0] ?? [];
        }

        return view('livewire.reports.employee-attendance-report', [
            'employee' => $employee,
            'detail' => $detail,
            'summary' => $summary,
            'validRange' => $validRange,
        ]);
    }

    private function hasValidRange(): bool
    {
        try {
            return filled($this->startDate)
                && filled($this->endDate)
                && CarbonImmutable::parse($this->endDate)->greaterThanOrEqualTo(CarbonImmutable::parse($this->startDate));
        } catch (\Throwable) {
            return false;
        }
    }
}