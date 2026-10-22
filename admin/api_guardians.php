<?php
/**
 * Family Link API — guardian entity, member links, picker search and the
 * steward review queue.
 *
 * Roles: write = super_admin, school_admin, hr_dept; read adds info_dept.
 * CSRF enforced on every POST. All mutations audited (GuardianService).
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/backend/services/GuardianService.php';
require_once __DIR__ . '/backend/danger_confirm.php';

use App\Services\GuardianService;
use App\Services\GuardianMatchService;

header('Content-Type: application/json; charset=utf-8');

if (empty($_SESSION['admin_id']) || empty($_SESSION['admin_logged_in'])) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
    exit;
}

$role = (string)($_SESSION['admin_role'] ?? '');
$action = is_scalar($_REQUEST['action'] ?? '') ? (string)$_REQUEST['action'] : '';

$__readActions = ['search', 'get', 'get_family', 'list_suggestions', 'score_check', 'stats', 'list_guardians', 'unlinked_members', 'activity'];
$__writeActions = ['create', 'update', 'link', 'unlink', 'merge', 'merge_suggestion', 'reject_suggestion', 'add_alias', 'run_rematch', 'attach_member', 'attach_batch', 'set_relation', 'remove_alias'];

if (in_array($action, $__writeActions, true) && !in_array($role, ['super_admin', 'school_admin', 'hr_dept'], true)) {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'Only HR or school/super admins can manage guardians.']);
    exit;
}
if (in_array($action, $__readActions, true) && !in_array($role, ['super_admin', 'school_admin', 'hr_dept', 'info_dept'], true)) {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'Access denied.']);
    exit;
}
if (!in_array($action, array_merge($__readActions, $__writeActions), true)) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Unknown action.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !validateCsrf($_POST['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''))) {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'Security token expired. Please refresh.']);
    exit;
}

/**
 * Only InvalidArgumentException messages are intentional user-facing
 * validation text; everything else is logged and replaced by a generic
 * message (no SQL/driver diagnostics in HTTP responses).
 */
function guardianSafeError(Throwable $e, string $fallback): string
{
    if ($e instanceof InvalidArgumentException) {
        return $e->getMessage();
    }
    error_log('api_guardians: ' . get_class($e) . ': ' . $e->getMessage());
    return $fallback;
}

switch ($action) {
    case 'search': {
        $q = (string)($_GET['q'] ?? $_POST['q'] ?? '');
        echo json_encode(['status' => 'success', 'guardians' => GuardianService::searchForPicker($conn, $q)]);
        exit;
    }

    case 'get': {
        // One guardian by id, same shape as the picker rows (children + aliases).
        $gid = (int)($_GET['guardian_id'] ?? $_POST['guardian_id'] ?? 0);
        $g = $gid > 0 ? GuardianService::get($conn, $gid) : null;
        if (!$g) {
            http_response_code(404);
            echo json_encode(['status' => 'error', 'message' => 'Guardian not found.']);
            exit;
        }
        $g['children'] = GuardianService::childrenOf($conn, $gid);
        echo json_encode(['status' => 'success', 'guardian' => $g]);
        exit;
    }

    case 'stats': {
        echo json_encode(['status' => 'success', 'stats' => GuardianService::stats($conn)]);
        exit;
    }

    case 'list_guardians': {
        $q = (string)($_GET['q'] ?? '');
        $filter = (string)($_GET['filter'] ?? 'all');
        $page = (int)($_GET['page'] ?? 1);
        echo json_encode(['status' => 'success'] + GuardianService::listGuardians($conn, $q, $filter, $page, 20), JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        exit;
    }

    case 'unlinked_members': {
        $page = (int)($_GET['page'] ?? 1);
        echo json_encode(['status' => 'success'] + GuardianService::unlinkedMembers($conn, $page, 25), JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        exit;
    }

    case 'activity': {
        echo json_encode(['status' => 'success', 'rows' => GuardianService::activity($conn, 40)], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        exit;
    }

    case 'attach_member': {
        $mid = (int)($_POST['member_id'] ?? 0);
        $gid = (int)($_POST['guardian_id'] ?? 0); // optional explicit pick
        try {
            if ($mid <= 0) {
                throw new InvalidArgumentException('Member is required.');
            }
            $r = GuardianService::attachFromLegacy($conn, $mid, $gid, 'hr');
            if ($r['mode'] === 'none') {
                throw new InvalidArgumentException('This member has no guardian name saved — add it on the member profile first, or pick a guardian.');
            }
            echo json_encode(['status' => 'success', 'message' => 'Member linked.'] + $r);
        } catch (Throwable $e) {
            http_response_code(422);
            echo json_encode(['status' => 'error', 'message' => guardianSafeError($e, 'Member could not be linked.')]);
        }
        exit;
    }

    case 'attach_batch': {
        // Danger confirmation (HIGH): "Link all" — every step carries the
        // phrase typed in the 3-step dialog.
        dc_require_phrase('guardians.link_all');
        $cursor = max(0, (int)($_POST['cursor'] ?? 0));
        @set_time_limit(60);
        $r = GuardianService::attachBatch($conn, $cursor, 40, 15.0);
        if ($r['processed'] > 0) {
            GuardianService::audit($conn, 'Guardian Backfill', "Link-all step: {$r['processed']} member(s) linked ({$r['created']} new guardians, {$r['matched']} matched, {$r['failed']} failed); " . ($r['done'] ? 'complete' : 'continuing') . '.');
        }
        echo json_encode(['status' => 'success'] + $r);
        exit;
    }

    case 'set_relation': {
        $gid = (int)($_POST['guardian_id'] ?? 0);
        $mid = (int)($_POST['member_id'] ?? 0);
        $rel = (string)($_POST['relation'] ?? '');
        $prim = isset($_POST['is_primary']) ? ((int)$_POST['is_primary'] === 1) : null;
        try {
            GuardianService::setRelation($conn, $gid, $mid, $rel, $prim);
            echo json_encode(['status' => 'success', 'message' => 'Link updated.']);
        } catch (Throwable $e) {
            http_response_code(422);
            echo json_encode(['status' => 'error', 'message' => guardianSafeError($e, 'Link could not be updated.')]);
        }
        exit;
    }

    case 'remove_alias': {
        $id = (int)($_POST['guardian_id'] ?? 0);
        $alias = trim((string)($_POST['alias_name'] ?? ''));
        $st = $conn->prepare('DELETE FROM guardian_aliases WHERE guardian_id = ? AND alias_name = ?');
        if ($st) {
            $st->bind_param('is', $id, $alias);
            $st->execute();
            $st->close();
        }
        GuardianService::audit($conn, 'Guardian Alias Removed', "Alias '{$alias}' removed from guardian {$id}.");
        echo json_encode(['status' => 'success', 'message' => 'Alias removed.']);
        exit;
    }

    case 'get_family': {
        $memberId = (int)($_GET['member_id'] ?? 0);
        echo json_encode([
            'status' => 'success',
            'guardians' => GuardianService::familyOfMember($conn, $memberId),
            'siblings' => GuardianService::siblingsOf($conn, $memberId),
        ]);
        exit;
    }

    case 'score_check': {
        // Live "did you mean" helper: score a typed guardian block against
        // existing guardians; returns top candidates with band + evidence.
        $name = (string)($_POST['display_name'] ?? $_GET['display_name'] ?? '');
        $phone = (string)($_POST['phone_primary'] ?? $_GET['phone_primary'] ?? '');
        $addr = [
            'city' => (string)($_POST['city'] ?? ''),
            'sub_city' => (string)($_POST['sub_city'] ?? ''),
            'woreda' => (string)($_POST['woreda'] ?? ''),
            'mender' => (string)($_POST['mender'] ?? ''),
            'block_number' => (string)($_POST['block_number'] ?? ''),
            'house' => (string)($_POST['house'] ?? ''),
        ];
        $cands = GuardianMatchService::findCandidates($conn, $name, $phone, $addr);
        $probe = ['display_name' => $name, 'phone_primary_n9' => GuardianMatchService::phoneN9($phone), 'phone_alt_n9' => '', 'aliases' => []] + $addr;
        $out = [];
        foreach ($cands as $c) {
            $r = GuardianMatchService::score($probe, $c);
            $out[] = [
                'id' => (int)$c['id'],
                'display_name' => $c['display_name'],
                'phone_primary' => $c['phone_primary'],
                'score' => $r['score'],
                'band' => $r['band'],
                'evidence' => $r['evidence'],
            ];
        }
        usort($out, fn($a, $b) => $b['score'] <=> $a['score']);
        echo json_encode(['status' => 'success', 'candidates' => array_slice($out, 0, 5)]);
        exit;
    }

    case 'create': {
        try {
            $id = GuardianService::create($conn, [
                'display_name' => (string)($_POST['display_name'] ?? ''),
                'phone_primary' => (string)($_POST['phone_primary'] ?? ''),
                'phone_alt' => (string)($_POST['phone_alt'] ?? ''),
                'city' => (string)($_POST['city'] ?? ''),
                'sub_city' => (string)($_POST['sub_city'] ?? ''),
                'woreda' => (string)($_POST['woreda'] ?? ''),
                'mender' => (string)($_POST['mender'] ?? ''),
                'block_number' => (string)($_POST['block_number'] ?? ''),
                'house' => (string)($_POST['house'] ?? ''),
                'aliases' => array_filter(explode("\n", (string)($_POST['aliases'] ?? ''))),
            ]);
            echo json_encode(['status' => 'success', 'id' => $id, 'message' => 'Guardian created.']);
        } catch (Throwable $e) {
            http_response_code(422);
            echo json_encode(['status' => 'error', 'message' => guardianSafeError($e, 'Guardian could not be created.')]);
        }
        exit;
    }

    case 'update': {
        $id = (int)($_POST['guardian_id'] ?? 0);
        try {
            GuardianService::update($conn, $id, [
                'display_name' => (string)($_POST['display_name'] ?? ''),
                'phone_primary' => (string)($_POST['phone_primary'] ?? ''),
                'phone_alt' => (string)($_POST['phone_alt'] ?? ''),
                'city' => (string)($_POST['city'] ?? ''),
                'sub_city' => (string)($_POST['sub_city'] ?? ''),
                'woreda' => (string)($_POST['woreda'] ?? ''),
                'mender' => (string)($_POST['mender'] ?? ''),
                'block_number' => (string)($_POST['block_number'] ?? ''),
                'house' => (string)($_POST['house'] ?? ''),
            ]);
            echo json_encode(['status' => 'success', 'message' => 'Guardian updated.']);
        } catch (Throwable $e) {
            http_response_code(422);
            echo json_encode(['status' => 'error', 'message' => guardianSafeError($e, 'Guardian could not be updated.')]);
        }
        exit;
    }

    case 'add_alias': {
        $id = (int)($_POST['guardian_id'] ?? 0);
        $alias = trim((string)($_POST['alias_name'] ?? ''));
        if ($id <= 0 || $alias === '' || !GuardianService::get($conn, $id)) {
            http_response_code(422);
            echo json_encode(['status' => 'error', 'message' => 'Guardian and alias text are required.']);
            exit;
        }
        GuardianService::addAlias($conn, $id, $alias);
        GuardianService::audit($conn, 'Guardian Alias Added', "Alias '{$alias}' added to guardian {$id}.");
        echo json_encode(['status' => 'success', 'message' => 'Alias added.']);
        exit;
    }

    case 'link': {
        $gid = (int)($_POST['guardian_id'] ?? 0);
        $mid = (int)($_POST['member_id'] ?? 0);
        $rel = (string)($_POST['relation'] ?? 'other');
        if (!in_array($rel, ['father', 'mother', 'other'], true)) {
            $rel = 'other';
        }
        $prio = max(1, (int)($_POST['contact_priority'] ?? 1));
        $prim = (int)($_POST['is_primary'] ?? 0) === 1;
        try {
            GuardianService::linkMember($conn, $gid, $mid, $rel, $prio, $prim);
            echo json_encode(['status' => 'success', 'message' => 'Member linked to guardian.']);
        } catch (Throwable $e) {
            http_response_code(422);
            echo json_encode(['status' => 'error', 'message' => guardianSafeError($e, 'Member could not be linked.')]);
        }
        exit;
    }

    case 'unlink': {
        $gid = (int)($_POST['guardian_id'] ?? 0);
        $mid = (int)($_POST['member_id'] ?? 0);
        if ($gid <= 0 || $mid <= 0) {
            http_response_code(422);
            echo json_encode(['status' => 'error', 'message' => 'Guardian and member are required.']);
            exit;
        }
        GuardianService::unlinkMember($conn, $gid, $mid);
        echo json_encode(['status' => 'success', 'message' => 'Link removed.']);
        exit;
    }

    case 'merge': {
        try {
            GuardianService::merge($conn, (int)($_POST['from_id'] ?? 0), (int)($_POST['into_id'] ?? 0), (int)($_SESSION['admin_id'] ?? 0));
            echo json_encode(['status' => 'success', 'message' => 'Guardians merged.']);
        } catch (Throwable $e) {
            http_response_code(422);
            echo json_encode(['status' => 'error', 'message' => guardianSafeError($e, 'Guardians could not be merged.')]);
        }
        exit;
    }

    case 'run_rematch': {
        // Bounded sweep step; the UI loops with next_cursor until done.
        $cursor = max(0, (int)($_POST['cursor'] ?? 0));
        @set_time_limit(60); // shared hosts may ignore this; the 15 s budget keeps us under a 30 s cap anyway
        $r = GuardianMatchService::rematchBatch($conn, $cursor, 300, 15.0);
        if ($r['scanned'] > 0 || $r['filed'] > 0) {
            GuardianService::audit($conn, 'Guardian Rematch', "Sweep step: scanned {$r['scanned']} guardians from cursor {$cursor}, filed {$r['filed']} suggestion(s), " . ($r['done'] ? 'complete' : 'continuing') . '.');
        }
        echo json_encode(['status' => 'success'] + $r);
        exit;
    }

    case 'list_suggestions': {
        $rows = [];
        // Self-heal: a pending pair whose side was merged/removed elsewhere is closed.
        $conn->query("UPDATE guardian_link_suggestions s LEFT JOIN guardians a ON a.id = s.guardian_a LEFT JOIN guardians b ON b.id = s.guardian_b
            SET s.status = 'expired', s.resolved_at = NOW()
            WHERE s.status = 'pending' AND (a.id IS NULL OR b.id IS NULL OR a.status = 'merged' OR b.status = 'merged' OR s.guardian_a = s.guardian_b)");
        $r = $conn->query("SELECT * FROM guardian_link_suggestions WHERE status='pending' ORDER BY score DESC, id DESC LIMIT 100");
        if ($r) {
            while ($s = $r->fetch_assoc()) {
                $s['guardian_a_row'] = $s['guardian_a'] ? GuardianService::get($conn, (int)$s['guardian_a']) : null;
                $s['guardian_b_row'] = $s['guardian_b'] ? GuardianService::get($conn, (int)$s['guardian_b']) : null;
                if ($s['guardian_a_row']) {
                    $s['guardian_a_row']['children'] = GuardianService::childrenOf($conn, (int)$s['guardian_a'], 8);
                }
                if ($s['guardian_b_row']) {
                    $s['guardian_b_row']['children'] = GuardianService::childrenOf($conn, (int)$s['guardian_b'], 8);
                }
                $rows[] = $s;
            }
        }
        // Phase 3: the queue shows the best 100; total says how many wait.
        $total = count($rows);
        $rc = $conn->query("SELECT COUNT(*) AS c FROM guardian_link_suggestions WHERE status='pending'");
        if ($rc) { $total = max($total, (int)($rc->fetch_assoc()['c'] ?? 0)); }
        echo json_encode(['status' => 'success', 'suggestions' => $rows, 'total' => $total], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        exit;
    }

    case 'merge_suggestion': {
        $sid = (int)($_POST['suggestion_id'] ?? 0);
        $st = $conn->prepare('SELECT * FROM guardian_link_suggestions WHERE id = ?');
        $st->bind_param('i', $sid);
        $st->execute();
        $sug = $st->get_result()->fetch_assoc();
        $st->close();
        if (!$sug || $sug['status'] !== 'pending') {
            http_response_code(409);
            echo json_encode(['status' => 'error', 'message' => 'Suggestion not pending.']);
            exit;
        }
        $uid = (int)($_SESSION['admin_id'] ?? 0);
        $keep = (string)($_POST['keep'] ?? 'a');
        $from = $keep === 'b' ? (int)$sug['guardian_a'] : (int)$sug['guardian_b'];
        $into = $keep === 'b' ? (int)$sug['guardian_b'] : (int)$sug['guardian_a'];
        try {
            GuardianService::merge($conn, $from, $into, $uid);
        } catch (InvalidArgumentException $e) {
            // One side no longer mergeable (already merged elsewhere / deleted):
            // close the stale suggestion instead of leaving it stuck in the queue.
            $st = $conn->prepare("UPDATE guardian_link_suggestions SET status='expired', resolved_by=?, resolved_at=NOW() WHERE id=? AND status='pending'");
            $st->bind_param('ii', $uid, $sid);
            $st->execute();
            $st->close();
            http_response_code(409);
            echo json_encode(['status' => 'error', 'message' => guardianSafeError($e, 'Guardians could not be merged.') . ' Suggestion closed as expired.']);
            exit;
        } catch (Throwable $e) {
            http_response_code(422);
            echo json_encode(['status' => 'error', 'message' => guardianSafeError($e, 'Guardians could not be merged.')]);
            exit;
        }
        $st = $conn->prepare("UPDATE guardian_link_suggestions SET status='merged', resolved_by=?, resolved_at=NOW() WHERE id=?");
        $st->bind_param('ii', $uid, $sid);
        $st->execute();
        $st->close();
        echo json_encode(['status' => 'success', 'message' => 'Suggestion merged.']);
        exit;
    }

    case 'reject_suggestion': {
        $sid = (int)($_POST['suggestion_id'] ?? 0);
        $st = $conn->prepare("UPDATE guardian_link_suggestions SET status='rejected', resolved_by=?, resolved_at=NOW() WHERE id=? AND status='pending'");
        $uid = (int)($_SESSION['admin_id'] ?? 0);
        $st->bind_param('ii', $uid, $sid);
        $st->execute();
        $st->close();
        GuardianService::audit($conn, 'Suggestion Rejected', "Suggestion {$sid} kept as separate guardians.");
        echo json_encode(['status' => 'success', 'message' => 'Suggestion rejected.']);
        exit;
    }
}
