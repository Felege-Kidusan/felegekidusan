<?php
/**
 * Smart Member Import API (Excel .xlsx UPSERT)
 * - CSRF + role gated
 * - Human headers AND legacy snake_case headers
 * - Filled Excel cells update the member. Blank cells are left unchanged.
 * - Member code is never overwritten. Class Code enrolls/transfers in the active year.
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/backend/ethiopian_date.php';
require_once __DIR__ . '/backend/workflow.php';
require_once __DIR__ . '/backend/services/ExcelColumnMap.php';
require_once __DIR__ . '/backend/services/EnrollmentService.php';

use PhpOffice\PhpSpreadsheet\IOFactory;
use App\Services\ExcelColumnMap;
use App\Services\EnrollmentService;

header('Content-Type: application/json; charset=utf-8');

function import_json($data, $code = 200) {
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    import_json(['success' => false, 'message' => 'Invalid request method.'], 405);
}

$role = $_SESSION['admin_role'] ?? '';
if (empty($_SESSION['admin_id']) || !in_array($role, ['super_admin', 'school_admin', 'hr_dept'], true)) {
    import_json(['success' => false, 'message' => 'Access denied.'], 403);
}

$csrf = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
if (!function_exists('validateCsrf') || !validateCsrf($csrf)) {
    import_json(['success' => false, 'message' => 'Security token expired. Please refresh and try again.'], 403);
}

if (!isset($_FILES['import_file']) || $_FILES['import_file']['error'] !== UPLOAD_ERR_OK) {
    import_json(['success' => false, 'message' => 'File upload failed.']);
}

$file = $_FILES['import_file'];
$tmpPath = (string)($file['tmp_name'] ?? '');
$actualSize = $tmpPath !== '' ? @filesize($tmpPath) : false;
if ($tmpPath === '' || !is_uploaded_file($tmpPath) || $actualSize === false || $actualSize <= 0) {
    import_json(['success' => false, 'message' => 'The uploaded workbook could not be verified.']);
}
if ($actualSize > 5 * 1024 * 1024) {
    import_json(['success' => false, 'message' => 'File too large (max 5 MB).']);
}

$fileName = $file['name'] ?? '';
$ext = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
if ($ext !== 'xlsx') {
    import_json(['success' => false, 'message' => 'Only Excel files (.xlsx) are supported.']);
}

$finfo = function_exists('finfo_open') ? finfo_open(FILEINFO_MIME_TYPE) : false;
if ($finfo) {
    $mime = finfo_file($finfo, $tmpPath);
    finfo_close($finfo);
    $okMime = [
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'application/octet-stream',
        'application/zip',
    ];
    if ($mime && !in_array($mime, $okMime, true)) {
        import_json(['success' => false, 'message' => 'File does not look like a valid Excel workbook.']);
    }
}

// XLSX files are ZIP containers. Bound expanded data before PhpSpreadsheet
// parses it to prevent small compressed uploads from exhausting server memory.
if (!class_exists('ZipArchive')) {
    import_json(['success' => false, 'message' => 'Excel validation is unavailable on this server.'], 503);
}
$zip = new ZipArchive();
if ($zip->open($tmpPath) !== true || $zip->locateName('[Content_Types].xml') === false || $zip->locateName('xl/workbook.xml') === false) {
    if ($zip->status === ZipArchive::ER_OK) $zip->close();
    import_json(['success' => false, 'message' => 'File is not a valid XLSX workbook.']);
}
$expandedBytes = 0;
if ($zip->numFiles > 5000) {
    $zip->close();
    import_json(['success' => false, 'message' => 'Workbook contains too many internal files.']);
}
for ($i = 0; $i < $zip->numFiles; $i++) {
    $stat = $zip->statIndex($i);
    $entrySize = (int)($stat['size'] ?? 0);
    $expandedBytes += $entrySize;
    if ($entrySize > 20 * 1024 * 1024 || $expandedBytes > 50 * 1024 * 1024) {
        $zip->close();
        import_json(['success' => false, 'message' => 'Workbook expands beyond the safe processing limit.']);
    }
}
$zip->close();

$tier = $_POST['tier'] ?? 'permanent';
if (!in_array($tier, ['temporary', 'permanent'], true)) {
    $tier = 'permanent';
}

try {
    $spreadsheet = IOFactory::load($file['tmp_name']);
    $sheet = $spreadsheet->getActiveSheet();
    $rows = $sheet->toArray(null, true, true, false);
} catch (\Exception $e) {
    import_json(['success' => false, 'message' => 'Error reading Excel file.']);
}

if (count($rows) < 2) {
    import_json(['success' => false, 'message' => 'Excel file is empty or missing data rows.']);
}

$headers = [];
$headerIndex = -1;
foreach ($rows as $index => $row) {
    $mapped = 0;
    foreach ($row as $cell) {
        if (ExcelColumnMap::resolveHeader((string)$cell) !== null) {
            $mapped++;
        }
    }
    if ($mapped >= 2) {
        $headers = $row;
        $headerIndex = $index;
        break;
    }
}

if (empty($headers)) {
    $headers = array_shift($rows);
} else {
    foreach (array_keys($rows) as $idx) {
        if ($idx <= $headerIndex) {
            unset($rows[$idx]);
        }
    }
}

if (count($rows) > 2000) {
    import_json(['success' => false, 'message' => 'Too many rows (max 2,000). Split the file and try again.']);
}

$resolvedHeaders = [];
foreach ($headers as $i => $raw) {
    $key = ExcelColumnMap::resolveHeader((string)$raw);
    $resolvedHeaders[$i] = $key;
}

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
    'enrolled' => 0,
    'enroll_skipped' => 0,
    'errors' => 0,
    'error_details' => [],
];
$enrollJobs = [];

if (!isset($conn) || !($conn instanceof mysqli) || $conn->connect_error) {
    import_json(['success' => false, 'message' => 'Database connection error.'], 503);
}

try {
    $pdo->beginTransaction();

    foreach ($rows as $rowNum => $row) {
        if (empty(array_filter($row, function ($v) { return $v !== null && $v !== ''; }))) {
            continue;
        }

        $rowData = [];
        $classLabel = '';

        foreach ($resolvedHeaders as $index => $key) {
            if ($key === null || !isset($row[$index])) {
                continue;
            }
            $val = trim((string)$row[$index]);

            if (ExcelColumnMap::isClassColumn($key)) {
                if ($val !== '') {
                    $classLabel = $val;
                }
                continue;
            }
            if (ExcelColumnMap::isVirtual($key)) {
                continue;
            }
            if (!in_array($key, $validCols, true)) {
                continue;
            }

            if (ExcelColumnMap::isDateColumn($key) && $val !== '') {
                $parts = preg_split('/[-\/.]/', $val);
                if (count($parts) >= 3) {
                    $y = (int)$parts[0];
                    $m = (int)$parts[1];
                    $d = (int)$parts[2];
                    if ($y > 1900 && $m >= 1 && $m <= 13 && $d >= 1 && $d <= 30) {
                        try {
                            $gcDate = ethiopian_to_gregorian($y, $m, $d);
                            $val = $gcDate->format('Y-m-d');
                        } catch (\Exception $e) {
                            // keep original
                        }
                    }
                }
            }

            $rowData[$key] = $val;
        }

        if (empty($rowData['full_name_am']) && empty($rowData['member_code'])) {
            $stats['errors']++;
            $stats['error_details'][] = 'Row ' . ($rowNum + 1) . ': missing name and member code.';
            continue;
        }

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

        $memberId = 0;

        if ($existingMember) {
            $updateFields = [];
            $updateValues = [];

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

            foreach ($rowData as $col => $val) {
                if ($col === 'id' || $col === 'member_code') {
                    continue;
                }
                if ($val === '') {
                    continue;
                }
                $dbVal = trim((string)($existingMember[$col] ?? ''));
                if ($dbVal === $val) {
                    continue;
                }
                $updateFields[] = "`$col` = ?";
                $updateValues[] = $val;
            }

            if (!empty($updateFields)) {
                $updateValues[] = $existingMember['id'];
                $sql = "UPDATE members SET " . implode(', ', $updateFields) . " WHERE id = ?";
                $stmt = $pdo->prepare($sql);
                $stmt->execute($updateValues);
                $stats['updated']++;
            }
            $memberId = (int)$existingMember['id'];
        } else {
            unset($rowData['id'], $rowData['member_code']);

            if (empty($rowData['membership_tier'])) {
                $rowData['membership_tier'] = $tier;
            }

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

            foreach ($notNullCols as $reqCol) {
                if (!isset($rowData[$reqCol]) || $rowData[$reqCol] === null) {
                    $rowData[$reqCol] = '';
                }
            }

            $rowTier = $rowData['membership_tier'] ?? $tier;
            if ($rowTier !== 'temporary') {
                // Use the row's own age group when the sheet provides one;
                // otherwise the member is saved with a pending code instead
                // of being guessed into the ህጻናት (A) sequence.
                $rowData['member_code'] = EnrollmentService::generateMemberCode(
                    $conn,
                    isset($rowData['age_group']) ? (string)$rowData['age_group'] : null
                );
            }

            $cols = array_keys($rowData);
            $vals = array_values($rowData);
            $placeholders = str_repeat('?,', count($cols) - 1) . '?';
            $sql = "INSERT INTO members (`" . implode('`,`', $cols) . "`) VALUES ($placeholders)";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($vals);
            $memberId = (int)$pdo->lastInsertId();
            $stats['inserted']++;
        }

        if ($memberId > 0 && $classLabel !== '') {
            $enrollJobs[] = ['member_id' => $memberId, 'class_label' => $classLabel, 'row' => $rowNum + 1];
        }
    }

    $pdo->commit();

    foreach ($enrollJobs as $job) {
        $enr = EnrollmentService::enrollByLabel(
            $conn,
            (int)$job['member_id'],
            (string)$job['class_label'],
            null,
            (int)$_SESSION['admin_id']
        );
        if (($enr['status'] ?? '') === 'success') {
            if (!empty($enr['skipped'])) {
                $stats['enroll_skipped']++;
            } else {
                $stats['enrolled']++;
            }
        } else {
            $stats['errors']++;
            $stats['error_details'][] = 'Row ' . $job['row'] . ': ' . ($enr['message'] ?? 'enrollment failed');
        }
    }

    $msg = "Sync complete. Inserted {$stats['inserted']}, updated {$stats['updated']}";
    if ($stats['enrolled']) {
        $msg .= ", enrolled {$stats['enrolled']}";
    }
    if ($stats['enroll_skipped']) {
        $msg .= ", Already enrolled: {$stats['enroll_skipped']}";
    }
    if ($stats['errors']) {
        $msg .= ", Errors: {$stats['errors']}";
    }

    import_json([
        'success' => true,
        'message' => $msg,
        'stats' => $stats,
    ]);
} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('api_import_members: ' . $e->getMessage());
    import_json(['success' => false, 'message' => 'Database error. No changes were saved.']);
}
