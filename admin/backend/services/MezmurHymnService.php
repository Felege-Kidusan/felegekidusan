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
        $category = trim((string)($filters['category'] ?? ''));
        $status = in_array($filters['status'] ?? 'active', ['active', 'archived', ''], true)
            ? ($filters['status'] ?? 'active') : 'active';

        $where = [];
        $types = '';
        $params = [];
        if ($status !== '') {
            $where[] = "status = ?";
            $types .= 's';
            $params[] = $status;
        }
        if ($category !== '') {
            $where[] = "category = ?";
            $types .= 's';
            $params[] = $category;
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
        $stmt = $conn->prepare(
            "SELECT id, title, title_am, category, reference, status, $rev, updated_at
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
        $stmt = $conn->prepare("SELECT id, title, title_am, category, reference, lyrics, status, $rev, created_at, updated_at FROM mezmur_hymns WHERE id = ?");
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $item = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($item) $item['id'] = (int)$item['id'];
        return $item ?: null;
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

    private static function auditHymn(\mysqli $conn, string $action, array $details, int $hymnId, int $actorId): void
    {
        // Bearer-token callers have no admin session; the acting uid is
        // carried in the detail payload so the trail stays complete.
        $details['actor'] = $actorId;
        try {
            \App\Services\SecurityAuditService::record($conn, $action, $details, 'mezmur_hymn', $hymnId);
        } catch (\Throwable $e) {
            error_log('[mezmur-hymn-audit] ' . $e->getMessage());
        }
    }

    /**
     * Create or update one hymn (web dashboard + mobile parity).
     *
     * Conflict rule (offline edits): when the client supplies
     * base_revision and the row moved past it, the write is refused
     * with the current server copy so the device can reconcile.
     *
     * @param array{id?:int|string,title?:string,title_am?:string,category?:string,reference?:string,lyrics?:string,status?:string,base_revision?:int|string|null} $input
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

        if ($title === '') {
            return ['ok' => false, 'message' => 'Title is required.'];
        }
        if (mb_strlen($title) > 255 || mb_strlen($titleAm) > 255 || mb_strlen($reference) > 255) {
            return ['ok' => false, 'message' => 'A field exceeds its maximum length.'];
        }
        if (mb_strlen($category) > 50) $category = mb_substr($category, 0, 50);
        if ($category === '') $category = 'general';
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
            $stmt = $conn->prepare(
                "UPDATE mezmur_hymns
                 SET title=?, title_am=?, category=?, reference=?, lyrics=?,
                     updated_by=?, updated_at=NOW(), revision = revision + 1
                 WHERE id=?"
            );
            $stmt->bind_param('sssssii', $title, $titleAm, $category, $reference, $lyrics, $actorId, $id);
            $ok = $stmt->execute();
            $stmt->close();
            if (!$ok) {
                return ['ok' => false, 'message' => 'Unable to update the hymn.'];
            }
            self::auditHymn($conn, 'Mezmur Hymn Updated', [
                'title' => mb_substr($title, 0, 255), 'category' => $category, 'source' => 'sync',
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
        $stmt = $conn->prepare(
            "INSERT INTO mezmur_hymns (title, title_am, category, reference, lyrics, status, created_by, updated_by)
             VALUES (?,?,?,?,?,?,?,?)"
        );
        $stmt->bind_param('ssssssii', $title, $titleAm, $category, $reference, $lyrics, $status, $actorId, $actorId);
        $ok = $stmt->execute();
        $newId = $ok ? (int)$stmt->insert_id : 0;
        $stmt->close();
        if (!$ok || $newId <= 0) {
            return ['ok' => false, 'message' => 'Unable to save the hymn.'];
        }
        self::auditHymn($conn, 'Mezmur Hymn Created', [
            'title' => mb_substr($title, 0, 255), 'category' => $category, 'source' => 'sync',
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
                "UPDATE mezmur_hymns SET status=?, updated_by=?, updated_at=NOW(), revision = revision + 1 WHERE id=?"
            );
        } catch (\Throwable $e) {
            // Stale schema without revision column: plain status update.
            $stmt = $conn->prepare("UPDATE mezmur_hymns SET status=?, updated_by=?, updated_at=NOW() WHERE id=?");
        }
        $stmt->bind_param('sii', $status, $actorId, $id);
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

        $cursorTs = null;
        $cursorId = 0;
        $parts = explode('|', trim($cursor));
        if (count($parts) === 2 && preg_match('/^\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}:\d{2}$/', $parts[0])) {
            $cursorTs = str_replace('T', ' ', $parts[0]);
            $cursorId = max(0, (int)$parts[1]);
        }

        if ($cursorTs !== null) {
            $sql = "SELECT id, title, title_am, category, reference, status, $rev,
                           DATE_FORMAT(updated_at, '%Y-%m-%d %H:%i:%s') AS updated_at, $lyricsCol
                    FROM mezmur_hymns
                    WHERE updated_at > ? OR (updated_at = ? AND id > ?)
                    ORDER BY updated_at ASC, id ASC
                    LIMIT ?";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param('ssii', $cursorTs, $cursorTs, $cursorId, $limit);
        } else {
            $sql = "SELECT id, title, title_am, category, reference, status, $rev,
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
            $stmt = $conn->prepare("UPDATE mezmur_categories SET name=?, sort_order=?, created_by=COALESCE(created_by, ?) WHERE id=?");
            $stmt->bind_param('siii', $name, $sortOrder, $actorId, $id);
            $ok = $stmt->execute();
            $affected = $stmt->affected_rows;
            $stmt->close();
            if (!$ok || $affected === 0) {
                return ['ok' => false, 'message' => 'Category not found or unchanged.'];
            }
            try {
                \App\Services\SecurityAuditService::record($conn, 'Mezmur Category Renamed', ['name' => $name, 'actor' => $actorId], 'mezmur_category', $id);
            } catch (\Throwable $e) { /* fail-soft */ }
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
        try {
            \App\Services\SecurityAuditService::record($conn, 'Mezmur Category Created', ['name' => $name, 'actor' => $actorId], 'mezmur_category', $newId);
        } catch (\Throwable $e) { /* fail-soft */ }
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
        try {
            \App\Services\SecurityAuditService::record($conn, $active ? 'Mezmur Category Activated' : 'Mezmur Category Deactivated', ['actor' => $actorId], 'mezmur_category', $id);
        } catch (\Throwable $e) { /* fail-soft */ }
        return ['ok' => true, 'message' => $active ? 'Category restored.' : 'Category hidden.'];
    }
}
