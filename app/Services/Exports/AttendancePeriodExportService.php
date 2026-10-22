<?php

namespace App\Services\Exports;

use App\Models\Employee;
use App\Models\Unit;
use App\Services\Reports\AttendancePeriodReportService;
use Carbon\CarbonImmutable;
use PhpOffice\PhpSpreadsheet\Spreadsheet;

class AttendancePeriodExportService
{
    public function __construct(private readonly AttendancePeriodReportService $reportService) {}

    public function buildSpreadsheet(int $unitId, string $startDate, string $endDate): Spreadsheet
    {
        $unit = Unit::query()->findOrFail($unitId);
        $report = $this->reportService->generate($unitId, $startDate, $endDate);
        $employees = Employee::query()
            ->where('unit_id', $unitId)
            ->get(['id', 'namafinger', 'nama_lengkap', 'position'])
            ->keyBy('id');

        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Rekap Periode');

        $sheet->setCellValue('A1', 'Unit:');
        $sheet->setCellValue('B1', $unit->code ?? $unit->name);
        $sheet->setCellValue('A2', 'Periode:');
        $sheet->setCellValue('B2', CarbonImmutable::parse($startDate)->format('d-m-Y').' s/d '.CarbonImmutable::parse($endDate)->format('d-m-Y'));

        $headers = ['No', 'ID Pegawai', 'Nama', 'Jabatan', 'Hari Kerja', 'Hadir', 'Terlambat', 'Pulang Awal', 'Total Finger', 'Izin', 'Sakit', 'Dinas', 'Izin Keluar', 'Alpha', 'Perlu Verifikasi'];
        $headerRow = 5;
        $column = 'A';
        foreach ($headers as $header) {
            $sheet->setCellValue($column.$headerRow, $header);
            $sheet->getStyle($column.$headerRow)->getFont()->setBold(true);
            $column++;
        }

        $row = 6;
        foreach ($report['data'] ?? [] as $index => $entry) {
            $employee = $employees->get($entry['employee_id']);
            $data = [
                $index + 1,
                $entry['employee_id'],
                $entry['name'] ?? ($employee?->displayName() ?? ''),
                $employee?->position ?? '',
                $entry['hari_kerja'] ?? 0,
                $entry['hadir'] ?? 0,
                $entry['terlambat'] ?? 0,
                $entry['pulang_awal'] ?? 0,
                $entry['total_finger'] ?? 0,
                $entry['izin'] ?? 0,
                $entry['sakit'] ?? 0,
                $entry['dinas'] ?? 0,
                $entry['izin_keluar'] ?? 0,
                $entry['alpha'] ?? 0,
                $entry['perlu_verifikasi'] ?? 0,
            ];

            $sheet->fromArray($data, null, 'A'.$row, true);
            $row++;
        }

        $lastColumn = 'O';
        $sheet->setAutoFilter('A5:'.$lastColumn.'5');
        $sheet->freezePane('A6');
        foreach (range('A', $lastColumn) as $columnLetter) {
            $sheet->getColumnDimension($columnLetter)->setAutoSize(true);
        }

        return $spreadsheet;
    }

    public function filename(int $unitId, string $startDate, string $endDate): string
    {
        $unit = Unit::query()->findOrFail($unitId);

        return 'rekap-periode-'.preg_replace('/[^A-Za-z0-9]+/', '-', strtolower($unit->code ?? $unit->name ?? 'unit')).'-'.$startDate.'-'.$endDate.'.xlsx';
    }
}
