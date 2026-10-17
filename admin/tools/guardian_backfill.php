<?php
/**
 * Family Link one-time backfill.
 *
 * Builds guardian records from the legacy denormalized members.guardian_*
 * columns and links each member to its primary guardian.
 *
 * Usage:
 *   php admin/tools/guardian_backfill.php --dry-run
 *   php admin/tools/guardian_backfill.php --execute
 *
 * Design notes:
 *  - Idempotent: members that already have a primary guardian link are skipped.
 *  - Conservative clustering: members share a guardian ONLY when the
 *    last-9 phone digits match exactly, or (when no phone exists at all)
 *    when the normalized guardian name matches exactly. Near-duplicate
 *    names across different phones are NOT merged here — that is the job
 *    of the matcher + review queue afterwards.
 *  - Name/phone variants inside a cluster become guardian_aliases so the
 *    typo-tolerant matcher can use them later.
 *  - Batches at most 200 clusters per run (shared-hosting safe). Repeat
 *    until the summary reports 0 deferred clusters.
 *  - Single summary audit row per run; no per-record audit flooding.
 *  - source='backfill' on every created guardian and alias.
 *  - All output goes straight to STDOUT/STDERR: config.php starts an
 *    ob_gzhandler buffer that the error monitor may discard at shutdown,
 *    so CLI tools must not rely on echo/ob flushing.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only.');
}
@set_time_limit(0);

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../backend/services/GuardianService.php';
require_once __DIR__ . '/../backend/services/GuardianMatchService.php';

use App\Services\GuardianService;
use App\Services\GuardianMatchService;

const MAX_CLUSTERS_PER_RUN = 200;

function bf_out(string $msg): void
{
    fwrite(STDOUT, $msg);
}

$args = $argv ?? [];
$isDryRun = in_array('--dry-run', $args, true);
$isExecute = in_array('--execute', $args, true);
if ($isDryRun === $isExecute) {
    fwrite(STDERR, "Usage: php " . basename(__FILE__) . " --dry-run | --execute\n");
    exit(1);
}

function bf_norm_name(string $n): string
{
    $n = mb_strtolower(trim(preg_replace('/\s+/u', ' ', $n) ?? $n));
    return $n;
}

function bf_mode(array $values): string
{
    $counts = [];
    foreach ($values as $v) {
        $v = trim((string)$v);
        if ($v === '') {
            continue;
        }
        $counts[$v] = ($counts[$v] ?? 0) + 1;
    }
    if (!$counts) {
        return '';
    }
    arsort($counts);
    return (string)array_key_first($counts);
}

function bf_first_nonempty(array $values): string
{
    foreach ($values as $v) {
        $v = trim((string)$v);
        if ($v !== '') {
            return $v;
        }
    }
    return '';
}

// ---- Load unlinked members with guardian data -----------------------------
$rows = [];
$cursor = 0;
while (true) {
    $stmt = $conn->prepare(
        'SELECT m.id, m.student_name, m.guardian_name, m.guardian_phone1, m.guardian_phone2,
                m.guardian_city, m.guardian_sub_city, m.guardian_woreda, m.guardian_mender,
                m.guardian_block_number, m.guardian_house
         FROM members m
         LEFT JOIN member_guardians mg ON mg.member_id = m.id AND mg.is_primary = 1
         WHERE mg.member_id IS NULL
           AND (COALESCE(m.guardian_name, \'\') <> \'\' OR COALESCE(m.guardian_phone1, \'\') <> \'\')
           AND m.id > ?
         ORDER BY m.id ASC
         LIMIT 2000'
    );
    if (!$stmt) {
        fwrite(STDERR, "Prepare failed: " . $conn->error . "\n");
        exit(1);
    }
    $stmt->bind_param('i', $cursor);
    $stmt->execute();
    $res = $stmt->get_result();
    $batch = 0;
    while ($r = $res->fetch_assoc()) {
        $rows[] = $r;
        $cursor = (int)$r['id'];
        $batch++;
    }
    $stmt->close();
    if ($batch < 2000) {
        break;
    }
}

// ---- Conservative clustering ----------------------------------------------
$clusters = [];
foreach ($rows as $r) {
    $n9 = GuardianMatchService::phoneN9((string)$r['guardian_phone1']);
    $n9b = GuardianMatchService::phoneN9((string)$r['guardian_phone2']);
    $nname = bf_norm_name((string)$r['guardian_name']);
    if ($n9 !== '') {
        $key = 'P:' . $n9;
    } elseif ($n9b !== '') {
        $key = 'P:' . $n9b;
    } elseif ($nname !== '') {
        $key = 'N:' . $nname;
    } else {
        continue;
    }
    if (!isset($clusters[$key])) {
        $clusters[$key] = ['members' => [], 'names' => [], 'p1' => [], 'p2' => [], 'addr' => []];
    }
    $clusters[$key]['members'][] = $r;
    $clusters[$key]['names'][] = (string)$r['guardian_name'];
    $clusters[$key]['p1'][] = (string)$r['guardian_phone1'];
    $clusters[$key]['p2'][] = (string)$r['guardian_phone2'];
    $clusters[$key]['addr'][] = $r;
}

$totalMembers = count($rows);
$totalClusters = count($clusters);
$processClusters = array_slice($clusters, 0, MAX_CLUSTERS_PER_RUN, true);
$deferredClusters = max(0, $totalClusters - count($processClusters));

bf_out(($isDryRun ? "[DRY RUN] " : "[EXECUTE] ") . "Family Link backfill\n");
bf_out("Unlinked members with guardian data: {$totalMembers}\n");
bf_out("Conservative clusters found: {$totalClusters}\n");
bf_out("Clusters in this run: " . count($processClusters) . " (cap " . MAX_CLUSTERS_PER_RUN . ", deferred {$deferredClusters})\n\n");

$shown = 0;
$created = 0;
$linked = 0;
$aliased = 0;

$conn->begin_transaction();
try {
    foreach ($processClusters as $key => $c) {
        $displayName = bf_mode($c['names']);
        if ($displayName === '') {
            continue;
        }
        $p1 = bf_mode($c['p1']);
        $p2 = bf_mode($c['p2']);
        if ($p1 === '' && $p2 !== '') {
            $p1 = $p2;
            $p2 = '';
        }
        $n91 = GuardianMatchService::phoneN9($p1);
        $n92 = GuardianMatchService::phoneN9($p2);
        $addr = $c['addr'];
        $city = bf_first_nonempty(array_column($addr, 'guardian_city'));
        $sub = bf_first_nonempty(array_column($addr, 'guardian_sub_city'));
        $wor = bf_first_nonempty(array_column($addr, 'guardian_woreda'));
        $men = bf_first_nonempty(array_column($addr, 'guardian_mender'));
        $blk = bf_first_nonempty(array_column($addr, 'guardian_block_number'));
        $hou = bf_first_nonempty(array_column($addr, 'guardian_house'));

        $aliasVariants = array_values(array_unique(array_filter(
            array_map(static fn($v) => trim((string)$v), $c['names']),
            static fn($v) => $v !== '' && bf_norm_name($v) !== bf_norm_name($displayName)
        )));

        if ($shown < 10) {
            $shown++;
            bf_out(sprintf(
                "  %-34s members=%-3d phone=%-15s aliases=%d\n",
                mb_substr($displayName, 0, 32),
                count($c['members']),
                $p1 !== '' ? $p1 : '(none)',
                count($aliasVariants)
            ));
        }

        if (!$isExecute) {
            $created++;
            $linked += count($c['members']);
            $aliased += count($aliasVariants);
            continue;
        }

        $source = 'backfill';
        $stmt = $conn->prepare('INSERT INTO guardians (display_name, phone_primary, phone_alt, phone_primary_n9, phone_alt_n9, city, sub_city, woreda, mender, block_number, house, source, created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,0)');
        if (!$stmt) {
            throw new RuntimeException('Guardian insert prepare failed: ' . $conn->error);
        }
        $stmt->bind_param('ssssssssssss', $displayName, $p1, $p2, $n91, $n92, $city, $sub, $wor, $men, $blk, $hou, $source);
        if (!$stmt->execute()) {
            throw new RuntimeException('Guardian insert failed: ' . $stmt->error);
        }
        $gid = (int)$conn->insert_id;
        $stmt->close();
        $created++;

        foreach ($aliasVariants as $alias) {
            $astmt = $conn->prepare("INSERT IGNORE INTO guardian_aliases (guardian_id, alias_name, source) VALUES (?,?,'backfill')");
            if ($astmt) {
                $astmt->bind_param('is', $gid, $alias);
                $astmt->execute();
                $astmt->close();
                $aliased++;
            }
        }

        foreach ($c['members'] as $m) {
            GuardianService::linkMember($conn, $gid, (int)$m['id'], 'other', 1, true, false);
            $linked++;
        }
    }

    if ($isExecute) {
        GuardianService::audit(
            $conn,
            'Guardian Backfill',
            "Created {$created} guardians, linked {$linked} member rows, stored {$aliased} alias variants (batch cap " . MAX_CLUSTERS_PER_RUN . ", deferred clusters {$deferredClusters})."
        );
        $conn->commit();
    } else {
        $conn->rollback();
    }
} catch (Throwable $e) {
    $conn->rollback();
    fwrite(STDERR, "Backfill failed, rolled back: " . $e->getMessage() . "\n");
    exit(1);
}

bf_out("\n" . ($isDryRun ? "Would create" : "Created") . " guardians: {$created}\n");
bf_out(($isDryRun ? "Would link" : "Linked") . " member rows: {$linked}\n");
bf_out("Alias variants stored: {$aliased}\n");
if ($deferredClusters > 0) {
    bf_out("\nDeferred clusters remain ({$deferredClusters}). Run again until this reaches 0.\n");
} else {
    bf_out("\nAll clusters processed in this run.\n");
}
if ($isDryRun) {
    bf_out("\nDry run only — nothing was written. Re-run with --execute to apply.\n");
}
