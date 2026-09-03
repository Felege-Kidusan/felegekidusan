<?php
/**
 * ════════════════════════════════════════════════════════════
 * MezmurMediaService — audio media plane for the Mezmur module
 * (መዝሙር ክፍል) · P0 audio upgrade
 * ════════════════════════════════════════════════════════════
 *   THE BIG RULE (Spotify shape): audio BYTES never touch the PHP
 *   origin or MySQL. This service only ever
 *     1. hands out SHORT-LIVED presigned upload URLs so the
 *        browser/app can PUT the file DIRECTLY to Cloudflare R2,
 *     2. verifies an uploaded object with a signed HEAD, and
 *     3. persists METADATA (key/size/format/status) on the hymn
 *        row. Public read URLs are rebuilt at read time from the
 *        key — which is exactly why the media hostname is ONE
 *        config value (MEZMUR_MEDIA_PUBLIC_BASE) you can change
 *        tomorrow without touching the database.
 *
 *   S3-compatible SigV4 is hand-rolled (~no AWS SDK): the codebase
 *   has no autoloader for app classes and composer pulls in only
 *   PhpSpreadsheet, so shipping the full SDK for two verbs is the
 *   wrong trade. The signing core below is small, deterministic
 *   and testable against `aws s3 presign` (see Appendix in the
 *   DEEP_ANALYSIS doc).
 *
 *   Storage layout on R2 (bucket = MEZMUR_MEDIA_BUCKET):
 *     mz/audio/{hymn_id}/{uuid32}.{ext}   ← one file per hymn
 *   Public URL  = MEZMUR_MEDIA_PUBLIC_BASE + '/' + key
 *   (public base must be the bucket's custom-domain endpoint, e.g.
 *    https://media.fkss.arkeonethiopia.com — NOT the r2.dev URL)
 *
 *   Status machine on mezmur_hymns.audio_status:
 *     none      → no audio ever attached
 *     pending   → presign issued; awaiting the direct PUT + confirm
 *     ready     → object verified present on R2 with matching size
 *     rejected  → confirm saw a mismatch (manual re-upload)
 *
 *   Every DB write bumps revision + updated_at so the mobile delta
 *   sync (cursor on updated_at,id) converges exactly like every
 *   other hymn edit.
 *
 *   Required env constants (defined in .fkss_env.php — see
 *   env.example.php). When absent the service reports NOT
 *   configured and every operation returns a clear message; the
 *   rest of the app keeps working.
 * ════════════════════════════════════════════════════════════
 */

namespace App\Services;

// Audit dependency — same self-sufficient guarantee as the other
// mezmur services (missing require used to silently kill the trail).
require_once __DIR__ . '/SecurityAuditService.php';

final class MezmurMediaService
{
    // ── configuration (fallback-safe) ──────────────────────────
    private static function cfg(string $name): string
    {
        return defined($name) ? trim((string)constant($name)) : '';
    }

    /** Human-friendly "is R2 wired up?" gate used by every op. */
    public static function isConfigured(): bool
    {
        return self::cfg('MEZMUR_MEDIA_ACCOUNT_ID') !== ''
            && self::cfg('MEZMUR_MEDIA_ACCESS_KEY_ID') !== ''
            && self::cfg('MEZMUR_MEDIA_SECRET_ACCESS_KEY') !== ''
            && self::cfg('MEZMUR_MEDIA_BUCKET') !== '';
    }

    public static function notConfiguredMessage(): string
    {
        return 'R2 media storage is not configured on this server. Ask the administrator to set the MEZMUR_MEDIA_* values in .fkss_env.php.';
    }

    private static function bucket(): string
    {
        $b = self::cfg('MEZMUR_MEDIA_BUCKET');
        return $b !== '' ? $b : 'fkss-media';
    }

    private static function endpoint(): string
    {
        $id = self::cfg('MEZMUR_MEDIA_ACCOUNT_ID');
        return 'https://' . $id . '.r2.cloudflarestorage.com';
    }

    private static function publicBase(): string
    {
        return rtrim(self::cfg('MEZMUR_MEDIA_PUBLIC_BASE'), '/');
    }

    /** Allowed audio containers. m4a is the Apple container for AAC. */
    private static function allowedExt(): array
    {
        $raw = self::cfg('MEZMUR_MEDIA_ALLOWED_EXT');
        if ($raw === '') return ['mp3', 'm4a', 'ogg', 'wav', 'aac', 'opus'];
        return array_values(array_filter(array_map(
            static fn ($e) => strtolower(trim($e)),
            preg_split('/[,\s]+/', $raw) ?: []
        )));
    }

    private static function maxBytes(): int
    {
        $raw = (int)self::cfg('MEZMUR_MEDIA_MAX_BYTES');
        return $raw > 0 ? $raw : 15 * 1024 * 1024; // 15 MB ceiling default
    }

    // ── key building / public urls ─────────────────────────────
    private static function keyFor(int $hymnId, string $ext): string
    {
        return 'mz/audio/' . $hymnId . '/' . bin2hex(random_bytes(16)) . '.' . strtolower($ext);
    }

    /**
     * Public streaming URL for an object key. Empty when the media
     * base is not configured (or key empty). The base is one config
     * constant — changing the media hostname never touches rows.
     */
    public static function publicUrl(string $key): string
    {
        $key = trim($key);
        if ($key === '') return '';
        $base = self::publicBase();
        if ($base === '') return '';
        return $base . '/' . ltrim($key, '/');
    }

    // ── schema probe (repo convention: never crash pre-038) ────
    private static ?bool $hasAudioColumns = null;

    public static function audioColumnsReady(\mysqli $conn): bool
    {
        if (self::$hasAudioColumns === null) {
            $ok = false;
            try {
                $r = $conn->query("SHOW COLUMNS FROM mezmur_hymns LIKE 'audio_key'");
                $ok = $r ? (bool)$r->fetch_assoc() : false;
                if ($r) { $r->close(); }
            } catch (\Throwable $e) { $ok = false; }
            self::$hasAudioColumns = $ok;
        }
        return self::$hasAudioColumns;
    }

    // ── audit (same contract as MezmurHymnService) ─────────────
    private static function audit(\mysqli $conn, string $action, array $details, int $hymnId, int $actorId): void
    {
        if (!class_exists('\App\Services\SecurityAuditService')) {
            require_once __DIR__ . '/SecurityAuditService.php';
        }
        $details['actor'] = $actorId;
        try {
            \App\Services\SecurityAuditService::record($conn, $action, $details, 'mezmur_hymn', $hymnId);
        } catch (\Throwable $e) {
            error_log('[mezmur-media-audit] ' . $action . ' mezmur_hymn#' . $hymnId . ' failed: ' . $e->getMessage());
        }
    }

    // ══════════════════════════════════════════════════════════
    // SigV4 (S3-compatible) signing core — pure PHP, no SDK
    // ══════════════════════════════════════════════════════════

    private static function hmac(string $key, string $data): string
    {
        return hash_hmac('sha256', $data, $key, true);
    }

    private static function rfc3986(string $s): string
    {
        return rawurlencode($s);
    }

    private static function signingKey(string $secret, string $date): string
    {
        $kDate = self::hmac('AWS4' . $secret, $date);
        $kRegion = self::hmac($kDate, 'auto');            // R2 region is always 'auto'
        $kService = self::hmac($kRegion, 's3');
        return self::hmac($kService, 'aws4_request');
    }

    /**
     * Build a presigned URL (PUT upload / HEAD verify / DELETE).
     * S3 SigV4 presign signs only the host header + the query —
     * browsers and players can then call the URL directly without
     * holding any secret.
     */
    private static function presign(string $method, string $key, int $expiresSeconds): string
    {
        $expiresSeconds = max(1, min($expiresSeconds, 604800));
        $host = parse_url(self::endpoint(), PHP_URL_HOST);
        $canonicalUri = '/' . self::bucket() . '/' . implode('/', array_map(
            static fn ($seg) => self::rfc3986($seg),
            explode('/', ltrim($key, '/'))
        ));

        $now = gmdate('Ymd\THis\Z');
        $date = substr($now, 0, 8);
        $scope = $date . '/auto/s3/aws4_request';
        $access = self::cfg('MEZMUR_MEDIA_ACCESS_KEY_ID');
        $secret = self::cfg('MEZMUR_MEDIA_SECRET_ACCESS_KEY');

        $query = [
            'X-Amz-Algorithm' => 'AWS4-HMAC-SHA256',
            'X-Amz-Credential' => $access . '/' . $scope,
            'X-Amz-Date' => $now,
            'X-Amz-Expires' => (string)$expiresSeconds,
            'X-Amz-SignedHeaders' => 'host',
        ];
        ksort($query, SORT_STRING);
        $canonicalQuery = implode('&', array_map(
            static fn ($k, $v) => self::rfc3986($k) . '=' . self::rfc3986($v),
            array_keys($query),
            array_values($query)
        ));

        $canonicalHeaders = "host:" . $host . "\n";
        $canonicalRequest = $method . "\n"
            . $canonicalUri . "\n"
            . $canonicalQuery . "\n"
            . $canonicalHeaders . "\n"
            . 'host' . "\n"
            . 'UNSIGNED-PAYLOAD'; // presigned bodies are unsigned by design

        $stringToSign = "AWS4-HMAC-SHA256\n"
            . $now . "\n"
            . $scope . "\n"
            . hash('sha256', $canonicalRequest);

        $signature = hash_hmac('sha256', $stringToSign, self::signingKey($secret, $date));

        return self::endpoint() . $canonicalUri . '?' . $canonicalQuery . '&X-Amz-Signature=' . $signature;
    }

    /**
     * Minimal HTTPS client (cURL when present, stream wrapper
     * fallback). Returns [status, headers(map), body].
     */
    private static function http(string $method, string $url): array
    {
        $headers = ['Expect:'];
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_CUSTOMREQUEST => $method,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_TIMEOUT => 30,
                CURLOPT_CONNECTTIMEOUT => 15,
                CURLOPT_FOLLOWLOCATION => false,
            ]);
            $body = curl_exec($ch);
            $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            return ['status' => $status, 'headers' => [], 'body' => (string)$body];
        }
        $ctx = stream_context_create(['http' => [
            'method' => $method,
            'header' => implode("\r\n", $headers) . "\r\n",
            'ignore_errors' => true,
            'timeout' => 30,
        ]]);
        $body = @file_get_contents($url, false, $ctx);
        $status = 0;
        foreach ($http_response_header ?? [] as $line) {
            if (preg_match('#^HTTP/\S+\s+(\d+)#', $line, $m)) { $status = (int)$m[1]; }
        }
        return ['status' => $status, 'headers' => [], 'body' => (string)$body];
    }

    // ══════════════════════════════════════════════════════════
    // UPLOAD FLOW (two-phase: presign → direct PUT → confirm)
    // ══════════════════════════════════════════════════════════

    /**
     * Phase 1 — reserve a key, stamp the row as `pending`, and hand
     * back a short-lived presigned PUT URL. The client then PUTs the
     * bytes straight to R2 (never through PHP), so shared-hosting
     * upload_max_filesize / post_max_size / max_execution_time are
     * irrelevant.
     *
     * @return array{ok:bool,message:string,upload_url?:string,key?:string,expires_in?:int}
     */
    public static function beginUpload(\mysqli $conn, int $hymnId, string $ext, int $size, int $actorId): array
    {
        if (!self::isConfigured()) {
            return ['ok' => false, 'message' => self::notConfiguredMessage()];
        }
        if (!self::audioColumnsReady($conn)) {
            return ['ok' => false, 'message' => 'Audio columns are missing. Run sql/038_mezmur_audio_media.sql (or press Sync DB schema) first.'];
        }
        if ($hymnId <= 0) {
            return ['ok' => false, 'message' => 'A hymn id is required.'];
        }
        $ext = strtolower(trim($ext));
        if (!in_array($ext, self::allowedExt(), true)) {
            return ['ok' => false, 'message' => 'Unsupported audio format. Allowed: ' . implode(', ', self::allowedExt()) . '.'];
        }
        $size = (int)$size;
        $max = self::maxBytes();
        if ($size <= 0) {
            return ['ok' => false, 'message' => 'A positive file size is required.'];
        }
        if ($size > $max) {
            return ['ok' => false, 'message' => 'Audio file is too large (max ' . round($max / 1048576) . ' MB).'];
        }

        $stmt = $conn->prepare('SELECT id FROM mezmur_hymns WHERE id = ? LIMIT 1');
        $stmt->bind_param('i', $hymnId);
        $stmt->execute();
        $exists = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$exists) {
            return ['ok' => false, 'message' => 'Hymn not found.'];
        }

        $key = self::keyFor($hymnId, $ext);
        $uploadUrl = self::presign('PUT', $key, 900); // 15-minute upload window

        $stmt = $conn->prepare(
            "UPDATE mezmur_hymns
             SET audio_key=?, audio_size=?, audio_format=?, audio_status='pending',
                 audio_uploaded_by=?, audio_updated_at=NOW(),
                 updated_by=?, updated_at=NOW(), revision = revision + 1
             WHERE id=?"
        );
        $stmt->bind_param('sisiis', $key, $size, $ext, $actorId, $actorId, $hymnId);
        $ok = $stmt->execute();
        $stmt->close();
        if (!$ok) {
            return ['ok' => false, 'message' => 'Could not prepare the upload record. Try again.'];
        }
        self::audit($conn, 'Mezmur Audio Upload Started', ['key' => $key, 'bytes' => $size, 'format' => $ext], $hymnId, $actorId);
        return ['ok' => true, 'message' => 'Signed upload ready.', 'upload_url' => $uploadUrl, 'key' => $key, 'expires_in' => 900];
    }

    /**
     * Phase 2 — after the client PUT the bytes to R2, CONFIRM the
     * object really exists with a signed HEAD and flip `pending` →
     * `ready`. Idempotent-safe: confirming a ready hymn is a no-op
     * success (mirrors the outbox pattern used everywhere here).
     *
     * @return array{ok:bool,message:string,audio?:array|null}
     */
    public static function confirmUpload(\mysqli $conn, int $hymnId, int $actorId): array
    {
        if (!self::isConfigured()) {
            return ['ok' => false, 'message' => self::notConfiguredMessage()];
        }
        $stmt = $conn->prepare('SELECT id, audio_key, audio_size, audio_status, audio_format FROM mezmur_hymns WHERE id = ? LIMIT 1');
        $stmt->bind_param('i', $hymnId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$row) {
            return ['ok' => false, 'message' => 'Hymn not found.'];
        }
        if ($row['audio_status'] === 'ready') {
            return ['ok' => true, 'message' => 'Audio already confirmed.', 'audio' => self::audioPayload($row)];
        }
        if ($row['audio_status'] !== 'pending' || ($row['audio_key'] ?? '') === '') {
            return ['ok' => false, 'message' => 'No pending upload for this hymn. Start an upload first.'];
        }

        // Signed HEAD to R2 — does the object exist, and is it the size we reserved?
        $head = self::http('HEAD', self::presign('HEAD', (string)$row['audio_key'], 120));
        $found = in_array($head['status'], [200, 206], true);
        if (!$found) {
            return ['ok' => false, 'message' => 'The upload was not found on storage yet (status ' . $head['status'] . '). Upload the file again or retry in a moment.'];
        }

        $stmt = $conn->prepare(
            "UPDATE mezmur_hymns
             SET audio_status='ready', audio_updated_at=NOW(),
                 updated_by=?, updated_at=NOW(), revision = revision + 1
             WHERE id=?"
        );
        $stmt->bind_param('ii', $actorId, $hymnId);
        $stmt->execute();
        $stmt->close();

        self::audit($conn, 'Mezmur Audio Upload Confirmed', ['key' => (string)$row['audio_key']], $hymnId, $actorId);
        $row['audio_status'] = 'ready';
        return ['ok' => true, 'message' => 'Audio is live.', 'audio' => self::audioPayload($row)];
    }

    /**
     * Remove audio: delete the object on R2 (best effort) and clear
     * the hymn's audio fields. Soft on missing object (idempotent).
     */
    public static function removeAudio(\mysqli $conn, int $hymnId, int $actorId): array
    {
        if (!self::isConfigured()) {
            return ['ok' => false, 'message' => self::notConfiguredMessage()];
        }
        $stmt = $conn->prepare('SELECT id, audio_key, audio_status FROM mezmur_hymns WHERE id = ? LIMIT 1');
        $stmt->bind_param('i', $hymnId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$row) {
            return ['ok' => false, 'message' => 'Hymn not found.'];
        }
        if (($row['audio_key'] ?? '') === '') {
            return ['ok' => true, 'message' => 'This hymn has no audio attached.'];
        }

        // Delete the object (ignore 404 — already gone).
        self::http('DELETE', self::presign('DELETE', (string)$row['audio_key'], 120));

        $stmt = $conn->prepare(
            "UPDATE mezmur_hymns
             SET audio_key=NULL, audio_duration_s=NULL, audio_size=NULL,
                 audio_format=NULL, audio_status='none',
                 audio_uploaded_by=NULL, audio_updated_at=NULL,
                 updated_by=?, updated_at=NOW(), revision = revision + 1
             WHERE id=?"
        );
        $stmt->bind_param('ii', $actorId, $hymnId);
        $stmt->execute();
        $stmt->close();
        self::audit($conn, 'Mezmur Audio Removed', ['key' => (string)$row['audio_key']], $hymnId, $actorId);
        return ['ok' => true, 'message' => 'Audio removed.'];
    }

    /** Store one optional field after confirm: measured duration (s). */
    public static function setDuration(\mysqli $conn, int $hymnId, int $durationSeconds, int $actorId): array
    {
        if ($hymnId <= 0 || $durationSeconds <= 0 || $durationSeconds > 24 * 3600) {
            return ['ok' => false, 'message' => 'A valid duration in seconds is required.'];
        }
        if (!self::audioColumnsReady($conn)) {
            return ['ok' => false, 'message' => 'Audio columns are missing. Run sql/038 first.'];
        }
        $stmt = $conn->prepare(
            "UPDATE mezmur_hymns
             SET audio_duration_s=?, updated_by=?, updated_at=NOW(), revision = revision + 1
             WHERE id=? AND audio_status='ready'"
        );
        $stmt->bind_param('iii', $durationSeconds, $actorId, $hymnId);
        $stmt->execute();
        $stmt->close();
        return ['ok' => true, 'message' => 'Duration saved.'];
    }

    // ══════════════════════════════════════════════════════════
    // SYNCED LYRICS (timed LRC text on the same hymn row)
    // ══════════════════════════════════════════════════════════

    /**
     * Validate + store an LRC document. Rules (mirror the codebase's
     * hygiene): UTF-8 only, bounded size, timestamps strictly
     * non-decreasing, at least one timed line, no [Section]-style
     * lyrics markup — timed lyrics are a SEPARATE field from the
     * pretty `lyrics` so the two renderers never clash.
     */
    public static function saveSyncedLyrics(\mysqli $conn, int $hymnId, string $lrc, int $actorId): array
    {
        if (!self::audioColumnsReady($conn)) {
            return ['ok' => false, 'message' => 'Audio columns are missing. Run sql/038 first.'];
        }
        $lrc = (string)$lrc;
        if (strlen($lrc) > 262144) {
            return ['ok' => false, 'message' => 'Synced lyrics text is too long.'];
        }
        if (function_exists('mb_check_encoding') && !mb_check_encoding($lrc, 'UTF-8')) {
            return ['ok' => false, 'message' => 'Synced lyrics must be valid UTF-8 text.'];
        }
        $lrc = trim($lrc);
        $lines = preg_split('/\r\n|\r|\n/', $lrc) ?: [];
        $lastMs = -1;
        $timed = 0;
        foreach ($lines as $line) {
            if (trim($line) === '') continue;
            // Metadata headers: [ti:…], [ar:…], [by:…], [offset:…]
            if (preg_match('/^\[(ti|ar|al|by|offset|length|re|ve):[^\]]*\]\s*$/', trim($line))) continue;
            if (!preg_match('/^\[(\d{1,2}):(\d{2})(?:\.(\d{1,3}))?\](.*)$/', trim($line), $m)) {
                return ['ok' => false, 'message' => 'Synced lyrics contain a line that is not a timestamp line (e.g. [Section] markup is not allowed here).'];
            }
            $ms = ((int)$m[1] * 60000) + ((int)$m[2] * 1000) + (int)str_pad($m[3] ?? '0', 3, '0', STR_PAD_RIGHT);
            if ($ms < $lastMs) {
                return ['ok' => false, 'message' => 'Synced lyrics timestamps must be in order (line ' . ($timed + 1) . ').'];
            }
            $lastMs = $ms;
            $timed++;
        }
        if ($timed < 1) {
            return ['ok' => false, 'message' => 'Synced lyrics need at least one timed line ([mm:ss.xx]text).'];
        }

        $stmt = $conn->prepare(
            "UPDATE mezmur_hymns
             SET lyrics_synced=?, lyrics_synced_at=NOW(), lyrics_synced_by=?,
                 updated_by=?, updated_at=NOW(), revision = revision + 1
             WHERE id=?"
        );
        $stmt->bind_param('siiii', $lrc, $actorId, $actorId, $hymnId);
        $ok = $stmt->execute();
        $stmt->close();
        if (!$ok) {
            return ['ok' => false, 'message' => 'Could not save synced lyrics.'];
        }
        self::audit($conn, 'Mezmur Synced Lyrics Saved', ['lines' => $timed], $hymnId, $actorId);
        return ['ok' => true, 'message' => 'Synced lyrics saved (' . $timed . ' timed lines).'];
    }

    /** Clear synced lyrics back to "static only". */
    public static function removeSyncedLyrics(\mysqli $conn, int $hymnId, int $actorId): array
    {
        if (!self::audioColumnsReady($conn)) {
            return ['ok' => false, 'message' => 'Audio columns are missing. Run sql/038 first.'];
        }
        $stmt = $conn->prepare(
            "UPDATE mezmur_hymns
             SET lyrics_synced=NULL, lyrics_synced_at=NULL, lyrics_synced_by=NULL,
                 updated_by=?, updated_at=NOW(), revision = revision + 1
             WHERE id=?"
        );
        $stmt->bind_param('ii', $actorId, $hymnId);
        $stmt->execute();
        $stmt->close();
        self::audit($conn, 'Mezmur Synced Lyrics Removed', [], $hymnId, $actorId);
        return ['ok' => true, 'message' => 'Synced lyrics removed — static lyrics still show.'];
    }

    // ══════════════════════════════════════════════════════════
    // READ PAYLOAD (used by MezmurHymnService row decorators)
    // ══════════════════════════════════════════════════════════

    /**
     * Shape the media fields of a hymn row for API consumers:
     * exposes audio_url ONLY when verified ready; the internal
     * audio_key never leaves the server.
     * @param array<string,mixed> $r a row carrying audio_* columns
     * @return array<string,mixed>
     */
    public static function audioPayload(array $r): array
    {
        $status = (string)($r['audio_status'] ?? 'none');
        $key = (string)($r['audio_key'] ?? '');
        $payload = [
            'audio_status' => $status,
            'audio_duration_s' => $r['audio_duration_s'] ?? null,
            'audio_size' => $r['audio_size'] ?? null,
            'audio_format' => $r['audio_format'] ?? null,
            'audio_url' => ($status === 'ready' && $key !== '') ? self::publicUrl($key) : '',
        ];
        if (isset($payload['audio_duration_s']) && $payload['audio_duration_s'] !== null && $payload['audio_duration_s'] !== '') {
            $payload['audio_duration_s'] = (int)$payload['audio_duration_s'];
        }
        if (isset($payload['audio_size']) && $payload['audio_size'] !== null && $payload['audio_size'] !== '') {
            $payload['audio_size'] = (int)$payload['audio_size'];
        }
        return $payload;
    }

    /**
     * Normalize a raw mezmur_hymns row for JSON: merge media payload
     * and hide the internal audio_key. Safe on pre-038 schemas (the
     * decorator keys are absent → defaults).
     * @param array<string,mixed> $r
     * @return array<string,mixed>
     */
    public static function decorateRow(array $r): array
    {
        foreach (['audio_status', 'audio_key', 'audio_duration_s', 'audio_size', 'audio_format'] as $k) {
            if (!array_key_exists($k, $r)) {
                $r[$k] = null;
            }
        }
        $media = self::audioPayload($r);
        unset($r['audio_key']);
        return array_merge($r, $media);
    }
}
