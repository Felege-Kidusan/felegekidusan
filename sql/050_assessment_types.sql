-- ============================================================
-- 050_assessment_types.sql  (IDEMPOTENT / RE-RUNNABLE)
-- Dynamic Assessment Types managed by Education Department.
-- Replaces rigid hardcoded types with full CRUD management.
-- ============================================================

CREATE TABLE IF NOT EXISTS `assessment_types` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `type_key` VARCHAR(50) NOT NULL UNIQUE,
    `type_name` VARCHAR(100) NOT NULL COMMENT 'Amharic display name, e.g. ፈተና, የቃል ፈተና',
    `type_name_en` VARCHAR(100) DEFAULT NULL COMMENT 'English display name, e.g. Test, Oral Exam',
    `default_weight` DECIMAL(5,2) DEFAULT NULL COMMENT 'Default suggested weight %',
    `default_max_score` DECIMAL(6,2) NOT NULL DEFAULT 100.00 COMMENT 'Default maximum score',
    `description` TEXT DEFAULT NULL,
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `sort_order` INT NOT NULL DEFAULT 0,
    `created_by` INT UNSIGNED DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_asmt_types_active` (`is_active`, `sort_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Seed default initial types if empty
INSERT IGNORE INTO `assessment_types` (`type_key`, `type_name`, `type_name_en`, `default_weight`, `default_max_score`, `sort_order`) VALUES
('test', 'ፈተና', 'Class Test', 10.00, 10.00, 1),
('midterm', 'አጋማሽ ፈተና', 'Midterm Exam', 40.00, 40.00, 2),
('final', 'የማጠቃለያ ፈተና', 'Final Exam', 50.00, 50.00, 3),
('quiz', 'አጭር ፈተና', 'Quiz', 10.00, 10.00, 4),
('assignment', 'የቤት ስራ', 'Assignment / Homework', 10.00, 10.00, 5),
('project', 'ተግባራዊ ስራ', 'Project', 20.00, 20.00, 6),
('participation', 'ተሳትፎ', 'Participation', 10.00, 10.00, 7),
('oral_exam', 'የቃል ፈተና', 'Oral Exam', 15.00, 15.00, 8),
('memorization', 'የቃል ጥናት / ዜማ', 'Hymn / Memorization', 15.00, 15.00, 9);
