<?php
/**
 * Format-v2 identity code migration — renumbers every active member to
 * `{PREFIX}-{random 5-digit tail}` and regenerates ID-card QR images.
 *
 * Usage (CLI ONLY, from the deployment root):
 *   php admin/tools/migrate_identity_codes.php --dry-run
 *   php admin/tools/migrate_identity_codes.php --execute
 *
 * Idempotent: members whose code already matches their computed v2
 * prefix are skipped. Shared engine with the web runner
 * (admin/api_identity_migration.php) via IdentityMigrationService.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit('Not found.');
}

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../backend/services/IdentityMigrationService.php';

use App\Services\IdentityMigrationService;

$args = array_slice($argv, 1);
$isDryRun = in_array('--dry-run', $args, true);
$isExecute = in_array('--execute', $args, true);

if (!$isDryRun && !$isExecute) {
    fwrite(STDERR, "Usage: php " . basename(__FILE__) . " --dry-run | --execute\n");
    exit(1);
}

mysqli_report(MYSQLI_REPORT_OFF);
$conn = @new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
if ($conn->connect_error) {
    fwrite(STDERR, "FATAL: database connection failed.\n");
    exit(1);
}
$conn->set_charset('utf8mb4');

$check = $conn->query("SELECT COUNT(*) AS c FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'member_code_migrations'");
if (!$check || (int)$check->fetch_assoc()['c'] === 0) {
    fwrite(STDERR, "Run sql/017_identity_codes.sql first.\n");
    exit(1);
}
$check->free();

@set_time_limit(600);
require_once __DIR__ . '/../id_cards/libs/qr_loader.php';

echo "=== Identity code format v2 migration (" . ($isDryRun ? 'DRY RUN' : 'EXECUTE') . ") ===\n";
$outcome = IdentityMigrationService::renumberAll($conn, $isDryRun);

foreach ($outcome['log'] as $line) {
    echo $line . "\n";
}

echo "\n=== SUMMARY ===\n";
echo ($isDryRun ? "Would renumber: " : "Renumbered: ") . $outcome['renumbered'] . "\n";
echo "QR refreshed: " . ($isDryRun ? 'n/a' : (string)$outcome['qr']) . "\n";
echo "Pending (no category/positions): " . $outcome['skipped_pending'] . "\n";
echo "Errors: " . count($outcome['errors']) . "\n";
foreach ($outcome['errors'] as $err) {
    echo "  ! " . $err . "\n";
}
$conn->close();
exit($outcome['errors'] === [] ? 0 : 2);
