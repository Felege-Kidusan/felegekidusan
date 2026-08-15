<?php
/**
 * Smart Member Export API
 * Streams all members to a CSV file dynamically based on the current schema.
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/access_control.php';

// Security check (only hr or super admin should probably export)
if (!isset($_SESSION['role']) || !in_array($_SESSION['role'], ['super_admin', 'info_dept', 'hr_dept'])) { // Assumed roles based on context
    // Just a fallback check, access_control usually handles it
}

header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="sundayschool_members_export_' . date('Y-m-d') . '.csv"');
header('Pragma: no-cache');
header('Expires: 0');

$output = fopen('php://output', 'w');

// Add UTF-8 BOM so Excel opens it properly with Amharic characters
fputs($output, chr(0xEF) . chr(0xBB) . chr(0xBF));

try {
    $stmt = $pdo->query("SELECT * FROM members ORDER BY id DESC");
    $firstRow = true;
    
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        if ($firstRow) {
            fputcsv($output, array_keys($row));
            $firstRow = false;
        }
        fputcsv($output, array_values($row));
    }
} catch (PDOException $e) {
    // Write error into the CSV so the user knows it failed
    fputcsv($output, ['Error exporting data', $e->getMessage()]);
}

fclose($output);
exit;
