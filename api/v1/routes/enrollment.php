<?php
/**
 * School API v1 — Enrollment (Education on the phone)
 * Uses the same EnrollmentService as the website.
 *
 * GET  /enrollment/overview
 * GET  /enrollment/search?q=
 * POST /enrollment            { member_id, class_id }
 */

$auth = apiRequireAuth();
apiRequireRole($auth, apiRolesEducation());

require_once __DIR__ . '/../../../admin/backend/services/EnrollmentService.php';

$action = $ROUTE['id'] ?? '';
$year = getCurrentAcademicYear();
$yearId = $year ? (int)$year['id'] : 0;

if ($action === 'overview' && $method === 'GET') {
    $totalMembers = 0;
    $r = $conn->query("SELECT COUNT(*) c FROM members WHERE status = 'active'");
    if ($r) $totalMembers = (int)$r->fetch_assoc()['c'];

    $enrolled = 0;
    $unassigned = $totalMembers;
    if ($yearId) {
        $stmt = $conn->prepare(
            "SELECT COUNT(*) c FROM class_enrollments
             WHERE academic_year_id = ? AND status = 'active'"
        );
        $stmt->bind_param('i', $yearId);
        $stmt->execute();
        $enrolled = (int)$stmt->get_result()->fetch_assoc()['c'];
        $stmt->close();

        $stmt = $conn->prepare(
            "SELECT COUNT(*) c FROM members m
             WHERE m.status = 'active'
               AND m.id NOT IN (
                    SELECT ce.member_id FROM class_enrollments ce
                    WHERE ce.academic_year_id = ? AND ce.status = 'active'
               )"
        );
        $stmt->bind_param('i', $yearId);
        $stmt->execute();
        $unassigned = (int)$stmt->get_result()->fetch_assoc()['c'];
        $stmt->close();
    }

    $classes = 0;
    $r = $conn->query("SELECT COUNT(*) c FROM classes WHERE is_active = 1");
    if ($r) $classes = (int)$r->fetch_assoc()['c'];

    $teachers = 0;
    $r = $conn->query("SELECT COUNT(*) c FROM users WHERE role = 'teacher' AND is_active = 1");
    if ($r) $teachers = (int)$r->fetch_assoc()['c'];

    ok([
        'year_id' => $yearId,
        'year_name' => $year['year_name'] ?? null,
        'total_members' => $totalMembers,
        'total_enrolled' => $enrolled,
        'unassigned_members' => $unassigned,
        'total_classes' => $classes,
        'total_teachers' => $teachers,
    ]);
}

if ($action === 'search' && $method === 'GET') {
    $q = trim($_GET['q'] ?? '');
    if (strlen($q) < 1) {
        ok(['members' => []]);
    }
    if (isApiRateLimited('enroll_search', 60)) {
        err('Too many searches. Please wait a moment.', 429);
    }

    $limit = min(20, max(1, (int)($_GET['limit'] ?? 20)));
    $st = '%' . $q . '%';
    $sql = "SELECT m.id, m.member_code, m.student_name, m.father_name, m.gender, m.member_type,
                   c.class_name, c.id AS class_id
            FROM members m
            LEFT JOIN class_enrollments ce
                   ON ce.member_id = m.id AND ce.status = 'active' AND ce.academic_year_id = ?
            LEFT JOIN classes c ON c.id = ce.class_id
            WHERE m.status = 'active'
              AND (m.student_name LIKE ? OR m.father_name LIKE ? OR m.member_code LIKE ? OR m.baptismal_name LIKE ?)
            ORDER BY m.student_name
            LIMIT ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('issssi', $yearId, $st, $st, $st, $st, $limit);
    $stmt->execute();
    $members = [];
    $r = $stmt->get_result();
    while ($row = $r->fetch_assoc()) {
        $members[] = [
            'id' => (int)$row['id'],
            'member_code' => $row['member_code'] ?? '',
            'student_name' => $row['student_name'] ?? '',
            'father_name' => $row['father_name'] ?? '',
            'gender' => $row['gender'] ?? '',
            'member_type' => $row['member_type'] ?? '',
            'class_id' => $row['class_id'] ? (int)$row['class_id'] : null,
            'class_name' => $row['class_name'] ?? null,
        ];
    }
    $stmt->close();
    ok(['members' => $members]);
}

if (($action === '' || $action === null) && $method === 'POST') {
    $input = getBody();
    $memberId = (int)($input['member_id'] ?? 0);
    $classId = (int)($input['class_id'] ?? 0);
    if (!$memberId || !$classId) {
        err('member_id and class_id are required.');
    }
    if (isApiRateLimited('enroll_save', 30)) {
        err('Too many enrollments. Please wait a moment.', 429);
    }

    $result = \App\Services\EnrollmentService::enroll(
        $conn,
        $memberId,
        $classId,
        $yearId ?: null,
        (int)$auth['uid']
    );
    if (($result['status'] ?? '') !== 'success') {
        err($result['message'] ?? 'Could not enroll this student.', 400);
    }
    logApiAction($auth['uid'], $auth['usr'], 'Mobile Enroll', "Member {$memberId} → class {$classId}");
    ok($result);
}

err("Unknown enrollment action. Use: overview, search, or POST /enrollment", 404);
