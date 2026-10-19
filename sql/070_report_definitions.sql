-- ============================================================
-- 070_report_definitions.sql   (IDEMPOTENT / RE-RUNNABLE)
-- Reporting Centre V2-3: saved views (private / department / shared) and
-- pinned "standing reports". Shared-hosting safe: no JSON column type, no
-- generated columns, no CHECK constraints, InnoDB + utf8mb4 like the rest.
-- Apply in phpMyAdmin before deploying the matching code (the code degrades
-- gracefully — saved views simply stay unavailable — until the table exists).
-- ============================================================

CREATE TABLE IF NOT EXISTS `report_definitions` (
  `id`             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `dataset`        VARCHAR(40)  NOT NULL DEFAULT 'members',
  `name`           VARCHAR(120) NOT NULL,
  `description`    VARCHAR(500) DEFAULT NULL,
  `definition`     MEDIUMTEXT   NOT NULL COMMENT 'Normalised report definition (JSON text, <= 16 KB enforced in code)',
  `scope`          ENUM('private','department','shared') NOT NULL DEFAULT 'private',
  `visible_role`   VARCHAR(30)  DEFAULT NULL COMMENT 'Role that sees a department-scoped view (owner role at save time)',
  `is_pinned`      TINYINT(1)   NOT NULL DEFAULT 0 COMMENT 'Standing report: shown in the starter row (admins only)',
  `sort_order`     SMALLINT     NOT NULL DEFAULT 0,
  `owner_user_id`  INT UNSIGNED NOT NULL,
  `owner_role`     VARCHAR(30)  NOT NULL,
  `updated_by`     INT UNSIGNED DEFAULT NULL,
  `run_count`      INT UNSIGNED NOT NULL DEFAULT 0,
  `last_run_at`    DATETIME     DEFAULT NULL,
  `created_at`     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_rd_owner`   (`owner_user_id`, `dataset`),
  KEY `idx_rd_scope`   (`scope`, `visible_role`, `dataset`),
  KEY `idx_rd_pinned`  (`is_pinned`, `sort_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Verification (optional):
-- SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'report_definitions';
