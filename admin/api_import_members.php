<?php
/**
 * Smart Member Import API (Excel .xlsx UPSERT)
 * Dynamically parses XLSX and strictly protects existing non-empty DB fields.
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/../vendor/autoload.php';

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

$headers = array_shift($rows); // First row is headers
$headers = array_map('trim', $headers);

// Get valid columns from database to prevent SQL errors
$stmtCols = $pdo->query("SHOW COLUMNS FROM members");
$validCols = $stmtCols->fetchAll(PDO::FETCH_COLUMN);

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
        foreach ($headers as $index => $col) {
            if (in_array($col, $validCols) && isset($row[$index])) {
                $rowData[$col] = trim((string)$row[$index]);
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
                if ($col === 'id') continue;
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
            
            // Assign membership_tier based on the template used (if not explicitly in the Excel file)
            if (empty($rowData['membership_tier'])) {
                $rowData['membership_tier'] = $tier;
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
