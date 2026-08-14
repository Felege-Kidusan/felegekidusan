<?php
/**
 * Migration 004: Finance & Material Department Tables
 * Run once to create all required tables for these departments.
 */
require_once __DIR__ . '/../config.php';

header('Content-Type: text/html; charset=utf-8');
echo "<h2>Migration 004: Finance & Material Tables</h2><pre>";

$tables = [
// ── FINANCE TABLES ──
"CREATE TABLE IF NOT EXISTS `finance_categories` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `name` VARCHAR(100) NOT NULL,
    `type` ENUM('income','expense') NOT NULL,
    `description` VARCHAR(255) DEFAULT NULL,
    `is_active` TINYINT(1) DEFAULT 1,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

"CREATE TABLE IF NOT EXISTS `finance_transactions` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `type` ENUM('income','expense') NOT NULL,
    `category_id` INT UNSIGNED DEFAULT NULL,
    `member_id` INT UNSIGNED DEFAULT NULL,
    `amount` DECIMAL(12,2) NOT NULL DEFAULT 0,
    `description` VARCHAR(500) DEFAULT NULL,
    `receipt_number` VARCHAR(50) DEFAULT NULL,
    `payment_method` ENUM('cash','bank_transfer','mobile_money','check','other') DEFAULT 'cash',
    `transaction_date` DATE NOT NULL,
    `ec_month` TINYINT UNSIGNED DEFAULT NULL,
    `ec_year` SMALLINT UNSIGNED DEFAULT NULL,
    `recorded_by` INT UNSIGNED DEFAULT NULL,
    `status` ENUM('confirmed','pending','cancelled') DEFAULT 'confirmed',
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY `idx_type` (`type`),
    KEY `idx_date` (`transaction_date`),
    KEY `idx_member` (`member_id`),
    KEY `idx_category` (`category_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

"CREATE TABLE IF NOT EXISTS `finance_budgets` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `category_id` INT UNSIGNED DEFAULT NULL,
    `ec_year` SMALLINT UNSIGNED NOT NULL,
    `budgeted_amount` DECIMAL(12,2) NOT NULL DEFAULT 0,
    `description` VARCHAR(255) DEFAULT NULL,
    `created_by` INT UNSIGNED DEFAULT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY `uniq_cat_year` (`category_id`, `ec_year`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

"CREATE TABLE IF NOT EXISTS `finance_member_fees` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `member_id` INT UNSIGNED NOT NULL,
    `fee_type` VARCHAR(100) NOT NULL DEFAULT 'monthly',
    `amount` DECIMAL(12,2) NOT NULL DEFAULT 0,
    `ec_month` TINYINT UNSIGNED DEFAULT NULL,
    `ec_year` SMALLINT UNSIGNED DEFAULT NULL,
    `paid_date` DATE DEFAULT NULL,
    `status` ENUM('paid','unpaid','partial') DEFAULT 'unpaid',
    `recorded_by` INT UNSIGNED DEFAULT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    KEY `idx_member` (`member_id`),
    KEY `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

// ── MATERIAL TABLES ──
"CREATE TABLE IF NOT EXISTS `material_categories` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `name` VARCHAR(100) NOT NULL,
    `description` VARCHAR(255) DEFAULT NULL,
    `is_active` TINYINT(1) DEFAULT 1,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

"CREATE TABLE IF NOT EXISTS `material_items` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `name` VARCHAR(150) NOT NULL,
    `category_id` INT UNSIGNED DEFAULT NULL,
    `description` VARCHAR(500) DEFAULT NULL,
    `quantity` INT DEFAULT 0,
    `min_quantity` INT DEFAULT 0,
    `unit` VARCHAR(30) DEFAULT 'piece',
    `location` VARCHAR(100) DEFAULT NULL,
    `condition_status` ENUM('good','fair','poor','damaged','disposed') DEFAULT 'good',
    `purchase_date` DATE DEFAULT NULL,
    `purchase_price` DECIMAL(12,2) DEFAULT NULL,
    `status` ENUM('in_stock','low_stock','out_of_stock','maintenance') DEFAULT 'in_stock',
    `added_by` INT UNSIGNED DEFAULT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY `idx_category` (`category_id`),
    KEY `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

"CREATE TABLE IF NOT EXISTS `material_transactions` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `item_id` INT UNSIGNED NOT NULL,
    `type` ENUM('incoming','outgoing','adjustment','disposal') NOT NULL,
    `quantity` INT NOT NULL DEFAULT 0,
    `reason` VARCHAR(255) DEFAULT NULL,
    `handled_by` VARCHAR(100) DEFAULT NULL,
    `recorded_by` INT UNSIGNED DEFAULT NULL,
    `transaction_date` DATE NOT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    KEY `idx_item` (`item_id`),
    KEY `idx_type` (`type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

"CREATE TABLE IF NOT EXISTS `material_requests` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `item_id` INT UNSIGNED DEFAULT NULL,
    `item_name` VARCHAR(150) DEFAULT NULL,
    `quantity` INT NOT NULL DEFAULT 1,
    `requested_by` VARCHAR(100) NOT NULL,
    `department` VARCHAR(100) DEFAULT NULL,
    `reason` VARCHAR(500) DEFAULT NULL,
    `status` ENUM('pending','approved','denied','fulfilled') DEFAULT 'pending',
    `approved_by` INT UNSIGNED DEFAULT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
];

// Insert default categories
$defaults = [
"INSERT IGNORE INTO `finance_categories` (`name`,`type`,`description`) VALUES
('Monthly Contribution','income','Regular monthly member fees'),
('Special Offering','income','Special event contributions'),
('Donation','income','General donations'),
('Event Income','income','Income from events/activities'),
('Teaching Materials','expense','Books, supplies for teaching'),
('Office Supplies','expense','Administrative supplies'),
('Maintenance','expense','Building/facility maintenance'),
('Events & Programs','expense','Event organization costs'),
('Transport','expense','Transportation costs'),
('Utility','expense','Electric, water, etc.'),
('Other Income','income','Miscellaneous income'),
('Other Expense','expense','Miscellaneous expenses')",

"INSERT IGNORE INTO `material_categories` (`name`,`description`) VALUES
('Church Items','Holy items, crosses, books'),
('Educational Materials','Textbooks, notebooks, teaching aids'),
('Office Supplies','Pens, paper, printer supplies'),
('Furniture','Tables, chairs, desks'),
('Equipment','Projectors, speakers, computers'),
('Cleaning Supplies','Cleaning materials and tools'),
('Kitchen Items','Cups, plates, cooking supplies')"
];

$success = 0; $fail = 0;
foreach ($tables as $sql) {
    try {
        $conn->query($sql);
        preg_match('/`(\w+)`/', $sql, $m);
        echo "✅ Table {$m[1]} — OK\n";
        $success++;
    } catch (Exception $e) {
        echo "❌ Error: " . $e->getMessage() . "\n";
        $fail++;
    }
}

foreach ($defaults as $sql) {
    try { $conn->query($sql); echo "✅ Default data inserted\n"; }
    catch (Exception $e) { echo "⚠️ Defaults: " . $e->getMessage() . "\n"; }
}

echo "\n═══════════════════════════════════\n";
echo "Done! Success: $success | Failed: $fail\n";
echo "═══════════════════════════════════\n";
echo "</pre><p><a href='../dashboard.php'>← Back to Dashboard</a></p>";
