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
require_once __DIR__ . '/backend/services/MemberCategory.php';

use App\Services\IdentityCodeService;
use App\Services\MemberCategory;
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

            if ($stmt->execute()) {
                SecurityAuditService::record($conn, $id > 0 ? 'Department Updated' : 'Department Created',
                    ['code' => $code, 'name_en' => $nameEn], 'department', $id ?: $conn->insert_id);
                echo json_encode(['status' => 'success', 'message' => 'Department saved.', 'id' => $id > 0 ? $id : $conn->insert_id]);
            } elseif ($conn->errno === 1062) {
                echo json_encode(['status' => 'error', 'message' => 'A department with that code already exists.']);
            } else {
                reportInternalError('Department save failed', $stmt->error);
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
            $result = $conn->query(
                'SELECT sp.id, sp.department_id, d.code AS dept_code,
                        sp.role_code, sp.title_am, sp.title_en,
                        sp.is_active, sp.sort_order
                 FROM staff_positions sp
                 LEFT JOIN departments d ON d.id = sp.department_id
                 WHERE COALESCE(sp.is_active, 1) IN (0,1)
                 ORDER BY d.sort_order ASC NULLS FIRST, sp.sort_order ASC, sp.id ASC'
            );
            // Fallback for MariaDB < 10.4 that may not support NULLS FIRST.
            if (!$result) {
                $result = $conn->query(
                    'SELECT sp.id, sp.department_id, d.code AS dept_code,
                            sp.role_code, sp.title_am, sp.title_en,
                            sp.is_active, sp.sort_order
                     FROM staff_positions sp
                     LEFT JOIN departments d ON d.id = sp.department_id
                     ORDER BY COALESCE(d.sort_order, 9999) ASC, sp.sort_order ASC, sp.id ASC'
                );
            }
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
            $deptId = filter_var($_POST['department_id'] ?? '', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            $roleCode = strtoupper(trim((string)($_POST['role_code'] ?? '')));
            $titleAm = trim((string)($_POST['title_am'] ?? ''));
            $titleEn = trim((string)($_POST['title_en'] ?? '')) ?: null;
            $isActive = isset($_POST['is_active']) ? 1 : 0;

            if (!validateCodeSegment($roleCode)) {
                echo json_encode(['status' => 'error', 'message' => 'Position code must be 1-4 uppercase A-Z letters.']); exit;
            }
            if ($roleCode === IdentityCodeService::ORDINARY_MARKER) {
                echo json_encode(['status' => 'error', 'message' => "'N' is reserved for ordinary members."]); exit;
            }
            if ($titleAm === '') {
                echo json_encode(['status' => 'error', 'message' => 'Amharic title is required.']); exit;
            }

            if ($id > 0) {
                $stmt = $conn->prepare(
                    'UPDATE staff_positions SET department_id=?, role_code=?, title_am=?, title_en=?, is_active=? WHERE id=?'
                );
                $stmt->bind_param('isssi', $deptId, $roleCode, $titleAm, $titleEn, $isActive, $id);
            } else {
                $stmt = $conn->prepare(
                    'INSERT INTO staff_positions (department_id, role_code, title_am, title_en, is_active) VALUES (?, ?, ?, ?, ?)'
                );
                $stmt->bind_param('isssi', $deptId, $roleCode, $titleAm, $titleEn, $isActive);
            }

            if ($stmt->execute()) {
                SecurityAuditService::record($conn, $id > 0 ? 'Position Updated' : 'Position Created',
                    ['role_code' => $roleCode, 'department_id' => $deptId], 'staff_position', $id ?: $conn->insert_id);
                echo json_encode(['status' => 'success', 'message' => 'Position saved.', 'id' => $id > 0 ? $id : $conn->insert_id]);
            } elseif ($conn->errno === 1062) {
                echo json_encode(['status' => 'error', 'message' => 'That position code already exists in this department.']);
            } else {
                reportInternalError('Staff position save failed', $stmt->error);
                echo json_encode(['status' => 'error', 'message' => 'Unable to save position.']);
            }
            $stmt->close();
            break;

        /* ── Assign positions to a member (regenerates their code) ───── */

        case 'assign_positions':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                http_response_code(405); header('Allow: POST'); exit('Method not allowed.');
            }
            $memberId = filter_var($_POST['member_id'] ?? 0, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            $positionIds = $_POST['position_ids'] ?? []; // array of ints

            if (!$memberId || !is_array($positionIds)) {
                echo json_encode(['status' => 'error', 'message' => 'Invalid input.']); exit;
            }

            $safeIds = array_map('intval', array_filter($positionIds, fn($v) => filter_var($v, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]])));

            $conn->begin_transaction();
            try {
                // Clear existing assignments for this member.
                $clearStmt = $conn->prepare('DELETE FROM member_staff_positions WHERE member_id = ?');
                $clearStmt->bind_param('i', $memberId);
                $clearStmt->execute();
                $clearStmt->close();

                if ($safeIds !== []) {
                    $placeholders = implode(',', array_fill(0, count($safeIds), '?'));
                    $types = str_repeat('i', count($safeIds));
                    $insertStmt = $conn->prepare(
                        "INSERT IGNORE INTO member_staff_positions (member_id, position_id, assigned_by)
                         VALUES (?, $placeholders, ?)"
                    );
                    $adminId = (int)$_SESSION['admin_id'];
                    $allParams = array_merge([$memberId], $safeIds, [$adminId]);
                    $allTypes = 'i' . $types . 'i';
                    $bindParams = [$allTypes];
                    foreach ($allParams as &$p) { $bindParams[] = &$p; }
                    call_user_func_array([$insertStmt, 'bind_param'], $bindParams);
                    $insertStmt->execute();
                    $insertStmt->close();
                }

                // Regenerate the member's code based on new assignments.
                $getStmt = $conn->prepare('SELECT age_group FROM members WHERE id = ?');
                $getStmt->bind_param('i', $memberId);
                $getStmt->execute();
                $ageGroup = (string)($getStmt->get_result()->fetch_assoc()['age_group'] ?? '');
                $getStmt->close();

                $segments = IdentityCodeService::resolveStaffSegments($conn, $memberId);
                $oldCode = '';
                $oldStmt = $conn->prepare('SELECT member_code FROM members WHERE id = ?');
                $oldStmt->bind_param('i', $memberId);
                $oldStmt->execute();
                $oldCode = (string)($oldStmt->get_result()->fetch_assoc()['member_code'] ?? '');
                $oldStmt->close();

                if ($segments !== null && $segments !== []) {
                    $newCode = IdentityCodeService::allocateStaff($conn, $segments);
                } else {
                    $letter = MemberCategory::letterFor($ageGroup) ?? MemberCategory::LETTER_A;
                    $newCode = IdentityCodeService::allocateStudent($conn, $letter);
                }

                $updateStmt = $conn->prepare(
                    'UPDATE members SET legacy_member_code = ?, member_code = ? WHERE id = ?'
                );
                $updateStmt->bind_param('ssi', $oldCode, $newCode, $memberId);
                $updateStmt->execute();
                $updateStmt->close();

                // Log migration trail.
                $logStmt = $conn->prepare(
                    'INSERT INTO member_code_migrations (member_id, old_code, new_code, reason)
                     VALUES (?, ?, ?, ?)
                     ON DUPLICATE KEY UPDATE old_code = VALUES(old_code), new_code = VALUES(new_code), reason = VALUES(reason)'
                );
                $reason = 'staff_assignment_change';
                $logStmt->bind_param('isss', $memberId, $oldCode, $newCode, $reason);
                $logStmt->execute();
                $logStmt->close();

                SecurityAuditService::record($conn, 'Member Positions Assigned',
                    ['position_ids' => $safeIds, 'new_code' => $newCode, 'old_code' => $oldCode],
                    'member', $memberId);

                $qrOk = false;
                require_once __DIR__ . '/../id_cards/libs/phpqrcode/qrlib.php';
                $qrOk = IdentityCodeService::regenerateQr($conn, $memberId);

                $conn->commit();
                echo json_encode([
                    'status' => 'success',
                    'message' => 'Positions assigned. New code: ' . $newCode . ($qrOk ? ' · QR refreshed.' : ''),
                    'new_code' => $newCode,
                ], JSON_UNESCAPED_UNICODE);
            } catch (Throwable $e) {
                $conn->rollback();
                reportInternalError('Assign positions failed', $e);
                echo json_encode(['status' => 'error', 'message' => 'Unable to assign positions.']);
            }
            break;

        default:
            echo json_encode(['status' => 'error', 'message' => 'Unknown action: ' . $action]);
    }
} catch (Throwable $e) {
    reportInternalError('Identity API error: ' . $action, $e);
    echo json_encode(['status' => 'error', 'message' => 'Unable to complete the identity request.']);
}
