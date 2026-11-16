<?php

namespace App\Services\Exports;

use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Conditional;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\PageSetup;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class ExcelExportStyler
{
    public static function styleSheet(
        Worksheet $sheet,
        string $titleRange,
        string $headerRange,
        string $lastColumn,
        int $lastRow,
        string $freezePane,
        array $groupHeaders,
        string $numberFormatRange,
        string $nipRange,
        string $lateRange,
        string $earlyRange,
        array $absenceRanges,
        array $widths,
        array $options = [],
    ): void {
        $groupHeaderRow = $options['group_header_row'] ?? 5;
        $headerRow = $options['header_row'] ?? 6;
        $dataStartRow = $options['data_start_row'] ?? 7;
        $labelCells = $options['label_cells'] ?? ['A2', 'C2', 'A3', 'D3'];
        $alignmentRanges = $options['alignment_ranges'] ?? [
            ['range' => 'A'.$dataStartRow.':B'.$lastRow, 'horizontal' => Alignment::HORIZONTAL_CENTER],
            ['range' => 'C'.$dataStartRow.':E'.$lastRow, 'horizontal' => Alignment::HORIZONTAL_LEFT, 'indent' => 1],
        ];
        $repeatRows = $options['repeat_rows'] ?? [$groupHeaderRow, $headerRow];
        $margins = $options['margins'] ?? [0.25, 0.25, 0.25, 0.25];
        $fontLastRow = max($lastRow, $headerRow);

        $sheet->getStyle('A1:'.$lastColumn.$fontLastRow)->getFont()
            ->setName('Calibri')
            ->setSize(11);
        $sheet->getStyle($titleRange)->applyFromArray([
            'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF'], 'size' => 14],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '0F4C5C']],
            'alignment' => [
                'horizontal' => Alignment::HORIZONTAL_LEFT,
                'vertical' => Alignment::VERTICAL_CENTER,
                'indent' => 1,
            ],
        ]);
        $sheet->getRowDimension(1)->setRowHeight(32);
        $sheet->getStyle('A2:'.$lastColumn.'3')->getAlignment()
            ->setVertical(Alignment::VERTICAL_CENTER)
            ->setWrapText(false);
        $sheet->getStyle('B2:'.$lastColumn.'3')->getAlignment()
            ->setHorizontal(Alignment::HORIZONTAL_LEFT);
        foreach ($labelCells as $labelCell) {
            $sheet->getStyle($labelCell)->getFont()->setBold(true);
        }
        $sheet->getRowDimension(2)->setRowHeight(20);
        $sheet->getRowDimension(3)->setRowHeight(20);
        $sheet->getRowDimension(4)->setRowHeight($options['spacer_height'] ?? 8);
        foreach ($options['hidden_rows'] ?? [] as $row) {
            $sheet->getRowDimension($row)->setZeroHeight(true);
        }

        foreach ($groupHeaders as $range => $color) {
            $sheet->getStyle($range)->applyFromArray([
                'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF'], 'size' => 11],
                'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => $color]],
                'alignment' => [
                    'horizontal' => Alignment::HORIZONTAL_CENTER,
                    'vertical' => Alignment::VERTICAL_CENTER,
                ],
            ]);
        }
        $sheet->getRowDimension($groupHeaderRow)->setRowHeight(24);
        $sheet->getStyle($headerRange)->applyFromArray([
            'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF'], 'size' => 11],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '0F4C5C']],
            'alignment' => [
                'horizontal' => Alignment::HORIZONTAL_CENTER,
                'vertical' => Alignment::VERTICAL_CENTER,
                'wrapText' => true,
            ],
            'borders' => [
                'bottom' => ['borderStyle' => Border::BORDER_MEDIUM, 'color' => ['rgb' => '0F4C5C']],
            ],
        ]);
        $sheet->getRowDimension($headerRow)->setRowHeight(36);

        if ($lastRow >= $dataStartRow) {
            $sheet->getStyle('A'.$dataStartRow.':'.$lastColumn.$lastRow)->applyFromArray([
                'font' => ['name' => 'Calibri', 'size' => 11, 'color' => ['rgb' => '334155']],
                'alignment' => [
                    'vertical' => Alignment::VERTICAL_CENTER,
                    'wrapText' => false,
                ],
                'borders' => [
                    'bottom' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'D9E2E7']],
                ],
            ]);
            for ($row = $dataStartRow; $row <= $lastRow; $row++) {
                $sheet->getRowDimension($row)->setRowHeight(20);
                if ($row % 2 === 0) {
                    $sheet->getStyle('A'.$row.':'.$lastColumn.$row)
                        ->getFill()
                        ->setFillType(Fill::FILL_SOLID)
                        ->getStartColor()
                        ->setRGB('F3F7F9');
                }
            }
            foreach ($alignmentRanges as $alignment) {
                $style = $sheet->getStyle($alignment['range'])->getAlignment()
                    ->setHorizontal($alignment['horizontal'])
                    ->setVertical(Alignment::VERTICAL_CENTER);
                if (isset($alignment['indent'])) {
                    $style->setIndent($alignment['indent']);
                }
            }
            $sheet->getStyle($numberFormatRange)->getNumberFormat()
                ->setFormatCode('#,##0;-#,##0;"-"');
            $sheet->getStyle($numberFormatRange)->getAlignment()
                ->setHorizontal(Alignment::HORIZONTAL_CENTER)
                ->setVertical(Alignment::VERTICAL_CENTER);
        }

        $sheet->getStyle($nipRange)->getNumberFormat()->setFormatCode('@');
        $sheet->getStyle($nipRange)->getAlignment()
            ->setHorizontal(Alignment::HORIZONTAL_LEFT)
            ->setIndent(1);
        if ($lastRow >= $dataStartRow) {
            self::applyThresholdStyle($sheet, $lateRange, '10', Conditional::OPERATOR_GREATERTHANOREQUAL, 'FFF2CC');
            self::applyThresholdStyle($sheet, $earlyRange, '10', Conditional::OPERATOR_GREATERTHANOREQUAL, 'FFF2CC');
            foreach ($absenceRanges as $range) {
                self::applyThresholdStyle($sheet, $range, '0', Conditional::OPERATOR_GREATERTHAN, null, 'C00000');
            }
        }

        $sheet->freezePane($freezePane);
        $sheet->setAutoFilter('A'.$headerRow.':'.$lastColumn.max($lastRow, $headerRow));
        $sheet->setShowGridlines(false);
        $sheet->getPageSetup()
            ->setOrientation(PageSetup::ORIENTATION_LANDSCAPE)
            ->setPaperSize(PageSetup::PAPERSIZE_A4)
            ->setFitToPage(true)
            ->setFitToWidth(1)
            ->setFitToHeight(0)
            ->setRowsToRepeatAtTopByStartAndEnd($repeatRows[0], $repeatRows[1]);
        $sheet->getPageMargins()
            ->setTop($margins[0])
            ->setRight($margins[1])
            ->setLeft($margins[2])
            ->setBottom($margins[3]);
        $sheet->getHeaderFooter()->setOddFooter('&CHalaman &P dari &N');

        foreach ($widths as $column => $width) {
            $sheet->getColumnDimension($column)->setWidth($width);
        }
    }

    private static function applyThresholdStyle(
        Worksheet $sheet,
        string $range,
        string $threshold,
        string $operator,
        ?string $fillColor = null,
        ?string $fontColor = null,
    ): void {
        $conditional = new Conditional;
        $conditional->setConditionType(Conditional::CONDITION_CELLIS);
        $conditional->setOperatorType($operator);
        $conditional->addCondition($threshold);
        if ($fillColor !== null) {
            $conditional->getStyle()->getFill()
                ->setFillType(Fill::FILL_SOLID)
                ->getStartColor()
                ->setRGB($fillColor);
        }
        if ($fontColor !== null) {
            $conditional->getStyle()->getFont()
                ->setBold(true)
                ->getColor()
                ->setRGB($fontColor);
        }

        $styles = $sheet->getStyle($range)->getConditionalStyles();
        $styles[] = $conditional;
        $sheet->getStyle($range)->setConditionalStyles($styles);
    }
}
