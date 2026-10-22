<?php

namespace App\Http\Controllers;

use App\Data\Reports\AttendanceFingerprintPeriodReportFilters;
use App\Http\Requests\AttendanceFingerprintPeriodReportRequest;
use App\Models\Employee;
use App\Services\Reports\AttendanceFingerprintPeriodReportService;
use Illuminate\Contracts\View\View;

class FingerprintPeriodReportController extends Controller
{
    public function show(
        AttendanceFingerprintPeriodReportRequest $request,
        Employee $employee,
        AttendanceFingerprintPeriodReportService $reportService,
    ): View {
        $validated = $request->validated();
        abort_unless((int) $validated['unit_id'] === (int) $employee->unit_id, 404);

        $filters = AttendanceFingerprintPeriodReportFilters::fromValidated($validated)
            ->forEmployee($employee->id);
        $report = $reportService->generate($filters);
        $row = $report['data'][0] ?? null;
        abort_if($row === null, 404);

        return view('livewire.reports.fingerprint-period-detail', [
            'employee' => $employee->loadMissing('unit'),
            'row' => $row,
            'filters' => [
                'unit_id' => $filters->unitId,
                'start_date' => $filters->startDate,
                'end_date' => $filters->endDate,
                'category_id' => $filters->categoryId,
                'search' => $filters->search,
            ],
        ]);
    }
}
