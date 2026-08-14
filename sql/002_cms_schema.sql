-- ============================================================
-- FKSS CMS — Database Migration
-- ============================================================
-- Creates tables for: Gallery, Registration Submissions,
-- Social Links, Teachers, Weekly Schedule, Programs
-- Plus: adds 'content_editor' role to users table
--
-- Run this in phpMyAdmin (SQL tab) on the FKSS database.
-- Safe to run multiple times.
-- ============================================================

-- ── 1. Expand users.role to allow content_editor (and the other roles) ──
ALTER TABLE `users`
MODIFY COLUMN `role` VARCHAR(30) NOT NULL DEFAULT 'info_dept';
-- Valid roles now: super_admin, school_admin, info_dept, edu_dept,
--                  finance_dept, material_dept, teacher, attendance_taker, content_editor


-- ── 2. GALLERY: categories (albums) ──
CREATE TABLE IF NOT EXISTS `cms_gallery_categories` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `name` VARCHAR(100) NOT NULL,
    `name_am` VARCHAR(150) DEFAULT NULL,
    `slug` VARCHAR(120) NOT NULL,
    `description` TEXT DEFAULT NULL,
    `sort_order` INT NOT NULL DEFAULT 0,
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `slug` (`slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── 3. GALLERY: photos ──
CREATE TABLE IF NOT EXISTS `cms_gallery_photos` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `category_id` INT UNSIGNED DEFAULT NULL,
    `image_path` VARCHAR(255) NOT NULL,
    `caption` VARCHAR(255) DEFAULT NULL,
    `caption_am` VARCHAR(300) DEFAULT NULL,
    `sort_order` INT NOT NULL DEFAULT 0,
    `is_featured` TINYINT(1) NOT NULL DEFAULT 0,
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `uploaded_by` INT UNSIGNED DEFAULT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `category_id` (`category_id`),
    KEY `is_active` (`is_active`),
    CONSTRAINT `fk_gallery_cat` FOREIGN KEY (`category_id`)
        REFERENCES `cms_gallery_categories` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── 4. REGISTRATION SUBMISSIONS (leads from public site — Option B) ──
CREATE TABLE IF NOT EXISTS `cms_registration_submissions` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `full_name` VARCHAR(150) NOT NULL,
    `phone` VARCHAR(30) NOT NULL,
    `email` VARCHAR(120) DEFAULT NULL,
    `age` VARCHAR(10) DEFAULT NULL,
    `gender` VARCHAR(20) DEFAULT NULL,
    `address` VARCHAR(255) DEFAULT NULL,
    `program_interest` VARCHAR(150) DEFAULT NULL,
    `message` TEXT DEFAULT NULL,
    `status` ENUM('new','contacted','enrolled','rejected') NOT NULL DEFAULT 'new',
    `admin_notes` TEXT DEFAULT NULL,
    `ip_address` VARCHAR(45) DEFAULT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `status` (`status`),
    KEY `created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── 5. SOCIAL MEDIA LINKS ──
CREATE TABLE IF NOT EXISTS `cms_social_links` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `platform` VARCHAR(50) NOT NULL,
    `url` VARCHAR(255) NOT NULL,
    `icon_class` VARCHAR(80) NOT NULL DEFAULT 'fa-solid fa-link',
    `label` VARCHAR(100) DEFAULT NULL,
    `sort_order` INT NOT NULL DEFAULT 0,
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── 6. TEACHERS (public "our teachers" section) ──
CREATE TABLE IF NOT EXISTS `cms_teachers` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `name` VARCHAR(150) NOT NULL,
    `name_am` VARCHAR(200) DEFAULT NULL,
    `role_title` VARCHAR(150) DEFAULT NULL,
    `role_title_am` VARCHAR(200) DEFAULT NULL,
    `bio` TEXT DEFAULT NULL,
    `photo_path` VARCHAR(255) DEFAULT NULL,
    `sort_order` INT NOT NULL DEFAULT 0,
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `is_active` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── 7. WEEKLY SCHEDULE ──
CREATE TABLE IF NOT EXISTS `cms_schedule` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `day_of_week` VARCHAR(20) NOT NULL,
    `day_of_week_am` VARCHAR(40) DEFAULT NULL,
    `time_label` VARCHAR(60) DEFAULT NULL,
    `activity` VARCHAR(200) NOT NULL,
    `activity_am` VARCHAR(250) DEFAULT NULL,
    `location` VARCHAR(150) DEFAULT NULL,
    `sort_order` INT NOT NULL DEFAULT 0,
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `is_active` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── 8. PROGRAMS ──
CREATE TABLE IF NOT EXISTS `cms_programs` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `title` VARCHAR(200) NOT NULL,
    `title_am` VARCHAR(250) DEFAULT NULL,
    `description` TEXT DEFAULT NULL,
    `description_am` TEXT DEFAULT NULL,
    `icon_class` VARCHAR(80) DEFAULT 'fa-solid fa-book',
    `features` TEXT DEFAULT NULL,
    `sort_order` INT NOT NULL DEFAULT 0,
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `is_active` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- SEED DATA — sensible starting content so the public page
-- isn't empty on day one. Edit/delete from the admin later.
-- ============================================================

-- Default gallery category
INSERT INTO `cms_gallery_categories` (`name`, `name_am`, `slug`, `sort_order`)
SELECT 'General', 'አጠቃላይ', 'general', 0
WHERE NOT EXISTS (SELECT 1 FROM `cms_gallery_categories` WHERE `slug` = 'general');

-- Default social links (edit URLs in admin)
INSERT INTO `cms_social_links` (`platform`, `url`, `icon_class`, `label`, `sort_order`)
SELECT * FROM (
    SELECT 'Facebook' AS platform, '#' AS url, 'fa-brands fa-facebook' AS icon_class, 'Facebook' AS label, 1 AS sort_order
    UNION ALL SELECT 'Telegram', '#', 'fa-brands fa-telegram', 'Telegram', 2
    UNION ALL SELECT 'YouTube', '#', 'fa-brands fa-youtube', 'YouTube', 3
    UNION ALL SELECT 'TikTok', '#', 'fa-brands fa-tiktok', 'TikTok', 4
) AS seed
WHERE NOT EXISTS (SELECT 1 FROM `cms_social_links` LIMIT 1);

-- Done. Verify: SHOW TABLES LIKE 'cms_%';
