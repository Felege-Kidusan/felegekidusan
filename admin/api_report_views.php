<?php
/**
 * ============================================================================
 * Reporting Centre — saved views API (V2‑3).
 * ============================================================================
 *
 *   GET  ?action=list                      → views visible to the caller (mine / department / shared / pinned)
 *   GET  ?action=get&id=N                  → one view (visibility checked)
 *   POST action=save    {id?, name, description?, scope, definition(json), pinned?}
 *   POST action=delete  {id}
 *   POST action=pin     {id, pinned}        (super_admin / school_admin only)
 *   POST action=opened  {id}                (usage counter, best‑effort)
 *
 * Visibility:  private → owner (and super_admin/school_admin for maintenance)
 *              department → same role as the owner at save time, plus admins
 *              shared → all reporting‑centre roles
 * Edit/delete: owner, or super_admin/school_admin. Pinning: admins only.
 * Definitions are validated through ReportQueryService::normalise() with the
 * SAVER's role, so nothing a role cannot see can be stored under its name.
 * CSRF enforced on every POST. All writes audited.
 */
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/backend/services/SecurityRateLimiter.php';
require_once __DIR__ . '/backend/services/SecurityAuditService.php';
require_once __DIR__ . '/backend/reporting/ReportRegistry.php';
require_once __DIR__ . '/backend/reporting/ReportQueryService.php';

use App\Reporting\ReportQueryService;
use App\Reporting\ReportRegistry;
use App\Services\SecurityAuditService;

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$fail = static function (int $code, string $message): never {
    http_response_code($code);
    echo json_encode(['status' => 'error', 'message' => $message], JSON_UNESCAPED_UNICODE);
    exit;
};
$ok = static function (array $data): never {
    echo json_encode(['status' => 'success', 'data' => $data, 'generated_at' => date('c')], JSON_UNESCAPED_UNICODE);
    exit;
};

if (empty($_SESSION['admin_logged_in']) || empty($_SESSION['admin_id'])) {
    $fail(401, 'Unauthorized');
}
$adminId = (int)$_SESSION['admin_id'];
$role    = (string)($_SESSION['admin_role'] ?? '');
if (!in_array($role, ReportRegistry::READER_ROLES, true)) {
    $fail(403, 'You do not have permission to use the reporting centre.');
}
if (!isset($pdo) || !($pdo instanceof PDO)) {
    $fail(503, 'The report service is unavailable.');
}
$isAdmin = in_array($role, ['super_admin', 'school_admin'], true);
$method  = (string)($_SERVER['REQUEST_METHOD'] ?? 'GET');
$action  = (string)(($method === 'POST' ? ($_POST['action'] ?? '') : ($_GET['action'] ?? '')));

$readActions  = ['list', 'get'];
$writeActions = ['save', 'delete', 'pin', 'opened'];
if (!in_array($action, array_merge($readActions, $writeActions), true)) {
    $fail(400, 'Unknown action');
}
if (in_array($action, $writeActions, true) && $method !== 'POST') {
    $fail(405, 'Use POST for changes.');
}
if ($method === 'POST' && !validateCsrf($_POST['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''))) {
    $fail(403, 'Security token expired. Please refresh the page.');
}

$rl = new \App\Services\SecurityRateLimiter($pdo, sys_get_temp_dir() . '/ssms_ratelimit');
$check = $rl->consume($method === 'POST' ? 'report_views_write' : 'report_views_read', 'user:' . $adminId, $method === 'POST' ? 60 : 240, 60);
if (!$check['allowed']) {
    $fail(429, 'Too many requests. Please wait a moment and try again.');
}

const RV_MAX_DEFINITION_BYTES = 16384;
const RV_MAX_VIEWS_PER_USER   = 100;

/** The table is created by sql/070; until then the feature is reported as unavailable. */
function rvTableExists(PDO $pdo): bool
{
    static $exists = null;
    if ($exists === null) {
        $stmt = $pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'report_definitions'");
        $exists = (int)$stmt->fetchColumn() > 0;
    }
    return $exists;
}

/** Public shape of a row (definition decoded, no internals). */
function rvShape(array $r, int $adminId, bool $isAdmin): array
{
    $def = json_decode((string)$r['definition'], true);
    return [
        'id'          => (int)$r['id'],
        'name'        => (string)$r['name'],
        'description' => (string)($r['description'] ?? ''),
        'scope'       => (string)$r['scope'],
        'pinned'      => (int)$r['is_pinned'] === 1,
        'owner'       => (string)($r['owner_name'] ?? ''),
        'owner_role'  => (string)$r['owner_role'],
        'mine'        => (int)$r['owner_user_id'] === $adminId,
        'can_edit'    => $isAdmin || (int)$r['owner_user_id'] === $adminId,
        'run_count'   => (int)$r['run_count'],
        'updated_at'  => (string)$r['updated_at'],
        'definition'  => is_array($def) ? $def : [],
    ];
}

/** SQL fragment + params restricting rows to what the caller may see. */
function rvVisibilityWhere(int $adminId, string $role, bool $isAdmin): array
{
    if ($isAdmin) {
        return ['1 = 1', []];
    }
    return ["(rd.owner_user_id = ? OR rd.scope = 'shared' OR (rd.scope = 'department' AND rd.visible_role = ?))", [$adminId, $role]];
}

function rvFetch(PDO $pdo, int $id): ?array
{
    $stmt = $pdo->prepare('SELECT rd.*, u.full_name AS owner_name FROM report_definitions rd LEFT JOIN users u ON u.id = rd.owner_user_id WHERE rd.id = ? LIMIT 1');
    $stmt->execute([$id]);
    $r = $stmt->fetch(PDO::FETCH_ASSOC);
    return $r ?: null;
}

function rvCanSee(array $r, int $adminId, string $role, bool $isAdmin): bool
{
    if ($isAdmin || (int)$r['owner_user_id'] === $adminId || $r['scope'] === 'shared') {
        return true;
    }
    return $r['scope'] === 'department' && (string)$r['visible_role'] === $role;
}

try {
    if (!rvTableExists($pdo)) {
        // Degrade gracefully: the UI hides saved views and tells admins which migration to run.
        if ($action === 'list') {
            $ok(['available' => false, 'migration' => 'sql/070_report_definitions.sql', 'views' => []]);
        }
        $fail(503, 'Saved views are not set up yet (run sql/070_report_definitions.sql).');
    }

    switch ($action) {
        case 'list': {
            [$where, $params] = rvVisibilityWhere($adminId, $role, $isAdmin);
            $stmt = $pdo->prepare(
                "SELECT rd.*, u.full_name AS owner_name FROM report_definitions rd LEFT JOIN users u ON u.id = rd.owner_user_id
                  WHERE rd.dataset = 'members' AND {$where}
                  ORDER BY rd.is_pinned DESC, rd.sort_order ASC, rd.scope = 'shared' DESC, rd.name ASC LIMIT 300"
            );
            $stmt->execute($params);
            $views = [];
            while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $views[] = rvShape($r, $adminId, $isAdmin);
            }
            $ok(['available' => true, 'views' => $views, 'can_pin' => $isAdmin, 'max_per_user' => RV_MAX_VIEWS_PER_USER]);
        }
        // no break — $ok exits

        case 'get': {
            $r = rvFetch($pdo, (int)($_GET['id'] ?? 0));
            if ($r === null || !rvCanSee($r, $adminId, $role, $isAdmin)) {
                $fail(404, 'View not found.');
            }
            $ok(['view' => rvShape($r, $adminId, $isAdmin)]);
        }
        // no break

        case 'save': {
            $id    = (int)($_POST['id'] ?? 0);
            $name  = trim((string)($_POST['name'] ?? ''));
            $desc  = trim((string)($_POST['description'] ?? ''));
            $scope = (string)($_POST['scope'] ?? 'private');
            $raw   = (string)($_POST['definition'] ?? '');
            if ($name === '' || mb_strlen($name) > 120) {
                $fail(400, 'Give the view a name (1–120 characters).');
            }
            if (mb_strlen($desc) > 500) {
                $fail(400, 'Description is too long (max 500 characters).');
            }
            if (!in_array($scope, ['private', 'department', 'shared'], true)) {
                $fail(400, 'Invalid sharing scope.');
            }
            if (strlen($raw) > RV_MAX_DEFINITION_BYTES) {
                $fail(400, 'Report definition too large.');
            }
            $decoded = json_decode($raw, true);
            if (!is_array($decoded)) {
                $fail(400, 'Report definition must be a JSON object.');
            }
            // Validate with the saver's role; store the normalised, role‑safe shape.
            $svc = new ReportQueryService($pdo, $role);
            $n   = $svc->normalise($decoded);
            $definition = [
                'dataset'  => 'members',
                'filters'  => $n['filters'],
                'search'   => $n['search'],
                'columns'  => $n['columns'],
                'sort'     => $n['sort'],
                'group_by' => $n['group_by'],
                'metrics'  => $n['metrics'],
                'ui'       => ['tab' => in_array((string)($decoded['ui']['tab'] ?? ''), ['overview', 'breakdown', 'list'], true) ? (string)$decoded['ui']['tab'] : 'list'],
            ];
            $json = json_encode($definition, JSON_UNESCAPED_UNICODE);
            if ($json === false || strlen($json) > RV_MAX_DEFINITION_BYTES) {
                $fail(400, 'Report definition could not be stored.');
            }
            $pinned = $isAdmin && (string)($_POST['pinned'] ?? '0') === '1' ? 1 : 0;

            if ($id > 0) {
                $r = rvFetch($pdo, $id);
                if ($r === null || !rvCanSee($r, $adminId, $role, $isAdmin)) {
                    $fail(404, 'View not found.');
                }
                if (!$isAdmin && (int)$r['owner_user_id'] !== $adminId) {
                    $fail(403, 'Only the owner or a school administrator can change this view.');
                }
                $visibleRole = $scope === 'department' ? (string)$r['owner_role'] : null;
                $stmt = $pdo->prepare('UPDATE report_definitions SET name = ?, description = ?, scope = ?, visible_role = ?, definition = ?, is_pinned = ?, updated_by = ? WHERE id = ?');
                $stmt->execute([$name, $desc !== '' ? $desc : null, $scope, $visibleRole, $json, $isAdmin ? $pinned : (int)$r['is_pinned'], $adminId, $id]);
                SecurityAuditService::record($conn, 'Report View Updated', ['id' => $id, 'scope' => $scope, 'pinned' => $pinned, 'filters' => count($definition['filters'])], 'report_view', $id);
            } else {
                $cnt = $pdo->prepare('SELECT COUNT(*) FROM report_definitions WHERE owner_user_id = ?');
                $cnt->execute([$adminId]);
                if ((int)$cnt->fetchColumn() >= RV_MAX_VIEWS_PER_USER) {
                    $fail(400, 'You have reached the limit of ' . RV_MAX_VIEWS_PER_USER . ' saved views. Delete one first.');
                }
                $visibleRole = $scope === 'department' ? $role : null;
                $stmt = $pdo->prepare('INSERT INTO report_definitions (dataset, name, description, definition, scope, visible_role, is_pinned, owner_user_id, owner_role, updated_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
                $stmt->execute(['members', $name, $desc !== '' ? $desc : null, $json, $scope, $visibleRole, $pinned, $adminId, $role, $adminId]);
                $id = (int)$pdo->lastInsertId();
                SecurityAuditService::record($conn, 'Report View Saved', ['id' => $id, 'scope' => $scope, 'pinned' => $pinned, 'filters' => count($definition['filters'])], 'report_view', $id);
            }
            $r = rvFetch($pdo, $id);
            $ok(['view' => rvShape($r, $adminId, $isAdmin)]);
        }
        // no break

        case 'delete': {
            $id = (int)($_POST['id'] ?? 0);
            $r = rvFetch($pdo, $id);
            if ($r === null || !rvCanSee($r, $adminId, $role, $isAdmin)) {
                $fail(404, 'View not found.');
            }
            if (!$isAdmin && (int)$r['owner_user_id'] !== $adminId) {
                $fail(403, 'Only the owner or a school administrator can delete this view.');
            }
            $pdo->prepare('DELETE FROM report_definitions WHERE id = ?')->execute([$id]);
            SecurityAuditService::record($conn, 'Report View Deleted', ['id' => $id, 'scope' => (string)$r['scope'], 'name_len' => mb_strlen((string)$r['name'])], 'report_view', $id);
            $ok(['deleted' => $id]);
        }
        // no break

        case 'pin': {
            if (!$isAdmin) {
                $fail(403, 'Only school administrators can pin standing reports.');
            }
            $id = (int)($_POST['id'] ?? 0);
            $r = rvFetch($pdo, $id);
            if ($r === null) {
                $fail(404, 'View not found.');
            }
            $pinned = (string)($_POST['pinned'] ?? '0') === '1' ? 1 : 0;
            // A pinned standing report must be visible to everyone it is shown to.
            $pdo->prepare("UPDATE report_definitions SET is_pinned = ?, scope = IF(? = 1, 'shared', scope), updated_by = ? WHERE id = ?")->execute([$pinned, $pinned, $adminId, $id]);
            SecurityAuditService::record($conn, 'Report View Pinned', ['id' => $id, 'pinned' => $pinned], 'report_view', $id);
            $ok(['view' => rvShape(rvFetch($pdo, $id), $adminId, $isAdmin)]);
        }
        // no break

        case 'opened': {
            $id = (int)($_POST['id'] ?? 0);
            $r = rvFetch($pdo, $id);
            if ($r !== null && rvCanSee($r, $adminId, $role, $isAdmin)) {
                $pdo->prepare('UPDATE report_definitions SET run_count = run_count + 1, last_run_at = NOW() WHERE id = ?')->execute([$id]);
            }
            $ok(['ok' => true]);
        }
        // no break

        default:
            $fail(400, 'Unknown action');
    }
} catch (InvalidArgumentException $e) {
    $fail(400, $e->getMessage()); // registry validation text is user‑safe by construction
} catch (Throwable $e) {
    error_log('api_report_views ' . $action . ' failed: ' . $e->getMessage());
    $fail(500, 'The saved view could not be processed.');
}
