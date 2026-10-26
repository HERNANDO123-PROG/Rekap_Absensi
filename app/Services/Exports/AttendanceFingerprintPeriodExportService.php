<?php

namespace App\Services\Exports;

use App\Data\Reports\AttendanceFingerprintPeriodReportFilters;
use App\Models\Unit;
use App\Services\Reports\AttendanceFingerprintPeriodReportService;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
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
        $spreadsheet = new Spreadsheet;
        $summarySheet = $spreadsheet->getActiveSheet();
        $summarySheet->setTitle('Rekap Pegawai');
        $this->writeRow($summarySheet, 1, ['Unit', $unit->code ?? $unit->name]);
        $this->writeRow($summarySheet, 2, ['Periode', $filters->startDate.' s/d '.$filters->endDate]);
        $this->writeRow($summarySheet, 3, ['Kategori', $filters->categoryId ?? 'Semua']);
        $this->writeRow($summarySheet, 4, ['Pencarian', $filters->search ?? '']);
        $headers = [
            'No', 'ID Pegawai', 'Nama', 'NIP', 'Kategori', 'Hari Kerja', 'Total Finger',
            'Hadir', 'Terlambat', 'Pulang Awal', 'Tidak Hadir', 'Izin Keluar', 'Dinas',
            'Provisional', 'Ambigu', 'Histori Terbatas',
        ];
        $this->writeRow($summarySheet, 6, $headers);

        foreach ($report['data'] as $index => $employee) {
            $this->writeRow($summarySheet, $index + 7, [
                $index + 1,
                $employee['employee_id'],
                $employee['name'],
                $employee['nip'] ?? '',
                $employee['category_name'] ?? 'Tidak tersedia',
                $this->display($employee['hari_kerja']),
                $this->display($employee['total_finger']),
                $this->display($employee['hadir']),
                $this->display($employee['terlambat']),
                $this->display($employee['pulang_awal']),
                $this->display($employee['tidak_hadir']),
                $this->display($employee['izin_keluar']),
                $this->display($employee['dinas']),
                $employee['is_provisional'] ? 'Ya' : 'Tidak',
                $employee['is_ambiguous'] ? 'Ya' : 'Tidak',
                $employee['history_limited'] ? 'Ya' : 'Tidak',
            ]);
        }

        $dailySheet = $spreadsheet->createSheet();
        $dailySheet->setTitle('Rincian Harian');
        $dailyHeaders = [
            'ID Pegawai', 'Nama', 'NIP', 'Tanggal', 'Status Hari', 'Sumber Status',
            'Provisional', 'Ambigu', 'Histori Terbatas', 'Jadwal Masuk', 'Jadwal Pulang',
            'Sumber Jadwal', 'Masuk Terpilih', 'Pulang Terpilih', 'Status Kandidat',
            'Jumlah Log', 'Timestamp Unik', 'Hari Kerja', 'Total Finger', 'Hadir',
            'Terlambat', 'Pulang Awal', 'Tidak Hadir', 'Izin Keluar', 'Dinas',
            'Jenis Izin', 'Status Persetujuan', 'Status Verifikasi', 'Alasan/Peringatan',
        ];
        $this->writeRow($dailySheet, 1, $dailyHeaders);
        $dailyRow = 2;
        foreach ($report['data'] as $employee) {
            foreach ($employee['daily'] as $day) {
                $metrics = $day['metrics'];
                $this->writeRow($dailySheet, $dailyRow++, [
                    $employee['employee_id'],
                    $employee['name'],
                    $employee['nip'] ?? '',
                    $day['date'],
                    $day['day_status'],
                    $day['source'] ?? 'Belum dapat ditentukan',
                    $day['is_provisional'] ? 'Ya' : 'Tidak',
                    $day['is_ambiguous'] ? 'Ya' : 'Tidak',
                    $day['history_limited'] ? 'Ya' : 'Tidak',
                    $day['schedule_start'] ?? 'Belum dapat ditentukan',
                    $day['schedule_end'] ?? 'Belum dapat ditentukan',
                    $day['schedule_source'] ?? 'Belum dapat ditentukan',
                    $day['selected_in'] ?? 'Belum dapat ditentukan',
                    $day['selected_out'] ?? 'Belum dapat ditentukan',
                    $day['candidate_status'],
                    $day['raw_log_count'],
                    $day['distinct_time_count'],
                    $this->display($metrics['hari_kerja']),
                    $this->display($metrics['total_finger']),
                    $this->display($metrics['hadir']),
                    $this->display($metrics['terlambat']),
                    $this->display($metrics['pulang_awal']),
                    $this->display($metrics['tidak_hadir']),
                    $this->display($metrics['izin_keluar']),
                    $this->display($metrics['dinas']),
                    implode(', ', $day['leave_types']),
                    $day['leave_approval_status'],
                    $day['leave_status'],
                    $day['reason_or_warning'] ?? '',
                ]);
            }
        }

        foreach ([$summarySheet, $dailySheet] as $sheet) {
            $lastColumn = Coordinate::columnIndexFromString($sheet->getHighestColumn());
            for ($columnIndex = 1; $columnIndex <= $lastColumn; $columnIndex++) {
                $column = Coordinate::stringFromColumnIndex($columnIndex);
                $sheet->getColumnDimension($column)->setAutoSize(true);
            }
            $sheet->freezePane($sheet === $summarySheet ? 'A7' : 'A2');
        }
        $summarySheet->getStyle('A6:P6')->getFont()->setBold(true);
        $dailySheet->getStyle('A1:AC1')->getFont()->setBold(true);
        $summarySheet->setAutoFilter('A6:P'.max(6, $summarySheet->getHighestRow()));
        $dailySheet->setAutoFilter('A1:AC'.max(1, $dailySheet->getHighestRow()));

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
        $firstPage = $this->reportService->generate($filters->forPage(1, 100));
        $rows = $firstPage['data'];

        for ($page = 2; $page <= $firstPage['pagination']['last_page']; $page++) {
            $pageReport = $this->reportService->generate($filters->forPage($page, 100));
            array_push($rows, ...$pageReport['data']);
        }

        $firstPage['data'] = $rows;

        return $firstPage;
    }

    private function display(mixed $value): int|string
    {
        return $value === null ? 'Belum dapat ditentukan' : $value;
    }

    private function writeRow(Worksheet $sheet, int $row, array $values): void
    {
        foreach (array_values($values) as $index => $value) {
            $coordinate = Coordinate::stringFromColumnIndex($index + 1).$row;
            if (is_string($value)) {
                $sheet->setCellValueExplicit($coordinate, $value, DataType::TYPE_STRING);
            } else {
                $sheet->setCellValue($coordinate, $value);
            }
        }
    }
}
