<?php
/**
 * Smart Member Export API - Excel (.xlsx)
 * Human-readable headers. Class Code / Class Name come from the active-year enrollment.
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/access_control.php';
$_autoload = dirname(__DIR__) . '/vendor/autoload.php';
if (!is_file($_autoload)) {
    http_response_code(500);
    die('Excel library is missing. Run composer install on the server.');
}
require_once $_autoload;
require_once __DIR__ . '/backend/services/ExcelExportService.php';
require_once __DIR__ . '/backend/services/ExcelColumnMap.php';
require_once __DIR__ . '/backend/services/EnrollmentService.php';

use App\Services\ExcelExportService;
use App\Services\ExcelColumnMap;
use App\Services\EnrollmentService;

$role = $_SESSION['admin_role'] ?? '';
if (!in_array($role, ['super_admin', 'school_admin', 'info_dept', 'hr_dept'], true)) {
    http_response_code(403);
    die('Access Denied');
}

$tier = $_GET['tier'] ?? 'permanent';
if (!in_array($tier, ['temporary', 'permanent'], true)) {
    $tier = 'permanent';
}

$columns = ExcelColumnMap::columns($tier);
$lockedColumns = ExcelColumnMap::locked($tier);
$headerLabels = ExcelColumnMap::headersFor($columns);
$title = ($tier === 'temporary') ? 'Temporary Members Sync' : 'Permanent Members Sync';

$classOptions = [];
if (isset($conn) && $conn instanceof mysqli && !$conn->connect_error) {
    try {
        $cr = $conn->query("SELECT class_name FROM classes WHERE is_active = 1 ORDER BY level_order, class_name");
        if ($cr) {
            while ($crow = $cr->fetch_assoc()) {
                if (!empty($crow['class_name'])) {
                    $classOptions[] = $crow['class_name'];
                }
            }
        }
    } catch (Throwable $e) {
        $classOptions = [];
    }
}

require_once __DIR__ . '/backend/ethiopian_date.php';

$year = (isset($conn) && $conn instanceof mysqli) ? EnrollmentService::activeYear($conn) : null;
$yearId = $year ? (int)$year['id'] : 0;

$data = [];
$dateColumns = ['date_of_birth', 'registered_at', 'waiting_since', 'joined_date'];

if (!isset($pdo) || !($pdo instanceof PDO)) {
    http_response_code(500);
    die('Database connection is not available.');
}

try {
    if ($yearId > 0) {
        try {
            $stmt = $pdo->prepare(
                "SELECT m.*,
                        c.class_code AS class_code,
                        c.class_name AS class_name
                 FROM members m
                 LEFT JOIN class_enrollments ce
                        ON ce.member_id = m.id
                       AND ce.status = 'active'
                       AND ce.academic_year_id = ?
                 LEFT JOIN classes c ON c.id = ce.class_id
                 WHERE m.membership_tier = ?
                 ORDER BY m.id DESC"
            );
            $stmt->execute([$yearId, $tier]);
        } catch (PDOException $e) {
            $stmt = $pdo->prepare("SELECT * FROM members WHERE membership_tier = ? ORDER BY id DESC");
            $stmt->execute([$tier]);
        }
    } else {
        $stmt = $pdo->prepare("SELECT * FROM members WHERE membership_tier = ? ORDER BY id DESC");
        $stmt->execute([$tier]);
    }

    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        foreach ($dateColumns as $dc) {
            if (!empty($row[$dc])) {
                try {
                    $dt = new DateTime($row[$dc], new DateTimeZone('Africa/Addis_Ababa'));
                    $row[$dc] = ethio_date_format($dt, 'Y-m-d');
                } catch (\Exception $e) {
                    // leave raw
                }
            }
        }
        if (!empty($row['class_name'])) {
            $row['class'] = $row['class_name'];
        } elseif (!empty($row['class_code'])) {
            $row['class'] = $row['class_code'];
        }
        $data[] = $row;
    }
} catch (PDOException $e) {
    error_log('api_export_members: ' . $e->getMessage());
    die('Database error.');
}

$filename = 'sundayschool_' . $tier . '_members_' . date('Y-m-d') . '.xlsx';

ExcelExportService::export($title, $columns, $data, $filename, $lockedColumns, $headerLabels, $classOptions);
