<?php
/**
 * Legacy Education Department dashboard URL.
 *
 * This file used to be a debug wrapper that rendered the INFORMATION
 * department dashboard (wrong content) with error-reporting knobs left on.
 * The real Education dashboard lives at edu_dept.php — audit finding C4.
 *
 * Keep this file as a permanent 301 so old bookmarks, menu links and search
 * indexes land on the right page instead of a 404. Access control still
 * applies via access_control.php (education_department.php => edu roles).
 */
require_once __DIR__ . '/../config.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

http_response_code(301);
header('Location: edu_dept.php', true, 301);
exit;
