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
 * relies on — through migration 038 inclusive (audio media + synced
 * lyrics). report() lists the drift; apply() closes it with guarded,
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
            'category'   => "VARCHAR(50) NOT NULL DEFAULT 'general'",
            'lyrics'     => "LONGTEXT DEFAULT NULL",
            'status'     => "ENUM('active','archived') NOT NULL DEFAULT 'active'",
            'created_by' => "INT UNSIGNED DEFAULT NULL",
            'updated_by' => "INT UNSIGNED DEFAULT NULL",
            'created_at' => "TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP",
            'updated_at' => "TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP",
            // 025: offline-first sync — monotonic row version + delta index.
            'revision'   => "INT UNSIGNED NOT NULL DEFAULT 1",
            // 038: audio media plane. Without these the media service fails
            // closed and its error message points admins HERE, so the
            // reconciler must be able to close this drift on its own.
            'audio_key'         => "VARCHAR(255) DEFAULT NULL",
            'audio_duration_s'  => "INT UNSIGNED DEFAULT NULL",
            'audio_size'        => "INT UNSIGNED DEFAULT NULL",
            'audio_format'      => "VARCHAR(10) DEFAULT NULL",
            'audio_status'      => "ENUM('none','pending','ready','rejected') NOT NULL DEFAULT 'none'",
            'audio_uploaded_by' => "INT UNSIGNED DEFAULT NULL",
            'audio_updated_at'  => "DATETIME DEFAULT NULL",
            // 038: timed (LRC) lyrics — a SEPARATE field from `lyrics`.
            'lyrics_synced'     => "LONGTEXT DEFAULT NULL",
            'lyrics_synced_at'  => "DATETIME DEFAULT NULL",
            'lyrics_synced_by'  => "INT UNSIGNED DEFAULT NULL",
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

    /**
     * P25: FULLTEXT indexes are deliberately NO LONGER created — MariaDB
     * InnoDB FULLTEXT cannot tokenize Ge'ez script and one observed
     * CREATE FULLTEXT INDEX build returned 0 matches silently. Search
     * uses the mezmur_hymn_words inverted index instead (sql/032,
     * maintained by MezmurHymnService::reindexHymnWords on every save
     * and backfilled by apply()). Existing FT indexes are left in place
     * (harmless) but nothing reads them anymore.
     */
    public const INDEXES = [];

    /** Minimal CREATE for tables that do not exist at all. */
    private const CREATE = [
        'mezmur_hymns' => "CREATE TABLE `mezmur_hymns` (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `title` VARCHAR(255) NOT NULL,
            PRIMARY KEY (`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        'mezmur_hymn_words' => "CREATE TABLE `mezmur_hymn_words` (
            `word` VARBINARY(80) NOT NULL,
            `hymn_id` BIGINT UNSIGNED NOT NULL,
            PRIMARY KEY (`word`, `hymn_id`),
            KEY `idx_mhw_hymn` (`hymn_id`)
        ) ENGINE=InnoDB",
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
        // 038: play counters + per-user favorites. No code path reads or
        // writes these yet (the P0 player does not report plays); they are
        // created so the schema converges with the migration and a future
        // "Top hymns" / "Your Library" feature is a code-only change.
        'mezmur_play_stats' => "CREATE TABLE `mezmur_play_stats` (
            `hymn_id` BIGINT UNSIGNED NOT NULL,
            `day` DATE NOT NULL,
            `plays` INT UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY (`hymn_id`, `day`),
            CONSTRAINT `fk_mps_hymn` FOREIGN KEY (`hymn_id`)
                REFERENCES `mezmur_hymns` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        'mezmur_user_favorites' => "CREATE TABLE `mezmur_user_favorites` (
            `user_id` INT UNSIGNED NOT NULL,
            `hymn_id` BIGINT UNSIGNED NOT NULL,
            `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`user_id`, `hymn_id`),
            KEY `idx_muf_hymn` (`hymn_id`),
            CONSTRAINT `fk_muf_hymn` FOREIGN KEY (`hymn_id`)
                REFERENCES `mezmur_hymns` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
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
        // 038's audio_status index — keeps a "missing audio" curation view
        // (and any status filter) index-scanned instead of a table scan.
        try {
            $r = $conn->query("SHOW INDEX FROM mezmur_hymns WHERE Key_name = 'idx_mz38_audio_status'");
            $has = $r ? (bool)$r->fetch_assoc() : false;
            if ($r) { $r->close(); }
            if (!$has) {
                $sql = "ALTER TABLE mezmur_hymns ADD KEY `idx_mz38_audio_status` (`audio_status`)";
                if ($conn->query($sql) === false) {
                    $failed['index:idx_mz38_audio_status'] = (string)$conn->error;
                } else {
                    $applied[] = 'added index idx_mz38_audio_status';
                }
            }
        } catch (\Throwable $e) {
            // audio columns absent (freshly created table above) -> skip
        }
        // 031's storage-level title uniqueness (MZ-6): the SELECT-then-
        // INSERT dup check loses under concurrency; a real UNIQUE key
        // settles it. Skipped (and reported) when legacy duplicates exist.
        try {
            $r = $conn->query("SHOW INDEX FROM mezmur_hymns WHERE Key_name = 'uq_mezmur_hymns_title'");
            $has = $r ? (bool)$r->fetch_assoc() : false;
            if ($r) { $r->close(); }
            if (!$has) {
                $d = $conn->query(
                    "SELECT LOWER(title) AS t, COUNT(*) AS c FROM mezmur_hymns GROUP BY LOWER(title) HAVING c > 1 LIMIT 5"
                );
                $dupes = [];
                if ($d) {
                    while ($row = $d->fetch_assoc()) { $dupes[] = $row['t'] . '×' . $row['c']; }
                    $d->close();
                }
                if ($dupes) {
                    $failed['index:uq_mezmur_hymns_title'] =
                        'duplicate hymn titles must be merged first: ' . implode(', ', $dupes);
                } elseif ($conn->query("ALTER TABLE mezmur_hymns ADD UNIQUE KEY `uq_mezmur_hymns_title` (`title`)") === false) {
                    $failed['index:uq_mezmur_hymns_title'] = (string)$conn->error;
                } else {
                    $applied[] = 'added unique index uq_mezmur_hymns_title (title)';
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
        // P25: keep the word index warm — backfill every hymn that has no
        // word rows yet (bounded rounds, idempotent).
        try {
            $done = 0;
            $rounds = 0;
            $n = MezmurHymnService::backfillHymnWords($conn, 1000);
            while ($n > 0 && $rounds < 200) {
                $done += $n;
                $rounds++;
                $n = MezmurHymnService::backfillHymnWords($conn, 1000);
            }
            if ($done > 0) {
                $applied[] = "word index backfilled for $done hymn(s)";
            }
        } catch (\Throwable $e) {
            $failed['words:backfill'] = $e->getMessage();
        }

        return ['applied' => $applied, 'failed' => $failed];
    }
}
