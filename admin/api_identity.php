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
require_once __DIR__ . '/backend/services/MemberTypeService.php';

use App\Services\IdentityCodeService;
use App\Services\MemberCategory;
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
                        sp.is_active, sp.sort_order
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
                } elseif (($letter = MemberCategory::letterFor($ageGroup)) !== null) {
                    $newCode = IdentityCodeService::allocateStudent($conn, $letter);
                } else {
                    // Never guess a category: no positions and no resolvable
                    // age group means the code stays pending.
                    $newCode = null;
                }

                $updateStmt = $conn->prepare(
                    'UPDATE members SET legacy_member_code = ?, member_code = ? WHERE id = ?'
                );
                $updateStmt->bind_param('ssi', $oldCode, $newCode, $memberId);
                $updateStmt->execute();
                $updateStmt->close();

                // Log migration trail.
                $logNew = $newCode ?? '(pending)';
                $logStmt = $conn->prepare(
                    'INSERT INTO member_code_migrations (member_id, old_code, new_code, reason)
                     VALUES (?, ?, ?, ?)
                     ON DUPLICATE KEY UPDATE old_code = VALUES(old_code), new_code = VALUES(new_code), reason = VALUES(reason)'
                );
                $reason = 'staff_assignment_change';
                $logStmt->bind_param('isss', $memberId, $oldCode, $logNew, $reason);
                $logStmt->execute();
                $logStmt->close();

                SecurityAuditService::record($conn, 'Member Positions Assigned',
                    ['position_ids' => $safeIds, 'new_code' => $newCode, 'old_code' => $oldCode],
                    'member', $memberId);

                $qrOk = false;
                require_once __DIR__ . '/../id_cards/libs/qr_loader.php';
                if (class_exists('QRcode')) {
                    $qrOk = IdentityCodeService::regenerateQr($conn, $memberId);
                }

                $conn->commit();
                echo json_encode([
                    'status' => 'success',
                    'message' => 'Positions assigned. New code: ' . ($newCode ?? 'pending (no resolvable category)') . ($qrOk ? ' · QR refreshed.' : ''),
                    'new_code' => $newCode,
                ], JSON_UNESCAPED_UNICODE);
            } catch (Throwable $e) {
                $conn->rollback();
                reportInternalError('Assign positions failed', $e);
                echo json_encode(['status' => 'error', 'message' => 'Unable to assign positions.']);
            }
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

        /* ── Advanced member identity editor ────────────────────────── */

        case 'identity_search':
            // Bounded, index-friendly lookup for the editor's member picker.
            $search = trim((string)($_GET['q'] ?? ''));
            if ($search === '') {
                echo json_encode(['status' => 'success', 'members' => []], JSON_UNESCAPED_UNICODE);
                break;
            }
            $like = '%' . $search . '%';
            $stmt = $conn->prepare(
                "SELECT id, student_name, father_name, member_code, age_group, member_type, status
                 FROM members
                 WHERE (student_name LIKE ? OR father_name LIKE ? OR member_code LIKE ?)
                   AND status != 'archived'
                 ORDER BY student_name ASC LIMIT 20"
            );
            $stmt->bind_param('sss', $like, $like, $like);
            $stmt->execute();
            $result = $stmt->get_result();
            $rows = [];
            while ($row = $result->fetch_assoc()) {
                $rows[] = $row;
            }
            $stmt->close();
            echo json_encode(['status' => 'success', 'members' => $rows], JSON_UNESCAPED_UNICODE);
            break;

        case 'get_member_identity':
            $memberId = filter_var($_GET['member_id'] ?? 0, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if (!$memberId) { echo json_encode(['status' => 'error', 'message' => 'Invalid member id.']); break; }

            $stmt = $conn->prepare(
                'SELECT id, student_name, father_name, grandfather_name, member_code, age_group, member_type, status
                 FROM members WHERE id = ?'
            );
            $stmt->bind_param('i', $memberId);
            $stmt->execute();
            $member = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if (!$member) { echo json_encode(['status' => 'error', 'message' => 'Member not found.']); break; }

            $assigned = $conn->prepare(
                'SELECT position_id FROM member_staff_positions WHERE member_id = ?'
            );
            $assigned->bind_param('i', $memberId);
            $assigned->execute();
            $assignedIds = [];
            $r = $assigned->get_result();
            while ($row = $r->fetch_assoc()) { $assignedIds[] = (int)$row['position_id']; }
            $assigned->close();

            echo json_encode([
                'status' => 'success',
                'member' => $member,
                'assigned_position_ids' => $assignedIds,
                'member_types' => array_keys(MemberTypeService::labels($conn)),
                'categories' => MemberCategory::groups(),
            ], JSON_UNESCAPED_UNICODE);
            break;

        case 'update_member_identity':
            // Advanced edit: category (age_group) + membership type.
            // Category changes re-letter a STUDENT code; staff codes are
            // untouched (they encode positions, not category).
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit; }
            $memberId = filter_var($_POST['member_id'] ?? 0, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            $newGroup = (string)($_POST['age_group'] ?? '');
            $newType = (string)($_POST['member_type'] ?? '');
            if (!$memberId) { echo json_encode(['status' => 'error', 'message' => 'Invalid member id.']); break; }
            if (!in_array($newGroup, MemberCategory::groups(), true)) {
                echo json_encode(['status' => 'error', 'message' => 'Invalid category.']); break;
            }
            if (!in_array($newType, MemberTypeService::KEYS, true)) {
                echo json_encode(['status' => 'error', 'message' => 'Invalid membership type.']); break;
            }

            $conn->begin_transaction();
            try {
                $stmt = $conn->prepare('SELECT member_code, age_group FROM members WHERE id = ? FOR UPDATE');
                $stmt->bind_param('i', $memberId);
                $stmt->execute();
                $current = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                if (!$current) { throw new RuntimeException('Member not found.'); }

                $oldCode = (string)($current['member_code'] ?? '');
                $oldGroup = (string)($current['age_group'] ?? '');
                $newCode = $oldCode;
                $heldLetter = null;
                $isStudentCode = $oldCode !== '' && strpos($oldCode, '-') === false;

                if ($isStudentCode && $newGroup !== $oldGroup) {
                    $letter = MemberCategory::letterFor($newGroup);
                    if ($letter === null) { throw new RuntimeException('Unresolvable category.'); }
                    // Held allocation: the advisory lock stays until commit
                    // so no concurrent write can draw the same number.
                    $heldLetter = $letter;
                    $newCode = IdentityCodeService::allocateStudentHeld($conn, $letter);
                }

                $stmt = $conn->prepare('UPDATE members SET age_group = ?, member_type = ?, member_code = ? WHERE id = ?');
                $stmt->bind_param('sssi', $newGroup, $newType, $newCode, $memberId);
                if (!$stmt->execute()) { throw new RuntimeException('Update failed.'); }
                $stmt->close();

                if ($newCode !== $oldCode) {
                    $logStmt = $conn->prepare(
                        'INSERT INTO member_code_migrations (member_id, old_code, new_code, reason)
                         VALUES (?, ?, ?, ?)
                         ON DUPLICATE KEY UPDATE old_code = VALUES(old_code), new_code = VALUES(new_code), reason = VALUES(reason)'
                    );
                    $reason = 'category_change';
                    $logStmt->bind_param('isss', $memberId, $oldCode, $newCode, $reason);
                    $logStmt->execute();
                    $logStmt->close();

                    require_once __DIR__ . '/../id_cards/libs/qr_loader.php';
                    if (class_exists('QRcode')) {
                        IdentityCodeService::regenerateQr($conn, $memberId);
                    }
                }

                SecurityAuditService::record($conn, 'Member Identity Updated',
                    ['age_group' => $newGroup, 'member_type' => $newType,
                     'old_code' => $oldCode, 'new_code' => $newCode],
                    'member', $memberId);

                $conn->commit();
                if ($heldLetter !== null) {
                    IdentityCodeService::releaseCodeLock($conn, $heldLetter);
                }
                echo json_encode([
                    'status' => 'success',
                    'message' => 'Member updated.' . ($newCode !== $oldCode ? ' New code: ' . $newCode : ''),
                    'member_code' => $newCode,
                ], JSON_UNESCAPED_UNICODE);
            } catch (Throwable $e) {
                $conn->rollback();
                if (isset($heldLetter) && $heldLetter !== null) {
                    IdentityCodeService::releaseCodeLock($conn, $heldLetter);
                }
                reportInternalError('update_member_identity failed', $e);
                echo json_encode(['status' => 'error', 'message' => 'Unable to update member.']);
            }
            break;

        default:
            echo json_encode(['status' => 'error', 'message' => 'Unknown action: ' . $action]);
    }
} catch (Throwable $e) {
    reportInternalError('Identity API error: ' . $action, $e);
    echo json_encode(['status' => 'error', 'message' => 'Unable to complete the identity request.']);
}
