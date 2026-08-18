<?php
/**
 * Smart Member Export API - Excel (.xlsx)
 * Uses PhpSpreadsheet to generate a beautifully styled template.
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/access_control.php';
require_once __DIR__ . '/../vendor/autoload.php';

require_once __DIR__ . '/backend/services/ExcelExportService.php';

use App\Services\ExcelExportService;

// Security check
if (!isset($_SESSION['admin_role']) || !in_array($_SESSION['admin_role'], ['super_admin', 'info_dept', 'hr_dept'])) {
    die("Access Denied");
}

$tier = $_GET['tier'] ?? 'permanent';
if (!in_array($tier, ['temporary', 'permanent'])) {
    $tier = 'permanent';
}

$lockedColumns = [];

// Define columns based on tier
if ($tier === 'temporary') {
    $title = 'Temporary Members Sync';
    $columns = [
        'full_name_am', 'baptismal_name', 
        'current_section', 'education_level',
        'phone_primary', 'phone_number',
        'guardian_name', 'guardian_phone1', 
        'waiting_since'
    ];
} else {
    $title = 'Permanent Members Sync';
    $lockedColumns = ['member_code']; 
    $columns = [
        'member_code',
        'full_name_am',          // Single name field — space-separated
        'baptismal_name',        // Christian Name (የክርስትና ስም)
        'current_section',       // Class / Grade — used by Edu Dept
        'education_level',
        'gender', 'date_of_birth', 'age',
        'spiritual_education', 'member_type', 'membership_tier', 
        'status', 
        'phone_primary', 'phone_number', 'alt_phone_number', 'phone_guardian', 
        'guardian_name', 'guardian_phone1', 'guardian_phone2',
        'address', 'city', 'sub_city', 'woreda', 'house_number', 
        'work_profession', 'registered_at'
    ];
}

require_once __DIR__ . '/backend/ethiopian_date.php';

$data = [];
$dateColumns = ['date_of_birth', 'registered_at', 'waiting_since', 'joined_date'];

try {
    $stmt = $pdo->prepare("SELECT * FROM members WHERE membership_tier = ? ORDER BY id DESC");
    $stmt->execute([$tier]);
    
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        // Enforce Strict EC Display for UI/Exports
        foreach ($dateColumns as $dc) {
            if (!empty($row[$dc])) {
                try {
                    $dt = new DateTime($row[$dc], new DateTimeZone('Africa/Addis_Ababa'));
                    $row[$dc] = ethio_date_format($dt, 'Y-m-d');
                } catch (\Exception $e) {
                    // If parsing fails, leave it raw or empty
                }
            }
        }
        $data[] = $row;
    }
} catch (PDOException $e) {
    die("Database error: " . $e->getMessage());
}

$filename = 'sundayschool_' . $tier . '_members_' . date('Y-m-d') . '.xlsx';

ExcelExportService::export($title, $columns, $data, $filename, $lockedColumns);
