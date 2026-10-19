<?php
/**
 * Per-department attendance policy (Attendance alerting — Phase 1).
 *
 * One row per department in `attendance_policies` (sql/071). The School
 * Admin edits the thresholds; everything else reads them through
 * `AttendancePolicyService::for($conn, $dept)` which always returns a full
 * policy — the owner-approved defaults (5 / 8 / 10 consecutive sessions,
 * auto-deactivate OFF, 75 % low-rate, 3 minimum sessions) when the table
 * is missing or the feature flag is off.
 */
namespace App\Services;

require_once __DIR__ . '/FeatureGate.php';
require_once __DIR__ . '/SecurityAuditService.php';

final class AttendancePolicyService
{
    public const TABLE = 'attendance_policies';
    public const DEPARTMENTS = ['edu', 'mezmur'];

    public const DEFAULTS = [
        'consecutive_threshold' => 5,
        'prolonged_threshold' => 8,
        'critical_threshold' => 10,
        'auto_deactivate' => 0,
        'low_rate_threshold' => 75.00,
        'min_sessions' => 3,
    ];

    /** Server-side bounds (plan §5 Phase 1). */
    public const MAX_CONSECUTIVE = 60;
    public const MAX_MIN_SESSIONS = 30;

    /** @var array<int,array<string,array>> per-connection, per-department cache */
    private static array $cache = [];
    /** @var array<int,bool> */
    private static array $tableCache = [];

    public static function enabled(): bool
    {
        return FeatureGate::isEnabled('attendance_v2');
    }

    public static function tableExists(\mysqli $conn): bool
    {
        $key = spl_object_id($conn);
        if (!array_key_exists($key, self::$tableCache)) {
            $exists = false;
            try {
                $res = $conn->query("SHOW TABLES LIKE '" . self::TABLE . "'");
                $exists = $res && $res->num_rows > 0;
            } catch (\Throwable $e) {
                $exists = false;
            }
            self::$tableCache[$key] = $exists;
        }
        return self::$tableCache[$key];
    }

    public static function available(\mysqli $conn): bool
    {
        return self::enabled() && self::tableExists($conn);
    }

    public static function resetCache(): void
    {
        self::$cache = [];
        self::$tableCache = [];
    }

    /** @return array<string,mixed> normalised policy (always complete) */
    public static function defaults(string $dept): array
    {
        return self::normalise($dept, self::DEFAULTS + ['updated_by' => null, 'updated_at' => null, 'is_default' => true]);
    }

    /** @return array<string,mixed> */
    public static function for(\mysqli $conn, string $dept): array
    {
        if (!in_array($dept, self::DEPARTMENTS, true)) {
            throw new \DomainException('Unknown department.');
        }
        $key = spl_object_id($conn);
        if (isset(self::$cache[$key][$dept])) {
            return self::$cache[$key][$dept];
        }
        $policy = self::defaults($dept);
        if (self::available($conn)) {
            $stmt = $conn->prepare('SELECT * FROM ' . self::TABLE . ' WHERE department = ? LIMIT 1');
            if ($stmt) {
                $stmt->bind_param('s', $dept);
                $stmt->execute();
                $row = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                if ($row) {
                    $row['is_default'] = false;
                    $policy = self::normalise($dept, $row);
                }
            }
        }
        self::$cache[$key][$dept] = $policy;
        return $policy;
    }

    /** @return array<string,array<string,mixed>> */
    public static function all(\mysqli $conn): array
    {
        $out = [];
        foreach (self::DEPARTMENTS as $dept) {
            $out[$dept] = self::for($conn, $dept);
        }
        return $out;
    }

    /**
     * Validate a candidate policy. Returns the list of error messages (empty
     * when valid) and fills $clean with typed values.
     * @param array<string,mixed> $input
     * @param array<string,mixed> $clean
     * @return string[]
     */
    public static function validate(array $input, array &$clean): array
    {
        $errors = [];
        $clean = [];
        $ints = ['consecutive_threshold', 'prolonged_threshold', 'critical_threshold', 'min_sessions'];
        foreach ($ints as $f) {
            $v = $input[$f] ?? null;
            if ($v === '' || $v === null || !is_numeric($v) || (float)$v != (int)$v) {
                $errors[] = "$f must be a whole number.";
                continue;
            }
            $clean[$f] = (int)$v;
        }
        if (!array_key_exists('low_rate_threshold', $input) || !is_numeric($input['low_rate_threshold'])) {
            $errors[] = 'low_rate_threshold must be a number.';
        } else {
            $clean['low_rate_threshold'] = round((float)$input['low_rate_threshold'], 2);
        }
        $auto = $input['auto_deactivate'] ?? 0;
        $clean['auto_deactivate'] = ($auto === true || $auto === 1 || $auto === '1' || $auto === 'true') ? 1 : 0;

        if ($errors !== []) {
            return $errors;
        }
        $c = $clean['consecutive_threshold'];
        $p = $clean['prolonged_threshold'];
        $k = $clean['critical_threshold'];
        if ($c < 1 || $k > self::MAX_CONSECUTIVE || !($c < $p && $p < $k)) {
            $errors[] = 'Consecutive thresholds must satisfy 1 ≤ standard < prolonged < critical ≤ ' . self::MAX_CONSECUTIVE . '.';
        }
        if ($clean['low_rate_threshold'] <= 0 || $clean['low_rate_threshold'] > 100) {
            $errors[] = 'Low attendance threshold must be between 0 (exclusive) and 100 %.';
        }
        if ($clean['min_sessions'] < 1 || $clean['min_sessions'] > self::MAX_MIN_SESSIONS) {
            $errors[] = 'Minimum sessions must be between 1 and ' . self::MAX_MIN_SESSIONS . '.';
        }
        return $errors;
    }

    /**
     * Persist a validated policy. Audits old/new values.
     * @param array<string,mixed> $clean output of validate()
     * @return array{changed:bool,policy:array<string,mixed>}
     */
    public static function save(\mysqli $conn, string $dept, array $clean, int $actorId): array
    {
        if (!in_array($dept, self::DEPARTMENTS, true)) {
            throw new \DomainException('Unknown department.');
        }
        if (!self::available($conn)) {
            throw new \RuntimeException('Attendance policy is not available yet (feature disabled or sql/071 not applied).');
        }
        $before = self::for($conn, $dept);
        $fields = ['consecutive_threshold', 'prolonged_threshold', 'critical_threshold', 'auto_deactivate', 'low_rate_threshold', 'min_sessions'];
        $changed = [];
        foreach ($fields as $f) {
            if ((string)$before[$f] !== (string)$clean[$f]) {
                $changed[$f] = ['from' => $before[$f], 'to' => $clean[$f]];
            }
        }
        if ($changed === [] && !$before['is_default']) {
            return ['changed' => false, 'policy' => $before];
        }
        $stmt = $conn->prepare(
            'INSERT INTO ' . self::TABLE . ' (department, consecutive_threshold, prolonged_threshold, critical_threshold,
                    auto_deactivate, low_rate_threshold, min_sessions, updated_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE consecutive_threshold = VALUES(consecutive_threshold),
                    prolonged_threshold = VALUES(prolonged_threshold), critical_threshold = VALUES(critical_threshold),
                    auto_deactivate = VALUES(auto_deactivate), low_rate_threshold = VALUES(low_rate_threshold),
                    min_sessions = VALUES(min_sessions), updated_by = VALUES(updated_by)'
        );
        if (!$stmt) {
            throw new \RuntimeException('Policy statement failed.');
        }
        $by = $actorId > 0 ? $actorId : null;
        $stmt->bind_param(
            'siiiidii',
            $dept,
            $clean['consecutive_threshold'],
            $clean['prolonged_threshold'],
            $clean['critical_threshold'],
            $clean['auto_deactivate'],
            $clean['low_rate_threshold'],
            $clean['min_sessions'],
            $by
        );
        $stmt->execute();
        $stmt->close();
        unset(self::$cache[spl_object_id($conn)][$dept]);

        try {
            SecurityAuditService::record($conn, 'Attendance Policy Updated', [
                'department' => $dept,
                'changes' => $changed,
            ], 'attendance_policy', null);
        } catch (\Throwable $e) {
            error_log('Attendance policy audit failed: ' . $e->getMessage());
        }
        return ['changed' => true, 'policy' => self::for($conn, $dept)];
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private static function normalise(string $dept, array $row): array
    {
        return [
            'department' => $dept,
            'label' => $dept === 'edu' ? 'Education' : 'Mezmur',
            'consecutive_threshold' => (int)($row['consecutive_threshold'] ?? self::DEFAULTS['consecutive_threshold']),
            'prolonged_threshold' => (int)($row['prolonged_threshold'] ?? self::DEFAULTS['prolonged_threshold']),
            'critical_threshold' => (int)($row['critical_threshold'] ?? self::DEFAULTS['critical_threshold']),
            'auto_deactivate' => (int)($row['auto_deactivate'] ?? 0) === 1 ? 1 : 0,
            'low_rate_threshold' => round((float)($row['low_rate_threshold'] ?? self::DEFAULTS['low_rate_threshold']), 2),
            'min_sessions' => (int)($row['min_sessions'] ?? self::DEFAULTS['min_sessions']),
            'updated_by' => isset($row['updated_by']) ? (int)$row['updated_by'] : null,
            'updated_at' => $row['updated_at'] ?? null,
            'is_default' => (bool)($row['is_default'] ?? false),
        ];
    }
}
