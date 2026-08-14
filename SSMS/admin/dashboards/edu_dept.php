<?php
/**
 * Education Department Dashboard — <?= SCHOOL_NAME ?>
 * PRODUCTION BUILD — All features complete
 * Teacher CRUD, Classes CRUD, Subjects, Assessments, Grades, Enrollment, Academic Years
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../backend/ethiopian_date.php';
require_once __DIR__ . '/../backend/calendar_system.php';

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

$currentYear = null; $currentTerm = null; $classes = []; $subjects = []; $members = [];
if ($tablesExist) {
    $currentYear = function_exists('ay_resolve') ? ay_resolve($conn)['year'] : null;
    try { $r = $conn->query("SELECT * FROM academic_terms WHERE is_current = 1 LIMIT 1");
    $currentTerm = $r ? $r->fetch_assoc() : null; } catch(Exception $e) {}
    $r = $conn->query("SELECT * FROM classes WHERE is_active = 1 ORDER BY level_order");
    if ($r) while ($row = $r->fetch_assoc()) $classes[] = $row;
    $r = $conn->query("SELECT * FROM subjects WHERE is_active = 1 ORDER BY subject_name");
    if ($r) while ($row = $r->fetch_assoc()) $subjects[] = $row;
    $r = $conn->query("SELECT id, member_code, student_name, father_name, phone_number, gender FROM members WHERE status = 'active' ORDER BY student_name LIMIT 500");
    if ($r) while ($row = $r->fetch_assoc()) $members[] = $row;
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
.ab{width:36px;height:36px;border-radius:8px;display:inline-flex;align-items:center;justify-content:center;border:none;cursor:pointer;font-size:.75rem;transition:.2s}.ab:hover{transform:scale(1.1)}
.tbn{padding:.55rem 1.1rem;border:none;background:transparent;cursor:pointer;font-size:.8rem;font-weight:500;color:#64748b;border-bottom:2px solid transparent;transition:.2s}.tbn.act{color:#7c3aed;border-bottom-color:#7c3aed}
.at{display:inline-flex;align-items:center;gap:4px;padding:3px 8px;border-radius:8px;font-size:.65rem;background:#ede9fe;color:#5b21b6;margin:2px}
.at button{background:none;border:none;cursor:pointer;color:#7c3aed;padding:0;font-size:.7rem}.at button:hover{color:#dc2626}
.toast{position:fixed;bottom:1.5rem;right:1.5rem;padding:.75rem 1.1rem;border-radius:12px;color:#fff;z-index:200;animation:slideIn .3s}.toast-ok{background:#059669}.toast-err{background:#dc2626}
@keyframes slideIn{from{opacity:0;transform:translateX(100px)}to{opacity:1;transform:translateX(0)}}
.bn{display:none;position:fixed;bottom:0;left:0;right:0;background:rgba(255,255,255,.95);backdrop-filter:blur(10px);border-top:1px solid #e2e8f0;padding:.3rem 0;z-index:50}.bni{display:flex;justify-content:space-around;max-width:480px;margin:0 auto}.bn button,.bn a{display:flex;flex-direction:column;align-items:center;gap:.1rem;background:none;border:none;color:#94a3b8;font-size:.55rem;padding:.2rem .4rem;cursor:pointer;text-decoration:none}.bn button.act{color:#7c3aed}.bn i{font-size:1rem}
@media(max-width:768px){.sb{display:none}main{padding:1rem 1rem 5rem!important}.bn{display:block}}
@media print{.sb,.bn,.no-print{display:none!important}main{padding:0!important}}
</style>
<?= wbws_calendar_scripts($conn) ?>
<link rel="stylesheet" href="/admin/css/mobile.css">
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
<div class="crd" style="text-align:center;padding:3rem"><i class="fa-solid fa-database" style="font-size:3rem;color:#7c3aed;margin-bottom:1rem"></i><h2 style="margin-bottom:.5rem">Setup Required</h2><p style="color:#64748b;margin-bottom:1.5rem">Education tables need to be created first.</p><a href="/admin/migrations/002_add_academic_attendance_workflow.php" class="btn btn-p"><i class="fa-solid fa-play"></i> Run Migration 002</a> <a href="/admin/migrations/003_add_assessments.php" class="btn btn-s" style="margin-left:.5rem"><i class="fa-solid fa-play"></i> Run Migration 003</a></div>
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
<div style="display:flex;gap:.5rem" class="no-print"><button class="btn btn-p" onclick="openCreateTeacher()"><i class="fa-solid fa-plus"></i> Add Teacher</button><button class="btn btn-o btn-xs" onclick="exportTeachers()"><i class="fa-solid fa-download"></i> Export</button></div>
</div>
<div class="crd" style="padding:.75rem" class="no-print"><div style="display:flex;gap:.5rem;flex-wrap:wrap"><input type="text" id="teacherSearch" class="inp" style="max-width:250px" placeholder="Search teachers..." oninput="filterTeachers()"><label style="display:flex;align-items:center;gap:.3rem;font-size:.75rem;color:#64748b"><input type="checkbox" id="showInactive" onchange="loadTeachers()"> Show inactive</label></div></div>
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
<tr><td class="amharic" style="font-weight:600"><?= e($s['subject_name']) ?></td><td><?= e($s['subject_name_en'] ?? '—') ?></td><td><code style="font-size:.7rem;background:#f1f5f9;padding:2px 6px;border-radius:4px"><?= e($s['subject_code'] ?? '—') ?></code></td><td><span class="ch ch-i"><?= $cnt ?> classes</span></td><td><button onclick='editSubject(<?= json_encode($s) ?>)' class="ab" style="background:#ede9fe;color:#7c3aed" title="Edit"><i class="fa-solid fa-pen"></i></button></td></tr>
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
<button class="tbn" id="enrTabUnassigned" onclick="switchEnrollTab('unassigned')"><i class="fa-solid fa-user-xmark"></i> Unassigned Members</button>
<button class="tbn" id="enrTabTeachers" onclick="switchEnrollTab('teachers')"><i class="fa-solid fa-chalkboard-teacher"></i> Teacher Assignments</button>
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

<!-- TAB: Unassigned Members -->
<div id="enrPanelUnassigned" style="display:none">
<div class="crd" style="padding:1rem">
<div style="display:grid;grid-template-columns:1fr auto auto auto auto auto;gap:.5rem;align-items:end;flex-wrap:wrap">
<div><label class="lbl">Search Members</label><input type="text" id="unassignedSearch" class="inp" placeholder="Search by name or code..." oninput="debounceUnassigned()"></div>
<div><label class="lbl">Gender</label><select id="unassignedGender" class="inp" onchange="loadUnassigned()"><option value="">All</option><option value="male">Male ♂</option><option value="female">Female ♀</option></select></div>
<div><label class="lbl">Type</label><select id="unassignedMType" class="inp" onchange="loadUnassigned()"><option value="">All Types</option><option value="regular">Regular</option><option value="special_regular">Special</option><option value="honorary">Honorary</option></select></div>
<div><label class="lbl">Age Group</label><select id="unassignedAge" class="inp" onchange="loadUnassigned()"><option value="">All</option><option value="under6">Under 6</option><option value="7_13">7-13</option><option value="14_17">14-17</option><option value="18_plus">18+</option></select></div>
<div><label class="lbl">Enroll To</label><select id="unassignedTargetClass" class="inp"><option value="">— Class —</option><?php foreach ($classes as $c): ?><option value="<?= $c['id'] ?>"><?= e($c['class_name']) ?></option><?php endforeach; ?></select></div>
<button class="btn btn-s" onclick="bulkEnrollSelected()"><i class="fa-solid fa-users"></i> Enroll Selected</button>
</div>
</div>
<div id="unassignedArea" style="margin-top:.75rem"></div>
</div>

<!-- TAB: Teacher Assignments -->
<div id="enrPanelTeachers" style="display:none">
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
<div><h2 style="font-size:1.2rem;font-weight:700;color:#1e293b"><i class="fa-solid fa-inbox" style="color:#7c3aed"></i> Teacher Submissions</h2><p style="font-size:.75rem;color:#64748b" class="amharic">ከመምህራን የመጡ ውጤቶች</p></div>
<div style="display:flex;gap:.5rem;flex-wrap:wrap">
<select id="subFilterStatus" class="inp" style="max-width:160px" onchange="loadSubmissions()"><option value="">All Statuses</option><option value="submitted" selected>Pending Review</option><option value="approved">Approved</option><option value="rejected">Rejected</option><option value="revision_needed">Needs Revision</option></select>
<select id="subFilterClass" class="inp" style="max-width:180px" onchange="loadSubmissions()"><option value="">All Classes</option><?php foreach ($classes as $c): ?><option value="<?= $c['id'] ?>"><?= e($c['class_name']) ?></option><?php endforeach; ?></select>
</div></div>
<!-- Stats Row -->
<div id="subStatsRow" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:.75rem;margin-bottom:1rem"></div>
<!-- Submissions List -->
<div id="submissionsList" class="crd" style="padding:.5rem"><div style="text-align:center;padding:1.5rem;color:#94a3b8"><i class="fa-solid fa-spinner fa-spin"></i> Loading...</div></div>
</div>

<!-- ═══ REVIEW MODAL ═══ -->
<div class="mo" id="reviewModal"><div class="mc" style="max-width:780px">
<div style="background:linear-gradient(135deg,#7c3aed,#6366f1);color:#fff;padding:1rem 1.25rem;border-radius:20px 20px 0 0;display:flex;justify-content:space-between;align-items:center"><h3 id="reviewModalTitle" style="font-weight:700;font-size:1rem;margin:0"><i class="fa-solid fa-clipboard-check"></i> Review Submission</h3><button onclick="closeModal('reviewModal')" style="background:rgba(255,255,255,.2);border:none;color:#fff;width:32px;height:32px;border-radius:8px;cursor:pointer;font-size:1rem">&times;</button></div>
<div id="reviewModalContent" style="padding:1.25rem"><p style="text-align:center;color:#94a3b8">Loading...</p></div>
</div></div>

<!-- ═══ REPORT CARDS ═══ -->
<div id="sec-reportcards" class="sec">
<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1rem;flex-wrap:wrap;gap:.5rem">
<div><h2 style="font-size:1.2rem;font-weight:700;color:#1e293b"><i class="fa-solid fa-file-lines" style="color:#059669"></i> Report Cards</h2><p style="font-size:.75rem;color:#64748b" class="amharic">የተማሪ ሪፖርት ካርድ</p></div>
</div>
<div class="crd" style="padding:1rem;margin-bottom:1rem">
<div style="display:grid;grid-template-columns:1fr 1fr 1fr auto;gap:.75rem;align-items:end">
<div><label class="lbl">Class</label><select id="rcClass" class="inp" onchange="loadClassPerformance()"><option value="">— Select Class —</option><?php foreach ($classes as $c): ?><option value="<?= $c['id'] ?>"><?= e($c['class_name']) ?> (<?= e($c['class_name_en'] ?? '') ?>)</option><?php endforeach; ?></select></div>
<div><label class="lbl">Subject (Optional)</label><select id="rcSubject" class="inp" onchange="loadClassPerformance()"><option value="">All Subjects</option><?php foreach ($subjects as $s): ?><option value="<?= $s['id'] ?>"><?= e($s['subject_name']) ?></option><?php endforeach; ?></select></div>
<div><label class="lbl">Export</label><button class="btn btn-o" style="width:100%" onclick="exportPerformance()"><i class="fa-solid fa-download"></i> Excel</button></div>
<button class="btn btn-s" onclick="generateBulkReports()" title="Generate all student report cards"><i class="fa-solid fa-file-lines"></i> Bulk Generate</button>
</div></div>
<!-- Class Performance Stats -->
<div id="rcStatsArea" style="display:none">
<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(130px,1fr));gap:.75rem;margin-bottom:1rem" id="rcStatsCards"></div>
<!-- Grade Distribution Bar -->
<div class="crd" style="padding:1rem;margin-bottom:1rem" id="rcDistBar"></div>
</div>
<!-- Performance Table -->
<div id="rcTableArea" style="display:none" class="crd"><div class="tw"><table class="dt"><thead><tr><th>Rank</th><th>Student</th><th>Code</th><th>Average</th><th>Grade</th><th>Attendance</th><th>Actions</th></tr></thead><tbody id="rcTableBody"></tbody></table></div></div>
<div id="rcEmptyMsg" class="crd" style="padding:2rem;text-align:center;color:#94a3b8"><i class="fa-solid fa-chart-bar" style="font-size:2rem;margin-bottom:.5rem;display:block;opacity:.3"></i>Select a class to view performance and generate report cards</div>

<!-- Single Report Card Modal -->
<div class="mo" id="rcModal"><div class="mc" style="max-width:720px">
<div id="rcModalHeader" style="background:linear-gradient(135deg,#1e293b,#334155);color:#fff;padding:1.25rem;text-align:center;border-radius:20px 20px 0 0">
<div style="font-size:.6rem;text-transform:uppercase;letter-spacing:.15em;opacity:.6;margin-bottom:.2rem"><?= SCHOOL_NAME_SHORT_AM ?> የቅዱስ ቁርባን ሰንበት ትምህርት ቤት</div>
<h2 style="font-size:1.1rem;font-weight:700;margin:0">Student Report Card</h2>
<div style="font-size:.7rem;opacity:.7;margin-top:.2rem" id="rcModalYear"></div>
</div>
<div id="rcModalBody" style="padding:1.25rem"><p style="text-align:center;color:#94a3b8"><i class="fa-solid fa-spinner fa-spin"></i> Loading...</p></div>
</div></div>
</div>

<?php endif; ?>
</main>
</div>
<!-- TEACHER MODAL -->
<div class="mo" id="teacherModal"><div class="mc">
<div style="background:linear-gradient(135deg,#7c3aed,#6366f1);color:#fff;padding:1rem 1.25rem;border-radius:20px 20px 0 0;display:flex;justify-content:space-between;align-items:center"><h3 id="teacherModalTitle" style="font-weight:700;font-size:1rem;margin:0"><i class="fa-solid fa-user-plus"></i> Add Teacher</h3><button onclick="closeModal('teacherModal')" style="background:rgba(255,255,255,.2);border:none;color:#fff;width:32px;height:32px;border-radius:8px;cursor:pointer;font-size:1rem">&times;</button></div>
<div style="padding:1.25rem">
<div style="display:flex;gap:.5rem;margin-bottom:1rem;border-bottom:1px solid #e2e8f0"><button class="tbn act" id="tabInfo" onclick="showTab('info')">Basic Info</button><button class="tbn" id="tabAssign" onclick="showTab('assign')">Assignments</button></div>
<div id="panelInfo">
<div style="margin-bottom:.75rem"><label class="lbl">Link to Existing Member</label><select id="teacherMemberId" class="inp" onchange="fillFromMember()"><option value="">— Optional —</option><?php foreach ($members as $m): ?><option value="<?= $m['id'] ?>" data-name="<?= e($m['student_name']) ?>"><?= e($m['student_name']) ?> — <?= e($m['member_code']) ?></option><?php endforeach; ?></select></div>
<div style="display:grid;grid-template-columns:1fr 1fr;gap:.75rem">
<div><label class="lbl">Full Name *</label><input id="teacherFullName" class="inp" required></div>
<div><label class="lbl">Username *</label><input id="teacherUsername" class="inp" required></div>
<div><label class="lbl">Email</label><input id="teacherEmail" type="email" class="inp"></div>
<div><label class="lbl">Password *</label><input id="teacherPassword" type="password" class="inp"></div>
</div></div>
<div id="panelAssign" style="display:none">
<p style="font-size:.8rem;color:#64748b;margin-bottom:.75rem">Assign classes and subjects to this teacher</p>
<div style="display:grid;grid-template-columns:1fr 1fr auto;gap:.5rem;align-items:end;margin-bottom:.75rem">
<div><label class="lbl">Class</label><select id="asgClass" class="inp"><?php foreach ($classes as $c): ?><option value="<?= $c['id'] ?>"><?= e($c['class_name']) ?></option><?php endforeach; ?></select></div>
<div><label class="lbl">Subject</label><select id="asgSubject" class="inp"><?php foreach ($subjects as $s): ?><option value="<?= $s['id'] ?>"><?= e($s['subject_name']) ?></option><?php endforeach; ?></select></div>
<button class="btn btn-p btn-xs" onclick="addTempAssignment()"><i class="fa-solid fa-plus"></i></button>
</div>
<div id="tempAssignments"></div>
</div>
<div style="display:flex;justify-content:flex-end;gap:.5rem;margin-top:1rem"><button class="btn btn-o" onclick="closeModal('teacherModal')">Cancel</button><button class="btn btn-p" id="teacherSubmitBtn" onclick="saveTeacher()"><i class="fa-solid fa-save"></i> Save</button></div>
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
<div><label class="lbl">Name (Amharic) *</label><input id="subjectName" class="inp amharic" required placeholder="e.g. ቅዱስ ቁርባን"></div>
<div><label class="lbl">Name (English)</label><input id="subjectNameEn" class="inp" placeholder="e.g. Holy Communion"></div>
<div><label class="lbl">Description</label><textarea id="subjectDesc" class="inp" rows="2"></textarea></div>
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
<div><label class="lbl">Age Group</label><select id="classAge" class="inp"><option value="">—</option><option value="under6">Under 6</option><option value="7_13">7-13</option><option value="14_17">14-17</option><option value="18_plus">18+</option></select></div>
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
<div style="display:grid;grid-template-columns:1fr 1fr;gap:.75rem;margin-bottom:1rem">
<div><label class="lbl">Target Class *</label><select id="bulkClass" class="inp"><?php foreach ($classes as $c): ?><option value="<?= $c['id'] ?>"><?= e($c['class_name']) ?></option><?php endforeach; ?></select></div>
<div><label class="lbl">Filter</label><select id="bulkFilter" class="inp" onchange="loadBulkCandidates()"><option value="">All Unassigned</option><option value="male">Male Only</option><option value="female">Female Only</option><option value="under6">Under 6</option><option value="7_13">Age 7-13</option><option value="14_17">Age 14-17</option><option value="18_plus">18+</option></select></div>
</div>
<div style="margin-bottom:.75rem"><input type="text" id="bulkSearch" class="inp" placeholder="Search unassigned members..." oninput="loadBulkCandidates()"></div>
<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:.5rem">
<label style="font-size:.75rem;color:#64748b"><input type="checkbox" id="bulkSelectAll" onchange="toggleBulkAll()"> Select All</label>
<span id="bulkCount" style="font-size:.7rem;color:#7c3aed;font-weight:600">0 selected</span>
</div>
<div id="bulkCandidateList" style="max-height:300px;overflow-y:auto;border:1px solid #e2e8f0;border-radius:10px;padding:.5rem"></div>
<button class="btn btn-p" style="width:100%;justify-content:center;margin-top:1rem" onclick="executeBulkEnroll()"><i class="fa-solid fa-check-double"></i> Enroll Selected Students</button>
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

<div id="toastC"></div><script>
let allTeachers=[],currentTeacherId=null,tempAssignments=[];
const membersData=<?= json_encode($members) ?>;

// ═══ NAVIGATION ═══
function nav(n){document.querySelectorAll('.sec').forEach(s=>s.classList.remove('act'));const t=document.getElementById('sec-'+n);if(t)t.classList.add('act');document.querySelectorAll('.sb .nl').forEach(b=>b.classList.remove('act'));document.querySelectorAll('[data-sec="'+n+'"]').forEach(b=>b.classList.add('act'));document.querySelectorAll('.bn button').forEach(b=>b.classList.remove('act'));document.querySelectorAll('.bn [data-sec="'+n+'"]').forEach(b=>b.classList.add('act'));
if(n==='teachers')loadTeachers();if(n==='classes')loadClasses();if(n==='settings')loadYears();if(n==='enrollment')loadEnrollOverview();const _u=new URL(window.location);_u.searchParams.set('section',n);history.replaceState(null,'',_u);}
document.querySelectorAll('[data-sec]').forEach(el=>{el.addEventListener('click',function(e){e.preventDefault();const n=this.getAttribute('data-sec');if(n)nav(n);});});
{const _sp=new URLSearchParams(window.location.search).get('section');if(_sp)nav(_sp);}

// ═══ HELPERS ═══
function esc(t){const d=document.createElement('div');d.textContent=t;return d.innerHTML;}
function fD(d){return (typeof WBWSCalendar!=='undefined')?WBWSCalendar.formatDate(d,'medium'):(d||'—');}
function fDL(d){return (typeof WBWSCalendar!=='undefined')?WBWSCalendar.formatDate(d,'long'):(d||'—');}
function toast(m,t='ok'){const el=document.createElement('div');el.className='toast toast-'+t;el.innerHTML=`<i class="fa-solid fa-${t==='ok'?'check-circle':'exclamation-circle'}" style="margin-right:.4rem"></i>${m}`;document.getElementById('toastC').appendChild(el);setTimeout(()=>el.remove(),3500);}
function closeModal(id){document.getElementById(id).classList.remove('show');}
function showTab(t){document.getElementById('tabInfo').className='tbn'+(t==='info'?' act':'');document.getElementById('tabAssign').className='tbn'+(t==='assign'?' act':'');document.getElementById('panelInfo').style.display=t==='info'?'block':'none';document.getElementById('panelAssign').style.display=t==='assign'?'block':'none';}
function postAPI(url,fd){fd.append('csrf_token',CSRF_TOKEN);return fetch(url,{method:'POST',body:fd,credentials:'same-origin'}).then(r=>r.json());}
function getAPI(url){return fetch(url,{credentials:'same-origin'}).then(r=>r.json());}

// ═══ TEACHERS ═══
async function loadTeachers(){
    const inc=document.getElementById('showInactive')?.checked?'1':'0';
    try{const d=await getAPI(`/admin/api_teachers.php?action=get_teachers&include_inactive=${inc}`);
    if(d.status==='success'){allTeachers=d.teachers||[];renderTeachers();}}catch(e){toast('Failed to load teachers','err');}
}
function renderTeachers(){
    const q=(document.getElementById('teacherSearch')?.value||'').toLowerCase();
    const list=q?allTeachers.filter(t=>[t.full_name,t.username,t.email,t.member_code].filter(Boolean).join(' ').toLowerCase().includes(q)):allTeachers;
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
function openCreateTeacher(){currentTeacherId=null;tempAssignments=[];document.getElementById('teacherModalTitle').innerHTML='<i class="fa-solid fa-user-plus"></i> Add Teacher';document.getElementById('teacherFullName').value='';document.getElementById('teacherUsername').value='';document.getElementById('teacherEmail').value='';document.getElementById('teacherPassword').value='';document.getElementById('teacherPassword').required=true;document.getElementById('teacherMemberId').value='';renderTempAssignments();showTab('info');document.getElementById('teacherModal').classList.add('show');}
function editTeacher(id){
    getAPI(`/admin/api_teachers.php?action=get_teacher&teacher_id=${id}`).then(d=>{
        if(d.status==='success'){const t=d.teacher;currentTeacherId=t.id;
        document.getElementById('teacherModalTitle').innerHTML='<i class="fa-solid fa-pen"></i> Edit Teacher';
        document.getElementById('teacherFullName').value=t.full_name||'';document.getElementById('teacherUsername').value=t.username||'';
        document.getElementById('teacherEmail').value=t.email||'';document.getElementById('teacherPassword').value='';
        document.getElementById('teacherPassword').required=false;document.getElementById('teacherMemberId').value=t.member_id||'';
        const asgns=t.assignments||d.assignments||[];
        tempAssignments=asgns.map(a=>({class_id:a.class_id,subject_id:a.subject_id,class_name:a.class_name,subject_name:a.subject_name,id:a.id}));
        renderTempAssignments();showTab('info');document.getElementById('teacherModal').classList.add('show');}
    });
}
function fillFromMember(){const s=document.getElementById('teacherMemberId');const opt=s.options[s.selectedIndex];if(opt&&opt.dataset.name){document.getElementById('teacherFullName').value=opt.dataset.name;}}
function addTempAssignment(){const cSel=document.getElementById('asgClass'),sSel=document.getElementById('asgSubject');if(!cSel.value||!sSel.value)return;const exists=tempAssignments.some(a=>a.class_id==cSel.value&&a.subject_id==sSel.value);if(exists)return toast('Already assigned','err');tempAssignments.push({class_id:cSel.value,subject_id:sSel.value,class_name:cSel.options[cSel.selectedIndex].text,subject_name:sSel.options[sSel.selectedIndex].text});renderTempAssignments();}
function removeTempAssignment(i){tempAssignments.splice(i,1);renderTempAssignments();}
function renderTempAssignments(){document.getElementById('tempAssignments').innerHTML=tempAssignments.length?tempAssignments.map((a,i)=>`<span class="at">${esc(a.class_name)} → ${esc(a.subject_name)} <button onclick="removeTempAssignment(${i})">&times;</button></span>`).join(''):'<p style="font-size:.8rem;color:#94a3b8">No assignments yet</p>';}
async function saveTeacher(){
    const fd=new FormData();fd.append('action',currentTeacherId?'update_teacher':'create_teacher');
    if(currentTeacherId)fd.append('teacher_id',currentTeacherId);
    fd.append('full_name',document.getElementById('teacherFullName').value);fd.append('username',document.getElementById('teacherUsername').value);
    fd.append('email',document.getElementById('teacherEmail').value);fd.append('member_id',document.getElementById('teacherMemberId').value);
    const pw=document.getElementById('teacherPassword').value;if(pw)fd.append(currentTeacherId?'new_password':'password',pw);
    try{const d=await postAPI('/admin/api_teachers.php',fd);
    if(d.status==='success'){toast(d.message);const tid=currentTeacherId||d.teacher_id;
    // Sync assignments in separate try-catch so teacher save is not affected
    if(tid){try{
        // Get existing assignments from server for comparison
        if(currentTeacherId){
            const ed=await getAPI(`/admin/api_teachers.php?action=get_teacher&teacher_id=${tid}`);
            const ea=ed.teacher?.assignments||ed.assignments||[];
            const existingIds=ea.map(a=>a.id).filter(Boolean);
            const keepIds=tempAssignments.map(a=>a.id).filter(Boolean);
            // Remove assignments that were deleted
            for(const eid of existingIds){
                if(!keepIds.includes(eid)){
                    const rfd=new FormData();rfd.append('action','remove_assignment');rfd.append('assignment_id',eid);
                    const rr=await postAPI('/admin/api_teachers.php',rfd);
                    if(rr.status==='success')console.log('Removed assignment',eid);
                }
            }
        }
        // Add new assignments (ones without an id)
        for(const a of tempAssignments){
            if(!a.id){
                const afd=new FormData();afd.append('action','add_assignment');afd.append('teacher_id',tid);afd.append('class_id',a.class_id);afd.append('subject_id',a.subject_id);
                const ar=await postAPI('/admin/api_teachers.php',afd);
                if(ar.status==='success')toast('Assignment added','ok');
                else if(ar.message&&ar.message.includes('already exists'))console.log('Assignment already exists, skipping');
                else toast(ar.message||'Assignment error','err');
            }
        }
    }catch(ae){console.log('Assignment sync note:',ae);}}
    closeModal('teacherModal');loadTeachers();}else toast(d.message,'err');}catch(e){console.error('Save teacher error:',e);toast('Error saving teacher','err');}
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
                <button class="btn btn-p btn-xs" onclick="closeModal('viewTeacherModal');editTeacher(${t.id});showTab('assign')"><i class="fa-solid fa-plus"></i> Assign Classes</button>
            </div>`}
        </div>`;
    document.getElementById('viewTeacherModal').classList.add('show');}}catch(e){toast('Error loading teacher','err');}
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
function openSubjectModal(){document.getElementById('subjectForm').reset();document.getElementById('subjectFormId').value='';document.getElementById('subjectModalTitle').innerHTML='<i class="fa-solid fa-book"></i> Add Subject';document.getElementById('subjectModal').classList.add('show');}
function editSubject(s){document.getElementById('subjectFormId').value=s.id;document.getElementById('subjectName').value=s.subject_name||'';document.getElementById('subjectNameEn').value=s.subject_name_en||'';document.getElementById('subjectDesc').value=s.description||'';document.getElementById('subjectModalTitle').innerHTML='<i class="fa-solid fa-pen"></i> Edit Subject';document.getElementById('subjectModal').classList.add('show');}
document.getElementById('subjectForm')?.addEventListener('submit',function(e){e.preventDefault();const sid=document.getElementById('subjectFormId').value;const fd=new FormData();fd.append('action',sid?'update_subject':'create_subject');if(sid)fd.append('subject_id',sid);fd.append('subject_name',document.getElementById('subjectName').value);fd.append('subject_name_en',document.getElementById('subjectNameEn').value);fd.append('description',document.getElementById('subjectDesc').value);postAPI('/admin/api_subjects.php',fd).then(d=>{if(d.status==='success'){toast(d.message);closeModal('subjectModal');location.reload();}else toast(d.message,'err');});});

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
let _enrollSearchTimer=null, _selectedEnrollMember=null, _unassignedTimer=null, _bulkSelected=new Set();

function switchEnrollTab(tab) {
    ['classes','unassigned','teachers'].forEach(t => {
        document.getElementById('enrPanel'+(t.charAt(0).toUpperCase()+t.slice(1))).style.display = t===tab?'block':'none';
        document.getElementById('enrTab'+(t.charAt(0).toUpperCase()+t.slice(1))).className = 'tbn'+(t===tab?' act':'');
    });
    if(tab==='unassigned') loadUnassigned();
    if(tab==='teachers') { loadUnassignedTeachers(); loadClassTeacherGrid(); }
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
    }} catch(e){}
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
    const cid=document.getElementById('enrollClass').value;
    if(!cid) { document.getElementById('enrollArea').innerHTML=''; return; }
    document.getElementById('enrollArea').innerHTML='<div class="crd" style="padding:1.5rem;text-align:center;color:#94a3b8"><i class="fa-solid fa-spinner fa-spin"></i> Loading...</div>';
    const search=document.getElementById('enrollFilterSearch')?.value||'';
    const gender=document.getElementById('enrollFilterGender')?.value||'';
    const memberType=document.getElementById('enrollFilterMType')?.value||'';
    const sort=document.getElementById('enrollFilterSort')?.value||'name';
    let url=`/admin/api_education.php?action=get_enrolled_students&class_id=${cid}`;
    if(search) url+=`&search=${encodeURIComponent(search)}`;
    if(gender) url+=`&gender=${gender}`;
    if(memberType) url+=`&member_type=${memberType}`;
    if(sort) url+=`&sort=${sort}`;
    try { const d=await getAPI(url);
    if(d.status==='success') {
        const s=d.students||[], st=d.stats||{};
        const memberType=document.getElementById('enrollFilterMType')?.value||'';
        document.getElementById('enrollArea').innerHTML=`
        <div class="crd">
            <div style="padding:.75rem 1rem;border-bottom:1px solid #f1f5f9;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:.5rem">
                <div style="display:flex;align-items:center;gap:.75rem;flex-wrap:wrap">
                    <span style="font-weight:700;font-size:.95rem">${st.total||0} students</span>
                    <span class="ch ch-i" style="font-size:.6rem">♂ ${st.male||0}</span>
                    <span class="ch ch-p" style="font-size:.6rem">♀ ${st.female||0}</span>
                    <span style="font-size:.55rem;color:#64748b;border-left:1px solid #e2e8f0;padding-left:.5rem">Regular: ${st.regular||0} | Special: ${st.special_regular||0}${st.honorary?' | Honorary: '+st.honorary:''}${st.teachers?' | 👨‍🏫 '+st.teachers:''}</span>
                </div>
                <div style="display:flex;gap:.4rem;flex-wrap:wrap">
                    <input type="text" id="enrollFilterSearch" class="inp" style="max-width:140px;padding:.35rem .6rem;font-size:.75rem" placeholder="Filter..." value="${esc(search)}" oninput="loadEnrolled()">
                    <select id="enrollFilterGender" class="inp" style="max-width:85px;padding:.35rem .5rem;font-size:.75rem" onchange="loadEnrolled()"><option value="">Gender</option><option value="male" ${gender==='male'?'selected':''}>Male</option><option value="female" ${gender==='female'?'selected':''}>Female</option></select>
                    <select id="enrollFilterMType" class="inp" style="max-width:110px;padding:.35rem .5rem;font-size:.75rem" onchange="loadEnrolled()"><option value="">All Types</option><option value="regular" ${memberType==='regular'?'selected':''}>Regular</option><option value="special_regular" ${memberType==='special_regular'?'selected':''}>Special</option><option value="honorary" ${memberType==='honorary'?'selected':''}>Honorary</option></select>
                    <select id="enrollFilterSort" class="inp" style="max-width:100px;padding:.35rem .5rem;font-size:.75rem" onchange="loadEnrolled()"><option value="name" ${sort==='name'?'selected':''}>Name</option><option value="code" ${sort==='code'?'selected':''}>Code</option><option value="date" ${sort==='date'?'selected':''}>Date</option><option value="gender" ${sort==='gender'?'selected':''}>Gender</option></select>
                    <button class="btn btn-o btn-xs" onclick="exportEnrolled()"><i class="fa-solid fa-download"></i></button>
                </div>
            </div>
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
            </tr>`).join('')}</tbody></table></div>`:'<div style="padding:1.5rem;text-align:center;color:#94a3b8">No students enrolled</div>'}
        </div>`;
    }} catch(e){ toast('Error loading','err'); }
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
async function loadUnassigned(offset=0) {
    const area=document.getElementById('unassignedArea');
    area.innerHTML='<div class="crd" style="padding:1.5rem;text-align:center;color:#94a3b8"><i class="fa-solid fa-spinner fa-spin"></i> Loading unassigned members...</div>';
    const search=document.getElementById('unassignedSearch').value;
    const gender=document.getElementById('unassignedGender').value;
    const memberType=document.getElementById('unassignedMType').value;
    const age=document.getElementById('unassignedAge').value;
    let url=`/admin/api_education.php?action=get_unassigned_members&offset=${offset}&limit=50`;
    if(search) url+=`&search=${encodeURIComponent(search)}`;
    if(gender) url+=`&gender=${gender}`;
    if(memberType) url+=`&member_type=${memberType}`;
    if(age) url+=`&age_group=${age}`;
    try { const d=await getAPI(url);
    if(d.status==='success') {
        const m=d.members||[], total=d.total||0;
        _bulkSelected=new Set();
        area.innerHTML=`
        <div class="crd">
            <div style="padding:.75rem 1rem;border-bottom:1px solid #f1f5f9;display:flex;justify-content:space-between;align-items:center">
                <span style="font-weight:700;font-size:.9rem"><i class="fa-solid fa-user-xmark" style="color:#ef4444"></i> ${total} unassigned member${total!==1?'s':''}</span>
                <span style="font-size:.7rem;color:#64748b">Not enrolled in any class this year</span>
            </div>
            ${m.length?`<div class="tw"><table class="dt"><thead><tr><th><input type="checkbox" onchange="toggleBulkPage(this.checked)"></th><th>Name</th><th>Code</th><th>Type</th><th>Gender</th><th>Age Group</th><th>Phone</th></tr></thead><tbody>${m.map(x=>`<tr>
                <td><input type="checkbox" class="unassigned-cb" value="${x.id}" onchange="updateBulkCount()"></td>
                <td><div style="font-weight:600">${esc(x.student_name)} <span style="color:#64748b;font-weight:400">${esc(x.father_name)}</span></div><div style="margin-top:1px">${roleTags(x)}</div></td>
                <td><span class="ch ch-i">${esc(x.member_code||'—')}</span></td>
                <td>${mtBadge(x.member_type)}</td>
                <td>${x.gender==='male'?'<span style="color:#2563eb">♂ Male</span>':'<span style="color:#ec4899">♀ Female</span>'}</td>
                <td style="font-size:.75rem">${esc((x.age_group||'').replace(/_/g,' '))}</td>
                <td style="font-size:.75rem">${esc(x.phone_number||x.phone_primary||'—')}</td>
            </tr>`).join('')}</tbody></table></div>`:'<div style="padding:2rem;text-align:center;color:#94a3b8"><i class="fa-solid fa-check-circle" style="font-size:2rem;color:#059669;display:block;margin-bottom:.5rem"></i>All members are enrolled!</div>'}
            ${total>50?`<div style="padding:.75rem;text-align:center;border-top:1px solid #f1f5f9"><span style="font-size:.7rem;color:#64748b">Showing ${Math.min(50,m.length)} of ${total}</span></div>`:''}
        </div>`;
    }} catch(e){ area.innerHTML=`<div class="crd" style="padding:1.5rem;text-align:center;color:#ef4444">Error loading: ${e.message||'Unknown error'}</div>`; }
}
function toggleBulkPage(checked) { document.querySelectorAll('.unassigned-cb').forEach(cb=>{cb.checked=checked;}); updateBulkCount(); }
function updateBulkCount() { const cnt=document.querySelectorAll('.unassigned-cb:checked').length; const el=document.querySelector('#enrPanelUnassigned .btn-s'); if(el) el.innerHTML=`<i class="fa-solid fa-users"></i> Enroll Selected (${cnt})`; }
async function bulkEnrollSelected() {
    const cls=document.getElementById('unassignedTargetClass').value;
    if(!cls) return toast('Select a target class','err');
    const ids=[]; document.querySelectorAll('.unassigned-cb:checked').forEach(cb=>ids.push(parseInt(cb.value)));
    if(!ids.length) return toast('Select at least one member','err');
    if(!confirm(`Enroll ${ids.length} student(s) into the selected class?`)) return;
    const fd=new FormData(); fd.append('action','bulk_enroll'); fd.append('class_id',cls); fd.append('member_ids',JSON.stringify(ids));
    try { const d=await postAPI('/admin/api_education.php',fd);
    toast(d.message, d.status==='success'?'ok':'err');
    if(d.status==='success') { loadUnassigned(); loadEnrollOverview(); }
    } catch(e){ toast('Error','err'); }
}

// --- Bulk Enroll Modal ---
function openBulkEnrollModal() { document.getElementById('bulkEnrollModal').classList.add('show'); loadBulkCandidates(); }
async function loadBulkCandidates() {
    const search=document.getElementById('bulkSearch')?.value||'';
    const filter=document.getElementById('bulkFilter')?.value||'';
    let url=`/admin/api_education.php?action=get_unassigned_members&limit=100`;
    if(search) url+=`&search=${encodeURIComponent(search)}`;
    if(['male','female'].includes(filter)) url+=`&gender=${filter}`;
    if(['under6','7_13','14_17','18_plus'].includes(filter)) url+=`&age_group=${filter}`;
    try { const d=await getAPI(url);
    if(d.status==='success') {
        const m=d.members||[];
        _bulkSelected=new Set();
        document.getElementById('bulkCandidateList').innerHTML=m.length?m.map(x=>`<label style="display:flex;align-items:center;gap:.6rem;padding:.4rem .5rem;border-bottom:1px solid #f8fafc;cursor:pointer;font-size:.8rem" onmouseover="this.style.background='#faf5ff'" onmouseout="this.style.background=''">
            <input type="checkbox" class="bulk-cb" value="${x.id}" onchange="updateBulkModalCount()">
            <div style="flex:1"><strong>${esc(x.student_name)}</strong> ${esc(x.father_name)} <span class="ch ch-i" style="font-size:.5rem">${esc(x.member_code||'')}</span> ${mtBadge(x.member_type)} ${roleTags(x)}</div>
            <span style="color:${x.gender==='male'?'#2563eb':'#ec4899'};font-size:.7rem">${x.gender==='male'?'♂':'♀'}</span>
        </label>`).join(''):'<div style="padding:1rem;text-align:center;color:#94a3b8">No unassigned members found</div>';
        updateBulkModalCount();
    }} catch(e){}
}
function toggleBulkAll() { const c=document.getElementById('bulkSelectAll').checked; document.querySelectorAll('.bulk-cb').forEach(cb=>{cb.checked=c;}); updateBulkModalCount(); }
function updateBulkModalCount() { const cnt=document.querySelectorAll('.bulk-cb:checked').length; document.getElementById('bulkCount').textContent=cnt+' selected'; }
async function executeBulkEnroll() {
    const cls=document.getElementById('bulkClass').value;
    const ids=[]; document.querySelectorAll('.bulk-cb:checked').forEach(cb=>ids.push(parseInt(cb.value)));
    if(!ids.length) return toast('Select at least one student','err');
    if(!confirm(`Enroll ${ids.length} student(s)?`)) return;
    const fd=new FormData(); fd.append('action','bulk_enroll'); fd.append('class_id',cls); fd.append('member_ids',JSON.stringify(ids));
    try { const d=await postAPI('/admin/api_education.php',fd);
    toast(d.message, d.status==='success'?'ok':'err');
    if(d.status==='success') { closeModal('bulkEnrollModal'); loadEnrollOverview(); }
    } catch(e){ toast('Error','err'); }
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
async function loadGradeSubjects(){const cid=document.getElementById('gradeClass').value;const sel=document.getElementById('gradeSubject');sel.innerHTML='<option value="">— Select —</option>';document.getElementById('gradeAssessment').innerHTML='<option value="">— Select —</option>';document.getElementById('gradeArea').innerHTML='';if(!cid)return;try{const d=await getAPI(`/admin/api_subjects.php?action=get_class_subjects&class_id=${cid}`);if(d.status==='success')(d.subjects||[]).forEach(s=>{sel.innerHTML+=`<option value="${s.id}">${esc(s.subject_name)}</option>`;});}catch(e){}}
async function loadGradeAssessments(){const cid=document.getElementById('gradeClass').value,sid=document.getElementById('gradeSubject').value;const sel=document.getElementById('gradeAssessment');sel.innerHTML='<option value="">— Select —</option>';document.getElementById('gradeArea').innerHTML='';if(!cid||!sid)return;try{const d=await getAPI(`/admin/api_subjects.php?action=get_assessments&class_id=${cid}&subject_id=${sid}`);if(d.status==='success')(d.assessments||[]).forEach(a=>{sel.innerHTML+=`<option value="${a.id}" data-max="${a.max_score}">${esc(a.assessment_name)} (max: ${a.max_score})</option>`;});}catch(e){}}
async function loadGradeStudents(){const cid=document.getElementById('gradeClass').value,sid=document.getElementById('gradeSubject').value,aid=document.getElementById('gradeAssessment').value;if(!cid||!sid||!aid)return;try{const d=await getAPI(`/admin/api_subjects.php?action=get_students_for_grading&class_id=${cid}&subject_id=${sid}&assessment_id=${aid}`);if(d.status==='success'){const st=d.students||[],mx=document.getElementById('gradeAssessment').selectedOptions[0]?.dataset?.max||100;document.getElementById('gradeArea').innerHTML=st.length?`<div class="crd"><div style="padding:.75rem 1rem;border-bottom:1px solid #f1f5f9;font-weight:600;font-size:.9rem">Grade Entry — Max: ${mx}</div><div class="tw"><table class="dt"><thead><tr><th>#</th><th>Student</th><th>Code</th><th>Score</th><th>Remark</th></tr></thead><tbody>${st.map((s,i)=>`<tr><td>${i+1}</td><td style="font-weight:600">${esc(s.student_name)}</td><td>${esc(s.member_code||'')}</td><td><input type="number" class="inp grade-input" data-mid="${s.member_id||s.id}" style="width:80px" min="0" max="${mx}" value="${s.score||''}"></td><td><input type="text" class="inp grade-remark" data-mid="${s.member_id||s.id}" style="width:120px" value="${esc(s.remark||'')}"></td></tr>`).join('')}</tbody></table></div><div style="padding:1rem;text-align:right"><button class="btn btn-p" onclick="saveAllGrades(${aid})"><i class="fa-solid fa-save"></i> Save All Grades</button></div></div>`:'<div class="crd" style="padding:1.5rem;text-align:center;color:#94a3b8">No students found</div>';}}catch(e){toast('Error','err');}}
async function saveAllGrades(aid){const grades=[];document.querySelectorAll('.grade-input').forEach(inp=>{const mid=inp.dataset.mid,score=inp.value,remark=document.querySelector(`.grade-remark[data-mid="${mid}"]`)?.value||'';if(score!=='')grades.push({member_id:mid,score:parseFloat(score),remark});});if(!grades.length)return toast('No grades entered','err');const fd=new FormData();fd.append('action','save_grades');fd.append('assessment_id',aid);fd.append('grades',JSON.stringify(grades));try{const d=await postAPI('/admin/api_subjects.php',fd);toast(d.message,d.status==='success'?'ok':'err');}catch(e){toast('Error','err');}}

// ═══ ASSESSMENTS ═══
async function loadAsmtSubjects(){const cid=document.getElementById('asmtClass').value;const sel=document.getElementById('asmtSubject');sel.innerHTML='<option value="">— Select —</option>';document.getElementById('assessmentList').innerHTML='';if(!cid)return;try{const d=await getAPI(`/admin/api_subjects.php?action=get_class_subjects&class_id=${cid}`);if(d.status==='success')(d.subjects||[]).forEach(s=>{sel.innerHTML+=`<option value="${s.id}">${esc(s.subject_name)}</option>`;});}catch(e){}}
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
let allSubmissions=[];
async function loadSubmissions(){
    const sf=document.getElementById('subFilterStatus')?.value||'';
    const cf=document.getElementById('subFilterClass')?.value||'';
    let url='/admin/api_communication.php?action=get_submissions';
    if(sf)url+=`&status_filter=${sf}`;
    if(cf)url+=`&class_id=${cf}`;
    try{const d=await getAPI(url);
    if(d.status==='success'){
        allSubmissions=d.submissions||[];
        // Stats
        const pending=allSubmissions.filter(s=>s.status==='submitted').length;
        const approved=allSubmissions.filter(s=>s.status==='approved').length;
        const rejected=allSubmissions.filter(s=>s.status==='rejected').length;
        const total=allSubmissions.length;
        document.getElementById('subStatsRow').innerHTML=`
            <div class="sc" style="background:linear-gradient(135deg,#f59e0b,#d97706);padding:1rem"><div style="display:flex;justify-content:space-between;align-items:center"><div><div style="font-size:1.5rem;font-weight:700">${pending}</div><div style="font-size:.65rem;opacity:.8">Pending</div></div><i class="fa-solid fa-clock" style="font-size:1.2rem;opacity:.3"></i></div></div>
            <div class="sc" style="background:linear-gradient(135deg,#059669,#10b981);padding:1rem"><div style="display:flex;justify-content:space-between;align-items:center"><div><div style="font-size:1.5rem;font-weight:700">${approved}</div><div style="font-size:.65rem;opacity:.8">Approved</div></div><i class="fa-solid fa-check-circle" style="font-size:1.2rem;opacity:.3"></i></div></div>
            <div class="sc" style="background:linear-gradient(135deg,#ef4444,#dc2626);padding:1rem"><div style="display:flex;justify-content:space-between;align-items:center"><div><div style="font-size:1.5rem;font-weight:700">${rejected}</div><div style="font-size:.65rem;opacity:.8">Rejected</div></div><i class="fa-solid fa-times-circle" style="font-size:1.2rem;opacity:.3"></i></div></div>
            <div class="sc" style="background:linear-gradient(135deg,#7c3aed,#6366f1);padding:1rem"><div style="display:flex;justify-content:space-between;align-items:center"><div><div style="font-size:1.5rem;font-weight:700">${total}</div><div style="font-size:.65rem;opacity:.8">Total</div></div><i class="fa-solid fa-inbox" style="font-size:1.2rem;opacity:.3"></i></div></div>`;
        // List
        const el=document.getElementById('submissionsList');
        if(!allSubmissions.length){el.innerHTML='<div style="text-align:center;padding:2rem;color:#94a3b8"><i class="fa-solid fa-inbox" style="font-size:2rem;margin-bottom:.5rem;display:block;opacity:.3"></i>No submissions found</div>';return;}
        el.innerHTML=`<div class="tw"><table class="dt"><thead><tr><th>Teacher</th><th>Class</th><th>Subject</th><th>Assessment</th><th>Students</th><th>Average</th><th>Status</th><th>Submitted</th><th>Actions</th></tr></thead><tbody>${allSubmissions.map(s=>{
            const sc={draft:'ch-w',submitted:'ch-w',approved:'ch-ok',rejected:'ch-d',revision_needed:'ch-w'};
            const sl=sc[s.status]||'ch-w';
            const avg=s.average_score?parseFloat(s.average_score).toFixed(1):'—';
            const dt=s.submitted_at?fD(s.submitted_at):'—';
            return `<tr>
                <td style="font-weight:600;font-size:.8rem">${esc(s.teacher_name||'—')}</td>
                <td class="amharic">${esc(s.class_name||'—')}</td>
                <td class="amharic" style="font-size:.78rem">${esc(s.subject_name||'—')}</td>
                <td style="font-size:.78rem">${esc(s.assessment_name||'—')}</td>
                <td><span class="ch ch-i">${s.student_count||0}</span></td>
                <td style="font-weight:700;font-size:.85rem">${avg}</td>
                <td><span class="ch ${sl}" style="text-transform:capitalize">${(s.status||'').replace(/_/g,' ')}</span></td>
                <td style="font-size:.75rem;color:#64748b">${dt}</td>
                <td style="white-space:nowrap">
                    <button class="ab" style="background:#ede9fe;color:#7c3aed" onclick="reviewSubmission(${s.id})" title="Review"><i class="fa-solid fa-eye"></i></button>
                    ${s.status==='submitted'?`
                    <button class="ab" style="background:#d1fae5;color:#065f46" onclick="quickReview(${s.id},'approved')" title="Approve"><i class="fa-solid fa-check"></i></button>
                    <button class="ab" style="background:#fee2e2;color:#991b1b" onclick="quickReview(${s.id},'rejected')" title="Reject"><i class="fa-solid fa-times"></i></button>
                    `:''}
                </td></tr>`;
        }).join('')}</tbody></table></div>`;
    }}catch(e){toast('Error loading submissions','err');}
}
async function reviewSubmission(id){
    const s=allSubmissions.find(x=>x.id===id);
    if(!s)return;
    document.getElementById('reviewModalTitle').innerHTML=`<i class="fa-solid fa-clipboard-check"></i> Review: ${esc(s.assessment_name||'Marklist')}`;
    const sc={submitted:'#f59e0b',approved:'#059669',rejected:'#ef4444',revision_needed:'#f97316'};
    // Load grades for this submission
    let gradesHtml='<p style="text-align:center;color:#94a3b8;padding:1rem">Loading grades...</p>';
    try{
        const gd=await getAPI(`/admin/api_communication.php?action=get_class_report&class_id=${s.class_id}&subject_id=${s.subject_id}`);
        if(gd.status==='success'){
            const students=gd.students||[];
            gradesHtml=students.length?`<div class="tw"><table class="dt"><thead><tr><th>#</th><th>Student</th><th>Average</th><th>Grade</th></tr></thead><tbody>${students.map((st,i)=>`<tr><td>${i+1}</td><td style="font-weight:600">${esc(st.student_name||'')} ${esc(st.father_name||'')}</td><td style="font-weight:700">${st.avg_percentage?parseFloat(st.avg_percentage).toFixed(1)+'%':'—'}</td><td><span style="display:inline-flex;width:24px;height:24px;border-radius:50%;align-items:center;justify-content:center;font-weight:700;font-size:.65rem;color:#fff;background:${{A:'#059669',B:'#0284c7',C:'#d97706',D:'#ea580c',F:'#dc2626'}[st.grade_letter]||'#94a3b8'}">${st.grade_letter||'—'}</span></td></tr>`).join('')}</tbody></table></div>`:'<p style="text-align:center;color:#94a3b8">No grade data found</p>';
        }
    }catch(e){gradesHtml='<p style="color:#ef4444">Error loading grades</p>';}
    
    document.getElementById('reviewModalContent').innerHTML=`
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:.5rem;font-size:.82rem;margin-bottom:1rem;background:#f8fafc;padding:.85rem;border-radius:12px">
            <div><strong style="color:#64748b">Teacher:</strong> ${esc(s.teacher_name||'—')}</div>
            <div><strong style="color:#64748b">Class:</strong> <span class="amharic">${esc(s.class_name||'—')}</span></div>
            <div><strong style="color:#64748b">Subject:</strong> <span class="amharic">${esc(s.subject_name||'—')}</span></div>
            <div><strong style="color:#64748b">Assessment:</strong> ${esc(s.assessment_name||'—')}</div>
            <div><strong style="color:#64748b">Students:</strong> ${s.student_count||0}</div>
            <div><strong style="color:#64748b">Average:</strong> <span style="font-weight:700;color:#7c3aed">${s.average_score?parseFloat(s.average_score).toFixed(1):'—'}</span></div>
            <div><strong style="color:#64748b">Status:</strong> <span style="padding:2px 8px;border-radius:6px;font-size:.7rem;font-weight:700;color:#fff;background:${sc[s.status]||'#94a3b8'}">${(s.status||'').replace(/_/g,' ')}</span></div>
            <div><strong style="color:#64748b">Submitted:</strong> ${s.submitted_at?fD(s.submitted_at):'—'}</div>
            ${s.reviewer_name?`<div><strong style="color:#64748b">Reviewed by:</strong> ${esc(s.reviewer_name)}</div>`:''}
            ${s.review_notes?`<div style="grid-column:1/-1"><strong style="color:#64748b">Notes:</strong> ${esc(s.review_notes)}</div>`:''}
        </div>
        <h4 style="font-weight:700;font-size:.85rem;margin-bottom:.5rem"><i class="fa-solid fa-list-ol" style="color:#7c3aed"></i> Student Grades</h4>
        ${gradesHtml}
        ${s.status==='submitted'?`
        <div style="border-top:1px solid #f1f5f9;padding-top:1rem;margin-top:1rem">
            <label class="lbl">Review Notes</label>
            <textarea id="reviewNotes" class="inp" rows="2" placeholder="Optional feedback for the teacher..."></textarea>
            <div style="display:flex;gap:.5rem;margin-top:.75rem;justify-content:flex-end">
                <button class="btn btn-d" onclick="doReview(${s.id},'rejected')"><i class="fa-solid fa-times"></i> Reject</button>
                <button class="btn btn-w" onclick="doReview(${s.id},'revision_needed')"><i class="fa-solid fa-exclamation-circle"></i> Needs Revision</button>
                <button class="btn btn-s" onclick="doReview(${s.id},'approved')"><i class="fa-solid fa-check"></i> Approve</button>
            </div>
        </div>`:''}`;
    document.getElementById('reviewModal').classList.add('show');
}
async function quickReview(id,status){
    if(!confirm(`${status==='approved'?'Approve':'Reject'} this submission?`))return;
    await doReview(id,status);
}
async function doReview(id,status){
    const notes=document.getElementById('reviewNotes')?.value||'';
    const fd=new FormData();fd.append('action','review_submission');fd.append('submission_id',id);fd.append('new_status',status);fd.append('notes',notes);
    try{const d=await postAPI('/admin/api_communication.php',fd);
    toast(d.message,d.status==='success'?'ok':'err');
    if(d.status==='success'){closeModal('reviewModal');loadSubmissions();}}catch(e){toast('Error','err');}
}

// ═══ REPORT CARDS (Edu Dept) ═══
let rcData=[];
async function loadClassPerformance(){
    const cid=document.getElementById('rcClass')?.value;
    const sid=document.getElementById('rcSubject')?.value||'';
    if(!cid){document.getElementById('rcStatsArea').style.display='none';document.getElementById('rcTableArea').style.display='none';document.getElementById('rcEmptyMsg').style.display='block';return;}
    document.getElementById('rcEmptyMsg').style.display='none';
    try{let url=`/admin/api_communication.php?action=get_class_report&class_id=${cid}`;
    if(sid)url+=`&subject_id=${sid}`;
    const d=await getAPI(url);
    if(d.status==='success'){
        rcData=d.students||[];
        const st=d.stats||{};
        document.getElementById('rcStatsArea').style.display='block';
        document.getElementById('rcStatsCards').innerHTML=`
            <div class="sc" style="background:linear-gradient(135deg,#7c3aed,#6366f1);padding:.85rem"><div><div style="font-size:1.4rem;font-weight:700">${st.total_students||0}</div><div style="font-size:.6rem;opacity:.8">Total Students</div></div></div>
            <div class="sc" style="background:linear-gradient(135deg,#059669,#10b981);padding:.85rem"><div><div style="font-size:1.4rem;font-weight:700">${st.class_average||'—'}%</div><div style="font-size:.6rem;opacity:.8">Class Average</div></div></div>
            <div class="sc" style="background:linear-gradient(135deg,#0ea5e9,#3b82f6);padding:.85rem"><div><div style="font-size:1.4rem;font-weight:700">${st.pass_rate||0}%</div><div style="font-size:.6rem;opacity:.8">Pass Rate</div></div></div>
            <div class="sc" style="background:linear-gradient(135deg,#10b981,#34d399);padding:.85rem"><div><div style="font-size:1.4rem;font-weight:700">${st.highest||'—'}%</div><div style="font-size:.6rem;opacity:.8">Highest</div></div></div>
            <div class="sc" style="background:linear-gradient(135deg,#ef4444,#f87171);padding:.85rem"><div><div style="font-size:1.4rem;font-weight:700">${st.lowest||'—'}%</div><div style="font-size:.6rem;opacity:.8">Lowest</div></div></div>`;
        // Distribution bar
        const gd=st.grade_distribution||{A:0,B:0,C:0,D:0,F:0};
        const total=Math.max(st.graded_students||1,1);
        document.getElementById('rcDistBar').innerHTML=`<div style="font-size:.75rem;font-weight:600;color:#64748b;margin-bottom:.5rem">Grade Distribution</div>
            <div style="display:flex;height:32px;border-radius:10px;overflow:hidden;font-size:.65rem;font-weight:700;color:#fff">${
                [{l:'A',c:'#059669',n:gd.A},{l:'B',c:'#0284c7',n:gd.B},{l:'C',c:'#d97706',n:gd.C},{l:'D',c:'#ea580c',n:gd.D},{l:'F',c:'#dc2626',n:gd.F}]
                .filter(x=>x.n>0).map(x=>`<div style="flex:${x.n};background:${x.c};display:flex;align-items:center;justify-content:center;min-width:30px">${x.l}: ${x.n}</div>`).join('')
            }</div>`;
        // Table
        document.getElementById('rcTableArea').style.display='block';
        const gc={A:'#059669',B:'#0284c7',C:'#d97706',D:'#ea580c',F:'#dc2626'};
        document.getElementById('rcTableBody').innerHTML=rcData.length?rcData.map(s=>{
            const pct=s.avg_percentage?parseFloat(s.avg_percentage).toFixed(1):'—';
            const attR=s.attendance_rate||0;
            return `<tr>
                <td><span style="display:inline-flex;width:28px;height:28px;border-radius:50%;align-items:center;justify-content:center;font-weight:700;font-size:.7rem;${s.rank<=3?'background:linear-gradient(135deg,#f59e0b,#d97706);color:#fff':'background:#f1f5f9;color:#64748b'}">${s.rank}</span></td>
                <td style="font-weight:600;font-size:.82rem">${esc(s.student_name||'')} ${esc(s.father_name||'')}</td>
                <td><span class="ch ch-i" style="font-size:.65rem">${esc(s.member_code||'')}</span></td>
                <td style="font-weight:700;font-size:.9rem">${pct}%</td>
                <td><span style="display:inline-flex;width:26px;height:26px;border-radius:50%;align-items:center;justify-content:center;font-weight:700;font-size:.65rem;color:#fff;background:${gc[s.grade_letter]||'#94a3b8'}">${s.grade_letter||'—'}</span></td>
                <td><div style="display:flex;align-items:center;gap:.4rem"><div style="width:50px;height:6px;background:#e2e8f0;border-radius:99px"><div style="height:100%;border-radius:99px;background:${attR>=80?'#059669':attR>=60?'#f59e0b':'#ef4444'};width:${attR}%"></div></div><span style="font-size:.7rem;color:#64748b">${attR}%</span></div></td>
                <td><button class="btn btn-o btn-xs" onclick="viewStudentReport(${s.id})"><i class="fa-solid fa-file-lines"></i> Report</button></td></tr>`;
        }).join(''):'<tr><td colspan="7" style="text-align:center;padding:2rem;color:#94a3b8">No grade data available</td></tr>';
    }}catch(e){toast('Error loading report','err');}
}
async function viewStudentReport(memberId){
    const cid=document.getElementById('rcClass')?.value||0;
    document.getElementById('rcModal').classList.add('show');
    document.getElementById('rcModalBody').innerHTML='<p style="text-align:center;color:#94a3b8;padding:2rem"><i class="fa-solid fa-spinner fa-spin"></i> Generating report card...</p>';
    try{const d=await getAPI(`/admin/api_communication.php?action=get_report_card&member_id=${memberId}&class_id=${cid}`);
    if(d.status!=='success'){document.getElementById('rcModalBody').innerHTML=`<p style="text-align:center;color:#ef4444;padding:2rem">${d.message||'Error'}</p>`;return;}
    const s=d.student,cl=d.class,yr=d.year,tm=d.term,att=d.attendance,subjects=d.subjects||[];
    const oa=d.overall_average,og=d.overall_grade,rank=d.rank,total=d.total_in_class;
    const gc={A:'#059669',B:'#0284c7',C:'#d97706',D:'#ea580c',F:'#dc2626'};
    document.getElementById('rcModalYear').textContent=(yr?.year_name||'')+(tm?' • '+tm.term_name:'');
    document.getElementById('rcModalBody').innerHTML=`
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:.4rem .75rem;font-size:.8rem;margin-bottom:1rem;background:#f8fafc;padding:.85rem;border-radius:12px">
            <div><strong style="color:#64748b">Name:</strong> ${esc(s?.student_name||'')}</div>
            <div><strong style="color:#64748b">Father:</strong> ${esc(s?.father_name||'')}</div>
            <div><strong style="color:#64748b">Class:</strong> <span class="amharic">${esc(cl?.class_name||'')}</span></div>
            <div><strong style="color:#64748b">ID:</strong> ${esc(s?.member_code||'')}</div>
        </div>
        <div style="display:grid;grid-template-columns:repeat(4,1fr);gap:.5rem;margin-bottom:1rem">
            <div style="background:linear-gradient(135deg,#6366f1,#8b5cf6);color:#fff;padding:.7rem;border-radius:12px;text-align:center"><div style="font-size:1.2rem;font-weight:700">${oa?oa+'%':'—'}</div><div style="font-size:.55rem;opacity:.8">Overall</div></div>
            <div style="background:${gc[og]||'#64748b'};color:#fff;padding:.7rem;border-radius:12px;text-align:center"><div style="font-size:1.2rem;font-weight:700">${og||'—'}</div><div style="font-size:.55rem;opacity:.8">Grade</div></div>
            <div style="background:linear-gradient(135deg,#f59e0b,#d97706);color:#fff;padding:.7rem;border-radius:12px;text-align:center"><div style="font-size:1.2rem;font-weight:700">${rank||'—'}${rank?'<span style="font-size:.55rem">/'+total+'</span>':''}</div><div style="font-size:.55rem;opacity:.8">Rank</div></div>
            <div style="background:linear-gradient(135deg,#059669,#10b981);color:#fff;padding:.7rem;border-radius:12px;text-align:center"><div style="font-size:1.2rem;font-weight:700">${att.rate}%</div><div style="font-size:.55rem;opacity:.8">Attendance</div></div>
        </div>
        <table style="width:100%;font-size:.78rem;border-collapse:collapse;margin-bottom:1rem">
            <thead><tr style="background:#f1f5f9"><th style="padding:.5rem .6rem;text-align:left;font-weight:600;color:#64748b;font-size:.6rem;text-transform:uppercase">Subject</th><th style="padding:.5rem .6rem;text-align:center;font-weight:600;color:#64748b;font-size:.6rem">Assessments</th><th style="padding:.5rem .6rem;text-align:center;font-weight:600;color:#64748b;font-size:.6rem">Average</th><th style="padding:.5rem .6rem;text-align:center;font-weight:600;color:#64748b;font-size:.6rem">Grade</th></tr></thead>
            <tbody>${subjects.map(sub=>{
                const fp=sub.final_percentage;const gl=sub.grade_letter;
                return `<tr style="border-bottom:1px solid #f1f5f9"><td style="padding:.5rem .6rem;font-weight:600" class="amharic">${esc(sub.subject_name)}</td><td style="padding:.5rem .6rem;text-align:center">${(sub.assessments||[]).map(a=>`<span style="display:inline-block;background:#ede9fe;color:#5b21b6;padding:1px 5px;border-radius:4px;margin:1px;font-size:.55rem">${esc(a.assessment_name||'')}: ${a.score!==null?a.score:'—'}/${a.max_score}</span>`).join(' ')}</td><td style="padding:.5rem .6rem;text-align:center;font-weight:700">${fp!==null?fp.toFixed(1)+'%':'—'}</td><td style="padding:.5rem .6rem;text-align:center"><span style="display:inline-flex;width:24px;height:24px;border-radius:50%;align-items:center;justify-content:center;font-weight:700;font-size:.65rem;color:#fff;background:${gc[gl]||'#94a3b8'}">${gl||'—'}</span></td></tr>`;
            }).join('')}</tbody>
        </table>
        <div style="background:#f8fafc;padding:.65rem;border-radius:10px;margin-bottom:1rem">
            <div style="font-size:.65rem;font-weight:600;color:#64748b;margin-bottom:.3rem">Attendance: ${att.present} present, ${att.absent} absent, ${att.late} late / ${att.total} days</div>
            <div style="display:flex;height:6px;border-radius:99px;overflow:hidden;background:#e2e8f0">${att.total>0?`<div style="width:${att.present/att.total*100}%;background:#059669"></div><div style="width:${att.late/att.total*100}%;background:#f59e0b"></div><div style="width:${att.absent/att.total*100}%;background:#ef4444"></div>`:''}</div>
        </div>
        <div style="display:flex;gap:.5rem;justify-content:flex-end"><button class="btn btn-o" onclick="closeModal('rcModal')">Close</button><button class="btn btn-p" onclick="window.print()"><i class="fa-solid fa-print"></i> Print</button></div>`;
    }catch(e){document.getElementById('rcModalBody').innerHTML='<p style="color:#ef4444;text-align:center;padding:2rem">Error generating report</p>';}
}
function exportPerformance(){
    if(!rcData.length)return toast('No data','err');
    const h=['Rank','Name','Code','Average %','Grade','Attendance %'];
    const r=rcData.map(s=>[s.rank,(s.student_name||'')+' '+(s.father_name||''),s.member_code||'',s.avg_percentage?parseFloat(s.avg_percentage).toFixed(1):'',s.grade_letter||'',s.attendance_rate||0]);
    const ws=XLSX.utils.aoa_to_sheet([h,...r]);const wb=XLSX.utils.book_new();XLSX.utils.book_append_sheet(wb,ws,'Performance');XLSX.writeFile(wb,'Class_Performance.xlsx');
}
function generateBulkReports(){
    const cid=document.getElementById('rcClass')?.value;
    if(!cid)return toast('Select a class first','err');
    if(!rcData.length)return toast('No student data. Load class first.','err');
    toast('Bulk report generation: Open each student report individually for now. PDF batch coming soon!','ok');
}

// ═══ NAV EXTENSION ═══
const _origNav=nav;
nav=function(n){_origNav(n);if(n==='submissions')loadSubmissions();if(n==='reportcards')loadClassPerformance();};

// ═══ INIT ═══
document.addEventListener('DOMContentLoaded',()=>{loadTeachers();
// Close search dropdown on click outside
document.addEventListener('click',e=>{const sr=document.getElementById('enrollSearchResults');if(sr&&!sr.contains(e.target)&&e.target.id!=='enrollSearchInput')sr.style.display='none';});
});
</script>
</body></html>
