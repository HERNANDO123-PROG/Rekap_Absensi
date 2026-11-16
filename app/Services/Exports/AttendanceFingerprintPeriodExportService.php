<?php

namespace App\Services\Exports;

use App\Data\Reports\AttendanceFingerprintPeriodReportFilters;
use App\Models\Employee;
use App\Models\Unit;
use App\Models\WorkScheduleCategory;
use App\Services\Reports\AttendanceFingerprintPeriodReportService;
use Carbon\CarbonImmutable;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class AttendanceFingerprintPeriodExportService
{
    public function __construct(
        private readonly AttendanceFingerprintPeriodReportService $reportService,
    ) {}

    public function buildSpreadsheet(AttendanceFingerprintPeriodReportFilters $filters): Spreadsheet
    {
        $report = $this->generateAllPages($filters);
        $unit = Unit::query()->findOrFail($filters->unitId);
        $category = $filters->categoryId === null
            ? 'Semua kategori'
            : (WorkScheduleCategory::query()->find($filters->categoryId)?->name ?? 'Kategori tidak tersedia');
        $period = CarbonImmutable::parse($filters->startDate)->format('d-m-Y')
            .' s/d '.CarbonImmutable::parse($filters->endDate)->format('d-m-Y');
        $spreadsheet = new Spreadsheet;
        $summarySheet = $spreadsheet->getActiveSheet();
        $summarySheet->setTitle('Rekap Pegawai');
        $summarySheet->mergeCells('A1:M1');
        ExcelExportHelper::writeValue($summarySheet, 'A1', 'RINGKASAN REKAP ABSENSI');
        ExcelExportHelper::writeValue($summarySheet, 'A2', 'Unit:');
        ExcelExportHelper::writeValue($summarySheet, 'C2', $unit->code ?? $unit->name);
        ExcelExportHelper::writeValue($summarySheet, 'D2', 'Periode:');
        ExcelExportHelper::writeValue($summarySheet, 'F2', $period);
        ExcelExportHelper::writeValue($summarySheet, 'A3', 'Kategori:');
        ExcelExportHelper::writeValue($summarySheet, 'C3', $category);
        ExcelExportHelper::writeValue($summarySheet, 'D3', 'Pencarian:');
        ExcelExportHelper::writeValue($summarySheet, 'F3', $filters->search ?? 'Semua pegawai');
        $summarySheet->mergeCells('A2:B2');
        $summarySheet->mergeCells('D2:E2');
        $summarySheet->mergeCells('F2:M2');
        $summarySheet->mergeCells('A3:B3');
        $summarySheet->mergeCells('D3:E3');
        $summarySheet->mergeCells('F3:M3');
        ExcelExportHelper::writeValue($summarySheet, 'A6', 'IDENTITAS PEGAWAI');
        ExcelExportHelper::writeValue($summarySheet, 'F6', 'KEHADIRAN');
        ExcelExportHelper::writeValue($summarySheet, 'K6', 'KETIDAKHADIRAN');
        $summarySheet->mergeCells('A6:E6');
        $summarySheet->mergeCells('F6:J6');
        $summarySheet->mergeCells('K6:M6');
        $headers = [
            'No', 'ID Pegawai', 'Nama Pegawai', 'NIP', 'Kategori', 'Hari Kerja', 'Hadir',
            'Terlambat', 'Pulang Awal', 'Total Finger', 'Tidak Hadir', 'Izin Keluar', 'Dinas',
        ];
        ExcelExportHelper::writeHeaderRow($summarySheet, $headers, 7);

        foreach ($report['data'] as $index => $employee) {
            ExcelExportHelper::writeRow($summarySheet, $index + 8, [
                $index + 1,
                $employee['employee_id'],
                $employee['name'],
                (string) ($employee['nip'] ?? ''),
                $employee['category_name'] ?? 'Tidak tersedia',
                $this->display($employee['hari_kerja']),
                $this->display($employee['hadir']),
                $this->display($employee['terlambat']),
                $this->display($employee['pulang_awal']),
                $this->display($employee['total_finger']),
                $this->display($employee['tidak_hadir']),
                $this->display($employee['izin_keluar']),
                $this->display($employee['dinas']),
            ]);
        }

        $dailySheet = $spreadsheet->createSheet();
        $dailySheet->setTitle('Rincian Harian');
        $dailySheet->mergeCells('A1:Q1');
        ExcelExportHelper::writeValue($dailySheet, 'A1', 'RINCIAN ABSENSI HARIAN');
        ExcelExportHelper::writeValue($dailySheet, 'A2', 'Unit:');
        ExcelExportHelper::writeValue($dailySheet, 'C2', $unit->code ?? $unit->name);
        ExcelExportHelper::writeValue($dailySheet, 'D2', 'Periode:');
        ExcelExportHelper::writeValue($dailySheet, 'F2', $period);
        ExcelExportHelper::writeValue($dailySheet, 'A3', 'Kategori:');
        ExcelExportHelper::writeValue($dailySheet, 'C3', $category);
        ExcelExportHelper::writeValue($dailySheet, 'D3', 'Keterangan:');
        ExcelExportHelper::writeValue($dailySheet, 'F3', 'Satu baris mewakili satu pegawai pada satu tanggal.');
        $dailySheet->mergeCells('A2:B2');
        $dailySheet->mergeCells('D2:E2');
        $dailySheet->mergeCells('F2:Q2');
        $dailySheet->mergeCells('A3:B3');
        $dailySheet->mergeCells('D3:E3');
        $dailySheet->mergeCells('F3:Q3');
        ExcelExportHelper::writeValue($dailySheet, 'A6', 'IDENTITAS & TANGGAL');
        ExcelExportHelper::writeValue($dailySheet, 'F6', 'JADWAL & FINGERPRINT');
        ExcelExportHelper::writeValue($dailySheet, 'K6', 'KEHADIRAN');
        ExcelExportHelper::writeValue($dailySheet, 'O6', 'KETERANGAN');
        $dailySheet->mergeCells('A6:E6');
        $dailySheet->mergeCells('F6:J6');
        $dailySheet->mergeCells('K6:N6');
        $dailySheet->mergeCells('O6:Q6');
        $dailyHeaders = [
            'No', 'Tanggal', 'ID Pegawai', 'Nama Pegawai', 'NIP', 'Status Hari',
            'Jadwal Masuk', 'Scan Masuk', 'Jadwal Pulang', 'Scan Pulang',
            'Hadir', 'Terlambat', 'Pulang Awal', 'Total Finger',
            'Jenis Izin', 'Persetujuan', 'Catatan',
        ];
        ExcelExportHelper::writeHeaderRow($dailySheet, $dailyHeaders, 7);
        $dailyRow = 8;
        $dailyIndex = 1;
        foreach ($report['data'] as $employee) {
            foreach ($employee['daily'] as $day) {
                $metrics = $day['metrics'];
                ExcelExportHelper::writeRow($dailySheet, $dailyRow++, [
                    $dailyIndex++,
                    CarbonImmutable::parse($day['date'])->format('d-m-Y'),
                    $employee['employee_id'],
                    $employee['name'],
                    (string) ($employee['nip'] ?? ''),
                    $this->dayStatus($day['day_status']),
                    $this->compactDisplay($day['schedule_start']),
                    $this->compactDisplay($day['selected_in']),
                    $this->compactDisplay($day['schedule_end']),
                    $this->compactDisplay($day['selected_out']),
                    $this->compactDisplay($metrics['hadir']),
                    $this->compactDisplay($metrics['terlambat']),
                    $this->compactDisplay($metrics['pulang_awal']),
                    $this->compactDisplay($metrics['total_finger']),
                    $this->leaveTypes($day['leave_types']),
                    $this->leaveApproval($day['leave_approval_status']),
                    $this->dailyNote($day),
                ]);
            }
        }

        $summaryDataEnd = 7 + count($report['data']);
        $dailyDataEnd = $dailyRow - 1;
        ExcelExportStyler::styleSheet(
            $summarySheet,
            'A1:M1',
            'A7:M7',
            'M',
            $summaryDataEnd,
            'D8',
            ['A6:E6' => '0F4C5C', 'F6:J6' => '0F766E', 'K6:M6' => '115E59'],
            'F8:M'.max(8, $summaryDataEnd),
            'D8:D'.max(8, $summaryDataEnd),
            'H8:H'.max(8, $summaryDataEnd),
            'I8:I'.max(8, $summaryDataEnd),
            ['K8:K'.max(8, $summaryDataEnd), 'L8:L'.max(8, $summaryDataEnd)],
            [
                'A' => 6, 'B' => 14, 'C' => 28, 'D' => 16, 'E' => 14, 'F' => 14,
                'G' => 12, 'H' => 15, 'I' => 16, 'J' => 16, 'K' => 16, 'L' => 16,
                'M' => 12,
            ],
            [
                'group_header_row' => 6,
                'header_row' => 7,
                'data_start_row' => 8,
                'label_cells' => ['A2', 'D2', 'A3', 'D3'],
                'hidden_rows' => [5],
                'repeat_rows' => [6, 7],
            ],
        );
        ExcelExportStyler::styleSheet(
            $dailySheet,
            'A1:Q1',
            'A7:Q7',
            'Q',
            max(7, $dailyDataEnd),
            'D8',
            [
                'A6:E6' => '0F4C5C',
                'F6:J6' => '155E75',
                'K6:N6' => '0F766E',
                'O6:Q6' => '115E59',
            ],
            'K8:N'.max(8, $dailyDataEnd),
            'E8:E'.max(8, $dailyDataEnd),
            'L8:L'.max(8, $dailyDataEnd),
            'M8:M'.max(8, $dailyDataEnd),
            [],
            [
                'A' => 6, 'B' => 13, 'C' => 14, 'D' => 28, 'E' => 16, 'F' => 18,
                'G' => 14, 'H' => 14, 'I' => 14, 'J' => 14, 'K' => 12, 'L' => 14,
                'M' => 14, 'N' => 16, 'O' => 18, 'P' => 16, 'Q' => 42,
            ],
            [
                'group_header_row' => 6,
                'header_row' => 7,
                'data_start_row' => 8,
                'label_cells' => ['A2', 'D2', 'A3', 'D3'],
                'hidden_rows' => [5],
                'repeat_rows' => [6, 7],
                'alignment_ranges' => [
                    ['range' => 'A8:C'.max(8, $dailyDataEnd), 'horizontal' => Alignment::HORIZONTAL_CENTER],
                    ['range' => 'D8:E'.max(8, $dailyDataEnd), 'horizontal' => Alignment::HORIZONTAL_LEFT, 'indent' => 1],
                    ['range' => 'F8:N'.max(8, $dailyDataEnd), 'horizontal' => Alignment::HORIZONTAL_CENTER],
                    ['range' => 'O8:Q'.max(8, $dailyDataEnd), 'horizontal' => Alignment::HORIZONTAL_LEFT],
                ],
            ],
        );
        if ($dailyDataEnd >= 8) {
            $dailySheet->getStyle('O8:Q'.$dailyDataEnd)
                ->getAlignment()
                ->setWrapText(true);
        }
        if ($summaryDataEnd >= 8) {
            $this->writeTotalsRow($summarySheet, $summaryDataEnd, 'F', 'M');
        }
        $spreadsheet->setActiveSheetIndex(0);

        return $spreadsheet;
    }

    public function filename(AttendanceFingerprintPeriodReportFilters $filters): string
    {
        $unit = Unit::query()->findOrFail($filters->unitId);
        $unitName = preg_replace('/[^A-Za-z0-9]+/', '-', strtolower($unit->code ?? $unit->name ?? 'unit'));

        return 'rekap-fingerprint-periode-'.$unitName.'-'.$filters->startDate.'-'.$filters->endDate.'.xlsx';
    }

    private function generateAllPages(AttendanceFingerprintPeriodReportFilters $filters): array
    {
        $employeeQuery = Employee::query()
            ->where('unit_id', $filters->unitId)
            ->when(
                $filters->categoryId !== null,
                fn ($query) => $query->where('category_id', $filters->categoryId),
            )
            ->when($filters->search !== null, function ($query) use ($filters): void {
                $term = '%'.$filters->search.'%';
                $query->where(function ($query) use ($term): void {
                    $query->where('nama_lengkap', 'like', $term)
                        ->orWhere('namafinger', 'like', $term)
                        ->orWhere('nip', 'like', $term);
                });
            });
        $employeeCount = $employeeQuery->count();

        return $this->reportService->generate($filters->forPage(1, max(1, $employeeCount)));
    }

    private function display(mixed $value): int|string
    {
        return $value === null ? 'Belum dapat ditentukan' : $value;
    }

    private function compactDisplay(mixed $value): int|string
    {
        return $value === null ? '-' : $value;
    }

    private function dayStatus(string $status): string
    {
        return match ($status) {
            'working' => 'Hari kerja',
            'non_working' => 'Hari libur',
            default => 'Belum diketahui',
        };
    }

    private function leaveTypes(array $types): string
    {
        $labels = [
            'terlambat' => 'Terlambat',
            'tidak_hadir' => 'Tidak hadir',
            'pulang_awal' => 'Pulang awal',
            'dinas' => 'Dinas',
            'keluar' => 'Izin keluar',
        ];

        return collect($types)
            ->map(fn (string $type): string => $labels[$type] ?? str_replace('_', ' ', ucfirst($type)))
            ->implode(', ');
    }

    private function leaveApproval(string $status): string
    {
        return match ($status) {
            'none' => '-',
            'conflict' => 'Konflik',
            'import' => 'Impor',
            'pending' => 'Menunggu',
            'confirmed' => 'Disetujui',
            default => ucfirst($status),
        };
    }

    private function dailyNote(array $day): string
    {
        $warning = $day['reason_or_warning'] ?? null;
        if ($warning !== null) {
            return match ($warning) {
                'ambiguous_single_scan_role' => 'Satu scan; jam masuk/pulang belum dapat dipastikan.',
                'overnight_shift_undetermined' => 'Jadwal lintas hari belum dapat dipastikan.',
                'schedule_unavailable' => 'Jadwal kerja belum tersedia.',
                default => $warning,
            };
        }

        if ($day['is_provisional']) {
            return 'Hari kerja merupakan perkiraan.';
        }
        if ($day['is_ambiguous']) {
            return 'Data perlu diperiksa.';
        }
        if ($day['history_limited']) {
            return 'Acuan jadwal historis terbatas.';
        }

        return '';
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
            for ($row = 8; $row <= $lastDataRow; $row++) {
                if ($sheet->getCell($column.$row)->getValue() === 'Belum dapat ditentukan') {
                    $hasUnknownValue = true;

                    break;
                }
            }

            if ($hasUnknownValue) {
                ExcelExportHelper::writeValue($sheet, $column.$totalRow, 'Belum dapat ditentukan');
            } else {
                $sheet->setCellValue($column.$totalRow, '=SUM('.$column.'8:'.$column.$lastDataRow.')');
            }
        }

        $sheet->getStyle('A'.$totalRow.':M'.$totalRow)->applyFromArray([
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
}
