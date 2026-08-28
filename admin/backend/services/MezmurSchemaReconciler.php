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
            // 025: offline-first sync — monotonic row version + delta index.
            'revision'   => "INT UNSIGNED NOT NULL DEFAULT 1",
        ],
        'mezmur_categories' => [
            'name'       => "VARCHAR(50) NOT NULL",
            'sort_order' => "INT NOT NULL DEFAULT 0",
            'is_active'  => "TINYINT(1) NOT NULL DEFAULT 1",
            'created_by' => "INT UNSIGNED DEFAULT NULL",
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

    /** FULLTEXT indexes the ranked search relies on. */
    public const INDEXES = [
        'mezmur_hymns' => [
            'ft_mezmur_hymns_titles' => ['title', 'title_am'],
            'ft_mezmur_hymns_search' => ['title', 'title_am', 'reference', 'lyrics'],
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
        'mezmur_categories' => "CREATE TABLE `mezmur_categories` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `name` VARCHAR(50) NOT NULL,
            `sort_order` INT NOT NULL DEFAULT 0,
            `is_active` TINYINT(1) NOT NULL DEFAULT 1,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uq_mezmur_categories_name` (`name`)
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
        $missingIndexes = [];
        foreach (self::INDEXES as $table => $indexes) {
            if (isset($missingTables[$table])) { continue; }
            foreach ($indexes as $name => $_cols) {
                try {
                    $r = $conn->query("SHOW INDEX FROM `$table` WHERE Key_name = '" . $conn->real_escape_string($name) . "'");
                    $has = $r ? (bool)$r->fetch_assoc() : false;
                    if ($r) { $r->close(); }
                    if (!$has) { $missingIndexes[$table][] = $name; }
                } catch (\Throwable $e) {
                    // unreadable -> treat as missing
                    $missingIndexes[$table][] = $name;
                }
            }
        }
        return [
            'missing_tables' => $missingTables,
            'missing_columns' => $missingColumns,
            'missing_indexes' => $missingIndexes,
        ];
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
        foreach (self::INDEXES as $table => $indexes) {
            foreach ($indexes as $name => $cols) {
                try {
                    $r = $conn->query("SHOW INDEX FROM `$table` WHERE Key_name = '" . $conn->real_escape_string($name) . "'");
                    $has = $r ? (bool)$r->fetch_assoc() : false;
                    if ($r) { $r->close(); }
                    if ($has) { continue; }
                } catch (\Throwable $e) {
                    continue;
                }
                $sql = "ALTER TABLE `$table` ADD FULLTEXT INDEX `$name` (`" . implode('`, `', $cols) . "`)";
                try {
                    if ($conn->query($sql) === false) {
                        $failed["index:$table.$name"] = (string)$conn->error;
                    } else {
                        $applied[] = "added index $table.$name";
                    }
                } catch (\Throwable $e) {
                    $failed["index:$table.$name"] = $e->getMessage();
                }
            }
        }
        // 025's delta-sync cursor index (BTREE, guarded) — keeps
        // change-token pulls index-only on legacy DBs at scale.
        try {
            $r = $conn->query("SHOW INDEX FROM mezmur_hymns WHERE Key_name = 'idx_mezmur_hymns_updated'");
            $has = $r ? (bool)$r->fetch_assoc() : false;
            if ($r) { $r->close(); }
            if (!$has) {
                $sql = "ALTER TABLE mezmur_hymns ADD KEY `idx_mezmur_hymns_updated` (`updated_at`, `id`)";
                if ($conn->query($sql) === false) {
                    $failed['index:idx_mezmur_hymns_updated'] = (string)$conn->error;
                } else {
                    $applied[] = 'added index idx_mezmur_hymns_updated';
                }
            }
        } catch (\Throwable $e) {
            // table absent -> nothing to do
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
        // 025's canonical categories: UNIQUE(name) + idempotent seed.
        // Legacy tables created before the unique key existed may hold
        // duplicate seed rows — dedupe first, then add the key, then
        // INSERT IGNORE the canonical three.
        try {
            $r = $conn->query("SHOW INDEX FROM mezmur_categories WHERE Key_name = 'uq_mezmur_categories_name'");
            $hasUnique = $r ? (bool)$r->fetch_assoc() : false;
            if ($r) { $r->close(); }
            if (!$hasUnique) {
                // Keep the lowest id per name; drop later duplicates.
                $conn->query(
                    "DELETE c1 FROM mezmur_categories c1
                     JOIN mezmur_categories c2
                       ON c2.name = c1.name AND c2.id < c1.id"
                );
                if ($conn->query("ALTER TABLE mezmur_categories ADD UNIQUE KEY `uq_mezmur_categories_name` (`name`)") === false) {
                    $failed['index:uq_mezmur_categories_name'] = (string)$conn->error;
                } else {
                    $applied[] = 'mezmur_categories.name now unique (duplicates removed)';
                }
            }
            $seed = [
                ['ህናት', 1],
                ['ማዕከዊያን', 2],
                ['ጣቶች', 3],
            ];
            $stmt = $conn->prepare("INSERT IGNORE INTO mezmur_categories (name, sort_order) VALUES (?, ?)");
            if ($stmt) {
                $added = 0;
                foreach ($seed as [$name, $order]) {
                    $stmt->bind_param('si', $name, $order);
                    if ($stmt->execute() && $stmt->affected_rows > 0) {
                        $added++;
                    }
                }
                $stmt->close();
                if ($added > 0) {
                    $applied[] = "seeded $added canonical mezmur categor(y/ies)";
                }
            }
        } catch (\Throwable $e) {
            // table absent or seed already present -> nothing to do
        }
        return ['applied' => $applied, 'failed' => $failed];
    }
}
