<?php
/**
 * ============================================================================
 * ReportQueryService — turns a validated report definition into ONE prepared
 * SELECT against the registry, under the caller's role.
 * ============================================================================
 *
 * Definition (JSON from the client, or a saved report):
 * {
 *   "dataset":  "members",
 *   "filters":  [{"field":"status","op":"eq","value":"active"}, ...],
 *   "search":   "free text (searchable fields only)",
 *   "columns":  ["member_code","full_name_am", ...],        // list / export
 *   "sort":     [{"field":"registered_at","dir":"desc"}],
 *   "page":     1,  "page_size": 50,
 *   "group_by": "age_group",                                  // aggregate
 *   "metrics":  ["count","active"]                            // aggregate / kpis
 * }
 *
 * Guarantees
 *  - Every identifier comes from ReportRegistry; unknown keys/operators → 400.
 *  - Fields the role may not VIEW are dropped (filters on them are refused).
 *  - Values are bound as parameters; LIKE wildcards are escaped.
 *  - Budgets: page_size ≤ 200, offset+page ≤ 2 000, groups ≤ 60, filters ≤ 20.
 *  - Pure SQL building is separated from execution so tests can pin the SQL
 *    without a database.
 */

declare(strict_types=1);

namespace App\Reporting;

use InvalidArgumentException;
use PDO;

final class ReportQueryService
{
    private ?PDO $pdo;
    private string $role;

    public function __construct(?PDO $pdo, string $role)
    {
        if (!in_array($role, ReportRegistry::READER_ROLES, true)) {
            throw new InvalidArgumentException('Role may not use the reporting centre.');
        }
        $this->pdo  = $pdo;
        $this->role = $role;
    }

    // ───────────────────────── public API ─────────────────────────

    /** Paged list with total count. */
    public function list(array $definition): array
    {
        $d   = $this->normalise($definition);
        $sql = $this->buildListSql($d);
        $cnt = $this->buildCountSql($d);

        $rows  = $this->fetchAll($sql['sql'], $sql['params']);
        $total = (int)($this->fetchOne($cnt['sql'], $cnt['params'])['total'] ?? 0);

        return [
            'columns'   => array_map(fn (string $k): array => $this->columnMeta($k), $d['columns']),
            'rows'      => $rows,
            'page'      => $d['page'],
            'page_size' => $d['page_size'],
            'total'     => $total,
            'truncated' => $total > ReportRegistry::MAX_LIST_ROWS,
            'max_rows'  => ReportRegistry::MAX_LIST_ROWS,
        ];
    }

    /** Group‑by aggregate: one row per bucket, ordered by count desc, capped. */
    public function aggregate(array $definition): array
    {
        $d = $this->normalise($definition);
        if ($d['group_by'] === null) {
            throw new InvalidArgumentException('group_by is required for aggregate.');
        }
        $q    = $this->buildAggregateSql($d);
        $rows = $this->fetchAll($q['sql'], $q['params']);
        $f    = ReportRegistry::field($d['group_by']);

        // Attach option labels for enum‑like fields so the UI never maps them itself.
        foreach ($rows as &$r) {
            $v = $r['bucket'];
            $r['label'] = $f['options'][$v] ?? ($v === null || $v === '' ? '—' : (string)$v);
            if ($f['type'] === 'bool') {
                $r['label'] = ((int)$v === 1) ? 'Yes' : 'No';
            }
        }
        unset($r);

        return [
            'group_by' => $this->columnMeta($d['group_by']),
            'metrics'  => $d['metrics'],
            'rows'     => $rows,
            'capped'   => count($rows) >= ReportRegistry::MAX_GROUPS,
        ];
    }

    /** KPI strip: all requested metrics over the filtered population. */
    public function kpis(array $definition): array
    {
        $d = $this->normalise($definition);
        $q = $this->buildKpiSql($d);
        $row = $this->fetchOne($q['sql'], $q['params']) ?: [];
        $out = [];
        $defs = ReportRegistry::metrics();
        foreach ($d['metrics'] as $m) {
            $out[] = [
                'key'        => $m,
                'label_en'   => $defs[$m]['label_en'],
                'label_am'   => $defs[$m]['label_am'],
                'value'      => $row[$m] ?? null,
                'definition' => $defs[$m]['definition_en'],
            ];
        }
        return ['metrics' => $out];
    }

    /** Distinct values of a groupable string field (for filter pickers). */
    public function options(string $fieldKey, string $prefix = ''): array
    {
        $f = ReportRegistry::field($fieldKey);
        if ($f === null || !ReportRegistry::canView($fieldKey, $this->role) || !$f['group'] || $f['type'] !== 'string') {
            throw new InvalidArgumentException('Field has no option list.');
        }
        $params = [];
        $where  = "{$f['sql']} IS NOT NULL AND {$f['sql']} <> ''";
        if ($prefix !== '') {
            $where .= " AND {$f['sql']} LIKE ?";
            $params[] = self::escapeLike(mb_substr($prefix, 0, 40)) . '%';
        }
        $sql = "SELECT {$f['sql']} AS v, COUNT(*) AS n FROM " . $this->fromClause(['members'], $f['requires'])
             . " WHERE {$where} GROUP BY v ORDER BY n DESC, v ASC LIMIT 100";
        return ['field' => $fieldKey, 'values' => $this->fetchAll($sql, $params)];
    }

    /** Open a streaming statement for exports (caller iterates). */
    public function openExport(array $definition): array
    {
        $d = $this->normalise($definition, forExport: true);
        $q = $this->buildListSql($d, forExport: true);
        if ($this->pdo === null) {
            throw new \RuntimeException('Database unavailable.');
        }
        $st = $this->pdo->prepare($q['sql']);
        $st->execute($q['params']);
        $pii = 0;
        foreach ($d['columns'] as $k) {
            if (ReportRegistry::field($k)['pii']) {
                $pii++;
            }
        }
        return [
            'statement'  => $st,
            'columns'    => array_map(fn (string $k): array => $this->columnMeta($k), $d['columns']),
            'pii_fields' => $pii,
            'definition' => $d,
        ];
    }

    /** Compact, PII‑free description for the audit log. */
    public static function auditSummary(array $definition): array
    {
        $filters = [];
        foreach ($definition['filters'] ?? [] as $f) {
            if (!is_array($f)) {
                continue;
            }
            $filters[] = ($f['field'] ?? '?') . ':' . ($f['op'] ?? '?');
        }
        return [
            'dataset'    => (string)($definition['dataset'] ?? 'members'),
            'filters'    => array_slice($filters, 0, ReportRegistry::MAX_FILTERS),
            'columns'    => count($definition['columns'] ?? []),
            'group_by'   => $definition['group_by'] ?? null,
            'search_len' => isset($definition['search']) ? mb_strlen((string)$definition['search']) : 0,
        ];
    }

    // ───────────────────────── SQL builders (pure) ─────────────────────────

    /** @return array{sql:string,params:array} */
    public function buildListSql(array $d, bool $forExport = false): array
    {
        $select = [];
        $joins  = [];
        foreach ($d['columns'] as $k) {
            $f = ReportRegistry::field($k);
            $select[] = "{$f['sql']} AS `{$k}`";
            $joins = array_merge($joins, $f['requires']);
        }
        [$where, $params, $wJoins] = $this->whereClause($d);
        $joins = array_merge($joins, $wJoins);

        $order = [];
        foreach ($d['sort'] as $s) {
            $f = ReportRegistry::field($s['field']);
            $joins[] = $f['requires'];
            $order[] = "{$f['sql']} {$s['dir']}";
        }
        $order[] = 'm.id ASC'; // deterministic paging
        $joins = $this->flattenJoins($joins);

        $sql = 'SELECT ' . implode(', ', $select)
             . ' FROM ' . $this->fromClause(['members'], $joins)
             . ($where !== '' ? " WHERE {$where}" : '')
             . ' ORDER BY ' . implode(', ', $order);

        if ($forExport) {
            $sql .= ' LIMIT ' . ReportRegistry::MAX_EXPORT_ROWS;
        } else {
            $offset = ($d['page'] - 1) * $d['page_size'];
            $sql .= " LIMIT {$d['page_size']} OFFSET {$offset}";
        }
        return ['sql' => $sql, 'params' => $params];
    }

    /** @return array{sql:string,params:array} */
    public function buildCountSql(array $d): array
    {
        [$where, $params, $joins] = $this->whereClause($d);
        $sql = 'SELECT COUNT(*) AS total FROM ' . $this->fromClause(['members'], $this->flattenJoins($joins))
             . ($where !== '' ? " WHERE {$where}" : '');
        return ['sql' => $sql, 'params' => $params];
    }

    /** @return array{sql:string,params:array} */
    public function buildAggregateSql(array $d): array
    {
        $g = ReportRegistry::field($d['group_by']);
        [$where, $params, $joins] = $this->whereClause($d);
        $joins = $this->flattenJoins(array_merge($joins, [$g['requires']]));
        $metrics = ReportRegistry::metrics();
        $sel = ["{$g['sql']} AS `bucket`"];
        foreach ($d['metrics'] as $m) {
            $sel[] = "{$metrics[$m]['sql']} AS `{$m}`";
        }
        $sql = 'SELECT ' . implode(', ', $sel)
             . ' FROM ' . $this->fromClause(['members'], $joins)
             . ($where !== '' ? " WHERE {$where}" : '')
             . ' GROUP BY `bucket` ORDER BY `' . $d['metrics'][0] . '` DESC, `bucket` ASC LIMIT ' . ReportRegistry::MAX_GROUPS;
        return ['sql' => $sql, 'params' => $params];
    }

    /** @return array{sql:string,params:array} */
    public function buildKpiSql(array $d): array
    {
        [$where, $params, $joins] = $this->whereClause($d);
        $metrics = ReportRegistry::metrics();
        $sel = [];
        foreach ($d['metrics'] as $m) {
            $sel[] = "{$metrics[$m]['sql']} AS `{$m}`";
        }
        $sql = 'SELECT ' . implode(', ', $sel)
             . ' FROM ' . $this->fromClause(['members'], $this->flattenJoins($joins))
             . ($where !== '' ? " WHERE {$where}" : '');
        return ['sql' => $sql, 'params' => $params];
    }

    // ───────────────────────── normalisation / validation ─────────────────────────

    /**
     * Validate a raw definition against the registry and the role.
     * Throws InvalidArgumentException with a user‑safe message.
     */
    public function normalise(array $raw, bool $forExport = false): array
    {
        $dataset = (string)($raw['dataset'] ?? 'members');
        if (!isset(ReportRegistry::datasets()[$dataset])) {
            throw new InvalidArgumentException('Unknown dataset.');
        }

        // Filters
        $filters = [];
        $rawFilters = $raw['filters'] ?? [];
        if (!is_array($rawFilters)) {
            throw new InvalidArgumentException('filters must be a list.');
        }
        if (count($rawFilters) > ReportRegistry::MAX_FILTERS) {
            throw new InvalidArgumentException('Too many filters (max ' . ReportRegistry::MAX_FILTERS . ').');
        }
        $ops = ReportRegistry::operators();
        foreach ($rawFilters as $fl) {
            if (!is_array($fl)) {
                throw new InvalidArgumentException('Malformed filter.');
            }
            $key = (string)($fl['field'] ?? '');
            $op  = (string)($fl['op'] ?? '');
            $f   = ReportRegistry::field($key);
            if ($f === null || !$f['filter']) {
                throw new InvalidArgumentException("Unknown or non‑filterable field: {$key}");
            }
            if (!ReportRegistry::canView($key, $this->role)) {
                throw new InvalidArgumentException("You may not filter on {$key}.");
            }
            if (!in_array($op, $ops[$f['type']], true)) {
                throw new InvalidArgumentException("Operator {$op} is not valid for {$key}.");
            }
            $filters[] = ['field' => $key, 'op' => $op, 'value' => $this->normaliseValue($f, $op, $fl['value'] ?? null)];
        }

        // Search
        $search = isset($raw['search']) ? trim((string)$raw['search']) : '';
        if (mb_strlen($search) > ReportRegistry::MAX_TEXT_LEN) {
            $search = mb_substr($search, 0, ReportRegistry::MAX_TEXT_LEN);
        }

        // Columns (view for list, export for export) — silently drop what the role may not see.
        $columns = [];
        $rawCols = $raw['columns'] ?? ReportRegistry::defaultColumns($this->role);
        if (!is_array($rawCols)) {
            throw new InvalidArgumentException('columns must be a list.');
        }
        foreach ($rawCols as $c) {
            $c = (string)$c;
            $f = ReportRegistry::field($c);
            if ($f === null || !$f['list']) {
                continue;
            }
            $ok = $forExport ? ReportRegistry::canExport($c, $this->role) : ReportRegistry::canView($c, $this->role);
            if ($ok && !in_array($c, $columns, true)) {
                $columns[] = $c;
            }
            if (count($columns) >= ReportRegistry::MAX_COLUMNS) {
                break;
            }
        }
        if ($columns === []) {
            $columns = ReportRegistry::defaultColumns($this->role);
        }

        // Sort
        $sort = [];
        foreach ((array)($raw['sort'] ?? []) as $s) {
            if (!is_array($s)) {
                continue;
            }
            $k = (string)($s['field'] ?? '');
            $f = ReportRegistry::field($k);
            if ($f === null || !$f['sort'] || !ReportRegistry::canView($k, $this->role)) {
                continue;
            }
            $dir = strtolower((string)($s['dir'] ?? 'asc')) === 'desc' ? 'DESC' : 'ASC';
            $sort[] = ['field' => $k, 'dir' => $dir];
            if (count($sort) >= 3) {
                break;
            }
        }

        // Paging
        $pageSize = (int)($raw['page_size'] ?? 50);
        $pageSize = max(1, min(ReportRegistry::MAX_PAGE_SIZE, $pageSize));
        $page     = max(1, (int)($raw['page'] ?? 1));
        if (($page - 1) * $pageSize >= ReportRegistry::MAX_LIST_ROWS) {
            $page = (int)ceil(ReportRegistry::MAX_LIST_ROWS / $pageSize);
        }

        // Group / metrics
        $groupBy = null;
        if (isset($raw['group_by']) && $raw['group_by'] !== '' && $raw['group_by'] !== null) {
            $g = (string)$raw['group_by'];
            $f = ReportRegistry::field($g);
            if ($f === null || !$f['group'] || !ReportRegistry::canView($g, $this->role)) {
                throw new InvalidArgumentException("Cannot group by {$g}.");
            }
            $groupBy = $g;
        }
        $metrics = [];
        $allMetrics = ReportRegistry::metrics();
        foreach ((array)($raw['metrics'] ?? ['count']) as $m) {
            $m = (string)$m;
            if (!isset($allMetrics[$m])) {
                throw new InvalidArgumentException("Unknown metric {$m}.");
            }
            if (!in_array($m, $metrics, true)) {
                $metrics[] = $m;
            }
        }
        if ($metrics === []) {
            $metrics = ['count'];
        }

        return [
            'dataset'   => $dataset,
            'filters'   => $filters,
            'search'    => $search,
            'columns'   => $columns,
            'sort'      => $sort,
            'page'      => $page,
            'page_size' => $pageSize,
            'group_by'  => $groupBy,
            'metrics'   => $metrics,
        ];
    }

    private function normaliseValue(array $f, string $op, mixed $v): mixed
    {
        if (in_array($op, ['is_empty', 'not_empty', 'is_true', 'is_false', 'this_ec_year', 'last_ec_year'], true)) {
            return null;
        }
        if ($op === 'last_days') {
            $n = (int)$v;
            if ($n < 1 || $n > 3660) {
                throw new InvalidArgumentException('last_days must be 1–3660.');
            }
            return $n;
        }
        if (in_array($op, ['in', 'not_in'], true)) {
            if (!is_array($v) || $v === []) {
                throw new InvalidArgumentException("{$op} needs a non‑empty list.");
            }
            if (count($v) > ReportRegistry::MAX_IN_VALUES) {
                throw new InvalidArgumentException('Too many values.');
            }
            return array_map(fn ($x) => $this->scalar($f, $x), array_values($v));
        }
        if ($op === 'between') {
            if (!is_array($v) || count($v) !== 2) {
                throw new InvalidArgumentException('between needs [from, to].');
            }
            return [$this->scalar($f, $v[0]), $this->scalar($f, $v[1])];
        }
        return $this->scalar($f, $v);
    }

    private function scalar(array $f, mixed $v): string|int
    {
        if (is_array($v) || is_object($v)) {
            throw new InvalidArgumentException('Scalar expected.');
        }
        switch ($f['type']) {
            case 'int':
                if (!is_numeric($v)) {
                    throw new InvalidArgumentException("{$f['key']} expects a number.");
                }
                return (int)$v;
            case 'date':
                $s = (string)$v;
                if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $s) || !checkdate((int)substr($s, 5, 2), (int)substr($s, 8, 2), (int)substr($s, 0, 4))) {
                    throw new InvalidArgumentException("{$f['key']} expects YYYY-MM-DD.");
                }
                return $s;
            case 'enum':
            case 'derived_enum':
                $s = (string)$v;
                if (!isset($f['options'][$s])) {
                    throw new InvalidArgumentException("Invalid value for {$f['key']}.");
                }
                return $s;
            default:
                $s = trim((string)$v);
                if (mb_strlen($s) > ReportRegistry::MAX_TEXT_LEN) {
                    $s = mb_substr($s, 0, ReportRegistry::MAX_TEXT_LEN);
                }
                return $s;
        }
    }

    // ───────────────────────── WHERE ─────────────────────────

    /** @return array{0:string,1:array,2:array} where, params, joins */
    private function whereClause(array $d): array
    {
        $parts  = [];
        $params = [];
        $joins  = [];
        foreach ($d['filters'] as $fl) {
            $f = ReportRegistry::field($fl['field']);
            $joins[] = $f['requires'];
            $x = $f['sql'];
            $v = $fl['value'];
            switch ($fl['op']) {
                case 'eq':          $parts[] = "{$x} = ?";  $params[] = $v; break;
                case 'neq':         $parts[] = "({$x} <> ? OR {$x} IS NULL)"; $params[] = $v; break;
                case 'gt':          $parts[] = "{$x} > ?";  $params[] = $v; break;
                case 'gte':         $parts[] = "{$x} >= ?"; $params[] = $v; break;
                case 'lt':          $parts[] = "{$x} < ?";  $params[] = $v; break;
                case 'lte':         $parts[] = "{$x} <= ?"; $params[] = $v; break;
                case 'between':     $parts[] = "{$x} BETWEEN ? AND ?"; $params[] = $v[0]; $params[] = $v[1]; break;
                case 'contains':    $parts[] = "{$x} LIKE ?"; $params[] = '%' . self::escapeLike((string)$v) . '%'; break;
                case 'starts_with': $parts[] = "{$x} LIKE ?"; $params[] = self::escapeLike((string)$v) . '%'; break;
                case 'in':
                case 'not_in':
                    $ph = implode(',', array_fill(0, count($v), '?'));
                    $parts[] = $fl['op'] === 'in' ? "{$x} IN ({$ph})" : "({$x} NOT IN ({$ph}) OR {$x} IS NULL)";
                    foreach ($v as $one) { $params[] = $one; }
                    break;
                case 'is_empty':    $parts[] = $f['type'] === 'string' ? "({$x} IS NULL OR {$x} = '')" : "{$x} IS NULL"; break;
                case 'not_empty':   $parts[] = $f['type'] === 'string' ? "({$x} IS NOT NULL AND {$x} <> '')" : "{$x} IS NOT NULL"; break;
                case 'is_true':     $parts[] = "{$x} = 1"; break;
                case 'is_false':    $parts[] = "COALESCE({$x}, 0) = 0"; break;
                case 'last_days':   $parts[] = "{$x} >= DATE_SUB(CURDATE(), INTERVAL ? DAY)"; $params[] = (int)$v; break;
                case 'this_ec_year': $parts[] = "{$x} >= " . self::sqlEcYearStart(0) . " AND {$x} < " . self::sqlEcYearStart(1); break;
                case 'last_ec_year': $parts[] = "{$x} >= " . self::sqlEcYearStart(-1) . " AND {$x} < " . self::sqlEcYearStart(0); break;
                default:
                    throw new InvalidArgumentException('Unsupported operator.');
            }
        }
        if ($d['search'] !== '') {
            $like = '%' . self::escapeLike($d['search']) . '%';
            $or = [];
            foreach (ReportRegistry::fields() as $k => $f) {
                if ($f['search'] && ReportRegistry::canView($k, $this->role)) {
                    $or[] = "{$f['sql']} LIKE ?";
                    $params[] = $like;
                }
            }
            if ($or !== []) {
                $parts[] = '(' . implode(' OR ', $or) . ')';
            }
        }
        return [implode(' AND ', $parts), $params, $joins];
    }

    /**
     * Gregorian DATE of the Ethiopian new year that started the current E.C.
     * year (+offset years). Ethiopian new year = 11 Sept, 12 Sept when the
     * following Gregorian year is leap. Computed in PHP (deterministic, tested).
     */
    public static function sqlEcYearStart(int $offset): string
    {
        return "'" . self::ecYearStart($offset) . "'";
    }

    public static function ecYearStart(int $offset, ?int $todayTs = null): string
    {
        $ts   = $todayTs ?? time();
        $y    = (int)date('Y', $ts);
        $start = self::newYearDate($y);
        if (date('Y-m-d', $ts) < $start) {
            $y--; // still in the E.C. year that started last September
        }
        return self::newYearDate($y + $offset);
    }

    private static function newYearDate(int $gregYear): string
    {
        $next = $gregYear + 1;
        $leapNext = ($next % 4 === 0 && $next % 100 !== 0) || $next % 400 === 0;
        return sprintf('%04d-09-%02d', $gregYear, $leapNext ? 12 : 11);
    }

    // ───────────────────────── plumbing ─────────────────────────

    private function fromClause(array $datasets, array $joins): string
    {
        $ds = ReportRegistry::datasets()['members'];
        $sql = $ds['base'];
        $needed = [];
        foreach ($this->flattenJoins([$joins]) as $j) {
            foreach ($ds['join_requires'][$j] ?? [] as $dep) {
                $needed[$dep] = true;
            }
            $needed[$j] = true;
        }
        foreach ($ds['joins'] as $alias => $clause) { // declared order = dependency order
            if (isset($needed[$alias])) {
                $sql .= ' ' . $clause;
            }
        }
        return $sql;
    }

    private function flattenJoins(array $nested): array
    {
        $out = [];
        array_walk_recursive($nested, static function ($v) use (&$out): void {
            if (is_string($v) && $v !== '' && !in_array($v, $out, true)) {
                $out[] = $v;
            }
        });
        return $out;
    }

    private function columnMeta(string $k): array
    {
        $f = ReportRegistry::field($k);
        return [
            'key' => $k, 'label_en' => $f['label_en'], 'label_am' => $f['label_am'],
            'type' => $f['type'], 'options' => $f['options'], 'pii' => $f['pii'],
            'export' => ReportRegistry::canExport($k, $this->role),
        ];
    }

    private function fetchAll(string $sql, array $params): array
    {
        if ($this->pdo === null) {
            throw new \RuntimeException('Database unavailable.');
        }
        $st = $this->pdo->prepare($sql);
        $st->execute($params);
        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    private function fetchOne(string $sql, array $params): ?array
    {
        if ($this->pdo === null) {
            throw new \RuntimeException('Database unavailable.');
        }
        $st = $this->pdo->prepare($sql);
        $st->execute($params);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    public static function escapeLike(string $v): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $v);
    }
}
