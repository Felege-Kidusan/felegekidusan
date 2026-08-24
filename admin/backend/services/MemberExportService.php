<?php
/**
 * Bounded editable workbooks and constant-memory member CSV exports.
 */

namespace App\Services;

use PDO;
use PDOStatement;
use RuntimeException;

final class MemberExportService
{
    public const MAX_EDITABLE_ROWS = 2000;

    public static function count(PDO $pdo, string $tier): int
    {
        if ($tier === 'all') {
            $statement = $pdo->query('SELECT COUNT(*) FROM `members`');
            return (int)$statement->fetchColumn();
        }
        $statement = $pdo->prepare('SELECT COUNT(*) FROM `members` WHERE `membership_tier` = ?');
        $statement->execute([$tier]);
        return (int)$statement->fetchColumn();
    }

    public static function query(PDO $pdo, string $tier, int $yearId, ?int $limit = null): PDOStatement
    {
        $limitSql = $limit === null ? '' : ' LIMIT ' . max(1, $limit);
        $tierWhere = $tier === 'all' ? '' : ' WHERE m.membership_tier = ?';
        if ($yearId > 0) {
            $statement = $pdo->prepare(
                "SELECT m.*, c.class_code AS class_code, c.class_name AS class_name
                 FROM members m
                 LEFT JOIN class_enrollments ce
                        ON ce.id = (
                            SELECT MAX(ce_latest.id)
                            FROM class_enrollments ce_latest
                            WHERE ce_latest.member_id = m.id
                              AND ce_latest.status = 'active'
                              AND ce_latest.academic_year_id = ?
                        )
                 LEFT JOIN classes c ON c.id = ce.class_id
                 {$tierWhere}
                 ORDER BY m.id DESC{$limitSql}"
            );
            $params = [$yearId];
            if ($tier !== 'all') {
                $params[] = $tier;
            }
            $statement->execute($params);
            return $statement;
        }

        $statement = $pdo->prepare(
            "SELECT m.* FROM members m
             {$tierWhere}
             ORDER BY m.id DESC{$limitSql}"
        );
        $statement->execute($tier === 'all' ? [] : [$tier]);
        return $statement;
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public static function collectEditable(
        PDO $pdo,
        string $tier,
        int $yearId,
        callable $dateFormatter
    ): array {
        $statement = self::query($pdo, $tier, $yearId, self::MAX_EDITABLE_ROWS);
        $rows = [];
        while ($row = $statement->fetch(PDO::FETCH_ASSOC)) {
            $rows[] = self::normalizeRow($row, $dateFormatter);
        }
        $statement->closeCursor();
        return $rows;
    }

    /**
     * Stream a UTF-8 CSV without collecting member rows in PHP memory.
     *
     * @param string[] $columns
     * @param array<string,string> $headerLabels
     */
    public static function streamCsv(
        PDO $pdo,
        string $tier,
        int $yearId,
        array $columns,
        array $headerLabels,
        string $filename,
        callable $dateFormatter
    ): void {
        if (function_exists('set_time_limit')) {
            @set_time_limit(0);
        }
        if (!defined('PDO::MYSQL_ATTR_USE_BUFFERED_QUERY')) {
            throw new RuntimeException('Unbuffered database exports are unavailable.');
        }
        if (!$pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, false)) {
            throw new RuntimeException('Could not enable unbuffered database export.');
        }
        $statement = self::query($pdo, $tier, $yearId, null);

        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . self::safeFilename($filename) . '"');
        header('Cache-Control: no-store, no-cache, must-revalidate, private');
        header('Pragma: no-cache');
        header('Expires: 0');
        header('X-Content-Type-Options: nosniff');
        header('Content-Security-Policy: default-src \'none\'; sandbox');
        header('Referrer-Policy: no-referrer');

        $output = fopen('php://output', 'wb');
        if ($output === false) {
            throw new RuntimeException('Could not open export stream.');
        }

        // UTF-8 BOM makes Amharic text open correctly in desktop Excel.
        fwrite($output, "\xEF\xBB\xBF");
        $headers = [];
        foreach ($columns as $column) {
            $headers[] = $headerLabels[$column] ?? $column;
        }
        self::writeCsvRow($output, $headers);

        $written = 0;
        while ($row = $statement->fetch(PDO::FETCH_ASSOC)) {
            $row = self::normalizeRow($row, $dateFormatter);
            $values = [];
            foreach ($columns as $column) {
                $values[] = self::spreadsheetSafeValue($row[$column] ?? '');
            }
            self::writeCsvRow($output, $values);
            $written++;
            if (($written % 500) === 0) {
                fflush($output);
                if (connection_aborted()) {
                    break;
                }
            }
        }

        $statement->closeCursor();
        fclose($output);
        exit;
    }

    /** @return array<string,mixed> */
    private static function normalizeRow(array $row, callable $dateFormatter): array
    {
        foreach (['date_of_birth', 'registered_at', 'waiting_since', 'joined_date'] as $column) {
            if (!empty($row[$column])) {
                $row[$column] = $dateFormatter((string)$row[$column]);
            }
        }
        if (!empty($row['class_name'])) {
            $row['class'] = $row['class_name'];
        } elseif (!empty($row['class_code'])) {
            $row['class'] = $row['class_code'];
        }
        // PhpSpreadsheet also interprets leading formula characters. Protect
        // both editable workbooks and CSVs at the shared row boundary.
        foreach ($row as $column => $value) {
            if (is_string($value)) {
                $row[$column] = self::spreadsheetSafeValue($value);
            }
        }
        return $row;
    }

    private static function spreadsheetSafeValue($value): string
    {
        $value = (string)$value;
        // Prevent spreadsheet formula execution when staff open exported data.
        if ($value !== '' && preg_match('/^[=+\-@\t\r]/u', $value)) {
            return "'" . $value;
        }
        return $value;
    }

    /** @param resource $output */
    private static function writeCsvRow($output, array $values): void
    {
        if (fputcsv($output, $values, ',', '"', '') === false) {
            throw new RuntimeException('Could not write export row.');
        }
    }

    private static function safeFilename(string $filename): string
    {
        $filename = preg_replace('/[^A-Za-z0-9._-]/', '_', $filename);
        return $filename !== '' ? $filename : 'members.csv';
    }
}
