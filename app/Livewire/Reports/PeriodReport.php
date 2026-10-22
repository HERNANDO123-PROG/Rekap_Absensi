<?php

namespace App\Livewire\Reports;

use App\Data\Reports\AttendanceFingerprintPeriodReportFilters;
use App\Models\Unit;
use App\Models\WorkScheduleCategory;
use App\Services\Reports\AttendanceFingerprintPeriodReportService;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Livewire\Component;

class PeriodReport extends Component
{
    public string $unitId = '';

    public string $startDate;

    public string $endDate;

    public string $categoryId = '';

    public string $search = '';

    public int $page = 1;

    public function mount(): void
    {
        $this->unitId = (string) request()->query('unit_id', '');
        $this->startDate = (string) request()->query('start_date', now()->startOfMonth()->toDateString());
        $this->endDate = (string) request()->query('end_date', now()->endOfMonth()->toDateString());
        $this->categoryId = (string) request()->query('category_id', '');
        $this->search = (string) request()->query('search', '');
        $this->page = max(1, (int) request()->query('page', 1));
    }

    public function applyFilters(): void
    {
        $this->validate([
            'unitId' => ['required', 'integer', 'exists:units,id'],
            'startDate' => ['required', 'date'],
            'endDate' => ['required', 'date', 'after_or_equal:startDate'],
            'categoryId' => $this->categoryId === ''
                ? ['nullable']
                : ['nullable', 'integer', 'exists:work_schedule_categories,id'],
            'search' => ['nullable', 'string'],
        ]);
        $this->page = 1;
    }

    public function updatedSearch(): void
    {
        $this->page = 1;
    }

    public function previousPage(): void
    {
        $this->page = max(1, $this->page - 1);
    }

    public function nextPage(int $lastPage): void
    {
        $this->page = min($lastPage, $this->page + 1);
    }

    public function render(): View
    {
        $units = Unit::query()->orderBy('name')->get();
        $categories = WorkScheduleCategory::query()->orderBy('name')->get();
        $report = null;

        if ($this->filtersAreValid()) {
            $report = app(AttendanceFingerprintPeriodReportService::class)->generate(
                AttendanceFingerprintPeriodReportFilters::fromValidated([
                    'unit_id' => $this->unitId,
                    'start_date' => $this->startDate,
                    'end_date' => $this->endDate,
                    'category_id' => $this->categoryId,
                    'search' => $this->search,
                    'page' => $this->page,
                    'per_page' => 20,
                ]),
            );
        }

        return view('livewire.reports.period-report', [
            'units' => $units,
            'categories' => $categories,
            'report' => $report,
            'filterValues' => [
                'unit_id' => $this->unitId,
                'start_date' => $this->startDate,
                'end_date' => $this->endDate,
                'category_id' => $this->categoryId,
                'search' => $this->search,
            ],
        ]);
    }

    private function rules(): array
    {
        return app(AttendanceFingerprintPeriodReportRequest::class)->rules();
    }

    private function filtersAreValid(): bool
    {
        return validator([
            'unit_id' => $this->unitId,
            'start_date' => $this->startDate,
            'end_date' => $this->endDate,
            'category_id' => $this->categoryId === '' ? null : $this->categoryId,
            'search' => $this->search,
            'page' => $this->page,
            'per_page' => 20,
        ], [
            'unit_id' => ['required', 'integer', 'exists:units,id'],
            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date', 'after_or_equal:start_date'],
            'category_id' => ['nullable', 'integer', 'exists:work_schedule_categories,id'],
            'search' => ['nullable', 'string'],
            'page' => ['required', 'integer', 'min:1'],
            'per_page' => ['required', 'integer', 'min:1', 'max:100'],
        ])->passes()
            && CarbonImmutable::parse($this->endDate)->greaterThanOrEqualTo(CarbonImmutable::parse($this->startDate));
    }
}
