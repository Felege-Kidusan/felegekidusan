<?php
/**
 * Bell schedule + weekly class grid.
 * Thin store for later mobile teacher/class schedules.
 * Does not own a public Education page.
 */

namespace App\Services;

class TimetableService
{
    private static $schemaReady = false;

    public static function ensureSchema(\mysqli $conn): void
    {
        if (self::$schemaReady) {
            return;
        }
        self::$schemaReady = true;
        try {
            $conn->query(
                "CREATE TABLE IF NOT EXISTS `timetable_periods` (
                    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                    `label` VARCHAR(50) NOT NULL,
                    `start_time` TIME NOT NULL,
                    `end_time` TIME NOT NULL,
                    `is_break` TINYINT(1) NOT NULL DEFAULT 0,
                    `sort_order` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
                    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    PRIMARY KEY (`id`),
                    UNIQUE KEY `uniq_period_sort` (`sort_order`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
            );
            $conn->query(
                "CREATE TABLE IF NOT EXISTS `timetable_entries` (
                    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                    `class_id` INT UNSIGNED NOT NULL,
                    `period_id` INT UNSIGNED NOT NULL,
                    `day_of_week` TINYINT UNSIGNED NOT NULL,
                    `subject_id` INT UNSIGNED DEFAULT NULL,
                    `teacher_id` INT UNSIGNED DEFAULT NULL,
                    `room` VARCHAR(50) DEFAULT NULL,
                    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    PRIMARY KEY (`id`),
                    UNIQUE KEY `uniq_class_period_day` (`class_id`, `period_id`, `day_of_week`),
                    KEY `idx_tt_teacher_day` (`teacher_id`, `day_of_week`),
                    KEY `idx_tt_class` (`class_id`),
                    KEY `idx_tt_period` (`period_id`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
            );
        } catch (\Throwable $e) {
            error_log('TimetableService::ensureSchema: ' . $e->getMessage());
        }
    }

    /** @return array{status:string,periods:array} */
    public static function periods(\mysqli $conn): array
    {
        self::ensureSchema($conn);
        $rows = [];
        $r = $conn->query(
            "SELECT id, label, start_time, end_time, is_break, sort_order
             FROM timetable_periods ORDER BY sort_order, start_time"
        );
        if ($r) {
            while ($row = $r->fetch_assoc()) {
                $rows[] = self::formatPeriod($row);
            }
        }
        return ['status' => 'success', 'periods' => $rows];
    }

    /**
     * @return array{status:string,message:string,period_id?:int}
     */
    public static function savePeriod(\mysqli $conn, array $in): array
    {
        self::ensureSchema($conn);
        $id = (int)($in['id'] ?? 0);
        $label = trim((string)($in['label'] ?? ''));
        $start = self::normTime((string)($in['start_time'] ?? ''));
        $end = self::normTime((string)($in['end_time'] ?? ''));
        $isBreak = !empty($in['is_break']) ? 1 : 0;
        if ($label === '' || !$start || !$end) {
            return ['status' => 'error', 'message' => 'Label, start time, and end time are required.'];
        }
        if ($end <= $start) {
            return ['status' => 'error', 'message' => 'End time must be after start time.'];
        }
        $clash = self::overlappingPeriod($conn, $start, $end, $id ?: null);
        if ($clash) {
            return [
                'status' => 'error',
                'message' => 'That time overlaps “' . $clash['label'] . '” ('
                    . substr((string)$clash['start_time'], 0, 5) . '–'
                    . substr((string)$clash['end_time'], 0, 5) . ').',
            ];
        }
        if ($id > 0) {
            $stmt = $conn->prepare(
                "UPDATE timetable_periods SET label = ?, start_time = ?, end_time = ?, is_break = ? WHERE id = ?"
            );
            if (!$stmt) {
                return ['status' => 'error', 'message' => 'Could not update period.'];
            }
            $stmt->bind_param('sssii', $label, $start, $end, $isBreak, $id);
            $ok = $stmt->execute();
            $stmt->close();
            return $ok
                ? ['status' => 'success', 'message' => 'Period updated.', 'period_id' => $id]
                : ['status' => 'error', 'message' => 'Could not update period.'];
        }
        $sort = 1;
        $mx = $conn->query("SELECT COALESCE(MAX(sort_order), 0) AS m FROM timetable_periods");
        if ($mx) {
            $sort = (int)$mx->fetch_assoc()['m'] + 1;
        }
        $stmt = $conn->prepare(
            "INSERT INTO timetable_periods (label, start_time, end_time, is_break, sort_order)
             VALUES (?, ?, ?, ?, ?)"
        );
        if (!$stmt) {
            return ['status' => 'error', 'message' => 'Could not add period.'];
        }
        $stmt->bind_param('sssii', $label, $start, $end, $isBreak, $sort);
        $ok = $stmt->execute();
        $newId = (int)$stmt->insert_id;
        $stmt->close();
        return $ok
            ? ['status' => 'success', 'message' => 'Period added.', 'period_id' => $newId]
            : ['status' => 'error', 'message' => 'Could not add period.'];
    }

    /** @return array{status:string,message:string} */
    public static function deletePeriod(\mysqli $conn, int $id): array
    {
        self::ensureSchema($conn);
        if ($id <= 0) {
            return ['status' => 'error', 'message' => 'Period is required.'];
        }
        $cnt = 0;
        $c = $conn->prepare("SELECT COUNT(*) AS c FROM timetable_entries WHERE period_id = ?");
        if ($c) {
            $c->bind_param('i', $id);
            $c->execute();
            $cnt = (int)($c->get_result()->fetch_assoc()['c'] ?? 0);
            $c->close();
        }
        $delE = $conn->prepare("DELETE FROM timetable_entries WHERE period_id = ?");
        if ($delE) {
            $delE->bind_param('i', $id);
            $delE->execute();
            $delE->close();
        }
        $del = $conn->prepare("DELETE FROM timetable_periods WHERE id = ?");
        if (!$del) {
            return ['status' => 'error', 'message' => 'Could not remove period.'];
        }
        $del->bind_param('i', $id);
        $ok = $del->execute();
        $del->close();
        if (!$ok) {
            return ['status' => 'error', 'message' => 'Could not remove period.'];
        }
        return [
            'status' => 'success',
            'message' => $cnt > 0
                ? "Period removed along with {$cnt} scheduled lesson(s)."
                : 'Period removed.',
        ];
    }

    /**
     * @return array{status:string,class_id:int,periods:array,entries:array}
     */
    public static function classGrid(\mysqli $conn, int $classId): array
    {
        self::ensureSchema($conn);
        $periods = self::periods($conn)['periods'];
        $entries = [];
        if ($classId > 0) {
            $stmt = $conn->prepare(
                "SELECT e.id, e.class_id, e.period_id, e.day_of_week, e.subject_id, e.teacher_id, e.room,
                        s.subject_name, u.full_name AS teacher_name
                 FROM timetable_entries e
                 LEFT JOIN subjects s ON s.id = e.subject_id
                 LEFT JOIN users u ON u.id = e.teacher_id
                 WHERE e.class_id = ?"
            );
            if ($stmt) {
                $stmt->bind_param('i', $classId);
                $stmt->execute();
                $r = $stmt->get_result();
                while ($row = $r->fetch_assoc()) {
                    $entries[] = self::formatEntry($row);
                }
                $stmt->close();
            }
        }
        return ['status' => 'success', 'class_id' => $classId, 'periods' => $periods, 'entries' => $entries];
    }

    /**
     * Teacher weekly schedule (mobile + teacher portal).
     *
     * @return array{status:string,teacher_id:int,lessons:array}
     */
    public static function teacherSchedule(\mysqli $conn, int $teacherId): array
    {
        self::ensureSchema($conn);
        $lessons = [];
        if ($teacherId > 0) {
            $stmt = $conn->prepare(
                "SELECT e.id, e.class_id, e.period_id, e.day_of_week, e.subject_id, e.teacher_id, e.room,
                        p.label AS period_label, p.start_time, p.end_time, p.is_break,
                        c.class_name, c.class_name_en,
                        s.subject_name, s.subject_name_en
                 FROM timetable_entries e
                 JOIN timetable_periods p ON p.id = e.period_id
                 JOIN classes c ON c.id = e.class_id
                 LEFT JOIN subjects s ON s.id = e.subject_id
                 WHERE e.teacher_id = ?
                 ORDER BY e.day_of_week, p.sort_order, p.start_time"
            );
            if ($stmt) {
                $stmt->bind_param('i', $teacherId);
                $stmt->execute();
                $r = $stmt->get_result();
                while ($row = $r->fetch_assoc()) {
                    $lessons[] = [
                        'id' => (int)$row['id'],
                        'class_id' => (int)$row['class_id'],
                        'class_name' => $row['class_name'] ?? '',
                        'class_name_en' => $row['class_name_en'] ?? '',
                        'period_id' => (int)$row['period_id'],
                        'period_label' => $row['period_label'] ?? '',
                        'start_time' => substr((string)$row['start_time'], 0, 5),
                        'end_time' => substr((string)$row['end_time'], 0, 5),
                        'is_break' => (int)$row['is_break'] === 1,
                        'day_of_week' => (int)$row['day_of_week'],
                        'subject_id' => $row['subject_id'] ? (int)$row['subject_id'] : null,
                        'subject_name' => $row['subject_name'] ?? '',
                        'subject_name_en' => $row['subject_name_en'] ?? '',
                        'room' => $row['room'] ?? '',
                    ];
                }
                $stmt->close();
            }
        }
        return ['status' => 'success', 'teacher_id' => $teacherId, 'lessons' => $lessons];
    }

    /**
     * @return array{status:string,message:string,entry_id?:int}
     */
    public static function saveEntry(\mysqli $conn, array $in): array
    {
        self::ensureSchema($conn);
        $classId = (int)($in['class_id'] ?? 0);
        $periodId = (int)($in['period_id'] ?? 0);
        $day = (int)($in['day_of_week'] ?? 0);
        $subjectId = !empty($in['subject_id']) ? (int)$in['subject_id'] : null;
        $teacherId = !empty($in['teacher_id']) ? (int)$in['teacher_id'] : null;
        $room = trim((string)($in['room'] ?? ''));
        $room = $room !== '' ? substr($room, 0, 50) : null;
        if ($classId <= 0 || $periodId <= 0 || $day < 1 || $day > 7) {
            return ['status' => 'error', 'message' => 'Class, period, and weekday are required.'];
        }

        if ($teacherId) {
            $chk = $conn->prepare(
                "SELECT e.class_id, c.class_name
                 FROM timetable_entries e
                 JOIN classes c ON c.id = e.class_id
                 WHERE e.period_id = ? AND e.day_of_week = ? AND e.teacher_id = ? AND e.class_id != ?
                 LIMIT 1"
            );
            if ($chk) {
                $chk->bind_param('iiii', $periodId, $day, $teacherId, $classId);
                $chk->execute();
                $clash = $chk->get_result()->fetch_assoc();
                $chk->close();
                if ($clash) {
                    return [
                        'status' => 'error',
                        'message' => 'That teacher already teaches ' . ($clash['class_name'] ?: 'another class') . ' at this time.',
                    ];
                }
            }
        }

        $existing = $conn->prepare(
            "SELECT id FROM timetable_entries WHERE class_id = ? AND period_id = ? AND day_of_week = ? LIMIT 1"
        );
        $eid = 0;
        if ($existing) {
            $existing->bind_param('iii', $classId, $periodId, $day);
            $existing->execute();
            $row = $existing->get_result()->fetch_assoc();
            $existing->close();
            $eid = $row ? (int)$row['id'] : 0;
        }
        if ($eid) {
            $stmt = $conn->prepare(
                "UPDATE timetable_entries SET subject_id = ?, teacher_id = ?, room = ? WHERE id = ?"
            );
            if (!$stmt) {
                return ['status' => 'error', 'message' => 'Could not save lesson.'];
            }
            $stmt->bind_param('iisi', $subjectId, $teacherId, $room, $eid);
            $ok = $stmt->execute();
            $stmt->close();
            return $ok
                ? ['status' => 'success', 'message' => 'Lesson saved.', 'entry_id' => $eid]
                : ['status' => 'error', 'message' => 'Could not save lesson.'];
        }
        $stmt = $conn->prepare(
            "INSERT INTO timetable_entries (class_id, period_id, day_of_week, subject_id, teacher_id, room)
             VALUES (?, ?, ?, ?, ?, ?)"
        );
        if (!$stmt) {
            return ['status' => 'error', 'message' => 'Could not save lesson.'];
        }
        $stmt->bind_param('iiiiis', $classId, $periodId, $day, $subjectId, $teacherId, $room);
        $ok = $stmt->execute();
        $newId = (int)$stmt->insert_id;
        $stmt->close();
        return $ok
            ? ['status' => 'success', 'message' => 'Lesson saved.', 'entry_id' => $newId]
            : ['status' => 'error', 'message' => 'Could not save lesson.'];
    }

    /** @return array{status:string,message:string} */
    public static function clearEntry(\mysqli $conn, int $classId, int $periodId, int $day): array
    {
        self::ensureSchema($conn);
        if ($classId <= 0 || $periodId <= 0 || $day < 1 || $day > 7) {
            return ['status' => 'error', 'message' => 'Class, period, and weekday are required.'];
        }
        $stmt = $conn->prepare(
            "DELETE FROM timetable_entries WHERE class_id = ? AND period_id = ? AND day_of_week = ?"
        );
        if (!$stmt) {
            return ['status' => 'error', 'message' => 'Could not clear lesson.'];
        }
        $stmt->bind_param('iii', $classId, $periodId, $day);
        $ok = $stmt->execute();
        $stmt->close();
        return $ok
            ? ['status' => 'success', 'message' => 'Lesson cleared.']
            : ['status' => 'error', 'message' => 'Could not clear lesson.'];
    }

    private static function overlappingPeriod(\mysqli $conn, string $start, string $end, ?int $ignoreId): ?array
    {
        $sql = "SELECT id, label, start_time, end_time FROM timetable_periods
                WHERE start_time < ? AND end_time > ?";
        if ($ignoreId) {
            $sql .= " AND id != ?";
        }
        $sql .= " LIMIT 1";
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            return null;
        }
        if ($ignoreId) {
            $stmt->bind_param('ssi', $end, $start, $ignoreId);
        } else {
            $stmt->bind_param('ss', $end, $start);
        }
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $row ?: null;
    }

    private static function formatPeriod(array $row): array
    {
        return [
            'id' => (int)$row['id'],
            'label' => $row['label'] ?? '',
            'start_time' => substr((string)$row['start_time'], 0, 5),
            'end_time' => substr((string)$row['end_time'], 0, 5),
            'is_break' => (int)($row['is_break'] ?? 0) === 1,
            'sort_order' => (int)($row['sort_order'] ?? 0),
        ];
    }

    private static function formatEntry(array $row): array
    {
        return [
            'id' => (int)$row['id'],
            'class_id' => (int)$row['class_id'],
            'period_id' => (int)$row['period_id'],
            'day_of_week' => (int)$row['day_of_week'],
            'subject_id' => $row['subject_id'] ? (int)$row['subject_id'] : null,
            'teacher_id' => $row['teacher_id'] ? (int)$row['teacher_id'] : null,
            'room' => $row['room'] ?? '',
            'subject_name' => $row['subject_name'] ?? '',
            'teacher_name' => $row['teacher_name'] ?? '',
        ];
    }

    private static function normTime(string $t): ?string
    {
        $t = trim($t);
        if (preg_match('/^(\d{1,2}):(\d{2})(?::\d{2})?$/', $t, $m)) {
            $h = (int)$m[1];
            $min = (int)$m[2];
            if ($h >= 0 && $h <= 23 && $min >= 0 && $min <= 59) {
                return sprintf('%02d:%02d:00', $h, $min);
            }
        }
        return null;
    }
}
