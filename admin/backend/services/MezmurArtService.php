<?php
/**
 * ════════════════════════════════════════════════════════════
 * MezmurArtService — hymn cover art plane (P66 "Hymn Art")
 * ════════════════════════════════════════════════════════════
 * Every hymn can carry its own cover image, Spotify-style.
 *
 * STORAGE MODEL (owner decision 2026-09-09: LOCAL DISK, not R2 —
 * R2 stays the audio-only media plane):
 *   uploads/mezmur_art/{hymn_id}/{uuid32}_{160|320|640}.jpg
 *   • art_key stores the RELATIVE PATH PREFIX
 *     ("uploads/mezmur_art/{hymn_id}/{uuid32}");
 *   • public URLs are rebuilt from art_key at read time and
 *     version-tagged with art_updated_at (?v=…) — a new artwork
 *     always means a NEW URL, so browsers/Cloudflare can cache the
 *     files forever without a disk stat per request (the
 *     immutable-URL pattern; same idea as Spotify/Apple renditions).
 *
 * RENDITIONS (Spotify multi-size model): 160 (list thumb) /
 * 320 (card & detail) / 640 (hero, blurred player backdrop).
 * Source images are center-cropped to a square master — the same
 * 1:1 shape every large music platform standardises on — and
 * re-encoded as JPEG, which also strips EXIF/GPS/polyglot payloads.
 *
 * DOMINANT COLOR (Spotify "color as emotional infrastructure"):
 * art_color is extracted ONCE server-side at upload and stored as
 * #rrggbb so web and mobile theme from ONE server truth instead of
 * each client recomputing (and disagreeing).
 *
 * SECURITY — the exact OWASP chain the repo's own audit certifies
 * for category/singer images (taxonomyImageStore), kept identical on
 * purpose: is_uploaded_file → size cap → finfo magic bytes →
 * getimagesize bounds → full GD decode → RE-ENCODE → random
 * server-chosen filename (user input never touches a path) →
 * images-only .htaccess in the directory → prepared statements →
 * audit trail. art_key NEVER leaves the server; only built URLs do.
 *
 * SYNC DISCIPLINE (MZ-2 lesson): every mutation bumps revision and
 * updated_at so the mobile delta cursor converges art changes to
 * every device — exactly like audio and lyrics edits.
 * ════════════════════════════════════════════════════════════
 */

namespace App\Services;

final class MezmurArtService
{
    /** Fixed rendition widths (px). Order matters for file writes. */
    public const RENDITIONS = [160, 320, 640];

    /** Largest accepted upload (bytes) — the web manager's dialog
     *  pre-checks 4 MB too, but the server never trusts the client. */
    public const MAX_UPLOAD_BYTES = 4 * 1024 * 1024;

    /** Source dimension bounds (same as category/zemarian images). */
    public const MIN_DIM = 16;
    public const MAX_DIM = 4000;

    /** Fallback dominant color when the art is near-grayscale — the
     *  school's deep maroon keeps the theme on-brand. */
    public const FALLBACK_COLOR = '#5A1212';

    private static ?bool $hasArtColumnsCache = null;

    /**
     * Probe the schema once per request (probe-guarded pattern, so a
     * pre-040 database degrades to "no art" instead of a 1054 fatal).
     */
    public static function artColumnsReady(\mysqli $conn): bool
    {
        if (self::$hasArtColumnsCache === null) {
            $has = false;
            try {
                $r = $conn->query("SHOW COLUMNS FROM mezmur_hymns LIKE 'art_key'");
                $has = $r ? (bool)$r->fetch_assoc() : false;
                if ($r) {
                    $r->close();
                }
            } catch (\Throwable $e) {
                $has = false;
            }
            self::$hasArtColumnsCache = $has;
        }
        return self::$hasArtColumnsCache;
    }

    /**
     * SELECT fragment for the art fields, safe on a pre-040 schema
     * (mirrors MezmurHymnService::mediaColsExpr). art_updated_at is
     * projected as a unix timestamp so artPayload can version URLs
     * without another disk/database round trip.
     */
    public static function artColsExpr(\mysqli $conn): string
    {
        if (!self::artColumnsReady($conn)) {
            return "NULL AS art_key, 'none' AS art_status, NULL AS art_color, NULL AS art_updated_ts";
        }
        return "art_key, art_status, art_color, UNIX_TIMESTAMP(art_updated_at) AS art_updated_ts";
    }

    /**
     * Merge the public art payload into a hymn row and HIDE the
     * internal art_key (same contract as MezmurMediaService::
     * decorateRow: the key never leaves the server, only URLs).
     *
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    public static function decorateRow(array $row): array
    {
        $payload = self::artPayload($row);
        unset($row['art_key'], $row['art_updated_ts']);
        return array_merge($row, $payload);
    }

    /**
     * Public art fields for JSON. A row without ready art still gets
     * the full key set (empty strings / null) so clients can rely on
     * the shape — old payloads simply had no art at all.
     *
     * @param array<string,mixed> $row
     * @return array{art_status:string,art_color:?string,art_url:string,art_url_medium:string,art_url_small:string}
     */
    public static function artPayload(array $row): array
    {
        $status = (string)($row['art_status'] ?? 'none');
        $key = trim((string)($row['art_key'] ?? ''));
        $ready = ($status === 'ready' && $key !== '');
        $version = '';
        $ts = (int)($row['art_updated_ts'] ?? 0);
        if ($ts > 0) {
            $version = '?v=' . $ts;
        }
        $color = null;
        if ($ready && self::validColor((string)($row['art_color'] ?? ''))) {
            $color = strtoupper((string)$row['art_color']);
        }
        return [
            'art_status' => $status,
            'art_color' => $color,
            'art_url' => $ready ? ('/' . $key . '_640.jpg' . $version) : '',
            'art_url_medium' => $ready ? ('/' . $key . '_320.jpg' . $version) : '',
            'art_url_small' => $ready ? ('/' . $key . '_160.jpg' . $version) : '',
        ];
    }

    /** Strict '#rrggbb' validator (server truth only — clients mirror). */
    public static function validColor(string $value): bool
    {
        return (bool)preg_match('/^#[0-9a-fA-F]{6}$/', $value);
    }

    /**
     * Upload (or replace) the art of one hymn. Single request: the
     * files are small (≤4 MB) so — unlike 5–15 MB audio — they go
     * through PHP, which is REQUIRED anyway to run the OWASP chain,
     * crop the renditions and extract the dominant color.
     *
     * @param array<string,mixed> $file $_FILES['image']
     * @return array{ok:bool,message:string,art?:array|null}
     */
    public static function uploadArt(\mysqli $conn, int $hymnId, array $file, int $actorId): array
    {
        if (!self::artColumnsReady($conn)) {
            return ['ok' => false, 'message' => 'Hymn art columns are missing. Run sql/040_mezmur_hymn_art.sql, or press Sync DB schema in the Mezmur console.'];
        }
        if ($hymnId <= 0) {
            return ['ok' => false, 'message' => 'A hymn id is required.'];
        }

        $stmt = $conn->prepare('SELECT id, art_key FROM mezmur_hymns WHERE id = ? LIMIT 1');
        $stmt->bind_param('i', $hymnId);
        $stmt->execute();
        $hymn = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$hymn) {
            return ['ok' => false, 'message' => 'Hymn not found.'];
        }

        // ── OWASP upload chain (identical to taxonomyImageStore) ──
        if (empty($file['tmp_name']) || (int)($file['error'] ?? 1) !== UPLOAD_ERR_OK) {
            return ['ok' => false, 'message' => 'Upload failed — please try again.'];
        }
        if (!is_uploaded_file($file['tmp_name'])) {
            return ['ok' => false, 'message' => 'Upload failed — invalid transfer.'];
        }
        $size = (int)($file['size'] ?? 0);
        if ($size <= 0 || $size > self::MAX_UPLOAD_BYTES) {
            return ['ok' => false, 'message' => 'Image must be at most 4 MB.'];
        }
        $raw = @file_get_contents($file['tmp_name']);
        if ($raw === false || strlen($raw) !== $size) {
            return ['ok' => false, 'message' => 'Upload failed — unreadable file.'];
        }
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime = (string)$finfo->buffer($raw);
        $allow = ['image/jpeg' => 'jpg', 'image/png' => 'png'];
        if (function_exists('imagewebp')) {
            $allow['image/webp'] = 'jpg'; // re-encoded to JPEG
        }
        if (!isset($allow[$mime])) {
            return ['ok' => false, 'message' => 'Only JPEG, PNG or WebP images are allowed.'];
        }
        $info = @getimagesizefromstring($raw);
        if ($info === false
            || (int)$info[0] < self::MIN_DIM || (int)$info[1] < self::MIN_DIM
            || (int)$info[0] > self::MAX_DIM || (int)$info[1] > self::MAX_DIM) {
            return ['ok' => false, 'message' => 'The image dimensions must be between 16×16 and 4000×4000.'];
        }
        $src = @imagecreatefromstring($raw);
        if ($src === false) {
            return ['ok' => false, 'message' => 'The file is not a valid image.'];
        }

        // ── Square master (industry 1:1 cover standard) ──────────
        // Center-crop to a square, then render the fixed renditions.
        // The source bitmap is discarded — the re-encode below is what
        // strips EXIF/GPS and any embedded payload.
        $square = self::centerCropSquare($src);
        imagedestroy($src);
        if ($square === null) {
            return ['ok' => false, 'message' => 'The file is not a valid image.'];
        }

        // Dominant color from the square master (computed BEFORE the
        // renditions free it).
        $color = self::extractDominantColor($square);

        // ── Write renditions under a fresh random prefix ─────────
        $root = dirname(__DIR__, 3);
        $dir = $root . '/uploads/mezmur_art/' . $hymnId;
        if (!is_dir($dir) && !@mkdir($dir, 0755, true)) {
            imagedestroy($square);
            return ['ok' => false, 'message' => 'Server storage is not writable.'];
        }
        self::ensureImagesOnlyHtaccess($root . '/uploads/mezmur_art');

        $prefix = 'uploads/mezmur_art/' . $hymnId . '/' . bin2hex(random_bytes(16));
        $written = [];
        foreach (self::RENDITIONS as $px) {
            $img = imagescale($square, $px, $px);
            if ($img === false) {
                continue;
            }
            $dest = $root . '/' . $prefix . '_' . $px . '.jpg';
            if (@imagejpeg($img, $dest, 85)) {
                @chmod($dest, 0644);
                $written[] = $px;
            }
            imagedestroy($img);
        }
        imagedestroy($square);
        if (count($written) !== count(self::RENDITIONS)) {
            foreach ($written as $px) {
                @unlink($root . '/' . $prefix . '_' . $px . '.jpg');
            }
            return ['ok' => false, 'message' => 'Unable to store the image.'];
        }

        // ── Persist metadata (single writer + revision discipline) ──
        $stmt = $conn->prepare(
            "UPDATE mezmur_hymns
             SET art_key=?, art_status='ready', art_color=?,
                 art_uploaded_by=?, art_updated_at=NOW(),
                 updated_by=?, updated_at=NOW(), revision = revision + 1
             WHERE id=?"
        );
        $stmt->bind_param('ssiis', $prefix, $color, $actorId, $actorId, $hymnId);
        $ok = $stmt->execute();
        $stmt->close();
        if (!$ok) {
            foreach (self::RENDITIONS as $px) {
                @unlink($root . '/' . $prefix . '_' . $px . '.jpg');
            }
            return ['ok' => false, 'message' => 'Unable to save the art reference.'];
        }

        // Remove the previous artwork (best effort — the DB row already
        // points at the new one, an orphan is only wasted disk).
        $previousKey = trim((string)($hymn['art_key'] ?? ''));
        if ($previousKey !== '' && $previousKey !== $prefix) {
            self::unlinkArtFiles($root, $previousKey);
        }

        if (!class_exists('\\App\\Services\\SecurityAuditService')) {
            require_once __DIR__ . '/SecurityAuditService.php';
        }
        $details = ['prefix' => basename($prefix), 'color' => $color, 'actor' => $actorId];
        try {
            SecurityAuditService::record($conn, 'Mezmur Hymn Art Updated', $details, 'mezmur_hymn', $hymnId);
        } catch (\Throwable $e) {
            error_log('[mezmur-art] audit failed for hymn ' . $hymnId . ': ' . $e->getMessage());
        }

        // Read-back payload so the client can render immediately.
        $stmt = $conn->prepare(
            "SELECT art_key, art_status, art_color, UNIX_TIMESTAMP(art_updated_at) AS art_updated_ts
             FROM mezmur_hymns WHERE id = ? LIMIT 1"
        );
        $stmt->bind_param('i', $hymnId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return [
            'ok' => true,
            'message' => 'Hymn art updated.',
            'art' => $row ? self::artPayload($row) : null,
        ];
    }

    /**
     * Remove the art of one hymn: delete the three rendition files
     * (best effort) and clear the row. Revision + updated_at bump so
     * every device falls back to its gradient on the next delta.
     *
     * @return array{ok:bool,message:string}
     */
    public static function removeArt(\mysqli $conn, int $hymnId, int $actorId): array
    {
        if (!self::artColumnsReady($conn)) {
            return ['ok' => false, 'message' => 'Hymn art columns are missing. Run sql/040_mezmur_hymn_art.sql, or press Sync DB schema in the Mezmur console.'];
        }
        if ($hymnId <= 0) {
            return ['ok' => false, 'message' => 'A hymn id is required.'];
        }

        $stmt = $conn->prepare('SELECT id, art_key FROM mezmur_hymns WHERE id = ? LIMIT 1');
        $stmt->bind_param('i', $hymnId);
        $stmt->execute();
        $hymn = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$hymn) {
            return ['ok' => false, 'message' => 'Hymn not found.'];
        }
        if (trim((string)($hymn['art_key'] ?? '')) === '') {
            return ['ok' => true, 'message' => 'This hymn has no art.'];
        }

        $stmt = $conn->prepare(
            "UPDATE mezmur_hymns
             SET art_key=NULL, art_status='none', art_color=NULL, art_updated_at=NOW(),
                 updated_by=?, updated_at=NOW(), revision = revision + 1
             WHERE id=?"
        );
        $stmt->bind_param('ii', $actorId, $hymnId);
        $ok = $stmt->execute();
        $stmt->close();
        if (!$ok) {
            return ['ok' => false, 'message' => 'Unable to remove the art.'];
        }

        self::unlinkArtFiles(dirname(__DIR__, 3), trim((string)$hymn['art_key']));

        if (!class_exists('\\App\\Services\\SecurityAuditService')) {
            require_once __DIR__ . '/SecurityAuditService.php';
        }
        try {
            SecurityAuditService::record($conn, 'Mezmur Hymn Art Removed', ['actor' => $actorId], 'mezmur_hymn', $hymnId);
        } catch (\Throwable $e) {
            error_log('[mezmur-art] audit failed for hymn ' . $hymnId . ': ' . $e->getMessage());
        }
        return ['ok' => true, 'message' => 'Hymn art removed.'];
    }

    // ══════════════════════════════════════════════════════════
    // Internals
    // ══════════════════════════════════════════════════════════

    /** Center-crop a GdImage to a square (the 1:1 cover standard). */
    private static function centerCropSquare(\GdImage $src): ?\GdImage
    {
        $w = imagesx($src);
        $h = imagesy($src);
        if ($w <= 0 || $h <= 0) {
            return null;
        }
        $side = min($w, $h);
        $x = intdiv($w - $side, 2);
        $y = intdiv($h - $side, 2);
        $out = imagecrop($src, ['x' => $x, 'y' => $y, 'width' => $side, 'height' => $side]);
        return $out instanceof \GdImage ? $out : null;
    }

    /**
     * Dominant color, Spotify-style but deterministic and cheap:
     * downsample to 32×32, bucket pixels into a 4-bit-per-channel
     * histogram, score each bucket by (saturation × frequency) with a
     * luminance floor so near-black/near-white art still themes
     * readably. Returns '#RRGGBB' (uppercase).
     */
    private static function extractDominantColor(\GdImage $img): string
    {
        $small = imagescale($img, 32, 32);
        if ($small === false) {
            return self::FALLBACK_COLOR;
        }
        $buckets = [];
        for ($y = 0; $y < 32; $y++) {
            for ($x = 0; $x < 32; $x++) {
                $rgb = imagecolorat($small, $x, $y);
                $r = ($rgb >> 16) & 0xFF;
                $g = ($rgb >> 8) & 0xFF;
                $b = $rgb & 0xFF;
                $max = max($r, $g, $b);
                $min = min($r, $g, $b);
                $luma = 0.299 * $r + 0.587 * $g + 0.114 * $b;
                // Skip washed-out extremes; they never theme well.
                if ($luma < 24 || $luma > 232) {
                    continue;
                }
                $sat = $max > 0 ? ($max - $min) / $max : 0;
                $key = (($r >> 4) << 8) | (($g >> 4) << 4) | ($b >> 4);
                if (!isset($buckets[$key])) {
                    $buckets[$key] = ['n' => 0, 'r' => 0, 'g' => 0, 'b' => 0, 'sat' => 0.0];
                }
                $buckets[$key]['n']++;
                $buckets[$key]['r'] += $r;
                $buckets[$key]['g'] += $g;
                $buckets[$key]['b'] += $b;
                $buckets[$key]['sat'] += $sat;
            }
        }
        imagedestroy($small);
        if (!$buckets) {
            return self::FALLBACK_COLOR;
        }
        $best = null;
        $bestScore = -1.0;
        foreach ($buckets as $bucket) {
            // Vibrancy filter (Spotify's pipeline: dominant → vibrant →
            // contrast-checked): weight by frequency AND saturation so a
            // big grey field cannot beat a smaller vivid region.
            $score = $bucket['n'] * (0.25 + 0.75 * ($bucket['sat'] / max(1, $bucket['n'])));
            if ($score > $bestScore) {
                $bestScore = $score;
                $best = $bucket;
            }
        }
        if ($best === null) {
            return self::FALLBACK_COLOR;
        }
        $r = (int)round($best['r'] / $best['n']);
        $g = (int)round($best['g'] / $best['n']);
        $b = (int)round($best['b'] / $best['n']);
        return sprintf('#%02X%02X%02X', $r, $g, $b);
    }

    /**
     * Delete the three rendition files of one art_key (best effort).
     * $key is the stored relative prefix, always server-generated.
     */
    private static function unlinkArtFiles(string $root, string $key): void
    {
        $key = trim($key);
        if ($key === '' || str_contains($key, '..')) {
            return;
        }
        foreach (self::RENDITIONS as $px) {
            @unlink($root . '/' . $key . '_' . $px . '.jpg');
        }
        // Drop the per-hymn folder when it is now empty (uploads stay tidy
        // across thousands of hymns and many replacements).
        $dir = dirname($root . '/' . $key . '_640.jpg');
        if (is_dir($dir)) {
            $left = @scandir($dir);
            if (is_array($left) && count($left) <= 2) {
                @rmdir($dir);
            }
        }
    }

    /**
     * Images-only .htaccess for the art root (defense in depth — the
     * uploads/ tree already denies execution; this repeats it locally
     * exactly like taxonomyImageStore does for its directories).
     */
    private static function ensureImagesOnlyHtaccess(string $dir): void
    {
        $ht = $dir . '/.htaccess';
        if (file_exists($ht)) {
            return;
        }
        @file_put_contents(
            $ht,
            "# Hymn art — images only, never execute anything from here\n"
            . "Options -ExecCGI -Indexes\n"
            . "<FilesMatch \"\\.(php|phtml|phar|cgi|pl|py|sh|htaccess)$\">\n"
            . "  <IfModule mod_authz_core.c>\n    Require all denied\n  </IfModule>\n"
            . "  <IfModule !mod_authz_core.c>\n    Order Allow,Deny\n    Deny from all\n  </IfModule>\n"
            . "</FilesMatch>\n"
            . "# Renditions are immutable per URL (?v= version tag) — cache hard.\n"
            . "<IfModule mod_headers.c>\n"
            . "  <FilesMatch \"\\.(jpg|jpeg|png)$\">\n"
            . "    Header set Cache-Control \"public, max-age=31536000\"\n"
            . "  </FilesMatch>\n"
            . "</IfModule>\n"
        );
    }
}
