<?php
/**
 * Test harness: call an SSMS admin API endpoint as a given role.
 *
 * The subject-duration and semester-weight endpoints decide what to do based
 * on the session role, so testing them means arriving with a session already
 * established. This script builds one, dispatches to the real endpoint file,
 * and lets the endpoint's own JSON reach stdout unchanged. Nothing about the
 * endpoint is reimplemented here.
 *
 * COMMAND LINE ONLY. It fabricates a privileged session from its arguments,
 * so it must never be reachable over HTTP. The guard below is the safeguard;
 * the file also lives under tests/, which is not deployed.
 *
 * Usage:
 *   php tests/e2e/education_config_api.php <admin-file> <role> <GET|POST> <json-params>
 * Example:
 *   php tests/e2e/education_config_api.php api_subjects.php edu_dept GET \
 *       '{"action":"get_class_subject_durations","class_id":1}'
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$argv = $_SERVER['argv'] ?? [];
if (count($argv) < 5) {
    fwrite(STDERR, "usage: education_config_api.php <admin-file> <role> <GET|POST> <json>\n");
    exit(2);
}

$root = dirname(__DIR__, 2);
$apiName = basename($argv[1]);              // no path traversal
$apiFile = $root . '/admin/' . $apiName;
if (!is_file($apiFile)) {
    fwrite(STDERR, "no such endpoint: {$apiFile}\n");
    exit(2);
}

$role   = (string)$argv[2];
$method = strtoupper((string)$argv[3]) === 'POST' ? 'POST' : 'GET';
$params = json_decode((string)$argv[4], true);
if (!is_array($params)) {
    $params = [];
}

$sessDir = sys_get_temp_dir() . '/ssms_edu_cfg_sess';
if (!is_dir($sessDir)) {
    @mkdir($sessDir, 0700, true);
}
session_save_path($sessDir);
session_start();

// A logged-in staff member of the requested role.
$_SESSION['admin_id']       = 1;
$_SESSION['admin_username'] = 'test_' . $role;
$_SESSION['admin_role']     = $role;
$_SESSION['csrf_token']     = 'EDUCFGTESTTOKEN';

$params['csrf_token'] = 'EDUCFGTESTTOKEN';

$_SERVER['REQUEST_METHOD'] = $method;
$_SERVER['REMOTE_ADDR']    = '127.0.0.1';
$_SERVER['HTTP_USER_AGENT'] = 'education-config-test';
$_SERVER['SCRIPT_NAME']    = '/admin/' . $apiName;

$_GET     = $params;
$_REQUEST = $params;
$_POST    = ($method === 'POST') ? $params : ['csrf_token' => 'EDUCFGTESTTOKEN'];

chdir(dirname($apiFile));
require $apiFile;
