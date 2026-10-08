<?php
/**
 * ============================================================
 * SyncInstanceService — dataset identity for the mobile sync engine
 * ============================================================
 * Added 2026-10-07 (release 1.6.2+28, ghost-hymn remediation).
 *
 * THE PROBLEM THIS SOLVES
 * The hymn library on phones is local-first SQLite kept current by an
 * upsert-by-id delta cursor. Deletions travel only as archived
 * tombstones INSIDE one server's timeline, so after a server migration
 * or database restore, rows that exist only on the device (synced from
 * the previous dataset) can never be removed by deltas — they render
 * and pollute search forever ("ghost hymns"). The carried-over cursor
 * can also skip rows that predate it in the new dataset.
 *
 * THE FIX (industry sync-anchor / storage-reset pattern — the same idea
 * as Android sync accounts, Chrome sync and Gmail storage resets):
 * the server publishes a stable per-dataset identity. Clients bind
 * their local caches to the identity they were built from; when it
 * changes, they purge server-derived rows (pending local edits stay
 * protected) and re-pull from an empty cursor.
 *
 * LIFECYCLE
 *   - Generated ONCE per dataset, lazily, on first read.
 *   - Stable across code deploys (a deploy must not reset the fleet).
 *   - Rotated DELIBERATELY when the dataset is replaced (restore,
 *     migration, cutover to a different database) — delete the
 *     'sync_instance_id' row from system_settings (or UPDATE it) and
 *     every client rebuilds its corpus on the next sync cycle.
 *
 * FAILURE MODEL
 * Every failure path returns '' (never throws): GET /app/config must
 * stay usable during a database outage, and clients treat an empty id
 * as "epoch unknown — skip the check".
 */
namespace App\Services;

final class SyncInstanceService
{
    const SETTING_KEY = 'sync_instance_id';

    /**
     * The dataset identity for this deployment. Empty when the value
     * cannot be read or established (older/legacy databases keep
     * working; clients simply skip the epoch check).
     */
    public static function instanceId(?\mysqli $conn = null): string
    {
        try {
            if ($conn === null || $conn->connect_error) {
                return '';
            }

            // Fast path: the row exists — a single SELECT, no writes.
            $stmt = $conn->prepare(
                'SELECT setting_value FROM system_settings WHERE setting_key = ? LIMIT 1'
            );
            if (!$stmt) {
                return '';
            }
            $key = self::SETTING_KEY;
            $stmt->bind_param('s', $key);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if ($row) {
                $value = trim((string)($row['setting_value'] ?? ''));
                if (self::valid($value)) {
                    return $value;
                }
            }

            // Bootstrap: generate and insert. ON DUPLICATE KEY UPDATE
            // makes concurrent first-requests race-safe — exactly one
            // value wins, and the re-SELECT below returns the WINNER's
            // value to every caller (never two different ids).
            $candidate = bin2hex(random_bytes(16));
            $stmt = $conn->prepare(
                'INSERT INTO system_settings (setting_key, setting_value) '
                . 'VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_key = setting_key'
            );
            if (!$stmt) {
                return '';
            }
            $stmt->bind_param('ss', $key, $candidate);
            $stmt->execute();
            $stmt->close();

            // Read back the authoritative value (ours, or the winner of
            // a race we lost).
            $stmt = $conn->prepare(
                'SELECT setting_value FROM system_settings WHERE setting_key = ? LIMIT 1'
            );
            if (!$stmt) {
                return '';
            }
            $stmt->bind_param('s', $key);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            $value = trim((string)($row['setting_value'] ?? ''));
            return self::valid($value) ? $value : '';
        } catch (\Throwable $e) {
            return '';
        }
    }

    /** Identity values are opaque hex tokens of a sane length. */
    private static function valid(string $value): bool
    {
        return $value !== ''
            && strlen($value) <= 128
            && ctype_xdigit($value);
    }
}
