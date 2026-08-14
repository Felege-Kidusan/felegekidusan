<?php
/**
 * School API v1 — Classes Routes (v4 — correct column names)
 * 
 * Classes table columns: id, class_name, class_name_en, class_code, 
 *   level_order, section, age_group, description, is_active, created_at
 * 
 * teacher_assignments columns: id, teacher_id, class_id, subject_id,
 *   academic_year_id, is_class_teacher, is_primary, is_active, status,
 *   assigned_by, assigned_at
 */

$auth = apiRequireAuth();
$id = $ROUTE['id'];
$sub = $ROUTE['sub'];

// ============================================================
// GET /classes — List classes (role-filtered)
// ============================================================
if ($method === 'GET' && $id === null) {
    $year = getCurrentAcademicYear();
    $classes = [];
    $userId = $auth['uid'];
    $userRole = $auth['rol'];
    
    $restrictedRoles = ['teacher', 'attendance_taker'];
    $isRestricted = in_array($userRole, $restrictedRoles);
    
    try {
        $today = date('Y-m-d');
        $yearId = $year ? (int)$year['id'] : 0;
        
        if ($isRestricted) {
            // TEACHER: Only their assigned classes (deduplicated)
            // A teacher can have multiple assignments to the same class
            // (e.g. teaching 2 subjects in grade 1) — GROUP BY ensures 
            // each class appears only once
            $sql = "SELECT c.id, c.class_name, c.class_name_en, c.section,
                           c.level_order, c.age_group,
                           MAX(ta.is_class_teacher) as is_class_teacher,
                           COUNT(DISTINCT ta.subject_id) as subject_count,
                           (SELECT COUNT(*) FROM class_enrollments ce 
                            WHERE ce.class_id = c.id AND ce.status = 'active'
                            " . ($year ? " AND ce.academic_year_id = {$yearId}" : "") . "
                           ) as student_count,
                           (SELECT COUNT(*) FROM attendance a 
                            WHERE a.class_id = c.id AND a.attendance_date = ?
                           ) as attendance_count
                    FROM teacher_assignments ta
                    JOIN classes c ON ta.class_id = c.id
                    WHERE ta.teacher_id = ? 
                      AND ta.is_active = 1
                      AND (ta.academic_year_id IS NULL OR ta.academic_year_id = ?)
                    GROUP BY c.id, c.class_name, c.class_name_en, c.section,
                             c.level_order, c.age_group
                    ORDER BY c.level_order, c.class_name";
            
            $stmt = $conn->prepare($sql);
            $stmt->bind_param('sii', $today, $userId, $yearId);
            $stmt->execute();
            $r = $stmt->get_result();
            
        } else {
            // ADMIN: See all classes
            $yearJoin = $year ? " AND ce.academic_year_id = {$yearId}" : "";
            
            $sql = "SELECT c.id, c.class_name, c.class_name_en, c.section,
                           c.level_order, c.age_group,
                           0 as is_class_teacher,
                           NULL as subject_id,
                           (SELECT COUNT(*) FROM class_enrollments ce 
                            WHERE ce.class_id = c.id AND ce.status = 'active' {$yearJoin}
                           ) as student_count,
                           (SELECT COUNT(*) FROM attendance a 
                            WHERE a.class_id = c.id AND a.attendance_date = ?
                           ) as attendance_count
                    FROM classes c
                    WHERE c.is_active = 1
                    ORDER BY c.level_order, c.class_name";
            
            $stmt = $conn->prepare($sql);
            $stmt->bind_param('s', $today);
            $stmt->execute();
            $r = $stmt->get_result();
        }
        
        while ($row = $r->fetch_assoc()) {
            $row['attendance_taken_today'] = ((int)$row['attendance_count']) > 0;
            $row['section_name'] = $row['section']; // alias for Flutter compatibility
            unset($row['attendance_count']);
            $row['id'] = (int)$row['id'];
            $row['student_count'] = (int)$row['student_count'];
            $row['is_class_teacher'] = (bool)$row['is_class_teacher'];
            $classes[] = $row;
        }
        $stmt->close();
        
    } catch (Exception $e) {
        err('Failed to load classes: ' . $e->getMessage(), 500);
    }
    
    ok(['classes' => $classes, 'count' => count($classes)]);
}

// ============================================================
// GET /classes/{id} — Class details
// ============================================================
if ($method === 'GET' && $id !== null && $sub === null) {
    $id = (int)$id;
    $userId = $auth['uid'];
    $userRole = $auth['rol'];
    
    if (in_array($userRole, ['teacher', 'attendance_taker'])) {
        $yearId = 0;
        $year = getCurrentAcademicYear();
        if ($year) $yearId = (int)$year['id'];
        $check = $conn->prepare("SELECT id FROM teacher_assignments 
                                 WHERE teacher_id = ? AND class_id = ? 
                                 AND (academic_year_id IS NULL OR academic_year_id = ?)
                                 AND is_active = 1");
        $check->bind_param('iii', $userId, $id, $yearId);
        $check->execute();
        if ($check->get_result()->num_rows === 0) {
            err('You are not assigned to this class', 403);
        }
        $check->close();
    }
    
    $class = null;
    try {
        $stmt = $conn->prepare("SELECT * FROM classes WHERE id = ?");
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $class = $stmt->get_result()->fetch_assoc();
        $stmt->close();
    } catch (Exception $e) {}
    
    if (!$class) err('Class not found', 404);
    
    $year = getCurrentAcademicYear();
    if ($year) {
        $yearId = (int)$year['id'];
        try {
            $stmt = $conn->prepare("SELECT ta.teacher_id, u.full_name as teacher_name
                                    FROM teacher_assignments ta 
                                    JOIN users u ON ta.teacher_id = u.id
                                    WHERE ta.class_id = ? 
                                    AND (ta.academic_year_id IS NULL OR ta.academic_year_id = ?)
                                    AND ta.is_class_teacher = 1 
                                    AND ta.is_active = 1
                                    LIMIT 1");
            $stmt->bind_param('ii', $id, $yearId);
            $stmt->execute();
            $class['class_teacher'] = $stmt->get_result()->fetch_assoc();
            $stmt->close();
        } catch (Exception $e) {
            $class['class_teacher'] = null;
        }
    }
    
    ok($class);
}

// ============================================================
// GET /classes/{id}/students — Students in a class
// ============================================================
if ($method === 'GET' && $id !== null && $sub === 'students') {
    $id = (int)$id;
    $userId = $auth['uid'];
    $userRole = $auth['rol'];
    
    if (in_array($userRole, ['teacher', 'attendance_taker'])) {
        $yearId = 0;
        $year = getCurrentAcademicYear();
        if ($year) $yearId = (int)$year['id'];
        $check = $conn->prepare("SELECT id FROM teacher_assignments 
                                 WHERE teacher_id = ? AND class_id = ? 
                                 AND (academic_year_id IS NULL OR academic_year_id = ?)
                                 AND is_active = 1");
        $check->bind_param('iii', $userId, $id, $yearId);
        $check->execute();
        if ($check->get_result()->num_rows === 0) {
            err('You are not assigned to this class', 403);
        }
        $check->close();
    }
    
    $year = getCurrentAcademicYear();
    $students = [];
    
    try {
        if ($year) {
            $stmt = $conn->prepare("SELECT m.id, m.member_code, m.student_name, m.father_name, 
                                           m.gender, m.age_group, m.phone_number, 
                                           m.student_photo_path, m.status
                                    FROM class_enrollments ce 
                                    JOIN members m ON ce.member_id = m.id 
                                    WHERE ce.class_id = ? 
                                      AND ce.academic_year_id = ? 
                                      AND ce.status = 'active'
                                    ORDER BY m.student_name");
            $stmt->bind_param('ii', $id, $year['id']);
        } else {
            $stmt = $conn->prepare("SELECT m.id, m.member_code, m.student_name, m.father_name, 
                                           m.gender, m.age_group, m.phone_number, 
                                           m.student_photo_path, m.status
                                    FROM class_enrollments ce 
                                    JOIN members m ON ce.member_id = m.id 
                                    WHERE ce.class_id = ? AND ce.status = 'active'
                                    ORDER BY m.student_name");
            $stmt->bind_param('i', $id);
        }
        $stmt->execute();
        $r = $stmt->get_result();
        while ($row = $r->fetch_assoc()) {
            $row['id'] = (int)$row['id'];
            $row['photo_url'] = $row['student_photo_path'] 
                ? SITE_URL . '/' . ltrim($row['student_photo_path'], '/') 
                : null;
            unset($row['student_photo_path']);
            $students[] = $row;
        }
        $stmt->close();
    } catch (Exception $e) {
        err('Failed to load students: ' . $e->getMessage(), 500);
    }
    
    ok(['class_id' => $id, 'students' => $students, 'count' => count($students)]);
}

err("No handler for {$method} /classes" . ($id ? "/{$id}" : '') . ($sub ? "/{$sub}" : ''), 404);
