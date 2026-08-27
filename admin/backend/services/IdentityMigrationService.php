<?php
/**
 * Format-v2 renumbering engine — shared by the CLI tool and the web
 * runner so the logic can never drift.
 *
 * Every active member is (re)issued a v2 code:
 *   * prefix recomputed from CURRENT positions (v2 composition) or the
 *     category letter when the member holds none,
 *   * tail is a fresh random unique 5-digit number,
 *   * old code preserved in legacy_member_code + member_code_migrations,
 *   * QR image regenerated.
 *
 * Members whose stored code already matches their computed v2 prefix
 * are skipped, which makes the runner idempotent and cheap to re-run.
 * Keyset pagination (id > last) keeps memory flat at any roster size.
 */
namespace App\Services;

require_once __DIR__ . '/IdentityCodeService.php';
require_once __DIR__ . '/MemberCategory.php';

final class IdentityMigrationService
{
    private const PAGE = 100;
    private const LOG_CAP = 200;

    /**
     * @return array{renumbered:int,qr:int,errors:list<string>,log:list<string>,skipped_pending:int}
     */
    public static function renumberAll(\mysqli $conn, bool $dryRun): array
    {
        $renumbered = 0;
        $qr = 0;
        $pending = 0;
        $errors = [];
        $log = [];
        $lastId = 0;

        while (true) {
            $stmt = $conn->prepare(
                "SELECT id, member_code, age_group, student_name, father_name
                 FROM members
                 WHERE status != 'archived' AND id > ?
                 ORDER BY id ASC LIMIT " . self::PAGE
            );
            if (!$stmt) {
                $errors[] = 'Paged query prepare failed.';
                break;
            }
            $stmt->bind_param('i', $lastId);
            $stmt->execute();
            $result = $stmt->get_result();
            if ($result->num_rows === 0) {
                $stmt->close();
                break;
            }

            while ($row = $result->fetch_assoc()) {
                $memberId = (int)$row['id'];
                $lastId = $memberId;
                $old = (string)($row['member_code'] ?? '');
                $name = $row['student_name'] . ' ' . $row['father_name'];

                $segments = IdentityCodeService::resolveStaffSegments($conn, $memberId);
                if ($segments !== null && $segments !== []) {
                    $prefix = $segments[0];
                    $letter = null;
                } else {
                    $prefix = null;
                    $letter = MemberCategory::letterFor((string)$row['age_group']);
                }

                if ($prefix === null && $letter === null) {
                    $pending++;
                    if (count($log) < self::LOG_CAP) {
                        $log[] = "  {$name}: no positions & no resolvable category — stays pending";
                    }
                    continue;
                }
                $expected = $prefix ?? $letter;

                $parsed = IdentityCodeService::parse($old);
                if ($parsed !== null && $old !== '' &&
                    ($parsed['kind'] === 'staff' ? $parsed['prefix'] : $parsed['letter']) === $expected) {
                    continue; // already v2 with the correct prefix
                }

                if (count($log) < self::LOG_CAP) {
                    $log[] = "  {$name}: {$old} → {$expected}-" . ($dryRun ? '#####' : '(new tail)');
                }

                if ($dryRun) {
                    $renumbered++;
                    continue;
                }

                try {
                    $newCode = $letter !== null
                        ? IdentityCodeService::allocateStudent($conn, $letter)
                        : IdentityCodeService::allocateStaff($conn, [$prefix]);

                    $update = $conn->prepare(
                        'UPDATE members SET legacy_member_code = ?, member_code = ? WHERE id = ?'
                    );
                    $update->bind_param('ssi', $old, $newCode, $memberId);
                    if (!$update->execute()) {
                        $errors[] = "Update failed for member {$memberId}";
                        $update->close();
                        continue;
                    }
                    $update->close();

                    $logStmt = $conn->prepare(
                        'INSERT INTO member_code_migrations (member_id, old_code, new_code, reason)
                         VALUES (?, ?, ?, ?)
                         ON DUPLICATE KEY UPDATE old_code = VALUES(old_code), new_code = VALUES(new_code), reason = VALUES(reason)'
                    );
                    $reason = 'format_v2_renumber';
                    $logStmt->bind_param('isss', $memberId, $old, $newCode, $reason);
                    $logStmt->execute();
                    $logStmt->close();

                    $renumbered++;
                    if (IdentityCodeService::regenerateQr($conn, $memberId)) {
                        $qr++;
                    }
                } catch (\Throwable $e) {
                    $errors[] = "Member {$memberId}: " . $e->getMessage();
                }
            }
            $result->free();
            $stmt->close();
        }

        return [
            'renumbered' => $renumbered,
            'qr' => $qr,
            'errors' => $errors,
            'log' => $log,
            'skipped_pending' => $pending,
        ];
    }
}
