<?php

namespace App\Services\Fingerprint;

use Carbon\CarbonImmutable;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\RichText\RichText;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use Throwable;

class FingerprintExcelReader
{
    public const RAW_SHEET_NAME = 'Lap. Log Absen';

    private const ID_HEADERS = ['id', 'fingerprintid', 'idfingerprint', 'employeeid', 'pegawaiid', 'userid', 'noid'];

    private const NAME_HEADERS = ['nama', 'name', 'employeename', 'namapegawai'];

    private const DEPARTMENT_HEADERS = ['dept', 'department', 'departemen', 'kategori', 'category'];

    private const DATE_HEADERS = ['tanggal', 'date', 'attendancedate'];

    private const TIME_HEADERS = ['waktu', 'time', 'timestamp', 'attendancetime', 'logtime'];

    private const MONTHS = [
        'january' => 1, 'jan' => 1, 'januari' => 1,
        'february' => 2, 'feb' => 2, 'februari' => 2,
        'march' => 3, 'mar' => 3, 'maret' => 3,
        'april' => 4, 'apr' => 4,
        'may' => 5, 'mei' => 5,
        'june' => 6, 'jun' => 6, 'juni' => 6,
        'july' => 7, 'jul' => 7, 'juli' => 7,
        'august' => 8, 'aug' => 8, 'agustus' => 8,
        'september' => 9, 'sep' => 9,
        'october' => 10, 'oct' => 10, 'oktober' => 10, 'okt' => 10,
        'november' => 11, 'nov' => 11,
        'december' => 12, 'dec' => 12, 'desember' => 12, 'des' => 12,
    ];

    public function __construct(
        private readonly FingerprintRowParser $rowParser,
        private readonly FingerprintDateParser $dateParser,
    ) {}

    public function read(string $path): array
    {
        try {
            $spreadsheet = IOFactory::load($path);
        } catch (Throwable $exception) {
            throw new FingerprintImportException('The Excel file is corrupt or unreadable.', previous: $exception);
        }

        try {
            $selectedSheet = null;
            $selectedMapping = null;

            foreach ($spreadsheet->getAllSheets() as $sheet) {
                if ($sheet->getTitle() === self::RAW_SHEET_NAME) {
                    try {
                        $selectedSheet = $sheet;
                        $selectedMapping = $this->findHeader($sheet);

                        break;
                    } catch (FingerprintImportException) {
                        // keep scanning other sheets; the exact raw name may be present but malformed
                    }
                }
            }

            if ($selectedSheet === null) {
                foreach ($spreadsheet->getAllSheets() as $sheet) {
                    try {
                        $mapping = $this->findHeader($sheet);
                        $selectedSheet = $sheet;
                        $selectedMapping = $mapping;

                        break;
                    } catch (FingerprintImportException) {
                        // continue until we find a valid fingerprint log sheet
                    }
                }
            }

            if ($selectedSheet === null || $selectedMapping === null) {
                throw new FingerprintImportException('Required sheet "Lap. Log Absen" was not found.');
            }

            return $selectedMapping['format'] === 'matrix'
                ? $this->readMatrix($selectedSheet, $selectedMapping)
                : $this->readTabular($selectedSheet, $selectedMapping);
        } finally {
            $spreadsheet->disconnectWorksheets();
        }
    }

    private function readTabular(Worksheet $sheet, array $mapping): array
    {
        $rows = [];
        $fingerprintIds = [];
        $rawTimestampCount = 0;
        $validTimestampCount = 0;
        $invalidTimestampCount = 0;
        $lastColumn = Coordinate::columnIndexFromString($sheet->getHighestDataColumn());

        for ($rowNumber = $mapping['header_row'] + 1; $rowNumber <= $sheet->getHighestDataRow(); $rowNumber++) {
            $idCell = $sheet->getCell([$mapping['id_column'], $rowNumber]);
            $dateCell = $sheet->getCell([$mapping['date_column'], $rowNumber]);
            $nameCell = $mapping['name_column'] === null
                ? null
                : $sheet->getCell([$mapping['name_column'], $rowNumber]);
            $departmentCell = $mapping['department_column'] === null
                ? null
                : $sheet->getCell([$mapping['department_column'], $rowNumber]);
            $timeCells = [];

            foreach ($mapping['time_columns'] as $column) {
                $cell = $sheet->getCell([$column, $rowNumber]);
                $rawValue = $this->plainValue($cell->getValue());
                $parseValue = $rawValue;

                if (is_numeric($cell->getValue()) && ExcelDate::isDateTime($cell)) {
                    $parseValue = ExcelDate::excelToDateTimeObject((float) $cell->getValue())->format('H:i');
                }

                $timeCells[] = [
                    'raw_value' => $rawValue,
                    'parse_value' => $parseValue,
                    'source_cell' => $cell->getCoordinate(),
                ];
            }

            $parsed = $this->rowParser->parse([
                'source_row' => $rowNumber,
                'fingerprint_id' => trim((string) $idCell->getFormattedValue()),
                'employee_name_raw' => $nameCell === null ? null : $this->plainValue($nameCell->getValue()),
                'employee_department_raw' => $departmentCell === null ? null : $this->plainValue($departmentCell->getValue()),
                'raw_date' => $dateCell->getValue(),
                'source_date_cell' => $dateCell->getCoordinate(),
                'time_cells' => $timeCells,
            ]);

            if ($parsed !== null) {
                $rows[] = $parsed;
                $fingerprintIds[$parsed['fingerprint_id']] = true;
                $rawTimestampCount += $parsed['raw_timestamp_count'];
                $validTimestampCount += $parsed['valid_timestamp_count'];
                $invalidTimestampCount += $parsed['invalid_timestamp_count'];
            }
        }

        return [
            'sheet' => $sheet->getTitle(),
            'format' => 'tabular',
            'header_row' => $mapping['header_row'],
            'rows' => $rows,
            'employees_detected' => count(array_filter(array_keys($fingerprintIds), static fn (string $id): bool => $id !== '')),
            'raw_timestamp_count' => $rawTimestampCount,
            'valid_timestamp_count' => $validTimestampCount,
            'invalid_timestamp_count' => $invalidTimestampCount,
        ];
    }

    private function readMatrix(Worksheet $sheet, array $mapping): array
    {
        $rows = [];
        $employeesDetected = 0;
        $rawTimestampCount = 0;
        $validTimestampCount = 0;
        $invalidTimestampCount = 0;
        $highestRow = $sheet->getHighestDataRow();
        $lastColumn = Coordinate::columnIndexFromString($sheet->getHighestDataColumn());

        for ($metadataRow = $mapping['date_header_row'] + 1; $metadataRow <= $highestRow; $metadataRow++) {
            $metadata = $this->readEmployeeMetadata($sheet, $metadataRow, $lastColumn);

            if (! $metadata['has_labels']) {
                continue;
            }

            $employeesDetected++;
            $timelineRow = $metadataRow + 1;

            if ($timelineRow > $highestRow) {
                continue;
            }

            foreach ($mapping['day_columns'] as $day => $column) {
                $date = $mapping['period_start']->addDays($day - 1);

                if ($date->gt($mapping['period_end'])) {
                    continue;
                }

                $cell = $sheet->getCell([$column, $timelineRow]);
                $rawValue = $this->plainValue($cell->getValue());

                if ($rawValue === null || trim($rawValue) === '') {
                    continue;
                }

                $parseValue = $rawValue;
                if (is_numeric($cell->getValue()) && ExcelDate::isDateTime($cell)) {
                    $parseValue = ExcelDate::excelToDateTimeObject((float) $cell->getValue())->format('H:i');
                }

                $sourceCell = $cell->getCoordinate();
                $sourceDateCell = $sheet->getCell([$column, $mapping['date_header_row']])->getCoordinate();

                $parsed = $this->rowParser->parse([
                    'source_row' => $timelineRow,
                    'fingerprint_id' => $metadata['fingerprint_id'],
                    'employee_name_raw' => $metadata['name'],
                    'employee_department_raw' => $metadata['department'],
                    'raw_date' => $date->toDateString(),
                    'source_date_cell' => $sourceDateCell,
                    'time_cells' => [[
                        'raw_value' => $rawValue,
                        'parse_value' => $parseValue,
                        'source_cell' => $sourceCell,
                    ]],
                ]);

                if ($parsed === null) {
                    continue;
                }

                $rows[] = $parsed;
                $rawTimestampCount += $parsed['raw_timestamp_count'];
                $validTimestampCount += $parsed['valid_timestamp_count'];
                $invalidTimestampCount += $parsed['invalid_timestamp_count'];
            }

            $metadataRow = $timelineRow;
        }

        return [
            'sheet' => $sheet->getTitle(),
            'format' => 'matrix',
            'header_row' => $mapping['header_row'],
            'date_header_row' => $mapping['date_header_row'],
            'period_start' => $mapping['period_start']->toDateString(),
            'period_end' => $mapping['period_end']->toDateString(),
            'employees_detected' => $employeesDetected,
            'raw_timestamp_count' => $rawTimestampCount,
            'valid_timestamp_count' => $validTimestampCount,
            'invalid_timestamp_count' => $invalidTimestampCount,
            'rows' => $rows,
        ];
    }

    private function findHeader(Worksheet $sheet): array
    {
        $lastRow = min($sheet->getHighestDataRow(), 100);
        $lastColumn = Coordinate::columnIndexFromString($sheet->getHighestDataColumn());

        for ($rowNumber = 1; $rowNumber <= $lastRow; $rowNumber++) {
            $headers = [];

            for ($column = 1; $column <= $lastColumn; $column++) {
                $headers[$column] = $this->headerToken($this->plainValue($sheet->getCell([$column, $rowNumber])->getValue()));
            }

            $matrixColumn = $this->findColumnContaining($headers, 'waktuabsen');
            if ($matrixColumn !== null) {
                $dayColumns = $this->findDayColumns($sheet, $rowNumber, $lastColumn);
                $dateHeaderRow = $rowNumber;

                if ($dayColumns === []) {
                    $dateHeaderRow++;
                    $dayColumns = $this->findDayColumns($sheet, $dateHeaderRow, $lastColumn);
                }

                if ($dayColumns === []) {
                    throw new FingerprintImportException('The "Waktu Absen" header was found, but its day columns (1-31) could not be detected.');
                }

                $period = $this->findPeriod($sheet, $rowNumber);
                if ($period === null) {
                    throw new FingerprintImportException('The attendance period could not be determined safely from "Lap. Log Absen". Include a date range or month and year in the sheet metadata.');
                }

                return [
                    'format' => 'matrix',
                    'header_row' => $rowNumber,
                    'date_header_row' => $dateHeaderRow,
                    'day_columns' => $dayColumns,
                    'period_start' => $period['start'],
                    'period_end' => $period['end'],
                ];
            }

            $idColumn = $this->findColumn($headers, self::ID_HEADERS);
            $nameColumn = $this->findColumn($headers, self::NAME_HEADERS);
            $departmentColumn = $this->findColumn($headers, self::DEPARTMENT_HEADERS);
            $dateColumn = $this->findColumn($headers, self::DATE_HEADERS);
            $timeColumns = [];

            foreach ($headers as $column => $header) {
                if (in_array($header, self::TIME_HEADERS, true)
                    || str_contains($header, 'timestamp')
                    || str_contains($header, 'waktu')
                    || str_contains($header, 'jam')
                    || str_contains($header, 'time')) {
                    $timeColumns[] = $column;
                }
            }

            if ($idColumn !== null && $dateColumn !== null && $timeColumns !== []) {
                return [
                    'format' => 'tabular',
                    'header_row' => $rowNumber,
                    'id_column' => $idColumn,
                    'name_column' => $nameColumn,
                    'department_column' => $departmentColumn,
                    'date_column' => $dateColumn,
                    'time_columns' => $timeColumns,
                ];
            }
        }

        throw new FingerprintImportException('The raw log sheet must contain either an ID/Tanggal/Waktu table or a "Waktu Absen" matrix with day columns.');
    }

    private function findDayColumns(Worksheet $sheet, int $rowNumber, int $lastColumn): array
    {
        $dayColumns = [];

        for ($column = 1; $column <= $lastColumn; $column++) {
            $value = $sheet->getCell([$column, $rowNumber])->getValue();
            $formatted = trim((string) $sheet->getCell([$column, $rowNumber])->getFormattedValue());

            if (is_numeric($value) && (int) $value >= 1 && (int) $value <= 31 && (float) $value === (float) (int) $value) {
                $dayColumns[(int) $value] = $column;
            } elseif (preg_match('/^(?:0?[1-9]|[12][0-9]|3[01])$/', $formatted) === 1) {
                $dayColumns[(int) $formatted] = $column;
            }
        }

        ksort($dayColumns);

        return $dayColumns;
    }

    private function readEmployeeMetadata(Worksheet $sheet, int $rowNumber, int $lastColumn): array
    {
        $metadata = ['has_labels' => false, 'fingerprint_id' => '', 'name' => null, 'department' => null];

        for ($column = 1; $column <= $lastColumn; $column++) {
            $text = trim((string) ($this->plainValue($sheet->getCell([$column, $rowNumber])->getValue()) ?? ''));
            $label = $this->metadataLabel($text);

            if ($label === null) {
                continue;
            }

            $metadata['has_labels'] = true;
            $value = $this->metadataValue($sheet, $rowNumber, $column, $text, $label, $lastColumn);

            if ($label === 'id') {
                $metadata['fingerprint_id'] = $value ?? '';
            } elseif ($label === 'name') {
                $metadata['name'] = $value;
            } elseif ($label === 'department') {
                $metadata['department'] = $value;
            }
        }

        return $metadata;
    }

    private function metadataLabel(string $value): ?string
    {
        $label = $this->headerToken($value);

        if (preg_match('/^id\s*:/i', $value) === 1 || $label === 'id') {
            return 'id';
        }

        if (preg_match('/^(?:nama|name)\s*:/i', $value) === 1 || in_array($label, self::NAME_HEADERS, true)) {
            return 'name';
        }

        if (preg_match('/^(?:dept\.?|department|departemen|kategori|category)\s*:/i', $value) === 1
            || in_array($label, self::DEPARTMENT_HEADERS, true)) {
            return 'department';
        }

        return null;
    }

    private function metadataValue(Worksheet $sheet, int $rowNumber, int $column, string $labelCell, string $label, int $lastColumn): ?string
    {
        $inline = preg_replace('/^(?:id|nama|name|dept\.?|department|departemen|kategori|category)\s*:\s*/i', '', $labelCell);
        if ($inline !== null && trim($inline) !== '' && trim($inline) !== trim($labelCell)) {
            return trim($inline);
        }

        for ($candidateColumn = $column + 1; $candidateColumn <= min($lastColumn, $column + 12); $candidateColumn++) {
            $candidateCell = $sheet->getCell([$candidateColumn, $rowNumber]);
            $candidate = trim((string) ($this->plainValue($candidateCell->getValue()) ?? ''));

            if ($candidate === '') {
                continue;
            }

            if ($this->metadataLabel($candidate) !== null) {
                break;
            }

            return trim((string) $candidateCell->getFormattedValue());
        }

        return null;
    }

    private function findPeriod(Worksheet $sheet, int $headerRow): ?array
    {
        $lastRow = min($headerRow, 50);
        $lastColumn = Coordinate::columnIndexFromString($sheet->getHighestDataColumn());
        $text = '';

        for ($row = 1; $row <= $lastRow; $row++) {
            for ($column = 1; $column <= $lastColumn; $column++) {
                $value = $this->plainValue($sheet->getCell([$column, $row])->getValue());
                if ($value !== null && trim($value) !== '') {
                    $text .= ' '.$value;
                }
            }
        }

        $datePattern = '(?:\d{1,2}[\/.-]\d{1,2}[\/.-]\d{4}|\d{4}[\/.-]\d{1,2}[\/.-]\d{1,2})';
        $rangePattern = '/(?<!\d)('.$datePattern.')(?!\d)\s*(?:s\s*\/\s*d|to|through|[-\x{2013}\x{2014}])\s*('.$datePattern.')(?!\d)/iu';

        if (preg_match($rangePattern, $text, $matches) === 1) {
            try {
                $start = CarbonImmutable::parse($this->dateParser->parse($matches[1]));
                $end = CarbonImmutable::parse($this->dateParser->parse($matches[2]));

                return $end->greaterThanOrEqualTo($start) ? ['start' => $start, 'end' => $end] : null;
            } catch (FingerprintImportException) {
                return null;
            }
        }

        if (preg_match('/\b(0?[1-9]|1[0-2])\s*[\/.-]\s*(20\d{2})\b/', $text, $matches) === 1) {
            return $this->monthPeriod((int) $matches[1], (int) $matches[2]);
        }

        if (preg_match('/\b(20\d{2})\s*[\/.-]\s*(0?[1-9]|1[0-2])\b/', $text, $matches) === 1) {
            return $this->monthPeriod((int) $matches[2], (int) $matches[1]);
        }

        if (preg_match_all('/\b([a-z]+)[\s,\/.-]+(20\d{2})\b/i', $text, $matches, PREG_SET_ORDER) > 0) {
            foreach ($matches as $match) {
                $month = self::MONTHS[mb_strtolower($match[1], 'UTF-8')] ?? null;
                if ($month !== null) {
                    return $this->monthPeriod($month, (int) $match[2]);
                }
            }
        }

        return null;
    }

    private function monthPeriod(int $month, int $year): array
    {
        $start = CarbonImmutable::create($year, $month, 1)->startOfDay();

        return ['start' => $start, 'end' => $start->endOfMonth()];
    }

    private function findColumn(array $headers, array $aliases): ?int
    {
        foreach ($headers as $column => $header) {
            if (in_array($header, $aliases, true)) {
                return $column;
            }
        }

        return null;
    }

    private function findColumnContaining(array $headers, string $needle): ?int
    {
        foreach ($headers as $column => $header) {
            if (str_contains($header, $needle)) {
                return $column;
            }
        }

        return null;
    }

    private function headerToken(?string $header): string
    {
        return preg_replace('/[^a-z0-9]/', '', mb_strtolower(trim($header ?? ''), 'UTF-8')) ?? '';
    }

    private function plainValue(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if ($value instanceof RichText) {
            return $value->getPlainText();
        }

        if (is_scalar($value)) {
            return (string) $value;
        }

        return method_exists($value, 'format') ? $value->format('Y-m-d H:i:s') : (string) $value;
    }
}
