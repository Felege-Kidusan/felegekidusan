<?php
/**
 * Single source of truth for year-scoped class enrollment.
 *
 * Used by HR registration, Excel import, and Education APIs.
 * One member may have only one *active* enrollment per academic year.
 */

namespace App\Services;

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
            $err = $stmt->error;
            $stmt->close();
            return ['status' => 'error', 'message' => 'Enrollment failed: ' . $err];
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

        $up = $conn->prepare("UPDATE class_enrollments SET status='transferred', notes=CONCAT(IFNULL(notes,''), ' [', ?, ']') WHERE id = ?");
        if ($up) {
            $up->bind_param('si', $note, $enrollmentId);
            $up->execute();
            $up->close();
        }

        try {
            $r = $conn->query("SHOW COLUMNS FROM `class_enrollments` LIKE 'promoted_from'");
            if ($r && $r->num_rows === 0) {
                $conn->query("ALTER TABLE `class_enrollments` ADD COLUMN `promoted_from` INT UNSIGNED DEFAULT NULL AFTER `notes`");
            }
        } catch (\Throwable $e) { /* ignore */ }

        $ins = $conn->prepare(
            "INSERT INTO class_enrollments
                (member_id, class_id, academic_year_id, enrolled_at, status, notes, promoted_from, enrolled_by)
             VALUES (?, ?, ?, ?, 'active', ?, ?, ?)
             ON DUPLICATE KEY UPDATE status='active', notes=VALUES(notes), enrolled_by=VALUES(enrolled_by)"
        );
        if (!$ins) {
            return ['status' => 'error', 'message' => 'Could not create transfer enrollment.'];
        }
        $ins->bind_param('iiissii', $memberId, $toClass['id'], $yearId, $today, $note, $fromClass, $enrolledBy);
        if (!$ins->execute()) {
            $err = $ins->error;
            $ins->close();
            return ['status' => 'error', 'message' => 'Transfer failed: ' . $err];
        }
        $newId = (int)$ins->insert_id;
        $ins->close();

        if (function_exists('autoUpdateMemberClass')) {
            autoUpdateMemberClass($conn, $memberId, (int)$toClass['id'], $yearId);
        }

        return [
            'status' => 'success',
            'message' => 'Transferred to ' . ($toClass['class_name'] ?? 'class') . '.',
            'enrollment_id' => $newId,
            'transferred' => true,
        ];
    }

    public static function generateMemberCode(\mysqli $conn): string
    {
        for ($attempt = 0; $attempt < 50; $attempt++) {
            $code = (string)random_int(10000, 99999);
            $stmt = $conn->prepare("SELECT COUNT(*) AS cnt FROM members WHERE member_code = ?");
            if (!$stmt) {
                return $code;
            }
            $stmt->bind_param('s', $code);
            $stmt->execute();
            $exists = (int)($stmt->get_result()->fetch_assoc()['cnt'] ?? 0);
            $stmt->close();
            if ($exists === 0) {
                return $code;
            }
        }
        return (string)random_int(10000, 99999);
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
}
