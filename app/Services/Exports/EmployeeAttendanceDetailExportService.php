<?php

namespace App\Services\Exports;

use App\Models\Employee;
use App\Services\Reports\EmployeeAttendanceDetailService;
use Carbon\CarbonImmutable;
use PhpOffice\PhpSpreadsheet\Spreadsheet;

class EmployeeAttendanceDetailExportService
{
    public function __construct(private readonly EmployeeAttendanceDetailService $reportService) {}

    public function buildSpreadsheet(Employee $employee, string $startDate, string $endDate): Spreadsheet
    {
        $report = $this->reportService->generate($employee, $startDate, $endDate);
        $spreadsheet = new Spreadsheet;

        $detailSheet = $spreadsheet->getActiveSheet();
        $detailSheet->setTitle('Detail Absensi');
        ExcelExportHelper::writeValue($detailSheet, 'A3', $report['employee']['name'] ?? $employee->displayName());
        ExcelExportHelper::writeValue($detailSheet, 'B3', $employee->id);
        ExcelExportHelper::writeValue($detailSheet, 'A4', 'Jabatan');
        ExcelExportHelper::writeValue($detailSheet, 'B4', $employee->position ?? '');
        ExcelExportHelper::writeValue($detailSheet, 'A5', 'Unit');
        ExcelExportHelper::writeValue($detailSheet, 'B5', $report['employee']['unit'] ?? ($employee->unit?->code ?? $employee->unit?->name ?? ''));
        ExcelExportHelper::writeValue($detailSheet, 'A6', 'Periode');
        ExcelExportHelper::writeValue($detailSheet, 'B6', CarbonImmutable::parse($startDate)->format('d-m-Y').' s/d '.CarbonImmutable::parse($endDate)->format('d-m-Y'));

        $headers = ['Tanggal', 'Hari', 'Status', 'Masuk', 'Keluar', 'Terlambat (menit)', 'Pulang Awal (menit)', 'Alasan', 'Perlu Verifikasi', 'Catatan'];
        $headerRow = 7;
        $column = 'A';
        foreach ($headers as $header) {
            $detailSheet->setCellValue($column.$headerRow, $header);
            $detailSheet->getStyle($column.$headerRow)->getFont()->setBold(true);
            $column++;
        }

        $row = 8;
        foreach ($report['daily'] ?? [] as $entry) {
            $intervals = $entry['intervals'] ?? [];
            $exitDurations = array_values(array_map(fn ($interval) => (int) ($interval['duration_minutes'] ?? 0), $intervals));
            $totalExitMinutes = array_sum($exitDurations);
            ExcelExportHelper::writeRow($detailSheet, $row, [
                $entry['date'] ?? '',
                CarbonImmutable::parse($entry['date'])->translatedFormat('l') ?? '',
                $this->stringify($entry['status'] ?? ''),
                $entry['actual_in'] ?? $entry['scheduled_in'] ?? '',
                $entry['actual_out'] ?? (is_array($intervals) && ! empty($intervals) ? end($intervals)['end_time'] ?? '' : ''),
                $entry['late_minutes'] ?? 0,
                $entry['early_leave_minutes'] ?? 0,
                '',
                '',
                $totalExitMinutes > 0 ? 'Izin keluar: '.$totalExitMinutes.' menit' : '',
            ]);
            $row++;
        }

        $detailSheet->freezePane('A8');
        $detailSheet->setAutoFilter('A7:J7');
        foreach (range('A', 'J') as $columnLetter) {
            $detailSheet->getColumnDimension($columnLetter)->setAutoSize(true);
        }

        $intervalSheet = $spreadsheet->createSheet();
        $intervalSheet->setTitle('Interval');
        $intervalHeaders = ['Tanggal', 'Jenis', 'Mulai', 'Selesai', 'Durasi (menit)', 'Sumber', 'Catatan'];
        $intervalColumn = 'A';
        foreach ($intervalHeaders as $header) {
            $intervalSheet->setCellValue($intervalColumn.'6', $header);
            $intervalSheet->getStyle($intervalColumn.'6')->getFont()->setBold(true);
            $intervalColumn++;
        }

        $intervalRow = 7;
        foreach ($report['daily'] ?? [] as $entry) {
            foreach ($entry['intervals'] ?? [] as $interval) {
                ExcelExportHelper::writeRow($intervalSheet, $intervalRow, [
                    $entry['date'] ?? '',
                    $this->stringify($interval['type'] ?? ''),
                    $interval['start_time'] ?? '',
                    $interval['end_time'] ?? '',
                    $interval['duration_minutes'] ?? 0,
                    $this->stringify($interval['source'] ?? ''),
                    $interval['notes'] ?? '',
                ]);
                $intervalRow++;
            }
        }

        $anomalySheet = $spreadsheet->createSheet();
        $anomalySheet->setTitle('Anomali');
        $anomalyHeaders = ['Tanggal', 'Tipe', 'Severity', 'Pesan', 'Resolved'];
        $anomalyColumn = 'A';
        foreach ($anomalyHeaders as $header) {
            $anomalySheet->setCellValue($anomalyColumn.'6', $header);
            $anomalySheet->getStyle($anomalyColumn.'6')->getFont()->setBold(true);
            $anomalyColumn++;
        }

        $anomalyRow = 7;
        foreach ($report['daily'] ?? [] as $entry) {
            foreach ($entry['anomalies'] ?? [] as $anomaly) {
                ExcelExportHelper::writeRow($anomalySheet, $anomalyRow, [
                    $entry['date'] ?? '',
                    $this->stringify($anomaly['type'] ?? ''),
                    $this->stringify($anomaly['severity'] ?? ''),
                    $anomaly['message'] ?? '',
                    $anomaly['is_resolved'] ? 'Ya' : 'Tidak',
                ]);
                $anomalyRow++;
            }
        }

        $spreadsheet->setActiveSheetIndex(0);

        return $spreadsheet;
    }

    private function stringify(mixed $value): string
    {
        if ($value instanceof \BackedEnum) {
            return $value->value;
        }

        return (string) $value;
    }

    public function filename(Employee $employee, string $startDate, string $endDate): string
    {
        $safeName = preg_replace('/[^A-Za-z0-9]+/', '-', strtolower(trim($employee->displayName())));
        $safeName = trim($safeName, '-');

        return 'detail-pegawai-'.($safeName !== '' ? $safeName : 'pegawai').'-'.$startDate.'-'.$endDate.'.xlsx';
    }
}
