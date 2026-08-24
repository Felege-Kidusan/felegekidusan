<?php
/**
 * Transactional strong-identity duplicate checks for member registration.
 */
namespace App\Services;

final class DuplicateMemberException extends \DomainException
{
    /** @var array{id:int,member_code:string,status:string,name:string} */
    public array $member;

    /** @param array{id:int,member_code:string,status:string,name:string} $member */
    public function __construct(array $member)
    {
        parent::__construct('A strongly matching member already exists.');
        $this->member = $member;
    }
}

final class DuplicateRegistrationBusyException extends \RuntimeException
{
}

final class MemberDuplicateService
{
    /**
     * Return a strong match only when names are paired with a second identity
     * signal (complete Ethiopian DOB or normalized phone suffix). The locking
     * read serializes same-name registration races on the indexed name range.
     *
     * @param array<string,mixed> $identity
     * @return array{id:int,member_code:string,status:string,name:string}|null
     */
    public static function findStrongMatch(
        \mysqli $conn,
        array $identity,
        int $excludeId = 0,
        bool $lock = true
    ): ?array {
        $student = self::normalizeName($identity['student_name'] ?? '');
        $father = self::normalizeName($identity['father_name'] ?? '');
        $grandfather = self::normalizeName($identity['grandfather_name'] ?? '');
        if ($student === '' || $father === '') {
            return null;
        }

        $where = ['student_name = ?', 'father_name = ?', 'id <> ?'];
        $params = [$student, $father, max(0, $excludeId)];
        $types = 'ssi';
        if ($grandfather !== '') {
            $where[] = 'grandfather_name = ?';
            $params[] = $grandfather;
            $types .= 's';
        }

        $signals = [];
        $day = (int)($identity['dob_ec_day'] ?? 0);
        $month = (int)($identity['dob_ec_month'] ?? 0);
        $year = (int)($identity['dob_ec_year'] ?? 0);
        if ($day > 0 && $month > 0 && $year > 0) {
            $signals[] = '(dob_ec_day = ? AND dob_ec_month = ? AND dob_ec_year = ?)';
            array_push($params, $day, $month, $year);
            $types .= 'iii';
        }

        $phone = self::normalizePhoneSuffix($identity['phone_number'] ?? '');
        if ($phone !== '') {
            $normalize = static function (string $column): string {
                return "RIGHT(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(COALESCE({$column}, ''), '+', ''), ' ', ''), '-', ''), '(', ''), ')', ''), '.', ''), 9)";
            };
            $signals[] = '(' . implode(' OR ', array_map(
                static fn(string $column): string => $normalize($column) . ' = ?',
                [
                    'phone_number', 'phone_primary', 'alt_phone_number',
                    'phone_guardian', 'guardian_phone1', 'guardian_phone2',
                ]
            )) . ')';
            array_push($params, $phone, $phone, $phone, $phone, $phone, $phone);
            $types .= 'ssssss';
        }

        if ($signals === []) {
            return null;
        }
        $where[] = '(' . implode(' OR ', $signals) . ')';
        $sql = "SELECT id, member_code, status, student_name, father_name, grandfather_name
                FROM members WHERE " . implode(' AND ', $where)
            . " ORDER BY (status = 'archived') ASC, id DESC LIMIT 1"
            . ($lock ? ' FOR UPDATE' : '');
        $statement = $conn->prepare($sql);
        if (!$statement) {
            throw new \RuntimeException('Could not check member identity.');
        }
        $statement->bind_param($types, ...$params);
        $statement->execute();
        $row = $statement->get_result()->fetch_assoc();
        $statement->close();
        if (!$row) {
            return null;
        }
        return [
            'id' => (int)$row['id'],
            'member_code' => (string)($row['member_code'] ?? ''),
            'status' => (string)($row['status'] ?? ''),
            'name' => trim((string)$row['student_name'] . ' ' . (string)$row['father_name'] . ' '
                . (string)($row['grandfather_name'] ?? '')),
        ];
    }

    /**
     * Acquire a short-lived database advisory lock for a strong identity. This
     * closes the no-existing-row race even when the connection uses READ
     * COMMITTED (where an InnoDB gap lock is not guaranteed).
     *
     * @param array<string,mixed> $identity
     * @return string|null Lock name, null when the identity is not strong enough
     */
    public static function acquireStrongIdentityLock(\mysqli $conn, array $identity, int $timeout = 5): ?string
    {
        $student = self::normalizeName($identity['student_name'] ?? '');
        $father = self::normalizeName($identity['father_name'] ?? '');
        $grandfather = self::normalizeName($identity['grandfather_name'] ?? '');
        if ($student === '' || $father === '') {
            return null;
        }
        $day = (int)($identity['dob_ec_day'] ?? 0);
        $month = (int)($identity['dob_ec_month'] ?? 0);
        $year = (int)($identity['dob_ec_year'] ?? 0);
        $phone = self::normalizePhoneSuffix($identity['phone_number'] ?? '');
        $signals = [];
        if ($day > 0 && $month > 0 && $year > 0) {
            $signals[] = "dob:{$year}-{$month}-{$day}";
        }
        if ($phone !== '') {
            $signals[] = 'phone:' . $phone;
        }
        if ($signals === []) {
            return null;
        }
        $fold = static function (string $value): string {
            return function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
        };
        // Lock the name range, not a specific signal: one request may provide
        // DOB while another provides only phone yet both can identify the same
        // person and therefore must not race.
        $fingerprint = implode('|', [
            $fold($student),
            $fold($father),
            $fold($grandfather),
        ]);
        $lockName = 'ssms_member_duplicate_' . substr(hash('sha256', $fingerprint), 0, 40);
        $statement = $conn->prepare('SELECT GET_LOCK(?, ?)');
        if (!$statement) {
            throw new \RuntimeException('Could not prepare member identity lock.');
        }
        $timeout = max(0, min(10, $timeout));
        $statement->bind_param('si', $lockName, $timeout);
        $statement->execute();
        $row = $statement->get_result()->fetch_row();
        $statement->close();
        if ((int)($row[0] ?? 0) !== 1) {
            throw new DuplicateRegistrationBusyException('Another matching registration is currently processing.');
        }
        return $lockName;
    }

    public static function releaseIdentityLock(\mysqli $conn, ?string $lockName): void
    {
        if ($lockName === null || $lockName === '') {
            return;
        }
        try {
            $statement = $conn->prepare('SELECT RELEASE_LOCK(?)');
            if (!$statement) {
                return;
            }
            $statement->bind_param('s', $lockName);
            $statement->execute();
            $statement->close();
        } catch (\Throwable $error) {
            error_log('Could not release member identity lock: ' . $error->getMessage());
        }
    }

    /**
     * Bounded advisory candidates for the registration UI. Candidate retrieval
     * always starts with the indexed name pair; phone values only increase a
     * candidate's score and can never trigger a table-wide suffix scan.
     *
     * @param array<string,mixed> $identity
     * @return array<int,array<string,mixed>>
     */
    public static function findAdvisoryMatches(\mysqli $conn, array $identity, int $limit = 5): array
    {
        $student = self::normalizeName($identity['student_name'] ?? '');
        $father = self::normalizeName($identity['father_name'] ?? '');
        $grandfather = self::normalizeName($identity['grandfather_name'] ?? '');
        if ($student === '' || $father === '') {
            return [];
        }
        $limit = max(1, min(20, $limit));
        $sql = "SELECT id, member_code, student_name, father_name, grandfather_name,
                       baptismal_name, gender, age, current_section, phone_number,
                       phone_primary, alt_phone_number, phone_guardian,
                       guardian_phone1, guardian_phone2, guardian_name, status,
                       student_photo_path, registered_at, created_at
                FROM members
                WHERE student_name = ? AND father_name = ?
                ORDER BY status ASC, id DESC LIMIT {$limit}";
        $statement = $conn->prepare($sql);
        if (!$statement) {
            throw new \RuntimeException('Could not check duplicate candidates.');
        }
        $statement->bind_param('ss', $student, $father);
        $statement->execute();
        $result = $statement->get_result();
        $phone = self::normalizePhoneSuffix($identity['phone_number'] ?? '');
        $matches = [];
        while ($row = $result->fetch_assoc()) {
            $score = 80;
            $reasons = ['Student name matches', 'Father name matches'];
            if ($grandfather !== '' && self::normalizeName($row['grandfather_name'] ?? '') === $grandfather) {
                $score += 20;
                $reasons[] = 'Grandfather name matches';
            }
            if ($phone !== '' && in_array($phone, [
                self::normalizePhoneSuffix($row['phone_number'] ?? ''),
                self::normalizePhoneSuffix($row['phone_primary'] ?? ''),
                self::normalizePhoneSuffix($row['alt_phone_number'] ?? ''),
                self::normalizePhoneSuffix($row['phone_guardian'] ?? ''),
                self::normalizePhoneSuffix($row['guardian_phone1'] ?? ''),
                self::normalizePhoneSuffix($row['guardian_phone2'] ?? ''),
            ], true)) {
                $score += 30;
                $reasons[] = 'Phone number matches';
            }
            unset(
                $row['phone_primary'],
                $row['alt_phone_number'],
                $row['phone_guardian'],
                $row['guardian_phone1'],
                $row['guardian_phone2']
            );
            $row['match_score'] = $score;
            $row['match_reasons'] = $reasons;
            $row['is_archived'] = (string)($row['status'] ?? '') === 'archived';
            $matches[] = $row;
        }
        $statement->close();
        usort($matches, static fn(array $a, array $b): int => (int)$b['match_score'] <=> (int)$a['match_score']);
        return $matches;
    }

    public static function normalizeName($value): string
    {
        if (!is_scalar($value)) {
            return '';
        }
        return trim((string)preg_replace('/\s+/u', ' ', (string)$value));
    }

    public static function normalizePhoneSuffix($value): string
    {
        if (!is_scalar($value)) {
            return '';
        }
        $digits = preg_replace('/\D+/', '', (string)$value);
        return is_string($digits) && strlen($digits) >= 9 ? substr($digits, -9) : '';
    }
}
