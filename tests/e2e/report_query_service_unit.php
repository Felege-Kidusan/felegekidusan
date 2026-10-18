<?php
/**
 * Unit checks for the Reporting Centre V2 registry + query builder.
 * Runs WITHOUT a database: only SQL construction and validation are tested.
 *
 *   php tests/e2e/report_query_service_unit.php
 *
 * Exit code 0 = all assertions passed. Prints JSON summary on STDOUT.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../admin/backend/reporting/ReportRegistry.php';
require_once __DIR__ . '/../../admin/backend/reporting/ReportQueryService.php';

use App\Reporting\ReportQueryService;
use App\Reporting\ReportRegistry;

$failures = [];
$checks = 0;
$ok = static function (bool $cond, string $what) use (&$failures, &$checks): void {
    $checks++;
    if (!$cond) {
        $failures[] = $what;
    }
};
$throws = static function (callable $fn, string $what) use (&$failures, &$checks): void {
    $checks++;
    try {
        $fn();
        $failures[] = "expected exception: {$what}";
    } catch (InvalidArgumentException $e) {
        // expected
    }
};

// ── registry invariants ───────────────────────────────────────────────
$fields = ReportRegistry::fields();
$ok(count($fields) >= 45, 'registry has ≥45 fields');
foreach ($fields as $k => $f) {
    $ok(array_diff($f['export'], $f['view']) === [], "export ⊆ view for {$k}");
    $ok(in_array($f['type'], ['string', 'enum', 'int', 'date', 'bool', 'derived_enum'], true), "type known for {$k}");
    $ok($f['sql'] !== '' && stripos($f['sql'], ';') === false, "sql for {$k} has no statement separator");
    $ok(preg_match('/^[a-z0-9_]+$/', $k) === 1, "key {$k} is a safe identifier");
    if (in_array($f['type'], ['enum', 'derived_enum'], true)) {
        $ok(is_array($f['options']) && $f['options'] !== [], "enum {$k} has options");
    }
}
foreach (ReportRegistry::metrics() as $k => $m) {
    $ok(isset($m['definition_en']) && $m['definition_en'] !== '', "metric {$k} has a definition");
}
$ok(ReportRegistry::canView('created_by', 'info_dept') === false, 'created_by hidden from info_dept');
$ok(ReportRegistry::canView('emergency_phone', 'info_dept') === false, 'emergency_phone hidden from info_dept');
$ok(ReportRegistry::canExport('guardian_phone', 'info_dept') === true, 'info_dept may export guardian phone (owner decision 2026-10-18)');
$ok(ReportRegistry::canView('phone_primary', 'hr_dept') === true, 'hr sees phone');

// ── role gate ─────────────────────────────────────────────────────────
$throws(static fn () => new ReportQueryService(null, 'teacher'), 'teacher refused');
$throws(static fn () => new ReportQueryService(null, 'edu_dept'), 'edu_dept refused');

$svc = new ReportQueryService(null, 'info_dept');

// ── list SQL ──────────────────────────────────────────────────────────
$d = $svc->normalise([
    'filters' => [
        ['field' => 'status', 'op' => 'eq', 'value' => 'active'],
        ['field' => 'age_group', 'op' => 'in', 'value' => ['7_13', '14_17']],
        ['field' => 'full_name_am', 'op' => 'contains', 'value' => '50%_x'],
        ['field' => 'registered_at', 'op' => 'last_days', 'value' => 30],
        ['field' => 'is_enrolled', 'op' => 'is_false'],
    ],
    'columns' => ['member_code', 'full_name_am', 'current_class', 'created_by', 'nonexistent'],
    'sort' => [['field' => 'registered_at', 'dir' => 'DESC']],
    'page' => 2, 'page_size' => 500,
]);
$ok($d['page_size'] === ReportRegistry::MAX_PAGE_SIZE, 'page_size capped to 200');
$ok(!in_array('created_by', $d['columns'], true), 'created_by stripped for info_dept');
$ok(!in_array('nonexistent', $d['columns'], true), 'unknown column dropped');
$q = $svc->buildListSql($d);
$ok(str_contains($q['sql'], 'LEFT JOIN `class_enrollments` ce'), 'enrolment join attached when needed');
$ok(str_contains($q['sql'], 'LEFT JOIN `classes` cl'), 'classes join attached for current_class');
$ok(substr_count($q['sql'], 'LEFT JOIN `class_enrollments`') === 1, 'join attached once');
$ok(str_contains($q['sql'], 'LIMIT 200 OFFSET 200'), 'paging arithmetic');
$ok(in_array('%50\\%\\_x%', $q['params'], true), 'LIKE wildcards escaped');
$ok(in_array('7_13', $q['params'], true) && in_array('14_17', $q['params'], true), 'IN values bound');
$ok(substr_count($q['sql'], '?') === count($q['params']), 'placeholder count equals params');
$ok(!preg_match('/\b(DELETE|UPDATE|INSERT|DROP)\b/i', $q['sql']), 'read-only statement');
$ok(str_ends_with(trim($q['sql']), 'OFFSET 200'), 'no trailing injection surface');

// deep paging capped
$d2 = $svc->normalise(['page' => 999, 'page_size' => 200]);
$ok(($d2['page'] - 1) * $d2['page_size'] < ReportRegistry::MAX_LIST_ROWS, 'offset never exceeds 2000');

// list without joins has no join text
$q3 = $svc->buildListSql($svc->normalise(['columns' => ['member_code', 'gender']]));
$ok(!str_contains($q3['sql'], 'LEFT JOIN'), 'no join when not needed');

// ── validation refusals ───────────────────────────────────────────────
$throws(static fn () => $svc->normalise(['filters' => [['field' => 'created_by', 'op' => 'eq', 'value' => 1]]]), 'filter on hidden field refused');
$throws(static fn () => $svc->normalise(['filters' => [['field' => 'status', 'op' => 'contains', 'value' => 'a']]]), 'operator not valid for enum');
$throws(static fn () => $svc->normalise(['filters' => [['field' => 'status', 'op' => 'eq', 'value' => 'bogus']]]), 'enum value outside options');
$throws(static fn () => $svc->normalise(['filters' => [['field' => 'registered_at', 'op' => 'eq', 'value' => '2026-13-40']]]), 'bad date');
$throws(static fn () => $svc->normalise(['filters' => [['field' => 'age', 'op' => 'gt', 'value' => 'x']]]), 'non-numeric int');
$throws(static fn () => $svc->normalise(['filters' => [['field' => 'age', 'op' => 'in', 'value' => range(1, 60)]]]), 'IN list too long');
$throws(static fn () => $svc->normalise(['filters' => array_fill(0, 21, ['field' => 'status', 'op' => 'eq', 'value' => 'active'])]), 'too many filters');
$throws(static fn () => $svc->normalise(['group_by' => 'full_name_am']), 'group by non-groupable');
$throws(static fn () => $svc->normalise(['metrics' => ['hack']]), 'unknown metric');
$throws(static fn () => $svc->normalise(['dataset' => 'users']), 'unknown dataset');
$throws(static fn () => $svc->normalise(['filters' => [['field' => "m.id; DROP TABLE members", 'op' => 'eq', 'value' => 1]]]), 'sql in field key refused');

// ── aggregate / kpis ──────────────────────────────────────────────────
$a = $svc->buildAggregateSql($svc->normalise(['group_by' => 'age_group', 'metrics' => ['count', 'female_pct']]));
$ok(str_contains($a['sql'], 'GROUP BY `bucket`') && str_contains($a['sql'], 'LIMIT ' . ReportRegistry::MAX_GROUPS), 'aggregate grouped and capped');
$ok(str_contains($a['sql'], 'AS `female_pct`'), 'metric alias present');
$k = $svc->buildKpiSql($svc->normalise(['metrics' => ['count', 'promotion_eligible'], 'filters' => [['field' => 'registered_at', 'op' => 'this_ec_year']]]));
$ok(preg_match("/m\\.registered_at >= '\\d{4}-09-1[12]' AND m\\.registered_at < '\\d{4}-09-1[12]'/", $k['sql']) === 1, 'E.C. year window literal dates');

// ── Ethiopian year boundaries ─────────────────────────────────────────
$ok(ReportQueryService::ecYearStart(0, strtotime('2026-10-18')) === '2026-09-11', 'EC start 2026');
$ok(ReportQueryService::ecYearStart(0, strtotime('2026-09-10')) === '2025-09-11', 'before new year → previous');
$ok(ReportQueryService::ecYearStart(0, strtotime('2023-09-12')) === '2023-09-12', '2023 new year on 12 Sept (2024 leap)');
$ok(ReportQueryService::ecYearStart(0, strtotime('2023-09-11')) === '2022-09-11', '11 Sept 2023 still old year');
$ok(ReportQueryService::ecYearStart(-1, strtotime('2026-10-18')) === '2025-09-11', 'last EC year start');

// ── export normalisation uses export roles ────────────────────────────
$svcHr = new ReportQueryService(null, 'hr_dept');
$dx = $svcHr->normalise(['columns' => ['member_code', 'emergency_phone']], forExport: true);
$ok(in_array('emergency_phone', $dx['columns'], true), 'hr exports emergency phone');
$dx2 = $svc->normalise(['columns' => ['member_code', 'emergency_phone']], forExport: true);
$ok(!in_array('emergency_phone', $dx2['columns'], true), 'info cannot export emergency phone');
$ex = $svc->buildListSql($dx2, forExport: true);
$ok(str_contains($ex['sql'], 'LIMIT ' . ReportRegistry::MAX_EXPORT_ROWS), 'export capped at 5000');

// ── audit summary is PII-free ─────────────────────────────────────────
$s = ReportQueryService::auditSummary(['filters' => [['field' => 'full_name_am', 'op' => 'contains', 'value' => 'አበበ']], 'search' => 'secret']);
$ok(!str_contains(json_encode($s, JSON_UNESCAPED_UNICODE), 'አበበ') && !str_contains(json_encode($s), 'secret'), 'audit summary has no values');

// ── starters validate for every role that may see them ───────────────
foreach (ReportRegistry::starters() as $st) {
    foreach ($st['roles'] as $r) {
        try {
            (new ReportQueryService(null, $r))->normalise($st['definition']);
            $checks++;
        } catch (Throwable $e) {
            $failures[] = "starter {$st['key']} invalid for {$r}: " . $e->getMessage();
        }
    }
}

// ── schema for role contains no SQL ──────────────────────────────────
$schema = ReportRegistry::schemaForRole('info_dept');
$ok(!str_contains(json_encode($schema), 'm.'), 'schema payload leaks no SQL');

fwrite(STDOUT, json_encode(['checks' => $checks, 'failures' => $failures], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n");
exit($failures === [] ? 0 : 1);
