<?php
require_once __DIR__ . '/../school_config.php';
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
$adminUrl = defined('ADMIN_URL') ? ADMIN_URL : '/admin';
$siteName = defined('SCHOOL_NAME_SHORT') ? SCHOOL_NAME_SHORT : 'FKSS';
$siteAm = defined('SCHOOL_NAME_SHORT_AM') ? SCHOOL_NAME_SHORT_AM : '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex">
<meta name="theme-color" content="#5A1212">
<title><?= htmlspecialchars($siteName) ?> — use the website</title>
<script src="/app/js/app.js"></script>
<style>
  *{box-sizing:border-box;margin:0;padding:0}
  body{min-height:100vh;display:flex;align-items:center;justify-content:center;
       font-family:system-ui,-apple-system,Segoe UI,sans-serif;
       background:#f8fafc;color:#1e293b;padding:24px}
  .card{max-width:420px;width:100%;background:#fff;border-radius:20px;
        box-shadow:0 10px 40px rgba(15,23,42,.08);padding:2rem 1.5rem;text-align:center}
  h1{font-size:1.35rem;margin:.4rem 0 .25rem}
  .am{font-size:.9rem;color:#64748b;margin-bottom:1rem}
  p{font-size:.92rem;line-height:1.5;color:#475569;margin-bottom:.85rem}
  a.btn{display:inline-block;background:#5A1212;color:#fff;text-decoration:none;
        padding:.7rem 1.2rem;border-radius:12px;font-weight:600;font-size:.9rem}
  a.btn:hover{background:#3f0c0c}
</style>
</head>
<body>
<div class="card">
  <h1><?= htmlspecialchars($siteName) ?></h1>
  <?php if ($siteAm): ?><div class="am"><?= htmlspecialchars($siteAm) ?></div><?php endif; ?>
  <p>The old phone website is closed so student records stay in one safe place.</p>
  <p>Teachers: use the <strong>FKSS</strong> phone app for attendance and grades.</p>
  <p>Everyone else: sign in on the website.</p>
  <a class="btn" href="<?= htmlspecialchars($adminUrl) ?>">Open the website</a>
</div>
</body>
</html>
