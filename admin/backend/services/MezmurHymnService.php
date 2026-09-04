<?php
/**
 * ════════════════════════════════════════════════════════════
 * MezmurHymnService — read-only hymn access for the mobile API
 * (መዝሙር ክፍል). The web dashboard keeps its writer inline in
 * admin/api_mezmur.php; mobile consumes these readers.
 * ════════════════════════════════════════════════════════════
 *   - Server-side pagination + clamped page sizes (scale-safe).
 *   - LIKE-inputs escaped; every query prepared.
 *   - No PII in hymn rows; lyrics returned verbatim for the
 *     single-hymn reader only.
 * ════════════════════════════════════════════════════════════
 */

namespace App\Services;

// MZ-1 hardening: every mutation this service commits is audited, so the
// service must be SELF-SUFFICIENT about its audit dependency — the codebase
// has no autoloader and a missing require in any entry point previously
// silenced the whole trail (class-not-found was swallowed by the fail-soft
// catch). The web controller and the mobile route also declare this
// dependency explicitly (defense in depth), but the writer itself is the
// guarantee — Google's admin-activity model: audit is a property of the
// writer, not a courtesy of the caller.
require_once __DIR__ . '/SecurityAuditService.php';
require_once __DIR__ . '/MezmurMediaService.php';

final class MezmurHymnService
{
    private static function clampPerPage(int $perPage): int
    {
        return $perPage < 1 ? 25 : min($perPage, 100);
    }

    private static function escapeLike(string $v): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $v);
    }

    /**
     * Paginated hymn list for the app (library browser).
     * @return array{items:list<array>,total:int,page:int,total_pages:int,categories:list<string>}
     */
    public static function listHymns(\mysqli $conn, array $filters): array
    {
        $page = max(1, (int)($filters['page'] ?? 1));
        $perPage = self::clampPerPage((int)($filters['per_page'] ?? 25));
        $search = trim((string)($filters['search'] ?? ''));
        // Keystroke hygiene (Telegram/Google parity): single-character
        // queries are ignored server-side too — a '%x%' LIKE scan cannot
        // use an index, so one keystroke must never reach the database.
        if (mb_strlen($search) < 2) {
            $search = '';
        }
        $category = mb_substr(trim((string)($filters['category'] ?? '')), 0, 50);
        $status = in_array($filters['status'] ?? 'active', ['active', 'archived', ''], true)
            ? ($filters['status'] ?? 'active') : 'active';
        $length = in_array($filters['length'] ?? '', ['long', 'short'], true)
            ? (string)($filters['length'] ?? '') : '';
        $language = in_array($filters['language'] ?? '', ['geez', 'amharic'], true)
            ? (string)($filters['language'] ?? '') : '';
        $categoryId = max(0, (int)($filters['category_id'] ?? 0));
        $zemarianId = max(0, (int)($filters['zemarian_id'] ?? 0));

        // Structural filters only — the text condition is kept SEPARATE so
        // the fuzzy-rescue pass can reuse the filters without it.
        $where = [];
        $types = '';
        $params = [];
        if ($status !== '') {
            $where[] = "status = ?";
            $types .= 's';
            $params[] = $status;
        }
        if ($category !== '') {
            // MZ-4: join-aware name filter — a hymn linked to several
            // categories must be findable by EVERY label it carries, not
            // only the first one (which is all the legacy mirror string
            // stores). The plain string compare stays for read-backward
            // compatibility ('general' rows carry no join rows).
            $where[] = "(category = ? OR EXISTS (SELECT 1 FROM mezmur_hymn_categories mhc JOIN mezmur_categories mc ON mc.id = mhc.category_id WHERE mhc.hymn_id = mezmur_hymns.id AND mc.name = ?))";
            $types .= 'ss';
            $params[] = $category;
            $params[] = $category;
        }
        if ($length !== '') {
            $where[] = "length = ?";
            $types .= 's';
            $params[] = $length;
        }
        if ($language !== '') {
            $where[] = "language = ?";
            $types .= 's';
            $params[] = $language;
        }
        if ($categoryId > 0) {
            // P30 two-level taxonomy: filtering by a MAIN category rolls
            // up — the hymn matches when linked to the category itself
            // OR to any of its sub-categories (indexed join, one level).
            $where[] = "EXISTS (SELECT 1 FROM mezmur_hymn_categories mhc JOIN mezmur_categories mc2 ON mc2.id = mhc.category_id WHERE mhc.hymn_id = mezmur_hymns.id AND (mc2.id = ? OR mc2.parent_id = ?))";
            $types .= 'ii';
            $params[] = $categoryId;
            $params[] = $categoryId;
        }
        if ($zemarianId > 0) {
            $where[] = "EXISTS (SELECT 1 FROM mezmur_hymn_zemarians mhz WHERE mhz.hymn_id = mezmur_hymns.id AND mhz.zemarian_id = ?)";
            $types .= 'i';
            $params[] = $zemarianId;
        }
        $filterSql = $where ? implode(' AND ', $where) : '1=1';

        $rev = self::revisionExpr($conn);
        $tax = self::taxonomyCols($conn);
        $media = self::mediaColsExpr($conn);
        $selectBase = "SELECT id, title, category, status, $rev, $tax, $media, updated_at FROM mezmur_hymns WHERE ";      

        $items = [];
        if ($search !== '') {
            // ── Word-index retrieval (P25) ────────────────────────────
            // InnoDB FULLTEXT cannot tokenize Ge'ez script (and a dead
            // index build returned 0 silently), so candidates come from
            // the mezmur_hymn_words inverted index — exact + prefix word
            // hits over titles AND lyrics, index-range-scanned, bounded.
            $terms = self::tokenizeWords($search);
            // lyrics is selected ONLY for scoring/snippets and stripped
            // from every list payload before it leaves this method.
            $selectSearch = "SELECT id, title, category, status, lyrics, $rev, $tax, $media, updated_at FROM mezmur_hymns WHERE ";
            $all = [];
            $scoreRow = static function (array $r) use ($search, $terms): array {
                $r['id'] = (int)$r['id'];
                $lyr = (string)($r['lyrics'] ?? '');
                $titleScore = self::searchScore($search, (string)$r['title']);
                $r['similarity'] = self::searchScore($search, (string)$r['title'], $lyr);
                $r['match_in'] = $titleScore > 0.0 ? 'title' : 'lyrics';
                $r['snippet'] = $r['match_in'] === 'lyrics' ? self::lyricSnippet($terms, $lyr) : '';
                return $r;
            };

            $candidateIds = self::searchWordCandidates($conn, $search);
            foreach (array_chunk($candidateIds, 500) as $chunk) {
                $in = implode(',', array_map('intval', $chunk));
                $stmt = $conn->prepare($selectSearch . "id IN ($in) AND $filterSql");
                if ($types !== '') {
                    $stmt->bind_param($types, ...$params);
                }
                $stmt->execute();
                $res = $stmt->get_result();
                while ($r = $res->fetch_assoc()) {
                    $all[$r['id']] = $scoreRow($r);
                }
                $stmt->close();
            }

            // Fallback (zero-guard / rows not yet word-indexed): strict
            // title LIKE. Unindexed by nature, so bounded to 500 and only
            // used while the word path cannot fill the page.
            if (count($all) < $perPage) {
                $like = '%' . self::escapeLike(mb_substr($search, 0, 100)) . '%';
                $strictTypes = $types . 's';
                $strictParams = array_merge($params, [$like]);
                $cand = $conn->prepare(
                    $selectSearch . "$filterSql AND title LIKE ? ORDER BY updated_at DESC, id DESC LIMIT 500"
                );
                $cand->bind_param($strictTypes, ...$strictParams);
                $cand->execute();
                $res = $cand->get_result();
                while ($r = $res->fetch_assoc()) {
                    $r = $scoreRow($r);
                    $all[$r['id']] = $r;
                }
                $cand->close();
            }

            // Stage 2 (fuzzy rescue): when the strict passes cannot fill a
            // page, score a bounded pool under the SAME structural filters
            // WITHOUT the text condition — the Levenshtein tier (>= 0.6
            // word similarity) pulls misspellings back into the ranking.
            if (count($all) < $perPage) {
                $pool = $conn->prepare($selectSearch . "$filterSql ORDER BY updated_at DESC, id DESC LIMIT 500");
                if ($types !== '') {
                    $pool->bind_param($types, ...$params);
                }
                $pool->execute();
                $pres = $pool->get_result();
                $seen = [];
                foreach ($all as $row) {
                    $seen[$row['id']] = true;
                }
                while ($r = $pres->fetch_assoc()) {
                    $r['id'] = (int)$r['id'];
                    if (isset($seen[$rowId = $r['id']])) {
                        continue;
                    }
                    $r = $scoreRow($r);
                    if ($r['similarity'] <= 0.0) {
                        continue;
                    }
                    $all[$rowId] = $r;
                }
                $pool->close();
            }
            $all = array_values($all);

            usort($all, static function ($a, $b) {
                $cmp = (float)$b['similarity'] <=> (float)$a['similarity'];
                return $cmp !== 0 ? $cmp : strcmp((string)$b['updated_at'], (string)$a['updated_at']);
            });
            $total = count($all);
            $totalPages = max(1, (int)ceil($total / $perPage));
            $page = min($page, $totalPages);
            $offset = ($page - 1) * $perPage;
            $items = array_slice($all, $offset, $perPage);
        } else {
            $stmt = $conn->prepare("SELECT COUNT(*) c FROM mezmur_hymns WHERE $filterSql");
            if ($types !== '') $stmt->bind_param($types, ...$params);
            $stmt->execute();
            $total = (int)$stmt->get_result()->fetch_assoc()['c'];
            $stmt->close();

            $totalPages = max(1, (int)ceil($total / $perPage));
            $page = min($page, $totalPages);
            $offset = ($page - 1) * $perPage;
            $stmt = $conn->prepare($selectBase . "$filterSql ORDER BY updated_at DESC, id DESC LIMIT ? OFFSET ?");
            $stmt->bind_param($types . 'ii', ...array_merge($params, [$perPage, $offset]));
            $stmt->execute();
            $res = $stmt->get_result();
            while ($r = $res->fetch_assoc()) {
                $r['id'] = (int)$r['id'];
                $items[] = $r;
            }
            $stmt->close();
        }

        $ids = array_map(static fn ($i) => (int)$i['id'], $items);
        $taxonomy = self::attachTaxonomyBulk($conn, $ids);
        foreach ($items as &$it) {
            $it['categories'] = $taxonomy[(int)$it['id']]['categories'] ?? [];
            $it['zemarians'] = $taxonomy[(int)$it['id']]['zemarians'] ?? [];
            // Lyrics never travel in list payloads (P25: selected for
            // scoring/snippet only).
            unset($it['lyrics']);
            // P0 media payload: audio_url built from the R2 key (hidden).
            $it = self::applyMedia($it);
        }
        unset($it);

        $cats = [];
        $rc = $conn->query("SELECT DISTINCT category FROM mezmur_hymns WHERE category <> '' ORDER BY category LIMIT 100");
        if ($rc) {
            while ($c = $rc->fetch_assoc()) $cats[] = $c['category'];
        }

        return ['items' => $items, 'total' => $total, 'page' => $page, 'total_pages' => $totalPages, 'categories' => $cats];
    }

    /**
     * Single hymn (lyrics reader). Returns null when missing.
     * @return array|null
     */
    public static function getHymn(\mysqli $conn, int $id): ?array
    {
        if ($id <= 0) return null;
        $rev = self::revisionExpr($conn);
        $tax = self::taxonomyCols($conn);
        $media = self::mediaColsExpr($conn);
        $synced = self::syncedColExpr($conn);
        $stmt = $conn->prepare("SELECT id, title, category, lyrics, $synced, status, $rev, $tax, $media, created_at, updated_at FROM mezmur_hymns WHERE id = ?");
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $item = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$item) return null;
        $item['id'] = (int)$item['id'];
        $item = self::applyMedia($item);
        return array_merge($item, self::attachTaxonomy($conn, $id));
    }


    // ══════════════════════════════════════════════════════════
    // OFFLINE-FIRST SYNC CONTRACT (migration 025)
    // ─ writers with revision-based conflict detection
    // ─ delta pulls keyed on the (updated_at, id) cursor
    // ─ canonical category management
    // ══════════════════════════════════════════════════════════

    private static ?bool $hasRevisionCache = null;

    /** SELECT expression that always yields `revision` even on a stale schema. */
    private static function revisionExpr(\mysqli $conn): string
    {
        if (self::$hasRevisionCache === null) {
            $has = false;
            try {
                $r = $conn->query("SHOW COLUMNS FROM mezmur_hymns LIKE 'revision'");
                $has = $r ? (bool)$r->fetch_assoc() : false;
                if ($r) { $r->close(); }
            } catch (\Throwable $e) { $has = false; }
            self::$hasRevisionCache = $has;
        }
        return self::$hasRevisionCache ? 'revision' : '1 AS revision';
    }

    private static ?bool $hasTaxonomyCache = null;

    /** SELECT fragment for the taxonomy flags, safe on a pre-030 schema. */
    private static function taxonomyCols(\mysqli $conn): string
    {
        if (self::$hasTaxonomyCache === null) {
            $has = false;
            try {
                $r = $conn->query("SHOW COLUMNS FROM mezmur_hymns LIKE 'length'");
                $has = $r ? (bool)$r->fetch_assoc() : false;
                if ($r) { $r->close(); }
            } catch (\Throwable $e) { $has = false; }
            self::$hasTaxonomyCache = $has;
        }
        return self::$hasTaxonomyCache ? 'length, language' : "'long' AS length, 'amharic' AS language";
    }

    // ── P0 audio media columns (038) — probe-guarded like the rest ──
    private static ?bool $hasMediaColumnsCache = null;

    /** SELECT fragment for the audio fields, safe on a pre-038 schema. */
    private static function mediaColsExpr(\mysqli $conn): string
    {
        if (self::$hasMediaColumnsCache === null) {
            $has = false;
            try {
                $r = $conn->query("SHOW COLUMNS FROM mezmur_hymns LIKE 'audio_key'");
                $has = $r ? (bool)$r->fetch_assoc() : false;
                if ($r) { $r->close(); }
            } catch (\Throwable $e) { $has = false; }
            self::$hasMediaColumnsCache = $has;
        }
        return self::$hasMediaColumnsCache
            ? 'audio_key, audio_status, audio_duration_s, audio_size, audio_format, audio_updated_at'
            : "'' AS audio_key, 'none' AS audio_status, NULL AS audio_duration_s, NULL AS audio_size, NULL AS audio_format, NULL AS audio_updated_at";
    }

    private static ?bool $hasSyncedCache = null;

    /** SELECT fragment for the timed-lyrics field (probe-guarded). */
    private static function syncedColExpr(\mysqli $conn): string
    {
        if (self::$hasSyncedCache === null) {
            $has = false;
            try {
                $r = $conn->query("SHOW COLUMNS FROM mezmur_hymns LIKE 'lyrics_synced'");
                $has = $r ? (bool)$r->fetch_assoc() : false;
                if ($r) { $r->close(); }
            } catch (\Throwable $e) { $has = false; }
            self::$hasSyncedCache = $has;
        }
        return self::$hasSyncedCache ? 'lyrics_synced' : "'' AS lyrics_synced";
    }

    /** SELECT fragment for the synced-lyrics edit time (probe-guarded). */
    private static function syncedAtColExpr(\mysqli $conn): string
    {
        self::syncedColExpr($conn); // warms the shared column-probe cache
        return self::$hasSyncedCache
            ? "COALESCE(DATE_FORMAT(lyrics_synced_at, '%Y-%m-%dT%H:%i:%sZ'), '') AS lyrics_synced_at"
            : "'' AS lyrics_synced_at";
    }

    /** Merge the media payload (audio_url from key) into a hymn row. */
    private static function applyMedia(array $item): array
    {
        return \App\Services\MezmurMediaService::decorateRow($item);
    }

    /**
     * Central audit writer for the mezmur module (MZ-1 hardening).
     *
     * Contract — one place, one behaviour, every mutation:
     *  - GUARANTEED: the audit service class is loaded even if the entry
     *    point forgot to declare it (see the require_once above).
     *  - NEVER SILENT: an audit failure must not break the business
     *    operation (fail-soft for availability), but it always lands in
     *    the error log with action + entity context so it is observable
     *    (OWASP A09: "events not logged" is the #1 detection gap).
     *  - COMPLETE: actor, action, target entity, before/after detail —
     *    bearer-token callers have no admin session, so the acting uid
     *    rides in the detail payload.
     */
    private static function audit(\mysqli $conn, string $action, array $details, string $entityType, ?int $entityId, int $actorId): void
    {
        if (!class_exists('\App\Services\SecurityAuditService')) {
            require_once __DIR__ . '/SecurityAuditService.php';
        }
        $details['actor'] = $actorId;
        try {
            \App\Services\SecurityAuditService::record($conn, $action, $details, $entityType, $entityId);
        } catch (\Throwable $e) {
            error_log('[mezmur-audit] ' . $action . ' ' . $entityType . '#' . ($entityId ?? 0) . ' failed: ' . $e->getMessage());
        }
    }

    private static function auditHymn(\mysqli $conn, string $action, array $details, int $hymnId, int $actorId): void
    {
        self::audit($conn, $action, $details, 'mezmur_hymn', $hymnId, $actorId);
    }

    /** longest safe id list length for taxonomy inputs (scale + abuse bound). */
    private const MAX_TAXONOMY_IDS = 200;

    // ── P25: inverted word index (script-agnostic instant search) ──
    // InnoDB FULLTEXT cannot tokenize Ge'ez script and dead-index builds
    // returned 0 silently, so search now uses a word table maintained on
    // every hymn write (Telegram-style local index, server-side).
    private const WORD_MIN_CHARS = 2;
    private const WORD_MAX_BYTES = 80;
    private const WORDS_PER_HYMN = 4000;
    private const WORD_CANDIDATE_CAP = 2000;

    private static ?bool $hasWordsTable = null;

    public static function wordsTableReady(\mysqli $conn): bool
    {
        if (self::$hasWordsTable === null) {
            $ok = false;
            try {
                $r = $conn->query('SELECT 1 FROM mezmur_hymn_words LIMIT 0');
                $ok = $r !== false;
                if ($r) { $r->close(); }
            } catch (\Throwable $e) {
                $ok = false;
            }
            self::$hasWordsTable = $ok;
        }
        return self::$hasWordsTable;
    }

    /** Lowercased unicode words (letters + digits) — works for any script. */
    public static function tokenizeWords(string $text): array
    {
        $words = [];
        foreach (preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($text, 'UTF-8')) ?: [] as $w) {
            $len = mb_strlen($w, 'UTF-8');
            if ($len < self::WORD_MIN_CHARS || $len > 30 || strlen($w) > self::WORD_MAX_BYTES) {
                continue;
            }
            $words[$w] = true;
        }
        return array_keys(array_slice($words, 0, self::WORDS_PER_HYMN, true));
    }

    /** Rebuild the word rows for one hymn (call INSIDE the save txn). */
    public static function reindexHymnWords(\mysqli $conn, int $hymnId, string $title, ?string $lyrics): void
    {
        if ($hymnId <= 0 || !self::wordsTableReady($conn)) {
            return;
        }
        // P28: single Amharic title — the retired title_am / reference
        // fields no longer feed the index (stale rows are cleared by
        // sql/033; backfill rebuilds from title + lyrics only).
        $words = self::tokenizeWords(implode(' ', [$title, (string)$lyrics]));
        $stmt = $conn->prepare('DELETE FROM mezmur_hymn_words WHERE hymn_id = ?');
        $stmt->bind_param('i', $hymnId);
        $stmt->execute();
        $stmt->close();
        $ins = $conn->prepare('INSERT IGNORE INTO mezmur_hymn_words (word, hymn_id) VALUES (?, ?)');
        foreach ($words as $w) {
            $ins->bind_param('si', $w, $hymnId);
            $ins->execute();
        }
        $ins->close();
    }

    /** Backfill the index for hymns that have no word rows yet (admin migrate). */
    public static function backfillHymnWords(\mysqli $conn, int $limit = 1000): int
    {
        if (!self::wordsTableReady($conn)) {
            return 0;
        }
        $stmt = $conn->prepare(
            'SELECT id, title, lyrics FROM mezmur_hymns h
             WHERE NOT EXISTS (SELECT 1 FROM mezmur_hymn_words w WHERE w.hymn_id = h.id)
             ORDER BY id LIMIT ?'
        );
        $stmt->bind_param('i', $limit);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        foreach ($rows as $r) {
            self::reindexHymnWords($conn, (int)$r['id'], (string)$r['title'], $r['lyrics']);
        }
        return count($rows);
    }

    /**
     * Candidate hymn ids for a query: exact word hits plus prefix range
     * scans (clustered PK) — indexed, script-agnostic, bounded.
     * @return list<int>
     */
    public static function searchWordCandidates(\mysqli $conn, string $search): array
    {
        if (!self::wordsTableReady($conn)) {
            return [];
        }
        $ids = [];
        foreach (self::tokenizeWords(mb_substr($search, 0, 100)) as $tok) {
            $like = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $tok) . '%';
            $stmt = $conn->prepare(
                'SELECT DISTINCT hymn_id FROM mezmur_hymn_words WHERE word = ? OR word LIKE ? LIMIT ' . self::WORD_CANDIDATE_CAP
            );
            $stmt->bind_param('ss', $tok, $like);
            $stmt->execute();
            $res = $stmt->get_result();
            while ($r = $res->fetch_assoc()) {
                $ids[(int)$r['hymn_id']] = true;
            }
            $stmt->close();
        }
        return array_keys($ids);
    }

    /**
     * True when the last mysqli error is a duplicate-key violation (1062).
     * Used to settle title-uniqueness races the SELECT pre-check cannot
     * see (MZ-6): with the storage-level UNIQUE key from sql/031 the
     * loser of a concurrent create/update gets the friendly message.
     * NOTE: only valid BEFORE rollback() — a successful rollback resets
     * errno; inside a catch block use isDuplicateKeyValue($e) instead.
     */
    private static function isDuplicateKeyError(\mysqli $conn): bool
    {
        return $conn->errno === 1062 || strpos((string)$conn->error, 'Duplicate entry') !== false;
    }

    /** Same check for the exception path (errno is already reset by the
     *  rollback that runs before we look — the exception carries it). */
    private static function isDuplicateKeyValue(\Throwable $e): bool
    {
        return (int)$e->getCode() === 1062 || stripos($e->getMessage(), 'duplicate entry') !== false;
    }

    /**
     * MZ-9 whitelist helper: which of the given ids do NOT exist in the
     * taxonomy table? Prepared IN-list; table name is hard-whitelisted.
     * @param list<int> $ids normalized, bounded, non-empty
     * @return list<int>
     */
    private static function unknownTaxonomyIds(\mysqli $conn, string $table, array $ids): array
    {
        if (!in_array($table, ['mezmur_categories', 'mezmur_zemarians'], true) || !$ids) {
            return [];
        }
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $conn->prepare("SELECT id FROM `$table` WHERE id IN ($ph)");
        $stmt->bind_param(str_repeat('i', count($ids)), ...$ids);
        $stmt->execute();
        $found = [];
        $res = $stmt->get_result();
        while ($r = $res->fetch_assoc()) {
            $found[(int)$r['id']] = true;
        }
        $stmt->close();
        return array_values(array_diff($ids, array_keys($found)));
    }

    /**
     * Normalize a client-supplied id list (ints or numeric strings) to a
     * clean, unique, bounded int list.
     * @return list<int>
     */
    public static function normalizeIds(mixed $value): array
    {
        if ($value === null) return [];
        if (!is_array($value)) {
            if (is_string($value) && trim($value) !== '') {
                $value = preg_split('/[\s,]+/', $value);
            } else {
                return [];
            }
        }
        $ids = [];
        foreach (array_slice($value, 0, self::MAX_TAXONOMY_IDS) as $raw) {
            if (!is_scalar($raw)) continue;
            $id = filter_var($raw, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($id !== false) $ids[] = (int)$id;
        }
        return array_values(array_unique($ids));
    }

    /**
     * P23 (taxonomy sync): parse a client taxonomy reference list. Plain
     * ids (>= 1) pass through; offline-created refs arrive as
     * `{'id': -42, 'name': 'X'}` maps (negative placeholder ids from the
     * device's optimistic store) and are collected for NATURAL-KEY (name)
     * resolution — the industry outbox pattern adapted to this int-id
     * schema, which already carries unique name keys on both tables.
     * @return array{ids: list<int>, pendingNames: list<string>}
     */
    public static function parseTaxonomyRefs(mixed $value): array
    {
        $ids = [];
        $pending = [];
        if (is_array($value)) {
            foreach (array_slice($value, 0, self::MAX_TAXONOMY_IDS) as $raw) {
                if (is_array($raw)) {
                    $name = trim((string)($raw['name'] ?? ''));
                    $refId = (int)($raw['id'] ?? 0);
                    if ($name !== '' && $refId < 1 && mb_strlen($name) <= 100) {
                        $pending[] = $name;
                    }
                    continue;
                }
                if (!is_scalar($raw)) continue;
                $id = filter_var($raw, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
                if ($id !== false) $ids[] = (int)$id;
            }
        } elseif (is_string($value) && trim($value) !== '') {
            foreach (preg_split('/[\s,]+/', $value) as $raw) {
                $id = filter_var($raw, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
                if ($id !== false) $ids[] = (int)$id;
            }
        }
        return [
            'ids' => array_values(array_unique($ids)),
            'pendingNames' => array_values(array_unique($pending)),
        ];
    }

    /** Case-insensitive natural-key lookup (categories + singers only). */
    private static function resolveNameToId(\mysqli $conn, string $table, string $name): ?int
    {
        if (!in_array($table, ['mezmur_categories', 'mezmur_zemarians'], true) || $name === '') {
            return null;
        }
        // P30: sub-categories may repeat a name under many parents
        // ("አጠቃላይ"), but legacy offline refs always mean MAIN categories
        // — prefer a main when one carries the name.
        $prefersMain = $table === 'mezmur_categories' && self::twoLevelReady($conn);
        $stmt = $conn->prepare(
            $prefersMain
                ? "SELECT id FROM `$table` WHERE LOWER(name) = LOWER(?) ORDER BY parent_id IS NULL DESC LIMIT 1"
                : "SELECT id FROM `$table` WHERE LOWER(name) = LOWER(?) LIMIT 1"
        );
        $stmt->bind_param('s', $name);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $row ? (int)$row['id'] : null;
    }

    /** Public URL of a category cover (cache-busted by mtime). */
    public static function categoryImageUrl(?string $path): string
    {
        if ($path === null || $path === '') return '';
        $full = dirname(__DIR__, 3) . '/' . ltrim($path, '/');
        $v = @filemtime($full);
        return '/' . ltrim($path, '/') . ($v !== false ? ('?v=' . $v) : '');
    }

    /**
     * P30: securely store a category/sub-category cover image.
     * OWASP file-upload hardening: allowlist only, real-content check
     * (finfo magic bytes + getimagesize + a full GD decode), hard size
     * cap, random server-chosen filename (user input never touches the
     * filesystem path), and a RE-ENCODE that strips EXIF and any
     * embedded payload. The upload dir never executes scripts.
     * @return array{ok:bool,image_url?:string,message:string}
     */
    public static function uploadCategoryImage(\mysqli $conn, int $id, array $file, int $actorId): array
    {
        return self::taxonomyImageStore($conn, 'mezmur_categories', 'mezmur_categories',
            self::categoriesReady($conn), 'Category tables are not ready.',
            $id, $file, $actorId, 'Mezmur Category Image Updated', 'mezmur_category');
    }

    /** P34: singers carry cover images too (same hardened chain). */
    public static function uploadZemarianImage(\mysqli $conn, int $id, array $file, int $actorId): array
    {
        return self::taxonomyImageStore($conn, 'mezmur_zemarians', 'mezmur_zemarians',
            self::zemariansReady($conn), 'Singer tables are not ready.',
            $id, $file, $actorId, 'Mezmur Singer Image Updated', 'mezmur_zemarian');
    }

    private static function taxonomyImageStore(\mysqli $conn, string $table, string $dirName, bool $ready, string $readyMsg, int $id, array $file, int $actorId, string $auditTitle, string $auditTable): array
    {
        if (!$ready) {
            return ['ok' => false, 'message' => $readyMsg];
        }
        if ($id <= 0) {
            return ['ok' => false, 'message' => 'Invalid category id.'];
        }
        $stmt = $conn->prepare("SELECT id, image_path FROM " . $table . " WHERE id = ? LIMIT 1");
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$row) {
            return ['ok' => false, 'message' => 'Row not found.'];
        }

        if (empty($file['tmp_name']) || (int)($file['error'] ?? 1) !== UPLOAD_ERR_OK) {
            return ['ok' => false, 'message' => 'Upload failed — please try again.'];
        }
        if (!is_uploaded_file($file['tmp_name'])) {
            return ['ok' => false, 'message' => 'Upload failed — invalid transfer.'];
        }
        $size = (int)($file['size'] ?? 0);
        if ($size <= 0 || $size > 2 * 1024 * 1024) {
            return ['ok' => false, 'message' => 'Image must be at most 2 MB.'];
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
        if ($info === false || (int)$info[0] < 16 || (int)$info[1] < 16 || (int)$info[0] > 4000 || (int)$info[1] > 4000) {
            return ['ok' => false, 'message' => 'The image dimensions must be between 16×16 and 4000×4000.'];
        }
        $img = @imagecreatefromstring($raw);
        if ($img === false) {
            return ['ok' => false, 'message' => 'The file is not a valid image.'];
        }

        // Re-encode: strips EXIF/metadata and any embedded payload.
        // PNG sources keep PNG (transparency); everything else -> JPEG.
        $keepPng = $mime === 'image/png';
        $dir = dirname(__DIR__, 3) . '/uploads/' . $dirName;
        if (!is_dir($dir) && !@mkdir($dir, 0755, true)) {
            return ['ok' => false, 'message' => 'Server storage is not writable.'];
        }
        // Defense in depth for Apache deployments: the directory serves
        // images only — never scripts (php -S ignores this; it never
        // executes uploaded files anyway).
        $ht = $dir . '/.htaccess';
        if (!file_exists($ht)) {
            @file_put_contents($ht, "# Images only - never execute anything from here
"
                . "Options -ExecCGI -Indexes\n"
                . "<FilesMatch \"\\.(php|phtml|phar|cgi|pl|py|sh|htaccess)$\">\n"
                // Both authorization modules are covered: `Require all denied`
                // is Apache 2.4+ only, and on a 2.2 host an unrecognized
                // directive inside .htaccess 500s the whole directory.
                . "  <IfModule mod_authz_core.c>\n    Require all denied\n  </IfModule>\n"
                . "  <IfModule !mod_authz_core.c>\n    Order Allow,Deny\n    Deny from all\n  </IfModule>\n"
                . "</FilesMatch>\n");
        }
        $name = bin2hex(random_bytes(16)) . ($keepPng ? '.png' : '.jpg');
        $dest = $dir . '/' . $name;
        $saved = $keepPng ? imagepng($img, $dest, 6) : imagejpeg($img, $dest, 88);
        imagedestroy($img);
        if (!$saved) {
            return ['ok' => false, 'message' => 'Unable to store the image.'];
        }
        @chmod($dest, 0644);

        $rel = 'uploads/' . $dirName . '/' . $name;
        // updated_at bump: the change must reach every device on the
        // next categories refresh (P33 sync fix).
        $stmt = $conn->prepare("UPDATE " . $table . " SET image_path = ?, updated_at = NOW() WHERE id = ?");
        $stmt->bind_param('si', $rel, $id);
        $ok = $stmt->execute();
        $stmt->close();
        if (!$ok) {
            @unlink($dest);
            return ['ok' => false, 'message' => 'Unable to save the image reference.'];
        }
        // Remove the previous cover (best effort).
        if (!empty($row['image_path']) && $row['image_path'] !== $rel) {
            @unlink(dirname(__DIR__, 3) . '/' . ltrim((string)$row['image_path'], '/'));
        }
        self::audit($conn, $auditTitle, ['file' => $name], $auditTable, $id, $actorId);
        return ['ok' => true, 'image_url' => self::categoryImageUrl($rel), 'message' => 'Image updated.'];
    }

    /**
     * Create a taxonomy row by name (P23). MUST be called inside the
     * caller's transaction (MZ-10: never orphan rows on a failed save).
     */
    private static function createNamedTaxonomy(\mysqli $conn, string $table, string $name, int $actorId): ?int
    {
        if (!in_array($table, ['mezmur_categories', 'mezmur_zemarians'], true) || $name === '') {
            return null;
        }
        $isCategory = $table === 'mezmur_categories';
        $sort = 0;
        $stmt = $conn->prepare(
            "INSERT INTO `$table` (name, sort_order, created_by) VALUES (?,?,?)"
        );
        $stmt->bind_param('sii', $name, $sort, $actorId);
        $ok = $stmt->execute();
        $newId = $ok ? (int)$stmt->insert_id : 0;
        $stmt->close();
        if (!$ok || $newId <= 0) return null;
        self::audit($conn, $isCategory ? 'Mezmur Category Created (offline sync)' : 'Mezmur Singer Created (offline sync)', ['name' => $name], $isCategory ? 'mezmur_category' : 'mezmur_zemarian', $newId, $actorId);
        return $newId;
    }

    /**
     * Resolve the legacy single `category` name into a canonical category
     * id (creating the row if absent), so old clients keep working.
     */
    private static function resolveLegacyCategoryId(\mysqli $conn, string $name, bool $create = true): ?int
    {
        $name = trim($name);
        if ($name === '' || $name === 'general' || mb_strlen($name) > 50) return null;
        $stmt = $conn->prepare("SELECT id FROM mezmur_categories WHERE name = ? LIMIT 1");
        if (!$stmt) return null;
        $stmt->bind_param('s', $name);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($row) return (int)$row['id'];
        if (!$create) return null;
        $stmt = $conn->prepare("INSERT IGNORE INTO mezmur_categories (name, sort_order) VALUES (?, 100)");
        if (!$stmt) return null;
        $stmt->bind_param('s', $name);
        $stmt->execute();
        $newId = (int)$stmt->insert_id;
        $stmt->close();
        if ($newId > 0) return $newId;
        $stmt = $conn->prepare("SELECT id FROM mezmur_categories WHERE name = ? LIMIT 1");
        $stmt->bind_param('s', $name);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $row ? (int)$row['id'] : null;
    }

    /** Rebuild the hymn ↔ category join for one hymn (caller holds txn). */
    private static function syncHymnCategories(\mysqli $conn, int $hymnId, array $categoryIds): void
    {
        $del = $conn->prepare("DELETE FROM mezmur_hymn_categories WHERE hymn_id = ?");
        $del->bind_param('i', $hymnId);
        $del->execute();
        $del->close();
        if (!$categoryIds) return;
        $ins = $conn->prepare("INSERT IGNORE INTO mezmur_hymn_categories (hymn_id, category_id) VALUES (?,?)");
        foreach ($categoryIds as $cid) {
            $ins->bind_param('ii', $hymnId, $cid);
            $ins->execute();
        }
        $ins->close();
    }

    /** Rebuild the hymn ↔ zemarian join for one hymn (caller holds txn). */
    private static function syncHymnZemarians(\mysqli $conn, int $hymnId, array $zemarianIds): void
    {
        $del = $conn->prepare("DELETE FROM mezmur_hymn_zemarians WHERE hymn_id = ?");
        $del->bind_param('i', $hymnId);
        $del->execute();
        $del->close();
        if (!$zemarianIds) return;
        $ins = $conn->prepare("INSERT IGNORE INTO mezmur_hymn_zemarians (hymn_id, zemarian_id) VALUES (?,?)");
        foreach ($zemarianIds as $zid) {
            $ins->bind_param('ii', $hymnId, $zid);
            $ins->execute();
        }
        $ins->close();
    }

    /**
     * Attach the full taxonomy (categories + zemarians) to a hymn row.
     * @return array<string,mixed> with 'categories' and 'zemarians'
     */
    public static function attachTaxonomy(\mysqli $conn, int $hymnId): array
    {
        $cats = [];
        try {
            $rc = $conn->prepare(
                "SELECT c.id, c.name FROM mezmur_hymn_categories mhc
                 JOIN mezmur_categories c ON c.id = mhc.category_id
                 WHERE mhc.hymn_id = ? ORDER BY c.sort_order, c.name LIMIT 200"
            );
            if ($rc) {
                $rc->bind_param('i', $hymnId);
                $rc->execute();
                $res = $rc->get_result();
                while ($r = $res->fetch_assoc()) { $r['id'] = (int)$r['id']; $cats[] = $r; }
                $rc->close();
            }
        } catch (\Throwable $e) { $cats = []; }

        $zem = [];
        try {
            $rz = $conn->prepare(
                "SELECT z.id, z.name, z.name_am FROM mezmur_hymn_zemarians mhz
                 JOIN mezmur_zemarians z ON z.id = mhz.zemarian_id
                 WHERE mhz.hymn_id = ? ORDER BY z.sort_order, z.name LIMIT 200"
            );
            if ($rz) {
                $rz->bind_param('i', $hymnId);
                $rz->execute();
                $res = $rz->get_result();
                while ($r = $res->fetch_assoc()) { $r['id'] = (int)$r['id']; $zem[] = $r; }
                $rz->close();
            }
        } catch (\Throwable $e) { $zem = []; }

        return ['categories' => $cats, 'zemarians' => $zem];
    }

    /**
     * Bulk-attach taxonomy (categories + zemarians) for many hymn ids in
     * one round trip (avoids N+1 on list screens). Missing join tables
     * (pre-030 schema) degrade to empty arrays for every id.
     *
     * @param list<int> $ids
     * @return array<int,array{categories:list<array>,zemarians:list<array>}>
     */
    public static function attachTaxonomyBulk(\mysqli $conn, array $ids): array
    {
        $out = [];
        foreach ($ids as $id) {
            $out[(int)$id] = ['categories' => [], 'zemarians' => []];
        }
        $ids = array_values(array_filter(array_map('intval', $ids), static fn ($i) => $i > 0));
        if (!$ids) return $out;

        $ph = implode(',', array_fill(0, count($ids), '?'));
        $types = str_repeat('i', count($ids));

        try {
            $stmt = $conn->prepare(
                "SELECT mhc.hymn_id AS hymn_id, c.id, c.name
                 FROM mezmur_hymn_categories mhc
                 JOIN mezmur_categories c ON c.id = mhc.category_id
                 WHERE mhc.hymn_id IN ($ph) ORDER BY c.sort_order, c.name"
            );
            if ($stmt) {
                $stmt->bind_param($types, ...$ids);
                $stmt->execute();
                $res = $stmt->get_result();
                while ($r = $res->fetch_assoc()) {
                    $out[(int)$r['hymn_id']]['categories'][] = ['id' => (int)$r['id'], 'name' => (string)$r['name']];
                }
                $stmt->close();
            }
        } catch (\Throwable $e) { /* pre-030: no join tables */ }

        try {
            $stmt = $conn->prepare(
                "SELECT mhz.hymn_id AS hymn_id, z.id, z.name, z.name_am
                 FROM mezmur_hymn_zemarians mhz
                 JOIN mezmur_zemarians z ON z.id = mhz.zemarian_id
                 WHERE mhz.hymn_id IN ($ph) ORDER BY z.sort_order, z.name"
            );
            if ($stmt) {
                $stmt->bind_param($types, ...$ids);
                $stmt->execute();
                $res = $stmt->get_result();
                while ($r = $res->fetch_assoc()) {
                    $out[(int)$r['hymn_id']]['zemarians'][] = ['id' => (int)$r['id'], 'name' => (string)$r['name'], 'name_am' => $r['name_am']];
                }
                $stmt->close();
            }
        } catch (\Throwable $e) { /* pre-030: no join tables */ }

        return $out;
    }

    /**
     * Telegram-style relevance score (0..N). Words are matched against
     * the single title (P28: one Amharic title; the old Amharic-title
     * and reference fields were retired by sql/033) and tiered:
     *   exact string > prefix > substring > fuzzy (Levenshtein spelling
     *   tolerance). Higher = more similar; callers sort descending.
     */
    public static function searchScore(string $query, ?string $title, ?string $lyrics = null): float
    {
        $query = mb_strtolower(trim($query));
        if ($query === '') return 0.0;
        $terms = array_values(array_filter(preg_split('/\s+/u', $query), static fn ($t) => $t !== ''));
        if (!$terms) return 0.0;

        $haystack = mb_strtolower(trim((string)$title));

        $score = 0.0;
        foreach ($terms as $term) {
            $score += self::termScore($term, $haystack);
        }
        // P25 lyrics tier: a word found in the lyrics body scores below a
        // title substring (70) but above fuzzy (<=40) — 50 per matched
        // term. Fuzzy matching stays TITLE-only (Levenshtein over whole
        // lyric bodies would be O(lyrics) per keystroke).
        if ($lyrics !== null && $lyrics !== '') {
            $low = mb_strtolower($lyrics);
            foreach ($terms as $term) {
                if (mb_strpos($low, $term) !== false) {
                    $score += 50.0;
                }
            }
        }
        return round($score, 3);
    }

    /** Tight context window around the first term found in the lyrics. */
    private static function lyricSnippet(array $terms, string $lyrics): string
    {
        if ($lyrics === '') return '';
        foreach ($terms as $t) {
            $pos = mb_stripos($lyrics, $t);
            if ($pos !== false) {
                $start = max(0, $pos - 60);
                return ($start > 0 ? '…' : '') . trim(mb_substr($lyrics, $start, 160)) . '…';
            }
        }
        return '';
    }

    private static function termScore(string $term, string $haystack): float
    {
        if ($term === '' || $haystack === '') return 0.0;
        if ($haystack === $term) return 100.0;
        if (str_starts_with($haystack, $term)) return 90.0;
        if (mb_strpos($haystack, $term) !== false) return 70.0;

        $best = 0.0;
        $maxLen = max(mb_strlen($term), 1);
        foreach (preg_split('/\s+/u', $haystack) as $word) {
            if ($word === '') continue;
            $dist = levenshtein($term, $word);
            $sim = 1.0 - $dist / max(mb_strlen($word), $maxLen);
            if ($sim > $best) $best = $sim;
        }
        return $best >= 0.6 ? (40.0 * $best) : 0.0;
    }

    /**
     * Create or update one hymn (web dashboard + mobile parity).
     *
     * Accepts multi-category (`categories`: list of category ids),
     * multi-singer (`zemarians`: list of zemarian ids), and the
     * `length` / `language` flags. The legacy single `category` name is
     * still honoured for backward compatibility.
     *
     * Conflict rule (offline edits): when the client supplies
     * base_revision and the row moved past it, the write is refused
     * with the current server copy so the device can reconcile.
     *
     * @param array{id?:int|string,title?:string,category?:string,lyrics?:string,status?:string,length?:string,language?:string,categories?:mixed,zemarians?:mixed,base_revision?:int|string|null} $input
     * @return array{ok:bool,conflict?:bool,item?:array|null,message:string,created?:bool}
     */
    public static function saveHymn(\mysqli $conn, array $input, int $actorId): array
    {
        $id        = (int)($input['id'] ?? 0);
        $title     = trim((string)($input['title'] ?? ''));
        $category  = trim((string)($input['category'] ?? ''));
        $lyrics    = (string)($input['lyrics'] ?? '');
        // P28: title_am / reference are RETIRED. Older app builds still
        // send them; they are deliberately accepted-and-ignored so those
        // clients keep working (no breakage) while nothing persists them.
        $length    = (string)($input['length'] ?? 'long');
        $language  = (string)($input['language'] ?? 'amharic');

        if (!in_array($length, ['long', 'short'], true)) $length = 'long';
        if (!in_array($language, ['geez', 'amharic'], true)) $language = 'amharic';

        // P23: taxonomy refs may mix real ids with offline-created
        // {id: -42, name: 'X'} placeholder refs. Real ids validate as
        // before (MZ-9); placeholder refs resolve by NAME below.
        $catRefs = self::parseTaxonomyRefs($input['categories'] ?? null);
        $zemRefs = self::parseTaxonomyRefs($input['zemarians'] ?? null);
        $categoryIds = $catRefs['ids'];
        $zemarianIds = $zemRefs['ids'];
        $pendingCategoryNames = $catRefs['pendingNames'];
        $pendingZemarianNames = $zemRefs['pendingNames'];
        // Pre-resolve names that already exist server-side (read-only);
        // only genuinely new ones wait for in-transaction creation.
        foreach ($pendingCategoryNames as $k => $n) {
            $rid = self::resolveNameToId($conn, 'mezmur_categories', $n);
            if ($rid !== null) { $categoryIds[] = $rid; unset($pendingCategoryNames[$k]); }
        }
        foreach ($pendingZemarianNames as $k => $n) {
            $rid = self::resolveNameToId($conn, 'mezmur_zemarians', $n);
            if ($rid !== null) { $zemarianIds[] = $rid; unset($pendingZemarianNames[$k]); }
        }
        $categoryIds = array_values(array_unique($categoryIds));
        $zemarianIds = array_values(array_unique($zemarianIds));
        $pendingCategoryNames = array_values($pendingCategoryNames);
        $pendingZemarianNames = array_values($pendingZemarianNames);

        // MZ-9 whitelist validation (OWASP): taxonomy ids must reference
        // existing rows. The FKs already stop unknown ids at the storage
        // layer, but INSERT IGNORE used to swallow them silently — a stale
        // picker (category/singer deleted on another device) got a fake
        // success. Surface an honest 422 instead.
        if ($categoryIds && self::unknownTaxonomyIds($conn, 'mezmur_categories', $categoryIds)) {
            return ['ok' => false, 'message' => 'One of the selected categories no longer exists. Refresh the catalog and try again.'];
        }
        if ($zemarianIds && self::unknownTaxonomyIds($conn, 'mezmur_zemarians', $zemarianIds)) {
            return ['ok' => false, 'message' => 'One of the selected singers no longer exists. Refresh the catalog and try again.'];
        }

        // Legacy single-name input becomes a category id. MZ-10: the row is
        // only CREATED inside the save transaction — creating it here used
        // to leave an orphan category behind whenever the hymn save later
        // failed or rolled back.
        $pendingLegacyCategory = false;
        if (!$categoryIds && $category !== '' && $category !== 'general' && mb_strlen($category) <= 50) {
            $legacyId = self::resolveLegacyCategoryId($conn, $category, false);
            if ($legacyId !== null) {
                $categoryIds = [$legacyId];
            } else {
                $pendingLegacyCategory = true;
            }
        }

        // Keep the legacy column in sync for old readers.
        $categoryName = '';
        if ($categoryIds) {
            $stmt = $conn->prepare("SELECT name FROM mezmur_categories WHERE id = ? LIMIT 1");
            $stmt->bind_param('i', $categoryIds[0]);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            $categoryName = $row ? (string)$row['name'] : '';
        }
        if ($categoryName === '') $categoryName = $category === '' ? 'general' : $category;

        if ($title === '') {
            return ['ok' => false, 'message' => 'Title is required.'];
        }
        if (mb_strlen($title) > 255) {
            return ['ok' => false, 'message' => 'A field exceeds its maximum length.'];
        }
        if (mb_strlen($categoryName) > 50) $categoryName = mb_substr($categoryName, 0, 50);
        if (mb_strlen($lyrics) > 200000) {
            return ['ok' => false, 'message' => 'Lyrics text is too long.'];
        }
        $lyrics    = trim($lyrics) === '' ? null : $lyrics;

        if ($id > 0) {
            $rev = self::revisionExpr($conn);
            $stmt = $conn->prepare("SELECT id, title, revision, status FROM mezmur_hymns WHERE id = ? LIMIT 1");
            $stmt->bind_param('i', $id);
            $stmt->execute();
            $current = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if (!$current) {
                return ['ok' => false, 'message' => 'Hymn not found.'];
            }
            $baseRevision = isset($input['base_revision']) && $input['base_revision'] !== '' && $input['base_revision'] !== null
                ? (int)$input['base_revision'] : null;
            if ($baseRevision !== null && (int)$current['revision'] > $baseRevision) {
                return [
                    'ok' => false,
                    'conflict' => true,
                    'item' => self::getHymn($conn, $id),
                    'message' => 'This hymn changed on the server while you were offline. Review the newest copy and save again.',
                ];
            }
            $stmt = $conn->prepare("SELECT COUNT(*) c FROM mezmur_hymns WHERE LOWER(title) = LOWER(?) AND id <> ?");
            $stmt->bind_param('si', $title, $id);
            $stmt->execute();
            $dup = (int)$stmt->get_result()->fetch_assoc()['c'];
            $stmt->close();
            if ($dup > 0) {
                return ['ok' => false, 'message' => 'A hymn with this title already exists.'];
            }

            $conn->begin_transaction();
            try {
                if ($pendingLegacyCategory) {
                    // MZ-10: create inside the transaction so a failed save
                    // rolls the category row back with it (no orphans).
                    $legacyId = self::resolveLegacyCategoryId($conn, $category, true);
                    if ($legacyId !== null) $categoryIds = [$legacyId];
                }
                // P23: resolve offline-created taxonomy refs by name,
                // creating any that are still absent — inside this
                // transaction (same MZ-10 no-orphan guarantee).
                foreach ($pendingCategoryNames as $pname) {
                    $nid = self::resolveNameToId($conn, 'mezmur_categories', $pname)
                        ?? self::createNamedTaxonomy($conn, 'mezmur_categories', $pname, $actorId);
                    if ($nid !== null) $categoryIds[] = $nid;
                }
                foreach ($pendingZemarianNames as $pname) {
                    $nid = self::resolveNameToId($conn, 'mezmur_zemarians', $pname)
                        ?? self::createNamedTaxonomy($conn, 'mezmur_zemarians', $pname, $actorId);
                    if ($nid !== null) $zemarianIds[] = $nid;
                }
                $categoryIds = array_values(array_unique($categoryIds));
                $zemarianIds = array_values(array_unique($zemarianIds));
                // Optimistic concurrency (MZ-6): when the client supplied a
                // base_revision the guard lives IN the UPDATE itself. The
                // old SELECT-then-UPDATE pair was a check-then-act race —
                // another writer landing between the two silently beat the
                // check and last-write-won. affected_rows 0 now PROVES the
                // row moved past us (the UPDATE always bumps revision and
                // updated_at, so a matching row always counts as changed).
                if ($baseRevision !== null) {
                    $stmt = $conn->prepare(
                        "UPDATE mezmur_hymns
                         SET title=?, category=?, lyrics=?, length=?, language=?,
                             updated_by=?, updated_at=NOW(), revision = revision + 1
                         WHERE id=? AND revision = ?"
                    );
                    $stmt->bind_param('sssssiii', $title, $categoryName, $lyrics, $length, $language, $actorId, $id, $baseRevision);
                } else {
                    $stmt = $conn->prepare(
                        "UPDATE mezmur_hymns
                         SET title=?, category=?, lyrics=?, length=?, language=?,
                             updated_by=?, updated_at=NOW(), revision = revision + 1
                         WHERE id=?"
                    );
                    $stmt->bind_param('sssssii', $title, $categoryName, $lyrics, $length, $language, $actorId, $id);
                }
                $ok = $stmt->execute();
                $affected = $ok ? $stmt->affected_rows : 0;
                $stmt->close();
                if (!$ok) {
                    $conn->rollback();
                    if (self::isDuplicateKeyError($conn)) {
                        // Lost the title-uniqueness race (storage-level guard).
                        return ['ok' => false, 'message' => 'A hymn with this title already exists.'];
                    }
                    return ['ok' => false, 'message' => 'Unable to update the hymn.'];
                }
                if ($baseRevision !== null && $affected === 0) {
                    // Lost the revision race — hand back the winning copy so
                    // the device can reconcile without a second round trip.
                    $conn->rollback();
                    $winner = self::getHymn($conn, $id);
                    if ($winner === null) {
                        return ['ok' => false, 'message' => 'Hymn not found.'];
                    }
                    return [
                        'ok' => false,
                        'conflict' => true,
                        'item' => $winner,
                        'message' => 'This hymn changed on the server while you were offline. Review the newest copy and save again.',
                    ];
                }
                self::syncHymnCategories($conn, $id, $categoryIds);
                self::syncHymnZemarians($conn, $id, $zemarianIds);
                self::reindexHymnWords($conn, $id, $title, $lyrics);
                $conn->commit();
            } catch (\Throwable $e) {
                try { $conn->rollback(); } catch (\Throwable $r) {}
                // PHP 8.1+ throws mysqli_sql_exception on the duplicate; the
                // connection's errno is already RESET by the rollback above, so
                // the verdict must come from the exception itself.
                if (self::isDuplicateKeyValue($e)) {
                    return ['ok' => false, 'message' => 'A hymn with this title already exists.'];
                }
                throw $e;
            }
            self::auditHymn($conn, 'Mezmur Hymn Updated', [
                'title' => mb_substr($title, 0, 255), 'categories' => $categoryIds, 'zemarians' => $zemarianIds, 'source' => 'sync',
            ], $id, $actorId);
            return ['ok' => true, 'created' => false, 'item' => self::getHymn($conn, $id), 'message' => 'Hymn updated.'];
        }

        $stmt = $conn->prepare("SELECT COUNT(*) c FROM mezmur_hymns WHERE LOWER(title) = LOWER(?)");
        $stmt->bind_param('s', $title);
        $stmt->execute();
        $dup = (int)$stmt->get_result()->fetch_assoc()['c'];
        $stmt->close();
        if ($dup > 0) {
            return ['ok' => false, 'message' => 'A hymn with this title already exists.'];
        }
        $status = in_array($input['status'] ?? 'active', ['active', 'archived'], true)
            ? (string)($input['status'] ?? 'active') : 'active';

        $conn->begin_transaction();
        $newId = 0;
        try {
            if ($pendingLegacyCategory) {
                // MZ-10: create inside the transaction (see update path).
                $legacyId = self::resolveLegacyCategoryId($conn, $category, true);
                if ($legacyId !== null) $categoryIds = [$legacyId];
            }
            // P23: resolve offline-created taxonomy refs by name (see the
            // update path — same in-transaction no-orphan guarantee).
            foreach ($pendingCategoryNames as $pname) {
                $nid = self::resolveNameToId($conn, 'mezmur_categories', $pname)
                    ?? self::createNamedTaxonomy($conn, 'mezmur_categories', $pname, $actorId);
                if ($nid !== null) $categoryIds[] = $nid;
            }
            foreach ($pendingZemarianNames as $pname) {
                $nid = self::resolveNameToId($conn, 'mezmur_zemarians', $pname)
                    ?? self::createNamedTaxonomy($conn, 'mezmur_zemarians', $pname, $actorId);
                if ($nid !== null) $zemarianIds[] = $nid;
            }
            $categoryIds = array_values(array_unique($categoryIds));
            $zemarianIds = array_values(array_unique($zemarianIds));
            $stmt = $conn->prepare(
                "INSERT INTO mezmur_hymns (title, category, lyrics, length, language, status, created_by, updated_by)
                 VALUES (?,?,?,?,?,?,?,?)"
            );
            $stmt->bind_param('ssssssii', $title, $categoryName, $lyrics, $length, $language, $status, $actorId, $actorId);
            $ok = $stmt->execute();
            $newId = $ok ? (int)$stmt->insert_id : 0;
            $dupRace = !$ok && self::isDuplicateKeyError($conn);
            $stmt->close();
            if ($dupRace) {
                // MZ-6: two writers passed the SELECT dup-check together;
                // the storage-level UNIQUE key (sql/031) settled it — the
                // loser gets the same friendly message as the slow path.
                $conn->rollback();
                return ['ok' => false, 'message' => 'A hymn with this title already exists.'];
            }
            if (!$ok || $newId <= 0) {
                $conn->rollback();
                return ['ok' => false, 'message' => 'Unable to save the hymn.'];
            }
            self::syncHymnCategories($conn, $newId, $categoryIds);
            self::syncHymnZemarians($conn, $newId, $zemarianIds);
            self::reindexHymnWords($conn, $newId, $title, $lyrics);
            $conn->commit();
        } catch (\Throwable $e) {
            try { $conn->rollback(); } catch (\Throwable $r) {}
            // PHP 8.1+ throws mysqli_sql_exception on the duplicate; the
            // connection's errno is already RESET by the rollback above, so
            // the verdict must come from the exception itself.
            if (self::isDuplicateKeyValue($e)) {
                return ['ok' => false, 'message' => 'A hymn with this title already exists.'];
            }
            throw $e;
        }
        self::auditHymn($conn, 'Mezmur Hymn Created', [
            'title' => mb_substr($title, 0, 255), 'categories' => $categoryIds, 'zemarians' => $zemarianIds, 'source' => 'sync',
        ], $newId, $actorId);
        return ['ok' => true, 'created' => true, 'item' => self::getHymn($conn, $newId), 'message' => 'Hymn added to the library.'];
    }

    /**
     * Archive / restore (soft delete) with revision bump + audit.
     * @return array{ok:bool,item?:array|null,message:string}
     */
    public static function setStatusHymn(\mysqli $conn, int $id, string $status, int $actorId): array
    {
        if ($id <= 0) {
            return ['ok' => false, 'message' => 'Invalid hymn id.'];
        }
        if (!in_array($status, ['active', 'archived'], true)) {
            return ['ok' => false, 'message' => 'Invalid status.'];
        }
        try {
            $stmt = $conn->prepare(
                "UPDATE mezmur_hymns SET status=?, updated_by=?, updated_at=NOW(), revision = revision + 1
                 WHERE id=? AND status <> ?"
            );
        } catch (\Throwable $e) {
            // Stale schema without revision column: plain status update.
            $stmt = $conn->prepare("UPDATE mezmur_hymns SET status=?, updated_by=?, updated_at=NOW() WHERE id=? AND status <> ?");
        }
        // No-op transitions are refused (affected_rows 0 below): a repeat
        // archive used to "succeed" because the unconditional revision
        // bump made every UPDATE count as changed — burning revisions and
        // pushing phantom sync deltas to every device for nothing.
        $stmt->bind_param('siis', $status, $actorId, $id, $status);
        $ok = $stmt->execute();
        $affected = $stmt->affected_rows;
        $stmt->close();
        if (!$ok || $affected === 0) {
            return ['ok' => false, 'message' => 'Hymn not found or already in that state.'];
        }
        self::auditHymn($conn, $status === 'archived' ? 'Mezmur Hymn Archived' : 'Mezmur Hymn Restored', [
            'new_status' => $status, 'source' => 'sync',
        ], $id, $actorId);
        return ['ok' => true, 'item' => self::getHymn($conn, $id), 'message' => $status === 'archived' ? 'Hymn archived.' : 'Hymn restored.'];
    }

    /**
     * DELTA PULL (Telegram/Drive change-token pattern): rows changed
     * after the client's cursor, oldest first, archived rows included
     * (a delete is an archived delta, never a silent disappearance).
     *
     * Cursor format: "Y-m-d H:i:s|<id>" — ties on updated_at resolve by
     * id so no row is skipped or doubled. Empty cursor = full bootstrap
     * (metadata only unless include_lyrics).
     *
     * @return array{items:list<array>,next_cursor:string,server_time:string,has_more:bool}
     */
    public static function listChangedSince(\mysqli $conn, string $cursor, int $limit = 200, bool $includeLyrics = false): array
    {
        $limit = max(1, min($limit, $includeLyrics ? 100 : 500));
        $rev = self::revisionExpr($conn);
        $lyricsCol = $includeLyrics ? 'lyrics' : "'' AS lyrics";
        $taxCols = self::taxonomyCols($conn);
        $mediaCols = self::mediaColsExpr($conn);
        // F5 convergence: timed lyrics ride the SAME delta cursor, so a
        // server-side LRC edit reaches every cached device (the Flutter
        // local_db upsert already applies these keys when present).
        $syncedCols = self::syncedColExpr($conn) . ', ' . self::syncedAtColExpr($conn);

        $cursorTs = null;
        $cursorId = 0;
        $parts = explode('|', trim($cursor));
        if (count($parts) === 2 && preg_match('/^\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}:\d{2}$/', $parts[0])) {
            $cursorTs = str_replace('T', ' ', $parts[0]);
            $cursorId = max(0, (int)$parts[1]);
        }

        if ($cursorTs !== null) {
            $sql = "SELECT id, title, category, status, $rev, $taxCols,
                           DATE_FORMAT(updated_at, '%Y-%m-%d %H:%i:%s') AS updated_at, $lyricsCol, $mediaCols, $syncedCols
                    FROM mezmur_hymns
                    WHERE updated_at > ? OR (updated_at = ? AND id > ?)
                    ORDER BY updated_at ASC, id ASC
                    LIMIT ?";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param('ssii', $cursorTs, $cursorTs, $cursorId, $limit);
        } else {
            $sql = "SELECT id, title, category, status, $rev, $taxCols,
                           DATE_FORMAT(updated_at, '%Y-%m-%d %H:%i:%s') AS updated_at, $lyricsCol, $mediaCols, $syncedCols
                    FROM mezmur_hymns
                    ORDER BY updated_at ASC, id ASC
                    LIMIT ?";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param('i', $limit);
        }
        $stmt->execute();
        $res = $stmt->get_result();
        $items = [];
        $lastTs = $cursorTs ?? '';
        $lastId = $cursorId;
        while ($r = $res->fetch_assoc()) {
            $r['id'] = (int)$r['id'];
            $r['revision'] = (int)$r['revision'];
            if (!$includeLyrics) {
                unset($r['lyrics']);
            }
            // P0 media payload: audio_url + status ride the delta so
            // devices converge audio metadata over the SAME cursor.
            $r = self::applyMedia($r);
            $items[] = $r;
            $lastTs = (string)$r['updated_at'];
            $lastId = (int)$r['id'];
        }
        $stmt->close();

        // Attach category + singer id lists so the device caches the
        // many-to-many associations alongside the row (single round trip).
        if ($items) {
            $ids = array_map(static fn ($i) => (int)$i['id'], $items);
            $taxonomy = self::attachTaxonomyBulk($conn, $ids);
            foreach ($items as &$it) {
                $it['category_ids'] = array_map(static fn ($c) => (int)$c['id'], $taxonomy[(int)$it['id']]['categories'] ?? []);
                $it['zemarian_ids'] = array_map(static fn ($z) => (int)$z['id'], $taxonomy[(int)$it['id']]['zemarians'] ?? []);
            }
            unset($it);
        }

        // Server clock reference for the next cursor baseline.
        $now = '';
        try {
            $t = $conn->query("SELECT DATE_FORMAT(NOW(), '%Y-%m-%d %H:%i:%s') AS now");
            if ($t) {
                $now = (string)($t->fetch_assoc()['now'] ?? '');
                $t->close();
            }
        } catch (\Throwable $e) { $now = ''; }

        return [
            'items' => $items,
            'next_cursor' => $lastTs !== '' ? ($lastTs . '|' . $lastId) : '',
            'server_time' => $now,
            'has_more' => count($items) >= $limit,
        ];
    }

    // ── canonical categories ───────────────────────────────────

    private static ?bool $hasCategoriesCache = null;

    private static function categoriesReady(\mysqli $conn): bool
    {
        if (self::$hasCategoriesCache === null) {
            $ok = false;
            try {
                $r = $conn->query("SELECT 1 FROM mezmur_categories LIMIT 0");
                $ok = $r !== false;
                if ($r) { $r->close(); }
            } catch (\Throwable $e) { $ok = false; }
            self::$hasCategoriesCache = $ok;
        }
        return self::$hasCategoriesCache;
    }

    /**
     * Canonical list (managed in-app). Legacy deployments without the
     * table degrade to the DISTINCT categories present on hymns.
     * @return list<array<string,mixed>>
     */
    /** P30: does mezmur_categories carry the two-level columns? (Guards
     *  pre-034 deployments so shipped code never references missing
     *  columns — the reconciler/admin migrate brings them in.) */
    private static ?bool $twoLevelCache = null;
    private static ?bool $gradientsCache = null;

    /** P32: gradient columns probe (sql/035) — absent columns mean
     *  deployments mid-upgrade keep the automatic palette. */
    public static function gradientsReady(\mysqli $conn): bool
    {
        if (self::$gradientsCache === null) {
            $ok = false;
            try {
                $r = $conn->query("SHOW COLUMNS FROM mezmur_categories LIKE 'gradient_start'");
                $ok = $r ? (bool)$r->fetch_assoc() : false;
                if ($r) { $r->close(); }
            } catch (\Throwable $e) { $ok = false; }
            self::$gradientsCache = $ok;
        }
        return self::$gradientsCache;
    }

    public static function twoLevelReady(\mysqli $conn): bool
    {
        if (self::$twoLevelCache === null) {
            $ok = false;
            try {
                $r = $conn->query("SHOW COLUMNS FROM mezmur_categories LIKE 'parent_id'");
                $ok = $r ? (bool)$r->fetch_assoc() : false;
                if ($r) { $r->close(); }
            } catch (\Throwable $e) { $ok = false; }
            self::$twoLevelCache = $ok;
        }
        return self::$twoLevelCache;
    }

    /** Strict #rrggbb or #rrggbbaa hex (P32 + P33 alpha), or NULL. */
    private static function hexColorOrNull($v): ?string
    {
        $v = trim((string)($v ?? ''));
        if ($v === '') return null;
        if (!preg_match('/^#([0-9a-fA-F]{6}|[0-9a-fA-F]{8})$/', $v)) return '@@invalid@@';
        return strtolower($v);
    }

    /** Persist the cover gradient for one category (probe-guarded). */
    private static function saveCategoryGradient(\mysqli $conn, int $id, ?string $start, ?string $end): void
    {
        if (!self::gradientsReady($conn) || $id <= 0) return;
        // updated_at bump: gradient edits must reach every device (P33).
        $stmt = $conn->prepare("UPDATE mezmur_categories SET gradient_start=?, gradient_end=?, updated_at=NOW() WHERE id=?");
        $stmt->bind_param('ssi', $start, $end, $id);
        $stmt->execute();
        $stmt->close();
    }

    /** Drop the cover image (the gradient/automatic palette shows). */
    public static function removeCategoryImage(\mysqli $conn, int $id, int $actorId): array
    {
        return self::taxonomyImageDrop($conn, 'mezmur_categories', $id, $actorId,
            'Mezmur Category Image Removed', 'mezmur_category');
    }

    /** P34: singers. */
    public static function removeZemarianImage(\mysqli $conn, int $id, int $actorId): array
    {
        return self::taxonomyImageDrop($conn, 'mezmur_zemarians', $id, $actorId,
            'Mezmur Singer Image Removed', 'mezmur_zemarian');
    }

    private static function taxonomyImageDrop(\mysqli $conn, string $table, int $id, int $actorId, string $auditTitle, string $auditTable): array
    {
        if ($id <= 0) return ['ok' => false, 'message' => 'Invalid category id.'];
        $stmt = $conn->prepare("SELECT image_path FROM " . $table . " WHERE id = ? LIMIT 1");
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$row) return ['ok' => false, 'message' => 'Row not found.'];
        if (empty($row['image_path'])) return ['ok' => true, 'message' => 'No cover image set.'];
        @unlink(dirname(__DIR__, 3) . '/' . ltrim((string)$row['image_path'], '/'));
        $stmt = $conn->prepare("UPDATE " . $table . " SET image_path = NULL, updated_at = NOW() WHERE id = ?");
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $stmt->close();
        self::audit($conn, $auditTitle, [], $auditTable, $id, $actorId);
        return ['ok' => true, 'message' => 'Cover image removed — the gradient shows.'];
    }

    public static function listCategories(\mysqli $conn): array
    {
        if (self::categoriesReady($conn)) {
            $out = [];
            // P30 two-level taxonomy: mains (parent_id NULL) + subs,
            // usage counts at the leaf AND rolled up for mains, plus
            // the optional cover image. Legacy (pre-034) deployments
            // fall back to the flat shape — no breakage mid-upgrade.
            if (self::twoLevelReady($conn)) {
                $gradCols = self::gradientsReady($conn)
                    ? 'c.gradient_start, c.gradient_end'
                    : 'NULL AS gradient_start, NULL AS gradient_end';
                $res = $conn->query(
                    "SELECT c.id, c.name, c.parent_id, c.image_path, c.sort_order, c.is_active,
                            $gradCols,
                            p.name AS parent_name,
                            (SELECT COUNT(*) FROM mezmur_hymn_categories hc
                             JOIN mezmur_hymns h ON h.id = hc.hymn_id AND h.status = 'active'
                             WHERE hc.category_id = c.id) AS hymn_count,
                            (SELECT COUNT(*) FROM mezmur_hymn_categories hc
                             JOIN mezmur_hymns h ON h.id = hc.hymn_id AND h.status = 'active'
                             JOIN mezmur_categories sc ON sc.id = hc.category_id
                             WHERE sc.id = c.id OR sc.parent_id = c.id) AS hymn_count_total
                     FROM mezmur_categories c
                     LEFT JOIN mezmur_categories p ON p.id = c.parent_id
                     ORDER BY c.parent_id IS NOT NULL, c.parent_id, c.sort_order, c.name
                     LIMIT 400");
                if ($res) {
                    while ($r = $res->fetch_assoc()) {
                        $r['id'] = (int)$r['id'];
                        $r['parent_id'] = $r['parent_id'] === null ? null : (int)$r['parent_id'];
                        $r['sort_order'] = (int)$r['sort_order'];
                        $r['is_active'] = (int)$r['is_active'];
                        $r['hymn_count'] = (int)$r['hymn_count'];
                        $r['hymn_count_total'] = (int)$r['hymn_count_total'];
                        $r['gradient_start'] = $r['gradient_start'] ?: null;
                        $r['gradient_end'] = $r['gradient_end'] ?: null;
                        $r['image_url'] = self::categoryImageUrl($r['image_path']);
                        $out[] = $r;
                    }
                }
                return $out;
            }
            // P28 (item 11): usage counts for the web catalog manager —
            // how many ACTIVE hymns reference each entry (indexed join,
            // bounded by the LIMIT above).
            $res = $conn->query(
                "SELECT c.id, c.name, c.sort_order, c.is_active,
                        (SELECT COUNT(*) FROM mezmur_hymn_categories hc
                         JOIN mezmur_hymns h ON h.id = hc.hymn_id AND h.status = 'active'
                         WHERE hc.category_id = c.id) AS hymn_count
                 FROM mezmur_categories c ORDER BY c.sort_order, c.name LIMIT 200");
            if ($res) {
                while ($r = $res->fetch_assoc()) {
                    $r['id'] = (int)$r['id'];
                    $r['sort_order'] = (int)$r['sort_order'];
                    $r['is_active'] = (int)$r['is_active'];
                    $r['hymn_count'] = (int)$r['hymn_count'];
                    $out[] = $r;
                }
            }
            return $out;
        }
        $out = [];
        $res = $conn->query("SELECT DISTINCT category FROM mezmur_hymns WHERE category <> '' AND category <> 'general' ORDER BY category LIMIT 200");
        if ($res) {
            $i = 0;
            while ($c = $res->fetch_assoc()) {
                $out[] = ['id' => 0, 'name' => $c['category'], 'sort_order' => ++$i, 'is_active' => 1, 'hymn_count' => 0];
            }
        }
        return $out;
    }

    /**
     * Create or rename a category. @return array{ok:bool,item?:array,message:string}
     * @param array{id?:int|string,name?:string,sort_order?:int|string} $input
     */
    public static function saveCategory(\mysqli $conn, array $input, int $actorId): array
    {
        if (!self::categoriesReady($conn)) {
            return ['ok' => false, 'message' => 'Category tables are not ready. Ask the administrator to run sql/025 or press Sync DB schema.'];
        }
        $id = (int)($input['id'] ?? 0);
        $name = trim((string)($input['name'] ?? ''));
        if ($name === '' || mb_strlen($name) > 50) {
            return ['ok' => false, 'message' => 'Category name is required (max 50 characters).'];
        }
        $sortOrder = max(0, min(10000, (int)($input['sort_order'] ?? 0)));
        // P32 gradient cover colors (ignored mid-upgrade pre-035).
        // Empty strings CLEAR the colors (back to the automatic
        // palette); absent keys leave them untouched.
        $gradReady = self::gradientsReady($conn);
        $wantsColors = $gradReady
            && (array_key_exists('gradient_start', $input) || array_key_exists('gradient_end', $input));
        $gradStart = $gradReady ? self::hexColorOrNull($input['gradient_start'] ?? '') : null;
        $gradEnd = $gradReady ? self::hexColorOrNull($input['gradient_end'] ?? '') : null;
        if ($gradStart === '@@invalid@@' || $gradEnd === '@@invalid@@') {
            return ['ok' => false, 'message' => 'Colors must be hex like #4f46e5 (opacity: #4f46e580).'];
        }

        // P30 two-level taxonomy: a sub carries parent_id; a main has
        // NULL. Depth is exactly 2 (a sub cannot become a parent), and
        // a parent must itself be a main. Pre-034 deployments ignore
        // parent_id entirely (column probe) — nothing breaks mid-
        // upgrade.
        $parentId = null;
        $twoLevel = self::twoLevelReady($conn);
        if ($twoLevel) {
            $parentId = (int)($input['parent_id'] ?? 0) > 0 ? (int)$input['parent_id'] : null;
            if ($parentId !== null) {
                $stmt = $conn->prepare("SELECT id, parent_id FROM mezmur_categories WHERE id = ? LIMIT 1");
                $stmt->bind_param('i', $parentId);
                $stmt->execute();
                $parent = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                if (!$parent) {
                    return ['ok' => false, 'message' => 'The parent category does not exist.'];
                }
                if ($parent['parent_id'] !== null) {
                    return ['ok' => false, 'message' => 'Sub-categories cannot have their own sub-categories (two levels maximum).'];
                }
            }
            if ($id > 0) {
                // Reparenting guard: a main that already has subs stays a main.
                $stmt = $conn->prepare("SELECT COUNT(*) c FROM mezmur_categories WHERE parent_id = ?");
                $stmt->bind_param('i', $id);
                $stmt->execute();
                $childCount = (int)$stmt->get_result()->fetch_assoc()['c'];
                $stmt->close();
                if ($childCount > 0 && $parentId !== null) {
                    return ['ok' => false, 'message' => 'This category has sub-categories, so it cannot become a sub-category itself.'];
                }
            }
        }

        // Uniqueness is scoped to the same level+parent (sql/034 unique
        // key backs this at the storage layer for the create race).
        if ($twoLevel) {
            $stmt = $conn->prepare("SELECT id, name, sort_order, is_active, parent_id FROM mezmur_categories WHERE LOWER(name) = LOWER(?) AND id <> ? AND parent_id <=> ? LIMIT 1");
            $stmt->bind_param('sii', $name, $id, $parentId);
        } else {
            $stmt = $conn->prepare("SELECT id, name, sort_order, is_active FROM mezmur_categories WHERE LOWER(name) = LOWER(?) AND id <> ? LIMIT 1");
            $stmt->bind_param('si', $name, $id);
        }
        $stmt->execute();
        $dup = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($dup && $id <= 0) {
            // P23 idempotent create: another device already created this
            // name. Link to the existing row (natural-key convergence)
            // instead of erroring — the old 422 made the device drop the
            // op and keep its placeholder, ending up with duplicate rows.
            return ['ok' => true, 'item' => ['id' => (int)$dup['id'], 'name' => (string)$dup['name'], 'sort_order' => (int)$dup['sort_order'], 'is_active' => (int)$dup['is_active']], 'message' => 'Category already exists — linked.'];
        }
        if ($dup) {
            return ['ok' => false, 'message' => 'A category with this name already exists.'];
        }

        if ($id > 0) {
            // Capture the previous state for the audit trail (before/after
            // on every administrative change — OWASP A09) and refuse no-ops.
            $stmt = $conn->prepare("SELECT name, sort_order, is_active" . ($twoLevel ? ", parent_id" : "") . ($gradReady ? ", gradient_start, gradient_end" : "") . " FROM mezmur_categories WHERE id = ? LIMIT 1");
            $stmt->bind_param('i', $id);
            $stmt->execute();
            $old = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if (!$old) {
                return ['ok' => false, 'message' => 'Category not found.'];
            }
            // P32: a cover-color change is a real change even when the
            // name and order are untouched (color-only edits).
            $colorsChanged = $wantsColors && (
                ($gradStart ?? null) !== (isset($old['gradient_start']) ? strtolower((string)$old['gradient_start']) : null)
                || ($gradEnd ?? null) !== (isset($old['gradient_end']) ? strtolower((string)$old['gradient_end']) : null)
            );
            if ($twoLevel && $parentId === null && $old['parent_id'] !== null) {
                // Editing a sub without parent_id means "keep its parent".
                $parentId = (int)$old['parent_id'];
            }
            $renamed = $old['name'] !== $name;
            if (!$renamed && (int)$old['sort_order'] === $sortOrder && !$colorsChanged) {
                return ['ok' => false, 'message' => 'Category not found or unchanged.'];
            }
            $relabelled = 0;
            $conn->begin_transaction();
            try {
                if ($twoLevel) {
                    $stmt = $conn->prepare("UPDATE mezmur_categories SET name=?, sort_order=?, parent_id=?, created_by=COALESCE(created_by, ?) WHERE id=?");
                    $stmt->bind_param('siiii', $name, $sortOrder, $parentId, $actorId, $id);
                } else {
                    $stmt = $conn->prepare("UPDATE mezmur_categories SET name=?, sort_order=?, created_by=COALESCE(created_by, ?) WHERE id=?");
                    $stmt->bind_param('siii', $name, $sortOrder, $actorId, $id);
                }
                $ok = $stmt->execute();
                $stmt->close();
                if (!$ok) {
                    throw new \RuntimeException('category update failed');
                }
                if ($wantsColors) {
                // color-only edits (and clearing back to auto) are
                // real changes — never a no-op.
                self::saveCategoryGradient($conn, $id, $gradStart, $gradEnd);
            }
            if ($renamed) {
                    // MZ-3: a rename must reach every hymn that carries the
                    // label. One statement relabels the legacy mirror string
                    // (the web badge and legacy clients read it) AND touches
                    // updated_at so the (updated_at, id) delta cursor emits a
                    // sync delta to every device. Deliberately NO revision
                    // bump: a relabel is not a content change, so offline
                    // editors must not be dragged into server-wins conflicts
                    // (their queued edits would be dropped).
                    $mir = $conn->prepare("UPDATE mezmur_hymns SET category=?, updated_at=NOW() WHERE category=?");
                    $mir->bind_param('ss', $name, $old['name']);
                    $mir->execute();
                    $relabelled = $mir->affected_rows;
                    $mir->close();
                }
                $conn->commit();
            } catch (\Throwable $e) {
                try { $conn->rollback(); } catch (\Throwable $r) {}
                throw $e;
            }
            self::audit($conn, 'Mezmur Category Renamed', [
                'from' => $old['name'],
                'to' => $name,
                'hymns_relabelled' => $relabelled,
            ], 'mezmur_category', $id, $actorId);
            // P23: echo the REAL is_active — the hardcoded 1 un-hid a
            // hidden category on the device that renamed it.
            return ['ok' => true, 'item' => ['id' => $id, 'name' => $name, 'sort_order' => $sortOrder, 'is_active' => (int)$old['is_active'], 'parent_id' => $twoLevel ? $parentId : null], 'message' => 'Category updated.'];
        }

        if ($twoLevel) {
            $stmt = $conn->prepare("INSERT INTO mezmur_categories (name, parent_id, sort_order, created_by) VALUES (?,?,?,?)");
            $stmt->bind_param('siii', $name, $parentId, $sortOrder, $actorId);
        } else {
            $stmt = $conn->prepare("INSERT INTO mezmur_categories (name, sort_order, created_by) VALUES (?,?,?)");
            $stmt->bind_param('sii', $name, $sortOrder, $actorId);
        }
        $ok = $stmt->execute();
        $newId = $ok ? (int)$stmt->insert_id : 0;
        if ($ok && $newId > 0 && $gradReady && ($gradStart !== null || $gradEnd !== null)) {
            self::saveCategoryGradient($conn, $newId, $gradStart, $gradEnd);
        }
        $dupRace = !$ok && self::isDuplicateKeyError($conn);
        $stmt->close();
        if ($dupRace) {
            // Scoped-unique create race (two devices, same name + parent):
            // converge to the winner, same as the slow path above.
            $stmt = $conn->prepare("SELECT id FROM mezmur_categories WHERE LOWER(name) = LOWER(?) AND parent_id <=> ? LIMIT 1");
            $stmt->bind_param('si', $name, $parentId);
            $stmt->execute();
            $winner = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if ($winner) {
                return ['ok' => true, 'item' => ['id' => (int)$winner['id'], 'name' => $name, 'sort_order' => $sortOrder, 'is_active' => 1, 'parent_id' => $parentId], 'message' => 'Category already exists — linked.'];
            }
        }
        if (!$ok || $newId <= 0) {
            return ['ok' => false, 'message' => 'Unable to create the category.'];
        }
        self::audit($conn, 'Mezmur Category Created', ['name' => $name, 'parent_id' => $parentId], 'mezmur_category', $newId, $actorId);
        return ['ok' => true, 'item' => ['id' => $newId, 'name' => $name, 'sort_order' => $sortOrder, 'is_active' => 1, 'parent_id' => $parentId], 'message' => 'Category added.'];
    }

    /** Activate / deactivate a category (soft). @return array{ok:bool,message:string} */
    public static function setCategoryStatus(\mysqli $conn, int $id, bool $active, int $actorId): array
    {
        if (!self::categoriesReady($conn)) {
            return ['ok' => false, 'message' => 'Category tables are not ready. Ask the administrator to run sql/025 or press Sync DB schema.'];
        }
        if ($id <= 0) {
            return ['ok' => false, 'message' => 'Invalid category id.'];
        }
        $stmt = $conn->prepare("UPDATE mezmur_categories SET is_active=? WHERE id=?");
        $flag = $active ? 1 : 0;
        $stmt->bind_param('ii', $flag, $id);
        $ok = $stmt->execute();
        $affected = $stmt->affected_rows;
        $stmt->close();
        if (!$ok || $affected === 0) {
            return ['ok' => false, 'message' => 'Category not found or unchanged.'];
        }
        self::audit($conn, $active ? 'Mezmur Category Activated' : 'Mezmur Category Deactivated', [], 'mezmur_category', $id, $actorId);
        return ['ok' => true, 'message' => $active ? 'Category restored.' : 'Category hidden.'];
    }

    // ── zemarians (singers / artists) ─────────────────────────

    private static ?bool $hasZemarianCache = null;

    private static function zemariansReady(\mysqli $conn): bool
    {
        if (self::$hasZemarianCache === null) {
            $ok = false;
            try {
                $r = $conn->query("SELECT 1 FROM mezmur_zemarians LIMIT 0");
                $ok = $r !== false;
                if ($r) { $r->close(); }
            } catch (\Throwable $e) { $ok = false; }
            self::$hasZemarianCache = $ok;
        }
        return self::$hasZemarianCache;
    }

    /** @return list<array<string,mixed>> */
    public static function listZemarians(\mysqli $conn): array
    {
        if (!self::zemariansReady($conn)) return [];
        $out = [];
        // P28 (item 11): usage counts for the web catalog manager.
        $res = $conn->query(
            "SELECT z.id, z.name, z.name_am, z.image_path, z.sort_order, z.is_active,
                    (SELECT COUNT(*) FROM mezmur_hymn_zemarians hz
                     JOIN mezmur_hymns h ON h.id = hz.hymn_id AND h.status = 'active'
                     WHERE hz.zemarian_id = z.id) AS hymn_count
             FROM mezmur_zemarians z ORDER BY z.sort_order, z.name LIMIT 500");
            // LIMIT 500: singers are a small canonical list, fully
            // refreshed on every device pull and in the dropdowns — the
            // bound keeps the response bounded if a deployment ever
            // balloons (audited P36; scale decision, not a leak).
        if ($res) {
            while ($r = $res->fetch_assoc()) {
                $r['id'] = (int)$r['id'];
                $r['sort_order'] = (int)$r['sort_order'];
                $r['is_active'] = (int)$r['is_active'];
                $r['hymn_count'] = (int)$r['hymn_count'];
                $r['image_url'] = self::categoryImageUrl($r['image_path'] ?? null);
                unset($r['image_path']);
                $out[] = $r;
            }
        }
        return $out;
    }

    /** @param array{id?:int|string,name?:string,name_am?:string,sort_order?:int|string} $input */
    public static function saveZemarian(\mysqli $conn, array $input, int $actorId): array
    {
        if (!self::zemariansReady($conn)) {
            return ['ok' => false, 'message' => 'Singer tables are not ready. Ask the administrator to run sql/030.'];
        }
        $id = (int)($input['id'] ?? 0);
        $name = trim((string)($input['name'] ?? ''));
        $nameAm = trim((string)($input['name_am'] ?? ''));
        if ($name === '' || mb_strlen($name) > 100) {
            return ['ok' => false, 'message' => 'Singer name is required (max 100 characters).'];
        }
        $sortOrder = max(0, min(10000, (int)($input['sort_order'] ?? 0)));

        $stmt = $conn->prepare("SELECT id, name, name_am, sort_order, is_active FROM mezmur_zemarians WHERE LOWER(name) = LOWER(?) AND id <> ? LIMIT 1");
        $stmt->bind_param('si', $name, $id);
        $stmt->execute();
        $dup = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($dup && $id <= 0) {
            // P23 idempotent create (see saveCategory).
            return ['ok' => true, 'item' => ['id' => (int)$dup['id'], 'name' => (string)$dup['name'], 'name_am' => $dup['name_am'], 'sort_order' => (int)$dup['sort_order'], 'is_active' => (int)$dup['is_active']], 'message' => 'Singer already exists — linked.'];
        }
        if ($dup) {
            return ['ok' => false, 'message' => 'A singer with this name already exists.'];
        }
        // P35: singers carry ONE name, written in Amharic. When a client
        // sends no separate Amharic value it mirrors the name, so name_am
        // is never empty/NULL while name stays the canonical filter field.
        if ($nameAm === '') $nameAm = $name;

        if ($id > 0) {
            // Capture previous names for the audit trail (before/after)
            // and fail honestly when the row does not exist — previously a
            // rename of a missing id reported success (affected_rows 0
            // was never checked) and left no audit entry at all (MZ-7).
            $stmt = $conn->prepare("SELECT name, name_am, is_active FROM mezmur_zemarians WHERE id = ? LIMIT 1");
            $stmt->bind_param('i', $id);
            $stmt->execute();
            $old = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if (!$old) {
                return ['ok' => false, 'message' => 'Singer not found.'];
            }
            $stmt = $conn->prepare("UPDATE mezmur_zemarians SET name=?, name_am=?, sort_order=? WHERE id=?");
            $stmt->bind_param('ssii', $name, $nameAm, $sortOrder, $id);
            $ok = $stmt->execute();
            $stmt->close();
            if (!$ok) return ['ok' => false, 'message' => 'Unable to update the singer.'];
            self::audit($conn, 'Mezmur Singer Renamed', [
                'from' => $old['name'],
                'to' => $name,
                'name_am' => $nameAm,
            ], 'mezmur_zemarian', $id, $actorId);
            // P23: echo the REAL is_active (was hardcoded 1).
            return ['ok' => true, 'item' => ['id' => $id, 'name' => $name, 'name_am' => $nameAm, 'sort_order' => $sortOrder, 'is_active' => (int)$old['is_active']], 'message' => 'Singer updated.'];
        }

        $stmt = $conn->prepare("INSERT INTO mezmur_zemarians (name, name_am, sort_order, created_by) VALUES (?,?,?,?)");
        $stmt->bind_param('ssii', $name, $nameAm, $sortOrder, $actorId);
        $ok = $stmt->execute();
        $newId = $ok ? (int)$stmt->insert_id : 0;
        $stmt->close();
        if (!$ok || $newId <= 0) return ['ok' => false, 'message' => 'Unable to add the singer.'];
        self::audit($conn, 'Mezmur Singer Created', ['name' => $name], 'mezmur_zemarian', $newId, $actorId);
        return ['ok' => true, 'item' => ['id' => $newId, 'name' => $name, 'name_am' => $nameAm, 'sort_order' => $sortOrder, 'is_active' => 1], 'message' => 'Singer added.'];
    }

    public static function setZemarianStatus(\mysqli $conn, int $id, bool $active, int $actorId): array
    {
        if (!self::zemariansReady($conn)) {
            return ['ok' => false, 'message' => 'Singer tables are not ready. Ask the administrator to run sql/030.'];
        }
        if ($id <= 0) return ['ok' => false, 'message' => 'Invalid singer id.'];
        $stmt = $conn->prepare("UPDATE mezmur_zemarians SET is_active=? WHERE id=?");
        $flag = $active ? 1 : 0;
        $stmt->bind_param('ii', $flag, $id);
        $ok = $stmt->execute();
        $affected = $stmt->affected_rows;
        $stmt->close();
        if (!$ok || $affected === 0) return ['ok' => false, 'message' => 'Singer not found or unchanged.'];
        self::audit($conn, $active ? 'Mezmur Singer Activated' : 'Mezmur Singer Deactivated', [], 'mezmur_zemarian', $id, $actorId);
        return ['ok' => true, 'message' => $active ? 'Singer restored.' : 'Singer hidden.'];
    }
}
