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
    // member_code removed since it's system generated for new temporary members
    $columns = [
        'full_name_am', 'baptismal_name', 
        'phone_primary', 'guardian_name', 'guardian_phone1', 
        'dob_year', 'waiting_since'
    ];
} else {
    $title = 'Permanent Members Sync';
    // member_code kept for existing permanent members but locked
    $lockedColumns = ['member_code']; 
    $columns = [
        'member_code', 'full_name_am', 'student_name', 'father_name', 'grandfather_name', 
        'baptismal_name', 'full_name_en', 'christian_name', 'gender', 'date_of_birth', 'age', 
        'current_section', 'education_level', 'spiritual_education', 'member_type', 'membership_tier', 
        'status', 'phone_primary', 'phone_number', 'alt_phone_number', 'phone_guardian', 
        'guardian_name', 'address', 'city', 'sub_city', 'woreda', 'house_number', 
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
