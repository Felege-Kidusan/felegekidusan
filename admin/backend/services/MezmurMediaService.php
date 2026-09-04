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
     *
     * This is the long-lived URL the mobile app caches. The WEB
     * console must NOT depend on it for playback: upload/confirm
     * talk to the signed R2 API endpoint, but this hostname is a
     * custom domain that is often not wired yet — which is exactly
     * why the HTML5 player showed 0:00/0:00 on a "Ready" hymn.
     */
    public static function publicUrl(string $key): string
    {
        $key = trim($key);
        if ($key === '') return '';
        $base = self::publicBase();
        if ($base === '') return '';
        return $base . '/' . ltrim($key, '/');
    }

    /**
     * Short-lived signed GET against the R2 S3 API endpoint — the
     * same host confirmUpload() already HEADs successfully. Browser
     * <audio> does not need CORS for a media element src; Range is
     * an unsigned extra header so seeking still works.
     */
    public static function signedGetUrl(string $key, int $expiresSeconds = 3600): string
    {
        $key = trim($key);
        if ($key === '') return '';
        return self::presign('GET', $key, $expiresSeconds);
    }

    /**
     * Fresh playback URL for a READY hymn. Always a signed GET so
     * the web console plays as soon as upload+confirm succeed, even
     * when MEZMUR_MEDIA_PUBLIC_BASE is missing or the custom domain
     * is not public.
     *
     * @return array{ok:bool,message:string,url?:string,content_type?:string,expires_in?:int}
     */
    public static function playUrl(\mysqli $conn, int $hymnId): array
    {
        if (!self::isConfigured()) {
            return ['ok' => false, 'message' => self::notConfiguredMessage()];
        }
        if ($hymnId <= 0) {
            return ['ok' => false, 'message' => 'A hymn id is required.'];
        }
        if (!self::audioColumnsReady($conn)) {
            return ['ok' => false, 'message' => 'Audio columns are missing. Run sql/038_mezmur_audio_media.sql, or press Sync DB schema in the Mezmur console.'];
        }

        $stmt = $conn->prepare(
            'SELECT audio_key, audio_status, audio_format FROM mezmur_hymns WHERE id = ? LIMIT 1'
        );
        if (!$stmt) {
            return ['ok' => false, 'message' => 'Could not look up the hymn audio.'];
        }
        $stmt->bind_param('i', $hymnId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$row) {
            return ['ok' => false, 'message' => 'Hymn not found.'];
        }
        $status = (string)($row['audio_status'] ?? 'none');
        $key = trim((string)($row['audio_key'] ?? ''));
        if ($status !== 'ready' || $key === '') {
            return ['ok' => false, 'message' => 'This hymn has no playable audio yet.'];
        }

        $ext = strtolower((string)($row['audio_format'] ?? ''));
        $expires = 3600;
        $url = self::signedGetUrl($key, $expires);
        if ($url === '') {
            return ['ok' => false, 'message' => 'Could not sign a playback URL. Check the MEZMUR_MEDIA_* settings.'];
        }
        return [
            'ok' => true,
            'message' => 'Stream ready.',
            'url' => $url,
            'content_type' => self::contentTypeFor($ext !== '' ? $ext : 'm4a'),
            'expires_in' => $expires,
        ];
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
     * S3 SigV4 presign signs the host header + the query — browsers and
     * players can then call the URL directly without holding any secret.
     *
     * Uploads ALSO sign content-type and content-length. Without them the
     * signature constrained nothing but the key: a client could reserve a
     * 1-byte slot and PUT 5 MB, or store text/html under an .mp3 key on
     * the public media domain. The caller must send EXACTLY these two
     * header values or storage rejects the PUT.
     *
     * @param array<string,string> $extraHeaders header-name => value to sign
     */
    private static function presign(string $method, string $key, int $expiresSeconds, array $extraHeaders = []): string
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

        // host is always signed; the extras ride along when present.
        $signed = ['host' => $host];
        foreach ($extraHeaders as $name => $value) {
            $name = strtolower(trim((string)$name));
            if ($name === '' || $value === '') { continue; }
            $signed[$name] = $value;
        }
        ksort($signed, SORT_STRING);
        $signedHeaders = implode(';', array_keys($signed));
        $query['X-Amz-SignedHeaders'] = $signedHeaders;

        ksort($query, SORT_STRING);
        $canonicalQuery = implode('&', array_map(
            static fn ($k, $v) => self::rfc3986($k) . '=' . self::rfc3986($v),
            array_keys($query),
            array_values($query)
        ));

        $canonicalHeaders = '';
        foreach ($signed as $name => $value) {
            $canonicalHeaders .= $name . ':' . trim((string)$value) . "\n";
        }
        $canonicalRequest = $method . "\n"
            . $canonicalUri . "\n"
            . $canonicalQuery . "\n"
            . $canonicalHeaders . "\n"
            . $signedHeaders . "\n"
            . 'UNSIGNED-PAYLOAD'; // presigned bodies are unsigned by design

        $stringToSign = "AWS4-HMAC-SHA256\n"
            . $now . "\n"
            . $scope . "\n"
            . hash('sha256', $canonicalRequest);

        $signature = hash_hmac('sha256', $stringToSign, self::signingKey($secret, $date));

        return self::endpoint() . $canonicalUri . '?' . $canonicalQuery . '&X-Amz-Signature=' . $signature;
    }

    /**
     * The canonical Content-Type for an allowed extension. Chosen HERE,
     * never taken from the client — the declared type is what the media
     * domain will serve, so letting the uploader pick it is how an .mp3
     * ends up served as text/html.
     */
    private static function contentTypeFor(string $ext): string
    {
        $map = [
            'mp3'  => 'audio/mpeg',
            'm4a'  => 'audio/mp4',
            'aac'  => 'audio/aac',
            'ogg'  => 'audio/ogg',
            'opus' => 'audio/ogg',
            'wav'  => 'audio/wav',
        ];
        return $map[$ext] ?? 'application/octet-stream';
    }

    /**
     * Minimal HTTPS client (cURL when present, stream wrapper
     * fallback). Returns [status, headers(lowercased map), body].
     *
     * The header map is what lets confirmUpload() compare the object's
     * real Content-Length against the size the client declared — without
     * it the two-phase upload verified existence only, and a client could
     * reserve 1 byte and store 5 MB (see the audit, finding F3).
     */
    private static function http(string $method, string $url): array
    {
        $headers = ['Expect:'];
        $responseHeaders = [];
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_CUSTOMREQUEST => $method,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_TIMEOUT => 30,
                CURLOPT_CONNECTTIMEOUT => 15,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_HEADER => true,
                // A HEAD has no body, so the "headers" arrive where the
                // body would be; keep them either way and parse both.
                CURLOPT_NOBODY => ($method === 'HEAD'),
            ]);
            $raw = (string)curl_exec($ch);
            $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $headerSize = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE);
            curl_close($ch);
            $headBlock = $headerSize > 0 ? substr($raw, 0, $headerSize) : $raw;
            $body = $headerSize > 0 ? substr($raw, $headerSize) : '';
            return [
                'status' => $status,
                'headers' => self::parseHeaders($headBlock),
                'body' => (string)$body,
            ];
        }
        $ctx = stream_context_create(['http' => [
            'method' => $method,
            'header' => implode("\r\n", $headers) . "\r\n",
            'ignore_errors' => true,
            'timeout' => 30,
        ]]);
        $body = @file_get_contents($url, false, $ctx);
        $status = 0;
        $rawHeaders = $http_response_header ?? [];
        foreach ($rawHeaders as $line) {
            if (preg_match('#^HTTP/\S+\s+(\d+)#', $line, $m)) { $status = (int)$m[1]; }
        }
        return [
            'status' => $status,
            'headers' => self::parseHeaders(implode("\r\n", $rawHeaders)),
            'body' => (string)$body,
        ];
    }

    /** Fold a raw header block into a lowercased name => value map. */
    private static function parseHeaders(string $block): array
    {
        $out = [];
        foreach (preg_split("/\r\n|\n|\r/", $block) ?: [] as $line) {
            if (strpos($line, ':') === false) { continue; }
            [$name, $value] = explode(':', $line, 2);
            $out[strtolower(trim($name))] = trim($value);
        }
        return $out;
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
            return ['ok' => false, 'message' => 'Audio columns are missing. Run sql/038_mezmur_audio_media.sql, or press Sync DB schema in the Mezmur console (the reconciler adds them).'];
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

        $stmt = $conn->prepare('SELECT id, audio_key FROM mezmur_hymns WHERE id = ? LIMIT 1');
        $stmt->bind_param('i', $hymnId);
        $stmt->execute();
        $exists = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$exists) {
            return ['ok' => false, 'message' => 'Hymn not found.'];
        }

        // Retire the outgoing object BEFORE reserving a new key. Without
        // this every replace (and every re-presign after a stalled upload)
        // left the previous file on storage with no row pointing at it.
        $previousKey = trim((string)($exists['audio_key'] ?? ''));
        if ($previousKey !== '') {
            self::http('DELETE', self::presign('DELETE', $previousKey, 120));
        }

        $key = self::keyFor($hymnId, $ext);
        $contentType = self::contentTypeFor($ext);
        // content-type + content-length are SIGNED, so storage will reject
        // a PUT whose size or type differs from what was reserved.
        $uploadUrl = self::presign('PUT', $key, 900, [
            'content-type' => $contentType,
            'content-length' => (string)$size,
        ]); // 15-minute upload window

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
        return [
            'ok' => true,
            'message' => 'Signed upload ready.',
            'upload_url' => $uploadUrl,
            'key' => $key,
            'expires_in' => 900,
            // the client MUST send these verbatim or storage rejects the PUT
            'content_type' => $contentType,
            'content_length' => $size,
        ];
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

        // Existence is not enough: the reserved size is the contract. A
        // mismatch means the object is not the file the row describes, so
        // it is rejected rather than promoted to `ready`. (Signing
        // content-length at presign time already prevents the common
        // case; this catches a stale object or a hand-made upload.)
        $reserved = (int)($row['audio_size'] ?? 0);
        $actualRaw = $head['headers']['content-length'] ?? '';
        if ($reserved > 0 && $actualRaw !== '' && (int)$actualRaw !== $reserved) {
            $stmt = $conn->prepare(
                "UPDATE mezmur_hymns
                 SET audio_status='rejected', audio_updated_at=NOW(),
                     updated_by=?, updated_at=NOW(), revision = revision + 1
                 WHERE id=?"
            );
            $stmt->bind_param('ii', $actorId, $hymnId);
            $stmt->execute();
            $stmt->close();
            self::audit($conn, 'Mezmur Audio Upload Rejected', [
                'key' => (string)$row['audio_key'],
                'reserved_bytes' => $reserved,
                'actual_bytes' => (int)$actualRaw,
            ], $hymnId, $actorId);
            return ['ok' => false, 'message' => 'The file on storage is ' . (int)$actualRaw
                . ' bytes but ' . $reserved . ' were reserved. Upload the file again.'];
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
            return ['ok' => false, 'message' => 'Audio columns are missing. Run sql/038_mezmur_audio_media.sql, or press Sync DB schema in the Mezmur console.'];
        }
        // The `audio_duration_s <> ?` guard matters: the console fires
        // this on EVERY audio-modal open, and a revision bump pushes a
        // delta row to every device in the fleet. Re-measuring the same
        // duration must be a no-op, not a fleet-wide resync.
        $stmt = $conn->prepare(
            "UPDATE mezmur_hymns
             SET audio_duration_s=?, updated_by=?, updated_at=NOW(), revision = revision + 1
             WHERE id=? AND audio_status='ready'
               AND (audio_duration_s IS NULL OR audio_duration_s <> ?)"
        );
        $stmt->bind_param('iiii', $durationSeconds, $actorId, $hymnId, $durationSeconds);
        $stmt->execute();
        $changed = $stmt->affected_rows;
        $stmt->close();
        if ($changed === 0) {
            return ['ok' => true, 'message' => 'Duration unchanged.', 'changed' => false];
        }
        return ['ok' => true, 'message' => 'Duration saved.', 'changed' => true];
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
    /**
     * Canonicalize an LRC document — pure, DB-free, unit-testable.
     *
     * Industry practice for synced-lyrics ingestion (Mutagen / ffmpeg
     * lrc writers, streaming services' timed-text pipelines): normalize
     * ON WRITE so every reader — web console, the Flutter parser, future
     * integrations — sees exactly one dialect:
     *   - ONE [mm:ss.mmm] stamp per line; bracket runs such as
     *     [00:01.00][00:09.00]text are EXPANDED to two lines, never
     *     silently folded into the text or dropped,
     *   - lines sorted by timestamp (stable on input order for ties),
     *   - millisecond fraction always 3 digits,
     *   - metadata headers ([ti:]/[ar:]/[offset:]…) preserved up top.
     * Hand-made quirks are repaired, not misparsed: [00:75] becomes
     * [01:15.000], .5 becomes .500, out-of-order lines are sorted.
     * What cannot be repaired is rejected loudly ([Section] markup,
     * hour-format stamps) — silent loss is the bug this fixes.
     *
     * @return array{ok:bool,message?:string,doc?:string,timed?:int}
     */
    public static function canonicalizeLrc(string $lrc): array
    {
        $lines = preg_split('/\r\n|\r|\n/', trim($lrc)) ?: [];
        $headers = [];
        $entries = []; // [ms, text, inputSeq]
        $seq = 0;
        foreach ($lines as $raw) {
            $line = trim($raw);
            if ($line === '') continue;
            if (preg_match('/^\[(ti|ar|al|by|offset|length|re|ve):[^\]]*\]$/', $line)) {
                $headers[] = $line;
                continue;
            }
            if (!preg_match('/^((?:\[\d{1,2}:\d{2}(?:\.\d{1,3})?\])+)\s*(.*)$/s', $line, $m)) {
                return ['ok' => false, 'message' => 'Synced lyrics contain a line that is not a timestamp line (e.g. [Section] markup is not allowed here).'];
            }
            $text = trim($m[2]);
            preg_match_all('/\[(\d{1,2}):(\d{2})(?:\.(\d{1,3}))?\]/', $m[1], $st, PREG_SET_ORDER);
            foreach ($st as $s) {
                $ms = ((int)$s[1] * 60000) + ((int)$s[2] * 1000) + (int)str_pad($s[3] ?? '0', 3, '0', STR_PAD_RIGHT);
                $entries[] = [$ms, $text, $seq++];
            }
            if (count($entries) > 20000) {
                return ['ok' => false, 'message' => 'Synced lyrics have too many timed lines.'];
            }
        }
        if (!$entries) {
            return ['ok' => false, 'message' => 'Synced lyrics need at least one timed line ([mm:ss.xx]text).'];
        }
        usort($entries, static fn ($a, $b) => $a[0] <=> $b[0] ?: $a[2] <=> $b[2]);
        $body = [];
        foreach ($entries as $e) {
            $body[] = sprintf('[%02d:%02d.%03d] %s', intdiv($e[0], 60000), intdiv($e[0] % 60000, 1000), $e[0] % 1000, $e[1]);
        }
        $doc = ($headers ? implode("\n", $headers) . "\n" : '') . implode("\n", $body);
        return ['ok' => true, 'doc' => $doc, 'timed' => count($entries)];
    }

    public static function saveSyncedLyrics(\mysqli $conn, int $hymnId, string $lrc, int $actorId): array
    {
        if (!self::audioColumnsReady($conn)) {
            return ['ok' => false, 'message' => 'Audio columns are missing. Run sql/038_mezmur_audio_media.sql, or press Sync DB schema in the Mezmur console.'];
        }
        $lrc = (string)$lrc;
        if (strlen($lrc) > 262144) {
            return ['ok' => false, 'message' => 'Synced lyrics text is too long.'];
        }
        if (function_exists('mb_check_encoding') && !mb_check_encoding($lrc, 'UTF-8')) {
            return ['ok' => false, 'message' => 'Synced lyrics must be valid UTF-8 text.'];
        }
        // F5 hardening: validate AND canonicalize in one pure step so the
        // stored document is the single dialect every reader agrees on.
        $canon = self::canonicalizeLrc($lrc);
        if (empty($canon['ok'])) {
            return ['ok' => false, 'message' => $canon['message']];
        }
        $lrc = (string)$canon['doc'];
        $timed = (int)$canon['timed'];

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
            return ['ok' => false, 'message' => 'Audio columns are missing. Run sql/038_mezmur_audio_media.sql, or press Sync DB schema in the Mezmur console.'];
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
