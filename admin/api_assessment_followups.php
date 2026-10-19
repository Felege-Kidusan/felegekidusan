<?php
/**
 * ============================================================
 * Assessment follow-ups API (Attendance alerting — Phase 5, req §11–§12)
 * ============================================================
 *   GET  ?action=list[&decision=&open=1&class_id=&subject_id=&member_id=&q=&limit=&offset=]
 *   GET  ?action=counts[&class_id=&subject_id=&member_id=]
 *   GET  ?action=detail&id=N                 → follow-up + make-up attempts
 *   GET  ?action=member&member_id=N          → the member's follow-ups (profile drill-down)
 *   POST action=decide   {id, decision, note?, csrf_token}
 *   POST action=attempt  {id, score, max_score?, remarks?, csrf_token}
 *
 * Who (server-side, §8 + plan §Phase 5):
 *   teacher   → ONLY follow-ups inside an active teacher_assignments row
 *               (class + subject, or class-wide); may decide / record.
 *   edu_dept  → all Education follow-ups; may decide / record.
 *   hr_dept / info_dept / school_admin / super_admin → read-only.
 * Nothing is ever auto-scored (no automatic zero); the original
 * academic_records row is never changed — a make-up is a separate row.
 * Every date in the payload is an Ethiopian-calendar label.
 * Until FEATURE_ATTENDANCE_V2 is on and sql/075 is applied: available:false.
 * ============================================================
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/backend/ethiopian_date.php';
require_once __DIR__ . '/backend/services/SecurityRateLimiter.php';
require_once __DIR__ . '/backend/services/SecurityAuditService.php';
require_once __DIR__ . '/backend/services/AssessmentFollowupService.php';

use App\Services\AssessmentFollowupService;

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$fail = static function (int $code, string $message, array $extra = []): never {
    http_response_code($code);
    echo json_encode(['status' => 'error', 'message' => $message] + $extra, JSON_UNESCAPED_UNICODE);
    exit;
};
$ok = static function (array $data): never {
    echo json_encode(['status' => 'success'] + $data, JSON_UNESCAPED_UNICODE);
    exit;
};

if (empty($_SESSION['admin_logged_in']) || empty($_SESSION['admin_id'])) {
    $fail(401, 'Unauthorized');
}
$adminId = (int)$_SESSION['admin_id'];
$role = (string)($_SESSION['admin_role'] ?? '');
if (!in_array($role, AssessmentFollowupService::READ_ROLES, true)) {
    $fail(403, 'You do not have permission to use assessment follow-ups.');
}

$method = (string)($_SERVER['REQUEST_METHOD'] ?? 'GET');
$action = (string)($method === 'POST' ? ($_POST['action'] ?? '') : ($_GET['action'] ?? ''));
$input = $_POST;
if ($method === 'POST' && stripos((string)($_SERVER['CONTENT_TYPE'] ?? ''), 'application/json') !== false) {
    $raw = file_get_contents('php://input');
    $json = json_decode((string)$raw, true);
    if (is_array($json)) {
        $input = $json + $_POST;
        $action = (string)($json['action'] ?? $action);
    }
}

$readActions = ['list', 'counts', 'detail', 'member'];
$writeActions = ['decide', 'attempt'];
if (!in_array($action, array_merge($readActions, $writeActions), true)) {
    $fail(400, 'Unknown action');
}
$canWrite = AssessmentFollowupService::roleMayWrite($role);
if (in_array($action, $writeActions, true)) {
    if ($method !== 'POST') {
        $fail(405, 'Use POST for changes.');
    }
    if (!$canWrite) {
        $fail(403, 'Only the responsible teacher or the Education department can change follow-ups.');
    }
    $token = (string)($input['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''));
    if (!validateCsrf($token)) {
        $fail(403, 'Security token expired. Please refresh the page.');
    }
}

if (isset($pdo) && $pdo instanceof PDO) {
    $rl = new \App\Services\SecurityRateLimiter($pdo, sys_get_temp_dir() . '/ssms_ratelimit');
    $check = $rl->consume($method === 'POST' ? 'af_write' : 'af_read', 'user:' . $adminId, $method === 'POST' ? 60 : 240, 60);
    if (!$check['allowed']) {
        $fail(429, 'Too many requests. Please wait a moment and try again.');
    }
}

$available = AssessmentFollowupService::available($conn);
$meta = [
    'available' => $available,
    'can_write' => $available && $canWrite,
    'role' => $role,
    'decisions' => AssessmentFollowupService::DECISION_LABELS,
];
/** Scope: teachers are pinned to their own assignments; everyone else sees all. */
$scope = static function (array $extra = []) use ($role, $adminId): array {
    $f = $extra;
    if ($role === 'teacher') {
        $f['teacher_id'] = $adminId;
    }
    return $f;
};
$auditActor = \App\Services\SecurityAuditActor::fromAuthenticatedContext(
    $adminId, (string)($_SESSION['admin_username'] ?? ''), 'admin', $_SERVER
);

switch ($action) {
    case 'list':
        if (!$available) {
            $ok($meta + ['items' => [], 'total' => 0]);
        }
        $f = $scope([
            'decision' => (string)($_GET['decision'] ?? ''),
            'open_only' => (string)($_GET['open'] ?? '') === '1',
            'class_id' => (int)($_GET['class_id'] ?? 0),
            'subject_id' => (int)($_GET['subject_id'] ?? 0),
            'member_id' => (int)($_GET['member_id'] ?? 0),
            'q' => (string)($_GET['q'] ?? ''),
            'limit' => (int)($_GET['limit'] ?? 50),
            'offset' => (int)($_GET['offset'] ?? 0),
        ]);
        $ok($meta + AssessmentFollowupService::listQueue($conn, $f));

    case 'counts':
        if (!$available) {
            $ok($meta + ['counts' => array_fill_keys(AssessmentFollowupService::DECISIONS, 0) + ['total' => 0, 'with_attempt' => 0]]);
        }
        $f = $scope([
            'class_id' => (int)($_GET['class_id'] ?? 0),
            'subject_id' => (int)($_GET['subject_id'] ?? 0),
            'member_id' => (int)($_GET['member_id'] ?? 0),
        ]);
        $ok($meta + ['counts' => AssessmentFollowupService::counts($conn, $f)]);

    case 'detail':
        if (!$available) {
            $ok($meta + ['item' => null]);
        }
        $id = (int)($_GET['id'] ?? 0);
        $item = AssessmentFollowupService::find($conn, $id);
        if (!$item) {
            $fail(404, 'Follow-up not found.');
        }
        if ($role === 'teacher' && !AssessmentFollowupService::teacherMayHandle($conn, $adminId, (int)$item['class_id'], (int)$item['subject_id'])) {
            $fail(403, 'You are not assigned to this class and subject.');
        }
        $ok($meta + ['item' => $item]);

    case 'member':
        if (!$available) {
            $ok($meta + ['items' => [], 'total' => 0]);
        }
        $memberId = (int)($_GET['member_id'] ?? 0);
        if ($memberId <= 0) {
            $fail(400, 'member_id is required.');
        }
        $ok($meta + AssessmentFollowupService::listQueue($conn, $scope(['member_id' => $memberId, 'limit' => 200])));

    case 'decide':
        if (!$available) {
            $fail(409, 'Assessment follow-ups are not available yet.', ['available' => false]);
        }
        $id = (int)($input['id'] ?? 0);
        $decision = strtolower(trim((string)($input['decision'] ?? '')));
        $note = (string)($input['note'] ?? '');
        if ($id <= 0) {
            $fail(400, 'id is required.');
        }
        try {
            $res = AssessmentFollowupService::decide($conn, $id, $decision, $note, $adminId, $role, $auditActor);
        } catch (\DomainException $e) {
            $__msg = $e->getMessage();
            $fail(422, $__msg);
        } catch (\Throwable $e) {
            error_log('Assessment follow-up decide failed: ' . $e->getMessage());
            $fail(500, 'Unable to save the decision right now.');
        }
        $ok($meta + $res);

    case 'attempt':
        if (!$available) {
            $fail(409, 'Assessment follow-ups are not available yet.', ['available' => false]);
        }
        $id = (int)($input['id'] ?? 0);
        if ($id <= 0) {
            $fail(400, 'id is required.');
        }
        try {
            $res = AssessmentFollowupService::recordAttempt(
                $conn, $id, $input['score'] ?? null, $input['max_score'] ?? null,
                (string)($input['remarks'] ?? ''), $adminId, $role, $auditActor
            );
        } catch (\DomainException $e) {
            $__msg = $e->getMessage();
            $fail(422, $__msg);
        } catch (\Throwable $e) {
            error_log('Assessment make-up record failed: ' . $e->getMessage());
            $fail(500, 'Unable to record the make-up result right now.');
        }
        $ok($meta + $res);
}
$fail(400, 'Unknown action');
