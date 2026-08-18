<?php
/**
 * Excel Export Service
 * Handles generation and styling of Excel files following professional UI standards.
 * Implements column protection to prevent tampering with system-generated IDs.
 */

namespace App\Services;

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Color;
use PhpOffice\PhpSpreadsheet\Style\Protection;

class ExcelExportService {
    
    /**
     * Generates and downloads a styled Excel file.
     * 
     * @param string $title Document and Sheet Title
     * @param array $columns Array of column names
     * @param array $data Array of associative arrays containing data rows
     * @param string $filename Output filename
     * @param array $lockedColumns Array of column names that should be locked
     */
    public static function export(string $title, array $columns, array $data, string $filename, array $lockedColumns = []) {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        
        // Setup Sheet Protection
        $sheet->getProtection()->setSheet(true); // Lock entire sheet by default
        
        $sheet->setTitle(substr($title, 0, 31)); // Max length for sheet title is 31 chars
        
        $lastColumnString = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex(count($columns));
        
        // 1. Row 1: Main Title
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
        
        // 2. Row 2: Spacer
        $sheet->mergeCells("A2:{$lastColumnString}2");
        $sheet->getRowDimension(2)->setRowHeight(24.75);
        $sheet->getStyle("A2:{$lastColumnString}2")->applyFromArray([
            'fill' => [
                'fillType' => Fill::FILL_SOLID,
                'startColor' => ['argb' => 'FF3B0909'],
            ]
        ]);
        
        // 3. Row 3: Column Headers
        $colIndex = 1;
        $lockedColumnIndices = [];
        
        foreach ($columns as $colName) {
            $cell = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($colIndex) . '3';
            $headerText = $headerLabels[$colName] ?? $colName;
            $sheet->setCellValue($cell, $headerText);
            
            if (in_array($colName, $lockedColumns)) {
                $lockedColumnIndices[] = $colIndex;
            }
            $colIndex++;
        }
        
        $sheet->getRowDimension(3)->setRowHeight(19.5);
        $sheet->getStyle("A3:{$lastColumnString}3")->applyFromArray([
            'font' => [
                'name' => 'Calibri',
                'bold' => true,
                'color' => ['argb' => 'FFDBAD00'], // Gold text
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
        
        // 4. Row 4+: Fetch and Write Data
        $rowIndex = 4;
        foreach ($data as $row) {
            $colIndex = 1;
            foreach ($columns as $colName) {
                $val = isset($row[$colName]) ? $row[$colName] : '';
                $cell = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($colIndex) . $rowIndex;
                $sheet->setCellValueExplicit($cell, $val, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
                
                // Unlock cell if it's not in the locked columns list
                if (!in_array($colIndex, $lockedColumnIndices)) {
                    $sheet->getStyle($cell)->getProtection()->setLocked(Protection::PROTECTION_UNPROTECTED);
                }
                
                $colIndex++;
            }
            
            // Set row height for better UI
            $sheet->getRowDimension($rowIndex)->setRowHeight(19.5);
            $rowIndex++;
        }
        
        // Freeze top rows (Row 4 is where scrolling starts)
        $sheet->freezePane('A4');
        
        // Set comfortable explicit widths (setAutoSize often fails for Amharic/UTF-8)
        for ($i = 1; $i <= count($columns); $i++) {
            $colString = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($i);
            $colName = strtolower($columns[$i - 1]);
            
            $width = 25; // Default comfortable width
            if (strpos($colName, 'name') !== false) {
                $width = 35; // Wider for names
            } elseif (strpos($colName, 'date') !== false || strpos($colName, 'dob') !== false || strpos($colName, 'registered') !== false || strpos($colName, 'since') !== false) {
                $width = 20; // Dates
            } elseif (strpos($colName, 'phone') !== false) {
                $width = 22; // Phone numbers
            } elseif (strpos($colName, 'code') !== false || strpos($colName, 'id') !== false) {
                $width = 20; // IDs
            }
            
            $sheet->getColumnDimension($colString)->setAutoSize(false);
            $sheet->getColumnDimension($colString)->setWidth($width);
        }
        
        // Style all data rows (Vertical center, light bottom border)
        if (count($data) > 0) {
            $dataRange = 'A4:' . $lastColumnString . ($rowIndex - 1);
            $sheet->getStyle($dataRange)->applyFromArray([
                'alignment' => [
                    'vertical' => Alignment::VERTICAL_CENTER,
                ]
            ]);
            
            // Apply a slight grey background to locked columns to indicate they are read-only
            foreach ($lockedColumnIndices as $lockedColIndex) {
                $lockedColLetter = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($lockedColIndex);
                $lockedColRange = $lockedColLetter . '4:' . $lockedColLetter . ($rowIndex - 1);
                $sheet->getStyle($lockedColRange)->applyFromArray([
                    'fill' => [
                        'fillType' => Fill::FILL_SOLID,
                        'startColor' => ['argb' => 'FFF3F4F6'], // Light grey
                    ],
                    'font' => [
                        'color' => ['argb' => 'FF6B7280'], // Dark grey text
                    ]
                ]);
            }
        }
        
        // Unlock empty rows below data so users can add new entries
        // We'll unlock 500 rows below the existing data for new entries
        $startEmptyRow = count($data) > 0 ? count($data) + 4 : 4;
        $endEmptyRow = $startEmptyRow + 500;
        
        for ($r = $startEmptyRow; $r <= $endEmptyRow; $r++) {
            for ($c = 1; $c <= count($columns); $c++) {
                if (!in_array($c, $lockedColumnIndices)) {
                    $cell = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($c) . $r;
                    $sheet->getStyle($cell)->getProtection()->setLocked(Protection::PROTECTION_UNPROTECTED);
                }
            }
        }
        
        // 4. Output the file
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Pragma: no-cache');
        
        $writer = new Xlsx($spreadsheet);
        $writer->save('php://output');
        exit;
    }
}
