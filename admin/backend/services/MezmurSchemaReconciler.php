<?php
/**
 * Mezmur schema reconciliation (schema-drift killer).
 *
 * WHY: production repeatedly broke because deployed code expected a
 * newer schema than the database had:
 *   - legacy tables created before the repo existed are never upgraded
 *     by CREATE TABLE IF NOT EXISTS,
 *   - code updates arrive (cron git reset) while SQL migrations lag.
 *
 * This service knows the exact column contract every mezmur query
 * relies on. report() lists the drift; apply() closes it with guarded,
 * idempotent ALTER/CREATE statements (safe to run any number of times).
 * Exposed via action=schema (GET, report) and action=migrate
 * (POST + CSRF, apply) in admin/api_mezmur.php, and via ?diag=2.
 */

namespace App\Services;

final class MezmurSchemaReconciler
{
    /** table => column => column DDL (the contract the queries rely on) */
    public const COLUMNS = [
        'mezmur_hymns' => [
            'title_am'   => "VARCHAR(255) DEFAULT NULL",
            'category'   => "VARCHAR(50) NOT NULL DEFAULT 'general'",
            'reference'  => "VARCHAR(255) DEFAULT NULL",
            'lyrics'     => "LONGTEXT DEFAULT NULL",
            'status'     => "ENUM('active','archived') NOT NULL DEFAULT 'active'",
            'created_by' => "INT UNSIGNED DEFAULT NULL",
            'updated_by' => "INT UNSIGNED DEFAULT NULL",
            'created_at' => "TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP",
            'updated_at' => "TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP",
        ],
        'mezmur_days' => [
            'attendance_date' => "DATE DEFAULT NULL",
            'program_type'    => "VARCHAR(50) NOT NULL DEFAULT 'rehearsal'",
            'title'           => "VARCHAR(255) DEFAULT NULL",
            'notes'           => "VARCHAR(500) DEFAULT NULL",
            'created_by'      => "INT UNSIGNED DEFAULT NULL",
            'created_at'      => "TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP",
        ],
        'mezmur_attendance' => [
            'session_id'      => "BIGINT UNSIGNED DEFAULT NULL",
            'attendance_date' => "DATE DEFAULT NULL",
            'member_id'       => "INT UNSIGNED NOT NULL",
            'status'          => "ENUM('present','late','absent','excused') NOT NULL DEFAULT 'present'",
            'notes'           => "VARCHAR(500) DEFAULT NULL",
            'marked_by'       => "INT UNSIGNED DEFAULT NULL",
            'marked_at'       => "TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP",
        ],
        'mezmur_attendance_audit' => [
            'session_id' => "BIGINT UNSIGNED DEFAULT NULL",
            'actor_id'   => "INT UNSIGNED DEFAULT NULL",
            'action'     => "VARCHAR(40) NOT NULL",
            'details'    => "VARCHAR(500) DEFAULT NULL",
            'created_at' => "TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP",
        ],
        'mezmur_submissions' => [
            'attendance_date' => "DATE NOT NULL",
            'section'         => "VARCHAR(64) NOT NULL",
            'taker_id'        => "INT UNSIGNED DEFAULT NULL",
            'status'          => "VARCHAR(20) NOT NULL DEFAULT 'draft'",
            'member_count'    => "INT NOT NULL DEFAULT 0",
            'present_count'   => "INT NOT NULL DEFAULT 0",
            'late_count'      => "INT NOT NULL DEFAULT 0",
            'absent_count'    => "INT NOT NULL DEFAULT 0",
            'excused_count'   => "INT NOT NULL DEFAULT 0",
            'client_op_id'    => "VARCHAR(64) DEFAULT NULL",
            'submitted_at'    => "DATETIME DEFAULT NULL",
            'reviewed_by'     => "INT UNSIGNED DEFAULT NULL",
            'reviewed_at'     => "DATETIME DEFAULT NULL",
            'review_notes'    => "VARCHAR(500) DEFAULT NULL",
        ],
    ];

    /** Minimal CREATE for tables that do not exist at all. */
    private const CREATE = [
        'mezmur_hymns' => "CREATE TABLE `mezmur_hymns` (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `title` VARCHAR(255) NOT NULL,
            PRIMARY KEY (`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        'mezmur_days' => "CREATE TABLE `mezmur_days` (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            PRIMARY KEY (`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        'mezmur_attendance' => "CREATE TABLE `mezmur_attendance` (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            PRIMARY KEY (`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        'mezmur_attendance_audit' => "CREATE TABLE `mezmur_attendance_audit` (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            PRIMARY KEY (`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        'mezmur_submissions' => "CREATE TABLE `mezmur_submissions` (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            PRIMARY KEY (`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    ];

    /**
     * Describe the drift: missing tables and missing columns.
     * @return array{missing_tables:array<string,bool>,missing_columns:array<string,list<string>>}
     */
    public static function report(\mysqli $conn): array
    {
        $missingTables = [];
        $missingColumns = [];
        foreach (self::COLUMNS as $table => $columns) {
            try {
                $r = $conn->query("SELECT 1 FROM `$table` LIMIT 0");
                if ($r === false) { $missingTables[$table] = true; continue; }
                $r->close();
            } catch (\Throwable $e) {
                $missingTables[$table] = true;
                continue;
            }
            try {
                $r = $conn->query("SHOW COLUMNS FROM `$table`");
                $have = [];
                if ($r) {
                    while ($row = $r->fetch_assoc()) { $have[strtolower((string)$row['Field'])] = true; }
                    $r->close();
                }
            } catch (\Throwable $e) {
                $missingTables[$table] = true;
                continue;
            }
            $missing = [];
            foreach ($columns as $col => $_ddl) {
                if (!isset($have[strtolower($col)])) { $missing[] = $col; }
            }
            if ($missing) { $missingColumns[$table] = $missing; }
        }
        return ['missing_tables' => $missingTables, 'missing_columns' => $missingColumns];
    }

    /**
     * Close the drift. Every statement is guarded; failures are
     * collected, never thrown. Idempotent by construction.
     * @return array{applied:list<string>,failed:array<string,string>}
     */
    public static function apply(\mysqli $conn): array
    {
        $applied = [];
        $failed = [];
        foreach (self::CREATE as $table => $ddl) {
            try {
                $exists = $conn->query("SELECT 1 FROM `$table` LIMIT 0");
                if ($exists !== false) { $exists->close(); continue; }
            } catch (\Throwable $e) {
                // table missing -> create below
            }
            try {
                if ($conn->query($ddl) === false) {
                    $failed["create:$table"] = (string)$conn->error;
                } else {
                    $applied[] = "created $table";
                }
            } catch (\Throwable $e) {
                $failed["create:$table"] = $e->getMessage();
            }
        }
        foreach (self::COLUMNS as $table => $columns) {
            foreach ($columns as $col => $ddl) {
                try {
                    $r = $conn->query("SHOW COLUMNS FROM `$table` LIKE '" . $conn->real_escape_string($col) . "'");
                    if ($r && $r->fetch_assoc()) { $r->close(); continue; }
                    if ($r) { $r->close(); }
                } catch (\Throwable $e) {
                    continue; // table absent or unreadable -> nothing to do
                }
                $sql = "ALTER TABLE `$table` ADD COLUMN `$col` $ddl";
                try {
                    if ($conn->query($sql) === false) {
                        $failed["alter:$table.$col"] = (string)$conn->error;
                    } else {
                        $applied[] = "added $table.$col";
                    }
                } catch (\Throwable $e) {
                    $failed["alter:$table.$col"] = $e->getMessage();
                }
            }
        }
        // 024's excused-status guarantee: extend the enum if missing
        try {
            $r = $conn->query("SHOW COLUMNS FROM mezmur_attendance LIKE 'status'");
            $col = $r ? $r->fetch_assoc() : null;
            if ($r) { $r->close(); }
            if ($col && strpos((string)($col['Type'] ?? ''), 'excused') === false) {
                $sql = "ALTER TABLE mezmur_attendance MODIFY COLUMN status ENUM('present','late','absent','excused') NOT NULL DEFAULT 'present'";
                if ($conn->query($sql) === false) {
                    $failed['enum:status'] = (string)$conn->error;
                } else {
                    $applied[] = 'mezmur_attendance.status now includes excused';
                }
            }
        } catch (\Throwable $e) {
            // table absent -> nothing to do
        }
        // 024's nullable-session_id guarantee (guarded, same intent)
        try {
            $r = $conn->query("SHOW COLUMNS FROM mezmur_attendance LIKE 'session_id'");
            $col = $r ? $r->fetch_assoc() : null;
            if ($r) { $r->close(); }
            if ($col && ($col['Null'] ?? '') !== 'YES') {
                if ($conn->query("ALTER TABLE mezmur_attendance MODIFY COLUMN session_id BIGINT UNSIGNED DEFAULT NULL") === false) {
                    $failed['nullable:session_id'] = (string)$conn->error;
                } else {
                    $applied[] = 'mezmur_attendance.session_id now nullable';
                }
            }
        } catch (\Throwable $e) {
            // table absent -> nothing to do
        }
        return ['applied' => $applied, 'failed' => $failed];
    }
}
