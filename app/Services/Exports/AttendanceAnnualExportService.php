<?php

namespace App\Services\Exports;

use App\Models\Employee;
use App\Models\Unit;
use App\Services\Reports\AttendanceAnnualReportService;
use PhpOffice\PhpSpreadsheet\Spreadsheet;

class AttendanceAnnualExportService
{
    public function __construct(private readonly AttendanceAnnualReportService $reportService) {}

    public function buildSpreadsheet(int $unitId, int $year): Spreadsheet
    {
        $unit = Unit::query()->findOrFail($unitId);
        $report = $this->reportService->generate($unitId, $year);
        $employees = Employee::query()
            ->where('unit_id', $unitId)
            ->get(['id', 'namafinger', 'nama_lengkap', 'position'])
            ->keyBy('id');

        $months = ['Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des'];
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Rekap Tahunan');

        ExcelExportHelper::writeValue($sheet, 'A1', 'Unit:');
        ExcelExportHelper::writeValue($sheet, 'B1', $unit->code ?? $unit->name);
        ExcelExportHelper::writeValue($sheet, 'A2', 'Tahun:');
        $sheet->setCellValue('B2', $year);

        ExcelExportHelper::writeRow($sheet, 5, ['No', 'ID Pegawai', 'Nama', 'Jabatan', 'Metric']);
        foreach ($months as $index => $month) {
            ExcelExportHelper::writeValue($sheet, ExcelExportHelper::columnName($index + 6).'5', $month);
        }
        ExcelExportHelper::writeValue($sheet, 'R5', 'Total');

        foreach (range('A', 'R') as $column) {
            $sheet->getStyle($column.'5')->getFont()->setBold(true);
        }

        $metrics = [
            'hari_kerja' => 'Hari Kerja',
            'hadir' => 'Hadir',
            'terlambat' => 'Terlambat',
            'pulang_awal' => 'Pulang Awal',
            'total_finger' => 'Total Finger',
        ];
        $monthNames = [
            'Jan' => 'January',
            'Feb' => 'February',
            'Mar' => 'March',
            'Apr' => 'April',
            'Mei' => 'May',
            'Jun' => 'June',
            'Jul' => 'July',
            'Agu' => 'August',
            'Sep' => 'September',
            'Okt' => 'October',
            'Nov' => 'November',
            'Des' => 'December',
        ];

        $row = 6;
        foreach ($report['data'] ?? [] as $index => $entry) {
            $employee = $employees->get($entry['employee_id']);
            $monthly = $entry['monthly'] ?? [];
            foreach ($metrics as $metric => $label) {
                $values = [
                    $index + 1,
                    $entry['employee_id'],
                    $entry['name'] ?? ($employee?->displayName() ?? ''),
                    $employee?->position ?? '',
                    $label,
                ];

                foreach ($months as $monthLabel) {
                    $monthName = $monthNames[$monthLabel];
                    $values[] = $monthly[$monthName][$metric] ?? 0;
                }

                $values[] = $entry['annual'][$metric] ?? 0;
                ExcelExportHelper::writeRow($sheet, $row, $values);
                $row++;
            }
        }

        $sheet->freezePane('A6');
        $sheet->setAutoFilter('A5:R5');
        foreach (range('A', 'R') as $columnLetter) {
            $sheet->getColumnDimension($columnLetter)->setAutoSize(true);
        }

        return $spreadsheet;
    }

    public function filename(int $unitId, int $year): string
    {
        $unit = Unit::query()->findOrFail($unitId);

        return 'rekap-tahunan-'.preg_replace('/[^A-Za-z0-9]+/', '-', strtolower($unit->code ?? $unit->name ?? 'unit')).'-'.$year.'.xlsx';
    }
}
