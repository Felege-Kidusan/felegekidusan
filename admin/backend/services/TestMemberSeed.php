<?php
/**
 * Load / remove the practice roster (city = TEST-FKSS).
 *
 * Used only for a full-system test. Real members are never matched
 * or deleted. Classes 1ኛ–6ኛ ክፍል are created if they are missing;
 * existing class codes are left untouched.
 */

namespace App\Services;

class TestMemberSeed
{
    public const CITY = 'TEST-FKSS';
    public const PROFESSION = 'TEST-BATCH-20260823';
    public const FLAG_KEY = '_test_members_state';

    /** @var list<array{am:string,en:string,code:string,order:int}> */
    public const CLASSES = [
        1 => ['am' => '1ኛ ክፍል', 'en' => 'Grade 1', 'code' => 'K1', 'order' => 1],
        2 => ['am' => '2ኛ ክፍል', 'en' => 'Grade 2', 'code' => 'K2', 'order' => 2],
        3 => ['am' => '3ኛ ክፍል', 'en' => 'Grade 3', 'code' => 'K3', 'order' => 3],
        4 => ['am' => '4ኛ ክፍል', 'en' => 'Grade 4', 'code' => 'K4', 'order' => 4],
        5 => ['am' => '5ኛ ክፍል', 'en' => 'Grade 5', 'code' => 'K5', 'order' => 5],
        6 => ['am' => '6ኛ ክፍል', 'en' => 'Grade 6', 'code' => 'K6', 'order' => 6],
    ];

    public static function roster(): array
    {
        $path = dirname(__DIR__) . '/data/test_members_roster.php';
        if (!is_file($path)) {
            return [];
        }
        $rows = include $path;
        return is_array($rows) ? $rows : [];
    }

    public static function countLoaded(\mysqli $conn): int
    {
        $city = self::CITY;
        $job = self::PROFESSION;
        $stmt = $conn->prepare(
            "SELECT COUNT(*) AS c FROM members
             WHERE status <> 'archived'
               AND (city = ? OR work_profession = ?)"
        );
        if (!$stmt) {
            return 0;
        }
        $stmt->bind_param('ss', $city, $job);
        $stmt->execute();
        $n = (int)($stmt->get_result()->fetch_assoc()['c'] ?? 0);
        $stmt->close();
        return $n;
    }

    /**
     * First Super Admin / Education visit after deploy: load once.
     * Never auto-loads again after a manual clear.
     */
    public static function maybeAutoLoad(\mysqli $conn, int $adminId = 0): ?array
    {
        $state = self::readFlag($conn);
        if (($state['status'] ?? '') === 'cleared') {
            return null;
        }
        if (self::countLoaded($conn) > 0) {
            return null;
        }
        if (($state['status'] ?? '') === 'loaded') {
            return null;
        }
        return self::load($conn, $adminId);
    }

    public static function load(\mysqli $conn, int $adminId = 0): array
    {
        @set_time_limit(90);
        $roster = self::roster();
        if (!$roster) {
            return ['ok' => false, 'message' => 'Practice list is missing from the website files.'];
        }

        require_once __DIR__ . '/EnrollmentService.php';

        $classes = self::ensureClasses($conn);
        $year = self::ensureYear($conn);
        if (!$year) {
            return ['ok' => false, 'message' => 'Could not create or find an academic year. Open Education and set the year first.'];
        }

        $cols = self::memberColumns($conn);
        $stats = [
            'ok' => true,
            'inserted' => 0,
            'updated' => 0,
            'enrolled' => 0,
            'skipped' => 0,
            'errors' => 0,
            'error_details' => [],
            'classes' => [],
            'total' => count($roster),
        ];

        foreach (self::CLASSES as $g => $meta) {
            $cls = $classes[$g] ?? null;
            $stats['classes'][] = [
                'grade' => $g,
                'name' => $cls['class_name'] ?? $meta['am'],
                'created' => !empty($cls['_created']),
                'id' => (int)($cls['id'] ?? 0),
            ];
        }

        foreach ($roster as $i => $row) {
            $grade = (int)($row['grade'] ?? 0);
            $name = trim((string)($row['full_name_am'] ?? ''));
            if ($name === '' || $grade < 1 || $grade > 6) {
                $stats['skipped']++;
                continue;
            }
            $class = $classes[$grade] ?? null;
            if (!$class) {
                $stats['errors']++;
                $stats['error_details'][] = $name . ': class ' . $grade . ' missing';
                continue;
            }

            $existing = self::findTestMember($conn, $name);
            $payload = self::memberPayload($row, $cols);

            try {
                if ($existing) {
                    $memberId = (int)$existing['id'];
                    self::updateMember($conn, $memberId, $payload, $cols);
                    $stats['updated']++;
                } else {
                    $payload['member_code'] = EnrollmentService::generateMemberCode($conn);
                    $memberId = self::insertMember($conn, $payload, $cols);
                    if ($memberId <= 0) {
                        $stats['errors']++;
                        $stats['error_details'][] = $name . ': could not save';
                        continue;
                    }
                    $stats['inserted']++;
                }

                $enr = EnrollmentService::enroll($conn, $memberId, (int)$class['id'], (int)$year['id'], $adminId);
                if (($enr['status'] ?? '') === 'success') {
                    $stats['enrolled']++;
                } else {
                    $stats['errors']++;
                    $stats['error_details'][] = $name . ': ' . ($enr['message'] ?? 'enrollment failed');
                }
            } catch (\Throwable $e) {
                $stats['errors']++;
                $stats['error_details'][] = $name . ': ' . $e->getMessage();
            }
        }

        self::ensureT1OnGrade1($conn, $classes, $year, $adminId);

        $loaded = self::countLoaded($conn);
        self::writeFlag($conn, [
            'status' => 'loaded',
            'count' => $loaded,
            'at' => date('c'),
        ]);

        $stats['loaded'] = $loaded;
        $stats['message'] = "Practice members ready: {$loaded} on the website"
            . " ({$stats['inserted']} new, {$stats['updated']} already there).";
        if ($stats['errors']) {
            $stats['message'] .= " {$stats['errors']} row(s) had a problem.";
        }
        return $stats;
    }

    public static function clear(\mysqli $conn): array
    {
        @set_time_limit(90);
        $ids = self::testMemberIds($conn);
        $n = count($ids);
        if ($n === 0) {
            self::writeFlag($conn, ['status' => 'cleared', 'count' => 0, 'at' => date('c')]);
            return ['ok' => true, 'removed' => 0, 'message' => 'No practice members to remove.'];
        }

        $placeholders = implode(',', array_fill(0, $n, '?'));
        $types = str_repeat('i', $n);

        foreach (['attendance', 'attendance_summary', 'academic_records', 'class_enrollments'] as $table) {
            if (!self::tableExists($conn, $table) || !self::columnExists($conn, $table, 'member_id')) {
                continue;
            }
            $sql = "DELETE FROM `{$table}` WHERE member_id IN ({$placeholders})";
            $stmt = $conn->prepare($sql);
            if ($stmt) {
                $stmt->bind_param($types, ...$ids);
                $stmt->execute();
                $stmt->close();
            }
        }

        $city = self::CITY;
        $job = self::PROFESSION;
        $stmt = $conn->prepare(
            "DELETE FROM members WHERE city = ? OR work_profession = ?"
        );
        if ($stmt) {
            $stmt->bind_param('ss', $city, $job);
            $stmt->execute();
            $removed = $stmt->affected_rows;
            $stmt->close();
        } else {
            $removed = 0;
        }

        self::writeFlag($conn, ['status' => 'cleared', 'count' => 0, 'at' => date('c')]);

        return [
            'ok' => true,
            'removed' => max(0, (int)$removed),
            'message' => 'Removed ' . max(0, (int)$removed) . ' practice members. Real members were not touched. Classes were kept.',
        ];
    }

    public static function status(\mysqli $conn): array
    {
        $byGrade = [];
        foreach (self::CLASSES as $g => $meta) {
            $byGrade[$g] = ['name' => $meta['am'], 'count' => 0, 'class_id' => 0];
        }
        $city = self::CITY;
        $sql = "SELECT c.class_name, c.id AS class_id, COUNT(*) AS cnt
                FROM members m
                LEFT JOIN class_enrollments ce ON ce.member_id = m.id AND ce.status = 'active'
                LEFT JOIN classes c ON c.id = ce.class_id
                WHERE m.status <> 'archived' AND (m.city = ? OR m.work_profession = ?)
                GROUP BY c.id, c.class_name";
        $stmt = $conn->prepare($sql);
        if ($stmt) {
            $job = self::PROFESSION;
            $stmt->bind_param('ss', $city, $job);
            $stmt->execute();
            $r = $stmt->get_result();
            while ($row = $r->fetch_assoc()) {
                $name = (string)($row['class_name'] ?? '');
                foreach (self::CLASSES as $g => $meta) {
                    if ($name === $meta['am'] || strpos($name, $g . 'ኛ') === 0) {
                        $byGrade[$g]['count'] = (int)$row['cnt'];
                        $byGrade[$g]['class_id'] = (int)$row['class_id'];
                        $byGrade[$g]['name'] = $name !== '' ? $name : $meta['am'];
                    }
                }
            }
            $stmt->close();
        }

        return [
            'loaded' => self::countLoaded($conn),
            'expected' => count(self::roster()),
            'flag' => self::readFlag($conn),
            'by_grade' => $byGrade,
            'marker' => self::CITY,
        ];
    }

    /** @return array<int, array<string,mixed>> */
    public static function ensureClasses(\mysqli $conn): array
    {
        $out = [];
        foreach (self::CLASSES as $g => $meta) {
            $found = self::findClass($conn, $g, $meta);
            $created = false;
            if (!$found) {
                $stmt = $conn->prepare(
                    "INSERT INTO classes (class_name, class_name_en, class_code, level_order, section, age_group, description, is_active)
                     VALUES (?, ?, ?, ?, '', NULL, 'Created for the practice test', 1)"
                );
                if ($stmt) {
                    $stmt->bind_param('sssi', $meta['am'], $meta['en'], $meta['code'], $meta['order']);
                    if ($stmt->execute()) {
                        $id = (int)$conn->insert_id;
                        $found = [
                            'id' => $id,
                            'class_name' => $meta['am'],
                            'class_name_en' => $meta['en'],
                            'class_code' => $meta['code'],
                        ];
                        $created = true;
                    }
                    $stmt->close();
                }
            }
            if ($found) {
                $found['_created'] = $created;
                $out[$g] = $found;
            }
        }
        return $out;
    }

    public static function ensureYear(\mysqli $conn): ?array
    {
        if (function_exists('ay_ensure_schema')) {
            try {
                ay_ensure_schema($conn);
            } catch (\Throwable $e) { /* ok */ }
        }
        if (function_exists('ay_active_year')) {
            $y = ay_active_year($conn);
            if (is_array($y) && !empty($y['id'])) {
                return $y;
            }
        }
        try {
            $r = $conn->query("SELECT * FROM academic_years WHERE is_current = 1 ORDER BY id DESC LIMIT 1");
            $y = $r ? $r->fetch_assoc() : null;
            if ($y) {
                return $y;
            }
        } catch (\Throwable $e) { /* ok */ }

        $hasStatus = self::columnExists($conn, 'academic_years', 'status');
        $hasEc = self::columnExists($conn, 'academic_years', 'ec_year');
        if ($hasStatus && $hasEc) {
            $conn->query(
                "INSERT INTO academic_years (year_name, year_gc, ec_year, start_date, end_date, is_current, status)
                 VALUES ('2018 ዓ.ም.', '2025/26', 2018, '2025-09-11', '2026-09-10', 1, 'active')"
            );
        } elseif ($hasEc) {
            $conn->query(
                "INSERT INTO academic_years (year_name, year_gc, ec_year, start_date, end_date, is_current)
                 VALUES ('2018 ዓ.ም.', '2025/26', 2018, '2025-09-11', '2026-09-10', 1)"
            );
        } else {
            $conn->query(
                "INSERT INTO academic_years (year_name, year_gc, start_date, end_date, is_current)
                 VALUES ('2018 ዓ.ም.', '2025/26', '2025-09-11', '2026-09-10', 1)"
            );
        }
        $id = (int)$conn->insert_id;
        if ($id <= 0) {
            return null;
        }
        $stmt = $conn->prepare("SELECT * FROM academic_years WHERE id = ? LIMIT 1");
        if (!$stmt) {
            return ['id' => $id, 'year_name' => '2018 ዓ.ም.'];
        }
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $row ?: ['id' => $id];
    }

    private static function findClass(\mysqli $conn, int $grade, array $meta): ?array
    {
        $exact = $conn->prepare(
            "SELECT * FROM classes WHERE is_active = 1 AND (class_name = ? OR class_name_en = ? OR class_code = ?) LIMIT 1"
        );
        if ($exact) {
            $exact->bind_param('sss', $meta['am'], $meta['en'], $meta['code']);
            $exact->execute();
            $row = $exact->get_result()->fetch_assoc();
            $exact->close();
            if ($row) {
                return $row;
            }
        }

        $like = $grade . 'ኛ%';
        $stmt = $conn->prepare(
            "SELECT * FROM classes WHERE is_active = 1 AND class_name LIKE ? ORDER BY id ASC LIMIT 1"
        );
        if ($stmt) {
            $stmt->bind_param('s', $like);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if ($row) {
                return $row;
            }
        }
        return null;
    }

    private static function findTestMember(\mysqli $conn, string $name): ?array
    {
        $city = self::CITY;
        $job = self::PROFESSION;
        $stmt = $conn->prepare(
            "SELECT id, member_code, full_name_am FROM members
             WHERE full_name_am = ? AND (city = ? OR work_profession = ?)
             LIMIT 1"
        );
        if (!$stmt) {
            return null;
        }
        $stmt->bind_param('sss', $name, $city, $job);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $row ?: null;
    }

    private static function memberPayload(array $row, array $cols): array
    {
        $phone = trim((string)($row['phone'] ?? ''));
        $data = [
            'full_name_am' => $row['full_name_am'],
            'student_name' => $row['student_name'] !== '' ? $row['student_name'] : $row['full_name_am'],
            'father_name' => $row['father_name'] !== '' ? $row['father_name'] : '',
            'grandfather_name' => $row['grandfather_name'] ?? '',
            'baptismal_name' => $row['baptismal_name'] ?? '',
            'gender' => ($row['gender'] ?? 'male') === 'female' ? 'female' : 'male',
            'education_level' => $row['education_level'] ?? '',
            'phone_number' => $phone,
            'phone_primary' => $phone,
            'guardian_phone1' => $phone,
            'city' => self::CITY,
            'work_profession' => self::PROFESSION,
            'status' => 'active',
            'member_type' => 'regular',
            'registration_type' => 'direct',
            'membership_tier' => 'permanent',
            'registered_at' => date('Y-m-d'),
        ];
        if ($row['age'] !== null && $row['age'] !== '') {
            $data['age'] = (int)$row['age'];
        }
        if (!empty($row['age_group'])) {
            $data['age_group'] = $row['age_group'];
        }
        $out = [];
        foreach ($data as $k => $v) {
            if (in_array($k, $cols, true)) {
                $out[$k] = $v;
            }
        }
        return $out;
    }

    private static function insertMember(\mysqli $conn, array $payload, array $cols): int
    {
        $use = [];
        foreach ($payload as $k => $v) {
            if (in_array($k, $cols, true)) {
                $use[$k] = $v;
            }
        }
        if (!$use) {
            return 0;
        }
        $fields = array_keys($use);
        $placeholders = implode(',', array_fill(0, count($fields), '?'));
        $sql = 'INSERT INTO members (`' . implode('`,`', $fields) . '`) VALUES (' . $placeholders . ')';
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            return 0;
        }
        self::bindDynamic($stmt, array_values($use));
        if (!$stmt->execute()) {
            $stmt->close();
            return 0;
        }
        $id = (int)$conn->insert_id;
        $stmt->close();
        return $id;
    }

    private static function updateMember(\mysqli $conn, int $id, array $payload, array $cols): void
    {
        $skip = ['member_code', 'registered_at', 'registration_type'];
        $sets = [];
        $vals = [];
        foreach ($payload as $k => $v) {
            if (!in_array($k, $cols, true) || in_array($k, $skip, true)) {
                continue;
            }
            if ($v === '' || $v === null) {
                continue;
            }
            $sets[] = "`{$k}` = ?";
            $vals[] = $v;
        }
        if (!$sets) {
            return;
        }
        $vals[] = $id;
        $sql = 'UPDATE members SET ' . implode(', ', $sets) . ' WHERE id = ?';
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            return;
        }
        self::bindDynamic($stmt, $vals);
        $stmt->execute();
        $stmt->close();
    }

    private static function bindDynamic(\mysqli_stmt $stmt, array $vals): void
    {
        $types = '';
        $refs = [];
        foreach ($vals as $i => $v) {
            if (is_int($v)) {
                $types .= 'i';
            } else {
                $types .= 's';
                $vals[$i] = $v === null ? '' : (string)$v;
            }
            $refs[$i] = &$vals[$i];
        }
        array_unshift($refs, $types);
        $stmt->bind_param(...$refs);
    }

    /** @return list<int> */
    private static function testMemberIds(\mysqli $conn): array
    {
        $city = self::CITY;
        $job = self::PROFESSION;
        $stmt = $conn->prepare(
            "SELECT id FROM members WHERE city = ? OR work_profession = ?"
        );
        if (!$stmt) {
            return [];
        }
        $stmt->bind_param('ss', $city, $job);
        $stmt->execute();
        $ids = [];
        $r = $stmt->get_result();
        while ($row = $r->fetch_assoc()) {
            $ids[] = (int)$row['id'];
        }
        $stmt->close();
        return $ids;
    }

    private static function memberColumns(\mysqli $conn): array
    {
        $cols = [];
        $r = $conn->query('SHOW COLUMNS FROM members');
        if ($r) {
            while ($row = $r->fetch_assoc()) {
                $cols[] = $row['Field'];
            }
        }
        return $cols;
    }

    private static function tableExists(\mysqli $conn, string $table): bool
    {
        $table = preg_replace('/[^a-z0-9_]/i', '', $table);
        $r = $conn->query("SHOW TABLES LIKE '{$table}'");
        return $r && $r->num_rows > 0;
    }

    private static function columnExists(\mysqli $conn, string $table, string $column): bool
    {
        $table = preg_replace('/[^a-z0-9_]/i', '', $table);
        $column = preg_replace('/[^a-z0-9_]/i', '', $column);
        $r = $conn->query("SHOW COLUMNS FROM `{$table}` LIKE '{$column}'");
        return $r && $r->num_rows > 0;
    }

    private static function readFlag(\mysqli $conn): array
    {
        $file = self::flagFile();
        if (is_file($file)) {
            $raw = @file_get_contents($file);
            $j = $raw ? json_decode($raw, true) : null;
            if (is_array($j)) {
                return $j;
            }
        }
        if (self::tableExists($conn, 'system_branding')) {
            $key = self::FLAG_KEY;
            $stmt = $conn->prepare("SELECT original_name FROM system_branding WHERE asset_key = ? LIMIT 1");
            if ($stmt) {
                $stmt->bind_param('s', $key);
                $stmt->execute();
                $row = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                if ($row && !empty($row['original_name'])) {
                    $j = json_decode((string)$row['original_name'], true);
                    if (is_array($j)) {
                        return $j;
                    }
                }
            }
        }
        return [];
    }

    private static function writeFlag(\mysqli $conn, array $state): void
    {
        $json = json_encode($state, JSON_UNESCAPED_UNICODE);
        $dir = dirname(self::flagFile());
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        @file_put_contents(self::flagFile(), $json);

        if (!self::tableExists($conn, 'system_branding')) {
            return;
        }
        $key = self::FLAG_KEY;
        $label = 'Test members flag';
        $stmt = $conn->prepare(
            "INSERT INTO system_branding (asset_key, asset_label, original_name)
             VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE original_name = VALUES(original_name)"
        );
        if ($stmt) {
            $stmt->bind_param('sss', $key, $label, $json);
            $stmt->execute();
            $stmt->close();
        }
    }

    private static function flagFile(): string
    {
        $root = defined('ROOT_PATH') ? ROOT_PATH : dirname(__DIR__, 3);
        return $root . '/admin/uploads/cache/test_members_state.json';
    }

    /**
     * Keep teacher t1 on 1ኛ ክፍል so the phone sync test still has a login.
     * Does not create a teacher and does not steal another class teacher.
     */
    private static function ensureT1OnGrade1(\mysqli $conn, array $classes, ?array $year, int $adminId): void
    {
        if (empty($classes[1]['id']) || empty($year['id'])) {
            return;
        }
        try {
            $r = $conn->query("SELECT id FROM users WHERE username = 't1' AND role = 'teacher' AND is_active = 1 LIMIT 1");
            $t = $r ? $r->fetch_assoc() : null;
            if (!$t) {
                return;
            }
            $tid = (int)$t['id'];
            $cid = (int)$classes[1]['id'];
            $yid = (int)$year['id'];
            $chk = $conn->prepare(
                "SELECT id FROM teacher_assignments
                 WHERE teacher_id = ? AND class_id = ? AND academic_year_id = ?
                   AND (is_active = 1 OR is_active IS NULL)
                 LIMIT 1"
            );
            if ($chk) {
                $chk->bind_param('iii', $tid, $cid, $yid);
                $chk->execute();
                $already = (bool)$chk->get_result()->fetch_assoc();
                $chk->close();
                if ($already) {
                    return;
                }
            }
            require_once __DIR__ . '/AssignmentService.php';
            AssignmentService::assign($conn, $tid, $cid, null, 'homeroom', $yid, $adminId);
        } catch (\Throwable $e) {
            error_log('TestMemberSeed t1: ' . $e->getMessage());
        }
    }
}
