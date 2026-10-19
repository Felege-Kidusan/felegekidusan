<?php
/**
 * ════════════════════════════════════════════════════════════
 * AttendanceSubmissionEventService — append-only per-packet history
 * for attendance submissions (Phase 3 of the attendance alerting
 * plan, requirements §5 "Audit").
 *
 * Why: grade_submissions / mezmur_submissions keep only the LAST
 * reviewer decision (reviewed_by / reviewed_at / review_notes) and
 * clear it when the taker resubmits. The requirement asks for "who
 * submitted, who reopened or rejected, when, and the reason" for
 * every step — so every transition is appended here as well.
 *
 * Best-effort by design: recording never throws and never fails the
 * save/review that triggered it (missing table before sql/073 is
 * applied → silently skipped; detail views show an empty history).
 * Rows are never updated or deleted.
 * ════════════════════════════════════════════════════════════
 */

namespace App\Services;

if (!function_exists('gregorian_to_ethiopian')) {
    require_once dirname(__DIR__) . '/ethiopian_date.php';
}

final class AttendanceSubmissionEventService
{
    public const DEPARTMENTS = ['edu', 'mezmur'];

    /** @var array<string,bool> */
    private static array $tableCache = [];

    public static function tableExists(\mysqli $conn): bool
    {
        $key = spl_object_id($conn) . ':attendance_submission_events';
        if (!array_key_exists($key, self::$tableCache)) {
            try {
                $res = $conn->query("SHOW TABLES LIKE 'attendance_submission_events'");
                self::$tableCache[$key] = $res !== false && $res->num_rows > 0;
            } catch (\Throwable $e) {
                self::$tableCache[$key] = false;
            }
        }
        return self::$tableCache[$key];
    }

    public static function resetCache(): void
    {
        self::$tableCache = [];
    }

    /**
     * Append one event. Never throws; returns the new id or 0.
     *
     * $toStatus is the packet status after the step (submitted, approved,
     * revision_needed, rejected). $fromStatus '' = first submission.
     */
    public static function record(
        \mysqli $conn,
        string $department,
        int $packetId,
        string $fromStatus,
        string $toStatus,
        ?int $actorId,
        string $reason = '',
        string $channel = 'web'
    ): int {
        if (!in_array($department, self::DEPARTMENTS, true) || $packetId <= 0 || $toStatus === '') {
            return 0;
        }
        if (!self::tableExists($conn)) {
            return 0;
        }
        $fromStatus = mb_substr(trim($fromStatus), 0, 20);
        $toStatus = mb_substr(trim($toStatus), 0, 20);
        $reason = mb_substr(trim($reason), 0, 500);
        $channel = mb_substr(trim($channel) === '' ? 'web' : trim($channel), 0, 10);
        $actor = $actorId !== null && $actorId > 0 ? $actorId : null;
        try {
            $stmt = $conn->prepare(
                "INSERT INTO attendance_submission_events
                    (department, packet_id, from_status, to_status, actor_id, reason, channel)
                 VALUES (?, ?, ?, ?, ?, ?, ?)"
            );
            if (!$stmt) {
                return 0;
            }
            $stmt->bind_param('sississ', $department, $packetId, $fromStatus, $toStatus, $actor, $reason, $channel);
            $ok = $stmt->execute();
            $id = (int)$stmt->insert_id;
            $stmt->close();
            return $ok ? $id : 0;
        } catch (\Throwable $e) {
            error_log('attendance_submission_events insert failed: ' . $e->getMessage());
            return 0;
        }
    }

    /**
     * Oldest-first history for one packet, presented for UI/API
     * (Ethiopian-calendar timestamps only).
     *
     * @return list<array{id:int,from_status:string,to_status:string,event:string,label:string,actor_id:int|null,actor_name:string,reason:string,channel:string,created_ec:string}>
     */
    public static function forPacket(\mysqli $conn, string $department, int $packetId): array
    {
        if (!in_array($department, self::DEPARTMENTS, true) || $packetId <= 0 || !self::tableExists($conn)) {
            return [];
        }
        try {
            $stmt = $conn->prepare(
                "SELECT e.id, e.from_status, e.to_status, e.actor_id, e.reason, e.channel, e.created_at,
                        u.full_name AS actor_name
                 FROM attendance_submission_events e
                 LEFT JOIN users u ON u.id = e.actor_id
                 WHERE e.department = ? AND e.packet_id = ?
                 ORDER BY e.id ASC
                 LIMIT 200"
            );
            if (!$stmt) {
                return [];
            }
            $stmt->bind_param('si', $department, $packetId);
            $stmt->execute();
            $res = $stmt->get_result();
            $out = [];
            while ($row = $res->fetch_assoc()) {
                $out[] = self::present($row);
            }
            $stmt->close();
            return $out;
        } catch (\Throwable $e) {
            return [];
        }
    }

    /** Pure: event code + label from a (from, to) pair. @return array{0:string,1:string} */
    public static function eventFor(string $fromStatus, string $toStatus): array
    {
        $from = strtolower(trim($fromStatus));
        $to = strtolower(trim($toStatus));
        if ($to === 'submitted') {
            return in_array($from, ['', 'draft', 'incomplete'], true)
                ? ['submitted', 'Submitted']
                : ['resubmitted', 'Resubmitted after correction'];
        }
        if ($to === 'approved') {
            return ['approved', 'Approved'];
        }
        if ($to === 'revision_needed') {
            return $from === 'approved'
                ? ['reopened', 'Reopened for correction (was approved)']
                : ['returned', 'Returned for correction'];
        }
        if ($to === 'rejected') {
            return $from === 'approved'
                ? ['rejected', 'Rejected (was approved)']
                : ['rejected', 'Rejected'];
        }
        return [$to, ucfirst(str_replace('_', ' ', $to))];
    }

    /** @param array<string,mixed> $row */
    private static function present(array $row): array
    {
        [$event, $label] = self::eventFor((string)$row['from_status'], (string)$row['to_status']);
        return [
            'id' => (int)$row['id'],
            'from_status' => (string)$row['from_status'],
            'to_status' => (string)$row['to_status'],
            'event' => $event,
            'label' => $label,
            'actor_id' => $row['actor_id'] !== null ? (int)$row['actor_id'] : null,
            'actor_name' => (string)($row['actor_name'] ?? ''),
            'reason' => (string)$row['reason'],
            'channel' => (string)$row['channel'],
            'created_ec' => self::ecDateTime((string)$row['created_at']),
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
}
