<?php
/**
 * Family Link re-match sweep (CLI).
 *
 * Re-scores active guardians against each other and files probable
 * duplicates (review band) into the HR review queue. Never merges.
 *
 * Usage (one full pass, resumable in bounded steps):
 *   php admin/tools/guardian_rematch.php
 *
 * Optional cPanel cron (nightly 02:30):
 *   30 2 * * * /usr/local/bin/php /home/USER/public_html/admin/tools/guardian_rematch.php >/dev/null 2>&1
 *
 * The same sweep is available from the HR dashboard ("Run re-match now"),
 * so a cron is optional on shared hosting.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only.');
}
@set_time_limit(0);

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../backend/services/GuardianService.php';
require_once __DIR__ . '/../backend/services/GuardianMatchService.php';

use App\Services\GuardianMatchService;
use App\Services\GuardianService;

$cursor = 0;
$totalScanned = 0;
$totalFiled = 0;
$steps = 0;
do {
    $r = GuardianMatchService::rematchBatch($conn, $cursor, 300, 25.0);
    $steps++;
    $totalScanned += $r['scanned'];
    $totalFiled += $r['filed'];
    fwrite(STDOUT, sprintf("step %d: scanned=%d filed=%d cursor=%d (%.2fs)\n", $steps, $r['scanned'], $r['filed'], $r['next_cursor'], $r['elapsed']));
    if ($r['next_cursor'] <= $cursor && !$r['done']) {
        break; // defensive: no progress
    }
    $cursor = $r['next_cursor'];
} while (!$r['done'] && $steps < 500);

GuardianService::audit($conn, 'Guardian Rematch', "CLI sweep complete: scanned {$totalScanned} guardians, filed {$totalFiled} suggestion(s) in {$steps} step(s).");
fwrite(STDOUT, "Done. scanned={$totalScanned} filed={$totalFiled}\n");
