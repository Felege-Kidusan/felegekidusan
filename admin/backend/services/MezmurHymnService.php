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
        $category = mb_substr(trim((string)($filters['category'] ?? '')), 0, 50);
        $status = in_array($filters['status'] ?? 'active', ['active', 'archived', ''], true)
            ? ($filters['status'] ?? 'active') : 'active';
        $length = in_array($filters['length'] ?? '', ['long', 'short'], true)
            ? (string)($filters['length'] ?? '') : '';
        $language = in_array($filters['language'] ?? '', ['geez', 'amharic'], true)
            ? (string)($filters['language'] ?? '') : '';
        $categoryId = max(0, (int)($filters['category_id'] ?? 0));
        $zemarianId = max(0, (int)($filters['zemarian_id'] ?? 0));

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
            $where[] = "EXISTS (SELECT 1 FROM mezmur_hymn_categories mhc WHERE mhc.hymn_id = mezmur_hymns.id AND mhc.category_id = ?)";
            $types .= 'i';
            $params[] = $categoryId;
        }
        if ($zemarianId > 0) {
            $where[] = "EXISTS (SELECT 1 FROM mezmur_hymn_zemarians mhz WHERE mhz.hymn_id = mezmur_hymns.id AND mhz.zemarian_id = ?)";
            $types .= 'i';
            $params[] = $zemarianId;
        }
        if ($search !== '') {
            $like = '%' . self::escapeLike(mb_substr($search, 0, 100)) . '%';
            $where[] = "(title LIKE ? OR title_am LIKE ? OR reference LIKE ?)";
            $types .= 'sss';
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
        }
        $whereSql = $where ? implode(' AND ', $where) : '1=1';

        $stmt = $conn->prepare("SELECT COUNT(*) c FROM mezmur_hymns WHERE $whereSql");
        if ($types !== '') $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $total = (int)$stmt->get_result()->fetch_assoc()['c'];
        $stmt->close();

        $totalPages = max(1, (int)ceil($total / $perPage));
        $page = min($page, $totalPages);
        $offset = ($page - 1) * $perPage;

        $rev = self::revisionExpr($conn);
        $tax = self::taxonomyCols($conn);
        $stmt = $conn->prepare(
            "SELECT id, title, title_am, category, reference, status, $rev, $tax, updated_at
             FROM mezmur_hymns WHERE $whereSql
             ORDER BY updated_at DESC, id DESC LIMIT ? OFFSET ?"
        );
        $stmt->bind_param($types . 'ii', ...array_merge($params, [$perPage, $offset]));
        $stmt->execute();
        $res = $stmt->get_result();
        $items = [];
        while ($r = $res->fetch_assoc()) {
            $r['id'] = (int)$r['id'];
            $items[] = $r;
        }
        $stmt->close();

        $ids = array_map(static fn ($i) => (int)$i['id'], $items);
        $taxonomy = self::attachTaxonomyBulk($conn, $ids);
        foreach ($items as &$it) {
            $it['categories'] = $taxonomy[(int)$it['id']]['categories'] ?? [];
            $it['zemarians'] = $taxonomy[(int)$it['id']]['zemarians'] ?? [];
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
        $stmt = $conn->prepare("SELECT id, title, title_am, category, reference, lyrics, status, $rev, $tax, created_at, updated_at FROM mezmur_hymns WHERE id = ?");
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $item = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$item) return null;
        $item['id'] = (int)$item['id'];
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
     * Resolve the legacy single `category` name into a canonical category
     * id (creating the row if absent), so old clients keep working.
     */
    private static function resolveLegacyCategoryId(\mysqli $conn, string $name): ?int
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
     * @param array{id?:int|string,title?:string,title_am?:string,category?:string,reference?:string,lyrics?:string,status?:string,length?:string,language?:string,categories?:mixed,zemarians?:mixed,base_revision?:int|string|null} $input
     * @return array{ok:bool,conflict?:bool,item?:array|null,message:string,created?:bool}
     */
    public static function saveHymn(\mysqli $conn, array $input, int $actorId): array
    {
        $id        = (int)($input['id'] ?? 0);
        $title     = trim((string)($input['title'] ?? ''));
        $titleAm   = trim((string)($input['title_am'] ?? ''));
        $category  = trim((string)($input['category'] ?? ''));
        $reference = trim((string)($input['reference'] ?? ''));
        $lyrics    = (string)($input['lyrics'] ?? '');
        $length    = (string)($input['length'] ?? 'long');
        $language  = (string)($input['language'] ?? 'amharic');

        if (!in_array($length, ['long', 'short'], true)) $length = 'long';
        if (!in_array($language, ['geez', 'amharic'], true)) $language = 'amharic';

        $categoryIds = self::normalizeIds($input['categories'] ?? null);
        $zemarianIds = self::normalizeIds($input['zemarians'] ?? null);

        // Legacy single-name input becomes a category id (creates it).
        if (!$categoryIds && $category !== '') {
            $legacyId = self::resolveLegacyCategoryId($conn, $category);
            if ($legacyId !== null) $categoryIds = [$legacyId];
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
        if (mb_strlen($title) > 255 || mb_strlen($titleAm) > 255 || mb_strlen($reference) > 255) {
            return ['ok' => false, 'message' => 'A field exceeds its maximum length.'];
        }
        if (mb_strlen($categoryName) > 50) $categoryName = mb_substr($categoryName, 0, 50);
        if (mb_strlen($lyrics) > 200000) {
            return ['ok' => false, 'message' => 'Lyrics text is too long.'];
        }
        $titleAm   = $titleAm === '' ? null : $titleAm;
        $reference = $reference === '' ? null : $reference;
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
                         SET title=?, title_am=?, category=?, reference=?, lyrics=?, length=?, language=?,
                             updated_by=?, updated_at=NOW(), revision = revision + 1
                         WHERE id=? AND revision = ?"
                    );
                    $stmt->bind_param('sssssssiii', $title, $titleAm, $categoryName, $reference, $lyrics, $length, $language, $actorId, $id, $baseRevision);
                } else {
                    $stmt = $conn->prepare(
                        "UPDATE mezmur_hymns
                         SET title=?, title_am=?, category=?, reference=?, lyrics=?, length=?, language=?,
                             updated_by=?, updated_at=NOW(), revision = revision + 1
                         WHERE id=?"
                    );
                    $stmt->bind_param('sssssssii', $title, $titleAm, $categoryName, $reference, $lyrics, $length, $language, $actorId, $id);
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
            $stmt = $conn->prepare(
                "INSERT INTO mezmur_hymns (title, title_am, category, reference, lyrics, length, language, status, created_by, updated_by)
                 VALUES (?,?,?,?,?,?,?,?,?,?)"
            );
            $stmt->bind_param('ssssssssii', $title, $titleAm, $categoryName, $reference, $lyrics, $length, $language, $status, $actorId, $actorId);
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

        $cursorTs = null;
        $cursorId = 0;
        $parts = explode('|', trim($cursor));
        if (count($parts) === 2 && preg_match('/^\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}:\d{2}$/', $parts[0])) {
            $cursorTs = str_replace('T', ' ', $parts[0]);
            $cursorId = max(0, (int)$parts[1]);
        }

        if ($cursorTs !== null) {
            $sql = "SELECT id, title, title_am, category, reference, status, $rev, $taxCols,
                           DATE_FORMAT(updated_at, '%Y-%m-%d %H:%i:%s') AS updated_at, $lyricsCol
                    FROM mezmur_hymns
                    WHERE updated_at > ? OR (updated_at = ? AND id > ?)
                    ORDER BY updated_at ASC, id ASC
                    LIMIT ?";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param('ssii', $cursorTs, $cursorTs, $cursorId, $limit);
        } else {
            $sql = "SELECT id, title, title_am, category, reference, status, $rev, $taxCols,
                           DATE_FORMAT(updated_at, '%Y-%m-%d %H:%i:%s') AS updated_at, $lyricsCol
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
    public static function listCategories(\mysqli $conn): array
    {
        if (self::categoriesReady($conn)) {
            $out = [];
            $res = $conn->query("SELECT id, name, sort_order, is_active FROM mezmur_categories ORDER BY sort_order, name LIMIT 200");
            if ($res) {
                while ($r = $res->fetch_assoc()) {
                    $r['id'] = (int)$r['id'];
                    $r['sort_order'] = (int)$r['sort_order'];
                    $r['is_active'] = (int)$r['is_active'];
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
                $out[] = ['id' => 0, 'name' => $c['category'], 'sort_order' => ++$i, 'is_active' => 1];
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

        $stmt = $conn->prepare("SELECT id FROM mezmur_categories WHERE LOWER(name) = LOWER(?) AND id <> ? LIMIT 1");
        $stmt->bind_param('si', $name, $id);
        $stmt->execute();
        $dup = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($dup) {
            return ['ok' => false, 'message' => 'A category with this name already exists.'];
        }

        if ($id > 0) {
            // Capture the previous state for the audit trail (before/after
            // on every administrative change — OWASP A09) and refuse no-ops.
            $stmt = $conn->prepare("SELECT name, sort_order FROM mezmur_categories WHERE id = ? LIMIT 1");
            $stmt->bind_param('i', $id);
            $stmt->execute();
            $old = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if (!$old) {
                return ['ok' => false, 'message' => 'Category not found.'];
            }
            $renamed = $old['name'] !== $name;
            if (!$renamed && (int)$old['sort_order'] === $sortOrder) {
                return ['ok' => false, 'message' => 'Category not found or unchanged.'];
            }
            $relabelled = 0;
            $conn->begin_transaction();
            try {
                $stmt = $conn->prepare("UPDATE mezmur_categories SET name=?, sort_order=?, created_by=COALESCE(created_by, ?) WHERE id=?");
                $stmt->bind_param('siii', $name, $sortOrder, $actorId, $id);
                $ok = $stmt->execute();
                $stmt->close();
                if (!$ok) {
                    throw new \RuntimeException('category update failed');
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
            return ['ok' => true, 'item' => ['id' => $id, 'name' => $name, 'sort_order' => $sortOrder, 'is_active' => 1], 'message' => 'Category updated.'];
        }

        $stmt = $conn->prepare("INSERT INTO mezmur_categories (name, sort_order, created_by) VALUES (?,?,?)");
        $stmt->bind_param('sii', $name, $sortOrder, $actorId);
        $ok = $stmt->execute();
        $newId = $ok ? (int)$stmt->insert_id : 0;
        $stmt->close();
        if (!$ok || $newId <= 0) {
            return ['ok' => false, 'message' => 'Unable to create the category.'];
        }
        self::audit($conn, 'Mezmur Category Created', ['name' => $name], 'mezmur_category', $newId, $actorId);
        return ['ok' => true, 'item' => ['id' => $newId, 'name' => $name, 'sort_order' => $sortOrder, 'is_active' => 1], 'message' => 'Category added.'];
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
        $res = $conn->query("SELECT id, name, name_am, sort_order, is_active FROM mezmur_zemarians ORDER BY sort_order, name LIMIT 500");
        if ($res) {
            while ($r = $res->fetch_assoc()) {
                $r['id'] = (int)$r['id'];
                $r['sort_order'] = (int)$r['sort_order'];
                $r['is_active'] = (int)$r['is_active'];
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

        $stmt = $conn->prepare("SELECT id FROM mezmur_zemarians WHERE LOWER(name) = LOWER(?) AND id <> ? LIMIT 1");
        $stmt->bind_param('si', $name, $id);
        $stmt->execute();
        $dup = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($dup) {
            return ['ok' => false, 'message' => 'A singer with this name already exists.'];
        }
        $nameAm = $nameAm === '' ? null : $nameAm;

        if ($id > 0) {
            // Capture previous names for the audit trail (before/after)
            // and fail honestly when the row does not exist — previously a
            // rename of a missing id reported success (affected_rows 0
            // was never checked) and left no audit entry at all (MZ-7).
            $stmt = $conn->prepare("SELECT name, name_am FROM mezmur_zemarians WHERE id = ? LIMIT 1");
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
            return ['ok' => true, 'item' => ['id' => $id, 'name' => $name, 'name_am' => $nameAm, 'sort_order' => $sortOrder, 'is_active' => 1], 'message' => 'Singer updated.'];
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
