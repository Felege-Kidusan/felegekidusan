<?php
/**
 * School API v1 — Assessment follow-ups (Attendance alerting — Phase 5)
 * Mounted from routes/grades.php when the first segment is `followups`.
 *
 *   GET  /grades/followups?decision=&open=1&class_id=&member_id=&q=&limit=&offset=
 *   GET  /grades/followups/counts
 *   GET  /grades/followups/{id}
 *   POST /grades/followups/{id}/decide   {decision, note?}
 *   POST /grades/followups/{id}/attempt  {score, max_score?, remarks?}
 *
 * Roles (requirements §8/§12, plan §Phase 5): teacher — only follow-ups
 * inside an active teacher_assignments row (class + subject or class-wide),
 * may decide / record; edu_dept — all, may decide / record;
 * hr_dept / info_dept / school_admin / super_admin — read-only.
 * No automatic score; the original academic_records row is never changed.
 * Dates in the payload are Ethiopian-calendar labels.
 */

require_once dirname(__DIR__, 3) . '/admin/backend/services/AssessmentFollowupService.php';
require_once dirname(__DIR__, 3) . '/admin/backend/services/SecurityAuditService.php';
if (!class_exists('\\App\\Services\\AssessmentFollowupService')) {
    err('Assessment follow-ups are temporarily unavailable.', 503);
}

$afRole = apiRoleOf($auth);
if (!in_array($afRole, \App\Services\AssessmentFollowupService::READ_ROLES, true)) {
    err('You cannot view assessment follow-ups.', 403);
}
$afUid = (int)$auth['uid'];
$afAvailable = \App\Services\AssessmentFollowupService::available($conn);
$afCanWrite = $afAvailable && \App\Services\AssessmentFollowupService::roleMayWrite($afRole);
$afSub = $ROUTE['sub'] ?? null;                 // null | 'counts' | {id}
$afTail = $ROUTE['parts'][3] ?? null;           // 'decide' | 'attempt' | null
$afMeta = [
    'available' => $afAvailable,
    'can_write' => $afCanWrite,
    'decisions' => \App\Services\AssessmentFollowupService::DECISION_LABELS,
];
$afScope = static function (array $extra = []) use ($afRole, $afUid): array {
    if ($afRole === 'teacher') {
        $extra['teacher_id'] = $afUid;
    }
    return $extra;
};

if (isApiRateLimited('assessment_followups', $method === 'POST' ? 30 : 120)) {
    err('Too many requests. Please wait a moment.', 429);
}

// GET /grades/followups
if ($method === 'GET' && ($afSub === null || $afSub === '')) {
    if (!$afAvailable) {
        ok($afMeta + ['items' => [], 'total' => 0]);
    }
    ok($afMeta + \App\Services\AssessmentFollowupService::listQueue($conn, $afScope([
        'decision' => (string)($_GET['decision'] ?? ''),
        'open_only' => (string)($_GET['open'] ?? '') === '1',
        'class_id' => (int)($_GET['class_id'] ?? 0),
        'subject_id' => (int)($_GET['subject_id'] ?? 0),
        'member_id' => (int)($_GET['member_id'] ?? 0),
        'q' => (string)($_GET['q'] ?? ''),
        'limit' => (int)($_GET['limit'] ?? 50),
        'offset' => (int)($_GET['offset'] ?? 0),
    ])));
}

// GET /grades/followups/counts
if ($method === 'GET' && $afSub === 'counts') {
    if (!$afAvailable) {
        ok($afMeta + ['counts' => array_fill_keys(\App\Services\AssessmentFollowupService::DECISIONS, 0) + ['total' => 0, 'with_attempt' => 0]]);
    }
    ok($afMeta + ['counts' => \App\Services\AssessmentFollowupService::counts($conn, $afScope([
        'class_id' => (int)($_GET['class_id'] ?? 0),
        'member_id' => (int)($_GET['member_id'] ?? 0),
    ]))]);
}

$afId = (int)$afSub;
if ($afId <= 0) {
    err('Unknown follow-up endpoint.', 404);
}
if (!$afAvailable) {
    err('Assessment follow-ups are not available yet.', 409, ['available' => false]);
}
$afItem = \App\Services\AssessmentFollowupService::find($conn, $afId);
if (!$afItem) {
    err('Follow-up not found.', 404);
}
if ($afRole === 'teacher' && !\App\Services\AssessmentFollowupService::teacherMayHandle($conn, $afUid, (int)$afItem['class_id'], (int)$afItem['subject_id'])) {
    err('You are not assigned to this class and subject.', 403);
}

// GET /grades/followups/{id}
if ($method === 'GET' && ($afTail === null || $afTail === '')) {
    ok($afMeta + ['item' => $afItem]);
}

if ($method === 'POST' && in_array($afTail, ['decide', 'attempt'], true)) {
    if (!$afCanWrite) {
        err('Only the responsible teacher or the Education department can change follow-ups.', 403);
    }
    $input = getBody();
    $afActor = \App\Services\SecurityAuditActor::fromAuthenticatedContext($afUid, (string)($auth['usr'] ?? ''), 'api', $_SERVER);
    try {
        if ($afTail === 'decide') {
            $res = \App\Services\AssessmentFollowupService::decide(
                $conn, $afId, strtolower(trim((string)($input['decision'] ?? ''))), (string)($input['note'] ?? ''),
                $afUid, $afRole, $afActor
            );
            logApiAction($afUid, $auth['usr'] ?? '', 'Assessment Follow-up Decided', "followup {$afId} -> " . (string)($input['decision'] ?? ''));
        } else {
            $res = \App\Services\AssessmentFollowupService::recordAttempt(
                $conn, $afId, $input['score'] ?? null, $input['max_score'] ?? null, (string)($input['remarks'] ?? ''),
                $afUid, $afRole, $afActor
            );
            logApiAction($afUid, $auth['usr'] ?? '', 'Assessment Make-up Recorded', "followup {$afId}");
        }
    } catch (\DomainException $e) {
        $__afMessage = $e->getMessage();
        err($__afMessage, 422);
    } catch (\Throwable $e) {
        reportInternalError('Assessment follow-up change failed', $e->getMessage());
        err('Unable to save right now.', 500);
    }
    ok($afMeta + $res);
}

err('Unknown follow-up endpoint.', 404);
