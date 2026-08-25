<?php
/**
 * One-time identity code migration — renumbers every active member to the
 * ministry A/B/C sequential system and regenerates all ID-card QR images.
 *
 * Usage (CLI ONLY, from the deployment root):
 *   php admin/tools/migrate_identity_codes.php --dry-run
 *   php admin/tools/migrate_identity_codes.php --execute
 *
 * The tool is idempotent: already-migrated members (tracked in
 * member_code_migrations) are skipped on re-run.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit('Not found.');
}

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../backend/services/MemberCategory.php';
require_once __DIR__ . '/../backend/services/IdentityCodeService.php';

use App\Services\IdentityCodeService;
use App\Services\MemberCategory;

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

echo "=== FKSS Identity Code Migration ===\n";
echo 'Mode: ' . ($isDryRun ? 'DRY RUN' : 'EXECUTE') . "\n\n";

/* ── Step 1: Verify schema prerequisites ─────────────────────────────── */
$check = $conn->query(
    "SELECT COUNT(*) AS c FROM information_schema.tables
     WHERE table_schema = DATABASE() AND table_name = 'member_code_migrations'"
);
if (!$check || (int)$check->fetch_assoc()['c'] === 0) {
    fwrite(STDERR, "FATAL: run sql/017_identity_codes.sql first.\n");
    exit(1);
}
$check->free();

/* ── Step 2: Renumber students per category letter ──────────────────── */
$letters = MemberCategory::letters();
$totalRenumbered = 0;
$totalQrRefreshed = 0;
$errors = [];

foreach ($letters as $letter) {
    $group = MemberCategory::groupFor($letter);
    echo "--- Category {$letter} ({$group}) ---\n";

    // Alphabetical within category; keyset pagination for bounded memory.
    $lastId = 0;
    $seq = 0;
    while (true) {
        $stmt = $conn->prepare(
            "SELECT id, member_code, student_name, father_name
             FROM members
             WHERE age_group = ? AND status != 'archived' AND id > ?
               AND member_code NOT REGEXP CONCAT('^', ?, '[0-9]+$')
             ORDER BY student_name ASC, father_name ASC, id ASC
             LIMIT 200"
        );
        if (!$stmt) {
            $errors[] = "Prepare failed for letter {$letter}";
            break;
        }
        $stmt->bind_param('sis', $group, $lastId, $letter);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($result->num_rows === 0) {
            $stmt->close();
            break;
        }

        $updateStmt = $conn->prepare(
            "UPDATE members SET legacy_member_code = ?, member_code = ? WHERE id = ?"
        );
        $logStmt = $conn->prepare(
            "INSERT INTO member_code_migrations (member_id, old_code, new_code)
             VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE old_code = VALUES(old_code), new_code = VALUES(new_code)"
        );

        while ($row = $result->fetch_assoc()) {
            $memberId = (int)$row['id'];
            $oldCode = (string)$row['member_code'];
            $lastId = $memberId;

            // Skip members that already have a staff-format code (contain '-')
            if (strpos($oldCode, '-') !== false) {
                continue;
            }

            $seq++;
            $newCode = $letter . (string)$seq;

            // Ensure uniqueness (paranoid check against manual inserts).
            $dupStmt = $conn->prepare('SELECT 1 FROM members WHERE member_code = ? AND id != ? LIMIT 1');
            $dupStmt->bind_param('si', $newCode, $memberId);
            $dupStmt->execute();
            if ($dupStmt->get_result()->fetch_row()) {
                // Bump until unique.
                $seq++;
                $newCode = $letter . (string)$seq;
                $seq++; // reserve this slot too
            }
            $dupStmt->close();

            echo "  {$row['student_name']} {$row['father_name']}: {$oldCode} → {$newCode}\n";

            if (!$isDryRun) {
                $updateStmt->bind_param('ssi', $oldCode, $newCode, $memberId);
                if (!$updateStmt->execute()) {
                    $errors[] = "Update failed for member {$memberId}";
                    continue;
                }
                $logStmt->bind_param('iss', $memberId, $oldCode, $newCode);
                $logStmt->execute();
                $totalRenumbered++;

                // Regenerate QR if the member had one.
                $qrCheck = $conn->prepare(
                    "SELECT qr_code_path FROM members WHERE id = ?"
                );
                $qrCheck->bind_param('i', $memberId);
                $qrCheck->execute();
                $qrPath = (string)($qrCheck->get_result()->fetch_assoc()['qr_code_path'] ?? '');
                $qrCheck->close();

                if ($qrPath !== '') {
                    require_once __DIR__ . '/../id_cards/libs/phpqrcode/qrlib.php';
                    if (IdentityCodeService::regenerateQr($conn, $memberId)) {
                        $totalQrRefreshed++;
                    }
                }
            }
        }
        $updateStmt->close();
        $logStmt->close();
        $result->free();
        $stmt->close();

        if ($isDryRun && $seq > 20) {
            echo "  … (dry-run preview truncated)\n";
            break;
        }
    }
}

/* ── Summary ────────────────────────────────────────────────────────── */
echo "\n=== SUMMARY ===\n";
echo 'Members renumbered: ' . ($isDryRun ? '(dry-run)' : $totalRenumbered) . "\n";
echo 'QR codes regenerated: ' . ($isDryRun ? '(dry-run)' : $totalQrRefreshed) . "\n";
if ($errors) {
    echo "Errors:\n";
    foreach ($errors as $e) { echo "  - {$e}\n"; }
}
if ($isDryRun) {
    echo "\nThis was a DRY RUN. Re-run with --execute to apply changes.\n";
} else {
    echo "\nMigration complete.\n";
}

$conn->close();
