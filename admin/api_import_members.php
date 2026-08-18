<?php
/**
 * Smart Member Import API (Excel .xlsx UPSERT)
 * Dynamically parses XLSX and strictly protects existing non-empty DB fields.
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/backend/ethiopian_date.php';

use PhpOffice\PhpSpreadsheet\IOFactory;

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method.']);
    exit;
}

if (!isset($_FILES['import_file']) || $_FILES['import_file']['error'] !== UPLOAD_ERR_OK) {
    echo json_encode(['success' => false, 'message' => 'File upload failed.']);
    exit;
}

$file = $_FILES['import_file']['tmp_name'];
$fileName = $_FILES['import_file']['name'];

$tier = $_POST['tier'] ?? 'permanent';
if (!in_array($tier, ['temporary', 'permanent'])) {
    $tier = 'permanent';
}

$ext = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
if (!in_array($ext, ['xlsx', 'xls'])) {
    echo json_encode(['success' => false, 'message' => 'Only Excel files (.xlsx, .xls) are supported.']);
    exit;
}

try {
    $spreadsheet = IOFactory::load($file);
    $sheet = $spreadsheet->getActiveSheet();
    $rows = $sheet->toArray(null, true, true, false); // Get rows as arrays, calculate formulas, format data, don't index by col letter
} catch (\Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Error reading Excel file: ' . $e->getMessage()]);
    exit;
}

if (count($rows) < 2) {
    echo json_encode(['success' => false, 'message' => 'Excel file is empty or missing data rows.']);
    exit;
}

$headers = [];
$headerIndex = -1;
foreach ($rows as $index => $row) {
    // Look for recognizable column names to identify the header row
    $rowStr = implode(',', $row);
    if (strpos($rowStr, 'full_name_am') !== false || strpos($rowStr, 'member_code') !== false) {
        $headers = $row;
        $headerIndex = $index;
        break;
    }
}

if (empty($headers)) {
    // Fallback to first row if we can't auto-detect
    $headers = array_shift($rows);
} else {
    // Remove all rows up to and including the header row
    foreach (array_keys($rows) as $idx) {
        if ($idx <= $headerIndex) {
            unset($rows[$idx]);
        }
    }
}

$headers = array_map('trim', $headers);

// Get valid columns from database to prevent SQL errors
$stmtCols = $pdo->query("SHOW COLUMNS FROM members");
$colsInfo = $stmtCols->fetchAll(PDO::FETCH_ASSOC);
$validCols = array_column($colsInfo, 'Field');

$notNullCols = [];
foreach ($colsInfo as $col) {
    if ($col['Null'] === 'NO' && $col['Default'] === null && $col['Extra'] !== 'auto_increment') {
        $notNullCols[] = $col['Field'];
    }
}

$stats = [
    'updated' => 0,
    'inserted' => 0,
    'errors' => 0,
    'error_details' => []
];

try {
    $pdo->beginTransaction();

    foreach ($rows as $row) {
        // Skip completely empty rows
        if (empty(array_filter($row, function($v) { return $v !== null && $v !== ''; }))) continue;

        // Map row to headers
        $rowData = [];
        $dateColumns = ['date_of_birth', 'registered_at', 'waiting_since', 'joined_date'];
        
        foreach ($headers as $index => $col) {
            if (in_array($col, $validCols) && isset($row[$index])) {
                $val = trim((string)$row[$index]);
                
                // If it is a date column and not empty, convert from EC to GC
                if (in_array($col, $dateColumns) && !empty($val)) {
                    $parts = preg_split('/[-\/.]/', $val);
                    if (count($parts) >= 3) {
                        $y = (int)$parts[0];
                        $m = (int)$parts[1];
                        $d = (int)$parts[2];
                        // Validate reasonable EC bounds
                        if ($y > 1900 && $m >= 1 && $m <= 13 && $d >= 1 && $d <= 30) {
                            try {
                                $gcDate = ethiopian_to_gregorian($y, $m, $d);
                                $val = $gcDate->format('Y-m-d');
                            } catch (\Exception $e) {
                                // fallback
                            }
                        }
                    }
                }
                
                $rowData[$col] = $val;
            }
        }

        // Must have at least a name or member_code to do anything meaningful
        if (empty($rowData['full_name_am']) && empty($rowData['member_code'])) {
            $stats['errors']++;
            continue;
        }

        // 1. MATCHING LOGIC
        $existingMember = false;
        if (!empty($rowData['member_code'])) {
            $stmt = $pdo->prepare("SELECT * FROM members WHERE member_code = ? LIMIT 1");
            $stmt->execute([$rowData['member_code']]);
            $existingMember = $stmt->fetch(PDO::FETCH_ASSOC);
        }
        
        if (!$existingMember && !empty($rowData['full_name_am']) && !empty($rowData['phone_number'])) {
            $stmt = $pdo->prepare("SELECT * FROM members WHERE full_name_am = ? AND phone_number = ? LIMIT 1");
            $stmt->execute([$rowData['full_name_am'], $rowData['phone_number']]);
            $existingMember = $stmt->fetch(PDO::FETCH_ASSOC);
        }

        if ($existingMember) {
            // 2. UPDATE LOGIC (Strict Protection)
            $updateFields = [];
            $updateValues = [];
            
            foreach ($rowData as $col => $val) {
                if ($col === 'id' || $col === 'member_code') continue;
                if ($val === '') continue; // Empty cell in excel means skip

                // STRICT RULE: If the DB currently has a non-empty value, DO NOT OVERWRITE
                $dbVal = $existingMember[$col];
                if ($dbVal === null || trim((string)$dbVal) === '') {
                    $updateFields[] = "`$col` = ?";
                    $updateValues[] = $val;
                }
            }

            if (!empty($updateFields)) {
                $updateValues[] = $existingMember['id'];
                $sql = "UPDATE members SET " . implode(', ', $updateFields) . " WHERE id = ?";
                $stmt = $pdo->prepare($sql);
                $stmt->execute($updateValues);
                $stats['updated']++;
            }
        } else {
            // 3. INSERT LOGIC
            unset($rowData['id']); 
            unset($rowData['member_code']); // Force system generation
            
            // Assign membership_tier based on the template used
            if (empty($rowData['membership_tier'])) {
                $rowData['membership_tier'] = $tier;
            }
            
            // Smart Name Splitting: if full_name_am is present, derive sub-fields
            if (!empty($rowData['full_name_am'])) {
                $nameParts = preg_split('/\s+/', trim($rowData['full_name_am']), 3);
                if (empty($rowData['student_name'])) {
                    $rowData['student_name'] = $nameParts[0] ?? '';
                }
                if (empty($rowData['father_name'])) {
                    $rowData['father_name'] = $nameParts[1] ?? '';
                }
                if (empty($rowData['grandfather_name']) && isset($nameParts[2])) {
                    $rowData['grandfather_name'] = $nameParts[2];
                }
            }

            // Graceful fallback for ALL NOT NULL columns — never crash on missing data
            foreach ($notNullCols as $reqCol) {
                if (!isset($rowData[$reqCol]) || $rowData[$reqCol] === null) {
                    $rowData[$reqCol] = ''; 
                }
            }

            $cols = array_keys($rowData);
            $vals = array_values($rowData);
            
            $placeholders = str_repeat('?,', count($cols) - 1) . '?';
            $sql = "INSERT INTO members (`" . implode('`,`', $cols) . "`) VALUES ($placeholders)";
            
            $stmt = $pdo->prepare($sql);
            $stmt->execute($vals);
            $stats['inserted']++;
        }
    }
    
    $pdo->commit();
    echo json_encode([
        'success' => true, 
        'message' => "Process complete! Inserted: {$stats['inserted']}, Updated: {$stats['updated']}, Errors: {$stats['errors']}"
    ]);

} catch (Exception $e) {
    $pdo->rollBack();
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}
exit;
