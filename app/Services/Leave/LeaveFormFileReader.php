<?php

namespace App\Services\Leave;

use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\RichText\RichText;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use SplFileObject;
use Throwable;

class LeaveFormFileReader
{
    private const HEADER_SCAN_LIMIT = 30;

    public function __construct(private readonly LeaveFormHeaderMapper $mapper) {}

    public function read(string $path, string $extension): array
    {
        return match (strtolower($extension)) {
            'csv' => $this->readCsv($path),
            'xls', 'xlsx' => $this->readWorkbook($path),
            default => throw new LeaveImportException('Only .xls, .xlsx, and .csv files are supported.'),
        };
    }

    private function readWorkbook(string $path): array
    {
        try {
            $spreadsheet = IOFactory::load($path);
        } catch (Throwable $exception) {
            throw new LeaveImportException('The Excel file is corrupt or unreadable.', previous: $exception);
        }

        try {
            $best = null;

            foreach ($spreadsheet->getAllSheets() as $sheet) {
                $candidate = $this->readSheet($sheet);

                if ($best === null || $candidate['header_mapping']['recognized_count'] > $best['header_mapping']['recognized_count']) {
                    $best = $candidate;
                }

                if ($candidate['header_mapping']['missing_fields'] === []
                    && $candidate['header_mapping']['ambiguous_fields'] === []) {
                    return $candidate;
                }
            }

            if ($best === null) {
                throw new LeaveImportException('The Excel workbook contains no readable sheets.');
            }

            return $best;
        } finally {
            $spreadsheet->disconnectWorksheets();
        }
    }

    private function readSheet(Worksheet $sheet): array
    {
        $lastRow = min(max($sheet->getHighestDataRow(), 1), self::HEADER_SCAN_LIMIT);
        $lastColumn = Coordinate::columnIndexFromString($sheet->getHighestDataColumn());
        $best = null;

        for ($rowNumber = 1; $rowNumber <= $lastRow; $rowNumber++) {
            $headers = [];

            for ($column = 1; $column <= $lastColumn; $column++) {
                $headers[] = $this->plainValue($sheet->getCell([$column, $rowNumber])->getValue());
            }

            $mapping = $this->mapper->map($headers);
            $candidate = [
                'sheet_name' => $sheet->getTitle(),
                'header_row' => $rowNumber,
                'headers' => $mapping['detected_headers'],
                'header_mapping' => $mapping,
                'rows' => [],
            ];

            if ($best === null || $mapping['recognized_count'] > $best['header_mapping']['recognized_count']) {
                $best = $candidate;
            }

            if ($mapping['missing_fields'] === [] && $mapping['ambiguous_fields'] === []) {
                $best = $candidate;
                break;
            }
        }

        if ($best === null) {
            throw new LeaveImportException('Could not inspect the Excel sheet headers.');
        }

        for ($rowNumber = $best['header_row'] + 1; $rowNumber <= $sheet->getHighestDataRow(); $rowNumber++) {
            $row = [];

            for ($column = 1; $column <= $lastColumn; $column++) {
                $cell = $sheet->getCell([$column, $rowNumber]);
                $rawValue = $cell->getValue();
                $parseValue = $rawValue;

                if (is_numeric($rawValue) && ExcelDate::isDateTime($cell)) {
                    $parseValue = ExcelDate::excelToDateTimeObject((float) $rawValue);
                }

                $row[] = ['raw' => $this->plainValue($rawValue), 'value' => $this->plainValue($parseValue)];
            }

            $best['rows'][] = $row;
        }

        return $best;
    }

    private function readCsv(string $path): array
    {
        try {
            $file = new SplFileObject($path, 'r');
            $file->setFlags(SplFileObject::DROP_NEW_LINE);
            $delimiter = $this->detectCsvDelimiter($path);
            $best = null;
            $rowNumber = 0;

            while (! $file->eof() && $rowNumber < self::HEADER_SCAN_LIMIT) {
                $cells = $file->fgetcsv($delimiter, '"', '');
                $rowNumber++;

                if ($cells === [null] || $cells === false) {
                    continue;
                }

                $mapping = $this->mapper->map($cells);
                $candidate = [
                    'sheet_name' => null,
                    'header_row' => $rowNumber,
                    'headers' => $mapping['detected_headers'],
                    'header_mapping' => $mapping,
                    'rows' => [],
                ];

                if ($best === null || $mapping['recognized_count'] > $best['header_mapping']['recognized_count']) {
                    $best = $candidate;
                }

                if ($mapping['missing_fields'] === [] && $mapping['ambiguous_fields'] === []) {
                    $best = $candidate;
                    break;
                }
            }

            if ($best === null) {
                throw new LeaveImportException('Could not read CSV headers.');
            }

            while (! $file->eof()) {
                $cells = $file->fgetcsv($delimiter, '"', '');

                if ($cells === [null] || $cells === false) {
                    continue;
                }

                $best['rows'][] = array_map(static fn ($value): array => [
                    'raw' => $value === null ? null : (string) $value,
                    'value' => $value === null ? null : (string) $value,
                ], $cells);
            }

            return $best;
        } catch (LeaveImportException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw new LeaveImportException('The CSV file is corrupt or unreadable.', previous: $exception);
        }
    }

    private function detectCsvDelimiter(string $path): string
    {
        $handle = fopen($path, 'rb');
        $line = $handle === false ? '' : (fgets($handle) ?: '');

        if ($handle !== false) {
            fclose($handle);
        }

        $counts = [];

        foreach ([',', ';', "\t"] as $delimiter) {
            $counts[$delimiter] = substr_count($line, $delimiter);
        }

        arsort($counts);

        return array_key_first($counts) ?? ',';
    }

    private function plainValue(mixed $value): mixed
    {
        if ($value === null || $value instanceof \DateTimeInterface) {
            return $value;
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
