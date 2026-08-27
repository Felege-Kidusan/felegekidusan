-- ════════════════════════════════════════════════════════════
-- 022 — Mezmur Attendance & Analytics (መዝሙር ክፍል)
-- Session-based attendance (section-grouped analytics) for the
-- Mezmur department, plus an audit trail for decision-critical
-- records.
--
-- Safety: creates NEW objects only (CREATE TABLE IF NOT EXISTS).
-- Never touches the class-attendance dataset. Idempotent.
-- ════════════════════════════════════════════════════════════

-- ── Sessions (rehearsals / services / feast programs) ────────
CREATE TABLE IF NOT EXISTS `mezmur_sessions` (
    `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `session_date`  DATE NOT NULL,
    `program_type`  VARCHAR(50) NOT NULL DEFAULT 'rehearsal',
    `title`         VARCHAR(255) NOT NULL,
    `notes`         VARCHAR(500) DEFAULT NULL,
    `status`        ENUM('active','deleted') NOT NULL DEFAULT 'active',
    `created_by`    INT UNSIGNED DEFAULT NULL,
    `created_at`    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_mezmur_sessions_date` (`session_date`),
    KEY `idx_mezmur_sessions_type` (`program_type`),
    KEY `idx_mezmur_sessions_status` (`status`),
    CONSTRAINT `fk_mezmur_sessions_created_by`
        FOREIGN KEY (`created_by`) REFERENCES `users` (`id`)
        ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Attendance marks (one per member per session) ─────────────
CREATE TABLE IF NOT EXISTS `mezmur_attendance` (
    `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `session_id`  BIGINT UNSIGNED NOT NULL,
    `member_id`   INT UNSIGNED NOT NULL,
    `status`      ENUM('present','late','absent') NOT NULL DEFAULT 'present',
    `marked_by`   INT UNSIGNED DEFAULT NULL,
    `marked_at`   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
                  ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_mezmur_attendance_session_member` (`session_id`, `member_id`),
    KEY `idx_mezmur_attendance_member` (`member_id`, `session_id`),
    KEY `idx_mezmur_attendance_status` (`status`),
    CONSTRAINT `fk_mezmur_attendance_session`
        FOREIGN KEY (`session_id`) REFERENCES `mezmur_sessions` (`id`)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_mezmur_attendance_member`
        FOREIGN KEY (`member_id`) REFERENCES `members` (`id`)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_mezmur_attendance_marked_by`
        FOREIGN KEY (`marked_by`) REFERENCES `users` (`id`)
        ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Audit trail (who changed what, for selection decisions) ───
CREATE TABLE IF NOT EXISTS `mezmur_attendance_audit` (
    `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `session_id`  BIGINT UNSIGNED DEFAULT NULL,
    `actor_id`    INT UNSIGNED DEFAULT NULL,
    `action`      VARCHAR(40) NOT NULL,
    `details`     VARCHAR(500) DEFAULT NULL,
    `created_at`  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_mezmur_audit_session` (`session_id`),
    KEY `idx_mezmur_audit_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
