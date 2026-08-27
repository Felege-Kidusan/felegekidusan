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

        $stmt = $conn->prepare(
            "SELECT id, title, title_am, category, reference, status, updated_at
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
        $stmt = $conn->prepare("SELECT id, title, title_am, category, reference, lyrics, status, created_at, updated_at FROM mezmur_hymns WHERE id = ?");
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $item = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($item) $item['id'] = (int)$item['id'];
        return $item ?: null;
    }
}
