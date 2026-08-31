<?php
/**
 * Education Department Dashboard — <?= SCHOOL_NAME ?>
 * PRODUCTION BUILD — All features complete
 * Teacher CRUD, Classes CRUD, Subjects, Assessments, Grades, Enrollment, Academic Years
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../backend/ethiopian_date.php';
require_once __DIR__ . '/../backend/calendar_system.php';

// Practice members are loaded only from Super Admin → Load button.
// Never write on a GET. That used to hold the only PHP worker and
// make /admin/ time out for everyone.
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET' && session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}

$tablesExist = true;
try { $conn->query("SELECT 1 FROM academic_years LIMIT 1"); } catch (Exception $e) { $tablesExist = false; }

if (session_status() === PHP_SESSION_NONE) session_start();
if (empty($_SESSION['admin_logged_in'])) { header('Location: ../index.php'); exit; }

$userName = $_SESSION['admin_full_name'] ?? $_SESSION['admin_username'] ?? 'User';
$userRole = $_SESSION['admin_role'] ?? 'unknown';
$initials = strtoupper(substr($userName, 0, 1));
$now = new DateTime('now', new DateTimeZone('Africa/Addis_Ababa'));
$todayFormatted = wbws_format_date($now, 'long', $conn);
$greeting = ((int)$now->format('H') < 12) ? 'Good Morning' : (((int)$now->format('H') < 17) ? 'Good Afternoon' : 'Good Evening');

$currentYear = null; $currentTerm = null; $classes = []; $subjects = []; $years = []; $terms = [];
if ($tablesExist) {
    $currentYear = function_exists('ay_resolve') ? ay_resolve($conn)['year'] : null;
    try { $r = $conn->query("SELECT * FROM academic_terms WHERE is_current = 1 LIMIT 1");
    $currentTerm = $r ? $r->fetch_assoc() : null; } catch(Exception $e) {}
    $r = $conn->query("SELECT * FROM classes WHERE is_active = 1 ORDER BY level_order");
    if ($r) while ($row = $r->fetch_assoc()) $classes[] = $row;
    $r = $conn->query("SELECT * FROM subjects WHERE is_active = 1 ORDER BY subject_name");
    if ($r) while ($row = $r->fetch_assoc()) $subjects[] = $row;
    try {
        $r = $conn->query("SELECT id, year_name, is_current FROM academic_years ORDER BY is_current DESC, id DESC");
        if ($r) while ($row = $r->fetch_assoc()) $years[] = $row;
        $r = $conn->query("SELECT id, academic_year_id, term_name, term_number, is_current FROM academic_terms ORDER BY term_number");
        if ($r) while ($row = $r->fetch_assoc()) $terms[] = $row;
    } catch (Exception $e) {}
}

$totalStudents = 0; $r = $conn->query("SELECT COUNT(*) c FROM members WHERE status='active'");
if ($r) $totalStudents = (int)$r->fetch_assoc()['c'];
$totalTeachers = 0; try { $r = $conn->query("SELECT COUNT(*) c FROM users WHERE role='teacher'");
if ($r) $totalTeachers = (int)$r->fetch_assoc()['c']; } catch(Exception $e) {}
$totalSubjects = count($subjects);
$totalClasses = count($classes);
$totalEnrolled = 0;
if ($currentYear) { $stmt = $conn->prepare("SELECT COUNT(*) c FROM class_enrollments WHERE academic_year_id=? AND status='active'"); $stmt->bind_param("i", $currentYear['id']); $stmt->execute(); $r = $stmt->get_result(); if($r) $totalEnrolled=(int)$r->fetch_assoc()['c']; $stmt->close(); }

$csrfToken = generateCsrfToken();
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>Education Department — <?= SCHOOL_NAME_SHORT ?></title>
<script>const CSRF_TOKEN='<?= $csrfToken ?>';</script>
<script src="https://cdn.tailwindcss.com"></script>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css"/>
<script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js"></script>
<style>
@import url('https://fonts.googleapis.com/css2?family=Noto+Serif+Ethiopic:wght@400;600;700&family=Poppins:wght@300;400;600;700&display=swap');
/* Field footer: inline validation errors + live character counters (patch 8) */
.field-foot{display:flex;justify-content:space-between;align-items:baseline;gap:.5rem;margin-top:.2rem;min-height:1rem}
.field-hint{font-size:.65rem;color:#94a3b8;margin-left:auto;white-space:nowrap}
.field-hint.warn{color:#b45309}
.field-hint.over{color:#dc2626;font-weight:600}
.field-err{font-size:.68rem;color:#dc2626;line-height:1.2}
.inp.err{border-color:#dc2626 !important}
body{font-family:'Poppins',sans-serif;background:#f8fafc;margin:0}
.amharic{font-family:'Noto Serif Ethiopic',serif}
.sb{background:linear-gradient(180deg,#6d28d9,#5b21b6);width:260px;position:sticky;top:0;height:100vh;overflow-y:auto;flex-shrink:0;padding:1.25rem;display:flex;flex-direction:column;gap:1.25rem}
.nl{display:flex;align-items:center;gap:.75rem;padding:.65rem .85rem;border-radius:12px;color:rgba(255,255,255,.7);font-size:.85rem;cursor:pointer;transition:.2s;border:none;background:none;width:100%;text-align:left}
.nl:hover,.nl.act{background:rgba(255,255,255,.15);color:#fff}.nl.act{font-weight:600}.nl i{width:18px;text-align:center}
.nt{font-size:.6rem;text-transform:uppercase;letter-spacing:.1em;color:rgba(255,255,255,.4);padding:.5rem .85rem}
.crd{background:#fff;border-radius:16px;box-shadow:0 1px 3px rgba(0,0,0,.05);margin-bottom:1rem}
.sc{border-radius:16px;color:#fff;padding:1.25rem}
.inp{width:100%;padding:.6rem .85rem;border:1px solid #e2e8f0;border-radius:10px;font-size:.85rem;outline:none;background:#fff}.inp:focus{border-color:#7c3aed;box-shadow:0 0 0 3px rgba(124,58,237,.1)}
.lbl{display:block;font-size:.7rem;font-weight:500;color:#64748b;margin-bottom:.3rem}
.btn{padding:.55rem 1rem;border-radius:10px;font-size:.8rem;font-weight:500;cursor:pointer;border:none;display:inline-flex;align-items:center;gap:.4rem;transition:.2s}
.btn-p{background:#7c3aed;color:#fff}.btn-p:hover{background:#6d28d9}
.btn-s{background:#059669;color:#fff}.btn-s:hover{background:#047857}
.btn-o{background:#f1f5f9;color:#475569}.btn-o:hover{background:#e2e8f0}
.btn-d{background:#ef4444;color:#fff}.btn-d:hover{background:#dc2626}
.btn-w{background:#f59e0b;color:#fff}
.btn-xs{padding:.3rem .5rem;font-size:.7rem}
.ch{display:inline-flex;padding:.2rem .6rem;border-radius:99px;font-size:.65rem;font-weight:600}
.ch-ok{background:#d1fae5;color:#065f46}.ch-w{background:#fef3c7;color:#92400e}.ch-i{background:#dbeafe;color:#1e40af}.ch-p{background:#ede9fe;color:#5b21b6}.ch-d{background:#fee2e2;color:#991b1b}
.tw{overflow-x:auto}.dt{width:100%;font-size:.8rem;border-collapse:collapse}.dt th{background:#f8fafc;padding:.7rem .85rem;text-align:left;font-weight:600;color:#64748b;font-size:.65rem;text-transform:uppercase}.dt td{padding:.65rem .85rem;border-bottom:1px solid #f1f5f9}.dt tr:hover td{background:#faf5ff}
.mo{display:none;position:fixed;inset:0;background:rgba(15,23,42,.7);backdrop-filter:blur(4px);z-index:100;align-items:center;justify-content:center;padding:1rem}.mo.show{display:flex}
.mc{background:#fff;border-radius:20px;max-width:640px;width:100%;max-height:90vh;overflow-y:auto}
.sec{display:none}.sec.act{display:block}
.ab{width:36px;height:36px;border-radius:8px;display:inline-flex;align-items:center;justify-content:center;border:none;cursor:pointer;font-size:.75rem}
.tbn{padding:.55rem 1.1rem;border:none;background:transparent;cursor:pointer;font-size:.8rem;font-weight:500;color:#64748b;border-bottom:2px solid transparent;transition:.2s}.tbn.act{color:#7c3aed;border-bottom-color:#7c3aed}
.at{display:inline-flex;align-items:center;gap:4px;padding:3px 8px;border-radius:8px;font-size:.65rem;background:#ede9fe;color:#5b21b6;margin:2px}
.at button{background:none;border:none;cursor:pointer;color:#7c3aed;padding:0;font-size:.7rem}.at button:hover{color:#dc2626}
.toast{position:fixed;bottom:1.5rem;right:1.5rem;padding:.85rem 1rem;border-radius:12px;color:#fff;z-index:300;animation:slideIn .3s;max-width:380px;display:flex;align-items:flex-start;gap:.55rem;box-shadow:0 10px 28px rgba(15,23,42,.18);font-size:.82rem;line-height:1.4}
.toast-ok{background:#059669}.toast-err{background:#dc2626}.toast-w{background:#d97706}
.toast .tx{flex:1;min-width:0}.toast .tx-close{background:none;border:none;color:#fff;opacity:.8;cursor:pointer;font-size:1.1rem;line-height:1;padding:0}
.form-alert{display:none;padding:.7rem .85rem;border-radius:10px;font-size:.78rem;margin-bottom:.85rem;line-height:1.4}
.form-alert.show{display:block}.form-alert.err{background:#fef2f2;color:#991b1b;border:1px solid #fecaca}
.form-alert.ok{background:#ecfdf5;color:#065f46;border:1px solid #a7f3d0}
.form-alert.warn{background:#fffbeb;color:#92400e;border:1px solid #fde68a}
.fld-err{border-color:#ef4444!important;box-shadow:0 0 0 3px rgba(239,68,68,.12)!important}
@keyframes slideIn{from{opacity:0;transform:translateX(100px)}to{opacity:1;transform:translateX(0)}}
@media(max-width:768px){.toast{left:1rem;right:1rem;bottom:5.2rem;max-width:none}}
.bn{display:none;position:fixed;bottom:0;left:0;right:0;background:rgba(255,255,255,.95);backdrop-filter:blur(10px);border-top:1px solid #e2e8f0;padding:.3rem 0;z-index:50}.bni{display:flex;justify-content:space-around;max-width:480px;margin:0 auto}.bn button,.bn a{display:flex;flex-direction:column;align-items:center;gap:.1rem;background:none;border:none;color:#94a3b8;font-size:.55rem;padding:.2rem .4rem;cursor:pointer;text-decoration:none}.bn button.act{color:#7c3aed}.bn i{font-size:1rem}
.hr-chip{display:inline-flex;align-items:center;gap:4px;padding:.35rem .7rem;border-radius:10px;border:1px solid #e2e8f0;background:#fff;font-size:.72rem;cursor:pointer;color:#475569;font-family:inherit}
.hr-chip.on{background:#7c3aed;border-color:#7c3aed;color:#fff}
.asg-row{display:grid;grid-template-columns:1fr 1fr auto;gap:.4rem;align-items:center;margin-bottom:.4rem}
.t-hits{border:1px solid #e2e8f0;border-radius:10px;max-height:180px;overflow:auto;margin-top:.3rem}
.t-hit{padding:.5rem .7rem;cursor:pointer;font-size:.8rem;border-bottom:1px solid #f1f5f9}
.t-hit:hover{background:#faf5ff}
@media(max-width:768px){.sb{display:none}main{padding:1rem 1rem 5rem!important}.bn{display:block}}
@media print{
.sb,.bn,.no-print,.wbws-bnav,.wbws-mob-header,#ai-fab,#ai-win,#impersonateBar,aside,header,nav,.mo{display:none!important;visibility:hidden!important}
main{padding:0!important;background:#fff!important;color:#1a0a0a!important}
#sec-reportcards .sc,#sec-reportcards .rc-stat{background:#fff!important;color:#1a0a0a!important;border:2px solid #600000!important;-webkit-print-color-adjust:exact;print-color-adjust:exact}
#sec-reportcards .sc div,#sec-reportcards .sc span{color:#1a0a0a!important;opacity:1!important}
}
</style>
<?= wbws_calendar_scripts($conn) ?>
<link rel="stylesheet" href="/admin/css/mobile.css">
<link rel="stylesheet" href="/admin/css/report_card.css?v=20260819c">
<?php include __DIR__ . "/../theme.php"; ?>
</head>
<body>
<?php if (function_exists("ay_context_bar_html")) echo ay_context_bar_html($conn ?? null); ?>
<div style="display:flex;min-height:100vh">
<!-- SIDEBAR -->
<aside class="sb school-sidebar">
<div style="display:flex;align-items:center;gap:.75rem;margin-bottom:.5rem"><div style="width:42px;height:42px;background:rgba(255,255,255,.2);border-radius:12px;display:flex;align-items:center;justify-content:center"><i class="fa-solid fa-graduation-cap" style="color:#fff;font-size:1.1rem"></i></div><div><div style="color:#fff;font-weight:700;font-size:.9rem">Education Dept</div><div class="amharic" style="color:rgba(255,255,255,.6);font-size:.65rem">የትምህርት ክፍል</div></div></div>
<div>
<div class="nt">Main</div>
<button class="nl act" data-sec="dashboard"><i class="fa-solid fa-gauge-high"></i> Dashboard</button>
<button class="nl" data-sec="teachers"><i class="fa-solid fa-chalkboard-teacher"></i> Teachers</button>
<button class="nl" data-sec="classes"><i class="fa-solid fa-school"></i> Classes</button>
<button class="nl" data-sec="subjects"><i class="fa-solid fa-book"></i> Subjects</button>
</div>
<div>
<div class="nt">Academic</div>
<button class="nl" data-sec="enrollment"><i class="fa-solid fa-user-graduate"></i> Enrollment</button>
<button class="nl" data-sec="grades"><i class="fa-solid fa-star"></i> Grades</button>
<button class="nl" data-sec="assessments"><i class="fa-solid fa-clipboard-list"></i> Assessments</button>
<button class="nl" data-sec="settings"><i class="fa-solid fa-cog"></i> Academic Year</button>
</div>
<div>
<div class="nt">Communication</div>
<button class="nl" data-sec="submissions"><i class="fa-solid fa-inbox"></i> Submissions</button>
<button class="nl" data-sec="reportcards"><i class="fa-solid fa-file-lines"></i> Report Cards</button>
</div>
<div style="margin-top:auto;display:flex;align-items:center;gap:.6rem;padding:.6rem;border-radius:12px;background:rgba(255,255,255,.1)"><div style="width:36px;height:36px;border-radius:50%;background:linear-gradient(135deg,#7c3aed,#6366f1);display:flex;align-items:center;justify-content:center;color:#fff;font-weight:700;font-size:.8rem"><?= $initials ?></div><div><span style="font-size:.75rem;font-weight:600;color:#fff"><?= e($userName) ?></span><br><span style="font-size:.6rem;color:rgba(255,255,255,.6)"><?= $todayFormatted ?></span></div></div>
<a href="/admin/logout.php" class="nl" style="color:#fca5a5"><i class="fa-solid fa-right-from-bracket"></i> Logout</a>
</aside>

<main style="flex:1;padding:1.5rem 2rem 4rem;overflow-y:auto">
<!-- Mobile Header (visible only on mobile) -->
<div class="wbws-mob-header">
    <a href="/admin/dashboard.php" class="mob-back"><i class="fa-solid fa-arrow-left"></i></a>
    <div class="mob-title">
        <h1>Education Dept</h1>
        <p class="mob-sub"><?= $todayFormatted ?></p>
    </div>
    <div class="mob-avatar"><?= $initials ?></div>
</div>
<?php if (!$tablesExist): ?>
<div class="crd" style="text-align:center;padding:3rem"><i class="fa-solid fa-database" style="font-size:3rem;color:#7c3aed;margin-bottom:1rem"></i><h2 style="margin-bottom:.5rem">Setup Required</h2><p style="color:#64748b;margin-bottom:1.5rem">Education tables need to be created first.</p><span class="bg bg-w">Ask the deployment administrator to apply the versioned SQL migrations.</span></div>
<?php else: ?>

<!-- ═══ DASHBOARD ═══ -->
<div id="sec-dashboard" class="sec act">
<div style="margin-bottom:1.5rem"><h1 style="font-size:1.4rem;font-weight:700;color:#1e293b"><?= $greeting ?>, <?= e(explode(' ',$userName)[0]) ?> 📚</h1><p style="color:#64748b;font-size:.8rem"><?= $todayFormatted ?> • Education Department<?php if($currentYear): ?> • <span class="ch ch-p"><?= e($currentYear['year_name']) ?></span><?php endif; ?></p></div>
<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:1rem;margin-bottom:1.5rem">
<div class="sc" style="background:linear-gradient(135deg,#7c3aed,#6366f1)"><div style="display:flex;justify-content:space-between;align-items:center"><div><div style="font-size:1.75rem;font-weight:700"><?= $totalStudents ?></div><div style="font-size:.75rem;opacity:.8">Total Students</div></div><i class="fa-solid fa-users" style="font-size:1.5rem;opacity:.3"></i></div></div>
<div class="sc" style="background:linear-gradient(135deg,#059669,#10b981)"><div style="display:flex;justify-content:space-between;align-items:center"><div><div style="font-size:1.75rem;font-weight:700"><?= $totalTeachers ?></div><div style="font-size:.75rem;opacity:.8">Teachers</div></div><i class="fa-solid fa-chalkboard-teacher" style="font-size:1.5rem;opacity:.3"></i></div></div>
<div class="sc" style="background:linear-gradient(135deg,#0ea5e9,#3b82f6)"><div style="display:flex;justify-content:space-between;align-items:center"><div><div style="font-size:1.75rem;font-weight:700"><?= $totalClasses ?></div><div style="font-size:.75rem;opacity:.8">Classes</div></div><i class="fa-solid fa-school" style="font-size:1.5rem;opacity:.3"></i></div></div>
<div class="sc" style="background:linear-gradient(135deg,#f59e0b,#d97706)"><div style="display:flex;justify-content:space-between;align-items:center"><div><div style="font-size:1.75rem;font-weight:700"><?= $totalSubjects ?></div><div style="font-size:.75rem;opacity:.8">Subjects</div></div><i class="fa-solid fa-book" style="font-size:1.5rem;opacity:.3"></i></div></div>
<div class="sc" style="background:linear-gradient(135deg,#ec4899,#d946ef)"><div style="display:flex;justify-content:space-between;align-items:center"><div><div style="font-size:1.75rem;font-weight:700"><?= $totalEnrolled ?></div><div style="font-size:.75rem;opacity:.8">Enrolled</div></div><i class="fa-solid fa-user-graduate" style="font-size:1.5rem;opacity:.3"></i></div></div>
</div>
<div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem">
<div class="crd" style="padding:1.25rem"><h3 style="font-size:.9rem;font-weight:600;margin-bottom:.75rem"><i class="fa-solid fa-bolt" style="color:#7c3aed"></i> Quick Actions</h3>
<div style="display:flex;flex-direction:column;gap:.5rem">
<button class="btn btn-p" style="width:100%;justify-content:center" onclick="nav('teachers');openCreateTeacher()"><i class="fa-solid fa-user-plus"></i> Add Teacher</button>
<button class="btn btn-s" style="width:100%;justify-content:center" onclick="nav('enrollment')"><i class="fa-solid fa-user-graduate"></i> Manage Enrollment</button>
<button class="btn btn-o" style="width:100%;justify-content:center" onclick="nav('grades')"><i class="fa-solid fa-star"></i> Enter Grades</button>
<button class="btn btn-o" style="width:100%;justify-content:center" onclick="nav('classes')"><i class="fa-solid fa-plus"></i> Manage Classes</button>
</div></div>
<div class="crd" style="padding:1.25rem"><h3 style="font-size:.9rem;font-weight:600;margin-bottom:.75rem"><i class="fa-solid fa-school" style="color:#059669"></i> Classes Overview</h3>
<?php if (empty($classes)): ?><p style="color:#94a3b8;font-size:.8rem;text-align:center;padding:1rem">No classes created yet</p>
<?php else: foreach ($classes as $c): $cnt=0;if($currentYear){$r2=$conn->query("SELECT COUNT(*) c FROM class_enrollments WHERE class_id={$c['id']} AND academic_year_id={$currentYear['id']} AND status='active'");if($r2)$cnt=(int)$r2->fetch_assoc()['c'];} ?>
<div style="display:flex;justify-content:space-between;padding:.4rem 0;border-bottom:1px solid #f1f5f9;font-size:.8rem"><span class="amharic"><?= e($c['class_name']) ?></span><span class="ch ch-i"><?= $cnt ?> students</span></div>
<?php endforeach; endif; ?></div></div>
</div>

<!-- ═══ TEACHERS ═══ -->
<div id="sec-teachers" class="sec">
<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1rem;flex-wrap:wrap;gap:.5rem">
<div><h2 style="font-size:1.2rem;font-weight:700;color:#1e293b"><i class="fa-solid fa-chalkboard-teacher" style="color:#7c3aed"></i> Teachers</h2><p style="font-size:.75rem;color:#64748b" class="amharic">መምህራን አስተዳደር</p></div>
<button class="btn btn-p" onclick="openCreateTeacher()"><i class="fa-solid fa-plus"></i> Add Teacher</button>
</div>
<div class="crd no-print" style="padding:.75rem"><div style="display:flex;gap:.5rem;flex-wrap:wrap;align-items:center">
<input autocomplete="off" type="text" id="teacherSearch" class="inp" style="max-width:240px" placeholder="Search name, username, or code..." oninput="debounceTeacherSearch()">
<select id="teacherStatus" class="inp" style="max-width:140px" onchange="loadTeachers()"><option value="active">Active</option><option value="all">All statuses</option><option value="inactive">Inactive</option></select>
<select id="teacherSort" class="inp" style="max-width:160px" onchange="renderTeachers()"><option value="name">Sort: Name</option><option value="username">Sort: Username</option><option value="classes">Sort: Most classes</option></select>
<button class="btn btn-o btn-xs" onclick="exportTeachers()"><i class="fa-solid fa-download"></i> Excel</button>
</div></div>
<div class="crd" style="margin-top:.75rem"><div class="tw"><table class="dt"><thead><tr><th>Teacher</th><th>Username</th><th>Email</th><th>Member Link</th><th>Assignments</th><th>Status</th><th class="text-center">Actions</th></tr></thead><tbody id="teacherBody"><tr><td colspan="7" style="text-align:center;padding:1.5rem;color:#94a3b8"><i class="fa-solid fa-spinner fa-spin"></i> Loading...</td></tr></tbody></table></div></div>
</div>

<!-- ═══ CLASSES ═══ -->
<div id="sec-classes" class="sec">
<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1rem"><div><h2 style="font-size:1.2rem;font-weight:700;color:#1e293b"><i class="fa-solid fa-school" style="color:#0ea5e9"></i> Classes</h2><p style="font-size:.75rem;color:#64748b" class="amharic">ክፍሎች አስተዳደር</p></div><button class="btn btn-p" onclick="openClassModal()"><i class="fa-solid fa-plus"></i> Add Class</button></div>
<div class="crd"><div class="tw"><table class="dt"><thead><tr><th>Order</th><th>Name (Amharic)</th><th>Name (English)</th><th>Code</th><th>Section</th><th>Age Group</th><th>Students</th><th>Status</th><th>Actions</th></tr></thead><tbody id="classBody"></tbody></table></div></div>
</div>

<!-- ═══ SUBJECTS ═══ -->
<div id="sec-subjects" class="sec">
<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1rem"><div><h2 style="font-size:1.2rem;font-weight:700;color:#1e293b"><i class="fa-solid fa-book" style="color:#f59e0b"></i> Subjects</h2><p style="font-size:.75rem;color:#64748b" class="amharic">የትምህርት ዓይነቶች</p></div><button class="btn btn-p" onclick="openSubjectModal()"><i class="fa-solid fa-plus"></i> Add Subject</button></div>
<div class="crd"><div class="tw"><table class="dt"><thead><tr><th>Subject (Amharic)</th><th>Subject (English)</th><th>Code</th><th>Classes</th><th>Actions</th></tr></thead><tbody>
<?php foreach ($subjects as $s): $cnt=0;try{$r2=$conn->query("SELECT COUNT(*) c FROM class_subjects WHERE subject_id={$s['id']}");if($r2)$cnt=(int)$r2->fetch_assoc()['c'];}catch(Exception $e){} ?>
<tr><td class="amharic" style="font-weight:600"><?= e($s['subject_name']) ?></td><td><?= e($s['subject_name_en'] ?? '—') ?></td><td><code style="font-size:.7rem;background:#f1f5f9;padding:2px 6px;border-radius:4px"><?= e($s['subject_code'] ?? '—') ?></code></td><td><span class="ch ch-i"><?= $cnt ?> classes</span></td><td><button onclick='editSubject(<?= json_encode($s, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>)' class="ab" style="background:#ede9fe;color:#7c3aed" title="Edit"><i class="fa-solid fa-pen"></i></button></td></tr>
<?php endforeach; if(empty($subjects)): ?><tr><td colspan="5" style="text-align:center;padding:1.5rem;color:#94a3b8">No subjects yet</td></tr><?php endif; ?>
</tbody></table></div></div>
</div>

<!-- ═══ ENROLLMENT (ADVANCED) ═══ -->
<div id="sec-enrollment" class="sec">
<!-- Enrollment Overview Stats -->
<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1rem;flex-wrap:wrap;gap:.5rem">
<div><h2 style="font-size:1.2rem;font-weight:700;color:#1e293b"><i class="fa-solid fa-user-graduate" style="color:#ec4899"></i> Student Enrollment</h2><p style="font-size:.75rem;color:#64748b" class="amharic">የተማሪ ምዝገባ አስተዳደር</p></div>
<div style="display:flex;gap:.5rem;flex-wrap:wrap">
<button class="btn btn-p" onclick="openBulkEnrollModal()"><i class="fa-solid fa-users"></i> Bulk Enroll</button>
<button class="btn btn-o btn-xs" onclick="loadEnrollOverview()"><i class="fa-solid fa-sync"></i> Refresh</button>
</div>
</div>

<!-- Overview Stat Cards -->
<div id="enrollOverviewStats" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:.75rem;margin-bottom:1rem"></div>

<!-- Tab Navigation -->
<div style="display:flex;gap:0;border-bottom:2px solid #e2e8f0;margin-bottom:1rem">
<button class="tbn act" id="enrTabClasses" onclick="switchEnrollTab('classes')"><i class="fa-solid fa-school"></i> By Class</button>
<button class="tbn" id="enrTabRoster" onclick="switchEnrollTab('roster')"><i class="fa-solid fa-table-list"></i> All Students</button>
<button class="tbn" id="enrTabUnassigned" onclick="switchEnrollTab('unassigned')"><i class="fa-solid fa-user-xmark"></i> Unassigned Members</button>

</div>

<!-- TAB: By Class -->
<div id="enrPanelClasses">
<div class="crd" style="padding:1rem">
<div style="display:grid;grid-template-columns:1fr 1fr auto;gap:.75rem;align-items:end">
<div><label class="lbl">Select Class</label><select id="enrollClass" class="inp" onchange="loadEnrolled()"><option value="">— Select Class —</option><?php foreach ($classes as $c): ?><option value="<?= $c['id'] ?>"><?= e($c['class_name']) ?> (<?= e($c['class_name_en'] ?? '') ?>)</option><?php endforeach; ?></select></div>
<div><label class="lbl">Search & Add Student</label>
<div style="position:relative"><input type="text" id="enrollSearchInput" class="inp" placeholder="Type name or code to search..." autocomplete="off" oninput="liveSearchEnroll(this.value)">
<div id="enrollSearchResults" style="display:none;position:absolute;top:100%;left:0;right:0;max-height:250px;overflow-y:auto;background:#fff;border:1px solid #e2e8f0;border-radius:0 0 10px 10px;box-shadow:0 4px 12px rgba(0,0,0,.1);z-index:20"></div></div></div>
<button class="btn btn-p" onclick="enrollFromSearch()" id="enrollBtn" disabled><i class="fa-solid fa-user-plus"></i> Enroll</button>
</div>
</div>

<!-- Enrolled Students Area with filters -->
<div id="enrollArea" style="margin-top:.75rem"></div>
</div>

<!-- TAB: All Students -->
<div id="enrPanelRoster" style="display:none">
<div class="crd" style="padding:1rem">
<div id="rosterFormAlert" class="form-alert" role="alert"></div>
<div style="display:grid;grid-template-columns:1fr auto auto auto auto auto;gap:.5rem;align-items:end;flex-wrap:wrap">
<div><label class="lbl">Search</label><input type="text" id="rosterQ" class="inp" placeholder="Name or code…" autocomplete="off" oninput="debounceRoster()"></div>
<div><label class="lbl">Class</label><select id="rosterClass" class="inp" onchange="loadRoster(1)"><option value="">All classes</option><option value="unassigned">Unassigned</option><?php foreach ($classes as $c): ?><option value="<?= (int)$c['id'] ?>"><?= e($c['class_name']) ?></option><?php endforeach; ?></select></div>
<div><label class="lbl">Gender</label><select id="rosterGender" class="inp" onchange="loadRoster(1)"><option value="">All</option><option value="male">Male</option><option value="female">Female</option></select></div>
<div><label class="lbl">Type</label><select id="rosterType" class="inp" onchange="loadRoster(1)"><option value="">All types</option><option value="regular">Regular</option><option value="special_regular">Special</option><option value="honorary">Honorary</option></select></div>
<div><label class="lbl">Age</label><select id="rosterAge" class="inp" onchange="loadRoster(1)"><option value="">All</option><option value="7_13">7–13</option><option value="14_17">14–17</option><option value="18_plus">18+</option></select></div>
<div><label class="lbl">Sort</label><select id="rosterSort" class="inp" onchange="loadRoster(1)"><option value="name">Name</option><option value="code">Code</option><option value="class">Class</option></select></div>
</div>
<div style="display:flex;gap:.5rem;flex-wrap:wrap;align-items:end;margin-top:.65rem">
<div style="min-width:180px"><label class="lbl">Enroll selected into</label><select id="rosterTargetClass" class="inp"><option value="">— Class —</option><?php foreach ($classes as $c): ?><option value="<?= (int)$c['id'] ?>"><?= e($c['class_name']) ?></option><?php endforeach; ?></select></div>
<button class="btn btn-s" type="button" onclick="rosterBulkEnroll()"><i class="fa-solid fa-users"></i> Enroll selected</button>
</div>
</div>
<div id="rosterArea" style="margin-top:.75rem"></div>
</div>

<!-- TAB: Unassigned Members -->
<div id="enrPanelUnassigned" style="display:none">
<div class="crd" style="padding:1rem">
<div id="unassignedFormAlert" class="form-alert" role="alert"></div>
<div style="display:grid;grid-template-columns:1fr auto auto auto auto auto;gap:.5rem;align-items:end;flex-wrap:wrap">
<div><label class="lbl">Search Members</label><input autocomplete="off" type="text" id="unassignedSearch" class="inp" placeholder="Search by name or code..." oninput="debounceUnassigned()"></div>
<div><label class="lbl">Gender</label><select id="unassignedGender" class="inp" onchange="loadUnassigned()"><option value="">All</option><option value="male">Male ♂</option><option value="female">Female ♀</option></select></div>
<div><label class="lbl">Type</label><select id="unassignedMType" class="inp" onchange="loadUnassigned()"><option value="">All Types</option><option value="regular">Regular</option><option value="special_regular">Special</option><option value="honorary">Honorary</option></select></div>
<div><label class="lbl">Age Group</label><select id="unassignedAge" class="inp" onchange="loadUnassigned()"><option value="">All</option><option value="7_13">7-13</option><option value="14_17">14-17</option><option value="18_plus">18+</option></select></div>
<div><label class="lbl">Enroll To</label><select id="unassignedTargetClass" class="inp"><option value="">— Class —</option><?php foreach ($classes as $c): ?><option value="<?= $c['id'] ?>"><?= e($c['class_name']) ?></option><?php endforeach; ?></select></div>
<button class="btn btn-s" onclick="bulkEnrollSelected()"><i class="fa-solid fa-users"></i> Enroll Selected</button>
</div>
</div>
<div id="unassignedArea" style="margin-top:.75rem"></div>
</div>

<!-- TAB: Teacher Assignments -->
<div id="enrPanelTeachers" style="display:none">
<div class="crd" style="padding:.85rem 1rem;margin-bottom:.75rem;border-left:4px solid #7c3aed;background:#faf5ff">
<div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:.5rem">
<div style="font-size:.82rem;color:#5b21b6"><i class="fa-solid fa-chalkboard-teacher"></i> Create a teacher login and assign classes from Teachers — one section.</div>
<button class="btn btn-p btn-xs" type="button" onclick="nav('teachers')"><i class="fa-solid fa-arrow-right"></i> Open Teachers</button>
</div>
</div>
<div class="crd" style="padding:1rem">
<div style="display:grid;grid-template-columns:1fr 1fr 1fr auto;gap:.5rem;align-items:end">
<div><label class="lbl">Teacher</label><select id="taTeacher" class="inp"><option value="">— Select Teacher —</option></select></div>
<div><label class="lbl">Assign to Class</label><select id="taClass" class="inp"><option value="">— Select Class —</option><?php foreach ($classes as $c): ?><option value="<?= $c['id'] ?>"><?= e($c['class_name']) ?></option><?php endforeach; ?></select></div>
<div><label class="lbl">Subject</label><select id="taSubject" class="inp"><option value="">— Optional —</option><?php foreach ($subjects as $s): ?><option value="<?= $s['id'] ?>"><?= e($s['subject_name']) ?></option><?php endforeach; ?></select></div>
<button class="btn btn-s" onclick="assignTeacherFromPanel()"><i class="fa-solid fa-link"></i> Assign</button>
</div>
<div style="margin-top:.5rem"><label style="display:flex;align-items:center;gap:.3rem;font-size:.75rem;color:#64748b"><input type="checkbox" id="taClassTeacher"> Set as Class Teacher (homeroom)</label></div>
</div>
<!-- Unassigned Teachers -->
<div id="unassignedTeachersArea" style="margin-top:.75rem"></div>
<!-- Class-Teacher Overview Grid -->
<div id="classTeacherOverview" style="margin-top:.75rem"></div>
</div>
</div>

<!-- ═══ GRADES ═══ -->
<div id="sec-grades" class="sec">
<h2 style="font-size:1.2rem;font-weight:700;color:#1e293b;margin-bottom:1rem"><i class="fa-solid fa-star" style="color:#f59e0b"></i> Grade Entry <span class="amharic" style="font-size:.8rem;color:#64748b">የውጤት ማስገቢያ</span></h2>
<div class="crd" style="padding:1rem"><div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:.75rem">
<div><label class="lbl">Class</label><select id="gradeClass" class="inp" onchange="loadGradeSubjects()"><option value="">— Select —</option><?php foreach ($classes as $c): ?><option value="<?= $c['id'] ?>"><?= e($c['class_name']) ?></option><?php endforeach; ?></select></div>
<div><label class="lbl">Subject</label><select id="gradeSubject" class="inp" onchange="loadGradeAssessments()"><option value="">— Select —</option></select></div>
<div id="gradeSubjHint" style="display:none;font-size:.7rem;color:#b45309;background:#fffbeb;border:1px solid #fde68a;border-radius:6px;padding:.35rem .5rem;margin-top:.35rem"></div>
<div><label class="lbl">Assessment</label><select id="gradeAssessment" class="inp" onchange="loadGradeStudents()"><option value="">— Select —</option></select></div>
</div></div>
<div id="gradeArea" style="margin-top:.75rem"></div>
</div>

<!-- ═══ ASSESSMENTS ═══ -->
<div id="sec-assessments" class="sec">
<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1rem"><div><h2 style="font-size:1.2rem;font-weight:700;color:#1e293b"><i class="fa-solid fa-clipboard-list" style="color:#7c3aed"></i> Assessment Management</h2><p style="font-size:.75rem;color:#64748b">Configure tests, exams, quizzes</p></div><button class="btn btn-p" onclick="openAssessmentModal()"><i class="fa-solid fa-plus"></i> Add Assessment</button></div>
<div class="crd" style="padding:1rem"><div style="display:grid;grid-template-columns:1fr 1fr;gap:.75rem">
<div><label class="lbl">Class</label><select id="asmtClass" class="inp" onchange="loadAsmtSubjects()"><option value="">— Select —</option><?php foreach ($classes as $c): ?><option value="<?= $c['id'] ?>"><?= e($c['class_name']) ?></option><?php endforeach; ?></select></div>
<div><label class="lbl">Subject</label><select id="asmtSubject" class="inp" onchange="loadAssessments()"><option value="">— Select —</option></select></div>
<div id="asmtSubjHint" style="display:none;font-size:.7rem;color:#b45309;background:#fffbeb;border:1px solid #fde68a;border-radius:6px;padding:.35rem .5rem;margin-top:.35rem"></div>
</div></div>
<div id="assessmentList" style="margin-top:.75rem"></div>
</div>

<!-- ═══ SETTINGS (Academic Year + Semesters) ═══ -->
<div id="sec-settings" class="sec">
<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1rem;flex-wrap:wrap;gap:.5rem"><div><h2 style="font-size:1.2rem;font-weight:700;color:#1e293b"><i class="fa-solid fa-calendar" style="color:#7c3aed"></i> Academic Year Setup</h2><p style="font-size:.75rem;color:#64748b" class="amharic">የትምህርት ዘመን እና ሴሚስተር አስተዳደር</p></div><span style="font-size:.72rem;color:#64748b;background:#f1f5f9;padding:.45rem .75rem;border-radius:8px"><i class="fa-solid fa-lock" style="color:#94a3b8"></i> Managed by School Admin</span></div>
<?php if($currentYear): ?>
<div class="crd" style="padding:1rem;margin-bottom:1rem;border-left:4px solid #7c3aed;background:#faf5ff">
<div style="display:flex;align-items:center;gap:.75rem;flex-wrap:wrap">
<span style="font-weight:700;color:#5b21b6;font-size:.9rem"><i class="fa-solid fa-calendar-check"></i> Current Year:</span>
<span class="ch ch-p" style="font-size:.8rem"><?= e($currentYear['year_name']) ?></span>
<?php if($currentTerm): ?><span class="ch ch-i" style="font-size:.75rem"><?= e($currentTerm['term_name']) ?></span><?php endif; ?>
</div></div>
<?php endif; ?>
<div class="crd"><div class="tw"><table class="dt"><thead><tr><th>Year Name</th><th>EC Year</th><th>GC Year</th><th>Start</th><th>End</th><th>Semesters</th><th>Current</th><th>Actions</th></tr></thead><tbody id="yearBody"></tbody></table></div></div>
<div id="termArea" style="margin-top:.75rem"></div>
</div>

<!-- ═══ SUBMISSIONS REVIEW ═══ -->
<div id="sec-submissions" class="sec">
<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1rem;flex-wrap:wrap;gap:.5rem">
<div><h2 style="font-size:1.2rem;font-weight:700;color:#1e293b"><i class="fa-solid fa-inbox" style="color:#7c3aed"></i> Teacher Submissions</h2><p style="font-size:.75rem;color:#64748b">Drafts are still being worked on. Submitted means the teacher finished.</p></div>
<div style="display:flex;gap:.5rem;flex-wrap:wrap">
<select id="subFilterType" class="inp" style="max-width:150px" onchange="loadSubmissions()"><option value="">All types</option><option value="attendance">Attendance</option><option value="marklist">Mark lists</option></select>
<select id="subFilterClass" class="inp" style="max-width:180px" onchange="loadSubmissions()"><option value="">All Classes</option><?php foreach ($classes as $c): ?><option value="<?= $c['id'] ?>"><?= e($c['class_name']) ?></option><?php endforeach; ?></select>
<button class="btn btn-o btn-xs" type="button" onclick="exportSubmissions()"><i class="fa-solid fa-download"></i> Excel</button>
<button class="btn btn-o btn-xs" type="button" onclick="loadSubmissions()"><i class="fa-solid fa-sync"></i> Refresh</button>
<button class="btn btn-o btn-xs" type="button" onclick="printQrRoster()" title="Printable QR tiles for this class — scanned by takers in the mobile app"><i class="fa-solid fa-qrcode"></i> QR Roster</button>
</div></div>
<div style="display:flex;gap:0;border-bottom:2px solid #e2e8f0;margin-bottom:1rem">
<button class="tbn act" id="subTabDraft" type="button" onclick="switchSubTab('draft')"><i class="fa-solid fa-pen-to-square"></i> Drafts</button>
<button class="tbn" id="subTabSubmitted" type="button" onclick="switchSubTab('submitted')"><i class="fa-solid fa-paper-plane"></i> Submitted</button>
<button class="tbn" id="subTabInsights" type="button" onclick="switchSubTab('insights')"><i class="fa-solid fa-chart-line"></i> Insights</button>
</div>
<input autocomplete="off" type="hidden" id="subFilterStatus" value="draft">
<div id="subStatsRow" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:.75rem;margin-bottom:1rem"></div>
<div id="submissionsList" class="crd" style="padding:.5rem"><div style="text-align:center;padding:1.5rem;color:#94a3b8"><i class="fa-solid fa-spinner fa-spin"></i> Loading...</div></div>
<div id="subInsights" class="crd" style="padding:1rem;display:none"></div>
</div>

<!-- ═══ REVIEW MODAL ═══ -->
<div class="mo" id="reviewModal"><div class="mc" style="max-width:1100px">
<div style="background:linear-gradient(135deg,#7c3aed,#6366f1);color:#fff;padding:1rem 1.25rem;border-radius:20px 20px 0 0;display:flex;justify-content:space-between;align-items:center"><h3 id="reviewModalTitle" style="font-weight:700;font-size:1rem;margin:0"><i class="fa-solid fa-clipboard-check"></i> Review Submission</h3><button onclick="closeModal('reviewModal')" style="background:rgba(255,255,255,.2);border:none;color:#fff;width:32px;height:32px;border-radius:8px;cursor:pointer;font-size:1rem">&times;</button></div>
<div id="reviewModalContent" style="padding:1.25rem"><p style="text-align:center;color:#94a3b8">Loading...</p></div>
</div></div>

<!-- ═══ REPORT CARDS ═══ -->
<div id="sec-reportcards" class="sec">
<div class="rc-print-banner" id="rcPrintBanner">
<div class="am">ፈለገ ቅዱሳን ሰንበት ትምህርት ቤት</div>
<div class="en">Felege Kidusan Sunday School · Class report</div>
<div class="en" id="rcPrintBannerMeta"></div>
</div>
<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1rem;flex-wrap:wrap;gap:.5rem">
<div><h2 style="font-size:1.2rem;font-weight:700;color:#1e293b"><i class="fa-solid fa-file-lines" style="color:#600000"></i> Report Cards</h2><p style="font-size:.75rem;color:#64748b" class="amharic">የተማሪ ሪፖርት ካርድ — totals, average, rank, print</p></div>
</div>
<div class="crd no-print" style="padding:1rem;margin-bottom:1rem">
<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:.65rem;align-items:end">
<div><label class="lbl">Class</label><select id="rcClass" class="inp" onchange="loadClassPerformance()"><option value="">— Select Class —</option><?php foreach ($classes as $c): ?><option value="<?= (int)$c['id'] ?>"><?= e($c['class_name']) ?><?php if (!empty($c['class_name_en'])): ?> (<?= e($c['class_name_en']) ?>)<?php endif; ?></option><?php endforeach; ?></select></div>
<div><label class="lbl">Year</label><select id="rcYear" class="inp" onchange="filterRcTerms();loadClassPerformance()"><option value="">Current year</option><?php foreach ($years as $y): ?><option value="<?= (int)$y['id'] ?>"<?= !empty($y['is_current'])?' selected':''; ?>><?= e($y['year_name']) ?></option><?php endforeach; ?></select></div>
<div><label class="lbl">Term</label><select id="rcTerm" class="inp" onchange="loadClassPerformance()"><option value="">All / current</option><?php foreach ($terms as $tm): ?><option value="<?= (int)$tm['id'] ?>" data-year="<?= (int)$tm['academic_year_id'] ?>"><?= e($tm['term_name']) ?></option><?php endforeach; ?></select></div>
<div><label class="lbl">Subject</label><select id="rcSubject" class="inp" onchange="loadClassPerformance()"><option value="">All subjects</option><?php foreach ($subjects as $s): ?><option value="<?= (int)$s['id'] ?>"><?= e($s['subject_name']) ?></option><?php endforeach; ?></select></div>
</div>
<div style="display:flex;gap:.5rem;flex-wrap:wrap;align-items:end;margin-top:.65rem">
<input autocomplete="off" type="text" id="rcSearch" class="inp" style="max-width:220px" placeholder="Search name or code…" oninput="renderRcTable()">
<select id="rcFilter" class="inp" style="max-width:150px" onchange="renderRcTable()"><option value="">All students</option><option value="graded">Has scores</option><option value="blank">No scores yet</option><option value="A">Grade A</option><option value="B">Grade B</option><option value="C">Grade C</option><option value="D">Grade D</option><option value="F">Grade F</option></select>
<select id="rcSort" class="inp" style="max-width:160px" onchange="renderRcTable()"><option value="rank">Sort: Rank</option><option value="name">Sort: Name</option><option value="average">Sort: Average</option><option value="attendance">Sort: Attendance</option></select>
<button class="btn btn-o" type="button" onclick="exportPerformance()"><i class="fa-solid fa-download"></i> Excel</button>
<button class="btn btn-o" type="button" onclick="printClassList()"><i class="fa-solid fa-print"></i> Print list</button>
<button class="btn btn-s" type="button" onclick="generateBulkReports()"><i class="fa-solid fa-print"></i> Print cards</button>
</div>
</div>
<div id="rcStatsArea" style="display:none">
<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(120px,1fr));gap:.75rem;margin-bottom:1rem" id="rcStatsCards"></div>
<div class="crd" style="padding:1rem;margin-bottom:1rem" id="rcDistBar"></div>
<div class="crd" style="padding:1rem;margin-bottom:1rem;display:none" id="rcSubjectAvg"></div>
</div>
<div id="rcTableArea" style="display:none" class="crd"><div class="tw"><table class="dt"><thead><tr><th>Rank</th><th>Student</th><th>Code</th><th>Obtained</th><th>Average</th><th>Grade</th><th>Attendance</th><th class="no-print">Actions</th></tr></thead><tbody id="rcTableBody"></tbody></table></div></div>
<div id="rcEmptyMsg" class="crd" style="padding:2rem;text-align:center;color:#94a3b8"><i class="fa-solid fa-chart-bar" style="font-size:2rem;margin-bottom:.5rem;display:block;opacity:.3"></i>Select a class to view performance and open report cards</div>
<div class="mo" id="rcModal" onclick="if(event.target===this)closeModal('rcModal')"><div class="mc" style="max-width:920px;padding:0;background:transparent;box-shadow:none">
<div id="rcModalBody" style="background:#fff;border-radius:8px;padding:0 0 .85rem"><p style="text-align:center;color:#94a3b8;padding:2rem"><i class="fa-solid fa-spinner fa-spin"></i> Opening report card…</p></div>
</div></div>
</div>

<?php endif; ?>
</main>
</div>
<!-- TEACHER MODAL — one form: login + class/subject + homeroom -->
<div class="mo" id="teacherModal"><div class="mc" style="max-width:720px">
<div style="background:linear-gradient(135deg,#7c3aed,#6366f1);color:#fff;padding:1rem 1.25rem;border-radius:20px 20px 0 0;display:flex;justify-content:space-between;align-items:center"><h3 id="teacherModalTitle" style="font-weight:700;font-size:1rem;margin:0"><i class="fa-solid fa-user-plus"></i> Add Teacher</h3><button onclick="closeModal('teacherModal')" style="background:rgba(255,255,255,.2);border:none;color:#fff;width:32px;height:32px;border-radius:8px;cursor:pointer;font-size:1rem">&times;</button></div>
<div style="padding:1.25rem">
<p style="font-size:.75rem;color:#64748b;margin:0 0 1rem">Creates their login and class work in one save. They sign in with the username and password.</p>
<div id="teacherFormAlert" class="form-alert" role="alert"></div>
<input type="hidden" id="teacherMemberId" value="">
<div style="margin-bottom:.75rem"><label class="lbl">Link an existing member (optional)</label>
<input id="teacherMemberQ" class="inp" placeholder="Search member name or code..." autocomplete="off" oninput="searchTeacherMembers(this.value)">
<div id="teacherMemberHits" class="t-hits" style="display:none"></div>
<div id="teacherMemberPicked" style="font-size:.72rem;color:#64748b;margin-top:.35rem">Not linked — you can still type a name below.</div>
</div>
<div style="display:grid;grid-template-columns:1fr 1fr;gap:.75rem">
<div><label class="lbl">Full Name *</label><input id="teacherFullName" class="inp" required></div>
<div><label class="lbl">Username *</label><input id="teacherUsername" class="inp" required></div>
<div><label class="lbl">Email</label><input id="teacherEmail" type="email" class="inp"></div>
<div><label class="lbl" id="teacherPasswordLabel">Password *</label><input id="teacherPassword" type="password" class="inp" minlength="12" maxlength="72" autocomplete="new-password">
<div id="teacherPasswordHint" style="font-size:.65rem;color:#94a3b8;margin-top:.25rem">They will sign in with this password.</div></div>
</div>
<div style="margin-top:1.15rem;padding-top:1rem;border-top:1px solid #f1f5f9">
<div style="font-size:.68rem;font-weight:700;letter-spacing:.06em;text-transform:uppercase;color:#7c3aed;margin-bottom:.35rem">Teaching this year</div>
<p style="font-size:.72rem;color:#64748b;margin:0 0 .6rem">Each row is one class + one subject. Add as many as you need. Class Teacher is separate — tap the class chips.</p>
<div id="asgRows"></div>
<button type="button" class="btn btn-o btn-xs" onclick="addAsgRow()" style="margin-top:.25rem"><i class="fa-solid fa-plus"></i> Add class &amp; subject</button>
<div style="margin-top:1rem">
<div style="font-size:.68rem;font-weight:700;letter-spacing:.06em;text-transform:uppercase;color:#64748b;margin-bottom:.35rem"><i class="fa-solid fa-house"></i> Class Teacher of</div>
<p style="font-size:.68rem;color:#94a3b8;margin:0 0 .5rem">A class has one Class Teacher. Picking a class that already has one reassigns it.</p>
<div id="homeroomChips" style="display:flex;flex-wrap:wrap;gap:.4rem"></div>
</div>
</div>
<div style="display:flex;justify-content:flex-end;gap:.5rem;margin-top:1.15rem"><button class="btn btn-o" onclick="closeModal('teacherModal')">Cancel</button><button class="btn btn-p" id="teacherSubmitBtn" onclick="saveTeacher()"><i class="fa-solid fa-save"></i> Save teacher</button></div>
</div></div></div>

<!-- VIEW TEACHER MODAL -->
<div class="mo" id="viewTeacherModal"><div class="mc" style="max-width:720px">
<div style="background:linear-gradient(135deg,#7c3aed,#6366f1);color:#fff;padding:1rem 1.25rem;border-radius:20px 20px 0 0;display:flex;justify-content:space-between;align-items:center"><h3 style="font-weight:700;font-size:1rem;margin:0"><i class="fa-solid fa-user"></i> Teacher Profile</h3><button onclick="closeModal('viewTeacherModal')" style="background:rgba(255,255,255,.2);border:none;color:#fff;width:32px;height:32px;border-radius:8px;cursor:pointer;font-size:1rem">&times;</button></div>
<div id="viewTeacherContent" style="padding:1.25rem"><p style="text-align:center;color:#94a3b8"><i class="fa-solid fa-spinner fa-spin"></i> Loading...</p></div>
</div></div>

<!-- SUBJECT MODAL -->
<div class="mo" id="subjectModal"><div class="mc" style="max-width:500px">
<div style="background:linear-gradient(135deg,#0ea5e9,#3b82f6);color:#fff;padding:1rem 1.25rem;border-radius:20px 20px 0 0;display:flex;justify-content:space-between;align-items:center"><h3 id="subjectModalTitle" style="font-weight:700;font-size:1rem;margin:0"><i class="fa-solid fa-book"></i> Add Subject</h3><button onclick="closeModal('subjectModal')" style="background:rgba(255,255,255,.2);border:none;color:#fff;width:32px;height:32px;border-radius:8px;cursor:pointer;font-size:1rem">&times;</button></div>
<form id="subjectForm" style="padding:1.25rem">
<input type="hidden" id="subjectFormId">
<div style="display:flex;flex-direction:column;gap:.75rem">
<div><label class="lbl" for="subjectName">Name (Amharic) *</label><input id="subjectName" class="inp amharic" required maxlength="150" placeholder="e.g. ቅዱስ ቁርባን" aria-describedby="subjectNameCnt"><div class="field-foot"><span class="field-err" id="subjectNameErr" role="alert"></span><span class="field-hint" id="subjectNameCnt">0/150</span></div></div>
<div><label class="lbl" for="subjectNameEn">Name (English)</label><input id="subjectNameEn" class="inp" maxlength="150" placeholder="e.g. Holy Communion" aria-describedby="subjectNameEnCnt"><div class="field-foot"><span class="field-err" id="subjectNameEnErr" role="alert"></span><span class="field-hint" id="subjectNameEnCnt">0/150</span></div></div>
<div><label class="lbl" for="subjectDesc">Description</label><textarea id="subjectDesc" class="inp" rows="2" maxlength="2000" aria-describedby="subjectDescCnt"></textarea><div class="field-foot"><span class="field-err" id="subjectDescErr" role="alert"></span><span class="field-hint" id="subjectDescCnt">0/2000</span></div></div>
<div><label class="lbl">Taught in classes</label>
<div id="subjectClassChecks" style="display:grid;grid-template-columns:1fr 1fr;gap:.3rem;max-height:160px;overflow:auto;border:1px solid #e2e8f0;border-radius:10px;padding:.5rem">
<?php foreach ($classes as $c): ?>
<label style="display:flex;align-items:center;gap:.35rem;font-size:.75rem"><input type="checkbox" class="subj-class-cb" value="<?= (int)$c['id'] ?>"> <span class="amharic"><?= e($c['class_name']) ?></span></label>
<?php endforeach; ?>
</div>
<p style="font-size:.65rem;color:#94a3b8;margin-top:.25rem">Tick every class that studies this subject.</p>
</div>
</div>
<div style="display:flex;justify-content:flex-end;gap:.5rem;margin-top:1rem"><button type="button" class="btn btn-o" onclick="closeModal('subjectModal')">Cancel</button><button type="submit" class="btn btn-p"><i class="fa-solid fa-save"></i> Save</button></div>
</form></div></div>

<!-- CLASS MODAL -->
<div class="mo" id="classModal"><div class="mc" style="max-width:520px">
<div style="background:linear-gradient(135deg,#0ea5e9,#06b6d4);color:#fff;padding:1rem 1.25rem;border-radius:20px 20px 0 0;display:flex;justify-content:space-between;align-items:center"><h3 id="classModalTitle" style="font-weight:700;font-size:1rem;margin:0"><i class="fa-solid fa-school"></i> Add Class</h3><button onclick="closeModal('classModal')" style="background:rgba(255,255,255,.2);border:none;color:#fff;width:32px;height:32px;border-radius:8px;cursor:pointer;font-size:1rem">&times;</button></div>
<div style="padding:1.25rem">
<input type="hidden" id="classFormId" value="0">
<div style="display:grid;grid-template-columns:1fr 1fr;gap:.75rem">
<div><label class="lbl">Name (Amharic) *</label><input id="className" class="inp amharic" required placeholder="1ኛ ክፍል"></div>
<div><label class="lbl">Name (English)</label><input id="classNameEn" class="inp" placeholder="Grade 1"></div>
<div><label class="lbl">Code *</label><input id="classCode" class="inp" placeholder="grade_1"></div>
<div><label class="lbl">Level Order</label><input type="number" id="classLevel" class="inp" value="1" min="1"></div>
<div><label class="lbl">Section</label><select id="classSection" class="inp"><option value="">—</option><option value="ልጆች">ልጆች (Children)</option><option value="ማእከላዊ">ማእከላዊ (Middle)</option><option value="ሰበካ">ሰበካ (Parish)</option></select></div>
<div><label class="lbl">Age Group</label><select id="classAge" class="inp"><option value="">—</option><option value="7_13">7-13</option><option value="14_17">14-17</option><option value="18_plus">18+</option></select></div>
</div>
<div style="margin-top:.75rem"><label class="lbl">Description</label><textarea id="classDesc" class="inp" rows="2"></textarea></div>
<div style="display:flex;justify-content:flex-end;gap:.5rem;margin-top:1rem"><button class="btn btn-o" onclick="closeModal('classModal')">Cancel</button><button class="btn btn-p" onclick="saveClass()"><i class="fa-solid fa-save"></i> Save</button></div>
</div></div></div>

<!-- ASSESSMENT MODAL -->
<div class="mo" id="assessmentModal"><div class="mc" style="max-width:480px">
<div style="background:linear-gradient(135deg,#7c3aed,#a855f7);color:#fff;padding:1rem 1.25rem;border-radius:20px 20px 0 0;display:flex;justify-content:space-between;align-items:center"><h3 style="font-weight:700;font-size:1rem;margin:0"><i class="fa-solid fa-clipboard-list"></i> New Assessment</h3><button onclick="closeModal('assessmentModal')" style="background:rgba(255,255,255,.2);border:none;color:#fff;width:32px;height:32px;border-radius:8px;cursor:pointer;font-size:1rem">&times;</button></div>
<div style="padding:1.25rem;display:flex;flex-direction:column;gap:.75rem">
<div><label class="lbl">Assessment Name *</label><input id="asmtName" class="inp" placeholder="e.g. Midterm Exam"></div>
<div style="display:grid;grid-template-columns:1fr 1fr;gap:.5rem">
<div><label class="lbl">Max Score</label><input type="number" id="asmtMax" class="inp" value="100"></div>
<div><label class="lbl">Weight (%)</label><input type="number" id="asmtWeight" class="inp" value="100"></div>
</div>
<div style="display:grid;grid-template-columns:1fr 1fr;gap:.5rem">
<div><label class="lbl">Class</label><select id="asmtModalClass" class="inp"><?php foreach ($classes as $c): ?><option value="<?= $c['id'] ?>"><?= e($c['class_name']) ?></option><?php endforeach; ?></select></div>
<div><label class="lbl">Subject</label><select id="asmtModalSubject" class="inp"><?php foreach ($subjects as $s): ?><option value="<?= $s['id'] ?>"><?= e($s['subject_name']) ?></option><?php endforeach; ?></select></div>
</div>
<button class="btn btn-p" onclick="saveAssessment()"><i class="fa-solid fa-save"></i> Create Assessment</button>
</div></div></div>

<!-- YEAR MODAL (with Semester Support) -->
<div class="mo" id="yearModal"><div class="mc" style="max-width:560px">
<div style="background:linear-gradient(135deg,#475569,#334155);color:#fff;padding:1rem 1.25rem;border-radius:20px 20px 0 0;display:flex;justify-content:space-between;align-items:center"><h3 id="yearModalTitle" style="font-weight:700;font-size:1rem;margin:0"><i class="fa-solid fa-calendar"></i> Academic Year</h3><button onclick="closeModal('yearModal')" style="background:rgba(255,255,255,.2);border:none;color:#fff;width:32px;height:32px;border-radius:8px;cursor:pointer;font-size:1rem">&times;</button></div>
<div style="padding:1.25rem;display:flex;flex-direction:column;gap:.75rem">
<input type="hidden" id="yearFormId" value="0">
<div style="display:grid;grid-template-columns:1fr 1fr;gap:.75rem">
<div><label class="lbl">Year Name * <span class="amharic" style="font-size:.6rem;color:#94a3b8">ዓ.ም.</span></label><input id="yearName" class="inp amharic" placeholder="e.g. 2018 ዓ.ም."></div>
<div><label class="lbl">EC Year (Ethiopian)</label><input type="number" id="yearEc" class="inp" value="<?= (int)ethio_date_format($now, 'Y') ?>"></div>
</div>
<div><label class="lbl">GC Year (Gregorian)</label><input id="yearGc" class="inp" placeholder="e.g. 2025/2026"></div>
<div style="display:grid;grid-template-columns:1fr 1fr;gap:.75rem">
<div><label class="lbl">Year Start Date</label><input type="date" id="yearStart" class="inp"></div>
<div><label class="lbl">Year End Date</label><input type="date" id="yearEnd" class="inp"></div>
</div>
<div style="display:flex;align-items:center;gap:.75rem">
<label style="display:flex;align-items:center;gap:.3rem;font-size:.8rem"><input type="checkbox" id="yearCurrent"> Set as Current Year</label>
</div>
<div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:10px;padding:.75rem">
<div style="font-size:.75rem;font-weight:600;color:#64748b;margin-bottom:.5rem"><i class="fa-solid fa-calendar-week" style="color:#7c3aed"></i> Semesters (Auto-created)</div>
<div style="display:grid;grid-template-columns:1fr 1fr;gap:.5rem">
<div style="background:#ede9fe;padding:.5rem .75rem;border-radius:8px"><div style="font-size:.7rem;font-weight:600;color:#5b21b6">1ኛ ሴሚስተር</div><div style="font-size:.6rem;color:#7c3aed">Meskerem — Yekatit</div></div>
<div style="background:#dbeafe;padding:.5rem .75rem;border-radius:8px"><div style="font-size:.7rem;font-weight:600;color:#1e40af">2ኛ ሴሚስተር</div><div style="font-size:.6rem;color:#2563eb">Megabit — Hamle</div></div>
</div>
<p style="font-size:.6rem;color:#94a3b8;margin-top:.3rem">Two semesters will be auto-created when you save a new academic year</p>
</div>
<button class="btn btn-p" style="width:100%;justify-content:center" onclick="saveYear()"><i class="fa-solid fa-save"></i> Save Academic Year</button>
</div></div></div>

<!-- BULK ENROLL MODAL -->
<div class="mo" id="bulkEnrollModal"><div class="mc" style="max-width:680px">
<div style="background:linear-gradient(135deg,#ec4899,#d946ef);color:#fff;padding:1rem 1.25rem;border-radius:20px 20px 0 0;display:flex;justify-content:space-between;align-items:center"><h3 style="font-weight:700;font-size:1rem;margin:0"><i class="fa-solid fa-users"></i> Bulk Enroll Students</h3><button onclick="closeModal('bulkEnrollModal')" style="background:rgba(255,255,255,.2);border:none;color:#fff;width:32px;height:32px;border-radius:8px;cursor:pointer;font-size:1rem">&times;</button></div>
<div style="padding:1.25rem">
<p style="font-size:.75rem;color:#64748b;margin:0 0 .75rem">Pick a class, search the members who are not in a class yet, tick the ones you want, and save once.</p>
<div id="bulkFormAlert" class="form-alert" role="alert"></div>
<div style="display:grid;grid-template-columns:1fr 1fr;gap:.75rem;margin-bottom:.75rem">
<div><label class="lbl">Target class *</label>
<select id="bulkClass" class="inp"><option value="">— Select class —</option><?php foreach ($classes as $c): ?><option value="<?= (int)$c['id'] ?>"><?= e($c['class_name']) ?><?php if (!empty($c['class_name_en'])): ?> (<?= e($c['class_name_en']) ?>)<?php endif; ?></option><?php endforeach; ?></select></div>
<div><label class="lbl">Search members</label>
<input type="text" id="bulkSearch" class="inp" placeholder="Name or member code…" autocomplete="off" oninput="debounceBulkSearch()"></div>
</div>
<div style="display:flex;gap:.5rem;flex-wrap:wrap;align-items:end;margin-bottom:.75rem">
<div style="min-width:140px"><label class="lbl">Filter</label>
<select id="bulkFilter" class="inp" onchange="loadBulkCandidates()"><option value="">All unassigned</option><option value="male">Male</option><option value="female">Female</option><option value="7_13">7–13</option><option value="14_17">14–17</option><option value="18_plus">18+</option></select></div>
</div>
<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:.5rem">
<label style="font-size:.75rem;color:#64748b"><input type="checkbox" id="bulkSelectAll" onchange="toggleBulkAll()"> Select all on this list</label>
<span id="bulkCount" style="font-size:.7rem;color:#7c3aed;font-weight:600">0 selected</span>
</div>
<div id="bulkCandidateList" style="max-height:280px;overflow-y:auto;border:1px solid #e2e8f0;border-radius:10px;padding:.5rem"><div style="padding:1rem;text-align:center;color:#94a3b8">Open this window to load unassigned members.</div></div>
<div style="display:flex;justify-content:flex-end;gap:.5rem;margin-top:1rem">
<button type="button" class="btn btn-o" onclick="closeModal('bulkEnrollModal')">Cancel</button>
<button type="button" class="btn btn-p" id="bulkEnrollBtn" onclick="executeBulkEnroll()"><i class="fa-solid fa-check-double"></i> Enroll selected</button>
</div>
</div></div></div>

<!-- TRANSFER MODAL -->
<div class="mo" id="transferModal"><div class="mc" style="max-width:480px">
<div style="background:linear-gradient(135deg,#0ea5e9,#3b82f6);color:#fff;padding:1rem 1.25rem;border-radius:20px 20px 0 0;display:flex;justify-content:space-between;align-items:center"><h3 style="font-weight:700;font-size:1rem;margin:0"><i class="fa-solid fa-exchange-alt"></i> Transfer Student</h3><button onclick="closeModal('transferModal')" style="background:rgba(255,255,255,.2);border:none;color:#fff;width:32px;height:32px;border-radius:8px;cursor:pointer;font-size:1rem">&times;</button></div>
<div style="padding:1.25rem">
<input type="hidden" id="transferEnrollmentId">
<div id="transferStudentInfo" style="background:#f8fafc;padding:.75rem;border-radius:10px;margin-bottom:1rem;font-size:.85rem"></div>
<div style="margin-bottom:.75rem"><label class="lbl">Transfer to Class *</label><select id="transferToClass" class="inp"><?php foreach ($classes as $c): ?><option value="<?= $c['id'] ?>"><?= e($c['class_name']) ?> (<?= e($c['class_name_en'] ?? '') ?>)</option><?php endforeach; ?></select></div>
<div style="margin-bottom:.75rem"><label class="lbl">Reason (Optional)</label><input id="transferReason" class="inp" placeholder="e.g. Age advancement, parent request..."></div>
<button class="btn btn-p" style="width:100%;justify-content:center" onclick="executeTransfer()"><i class="fa-solid fa-exchange-alt"></i> Transfer Now</button>
</div></div></div>

<!-- BOTTOM NAV -->
<nav class="wbws-bnav" id="wbwsBottomNav">
<div class="wbws-bnav-scroll-hint-left" id="bnScrollL"></div>
<div class="wbws-bnav-scroll-hint-right visible" id="bnScrollR"></div>
<div class="wbws-bnav-inner" id="bnScroll">
<button class="wbws-bnav-btn active" data-sec="dashboard"><i class="fa-solid fa-gauge-high"></i><span>Home</span></button>
<button class="wbws-bnav-btn" data-sec="teachers"><i class="fa-solid fa-chalkboard-teacher"></i><span>Teachers</span></button>
<button class="wbws-bnav-btn" data-sec="classes"><i class="fa-solid fa-school"></i><span>Classes</span></button>
<button class="wbws-bnav-btn" data-sec="enrollment"><i class="fa-solid fa-user-graduate"></i><span>Enroll</span></button>
<div class="wbws-bnav-divider"></div>
<button class="wbws-bnav-btn" data-sec="subjects"><i class="fa-solid fa-book"></i><span>Subjects</span></button>
<button class="wbws-bnav-btn" data-sec="grades"><i class="fa-solid fa-star"></i><span>Grades</span></button>
<button class="wbws-bnav-btn" data-sec="assessments"><i class="fa-solid fa-clipboard-list"></i><span>Assess</span></button>
<button class="wbws-bnav-btn" data-sec="reportcards"><i class="fa-solid fa-file-lines"></i><span>Reports</span></button>
<div class="wbws-bnav-divider"></div>
<button class="wbws-bnav-btn" data-sec="settings"><i class="fa-solid fa-gear"></i><span>Settings</span></button>
<a href="/admin/logout.php" class="wbws-bnav-btn bnav-exit"><i class="fa-solid fa-right-from-bracket"></i><span>Logout</span></a>
</div></nav>
<script>(function(){const sc=document.getElementById('bnScroll'),sl=document.getElementById('bnScrollL'),sr=document.getElementById('bnScrollR');if(!sc)return;function upd(){sl.classList.toggle('visible',sc.scrollLeft>10);sr.classList.toggle('visible',sc.scrollLeft<sc.scrollWidth-sc.clientWidth-10);}sc.addEventListener('scroll',upd,{passive:true});setTimeout(upd,100);sc.querySelectorAll('.wbws-bnav-btn[data-sec]').forEach(b=>{b.addEventListener('click',function(){const s=this.dataset.sec;if(typeof nav==='function')nav(s);sc.querySelectorAll('.wbws-bnav-btn').forEach(x=>x.classList.remove('active'));this.classList.add('active');});});})();</script>

<div id="toastC"></div>
<script src="/admin/js/report_card.js?v=20260819c"></script>
<script>
let allTeachers=[],currentTeacherId=null,asgRows=[],homeroomClassIds=[],homeroomHolders={};
const EDU_CLASSES=<?= json_encode(array_map(static function ($c) {
    return ['id' => (int)$c['id'], 'name' => (string)$c['class_name']];
}, $classes), JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?: '[]' ?>;
const EDU_SUBJECTS=<?= json_encode(array_map(static function ($s) {
    return ['id' => (int)$s['id'], 'name' => (string)$s['subject_name']];
}, $subjects), JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?: '[]' ?>;

// ═══ NAVIGATION ═══
function nav(n){
    try{
        const secs=document.querySelectorAll('.sec');
        secs.forEach(s=>s.classList.remove('act'));
        let t=document.getElementById('sec-'+n);
        if(!t){n='dashboard';t=document.getElementById('sec-dashboard');}
        if(t)t.classList.add('act');
        else if(secs[0])secs[0].classList.add('act');
        document.querySelectorAll('.sb .nl').forEach(b=>b.classList.remove('act'));
        document.querySelectorAll('[data-sec="'+n+'"]').forEach(b=>b.classList.add('act'));
        document.querySelectorAll('.bn button').forEach(b=>b.classList.remove('act'));
        document.querySelectorAll('.bn [data-sec="'+n+'"]').forEach(b=>b.classList.add('act'));
        try{ if(n==='teachers')loadTeachers(); }catch(e){console.error(e);}
        try{ if(n==='classes')loadClasses(); }catch(e){console.error(e);}
        try{ if(n==='settings')loadYears(); }catch(e){console.error(e);}
        try{ if(n==='enrollment')loadEnrollOverview(); }catch(e){console.error(e);}
        const _u=new URL(window.location);_u.searchParams.set('section',n);history.replaceState(null,'',_u);
    }catch(e){
        console.error(e);
        const d=document.getElementById('sec-dashboard');
        if(d)d.classList.add('act');
    }
}
document.querySelectorAll('[data-sec]').forEach(el=>{el.addEventListener('click',function(e){e.preventDefault();const n=this.getAttribute('data-sec');if(n)nav(n);});});
{const _sp=new URLSearchParams(window.location.search).get('section');if(_sp)nav(_sp);}

// ═══ HELPERS ═══
function esc(t){const d=document.createElement('div');d.textContent=t;return d.innerHTML;}
// ── Field-level validation helpers (patch 8) ─────────────────────────────
// Reusable everywhere: live counters, inline errors, error-ref display.
function bindCharCounter(inputId,max){
    const el=document.getElementById(inputId),cnt=document.getElementById(inputId+'Cnt');
    if(!el||!cnt)return;
    const upd=()=>{const n=[...el.value].length;cnt.textContent=n+'/'+max;
        cnt.classList.toggle('warn',n>=max*0.9&&n<=max);cnt.classList.toggle('over',n>max);
        if(n>max){el.classList.add('err');}else if(!el.dataset.errLock){el.classList.remove('err');}};
    el.addEventListener('input',upd);el.addEventListener('change',upd);el._updCounter=upd;upd();
}
function setFieldError(inputId,msg){
    const el=document.getElementById(inputId),err=document.getElementById(inputId+'Err');
    if(err)err.textContent=msg||'';
    if(el){el.dataset.errLock=msg?'1':'';el.classList.toggle('err',!!msg);}
}
function clearFieldErrors(ids){(ids||[]).forEach(id=>setFieldError(id,''))}
function refreshCounters(ids){(ids||[]).forEach(id=>{const el=document.getElementById(id);if(el&&el._updCounter)el._updCounter();})}
function apiErrorMessage(d){let m=d&&d.message?d.message:'Something went wrong. Please try again.';if(d&&d.error_ref)m+=' (Ref: '+d.error_ref+')';return m;}
['subjectName','subjectNameEn','subjectDesc'].forEach(id=>bindCharCounter(id,{'subjectName':150,'subjectNameEn':150,'subjectDesc':2000}[id]));
function fD(d){return (typeof WBWSCalendar!=='undefined')?WBWSCalendar.formatDate(d,'medium'):(d||'—');}
function fDL(d){return (typeof WBWSCalendar!=='undefined')?WBWSCalendar.formatDate(d,'long'):(d||'—');}
function toast(m,t='ok'){
    const box=document.getElementById('toastC');
    if(!box)return;
    const el=document.createElement('div');
    el.className='toast toast-'+t;
    const icon=t==='ok'?'check-circle':(t==='w'?'exclamation-triangle':'exclamation-circle');
    el.innerHTML=`<i class="fa-solid fa-${icon}" style="margin-top:.15rem"></i><div class="tx"></div><button type="button" class="tx-close" aria-label="Close">&times;</button>`;
    el.querySelector('.tx').textContent=m||'';
    el.querySelector('.tx-close').onclick=()=>el.remove();
    box.appendChild(el);
    setTimeout(()=>{if(el.parentNode)el.remove();}, t==='err'?8000:(t==='w'?6000:4500));
}
function friendlyNetError(e){
    const raw=String((e&&e.message)||e||'');
    if(/failed to fetch|networkerror|load failed/i.test(raw)) return 'Could not reach the server. Check your connection and try again.';
    if(/unexpected reply|invalid json|unexpected token/i.test(raw)) return 'The server sent an unexpected reply. Please refresh and try again.';
    return raw||'Something went wrong. Please try again.';
}
function parseJsonResponse(r){
    return r.text().then(txt=>{
        let d=null;
        try{d=JSON.parse(txt);}catch(e){
            throw new Error(r.ok?'The server sent an unexpected reply. Please refresh and try again.':'Could not reach the server. Please try again.');
        }
        if(d && (d.status==='session_expired' || d.action==='reload')){
            throw new Error(d.message||'Your session expired. Please refresh the page and sign in again.');
        }
        if(!r.ok && d && !d.message) d.message='Something went wrong. Please try again.';
        if(!d) throw new Error('Something went wrong. Please try again.');
        return d;
    });
}
function postAPI(url,fd){if(!fd.has('csrf_token'))fd.append('csrf_token',CSRF_TOKEN);return fetch(url,{method:'POST',body:fd,credentials:'same-origin'}).then(parseJsonResponse);}
function getAPI(url){return fetch(url,{credentials:'same-origin'}).then(parseJsonResponse);}
function setFormAlert(id,msg,kind){
    const el=document.getElementById(id);
    if(!el)return;
    if(!msg){el.className='form-alert';el.textContent='';return;}
    el.className='form-alert show '+(kind||'err');
    el.textContent=msg;
    try{el.scrollIntoView({block:'nearest',behavior:'smooth'});}catch(e){}
}
function markField(id,on){const el=document.getElementById(id);if(el)el.classList.toggle('fld-err',!!on);}
function closeModal(id){document.getElementById(id).classList.remove('show');}
function showTab(){/* teacher form is one scroll — no tabs */}

// ═══ TEACHERS ═══
let _teacherSearchTimer=null,_memberSearchTimer=null;
function debounceTeacherSearch(){ clearTimeout(_teacherSearchTimer); _teacherSearchTimer=setTimeout(loadTeachers, 300); }
async function loadTeachers(){
    const status=document.getElementById('teacherStatus')?.value||'active';
    const inc=status==='active'?'0':'1';
    const q=document.getElementById('teacherSearch')?.value||'';
    let url=`/admin/api_teachers.php?action=get_teachers&include_inactive=${inc}&limit=100`;
    if(q.trim()) url+=`&q=${encodeURIComponent(q.trim())}`;
    try{const d=await getAPI(url);
    if(d.status==='success'){allTeachers=d.teachers||[];renderTeachers();}
    else{allTeachers=[];renderTeachers();toast(d.message||'Could not load teachers.','err');}
    }catch(e){allTeachers=[];renderTeachers();toast(friendlyNetError(e),'err');}
}
function renderTeachers(){
    const q=(document.getElementById('teacherSearch')?.value||'').toLowerCase();
    const status=document.getElementById('teacherStatus')?.value||'active';
    const sort=document.getElementById('teacherSort')?.value||'name';
    let list=allTeachers.slice();
    if(status==='inactive') list=list.filter(t=>t.is_active!=1);
    else if(status==='active') list=list.filter(t=>t.is_active==1);
    if(q) list=list.filter(t=>[t.full_name,t.username,t.email,t.member_code].filter(Boolean).join(' ').toLowerCase().includes(q));
    list.sort((a,b)=>{
        if(sort==='username') return String(a.username||'').localeCompare(String(b.username||''));
        if(sort==='classes') return (parseInt(b.assigned_classes,10)||0)-(parseInt(a.assigned_classes,10)||0);
        return String(a.full_name||'').localeCompare(String(b.full_name||''));
    });
    document.getElementById('teacherBody').innerHTML=list.length?list.map(t=>`<tr style="${t.is_active==0?'opacity:.5':''}">
        <td><div style="display:flex;align-items:center;gap:.5rem"><div style="width:36px;height:36px;border-radius:50%;background:linear-gradient(135deg,#7c3aed,#6366f1);display:flex;align-items:center;justify-content:center;color:#fff;font-weight:700;font-size:.7rem">${esc((t.full_name||'?')[0].toUpperCase())}</div><div><div style="font-weight:600;font-size:.8rem">${esc(t.full_name)}</div></div></div></td>
        <td style="font-size:.8rem">${esc(t.username)}</td><td style="font-size:.8rem">${esc(t.email||'—')}</td>
        <td>${t.member_code?`<span class="ch ch-i">${esc(t.member_code)}</span>`:'—'}</td>
        <td><span class="ch ch-p" style="cursor:pointer" onclick="viewTeacher(${t.id})" title="Click to view assignments">${t.assigned_classes||0} class${(t.assigned_classes||0)!=1?'es':''}, ${t.assigned_subjects||0} subj</span></td>
        <td><span class="ch ${t.is_active==1?'ch-ok':'ch-d'}">${t.is_active==1?'Active':'Inactive'}</span></td>
        <td style="text-align:center;white-space:nowrap">
            <button class="ab" style="background:#ede9fe;color:#7c3aed" onclick="viewTeacher(${t.id})" title="View"><i class="fa-solid fa-eye"></i></button>
            <button class="ab" style="background:#dbeafe;color:#2563eb" onclick="editTeacher(${t.id})" title="Edit"><i class="fa-solid fa-pen"></i></button>
            <button class="ab" style="background:${t.is_active==1?'#fef3c7':'#d1fae5'};color:${t.is_active==1?'#92400e':'#065f46'}" onclick="toggleTeacher(${t.id},${t.is_active})" title="${t.is_active==1?'Deactivate':'Activate'}"><i class="fa-solid fa-${t.is_active==1?'ban':'check'}"></i></button>
            <button class="ab" style="background:#fee2e2;color:#dc2626" onclick="deleteTeacher(${t.id},'${esc(t.full_name)}')" title="Delete"><i class="fa-solid fa-trash"></i></button>
        </td></tr>`).join(''):'<tr><td colspan="7" style="text-align:center;padding:1.5rem;color:#94a3b8">No teachers found</td></tr>';
}
function filterTeachers(){renderTeachers();}
function classOptionsHtml(selected){
    return `<option value="">Select class…</option>`+EDU_CLASSES.map(c=>`<option value="${c.id}" ${String(c.id)===String(selected)?'selected':''}>${esc(c.name)}</option>`).join('');
}
function subjectOptionsHtml(selected){
    return `<option value="">Select subject…</option>`+EDU_SUBJECTS.map(s=>`<option value="${s.id}" ${String(s.id)===String(selected)?'selected':''}>${esc(s.name)}</option>`).join('');
}
function addAsgRow(classId,subjectId){asgRows.push({class_id:classId||'',subject_id:subjectId||''});renderAsgRows();}
function removeAsgRow(i){asgRows.splice(i,1);renderAsgRows();}
function setAsgRow(i,field,val){if(!asgRows[i])return;asgRows[i][field]=val;}
function renderAsgRows(){
    const box=document.getElementById('asgRows');
    if(!box)return;
    if(!asgRows.length){box.innerHTML='<p style="font-size:.78rem;color:#94a3b8;margin:.2rem 0 .5rem">No class &amp; subject rows yet.</p>';return;}
    box.innerHTML=asgRows.map((row,i)=>`<div class="asg-row">
        <select class="inp" onchange="setAsgRow(${i},'class_id',this.value)">${classOptionsHtml(row.class_id)}</select>
        <select class="inp" onchange="setAsgRow(${i},'subject_id',this.value)">${subjectOptionsHtml(row.subject_id)}</select>
        <button type="button" class="ab" style="background:#fee2e2;color:#dc2626" onclick="removeAsgRow(${i})" title="Remove"><i class="fa-solid fa-trash"></i></button>
    </div>`).join('');
}
function toggleHomeroom(classId){
    classId=parseInt(classId,10);
    if(!classId)return;
    const i=homeroomClassIds.indexOf(classId);
    if(i>=0) homeroomClassIds.splice(i,1); else homeroomClassIds.push(classId);
    renderHomeroomChips();
}
function renderHomeroomChips(){
    const box=document.getElementById('homeroomChips');
    if(!box)return;
    if(!EDU_CLASSES.length){box.innerHTML='<p style="font-size:.75rem;color:#94a3b8">No classes yet.</p>';return;}
    box.innerHTML=EDU_CLASSES.map(c=>{
        const on=homeroomClassIds.indexOf(c.id)>=0;
        const holder=homeroomHolders[c.id];
        const other=holder&&!on?` · ${esc(holder)}`:'';
        return `<button type="button" class="hr-chip${on?' on':''}" onclick="toggleHomeroom(${c.id})" title="${holder?'Currently: '+esc(holder):'No Class Teacher yet'}">${esc(c.name)}${other}</button>`;
    }).join('');
}
async function loadHomeroomHolders(){
    homeroomHolders={};
    try{
        const d=await getAPI('/admin/api_assignments.php?action=matrix');
        const homes=d.homerooms||{};
        Object.keys(homes).forEach(k=>{
            const h=homes[k];
            if(h&&h.full_name) homeroomHolders[parseInt(k,10)]=h.full_name;
        });
    }catch(e){}
}
function clearTeacherErrors(){
    setFormAlert('teacherFormAlert','');
    ['teacherFullName','teacherUsername','teacherEmail','teacherPassword'].forEach(id=>markField(id,false));
}
function highlightTeacherField(field){
    const map={full_name:'teacherFullName',username:'teacherUsername',email:'teacherEmail',password:'teacherPassword'};
    const id=map[field];
    if(id){markField(id,true);const el=document.getElementById(id);if(el)el.focus();}
}
function resetTeacherForm(){
    clearTeacherErrors();
    document.getElementById('teacherFullName').value='';
    document.getElementById('teacherUsername').value='';
    document.getElementById('teacherEmail').value='';
    document.getElementById('teacherPassword').value='';
    document.getElementById('teacherMemberId').value='';
    document.getElementById('teacherMemberQ').value='';
    document.getElementById('teacherMemberHits').style.display='none';
    document.getElementById('teacherMemberHits').innerHTML='';
    document.getElementById('teacherMemberPicked').textContent='Not linked — you can still type a name below.';
    const btn=document.getElementById('teacherSubmitBtn');
    if(btn){btn.disabled=false;btn.innerHTML='<i class="fa-solid fa-save"></i> Save teacher';}
}
function setPasswordMode(isCreate){
    document.getElementById('teacherPasswordLabel').textContent=isCreate?'Password *':'Password';
    document.getElementById('teacherPasswordHint').textContent=isCreate?'Use at least 12 characters.':'Leave blank to keep the current password; new passwords need at least 12 characters.';
    document.getElementById('teacherPassword').required=!!isCreate;
}
function pickTeacherMember(id,name,code){
    document.getElementById('teacherMemberId').value=id;
    document.getElementById('teacherMemberQ').value='';
    document.getElementById('teacherMemberHits').style.display='none';
    document.getElementById('teacherMemberPicked').innerHTML=`Linked: <strong>${esc(name)}</strong> <span class="ch ch-i">${esc(code||'')}</span> <button type="button" class="btn btn-o btn-xs" onclick="clearTeacherMember()">Unlink</button>`;
    const fn=document.getElementById('teacherFullName');
    if(!fn.value.trim()) fn.value=name;
}
function clearTeacherMember(){
    document.getElementById('teacherMemberId').value='';
    document.getElementById('teacherMemberQ').value='';
    document.getElementById('teacherMemberPicked').textContent='Not linked — you can still type a name below.';
}
function searchTeacherMembers(q){
    clearTimeout(_memberSearchTimer);
    const box=document.getElementById('teacherMemberHits');
    if(!q||q.trim().length<1){box.style.display='none';return;}
    _memberSearchTimer=setTimeout(async()=>{
        try{
            const d=await getAPI('/admin/api_teachers.php?action=search_members_for_teacher&q='+encodeURIComponent(q.trim()));
            const m=d.members||[];
            if(!m.length){box.innerHTML='<div class="t-hit" style="cursor:default;color:#94a3b8">No matching members</div>';box.style.display='block';return;}
            box.innerHTML=m.map(x=>{
                const nm=((x.student_name||'')+' '+(x.father_name||'')).trim();
                const safeNm=nm.replace(/['"\\]/g,'');
                const safeCode=String(x.member_code||'').replace(/['"\\]/g,'');
                return `<div class="t-hit" onclick="pickTeacherMember(${parseInt(x.id,10)},'${esc(safeNm)}','${esc(safeCode)}')"><strong>${esc(x.student_name||'')}</strong> <span style="color:#64748b">${esc(x.father_name||'')}</span> <span class="ch ch-i">${esc(x.member_code||'')}</span></div>`;
            }).join('');
            box.style.display='block';
        }catch(e){box.style.display='none';}
    },250);
}
async function openCreateTeacher(){
    currentTeacherId=null;asgRows=[];homeroomClassIds=[];
    resetTeacherForm();setPasswordMode(true);
    document.getElementById('teacherModalTitle').innerHTML='<i class="fa-solid fa-user-plus"></i> Add Teacher';
    addAsgRow();
    await loadHomeroomHolders();
    renderHomeroomChips();
    document.getElementById('teacherModal').classList.add('show');
}
function editTeacher(id){
    getAPI(`/admin/api_teachers.php?action=get_teacher&teacher_id=${id}`).then(async d=>{
        if(d.status!=='success'){toast(d.message||'That teacher could not be opened.','err');return;}
        const t=d.teacher;currentTeacherId=t.id;
        resetTeacherForm();setPasswordMode(false);
        document.getElementById('teacherModalTitle').innerHTML='<i class="fa-solid fa-pen"></i> Edit Teacher';
        document.getElementById('teacherFullName').value=t.full_name||'';
        document.getElementById('teacherUsername').value=t.username||'';
        document.getElementById('teacherEmail').value=t.email||'';
        if(t.member_id){
            const nm=(t.member_name||t.full_name||'Member');
            pickTeacherMember(t.member_id,nm,t.member_code||'');
        }
        const asgns=t.assignments||d.assignments||[];
        asgRows=[];homeroomClassIds=[];
        asgns.forEach(a=>{
            const isHome=a.is_class_teacher==1||!a.subject_id;
            if(a.subject_id) asgRows.push({class_id:a.class_id,subject_id:a.subject_id});
            if(isHome&&a.class_id){const cid=parseInt(a.class_id,10);if(homeroomClassIds.indexOf(cid)<0)homeroomClassIds.push(cid);}
        });
        if(!asgRows.length) asgRows.push({class_id:'',subject_id:''});
        renderAsgRows();
        await loadHomeroomHolders();
        renderHomeroomChips();
        document.getElementById('teacherModal').classList.add('show');
    }).catch(e=>toast(friendlyNetError(e),'err'));
}
async function saveTeacher(){
    clearTeacherErrors();
    const name=document.getElementById('teacherFullName').value.trim();
    const user=document.getElementById('teacherUsername').value.trim();
    const email=document.getElementById('teacherEmail').value.trim();
    const pw=document.getElementById('teacherPassword').value;
    if(!name){setFormAlert('teacherFormAlert','Please enter the teacher full name.','err');markField('teacherFullName',true);document.getElementById('teacherFullName').focus();return;}
    if(!user){setFormAlert('teacherFormAlert','Please choose a username for login.','err');markField('teacherUsername',true);document.getElementById('teacherUsername').focus();return;}
    if(!currentTeacherId&&pw.length<12){setFormAlert('teacherFormAlert','Set a password of at least 12 characters so they can log in.','err');markField('teacherPassword',true);document.getElementById('teacherPassword').focus();return;}
    if(currentTeacherId&&pw&&pw.length<12){setFormAlert('teacherFormAlert','New password must be at least 12 characters, or leave it blank to keep the current one.','err');markField('teacherPassword',true);document.getElementById('teacherPassword').focus();return;}
    if(email && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)){setFormAlert('teacherFormAlert','That email does not look right.','err');markField('teacherEmail',true);document.getElementById('teacherEmail').focus();return;}
    const incomplete=asgRows.some(r=>(r.class_id&&!r.subject_id)||(!r.class_id&&r.subject_id));
    if(incomplete){setFormAlert('teacherFormAlert','Each teaching row needs both a class and a subject. Finish the row or remove it.','err');return;}
    const assignments=asgRows.filter(r=>r.class_id&&r.subject_id).map(r=>({class_id:parseInt(r.class_id,10),subject_id:parseInt(r.subject_id,10)}));
    const fd=new FormData();
    fd.append('action','save_teacher_bundle');
    if(currentTeacherId) fd.append('teacher_id',currentTeacherId);
    fd.append('full_name',name);
    fd.append('username',user);
    fd.append('email',email);
    fd.append('member_id',document.getElementById('teacherMemberId').value);
    if(pw) fd.append('password',pw);
    fd.append('assignments',JSON.stringify(assignments));
    fd.append('homeroom_class_ids',JSON.stringify(homeroomClassIds));
    const btn=document.getElementById('teacherSubmitBtn');
    if(btn){btn.disabled=true;btn.innerHTML='<i class="fa-solid fa-spinner fa-spin"></i> Saving…';}
    try{
        const d=await postAPI('/admin/api_teachers.php',fd);
        if(d.status==='success' || d.status==='partial'){
            toast(d.message||'Teacher saved.', d.status==='partial'?'w':'ok');
            closeModal('teacherModal');
            loadTeachers();
        }else{
            setFormAlert('teacherFormAlert',d.message||'Could not save this teacher.','err');
            highlightTeacherField(d.field);
            toast(d.message||'Could not save this teacher.','err');
        }
    }catch(e){
        const msg=friendlyNetError(e);
        setFormAlert('teacherFormAlert',msg,'err');
        toast(msg,'err');
    }
    if(btn){btn.disabled=false;btn.innerHTML='<i class="fa-solid fa-save"></i> Save teacher';}
}
async function viewTeacher(id){
    try{const d=await getAPI(`/admin/api_teachers.php?action=get_teacher&teacher_id=${id}`);
    if(d.status==='success'){const t=d.teacher,a=t.assignments||d.assignments||[];
    const initials=(t.full_name||'?').split(' ').map(w=>w[0]).join('').toUpperCase().substring(0,2);
    document.getElementById('viewTeacherContent').innerHTML=`
        <div style="display:flex;gap:1.25rem;align-items:flex-start;margin-bottom:1.25rem;flex-wrap:wrap">
            <div style="width:72px;height:72px;border-radius:16px;background:linear-gradient(135deg,#7c3aed,#6366f1);display:flex;align-items:center;justify-content:center;color:#fff;font-weight:700;font-size:1.3rem;flex-shrink:0">${initials}</div>
            <div style="flex:1;min-width:200px">
                <h3 style="font-size:1.1rem;font-weight:700;color:#1e293b;margin:0 0 .2rem">${esc(t.full_name)}</h3>
                <div style="display:flex;gap:.5rem;flex-wrap:wrap;margin-bottom:.5rem">
                    <span class="ch ${t.is_active==1?'ch-ok':'ch-d'}">${t.is_active==1?'Active':'Inactive'}</span>
                    ${t.member_code?`<span class="ch ch-i"><i class="fa-solid fa-link" style="font-size:.5rem"></i> ${esc(t.member_code)}</span>`:''}
                </div>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:.4rem .75rem;font-size:.8rem">
                    <div style="color:#64748b"><i class="fa-solid fa-user" style="width:14px;color:#94a3b8"></i> ${esc(t.username)}</div>
                    <div style="color:#64748b"><i class="fa-solid fa-envelope" style="width:14px;color:#94a3b8"></i> ${esc(t.email||'No email')}</div>
                    <div style="color:#64748b"><i class="fa-solid fa-phone" style="width:14px;color:#94a3b8"></i> ${esc(t.phone_number||'—')}</div>
                    <div style="color:#64748b"><i class="fa-solid fa-calendar" style="width:14px;color:#94a3b8"></i> ${fD(t.created_at)}</div>
                </div>
            </div>
            <button class="btn btn-o btn-xs" onclick="closeModal('viewTeacherModal');editTeacher(${t.id})" style="flex-shrink:0"><i class="fa-solid fa-pen"></i> Edit</button>
        </div>
        <div style="border-top:1px solid #f1f5f9;padding-top:1rem">
            <h4 style="font-weight:700;font-size:.9rem;color:#1e293b;margin-bottom:.75rem"><i class="fa-solid fa-chalkboard" style="color:#7c3aed"></i> Teaching Assignments <span class="ch ch-p">${a.length}</span></h4>
            ${a.length?`<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(260px,1fr));gap:.6rem">${a.map(x=>`
                <div style="background:#faf5ff;border:1px solid #ede9fe;border-radius:12px;padding:.85rem;display:flex;align-items:flex-start;gap:.6rem">
                <div style="width:40px;height:40px;border-radius:10px;background:linear-gradient(135deg,#7c3aed,#a855f7);display:flex;align-items:center;justify-content:center;flex-shrink:0">
                        <i class="fa-solid fa-book-open" style="color:#fff;font-size:.75rem"></i>
                    </div>
                    <div style="flex:1;min-width:0">
                        <div style="font-weight:700;font-size:.82rem;color:#1e293b" class="amharic">${esc(x.class_name||'Class')}</div>
                        <div style="font-size:.72rem;color:#7c3aed" class="amharic">${esc(x.subject_name||'Subject')}</div>
                        <div style="font-size:.62rem;color:#94a3b8;margin-top:.2rem">${x.student_count||0} students enrolled${x.is_primary==1?' • <span style="color:#059669;font-weight:600">Primary</span>':''}</div>
                    </div>
                </div>`).join('')}</div>`
            :`<div style="text-align:center;padding:1.5rem;color:#94a3b8;background:#f8fafc;border-radius:12px">
                <i class="fa-solid fa-chalkboard" style="font-size:1.5rem;margin-bottom:.5rem;display:block;opacity:.3"></i>
                <p style="font-size:.85rem;margin-bottom:.5rem">No assignments yet</p>
                <button class="btn btn-p btn-xs" onclick="closeModal('viewTeacherModal');editTeacher(${t.id})"><i class="fa-solid fa-plus"></i> Assign Classes</button>
            </div>`}
        </div>`;
    document.getElementById('viewTeacherModal').classList.add('show');}}catch(e){toast(friendlyNetError(e),'err');}
}
async function toggleTeacher(id,cur){const act=cur==1?'deactivate':'activate';if(!confirm(act+' this teacher?'))return;const fd=new FormData();fd.append('action','toggle_status');fd.append('teacher_id',id);try{const d=await postAPI('/admin/api_teachers.php',fd);toast(d.message,d.status==='success'?'ok':'err');if(d.status==='success')loadTeachers();}catch(e){toast('Error','err');}}
async function deleteTeacher(id,name){if(!confirm(`Delete teacher "${name}"? This cannot be undone.`))return;const fd=new FormData();fd.append('action','delete_teacher');fd.append('teacher_id',id);try{const d=await postAPI('/admin/api_teachers.php',fd);toast(d.message,d.status==='success'?'ok':'err');if(d.status==='success')loadTeachers();}catch(e){toast('Error','err');}}
function exportTeachers(){if(!allTeachers.length)return toast('No data','err');const h=['Name','Username','Email','Status','Assignments'];const r=allTeachers.map(t=>[t.full_name,t.username,t.email||'',t.is_active==1?'Active':'Inactive',t.assigned_classes||0]);const ws=XLSX.utils.aoa_to_sheet([h,...r]);const wb=XLSX.utils.book_new();XLSX.utils.book_append_sheet(wb,ws,'Teachers');XLSX.writeFile(wb,'<?= MEMBER_CODE_PREFIX ?>_Teachers.xlsx');}

// ═══ CLASSES ═══
async function loadClasses(){try{const r=await fetch('/admin/api_education.php?action=get_classes',{credentials:'same-origin'});const txt=await r.text();let d;try{d=JSON.parse(txt);}catch(e){console.error('Classes API parse error:',txt);toast('Error loading classes: invalid response','err');return;}if(d.status==='success'){const cls=d.classes||[];document.getElementById('classBody').innerHTML=cls.length?cls.map(c=>`<tr><td style="font-weight:700">${c.level_order}</td><td class="amharic" style="font-weight:600">${esc(c.class_name)}</td><td>${esc(c.class_name_en||'—')}</td><td><code style="font-size:.7rem;background:#f1f5f9;padding:2px 6px;border-radius:4px">${esc(c.class_code)}</code></td><td>${esc(c.section||'—')}</td><td>${esc((c.age_group||'').replace(/_/g,' '))}</td><td><span class="ch ch-i">${c.student_count||0}</span></td><td><span class="ch ${c.is_active==1?'ch-ok':'ch-d'}">${c.is_active==1?'Active':'Inactive'}</span></td><td><button class="ab" style="background:#dbeafe;color:#2563eb" onclick='editClass(${JSON.stringify(c)})'><i class="fa-solid fa-pen"></i></button> <button class="ab" style="background:#fee2e2;color:#dc2626" onclick="deleteClass(${c.id})"><i class="fa-solid fa-trash"></i></button></td></tr>`).join(''):'<tr><td colspan="9" style="text-align:center;padding:1.5rem;color:#94a3b8">No classes. Click Add Class.</td></tr>';}else{toast(d.message||'Error loading classes','err');}}catch(e){console.error('Classes load error:',e);toast('Error loading classes','err');}}
function openClassModal(){document.getElementById('classFormId').value=0;document.getElementById('className').value='';document.getElementById('classNameEn').value='';document.getElementById('classCode').value='';document.getElementById('classLevel').value='1';document.getElementById('classSection').value='';document.getElementById('classAge').value='';document.getElementById('classDesc').value='';document.getElementById('classModalTitle').innerHTML='<i class="fa-solid fa-school"></i> Add Class';document.getElementById('classModal').classList.add('show');}
function editClass(c){document.getElementById('classFormId').value=c.id;document.getElementById('className').value=c.class_name||'';document.getElementById('classNameEn').value=c.class_name_en||'';document.getElementById('classCode').value=c.class_code||'';document.getElementById('classLevel').value=c.level_order||1;document.getElementById('classSection').value=c.section||'';document.getElementById('classAge').value=c.age_group||'';document.getElementById('classDesc').value=c.description||'';document.getElementById('classModalTitle').innerHTML='<i class="fa-solid fa-pen"></i> Edit Class';document.getElementById('classModal').classList.add('show');}
async function saveClass(){const fd=new FormData();fd.append('action','save_class');fd.append('id',document.getElementById('classFormId').value);fd.append('class_name',document.getElementById('className').value);fd.append('class_name_en',document.getElementById('classNameEn').value);fd.append('class_code',document.getElementById('classCode').value);fd.append('level_order',document.getElementById('classLevel').value);fd.append('section',document.getElementById('classSection').value);fd.append('age_group',document.getElementById('classAge').value);fd.append('description',document.getElementById('classDesc').value);fd.append('is_active','1');try{const d=await postAPI('/admin/api_education.php',fd);if(d.status==='success'){toast('Class saved!');closeModal('classModal');loadClasses();}else toast(d.message,'err');}catch(e){toast('Error','err');}}
async function deleteClass(id){if(!confirm('Delete this class?'))return;const fd=new FormData();fd.append('action','delete_class');fd.append('class_id',id);try{const d=await postAPI('/admin/api_education.php',fd);toast(d.message,d.status==='success'?'ok':'err');if(d.status==='success')loadClasses();}catch(e){toast('Error','err');}}

// ═══ SUBJECTS ═══
function openSubjectModal(){document.getElementById('subjectForm').reset();document.getElementById('subjectFormId').value='';document.getElementById('subjectModalTitle').innerHTML='<i class="fa-solid fa-book"></i> Add Subject';document.querySelectorAll('.subj-class-cb').forEach(cb=>cb.checked=false);document.getElementById('subjectModal').classList.add('show');refreshCounters(['subjectName','subjectNameEn','subjectDesc']);clearFieldErrors(['subjectName','subjectNameEn','subjectDesc']);}
function editSubject(s){document.getElementById('subjectFormId').value=s.id;document.getElementById('subjectName').value=s.subject_name||'';document.getElementById('subjectNameEn').value=s.subject_name_en||'';document.getElementById('subjectDesc').value=s.description||'';document.getElementById('subjectModalTitle').innerHTML='<i class="fa-solid fa-pen"></i> Edit Subject';document.querySelectorAll('.subj-class-cb').forEach(cb=>cb.checked=false);document.getElementById('subjectModal').classList.add('show');refreshCounters(['subjectName','subjectNameEn','subjectDesc']);clearFieldErrors(['subjectName','subjectNameEn','subjectDesc']);if(s.id){getAPI('/admin/api_subjects.php?action=get_subject_classes&subject_id='+s.id).then(d=>{const ids=(d.classes||[]).map(c=>String(c.id));document.querySelectorAll('.subj-class-cb').forEach(cb=>{cb.checked=ids.includes(cb.value);});});}}
document.getElementById('subjectForm')?.addEventListener('submit',function(e){
    e.preventDefault();
    const sid=document.getElementById('subjectFormId').value;
    const fields={'subjectName':150,'subjectNameEn':150,'subjectDesc':2000};
    clearFieldErrors(Object.keys(fields));
    // Client-side validation mirrors the server rules (fast feedback, patch 8).
    let firstBad=null;
    const nameEl=document.getElementById('subjectName');
    if(!nameEl.value.trim()){setFieldError('subjectName','Subject name is required.');firstBad=firstBad||nameEl;}
    for(const[fid,max]of Object.entries(fields)){
        const el=document.getElementById(fid);
        const n=[...el.value].length;
        if(n>max){setFieldError(fid,`Too long: ${n} characters (maximum is ${max}).`);firstBad=firstBad||el;}
    }
    if(firstBad){firstBad.focus();return;}
    const btn=this.querySelector('button[type="submit"]');if(btn)btn.disabled=true;
    const fd=new FormData();fd.append('action',sid?'update_subject':'create_subject');if(sid)fd.append('subject_id',sid);
    fd.append('subject_name',nameEl.value.trim());
    fd.append('subject_name_en',document.getElementById('subjectNameEn').value.trim());
    fd.append('description',document.getElementById('subjectDesc').value.trim());
    postAPI('/admin/api_subjects.php',fd).then(async d=>{
        if(d.status!=='success'){
            // Server field error -> inline; everything else -> toast with ref.
            const f=d.details&&d.details.field;
            if(f&&document.getElementById(f+'Err')!==null){setFieldError(f,d.message);document.getElementById(f).focus();}
            else toast(apiErrorMessage(d),'err');
            return;
        }
        const subjectId=sid||d.subject_id;
        if(subjectId){
            const ids=[];document.querySelectorAll('.subj-class-cb:checked').forEach(cb=>ids.push(parseInt(cb.value,10)));
            const cfd=new FormData();cfd.append('action','assign_subject_to_classes');cfd.append('subject_id',subjectId);cfd.append('class_ids',JSON.stringify(ids));
            const cd=await postAPI('/admin/api_subjects.php',cfd);
            if(cd.status!=='success'){toast(apiErrorMessage(cd),'err');return;}
        }
        toast(d.message);closeModal('subjectModal');location.reload();
    }).catch(err=>{toast(err.message||'Could not reach the server.','err');}).finally(()=>{if(btn)btn.disabled=false;});
});

// ═══ MEMBER TYPE HELPERS ═══
function mtBadge(type) {
    if(type==='special_regular') return '<span class="ch" style="background:#fef3c7;color:#92400e;font-size:.5rem;padding:1px 5px">ልዩ Special</span>';
    if(type==='honorary') return '<span class="ch" style="background:#ede9fe;color:#5b21b6;font-size:.5rem;padding:1px 5px">ክብር Honorary</span>';
    return '<span class="ch" style="background:#ecfdf5;color:#065f46;font-size:.5rem;padding:1px 5px">መደበኛ Regular</span>';
}
function roleTags(m) {
    let t='';
    if(m.is_teacher==1) t+='<span class="ch ch-w" style="font-size:.45rem;padding:0 4px">Teacher</span> ';
    if(m.is_staff==1) t+='<span class="ch" style="background:#dbeafe;color:#1e40af;font-size:.45rem;padding:0 4px">Staff</span> ';
    if(m.is_committee==1) t+='<span class="ch" style="background:#fce7f3;color:#9d174d;font-size:.45rem;padding:0 4px">Committee</span> ';
    if(m.is_volunteer==1) t+='<span class="ch" style="background:#d1fae5;color:#065f46;font-size:.45rem;padding:0 4px">Volunteer</span> ';
    return t;
}

// ═══ ENROLLMENT (ADVANCED) ═══
let _enrollSearchTimer=null, _selectedEnrollMember=null, _unassignedTimer=null, _bulkSelected=new Set(), _bulkModalSelected=new Set();

function switchEnrollTab(tab) {
    ['classes','roster','unassigned','teachers'].forEach(t => {
        const panel=document.getElementById('enrPanel'+(t.charAt(0).toUpperCase()+t.slice(1)));
        const btn=document.getElementById('enrTab'+(t.charAt(0).toUpperCase()+t.slice(1)));
        if(panel) panel.style.display = t===tab?'block':'none';
        if(btn) btn.className = 'tbn'+(t===tab?' act':'');
    });
    if(tab==='roster') loadRoster(1);
    if(tab==='unassigned') loadUnassigned();
    if(tab==='teachers') { loadUnassignedTeachers(); loadClassTeacherGrid(); }
}

// --- School-wide roster ---
let _rosterTimer=null, _rosterPage=1;
function debounceRoster(){ clearTimeout(_rosterTimer); _rosterTimer=setTimeout(()=>loadRoster(1), 350); }
async function loadRoster(page){
    _rosterPage = page || 1;
    const area=document.getElementById('rosterArea');
    if(!area) return;
    area.innerHTML='<div class="crd" style="padding:1.5rem;text-align:center;color:#94a3b8"><i class="fa-solid fa-spinner fa-spin"></i> Loading roster...</div>';
    const q=document.getElementById('rosterQ')?.value||'';
    const cls=document.getElementById('rosterClass')?.value||'';
    const gender=document.getElementById('rosterGender')?.value||'';
    const type=document.getElementById('rosterType')?.value||'';
    const age=document.getElementById('rosterAge')?.value||'';
    const sort=document.getElementById('rosterSort')?.value||'name';
    let url=`/admin/api_education.php?action=roster&page=${_rosterPage}&per_page=25&sort=${encodeURIComponent(sort)}`;
    if(q) url+=`&q=${encodeURIComponent(q)}`;
    if(cls==='unassigned') url+='&unassigned=1';
    else if(cls) url+=`&class_id=${encodeURIComponent(cls)}`;
    if(gender) url+=`&gender=${encodeURIComponent(gender)}`;
    if(type) url+=`&member_type=${encodeURIComponent(type)}`;
    if(age) url+=`&age_group=${encodeURIComponent(age)}`;
    try{
        const d=await getAPI(url);
        if(d.status!=='success'){ area.innerHTML=`<div class="crd" style="padding:1.5rem;color:#ef4444">${esc(d.message||'Error')}</div>`; return; }
        const rows=d.rows||[], total=d.total||0, pages=d.pages||1;
        area.innerHTML=`
        <div class="crd">
            <div style="padding:.75rem 1rem;border-bottom:1px solid #f1f5f9;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:.4rem">
                <span style="font-weight:700">${total} student${total===1?'':'s'}</span>
                <span style="font-size:.7rem;color:#64748b">Page ${_rosterPage} of ${pages}${d.year_name?' • '+esc(d.year_name):''}</span>
            </div>
            ${rows.length?`<div class="tw"><table class="dt"><thead><tr>
                <th><input type="checkbox" onchange="document.querySelectorAll('.roster-cb').forEach(c=>c.checked=this.checked)"></th>
                <th>Student</th><th>Code</th><th>Class</th><th>Type</th><th>Gender</th><th>Age</th>
            </tr></thead><tbody>${rows.map(x=>`<tr>
                <td><input type="checkbox" class="roster-cb" value="${x.id}"></td>
                <td><div style="font-weight:600">${esc(x.student_name||'')} ${x.baptismal_name?'<span style="font-size:.65rem;color:#94a3b8">('+esc(x.baptismal_name)+')</span>':''}</div><div style="font-size:.65rem;color:#64748b">${esc(x.father_name||'')} ${esc(x.grandfather_name||'')}</div></td>
                <td><span class="ch ch-i">${esc(x.member_code||'—')}</span></td>
                <td class="amharic">${x.class_name?esc(x.class_name)+' <span style="font-size:.6rem;color:#94a3b8">'+esc(x.class_code||'')+'</span>':'<span style="color:#f59e0b">Unassigned</span>'}</td>
                <td>${mtBadge(x.member_type)}</td>
                <td>${x.gender==='male'?'♂':'♀'}</td>
                <td>${esc(x.age||x.age_group||'—')}</td>
            </tr>`).join('')}</tbody></table></div>`:'<div style="padding:1.5rem;text-align:center;color:#94a3b8">No matching students</div>'}
            ${pages>1?`<div style="padding:.75rem;display:flex;justify-content:center;gap:.4rem;border-top:1px solid #f1f5f9">
                <button class="btn btn-o btn-xs" ${_rosterPage<=1?'disabled':''} onclick="loadRoster(${_rosterPage-1})">Prev</button>
                <button class="btn btn-o btn-xs" ${_rosterPage>=pages?'disabled':''} onclick="loadRoster(${_rosterPage+1})">Next</button>
            </div>`:''}
        </div>`;
    }catch(e){ area.innerHTML='<div class="crd" style="padding:1.5rem;color:#ef4444">Error loading roster</div>'; }
}
async function rosterBulkEnroll(){
    setFormAlert('rosterFormAlert','');
    const cls=document.getElementById('rosterTargetClass')?.value;
    if(!cls){ setFormAlert('rosterFormAlert','Pick the class these students should join.','err'); toast('Pick a class first.','err'); return; }
    const ids=[]; document.querySelectorAll('.roster-cb:checked').forEach(cb=>ids.push(parseInt(cb.value,10)));
    if(!ids.length){ setFormAlert('rosterFormAlert','Tick at least one student.','err'); toast('Tick at least one student.','err'); return; }
    if(!confirm('Enroll '+ids.length+' student(s) into the selected class?')) return;
    const fd=new FormData(); fd.append('action','bulk_enroll'); fd.append('class_id',cls); fd.append('member_ids',JSON.stringify(ids));
    try{
        const d=await postAPI('/admin/api_education.php',fd);
        if(d.status==='success'||d.status==='partial'){
            toast(d.message||'Students enrolled.', d.status==='partial'?'w':'ok');
            setFormAlert('rosterFormAlert',d.message||'Students enrolled.', d.status==='partial'?'warn':'ok');
            loadRoster(_rosterPage); loadEnrollOverview();
        }else{
            setFormAlert('rosterFormAlert',d.message||'Could not enroll those students.','err');
            toast(d.message||'Could not enroll those students.','err');
        }
    }catch(e){ const msg=friendlyNetError(e); setFormAlert('rosterFormAlert',msg,'err'); toast(msg,'err'); }
}

// --- Enrollment Overview ---
async function loadEnrollOverview() {
    try { const d=await getAPI('/admin/api_education.php?action=enrollment_overview');
    if(d.status==='success') {
        const s=d.summary||{}, tb=s.type_breakdown||{}, eb=s.enrolled_by_type||{};
        document.getElementById('enrollOverviewStats').innerHTML=`
            <div class="sc" style="background:linear-gradient(135deg,#7c3aed,#6366f1);padding:.85rem"><div style="font-size:1.4rem;font-weight:700">${s.total_enrolled||0}<span style="font-size:.65rem;opacity:.7">/${s.total_members||0}</span></div><div style="font-size:.6rem;opacity:.8">Enrolled / Total</div></div>
            <div class="sc" style="background:linear-gradient(135deg,#ef4444,#f97316);padding:.85rem"><div style="font-size:1.4rem;font-weight:700">${s.unassigned_members||0}</div><div style="font-size:.6rem;opacity:.8">Unassigned</div></div>
            <div class="sc" style="background:linear-gradient(135deg,#059669,#10b981);padding:.85rem"><div style="font-size:1.4rem;font-weight:700">${s.assigned_teachers||0}<span style="font-size:.65rem;opacity:.7">/${s.total_teachers||0}</span></div><div style="font-size:.6rem;opacity:.8">Teachers</div></div>
            <div class="sc" style="background:linear-gradient(135deg,#0ea5e9,#3b82f6);padding:.85rem"><div style="font-size:1.4rem;font-weight:700">${s.total_classes||0}</div><div style="font-size:.6rem;opacity:.8">Classes</div></div>
            <div class="sc" style="background:linear-gradient(135deg,#10b981,#34d399);padding:.85rem"><div style="font-size:1.2rem;font-weight:700">${tb.regular||0}<span style="font-size:.6rem;opacity:.7"> (${eb.regular||0} enrolled)</span></div><div style="font-size:.6rem;opacity:.8">መደበኛ Regular</div></div>
            <div class="sc" style="background:linear-gradient(135deg,#f59e0b,#fbbf24);padding:.85rem;color:#78350f"><div style="font-size:1.2rem;font-weight:700">${tb.special_regular||0}<span style="font-size:.6rem;opacity:.7"> (${eb.special_regular||0} enrolled)</span></div><div style="font-size:.6rem;opacity:.8">ልዩ መደበኛ Special</div></div>
            <div class="sc" style="background:linear-gradient(135deg,#8b5cf6,#a78bfa);padding:.85rem"><div style="font-size:1.2rem;font-weight:700">${tb.honorary||0}<span style="font-size:.6rem;opacity:.7"> (${eb.honorary||0} enrolled)</span></div><div style="font-size:.6rem;opacity:.8">ክብር Honorary</div></div>
            <div class="sc" style="background:linear-gradient(135deg,#64748b,#94a3b8);padding:.85rem;cursor:pointer" onclick="runMemberTypeSync()"><div style="font-size:1rem;font-weight:700"><i class="fa-solid fa-sync"></i></div><div style="font-size:.6rem;opacity:.8">Sync Types</div></div>`;
    }} catch(e){ toast(friendlyNetError(e),'err'); }
}

async function runMemberTypeSync() {
    if(!confirm('Sync all member types based on their roles? This will auto-fix any mismatched Regular/Special Regular types across departments.')) return;
    try { const fd=new FormData(); fd.append('action','sync_member_types');
    const d=await postAPI('/admin/api_education.php',fd);
    toast(d.message, 'ok'); loadEnrollOverview();
    } catch(e){ toast('Sync error','err'); }
}

// --- Live Search Enroll ---
function liveSearchEnroll(q) {
    clearTimeout(_enrollSearchTimer);
    const res=document.getElementById('enrollSearchResults');
    if(q.length<2) { res.style.display='none'; return; }
    _enrollSearchTimer=setTimeout(async()=>{
        const cid=document.getElementById('enrollClass').value;
        let url=`/admin/api_education.php?action=search_members&q=${encodeURIComponent(q)}&limit=10`;
        if(cid) url+=`&exclude_class=${cid}`;
        try { const d=await getAPI(url);
        if(d.status==='success' && d.members.length) {
            res.innerHTML=d.members.map(m=>`<div style="padding:.5rem .75rem;cursor:pointer;border-bottom:1px solid #f1f5f9;font-size:.8rem;display:flex;justify-content:space-between;align-items:center" onmouseover="this.style.background='#faf5ff'" onmouseout="this.style.background=''" onclick="selectEnrollMember(${m.id},'${esc(m.student_name)} ${esc(m.father_name)}','${esc(m.member_code||'')}')">
                <div><strong>${esc(m.student_name)}</strong> <span style="color:#64748b">${esc(m.father_name)}</span> ${mtBadge(m.member_type)} ${m.is_teacher?'<span class="ch ch-w" style="font-size:.45rem">Teacher</span>':''}</div>
                <div style="display:flex;gap:.3rem;align-items:center"><span class="ch ch-i" style="font-size:.55rem">${esc(m.member_code||'')}</span><span style="color:${m.gender==='male'?'#2563eb':'#ec4899'};font-size:.7rem">${m.gender==='male'?'♂':'♀'}</span></div>
            </div>`).join('');
            res.style.display='block';
        } else { res.innerHTML='<div style="padding:.75rem;text-align:center;color:#94a3b8;font-size:.8rem">No matching members found</div>'; res.style.display='block'; }
        } catch(e){ res.style.display='none'; }
    }, 300);
}
function selectEnrollMember(id, name, code) {
    _selectedEnrollMember=id;
    document.getElementById('enrollSearchInput').value=`${name} — ${code}`;
    document.getElementById('enrollSearchResults').style.display='none';
    document.getElementById('enrollBtn').disabled=false;
}
async function enrollFromSearch() {
    const cid=document.getElementById('enrollClass').value;
    if(!cid) return toast('Select a class first','err');
    if(!_selectedEnrollMember) return toast('Search and select a student','err');
    const fd=new FormData(); fd.append('action','enroll'); fd.append('class_id',cid); fd.append('member_id',_selectedEnrollMember);
    try { const d=await postAPI('/admin/api_education.php',fd);
    toast(d.message, d.status==='success'?'ok':'err');
    if(d.status==='success') { loadEnrolled(); document.getElementById('enrollSearchInput').value=''; _selectedEnrollMember=null; document.getElementById('enrollBtn').disabled=true; loadEnrollOverview(); }
    } catch(e){ toast('Error','err'); }
}

// --- Load Enrolled Students (with search/filter/sort) ---
async function loadEnrolled() {
    const area=document.getElementById('enrollArea');
    const cid=document.getElementById('enrollClass').value;
    if(!cid) { if(area) area.innerHTML=''; return; }
    if(area) area.innerHTML='<div class="crd" style="padding:1.75rem;text-align:center;color:#64748b"><i class="fa-solid fa-spinner fa-spin" style="color:#7c3aed"></i><div style="margin-top:.55rem;font-size:.8rem">Loading students…</div></div>';
    const search=document.getElementById('enrollFilterSearch')?.value||'';
    const gender=document.getElementById('enrollFilterGender')?.value||'';
    const memberType=document.getElementById('enrollFilterMType')?.value||'';
    const sort=document.getElementById('enrollFilterSort')?.value||'name';
    let url=`/admin/api_education.php?action=get_enrolled_students&class_id=${cid}`;
    if(search) url+=`&search=${encodeURIComponent(search)}`;
    if(gender) url+=`&gender=${gender}`;
    if(memberType) url+=`&member_type=${memberType}`;
    if(sort) url+=`&sort=${sort}`;
    try {
        const d=await getAPI(url);
        if(d.status!=='success'){
            if(area) area.innerHTML=`<div class="crd" style="padding:1.5rem;text-align:center"><div style="color:#dc2626;font-weight:600;margin-bottom:.35rem">Could not load this class</div><div style="color:#64748b;font-size:.8rem;margin-bottom:.85rem">${esc(d.message||'Please try again.')}</div><button class="btn btn-p btn-xs" type="button" onclick="loadEnrolled()"><i class="fa-solid fa-rotate-right"></i> Retry</button></div>`;
            toast(d.message||'Could not load enrolled students.','err');
            return;
        }
        const s=d.students||[], st=d.stats||{};
        const yearNote=d.roster_fallback?`<div style="margin:.65rem 1rem 0;padding:.55rem .75rem;background:#fffbeb;border:1px solid #fde68a;border-radius:10px;font-size:.75rem;color:#92400e"><i class="fa-solid fa-circle-info"></i> Showing students from <b class="amharic">${esc(d.roster_year_name||'a previous year')}</b>. They are not yet enrolled in the current year — use Bulk Enroll if this year should have them too.</div>`:'';
        const empty=s.length?'':`<div style="padding:2rem 1.25rem;text-align:center">
            <i class="fa-solid fa-user-graduate" style="font-size:1.8rem;color:#c4b5fd;display:block;margin-bottom:.55rem"></i>
            <div style="font-weight:600;color:#334155">No students in this class yet</div>
            <div style="font-size:.78rem;color:#64748b;margin-top:.35rem;max-width:360px;margin-left:auto;margin-right:auto">Search a name or code above to add one student, or use <b>Bulk Enroll</b> / <b>All Students</b> to add many at once.</div>
        </div>`;
        if(area) area.innerHTML=`
        <div class="crd">
            <div style="padding:.75rem 1rem;border-bottom:1px solid #f1f5f9;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:.5rem">
                <div style="display:flex;align-items:center;gap:.75rem;flex-wrap:wrap">
                    <span style="font-weight:700;font-size:.95rem">${st.total||0} student${(st.total||0)===1?'':'s'}</span>
                    <span class="ch ch-i" style="font-size:.6rem">♂ ${st.male||0}</span>
                    <span class="ch ch-p" style="font-size:.6rem">♀ ${st.female||0}</span>
                    <span style="font-size:.55rem;color:#64748b;border-left:1px solid #e2e8f0;padding-left:.5rem">Regular: ${st.regular||0} | Special: ${st.special_regular||0}${st.honorary?' | Honorary: '+st.honorary:''}${st.teachers?' | Teachers: '+st.teachers:''}</span>
                </div>
                <div style="display:flex;gap:.4rem;flex-wrap:wrap">
                    <input autocomplete="off" type="text" id="enrollFilterSearch" class="inp" style="max-width:140px;padding:.35rem .6rem;font-size:.75rem" placeholder="Filter..." value="${esc(search)}" oninput="loadEnrolled()">
                    <select id="enrollFilterGender" class="inp" style="max-width:85px;padding:.35rem .5rem;font-size:.75rem" onchange="loadEnrolled()"><option value="">Gender</option><option value="male" ${gender==='male'?'selected':''}>Male</option><option value="female" ${gender==='female'?'selected':''}>Female</option></select>
                    <select id="enrollFilterMType" class="inp" style="max-width:110px;padding:.35rem .5rem;font-size:.75rem" onchange="loadEnrolled()"><option value="">All Types</option><option value="regular" ${memberType==='regular'?'selected':''}>Regular</option><option value="special_regular" ${memberType==='special_regular'?'selected':''}>Special</option><option value="honorary" ${memberType==='honorary'?'selected':''}>Honorary</option></select>
                    <select id="enrollFilterSort" class="inp" style="max-width:100px;padding:.35rem .5rem;font-size:.75rem" onchange="loadEnrolled()"><option value="name" ${sort==='name'?'selected':''}>Name</option><option value="code" ${sort==='code'?'selected':''}>Code</option><option value="date" ${sort==='date'?'selected':''}>Date</option><option value="gender" ${sort==='gender'?'selected':''}>Gender</option></select>
                    <button class="btn btn-o btn-xs" onclick="exportEnrolled()"><i class="fa-solid fa-download"></i></button>
                </div>
            </div>
            ${yearNote}
            ${s.length?`<div class="tw"><table class="dt"><thead><tr><th>#</th><th>Student Name</th><th>Code</th><th>Type</th><th>Gender</th><th>Age</th><th>Enrolled</th><th style="text-align:center">Actions</th></tr></thead><tbody>${s.map((x,i)=>`<tr>
                <td>${i+1}</td>
                <td><div style="font-weight:600">${esc(x.student_name)}${x.baptismal_name?' <span style="font-size:.6rem;color:#94a3b8">('+esc(x.baptismal_name)+')</span>':''}</div><div style="font-size:.65rem;color:#64748b">${esc(x.father_name||'')} ${esc(x.grandfather_name||'')}</div><div style="margin-top:2px">${roleTags(x)}</div></td>
                <td><span class="ch ch-i">${esc(x.member_code||'')}</span></td>
                <td>${mtBadge(x.member_type)}</td>
                <td>${x.gender==='male'?'<span style="color:#2563eb">♂</span>':'<span style="color:#ec4899">♀</span>'}</td>
                <td style="font-size:.75rem">${x.age||'—'}</td>
                <td style="font-size:.7rem;color:#64748b">${fD(x.enrolled_at)}</td>
                <td style="text-align:center;white-space:nowrap">
                    <button class="ab" style="background:#dbeafe;color:#2563eb" onclick="openTransfer(${x.enrollment_id},'${esc(x.student_name)} ${esc(x.father_name)}','${esc(x.member_code||'')}')" title="Transfer"><i class="fa-solid fa-exchange-alt"></i></button>
                    <button class="ab" style="background:#fee2e2;color:#dc2626" onclick="unenroll(${x.enrollment_id})" title="Remove"><i class="fa-solid fa-user-minus"></i></button>
                </td>
            </tr>`).join('')}</tbody></table></div>`:empty}
        </div>`;
    } catch(e){
        const msg=friendlyNetError(e);
        if(area) area.innerHTML=`<div class="crd" style="padding:1.5rem;text-align:center"><div style="color:#dc2626;font-weight:600;margin-bottom:.35rem">Could not load this class</div><div style="color:#64748b;font-size:.8rem;margin-bottom:.85rem">${esc(msg)}</div><button class="btn btn-p btn-xs" type="button" onclick="loadEnrolled()"><i class="fa-solid fa-rotate-right"></i> Retry</button></div>`;
        toast(msg,'err');
    }
}

async function enrollStudent(){const cid=document.getElementById('enrollClass').value,mid=document.getElementById('enrollMember')?.value;if(!cid||!mid)return toast('Select class and student','err');const fd=new FormData();fd.append('action','enroll');fd.append('class_id',cid);fd.append('member_id',mid);try{const d=await postAPI('/admin/api_education.php',fd);toast(d.message,d.status==='success'?'ok':'err');if(d.status==='success'){loadEnrolled();loadEnrollOverview();}}catch(e){toast('Error','err');}}
async function unenroll(eid){if(!confirm('Remove student from class?'))return;const fd=new FormData();fd.append('action','unenroll_student');fd.append('enrollment_id',eid);try{const d=await postAPI('/admin/api_education.php',fd);toast(d.message,d.status==='success'?'ok':'err');if(d.status==='success'){loadEnrolled();loadEnrollOverview();}}catch(e){toast('Error','err');}}

// --- Transfer ---
function openTransfer(enrollId, name, code) {
    document.getElementById('transferEnrollmentId').value=enrollId;
    document.getElementById('transferStudentInfo').innerHTML=`<strong>${name}</strong> <span class="ch ch-i">${code}</span>`;
    document.getElementById('transferReason').value='';
    document.getElementById('transferModal').classList.add('show');
}
async function executeTransfer() {
    const fd=new FormData();
    fd.append('action','transfer_student');
    fd.append('enrollment_id',document.getElementById('transferEnrollmentId').value);
    fd.append('to_class_id',document.getElementById('transferToClass').value);
    fd.append('reason',document.getElementById('transferReason').value);
    try { const d=await postAPI('/admin/api_education.php',fd);
    toast(d.message, d.status==='success'?'ok':'err');
    if(d.status==='success') { closeModal('transferModal'); loadEnrolled(); loadEnrollOverview(); }
    } catch(e){ toast('Error','err'); }
}

// --- Unassigned Members ---
function debounceUnassigned() { clearTimeout(_unassignedTimer); _unassignedTimer=setTimeout(loadUnassigned, 350); }
let _unassignedPage=1, _unassignedLimit=50;
async function loadUnassigned(page=1) {
    const area=document.getElementById('unassignedArea');
    if(!area) return;
    _unassignedPage=Math.max(1,page|0);
    area.innerHTML='<div class="crd" style="padding:1.5rem;text-align:center;color:#94a3b8"><i class="fa-solid fa-spinner fa-spin"></i> Loading unassigned members...</div>';
    const search=document.getElementById('unassignedSearch').value;
    const gender=document.getElementById('unassignedGender').value;
    const memberType=document.getElementById('unassignedMType').value;
    const age=document.getElementById('unassignedAge').value;
    const offset=(_unassignedPage-1)*_unassignedLimit;
    let url=`/admin/api_education.php?action=get_unassigned_members&offset=${offset}&limit=${_unassignedLimit}`;
    if(search) url+=`&search=${encodeURIComponent(search)}`;
    if(gender) url+=`&gender=${gender}`;
    if(memberType) url+=`&member_type=${memberType}`;
    if(age) url+=`&age_group=${age}`;
    try { const d=await getAPI(url);
    if(d.status==='success') {
        const m=d.members||[], total=d.total||0, pages=Math.max(1,Math.ceil(total/_unassignedLimit));
        if(_unassignedPage>pages) _unassignedPage=pages;
        const from=total?offset+1:0, to=Math.min(offset+_unassignedLimit,total);
        const allOnPage=m.length>0&&m.every(x=>_bulkSelected.has(parseInt(x.id,10)));
        const selBar=_bulkSelected.size?`<div style="padding:.5rem 1rem;background:#f5f3ff;border-bottom:1px solid #ddd6fe;display:flex;justify-content:space-between;align-items:center;font-size:.75rem">
            <span><i class="fa-solid fa-check-double" style="color:#6d28d9"></i> <strong>${_bulkSelected.size}</strong> selected${_bulkSelected.size>m.length?' (across pages)':''}</span>
            <button class="btn btn-o btn-xs" onclick="clearBulkSelection()">Clear selection</button></div>`:'';
        area.innerHTML=`
        <div class="crd">
            <div style="padding:.75rem 1rem;border-bottom:1px solid #f1f5f9;display:flex;justify-content:space-between;align-items:center">
                <span style="font-weight:700;font-size:.9rem"><i class="fa-solid fa-user-xmark" style="color:#ef4444"></i> ${total} unassigned member${total!==1?'s':''}</span>
                <span style="font-size:.7rem;color:#64748b">Not enrolled in any class this year</span>
            </div>
            ${selBar}
            ${m.length?`<div class="tw"><table class="dt"><thead><tr><th><input type="checkbox" ${allOnPage?'checked':''} onchange="toggleBulkPage(this.checked)"></th><th>Name</th><th>Code</th><th>Type</th><th>Gender</th><th>Age Group</th><th>Phone</th></tr></thead><tbody>${m.map(x=>`<tr>
                <td><input type="checkbox" class="unassigned-cb" value="${x.id}" ${_bulkSelected.has(parseInt(x.id,10))?'checked':''} onchange="unassignedToggle(this)"></td>
                <td><div style="font-weight:600">${esc(x.student_name)} <span style="color:#64748b;font-weight:400">${esc(x.father_name)}</span></div><div style="margin-top:1px">${roleTags(x)}</div></td>
                <td><span class="ch ch-i">${esc(x.member_code||'—')}</span></td>
                <td>${mtBadge(x.member_type)}</td>
                <td>${x.gender==='male'?'<span style="color:#2563eb">♂ Male</span>':'<span style="color:#ec4899">♀ Female</span>'}</td>
                <td style="font-size:.75rem">${esc((x.age_group||'').replace(/_/g,' '))}</td>
                <td style="font-size:.75rem">${esc(x.phone_number||x.phone_primary||'—')}</td>
            </tr>`).join('')}</tbody></table></div>`:'<div style="padding:2rem;text-align:center;color:#94a3b8"><i class="fa-solid fa-check-circle" style="font-size:2rem;color:#059669;display:block;margin-bottom:.5rem"></i>All members are enrolled!</div>'}
            ${unassignedFooter(from,to,total,pages)}
        </div>`;
        updateBulkCount();
    }} catch(e){ area.innerHTML=`<div class="crd" style="padding:1.5rem;text-align:center;color:#ef4444">Error loading: ${e.message||'Unknown'}</div>`; }
}
function unassignedFooter(from,to,total,pages){
    if(total<=0) return '';
    const sizeSel=`<select class="inp" style="width:auto;padding:.15rem .4rem;font-size:.7rem" onchange="_unassignedLimit=parseInt(this.value,10)||50;loadUnassigned(1)">
        ${[25,50,100].map(n=>`<option value="${n}" ${n===_unassignedLimit?'selected':''}>${n} / page</option>`).join('')}</select>`;
    return `<div style="padding:.6rem .75rem;border-top:1px solid #f1f5f9;display:flex;justify-content:space-between;align-items:center;gap:.75rem;flex-wrap:wrap">
        <span style="font-size:.7rem;color:#64748b">Showing <strong>${from}–${to}</strong> of ${total}</span>${sizeSel}</div>
        ${pagerButtons(_unassignedPage,pages,'loadUnassigned')}`;
}
/** Compact numbered pager shared by paginated lists (H5). */
function pagerButtons(page,pages,fnName){
    if(pages<=1) return '';
    const start=Math.max(1,page-2), end=Math.min(pages,start+4), win=[];
    for(let i=start;i<=end;i++) win.push(i);
    const b=(label,target,dis,active)=>`<button class="btn ${active?'btn-s':'btn-o'} btn-xs" ${dis?'disabled':''} onclick="${fnName}(${target})">${label}</button>`;
    let out='<div style="padding:.6rem;display:flex;justify-content:center;align-items:center;gap:.3rem;flex-wrap:wrap;border-top:1px solid #f1f5f9">';
    out+=b('‹ Prev',page-1,page<=1,false);
    if(start>1){out+=b('1',1,false,false); if(start>2)out+='<span style="font-size:.7rem;color:#94a3b8">…</span>';}
    win.forEach(i=>out+=b(i,i,false,i===page));
    if(end<pages){if(end<pages-1)out+='<span style="font-size:.7rem;color:#94a3b8">…</span>';out+=b(pages,pages,false,false);}
    out+=b('Next ›',page+1,page>=pages,false)+'</div>';
    return out;
}
function unassignedToggle(cb){ const id=parseInt(cb.value,10); if(cb.checked)_bulkSelected.add(id); else _bulkSelected.delete(id); updateBulkCount(); }
function toggleBulkPage(checked) { document.querySelectorAll('.unassigned-cb').forEach(cb=>{const id=parseInt(cb.value,10); if(checked)_bulkSelected.add(id); else _bulkSelected.delete(id); cb.checked=checked;}); updateBulkCount(); }
function clearBulkSelection(){ _bulkSelected.clear(); document.querySelectorAll('.unassigned-cb').forEach(cb=>cb.checked=false); updateBulkCount(); }
function updateBulkCount() { const cnt=_bulkSelected.size; const el=document.querySelector('#enrPanelUnassigned .btn-s'); if(el) el.innerHTML=`<i class="fa-solid fa-users"></i> Enroll Selected (${cnt})`; }
async function bulkEnrollSelected() {
    setFormAlert('unassignedFormAlert','');
    const cls=document.getElementById('unassignedTargetClass').value;
    if(!cls){ setFormAlert('unassignedFormAlert','Pick the class these members should join.','err'); toast('Pick a class first.','err'); return; }
    const ids=[..._bulkSelected];
    if(!ids.length){ setFormAlert('unassignedFormAlert','Tick at least one member.','err'); toast('Tick at least one member.','err'); return; }
    if(!confirm(`Enroll ${ids.length} student(s) into the selected class?`)) return;
    const fd=new FormData(); fd.append('action','bulk_enroll'); fd.append('class_id',cls); fd.append('member_ids',JSON.stringify(ids));
    try {
        const d=await postAPI('/admin/api_education.php',fd);
        if(d.status==='success'||d.status==='partial'){
            toast(d.message||'Students enrolled.', d.status==='partial'?'w':'ok');
            _bulkSelected.clear(); loadUnassigned(1); loadEnrollOverview();
        }else{
            setFormAlert('unassignedFormAlert',d.message||'Could not enroll those members.','err');
            toast(d.message||'Could not enroll those members.','err');
        }
    } catch(e){ const msg=friendlyNetError(e); setFormAlert('unassignedFormAlert',msg,'err'); toast(msg,'err'); }
}

// --- Bulk Enroll Modal ---
let _bulkSearchTimer=null;
function debounceBulkSearch(){ clearTimeout(_bulkSearchTimer); _bulkSearchTimer=setTimeout(loadBulkCandidates, 300); }
function openBulkEnrollModal() {
    setFormAlert('bulkFormAlert','');
    const btn=document.getElementById('bulkEnrollBtn');
    if(btn){btn.disabled=false;btn.innerHTML='<i class="fa-solid fa-check-double"></i> Enroll selected';}
    _bulkModalSelected.clear();
    const sa=document.getElementById('bulkSelectAll'); if(sa) sa.checked=false;
    document.getElementById('bulkEnrollModal').classList.add('show');
    loadBulkCandidates(1);
}
let _bulkModalPage=1;
async function loadBulkCandidates(page=1) {
    const list=document.getElementById('bulkCandidateList');
    if(!list) return;
    _bulkModalPage=Math.max(1,page|0);
    list.innerHTML='<div style="padding:1rem;text-align:center;color:#94a3b8"><i class="fa-solid fa-spinner fa-spin"></i> Loading unassigned members…</div>';
    const search=document.getElementById('bulkSearch')?.value||'';
    const filter=document.getElementById('bulkFilter')?.value||'';
    const limit=50, offset=(_bulkModalPage-1)*limit;
    let url=`/admin/api_education.php?action=get_unassigned_members&limit=${limit}&offset=${offset}`;
    if(search.trim()) url+=`&search=${encodeURIComponent(search.trim())}`;
    if(['male','female'].includes(filter)) url+=`&gender=${filter}`;
    if(['7_13','14_17','18_plus'].includes(filter)) url+=`&age_group=${filter}`;
    try {
        const d=await getAPI(url);
        if(d.status!=='success'){
            list.innerHTML=`<div style="padding:1rem;text-align:center;color:#dc2626">${esc(d.message||'Could not load members.')}</div>`;
            return;
        }
        const m=d.members||[];
        const total=d.total||m.length, pages=Math.max(1,Math.ceil(total/limit));
        if(_bulkModalPage>pages) _bulkModalPage=pages;
        const from=total?offset+1:0, to=Math.min(offset+limit,total);
        const allOn=m.length>0&&m.every(x=>_bulkModalSelected.has(parseInt(x.id,10)));
        list.innerHTML=(m.length?m.map(x=>`<label style="display:flex;align-items:center;gap:.6rem;padding:.4rem .5rem;border-bottom:1px solid #f8fafc;cursor:pointer;font-size:.8rem" onmouseover="this.style.background='#faf5ff'" onmouseout="this.style.background=''">
            <input type="checkbox" class="bulk-cb" value="${x.id}" ${_bulkModalSelected.has(parseInt(x.id,10))?'checked':''} onchange="bulkModalToggle(this)">
            <div style="flex:1"><strong>${esc(x.student_name)}</strong> ${esc(x.father_name||'')} <span class="ch ch-i" style="font-size:.5rem">${esc(x.member_code||'')}</span> ${mtBadge(x.member_type)} ${roleTags(x)}</div>
            <span style="color:${x.gender==='male'?'#2563eb':'#ec4899'};font-size:.7rem">${x.gender==='male'?'♂':'♀'}</span>
        </label>`).join(''):'<div style="padding:1rem;text-align:center;color:#94a3b8">No unassigned members match this search.</div>')
        +(total>0?`<div style="padding:.45rem .5rem;font-size:.68rem;color:#94a3b8">Showing ${from}–${to} of ${total} unassigned member(s).</div>`:'')
        +pagerButtons(_bulkModalPage,pages,'loadBulkCandidates');
        const sa=document.getElementById('bulkSelectAll'); if(sa) sa.checked=allOn;
        updateBulkModalCount();
    } catch(e){
        list.innerHTML=`<div style="padding:1rem;text-align:center;color:#dc2626">${esc(friendlyNetError(e))}</div>`;
    }
}
function bulkModalToggle(cb){ const id=parseInt(cb.value,10); if(cb.checked)_bulkModalSelected.add(id); else _bulkModalSelected.delete(id); updateBulkModalCount(); }
function toggleBulkAll() { const c=document.getElementById('bulkSelectAll').checked; document.querySelectorAll('.bulk-cb').forEach(cb=>{const id=parseInt(cb.value,10); if(c)_bulkModalSelected.add(id); else _bulkModalSelected.delete(id); cb.checked=c;}); updateBulkModalCount(); }
function updateBulkModalCount() { const cnt=_bulkModalSelected.size; const el=document.getElementById('bulkCount'); if(el) el.textContent=cnt+' selected'; }
async function executeBulkEnroll() {
    setFormAlert('bulkFormAlert','');
    markField('bulkClass',false);
    const cls=document.getElementById('bulkClass').value;
    const ids=[..._bulkModalSelected];
    if(!cls){ setFormAlert('bulkFormAlert','Pick the class these students should join.','err'); markField('bulkClass',true); toast('Pick a class first.','err'); return; }
    if(!ids.length){ setFormAlert('bulkFormAlert','Tick at least one member.','err'); toast('Tick at least one member.','err'); return; }
    const btn=document.getElementById('bulkEnrollBtn');
    if(btn){btn.disabled=true;btn.innerHTML='<i class="fa-solid fa-spinner fa-spin"></i> Enrolling…';}
    const fd=new FormData(); fd.append('action','bulk_enroll'); fd.append('class_id',cls); fd.append('member_ids',JSON.stringify(ids));
    try {
        const d=await postAPI('/admin/api_education.php',fd);
        if(d.status==='success'||d.status==='partial'){
            toast(d.message||'Students enrolled.', d.status==='partial'?'w':'ok');
            _bulkModalSelected.clear();
            if(d.status==='success') closeModal('bulkEnrollModal');
            else { setFormAlert('bulkFormAlert',d.message,'warn'); loadBulkCandidates(1); }
            loadEnrollOverview();
            if(document.getElementById('enrPanelUnassigned')?.style.display==='block') loadUnassigned(1);
        }else{
            setFormAlert('bulkFormAlert',d.message||'Could not enroll those members.','err'); toast(d.message||'Could not enroll those members.','err');
            if(d.error_ref) console.warn('Ref', d.error_ref);
        }
    } catch(e){ const msg=friendlyNetError(e); setFormAlert('bulkFormAlert',msg,'err'); toast(msg,'err'); }
    if(btn){btn.disabled=false;btn.innerHTML='<i class="fa-solid fa-check-double"></i> Enroll selected';}
}

// --- Unassigned Teachers & Class-Teacher Grid ---
async function loadUnassignedTeachers() {
    const sel=document.getElementById('taTeacher');
    sel.innerHTML='<option value="">— Select Teacher —</option>';
    try { const d=await getAPI('/admin/api_education.php?action=get_unassigned_teachers');
    if(d.status==='success') {
        const t=d.teachers||[];
        t.forEach(x=>{ sel.innerHTML+=`<option value="${x.id}">${esc(x.full_name)}${x.member_code?' — '+esc(x.member_code):''}</option>`; });
        document.getElementById('unassignedTeachersArea').innerHTML=t.length?`<div class="crd" style="padding:.75rem 1rem;border-left:4px solid #f59e0b;background:#fffbeb"><div style="font-size:.8rem;font-weight:600;color:#92400e"><i class="fa-solid fa-exclamation-triangle"></i> ${t.length} teacher(s) not assigned to any class</div><div style="display:flex;flex-wrap:wrap;gap:.3rem;margin-top:.4rem">${t.map(x=>`<span class="at">${esc(x.full_name)}</span>`).join('')}</div></div>`:'<div class="crd" style="padding:.75rem 1rem;border-left:4px solid #059669;background:#f0fdf4"><div style="font-size:.8rem;font-weight:600;color:#065f46"><i class="fa-solid fa-check-circle"></i> All teachers are assigned</div></div>';
    }} catch(e){}
}
async function loadClassTeacherGrid() {
    try { const d=await getAPI('/admin/api_education.php?action=enrollment_overview');
    if(d.status==='success') {
        const cls=d.classes||[];
        document.getElementById('classTeacherOverview').innerHTML=`<div class="crd"><div style="padding:.75rem 1rem;border-bottom:1px solid #f1f5f9;font-weight:600;font-size:.9rem"><i class="fa-solid fa-th-large" style="color:#7c3aed"></i> Class Overview</div>
        <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(240px,1fr));gap:.75rem;padding:1rem">${cls.map(c=>`
            <div style="border:1px solid #e2e8f0;border-radius:12px;padding:.85rem;${c.enrolled_count>0?'':'border-left:3px solid #f59e0b'}">
                <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:.4rem">
                    <span class="amharic" style="font-weight:700;font-size:.85rem">${esc(c.class_name)}</span>
                    <span class="ch ${c.enrolled_count>0?'ch-ok':'ch-w'}" style="font-size:.55rem">${c.enrolled_count} students</span>
                </div>
                <div style="display:flex;gap:.5rem;font-size:.65rem;color:#64748b;margin-bottom:.4rem">
                    <span>♂ ${c.male_count}</span><span>♀ ${c.female_count}</span><span>|</span><span>${c.teacher_count} teacher(s)</span>
                </div>
                ${c.class_teacher_name?`<div style="font-size:.7rem;color:#059669"><i class="fa-solid fa-user-tie" style="width:12px"></i> ${esc(c.class_teacher_name)}</div>`:'<div style="font-size:.7rem;color:#f59e0b"><i class="fa-solid fa-exclamation-circle" style="width:12px"></i> No class teacher</div>'}
            </div>`).join('')}</div></div>`;
    }} catch(e){}
}
async function assignTeacherFromPanel() {
    const tid=document.getElementById('taTeacher').value;
    const cid=document.getElementById('taClass').value;
    const sid=document.getElementById('taSubject').value;
    const isCT=document.getElementById('taClassTeacher').checked;
    if(!tid||!cid) return toast('Select teacher and class','err');
    const fd=new FormData(); fd.append('action','assign_teacher'); fd.append('teacher_id',tid); fd.append('class_id',cid);
    if(sid) fd.append('subject_id',sid);
    if(isCT) fd.append('is_class_teacher','1');
    try { const d=await postAPI('/admin/api_education.php',fd);
    toast(d.message, d.status==='success'?'ok':'err');
    if(d.status==='success') { loadUnassignedTeachers(); loadClassTeacherGrid(); loadEnrollOverview(); }
    } catch(e){ toast('Error','err'); }
}

function exportEnrolled(){const rows=[];document.querySelectorAll('#enrollArea .dt tbody tr').forEach(tr=>{rows.push([...tr.querySelectorAll('td')].map(td=>td.textContent.trim()));});if(!rows.length)return;const ws=XLSX.utils.aoa_to_sheet([['#','Name','Code','Father','Gender','Age','Enrolled',''],...rows]);const wb=XLSX.utils.book_new();XLSX.utils.book_append_sheet(wb,ws,'Enrolled');XLSX.writeFile(wb,'<?= MEMBER_CODE_PREFIX ?>_Enrollment.xlsx');}

// ═══ GRADES ═══
async function loadGradeSubjects(){const cid=document.getElementById('gradeClass').value;const sel=document.getElementById('gradeSubject');const hint=document.getElementById('gradeSubjHint');sel.innerHTML='<option value="">— Select —</option>';document.getElementById('gradeAssessment').innerHTML='<option value="">— Select —</option>';document.getElementById('gradeArea').innerHTML='';if(hint)hint.style.display='none';if(!cid)return;try{const d=await getAPI(`/admin/api_subjects.php?action=get_class_subjects&class_id=${cid}`);if(d.status==='success'){(d.subjects||[]).forEach(s=>{sel.innerHTML+=`<option value="${s.id}">${esc(s.subject_name)}</option>`;});subjHint(hint,d);}}catch(e){}}
async function loadGradeAssessments(){const cid=document.getElementById('gradeClass').value,sid=document.getElementById('gradeSubject').value;const sel=document.getElementById('gradeAssessment');sel.innerHTML='<option value="">— Select —</option>';document.getElementById('gradeArea').innerHTML='';if(!cid||!sid)return;try{const d=await getAPI(`/admin/api_subjects.php?action=get_assessments&class_id=${cid}&subject_id=${sid}`);if(d.status==='success')(d.assessments||[]).forEach(a=>{sel.innerHTML+=`<option value="${a.id}" data-max="${a.max_score}">${esc(a.assessment_name)} (max: ${a.max_score})</option>`;});}catch(e){}}
async function loadGradeStudents(){const cid=document.getElementById('gradeClass').value,sid=document.getElementById('gradeSubject').value,aid=document.getElementById('gradeAssessment').value;if(!cid||!sid||!aid)return;try{const d=await getAPI(`/admin/api_subjects.php?action=get_students_for_grading&class_id=${cid}&subject_id=${sid}&assessment_id=${aid}`);if(d.status==='success'){const st=d.students||[],mx=document.getElementById('gradeAssessment').selectedOptions[0]?.dataset?.max||100;document.getElementById('gradeArea').innerHTML=st.length?`<div class="crd"><div style="padding:.75rem 1rem;border-bottom:1px solid #f1f5f9;font-weight:600;font-size:.9rem">Grade Entry — Max: ${mx}</div><div class="tw"><table class="dt"><thead><tr><th>#</th><th>Student</th><th>Code</th><th>Score</th><th>Remark</th></tr></thead><tbody>${st.map((s,i)=>`<tr><td>${i+1}</td><td style="font-weight:600">${esc(s.student_name)}</td><td>${esc(s.member_code||'')}</td><td><input type="number" class="inp grade-input" data-mid="${s.member_id||s.id}" data-rid="${s.record_id||''}" style="width:80px" min="0" max="${mx}" value="${s.score==null?'':s.score}"></td><td><input type="text" class="inp grade-remark" data-mid="${s.member_id||s.id}" style="width:120px" value="${esc(s.remark||'')}"></td></tr>`).join('')}</tbody></table></div><div style="padding:1rem;text-align:right"><button class="btn btn-p" onclick="saveAllGrades(${aid})"><i class="fa-solid fa-save"></i> Save All Grades</button></div></div>`:'<div class="crd" style="padding:1.5rem;text-align:center;color:#94a3b8">No students found</div>';}}catch(e){toast('Error','err');}}
async function saveAllGrades(aid){const grades=[];document.querySelectorAll('.grade-input').forEach(inp=>{const mid=inp.dataset.mid,score=inp.value,remark=document.querySelector(`.grade-remark[data-mid="${mid}"]`)?.value||'';if(score!=='')grades.push({member_id:mid,record_id:inp.dataset.rid||null,score:parseFloat(score),remark});});if(!grades.length)return toast('No grades entered','err');const fd=new FormData();fd.append('action','save_grades');fd.append('assessment_id',aid);fd.append('grades',JSON.stringify(grades));try{const d=await postAPI('/admin/api_subjects.php',fd);toast(d.message,d.status==='success'?'ok':'err');}catch(e){toast('Error','err');}}

// ═══ ASSESSMENTS ═══
async function loadAsmtSubjects(){const cid=document.getElementById('asmtClass').value;const sel=document.getElementById('asmtSubject');const hint=document.getElementById('asmtSubjHint');sel.innerHTML='<option value="">— Select —</option>';document.getElementById('assessmentList').innerHTML='';if(hint)hint.style.display='none';if(!cid)return;try{const d=await getAPI(`/admin/api_subjects.php?action=get_class_subjects&class_id=${cid}`);if(d.status==='success'){(d.subjects||[]).forEach(s=>{sel.innerHTML+=`<option value="${s.id}">${esc(s.subject_name)}</option>`;});subjHint(hint,d);}}catch(e){}}
function subjHint(el,d){if(!el)return;if(d.message){el.textContent=d.message;el.style.display='block';}}
async function loadAssessments(){const cid=document.getElementById('asmtClass').value,sid=document.getElementById('asmtSubject').value;if(!cid||!sid){document.getElementById('assessmentList').innerHTML='';return;}
try{const d=await getAPI(`/admin/api_subjects.php?action=get_assessments&class_id=${cid}&subject_id=${sid}`);if(d.status==='success'){const a=d.assessments||[];document.getElementById('assessmentList').innerHTML=a.length?`<div class="crd"><div class="tw"><table class="dt"><thead><tr><th>Name</th><th>Max Score</th><th>Weight</th><th>Actions</th></tr></thead><tbody>${a.map(x=>`<tr><td style="font-weight:600">${esc(x.assessment_name)}</td><td>${x.max_score}</td><td>${x.weight||100}%</td><td><button class="ab" style="background:#fee2e2;color:#dc2626" onclick="deleteAssessment(${x.id})"><i class="fa-solid fa-trash"></i></button></td></tr>`).join('')}</tbody></table></div></div>`:'<div class="crd" style="padding:1.5rem;text-align:center;color:#94a3b8">No assessments created yet</div>';}}catch(e){}}
function openAssessmentModal(){document.getElementById('assessmentModal').classList.add('show');}
async function saveAssessment(){const fd=new FormData();fd.append('action','create_assessment');fd.append('assessment_name',document.getElementById('asmtName').value);fd.append('max_score',document.getElementById('asmtMax').value);fd.append('weight',document.getElementById('asmtWeight').value);fd.append('class_id',document.getElementById('asmtModalClass').value);fd.append('subject_id',document.getElementById('asmtModalSubject').value);try{const d=await postAPI('/admin/api_subjects.php',fd);if(d.status==='success'){toast('Assessment created!');closeModal('assessmentModal');document.getElementById('asmtClass').value=document.getElementById('asmtModalClass').value;document.getElementById('asmtSubject').value=document.getElementById('asmtModalSubject').value;loadAssessments();}else toast(d.message,'err');}catch(e){toast('Error','err');}}
async function deleteAssessment(id){if(!confirm('Delete assessment?'))return;const fd=new FormData();fd.append('action','delete_assessment');fd.append('assessment_id',id);try{const d=await postAPI('/admin/api_subjects.php',fd);toast(d.message,d.status==='success'?'ok':'err');loadAssessments();}catch(e){toast('Error','err');}}

// ═══ ACADEMIC YEARS + SEMESTERS ═══
function escAttr(s){return String(s||'').replace(/&/g,'&amp;').replace(/'/g,'&#39;').replace(/"/g,'&quot;').replace(/</g,'&lt;').replace(/>/g,'&gt;');}
async function loadYears(){try{const d=await getAPI('/admin/api_education.php?action=get_academic_years');if(d.status==='success'){const y=d.years||[];window._yearData={};y.forEach(x=>window._yearData[x.id]=x);document.getElementById('yearBody').innerHTML=y.length?y.map(x=>`<tr><td style="font-weight:600" class="amharic">${esc(x.year_name)}</td><td>${x.ec_year||'—'}</td><td style="font-size:.75rem">${esc(x.year_gc||'—')}</td><td style="font-size:.75rem">${fD(x.start_date)}</td><td style="font-size:.75rem">${fD(x.end_date)}</td><td><span class="ch ch-i">${x.term_count||0} semesters</span></td><td>${x.is_current==1?'<span class="ch ch-ok">Current</span>':'<span class="ch ch-w">No</span>'}</td><td style="white-space:nowrap"><button class="ab" style="background:#ede9fe;color:#7c3aed" onclick="viewYearTermsById(${x.id})" title="View Semesters"><i class="fa-solid fa-calendar-week"></i></button></td></tr>`).join(''):'<tr><td colspan="8" style="text-align:center;padding:1.5rem;color:#94a3b8">No academic years. Create one to get started.</td></tr>';}}catch(e){toast('Error loading years','err');}}
function viewYearTermsById(id){const y=window._yearData?.[id];if(y)viewYearTerms(id,y.year_name||'');}
function editYearById(id){const y=window._yearData?.[id];if(y)editYear(y);}
function openYearModal(){
    const currentEc=<?= (int)ethio_date_format($now, 'Y') ?>;
    let ecYear=currentEc, gcYear=new Date().getFullYear();
    /* Suggest a year name that does NOT already exist. If the current
       Ethiopian year (or a later one) is already saved, suggest the NEXT
       year — otherwise the prefill always collided with the UNIQUE
       year_name constraint and every save after the first one failed. */
    try{
        let maxEc=0;Object.values(window._yearData||{}).forEach(y=>{const e=parseInt(y.ec_year,10);if(!isNaN(e)&&e>maxEc)maxEc=e;});
        if(maxEc>=currentEc){const bump=(maxEc+1)-currentEc;ecYear=maxEc+1;gcYear=gcYear+bump;}
    }catch(e){}
    document.getElementById('yearFormId').value=0;
    document.getElementById('yearName').value=ecYear+' ዓ.ም.';
    document.getElementById('yearEc').value=ecYear;
    document.getElementById('yearGc').value=gcYear+'/'+(gcYear+1);
    document.getElementById('yearStart').value='';
    document.getElementById('yearEnd').value='';
    document.getElementById('yearCurrent').checked=true;
    document.getElementById('yearModalTitle').innerHTML='<i class="fa-solid fa-calendar"></i> New Academic Year';
    document.getElementById('yearModal').classList.add('show');
    if(typeof WBWSCalendar!=='undefined')setTimeout(()=>WBWSCalendar.refreshPickers(),50);
}
function editYear(y){
    document.getElementById('yearFormId').value=y.id;
    document.getElementById('yearName').value=y.year_name||'';
    document.getElementById('yearEc').value=y.ec_year||'';
    document.getElementById('yearGc').value=y.year_gc||'';
    document.getElementById('yearStart').value=y.start_date||'';
    document.getElementById('yearEnd').value=y.end_date||'';
    document.getElementById('yearCurrent').checked=y.is_current==1;
    document.getElementById('yearModalTitle').innerHTML='<i class="fa-solid fa-pen"></i> Edit Academic Year';
    document.getElementById('yearModal').classList.add('show');
    if(typeof WBWSCalendar!=='undefined')setTimeout(()=>WBWSCalendar.refreshPickers(),50);
}
async function saveYear(){
    const fd=new FormData();fd.append('action','save_academic_year');
    fd.append('id',document.getElementById('yearFormId').value);
    fd.append('year_name',document.getElementById('yearName').value);
    fd.append('ec_year',document.getElementById('yearEc').value);
    fd.append('year_gc',document.getElementById('yearGc').value);
    fd.append('start_date',document.getElementById('yearStart').value);
    fd.append('end_date',document.getElementById('yearEnd').value);
    fd.append('is_current',document.getElementById('yearCurrent').checked?1:0);
    try{const d=await postAPI('/admin/api_education.php',fd);
    if(d.status==='success'){toast('Academic year saved!');closeModal('yearModal');loadYears();}
    else toast(d.message,'err');}catch(e){toast('Error saving','err');}
}
async function setCurrent(id){if(!confirm('Set this as the current academic year?'))return;const fd=new FormData();fd.append('action','set_current_year');fd.append('year_id',id);try{const d=await postAPI('/admin/api_education.php',fd);toast(d.message,d.status==='success'?'ok':'err');if(d.status==='success')loadYears();}catch(e){toast('Error','err');}}
async function viewYearTerms(yearId,yearName){
    document.getElementById('termArea').innerHTML='<div class="crd" style="padding:1.5rem;text-align:center;color:#94a3b8"><i class="fa-solid fa-spinner fa-spin"></i> Loading semesters...</div>';
    try{const d=await getAPI(`/admin/api_education.php?action=get_terms&year_id=${yearId}`);
    if(d.status==='success'){const terms=d.terms||[];
    window._termData={};terms.forEach(t=>window._termData[t.id]=t);
    document.getElementById('termArea').innerHTML=`<div class="crd" style="padding:1rem"><div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:.75rem;flex-wrap:wrap;gap:.5rem"><h3 style="font-size:.9rem;font-weight:600;color:#1e293b"><i class="fa-solid fa-calendar-week" style="color:#7c3aed"></i> Semesters — <span class="amharic">${esc(yearName)}</span></h3><span style="font-size:.62rem;color:#94a3b8"><i class="fa-solid fa-lock"></i> View only — managed by School Admin</span></div>
    ${terms.length?`<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:.75rem">${terms.map(t=>`<div class="crd" style="padding:1rem;border-left:4px solid ${t.is_current==1?'#7c3aed':'#e2e8f0'}">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:.5rem">
    <div><span class="amharic" style="font-weight:700;font-size:.9rem;color:#1e293b">${esc(t.term_name)}</span><br><span style="font-size:.65rem;color:#94a3b8">Semester ${t.term_number}</span></div>
    ${t.is_current==1?'<span class="ch ch-ok">Current</span>':'<span class="ch ch-w">Not current</span>'}
    </div>
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:.4rem;margin-bottom:.5rem">
    <div style="background:#f8fafc;padding:.4rem .6rem;border-radius:6px"><div style="font-size:.55rem;color:#94a3b8;text-transform:uppercase">Start Date</div><div style="font-size:.75rem;font-weight:600;color:#1e293b">${t.start_date?fD(t.start_date):'<span style=color:#dc2626>Not Set</span>'}</div></div>
    <div style="background:#f8fafc;padding:.4rem .6rem;border-radius:6px"><div style="font-size:.55rem;color:#94a3b8;text-transform:uppercase">End Date</div><div style="font-size:.75rem;font-weight:600;color:#1e293b">${t.end_date?fD(t.end_date):'<span style=color:#dc2626>Not Set</span>'}</div></div>
    </div>
    </div>`).join('')}</div>`:'<div style="text-align:center;color:#94a3b8;font-size:.8rem;padding:1rem"><i class="fa-solid fa-calendar-xmark" style="font-size:1.5rem;margin-bottom:.5rem;display:block"></i>No semesters configured yet.<br>Your School Admin can add them.</div>'}
    </div>`;
    window._currentTermYearId=yearId;window._currentTermYearName=yearName;
    }}catch(e){toast('Error loading semesters','err');}
}
function openTermModal(yearId,termIdOrNull){
    const term=termIdOrNull?window._termData?.[termIdOrNull]:null;
    const isEdit=!!term;
    const yearName=window._currentTermYearName||window._yearData?.[yearId]?.year_name||'';
    let h=`<div class="mo show" id="termModal"><div class="mc" style="max-width:480px">
    <div style="background:linear-gradient(135deg,#7c3aed,#a855f7);color:#fff;padding:1rem 1.25rem;border-radius:20px 20px 0 0;display:flex;justify-content:space-between;align-items:center">
    <h3 style="font-weight:700;font-size:1rem;margin:0"><i class="fa-solid fa-calendar-week"></i> ${isEdit?'Edit':'Add'} Semester</h3>
    <button onclick="document.getElementById('termModal').remove()" style="background:rgba(255,255,255,.2);border:none;color:#fff;width:32px;height:32px;border-radius:8px;cursor:pointer;font-size:1rem">&times;</button></div>
    <div style="padding:1.25rem;display:flex;flex-direction:column;gap:.75rem">
    <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;padding:.5rem .75rem;font-size:.75rem;color:#64748b"><i class="fa-solid fa-info-circle" style="color:#7c3aed"></i> Academic Year: <strong class="amharic">${esc(yearName)}</strong></div>
    <div style="display:grid;grid-template-columns:2fr 1fr;gap:.75rem">
    <div><label class="lbl">Semester Name * <span class="amharic" style="font-size:.55rem;color:#94a3b8">ሴሚስተር ስም</span></label><input id="termName" class="inp amharic" value="${isEdit?esc(term.term_name):''}" placeholder="e.g. 1ኛ ሴሚስተር"></div>
    <div><label class="lbl">Semester #</label><input type="number" id="termNumber" class="inp" value="${isEdit?term.term_number:'1'}" min="1" max="4"></div>
    </div>
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:.75rem">
    <div><label class="lbl">Start Date</label><input type="date" id="termStart" class="inp" value="${isEdit&&term.start_date?term.start_date:''}"></div>
    <div><label class="lbl">End Date</label><input type="date" id="termEnd" class="inp" value="${isEdit&&term.end_date?term.end_date:''}"></div>
    </div>
    <div style="background:#ede9fe;border-radius:8px;padding:.6rem .75rem;font-size:.7rem;color:#5b21b6"><i class="fa-solid fa-lightbulb"></i> <strong>Tip:</strong> Set the start and end dates for each semester. You can change these anytime if the schedule shifts.</div>
    <button class="btn btn-p" style="width:100%;justify-content:center" onclick="doSaveTerm(${yearId},${isEdit?term.id:0})"><i class="fa-solid fa-save"></i> ${isEdit?'Update':'Create'} Semester</button>
    </div></div></div>`;
    document.getElementById('termModal')?.remove();
    document.body.insertAdjacentHTML('beforeend',h);
    if(typeof WBWSCalendar!=='undefined')WBWSCalendar.refreshPickers();
}
async function doSaveTerm(yearId,termId){
    const fd=new FormData();fd.append('action','save_term');
    if(termId)fd.append('term_id',termId);
    fd.append('academic_year_id',yearId);
    fd.append('term_name',document.getElementById('termName').value);
    fd.append('term_number',document.getElementById('termNumber').value);
    fd.append('start_date',document.getElementById('termStart').value);
    fd.append('end_date',document.getElementById('termEnd').value);
    try{const d=await postAPI('/admin/api_education.php',fd);
    toast(d.message,d.status==='success'?'ok':'err');
    if(d.status==='success'){document.getElementById('termModal')?.remove();viewYearTerms(yearId,window._currentTermYearName||'');}}catch(e){toast('Error saving semester','err');}
}
async function doSetCurrentTerm(termId){
    const fd=new FormData();fd.append('action','set_current_term');fd.append('term_id',termId);
    const yid=window._currentTermYearId;const yn=window._currentTermYearName||'';
    try{const d=await postAPI('/admin/api_education.php',fd);toast(d.message,d.status==='success'?'ok':'err');if(d.status==='success')viewYearTerms(yid,yn);}catch(e){toast('Error','err');}
}
async function doDeleteTerm(termId){if(!confirm('Delete this semester? Grades linked to it may be affected.'))return;
    const fd=new FormData();fd.append('action','delete_term');fd.append('term_id',termId);
    const yid=window._currentTermYearId;const yn=window._currentTermYearName||'';
    try{const d=await postAPI('/admin/api_education.php',fd);toast(d.message,d.status==='success'?'ok':'err');if(d.status==='success')viewYearTerms(yid,yn);}catch(e){toast('Error','err');}
}

// ═══ SUBMISSIONS REVIEW ═══
let allSubmissions=[], _subAnalytics=null;
function subStatusChip(st){
    const map={incomplete:'ch-i',draft:'ch-i',submitted:'ch-w',approved:'ch-ok',rejected:'ch-d',revision_needed:'ch-w'};
    const label={incomplete:'Draft',draft:'Draft',submitted:'Submitted',approved:'Approved',rejected:'Rejected',revision_needed:'Needs revision'};
    return `<span class="ch ${map[st]||'ch-w'}">${label[st]||esc(st||'—')}</span>`;
}
function switchSubTab(tab){
    ['draft','submitted','insights'].forEach(t=>{
        const b=document.getElementById('subTab'+(t==='insights'?'Insights':t.charAt(0).toUpperCase()+t.slice(1)));
        if(b) b.className='tbn'+(t===tab?' act':'');
    });
    const list=document.getElementById('submissionsList');
    const insights=document.getElementById('subInsights');
    const hid=document.getElementById('subFilterStatus');
    if(tab==='insights'){
        if(list) list.style.display='none';
        if(insights) insights.style.display='block';
        loadSubInsights();
        return;
    }
    if(list) list.style.display='block';
    if(insights) insights.style.display='none';
    if(hid) hid.value=tab;
    loadSubmissions();
}
async function loadSubmissions(){
    const list=document.getElementById('submissionsList');
    const statsRow=document.getElementById('subStatsRow');
    if(list) list.innerHTML='<div style="text-align:center;padding:1.5rem;color:#94a3b8"><i class="fa-solid fa-spinner fa-spin"></i> Loading...</div>';
    const sf=document.getElementById('subFilterStatus')?.value||'draft';
    const cf=document.getElementById('subFilterClass')?.value||'';
    const tf=document.getElementById('subFilterType')?.value||'';
    let url='/admin/api_communication.php?action=get_submissions';
    if(sf) url+=`&status_filter=${encodeURIComponent(sf)}`;
    if(cf) url+=`&class_id=${encodeURIComponent(cf)}`;
    if(tf) url+=`&type=${encodeURIComponent(tf)}`;
    try{
        const d=await getAPI(url);
        if(d.status!=='success'){
            if(list) list.innerHTML=`<div style="text-align:center;padding:2rem"><div style="color:#dc2626;font-weight:600;margin-bottom:.35rem">Could not load submissions</div><div style="color:#64748b;font-size:.8rem;margin-bottom:.85rem">${esc(d.message||'Please try again.')}</div><button class="btn btn-p btn-xs" type="button" onclick="loadSubmissions()"><i class="fa-solid fa-rotate-right"></i> Retry</button></div>`;
            toast(d.message||'Could not load submissions.','err');
            return;
        }
        allSubmissions=d.submissions||[];
        const st=d.stats||{};
        const drafts=st.incomplete??allSubmissions.filter(s=>s.status==='draft'||s.status==='incomplete').length;
        const pending=st.pending??allSubmissions.filter(s=>s.status==='submitted').length;
        const approved=st.approved??0;
        if(statsRow) statsRow.innerHTML=`
            <div class="sc" style="background:linear-gradient(135deg,#2563eb,#3b82f6);padding:1rem"><div><div style="font-size:1.5rem;font-weight:700">${drafts}</div><div style="font-size:.65rem;opacity:.8">Drafts (not finished)</div></div></div>
            <div class="sc" style="background:linear-gradient(135deg,#f59e0b,#d97706);padding:1rem"><div><div style="font-size:1.5rem;font-weight:700">${pending}</div><div style="font-size:.65rem;opacity:.8">Submitted</div></div></div>
            <div class="sc" style="background:linear-gradient(135deg,#059669,#10b981);padding:1rem"><div><div style="font-size:1.5rem;font-weight:700">${approved}</div><div style="font-size:.65rem;opacity:.8">Approved</div></div></div>
            <div class="sc" style="background:linear-gradient(135deg,#7c3aed,#6366f1);padding:1rem"><div><div style="font-size:1.05rem;font-weight:700">${st.today_recorded?((st.today_present||0)+' P · '+(st.today_absent||0)+' A · '+(st.today_late||0)+' L'):'—'}</div><div style="font-size:.65rem;opacity:.8">Today’s marks</div></div></div>`;
        if(!list) return;
        if(!allSubmissions.length){
            const empty=sf==='draft'?'No drafts yet. When a teacher taps Save, the unfinished sheet appears here.':'No submitted work yet. Submit is used after class or after a test.';
            list.innerHTML=`<div style="text-align:center;padding:2rem;color:#94a3b8"><i class="fa-solid fa-inbox" style="font-size:2rem;margin-bottom:.5rem;display:block;opacity:.3"></i>${empty}</div>`;
            return;
        }
        list.innerHTML=`<div class="tw"><table class="dt"><thead><tr><th>Type</th><th>Teacher</th><th>Class</th><th>What</th><th>Students</th><th>Result</th><th>Status</th><th>Updated</th><th>Actions</th></tr></thead><tbody>${allSubmissions.map(s=>{
            const isAtt=s.submission_type==='attendance';
            const what=isAtt?(s.attendance_date?fD(s.attendance_date):'Attendance'):(s.assessment_name||s.subject_name||'Mark list');
            const result=isAtt?`${s.present_count||0}P / ${s.absent_count||0}A / ${s.late_count||0}L`:(s.average_score!=null?parseFloat(s.average_score).toFixed(1):'—');
            const dt=s.updated_at||s.submitted_at||s.created_at;
            return `<tr>
                <td><span class="ch ${isAtt?'ch-i':'ch-p'}">${isAtt?'Attendance':'Mark list'}</span></td>
                <td style="font-weight:600;font-size:.8rem">${esc(s.teacher_name||'—')}</td>
                <td class="amharic">${esc(s.class_name||'—')}</td>
                <td class="amharic" style="font-size:.78rem">${esc(what)}</td>
                <td><span class="ch ch-i">${s.student_count||0}</span></td>
                <td style="font-weight:700;font-size:.8rem">${esc(result)}</td>
                <td>${subStatusChip(s.status)}</td>
                <td style="font-size:.75rem;color:#64748b">${dt?fD(dt):'—'}${s.status==='revision_needed'&&s.reviewer_name?`<div style="color:#c2410c;font-size:.68rem;margin-top:2px"><i class="fa-solid fa-arrow-rotate-left"></i> ${esc(s.reviewer_name)}${s.review_notes?': '+esc(s.review_notes.length>60?s.review_notes.slice(0,60)+'…':s.review_notes):''}</div>`:''}</td>
                <td style="white-space:nowrap">
                    <button class="ab" style="background:#ede9fe;color:#7c3aed" onclick="reviewSubmission(${s.id})" title="Open"><i class="fa-solid fa-eye"></i></button>
                    ${s.status==='submitted'?`
                    <button class="ab" style="background:#d1fae5;color:#065f46" onclick="quickReview(${s.id},'approved')" title="Approve"><i class="fa-solid fa-check"></i></button>
                    <button class="ab" style="background:#fee2e2;color:#991b1b" onclick="quickReview(${s.id},'rejected')" title="Reject"><i class="fa-solid fa-times"></i></button>
                    `:''}
                </td></tr>`;
        }).join('')}</tbody></table></div>`;
    }catch(e){
        if(list) list.innerHTML=`<div style="text-align:center;padding:2rem"><div style="color:#dc2626;font-weight:600;margin-bottom:.35rem">Could not load submissions</div><div style="color:#64748b;font-size:.8rem;margin-bottom:.85rem">${esc(friendlyNetError(e))}</div><button class="btn btn-p btn-xs" type="button" onclick="loadSubmissions()"><i class="fa-solid fa-rotate-right"></i> Retry</button></div>`;
        toast(friendlyNetError(e),'err');
    }
}
// Printable QR tiles for the selected class (Phase 8 QR attendance).
// GET-only governed endpoint; the class picker doubles as the guard.
function printQrRoster(){
    const c=document.getElementById('subFilterClass') ? document.getElementById('subFilterClass').value : '';
    if(!c) return toast('Pick a class first.','err');
    window.open('/admin/api_qr_roster.php?dept=edu&class_id='+encodeURIComponent(c),'_blank');
}

function exportSubmissions(){
    if(!allSubmissions.length) return toast('Nothing to export on this tab.','err');
    const h=['Type','Status','Teacher','Class','What','Students','Present','Absent','Late','Average','Updated'];
    const r=allSubmissions.map(s=>[
        s.submission_type||'', s.status_label||s.status||'', s.teacher_name||'', s.class_name||'',
        s.attendance_date||s.assessment_name||s.subject_name||'', s.student_count||0,
        s.present_count||'', s.absent_count||'', s.late_count||'', s.average_score||'',
        s.updated_at||s.submitted_at||''
    ]);
    const ws=XLSX.utils.aoa_to_sheet([h,...r]);
    const wb=XLSX.utils.book_new();
    XLSX.utils.book_append_sheet(wb,ws,'Submissions');
    if(_subAnalytics&&_subAnalytics.days){
        const dh=['Date','Present','Absent','Late','Excused','Recorded','Rate %'];
        const dr=_subAnalytics.days.map(d=>[d.date,d.present,d.absent,d.late,d.excused,d.recorded,d.rate]);
        XLSX.utils.book_append_sheet(wb,XLSX.utils.aoa_to_sheet([dh,...dr]),'14-day attendance');
    }
    XLSX.writeFile(wb,'FKSS_Submissions.xlsx');
}
async function loadSubInsights(){
    const box=document.getElementById('subInsights');
    if(!box) return;
    box.innerHTML='<div style="text-align:center;padding:1.5rem;color:#94a3b8"><i class="fa-solid fa-spinner fa-spin"></i> Building analysis…</div>';
    try{
        const d=await getAPI('/admin/api_communication.php?action=get_submission_analytics');
        if(d.status!=='success'){ box.innerHTML=`<p style="color:#dc2626">${esc(d.message||'Could not load analysis.')}</p>`; return; }
        _subAnalytics=d.analytics||{};
        const days=_subAnalytics.days||[];
        const classes=_subAnalytics.classes||[];
        const maxR=Math.max(1,...days.map(x=>x.recorded||0));
        const wave=days.length?`<svg viewBox="0 0 280 72" width="100%" height="72" preserveAspectRatio="none">${(()=>{
            const pts=days.map((x,i)=>{
                const px=days.length===1?140:(i/(days.length-1))*280;
                const py=68-((x.rate||0)/100)*60;
                return px.toFixed(1)+','+py.toFixed(1);
            }).join(' ');
            return `<polyline fill="none" stroke="#7c3aed" stroke-width="2.2" points="${pts}"/><polyline fill="rgba(124,58,237,.12)" stroke="none" points="0,72 ${pts} 280,72"/>`;
        })()}</svg>`:'<p style="color:#94a3b8;font-size:.8rem">No attendance in the last 14 days yet.</p>';
        const bars=days.map(x=>{
            const h=Math.max(4, Math.round((x.recorded||0)/maxR*80));
            return `<div style="flex:1;min-width:12px;text-align:center"><div title="${esc(x.date)} ${x.present}P/${x.absent}A" style="height:${h}px;background:linear-gradient(180deg,#7c3aed,#6366f1);border-radius:4px 4px 0 0;margin:0 auto;width:70%"></div><div style="font-size:.5rem;color:#94a3b8;margin-top:3px">${esc((x.date||'').slice(5))}</div></div>`;
        }).join('');
        const classRows=classes.length?classes.map(c=>`<tr>
            <td class="amharic" style="font-weight:600">${esc(c.class_name)}</td>
            <td>${c.recorded||0}</td>
            <td style="color:#059669;font-weight:700">${c.present||0}</td>
            <td style="color:#dc2626">${c.absent||0}</td>
            <td style="color:#d97706">${c.late||0}</td>
            <td>${c.rate==null?'—':c.rate+'%'}</td>
        </tr>`).join(''):'<tr><td colspan="6" style="text-align:center;color:#94a3b8">No class marks today</td></tr>';
        box.innerHTML=`
            <div style="display:grid;grid-template-columns:1.2fr 1fr;gap:1rem;margin-bottom:1rem">
                <div>
                    <div style="font-size:.75rem;font-weight:700;color:#64748b;margin-bottom:.4rem">14-day present rate</div>
                    ${wave}
                </div>
                <div>
                    <div style="font-size:.75rem;font-weight:700;color:#64748b;margin-bottom:.4rem">Daily volume</div>
                    <div style="display:flex;align-items:flex-end;gap:3px;height:96px">${bars||'<span style="color:#94a3b8;font-size:.8rem">No days yet</span>'}</div>
                </div>
            </div>
            <div style="font-size:.75rem;font-weight:700;color:#64748b;margin-bottom:.4rem">Today by class</div>
            <div class="tw"><table class="dt"><thead><tr><th>Class</th><th>Marked</th><th>Present</th><th>Absent</th><th>Late</th><th>Rate</th></tr></thead><tbody>${classRows}</tbody></table></div>
            <div style="margin-top:.85rem;text-align:right"><button class="btn btn-o btn-xs" type="button" onclick="exportSubmissions()"><i class="fa-solid fa-download"></i> Export Excel</button></div>`;
    }catch(e){ box.innerHTML=`<p style="color:#dc2626">${esc(friendlyNetError(e))}</p>`; }
}
function paintReviewShell(s){
    const sc={incomplete:'#2563eb',draft:'#2563eb',submitted:'#f59e0b',approved:'#059669',rejected:'#ef4444',revision_needed:'#f97316'};
    const rows=s.rows||[];
    const isAtt=s.submission_type==='attendance';
    window._reviewRows=rows;
    window._reviewIsAtt=isAtt;
    document.getElementById('reviewModalTitle').innerHTML=`<i class="fa-solid fa-clipboard-check"></i> ${isAtt?'Attendance':'Mark list'} · ${esc(s.class_name||'')}`;
    document.getElementById('reviewModalContent').innerHTML=`
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:.5rem;font-size:.82rem;margin-bottom:1rem;background:#f8fafc;padding:.85rem;border-radius:12px">
            <div><strong style="color:#64748b">Teacher:</strong> ${esc(s.teacher_name||'—')}</div>
            <div><strong style="color:#64748b">Class:</strong> <span class="amharic">${esc(s.class_name||'—')}</span></div>
            <div><strong style="color:#64748b">${isAtt?'Date':'Subject'}:</strong> <span class="amharic">${esc(isAtt?(s.attendance_date?fD(s.attendance_date):'—'):(s.subject_name||'—'))}</span></div>
            <div><strong style="color:#64748b">${isAtt?'Marks':'Assessment'}:</strong> ${esc(isAtt?((s.present_count||0)+' P · '+(s.absent_count||0)+' A · '+(s.late_count||0)+' L'):(s.assessment_name||'—'))}</div>
            <div><strong style="color:#64748b">Students:</strong> ${s.student_count||rows.length||0}</div>
            <div><strong style="color:#64748b">${isAtt?'Status':'Average'}:</strong> ${isAtt?subStatusChip(s.status):`<span style="font-weight:700;color:#7c3aed">${s.average_score!=null?parseFloat(s.average_score).toFixed(1):'—'}</span>`}</div>
            <div><strong style="color:#64748b">Progress:</strong> <span style="padding:2px 8px;border-radius:6px;font-size:.7rem;font-weight:700;color:#fff;background:${sc[s.status]||'#94a3b8'}">${esc(s.status_label||s.status||'')}</span></div>
            <div><strong style="color:#64748b">Updated:</strong> ${s.updated_at?fD(s.updated_at):(s.submitted_at?fD(s.submitted_at):'—')}</div>
            ${s.reviewer_name?`<div><strong style="color:#64748b">Reviewed by:</strong> ${esc(s.reviewer_name)}</div>`:''}
            ${s.review_notes?`<div style="grid-column:1/-1"><strong style="color:#64748b">Notes:</strong> ${esc(s.review_notes)}</div>`:''}
        </div>
        <div style="display:flex;justify-content:space-between;align-items:center;gap:.5rem;flex-wrap:wrap;margin-bottom:.5rem">
            <h4 style="font-weight:700;font-size:.85rem;margin:0"><i class="fa-solid fa-list-ol" style="color:#7c3aed"></i> ${isAtt?'Attendance sheet':'Student scores'}</h4>
            <div style="display:flex;gap:.4rem;flex-wrap:wrap">
                <input autocomplete="off" id="reviewSearch" class="inp" style="max-width:180px;padding:.35rem .6rem;font-size:.75rem" placeholder="Search name or code…" oninput="renderReviewRows()">
                <select id="reviewFilter" class="inp" style="max-width:130px;padding:.35rem .5rem;font-size:.75rem" onchange="renderReviewRows()">${isAtt?'<option value="">All marks</option><option value="present">Present</option><option value="absent">Absent</option><option value="late">Late</option><option value="e/option><option value="late">Late</option><option value="excused">Excused</option>':'<option value="">All scores</option><option value="scored">Has score</option><option value="blank">No score</option><option value="high">8 and above</option><option value="low">Below 5</option>'}</select>
                <select id="reviewSort" class="inp" style="max-width:130px;padding:.35rem .5rem;font-size:.75rem" onchange="renderReviewRows()"><option value="name">Sort: Name</option><option value="code">Sort: Code</option>${isAtt?'<option value="mark">Sort: Mark</option>':'<option value="score">Sort: Score</option>'}</select>
            </div>
        </div>
        <div id="reviewRowsBox">${rows.length?'':'<p style="text-align:center;color:#94a3b8">Loading students…</p>'}</div>
        ${s.status==='submitted'?`
        <div style="border-top:1px solid #f1f5f9;padding-top:1rem;margin-top:1rem">
            <label class="lbl">Review Notes</label>
            <textarea id="reviewNotes" class="inp" rows="2" placeholder="Required for Needs Revision / Reject: tell the teacher exactly what to fix."></textarea>
            <div style="display:flex;gap:.5rem;margin-top:.75rem;justify-content:flex-end">
                <button class="btn btn-d" onclick="doReview(${s.id},'rejected')"><i class="fa-solid fa-times"></i> Reject</button>
                <button class="btn btn-w" onclick="doReview(${s.id},'revision_needed')"><i class="fa-solid fa-exclamation-circle"></i> Needs Revision</button>
                <button class="btn btn-s" onclick="doReview(${s.id},'approved')"><i class="fa-solid fa-check"></i> Approve</button>
            </div>
        </div>`:''}`;
    if(rows.length) renderReviewRows();
}
async function reviewSubmission(id){
    const local=allSubmissions.find(x=>Number(x.id)===Number(id))||{};
    document.getElementById('reviewModal').classList.add('show');
    paintReviewShell(local);
    try{
        const d=await getAPI(`/admin/api_communication.php?action=get_submission_detail&id=${id}`);
        if(d.status==='success' && d.submission) paintReviewShell(d.submission);
        else if(!(local.rows||[]).length){
            const box=document.getElementById('reviewRowsBox');
            if(box) box.innerHTML='<p style="text-align:center;color:#dc2626">Could not load students. Close and try again.</p>';
        }
    }catch(e){
        const box=document.getElementById('reviewRowsBox');
        if(box && !(local.rows||[]).length) box.innerHTML=`<p style="text-align:center;color:#dc2626">${esc(friendlyNetError(e))}</p>`;
    }
}
function renderReviewRows(){
    const box=document.getElementById('reviewRowsBox');
    if(!box) return;
    const isAtt=!!window._reviewIsAtt;
    let rows=(window._reviewRows||[]).slice();
    const q=(document.getElementById('reviewSearch')?.value||'').toLowerCase().trim();
    const f=document.getElementById('reviewFilter')?.value||'';
    const sort=document.getElementById('reviewSort')?.value||'name';
    if(q) rows=rows.filter(st=>[st.student_name,st.father_name,st.member_code].filter(Boolean).join(' ').toLowerCase().includes(q));
    if(isAtt && f) rows=rows.filter(st=>(st.status||'')===f);
    if(!isAtt && f==='scored') rows=rows.filter(st=>st.score!=null);
    if(!isAtt && f==='blank') rows=rows.filter(st=>st.score==null);
    if(!isAtt && f==='high') rows=rows.filter(st=>st.score!=null && Number(st.score)>=8);
    if(!isAtt && f==='low') rows=rows.filter(st=>st.score!=null && Number(st.score)<5);
    rows.sort((a,b)=>{
        if(sort==='code') return String(a.member_code||'').localeCompare(String(b.member_code||''));
        if(sort==='mark') return String(a.status||'').localeCompare(String(b.status||''));
        if(sort==='score') return (Number(b.score)||-1)-(Number(a.score)||-1);
        return String(a.student_name||'').localeCompare(String(b.student_name||''));
    });
    if(!rows.length){ box.innerHTML='<p style="text-align:center;color:#94a3b8">No matching students.</p>'; return; }
    const color={present:'#059669',absent:'#dc2626',late:'#d97706',excused:'#2563eb'};
    if(isAtt){
        box.innerHTML=`<div class="tw"><table class="dt"><thead><tr><th>#</th><th>Student</th><th>Code</th><th>Mark</th><th>Note</th></tr></thead><tbody>${rows.map((st,i)=>`<tr><td>${i+1}</td><td style="font-weight:600">${esc(st.student_name||'')} ${esc(st.father_name||'')}</td><td><span class="ch ch-i">${esc(st.member_code||'')}</span></td><td><span style="font-weight:700;color:${color[st.status]||'#64748b'};text-transform:capitalize">${esc(st.status||'—')}</span></td><td style="font-size:.75rem;color:#64748b">${esc(st.notes||'')}</td></tr>`).join('')}</tbody></table></div>`;
    }else{
        box.innerHTML=`<div class="tw"><table class="dt"><thead><tr><th>#</th><th>Student</th><th>Code</th><th>Score</th><th>Remark</th></tr></thead><tbody>${rows.map((st,i)=>`<tr><td>${i+1}</td><td style="font-weight:600">${esc(st.student_name||'')} ${esc(st.father_name||'')}</td><td><span class="ch ch-i">${esc(st.member_code||'')}</span></td><td style="font-weight:700">${st.score!=null?st.score+(st.max_score?' / '+st.max_score:''):'—'}</td><td style="font-size:.75rem;color:#64748b">${esc(st.remarks||'')}</td></tr>`).join('')}</tbody></table></div>`;
    }
}
async function quickReview(id,status){
    if(!confirm(`${status==='approved'?'Approve':'Reject'} this submission?`))return;
    await doReview(id,status);
}
async function doReview(id,status){
    const notes=(document.getElementById('reviewNotes')?.value||'').trim();
    // Maker-checker discipline: returning/rejecting MUST carry a reason —
    // it is the teacher's instruction for what to fix before resubmitting.
    if(status!=='approved' && notes.length<3){
        toast('Write a short reason so the teacher knows what to fix.','err');
        document.getElementById('reviewNotes')?.focus();
        return;
    }
    const fd=new FormData();fd.append('action','review_submission');fd.append('submission_id',id);fd.append('new_status',status);fd.append('notes',notes);
    try{const d=await postAPI('/admin/api_communication.php',fd);
    toast(d.message,d.status==='success'?'ok':'err');
    if(d.status==='success'){closeModal('reviewModal');loadSubmissions();}}catch(e){toast('Error','err');}
}

// ═══ REPORT CARDS (Edu Dept) ═══
let rcData=[], rcStats={};
function rcQs(){
    const y=document.getElementById('rcYear')?.value||'';
    const t=document.getElementById('rcTerm')?.value||'';
    const s=document.getElementById('rcSubject')?.value||'';
    let q='';
    if(y) q+=`&year_id=${encodeURIComponent(y)}`;
    if(t) q+=`&term_id=${encodeURIComponent(t)}`;
    if(s) q+=`&subject_id=${encodeURIComponent(s)}`;
    return q;
}
function filterRcTerms(){
    const y=document.getElementById('rcYear')?.value||'';
    const sel=document.getElementById('rcTerm');
    if(!sel) return;
    const cur=sel.value;
    Array.from(sel.options).forEach(opt=>{
        if(!opt.value){opt.hidden=false;return;}
        opt.hidden=!!(y && opt.dataset.year && opt.dataset.year!==y);
    });
    const vis=Array.from(sel.options).find(o=>o.value===cur && !o.hidden);
    if(!vis) sel.value='';
}
async function loadClassPerformance(){
    const cid=document.getElementById('rcClass')?.value;
    if(!cid){
        document.getElementById('rcStatsArea').style.display='none';
        document.getElementById('rcTableArea').style.display='none';
        document.getElementById('rcEmptyMsg').style.display='block';
        rcData=[];
        return;
    }
    document.getElementById('rcEmptyMsg').style.display='none';
    document.getElementById('rcTableArea').style.display='block';
    document.getElementById('rcTableBody').innerHTML='<tr><td colspan="8" style="text-align:center;padding:1.5rem;color:#94a3b8"><i class="fa-solid fa-spinner fa-spin"></i> Loading class…</td></tr>';
    try{
        const d=await getAPI(`/admin/api_communication.php?action=get_class_report&class_id=${cid}${rcQs()}`);
        if(d.status!=='success'){
            document.getElementById('rcTableBody').innerHTML=`<tr><td colspan="8" style="text-align:center;padding:1.5rem;color:#dc2626">${esc(d.message||'Could not load this class.')}</td></tr>`;
            toast(d.message||'Could not load this class.','err');
            return;
        }
        rcData=d.students||[];
        rcStats=d.stats||{};
        const st=rcStats;
        document.getElementById('rcStatsArea').style.display='block';
        const sem=st.semester||{};
        const semRec=Number(sem.recorded||0);
        const semLeft=Number(sem.remaining||0);
        const clsName=document.getElementById('rcClass')?.selectedOptions?.[0]?.text||'';
        const yrName=document.getElementById('rcYear')?.selectedOptions?.[0]?.text||'';
        const tmName=document.getElementById('rcTerm')?.selectedOptions?.[0]?.text||'';
        const banner=document.getElementById('rcPrintBannerMeta');
        if(banner) banner.textContent=[clsName,yrName,tmName].filter(Boolean).join(' · ');
        document.getElementById('rcStatsCards').innerHTML=`
            <div class="rc-sem" style="grid-column:1/-1">
                <div class="rc-sem-title">How much of this semester the teacher has recorded (each subject is 100%)</div>
                <div class="rc-sem-nums">
                    <div><span class="done">${semRec}%</span><small>Recorded</small></div>
                    <div><span class="left">${semLeft}%</span><small>Still left</small></div>
                </div>
                <div class="rc-donebar"><i style="width:${semRec}%"></i><em></em></div>
                <div class="rc-done-lbl">${semRec}% of the semester weight is recorded · <span class="left">${semLeft}% still left</span>${sem.subjects_left?` · ${sem.subjects_left} subject(s) not finished`:''}</div>
            </div>
            <div class="rc-stat"><b>${st.total_students||0}</b><span>Students</span></div>
            <div class="rc-stat"><b>${st.class_average!=null?st.class_average+'%':'—'}</b><span>Class average</span></div>
            <div class="rc-stat"><b>${st.median!=null?st.median+'%':'—'}</b><span>Median</span></div>
            <div class="rc-stat"><b>${st.pass_rate!=null?st.pass_rate+'%':'—'}</b><span>Pass rate</span></div>
            <div class="rc-stat"><b>${st.highest!=null?st.highest+'%':'—'}</b><span>Highest</span></div>
            <div class="rc-stat"><b>${st.lowest!=null?st.lowest+'%':'—'}</b><span>Lowest</span></div>`;
        const gd=st.grade_distribution||{A:0,B:0,C:0,D:0,F:0};
        const parts=[{l:'A',c:'#047857',n:gd.A},{l:'B',c:'#0369a1',n:gd.B},{l:'C',c:'#b45309',n:gd.C},{l:'D',c:'#c2410c',n:gd.D},{l:'F',c:'#b91c1c',n:gd.F}].filter(x=>x.n>0);
        document.getElementById('rcDistBar').innerHTML=`<div style="font-size:.75rem;font-weight:600;color:#64748b;margin-bottom:.5rem">Grade distribution · ${st.graded_students||0} with scores</div>
            <div style="display:flex;height:32px;border-radius:10px;overflow:hidden;font-size:.65rem;font-weight:700;color:#fff">${
                parts.length?parts.map(x=>`<div style="flex:${x.n};background:${x.c};display:flex;align-items:center;justify-content:center;min-width:30px">${x.l}: ${x.n}</div>`).join(''):'<div style="flex:1;background:#e2e8f0;color:#64748b;display:flex;align-items:center;justify-content:center">No scores yet</div>'
            }</div>`;
        const subj=st.subjects||[];
        const box=document.getElementById('rcSubjectAvg');
        if(box){
            if(subj.length){
                box.style.display='block';
                box.innerHTML=`<div style="font-size:.8rem;font-weight:800;color:#3b0000;margin-bottom:.55rem">Each subject is out of 100% this semester — recorded vs still left</div>
                    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(230px,1fr));gap:.65rem">${subj.map(x=>{
                        const c=x.completion||{};
                        const rec=Number(c.recorded||0);
                        const left=Number(c.remaining||0);
                        const items=c.items||[];
                        const list=items.length?`<ul class="rc-items">${items.map(it=>`<li class="${it.recorded?'ok':'wait'}"><span>${esc(it.name||'')}${it.weight!=null?' · '+it.weight+'%':''}</span><span>${it.recorded?'Recorded':'Still left'}</span></li>`).join('')}</ul>`:((c.missing||[]).length?`<div class="rc-done-miss">Still left: ${esc(c.missing.join(', '))}</div>`:'');
                        return `<div class="rc-subj-card">
                            <div class="nm amharic">${esc(x.subject_name)}</div>
                            <div class="av">${x.average!=null?x.average+'%':'—'}<small>Class average · ${x.graded||0} graded</small></div>
                            <div class="rc-donebar"><i style="width:${rec}%"></i><em></em></div>
                            <div class="rc-done-lbl">${rec}% of this semester recorded · <span class="left">${left}% still left</span></div>
                            ${list}
                        </div>`;
                    }).join('')}</div>`;
            }else box.style.display='none';
        }
        renderRcTable();
    }catch(e){
        document.getElementById('rcTableBody').innerHTML=`<tr><td colspan="8" style="text-align:center;padding:1.5rem;color:#dc2626">${esc(friendlyNetError(e))}</td></tr>`;
        toast(friendlyNetError(e),'err');
    }
}
function renderRcTable(){
    const q=(document.getElementById('rcSearch')?.value||'').toLowerCase().trim();
    const f=document.getElementById('rcFilter')?.value||'';
    const sort=document.getElementById('rcSort')?.value||'rank';
    let list=rcData.slice();
    if(q) list=list.filter(s=>[s.student_name,s.father_name,s.member_code,s.christian_name].filter(Boolean).join(' ').toLowerCase().includes(q));
    if(f==='graded') list=list.filter(s=>s.overall_average!=null||s.avg_percentage!=null);
    if(f==='blank') list=list.filter(s=>s.overall_average==null&&s.avg_percentage==null);
    if(['A','B','C','D','F'].includes(f)) list=list.filter(s=>(s.grade_letter||s.overall_grade)===f);
    list.sort((a,b)=>{
        if(sort==='name') return String(a.student_name||'').localeCompare(String(b.student_name||''));
        if(sort==='average') return (Number(b.overall_average??b.avg_percentage)||-1)-(Number(a.overall_average??a.avg_percentage)||-1);
        if(sort==='attendance') return (Number(b.attendance_rate)||0)-(Number(a.attendance_rate)||0);
        const ra=a.rank==null?9999:a.rank, rb=b.rank==null?9999:b.rank;
        return ra-rb;
    });
    const gc={A:'#047857',B:'#0369a1',C:'#b45309',D:'#c2410c',F:'#b91c1c'};
    document.getElementById('rcTableBody').innerHTML=list.length?list.map(s=>{
        const pct=s.overall_average??s.avg_percentage;
        const attR=s.attendance_rate||0;
        const obt=(s.total_obtained!=null&&s.total_max!=null)?(`${s.total_obtained} / ${s.total_max}`):'—';
        return `<tr>
            <td><span style="display:inline-flex;width:28px;height:28px;border-radius:50%;align-items:center;justify-content:center;font-weight:700;font-size:.7rem;${s.rank&&s.rank<=3?'background:#F0C000;color:#600000':'background:#f1f5f9;color:#64748b'}">${s.rank||'—'}${s.tied?'=':''}</span></td>
            <td style="font-weight:600;font-size:.82rem">${esc(s.student_name||'')} ${esc(s.father_name||'')}${s.christian_name?`<div style="font-size:.62rem;color:#94a3b8">Christian name: ${esc(s.christian_name)}</div>`:''}</td>
            <td><span class="ch ch-i" style="font-size:.65rem">${esc(s.member_code||'')}</span></td>
            <td style="font-size:.8rem">${esc(obt)}</td>
            <td style="font-weight:700;font-size:.9rem">${pct!=null?Number(pct).toFixed(1)+'%':'—'}</td>
            <td><span style="display:inline-flex;width:26px;height:26px;border-radius:50%;align-items:center;justify-content:center;font-weight:700;font-size:.65rem;color:#fff;background:${gc[s.grade_letter]||'#94a3b8'}">${s.grade_letter||'—'}</span></td>
            <td><div style="display:flex;align-items:center;gap:.4rem"><div style="width:50px;height:6px;background:#e2e8f0;border-radius:99px"><div style="height:100%;border-radius:99px;background:${attR>=80?'#047857':attR>=60?'#d97706':'#b91c1c'};width:${Math.min(100,attR)}%"></div></div><span style="font-size:.7rem;color:#64748b">${attR}%</span></div></td>
            <td class="no-print"><button class="btn btn-o btn-xs" type="button" onclick="viewStudentReport(${s.id})"><i class="fa-solid fa-file-lines"></i> Report</button></td></tr>`;
    }).join(''):'<tr><td colspan="8" style="text-align:center;padding:2rem;color:#94a3b8">No matching students</td></tr>';
}
async function viewStudentReport(memberId){
    const cid=document.getElementById('rcClass')?.value||0;
    document.getElementById('rcModal').classList.add('show');
    document.getElementById('rcModalBody').innerHTML='<p style="text-align:center;color:#94a3b8;padding:2rem"><i class="fa-solid fa-spinner fa-spin"></i> Opening report card…</p>';
    try{
        const d=await getAPI(`/admin/api_communication.php?action=get_report_card&member_id=${memberId}&class_id=${cid}${rcQs()}`);
        if(d.status!=='success'){
            document.getElementById('rcModalBody').innerHTML=`<p style="text-align:center;color:#ef4444;padding:2rem">${esc(d.message||'Could not open this report card.')}</p>`;
            return;
        }
        if(window.FKSSReportCard) FKSSReportCard.fillModal(document.getElementById('rcModalBody'), d);
        else document.getElementById('rcModalBody').innerHTML='<p style="text-align:center;color:#ef4444;padding:2rem">Report card view failed to load. Refresh the page.</p>';
    }catch(e){
        document.getElementById('rcModalBody').innerHTML=`<p style="color:#ef4444;text-align:center;padding:2rem">${esc(friendlyNetError(e))}</p>`;
    }
}
function exportPerformance(){
    const cid=document.getElementById('rcClass')?.value;
    if(!cid) return toast('Select a class first.','err');
    if(!rcData.length) return toast('No data to export.','err');
    window.location='/admin/export_class_report.php?class_id='+encodeURIComponent(cid)+rcQs();
}
function printClassList(){
    if(!rcData.length) return toast('Load a class first.','err');
    const fab=document.getElementById('ai-fab');
    const win=document.getElementById('ai-win');
    if(fab) fab.style.display='none';
    if(win) win.style.display='none';
    document.body.classList.add('rc-print-list');
    const done=function(){
        document.body.classList.remove('rc-print-list');
        if(fab) fab.style.display='';
        if(win) win.style.display='';
        window.removeEventListener('afterprint', done);
    };
    window.addEventListener('afterprint', done);
    window.print();
    setTimeout(done, 2000);
}
async function generateBulkReports(){
    const cid=document.getElementById('rcClass')?.value;
    if(!cid) return toast('Select a class first.','err');
    if(!rcData.length) return toast('Load the class first.','err');
    toast('Preparing class report cards…','ok');
    try{
        const d=await getAPI(`/admin/api_communication.php?action=get_class_cards&class_id=${cid}${rcQs()}`);
        if(d.status!=='success' || !(d.cards||[]).length){
            toast(d.message||'No report cards to print.','err');
            return;
        }
        if(window.FKSSReportCard) FKSSReportCard.printSheets(d.cards);
        if(d.truncated) toast('Printed the first 200 students in this class.','w');
    }catch(e){ toast(friendlyNetError(e),'err'); }
}

// ═══ NAV EXTENSION ═══
const _origNav=nav;
nav=function(n){try{_origNav(n);}catch(e){console.error(e);}try{if(n==='submissions')loadSubmissions();}catch(e){console.error(e);}try{if(n==='reportcards')loadClassPerformance();}catch(e){console.error(e);}};
try{
    const _sp=new URLSearchParams(window.location.search).get('section');
    if(_sp) nav(_sp);
}catch(e){console.error(e);}
if(!document.querySelector('.sec.act')){
    const _dash=document.getElementById('sec-dashboard');
    if(_dash) _dash.classList.add('act');
}

// ═══ INIT ═══
document.addEventListener('DOMContentLoaded',()=>{loadTeachers();try{filterRcTerms();}catch(e){}
document.addEventListener('click',e=>{
    const sr=document.getElementById('enrollSearchResults');
    if(sr&&!sr.contains(e.target)&&e.target.id!=='enrollSearchInput')sr.style.display='none';
    const mh=document.getElementById('teacherMemberHits');
    if(mh&&!mh.contains(e.target)&&e.target.id!=='teacherMemberQ')mh.style.display='none';
});
});
</script>
</body></html>
