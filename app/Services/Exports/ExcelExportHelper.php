<?php

namespace App\Services\Exports;

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class ExcelExportHelper
{
    public static function writeHeaderRow(Worksheet $sheet, array $headers, int $row, string $startColumn = 'A'): void
    {
        $column = 1;

        foreach ($headers as $header) {
            $cell = $startColumn.chr(64 + $column).$row;
            if ($column > 26) {
                $cell = self::columnName($column).$row;
            }

            $sheet->setCellValue($cell, $header);
            $sheet->getStyle($cell)->getFont()->setBold(true);
            $column++;
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
            $sheet->setCellValue('A'.$row, $label);
            $sheet->setCellValue('B'.$row, $value);
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

    public static function downloadPath(Spreadsheet $spreadsheet, string $filename): string
    {
        $path = tempnam(sys_get_temp_dir(), 'export-');
        $writer = \PhpOffice\PhpSpreadsheet\IOFactory::createWriter($spreadsheet, 'Xlsx');
        $writer->save($path);
        $spreadsheet->disconnectWorksheets();

        return $path;
    }
}
