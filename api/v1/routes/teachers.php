<?php
/**
 * School API v1 — Teachers (read-only)
 * Create / assign stays on the website Education screen.
 *
 * GET /teachers          — list (no emails, no member phones)
 * GET /teachers/{id}     — one teacher + this year's assignments
 */

$auth = apiRequireAuth();
apiRequireRole($auth, apiRolesEducation());

$id = $ROUTE['id'];
$year = getCurrentAcademicYear();
$yearId = $year ? (int)$year['id'] : 0;

if ($method === 'GET' && $id === null) {
    list($page, $limit, $offset) = getPagination(50);
    $q = trim($_GET['q'] ?? '');
    $includeInactive = ($_GET['include_inactive'] ?? '') === '1';

    $where = "u.role = 'teacher'";
    $params = [];
    $types = '';
    if (!$includeInactive) {
        $where .= " AND u.is_active = 1";
    }
    if ($q !== '') {
        $where .= " AND (u.full_name LIKE ? OR u.username LIKE ?)";
        $st = '%' . $q . '%';
        $params[] = $st;
        $params[] = $st;
        $types .= 'ss';
    }

    $countSql = "SELECT COUNT(*) AS total FROM users u WHERE {$where}";
    $countStmt = $conn->prepare($countSql);
    if ($types) $countStmt->bind_param($types, ...$params);
    $countStmt->execute();
    $total = (int)$countStmt->get_result()->fetch_assoc()['total'];
    $countStmt->close();

    $sql = "SELECT u.id, u.username, u.full_name, u.is_active, u.created_at,
                   (SELECT COUNT(DISTINCT ta.class_id) FROM teacher_assignments ta
                     WHERE ta.teacher_id = u.id AND ta.is_active = 1
                       AND (ta.academic_year_id IS NULL OR ta.academic_year_id = {$yearId})) AS assigned_classes,
                   (SELECT COUNT(DISTINCT ta.subject_id) FROM teacher_assignments ta
                     WHERE ta.teacher_id = u.id AND ta.is_active = 1 AND ta.subject_id IS NOT NULL
                       AND (ta.academic_year_id IS NULL OR ta.academic_year_id = {$yearId})) AS assigned_subjects
            FROM users u
            WHERE {$where}
            ORDER BY u.full_name
            LIMIT {$limit} OFFSET {$offset}";
    $stmt = $conn->prepare($sql);
    if ($types) $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $teachers = [];
    $r = $stmt->get_result();
    while ($row = $r->fetch_assoc()) {
        $teachers[] = [
            'id' => (int)$row['id'],
            'username' => $row['username'],
            'full_name' => $row['full_name'],
            'is_active' => (int)$row['is_active'],
            'created_at' => $row['created_at'],
            'assigned_classes' => (int)$row['assigned_classes'],
            'assigned_subjects' => (int)$row['assigned_subjects'],
        ];
    }
    $stmt->close();

    paginated($teachers, $total, $page, $limit);
}

if ($method === 'GET' && $id !== null && $ROUTE['sub'] === null) {
    $tid = (int)$id;
    $stmt = $conn->prepare(
        "SELECT id, username, full_name, is_active, created_at
         FROM users WHERE id = ? AND role = 'teacher' LIMIT 1"
    );
    $stmt->bind_param('i', $tid);
    $stmt->execute();
    $teacher = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$teacher) err('Teacher not found', 404);

    $asg = $conn->prepare(
        "SELECT ta.id, ta.class_id, ta.subject_id, ta.is_class_teacher, ta.is_primary,
                c.class_name, c.class_name_en,
                s.subject_name, s.subject_name_en
         FROM teacher_assignments ta
         JOIN classes c ON ta.class_id = c.id
         LEFT JOIN subjects s ON ta.subject_id = s.id
         WHERE ta.teacher_id = ? AND ta.is_active = 1
           AND (ta.academic_year_id IS NULL OR ta.academic_year_id = ?)
         ORDER BY c.level_order, s.subject_name"
    );
    $asg->bind_param('ii', $tid, $yearId);
    $asg->execute();
    $assignments = [];
    $r = $asg->get_result();
    while ($row = $r->fetch_assoc()) {
        $assignments[] = [
            'id' => (int)$row['id'],
            'class_id' => (int)$row['class_id'],
            'subject_id' => $row['subject_id'] ? (int)$row['subject_id'] : null,
            'is_class_teacher' => (int)$row['is_class_teacher'] === 1,
            'is_primary' => (int)$row['is_primary'] === 1,
            'class_name' => $row['class_name'],
            'class_name_en' => $row['class_name_en'],
            'subject_name' => $row['subject_name'] ?: ($row['is_class_teacher'] ? 'Class Teacher' : null),
            'subject_name_en' => $row['subject_name_en'],
        ];
    }
    $asg->close();

    $teacher['id'] = (int)$teacher['id'];
    $teacher['is_active'] = (int)$teacher['is_active'];
    $teacher['assignments'] = $assignments;
    ok($teacher);
}

err("No handler for {$method} /teachers" . ($id ? "/{$id}" : ''), 404);
