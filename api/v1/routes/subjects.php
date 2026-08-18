<?php
/**
 * School API v1 — Subjects (read-only list)
 * GET /subjects
 */

$auth = apiRequireAuth();
if (!apiRoleIs($auth, array_merge(apiRolesEducation(), ['teacher']))) {
    err('You cannot list subjects.', 403);
}

if ($method === 'GET' && ($ROUTE['id'] === null || $ROUTE['id'] === '')) {
    $subjects = [];
    $r = $conn->query(
        "SELECT s.id, s.subject_name, s.subject_name_en, s.subject_code,
                (SELECT COUNT(*) FROM class_subjects cs WHERE cs.subject_id = s.id) AS class_count
         FROM subjects s
         WHERE s.is_active = 1
         ORDER BY s.subject_name"
    );
    if ($r) {
        while ($row = $r->fetch_assoc()) {
            $subjects[] = [
                'id' => (int)$row['id'],
                'subject_name' => $row['subject_name'],
                'subject_name_en' => $row['subject_name_en'],
                'subject_code' => $row['subject_code'],
                'class_count' => (int)$row['class_count'],
            ];
        }
    }
    ok(['subjects' => $subjects, 'count' => count($subjects)]);
}

err("No handler for {$method} /subjects", 404);
