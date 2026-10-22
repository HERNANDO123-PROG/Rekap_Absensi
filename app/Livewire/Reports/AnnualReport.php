<?php

namespace App\Livewire\Reports;

use App\Models\Employee;
use App\Models\Unit;
use App\Services\Reports\AttendanceAnnualReportService;
use Livewire\Component;
use Livewire\WithPagination;

class AnnualReport extends Component
{
    use WithPagination;

    public string $unitId = 'all';

    public int $year;

    public string $search = '';

    public function mount(): void
    {
        $this->year = (int) now()->year;
    }

    public function updatedUnitId(): void
    {
        $this->resetPage();
    }

    public function updatedYear(): void
    {
        $this->resetPage();
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function applyFilters(): void
    {
        $this->validate([
            'unitId' => ['required', 'string'],
            'year' => ['required', 'integer', 'min:2000', 'max:2100'],
        ]);

        $this->resetPage();
    }

    public function render()
    {
        $units = Unit::query()->orderBy('name')->get();
        $employeeQuery = Employee::query()
            ->with('unit')
            ->when($this->unitId !== 'all', fn ($query) => $query->where('unit_id', $this->unitId))
            ->when(trim($this->search) !== '', function ($query) {
                $term = '%'.trim($this->search).'%';
                $query->where(function ($query) use ($term) {
                    $query->where('namafinger', 'like', $term)
                        ->orWhere('nama_lengkap', 'like', $term)
                        ->orWhere('nip', 'like', $term)
                        ->orWhere('fingerprint_id', 'like', $term);
                });
            })
            ->orderByDisplayName();

        $employees = $employeeQuery->paginate(20, ['*'], 'page');
        $reports = [];

        if ($this->year >= 2000 && $this->year <= 2100) {
            foreach ($employees->getCollection()->groupBy('unit_id') as $unitId => $unitEmployees) {
                $result = app(AttendanceAnnualReportService::class)->generate(
                    (int) $unitId,
                    $this->year,
                    $unitEmployees->pluck('id')->all(),
                );

                foreach ($result['data'] as $row) {
                    $reports[$row['employee_id']] = $row;
                }
            }
        }

        $employees->setCollection($employees->getCollection()->map(function (Employee $employee) use ($reports) {
            return $reports[$employee->id] ?? [
                'employee_id' => $employee->id,
                'unit_id' => $employee->unit_id,
                'nip' => $employee->nip,
                'name' => $employee->displayName(),
                'unit' => $employee->unit?->code ?? $employee->unit?->name,
                'annual' => [],
                'monthly' => [],
            ];
        }));

        return view('livewire.reports.annual-report', [
            'units' => $units,
            'employees' => $employees,
            'monthKeys' => [
                'January' => 'Jan', 'February' => 'Feb', 'March' => 'Mar', 'April' => 'Apr',
                'May' => 'Mei', 'June' => 'Jun', 'July' => 'Jul', 'August' => 'Agu',
                'September' => 'Sep', 'October' => 'Okt', 'November' => 'Nov', 'December' => 'Des',
            ],
        ]);
    }
}