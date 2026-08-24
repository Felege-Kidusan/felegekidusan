<?php
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

/**
 * Legacy compatibility migration. HTTP execution is disabled.
 * Apply reviewed, versioned sql/*.sql migrations during deployment.
 *
 * Database Migration: Add Temporary Members Tier & Archive Type
 * Run this once to add new columns to the members table
 */

require_once dirname(__DIR__) . '/config.php';

// Check auth
if (empty($_SESSION['admin_username'])) {
    die('Unauthorized. Please login first.');
}

echo "<html><head><title>Database Migration - Temporary Tier</title>";
echo "<style>body{font-family:sans-serif;padding:20px;max-width:800px;margin:0 auto}";
echo ".success{color:green;}.error{color:red;}.info{color:blue;}</style></head><body>";
echo "<h1>Database Migration - Temporary Tier & Archive Types</h1>";

$columns_to_add = [
    'membership_tier' => "ALTER TABLE members ADD COLUMN membership_tier ENUM('temporary', 'permanent') NULL DEFAULT 'permanent' AFTER status",
    'archive_type' => "ALTER TABLE members ADD COLUMN archive_type ENUM('permanent_archive', 'failed_observation') NULL DEFAULT 'permanent_archive' AFTER archive_reason"
];

// Check existing columns
$existing_columns = [];
$result = $conn->query("SHOW COLUMNS FROM members");
while ($row = $result->fetch_assoc()) {
    $existing_columns[] = $row['Field'];
}

echo "<h2>Applying Migrations...</h2>";
echo "<ul>";

$added = 0;
$skipped = 0;

foreach ($columns_to_add as $column => $sql) {
    if (in_array($column, $existing_columns)) {
        echo "<li class='info'>Column <strong>$column</strong> already exists - skipped</li>";
        $skipped++;
    } else {
        if ($conn->query($sql)) {
            echo "<li class='success'>Column <strong>$column</strong> added successfully</li>";
            $added++;
        } else {
            echo "<li class='error'>Error adding <strong>$column</strong>: " . $conn->error . "</li>";
        }
    }
}
echo "</ul>";
echo "<p>Migration finished. $added added, $skipped skipped.</p>";
echo "</body></html>";
