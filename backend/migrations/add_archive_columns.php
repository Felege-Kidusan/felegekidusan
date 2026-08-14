<?php
/**
 * Database Migration: Add Archive Columns
 * Run this once to add archive tracking columns to the members table
 * 
 * Usage: Visit /admin/migrations/add_archive_columns.php in browser
 */

require_once dirname(__DIR__) . '/config.php';

// Check auth
if (empty($_SESSION['admin_username'])) {
    die('Unauthorized. Please login first.');
}

echo "<html><head><title>Database Migration</title>";
echo "<style>body{font-family:sans-serif;padding:20px;max-width:800px;margin:0 auto}";
echo ".success{color:green;}.error{color:red;}.info{color:blue;}</style></head><body>";
echo "<h1>Database Migration - Archive & Registration Columns</h1>";

$columns_to_add = [
    'registered_at' => "ALTER TABLE members ADD COLUMN registered_at DATETIME NULL DEFAULT NULL AFTER created_at",
    'spiritual_education' => "ALTER TABLE members ADD COLUMN spiritual_education VARCHAR(50) NULL DEFAULT NULL AFTER education_level",
    'archived_at' => "ALTER TABLE members ADD COLUMN archived_at DATETIME NULL DEFAULT NULL AFTER status",
    'archived_by' => "ALTER TABLE members ADD COLUMN archived_by VARCHAR(100) NULL DEFAULT NULL AFTER archived_at",
    'archive_reason' => "ALTER TABLE members ADD COLUMN archive_reason VARCHAR(50) NULL DEFAULT NULL AFTER archived_by",
    'archive_notes' => "ALTER TABLE members ADD COLUMN archive_notes TEXT NULL AFTER archive_reason",
    'restored_at' => "ALTER TABLE members ADD COLUMN restored_at DATETIME NULL DEFAULT NULL AFTER archive_notes",
    'restored_by' => "ALTER TABLE members ADD COLUMN restored_by VARCHAR(100) NULL DEFAULT NULL AFTER restored_at"
];

// Check existing columns
$existing_columns = [];
$result = $conn->query("SHOW COLUMNS FROM members");
while ($row = $result->fetch_assoc()) {
    $existing_columns[] = $row['Field'];
}

echo "<h2>Checking Columns...</h2>";
echo "<ul>";

$added = 0;
$skipped = 0;

foreach ($columns_to_add as $column => $sql) {
    if (in_array($column, $existing_columns)) {
        echo "<li class='info'>Column <strong>$column</strong> already exists - skipped</li>";
        $skipped++;
    } else {
        if ($conn->query($sql)) {
            echo "<li class='success'>✓ Column <strong>$column</strong> added successfully</li>";
            $added++;
        } else {
            echo "<li class='error'>✗ Failed to add <strong>$column</strong>: " . $conn->error . "</li>";
        }
    }
}

echo "</ul>";

echo "<h2>Summary</h2>";
echo "<p>Columns added: <strong>$added</strong></p>";
echo "<p>Columns skipped (already exist): <strong>$skipped</strong></p>";

if ($added > 0) {
    echo "<p class='success' style='font-size:18px;'>✓ Migration completed successfully!</p>";
} else {
    echo "<p class='info'>No changes needed - all columns already exist.</p>";
}

echo "<p><a href='/admin/dashboards/info-dept.php'>← Back to Dashboard</a></p>";
echo "</body></html>";

$conn->close();
