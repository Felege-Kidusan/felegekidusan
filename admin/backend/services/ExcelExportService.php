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
        
        // 1. Write Headers
        $colIndex = 1;
        $lockedColumnIndices = [];
        
        foreach ($columns as $colName) {
            $cell = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($colIndex) . '1';
            $sheet->setCellValue($cell, $colName);
            
            if (in_array($colName, $lockedColumns)) {
                $lockedColumnIndices[] = $colIndex;
            }
            $colIndex++;
        }
        
        // 2. Fetch and Write Data
        $rowIndex = 2;
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
            $sheet->getRowDimension($rowIndex)->setRowHeight(22);
            $rowIndex++;
        }
        
        // 3. Styling the Sheet
        $lastColumnString = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex(count($columns));
        
        // Freeze top row
        $sheet->freezePane('A2');
        
        // Style the header row (FKSS Brand Maroon)
        $headerRange = 'A1:' . $lastColumnString . '1';
        $sheet->getStyle($headerRange)->applyFromArray([
            'font' => [
                'bold' => true,
                'size' => 12,
                'color' => ['argb' => 'FFFFFFFF'], // White text
            ],
            'fill' => [
                'fillType' => Fill::FILL_SOLID,
                'startColor' => ['argb' => 'FF5A1212'], // Deep Maroon
            ],
            'borders' => [
                'bottom' => [
                    'borderStyle' => Border::BORDER_MEDIUM,
                    'color' => ['argb' => 'FFD4AF37'], // Gold border bottom
                ],
            ],
            'alignment' => [
                'horizontal' => Alignment::HORIZONTAL_CENTER,
                'vertical' => Alignment::VERTICAL_CENTER,
            ],
        ]);
        
        // Style all data rows (Vertical center, light bottom border)
        if (count($data) > 0) {
            $dataRange = 'A2:' . $lastColumnString . ($rowIndex - 1);
            $sheet->getStyle($dataRange)->applyFromArray([
                'alignment' => [
                    'vertical' => Alignment::VERTICAL_CENTER,
                ],
                'borders' => [
                    'bottom' => [
                        'borderStyle' => Border::BORDER_THIN,
                        'color' => ['argb' => 'FFE5E7EB'], // Light gray border
                    ],
                ]
            ]);
            
            // Apply a slight grey background to locked columns to indicate they are read-only
            foreach ($lockedColumnIndices as $lockedColIndex) {
                $lockedColLetter = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($lockedColIndex);
                $lockedColRange = $lockedColLetter . '2:' . $lockedColLetter . ($rowIndex - 1);
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
        
        $sheet->getRowDimension(1)->setRowHeight(30); // Taller header
        
        // Auto-size columns
        for ($i = 1; $i <= count($columns); $i++) {
            $colString = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($i);
            $sheet->getColumnDimension($colString)->setAutoSize(true);
        }
        
        // Unlock empty rows below data so users can add new entries
        // We'll unlock 500 rows below the existing data for new entries
        $startEmptyRow = count($data) > 0 ? count($data) + 2 : 2;
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
        header('Cache-Control: max-age=0');
        
        $writer = new Xlsx($spreadsheet);
        $writer->save('php://output');
        exit;
    }
}
