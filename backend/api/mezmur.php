<?php
/**
 * Mezmur backend entry point.
 *
 * Normal requests are delegated to the real controller in admin/.
 *
 * DIAGNOSTIC MODE — added because production showed a host-level handler
 * that masks real failures as
 *   {"status":"error","message":"Server error. Please try again.","ref":"#N"}
 * making every real error invisible. The diagnostic:
 *   - uses only ancient PHP syntax (parses on any PHP >= 5.4),
 *   - ALWAYS answers HTTP 200 so no host handler can mask it,
 *   - ?diag=1 : zero-dependency facts — PHP version, extensions, OPcache
 *     state, parse-check of every mezmur file under the server's own PHP,
 *     and whether the controller on DISK is the current version. It never
 *     includes config.php, so nothing on the host can cut it short.
 *   - ?diag=2 : everything above PLUS the database/session probe. This one
 *     includes config.php; if the session guard ends the request, what you
 *     see is the guard's own answer (log in as admin first, then retry).
 */

if (isset($_GET['diag'])) {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-cache, no-store, must-revalidate');

    $root = dirname(dirname(__DIR__)); // repo root / webroot
    $out = array(
        'diag'           => 'mezmur-diag-3',
        'phase'          => (isset($_GET['diag']) && $_GET['diag'] === '2') ? 2 : 1,
        'php'            => PHP_VERSION,
        'sapi'           => PHP_SAPI,
        'time'           => date('c'),
        'display_errors' => (string)ini_get('display_errors'),
    );

    $out['extensions'] = array(
        'mysqli'    => extension_loaded('mysqli'),
        'mbstring'  => extension_loaded('mbstring'),
        'pdo_mysql' => extension_loaded('pdo_mysql'),
    );

    // OPcache state — a stale bytecode cache is the classic cPanel trap
    // after `git reset --hard` (new files on disk, old compiled code run).
    if (function_exists('opcache_get_status')) {
        $st = @opcache_get_status(false);
        if (is_array($st)) {
            $out['opcache'] = array(
                'enabled'             => !empty($st['opcache_enabled']),
                'validate_timestamps' => (string)ini_get('opcache.validate_timestamps'),
                'revalidate_freq'     => (string)ini_get('opcache.revalidate_freq'),
            );
        } else {
            $out['opcache'] = 'loaded but status unavailable';
        }
    } else {
        $out['opcache'] = 'not loaded';
    }

    // Parse-check every mezmur backend file under THIS server's PHP.
    // token_get_all(..., TOKEN_PARSE) throws on syntax this PHP cannot run.
    $files = array(
        'backend/api/mezmur.php',
        'admin/api_mezmur.php',
        'admin/backend/services/MezmurHymnService.php',
        'admin/backend/services/MezmurAttendanceService.php',
        'admin/backend/services/MezmurSubmissionService.php',
        'admin/backend/services/FeatureGate.php',
        'admin/backend/services/SecurityRateLimiter.php',
        'api/v1/routes/mezmur.php',
        'api/v1/core/response.php',
    );
    $parse = array();
    foreach ($files as $rel) {
        $path = $root . '/' . $rel;
        if (!is_file($path)) { $parse[$rel] = 'FILE MISSING ON DISK'; continue; }
        $code = @file_get_contents($path);
        if ($code === false) { $parse[$rel] = 'UNREADABLE'; continue; }
        try {
            if (defined('TOKEN_PARSE')) {
                token_get_all($code, TOKEN_PARSE);
            }
            $parse[$rel] = 'parses on PHP ' . PHP_VERSION;
        } catch (Throwable $e) {
            $parse[$rel] = 'PARSE ERROR: ' . $e->getMessage();
        } catch (Exception $e) {
            $parse[$rel] = 'PARSE ERROR: ' . $e->getMessage();
        }
    }
    $out['parse'] = $parse;

    // Is the controller on DISK the current version?
    $apiText = @file_get_contents($root . '/admin/api_mezmur.php');
    $out['disk_controller'] = ($apiText !== false && strpos($apiText, 'MEZMUR_API_VERSION') !== false)
        ? 'current (has MEZMUR_API_VERSION marker)'
        : 'OLD FILE ON DISK (no MEZMUR_API_VERSION marker)';
    $out['disk_controller_has_ping'] = ($apiText !== false && strpos($apiText, "case 'ping'") !== false);

    if ($out['phase'] === 2) {
        // Config include may exit on its own (secrets missing / session
        // guard). In that case the browser shows this JSON up to here plus
        // the config's own message — still actionable. Emit the phase-1
        // object WITHOUT its closing brace so the final echo can append.
        $json1 = json_encode($out, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        echo substr($json1, 0, -1) . ',"phase2_status":"including config.php ..."';
        try {
            require_once $root . '/config.php';
        } catch (Throwable $e) {
            // No exception internals in the response (disclosure rule);
            // the host error log carries the detail.
            echo "\n,\"config_error\":\"CONFIG_INCLUDE_THREW\"}";
            exit;
        } catch (Exception $e) {
            echo "\n,\"config_error\":\"CONFIG_INCLUDE_THREW\"}";
            exit;
        }
        $p2 = array();
        // (phase2_status gets superseded by the phase2 object below)
        if (isset($conn) && $conn instanceof mysqli) {
            $tables = array(
                'mezmur_hymns'            => 'sql/021',
                'mezmur_days'             => 'sql/022',
                'mezmur_attendance'       => 'sql/023',
                'mezmur_attendance_audit' => 'sql/023',
                'mezmur_submissions'      => 'sql/024',
                'security_rate_limits'    => 'sql/008',
            );
            $t = array();
            foreach ($tables as $tbl => $mig) {
                try {
                    $r = $conn->query("SELECT 1 FROM `$tbl` LIMIT 0");
                    if ($r === false) { $t[$tbl] = 'MISSING (run ' . $mig . ')'; }
                    else { $t[$tbl] = 'ok'; $r->close(); }
                } catch (Throwable $e) {
                    $t[$tbl] = 'MISSING (run ' . $mig . ')';
                } catch (Exception $e) {
                    $t[$tbl] = 'MISSING (run ' . $mig . ')';
                }
            }
            $p2['tables'] = $t;
            $p2['feature_mezmur'] = defined('FEATURE_MEZMUR')
                ? (constant('FEATURE_MEZMUR') === true ? 'enabled (true)' : 'DISABLED (' . var_export(constant('FEATURE_MEZMUR'), true) . ')')
                : 'CONSTANT UNDEFINED (module would fail closed!)';
            $p2['session'] = array(
                'logged_in' => !empty($_SESSION['admin_logged_in']),
                'role'      => isset($_SESSION['admin_role']) ? $_SESSION['admin_role'] : '(none)',
            );
            $recPath = $root . '/admin/backend/services/MezmurSchemaReconciler.php';
            if (is_file($recPath)) {
                try {
                    require_once $recPath;
                    $p2['schema_drift'] = \App\Services\MezmurSchemaReconciler::report($conn);
                } catch (Throwable $e) {
                    $p2['schema_drift'] = 'RECONCILER UNAVAILABLE';
                } catch (Exception $e) {
                    $p2['schema_drift'] = 'RECONCILER UNAVAILABLE';
                }
            } else {
                $p2['schema_drift'] = 'reconciler file missing (server code older than this fix)';
            }
        } else {
            $p2['tables'] = 'no $conn available (config.php did not complete)';
        }
        echo "\n,\"phase2\":" . json_encode($p2, JSON_UNESCAPED_UNICODE) . "}";
        exit;
    }

    echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

require_once __DIR__ . '/../../admin/api_mezmur.php';
