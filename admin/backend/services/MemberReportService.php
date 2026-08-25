<?php
/**
 * Bounded member-report query policy.
 *
 * This service owns filter validation, indexed search construction, summary
 * aggregation, and the unbuffered row cursor. HTTP headers and document markup
 * remain in MemberReportRenderer so report UI changes do not affect query rules.
 */
namespace App\Services;

use InvalidArgumentException;
use PDO;
use PDOStatement;
use RuntimeException;

final class MemberReportService
{
    public const MAX_ROWS = 5000;

    private const ROW_COLUMNS = [
        'member_code',
        'student_name',
        'father_name',
        'grandfather_name',
        'baptismal_name',
        'gender',
        'age_group',
        'current_section',
        'phone_number',
        'alt_phone_number',
        'guardian_name',
        'guardian_phone1',
        'guardian_phone2',
        'city',
        'sub_city',
        'woreda',
        'work_profession',
        'education_level',
        'registration_type',
        'member_type',
        'status',
        'created_at',
    ];

    // Printable reports deliberately omit guardian and alternate-contact fields.
    private const PRINT_COLUMNS = [
        'member_code',
        'student_name',
        'father_name',
        'grandfather_name',
        'gender',
        'age_group',
        'phone_number',
        'city',
        'sub_city',
        'work_profession',
        'education_level',
        'registration_type',
        'status',
    ];

    private PDO $connection;
    private string $whereSql;
    /** @var array<int,string|int> */
    private array $params;
    private string $presetTitle;

    /** @param array<string,mixed> $input */
    public function __construct(PDO $connection, array $input)
    {
        $this->connection = $connection;
        [$this->whereSql, $this->params, $this->presetTitle] = $this->compile($input);
    }

    public function presetTitle(): string
    {
        return $this->presetTitle;
    }

    /**
     * @return array{total:int,male:int,female:int,active:int,warning:int}
     */
    public function summary(): array
    {
        $statement = $this->connection->prepare(
            "SELECT COUNT(*) AS total,
                    COALESCE(SUM(CASE WHEN gender = 'male' THEN 1 ELSE 0 END), 0) AS male,
                    COALESCE(SUM(CASE WHEN gender = 'female' THEN 1 ELSE 0 END), 0) AS female,
                    COALESCE(SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END), 0) AS active,
                    COALESCE(SUM(CASE WHEN status = 'warning' THEN 1 ELSE 0 END), 0) AS warning
             FROM members WHERE {$this->whereSql}"
        );
        $this->execute($statement);
        $row = $statement->fetch(PDO::FETCH_ASSOC) ?: [];
        $statement->closeCursor();
        return [
            'total' => (int)($row['total'] ?? 0),
            'male' => (int)($row['male'] ?? 0),
            'female' => (int)($row['female'] ?? 0),
            'active' => (int)($row['active'] ?? 0),
            'warning' => (int)($row['warning'] ?? 0),
        ];
    }

    /**
     * Open the row query before response headers are sent. MySQL uses an
     * unbuffered cursor, keeping PHP memory independent of report row count.
     */
    public function openRows(string $format = 'pdf'): PDOStatement
    {
        if (!in_array($format, ['pdf', 'docx', 'csv'], true)) {
            throw new InvalidArgumentException('Invalid report row projection.');
        }
        if ($this->driver() === 'mysql') {
            if (!defined('PDO::MYSQL_ATTR_USE_BUFFERED_QUERY')) {
                throw new RuntimeException('Unbuffered report queries are unavailable.');
            }
            if (!$this->connection->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, false)) {
                throw new RuntimeException('Could not enable the report row stream.');
            }
        }

        $projection = $format === 'csv' ? self::ROW_COLUMNS : self::PRINT_COLUMNS;
        $columns = implode(', ', array_map(static function (string $column): string {
            return '`' . $column . '`';
        }, $projection));
        $statement = $this->connection->prepare(
            "SELECT {$columns}
             FROM `members`
             WHERE {$this->whereSql}
             ORDER BY `student_name` ASC, `father_name` ASC, `id` ASC
             LIMIT " . self::MAX_ROWS
        );
        $this->execute($statement);
        return $statement;
    }

    /** @return array<string,string>|null */
    public static function nextRow(PDOStatement $statement): ?array
    {
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return null;
        }
        $normalized = [];
        foreach (self::ROW_COLUMNS as $column) {
            $normalized[$column] = isset($row[$column]) ? (string)$row[$column] : '';
        }
        return $normalized;
    }

    /**
     * @param array<string,mixed> $input
     * @return array{0:string,1:array<int,string|int>,2:string}
     */
    private function compile(array $input): array
    {
        $filter = self::scalar($input, 'filter', 20, 'all');
        $presets = [
            'all' => null,
            'active' => ["`status` = ?", ['active'], 'Active Members Report'],
            'waiting' => [
                "`registration_type` = ? AND `status` <> ?",
                ['waiting', 'archived'],
                'Waiting Members Report',
            ],
            'no_id' => [
                "(`id_card_status` IS NULL OR `id_card_status` <> ?) AND `status` <> ?",
                ['generated', 'archived'],
                'Members Without ID Card',
            ],
            'male' => ["`gender` = ? AND `status` <> ?", ['male', 'archived'], 'Male Members Report'],
            'female' => ["`gender` = ? AND `status` <> ?", ['female', 'archived'], 'Female Members Report'],
        ];
        if (!array_key_exists($filter, $presets)) {
            throw new InvalidArgumentException('Invalid report filter.');
        }
        if ($filter !== 'all') {
            /** @var array{0:string,1:array<int,string>,2:string} $preset */
            $preset = $presets[$filter];
            return $preset;
        }

        $where = ['`status` <> ?'];
        $params = ['archived'];

        $this->enumFilter($input, 'gender', ['male', 'female'], '`gender`', $where, $params);
        $this->enumFilter(
            $input,
            'age_group',
            ['7_13', '14_17', '18_plus'],
            '`age_group`',
            $where,
            $params
        );

        $status = self::scalar($input, 'f_status', 30);
        if ($status !== '') {
            if (!in_array($status, ['active', 'warning', 'inactive', 'archived'], true)) {
                throw new InvalidArgumentException('Invalid member status filter.');
            }
            $where = array_values(array_filter(
                $where,
                static fn(string $clause): bool => $clause !== '`status` <> ?'
            ));
            // The default archived parameter belongs to the removed clause.
            array_shift($params);
            $where[] = '`status` = ?';
            $params[] = $status;
        }

        $this->enumFilter(
            $input,
            'member_type',
            ['regular', 'honorary'],
            '`member_type`',
            $where,
            $params
        );
        $this->enumFilter(
            $input,
            'registration_type',
            ['direct', 'transfer', 'waiting'],
            '`registration_type`',
            $where,
            $params
        );

        foreach ([
            'city' => '`city`',
            'sub_city' => '`sub_city`',
            'education_level' => '`education_level`',
        ] as $key => $column) {
            $value = self::scalar($input, $key, 120);
            if ($value !== '') {
                $where[] = $column . ' = ?';
                $params[] = $value;
            }
        }

        $this->yesNoFilter(
            $input,
            'has_id_card',
            "`id_card_status` = 'generated'",
            "(`id_card_status` IS NULL OR `id_card_status` <> 'generated')",
            $where
        );
        $this->yesNoFilter(
            $input,
            'has_phone',
            "(`phone_number` IS NOT NULL AND `phone_number` <> '')",
            "(`phone_number` IS NULL OR `phone_number` = '')",
            $where
        );

        $search = self::scalar($input, 'search', 120);
        if ($search !== '') {
            if ($this->driver() === 'mysql' && self::textLength($search) >= 3) {
                $where[] = 'MATCH (`student_name`, `father_name`, `grandfather_name`, '
                    . '`member_code`, `baptismal_name`, `phone_number`, `work_profession`, `city`) '
                    . 'AGAINST (? IN BOOLEAN MODE)';
                $params[] = self::booleanSearch($search);
            } else {
                $prefix = self::escapeLike($search) . '%';
                $where[] = '(`student_name` LIKE ? ESCAPE \'=\' '
                    . 'OR `father_name` LIKE ? ESCAPE \'=\' '
                    . 'OR `member_code` LIKE ? ESCAPE \'=\' '
                    . 'OR `phone_number` LIKE ? ESCAPE \'=\')';
                array_push($params, $prefix, $prefix, $prefix, $prefix);
            }
        }

        $dateFrom = self::date($input, 'date_from');
        $dateTo = self::date($input, 'date_to');
        if ($dateFrom !== '') {
            $where[] = '`created_at` >= ?';
            $params[] = $dateFrom . ' 00:00:00';
        }
        if ($dateTo !== '') {
            $where[] = '`created_at` <= ?';
            $params[] = $dateTo . ' 23:59:59';
        }
        if ($dateFrom !== '' && $dateTo !== '' && $dateFrom > $dateTo) {
            throw new InvalidArgumentException('The report date range is invalid.');
        }

        return [implode(' AND ', $where), $params, ''];
    }

    /**
     * @param array<string,mixed> $input
     * @param string[] $allowed
     * @param string[] $where
     * @param array<int,string|int> $params
     */
    private function enumFilter(
        array $input,
        string $key,
        array $allowed,
        string $column,
        array &$where,
        array &$params
    ): void {
        $value = self::scalar($input, $key, 40);
        if ($value === '') {
            return;
        }
        if (!in_array($value, $allowed, true)) {
            throw new InvalidArgumentException('Invalid ' . str_replace('_', ' ', $key) . ' filter.');
        }
        $where[] = $column . ' = ?';
        $params[] = $value;
    }

    /** @param array<string,mixed> $input @param string[] $where */
    private function yesNoFilter(
        array $input,
        string $key,
        string $yesClause,
        string $noClause,
        array &$where
    ): void {
        $value = self::scalar($input, $key, 3);
        if ($value === '') {
            return;
        }
        if (!in_array($value, ['yes', 'no'], true)) {
            throw new InvalidArgumentException('Invalid ' . str_replace('_', ' ', $key) . ' filter.');
        }
        $where[] = $value === 'yes' ? $yesClause : $noClause;
    }

    private function execute(PDOStatement $statement): void
    {
        foreach ($this->params as $index => $value) {
            $statement->bindValue(
                $index + 1,
                $value,
                is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR
            );
        }
        $statement->execute();
    }

    private function driver(): string
    {
        return strtolower((string)$this->connection->getAttribute(PDO::ATTR_DRIVER_NAME));
    }

    /** @param array<string,mixed> $input */
    private static function scalar(array $input, string $key, int $maxLength, string $default = ''): string
    {
        if (!array_key_exists($key, $input) || $input[$key] === null || $input[$key] === '') {
            return $default;
        }
        if (!is_string($input[$key]) && !is_int($input[$key])) {
            throw new InvalidArgumentException('Invalid report input.');
        }
        $value = trim((string)$input[$key]);
        if (preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $value)
            || self::textLength($value) > $maxLength) {
            throw new InvalidArgumentException('Invalid report input.');
        }
        return $value;
    }

    /** @param array<string,mixed> $input */
    private static function date(array $input, string $key): string
    {
        $value = self::scalar($input, $key, 10);
        if ($value === '') {
            return '';
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        $errors = \DateTimeImmutable::getLastErrors();
        if (!$date
            || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))
            || $date->format('Y-m-d') !== $value) {
            throw new InvalidArgumentException('Invalid report date.');
        }
        return $value;
    }

    private static function booleanSearch(string $value): string
    {
        $tokens = preg_split('/[^\p{L}\p{N}]+/u', $value, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $terms = [];
        foreach (array_slice($tokens, 0, 8) as $token) {
            $token = function_exists('mb_substr')
                ? mb_substr($token, 0, 40, 'UTF-8')
                : substr($token, 0, 40);
            if ($token !== '') {
                $terms[] = '+' . $token . '*';
            }
        }
        return $terms !== [] ? implode(' ', $terms) : '"' . $value . '"';
    }

    private static function escapeLike(string $value): string
    {
        return str_replace(['=', '%', '_'], ['==', '=%', '=_'], $value);
    }

    private static function textLength(string $value): int
    {
        return function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
    }
}
