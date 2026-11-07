<?php

namespace App\Services\Exports;

use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use RuntimeException;
use Throwable;

class ExcelExportHelper
{
    public static function writeHeaderRow(Worksheet $sheet, array $headers, int $row, string $startColumn = 'A'): void
    {
        $column = Coordinate::columnIndexFromString($startColumn);

        foreach ($headers as $header) {
            $cell = self::columnName($column++).$row;
            self::writeValue($sheet, $cell, $header);
            $sheet->getStyle($cell)->getFont()->setBold(true);
        }
    }

    public static function writeValue(Worksheet $sheet, string $coordinate, mixed $value): void
    {
        if (is_string($value)) {
            $sheet->setCellValueExplicit($coordinate, $value, DataType::TYPE_STRING);

            return;
        }

        $sheet->setCellValue($coordinate, $value);
    }

    public static function writeRow(Worksheet $sheet, int $row, array $values, string $startColumn = 'A'): void
    {
        $column = Coordinate::columnIndexFromString($startColumn);

        foreach (array_values($values) as $value) {
            self::writeValue($sheet, self::columnName($column++).$row, $value);
        }
    }

    public static function columnName(int $index): string
    {
        $result = '';

        while ($index > 0) {
            $index--;
            $result = chr(65 + ($index % 26)).$result;
            $index = intdiv($index, 26);
        }

        return $result;
    }

    public static function writeMeta(Worksheet $sheet, array $meta): void
    {
        $row = 1;
        foreach ($meta as $label => $value) {
            self::writeValue($sheet, 'A'.$row, (string) $label);
            self::writeValue($sheet, 'B'.$row, $value);
            $row++;
        }
    }

    public static function applyTableStyle(Worksheet $sheet, string $startCell, string $endCell): void
    {
        $sheet->freezePane('A'.$this->headerRowFor($startCell));
        $sheet->setAutoFilter($startCell.':'.$endCell);
        $sheet->getStyle($startCell.':'.$endCell)->getAlignment()->setVertical('center');
    }

    private static function headerRowFor(string $startCell): int
    {
        preg_match('/(\d+)$/', $startCell, $matches);

        return (int) ($matches[1] ?? 1);
    }

    public static function downloadPath(Spreadsheet $spreadsheet): string
    {
        $path = tempnam(sys_get_temp_dir(), 'export-');

        if ($path === false) {
            $spreadsheet->disconnectWorksheets();

            throw new RuntimeException('Unable to create a temporary export file.');
        }

        try {
            IOFactory::createWriter($spreadsheet, 'Xlsx')->save($path);

            return $path;
        } catch (Throwable $exception) {
            if (is_file($path) && ! unlink($path)) {
                throw new RuntimeException('Export failed and its temporary file could not be removed.', 0, $exception);
            }

            throw $exception;
        } finally {
            $spreadsheet->disconnectWorksheets();
        }
    }
}
