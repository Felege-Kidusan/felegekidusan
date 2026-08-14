<?php
/**
 * ============================================================
 * Migration 005: Create system_branding table
 * ============================================================
 * Run this ONCE if the branding section shows errors.
 * Safe to re-run — uses IF NOT EXISTS.
 * 
 * How to run:
 *   Option A: Visit this URL while logged in as super_admin
 *   Option B: Copy the SQL below and run in phpMyAdmin
 * ============================================================
 */
require_once __DIR__ . '/../config.php';

// Only super_admin can run migrations
if (empty($_SESSION['admin_id']) || ($_SESSION['admin_role'] ?? '') !== 'super_admin') {
    die('<h2 style="color:red">Access denied — Super Admin only</h2>');
}

echo '<pre style="font-family:monospace;background:#111;color:#0f0;padding:20px;border-radius:10px;max-width:800px;margin:20px auto">';
echo "=== Migration 005: system_branding table ===\n\n";

$errors = [];
$success = [];

// Step 1: Create the table
$sql1 = "CREATE TABLE IF NOT EXISTS `system_branding` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `asset_key` VARCHAR(50) NOT NULL,
    `asset_label` VARCHAR(100) NOT NULL DEFAULT '',
    `file_path` VARCHAR(500) DEFAULT NULL,
    `original_name` VARCHAR(255) DEFAULT NULL,
    `mime_type` VARCHAR(100) DEFAULT NULL,
    `file_size` INT UNSIGNED DEFAULT 0,
    `uploaded_by` INT UNSIGNED DEFAULT NULL,
    `uploaded_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_asset_key` (`asset_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

if ($conn->query($sql1)) {
    $success[] = "✅ Table 'system_branding' created/verified";
} else {
    $errors[] = "❌ CREATE TABLE failed: " . $conn->error;
}

// Step 2: Seed defaults
$defaults = [
    ['logo',      'School Logo',                '/admin/id_cards/assets/logos/school_logo.png'],
    ['seal',      'School Seal / Stamp',         '/admin/id_cards/assets/seals/school_seal.png'],
    ['sig_head',  'Head Teacher Signature',      '/admin/id_cards/assets/signatures/head_signature.png'],
    ['sig_admin', 'Director / Admin Signature',  '/admin/id_cards/assets/signatures/director_signature.png'],
];

$stmt = $conn->prepare("INSERT IGNORE INTO system_branding (asset_key, asset_label, file_path) VALUES (?, ?, ?)");
if ($stmt) {
    foreach ($defaults as $d) {
        $stmt->bind_param("sss", $d[0], $d[1], $d[2]);
        if ($stmt->execute()) {
            if ($stmt->affected_rows > 0) {
                $success[] = "  ✅ Seeded: " . $d[0] . " → " . $d[1];
            } else {
                $success[] = "  ⏭  Already exists: " . $d[0];
            }
        } else {
            $errors[] = "  ❌ Seed failed for " . $d[0] . ": " . $stmt->error;
        }
    }
    $stmt->close();
} else {
    $errors[] = "❌ Prepare failed: " . $conn->error;
}

// Step 3: Create asset directories
$baseDir = __DIR__ . '/../id_cards/assets';
$dirs = ['logos', 'seals', 'signatures', 'qr'];
foreach ($dirs as $sub) {
    $path = $baseDir . '/' . $sub;
    if (!is_dir($path)) {
        if (mkdir($path, 0755, true)) {
            $success[] = "  📁 Created: id_cards/assets/" . $sub . "/";
        } else {
            $errors[] = "  ❌ Could not create: " . $sub . "/ (check permissions)";
        }
    } else {
        $success[] = "  📁 Exists: id_cards/assets/" . $sub . "/";
    }
}

// Step 4: Verify
$check = $conn->query("SELECT COUNT(*) as cnt FROM system_branding");
$count = $check ? $check->fetch_assoc()['cnt'] : 0;

echo "\n--- Results ---\n";
foreach ($success as $s) echo $s . "\n";
foreach ($errors as $e) echo "<span style='color:red'>" . $e . "</span>\n";

echo "\n--- Verification ---\n";
echo "Rows in system_branding: " . $count . "\n";

if (empty($errors)) {
    echo "\n<span style='color:#0f0;font-weight:bold;font-size:1.2em'>✅ MIGRATION COMPLETE — Branding is ready!</span>\n";
    echo "\n<a href='../dashboards/super-admin.php?section=branding' style='color:#0af;text-decoration:underline'>→ Go to Branding Section</a>\n";
} else {
    echo "\n<span style='color:red;font-weight:bold;font-size:1.2em'>⚠ SOME ERRORS — See above</span>\n";
}

echo '</pre>';
