<?php
// Test/deployment tooling is CLI-only; deny before loading credentials or data.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

/**
 * Render smoke test — Account Settings shared component
 * (admin/components/account_settings.php).
 *
 * Verifies the P73 component contract WITHOUT a database:
 *   - self-bootstrapping does not pull config.php when ROOT_PATH is defined
 *   - panel + trigger + assets render, exactly once per page
 *   - CSRF token is self-contained (data-csrf present, single instance)
 *   - host-page values are HTML-escaped (XSS boundary)
 *   - render is error-bounded (no warnings/notices under E_ALL)
 *
 * Run: php tests/smoke/account_settings_component_test.php
 */

error_reporting(E_ALL);
ini_set('display_errors', '1');

$fail = function (string $msg): void { fwrite(STDERR, "FAIL: $msg\n"); exit(1); };
$pass = function (string $msg): void { echo "  ok: $msg\n"; };

// ── Stub the environment the host page normally provides ────────────
define('ROOT_PATH', dirname(__DIR__, 2)); // present → component must NOT load config.php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
$_SESSION['admin_logged_in'] = true;
$_SESSION['admin_id'] = 42;
$_SESSION['admin_username'] = 'hr_head';
$_SESSION['admin_role'] = 'hr_dept';
$_SESSION['admin_full_name'] = 'Abebe <script>alert(1)</script>'; // XSS canary

if (!function_exists('e')) {
    function e($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
}
if (!function_exists('generateCsrfToken')) {
    function generateCsrfToken(): string {
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf_token'];
    }
}

$warnings = [];
set_error_handler(function ($severity, $message, $file, $line) use (&$warnings) {
    $warnings[] = "$message @ $file:$line";
    return true;
});

require dirname(__DIR__, 2) . '/admin/components/account_settings.php';

// ── 1. First render: trigger + panel + assets ────────────────────────
$out1 = render_account_settings();
$out1 !== '' || $fail('render returned empty string');
strpos($out1, 'data-wba-panel') !== false || $fail('panel markup missing');
strpos($out1, 'data-wba-open') !== false || $fail('trigger button missing');
strpos($out1, 'account-settings.css?v=') !== false || $fail('stylesheet link missing');
strpos($out1, 'account-settings.js?v=') !== false || $fail('script tag missing');
$pass('first render emits trigger, panel, CSS and JS');

// ── 2. Self-contained CSRF, single instance ─────────────────────────
substr_count($out1, 'data-csrf=') === 1 || $fail('data-csrf must appear exactly once');
strpos($out1, 'data-api="/admin/api_settings.php"') !== false || $fail('data-api endpoint wrong');
$pass('single self-contained CSRF token; API endpoint correct');

// ── 3. XSS boundary: host-provided name must be escaped ─────────────
strpos($out1, '<script>alert(1)</script>') !== false && $fail('unescaped host value leaked into markup');
strpos($out1, 'Abebe &lt;script&gt;') !== false || $fail('name not escaped as expected');
$pass('host-page values are HTML-escaped (XSS canary neutralized)');

// ── 4. Second placement: another trigger, but NOT a second panel ────
$out2 = render_account_settings();
strpos($out2, 'data-wba-open') !== false || $fail('second placement missing its trigger');
strpos($out2, 'data-wba-panel') !== false && $fail('panel emitted twice (must be once per page)');
strpos($out2, 'rel="stylesheet"') !== false && $fail('assets emitted twice');
$pass('second placement renders trigger only (panel + assets singletons)');

// ── 5. Bilingual labels + key sections present ──────────────────────
foreach (['የእኔ መገለጫ', 'Security activity', 'Change password', 'data-wba-form="profile"', 'data-wba-form="password"', 'data-wba-signout'] as $needle) {
    strpos($out1, $needle) !== false || $fail("markup missing: $needle");
}
$pass('profile/password/activity sections and Amharic labels present');

// ── 5b. Inline SECTION renderer (v1.2 sidebar parity) ────────────────
$sec = render_account_section(['visible' => true]);
strpos($sec, 'data-wba-section') !== false || $fail('section markup missing');
strpos($sec, 'data-wba-form="profile"') !== false || $fail('section profile form missing');
strpos($sec, 'data-wba-form="password"') !== false || $fail('section password form missing');
strpos($sec, 'data-wba-signout') !== false || $fail('section devices card missing');
$secOpenTag = substr($sec, 0, (int)strpos($sec, '>') + 1);
strpos($secOpenTag, 'hidden') === false || $fail('visible section root must not carry hidden');
strpos($sec, 'account-settings.css') !== false && $fail('section must not re-emit assets (shared runtime)');
$sec2 = render_account_section();
$sec2 === '' || $fail('section must render once per page');
$pass('inline section renders once, fully featured, assets shared with modal');

// ── 5c. Section options: hidden default + selfroute ─────────────────
// (fresh process state is not available here; covered by E2E instead)

// ── 6. Error boundary: no warnings/notices during render ────────────
$warnings === [] || $fail('PHP warnings/notices emitted: ' . implode('; ', array_slice($warnings, 0, 3)));
$pass('render is warning-free under E_ALL');

restore_error_handler();

echo "\nAll account-settings component smoke checks passed.\n";
