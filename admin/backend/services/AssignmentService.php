<?php
/**
 * Single source of truth for teacher ↔ class ↔ subject assignments.
 *
 * Used by the Assignments board, teacher modal, Education assign_teacher,
 * and subject-to-class catalog updates.
 *
 * Rules (approved):
 *   - One teacher may teach many classes and many subjects.
 *   - One class-subject cell may have one Primary + any number of Assistants.
 *   - Class Teacher (homeroom) is independent of subjects. One per class/year.
 *   - Soft-remove only (is_active = 0). Grades are never deleted.
 */

namespace App\Services;

class AssignmentService
{
    private const ROLES = ['primary', 'assistant', 'homeroom'];

    /** @var array<string,bool> */
    private static $cols = [];
    private static $schemaReady = false;

    /** Compatibility hook; schema is deployment-managed by migration 006. */
    public static function ensureSchema(\mysqli $conn): void
    {
    }

    /**
     * Subjects teachable in a class (audit: empty-dropdown symptom).
     *
     * Source of truth is the class_subjects link table, but when a class has
     * no links configured yet we fall back to every active subject so grade
     * entry is never blocked by missing setup. The caller can tell the two
     * cases apart via 'linked' and show the returned guidance message.
     *
     * @return array{subjects: array[], linked: bool, message: ?string}
     */
    public static function subjectsForClass(\mysqli $conn, int $classId): array
    {
        $subjects = [];
        $stmt = $conn->prepare(
            "SELECT s.* FROM subjects s
             JOIN class_subjects cs ON s.id = cs.subject_id
             WHERE cs.class_id = ? AND s.is_active = 1
             ORDER BY s.subject_name"
        );
        if ($stmt) {
            $stmt->bind_param('i', $classId);
            $stmt->execute();
            $r = $stmt->get_result();
            while ($row = $r->fetch_assoc()) {
                $subjects[] = $row;
            }
            $stmt->close();
        }
        if ($subjects) {
            return ['subjects' => $subjects, 'linked' => true, 'message' => null];
        }

        // Fallback: nothing linked yet — offer every active subject.
        $stmt = $conn->prepare(
            "SELECT * FROM subjects WHERE is_active = 1 ORDER BY subject_name"
        );
        if ($stmt) {
            $stmt->execute();
            $r = $stmt->get_result();
            while ($row = $r->fetch_assoc()) {
                $subjects[] = $row;
            }
            $stmt->close();
        }
        return [
            'subjects' => $subjects,
            'linked' => false,
            'message' => $subjects
                ? 'No subjects are linked to this class yet — showing all subjects. Link them under Subjects → Edit → "Taught in classes".'
                : 'No active subjects exist yet — create subjects first under Subjects.',
        ];
    }

    public static function effectiveYear(\mysqli $conn): ?array
    {
        if (function_exists('ay_resolve')) {
            $resolved = ay_resolve($conn);
            if (!empty($resolved['year']) && is_array($resolved['year'])) {
                return $resolved['year'];
            }
        }
        try {
            $r = $conn->query("SELECT * FROM academic_years WHERE is_current = 1 LIMIT 1");
            return $r ? ($r->fetch_assoc() ?: null) : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * @return array{status:string,message:string,assignment_id?:int,skipped?:bool}
     */
    public static function assign(
        \mysqli $conn,
        int $teacherId,
        int $classId,
        ?int $subjectId,
        string $role = 'primary',
        ?int $yearId = null,
        ?int $assignedBy = null
    ): array {
        self::ensureSchema($conn);

        $role = strtolower(trim($role));
        if ($role === 'class_teacher' || $role === 'homeroom') {
            $role = 'homeroom';
        }
        if (!in_array($role, self::ROLES, true)) {
            $role = 'primary';
        }
        if ($role === 'homeroom') {
            $subjectId = null;
        }

        $check = self::validateActors($conn, $teacherId, $classId, $subjectId, $role);
        if ($check !== null) {
            return $check;
        }

        $yearId = self::resolveYearId($conn, $yearId);
        if (!$yearId) {
            return ['status' => 'error', 'message' => 'No active academic year. A School Admin must set the current year first.'];
        }
        $assignedBy = $assignedBy ?: (int)($_SESSION['admin_id'] ?? 0);

        if ($role === 'homeroom') {
            return self::setHomeroom($conn, $teacherId, $classId, $yearId, $assignedBy);
        }

        $existing = self::findRow($conn, $teacherId, $classId, $subjectId, $yearId, false);
        if ($existing) {
            $eid = (int)$existing['id'];
            if (self::isActiveRow($existing)) {
                if ($role === 'primary') {
                    self::promotePrimary($conn, $eid, $classId, $subjectId, $yearId);
                }
                return [
                    'status' => 'success',
                    'message' => 'This teacher is already assigned here.',
                    'assignment_id' => $eid,
                    'skipped' => true,
                ];
            }
            self::reactivate($conn, $eid, $role, $assignedBy);
            if ($role === 'primary') {
                self::promotePrimary($conn, $eid, $classId, $subjectId, $yearId);
            } else {
                self::ensureCellHasPrimary($conn, $classId, $subjectId, $yearId);
            }
            self::touchTeacherMember($conn, $teacherId, true);
            return [
                'status' => 'success',
                'message' => 'Assignment restored.',
                'assignment_id' => $eid,
            ];
        }

        $hasPrimary = self::cellHasPrimary($conn, $classId, $subjectId, $yearId);
        if ($role === 'assistant' && !$hasPrimary) {
            $role = 'primary';
        }

        $isPrimary = $role === 'primary' ? 1 : 0;
        $hasRoleCol = self::hasColumn($conn, 'teacher_assignments', 'assignment_role');
        $hasPrimaryCol = self::hasColumn($conn, 'teacher_assignments', 'is_primary');
        $hasActiveCol = self::hasColumn($conn, 'teacher_assignments', 'is_active');

        $cols = ['teacher_id', 'class_id', 'subject_id', 'academic_year_id', 'assigned_by'];
        $vals = ['?', '?', '?', '?', '?'];
        $types = 'iiiii';
        $bind = [$teacherId, $classId, $subjectId, $yearId, $assignedBy];

        if ($hasRoleCol) {
            $cols[] = 'assignment_role';
            $vals[] = '?';
            $types .= 's';
            $bind[] = $role;
        }
        if ($hasPrimaryCol) {
            $cols[] = 'is_primary';
            $vals[] = '?';
            $types .= 'i';
            $bind[] = $isPrimary;
        }
        if ($hasActiveCol) {
            $cols[] = 'is_active';
            $vals[] = '1';
        }
        if (self::hasColumn($conn, 'teacher_assignments', 'is_class_teacher')) {
            $cols[] = 'is_class_teacher';
            $vals[] = '0';
        }

        $sql = 'INSERT INTO teacher_assignments (' . implode(',', $cols) . ') VALUES (' . implode(',', $vals) . ')';
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            return ['status' => 'error', 'message' => 'Could not prepare assignment.'];
        }
        $stmt->bind_param($types, ...$bind);
        if (!$stmt->execute()) {
            $err = $stmt->error;
            $stmt->close();
            if (stripos($err, 'duplicate') !== false) {
                $again = self::findRow($conn, $teacherId, $classId, $subjectId, $yearId, false);
                if ($again) {
                    self::reactivate($conn, (int)$again['id'], $role, $assignedBy);
                    if ($role === 'primary') {
                        self::promotePrimary($conn, (int)$again['id'], $classId, $subjectId, $yearId);
                    }
                    return [
                        'status' => 'success',
                        'message' => 'Assignment updated.',
                        'assignment_id' => (int)$again['id'],
                    ];
                }
            }
            return ['status' => 'error', 'message' => 'Could not save assignment.'];
        }
        $newId = (int)$stmt->insert_id;
        $stmt->close();

        if ($role === 'primary') {
            self::promotePrimary($conn, $newId, $classId, $subjectId, $yearId);
        }

        self::touchTeacherMember($conn, $teacherId, true);
        $names = self::names($conn, $teacherId, $classId, $subjectId);
        return [
            'status' => 'success',
            'message' => ($names['teacher'] ?: 'Teacher') . ' assigned to ' . ($names['class'] ?: 'class')
                . ($names['subject'] ? ' — ' . $names['subject'] : '') . '.',
            'assignment_id' => $newId,
        ];
    }

    /**
     * @param int[] $classIds
     * @return array{status:string,message:string,assigned:int,skipped:int,failed:int}
     */
    public static function assignBulk(
        \mysqli $conn,
        int $teacherId,
        array $classIds,
        ?int $subjectId,
        string $role = 'primary',
        ?int $yearId = null,
        ?int $assignedBy = null
    ): array {
        $classIds = array_values(array_unique(array_filter(array_map('intval', $classIds))));
        if (!$teacherId || !$classIds) {
            return ['status' => 'error', 'message' => 'Teacher and at least one class are required.', 'assigned' => 0, 'skipped' => 0, 'failed' => 0];
        }

        $ok = 0;
        $skip = 0;
        $fail = 0;
        $conn->begin_transaction();
        try {
            foreach ($classIds as $cid) {
                $res = self::assign($conn, $teacherId, $cid, $subjectId, $role, $yearId, $assignedBy);
                if (($res['status'] ?? '') === 'success') {
                    if (!empty($res['skipped'])) {
                        $skip++;
                    } else {
                        $ok++;
                    }
                } else {
                    $fail++;
                }
            }
            $conn->commit();
        } catch (\Throwable $e) {
            $conn->rollback();
            error_log('AssignmentService::assignBulk: ' . $e->getMessage());
            return ['status' => 'error', 'message' => 'Bulk assign failed. No changes were saved.', 'assigned' => 0, 'skipped' => 0, 'failed' => count($classIds)];
        }

        $msg = $ok . ' class(es) assigned';
        if ($skip) {
            $msg .= ', ' . $skip . ' already set';
        }
        if ($fail) {
            $msg .= ', ' . $fail . ' failed';
        }
        return [
            'status' => $ok + $skip > 0 ? 'success' : 'error',
            'message' => $msg . '.',
            'assigned' => $ok,
            'skipped' => $skip,
            'failed' => $fail,
        ];
    }

    /**
     * @return array{status:string,message:string,assignment_id?:int}
     */
    public static function setHomeroom(
        \mysqli $conn,
        int $teacherId,
        int $classId,
        ?int $yearId = null,
        ?int $assignedBy = null
    ): array {
        self::ensureSchema($conn);
        $check = self::validateActors($conn, $teacherId, $classId, null, 'homeroom');
        if ($check !== null) {
            return $check;
        }
        $yearId = self::resolveYearId($conn, $yearId);
        if (!$yearId) {
            return ['status' => 'error', 'message' => 'No active academic year.'];
        }
        $assignedBy = $assignedBy ?: (int)($_SESSION['admin_id'] ?? 0);

        $conn->begin_transaction();
        try {
            if (self::hasColumn($conn, 'teacher_assignments', 'is_class_teacher')) {
                $clr = $conn->prepare(
                    "UPDATE teacher_assignments SET is_class_teacher = 0
                     WHERE class_id = ? AND academic_year_id = ? AND is_class_teacher = 1"
                );
                if ($clr) {
                    $clr->bind_param('ii', $classId, $yearId);
                    $clr->execute();
                    $clr->close();
                }
            }

            $existing = self::findRow($conn, $teacherId, $classId, null, $yearId, false);
            if ($existing) {
                $eid = (int)$existing['id'];
                $sql = "UPDATE teacher_assignments SET is_active = 1, is_class_teacher = 1";
                if (self::hasColumn($conn, 'teacher_assignments', 'assignment_role')) {
                    $sql .= ", assignment_role = 'homeroom'";
                }
                if (self::hasColumn($conn, 'teacher_assignments', 'assigned_by') && $assignedBy) {
                    $sql .= ", assigned_by = " . (int)$assignedBy;
                }
                $sql .= " WHERE id = ?";
                $up = $conn->prepare($sql);
                if ($up) {
                    $up->bind_param('i', $eid);
                    $up->execute();
                    $up->close();
                }
                $conn->commit();
                self::touchTeacherMember($conn, $teacherId, true);
                $names = self::names($conn, $teacherId, $classId, null);
                return [
                    'status' => 'success',
                    'message' => ($names['teacher'] ?: 'Teacher') . ' is now Class Teacher of ' . ($names['class'] ?: 'class') . '.',
                    'assignment_id' => $eid,
                ];
            }

            $cols = ['teacher_id', 'class_id', 'subject_id', 'academic_year_id', 'is_class_teacher', 'assigned_by'];
            $ph = ['?', '?', 'NULL', '?', '1', '?'];
            $types = 'iiii';
            $bind = [$teacherId, $classId, $yearId, $assignedBy];
            if (self::hasColumn($conn, 'teacher_assignments', 'is_active')) {
                $cols[] = 'is_active';
                $ph[] = '1';
            }
            if (self::hasColumn($conn, 'teacher_assignments', 'assignment_role')) {
                $cols[] = 'assignment_role';
                $ph[] = "'homeroom'";
            }
            if (self::hasColumn($conn, 'teacher_assignments', 'is_primary')) {
                $cols[] = 'is_primary';
                $ph[] = '0';
            }
            $ins = $conn->prepare('INSERT INTO teacher_assignments (' . implode(',', $cols) . ') VALUES (' . implode(',', $ph) . ')');
            if (!$ins) {
                throw new \RuntimeException($conn->error);
            }
            $ins->bind_param($types, ...$bind);
            if (!$ins->execute()) {
                throw new \RuntimeException($ins->error);
            }
            $newId = (int)$ins->insert_id;
            $ins->close();
            $conn->commit();
            self::touchTeacherMember($conn, $teacherId, true);
            $names = self::names($conn, $teacherId, $classId, null);
            return [
                'status' => 'success',
                'message' => ($names['teacher'] ?: 'Teacher') . ' is now Class Teacher of ' . ($names['class'] ?: 'class') . '.',
                'assignment_id' => $newId,
            ];
        } catch (\Throwable $e) {
            $conn->rollback();
            error_log('AssignmentService::setHomeroom: ' . $e->getMessage());
            return ['status' => 'error', 'message' => 'Could not set Class Teacher.'];
        }
    }

    /**
     * @return array{status:string,message:string}
     */
    public static function setPrimary(\mysqli $conn, int $assignmentId): array
    {
        self::ensureSchema($conn);
        $row = self::byId($conn, $assignmentId);
        if (!$row || !self::isActiveRow($row)) {
            return ['status' => 'error', 'message' => 'Assignment not found.'];
        }
        if (empty($row['subject_id'])) {
            return ['status' => 'error', 'message' => 'Class Teacher is not a subject assignment.'];
        }
        self::promotePrimary($conn, $assignmentId, (int)$row['class_id'], (int)$row['subject_id'], (int)$row['academic_year_id']);
        return ['status' => 'success', 'message' => 'Set as primary teacher.'];
    }

    /**
     * @return array{status:string,message:string}
     */
    public static function unassign(\mysqli $conn, int $assignmentId): array
    {
        self::ensureSchema($conn);
        $row = self::byId($conn, $assignmentId);
        if (!$row) {
            return ['status' => 'error', 'message' => 'Assignment not found.'];
        }
        if (!self::isActiveRow($row)) {
            return ['status' => 'success', 'message' => 'Assignment already removed.', 'skipped' => true];
        }

        if (self::hasColumn($conn, 'teacher_assignments', 'is_active')) {
            $stmt = $conn->prepare("UPDATE teacher_assignments SET is_active = 0, is_class_teacher = 0 WHERE id = ?");
            if (!$stmt) {
                return ['status' => 'error', 'message' => 'Could not remove assignment.'];
            }
            $stmt->bind_param('i', $assignmentId);
            $ok = $stmt->execute();
            $stmt->close();
        } else {
            $stmt = $conn->prepare("DELETE FROM teacher_assignments WHERE id = ?");
            if (!$stmt) {
                return ['status' => 'error', 'message' => 'Could not remove assignment.'];
            }
            $stmt->bind_param('i', $assignmentId);
            $ok = $stmt->execute();
            $stmt->close();
        }
        if (!$ok) {
            return ['status' => 'error', 'message' => 'Could not remove assignment.'];
        }

        if (!empty($row['subject_id'])) {
            self::ensureCellHasPrimary($conn, (int)$row['class_id'], (int)$row['subject_id'], (int)($row['academic_year_id'] ?? 0));
        }
        return ['status' => 'success', 'message' => 'Assignment removed. Grades were not changed.'];
    }

    /**
     * Replace this teacher's regular + homeroom set for the current year.
     * Rows with a subject stay regular; homeroom is only the listed classes.
     *
     * @param array<int, array{class_id?:int,subject_id?:int|null}> $assignments
     * @param int[] $homeroomClassIds
     * @return array{status:string,message:string,assigned:int,removed:int}
     */
    public static function syncForTeacher(
        \mysqli $conn,
        int $teacherId,
        array $assignments,
        array $homeroomClassIds,
        ?int $yearId = null,
        ?int $assignedBy = null
    ): array {
        self::ensureSchema($conn);
        $yearId = self::resolveYearId($conn, $yearId);
        if (!$yearId) {
            return ['status' => 'error', 'message' => 'No active academic year.', 'assigned' => 0, 'removed' => 0];
        }
        $assignedBy = $assignedBy ?: (int)($_SESSION['admin_id'] ?? 0);

        $desiredRegular = [];
        foreach ($assignments as $row) {
            if (!is_array($row)) {
                continue;
            }
            $cid = (int)($row['class_id'] ?? 0);
            $sid = !empty($row['subject_id']) ? (int)$row['subject_id'] : 0;
            if ($cid <= 0 || $sid <= 0) {
                continue;
            }
            $desiredRegular[$cid . ':' . $sid] = ['class_id' => $cid, 'subject_id' => $sid];
        }
        $desiredHome = [];
        foreach ($homeroomClassIds as $cid) {
            $cid = (int)$cid;
            if ($cid > 0) {
                $desiredHome[$cid] = $cid;
            }
        }

        $current = [];
        $stmt = $conn->prepare(
            "SELECT id, class_id, subject_id, is_class_teacher, assignment_role
             FROM teacher_assignments
             WHERE teacher_id = ? AND academic_year_id = ? AND is_active = 1"
        );
        if ($stmt) {
            $stmt->bind_param('ii', $teacherId, $yearId);
            $stmt->execute();
            $res = $stmt->get_result();
            while ($row = $res->fetch_assoc()) {
                $current[] = $row;
            }
            $stmt->close();
        }

        $keptRegular = [];
        $keptHome = [];
        $removed = 0;
        foreach ($current as $row) {
            $aid = (int)$row['id'];
            $cid = (int)$row['class_id'];
            $sid = !empty($row['subject_id']) ? (int)$row['subject_id'] : 0;
            $isHome = !empty($row['is_class_teacher']) || (($row['assignment_role'] ?? '') === 'homeroom');
            if ($sid > 0) {
                $key = $cid . ':' . $sid;
                if (isset($desiredRegular[$key])) {
                    $keptRegular[$key] = true;
                    continue;
                }
                $res = self::unassign($conn, $aid);
                if (($res['status'] ?? '') === 'success') {
                    $removed++;
                }
                continue;
            }
            if ($isHome) {
                if (isset($desiredHome[$cid])) {
                    $keptHome[$cid] = true;
                    continue;
                }
                $res = self::unassign($conn, $aid);
                if (($res['status'] ?? '') === 'success') {
                    $removed++;
                }
            }
        }

        $assigned = 0;
        foreach ($desiredRegular as $key => $pair) {
            if (!empty($keptRegular[$key])) {
                continue;
            }
            $res = self::assign(
                $conn,
                $teacherId,
                $pair['class_id'],
                $pair['subject_id'],
                'primary',
                $yearId,
                $assignedBy
            );
            if (($res['status'] ?? '') === 'success' && empty($res['skipped'])) {
                $assigned++;
            }
        }
        foreach ($desiredHome as $cid) {
            if (!empty($keptHome[$cid])) {
                continue;
            }
            $res = self::setHomeroom($conn, $teacherId, $cid, $yearId, $assignedBy);
            if (($res['status'] ?? '') === 'success') {
                $assigned++;
            }
        }

        $msg = 'Assignments saved.';
        if ($assigned || $removed) {
            $msg = $assigned . ' added, ' . $removed . ' removed.';
        }
        return [
            'status' => 'success',
            'message' => $msg,
            'assigned' => $assigned,
            'removed' => $removed,
        ];
    }

    /**
     * Replace the class list for one subject (catalog). Does not touch teacher rows.
     *
     * @param int[] $classIds
     * @return array{status:string,message:string,count:int}
     */
    public static function setClassSubjects(\mysqli $conn, int $subjectId, array $classIds): array
    {
        self::ensureSchema($conn);
        if ($subjectId <= 0) {
            return ['status' => 'error', 'message' => 'Subject is required.', 'count' => 0];
        }
        $chk = $conn->prepare("SELECT id FROM subjects WHERE id = ? AND is_active = 1 LIMIT 1");
        if (!$chk) {
            return ['status' => 'error', 'message' => 'Subject not found.', 'count' => 0];
        }
        $chk->bind_param('i', $subjectId);
        $chk->execute();
        if (!$chk->get_result()->fetch_assoc()) {
            $chk->close();
            return ['status' => 'error', 'message' => 'Subject not found or inactive.', 'count' => 0];
        }
        $chk->close();

        $classIds = array_values(array_unique(array_filter(array_map('intval', $classIds))));
        $conn->begin_transaction();
        try {
            $del = $conn->prepare("DELETE FROM class_subjects WHERE subject_id = ?");
            $del->bind_param('i', $subjectId);
            $del->execute();
            $del->close();
            $n = 0;
            if ($classIds) {
                $ins = $conn->prepare("INSERT INTO class_subjects (class_id, subject_id) VALUES (?, ?)");
                foreach ($classIds as $cid) {
                    if ($cid <= 0) {
                        continue;
                    }
                    $ins->bind_param('ii', $cid, $subjectId);
                    if ($ins->execute()) {
                        $n++;
                    }
                }
                $ins->close();
            }
            $conn->commit();
            return [
                'status' => 'success',
                'message' => 'Subject assigned to ' . $n . ' class(es).',
                'count' => $n,
            ];
        } catch (\Throwable $e) {
            $conn->rollback();
            error_log('AssignmentService::setClassSubjects: ' . $e->getMessage());
            return ['status' => 'error', 'message' => 'Could not update class list.', 'count' => 0];
        }
    }

    /**
     * @return array{status:string,year:?array,classes:array,subjects:array,class_subjects:array,cells:array,homerooms:array}
     */
    public static function matrix(\mysqli $conn, ?int $yearId = null): array
    {
        self::ensureSchema($conn);
        $year = $yearId ? self::yearById($conn, $yearId) : self::effectiveYear($conn);
        $yearId = $year ? (int)$year['id'] : 0;

        $classes = [];
        $r = $conn->query("SELECT id, class_name, class_name_en, class_code, level_order FROM classes WHERE is_active = 1 ORDER BY level_order, class_name");
        if ($r) {
            while ($row = $r->fetch_assoc()) {
                $classes[] = $row;
            }
        }

        $subjects = [];
        $r = $conn->query("SELECT id, subject_name, subject_name_en, subject_code FROM subjects WHERE is_active = 1 ORDER BY subject_name");
        if ($r) {
            while ($row = $r->fetch_assoc()) {
                $subjects[] = $row;
            }
        }

        $classSubjects = [];
        $r = $conn->query("SELECT class_id, subject_id FROM class_subjects");
        if ($r) {
            while ($row = $r->fetch_assoc()) {
                $cid = (int)$row['class_id'];
                if (!isset($classSubjects[$cid])) {
                    $classSubjects[$cid] = [];
                }
                $classSubjects[$cid][] = (int)$row['subject_id'];
            }
        }

        $cells = [];
        $homerooms = [];
        if ($yearId) {
            $sql = "SELECT ta.id, ta.teacher_id, ta.class_id, ta.subject_id, ta.is_class_teacher, ta.is_primary,
                           " . (self::hasColumn($conn, 'teacher_assignments', 'assignment_role') ? "ta.assignment_role," : "NULL AS assignment_role,") . "
                           u.full_name
                    FROM teacher_assignments ta
                    JOIN users u ON u.id = ta.teacher_id
                    WHERE ta.is_active = 1 AND ta.academic_year_id = ?";
            $stmt = $conn->prepare($sql);
            if ($stmt) {
                $stmt->bind_param('i', $yearId);
                $stmt->execute();
                $res = $stmt->get_result();
                while ($row = $res->fetch_assoc()) {
                    $role = self::normalizeRole($row);
                    $pack = [
                        'id' => (int)$row['id'],
                        'teacher_id' => (int)$row['teacher_id'],
                        'full_name' => $row['full_name'] ?? '',
                        'role' => $role,
                    ];
                    if (!empty($row['subject_id'])) {
                        $key = (int)$row['class_id'] . '-' . (int)$row['subject_id'];
                        if (!isset($cells[$key])) {
                            $cells[$key] = [];
                        }
                        $cells[$key][] = $pack;
                    }
                    if (!empty($row['is_class_teacher']) || $role === 'homeroom') {
                        $homerooms[(int)$row['class_id']] = $pack;
                    }
                }
                $stmt->close();
            }
        }

        foreach ($cells as $k => $list) {
            usort($list, static function ($a, $b) {
                $aw = $a['role'] === 'primary' ? 0 : 1;
                $bw = $b['role'] === 'primary' ? 0 : 1;
                if ($aw !== $bw) {
                    return $aw - $bw;
                }
                return strcasecmp($a['full_name'], $b['full_name']);
            });
            $cells[$k] = $list;
        }

        return [
            'status' => 'success',
            'year' => $year ? [
                'id' => (int)$year['id'],
                'year_name' => $year['year_name'] ?? '',
            ] : null,
            'classes' => $classes,
            'subjects' => $subjects,
            'class_subjects' => $classSubjects,
            'cells' => $cells,
            'homerooms' => $homerooms,
        ];
    }

    /**
     * @return array{status:string,teachers:array}
     */
    public static function workload(\mysqli $conn, ?int $yearId = null): array
    {
        self::ensureSchema($conn);
        $year = $yearId ? self::yearById($conn, $yearId) : self::effectiveYear($conn);
        $yearId = $year ? (int)$year['id'] : 0;
        $teachers = [];

        $sql = "SELECT u.id, u.full_name, u.username, u.is_active
                FROM users u
                WHERE u.role = 'teacher' AND u.is_active = 1
                ORDER BY u.full_name";
        $r = $conn->query($sql);
        $ids = [];
        if ($r) {
            while ($row = $r->fetch_assoc()) {
                $tid = (int)$row['id'];
                $ids[] = $tid;
                $teachers[$tid] = [
                    'id' => $tid,
                    'full_name' => $row['full_name'] ?? '',
                    'username' => $row['username'] ?? '',
                    'classes' => 0,
                    'subjects' => 0,
                    'homerooms' => 0,
                    'assignments' => 0,
                ];
            }
        }
        if (!$ids || !$yearId) {
            return ['status' => 'success', 'teachers' => array_values($teachers)];
        }

        $in = implode(',', array_map('intval', $ids));
        $q = $conn->query(
            "SELECT teacher_id,
                    COUNT(*) AS assignments,
                    COUNT(DISTINCT class_id) AS classes,
                    COUNT(DISTINCT CASE WHEN subject_id IS NOT NULL THEN subject_id END) AS subjects,
                    SUM(CASE WHEN is_class_teacher = 1 THEN 1 ELSE 0 END) AS homerooms
             FROM teacher_assignments
             WHERE is_active = 1 AND academic_year_id = {$yearId} AND teacher_id IN ({$in})
             GROUP BY teacher_id"
        );
        if ($q) {
            while ($row = $q->fetch_assoc()) {
                $tid = (int)$row['teacher_id'];
                if (!isset($teachers[$tid])) {
                    continue;
                }
                $teachers[$tid]['assignments'] = (int)$row['assignments'];
                $teachers[$tid]['classes'] = (int)$row['classes'];
                $teachers[$tid]['subjects'] = (int)$row['subjects'];
                $teachers[$tid]['homerooms'] = (int)$row['homerooms'];
            }
        }
        return ['status' => 'success', 'teachers' => array_values($teachers)];
    }

    /**
     * @return array{status:string,no_homeroom:array,uncovered_subjects:array,idle_teachers:array}
     */
    public static function gaps(\mysqli $conn, ?int $yearId = null): array
    {
        self::ensureSchema($conn);
        $year = $yearId ? self::yearById($conn, $yearId) : self::effectiveYear($conn);
        $yearId = $year ? (int)$year['id'] : 0;

        $noHomeroom = [];
        $uncovered = [];
        $idle = [];

        $classes = [];
        $r = $conn->query("SELECT id, class_name, class_name_en FROM classes WHERE is_active = 1 ORDER BY level_order");
        if ($r) {
            while ($row = $r->fetch_assoc()) {
                $classes[(int)$row['id']] = $row;
            }
        }
        $subjects = [];
        $r = $conn->query("SELECT id, subject_name, subject_name_en FROM subjects WHERE is_active = 1");
        if ($r) {
            while ($row = $r->fetch_assoc()) {
                $subjects[(int)$row['id']] = $row;
            }
        }

        $coveredHome = [];
        $coveredCell = [];
        if ($yearId) {
            $stmt = $conn->prepare(
                "SELECT class_id, subject_id, is_class_teacher FROM teacher_assignments
                 WHERE is_active = 1 AND academic_year_id = ?"
            );
            if ($stmt) {
                $stmt->bind_param('i', $yearId);
                $stmt->execute();
                $res = $stmt->get_result();
                while ($row = $res->fetch_assoc()) {
                    if (!empty($row['is_class_teacher'])) {
                        $coveredHome[(int)$row['class_id']] = true;
                    }
                    if (!empty($row['subject_id'])) {
                        $coveredCell[(int)$row['class_id'] . '-' . (int)$row['subject_id']] = true;
                    }
                }
                $stmt->close();
            }
        }

        foreach ($classes as $cid => $c) {
            if (empty($coveredHome[$cid])) {
                $noHomeroom[] = [
                    'class_id' => $cid,
                    'class_name' => $c['class_name'],
                    'class_name_en' => $c['class_name_en'] ?? '',
                ];
            }
        }

        $r = $conn->query("SELECT class_id, subject_id FROM class_subjects");
        if ($r) {
            while ($row = $r->fetch_assoc()) {
                $cid = (int)$row['class_id'];
                $sid = (int)$row['subject_id'];
                if (!isset($classes[$cid]) || !isset($subjects[$sid])) {
                    continue;
                }
                $key = $cid . '-' . $sid;
                if (empty($coveredCell[$key])) {
                    $uncovered[] = [
                        'class_id' => $cid,
                        'class_name' => $classes[$cid]['class_name'],
                        'subject_id' => $sid,
                        'subject_name' => $subjects[$sid]['subject_name'],
                    ];
                }
            }
        }

        $assigned = [];
        if ($yearId) {
            $r = $conn->query("SELECT DISTINCT teacher_id FROM teacher_assignments WHERE is_active = 1 AND academic_year_id = " . (int)$yearId);
            if ($r) {
                while ($row = $r->fetch_assoc()) {
                    $assigned[(int)$row['teacher_id']] = true;
                }
            }
        }
        $r = $conn->query("SELECT id, full_name, username FROM users WHERE role = 'teacher' AND is_active = 1 ORDER BY full_name");
        if ($r) {
            while ($row = $r->fetch_assoc()) {
                if (empty($assigned[(int)$row['id']])) {
                    $idle[] = [
                        'id' => (int)$row['id'],
                        'full_name' => $row['full_name'] ?? '',
                        'username' => $row['username'] ?? '',
                    ];
                }
            }
        }

        return [
            'status' => 'success',
            'no_homeroom' => $noHomeroom,
            'uncovered_subjects' => $uncovered,
            'idle_teachers' => $idle,
        ];
    }

    /**
     * Paged teacher search for pickers. Returns name/username only (no PII).
     *
     * @return array{status:string,teachers:array,total:int,page:int,pages:int}
     */
    public static function searchTeachers(\mysqli $conn, string $q = '', int $page = 1, int $perPage = 25): array
    {
        $page = max(1, $page);
        $perPage = min(50, max(10, $perPage));
        $offset = ($page - 1) * $perPage;
        $q = trim($q);

        $w = ["u.role = 'teacher'", 'u.is_active = 1'];
        $p = [];
        $t = '';
        if ($q !== '') {
            $w[] = '(u.full_name LIKE ? OR u.username LIKE ?)';
            $st = '%' . $q . '%';
            $p[] = $st;
            $p[] = $st;
            $t .= 'ss';
        }
        $wc = implode(' AND ', $w);

        $total = 0;
        if ($t !== '') {
            $stmt = $conn->prepare("SELECT COUNT(*) AS c FROM users u WHERE $wc");
            $stmt->bind_param($t, ...$p);
            $stmt->execute();
            $total = (int)($stmt->get_result()->fetch_assoc()['c'] ?? 0);
            $stmt->close();
        } else {
            $r = $conn->query("SELECT COUNT(*) AS c FROM users u WHERE $wc");
            $total = $r ? (int)$r->fetch_assoc()['c'] : 0;
        }

        $sql = "SELECT u.id, u.full_name, u.username FROM users u WHERE $wc ORDER BY u.full_name LIMIT ? OFFSET ?";
        $fp = $p;
        $ft = $t . 'ii';
        $fp[] = $perPage;
        $fp[] = $offset;
        $stmt = $conn->prepare($sql);
        $stmt->bind_param($ft, ...$fp);
        $stmt->execute();
        $teachers = [];
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) {
            $teachers[] = [
                'id' => (int)$row['id'],
                'full_name' => $row['full_name'] ?? '',
                'username' => $row['username'] ?? '',
            ];
        }
        $stmt->close();

        return [
            'status' => 'success',
            'teachers' => $teachers,
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
            'pages' => $perPage > 0 ? (int)ceil($total / $perPage) : 1,
        ];
    }

    // ── internals ──────────────────────────────────────────

    private static function validateActors(\mysqli $conn, int $teacherId, int $classId, ?int $subjectId, string $role): ?array
    {
        if ($teacherId <= 0 || $classId <= 0) {
            return ['status' => 'error', 'message' => 'Teacher and class are required.'];
        }
        $stmt = $conn->prepare("SELECT id, is_active FROM users WHERE id = ? AND role = 'teacher' LIMIT 1");
        if (!$stmt) {
            return ['status' => 'error', 'message' => 'Teacher not found.'];
        }
        $stmt->bind_param('i', $teacherId);
        $stmt->execute();
        $t = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$t) {
            return ['status' => 'error', 'message' => 'Teacher not found.'];
        }
        if ((int)$t['is_active'] !== 1) {
            return ['status' => 'error', 'message' => 'That teacher account is inactive.'];
        }

        $stmt = $conn->prepare("SELECT id FROM classes WHERE id = ? AND is_active = 1 LIMIT 1");
        $stmt->bind_param('i', $classId);
        $stmt->execute();
        $ok = (bool)$stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$ok) {
            return ['status' => 'error', 'message' => 'Class not found or inactive.'];
        }

        if ($role !== 'homeroom') {
            if (!$subjectId) {
                return ['status' => 'error', 'message' => 'Subject is required for a teaching assignment.'];
            }
            $stmt = $conn->prepare("SELECT id FROM subjects WHERE id = ? AND is_active = 1 LIMIT 1");
            $stmt->bind_param('i', $subjectId);
            $stmt->execute();
            $ok = (bool)$stmt->get_result()->fetch_assoc();
            $stmt->close();
            if (!$ok) {
                return ['status' => 'error', 'message' => 'Subject not found or inactive.'];
            }
        }
        return null;
    }

    private static function resolveYearId(\mysqli $conn, ?int $yearId): int
    {
        if ($yearId) {
            return $yearId;
        }
        $y = self::effectiveYear($conn);
        return $y ? (int)$y['id'] : 0;
    }

    private static function yearById(\mysqli $conn, int $id): ?array
    {
        $stmt = $conn->prepare("SELECT * FROM academic_years WHERE id = ? LIMIT 1");
        if (!$stmt) {
            return null;
        }
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $row ?: null;
    }

    private static function findRow(\mysqli $conn, int $teacherId, int $classId, ?int $subjectId, int $yearId, bool $activeOnly): ?array
    {
        if ($subjectId) {
            $sql = "SELECT * FROM teacher_assignments
                    WHERE teacher_id = ? AND class_id = ? AND subject_id = ? AND academic_year_id = ?";
            if ($activeOnly) {
                $sql .= " AND is_active = 1";
            }
            $sql .= " ORDER BY is_active DESC, id DESC LIMIT 1";
            $stmt = $conn->prepare($sql);
            if (!$stmt) {
                return null;
            }
            $stmt->bind_param('iiii', $teacherId, $classId, $subjectId, $yearId);
        } else {
            $sql = "SELECT * FROM teacher_assignments
                    WHERE teacher_id = ? AND class_id = ? AND subject_id IS NULL AND academic_year_id = ?";
            if ($activeOnly) {
                $sql .= " AND is_active = 1";
            }
            $sql .= " ORDER BY is_active DESC, id DESC LIMIT 1";
            $stmt = $conn->prepare($sql);
            if (!$stmt) {
                return null;
            }
            $stmt->bind_param('iii', $teacherId, $classId, $yearId);
        }
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $row ?: null;
    }

    private static function byId(\mysqli $conn, int $id): ?array
    {
        $stmt = $conn->prepare("SELECT * FROM teacher_assignments WHERE id = ? LIMIT 1");
        if (!$stmt) {
            return null;
        }
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $row ?: null;
    }

    private static function isActiveRow(array $row): bool
    {
        if (array_key_exists('is_active', $row)) {
            return (int)$row['is_active'] === 1;
        }
        return ($row['status'] ?? 'active') === 'active';
    }

    private static function reactivate(\mysqli $conn, int $id, string $role, int $assignedBy): void
    {
        $sql = "UPDATE teacher_assignments SET is_active = 1";
        if (self::hasColumn($conn, 'teacher_assignments', 'assignment_role')) {
            $sql .= ", assignment_role = '" . ($role === 'assistant' ? 'assistant' : ($role === 'homeroom' ? 'homeroom' : 'primary')) . "'";
        }
        if (self::hasColumn($conn, 'teacher_assignments', 'is_primary')) {
            $sql .= ", is_primary = " . ($role === 'primary' ? 1 : 0);
        }
        if (self::hasColumn($conn, 'teacher_assignments', 'is_class_teacher')) {
            $sql .= ", is_class_teacher = " . ($role === 'homeroom' ? 1 : 0);
        }
        if (self::hasColumn($conn, 'teacher_assignments', 'assigned_by') && $assignedBy) {
            $sql .= ", assigned_by = " . (int)$assignedBy;
        }
        $sql .= " WHERE id = " . (int)$id;
        $conn->query($sql);
    }

    private static function promotePrimary(\mysqli $conn, int $assignmentId, int $classId, int $subjectId, int $yearId): void
    {
        if (self::hasColumn($conn, 'teacher_assignments', 'is_primary')) {
            $down = $conn->prepare(
                "UPDATE teacher_assignments SET is_primary = 0"
                . (self::hasColumn($conn, 'teacher_assignments', 'assignment_role') ? ", assignment_role = IF(id = ?, 'primary', 'assistant')" : "")
                . " WHERE class_id = ? AND subject_id = ? AND academic_year_id = ? AND is_active = 1"
            );
            if ($down) {
                if (self::hasColumn($conn, 'teacher_assignments', 'assignment_role')) {
                    $down->bind_param('iiii', $assignmentId, $classId, $subjectId, $yearId);
                } else {
                    $down->bind_param('iii', $classId, $subjectId, $yearId);
                }
                $down->execute();
                $down->close();
            }
            $up = $conn->prepare("UPDATE teacher_assignments SET is_primary = 1 WHERE id = ?");
            if ($up) {
                $up->bind_param('i', $assignmentId);
                $up->execute();
                $up->close();
            }
        } elseif (self::hasColumn($conn, 'teacher_assignments', 'assignment_role')) {
            $down = $conn->prepare(
                "UPDATE teacher_assignments SET assignment_role = IF(id = ?, 'primary', 'assistant')
                 WHERE class_id = ? AND subject_id = ? AND academic_year_id = ? AND is_active = 1 AND subject_id IS NOT NULL"
            );
            if ($down) {
                $down->bind_param('iiii', $assignmentId, $classId, $subjectId, $yearId);
                $down->execute();
                $down->close();
            }
        }
    }

    private static function cellHasPrimary(\mysqli $conn, int $classId, int $subjectId, int $yearId): bool
    {
        $sql = "SELECT id FROM teacher_assignments
                WHERE class_id = ? AND subject_id = ? AND academic_year_id = ? AND is_active = 1";
        if (self::hasColumn($conn, 'teacher_assignments', 'is_primary')) {
            $sql .= " AND is_primary = 1";
        } elseif (self::hasColumn($conn, 'teacher_assignments', 'assignment_role')) {
            $sql .= " AND assignment_role = 'primary'";
        }
        $sql .= " LIMIT 1";
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            return false;
        }
        $stmt->bind_param('iii', $classId, $subjectId, $yearId);
        $stmt->execute();
        $ok = (bool)$stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $ok;
    }

    private static function ensureCellHasPrimary(\mysqli $conn, int $classId, int $subjectId, int $yearId): void
    {
        if (!$subjectId || !$yearId) {
            return;
        }
        if (self::cellHasPrimary($conn, $classId, $subjectId, $yearId)) {
            return;
        }
        $stmt = $conn->prepare(
            "SELECT id FROM teacher_assignments
             WHERE class_id = ? AND subject_id = ? AND academic_year_id = ? AND is_active = 1
             ORDER BY id ASC LIMIT 1"
        );
        if (!$stmt) {
            return;
        }
        $stmt->bind_param('iii', $classId, $subjectId, $yearId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($row) {
            self::promotePrimary($conn, (int)$row['id'], $classId, $subjectId, $yearId);
        }
    }

    private static function normalizeRole(array $row): string
    {
        $role = $row['assignment_role'] ?? '';
        if ($role === 'homeroom' || $role === 'assistant' || $role === 'primary') {
            return $role;
        }
        if (!empty($row['is_class_teacher']) && empty($row['subject_id'])) {
            return 'homeroom';
        }
        if (isset($row['is_primary']) && (int)$row['is_primary'] === 0 && !empty($row['subject_id'])) {
            return 'assistant';
        }
        return 'primary';
    }

    private static function names(\mysqli $conn, int $teacherId, int $classId, ?int $subjectId): array
    {
        $out = ['teacher' => '', 'class' => '', 'subject' => ''];
        $stmt = $conn->prepare("SELECT full_name FROM users WHERE id = ? LIMIT 1");
        if ($stmt) {
            $stmt->bind_param('i', $teacherId);
            $stmt->execute();
            $out['teacher'] = (string)($stmt->get_result()->fetch_assoc()['full_name'] ?? '');
            $stmt->close();
        }
        $stmt = $conn->prepare("SELECT class_name FROM classes WHERE id = ? LIMIT 1");
        if ($stmt) {
            $stmt->bind_param('i', $classId);
            $stmt->execute();
            $out['class'] = (string)($stmt->get_result()->fetch_assoc()['class_name'] ?? '');
            $stmt->close();
        }
        if ($subjectId) {
            $stmt = $conn->prepare("SELECT subject_name FROM subjects WHERE id = ? LIMIT 1");
            if ($stmt) {
                $stmt->bind_param('i', $subjectId);
                $stmt->execute();
                $out['subject'] = (string)($stmt->get_result()->fetch_assoc()['subject_name'] ?? '');
                $stmt->close();
            }
        }
        return $out;
    }

    private static function touchTeacherMember(\mysqli $conn, int $teacherUserId, bool $isTeacher): void
    {
        try {
            $stmt = $conn->prepare("SELECT member_id FROM users WHERE id = ? AND role = 'teacher' LIMIT 1");
            if (!$stmt) {
                return;
            }
            $stmt->bind_param('i', $teacherUserId);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            $mid = (int)($row['member_id'] ?? 0);
            if ($mid <= 0) {
                return;
            }
            if (function_exists('syncMemberTeacherFlag')) {
                syncMemberTeacherFlag($conn, $mid, $isTeacher);
            } elseif (function_exists('autoUpdateTeacherStatus')) {
                autoUpdateTeacherStatus($conn, $mid, $isTeacher);
            }
        } catch (\Throwable $e) {
            /* never fail the assignment because of member-flag sync */
        }
    }

    private static function hasColumn(\mysqli $conn, string $table, string $col): bool
    {
        // Migration 006 is now a deployment prerequisite. Keep this method as a
        // compatibility adapter for existing query builders without per-request
        // INFORMATION_SCHEMA/SHOW traffic.
        return $table === 'teacher_assignments' && in_array($col, [
            'assignment_role', 'is_primary', 'is_active',
            'is_class_teacher', 'assigned_by',
        ], true);
    }

}
