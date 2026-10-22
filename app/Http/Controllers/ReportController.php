<?php

namespace App\Http\Controllers;

use App\Models\Employee;
use App\Services\Reports\AttendanceAnnualReportService;
use App\Services\Reports\AttendancePeriodReportService;
use App\Services\Reports\EmployeeAttendanceDetailService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ReportController extends Controller
{
    public function __construct(
        private readonly AttendancePeriodReportService $periodReport,
        private readonly AttendanceAnnualReportService $annualReport,
        private readonly EmployeeAttendanceDetailService $employeeAttendanceDetail,
    ) {}

    public function period(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'unit_id' => ['required', 'integer', 'exists:units,id'],
            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date', 'after_or_equal:start_date'],
        ]);

        return response()->json($this->periodReport->generate((int) $validated['unit_id'], $validated['start_date'], $validated['end_date']));
    }

    public function annual(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'unit_id' => ['required', 'integer', 'exists:units,id'],
            'year' => ['required', 'integer', 'min:2000', 'max:2100'],
        ]);

        return response()->json($this->annualReport->generate((int) $validated['unit_id'], (int) $validated['year']));
    }

    public function employeeAttendance(Request $request, Employee $employee): JsonResponse
    {
        $validated = $request->validate([
            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date', 'after_or_equal:start_date'],
        ]);

        return response()->json($this->employeeAttendanceDetail->generate($employee, $validated['start_date'], $validated['end_date']));
    }
}
