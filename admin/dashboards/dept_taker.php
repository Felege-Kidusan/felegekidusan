<?php
/**
 * Department Attendance Taker landing page (read-only).
 *
 * mezmur_attendance_taker / hr_attendance_taker record attendance
 * from the FKSS mobile app. This web page only confirms the account
 * and points at the app — it never records or edits anything.
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../backend/ethiopian_date.php';

if (session_status() === PHP_SESSION_NONE) session_start();

if (empty($_SESSION['admin_logged_in'])) {
    header('Location: ../index.php');
    exit;
}

// Fail closed: only the two department-owned taker roles land here.
$userRole = (string)($_SESSION['admin_role'] ?? '');
if (!in_array($userRole, ['mezmur_attendance_taker', 'hr_attendance_taker'], true)) {
    http_response_code(403);
    exit('Access denied.');
}

$userName = $_SESSION['admin_full_name'] ?? $_SESSION['admin_username'] ?? 'Taker';
$isMezmur = $userRole === 'mezmur_attendance_taker';
$deptName = $isMezmur ? 'Mezmur Department (መዝሙር ክፍል)' : 'HR Department';

try {
    $now = new DateTime('now', new DateTimeZone('Africa/Addis_Ababa'));
    $todayFormatted = ethio_date_format($now, 'ዓ.ም. F j, Y');
} catch (\Throwable $e) {
    $todayFormatted = date('Y-m-d');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Attendance Taker · <?= e(SCHOOL_NAME_SHORT_AM ?? 'FKSS') ?></title>
<style>
:root{--maroon:#5A1212;--gold:#D4AF37;--bg:#F9FAFB;--card:#fff;--ink:#111827;--dim:#6B7280;--line:#E5E7EB}
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:system-ui,-apple-system,'Segoe UI',Roboto,sans-serif;background:var(--bg);color:var(--ink);min-height:100vh}
.top{background:linear-gradient(135deg,var(--maroon),#7A1E1E);color:#fff;padding:18px 24px;display:flex;align-items:center;justify-content:space-between;gap:12px}
.top h1{font-size:1rem;font-weight:700;display:flex;align-items:center;gap:10px}
.top .sub{font-size:.72rem;opacity:.8;margin-top:2px}
.exit{color:#fecaca;text-decoration:none;font-size:.8rem;border:1px solid rgba(255,255,255,.35);padding:7px 14px;border-radius:10px}
.exit:hover{background:rgba(255,255,255,.12);color:#fff}
.wrap{max-width:760px;margin:32px auto;padding:0 16px;display:grid;gap:16px}
.card{background:var(--card);border:1px solid var(--line);border-radius:16px;padding:22px}
.badge{display:inline-flex;align-items:center;gap:8px;background:rgba(90,18,18,.08);color:var(--maroon);font-weight:600;font-size:.78rem;padding:6px 12px;border-radius:999px}
.steps{margin:14px 0 0 18px;display:grid;gap:8px;font-size:.9rem;color:#374151}
.dim{color:var(--dim);font-size:.82rem;line-height:1.6}
.lockline{display:flex;align-items:center;gap:8px;font-size:.78rem;color:var(--dim);border-top:1px dashed var(--line);margin-top:16px;padding-top:12px}
</style>
</head>
<body>
<header class="top">
    <div>
        <h1>Attendance Taker · <?= e($deptName) ?></h1>
        <div class="sub"><?= e($todayFormatted) ?></div>
    </div>
    <a class="exit" href="/admin/logout.php">Exit</a>
</header>

<main class="wrap">
    <div class="card">
        <span class="badge"><?= e($userName) ?></span>
        <h2 style="font-size:1.05rem;margin-top:14px">Attendance is recorded from the FKSS mobile app</h2>
        <p class="dim" style="margin-top:8px">
            Your account belongs to the <strong><?= e($deptName) ?></strong> only. Open the FKSS app,
            sign in with this account, and take attendance for your department's sections.
            Sheets you save appear as drafts; submitting sends them to your department for review.
        </p>
        <ol class="steps">
            <li>Open the <strong>FKSS app</strong> on your phone.</li>
            <li>Sign in with your taker username and password.</li>
            <li>Pick the date and section, mark each member, then <strong>Save</strong> or <strong>Submit</strong>.</li>
        </ol>
        <div class="lockline">
            This page is read-only. Department attendance data is managed by your department's
            own takers and reviewers — it is never combined with other departments.
        </div>
    </div>
</main>
</body>
</html>
