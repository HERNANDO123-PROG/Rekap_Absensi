<?php

namespace App\Services\Exports;

use App\Models\Employee;
use App\Models\Unit;
use App\Services\Reports\AttendanceAnnualReportService;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

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

        $months = [
            'January' => 'Januari',
            'February' => 'Februari',
            'March' => 'Maret',
            'April' => 'April',
            'May' => 'Mei',
            'June' => 'Juni',
            'July' => 'Juli',
            'August' => 'Agustus',
            'September' => 'September',
            'October' => 'Oktober',
            'November' => 'November',
            'December' => 'Desember',
        ];
        $metrics = [
            'hari_kerja' => 'Hari Kerja',
            'hadir' => 'Hadir',
            'terlambat' => 'Terlambat',
            'pulang_awal' => 'Pulang Awal',
            'total_finger' => 'Total Finger',
            'alpha' => 'Tidak Hadir',
            'izin' => 'Izin',
            'sakit' => 'Sakit',
            'dinas' => 'Dinas',
            'izin_keluar' => 'Izin Keluar',
        ];
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Rekap Tahunan');
        $sheet->mergeCells('A1:O1');
        ExcelExportHelper::writeValue($sheet, 'A1', 'RINGKASAN REKAP ABSENSI TAHUNAN');
        ExcelExportHelper::writeRow($sheet, 2, [
            'Unit:', $unit->code ?? $unit->name, 'Tahun:', $year,
        ]);
        ExcelExportHelper::writeValue($sheet, 'A3', 'Jumlah Pegawai:');
        ExcelExportHelper::writeValue($sheet, 'C3', count($report['data'] ?? []));
        ExcelExportHelper::writeValue($sheet, 'D3', 'Keterangan:');
        ExcelExportHelper::writeValue($sheet, 'E3', 'Nilai kosong berarti belum dapat ditentukan.');
        $sheet->mergeCells('A3:B3');
        $sheet->mergeCells('E3:O3');
        ExcelExportHelper::writeValue($sheet, 'A5', 'IDENTITAS PEGAWAI');
        ExcelExportHelper::writeValue($sheet, 'F5', 'KEHADIRAN');
        ExcelExportHelper::writeValue($sheet, 'K5', 'KETIDAKHADIRAN');
        $sheet->mergeCells('A5:E5');
        $sheet->mergeCells('F5:J5');
        $sheet->mergeCells('K5:O5');
        $summaryHeaders = [
            'No', 'ID Pegawai', 'Nama Pegawai', 'NIP', 'Jabatan',
            ...array_values($metrics),
        ];
        ExcelExportHelper::writeHeaderRow($sheet, $summaryHeaders, 6);

        $detailSheet = $spreadsheet->createSheet();
        $detailSheet->setTitle('Rincian Bulanan');
        $detailSheet->mergeCells('A1:P1');
        ExcelExportHelper::writeValue($detailSheet, 'A1', 'RINCIAN ABSENSI BULANAN');
        ExcelExportHelper::writeRow($detailSheet, 2, [
            'Unit:', $unit->code ?? $unit->name, 'Tahun:', $year,
        ]);
        ExcelExportHelper::writeValue($detailSheet, 'A3', 'Jumlah Pegawai:');
        ExcelExportHelper::writeValue($detailSheet, 'C3', count($report['data'] ?? []));
        ExcelExportHelper::writeValue($detailSheet, 'D3', 'Keterangan:');
        ExcelExportHelper::writeValue($detailSheet, 'E3', 'Satu baris mewakili satu pegawai pada satu bulan.');
        $detailSheet->mergeCells('A3:B3');
        $detailSheet->mergeCells('E3:P3');
        ExcelExportHelper::writeValue($detailSheet, 'A5', 'IDENTITAS PEGAWAI');
        ExcelExportHelper::writeValue($detailSheet, 'F5', 'PERIODE');
        ExcelExportHelper::writeValue($detailSheet, 'G5', 'KEHADIRAN');
        ExcelExportHelper::writeValue($detailSheet, 'L5', 'KETIDAKHADIRAN');
        $detailSheet->mergeCells('A5:E5');
        $detailSheet->mergeCells('G5:K5');
        $detailSheet->mergeCells('L5:P5');
        ExcelExportHelper::writeHeaderRow($detailSheet, [
            'No', 'ID Pegawai', 'Nama Pegawai', 'NIP', 'Jabatan', 'Bulan',
            ...array_values($metrics),
        ], 6);

        $detailRow = 7;
        $detailIndex = 1;
        foreach ($report['data'] ?? [] as $index => $entry) {
            $employee = $employees->get($entry['employee_id']);
            $values = [
                $index + 1,
                $entry['employee_id'],
                $entry['name'] ?? ($employee?->displayName() ?? ''),
                $this->nipValue($entry['nip'] ?? null),
                $employee?->position ?? '',
            ];
            foreach (array_keys($metrics) as $metric) {
                $values[] = $this->metricValue($entry['annual'] ?? [], $metric);
            }
            ExcelExportHelper::writeRow($sheet, $index + 7, $values);

            foreach ($months as $monthName => $monthLabel) {
                $monthlyValues = [
                    $detailIndex++,
                    $entry['employee_id'],
                    $entry['name'] ?? ($employee?->displayName() ?? ''),
                    $this->nipValue($entry['nip'] ?? null),
                    $employee?->position ?? '',
                    $monthLabel,
                ];
                foreach (array_keys($metrics) as $metric) {
                    $monthlyValues[] = $this->metricValue($entry['monthly'][$monthName] ?? [], $metric);
                }
                ExcelExportHelper::writeRow($detailSheet, $detailRow++, $monthlyValues);
            }
        }

        $summaryDataEnd = 6 + count($report['data'] ?? []);
        $monthlyDataEnd = $detailRow - 1;
        $this->styleSheet(
            $sheet,
            'A1:O1',
            'A6:O6',
            'O',
            $summaryDataEnd,
            'D7',
            ['A5:E5' => '0F4C5C', 'F5:J5' => '0F766E', 'K5:O5' => '115E59'],
            'F7:O'.max(7, $summaryDataEnd),
            'D7:D'.max(7, $summaryDataEnd),
            'H7:H'.max(7, $summaryDataEnd),
            'I7:I'.max(7, $summaryDataEnd),
            [
                'K7:K'.max(7, $summaryDataEnd),
                'L7:L'.max(7, $summaryDataEnd),
                'M7:M'.max(7, $summaryDataEnd),
            ],
            [
                'A' => 6, 'B' => 16, 'C' => 28, 'D' => 16, 'E' => 22,
                'F' => 16, 'G' => 12, 'H' => 16, 'I' => 18, 'J' => 18,
                'K' => 17, 'L' => 10, 'M' => 10, 'N' => 10, 'O' => 16,
            ],
        );
        $this->styleSheet(
            $detailSheet,
            'A1:P1',
            'A6:P6',
            'P',
            $monthlyDataEnd,
            'D7',
            ['A5:E5' => '0F4C5C', 'F5:F5' => '155E75', 'G5:K5' => '0F766E', 'L5:P5' => '115E59'],
            'G7:P'.max(7, $monthlyDataEnd),
            'D7:D'.max(7, $monthlyDataEnd),
            'I7:I'.max(7, $monthlyDataEnd),
            'J7:J'.max(7, $monthlyDataEnd),
            [
                'L7:L'.max(7, $monthlyDataEnd),
                'M7:M'.max(7, $monthlyDataEnd),
                'N7:N'.max(7, $monthlyDataEnd),
            ],
            [
                'A' => 6, 'B' => 16, 'C' => 28, 'D' => 16, 'E' => 22, 'F' => 14,
                'G' => 16, 'H' => 12, 'I' => 16, 'J' => 18, 'K' => 18, 'L' => 17,
                'M' => 10, 'N' => 10, 'O' => 10, 'P' => 16,
            ],
        );
        $detailSheet->getStyle('F7:F'.max(7, $monthlyDataEnd))->getAlignment()
            ->setHorizontal(Alignment::HORIZONTAL_CENTER);
        if ($summaryDataEnd >= 7) {
            $this->writeTotalsRow($sheet, $summaryDataEnd, 'F', 'O');
        }
        if ($monthlyDataEnd >= 7) {
            $this->writeTotalsRow($detailSheet, $monthlyDataEnd, 'G', 'P');
        }
        $spreadsheet->setActiveSheetIndex(0);

        return $spreadsheet;
    }

    private function metricValue(array $totals, string $metric): int|string
    {
        return array_key_exists($metric, $totals)
            ? $this->display($totals[$metric])
            : 0;
    }

    private function display(mixed $value): int|string
    {
        return $value === null ? 'Belum dapat ditentukan' : $value;
    }

    private function nipValue(mixed $value): string
    {
        return $value === null ? '' : (string) $value;
    }

    private function styleSheet(
        Worksheet $sheet,
        string $titleRange,
        string $headerRange,
        string $lastColumn,
        int $lastDataRow,
        string $freezePane,
        array $groupHeaders,
        string $numberFormatRange,
        string $nipRange,
        string $lateRange,
        string $earlyRange,
        array $absenceRanges,
        array $widths,
    ): void {
        ExcelExportStyler::styleSheet(
            $sheet,
            $titleRange,
            $headerRange,
            $lastColumn,
            $lastDataRow,
            $freezePane,
            $groupHeaders,
            $numberFormatRange,
            $nipRange,
            $lateRange,
            $earlyRange,
            $absenceRanges,
            $widths,
        );
    }

    private function writeTotalsRow(Worksheet $sheet, int $lastDataRow, string $firstMetricColumn, string $lastMetricColumn): void
    {
        $totalRow = $lastDataRow + 1;
        ExcelExportHelper::writeValue($sheet, 'A'.$totalRow, 'TOTAL');
        $firstMetricIndex = Coordinate::columnIndexFromString($firstMetricColumn);
        $lastMetricIndex = Coordinate::columnIndexFromString($lastMetricColumn);

        for ($columnIndex = $firstMetricIndex; $columnIndex <= $lastMetricIndex; $columnIndex++) {
            $column = ExcelExportHelper::columnName($columnIndex);
            $hasUnknownValue = false;
            for ($row = 7; $row <= $lastDataRow; $row++) {
                if ($sheet->getCell($column.$row)->getValue() === 'Belum dapat ditentukan') {
                    $hasUnknownValue = true;

                    break;
                }
            }

            if ($hasUnknownValue) {
                ExcelExportHelper::writeValue($sheet, $column.$totalRow, 'Belum dapat ditentukan');
            } else {
                $sheet->setCellValue($column.$totalRow, '=SUM('.$column.'7:'.$column.$lastDataRow.')');
            }
        }

        $lastColumn = $sheet->getHighestColumn();
        $sheet->getStyle('A'.$totalRow.':'.$lastColumn.$totalRow)->applyFromArray([
            'font' => ['name' => 'Calibri', 'size' => 11, 'bold' => true, 'color' => ['rgb' => '1E293B']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'E8EEF2']],
            'alignment' => ['vertical' => Alignment::VERTICAL_CENTER],
            'borders' => [
                'top' => ['borderStyle' => Border::BORDER_MEDIUM, 'color' => ['rgb' => 'D9E2E7']],
            ],
        ]);
        $sheet->getStyle($firstMetricColumn.$totalRow.':'.$lastMetricColumn.$totalRow)
            ->getNumberFormat()
            ->setFormatCode('#,##0;-#,##0;"-"');
        $sheet->getStyle($firstMetricColumn.$totalRow.':'.$lastMetricColumn.$totalRow)
            ->getAlignment()
            ->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $sheet->getRowDimension($totalRow)->setRowHeight(20);
    }

    public function filename(int $unitId, int $year): string
    {
        $unit = Unit::query()->findOrFail($unitId);

        return 'rekap-tahunan-'.preg_replace('/[^A-Za-z0-9]+/', '-', strtolower($unit->code ?? $unit->name ?? 'unit')).'-'.$year.'.xlsx';
    }
}
