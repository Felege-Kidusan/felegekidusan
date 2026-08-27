<?php
/**
 * Identity code allocation — FORMAT v2 (ANALYSIS/08).
 *
 * Every member code is `{PREFIX}-{5-digit tail}` where the tail is a
 * RANDOM UNIQUE number (10000-99999). Sequential per-category numbers
 * were retired by leadership decision; random tails never leak internal
 * database ids (the enumeration-safe practice used for public
 * identifiers at Google/Meta-scale products).
 *
 * Students:   {CategoryLetter}-{tail}                 → A-76392
 * Staff:      {FREE…}{DEPT}{H|N}{DEPT-POSITIONS}-{tail}
 *             DEDHT-98798  (Director + Education head + Teacher)
 *             DEDH-98798   (Director + Education head)
 *             DT-98798     (Director + Teacher, free positions)
 *             EDHT-83719   (Education head + Teacher)
 *
 * Prefix composition order (single source of truth — composePrefix()):
 *   1. FREE positions (department_id NULL) in the order the Super Admin
 *      defines (sort_order, id);
 *   2. the PRIMARY department segment: department code + H when the
 *      member holds that department's head position, N otherwise
 *      (N is rendered smaller on cards/verification page);
 *   3. remaining department position letters (primary dept's non-head
 *      roles first, then other departments, each in defined order).
 *
 * Ambiguity guard: a free position may never use a single letter that
 * would collide with the student format — 'A', 'B', 'C' (categories) and
 * 'N' (ordinary marker) are reserved for department-less positions.
 *
 * Security & scale:
 *  - every value reaching SQL comes from ascii config tables or a
 *    validated A–Z token; uniqueness enforced by the members.member_code
 *    UNIQUE key with bounded retries over an indexed probe (O(1));
 *  - no sequence tables and no table scans — the
 *    retired per-letter sequence machinery (sql/018) remains in the
 *    schema for rollback safety but is no longer read or written.
 */

namespace App\Services;

use RuntimeException;

require_once __DIR__ . '/MemberCategory.php';

final class IdentityCodeService
{
    public const TAIL_MIN = 10000;
    public const TAIL_MAX = 99999;
    private const MAX_ATTEMPTS = 16;

    public const HEAD_MARKER = 'H';
    public const ORDINARY_MARKER = 'N';

    /** Letters a FREE (department-less) position may never take. */
    public const RESERVED_FREE_CODES = ['N', 'A', 'B', 'C'];

    /* ================================================================
     * FORMAT CONTRACT (the only parser/serializer in the codebase)
     * ================================================================ */

    public const STUDENT_REGEX = '/^[ABC]-[1-9][0-9]{4}$/';
    public const STAFF_REGEX   = '/^(?:[A-Z]{1,4})+-[1-9][0-9]{4}$/';

    /** Valid single-segment code token: 1-4 uppercase letters. */
    public static function isValidToken(string $token): bool
    {
        return (bool)preg_match('/^[A-Z]{1,4}$/', $token);
    }

    /**
     * @return array{kind:'student',letter:string,tail:string}
     *       | array{kind:'staff',prefix:string,tail:string}
     *       | null
     */
    public static function parse(?string $code): ?array
    {
        $code = trim((string)$code);
        if ($code === '') {
            return null;
        }
        if (preg_match(self::STUDENT_REGEX, $code)) {
            [$letter, $tail] = explode('-', $code, 2);
            return ['kind' => 'student', 'letter' => $letter, 'tail' => $tail];
        }
        if (preg_match(self::STAFF_REGEX, $code)) {
            [$prefix, $tail] = explode('-', $code, 2);
            return ['kind' => 'staff', 'prefix' => $prefix, 'tail' => $tail];
        }
        return null;
    }

    public static function isStudentCode(?string $code): bool
    {
        $parsed = self::parse($code);
        return ($parsed['kind'] ?? '') === 'student';
    }

    public static function isStaffCode(?string $code): bool
    {
        $parsed = self::parse($code);
        return ($parsed['kind'] ?? '') === 'staff';
    }

    public static function isValidV2(?string $code): bool
    {
        return self::parse($code) !== null;
    }

    /**
     * Pure prefix composer — deterministic, unit-testable, and the ONLY
     * place the ordering rule lives.
     *
     * @param list<string> $freeLetters   Free-position letters, defined order
     * @param string|null  $deptCode      Primary department code
     * @param string|null  $marker        'H'|'N' for the primary department
     * @param list<string> $extraLetters  Dept position letters, defined order
     */
    public static function composePrefix(
        array $freeLetters,
        ?string $deptCode,
        ?string $marker,
        array $extraLetters
    ): string {
        $prefix = implode('', $freeLetters);
        if ($deptCode !== null && $deptCode !== '' && $marker !== null) {
            $prefix .= $deptCode . $marker;
        }
        foreach ($extraLetters as $letter) {
            $prefix .= $letter;
        }
        return $prefix;
    }

    /* ================================================================
     * ALLOCATION
     * ================================================================ */

    /** Student code: category letter + random unique 5-digit tail. */
    public static function allocateStudent(\mysqli $conn, string $letter): string
    {
        if (!in_array($letter, MemberCategory::letters(), true)) {
            throw new RuntimeException('Invalid category letter.');
        }
        return self::allocateWithPrefix($conn, $letter);
    }

    /** Staff code: composed prefix + random unique 5-digit tail. */
    public static function allocateStaff(\mysqli $conn, array $segments): string
    {
        $prefix = implode('', $segments);
        if (!preg_match('/^[A-Z]{1,16}$/', $prefix)) {
            throw new RuntimeException('Invalid staff code segments.');
        }
        return self::allocateWithPrefix($conn, $prefix);
    }

    private static function allocateWithPrefix(\mysqli $conn, string $prefix): string
    {
        $statement = $conn->prepare('SELECT 1 FROM members WHERE member_code = ? LIMIT 1');
        if (!$statement) {
            throw new RuntimeException('Could not prepare code uniqueness probe.');
        }
        try {
            for ($attempt = 0; $attempt < self::MAX_ATTEMPTS; $attempt++) {
                $code = $prefix . '-' . (string)random_int(self::TAIL_MIN, self::TAIL_MAX);
                $statement->bind_param('s', $code);
                if (!$statement->execute()) {
                    throw new RuntimeException('Could not verify code uniqueness.');
                }
                if (!$statement->get_result()->fetch_row()) {
                    return $code;
                }
            }
        } finally {
            $statement->close();
        }
        throw new RuntimeException('Could not allocate a unique identity code.');
    }

    /* ================================================================
     * RESOLUTION — member's active positions → prefix segments
     * ================================================================ */

    /**
     * Build the prefix segment list for a member from their active
     * position assignments (v2 order: free → dept segment → dept extras).
     *
     * @return list<string>|null Segments ready for allocateStaff()
     */
    public static function resolveStaffSegments(\mysqli $conn, int $memberId): ?array
    {
        if ($memberId <= 0) {
            return null;
        }
        $statement = $conn->prepare(
            "SELECT sp.role_code, sp.department_id, d.code AS dept_code, d.sort_order AS dept_sort
             FROM member_staff_positions msp
             JOIN staff_positions sp ON sp.id = msp.position_id AND sp.is_active = 1
             LEFT JOIN departments d ON d.id = sp.department_id AND d.is_active = 1
             WHERE msp.member_id = ?
             ORDER BY (sp.department_id IS NULL) DESC, d.sort_order ASC,
                      sp.sort_order ASC, sp.id ASC"
        );
        if (!$statement) {
            throw new RuntimeException('Could not prepare staff code lookup.');
        }
        $statement->bind_param('i', $memberId);
        $statement->execute();
        $result = $statement->get_result();

        $freeLetters = [];
        /** @var array<int,array{code:string,head:bool,extras:list<string>}> $depts */
        $depts = [];
        $deptOrder = [];
        while ($row = $result->fetch_assoc()) {
            $role = strtoupper((string)$row['role_code']);
            if (!self::isValidToken($role)) {
                continue;
            }
            if ($row['department_id'] === null || $row['dept_code'] === null) {
                if (!in_array($role, $freeLetters, true)) {
                    $freeLetters[] = $role;
                }
                continue;
            }
            $deptId = (int)$row['department_id'];
            $dept = strtoupper((string)$row['dept_code']);
            if (!self::isValidToken($dept)) {
                continue;
            }
            if (!isset($depts[$deptId])) {
                $depts[$deptId] = ['code' => $dept, 'head' => false, 'extras' => []];
                $deptOrder[] = $deptId;
            }
            if ($role === self::HEAD_MARKER) {
                $depts[$deptId]['head'] = true;
            } else {
                if (!in_array($role, $depts[$deptId]['extras'], true)) {
                    $depts[$deptId]['extras'][] = $role;
                }
            }
        }
        $statement->close();

        if ($freeLetters === [] && $deptOrder === []) {
            return null;
        }

        $extraLetters = [];
        $deptCode = null;
        $marker = null;
        foreach ($deptOrder as $i => $deptId) {
            $dept = $depts[$deptId];
            if ($i === 0) {
                $deptCode = $dept['code'];
                $marker = $dept['head'] ? self::HEAD_MARKER : self::ORDINARY_MARKER;
            }
            foreach ($dept['extras'] as $letter) {
                if (!in_array($letter, $extraLetters, true)) {
                    $extraLetters[] = $letter;
                }
            }
        }

        $prefix = self::composePrefix($freeLetters, $deptCode, $marker, $extraLetters);
        if ($prefix === '') {
            return null;
        }
        // Segments for allocateStaff: single combined prefix token.
        return [$prefix];
    }

    /* ================================================================
     * QR REGENERATION (unchanged canonical generator)
     * ================================================================ */

    /**
     * Regenerate the QR PNG for one member. Returns true when a fresh
     * PNG exists at the canonical path so QR art never drifts from the
     * stored code.
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
            @unlink($tmp);
            return false;
        }
    }
}
