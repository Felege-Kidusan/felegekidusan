<?php
/**
 * Identity & Codes hub API — Super Admin only.
 *
 * Manages departments, staff positions, and the assignment of positions to
 * members (which drives their code). Follows the same JSON contract and
 * security posture as api_settings.php: session auth, CSRF on POST,
 * prepared statements, audit logging.
 */

header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/backend/services/SecurityAuditService.php';
require_once __DIR__ . '/backend/services/IdentityCodeService.php';
require_once __DIR__ . '/backend/services/MemberTypeService.php';

use App\Services\IdentityCodeService;
use App\Services\MemberTypeService;
use App\Services\SecurityAuditService;

if (empty($_SESSION['admin_id'])) {
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized'], JSON_UNESCAPED_UNICODE);
    exit;
}
if (($_SESSION['admin_role'] ?? '') !== 'super_admin') {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'Super Admin only'], JSON_UNESCAPED_UNICODE);
    exit;
}

$action = is_scalar($_REQUEST['action'] ?? '') ? (string)$_REQUEST['action'] : '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCsrf($_POST['csrf_token'] ?? '')) {
        http_response_code(403);
        echo json_encode(['status' => 'error', 'message' => 'Security token expired. Please refresh.']);
        exit;
    }
}

/** Validate a code segment: 1-4 uppercase A-Z letters only. */
function validateCodeSegment(string $code): bool
{
    return (bool)preg_match('/^[A-Z]{1,4}$/', $code);
}

try {
    switch ($action) {

        /* ── Departments ────────────────────────────────────────────── */

        case 'list_departments':
            $result = $conn->query(
                'SELECT id, code, name_am, name_en, is_active, sort_order
                 FROM departments ORDER BY sort_order ASC, id ASC'
            );
            $rows = [];
            while ($row = $result->fetch_assoc()) {
                $row['is_active'] = (int)$row['is_active'];
                $rows[] = $row;
            }
            $result->free();
            echo json_encode(['status' => 'success', 'departments' => $rows], JSON_UNESCAPED_UNICODE);
            break;

        case 'save_department':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                http_response_code(405); header('Allow: POST'); exit('Method not allowed.');
            }
            $id = filter_var($_POST['id'] ?? 0, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 0;
            $code = strtoupper(trim((string)($_POST['code'] ?? '')));
            $nameAm = trim((string)($_POST['name_am'] ?? ''));
            $nameEn = trim((string)($_POST['name_en'] ?? '')) ?: null;
            $isActive = isset($_POST['is_active']) ? 1 : 0;

            if (!validateCodeSegment($code)) {
                echo json_encode(['status' => 'error', 'message' => 'Department code must be 1-4 uppercase A-Z letters.']); exit;
            }
            if ($nameAm === '') {
                echo json_encode(['status' => 'error', 'message' => 'Amharic name is required.']); exit;
            }

            if ($id > 0) {
                $stmt = $conn->prepare(
                    'UPDATE departments SET code=?, name_am=?, name_en=?, is_active=? WHERE id=?'
                );
                $stmt->bind_param('sssii', $code, $nameAm, $nameEn, $isActive, $id);
            } else {
                $maxSort = $conn->query('SELECT COALESCE(MAX(sort_order),0)+1 AS n FROM departments')->fetch_assoc()['n'];
                $stmt = $conn->prepare(
                    'INSERT INTO departments (code, name_am, name_en, is_active, sort_order) VALUES (?, ?, ?, ?, ?)'
                );
                $stmt->bind_param('sssii', $code, $nameAm, $nameEn, $isActive, $maxSort);
            }

            // PHP >= 8.1 defaults mysqli to strict reporting, so a duplicate
            // key THROWS instead of returning false — handle both modes.
            $exception = null;
            try {
                $ok = $stmt->execute();
                $errno = $conn->errno;
            } catch (mysqli_sql_exception $e) {
                $ok = false;
                $errno = (int)$e->getCode();
                $exception = $e;
            }
            if ($ok) {
                SecurityAuditService::record($conn, $id > 0 ? 'Department Updated' : 'Department Created',
                    ['code' => $code, 'name_en' => $nameEn], 'department', $id ?: $conn->insert_id);
                echo json_encode(['status' => 'success', 'message' => 'Department saved.', 'id' => $id > 0 ? $id : $conn->insert_id]);
            } elseif ($errno === 1062) {
                echo json_encode(['status' => 'error', 'message' => 'A department with that code already exists.']);
            } else {
                reportInternalError('Department save failed', $exception ?? $stmt->error);
                echo json_encode(['status' => 'error', 'message' => 'Unable to save department.']);
            }
            $stmt->close();
            break;

        case 'delete_department':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit; }
            $id = filter_var($_POST['id'] ?? 0, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if (!$id) { echo json_encode(['status' => 'error', 'message' => 'Invalid ID']); exit; }

            // Check for active assignments before deleting.
            $checkStmt = $conn->prepare(
                'SELECT COUNT(*) AS c FROM staff_positions WHERE department_id = ? AND is_active = 1'
            );
            $checkStmt->bind_param('i', $id);
            $checkStmt->execute();
            $activeCount = (int)$checkStmt->get_result()->fetch_assoc()['c'];
            $checkStmt->close();

            if ($activeCount > 0) {
                echo json_encode(['status' => 'error', 'message' => "This department has {$activeCount} active position(s). Deactivate them first."]);
                exit;
            }

            $stmt = $conn->prepare('UPDATE departments SET is_active = 0 WHERE id = ?');
            $stmt->bind_param('i', $id);
            if ($stmt->execute()) {
                SecurityAuditService::record($conn, 'Department Deactivated', [], 'department', $id);
                echo json_encode(['status' => 'success', 'message' => 'Department deactivated.']);
            } else {
                echo json_encode(['status' => 'error', 'message' => 'Unable to deactivate.']);
            }
            $stmt->close();
            break;

        /* ── Staff Positions ─────────────────────────────────────────── */

        case 'list_positions':
            // Single portable query — no dialect-specific NULLS FIRST
            // (MySQL rejects it; with PHP >= 8.1's default strict mysqli
            // reporting a rejected query THROWS, so a false-return
            // fallback would never run).
            $result = $conn->query(
                'SELECT sp.id, sp.department_id, d.code AS dept_code,
                        sp.role_code, sp.title_am, sp.title_en,
                        sp.legacy_flag, sp.is_active, sp.sort_order
                 FROM staff_positions sp
                 LEFT JOIN departments d ON d.id = sp.department_id
                 ORDER BY COALESCE(d.sort_order, 9999) ASC, sp.sort_order ASC, sp.id ASC'
            );
            $rows = [];
            while ($row = $result->fetch_assoc()) {
                $row['is_active'] = (int)$row['is_active'];
                $rows[] = $row;
            }
            $result->free();
            echo json_encode(['status' => 'success', 'positions' => $rows], JSON_UNESCAPED_UNICODE);
            break;

        case 'save_position':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                http_response_code(405); header('Allow: POST'); exit('Method not allowed.');
            }
            $id = filter_var($_POST['id'] ?? 0, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 0;
            $deptId = filter_var($_POST['department_id'] ?? '', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: null;
            $roleCode = strtoupper(trim((string)($_POST['role_code'] ?? '')));
            $titleAm = trim((string)($_POST['title_am'] ?? ''));
            $titleEn = trim((string)($_POST['title_en'] ?? '')) ?: null;
            $legacyFlag = trim((string)($_POST['legacy_flag'] ?? ''));
            $legacyFlag = in_array($legacyFlag, ['is_teacher', 'is_staff', 'is_committee', 'is_volunteer'], true) ? $legacyFlag : null;
            $isActive = isset($_POST['is_active']) ? 1 : 0;

            if (!validateCodeSegment($roleCode)) {
                echo json_encode(['status' => 'error', 'message' => 'Position code must be 1-4 uppercase A-Z letters.']); exit;
            }
            if ($roleCode === IdentityCodeService::ORDINARY_MARKER) {
                echo json_encode(['status' => 'error', 'message' => "'N' is reserved for ordinary members."]); exit;
            }
            if ($deptId === null && in_array($roleCode, IdentityCodeService::RESERVED_FREE_CODES, true)) {
                echo json_encode(['status' => 'error', 'message' => "Code '{$roleCode}' is reserved (category letter) and cannot be a free position."]); exit;
            }
            if ($titleAm === '') {
                echo json_encode(['status' => 'error', 'message' => 'Amharic title is required.']); exit;
            }

            if ($id > 0) {
                $stmt = $conn->prepare(
                    'UPDATE staff_positions SET department_id=?, role_code=?, title_am=?, title_en=?, legacy_flag=?, is_active=? WHERE id=?'
                );
                $stmt->bind_param('isssii', $deptId, $roleCode, $titleAm, $titleEn, $legacyFlag, $isActive, $id);
            } else {
                $stmt = $conn->prepare(
                    'INSERT INTO staff_positions (department_id, role_code, title_am, title_en, legacy_flag, is_active) VALUES (?, ?, ?, ?, ?, ?)'
                );
                $stmt->bind_param('isssii', $deptId, $roleCode, $titleAm, $titleEn, $legacyFlag, $isActive);
            }

            // Strict-mode-safe: duplicate keys throw on PHP >= 8.1.
            $exception = null;
            try {
                $ok = $stmt->execute();
                $errno = $conn->errno;
            } catch (mysqli_sql_exception $e) {
                $ok = false;
                $errno = (int)$e->getCode();
                $exception = $e;
            }
            if ($ok) {
                SecurityAuditService::record($conn, $id > 0 ? 'Position Updated' : 'Position Created',
                    ['role_code' => $roleCode, 'department_id' => $deptId], 'staff_position', $id ?: $conn->insert_id);
                echo json_encode(['status' => 'success', 'message' => 'Position saved.', 'id' => $id > 0 ? $id : $conn->insert_id]);
            } elseif ($errno === 1062) {
                echo json_encode(['status' => 'error', 'message' => 'That position code already exists in this department.']);
            } else {
                reportInternalError('Staff position save failed', $exception ?? $stmt->error);
                echo json_encode(['status' => 'error', 'message' => 'Unable to save position.']);
            }
            $stmt->close();
            break;

        /* ── Membership types (labels editable by Super Admin) ──────── */

        case 'list_member_types':
            $labels = MemberTypeService::labels($conn);
            $rows = [];
            foreach (MemberTypeService::KEYS as $key) {
                $rows[] = [
                    'type_key' => $key,
                    'label_am' => $labels[$key]['am'],
                    'label_en' => $labels[$key]['en'],
                ];
            }
            echo json_encode(['status' => 'success', 'member_types' => $rows], JSON_UNESCAPED_UNICODE);
            break;

        case 'save_member_type':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit; }
            $result = MemberTypeService::saveLabel(
                $conn,
                (string)($_POST['type_key'] ?? ''),
                (string)($_POST['label_am'] ?? ''),
                (string)($_POST['label_en'] ?? '')
            );
            if ($result['status'] === 'success') {
                SecurityAuditService::record($conn, 'Membership Type Updated',
                    ['type_key' => $_POST['type_key'] ?? ''], 'member_type');
            }
            echo json_encode($result, JSON_UNESCAPED_UNICODE);
            break;

        default:
            echo json_encode(['status' => 'error', 'message' => 'Unknown action: ' . $action]);
    }
} catch (Throwable $e) {
    reportInternalError('Identity API error: ' . $action, $e);
    echo json_encode(['status' => 'error', 'message' => 'Unable to complete the identity request.']);
}
