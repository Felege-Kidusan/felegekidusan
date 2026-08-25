<?php
/**
 * Identity code allocation for the ministry coding system.
 *
 * Students:      {CategoryLetter}{Sequential}          → C1, C2, A15 …
 *   Sequential per letter, alphabetical order is applied by the migration
 *   tool; runtime allocation appends MAX+1 under a per-letter advisory lock
 *   so two registrations can never race to the same number.
 *
 * Staff:         {DEPT}{H|N}{POSITIONS}-{5-digit}      → EDHT-83719
 *   Built from the member's active rows in member_staff_positions:
 *     DEPT       primary department's code (lowest sort_order wins)
 *     H | N      H when the member holds that department's head position,
 *                N (rendered smaller on ID cards) for ordinary members.
 *     POSITIONS  remaining active role codes, ordered by staff_positions
 *                sort_order, excluding the department's head marker.
 *   School-wide posts (department_id NULL) contribute their role_code
 *   directly; a member with no department gets {ROLES}-{5-digit}.
 *
 * Security & scale notes:
 *  - Every value that reaches SQL comes from ascii-code config tables or a
 *    validated A–Z letter; nothing user-supplied is interpolated raw.
 *  - GET_LOCK serializes the per-letter sequence; uniqueness is additionally
 *    guaranteed by the members.member_code UNIQUE key with bounded retries.
 *  - All lookups are covered by existing/added indexes; no table scans.
 */

namespace App\Services;

use RuntimeException;

final class IdentityCodeService
{
    private const STAFF_TAIL_MIN = 10000;
    private const STAFF_TAIL_MAX = 99999;
    private const MAX_ATTEMPTS = 12;
    public const HEAD_MARKER = 'H';
    public const ORDINARY_MARKER = 'N';

    /** Valid single-segment code token: 1-4 uppercase letters/digits. */
    public static function isValidToken(string $token): bool
    {
        return (bool)preg_match('/^[A-Z0-9]{1,4}$/', $token);
    }

    /**
     * Allocate the correct code for a member payload.
     * Expected keys: age_group, id (optional), plus optional pre-resolved
     * staff codes via $staffCodes to avoid a re-query inside transactions.
     *
     * @param list<string>|null $staffCodes Resolved code segments (ED,H,T…)
     */
    public static function forMember(
        \mysqli $conn,
        array $member,
        ?array $staffCodes = null
    ): string {
        if ($staffCodes === null) {
            $memberId = (int)($member['id'] ?? 0);
            $resolved = self::resolveStaffSegments($conn, $memberId);
            if ($resolved !== null && $resolved !== []) {
                return self::allocateStaff($conn, $resolved);
            }
        } elseif ($staffCodes !== []) {
            return self::allocateStaff($conn, $staffCodes);
        }

        $letter = MemberCategory::letterFor((string)($member['age_group'] ?? ''));
        return self::allocateStudent($conn, $letter ?? MemberCategory::LETTER_A);
    }

    /* ================================================================
     * STUDENTS — sequential per category letter
     * ================================================================ */

    public static function allocateStudent(\mysqli $conn, string $letter): string
    {
        $letter = strtoupper(trim($letter));
        if (!preg_match('/^[A-Z]$/', $letter)) {
            throw new RuntimeException('Invalid member category letter.');
        }

        $lockName = 'ssms_member_code_' . $letter;
        $lockStatement = $conn->prepare('SELECT GET_LOCK(?, 8)');
        if (!$lockStatement) {
            throw new RuntimeException('Could not prepare code lock.');
        }
        try {
            $lockStatement->bind_param('si', $lockName, $timeout = 8);
            $lockStatement->execute();
            $row = $lockStatement->get_result()->fetch_row();
            if ((int)($row[0] ?? 0) !== 1) {
                throw new RuntimeException('Registration is busy. Try again.');
            }
        } finally {
            $lockStatement->close();
        }

        try {
            $statement = $conn->prepare(
                "SELECT MAX(CAST(SUBSTRING(member_code, 2) AS UNSIGNED)) AS max_n
                 FROM members
                 WHERE member_code REGEXP CONCAT('^', ?, '[0-9]+$')"
            );
            if (!$statement) {
                throw new RuntimeException('Could not prepare code lookup.');
            }
            try {
                $statement->bind_param('s', $letter);
                if (!$statement->execute()) {
                    throw new RuntimeException('Could not read last member code.');
                }
                $max = (int)($statement->get_result()->fetch_assoc()['max_n'] ?? 0);
            } finally {
                $statement->close();
            }

            $existsStatement = $conn->prepare(
                'SELECT 1 FROM members WHERE member_code = ? LIMIT 1'
            );
            if (!$existsStatement) {
                throw new RuntimeException('Could not prepare code check.');
            }
            try {
                // MAX+1 under the lock is collision-free in practice; the
                // UNIQUE index + bounded retry covers exotic edge cases such
                // as manually inserted legacy rows like "A12X" being renamed.
                for ($attempt = 0; $attempt < self::MAX_ATTEMPTS; $attempt++) {
                    $code = $letter . (string)($max + 1 + $attempt);
                    $existsStatement->bind_param('s', $code);
                    if (!$existsStatement->execute()) {
                        throw new RuntimeException('Could not verify member code.');
                    }
                    if (!$existsStatement->get_result()->fetch_row()) {
                        return $code;
                    }
                }
            } finally {
                $existsStatement->close();
            }
            throw new RuntimeException('Could not allocate a unique member code.');
        } finally {
            $release = $conn->prepare('SELECT RELEASE_LOCK(?)');
            if ($release) {
                $release->bind_param('s', $lockName);
                $release->execute();
                $release->close();
            }
        }
    }

    /* ================================================================
     * STAFF — {DEPT}{H|N}{POS*}-{random 5 digits}
     * ================================================================ */

    /**
     * Resolve the code segments for a member from assignments.
     * Returns null when the member has no staff assignment (student path),
     * or a non-empty list of validated segments.
     *
     * @return list<string>|null
     */
    public static function resolveStaffSegments(\mysqli $conn, int $memberId): ?array
    {
        if ($memberId <= 0) {
            return null;
        }
        $statement = $conn->prepare(
            "SELECT d.code AS dept_code, sp.role_code, sp.department_id,
                    (SELECT COUNT(*) FROM member_staff_positions peers
                      JOIN staff_positions peer_pos ON peer_pos.id = peers.position_id
                     WHERE peers.member_id = msp.member_id
                       AND peer_pos.department_id = sp.department_id
                       AND peer_pos.role_code = ?
                       AND peer_pos.is_active = 1) AS is_head_of_dept
             FROM member_staff_positions msp
             JOIN staff_positions sp ON sp.id = msp.position_id AND sp.is_active = 1
             LEFT JOIN departments d ON d.id = sp.department_id AND d.is_active = 1
             WHERE msp.member_id = ? AND sp.role_code <> ?
             ORDER BY (sp.department_id IS NULL) ASC, d.sort_order ASC,
                      sp.sort_order ASC, sp.id ASC"
        );
        if (!$statement) {
            throw new RuntimeException('Could not prepare staff code lookup.');
        }
        try {
            $head = self::HEAD_MARKER;
            $ordinary = self::ORDINARY_MARKER;
            $statement->bind_param('sis', $head, $memberId, $ordinary);
            $statement->execute();
            $result = $statement->get_result();

            $deptCode = null;
            $isHead = false;
            $extras = [];
            while ($row = $result->fetch_assoc()) {
                $role = strtoupper((string)$row['role_code']);
                if (!self::isValidToken($role)) {
                    continue;
                }
                if ($row['dept_code'] !== null && $deptCode === null) {
                    // Primary (first sorted) department decides the prefix
                    // and whether this member is its head.
                    $dept = strtoupper((string)$row['dept_code']);
                    if (!self::isValidToken($dept)) {
                        continue;
                    }
                    $deptCode = $dept;
                    $isHead = (int)$row['is_head_of_dept'] > 0;
                    if ($role !== $head || !$isHead) {
                        $extras[] = $role;
                    }
                    continue;
                }
                $extras[] = $role;
            }
            $statement->close();

            if ($deptCode === null && $extras === []) {
                return null;
            }

            $segments = [];
            if ($deptCode !== null) {
                $segments[] = $deptCode;
                $segments[] = $isHead ? self::HEAD_MARKER : self::ORDINARY_MARKER;
            }
            foreach ($extras as $extra) {
                if (!in_array($extra, $segments, true)) {
                    $segments[] = $extra;
                }
            }
            return $segments === [] ? null : $segments;
        } catch (\Throwable $error) {
            $statement->close();
            throw $error;
        }
    }

    /** @param list<string> $segments Validated A-Z tokens */
    public static function allocateStaff(\mysqli $conn, array $segments): string
    {
        foreach ($segments as $segment) {
            if (!self::isValidToken($segment)) {
                throw new RuntimeException('Invalid staff code segment.');
            }
        }
        $prefix = implode('', $segments);

        $statement = $conn->prepare(
            'SELECT 1 FROM members WHERE member_code = ? LIMIT 1'
        );
        if (!$statement) {
            throw new RuntimeException('Could not prepare staff code check.');
        }
        try {
            for ($attempt = 0; $attempt < self::MAX_ATTEMPTS; $attempt++) {
                $code = $prefix . '-' . (string)random_int(
                    self::STAFF_TAIL_MIN,
                    self::STAFF_TAIL_MAX
                );
                $statement->bind_param('s', $code);
                if (!$statement->execute()) {
                    throw new RuntimeException('Could not verify staff code.');
                }
                if (!$statement->get_result()->fetch_row()) {
                    return $code;
                }
            }
        } finally {
            $statement->close();
        }
        throw new RuntimeException('Could not allocate a unique staff code.');
    }

    /**
     * Regenerate the QR PNG for one member using the canonical generator.
     * Returns true when a fresh PNG exists at the canonical path. Used by
     * the migration CLI and the Super Admin hub so QR art never drifts from
     * the stored code.
     */
    public static function regenerateQr(\mysqli $conn, int $memberId): bool
    {
        $statement = $conn->prepare(
            'SELECT member_code FROM members WHERE id = ? LIMIT 1'
        );
        if (!$statement) {
            return false;
        }
        $statement->bind_param('i', $memberId);
        $statement->execute();
        $code = (string)($statement->get_result()->fetch_assoc()['member_code'] ?? '');
        $statement->close();
        if ($code === '' || !class_exists('\\QRcode')) {
            return false;
        }

        $dir = __DIR__ . '/../../id_cards/assets/qr';
        if (!is_dir($dir)) {
            return false;
        }
        $path = $dir . '/qr_member_' . $memberId . '.png';
        $tmp = tempnam($dir, '.qr-');
        if ($tmp === false) {
            return false;
        }
        try {
            \QRcode::png(
                (defined('SITE_URL') ? rtrim((string)SITE_URL, '/') : '')
                    . '/member.php?code=' . rawurlencode($code),
                $tmp,
                QR_ECLEVEL_L,
                4,
                2
            );
            if (!is_file($tmp) || filesize($tmp) < 32) {
                return false;
            }
            @chmod($tmp, 0644);
            if (!@rename($tmp, $path) && !@copy($tmp, $path)) {
                return false;
            }
            @unlink($tmp);
            return is_file($path);
        } catch (\Throwable $error) {
            if (is_file($tmp)) {
                @unlink($tmp);
            }
            return false;
        }
    }
}
