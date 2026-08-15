<?php
/**
 * Smart Member Import API (CSV UPSERT)
 * Dynamically parses CSV and strictly protects existing non-empty DB fields.
 */
require_once __DIR__ . '/config.php';

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

$ext = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
if ($ext !== 'csv') {
    echo json_encode(['success' => false, 'message' => 'Only CSV files are supported. Please export as CSV.']);
    exit;
}

$handle = fopen($file, "r");
if ($handle === false) {
    echo json_encode(['success' => false, 'message' => 'Could not open uploaded file.']);
    exit;
}

// Check and remove BOM if present
$bom = fread($handle, 3);
if ($bom === b"\xEF\xBB\xBF") {
    // BOM removed, proceed
} else {
    rewind($handle); // No BOM, go back to start
}

$headers = fgetcsv($handle, 10000, ",");
if (!$headers) {
    echo json_encode(['success' => false, 'message' => 'CSV file is empty or invalid.']);
    exit;
}

// Clean headers
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

    while (($row = fgetcsv($handle, 10000, ",")) !== false) {
        // Skip empty rows
        if (empty(array_filter($row))) continue;

        // Map row to headers
        $rowData = [];
        foreach ($headers as $index => $col) {
            if (in_array($col, $validCols) && isset($row[$index])) {
                $rowData[$col] = trim($row[$index]);
            }
        }

        // Must have at least a name to do anything meaningful
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
                // Skip the ID column
                if ($col === 'id') continue;
                
                // If the CSV value is empty, do nothing
                if ($val === '') continue;

                // STRICT RULE: If the DB currently has a non-empty value, DO NOT OVERWRITE
                $dbVal = $existingMember[$col];
                if ($dbVal === null || trim((string)$dbVal) === '') {
                    // Safe to update
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
            unset($rowData['id']); // never insert custom ID
            
            // Generate auto member code if missing and it's a permanent or direct registration
            // (Assuming you have logic for this, or leave NULL to auto-assign later)

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

fclose($handle);
exit;
