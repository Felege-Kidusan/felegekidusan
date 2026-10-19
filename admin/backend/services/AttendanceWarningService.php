<?php
/**
 * Attendance warnings (Attendance alerting — Phase 2).
 *
 * Idempotent evaluator run after every OFFICIAL attendance write (web sheet
 * save, mobile submit, Education edits, Mezmur submitted sheets, department
 * reactivation) and by the one-off rollout script. It reads the risk object
 * from AttendanceRiskService and the thresholds from AttendancePolicyService
 * and keeps `attendance_warnings` + `attendance_warning_events` in sync:
 *
 *   consecutive  streak >= consecutive_threshold  -> open (stage standard),
 *                keyed by streak_start; escalates IN PLACE to prolonged /
 *                critical (T-7) with an event + bell each time; a closed
 *                warning whose streak reaches a HIGHER stage is re-opened;
 *                critical + policy.auto_deactivate -> department status
 *                `inactive` (source=auto, warning_id) + event + bell;
 *                otherwise the bell text recommends a status review.
 *   low_month    sessions >= min_sessions and rate < low_rate_threshold for
 *                the Ethiopian month that contains the saved date -> open,
 *                keyed by (ec_year, ec_month). Recovery never auto-closes.
 *
 * A warning that no longer meets its threshold (a day corrected to present,
 * the member returned) is NOT deleted or auto-closed: `below_threshold` flips
 * to 0 with an `invalidated` event so staff still close it with an outcome.
 *
 * Bell: `notifications.type = attendance_warning`, target roles
 * `<dept>_dept,hr_dept,info_dept` — never school_admin / super_admin (§8).
 * Titles/messages are English + Amharic with Ethiopian-calendar dates only.
 * Rollout rows (`source=rollout`) never notify.
 *
 * Never writes `members.status`. Never crosses departments. Every public
 * entry point used from a write path is wrapped (`safeEvaluate*`) so a
 * failure here can never fail the attendance save.
 */
namespace App\Services;

require_once __DIR__ . '/FeatureGate.php';
require_once __DIR__ . '/SecurityAuditService.php';
require_once __DIR__ . '/DepartmentStatusService.php';
require_once __DIR__ . '/AttendancePolicyService.php';
require_once __DIR__ . '/AttendanceRiskService.php';

final class AttendanceWarningService
{
    public const TABLE = 'attendance_warnings';
    public const EVENTS = 'attendance_warning_events';
    public const KINDS = ['consecutive', 'low_month'];
    public const STAGES = ['standard', 'prolonged', 'critical'];
    public const OUTCOMES = ['contacted', 'excused', 'escalated', 'deactivated', 'no_action'];
    public const OUTCOME_LABELS = [
        'contacted' => 'Contacted', 'excused' => 'Excused', 'escalated' => 'Escalated',
        'deactivated' => 'Deactivated', 'no_action' => 'No Action',
    ];
    public const NOTIFICATION_TYPE = 'attendance_warning';
    /** Roles that receive the bell per department (§8: never admins). */
    public const BELL_ROLES = ['edu' => 'edu_dept,hr_dept,info_dept', 'mezmur' => 'mezmur_dept,hr_dept,info_dept'];
    /** Roles allowed to close, per department. Admins are read-only (§8). */
    public const CLOSE_ROLES = ['edu' => ['edu_dept', 'hr_dept', 'info_dept'], 'mezmur' => ['mezmur_dept', 'hr_dept', 'info_dept']];
    public const READ_ROLES = ['super_admin', 'school_admin', 'hr_dept', 'info_dept', 'edu_dept', 'mezmur_dept'];
    private const DEPT_AM = ['edu' => 'ትምህርት ክፍል', 'mezmur' => 'መዝሙር ክፍል'];

    /** @var array<int,bool> */
    private static array $tableCache = [];

    // ── availability ──────────────────────────────────────────────

    public static function enabled(): bool
    {
        return FeatureGate::isEnabled('attendance_v2');
    }

    public static function tableExists(\mysqli $conn): bool
    {
        $key = spl_object_id($conn);
        if (!isset(self::$tableCache[$key])) {
            $ok = false;
            try {
                $r = $conn->query("SHOW TABLES LIKE '" . self::TABLE . "'");
                $ok = $r && $r->num_rows > 0;
                if ($ok) {
                    $r2 = $conn->query("SHOW TABLES LIKE '" . self::EVENTS . "'");
                    $ok = $r2 && $r2->num_rows > 0;
                }
            } catch (\Throwable $e) {
                $ok = false;
            }
            self::$tableCache[$key] = $ok;
        }
        return self::$tableCache[$key];
    }

    public static function available(\mysqli $conn): bool
    {
        return self::enabled() && self::tableExists($conn);
    }

    public static function resetCache(): void
    {
        self::$tableCache = [];
    }

    /** Departments a role may see / close. @return string[] */
    public static function departmentsForRole(string $role): array
    {
        return match ($role) {
            'edu_dept' => ['edu'],
            'mezmur_dept' => ['mezmur'],
            'hr_dept', 'info_dept', 'school_admin', 'super_admin' => ['edu', 'mezmur'],
            default => [],
        };
    }

    public static function roleMayClose(string $role, string $dept): bool
    {
        return in_array($role, self::CLOSE_ROLES[$dept] ?? [], true);
    }

    // ── evaluation entry points ───────────────────────────────────

    /**
     * Evaluate one member in one department. Never throws from write paths —
     * use safeEvaluate(). Options: as_of (Y-m-d), month {year,month} (EC),
     * actor (int|null), source ('live'|'rollout'), notify (bool).
     * @return array{available:bool,risk:?array,actions:string[]}
     */
    public static function evaluate(\mysqli $conn, string $dept, int $memberId, array $opts = []): array
    {
        if (!self::available($conn) || !in_array($dept, AttendanceRiskService::DEPARTMENTS, true) || $memberId <= 0) {
            return ['available' => false, 'risk' => null, 'actions' => []];
        }
        $asOf = (string)($opts['as_of'] ?? date('Y-m-d'));
        $source = ($opts['source'] ?? 'live') === 'rollout' ? 'rollout' : 'live';
        $notify = $source === 'live' && ($opts['notify'] ?? true);
        $actor = isset($opts['actor']) ? (int)$opts['actor'] : null;
        $month = $opts['month'] ?? null;
        $policy = AttendancePolicyService::for($conn, $dept);
        $risk = AttendanceRiskService::riskFor($conn, $dept, $memberId, $asOf, $month);
        $actions = [];

        if ($risk['status'] === 'active') {
            $actions = array_merge($actions, self::evaluateConsecutive($conn, $dept, $memberId, $risk, $policy, $source, $notify, $actor));
            $actions = array_merge($actions, self::evaluateLowMonth($conn, $dept, $memberId, $risk, $policy, $source, $notify, $actor));
        }
        $risk = self::decorate($conn, $risk);
        return ['available' => true, 'risk' => $risk, 'actions' => $actions];
    }

    /** Write-path wrapper: logs and swallows every failure. */
    public static function safeEvaluate(\mysqli $conn, string $dept, int $memberId, array $opts = []): array
    {
        try {
            return self::evaluate($conn, $dept, $memberId, $opts);
        } catch (\Throwable $e) {
            error_log('Attendance warning evaluation failed (' . $dept . '#' . $memberId . '): ' . $e->getMessage());
            return ['available' => false, 'risk' => null, 'actions' => ['error']];
        }
    }

    /**
     * Evaluate many members after a sheet write. $date = the saved sheet's
     * date (decides the Ethiopian month that is re-checked).
     * @param int[] $memberIds
     * @return array{evaluated:int,actions:int}
     */
    public static function safeEvaluateMany(\mysqli $conn, string $dept, array $memberIds, string $date, ?int $actor = null): array
    {
        $out = ['evaluated' => 0, 'actions' => 0];
        if (!self::available($conn)) {
            return $out;
        }
        $ids = array_values(array_unique(array_filter(array_map('intval', $memberIds), static fn($i) => $i > 0)));
        if ($ids === []) {
            return $out;
        }
        $month = null;
        try {
            $ec = AttendanceRiskService::ecOf($date);
            $month = ['year' => $ec['year'], 'month' => $ec['month']];
        } catch (\Throwable $e) {
            $month = null;
        }
        foreach ($ids as $id) {
            $r = self::safeEvaluate($conn, $dept, $id, ['month' => $month, 'actor' => $actor]);
            $out['evaluated']++;
            $out['actions'] += count(array_filter($r['actions'], static fn($a) => $a !== 'error'));
        }
        return $out;
    }

    /** Member ids from a records array (web/mobile sheet payloads). @return int[] */
    public static function memberIdsFromRecords(array $records): array
    {
        $ids = [];
        foreach ($records as $r) {
            if (is_array($r) && isset($r['member_id'])) {
                $ids[] = (int)$r['member_id'];
            }
        }
        return array_values(array_unique(array_filter($ids, static fn($i) => $i > 0)));
    }

    // ── rules ─────────────────────────────────────────────────────

    private static function stageFor(int $length, array $policy): string
    {
        if ($length >= (int)$policy['critical_threshold']) {
            return 'critical';
        }
        if ($length >= (int)$policy['prolonged_threshold']) {
            return 'prolonged';
        }
        return 'standard';
    }

    private static function stageRank(?string $stage): int
    {
        return match ($stage) { 'critical' => 3, 'prolonged' => 2, 'standard' => 1, default => 0 };
    }

    /** @return string[] actions */
    private static function evaluateConsecutive(\mysqli $conn, string $dept, int $memberId, array $risk, array $policy, string $source, bool $notify, ?int $actor): array
    {
        $actions = [];
        $streak = $risk['streak'];
        $len = (int)$streak['length'];
        $open = self::findOpen($conn, $dept, $memberId, 'consecutive');

        if ($len < (int)$policy['consecutive_threshold']) {
            if ($open && (int)$open['below_threshold'] === 1) {
                self::update($conn, (int)$open['id'], ['below_threshold' => 0, 'last_event_at' => date('Y-m-d H:i:s')]);
                self::event($conn, (int)$open['id'], 'invalidated', $actor, [
                    'reason' => 'streak_below_threshold', 'streak_length' => $len, 'threshold' => (int)$policy['consecutive_threshold'],
                ]);
                $actions[] = 'consecutive_invalidated';
            }
            return $actions;
        }

        $stage = self::stageFor($len, $policy);
        $key = self::key('c', $dept, $memberId, (string)$streak['start']);
        $row = self::findByKey($conn, $key);
        $fields = [
            'stage' => $stage,
            'streak_length' => $len,
            'streak_start' => $streak['start'],
            'streak_last' => $streak['last'],
            'below_threshold' => 1,
            'last_event_at' => date('Y-m-d H:i:s'),
        ];
        $payload = ['stage' => $stage, 'streak_length' => $len, 'streak_start' => $streak['start'], 'streak_last' => $streak['last'],
                    'streak_start_ec' => $risk['streak']['start_ec'], 'streak_last_ec' => $risk['streak']['last_ec'],
                    'threshold' => (int)$policy['consecutive_threshold']];

        if ($row === null && $open !== null && self::overlaps($open, $streak)) {
            // Same absence episode whose first day was corrected: keep ONE row.
            $row = $open;
            $fields['dedupe_key'] = $key;
        }

        if ($row === null) {
            $id = self::insert($conn, [
                'department' => $dept, 'member_id' => $memberId, 'kind' => 'consecutive',
                'dedupe_key' => $key, 'threshold_value' => (int)$policy['consecutive_threshold'],
                'source' => $source, 'opened_at' => date('Y-m-d H:i:s'),
            ] + $fields);
            self::event($conn, $id, 'opened', $actor, $payload + ['source' => $source]);
            $actions[] = 'consecutive_opened';
            if ($notify) {
                self::notify($conn, $id, $dept, $memberId, 'consecutive', $stage, 'opened', $risk, $actor);
            }
            $row = self::find($conn, $id);
        } elseif ($row['status'] === 'closed') {
            if (self::stageRank($stage) > self::stageRank($row['stage'])) {
                self::update($conn, (int)$row['id'], $fields + ['status' => 'open', 'closed_at' => null, 'closed_by' => null,
                                                                 'outcome' => null, 'closure_note' => null]);
                self::event($conn, (int)$row['id'], 'reopened', $actor, $payload + ['previous_stage' => $row['stage']]);
                $actions[] = 'consecutive_reopened';
                if ($notify) {
                    self::notify($conn, (int)$row['id'], $dept, $memberId, 'consecutive', $stage, 'reopened', $risk, $actor);
                }
                $row = self::find($conn, (int)$row['id']);
            } else {
                return $actions; // already handled and closed for this streak
            }
        } else {
            $escalated = self::stageRank($stage) > self::stageRank($row['stage']);
            if (!$escalated) {
                $fields['stage'] = $row['stage']; // never de-escalate in place
            }
            $changed = $escalated
                || (int)$row['streak_length'] !== $len
                || (string)$row['streak_last'] !== (string)$streak['last']
                || (int)$row['below_threshold'] !== 1
                || isset($fields['dedupe_key']);
            if ($changed) {
                self::update($conn, (int)$row['id'], $fields);
            }
            if ($escalated) {
                self::event($conn, (int)$row['id'], 'escalated', $actor, $payload + ['previous_stage' => $row['stage']]);
                $actions[] = 'consecutive_escalated_' . $stage;
                if ($notify) {
                    self::notify($conn, (int)$row['id'], $dept, $memberId, 'consecutive', $stage, 'escalated', $risk, $actor);
                }
            }
            $row = self::find($conn, (int)$row['id']);
        }

        // Critical + auto-deactivate (policy, default OFF).
        if ($row && $stage === 'critical' && (int)$policy['auto_deactivate'] === 1
            && (int)$row['auto_deactivated'] === 0 && $risk['status'] === 'active') {
            try {
                $reason = 'Automatic: ' . $len . ' consecutive absences (critical threshold ' . (int)$policy['critical_threshold'] . ')';
                $res = DepartmentStatusService::setStatus($conn, $memberId, $dept, 'inactive', 0, $reason, 'auto', (int)$row['id']);
                if (!empty($res['changed'])) {
                    self::update($conn, (int)$row['id'], ['auto_deactivated' => 1, 'last_event_at' => date('Y-m-d H:i:s')]);
                    self::event($conn, (int)$row['id'], 'auto_deactivated', null, $payload + ['period_id' => $res['period_id'] ?? null]);
                    $actions[] = 'auto_deactivated';
                    if ($notify) {
                        self::notify($conn, (int)$row['id'], $dept, $memberId, 'consecutive', 'critical', 'auto_deactivated', $risk, $actor);
                    }
                }
            } catch (\Throwable $e) {
                error_log('Attendance auto-deactivate failed: ' . $e->getMessage());
            }
        }
        return $actions;
    }

    /** @return string[] actions */
    private static function evaluateLowMonth(\mysqli $conn, string $dept, int $memberId, array $risk, array $policy, string $source, bool $notify, ?int $actor): array
    {
        $actions = [];
        $m = $risk['month'];
        $sessions = (int)$m['sessions'];
        $rate = $m['rate'];
        $key = self::key('m', $dept, $memberId, $m['ec_year'] . '-' . $m['ec_month']);
        $row = self::findByKey($conn, $key);
        $below = $rate !== null && $sessions >= (int)$policy['min_sessions'] && (float)$rate < (float)$policy['low_rate_threshold'];
        $now = date('Y-m-d H:i:s');

        if ($below) {
            if ($row === null) {
                $id = self::insert($conn, [
                    'department' => $dept, 'member_id' => $memberId, 'kind' => 'low_month', 'stage' => null,
                    'dedupe_key' => $key, 'threshold_value' => (float)$policy['low_rate_threshold'],
                    'ec_year' => (int)$m['ec_year'], 'ec_month' => (int)$m['ec_month'],
                    'sessions' => $sessions, 'rate' => $rate, 'below_threshold' => 1,
                    'source' => $source, 'opened_at' => $now, 'last_event_at' => $now,
                ]);
                self::event($conn, $id, 'opened', $actor, ['ec_year' => $m['ec_year'], 'ec_month' => $m['ec_month'],
                    'sessions' => $sessions, 'rate' => $rate, 'threshold' => (float)$policy['low_rate_threshold'], 'source' => $source]);
                $actions[] = 'low_month_opened';
                if ($notify) {
                    self::notify($conn, $id, $dept, $memberId, 'low_month', null, 'opened', $risk, $actor);
                }
            } elseif ($row['status'] === 'open') {
                if ((float)$row['rate'] !== (float)$rate || (int)$row['sessions'] !== $sessions || (int)$row['below_threshold'] !== 1) {
                    self::update($conn, (int)$row['id'], ['sessions' => $sessions, 'rate' => $rate, 'below_threshold' => 1]);
                }
            }
            return $actions;
        }

        if ($row !== null && $row['status'] === 'open') {
            $upd = ['sessions' => $sessions, 'rate' => $rate];
            if ((int)$row['below_threshold'] === 1) {
                $upd['below_threshold'] = 0;
                $upd['last_event_at'] = $now;
                self::event($conn, (int)$row['id'], 'invalidated', $actor, ['reason' => 'month_recovered', 'sessions' => $sessions, 'rate' => $rate]);
                $actions[] = 'low_month_invalidated';
            }
            self::update($conn, (int)$row['id'], $upd);
        }
        return $actions;
    }

    private static function overlaps(array $open, array $streak): bool
    {
        if (empty($open['streak_start']) || empty($open['streak_last']) || empty($streak['start'])) {
            return false;
        }
        return $streak['start'] <= $open['streak_last'] && $streak['last'] >= $open['streak_start'];
    }

    private static function key(string $prefix, string $dept, int $memberId, string $tail): string
    {
        return $prefix . '|' . $dept . '|' . $memberId . '|' . $tail;
    }

    // ── notifications (bell only) ─────────────────────────────────

    private static function notify(\mysqli $conn, int $warningId, string $dept, int $memberId, string $kind, ?string $stage, string $event, array $risk, ?int $actor): void
    {
        try {
            $member = self::memberName($conn, $memberId);
            $deptEn = AttendanceRiskService::LABELS[$dept];
            $deptAm = self::DEPT_AM[$dept];
            $s = $risk['streak'];
            $m = $risk['month'];
            $data = [
                'warning_id' => $warningId, 'department' => $dept, 'member_id' => $memberId,
                'kind' => $kind, 'stage' => $stage, 'event' => $event,
                'streak_length' => (int)$s['length'], 'streak_start_ec' => $s['start_ec'], 'streak_last_ec' => $s['last_ec'],
                'absence_dates_ec' => array_map([AttendanceRiskService::class, 'ecLabel'], $s['dates']),
                'ec_year' => (int)$m['ec_year'], 'ec_month' => (int)$m['ec_month'], 'rate' => $m['rate'],
                'auto_deactivated' => $event === 'auto_deactivated' ? 1 : 0,
            ];
            $priority = 'high';
            if ($kind === 'consecutive') {
                $range = ($s['start_ec'] ?? '') . ' – ' . ($s['last_ec'] ?? '');
                $rangeAm = ($s['start_ec_am'] ?? '') . ' – ' . ($s['last_ec_am'] ?? '');
                $n = (int)$s['length'];
                switch ($event) {
                    case 'auto_deactivated':
                        $priority = 'urgent';
                        $title = "{$deptEn}: member deactivated after {$n} consecutive absences · አባሉ ኢ-ንቁ ተደርጓል";
                        $msg = "{$member} was automatically set INACTIVE in {$deptEn} after {$n} consecutive absences ({$range}). "
                             . "HR / Information may reactivate from the member profile.\n"
                             . "{$member} በ{$deptAm} {$n} ተከታታይ ቀናት በመቅረቱ ({$rangeAm}) በራስ-ሰር ኢ-ንቁ ተደርጓል።";
                        break;
                    default:
                        $stageEn = ['standard' => 'Consecutive absence', 'prolonged' => 'Prolonged absence', 'critical' => 'Critical absence'][$stage] ?? 'Consecutive absence';
                        $stageAm = ['standard' => 'ተከታታይ መቅረት', 'prolonged' => 'የተራዘመ መቅረት', 'critical' => 'ከባድ መቅረት'][$stage] ?? 'ተከታታይ መቅረት';
                        if ($stage === 'critical') {
                            $priority = 'urgent';
                        }
                        $title = "{$deptEn}: {$stageEn} warning · {$stageAm} ማስጠንቀቂያ";
                        $msg = "{$member}: {$n} consecutive absences in {$deptEn} ({$range})."
                             . ($stage === 'critical' ? ' Review of the department status is recommended.' : '')
                             . "\n{$member}: በ{$deptAm} {$n} ተከታታይ የመቅረት ቀናት ({$rangeAm})።"
                             . ($stage === 'critical' ? ' የክፍል ሁኔታ እንዲገመገም ይመከራል።' : '');
                }
            } else {
                $rate = $m['rate'] !== null ? number_format((float)$m['rate'], 1) : '—';
                $title = "{$deptEn}: Low monthly attendance · ዝቅተኛ የወር ተገኝነት";
                $msg = "{$member}: {$rate}% attendance in {$m['label']} ({$m['sessions']} sessions) in {$deptEn}.\n"
                     . "{$member}: በ{$deptAm} የ{$m['label_am']} ተገኝነት {$rate}% ({$m['sessions']} ክፍለ ጊዜዎች)።";
            }
            $stmt = $conn->prepare(
                'INSERT INTO notifications (type, title, message, data, priority, source_dept, source_user_id, target_roles, target_user_id)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, NULL)'
            );
            if (!$stmt) {
                return;
            }
            $type = self::NOTIFICATION_TYPE;
            $json = json_encode($data, JSON_UNESCAPED_UNICODE);
            $sourceDept = $dept . '_dept';
            $roles = self::BELL_ROLES[$dept];
            $stmt->bind_param('ssssssis', $type, $title, $msg, $json, $priority, $sourceDept, $actor, $roles);
            $stmt->execute();
            $notificationId = (int)$conn->insert_id;
            $stmt->close();
            self::event($conn, $warningId, 'notified', $actor, ['notification_id' => $notificationId, 'event' => $event, 'roles' => $roles]);
        } catch (\Throwable $e) {
            error_log('Attendance warning notification failed: ' . $e->getMessage());
        }
    }

    private static function memberName(\mysqli $conn, int $memberId): string
    {
        $stmt = $conn->prepare('SELECT student_name, father_name, member_code FROM members WHERE id = ? LIMIT 1');
        if (!$stmt) {
            return '#' . $memberId;
        }
        $stmt->bind_param('i', $memberId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$row) {
            return '#' . $memberId;
        }
        $name = trim((string)$row['student_name'] . ' ' . (string)$row['father_name']);
        return $name !== '' ? $name : ('#' . $memberId);
    }

    // ── closing ───────────────────────────────────────────────────

    /**
     * Close with an outcome. $role is enforced here as well as in the API
     * (edu_dept closes edu, mezmur_dept closes mezmur, hr/info both; admins
     * never). @return array<string,mixed> the closed warning
     */
    public static function close(\mysqli $conn, int $id, string $outcome, string $note, int $actorId, string $role, ?SecurityAuditActor $auditActor = null): array
    {
        if (!self::available($conn)) {
            throw new \RuntimeException('Attendance warnings are not available yet (feature disabled or sql/072 not applied).');
        }
        $outcome = strtolower(trim($outcome));
        if (!in_array($outcome, self::OUTCOMES, true)) {
            throw new \DomainException('Outcome must be one of: Contacted, Excused, Escalated, Deactivated, No Action.');
        }
        $note = trim($note);
        if (mb_strlen($note) > 500) {
            throw new \DomainException('The note is too long (maximum 500 characters).');
        }
        $row = self::find($conn, $id);
        if (!$row) {
            throw new \DomainException('Warning not found.');
        }
        if (!self::roleMayClose($role, (string)$row['department'])) {
            throw new \DomainException('You cannot close ' . AttendanceRiskService::LABELS[$row['department']] . ' warnings.');
        }
        if ($row['status'] === 'closed') {
            throw new \DomainException('This warning is already closed.');
        }
        $now = date('Y-m-d H:i:s');
        self::update($conn, $id, ['status' => 'closed', 'closed_at' => $now, 'closed_by' => $actorId,
                                   'outcome' => $outcome, 'closure_note' => $note !== '' ? $note : null, 'last_event_at' => $now]);
        self::event($conn, $id, 'closed', $actorId, ['outcome' => $outcome, 'note' => $note]);
        $details = ['warning_id' => $id, 'department' => $row['department'], 'member_id' => (int)$row['member_id'],
                    'kind' => $row['kind'], 'stage' => $row['stage'], 'outcome' => $outcome, 'note' => $note];
        try {
            if ($auditActor !== null) {
                SecurityAuditService::recordTrusted($conn, $auditActor, 'Attendance Warning Closed', $details, 'member', (int)$row['member_id']);
            } else {
                SecurityAuditService::record($conn, 'Attendance Warning Closed', $details, 'member', (int)$row['member_id']);
            }
        } catch (\Throwable $e) {
            error_log('Attendance warning audit failed: ' . $e->getMessage());
        }
        return self::present(self::find($conn, $id));
    }

    // ── reads ─────────────────────────────────────────────────────

    /**
     * @param array<string,mixed> $f department(s), status, kind, stage, member_id, source, q, limit, offset
     * @return array{items:array<int,array<string,mixed>>,total:int}
     */
    public static function list(\mysqli $conn, array $f = []): array
    {
        if (!self::available($conn)) {
            return ['items' => [], 'total' => 0];
        }
        $depts = array_values(array_intersect((array)($f['departments'] ?? AttendanceRiskService::DEPARTMENTS), AttendanceRiskService::DEPARTMENTS));
        if ($depts === []) {
            return ['items' => [], 'total' => 0];
        }
        $where = ['w.department IN (' . implode(',', array_fill(0, count($depts), '?')) . ')'];
        $types = str_repeat('s', count($depts));
        $params = $depts;
        $status = (string)($f['status'] ?? 'open');
        if (in_array($status, ['open', 'closed'], true)) {
            $where[] = 'w.status = ?';
            $types .= 's';
            $params[] = $status;
        }
        if (!empty($f['kind']) && in_array($f['kind'], self::KINDS, true)) {
            $where[] = 'w.kind = ?';
            $types .= 's';
            $params[] = $f['kind'];
        }
        if (!empty($f['stage']) && in_array($f['stage'], self::STAGES, true)) {
            $where[] = 'w.stage = ?';
            $types .= 's';
            $params[] = $f['stage'];
        }
        if (!empty($f['member_id'])) {
            $where[] = 'w.member_id = ?';
            $types .= 'i';
            $params[] = (int)$f['member_id'];
        }
        if (!empty($f['source']) && in_array($f['source'], ['live', 'rollout'], true)) {
            $where[] = 'w.source = ?';
            $types .= 's';
            $params[] = $f['source'];
        }
        if (!empty($f['q'])) {
            $q = '%' . trim((string)$f['q']) . '%';
            $where[] = '(m.student_name LIKE ? OR m.father_name LIKE ? OR m.full_name_am LIKE ? OR m.member_code LIKE ?)';
            $types .= 'ssss';
            array_push($params, $q, $q, $q, $q);
        }
        $limit = max(1, min(500, (int)($f['limit'] ?? 100)));
        $offset = max(0, (int)($f['offset'] ?? 0));
        $sqlWhere = implode(' AND ', $where);

        $cnt = $conn->prepare("SELECT COUNT(*) AS c FROM " . self::TABLE . " w JOIN members m ON m.id = w.member_id WHERE $sqlWhere");
        $cnt->bind_param($types, ...$params);
        $cnt->execute();
        $total = (int)($cnt->get_result()->fetch_assoc()['c'] ?? 0);
        $cnt->close();

        $stmt = $conn->prepare(
            "SELECT w.*, m.student_name, m.father_name, m.full_name_am, m.member_code, m.current_section, m.status AS member_status
             FROM " . self::TABLE . " w JOIN members m ON m.id = w.member_id
             WHERE $sqlWhere
             ORDER BY w.status ASC, FIELD(w.stage,'critical','prolonged','standard') ASC, w.opened_at DESC, w.id DESC
             LIMIT $limit OFFSET $offset"
        );
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $res = $stmt->get_result();
        $items = [];
        while ($row = $res->fetch_assoc()) {
            $items[] = self::present($row);
        }
        $stmt->close();
        return ['items' => $items, 'total' => $total];
    }

    /** Open counts per department / stage / kind for the dashboard cards. */
    public static function openCounts(\mysqli $conn, array $departments = AttendanceRiskService::DEPARTMENTS): array
    {
        $out = [];
        foreach ($departments as $d) {
            $out[$d] = ['label' => AttendanceRiskService::LABELS[$d], 'total' => 0, 'consecutive' => 0, 'low_month' => 0,
                        'standard' => 0, 'prolonged' => 0, 'critical' => 0, 'rollout' => 0];
        }
        if (!self::available($conn) || $out === []) {
            return $out;
        }
        $res = $conn->query(
            "SELECT department, kind, stage, source, COUNT(*) AS c FROM " . self::TABLE .
            " WHERE status = 'open' GROUP BY department, kind, stage, source"
        );
        while ($res && ($row = $res->fetch_assoc())) {
            $d = (string)$row['department'];
            if (!isset($out[$d])) {
                continue;
            }
            $c = (int)$row['c'];
            $out[$d]['total'] += $c;
            $out[$d][(string)$row['kind']] += $c;
            if ($row['stage'] !== null && isset($out[$d][(string)$row['stage']])) {
                $out[$d][(string)$row['stage']] += $c;
            }
            if ($row['source'] === 'rollout') {
                $out[$d]['rollout'] += $c;
            }
        }
        return $out;
    }

    /** @return array<string,mixed>|null */
    public static function detail(\mysqli $conn, int $id): ?array
    {
        $row = self::find($conn, $id);
        if (!$row) {
            return null;
        }
        $stmt = $conn->prepare(
            "SELECT w.*, m.student_name, m.father_name, m.full_name_am, m.member_code, m.current_section, m.status AS member_status
             FROM " . self::TABLE . " w JOIN members m ON m.id = w.member_id WHERE w.id = ? LIMIT 1"
        );
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $full = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        $out = self::present($full ?: $row);
        $out['events'] = self::events($conn, $id);
        $dept = (string)$row['department'];
        try {
            $risk = AttendanceRiskService::riskFor($conn, $dept, (int)$row['member_id'], null,
                $row['kind'] === 'low_month' ? ['year' => (int)$row['ec_year'], 'month' => (int)$row['ec_month']] : null);
            $out['risk'] = self::decorate($conn, $risk);
            $out['absence_dates_ec'] = array_map([AttendanceRiskService::class, 'ecLabel'], $risk['streak']['dates']);
        } catch (\Throwable $e) {
            $out['risk'] = null;
            $out['absence_dates_ec'] = [];
        }
        return $out;
    }

    /** @return array<int,array<string,mixed>> */
    public static function events(\mysqli $conn, int $warningId): array
    {
        $stmt = $conn->prepare(
            "SELECT e.id, e.event, e.actor_user_id, e.payload, e.created_at, u.username AS actor_username
             FROM " . self::EVENTS . " e LEFT JOIN users u ON u.id = e.actor_user_id
             WHERE e.warning_id = ? ORDER BY e.id ASC"
        );
        $stmt->bind_param('i', $warningId);
        $stmt->execute();
        $res = $stmt->get_result();
        $out = [];
        while ($r = $res->fetch_assoc()) {
            $payload = json_decode((string)$r['payload'], true);
            if (is_array($payload)) {
                // Machine dates stay in the audit row; clients only get EC labels.
                unset($payload['streak_start'], $payload['streak_last']);
            }
            $out[] = [
                'id' => (int)$r['id'],
                'event' => (string)$r['event'],
                'actor_user_id' => $r['actor_user_id'] !== null ? (int)$r['actor_user_id'] : null,
                'actor' => $r['actor_username'] ?? ($r['actor_user_id'] === null ? 'system' : null),
                'payload' => is_array($payload) ? $payload : [],
                'created_ec' => self::ecDateTime((string)$r['created_at']),
            ];
        }
        $stmt->close();
        return $out;
    }

    /** Both departments for one member: risk + open/closed warnings (HR/Info profile tab). */
    public static function memberSummary(\mysqli $conn, int $memberId, array $departments = AttendanceRiskService::DEPARTMENTS): array
    {
        $out = ['member_id' => $memberId, 'available' => self::available($conn), 'departments' => []];
        foreach ($departments as $d) {
            $entry = ['department' => $d, 'label' => AttendanceRiskService::LABELS[$d], 'risk' => null, 'warnings' => []];
            try {
                $entry['risk'] = self::decorate($conn, AttendanceRiskService::riskFor($conn, $d, $memberId));
            } catch (\Throwable $e) {
                $entry['risk'] = null;
            }
            if ($out['available']) {
                $entry['warnings'] = self::list($conn, ['departments' => [$d], 'member_id' => $memberId, 'status' => 'all', 'limit' => 50])['items'];
            }
            $out['departments'][$d] = $entry;
        }
        return $out;
    }

    /** Open warning per member for sheet indicators. @return array<int,array{id:int,kind:string,stage:?string}> */
    public static function openByMembers(\mysqli $conn, string $dept, array $memberIds): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $memberIds), static fn($i) => $i > 0)));
        if ($ids === [] || !self::available($conn)) {
            return [];
        }
        $in = implode(',', $ids);
        $stmt = $conn->prepare(
            "SELECT id, member_id, kind, stage FROM " . self::TABLE .
            " WHERE department = ? AND status = 'open' AND member_id IN ($in)
             ORDER BY FIELD(stage,'critical','prolonged','standard') ASC, id DESC"
        );
        $stmt->bind_param('s', $dept);
        $stmt->execute();
        $res = $stmt->get_result();
        $out = [];
        while ($r = $res->fetch_assoc()) {
            $mid = (int)$r['member_id'];
            if (!isset($out[$mid])) {
                $out[$mid] = ['id' => (int)$r['id'], 'kind' => (string)$r['kind'], 'stage' => $r['stage']];
            }
        }
        $stmt->close();
        return $out;
    }

    /** Fill risk.open_warning from the table (consecutive first). */
    public static function decorate(\mysqli $conn, array $risk): array
    {
        $risk['open_warning'] = null;
        if (!self::available($conn)) {
            return $risk;
        }
        $open = self::findOpen($conn, (string)$risk['department'], (int)$risk['member_id'], null);
        if ($open) {
            $risk['open_warning'] = ['id' => (int)$open['id'], 'kind' => (string)$open['kind'], 'stage' => $open['stage'],
                                     'below_threshold' => (int)$open['below_threshold'], 'opened_ec' => self::ecDateTime((string)$open['opened_at'])];
        }
        return $risk;
    }

    /** Presentation: EC labels + reason text (all user-facing dates Ethiopian). */
    public static function present(array $r): array
    {
        $dept = (string)$r['department'];
        $kind = (string)$r['kind'];
        $startEc = !empty($r['streak_start']) ? AttendanceRiskService::ecLabel((string)$r['streak_start']) : null;
        $lastEc = !empty($r['streak_last']) ? AttendanceRiskService::ecLabel((string)$r['streak_last']) : null;
        $startEcAm = !empty($r['streak_start']) ? AttendanceRiskService::ecLabelAm((string)$r['streak_start']) : null;
        $lastEcAm = !empty($r['streak_last']) ? AttendanceRiskService::ecLabelAm((string)$r['streak_last']) : null;
        $monthLabel = $r['ec_month'] !== null ? ethio_month_name((int)$r['ec_month']) . ' ' . (int)$r['ec_year'] : null;
        $monthLabelAm = $r['ec_month'] !== null ? AttendanceRiskService::monthLabelAm((int)$r['ec_year'], (int)$r['ec_month']) : null;
        if ($kind === 'consecutive') {
            $reason = (int)$r['streak_length'] . ' consecutive absences, ' . $startEc . ' – ' . $lastEc;
            $reasonAm = (int)$r['streak_length'] . ' ተከታታይ የመቅረት ቀናት፣ ' . $startEcAm . ' – ' . $lastEcAm;
        } else {
            $rate = $r['rate'] !== null ? number_format((float)$r['rate'], 1) . '%' : '—';
            $reason = 'Low month ' . $monthLabel . ': ' . $rate . ' (' . (int)$r['sessions'] . ' sessions)';
            $reasonAm = 'ዝቅተኛ የወር ተገኝነት ' . $monthLabelAm . '፡ ' . $rate;
        }
        $name = trim((string)($r['student_name'] ?? '') . ' ' . (string)($r['father_name'] ?? ''));
        return [
            'id' => (int)$r['id'],
            'department' => $dept,
            'department_label' => AttendanceRiskService::LABELS[$dept] ?? $dept,
            'member_id' => (int)$r['member_id'],
            'member_name' => $name,
            'member_name_am' => (string)($r['full_name_am'] ?? ''),
            'member_code' => (string)($r['member_code'] ?? ''),
            'section' => (string)($r['current_section'] ?? ''),
            'kind' => $kind,
            'stage' => $r['stage'],
            'threshold_value' => (float)$r['threshold_value'],
            'streak_length' => $r['streak_length'] !== null ? (int)$r['streak_length'] : null,
            'streak_start_ec' => $startEc,
            'streak_last_ec' => $lastEc,
            'ec_year' => $r['ec_year'] !== null ? (int)$r['ec_year'] : null,
            'ec_month' => $r['ec_month'] !== null ? (int)$r['ec_month'] : null,
            'month_label' => $monthLabel,
            'month_label_am' => $monthLabelAm,
            'sessions' => $r['sessions'] !== null ? (int)$r['sessions'] : null,
            'rate' => $r['rate'] !== null ? (float)$r['rate'] : null,
            'reason' => $reason,
            'reason_am' => $reasonAm,
            'status' => (string)$r['status'],
            'below_threshold' => (int)$r['below_threshold'],
            'source' => (string)$r['source'],
            'opened_ec' => self::ecDateTime((string)$r['opened_at']),
            'last_event_ec' => !empty($r['last_event_at']) ? self::ecDateTime((string)$r['last_event_at']) : null,
            'closed_ec' => !empty($r['closed_at']) ? self::ecDateTime((string)$r['closed_at']) : null,
            'closed_by' => $r['closed_by'] !== null ? (int)$r['closed_by'] : null,
            'outcome' => $r['outcome'],
            'outcome_label' => $r['outcome'] !== null ? (self::OUTCOME_LABELS[$r['outcome']] ?? $r['outcome']) : null,
            'closure_note' => $r['closure_note'],
            'auto_deactivated' => (int)$r['auto_deactivated'],
        ];
    }

    private static function ecDateTime(string $dt): string
    {
        try {
            $d = new \DateTime($dt, new \DateTimeZone('Africa/Addis_Ababa'));
            return ethio_date_format($d, 'F j, Y') . ' ' . $d->format('H:i');
        } catch (\Throwable $e) {
            return $dt;
        }
    }

    // ── low-level persistence ─────────────────────────────────────

    /** @return array<string,mixed>|null */
    public static function find(\mysqli $conn, int $id): ?array
    {
        $stmt = $conn->prepare('SELECT * FROM ' . self::TABLE . ' WHERE id = ? LIMIT 1');
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $row ?: null;
    }

    private static function findByKey(\mysqli $conn, string $key): ?array
    {
        $stmt = $conn->prepare('SELECT * FROM ' . self::TABLE . ' WHERE dedupe_key = ? LIMIT 1');
        $stmt->bind_param('s', $key);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $row ?: null;
    }

    private static function findOpen(\mysqli $conn, string $dept, int $memberId, ?string $kind): ?array
    {
        $sql = 'SELECT * FROM ' . self::TABLE . " WHERE department = ? AND member_id = ? AND status = 'open'";
        if ($kind !== null) {
            $sql .= ' AND kind = ?';
        }
        $sql .= " ORDER BY FIELD(kind,'consecutive','low_month') ASC, id DESC LIMIT 1";
        $stmt = $conn->prepare($sql);
        if ($kind !== null) {
            $stmt->bind_param('sis', $dept, $memberId, $kind);
        } else {
            $stmt->bind_param('si', $dept, $memberId);
        }
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $row ?: null;
    }

    private static function insert(\mysqli $conn, array $fields): int
    {
        $cols = array_keys($fields);
        $sql = 'INSERT INTO ' . self::TABLE . ' (`' . implode('`,`', $cols) . '`) VALUES (' . implode(',', array_fill(0, count($cols), '?')) . ')';
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            throw new \RuntimeException('Attendance warning insert failed to prepare.');
        }
        $types = str_repeat('s', count($cols));
        $vals = array_values($fields);
        $stmt->bind_param($types, ...$vals);
        $stmt->execute();
        $id = (int)$conn->insert_id;
        $stmt->close();
        return $id;
    }

    private static function update(\mysqli $conn, int $id, array $fields): void
    {
        if ($fields === []) {
            return;
        }
        $set = [];
        foreach (array_keys($fields) as $c) {
            $set[] = '`' . $c . '` = ?';
        }
        $stmt = $conn->prepare('UPDATE ' . self::TABLE . ' SET ' . implode(', ', $set) . ' WHERE id = ?');
        if (!$stmt) {
            throw new \RuntimeException('Attendance warning update failed to prepare.');
        }
        $vals = array_values($fields);
        $vals[] = $id;
        $stmt->bind_param(str_repeat('s', count($fields)) . 'i', ...$vals);
        $stmt->execute();
        $stmt->close();
    }

    private static function event(\mysqli $conn, int $warningId, string $event, ?int $actor, array $payload): void
    {
        $stmt = $conn->prepare('INSERT INTO ' . self::EVENTS . ' (warning_id, event, actor_user_id, payload) VALUES (?, ?, ?, ?)');
        if (!$stmt) {
            return;
        }
        $actorOrNull = ($actor !== null && $actor > 0) ? $actor : null;
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE);
        $stmt->bind_param('isis', $warningId, $event, $actorOrNull, $json);
        $stmt->execute();
        $stmt->close();
    }
}
