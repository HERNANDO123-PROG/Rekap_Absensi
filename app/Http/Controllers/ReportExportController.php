<?php

namespace App\Http\Controllers;

use App\Data\Reports\AttendanceFingerprintPeriodReportFilters;
use App\Http\Requests\AttendanceFingerprintPeriodReportRequest;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Services\Exports\AttendanceAnnualExportService;
use App\Services\Exports\AttendanceFingerprintPeriodExportService;
use App\Services\Exports\AttendancePeriodExportService;
use App\Services\Exports\EmployeeAttendanceDetailExportService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class ReportExportController extends Controller
{
    public function __construct(
        private readonly AttendancePeriodExportService $periodExport,
        private readonly AttendanceAnnualExportService $annualExport,
        private readonly EmployeeAttendanceDetailExportService $employeeExport,
        private readonly AttendanceFingerprintPeriodExportService $fingerprintPeriodExport,
    ) {}

    public function period(Request $request): BinaryFileResponse
    {
        $validated = $request->validate([
            'unit_id' => ['required', 'integer', 'exists:units,id'],
            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date', 'after_or_equal:start_date'],
        ]);

        $this->audit('report_export.period', [
            'unit_id' => $validated['unit_id'],
            'date_from' => $validated['start_date'],
            'date_to' => $validated['end_date'],
        ]);

        $spreadsheet = $this->periodExport->buildSpreadsheet((int) $validated['unit_id'], $validated['start_date'], $validated['end_date']);
        $path = tempnam(sys_get_temp_dir(), 'period-export-');
        IOFactory::createWriter($spreadsheet, 'Xlsx')->save($path);

        return response()->download($path, $this->periodExport->filename((int) $validated['unit_id'], $validated['start_date'], $validated['end_date']), [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    public function fingerprintPeriod(AttendanceFingerprintPeriodReportRequest $request): BinaryFileResponse
    {
        $validated = $request->validated();
        unset($validated['employee_id']);
        $filters = AttendanceFingerprintPeriodReportFilters::fromValidated($validated);
        $this->audit('report_export.fingerprint_period', [
            ...$filters->filters(),
            'date_from' => $filters->startDate,
            'date_to' => $filters->endDate,
        ]);

        $spreadsheet = $this->fingerprintPeriodExport->buildSpreadsheet($filters);
        $path = tempnam(sys_get_temp_dir(), 'fingerprint-period-export-');
        IOFactory::createWriter($spreadsheet, 'Xlsx')->save($path);

        return response()->download($path, $this->fingerprintPeriodExport->filename($filters), [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    public function annual(Request $request): BinaryFileResponse
    {
        $validated = $request->validate([
            'unit_id' => ['required', 'integer', 'exists:units,id'],
            'year' => ['required', 'integer', 'min:2000', 'max:2100'],
        ]);

        $this->audit('report_export.annual', [
            'unit_id' => $validated['unit_id'],
            'year' => $validated['year'],
        ]);

        $spreadsheet = $this->annualExport->buildSpreadsheet((int) $validated['unit_id'], (int) $validated['year']);
        $path = tempnam(sys_get_temp_dir(), 'annual-export-');
        IOFactory::createWriter($spreadsheet, 'Xlsx')->save($path);

        return response()->download($path, $this->annualExport->filename((int) $validated['unit_id'], (int) $validated['year']), [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    public function employee(Request $request, Employee $employee): BinaryFileResponse
    {
        if (! $request->filled('unit_id')) {
            abort(403, 'Employee export requires an explicit unit_id.');
        }

        $validated = $request->validate([
            'unit_id' => ['required', 'integer', 'exists:units,id'],
            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date', 'after_or_equal:start_date'],
        ]);

        if ((int) $validated['unit_id'] !== (int) $employee->unit_id) {
            abort(403, 'Employee does not belong to the selected unit.');
        }

        $this->audit('report_export.employee', [
            'employee_id' => $employee->id,
            'unit_id' => $validated['unit_id'],
            'date_from' => $validated['start_date'],
            'date_to' => $validated['end_date'],
        ]);

        $spreadsheet = $this->employeeExport->buildSpreadsheet($employee, $validated['start_date'], $validated['end_date']);
        $path = tempnam(sys_get_temp_dir(), 'employee-export-');
        IOFactory::createWriter($spreadsheet, 'Xlsx')->save($path);

        return response()->download($path, $this->employeeExport->filename($employee, $validated['start_date'], $validated['end_date']), [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    private function audit(string $action, array $details): void
    {
        AuditLog::create([
            'user_id' => Auth::id(),
            'action' => $action,
            'module' => 'reports',
            'record_type' => 'export',
            'description' => json_encode($details, JSON_THROW_ON_ERROR),
        ]);
    }
}
