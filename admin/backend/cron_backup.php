<?php
/**
 * Backward-compatible cron entry point.
 *
 * Existing server schedules may keep calling this path. New schedules should
 * call admin/tools/backup.php directly. Database dumps are deliberately never
 * created through a query-string key or an anonymous browser request.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    exit('Not found.');
}

require __DIR__ . '/../tools/backup.php';
