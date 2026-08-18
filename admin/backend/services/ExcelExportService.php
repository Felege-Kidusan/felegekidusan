<?php
/**
 * Excel Export Service
 * Styled workbooks, locked system IDs, optional class dropdown.
 */

namespace App\Services;

use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Cell\DataValidation;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Protection;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

class ExcelExportService
{
    /**
     * @param string[] $columns Internal column keys in display order
     * @param array<string,string> $headerLabels key => display header
     * @param string[] $classOptions Live class names for the Class dropdown
     */
    public static function export(
        string $title,
        array $columns,
        array $data,
        string $filename,
        array $lockedColumns = [],
        array $headerLabels = [],
        array $classOptions = []
    ) {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();

        $sheet->getProtection()->setSheet(true);
        $sheet->setTitle(substr($title, 0, 31));

        $lastColumnString = Coordinate::stringFromColumnIndex(count($columns));

        $sheet->mergeCells("A1:{$lastColumnString}1");
        $sheet->setCellValue('A1', 'ፈለገ ቅዱሳን ሰንበት ትምህርት ቤት');
        $sheet->getRowDimension(1)->setRowHeight(34.5);
        $sheet->getStyle("A1:{$lastColumnString}1")->applyFromArray([
            'font' => [
                'name' => 'Calibri',
                'bold' => true,
                'size' => 22,
                'color' => ['argb' => 'FFFFFFFF'],
            ],
            'fill' => [
                'fillType' => Fill::FILL_SOLID,
                'startColor' => ['argb' => 'FF3B0909'],
            ],
            'alignment' => [
                'horizontal' => Alignment::HORIZONTAL_CENTER,
                'vertical' => Alignment::VERTICAL_CENTER,
            ],
        ]);

        $sheet->mergeCells("A2:{$lastColumnString}2");
        $sheet->getRowDimension(2)->setRowHeight(24.75);
        $sheet->getStyle("A2:{$lastColumnString}2")->applyFromArray([
            'fill' => [
                'fillType' => Fill::FILL_SOLID,
                'startColor' => ['argb' => 'FF3B0909'],
            ],
        ]);

        $colIndex = 1;
        $lockedColumnIndices = [];
        $classColIndex = 0;

        foreach ($columns as $colName) {
            $cell = Coordinate::stringFromColumnIndex($colIndex) . '3';
            $headerText = $headerLabels[$colName] ?? $colName;
            $sheet->setCellValue($cell, $headerText);

            if (in_array($colName, $lockedColumns, true)) {
                $lockedColumnIndices[] = $colIndex;
            }
            if ($colName === 'class') {
                $classColIndex = $colIndex;
            }
            $colIndex++;
        }

        $sheet->getRowDimension(3)->setRowHeight(19.5);
        $sheet->getStyle("A3:{$lastColumnString}3")->applyFromArray([
            'font' => [
                'name' => 'Calibri',
                'bold' => true,
                'color' => ['argb' => 'FFDBAD00'],
            ],
            'fill' => [
                'fillType' => Fill::FILL_SOLID,
                'startColor' => ['argb' => 'FF3B0909'],
            ],
            'borders' => [
                'top' => [
                    'borderStyle' => Border::BORDER_THIN,
                    'color' => ['argb' => 'FF000000'],
                ],
                'bottom' => [
                    'borderStyle' => Border::BORDER_THIN,
                    'color' => ['argb' => 'FF000000'],
                ],
            ],
        ]);

        $rowIndex = 4;
        foreach ($data as $row) {
            $colIndex = 1;
            foreach ($columns as $colName) {
                $val = isset($row[$colName]) ? $row[$colName] : '';
                $cell = Coordinate::stringFromColumnIndex($colIndex) . $rowIndex;
                $sheet->setCellValueExplicit($cell, $val, DataType::TYPE_STRING);

                if (!in_array($colIndex, $lockedColumnIndices, true)) {
                    $sheet->getStyle($cell)->getProtection()->setLocked(Protection::PROTECTION_UNPROTECTED);
                }

                $colIndex++;
            }

            $sheet->getRowDimension($rowIndex)->setRowHeight(19.5);
            $rowIndex++;
        }

        $sheet->freezePane('A4');

        for ($i = 1; $i <= count($columns); $i++) {
            $colString = Coordinate::stringFromColumnIndex($i);
            $colName = strtolower((string)$columns[$i - 1]);

            $width = 25;
            if ($colName === 'class' || strpos($colName, 'name') !== false) {
                $width = 35;
            } elseif (strpos($colName, 'date') !== false || strpos($colName, 'dob') !== false || strpos($colName, 'registered') !== false || strpos($colName, 'since') !== false) {
                $width = 20;
            } elseif (strpos($colName, 'phone') !== false) {
                $width = 22;
            } elseif (strpos($colName, 'code') !== false || strpos($colName, 'id') !== false) {
                $width = 20;
            }

            $sheet->getColumnDimension($colString)->setAutoSize(false);
            $sheet->getColumnDimension($colString)->setWidth($width);
        }

        if (count($data) > 0) {
            $dataRange = 'A4:' . $lastColumnString . ($rowIndex - 1);
            $sheet->getStyle($dataRange)->applyFromArray([
                'alignment' => [
                    'vertical' => Alignment::VERTICAL_CENTER,
                ],
            ]);

            foreach ($lockedColumnIndices as $lockedColIndex) {
                $lockedColLetter = Coordinate::stringFromColumnIndex($lockedColIndex);
                $lockedColRange = $lockedColLetter . '4:' . $lockedColLetter . ($rowIndex - 1);
                $sheet->getStyle($lockedColRange)->applyFromArray([
                    'fill' => [
                        'fillType' => Fill::FILL_SOLID,
                        'startColor' => ['argb' => 'FFF3F4F6'],
                    ],
                    'font' => [
                        'color' => ['argb' => 'FF6B7280'],
                    ],
                ]);
            }
        }

        $startEmptyRow = count($data) > 0 ? count($data) + 4 : 4;
        $endEmptyRow = $startEmptyRow + 500;

        for ($r = $startEmptyRow; $r <= $endEmptyRow; $r++) {
            for ($c = 1; $c <= count($columns); $c++) {
                if (!in_array($c, $lockedColumnIndices, true)) {
                    $cell = Coordinate::stringFromColumnIndex($c) . $r;
                    $sheet->getStyle($cell)->getProtection()->setLocked(Protection::PROTECTION_UNPROTECTED);
                }
            }
        }

        if ($classColIndex > 0 && !empty($classOptions)) {
            self::attachClassDropdown($spreadsheet, $sheet, $classColIndex, $endEmptyRow, $classOptions);
        }

        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Pragma: no-cache');

        $writer = new Xlsx($spreadsheet);
        $writer->save('php://output');
        exit;
    }

    /**
     * Hidden _Classes sheet + data validation on the Class column.
     */
    private static function attachClassDropdown(
        Spreadsheet $spreadsheet,
        Worksheet $sheet,
        int $classColIndex,
        int $endRow,
        array $classOptions
    ) {
        $names = [];
        foreach ($classOptions as $opt) {
            $name = trim((string)$opt);
            if ($name !== '' && !in_array($name, $names, true)) {
                $names[] = $name;
            }
        }
        if (empty($names)) {
            return;
        }

        $hidden = $spreadsheet->createSheet();
        $hidden->setTitle('_Classes');
        $hidden->setSheetState(Worksheet::SHEETSTATE_HIDDEN);

        $i = 1;
        foreach ($names as $name) {
            $hidden->setCellValueExplicit('A' . $i, $name, DataType::TYPE_STRING);
            $i++;
        }
        $last = $i - 1;

        $colLetter = Coordinate::stringFromColumnIndex($classColIndex);
        $range = $colLetter . '4:' . $colLetter . $endRow;

        $dv = new DataValidation();
        $dv->setType(DataValidation::TYPE_LIST);
        $dv->setErrorStyle(DataValidation::STYLE_INFORMATION);
        $dv->setAllowBlank(true);
        $dv->setShowInputMessage(false);
        $dv->setShowErrorMessage(true);
        $dv->setShowDropDown(true);
        $dv->setErrorTitle('Class');
        $dv->setError('Please pick a class from the list.');
        $dv->setFormula1('_Classes!$A$1:$A$' . $last);

        $sheet->setDataValidation($range, $dv);
        $spreadsheet->setActiveSheetIndex(0);
    }
}
