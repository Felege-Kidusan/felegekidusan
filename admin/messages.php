<?php
/**
 * ============================================================
 * Messages — thin shell (P73 Phase 2)
 * ============================================================
 * The messaging experience lives in the shared partial
 * (admin/components/comm/comm_section.php, Messages view) rendered
 * by every dashboard. This page is the deep-link URL (bell,
 * bookmarks, the mobile app): the same partial in page mode.
 * Zero business logic and zero page-specific CSS/JS.
 */
require_once __DIR__ . '/config.php';

if (session_status() === PHP_SESSION_NONE) { session_start(); }
if (empty($_SESSION['admin_logged_in'])) { header('Location: index.php'); exit; }

require_once __DIR__ . '/backend/services/NotificationCenterService.php';
use App\Services\NotificationCenterService;

$role     = (string)($_SESSION['admin_role'] ?? '');
$todayFormatted = date('F j, Y');
if (function_exists('ethio_date_format')) {
    try { $todayFormatted = ethio_date_format(new DateTime('now', new DateTimeZone('Africa/Addis_Ababa')), 'F j, Y'); } catch (Exception $e) {}
}

$NC_COMM_PAGE = true;
$NC_COMM_VIEW = 'messages';
$NC_COMM_CTX  = [
    'canAnnounce' => NotificationCenterService::canAnnounce($role),
    'canMessage'  => NotificationCenterService::canMessage($role),
    'roleLabel'   => NotificationCenterService::ROLE_LABELS[$role] ?? $role,
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
<title>Messages — <?= e(defined('SCHOOL_NAME_SHORT') ? SCHOOL_NAME_SHORT : 'School') ?></title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
</head>
<body style="margin:0">
<div class="nc-page-top">
    <a href="/admin/dashboard.php" class="nc-back"><i class="fa-solid fa-arrow-left" aria-hidden="true"></i> Dashboard</a>
    <div class="nc-page-title">
        <div><i class="fa-solid fa-comments" aria-hidden="true"></i> Messages
            <div class="nc-page-sub"><?= e($todayFormatted) ?> · <?= e($NC_COMM_CTX['roleLabel']) ?></div>
        </div>
    </div>
    <?php include __DIR__ . '/components/notification_center.php'; ?><?= renderNotificationCenter() ?>
</div>
<main class="nc-page-main">
<?php include __DIR__ . '/components/comm/comm_section.php'; ?>
</main>
</body>
</html>
