<?php
/**
 * School API v1 — Incremental synchronization feed
 *
 * GET /sync/changes?entity=attendance&cursor=<revision>&limit=<n>
 *     Changes after <cursor>, oldest first, with canonical payloads.
 * GET /sync/state?entity=attendance
 *     Current head revision and retention floor, without pulling rows.
 *
 * Follows the delta contract already proven by /mezmur/hymns/changes:
 * the envelope is {items, next_cursor, server_time, has_more}. The
 * difference is the cursor — here it is `sync_changes.revision`, a
 * server-generated monotonic integer, so deletions and tables without
 * an updated_at column are covered too.
 *
 * The feed is permission scoped with the same primitives the ordinary
 * endpoints use. It is not, and must not become, a global "all database
 * changes" endpoint.
 */

require_once __DIR__ . '/../../../admin/backend/services/SyncChangeFeedService.php';

$auth = apiRequireAuth();

$action = $ROUTE['id'] ?? '';
$year = getCurrentAcademicYear();
$yearId = $year ? (int)$year['id'] : 0;

$feed = new \App\Services\SyncChangeFeedService($conn);

/**
 * Per-entity authorization. Reuses each domain's existing role gate, so
 * a client can never reach data through the feed that the domain's own
 * endpoint would refuse. Unknown entities are rejected outright.
 */
function syncAuthorizeEntity(array $auth, string $entity): void
{
    if ($entity === 'attendance') {
        // The module gate is server authoritative on the normal route,
        // so the feed must honour it too. Otherwise disabling a module
        // would still leak its changes through sync.
        if (!\App\Services\FeatureGate::isEnabled('attendance')) {
            err('The attendance module is disabled.', 403);
        }
        if (!apiRoleIs($auth, apiRolesAttendance())) {
            err('You cannot take or view attendance.', 403);
        }
        return;
    }
    err(
        'Unsupported sync entity: ' . $entity . '. Supported: '
        . implode(', ', \App\Services\SyncChangeFeedService::SUPPORTED_ENTITIES),
        400
    );
}

// ── GET /sync/state ─────────────────────────────────────────
// A cheap probe: lets a device check whether it is behind, and lets a
// fresh install learn the revision its bootstrap corresponds to.
if ($method === 'GET' && $action === 'state') {
    if (isApiRateLimited('sync_api_state', 120)) {
        err('Too many requests. Please wait a moment.', 429);
    }
    $entity = (string)($_GET['entity'] ?? 'attendance');
    syncAuthorizeEntity($auth, $entity);

    if (!$feed->isAvailable()) {
        ok([
            'available' => false,
            'bootstrap_required' => true,
            'reason' => 'feed_unavailable',
            'server_time' => gmdate('Y-m-d H:i:s'),
        ]);
    }

    ok([
        'available' => true,
        'entity' => $entity,
        'head_revision' => $feed->headRevision(),
        'min_valid_revision' => $feed->minValidRevision(),
        'retention_days' => \App\Services\SyncChangeFeedService::RETENTION_DAYS,
        'server_time' => gmdate('Y-m-d H:i:s'),
    ]);
}

// ── GET /sync/changes ───────────────────────────────────────
if ($method === 'GET' && $action === 'changes') {
    // Delta pulls are frequent but small. The bound protects shared
    // hosting from a client stuck in a retry loop.
    if (isApiRateLimited('sync_api_changes', 120)) {
        err('Too many requests. Please wait a moment.', 429);
    }

    $entity = (string)($_GET['entity'] ?? 'attendance');
    syncAuthorizeEntity($auth, $entity);

    // Absent the feed tables (057 not yet applied) the honest answer is
    // "bootstrap", never a delta we cannot substantiate.
    if (!$feed->isAvailable()) {
        ok([
            'bootstrap_required' => true,
            'reason' => 'feed_unavailable',
            'items' => [],
            'records' => [],
            'next_cursor' => 0,
            'server_time' => gmdate('Y-m-d H:i:s'),
            'has_more' => false,
        ]);
    }

    $rawCursor = $_GET['cursor'] ?? '';
    $cursor = ctype_digit((string)$rawCursor) ? (int)$rawCursor : 0;

    // No usable cursor means no complete delta exists. Say so explicitly
    // and hand back the revision a bootstrap should resume from, rather
    // than returning a partial set the device would mistake for whole.
    if (!$feed->cursorIsResumable($cursor)) {
        ok([
            'bootstrap_required' => true,
            'reason' => $cursor <= 0 ? 'no_cursor' : 'cursor_expired',
            'min_valid_revision' => $feed->minValidRevision(),
            'bootstrap_cursor' => $feed->headRevision(),
            'items' => [],
            'records' => [],
            'next_cursor' => $cursor,
            'server_time' => gmdate('Y-m-d H:i:s'),
            'has_more' => false,
        ]);
    }

    $visibleClassIds = $feed->visibleClassIds(
        (int)$auth['uid'],
        apiIsClassRestricted($auth),
        $yearId
    );

    $page = $feed->changesSince(
        $cursor,
        $entity,
        $visibleClassIds,
        (int)($_GET['limit'] ?? \App\Services\SyncChangeFeedService::DEFAULT_LIMIT)
    );

    // Canonical state for everything that still exists. Ids present in
    // `items` with op=DELETE are deliberately absent from `records`:
    // that absence is the tombstone the device applies.
    $liveIds = [];
    foreach ($page['items'] as $item) {
        if ($item['op'] !== 'DELETE') {
            $liveIds[] = $item['entity_id'];
        }
    }
    $records = $entity === 'attendance' ? $feed->hydrateAttendance($liveIds) : [];

    // A row changed and was then deleted inside the same page: the
    // hydrate misses it, so the earlier op must not be applied as live.
    foreach ($page['items'] as $index => $item) {
        if ($item['op'] !== 'DELETE' && !isset($records[$item['entity_id']])) {
            $page['items'][$index]['op'] = 'DELETE';
        }
    }

    ok([
        'bootstrap_required' => false,
        'entity' => $entity,
        'items' => $page['items'],
        // Keyed by entity id; JSON-encodes as an object, [] when empty.
        'records' => (object)$records,
        'next_cursor' => $page['next_cursor'],
        'server_time' => $page['server_time'],
        'has_more' => $page['has_more'],
    ]);
}

err("No handler matched for {$method} /sync/{$action}", 404);
