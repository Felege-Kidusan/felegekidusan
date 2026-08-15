<?php
/**
 * Smart Member Export API - Excel (.xlsx)
 * Uses PhpSpreadsheet to generate a beautifully styled template.
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/access_control.php';
require_once __DIR__ . '/../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Alignment;

// Security check
if (!isset($_SESSION['role']) || !in_array($_SESSION['role'], ['super_admin', 'info_dept', 'hr_dept'])) {
    die("Access Denied");
}

$tier = $_GET['tier'] ?? 'permanent';
if (!in_array($tier, ['temporary', 'permanent'])) {
    $tier = 'permanent';
}

$spreadsheet = new Spreadsheet();
$sheet = $spreadsheet->getActiveSheet();

// Define columns based on tier
if ($tier === 'temporary') {
    $title = 'Temporary Members Sync';
    $headerColor = 'FFD977'; // Amber-ish
    $columns = [
        'member_code', 'full_name_am', 'baptismal_name', 
        'phone_primary', 'guardian_name', 'guardian_phone1', 
        'dob_year', 'waiting_since'
    ];
} else {
    $title = 'Permanent Members Sync';
    $headerColor = 'A7F3D0'; // Emerald-ish
    $columns = [
        'member_code', 'full_name_am', 'student_name', 'father_name', 'grandfather_name', 
        'baptismal_name', 'full_name_en', 'christian_name', 'gender', 'date_of_birth', 'age', 
        'current_section', 'education_level', 'spiritual_education', 'member_type', 'membership_tier', 
        'status', 'phone_primary', 'phone_number', 'alt_phone_number', 'phone_guardian', 
        'guardian_name', 'address', 'city', 'sub_city', 'woreda', 'house_number', 
        'work_profession', 'registered_at'
    ];
}

$sheet->setTitle(ucfirst($tier) . ' Members');

// 1. Write Headers
$colIndex = 1;
foreach ($columns as $colName) {
    $cell = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($colIndex) . '1';
    $sheet->setCellValue($cell, $colName);
    $colIndex++;
}

// 2. Fetch Data
try {
    $stmt = $pdo->prepare("SELECT * FROM members WHERE membership_tier = ? ORDER BY id DESC");
    $stmt->execute([$tier]);
    
    $rowIndex = 2;
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $colIndex = 1;
        foreach ($columns as $colName) {
            $val = isset($row[$colName]) ? $row[$colName] : '';
            // Force string so phone numbers don't lose leading zeros
            $cell = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($colIndex) . $rowIndex;
            $sheet->setCellValueExplicit($cell, $val, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
            $colIndex++;
        }
        $rowIndex++;
    }
} catch (PDOException $e) {
    die("Database error: " . $e->getMessage());
}

// 3. Styling the Sheet
$lastColumnString = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex(count($columns));

// Freeze top row
$sheet->freezePane('A2');

// Style the header row
$headerRange = 'A1:' . $lastColumnString . '1';
$sheet->getStyle($headerRange)->applyFromArray([
    'font' => [
        'bold' => true,
        'size' => 12,
    ],
    'fill' => [
        'fillType' => Fill::FILL_SOLID,
        'startColor' => ['argb' => $headerColor],
    ],
    'borders' => [
        'allBorders' => [
            'borderStyle' => Border::BORDER_THIN,
            'color' => ['argb' => 'FF000000'],
        ],
    ],
    'alignment' => [
        'horizontal' => Alignment::HORIZONTAL_CENTER,
        'vertical' => Alignment::VERTICAL_CENTER,
    ],
]);

// Auto-size columns
for ($i = 1; $i <= count($columns); $i++) {
    $colString = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($i);
    $sheet->getColumnDimension($colString)->setAutoSize(true);
}

// 4. Output the file
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="sundayschool_' . $tier . '_members_' . date('Y-m-d') . '.xlsx"');
header('Cache-Control: max-age=0');

$writer = new Xlsx($spreadsheet);
$writer->save('php://output');
exit;
