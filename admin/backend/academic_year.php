<?php
/**
 * ════════════════════════════════════════════════════════════════
 * ACADEMIC YEAR CONTEXT — the SINGLE source of truth for "which year"
 * ════════════════════════════════════════════════════════════════
 * Loaded by config.php on every request. Every place that used to run
 *   SELECT * FROM academic_years WHERE is_current = 1 LIMIT 1
 * must instead call ay_resolve()/ay_active_year() here.
 *
 * MODEL:
 *   academic_years.status = 'upcoming' | 'active' | 'closed'  (source of truth)
 *   is_current            = derived mirror (1 when status='active')
 *   EXACTLY ONE row is 'active' at a time.
 *
 * THE EFFECTIVE YEAR for a request:
 *   - If the user is time-travelling (viewing a past year) → that year, READ-ONLY.
 *   - Otherwise → the single active year, WRITABLE.
 *
 * WRITE RULE (anti-corruption core):
 *   New/updated year-scoped rows ALWAYS stamp the ACTIVE year id — never a
 *   past year — and writes are refused while time-travelling or when the
 *   target year is not active. Viewing the past ≠ writing to the past.
 * ════════════════════════════════════════════════════════════════
 */

if (!function_exists('ay_ensure_schema')) {

/**
 * Make sure the `status` column exists and is in sync with is_current.
 * Self-healing: works even if sql/004_year_lifecycle.sql has not been run.
 * Runs its checks at most once per request.
 */
function ay_ensure_schema($conn): void {
    static $done = false;
    if ($done) return;
    $done = true;
    if (!$conn || (isset($conn->connect_error) && $conn->connect_error)) return;
    try {
        $r = $conn->query("SHOW COLUMNS FROM `academic_years` LIKE 'status'");
        if ($r && $r->num_rows === 0) {
            $conn->query("ALTER TABLE `academic_years`
                ADD COLUMN `status` ENUM('upcoming','active','closed')
                NOT NULL DEFAULT 'upcoming' AFTER `is_current`");
            // Seed from the legacy flag, then normalise to one active.
            $conn->query("UPDATE `academic_years` SET `status` = IF(`is_current`=1,'active','upcoming')");
            // If nothing is active, promote the most recent year.
            $c = $conn->query("SELECT COUNT(*) c FROM `academic_years` WHERE `status`='active'");
            $activeCount = $c ? (int)$c->fetch_assoc()['c'] : 0;
            if ($activeCount === 0) {
                $conn->query("UPDATE `academic_years` SET `status`='active'
                    ORDER BY COALESCE(`ec_year`,0) DESC, `id` DESC LIMIT 1");
            }
            $conn->query("UPDATE `academic_years` SET `is_current` = IF(`status`='active',1,0)");
        }
    } catch (Throwable $e) { /* table may not exist yet — resolver degrades gracefully */ }
}

/**
 * The single ACTIVE year row (writable target). Cached per request.
 * Returns the associative row, or null if none exists.
 */
function ay_active_year($conn) {
    static $cache = null; static $loaded = false;
    if ($loaded) return $cache;
    $loaded = true;
    ay_ensure_schema($conn);
    try {
        // Prefer status; fall back to is_current for safety.
        $r = $conn->query("SELECT * FROM `academic_years` WHERE `status`='active' ORDER BY id DESC LIMIT 1");
        $row = $r ? $r->fetch_assoc() : null;
        if (!$row) {
            $r = $conn->query("SELECT * FROM `academic_years` WHERE `is_current`=1 ORDER BY id DESC LIMIT 1");
            $row = $r ? $r->fetch_assoc() : null;
        }
        $cache = $row ?: null;
    } catch (Throwable $e) { $cache = null; }
    return $cache;
}

/**
 * Fetch a single year row by id (or null).
 */
function ay_year_by_id($conn, $id) {
    $id = (int)$id;
    if ($id <= 0) return null;
    try {
        $stmt = $conn->prepare("SELECT * FROM `academic_years` WHERE id=? LIMIT 1");
        if (!$stmt) return null;
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $row ?: null;
    } catch (Throwable $e) { return null; }
}

/**
 * RESOLVE the effective academic-year context for THIS request.
 * Returns:
 *   [ 'active' => row|null, 'active_id' => int,
 *     'year' => row|null,  'id' => int, 'ec_year' => int|null,
 *     'is_readonly' => bool, 'viewing_id' => int|null ]
 */
function ay_resolve($conn) {
    static $ctx = null;
    if ($ctx !== null) return $ctx;

    $active = ay_active_year($conn);
    $activeId = $active ? (int)$active['id'] : 0;

    $viewId = isset($_SESSION['ay_view_year_id']) ? (int)$_SESSION['ay_view_year_id'] : 0;
    // A view that equals the active year (or is invalid) is not time-travel.
    $year = $active;
    $readonly = false;
    $viewingId = null;

    if ($viewId > 0 && $viewId !== $activeId) {
        $vrow = ay_year_by_id($conn, $viewId);
        if ($vrow) {
            $year = $vrow;
            $readonly = true;          // viewing a non-active year is ALWAYS read-only
            $viewingId = (int)$vrow['id'];
        } else {
            // Stale/invalid view id — drop it.
            unset($_SESSION['ay_view_year_id']);
        }
    }

    $ctx = [
        'active'      => $active,
        'active_id'   => $activeId,
        'year'        => $year,
        'id'          => $year ? (int)$year['id'] : 0,
        'ec_year'     => $year && $year['ec_year'] !== null ? (int)$year['ec_year'] : null,
        'is_readonly' => $readonly,
        'viewing_id'  => $viewingId,
    ];
    return $ctx;
}

/** True when the current request is time-travelling (viewing a past year). */
function ay_is_readonly($conn) {
    return ay_resolve($conn)['is_readonly'];
}

/**
 * WRITE GUARD. Call at the top of any handler that writes year-scoped data.
 * - Refuses (HTTP 403 JSON + exit) when time-travelling.
 * - Returns the ACTIVE year id to stamp on the new/updated rows.
 * $silent=true returns false instead of exiting (for non-JSON contexts).
 */
function ay_require_writable($conn, $silent = false) {
    $ctx = ay_resolve($conn);
    if ($ctx['is_readonly']) {
        if ($silent) return false;
        if (!headers_sent()) { http_response_code(403); header('Content-Type: application/json; charset=utf-8'); }
        echo json_encode([
            'status'  => 'error',
            'message' => 'You are viewing a PAST academic year (read-only). Return to the current year to make changes.',
            'code'    => 'year_readonly'
        ]);
        exit;
    }
    if ($ctx['active_id'] <= 0) {
        if ($silent) return false;
        if (!headers_sent()) { http_response_code(409); header('Content-Type: application/json; charset=utf-8'); }
        echo json_encode([
            'status'  => 'error',
            'message' => 'No active academic year is set. A School Admin must set the current year first.',
            'code'    => 'no_active_year'
        ]);
        exit;
    }
    return $ctx['active_id'];
}

/**
 * Lighter write guard for writes that are NOT year-stamped (global catalog
 * edits, status flips) but must still be refused while time-travelling.
 * - Refuses (HTTP 403 JSON + exit) when viewing a past year.
 * - Does NOT require an active year to exist.
 * $silent=true returns false instead of exiting.
 */
function ay_block_if_readonly($conn, $silent = false) {
    $ctx = ay_resolve($conn);
    if ($ctx['is_readonly']) {
        if ($silent) return false;
        if (!headers_sent()) { http_response_code(403); header('Content-Type: application/json; charset=utf-8'); }
        echo json_encode([
            'status'  => 'error',
            'message' => 'You are viewing a PAST academic year (read-only). Return to the current year to make changes.',
            'code'    => 'year_readonly'
        ]);
        exit;
    }
    return true;
}

/** Only School Admin (owner) and Super Admin (break-glass) manage the year lifecycle. */
function ay_can_manage_years() {
    $role = $_SESSION['admin_role'] ?? '';
    return in_array($role, ['super_admin', 'school_admin'], true);
}

/**
 * ATOMIC, SAFE change of the active year with explicit legal transitions.
 * Legal: upcoming→active (closes the prior active), active→active (no-op),
 *        closed→active ONLY when $reopen=true (deliberate corrective action).
 * Returns ['status'=>'success'|'error', 'message'=>..., 'closed_year'=>name|null].
 */
function ay_switch_active($conn, $targetYearId, $reopen = false) {
    $targetYearId = (int)$targetYearId;
    $target = ay_year_by_id($conn, $targetYearId);
    if (!$target) return ['status' => 'error', 'message' => 'That academic year does not exist.'];

    $tstatus = $target['status'] ?? ($target['is_current'] ? 'active' : 'upcoming');
    if ($tstatus === 'active') {
        return ['status' => 'success', 'message' => 'That year is already the active year.', 'closed_year' => null];
    }
    if ($tstatus === 'closed' && !$reopen) {
        return ['status' => 'error', 'message' => 'That year is closed. Use the explicit "Reopen" action to make a closed year active again.', 'code' => 'needs_reopen'];
    }

    try {
        $conn->begin_transaction();

        // Close whatever is currently active (the prior year).
        $priorName = null;
        $pr = $conn->query("SELECT year_name FROM `academic_years` WHERE `status`='active' LIMIT 1");
        if ($pr && ($prow = $pr->fetch_assoc())) { $priorName = $prow['year_name']; }
        $conn->query("UPDATE `academic_years` SET `status`='closed', `is_current`=0 WHERE `status`='active'");

        // Activate the target.
        $stmt = $conn->prepare("UPDATE `academic_years` SET `status`='active', `is_current`=1 WHERE id=?");
        if (!$stmt) { throw new Exception($conn->error); }
        $stmt->bind_param('i', $targetYearId);
        $stmt->execute();
        $stmt->close();

        $conn->commit();
        return ['status' => 'success', 'message' => 'Active year changed.', 'closed_year' => $priorName];
    } catch (Throwable $e) {
        try { $conn->rollback(); } catch (Throwable $r) {}
        error_log('ay_switch_active failed: ' . $e->getMessage());
        return ['status' => 'error', 'message' => 'Could not change the active year. No changes were made.'];
    }
}

/** Years for a selector dropdown: [{id, year_name, ec_year, status, is_active}]. */
function ay_year_list($conn) {
    ay_ensure_schema($conn);
    $out = [];
    try {
        $r = $conn->query("SELECT id, year_name, ec_year, status, is_current FROM `academic_years` ORDER BY COALESCE(ec_year,0) DESC, id DESC");
        if ($r) while ($row = $r->fetch_assoc()) {
            $out[] = [
                'id'        => (int)$row['id'],
                'year_name' => $row['year_name'],
                'ec_year'   => $row['ec_year'] !== null ? (int)$row['ec_year'] : null,
                'status'    => $row['status'] ?? ($row['is_current'] ? 'active' : 'upcoming'),
                'is_active' => (($row['status'] ?? '') === 'active') || (int)$row['is_current'] === 1,
            ];
        }
    } catch (Throwable $e) {}
    return $out;
}

/**
 * The loud, impossible-to-miss "viewing past year" banner.
 * Returns '' when in the normal active year (nothing to show).
 * Echo this near the top of <body> on every dashboard.
 */
function ay_banner_html($conn) {
    $ctx = ay_resolve($conn);
    if (!$ctx['is_readonly'] || !$ctx['year']) return '';
    $viewName = $ctx['year']['year_name'] ?? '';
    $activeName = $ctx['active'] ? ($ctx['active']['year_name'] ?? '') : 'current';
    $vn = htmlspecialchars($viewName, ENT_QUOTES, 'UTF-8');
    $an = htmlspecialchars($activeName, ENT_QUOTES, 'UTF-8');
    return
    '<div id="ayTimeTravelBanner" style="position:sticky;top:0;left:0;right:0;z-index:9998;'
    . 'background:repeating-linear-gradient(45deg,#f59e0b,#f59e0b 18px,#d97706 18px,#d97706 36px);'
    . 'color:#1a1200;font-family:system-ui,Segoe UI,sans-serif;font-weight:800;'
    . 'padding:.6rem 1rem;display:flex;align-items:center;justify-content:center;gap:1rem;flex-wrap:wrap;'
    . 'box-shadow:0 3px 10px rgba(0,0,0,.3);border-bottom:3px solid #92400e;letter-spacing:.2px">'
    . '<span style="font-size:.95rem">📅 VIEWING PAST YEAR: <span class="amharic">' . $vn . '</span> — READ ONLY. '
    . 'You are NOT in the current year.</span>'
    . '<button onclick="ayReturnToCurrent()" style="background:#1a1200;color:#fde68a;border:0;border-radius:8px;'
    . 'padding:.45rem .9rem;font-weight:800;cursor:pointer;font-family:inherit;white-space:nowrap">'
    . '↩ Return to Current Year (' . $an . ')</button>'
    . '</div>'
    . '<script>'
    . 'function ayReturnToCurrent(){var f=new FormData();f.append("action","clear");'
    . 'f.append("csrf_token",(window.CSRF||(window.APP&&window.APP.csrf)||""));'
    . 'fetch("/admin/api_year_context.php",{method:"POST",body:f,credentials:"same-origin"})'
    . '.then(function(){location.reload();}).catch(function(){location.reload();});}'
    . 'document.addEventListener("DOMContentLoaded",function(){'
    . 'document.querySelectorAll(\'button,.btn,input[type=submit],a.btn\').forEach(function(el){'
    . 'var t=(el.textContent||"").toLowerCase();'
    . 'if(/save|add|edit|delete|create|remove|record|submit|enroll|promote|approve|reject|assign/.test(t)'
    . '&&!/return to current/.test(t)){el.setAttribute("disabled","disabled");el.style.opacity=".45";'
    . 'el.style.pointerEvents="none";el.title="Read-only: viewing a past year";}});});'
    . '</script>';
}

/**
 * The full academic-year CONTEXT BAR for dashboards. Combines:
 *   - NORMAL mode: a slim year selector to jump into read-only "time-travel".
 *   - TIME-TRAVEL mode: the loud amber READ-ONLY banner + one-click return,
 *     and auto-disables write controls on the page.
 * Self-contained (embeds the CSRF token server-side) so it works on every
 * dashboard regardless of how that page names its JS token. It renders nothing
 * until at least two academic years exist (nothing to travel to before then).
 * Echo it right after <body>.
 */
function ay_context_bar_html($conn) {
    $ctx   = ay_resolve($conn);
    $years = ay_year_list($conn);

    $active     = $ctx['active'];
    $activeId   = (int)$ctx['active_id'];
    $activeName = $active ? ($active['year_name'] ?? '') : '';
    $an = htmlspecialchars($activeName !== '' ? $activeName : '—', ENT_QUOTES, 'UTF-8');
    // Embed a guaranteed-valid CSRF token (generate it if the session lacks one)
    // so the time-travel calls pass validateCsrf() on every dashboard.
    $tokenJs = json_encode(function_exists('generateCsrfToken') ? generateCsrfToken() : ($_SESSION['csrf_token'] ?? ''));

    // One shared JS helper: set/clear the per-user viewing context, then reload.
    $js = '<script>window.ayCtx=window.ayCtx||{csrf:' . $tokenJs . ','
        . 'go:function(id){var f=new FormData();f.append("action",id?"set":"clear");if(id)f.append("year_id",id);'
        . 'f.append("csrf_token",this.csrf);'
        . 'fetch("/admin/api_year_context.php",{method:"POST",body:f,credentials:"same-origin"})'
        . '.then(function(){location.reload();}).catch(function(){location.reload();});}};</script>';

    // ── TIME-TRAVEL: loud, impossible-to-miss read-only banner ──
    if ($ctx['is_readonly'] && $ctx['year']) {
        $vn = htmlspecialchars($ctx['year']['year_name'] ?? '', ENT_QUOTES, 'UTF-8');
        return
        '<div id="ayTimeTravelBanner" style="position:sticky;top:0;left:0;right:0;z-index:9998;'
        . 'background:repeating-linear-gradient(45deg,#f59e0b,#f59e0b 18px,#d97706 18px,#d97706 36px);'
        . 'color:#1a1200;font-family:system-ui,Segoe UI,sans-serif;font-weight:800;'
        . 'padding:.6rem 1rem;display:flex;align-items:center;justify-content:center;gap:1rem;flex-wrap:wrap;'
        . 'box-shadow:0 3px 10px rgba(0,0,0,.3);border-bottom:3px solid #92400e;letter-spacing:.2px">'
        . '<span style="font-size:.95rem">📅 VIEWING PAST YEAR: <span class="amharic">' . $vn . '</span> — READ ONLY. '
        . 'You are NOT in the current year.</span>'
        . '<button type="button" onclick="ayCtx.go(0)" style="background:#1a1200;color:#fde68a;border:0;border-radius:8px;'
        . 'padding:.45rem .9rem;font-weight:800;cursor:pointer;font-family:inherit;white-space:nowrap">'
        . '↩ Return to Current Year (' . $an . ')</button>'
        . '</div>'
        . '<script>document.addEventListener("DOMContentLoaded",function(){'
        . 'document.querySelectorAll(\'button,.btn,input[type=submit],a.btn\').forEach(function(el){'
        . 'var t=(el.textContent||"").toLowerCase();'
        . 'if(/save|add|edit|delete|create|remove|record|submit|enroll|promote|approve|reject|assign|set current|set active|reopen/.test(t)'
        . '&&!/return to current/.test(t)){el.setAttribute("disabled","disabled");el.style.opacity=".45";'
        . 'el.style.pointerEvents="none";el.title="Read-only: viewing a past year";}});});</script>'
        . $js;
    }

    // ── NORMAL: slim selector. Hidden until there is a past year to view. ──
    if (count($years) < 2) return '';

    $opts = '';
    foreach ($years as $y) {
        $sid = (int)$y['id'];
        $st  = $y['status'] ?? 'upcoming';
        $tag = $st === 'active' ? 'Active' : ($st === 'closed' ? 'Closed' : 'Upcoming');
        $sel = ($sid === $activeId) ? ' selected' : '';
        $opts .= '<option value="' . $sid . '"' . $sel . '>'
              . htmlspecialchars($y['year_name'] . '  ·  ' . $tag, ENT_QUOTES, 'UTF-8')
              . '</option>';
    }
    return
    '<div style="position:sticky;top:0;z-index:9990;background:#0f172a;color:#e2e8f0;'
    . 'font-family:system-ui,Segoe UI,sans-serif;font-size:.8rem;padding:.35rem .8rem;'
    . 'display:flex;align-items:center;gap:.6rem;flex-wrap:wrap;border-bottom:1px solid #1e293b">'
    . '<span style="opacity:.85">📅 Current year: <b style="color:#fff" class="amharic">' . $an . '</b></span>'
    . '<span style="opacity:.5">|</span>'
    . '<label style="opacity:.85">View another year:</label>'
    . '<select onchange="ayCtx.go(this.value==' . $activeId . '?0:this.value)" '
    . 'style="background:#1e293b;color:#e2e8f0;border:1px solid #334155;border-radius:6px;padding:.2rem .4rem;font-family:inherit;font-size:.78rem">'
    . $opts . '</select>'
    . '<span style="opacity:.55">Past years open READ-ONLY.</span>'
    . '</div>' . $js;
}

} // end function guard
