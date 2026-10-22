<?php

namespace App\Data\Reports;

use Carbon\CarbonImmutable;

final readonly class AttendanceFingerprintPeriodReportFilters
{
    public int $page;

    public int $perPage;

    public string $startDate;

    public string $endDate;

    public ?string $search;

    public ?int $categoryId;

    public int $unitId;

    public ?int $employeeId;

    private function __construct(array $validated)
    {
        $this->unitId = (int) $validated['unit_id'];
        $employeeId = $validated['employee_id'] ?? null;
        $this->employeeId = $employeeId === null || $employeeId === '' ? null : (int) $employeeId;
        $this->startDate = CarbonImmutable::parse($validated['start_date'])->toDateString();
        $this->endDate = CarbonImmutable::parse($validated['end_date'])->toDateString();
        $categoryId = $validated['category_id'] ?? null;
        $this->categoryId = $categoryId === null || $categoryId === '' ? null : (int) $categoryId;
        $search = trim((string) ($validated['search'] ?? ''));
        $this->search = $search === '' ? null : $search;
        $this->page = (int) ($validated['page'] ?? 1);
        $this->perPage = (int) ($validated['per_page'] ?? 20);
    }

    public static function fromValidated(array $validated): self
    {
        return new self($validated);
    }

    public function filters(): array
    {
        return [
            'unit_id' => $this->unitId,
            'start_date' => $this->startDate,
            'end_date' => $this->endDate,
            'category_id' => $this->categoryId,
            'search' => $this->search ?? '',
        ];
    }

    public function forEmployee(int $employeeId, int $perPage = 1): self
    {
        return new self([
            ...$this->filters(),
            'employee_id' => $employeeId,
            'page' => 1,
            'per_page' => $perPage,
        ]);
    }

    public function forPage(int $page, int $perPage): self
    {
        return new self([
            ...$this->filters(),
            'employee_id' => $this->employeeId,
            'page' => $page,
            'per_page' => $perPage,
        ]);
    }
}
