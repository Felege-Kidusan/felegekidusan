<?php
/**
 * Single source of truth for year-scoped class enrollment.
 *
 * Used by HR registration, Excel import, and Education APIs.
 * One member may have only one *active* enrollment per academic year.
 */

namespace App\Services;

require_once __DIR__ . '/MemberCategory.php';
require_once __DIR__ . '/IdentityCodeService.php';

class EnrollmentService
{
    public static function activeYear(\mysqli $conn): ?array
    {
        if (function_exists('ay_resolve')) {
            $resolved = ay_resolve($conn);
            if (!empty($resolved['year']) && is_array($resolved['year'])) {
                return $resolved['year'];
            }
        }
        if (function_exists('ay_active_year')) {
            $y = ay_active_year($conn);
            if (is_array($y)) {
                return $y;
            }
        }
        try {
            $r = $conn->query("SELECT * FROM academic_years WHERE is_current = 1 LIMIT 1");
            return $r ? $r->fetch_assoc() : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    public static function resolveClass(\mysqli $conn, $idOrCode): ?array
    {
        if ($idOrCode === null || $idOrCode === '') {
            return null;
        }
        if (is_int($idOrCode) || (is_string($idOrCode) && ctype_digit(trim($idOrCode)))) {
            $id = (int)$idOrCode;
            if ($id <= 0) {
                return null;
            }
            $stmt = $conn->prepare("SELECT * FROM classes WHERE id = ? AND is_active = 1 LIMIT 1");
            if (!$stmt) {
                return null;
            }
            $stmt->bind_param('i', $id);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            return $row ?: null;
        }
        $label = trim((string)$idOrCode);
        if ($label === '') {
            return null;
        }
        $stmt = $conn->prepare(
            "SELECT * FROM classes
             WHERE is_active = 1
               AND (class_code = ? OR class_name = ? OR class_name_en = ?)
             LIMIT 1"
        );
        if (!$stmt) {
            return null;
        }
        $stmt->bind_param('sss', $label, $label, $label);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $row ?: null;
    }

    /**
     * Enroll using a dropdown name, class_code, or numeric class id.
     */
    public static function enrollByLabel(\mysqli $conn, int $memberId, string $label, ?int $yearId = null, ?int $enrolledBy = null): array
    {
        $class = self::resolveClass($conn, $label);
        if (!$class) {
            return ['status' => 'error', 'message' => 'Unknown class: ' . $label];
        }
        return self::enroll($conn, $memberId, (int)$class['id'], $yearId, $enrolledBy);
    }

    /**
     * Enroll a member in a class for the active (or given) year.
     *
     * @return array{status:string,message:string,enrollment_id?:int,skipped?:bool,transferred?:bool}
     */
    public static function enroll(\mysqli $conn, int $memberId, int $classId, ?int $yearId = null, ?int $enrolledBy = null): array
    {
        if ($memberId <= 0 || $classId <= 0) {
            return ['status' => 'error', 'message' => 'Member and class are required.'];
        }

        $class = self::resolveClass($conn, $classId);
        if (!$class) {
            return ['status' => 'error', 'message' => 'Class not found or inactive.'];
        }

        $year = null;
        if ($yearId) {
            $stmt = $conn->prepare("SELECT * FROM academic_years WHERE id = ? LIMIT 1");
            if ($stmt) {
                $stmt->bind_param('i', $yearId);
                $stmt->execute();
                $year = $stmt->get_result()->fetch_assoc();
                $stmt->close();
            }
        }
        if (!$year) {
            $year = self::activeYear($conn);
        }
        if (!$year) {
            return ['status' => 'error', 'message' => 'No active academic year. Member saved without a class.'];
        }
        $yearId = (int)$year['id'];
        $enrolledBy = $enrolledBy ?: (int)($_SESSION['admin_id'] ?? 0);
        $today = date('Y-m-d');

        $existing = self::activeEnrollment($conn, $memberId, $yearId);
        if ($existing) {
            if ((int)$existing['class_id'] === (int)$class['id']) {
                return [
                    'status' => 'success',
                    'message' => 'Already enrolled in this class.',
                    'enrollment_id' => (int)$existing['id'],
                    'skipped' => true,
                ];
            }
            $xfer = self::transferByEnrollment($conn, (int)$existing['id'], (int)$class['id'], $yearId, $enrolledBy, 'Assigned from HR / Excel');
            if (($xfer['status'] ?? '') === 'success') {
                $xfer['transferred'] = true;
            }
            return $xfer;
        }

        $stmt = $conn->prepare(
            "INSERT INTO class_enrollments
                (member_id, class_id, academic_year_id, enrolled_at, status, enrolled_by)
             VALUES (?, ?, ?, ?, 'active', ?)
             ON DUPLICATE KEY UPDATE status='active', enrolled_by=VALUES(enrolled_by), enrolled_at=VALUES(enrolled_at)"
        );
        if (!$stmt) {
            return ['status' => 'error', 'message' => 'Could not prepare enrollment.'];
        }
        $stmt->bind_param('iiisi', $memberId, $class['id'], $yearId, $today, $enrolledBy);
        if (!$stmt->execute()) {
            $error = $stmt->error;
            $stmt->close();
            if (function_exists('reportInternalError')) {
                reportInternalError('Enrollment insert failed', $error);
            }
            return ['status' => 'error', 'message' => 'Unable to save the enrollment.'];
        }
        $enrollId = (int)$stmt->insert_id;
        $stmt->close();

        if (function_exists('autoUpdateMemberClass')) {
            autoUpdateMemberClass($conn, $memberId, (int)$class['id'], $yearId);
        }

        return [
            'status' => 'success',
            'message' => 'Enrolled in ' . ($class['class_name'] ?? 'class') . '.',
            'enrollment_id' => $enrollId,
        ];
    }

    public static function enrollByCode(\mysqli $conn, int $memberId, string $classCode, ?int $yearId = null, ?int $enrolledBy = null): array
    {
        $class = self::resolveClass($conn, $classCode);
        if (!$class) {
            return ['status' => 'error', 'message' => 'Unknown class code: ' . $classCode];
        }
        return self::enroll($conn, $memberId, (int)$class['id'], $yearId, $enrolledBy);
    }

    public static function transferByEnrollment(\mysqli $conn, int $enrollmentId, int $toClassId, int $yearId, int $enrolledBy, string $reason = ''): array
    {
        $toClass = self::resolveClass($conn, $toClassId);
        if (!$toClass) {
            return ['status' => 'error', 'message' => 'Target class not found or inactive.'];
        }

        $stmt = $conn->prepare("SELECT * FROM class_enrollments WHERE id = ? LIMIT 1");
        if (!$stmt) {
            return ['status' => 'error', 'message' => 'Enrollment not found.'];
        }
        $stmt->bind_param('i', $enrollmentId);
        $stmt->execute();
        $enr = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$enr) {
            return ['status' => 'error', 'message' => 'Enrollment not found.'];
        }

        $memberId = (int)$enr['member_id'];
        $fromClass = (int)$enr['class_id'];
        if ($fromClass === (int)$toClass['id']) {
            return ['status' => 'success', 'message' => 'Already in target class.', 'skipped' => true];
        }

        $note = $reason !== '' ? $reason : 'Transferred';
        $today = date('Y-m-d');

        // TRANSACTION PARTICIPATION: close-and-recreate must be atomic. If
        // the caller already opened a transaction we participate in it;
        // otherwise we open (and close) our own so a mid-sequence failure
        // can never strand a member without an active enrollment.
        $ownsTransaction = false;
        if (!$conn->in_transaction()) {
            $conn->begin_transaction();
            $ownsTransaction = true;
        }

        try {
            $up = $conn->prepare("UPDATE class_enrollments SET status='transferred', notes=CONCAT(IFNULL(notes,''), ' [', ?, ']') WHERE id = ?");
            if ($up) {
                $up->bind_param('si', $note, $enrollmentId);
                $up->execute();
                $up->close();
            }

            $ins = $conn->prepare(
                "INSERT INTO class_enrollments
                    (member_id, class_id, academic_year_id, enrolled_at, status, notes, promoted_from, enrolled_by)
                 VALUES (?, ?, ?, ?, 'active', ?, ?, ?)
                 ON DUPLICATE KEY UPDATE status='active', notes=VALUES(notes), enrolled_by=VALUES(enrolled_by)"
            );
            if (!$ins) {
                throw new \RuntimeException('Could not create transfer enrollment.');
            }
            $ins->bind_param('iiissii', $memberId, $toClass['id'], $yearId, $today, $note, $fromClass, $enrolledBy);
            if (!$ins->execute()) {
                $err = $ins->error;
                $ins->close();
                throw new \RuntimeException('Transfer failed: ' . $err);
            }
            $newId = (int)$ins->insert_id;
            $ins->close();

            if (function_exists('autoUpdateMemberClass')) {
                autoUpdateMemberClass($conn, $memberId, (int)$toClass['id'], $yearId);
            }

            if ($ownsTransaction) {
                $conn->commit();
            }
        } catch (\Throwable $error) {
            if ($ownsTransaction && $conn->in_transaction()) {
                $conn->rollback();
            }
            return ['status' => 'error', 'message' => $error->getMessage() . ' No changes were made.'];
        }

        return [
            'status' => 'success',
            'message' => 'Transferred to ' . ($toClass['class_name'] ?? 'class') . '.',
            'enrollment_id' => $newId,
            'transferred' => true,
        ];
    }

    /**
     * Compatibility adapter for existing import/enrollment callers.
     * $ageGroup accepts the stored value ('7_13','14_17','18_plus', legacy
     * 'under6') or a bare letter; it maps onto the ministry A/B/C sequence.
     */
    /**
     * Compatibility adapter. Returns NULL (pending code) when the age group
     * cannot be mapped to a category letter — codes are never guessed, the
     * Identity hub issues them once the category is known.
     */
    public static function generateMemberCode(\mysqli $conn, ?string $ageGroup = null): ?string
    {
        require_once __DIR__ . '/MemberCategory.php';
        require_once __DIR__ . '/IdentityCodeService.php';
        $letter = MemberCategory::letterFor($ageGroup);
        if ($letter === null) {
            return null;
        }
        return IdentityCodeService::allocateStudent($conn, $letter);
    }

    private static function activeEnrollment(\mysqli $conn, int $memberId, int $yearId): ?array
    {
        $stmt = $conn->prepare(
            "SELECT * FROM class_enrollments
             WHERE member_id = ? AND academic_year_id = ? AND status = 'active'
             ORDER BY id DESC LIMIT 1"
        );
        if (!$stmt) {
            return null;
        }
        $stmt->bind_param('ii', $memberId, $yearId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $row ?: null;
    }

    /**
     * Count active enrollments for a class in one year.
     * When $includeUnscoped is true, also count rows with NULL/0 year
     * (legacy imports that never stamped a year).
     */
    public static function countActive(\mysqli $conn, int $classId, ?int $yearId, bool $includeUnscoped = true): int
    {
        if ($classId <= 0) {
            return 0;
        }
        $yearId = $yearId ? (int)$yearId : 0;
        if ($yearId > 0 && $includeUnscoped) {
            $stmt = $conn->prepare(
                "SELECT COUNT(*) AS c FROM class_enrollments
                 WHERE class_id = ? AND status = 'active'
                   AND (academic_year_id = ? OR academic_year_id IS NULL OR academic_year_id = 0)"
            );
            if (!$stmt) {
                return 0;
            }
            $stmt->bind_param('ii', $classId, $yearId);
        } elseif ($yearId > 0) {
            $stmt = $conn->prepare(
                "SELECT COUNT(*) AS c FROM class_enrollments
                 WHERE class_id = ? AND status = 'active' AND academic_year_id = ?"
            );
            if (!$stmt) {
                return 0;
            }
            $stmt->bind_param('ii', $classId, $yearId);
        } else {
            $stmt = $conn->prepare(
                "SELECT COUNT(*) AS c FROM class_enrollments
                 WHERE class_id = ? AND status = 'active'"
            );
            if (!$stmt) {
                return 0;
            }
            $stmt->bind_param('i', $classId);
        }
        $stmt->execute();
        $n = (int)($stmt->get_result()->fetch_assoc()['c'] ?? 0);
        $stmt->close();
        return $n;
    }

    /**
     * Which year should the roster for this class be read from?
     *
     * Prefer the active / requested year. If that year has nobody,
     * fall back to the year that actually has students (usually last year
     * before a rollover). Teachers must still see the children they teach.
     *
     * @return array{year_id:?int,count:int,fallback:bool,preferred_year_id:?int,preferred_count:int,year_name:?string}
     */
    public static function resolveRosterYear(\mysqli $conn, int $classId, ?int $preferredYearId): array
    {
        $preferredYearId = $preferredYearId ? (int)$preferredYearId : 0;
        $preferredCount = self::countActive($conn, $classId, $preferredYearId > 0 ? $preferredYearId : null, true);

        $out = [
            'year_id' => $preferredYearId > 0 ? $preferredYearId : null,
            'count' => $preferredCount,
            'fallback' => false,
            'preferred_year_id' => $preferredYearId > 0 ? $preferredYearId : null,
            'preferred_count' => $preferredCount,
            'year_name' => self::yearName($conn, $preferredYearId > 0 ? $preferredYearId : null),
        ];

        if ($preferredCount > 0) {
            return $out;
        }

        $stmt = $conn->prepare(
            "SELECT academic_year_id, COUNT(*) AS cnt
             FROM class_enrollments
             WHERE class_id = ? AND status = 'active'
             GROUP BY academic_year_id
             ORDER BY cnt DESC, academic_year_id DESC
             LIMIT 1"
        );
        if (!$stmt) {
            return $out;
        }
        $stmt->bind_param('i', $classId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$row) {
            return $out;
        }

        $altId = isset($row['academic_year_id']) && $row['academic_year_id'] !== null
            ? (int)$row['academic_year_id']
            : 0;
        $altCount = (int)$row['cnt'];
        if ($altCount <= 0) {
            return $out;
        }

        $out['year_id'] = $altId > 0 ? $altId : null;
        $out['count'] = $altCount;
        $out['fallback'] = ($preferredYearId > 0 && $altId !== $preferredYearId);
        $out['year_name'] = self::yearName($conn, $altId > 0 ? $altId : null);
        return $out;
    }

    public static function yearName(\mysqli $conn, ?int $yearId): ?string
    {
        $yearId = $yearId ? (int)$yearId : 0;
        if ($yearId <= 0) {
            return null;
        }
        $stmt = $conn->prepare("SELECT year_name FROM academic_years WHERE id = ? LIMIT 1");
        if (!$stmt) {
            return null;
        }
        $stmt->bind_param('i', $yearId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $row['year_name'] ?? null;
    }

    /**
     * Active students in a class for a resolved roster year.
     *
     * @param array{search?:string,gender?:string,member_type?:string,sort?:string,include_null_year?:bool} $opts
     * @return list<array<string,mixed>>
     */
    public static function fetchRoster(\mysqli $conn, int $classId, ?int $yearId, array $opts = []): array
    {
        if ($classId <= 0) {
            return [];
        }

        $search = trim((string)($opts['search'] ?? ''));
        $gender = trim((string)($opts['gender'] ?? ''));
        $memberType = trim((string)($opts['member_type'] ?? ''));
        $sort = trim((string)($opts['sort'] ?? 'name'));
        $includeNull = array_key_exists('include_null_year', $opts)
            ? (bool)$opts['include_null_year']
            : true;

        $sql = "SELECT ce.id AS enrollment_id, ce.enrolled_at, ce.status AS enrollment_status,
                       ce.notes AS enrollment_notes, ce.academic_year_id,
                       m.id AS member_id, m.id, m.student_name, m.father_name, m.grandfather_name,
                       m.member_code, m.gender, m.age_group, m.date_of_birth, m.age,
                       m.baptismal_name, m.education_level, m.member_type,
                       m.is_teacher, m.is_staff, m.is_committee, m.is_volunteer,
                       m.student_photo_path, m.status, m.current_section
                FROM class_enrollments ce
                JOIN members m ON ce.member_id = m.id
                WHERE ce.class_id = ? AND ce.status = 'active'";
        $params = [$classId];
        $types = 'i';

        $yearId = $yearId ? (int)$yearId : 0;
        if ($yearId > 0 && $includeNull) {
            $sql .= " AND (ce.academic_year_id = ? OR ce.academic_year_id IS NULL OR ce.academic_year_id = 0)";
            $params[] = $yearId;
            $types .= 'i';
        } elseif ($yearId > 0) {
            $sql .= " AND ce.academic_year_id = ?";
            $params[] = $yearId;
            $types .= 'i';
        }

        if ($search !== '') {
            $sql .= " AND (m.student_name LIKE ? OR m.father_name LIKE ? OR m.member_code LIKE ? OR m.baptismal_name LIKE ?)";
            $st = '%' . $search . '%';
            array_push($params, $st, $st, $st, $st);
            $types .= 'ssss';
        }
        if ($gender !== '' && in_array($gender, ['male', 'female'], true)) {
            $sql .= " AND m.gender = ?";
            $params[] = $gender;
            $types .= 's';
        }
        if ($memberType !== '' && in_array($memberType, ['regular', 'special_regular', 'honorary'], true)) {
            $sql .= " AND m.member_type = ?";
            $params[] = $memberType;
            $types .= 's';
        }

        $order = 'm.student_name';
        if ($sort === 'code') {
            $order = 'm.member_code';
        } elseif ($sort === 'date') {
            $order = 'ce.enrolled_at DESC';
        } elseif ($sort === 'gender') {
            $order = 'm.gender, m.student_name';
        }
        $sql .= " ORDER BY {$order}";

        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            // Slimmer query if a newer member column is missing on this database.
            $sql = "SELECT ce.id AS enrollment_id, ce.enrolled_at, ce.status AS enrollment_status,
                           ce.academic_year_id, m.id AS member_id, m.id, m.student_name,
                           m.father_name, m.member_code, m.gender
                    FROM class_enrollments ce
                    JOIN members m ON ce.member_id = m.id
                    WHERE ce.class_id = ? AND ce.status = 'active'";
            $params = [$classId];
            $types = 'i';
            if ($yearId > 0 && $includeNull) {
                $sql .= " AND (ce.academic_year_id = ? OR ce.academic_year_id IS NULL OR ce.academic_year_id = 0)";
                $params[] = $yearId;
                $types .= 'i';
            } elseif ($yearId > 0) {
                $sql .= " AND ce.academic_year_id = ?";
                $params[] = $yearId;
                $types .= 'i';
            }
            $sql .= " ORDER BY m.student_name";
            $stmt = $conn->prepare($sql);
            if (!$stmt) {
                return [];
            }
        }
        $stmt->bind_param($types, ...$params);
        if (!$stmt->execute()) {
            $stmt->close();
            return [];
        }
        $rows = [];
        $r = $stmt->get_result();
        while ($row = $r->fetch_assoc()) {
            $row['member_id'] = (int)$row['member_id'];
            $row['id'] = (int)$row['member_id'];
            $rows[] = $row;
        }
        $stmt->close();
        return $rows;
    }

    /**
     * SQL fragment: student_count per class that matches resolveRosterYear.
     * $yearId is cast to int before interpolation.
     */
    public static function rosterCountJoinSql(int $yearId): string
    {
        $yearId = (int)$yearId;
        if ($yearId <= 0) {
            return "SELECT class_id, COUNT(*) AS cnt
                    FROM class_enrollments
                    WHERE status = 'active'
                    GROUP BY class_id";
        }
        return "SELECT class_id, COUNT(*) AS cnt
                FROM class_enrollments ce
                WHERE status = 'active'
                  AND (
                    academic_year_id = {$yearId}
                    OR academic_year_id IS NULL
                    OR academic_year_id = 0
                    OR NOT EXISTS (
                        SELECT 1 FROM class_enrollments x
                        WHERE x.class_id = ce.class_id
                          AND x.status = 'active'
                          AND (x.academic_year_id = {$yearId}
                               OR x.academic_year_id IS NULL
                               OR x.academic_year_id = 0)
                    )
                  )
                GROUP BY class_id";
    }
}
