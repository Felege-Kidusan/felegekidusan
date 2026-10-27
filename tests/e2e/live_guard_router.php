<?php
/** Local PHP-server rewrite adapter, never a production router. */
if (PHP_SAPI !== 'cli-server' || getenv('SSMS_AUDIT_TESTING') !== '1') {
    http_response_code(404); exit;
}
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if (!is_string($path) || preg_match('~(?:^|/)\.|^/(?:tests|sql|docs|tools|scripts|vendor|Mobile)(?:/|$)~', $path)) {
    http_response_code(404); exit;
}
if (str_starts_with($path, '/api/v1/') && !str_ends_with($path, '.php')) {
    $_GET['_route'] = substr($path, strlen('/api/v1/'));
    require __DIR__ . '/../../api/v1/index.php';
    return true;
}
return false;
