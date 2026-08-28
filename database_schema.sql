-- ============================================================
-- WBWS School Management System - Database Schema
-- ============================================================
-- Database: wbwsprvr_wbws
-- Server: MariaDB 10.6
-- Last Updated: December 26, 2025
-- ============================================================
-- 
-- HOW TO USE THIS FILE:
-- 1. This file documents all tables in your database
-- 2. If you need to recreate the database, run this in phpMyAdmin
-- 3. Keep this file updated when you add new tables/columns
-- ============================================================

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
SET time_zone = "+00:00";
SET NAMES utf8mb4;

-- ============================================================
-- TABLE: users
-- Purpose: Admin login accounts (super admin, departments, etc.)
-- ============================================================
CREATE TABLE IF NOT EXISTS `users` (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `username` varchar(50) NOT NULL,
  `email` varchar(100) DEFAULT NULL,
  `full_name` varchar(100) NOT NULL,
  `role` enum('super_admin','school_admin','info_dept','edu_dept','finance_dept','material_dept') NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `username` (`username`),
  UNIQUE KEY `email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- TABLE: members
-- Purpose: Main student/member data (Sunday school members)
-- ============================================================
CREATE TABLE IF NOT EXISTS `members` (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  
  -- Identification
  `member_code` varchar(30) DEFAULT NULL,
  `registration_type` enum('waiting','transfer','direct') NOT NULL DEFAULT 'waiting',
  `waiting_since` date DEFAULT NULL,
  
  -- Names (Amharic & English)
  `full_name_am` varchar(150) NOT NULL,
  `student_name` varchar(150) NOT NULL,
  `baptismal_name` varchar(150) DEFAULT NULL,
  `father_name` varchar(150) NOT NULL,
  `grandfather_name` varchar(150) DEFAULT NULL,
  `full_name_en` varchar(150) DEFAULT NULL,
  `christian_name` varchar(100) DEFAULT NULL,
  
  -- Personal Info
  `gender` enum('male','female') NOT NULL DEFAULT 'male',
  `date_of_birth` date DEFAULT NULL,
  `dob_ec_day` tinyint(3) UNSIGNED DEFAULT NULL,
  `dob_ec_month` tinyint(3) UNSIGNED DEFAULT NULL,
  `dob_ec_year` smallint(5) UNSIGNED DEFAULT NULL,
  `age` tinyint(3) UNSIGNED DEFAULT NULL,
  
  -- Section/Classification
  `current_section` varchar(60) DEFAULT NULL,
  `education_level` varchar(60) DEFAULT NULL,
  `age_group` enum('7_13','14_17','18_plus') DEFAULT NULL,
  `member_type` enum('regular','special_regular','honorary') NOT NULL DEFAULT 'regular',
  
  -- Role Flags (can be teacher, staff, etc.)
  `is_teacher` tinyint(1) NOT NULL DEFAULT 0,
  `is_staff` tinyint(1) NOT NULL DEFAULT 0,
  `is_committee` tinyint(1) NOT NULL DEFAULT 0,
  `is_volunteer` tinyint(1) NOT NULL DEFAULT 0,
  `is_dept_head_1` tinyint(1) NOT NULL DEFAULT 0,
  `is_dept_head_2` tinyint(1) NOT NULL DEFAULT 0,
  `is_dept_head_3` tinyint(1) NOT NULL DEFAULT 0,
  `is_dept_head_4` tinyint(1) NOT NULL DEFAULT 0,
  `is_dept_head_5` tinyint(1) NOT NULL DEFAULT 0,
  `is_dept_head_6` tinyint(1) NOT NULL DEFAULT 0,
  `is_dept_head_7` tinyint(1) NOT NULL DEFAULT 0,
  `is_dept_head_8` tinyint(1) NOT NULL DEFAULT 0,
  
  -- Status
  `status` enum('active','warning','inactive','archived') NOT NULL DEFAULT 'active',
  
  -- Contact Info
  `phone_primary` varchar(30) DEFAULT NULL,
  `phone_number` varchar(30) DEFAULT NULL,
  `alt_phone_number` varchar(30) DEFAULT NULL,
  `phone_guardian` varchar(30) DEFAULT NULL,
  
  -- Guardian Info
  `guardian_name` varchar(150) DEFAULT NULL,
  `guardian_phone1` varchar(30) DEFAULT NULL,
  `guardian_phone2` varchar(30) DEFAULT NULL,
  `guardian_city` varchar(60) DEFAULT NULL,
  `guardian_sub_city` varchar(60) DEFAULT NULL,
  `guardian_woreda` varchar(10) DEFAULT NULL,
  `guardian_mender` varchar(10) DEFAULT NULL,
  `guardian_block_number` varchar(10) DEFAULT NULL,
  `guardian_house` varchar(10) DEFAULT NULL,
  
  -- Member Address
  `address` text DEFAULT NULL,
  `city` varchar(60) DEFAULT NULL,
  `sub_city` varchar(60) DEFAULT NULL,
  `woreda` varchar(10) DEFAULT NULL,
  `mender` varchar(10) DEFAULT NULL,
  `block_number` varchar(10) DEFAULT NULL,
  `house_number` varchar(10) DEFAULT NULL,
  `work_profession` varchar(150) DEFAULT NULL,
  
  -- Emergency Contact
  `emergency_name` varchar(100) DEFAULT NULL,
  `emergency_phone` varchar(20) DEFAULT NULL,
  
  -- Registration Info
  `registered_at` date NOT NULL DEFAULT curdate(),
  `created_by` int(10) UNSIGNED DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  
  -- File Paths (photos & documents)
  `student_photo_path` varchar(255) DEFAULT NULL,
  `guardian_photo_path` varchar(255) DEFAULT NULL,
  `doc_school_records_path` varchar(255) DEFAULT NULL,
  `doc_spiritual_path` varchar(255) DEFAULT NULL,
  `doc_signed_form_path` varchar(255) DEFAULT NULL,
  `signed_form_path` varchar(255) DEFAULT NULL,
  
  -- ID Card Info
  `id_card_status` enum('none','pending','generated') DEFAULT 'none',
  `id_card_generated_at` datetime DEFAULT NULL,
  `qr_code_path` varchar(255) DEFAULT NULL,
  
  PRIMARY KEY (`id`),
  UNIQUE KEY `member_code` (`member_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- TABLE: wbws_groups
-- Purpose: Groups/departments in the Sunday school
-- ============================================================
CREATE TABLE IF NOT EXISTS `wbws_groups` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `group_name` varchar(190) NOT NULL,
  `established_year` varchar(20) DEFAULT NULL,
  `is_under_sunday_school` tinyint(1) NOT NULL DEFAULT 1,
  `founding_male` int(11) NOT NULL DEFAULT 0,
  `founding_female` int(11) NOT NULL DEFAULT 0,
  `current_male` int(11) NOT NULL DEFAULT 0,
  `current_female` int(11) NOT NULL DEFAULT 0,
  `notes` varchar(500) DEFAULT NULL,
  `created_by` varchar(100) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `group_name` (`group_name`),
  KEY `is_under_sunday_school` (`is_under_sunday_school`),
  KEY `created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ============================================================
-- TABLE: wbws_group_leaders
-- Purpose: Leaders assigned to each group
-- ============================================================
CREATE TABLE IF NOT EXISTS `wbws_group_leaders` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `group_id` int(11) NOT NULL,
  `leader_full_name` varchar(190) NOT NULL,
  `sex` enum('M','F') NOT NULL,
  `phone` varchar(30) DEFAULT NULL,
  `education_level` varchar(80) DEFAULT NULL,
  `responsibility` varchar(120) DEFAULT NULL,
  `remark` varchar(200) DEFAULT NULL,
  `created_by` varchar(100) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `group_id` (`group_id`),
  CONSTRAINT `fk_wbws_group_leaders_group` FOREIGN KEY (`group_id`) REFERENCES `wbws_groups` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ============================================================
-- TABLE: cache_storage (Optional - for caching)
-- Purpose: Store cached data to improve performance
-- ============================================================
CREATE TABLE IF NOT EXISTS `cache_storage` (
  `cache_key` varchar(100) NOT NULL,
  `cache_value` longtext DEFAULT NULL,
  `expires_at` datetime NOT NULL,
  PRIMARY KEY (`cache_key`),
  KEY `expires_at` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- NOTES FOR FUTURE TABLES:
-- ============================================================
-- 
-- attendance        - Track member attendance
-- academic_records  - Store grades and academic info
-- payments          - Track membership fees/payments
-- classes           - Class/grade definitions
-- academic_years    - Academic year settings
-- 
-- ============================================================
-- ============================================================
-- Identity & Code Management (sql/017 + sql/018 + sql/019)
-- Incremental migrations own these; the block below keeps the
-- fresh-install baseline in sync.
-- ============================================================
CREATE TABLE IF NOT EXISTS `departments` (
  `id` INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `code` VARCHAR(4) NOT NULL,
  `name_en` VARCHAR(100) NOT NULL,
  `name_am` VARCHAR(100) DEFAULT NULL,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY `uk_departments_code` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `staff_positions` (
  `id` INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `department_id` INT NOT NULL,
  `role_code` VARCHAR(20) NOT NULL,
  `title_en` VARCHAR(100) NOT NULL,
  `title_am` VARCHAR(100) DEFAULT NULL,
  `sort_order` INT NOT NULL DEFAULT 0,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY `uk_staff_positions_code` (`department_id`, `role_code`),
  KEY `fk_staff_positions_dept` (`department_id`),
  CONSTRAINT `fk_staff_positions_dept` FOREIGN KEY (`department_id`) REFERENCES `departments` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `member_staff_positions` (
  `member_id` INT UNSIGNED NOT NULL,
  `position_id` INT NOT NULL,
  `assigned_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  `assigned_by` INT UNSIGNED DEFAULT NULL,
  PRIMARY KEY (`member_id`, `position_id`),
  KEY `fk_msp_position` (`position_id`),
  CONSTRAINT `fk_msp_member` FOREIGN KEY (`member_id`) REFERENCES `members` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_msp_position` FOREIGN KEY (`position_id`) REFERENCES `staff_positions` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `member_code_migrations` (
  `id` INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `member_id` INT NOT NULL,
  `old_code` VARCHAR(30) NOT NULL,
  `new_code` VARCHAR(30) NOT NULL,
  `reason` VARCHAR(50) DEFAULT NULL,
  `migrated_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY `uk_member_code_migrations_member` (`member_id`),
  KEY `idx_member_code_migrations_member` (`member_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `member_code_sequences` (
  `letter` CHAR(1) NOT NULL,
  `last_n` INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (`letter`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `member_type_settings` (
  `type_key` VARCHAR(30) NOT NULL PRIMARY KEY,
  `label_am` VARCHAR(80) NOT NULL,
  `label_en` VARCHAR(80) NOT NULL,
  `sort_order` INT NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
INSERT INTO `member_type_settings` (`type_key`,`label_am`,`label_en`,`sort_order`) VALUES
('regular','መደበኛ','Regular Member',1),
('special_regular','ልዩ መደበኛ','Special Regular Member',2),
('honorary','ክብረ ደንበኛ','Honorary Member',3)
ON DUPLICATE KEY UPDATE `label_am`=VALUES(`label_am`), `label_en`=VALUES(`label_en`), `sort_order`=VALUES(`sort_order`);

ALTER TABLE `members` ADD COLUMN IF NOT EXISTS `legacy_member_code` VARCHAR(30) DEFAULT NULL;
ALTER TABLE `members` ADD INDEX IF NOT EXISTS `idx_members_code` (`member_code`);
ALTER TABLE `members` ADD INDEX IF NOT EXISTS `idx_members_legacy_code` (`legacy_member_code`);
