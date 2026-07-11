<?php
/**
 * ============================================================
 * Teacher Dashboard - Complete Version
 * ============================================================
 * Shows ONLY assigned classes and subjects
 * Features:
 * - View assigned classes
 * - Enter grades for assigned subjects
 * - Take attendance
 * - View student roster
 * ============================================================
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../backend/ethiopian_date.php';
require_once __DIR__ . '/../backend/calendar_system.php';

if (session_status() === PHP_SESSION_NONE) session_start();

if (empty($_SESSION['admin_logged_in']) || $_SESSION['admin_role'] !== 'teacher') {
    header('Location: ../index.php');
    exit;
}

$userId = $_SESSION['admin_id'] ?? 0;
$userName = $_SESSION['admin_full_name'] ?? $_SESSION['admin_username'] ?? 'Teacher';

// Get Ethiopian date
$now = new DateTime('now', new DateTimeZone('Africa/Addis_Ababa'));
$todayFormatted = wbws_format_date($now, 'long', $conn ?? null);
$todayDate = $now->format('Y-m-d');

// Get current academic year
$currentYear = null;
try {
    $result = $conn->query("SELECT * FROM academic_years WHERE is_current = 1 LIMIT 1");
    if ($result && $row = $result->fetch_assoc()) {
        $currentYear = $row;
    }
} catch (Exception $e) { /* table may not exist yet */ }
$yearId = $currentYear ? $currentYear['id'] : 0;

// Get teacher's assigned classes and subjects
$assignments = [];
try {
    $stmt = $conn->prepare("
        SELECT DISTINCT 
            ta.id as assignment_id,
            ta.class_id, 
            ta.subject_id,
            ta.is_class_teacher,
            c.class_name, 
            c.class_name_en,
            c.level_order,
            s.subject_name, 
            s.subject_name_en,
            (SELECT COUNT(*) FROM class_enrollments ce 
             WHERE ce.class_id = ta.class_id 
             AND ce.status = 'active'
             AND (ce.academic_year_id = ? OR ? = 0)) as student_count
        FROM teacher_assignments ta
        JOIN classes c ON ta.class_id = c.id
        JOIN subjects s ON ta.subject_id = s.id
        WHERE ta.teacher_id = ? 
        AND (ta.is_active = 1 OR ta.status = 'active')
        ORDER BY c.level_order, s.subject_name
    ");
    if ($stmt) {
        $stmt->bind_param("iii", $yearId, $yearId, $userId);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $assignments[] = $row;
        }
    }
} catch (Exception $e) { 
    // Tables may not exist yet - teacher will see empty dashboard
}

// Get unique classes for attendance
$myClasses = [];
foreach ($assignments as $a) {
    $myClasses[$a['class_id']] = [
        'id' => $a['class_id'],
        'class_name' => $a['class_name'],
        'class_name_en' => $a['class_name_en']
    ];
}
$myClasses = array_values($myClasses);

$csrfToken = generateCsrfToken();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Teacher Dashboard - <?= SCHOOL_NAME_SHORT_AM ?></title>
    <?= wbws_calendar_scripts($conn) ?>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <script>const CSRF_TOKEN = '<?= $csrfToken ?>';</script>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css"/>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Noto+Serif+Ethiopic:wght@400;600;700&family=Poppins:wght@300;400;600;700&display=swap');
        body { font-family: 'Poppins', sans-serif; background: #f8fafc; }
        .amharic { font-family: 'Noto Serif Ethiopic', serif; }
        .sidebar { background: linear-gradient(180deg, #0369a1, #0284c7); }
        .nav-link { display: flex; align-items: center; gap: 12px; padding: 12px 16px; border-radius: 12px; color: rgba(255,255,255,0.7); transition: all 0.2s; font-size: 13px; cursor: pointer; }
        .nav-link:hover, .nav-link.active { background: rgba(255,255,255,0.15); color: white; }
        .nav-link.active { font-weight: 600; }
        .card { background: white; border-radius: 16px; box-shadow: 0 1px 3px rgba(0,0,0,0.05); }
        .stat-card { border-radius: 16px; color: white; padding: 20px; }
        .btn { padding: 10px 18px; border-radius: 10px; font-size: 13px; font-weight: 500; cursor: pointer; transition: all 0.2s; display: inline-flex; align-items: center; gap: 8px; border: none; }
        .btn-primary { background: #0284c7; color: white; }
        .btn-primary:hover { background: #0369a1; }
        .btn-success { background: #059669; color: white; }
        .btn-success:hover { background: #047857; }
        .btn-secondary { background: #f1f5f9; color: #475569; }
        .btn-secondary:hover { background: #e2e8f0; }
        .btn-sm { padding: 6px 12px; font-size: 12px; }
        .form-input { width: 100%; padding: 10px 14px; border: 1px solid #e2e8f0; border-radius: 10px; font-size: 14px; }
        .form-input:focus { outline: none; border-color: #0284c7; box-shadow: 0 0 0 3px rgba(2,132,199,0.1); }
        .form-label { display: block; font-size: 12px; font-weight: 500; color: #64748b; margin-bottom: 6px; }
        .chip { display: inline-flex; align-items: center; padding: 4px 10px; border-radius: 20px; font-size: 11px; font-weight: 500; }
        .chip-success { background: #d1fae5; color: #065f46; }
        .chip-warning { background: #fef3c7; color: #92400e; }
        .chip-info { background: #dbeafe; color: #1e40af; }
        .chip-blue { background: #e0f2fe; color: #0369a1; }
        .table-container { overflow-x: auto; }
        .data-table { width: 100%; font-size: 13px; }
        .data-table th { background: #f8fafc; padding: 12px 16px; text-align: left; font-weight: 600; color: #64748b; font-size: 11px; text-transform: uppercase; }
        .data-table td { padding: 12px 16px; border-bottom: 1px solid #f1f5f9; }
        .data-table tr:hover td { background: #f0f9ff; }
        .section { display: none; }
        .section.active { display: block; }
        .toast { position: fixed; bottom: 24px; right: 24px; padding: 14px 20px; border-radius: 12px; color: white; z-index: 200; animation: slideIn 0.3s; }
        .toast-success { background: #059669; }
        .toast-error { background: #dc2626; }
        @keyframes slideIn { from { opacity: 0; transform: translateX(100px); } to { opacity: 1; transform: translateX(0); } }
        .grade-input { width: 70px; padding: 6px 10px; border: 1px solid #e2e8f0; border-radius: 8px; text-align: center; }
        .grade-input:focus { outline: none; border-color: #0284c7; }
        .att-btn { width: 40px; height: 40px; border-radius: 10px; border: 2px solid transparent; cursor: pointer; font-size: 16px; transition: all 0.2s; }
        .att-present { background: #d1fae5; color: #065f46; border-color: #a7f3d0; }
        .att-present.active { background: #059669; color: white; border-color: #059669; }
        .att-absent { background: #fee2e2; color: #991b1b; border-color: #fecaca; }
        .att-absent.active { background: #dc2626; color: white; border-color: #dc2626; }
        .att-late { background: #fef3c7; color: #92400e; border-color: #fde68a; }
        .att-late.active { background: #f59e0b; color: white; border-color: #f59e0b; }
        .assignment-card { transition: all 0.2s; cursor: pointer; }
        .assignment-card:hover { transform: translateY(-2px); box-shadow: 0 4px 12px rgba(0,0,0,0.1); }
    </style>
<?= wbws_calendar_scripts($conn ?? null) ?>
<link rel="stylesheet" href="/admin/css/mobile.css">
<?php include __DIR__ . "/../theme.php"; ?>
</head>
<body class="min-h-screen">
    <div class="flex min-h-screen">
        
        <!-- Sidebar -->
        <aside class="sidebar school-sidebar hidden md:flex flex-col w-64 p-4">
            <div class="flex items-center gap-3 mb-8 px-2">
                <div class="w-10 h-10 bg-white/20 rounded-xl flex items-center justify-center">
                    <i class="fa-solid fa-chalkboard-user text-white"></i>
                </div>
                <div>
                    <div class="text-white font-bold text-sm">Teacher Portal</div>
                    <div class="text-sky-200 text-xs amharic">የመምህር ፖርታል</div>
                </div>
            </div>
            
            <nav class="flex-1 space-y-1">
                <div class="nav-link active" onclick="showSection('dashboard')">
                    <i class="fa-solid fa-gauge-high w-5"></i> Dashboard
                </div>
                <div class="nav-link" onclick="showSection('grades')">
                    <i class="fa-solid fa-pen-to-square w-5"></i> Enter Grades
                </div>
                <div class="nav-link" onclick="showSection('attendance')">
                    <i class="fa-solid fa-clipboard-check w-5"></i> Take Attendance
                </div>
                <div class="nav-link" onclick="showSection('submissions')">
                    <i class="fa-solid fa-paper-plane w-5"></i> Submit Marklist
                </div>
                <div class="nav-link" onclick="showSection('reports')">
                    <i class="fa-solid fa-chart-bar w-5"></i> Report Cards
                </div>
                <div class="nav-link" onclick="showSection('students')">
                    <i class="fa-solid fa-users w-5"></i> My Students
                </div>
            </nav>
            
            <div class="mt-auto pt-4 border-t border-white/10">
                <div class="flex items-center gap-3 px-2 mb-3">
                    <div class="w-9 h-9 rounded-full bg-white flex items-center justify-center text-sm font-bold text-sky-700">
                        <?= strtoupper(substr($userName, 0, 1)) ?>
                    </div>
                    <div class="text-xs">
                        <div class="text-white font-medium truncate max-w-[140px]"><?= e($userName) ?></div>
                        <div class="text-sky-200 text-[10px] uppercase">Teacher</div>
                    </div>
                </div>
                <a href="/admin/logout.php" class="nav-link text-red-300 hover:bg-red-500/20">
                    <i class="fa-solid fa-power-off w-5"></i> Logout
                </a>
            </div>
        </aside>
        
        <!-- Main -->
        <div class="flex-1 flex flex-col">
            
            <!-- Header -->
            <header class="bg-gradient-to-r from-sky-700 to-sky-600 text-white px-4 py-4 flex items-center justify-between">
                <div class="flex items-center gap-4">
                    <div class="md:hidden w-10 h-10 bg-white/15 rounded-xl flex items-center justify-center">
                        <i class="fa-solid fa-chalkboard-user"></i>
                    </div>
                    <div>
                        <h1 class="text-lg font-bold">Welcome, <?= e($userName) ?></h1>
                        <p class="text-xs text-sky-100 amharic">እንኳን ደህና መጡ</p>
                    </div>
                </div>
                <div class="hidden sm:block text-right text-xs">
                    <div class="font-medium"><?= e($todayFormatted) ?></div>
                    <?php if ($currentYear): ?>
                    <div class="text-sky-100 amharic"><?= e($currentYear['year_name']) ?></div>
                    <?php endif; ?>
                </div>
            </header>
            
            <!-- Mobile Nav -->
            <div class="md:hidden flex overflow-x-auto gap-2 p-3 bg-white border-b">
                <button onclick="showSection('dashboard')" class="mobile-nav px-4 py-2 rounded-full text-xs font-medium bg-sky-100 text-sky-700">Dashboard</button>
                <button onclick="showSection('grades')" class="mobile-nav px-4 py-2 rounded-full text-xs font-medium bg-gray-100">Grades</button>
                <button onclick="showSection('attendance')" class="mobile-nav px-4 py-2 rounded-full text-xs font-medium bg-gray-100">Attendance</button>
                <button onclick="showSection('submissions')" class="mobile-nav px-4 py-2 rounded-full text-xs font-medium bg-gray-100">Submit</button>
                <button onclick="showSection('reports')" class="mobile-nav px-4 py-2 rounded-full text-xs font-medium bg-gray-100">Reports</button>
                <button onclick="showSection('students')" class="mobile-nav px-4 py-2 rounded-full text-xs font-medium bg-gray-100">Students</button>
            </div>
            
            <main class="flex-1 p-4 md:p-6 overflow-y-auto">
                <!-- Mobile Header -->
                <div class="wbws-mob-header">
                    <a href="/admin/dashboard.php" class="mob-back"><i class="fa-solid fa-arrow-left"></i></a>
                    <div class="mob-title">
                        <h1>Teacher Dashboard</h1>
                        <p class="mob-sub"><?= htmlspecialchars($userName) ?></p>
                    </div>
                    <div class="mob-avatar"><?= strtoupper(substr($userName, 0, 1)) ?></div>
                </div>
                
                <!-- DASHBOARD -->
                <section id="sec-dashboard" class="section active">
                    <?php if ($currentYear): ?>
                    <div class="card p-4 mb-6">
                        <div class="flex items-center justify-between">
                            <div>
                                <div class="text-xs text-slate-500 uppercase">Current Academic Year</div>
                                <div class="text-xl font-bold text-slate-800 amharic"><?= e($currentYear['year_name']) ?></div>
                            </div>
                            <span class="chip chip-blue"><i class="fa-solid fa-calendar mr-1"></i> Active</span>
                        </div>
                    </div>
                    <?php endif; ?>
                    
                    <!-- Stats -->
                    <div class="grid grid-cols-2 md:grid-cols-3 gap-4 mb-6">
                        <div class="stat-card bg-gradient-to-br from-sky-500 to-sky-600">
                            <div class="text-2xl font-bold"><?= count($assignments) ?></div>
                            <div class="text-sm opacity-80">My Assignments</div>
                        </div>
                        <div class="stat-card bg-gradient-to-br from-emerald-500 to-emerald-600">
                            <div class="text-2xl font-bold"><?= count($myClasses) ?></div>
                            <div class="text-sm opacity-80">Classes</div>
                        </div>
                        <div class="stat-card bg-gradient-to-br from-purple-500 to-purple-600">
                            <div class="text-2xl font-bold"><?= array_sum(array_column($assignments, 'student_count')) ?></div>
                            <div class="text-sm opacity-80">Total Students</div>
                        </div>
                    </div>
                    
                    <!-- My Assigned Classes -->
                    <h2 class="text-lg font-bold text-slate-800 mb-4">My Assigned Classes & Subjects</h2>
                    <?php if (!empty($assignments)): ?>
                    <div class="grid gap-3">
                        <?php foreach ($assignments as $a): ?>
                        <div class="assignment-card card p-4 flex flex-col md:flex-row md:items-center justify-between gap-3">
                            <div class="flex items-center gap-3">
                                <div class="w-12 h-12 bg-sky-100 rounded-xl flex items-center justify-center">
                                    <i class="fa-solid fa-chalkboard text-sky-600 text-lg"></i>
                                </div>
                                <div>
                                    <div class="font-semibold amharic"><?= e($a['class_name']) ?></div>
                                    <div class="text-sm text-slate-500"><?= e($a['subject_name']) ?></div>
                                </div>
                            </div>
                            <div class="flex items-center gap-3">
                                <span class="chip chip-info"><?= $a['student_count'] ?> students</span>
                                <div class="flex gap-2">
                                    <button onclick="goToGrades(<?= $a['class_id'] ?>, <?= $a['subject_id'] ?>)" class="btn btn-sm btn-primary">
                                        <i class="fa-solid fa-pen"></i> Grades
                                    </button>
                                    <button onclick="goToAttendance(<?= $a['class_id'] ?>)" class="btn btn-sm btn-secondary">
                                        <i class="fa-solid fa-check"></i> Attendance
                                    </button>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php else: ?>
                    <div class="card p-8 text-center">
                        <div class="w-16 h-16 bg-amber-100 rounded-full flex items-center justify-center mx-auto mb-4">
                            <i class="fa-solid fa-exclamation-triangle text-amber-500 text-2xl"></i>
                        </div>
                        <h3 class="font-semibold text-slate-800 mb-2">No Assignments Yet</h3>
                        <p class="text-slate-500 text-sm">You haven't been assigned to any classes yet. Please contact the Education Department to get your class assignments.</p>
                    </div>
                    <?php endif; ?>
                </section>
                
                <!-- GRADES -->
                <section id="sec-grades" class="section">
                    <div class="mb-4">
                        <h2 class="text-xl font-bold text-slate-800">Enter Grades</h2>
                        <p class="text-sm text-slate-500 amharic">የተማሪዎች ውጤት ማስገቢያ</p>
                    </div>
                    
                    <?php if (!empty($assignments)): ?>
                    <div class="card p-4 mb-4">
                        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                            <div>
                                <label class="form-label">Select Class-Subject</label>
                                <select id="gradeAssignmentSelect" class="form-input" onchange="loadAssessments()">
                                    <option value="">-- Select --</option>
                                    <?php foreach ($assignments as $a): ?>
                                    <option value="<?= $a['class_id'] ?>-<?= $a['subject_id'] ?>" 
                                            data-class="<?= $a['class_id'] ?>" 
                                            data-subject="<?= $a['subject_id'] ?>">
                                        <?= e($a['class_name']) ?> - <?= e($a['subject_name']) ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div>
                                <label class="form-label">Select Assessment</label>
                                <select id="gradeAssessmentSelect" class="form-input" onchange="loadStudentsForGrading()">
                                    <option value="">-- Select Assessment --</option>
                                </select>
                            </div>
                            <div class="flex items-end">
                                <button onclick="saveAllGrades()" class="btn btn-success w-full justify-center" id="saveGradesBtn" style="display:none;">
                                    <i class="fa-solid fa-save"></i> Save All Grades
                                </button>
                            </div>
                        </div>
                    </div>
                    
                    <div id="gradeEntryCard" style="display:none;">
                        <div class="card overflow-hidden">
                            <div class="p-4 border-b">
                                <h3 class="font-semibold" id="gradeEntryTitle">Enter Grades</h3>
                                <p class="text-sm text-slate-500" id="gradeEntrySubtitle"></p>
                            </div>
                            <div class="table-container">
                                <table class="data-table">
                                    <thead>
                                        <tr>
                                            <th>#</th>
                                            <th>Student Name</th>
                                            <th>Code</th>
                                            <th>Score (out of <span id="maxScoreHeader">100</span>)</th>
                                            <th>Remarks</th>
                                        </tr>
                                    </thead>
                                    <tbody id="gradeEntryBody"></tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                    
                    <div id="selectGradeMsg" class="card p-8 text-center text-slate-400">
                        <i class="fa-solid fa-clipboard-list text-3xl mb-2"></i>
                        <p>Select class-subject and assessment to enter grades</p>
                    </div>
                    <?php else: ?>
                    <div class="card p-8 text-center text-amber-600">
                        <i class="fa-solid fa-exclamation-triangle text-3xl mb-2"></i>
                        <p>You don't have any class assignments yet.</p>
                    </div>
                    <?php endif; ?>
                </section>
                
                <!-- ATTENDANCE -->
                <section id="sec-attendance" class="section">
                    <div class="mb-4">
                        <h2 class="text-xl font-bold text-slate-800">Take Attendance</h2>
                        <p class="text-sm text-slate-500 amharic">የቀን ቅጥር መውሰድ</p>
                    </div>
                    
                    <?php if (!empty($myClasses)): ?>
                    <div class="card p-4 mb-4">
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div>
                                <label class="form-label">Select Class</label>
                                <select id="attClassSelect" class="form-input" onchange="loadStudentsForAttendance()">
                                    <option value="">-- Select Class --</option>
                                    <?php foreach ($myClasses as $c): ?>
                                    <option value="<?= $c['id'] ?>"><?= e($c['class_name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div>
                                <label class="form-label">Date</label>
                                <input type="date" id="attDateInput" class="form-input" value="<?= $todayDate ?>" onchange="loadStudentsForAttendance()">
                            </div>
                        </div>
                    </div>
                    
                    <!-- Quick Actions -->
                    <div class="flex gap-2 mb-4" id="attQuickActions" style="display:none;">
                        <button onclick="markAllAttendance('present')" class="btn btn-sm btn-success"><i class="fa-solid fa-check-double"></i> All Present</button>
                        <button onclick="markAllAttendance('absent')" class="btn btn-sm" style="background:#fee2e2;color:#991b1b;"><i class="fa-solid fa-xmark"></i> All Absent</button>
                    </div>
                    
                    <div class="card" id="attendanceCard" style="display:none;">
                        <div class="p-4 border-b flex flex-col md:flex-row md:items-center justify-between gap-3">
                            <div>
                                <h3 class="font-semibold" id="attTitle">Attendance</h3>
                                <p class="text-sm text-slate-500" id="attSubtitle"></p>
                            </div>
                            <button onclick="saveAttendance()" class="btn btn-success">
                                <i class="fa-solid fa-save"></i> Save Attendance
                            </button>
                        </div>
                        <div class="table-container">
                            <table class="data-table">
                                <thead>
                                    <tr>
                                        <th>#</th>
                                        <th>Student Name</th>
                                        <th>Code</th>
                                        <th class="text-center">Status</th>
                                        <th>Note</th>
                                    </tr>
                                </thead>
                                <tbody id="attendanceBody"></tbody>
                            </table>
                        </div>
                    </div>
                    
                    <div id="selectAttMsg" class="card p-8 text-center text-slate-400">
                        <i class="fa-solid fa-clipboard-check text-3xl mb-2"></i>
                        <p>Select a class to take attendance</p>
                    </div>
                    <?php else: ?>
                    <div class="card p-8 text-center text-amber-600">
                        <i class="fa-solid fa-exclamation-triangle text-3xl mb-2"></i>
                        <p>You don't have any class assignments yet.</p>
                    </div>
                    <?php endif; ?>
                </section>
                
                <!-- STUDENTS -->
                <section id="sec-students" class="section">
                    <div class="mb-4">
                        <h2 class="text-xl font-bold text-slate-800">My Students</h2>
                        <p class="text-sm text-slate-500">View students in your classes</p>
                    </div>
                    
                    <?php if (!empty($myClasses)): ?>
                    <div class="card p-4 mb-4">
                        <div>
                            <label class="form-label">Select Class</label>
                            <select id="studentsClassSelect" class="form-input" onchange="loadClassStudents()">
                                <option value="">-- Select Class --</option>
                                <?php foreach ($myClasses as $c): ?>
                                <option value="<?= $c['id'] ?>"><?= e($c['class_name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    
                    <div class="card" id="studentsCard" style="display:none;">
                        <div class="p-4 border-b">
                            <h3 class="font-semibold" id="studentsTitle">Students</h3>
                            <p class="text-sm text-slate-500" id="studentsCount"></p>
                        </div>
                        <div class="table-container">
                            <table class="data-table">
                                <thead>
                                    <tr>
                                        <th>#</th>
                                        <th>Student Name</th>
                                        <th>Father's Name</th>
                                        <th>Code</th>
                                        <th>Gender</th>
                                    </tr>
                                </thead>
                                <tbody id="studentsBody"></tbody>
                            </table>
                        </div>
                    </div>
                    
                    <div id="selectStudentsMsg" class="card p-8 text-center text-slate-400">
                        <i class="fa-solid fa-users text-3xl mb-2"></i>
                        <p>Select a class to view students</p>
                    </div>
                    <?php else: ?>
                    <div class="card p-8 text-center text-amber-600">
                        <i class="fa-solid fa-exclamation-triangle text-3xl mb-2"></i>
                        <p>You don't have any class assignments yet.</p>
                    </div>
                    <?php endif; ?>
                </section>
                
                <!-- SUBMIT MARKLIST -->
                <section id="sec-submissions" class="section">
                    <div class="mb-4">
                        <h2 class="text-xl font-bold text-slate-800">Submit Marklist</h2>
                        <p class="text-sm text-slate-500 amharic">የውጤት ዝርዝር ለትምህርት ክፍል ማስረከብ</p>
                    </div>
                    
                    <?php if (!empty($assignments)): ?>
                    <div class="card p-4 mb-4">
                        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                            <div>
                                <label class="form-label">Class-Subject</label>
                                <select id="submitAssignmentSelect" class="form-input" onchange="loadSubmitAssessments()">
                                    <option value="">-- Select --</option>
                                    <?php foreach ($assignments as $a): ?>
                                    <option value="<?= $a['class_id'] ?>-<?= $a['subject_id'] ?>"><?= e($a['class_name']) ?> — <?= e($a['subject_name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div>
                                <label class="form-label">Assessment</label>
                                <select id="submitAssessmentSelect" class="form-input" onchange="loadSubmitStudents()">
                                    <option value="">-- Select --</option>
                                </select>
                            </div>
                            <div class="flex items-end">
                                <button onclick="submitMarklist()" class="btn btn-success w-full justify-center" id="submitBtn" style="display:none">
                                    <i class="fa-solid fa-paper-plane"></i> Submit to Edu Dept
                                </button>
                            </div>
                        </div>
                    </div>
                    
                    <div id="submitEntryCard" style="display:none" class="card overflow-hidden mb-4">
                        <div class="p-4 border-b" style="background:linear-gradient(135deg,#059669,#10b981);color:#fff">
                            <h3 class="font-semibold" id="submitTitle">Marklist</h3>
                            <p class="text-sm opacity-80" id="submitSubtitle"></p>
                        </div>
                        <div class="table-container">
                            <table class="data-table">
                                <thead><tr><th>#</th><th>Student Name</th><th>Code</th><th>Score</th><th>Remark</th></tr></thead>
                                <tbody id="submitEntryBody"></tbody>
                            </table>
                        </div>
                    </div>
                    
                    <!-- My Submissions History -->
                    <div class="card p-4">
                        <h3 class="font-semibold mb-3"><i class="fa-solid fa-history" style="color:#6366f1"></i> My Submissions</h3>
                        <div id="mySubmissionsList"><p class="text-center text-slate-400 py-4"><i class="fa-solid fa-spinner fa-spin"></i> Loading...</p></div>
                    </div>
                    
                    <?php else: ?>
                    <div class="card p-8 text-center text-amber-600">
                        <i class="fa-solid fa-exclamation-triangle text-3xl mb-2"></i>
                        <p>You don't have any class assignments yet.</p>
                    </div>
                    <?php endif; ?>
                </section>
                
                <!-- REPORT CARDS -->
                <section id="sec-reports" class="section">
                    <div class="mb-4">
                        <h2 class="text-xl font-bold text-slate-800">Student Report Cards</h2>
                        <p class="text-sm text-slate-500 amharic">የተማሪ ሪፖርት ካርድ</p>
                    </div>
                    
                    <?php if (!empty($myClasses)): ?>
                    <div class="card p-4 mb-4">
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div>
                                <label class="form-label">Select Class</label>
                                <select id="reportClassSelect" class="form-input" onchange="loadClassReport()">
                                    <option value="">-- Select Class --</option>
                                    <?php foreach ($myClasses as $c): ?>
                                    <option value="<?= $c['id'] ?>"><?= e($c['class_name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="flex items-end gap-2">
                                <button onclick="exportClassReport()" class="btn" style="background:#f1f5f9;color:#475569" id="exportReportBtn" style="display:none">
                                    <i class="fa-solid fa-download"></i> Export
                                </button>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Class Stats -->
                    <div id="classStatsArea" style="display:none" class="mb-4">
                        <div class="grid grid-cols-2 md:grid-cols-5 gap-3" id="classStatsCards"></div>
                    </div>
                    
                    <!-- Student Rankings -->
                    <div id="classReportArea" style="display:none" class="card overflow-hidden">
                        <div class="p-4 border-b" style="background:linear-gradient(135deg,#6366f1,#8b5cf6);color:#fff">
                            <h3 class="font-semibold" id="reportTitle">Class Performance</h3>
                            <p class="text-sm opacity-80" id="reportSubtitle"></p>
                        </div>
                        <div class="table-container">
                            <table class="data-table">
                                <thead><tr><th>Rank</th><th>Student</th><th>Code</th><th>Average</th><th>Grade</th><th>Attendance</th><th>Action</th></tr></thead>
                                <tbody id="classReportBody"></tbody>
                            </table>
                        </div>
                    </div>
                    
                    <div id="selectReportMsg" class="card p-8 text-center text-slate-400">
                        <i class="fa-solid fa-chart-bar text-3xl mb-2"></i>
                        <p>Select a class to view performance report</p>
                    </div>
                    
                    <?php else: ?>
                    <div class="card p-8 text-center text-amber-600">
                        <i class="fa-solid fa-exclamation-triangle text-3xl mb-2"></i>
                        <p>No classes assigned.</p>
                    </div>
                    <?php endif; ?>
                </section>
                
                <!-- REPORT CARD MODAL -->
                <div id="reportCardModal" style="display:none;position:fixed;inset:0;background:rgba(15,23,42,.7);backdrop-filter:blur(4px);z-index:100;overflow-y:auto;padding:1rem">
                    <div style="max-width:700px;width:100%;margin:1rem auto;background:#fff;border-radius:20px;overflow:hidden" id="reportCardContent"></div>
                </div>
        </div>
    </div>
    
    <div id="toastContainer"></div>
    
    <script>
        let currentAssessment = null;
        
        function showSection(name) {
            document.querySelectorAll('.section').forEach(s => s.classList.remove('active'));
            document.querySelectorAll('.nav-link').forEach(n => n.classList.remove('active'));
            document.getElementById('sec-' + name).classList.add('active');
            const _u=new URL(window.location);_u.searchParams.set('section',name);history.replaceState(null,'',_u);
        }
        // Restore section from URL on load
        {const _sp=new URLSearchParams(window.location.search).get('section');if(_sp)showSection(_sp);}
        
        function showToast(msg, type = 'success') {
            const toast = document.createElement('div');
            toast.className = `toast toast-${type}`;
            toast.innerHTML = `<i class="fa-solid fa-${type === 'success' ? 'check-circle' : 'exclamation-circle'} mr-2"></i>${msg}`;
            document.getElementById('toastContainer').appendChild(toast);
            setTimeout(() => { toast.style.opacity = '0'; setTimeout(() => toast.remove(), 300); }, 4000);
        }
        
        function escapeHtml(text) {
            if (!text) return '';
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }
        
        // Quick navigation from dashboard
        function goToGrades(classId, subjectId) {
            showSection('grades');
            const select = document.getElementById('gradeAssignmentSelect');
            if (select) {
                select.value = classId + '-' + subjectId;
                loadAssessments();
            }
        }
        
        function goToAttendance(classId) {
            showSection('attendance');
            const select = document.getElementById('attClassSelect');
            if (select) {
                select.value = classId;
                loadStudentsForAttendance();
            }
        }
        
        // ============================================================
        // GRADE ENTRY
        // ============================================================
        function loadAssessments() {
            const combo = document.getElementById('gradeAssignmentSelect').value;
            const select = document.getElementById('gradeAssessmentSelect');
            select.innerHTML = '<option value="">-- Select Assessment --</option>';
            document.getElementById('gradeEntryCard').style.display = 'none';
            document.getElementById('selectGradeMsg').style.display = 'block';
            document.getElementById('saveGradesBtn').style.display = 'none';
            
            if (!combo) return;
            
            const [classId, subjectId] = combo.split('-');
            
            fetch(`/admin/api_subjects.php?action=get_assessments&class_id=${classId}&subject_id=${subjectId}`)
                .then(r => r.json())
                .then(data => {
                    if (data.status === 'success') {
                        if (data.assessments.length === 0) {
                            select.innerHTML = '<option value="">No assessments configured</option>';
                        } else {
                            data.assessments.forEach(a => {
                                select.innerHTML += `<option value="${a.id}">${escapeHtml(a.assessment_name)} (${a.weight_percentage}%)</option>`;
                            });
                        }
                    }
                });
        }
        
        function loadStudentsForGrading() {
            const assessmentId = document.getElementById('gradeAssessmentSelect').value;
            if (!assessmentId) {
                document.getElementById('gradeEntryCard').style.display = 'none';
                document.getElementById('selectGradeMsg').style.display = 'block';
                document.getElementById('saveGradesBtn').style.display = 'none';
                return;
            }
            
            document.getElementById('selectGradeMsg').style.display = 'none';
            document.getElementById('gradeEntryCard').style.display = 'block';
            document.getElementById('saveGradesBtn').style.display = 'flex';
            
            fetch(`/admin/api_subjects.php?action=get_students_for_grading&assessment_id=${assessmentId}`)
                .then(r => r.json())
                .then(data => {
                    if (data.status === 'success') {
                        currentAssessment = data.assessment;
                        document.getElementById('gradeEntryTitle').textContent = data.assessment.assessment_name;
                        document.getElementById('gradeEntrySubtitle').textContent = `Max Score: ${data.assessment.max_score} | Weight: ${data.assessment.weight_percentage}%`;
                        document.getElementById('maxScoreHeader').textContent = data.assessment.max_score;
                        renderGradeEntryTable(data.students, data.assessment.max_score);
                    }
                });
        }
        
        function renderGradeEntryTable(students, maxScore) {
            const tbody = document.getElementById('gradeEntryBody');
            if (students.length === 0) {
                tbody.innerHTML = '<tr><td colspan="5" class="text-center text-slate-400 py-8">No students enrolled</td></tr>';
                return;
            }
            tbody.innerHTML = students.map((s, i) => `
                <tr>
                    <td>${i + 1}</td>
                    <td class="font-medium">${escapeHtml(s.student_name + ' ' + s.father_name)}</td>
                    <td><code class="text-xs bg-slate-100 px-2 py-1 rounded">${escapeHtml(s.member_code || '—')}</code></td>
                    <td><input type="number" class="grade-input" data-member-id="${s.member_id}" value="${s.score !== null ? s.score : ''}" min="0" max="${maxScore}" step="0.5" placeholder="—"></td>
                    <td><input type="text" class="form-input remarks-input" style="width:150px" data-member-id="${s.member_id}" value="${escapeHtml(s.remarks || '')}" placeholder="Remarks"></td>
                </tr>
            `).join('');
        }
        
        function saveAllGrades() {
            if (!currentAssessment) return;
            const grades = [];
            document.querySelectorAll('#gradeEntryBody .grade-input').forEach(input => {
                const memberId = input.dataset.memberId;
                const score = input.value;
                const remarksInput = document.querySelector(`.remarks-input[data-member-id="${memberId}"]`);
                const remarks = remarksInput ? remarksInput.value : '';
                grades.push({ member_id: memberId, score, remarks });
            });
            
            const formData = new FormData();
            formData.append('action', 'save_grades');
            formData.append('assessment_id', currentAssessment.id);
            formData.append('grades', JSON.stringify(grades));
            formData.append('csrf_token', CSRF_TOKEN);
            
            fetch('/admin/api_subjects.php', { method: 'POST', body: formData })
                .then(r => r.json())
                .then(data => {
                    showToast(data.message, data.status === 'success' ? 'success' : 'error');
                    if (data.status === 'success') loadStudentsForGrading();
                });
        }
        
        // ============================================================
        // ATTENDANCE
        // ============================================================
        let attendanceData = [];
        
        function loadStudentsForAttendance() {
            const classId = document.getElementById('attClassSelect').value;
            const date = document.getElementById('attDateInput').value;
            
            if (!classId) {
                document.getElementById('attendanceCard').style.display = 'none';
                document.getElementById('attQuickActions').style.display = 'none';
                document.getElementById('selectAttMsg').style.display = 'block';
                return;
            }
            
            document.getElementById('selectAttMsg').style.display = 'none';
            document.getElementById('attendanceCard').style.display = 'block';
            document.getElementById('attQuickActions').style.display = 'flex';
            
            fetch(`/admin/api_attendance.php?action=get_class_attendance&class_id=${classId}&date=${date}`)
                .then(r => r.json())
                .then(data => {
                    if (data.status === 'success') {
                        document.getElementById('attTitle').textContent = data.class_name || 'Attendance';
                        document.getElementById('attSubtitle').textContent = `Date: ${date} | ${data.students.length} students`;
                        attendanceData = data.students;
                        renderAttendanceTable(data.students);
                    }
                });
        }
        
        function renderAttendanceTable(students) {
            const tbody = document.getElementById('attendanceBody');
            if (students.length === 0) {
                tbody.innerHTML = '<tr><td colspan="5" class="text-center text-slate-400 py-8">No students enrolled</td></tr>';
                return;
            }
            
            tbody.innerHTML = students.map((s, i) => {
                const status = s.status || 'present';
                return `
                <tr data-member-id="${s.member_id || s.id}">
                    <td>${i + 1}</td>
                    <td class="font-medium">${escapeHtml((s.student_name || '') + ' ' + (s.father_name || ''))}</td>
                    <td><code class="text-xs bg-slate-100 px-2 py-1 rounded">${escapeHtml(s.member_code || '—')}</code></td>
                    <td class="text-center">
                        <div class="flex justify-center gap-2">
                            <button type="button" class="att-btn att-present ${status === 'present' ? 'active' : ''}" onclick="setAttStatus(this, ${s.member_id || s.id}, 'present')" title="Present">✓</button>
                            <button type="button" class="att-btn att-absent ${status === 'absent' ? 'active' : ''}" onclick="setAttStatus(this, ${s.member_id || s.id}, 'absent')" title="Absent">✗</button>
                            <button type="button" class="att-btn att-late ${status === 'late' ? 'active' : ''}" onclick="setAttStatus(this, ${s.member_id || s.id}, 'late')" title="Late">L</button>
                        </div>
                    </td>
                    <td><input type="text" class="form-input att-note" style="width:120px" data-member-id="${s.member_id || s.id}" value="${escapeHtml(s.note || '')}" placeholder="Note"></td>
                </tr>
            `}).join('');
        }
        
        function setAttStatus(btn, memberId, status) {
            const row = btn.closest('tr');
            row.querySelectorAll('.att-btn').forEach(b => b.classList.remove('active'));
            btn.classList.add('active');
            
            const idx = attendanceData.findIndex(s => (s.member_id || s.id) == memberId);
            if (idx !== -1) attendanceData[idx].status = status;
        }
        
        function markAllAttendance(status) {
            document.querySelectorAll('#attendanceBody tr').forEach(row => {
                const btn = row.querySelector(`.att-${status}`);
                if (btn) {
                    row.querySelectorAll('.att-btn').forEach(b => b.classList.remove('active'));
                    btn.classList.add('active');
                }
            });
            attendanceData.forEach(s => s.status = status);
        }
        
        function saveAttendance() {
            const classId = document.getElementById('attClassSelect').value;
            const date = document.getElementById('attDateInput').value;
            if (!classId) return;
            
            const records = [];
            document.querySelectorAll('#attendanceBody tr').forEach(row => {
                const memberId = row.dataset.memberId;
                if (!memberId) return;
                
                let status = 'present';
                if (row.querySelector('.att-absent.active')) status = 'absent';
                else if (row.querySelector('.att-late.active')) status = 'late';
                
                const noteInput = row.querySelector('.att-note');
                const note = noteInput ? noteInput.value : '';
                
                records.push({ member_id: memberId, status, note });
            });
            
            const formData = new FormData();
            formData.append('action', 'save_attendance');
            formData.append('class_id', classId);
            formData.append('date', date);
            formData.append('records', JSON.stringify(records));
            formData.append('csrf_token', CSRF_TOKEN);
            
            fetch('/admin/api_attendance.php', { method: 'POST', body: formData })
                .then(r => r.json())
                .then(data => {
                    showToast(data.message, data.status === 'success' ? 'success' : 'error');
                });
        }
        
        // ============================================================
        // STUDENTS LIST
        // ============================================================
        function loadClassStudents() {
            const classId = document.getElementById('studentsClassSelect').value;
            
            if (!classId) {
                document.getElementById('studentsCard').style.display = 'none';
                document.getElementById('selectStudentsMsg').style.display = 'block';
                return;
            }
            
            document.getElementById('selectStudentsMsg').style.display = 'none';
            document.getElementById('studentsCard').style.display = 'block';
            
            fetch(`/admin/api_education.php?action=get_enrolled_students&class_id=${classId}`)
                .then(r => r.json())
                .then(data => {
                    if (data.status === 'success') {
                        document.getElementById('studentsTitle').textContent = data.class_name || 'Students';
                        document.getElementById('studentsCount').textContent = `${data.students.length} students enrolled`;
                        renderStudentsTable(data.students);
                    }
                });
        }
        
        function renderStudentsTable(students) {
            const tbody = document.getElementById('studentsBody');
            if (students.length === 0) {
                tbody.innerHTML = '<tr><td colspan="5" class="text-center text-slate-400 py-8">No students enrolled</td></tr>';
                return;
            }
            
            tbody.innerHTML = students.map((s, i) => `
                <tr>
                    <td>${i + 1}</td>
                    <td class="font-medium">${escapeHtml(s.student_name || '')}</td>
                    <td>${escapeHtml(s.father_name || '')}</td>
                    <td><code class="text-xs bg-slate-100 px-2 py-1 rounded">${escapeHtml(s.member_code || '—')}</code></td>
                    <td><span class="chip ${s.gender === 'male' ? 'chip-info' : 'chip-success'}">${s.gender === 'male' ? 'M' : 'F'}</span></td>
                </tr>
            `).join('');
        }

        // ============================================================
        // SUBMIT MARKLIST
        // ============================================================
        let submitAssessmentData = null;
        
        function loadSubmitAssessments() {
            const combo = document.getElementById('submitAssignmentSelect').value;
            const sel = document.getElementById('submitAssessmentSelect');
            sel.innerHTML = '<option value="">-- Select --</option>';
            document.getElementById('submitEntryCard').style.display = 'none';
            document.getElementById('submitBtn').style.display = 'none';
            if (!combo) return;
            const [cid, sid] = combo.split('-');
            fetch(`/admin/api_subjects.php?action=get_assessments&class_id=${cid}&subject_id=${sid}`)
                .then(r => r.json())
                .then(d => {
                    if (d.status === 'success') {
                        (d.assessments || []).forEach(a => {
                            sel.innerHTML += `<option value="${a.id}" data-max="${a.max_score}" data-name="${escapeHtml(a.assessment_name)}">${escapeHtml(a.assessment_name)} (max: ${a.max_score})</option>`;
                        });
                    }
                });
        }
        
        function loadSubmitStudents() {
            const aid = document.getElementById('submitAssessmentSelect').value;
            if (!aid) { document.getElementById('submitEntryCard').style.display='none'; document.getElementById('submitBtn').style.display='none'; return; }
            
            document.getElementById('submitEntryCard').style.display = 'block';
            document.getElementById('submitBtn').style.display = 'flex';
            
            const opt = document.getElementById('submitAssessmentSelect').selectedOptions[0];
            const maxScore = opt?.dataset?.max || 100;
            const aName = opt?.dataset?.name || 'Assessment';
            const combo = document.getElementById('submitAssignmentSelect');
            document.getElementById('submitTitle').textContent = aName;
            document.getElementById('submitSubtitle').textContent = combo.selectedOptions[0]?.text + ' • Max: ' + maxScore;
            
            submitAssessmentData = { id: aid, max: parseFloat(maxScore) };
            
            fetch(`/admin/api_subjects.php?action=get_students_for_grading&assessment_id=${aid}`)
                .then(r => r.json())
                .then(d => {
                    if (d.status === 'success') {
                        const tbody = document.getElementById('submitEntryBody');
                        const students = d.students || [];
                        tbody.innerHTML = students.length ? students.map((s, i) => `
                            <tr>
                                <td>${i+1}</td>
                                <td class="font-medium">${escapeHtml((s.student_name||'')+ ' '+(s.father_name||''))}</td>
                                <td><code class="text-xs bg-slate-100 px-2 py-1 rounded">${escapeHtml(s.member_code||'—')}</code></td>
                                <td><input type="number" class="grade-input submit-score" data-mid="${s.member_id}" min="0" max="${maxScore}" step="0.5" value="${s.score!==null&&s.score!==undefined?s.score:''}" placeholder="—"></td>
                                <td><input type="text" class="form-input submit-remark" data-mid="${s.member_id}" style="width:120px" value="${escapeHtml(s.remarks||'')}" placeholder="Remark"></td>
                            </tr>
                        `).join('') : '<tr><td colspan="5" class="text-center text-slate-400 py-8">No students</td></tr>';
                    }
                });
        }
        
        function submitMarklist() {
            if (!submitAssessmentData) return;
            const combo = document.getElementById('submitAssignmentSelect').value;
            if (!combo) return showToast('Select class and subject', 'error');
            const [cid, sid] = combo.split('-');
            
            const grades = [];
            document.querySelectorAll('.submit-score').forEach(inp => {
                const mid = inp.dataset.mid;
                const remarkInp = document.querySelector(`.submit-remark[data-mid="${mid}"]`);
                grades.push({ member_id: mid, score: inp.value, remark: remarkInp?.value || '' });
            });
            
            if (grades.filter(g => g.score !== '').length === 0) return showToast('Enter at least one score', 'error');
            
            const fd = new FormData();
            fd.append('action', 'submit_marklist');
            fd.append('class_id', cid);
            fd.append('subject_id', sid);
            fd.append('assessment_id', submitAssessmentData.id);
            fd.append('grades', JSON.stringify(grades));
            fd.append('csrf_token', CSRF_TOKEN);
            
            document.getElementById('submitBtn').disabled = true;
            document.getElementById('submitBtn').innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Submitting...';
            
            fetch('/admin/api_communication.php', { method: 'POST', body: fd })
                .then(r => r.json())
                .then(d => {
                    showToast(d.message, d.status === 'success' ? 'success' : 'error');
                    if (d.status === 'success') loadMySubmissions();
                })
                .finally(() => {
                    document.getElementById('submitBtn').disabled = false;
                    document.getElementById('submitBtn').innerHTML = '<i class="fa-solid fa-paper-plane"></i> Submit to Edu Dept';
                });
        }
        
        function loadMySubmissions() {
            fetch('/admin/api_communication.php?action=get_submissions')
                .then(r => r.json())
                .then(d => {
                    if (d.status === 'success') {
                        const subs = d.submissions || [];
                        const area = document.getElementById('mySubmissionsList');
                        if (!subs.length) { area.innerHTML = '<p class="text-center text-slate-400 py-4">No submissions yet</p>'; return; }
                        area.innerHTML = `<div class="space-y-2">${subs.map(s => {
                            const statusColors = {draft:'bg-gray-100 text-gray-700',submitted:'bg-amber-100 text-amber-800',approved:'bg-emerald-100 text-emerald-800',rejected:'bg-red-100 text-red-800',revision_needed:'bg-orange-100 text-orange-800'};
                            const statusIcons = {draft:'fa-pencil',submitted:'fa-clock',approved:'fa-check-circle',rejected:'fa-times-circle',revision_needed:'fa-exclamation-circle'};
                            const sc = statusColors[s.status]||'bg-gray-100';
                            const si = statusIcons[s.status]||'fa-circle';
                            return `<div class="flex items-center gap-3 p-3 rounded-xl border border-slate-100 hover:border-sky-200 transition-all">
                                <div class="w-10 h-10 rounded-lg flex items-center justify-center flex-shrink-0" style="background:linear-gradient(135deg,#6366f1,#8b5cf6)">
                                    <i class="fa-solid fa-file-lines text-white text-sm"></i>
                                </div>
                                <div class="flex-1 min-w-0">
                                    <div class="font-semibold text-sm text-slate-800 truncate">${escapeHtml(s.class_name||'')} — ${escapeHtml(s.subject_name||'')}</div>
                                    <div class="text-xs text-slate-500">${escapeHtml(s.assessment_name||'Marklist')} • ${s.student_count||0} students • Avg: ${s.average_score?parseFloat(s.average_score).toFixed(1):'—'}</div>
                                </div>
                                <span class="px-2.5 py-1 rounded-full text-xs font-semibold ${sc}"><i class="fa-solid ${si} mr-1"></i>${(s.status||'').replace('_',' ')}</span>
                                ${s.review_notes?`<span class="text-xs text-slate-400" title="${escapeHtml(s.review_notes)}"><i class="fa-solid fa-comment"></i></span>`:''}
                            </div>`;
                        }).join('')}</div>`;
                    }
                });
        }

        // ============================================================
        // REPORT CARDS
        // ============================================================
        let classReportData = [];
        
        function loadClassReport() {
            const cid = document.getElementById('reportClassSelect').value;
            if (!cid) {
                document.getElementById('classStatsArea').style.display='none';
                document.getElementById('classReportArea').style.display='none';
                document.getElementById('selectReportMsg').style.display='block';
                return;
            }
            document.getElementById('selectReportMsg').style.display='none';
            
            fetch(`/admin/api_communication.php?action=get_class_report&class_id=${cid}`)
                .then(r => r.json())
                .then(d => {
                    if (d.status === 'success') {
                        classReportData = d.students || [];
                        const stats = d.stats || {};
                        
                        // Stats cards
                        document.getElementById('classStatsArea').style.display='block';
                        document.getElementById('classStatsCards').innerHTML = `
                            <div class="card p-3 text-center"><div class="text-2xl font-bold text-indigo-600">${stats.total_students||0}</div><div class="text-xs text-slate-500">Students</div></div>
                            <div class="card p-3 text-center"><div class="text-2xl font-bold text-emerald-600">${stats.class_average||'—'}%</div><div class="text-xs text-slate-500">Class Average</div></div>
                            <div class="card p-3 text-center"><div class="text-2xl font-bold text-sky-600">${stats.pass_rate||0}%</div><div class="text-xs text-slate-500">Pass Rate</div></div>
                            <div class="card p-3 text-center"><div class="text-2xl font-bold text-green-600">${stats.highest||'—'}%</div><div class="text-xs text-slate-500">Highest</div></div>
                            <div class="card p-3 text-center"><div class="text-2xl font-bold text-red-500">${stats.lowest||'—'}%</div><div class="text-xs text-slate-500">Lowest</div></div>
                        `;
                        
                        // Table
                        document.getElementById('classReportArea').style.display='block';
                        const cls = document.getElementById('reportClassSelect').selectedOptions[0]?.text || '';
                        document.getElementById('reportTitle').textContent = cls + ' — Performance';
                        document.getElementById('reportSubtitle').textContent = `${stats.total_students} students • Grade Distribution: A:${stats.grade_distribution?.A||0} B:${stats.grade_distribution?.B||0} C:${stats.grade_distribution?.C||0} D:${stats.grade_distribution?.D||0} F:${stats.grade_distribution?.F||0}`;
                        
                        const tbody = document.getElementById('classReportBody');
                        tbody.innerHTML = classReportData.length ? classReportData.map(s => {
                            const pct = s.avg_percentage ? parseFloat(s.avg_percentage).toFixed(1) : '—';
                            const gc = {A:'text-emerald-700 bg-emerald-50',B:'text-sky-700 bg-sky-50',C:'text-amber-700 bg-amber-50',D:'text-orange-700 bg-orange-50',F:'text-red-700 bg-red-50'};
                            const gCls = gc[s.grade_letter] || 'text-slate-500 bg-slate-50';
                            const attBar = s.attendance_rate || 0;
                            return `<tr>
                                <td><span class="inline-flex items-center justify-center w-7 h-7 rounded-full text-xs font-bold ${s.rank<=3?'bg-amber-100 text-amber-800':'bg-slate-100 text-slate-600'}">${s.rank}</span></td>
                                <td class="font-medium">${escapeHtml((s.student_name||'')+' '+(s.father_name||''))}</td>
                                <td><code class="text-xs bg-slate-100 px-2 py-1 rounded">${escapeHtml(s.member_code||'—')}</code></td>
                                <td class="font-bold">${pct}%</td>
                                <td><span class="px-2 py-0.5 rounded-md text-xs font-bold ${gCls}">${s.grade_letter||'—'}</span></td>
                                <td><div class="flex items-center gap-2"><div class="w-16 h-1.5 bg-slate-100 rounded-full"><div class="h-full rounded-full ${attBar>=80?'bg-emerald-500':attBar>=60?'bg-amber-500':'bg-red-500'}" style="width:${attBar}%"></div></div><span class="text-xs text-slate-500">${attBar}%</span></div></td>
                                <td><button onclick="viewReportCard(${s.id},${document.getElementById('reportClassSelect').value})" class="text-xs text-indigo-600 hover:text-indigo-800 font-medium"><i class="fa-solid fa-file-lines"></i> View</button></td>
                            </tr>`;
                        }).join('') : '<tr><td colspan="7" class="text-center text-slate-400 py-8">No grade data</td></tr>';
                    }
                });
        }
        
        function viewReportCard(memberId, classId) {
            const modal = document.getElementById('reportCardModal');
            const content = document.getElementById('reportCardContent');
            modal.style.display = 'block';
            content.innerHTML = '<div class="p-8 text-center text-slate-400"><i class="fa-solid fa-spinner fa-spin text-2xl"></i><p class="mt-2">Generating report card...</p></div>';
            
            fetch(`/admin/api_communication.php?action=get_report_card&member_id=${memberId}&class_id=${classId}`)
                .then(r => r.json())
                .then(d => {
                    if (d.status !== 'success') { content.innerHTML = `<div class="p-8 text-center text-red-500">${d.message}</div>`; return; }
                    
                    const s = d.student, cl = d.class, yr = d.year, tm = d.term, att = d.attendance;
                    const subjects = d.subjects || [];
                    const oa = d.overall_average, og = d.overall_grade;
                    const rank = d.rank, total = d.total_in_class;
                    
                    const gc = {A:'#059669',B:'#0284c7',C:'#d97706',D:'#ea580c',F:'#dc2626'};
                    
                    content.innerHTML = `
                        <div style="background:linear-gradient(135deg,#1e293b,#334155);color:#fff;padding:1.5rem;text-align:center" id="reportCardPrintArea">
                            <div style="font-size:.6rem;text-transform:uppercase;letter-spacing:.15em;opacity:.7;margin-bottom:.25rem"><?= SCHOOL_TRANSLATION_EN ?> <?= SCHOOL_TYPE ?></div>
                            <h2 style="font-size:1.2rem;font-weight:700;margin:0">Student Report Card</h2>
                            <div style="font-size:.75rem;opacity:.8;margin-top:.25rem">${escapeHtml(yr?.year_name||'')} ${tm?'• '+escapeHtml(tm.term_name):''}</div>
                        </div>
                        <div style="padding:1.25rem">
                            <!-- Student Info -->
                            <div style="display:grid;grid-template-columns:1fr 1fr;gap:.5rem;font-size:.8rem;margin-bottom:1rem;background:#f8fafc;padding:.75rem;border-radius:12px">
                                <div><strong style="color:#64748b">Name:</strong> ${escapeHtml(s?.student_name||'')}</div>
                                <div><strong style="color:#64748b">Father:</strong> ${escapeHtml(s?.father_name||'')}</div>
                                <div><strong style="color:#64748b">Class:</strong> ${escapeHtml(cl?.class_name||'')}</div>
                                <div><strong style="color:#64748b">Code:</strong> ${escapeHtml(s?.member_code||'')}</div>
                            </div>
                            
                            <!-- Summary Cards -->
                            <div style="display:grid;grid-template-columns:repeat(4,1fr);gap:.5rem;margin-bottom:1rem">
                                <div style="background:linear-gradient(135deg,#6366f1,#8b5cf6);color:#fff;padding:.75rem;border-radius:12px;text-align:center">
                                    <div style="font-size:1.3rem;font-weight:700">${oa?oa+'%':'—'}</div>
                                    <div style="font-size:.6rem;opacity:.8">Overall</div>
                                </div>
                                <div style="background:${gc[og]||'#64748b'};color:#fff;padding:.75rem;border-radius:12px;text-align:center">
                                    <div style="font-size:1.3rem;font-weight:700">${og||'—'}</div>
                                    <div style="font-size:.6rem;opacity:.8">Grade</div>
                                </div>
                                <div style="background:linear-gradient(135deg,#f59e0b,#d97706);color:#fff;padding:.75rem;border-radius:12px;text-align:center">
                                    <div style="font-size:1.3rem;font-weight:700">${rank||'—'}${rank?'<span style="font-size:.65rem">/${total}</span>':''}</div>
                                    <div style="font-size:.6rem;opacity:.8">Rank</div>
                                </div>
                                <div style="background:linear-gradient(135deg,#059669,#10b981);color:#fff;padding:.75rem;border-radius:12px;text-align:center">
                                    <div style="font-size:1.3rem;font-weight:700">${att.rate}%</div>
                                    <div style="font-size:.6rem;opacity:.8">Attendance</div>
                                </div>
                            </div>
                            
                            <!-- Subject Grades Table -->
                            <table style="width:100%;font-size:.78rem;border-collapse:collapse;margin-bottom:1rem">
                                <thead><tr style="background:#f1f5f9">
                                    <th style="padding:.5rem .6rem;text-align:left;font-weight:600;color:#64748b;font-size:.65rem;text-transform:uppercase">Subject</th>
                                    <th style="padding:.5rem .6rem;text-align:center;font-weight:600;color:#64748b;font-size:.65rem">Assessments</th>
                                    <th style="padding:.5rem .6rem;text-align:center;font-weight:600;color:#64748b;font-size:.65rem">Average</th>
                                    <th style="padding:.5rem .6rem;text-align:center;font-weight:600;color:#64748b;font-size:.65rem">Grade</th>
                                </tr></thead>
                                <tbody>
                                    ${subjects.map(sub => {
                                        const fp = sub.final_percentage;
                                        const gl = sub.grade_letter;
                                        return `<tr style="border-bottom:1px solid #f1f5f9">
                                            <td style="padding:.5rem .6rem;font-weight:600">${escapeHtml(sub.subject_name)}</td>
                                            <td style="padding:.5rem .6rem;text-align:center;font-size:.7rem">${(sub.assessments||[]).map(a => 
                                                `<span style="display:inline-block;background:#ede9fe;color:#5b21b6;padding:1px 5px;border-radius:4px;margin:1px;font-size:.6rem">${escapeHtml(a.assessment_name||'')}: ${a.score!==null?a.score:'—'}/${a.max_score}</span>`
                                            ).join(' ')}</td>
                                            <td style="padding:.5rem .6rem;text-align:center;font-weight:700">${fp!==null?fp.toFixed(1)+'%':'—'}</td>
                                            <td style="padding:.5rem .6rem;text-align:center"><span style="display:inline-flex;width:28px;height:28px;border-radius:50%;align-items:center;justify-content:center;font-weight:700;font-size:.75rem;color:#fff;background:${gc[gl]||'#94a3b8'}">${gl||'—'}</span></td>
                                        </tr>`;
                                    }).join('')}
                                </tbody>
                            </table>
                            
                            <!-- Attendance Bar -->
                            <div style="background:#f8fafc;padding:.75rem;border-radius:12px;margin-bottom:1rem">
                                <div style="font-size:.7rem;font-weight:600;color:#64748b;margin-bottom:.4rem">Attendance: ${att.present} present, ${att.absent} absent, ${att.late} late out of ${att.total} days</div>
                                <div style="display:flex;height:8px;border-radius:99px;overflow:hidden;background:#e2e8f0">
                                    ${att.total>0?`<div style="width:${att.present/att.total*100}%;background:#059669"></div><div style="width:${att.late/att.total*100}%;background:#f59e0b"></div><div style="width:${att.absent/att.total*100}%;background:#ef4444"></div>`:''}
                                </div>
                            </div>
                            
                            <div style="display:flex;justify-content:space-between;align-items:center">
                                <button onclick="window.print()" style="padding:.5rem 1rem;background:#1e293b;color:#fff;border:none;border-radius:10px;font-size:.8rem;cursor:pointer"><i class="fa-solid fa-print"></i> Print</button>
                                <button onclick="document.getElementById('reportCardModal').style.display='none'" style="padding:.5rem 1rem;background:#f1f5f9;color:#475569;border:none;border-radius:10px;font-size:.8rem;cursor:pointer">Close</button>
                            </div>
                        </div>`;
                });
        }
        
        // Close modal on backdrop click
        document.getElementById('reportCardModal')?.addEventListener('click', function(e) {
            if (e.target === this) this.style.display = 'none';
        });
        
        // Auto-load submissions when section opens
        const origShowSection = showSection;
        showSection = function(name) {
            origShowSection(name);
            if (name === 'submissions') loadMySubmissions();
            if (name === 'reports') { /* auto-load if class selected */ }
        };
        
        function exportClassReport() {
            if (!classReportData.length) return showToast('No data to export', 'error');
            if (typeof XLSX === 'undefined') return showToast('Export library not loaded', 'error');
            const h = ['Rank','Name','Code','Average %','Grade','Attendance %'];
            const rows = classReportData.map(s => [
                s.rank, (s.student_name||'')+' '+(s.father_name||''),
                s.member_code||'', s.avg_percentage?parseFloat(s.avg_percentage).toFixed(1):'',
                s.grade_letter||'', s.attendance_rate||0
            ]);
            const ws = XLSX.utils.aoa_to_sheet([h,...rows]);
            const wb = XLSX.utils.book_new();
            XLSX.utils.book_append_sheet(wb, ws, 'Report');
            XLSX.writeFile(wb, 'Class_Report.xlsx');
        }
    </script>
<!-- ADVANCED MOBILE BOTTOM NAV -->
<nav class="wbws-bnav" id="wbwsBottomNav">
<div class="wbws-bnav-scroll-hint-left" id="bnScrollL"></div>
<div class="wbws-bnav-scroll-hint-right visible" id="bnScrollR"></div>
<div class="wbws-bnav-inner" id="bnScroll">
<button class="wbws-bnav-btn active" onclick="showSection('dashboard')" data-sec="dashboard"><i class="fa-solid fa-gauge-high"></i><span>Home</span></button>
<button class="wbws-bnav-btn" onclick="showSection('grades')" data-sec="grades"><i class="fa-solid fa-star"></i><span>Grades</span></button>
<button class="wbws-bnav-btn" onclick="showSection('attendance')" data-sec="attendance"><i class="fa-solid fa-clipboard-check"></i><span>Attend</span></button>
<div class="wbws-bnav-divider"></div>
<button class="wbws-bnav-btn" onclick="showSection('submissions')" data-sec="submissions"><i class="fa-solid fa-paper-plane"></i><span>Submit</span></button>
<button class="wbws-bnav-btn" onclick="showSection('reports')" data-sec="reports"><i class="fa-solid fa-chart-line"></i><span>Reports</span></button>
<button class="wbws-bnav-btn" onclick="showSection('students')" data-sec="students"><i class="fa-solid fa-users"></i><span>Students</span></button>
<div class="wbws-bnav-divider"></div>
<a href="/admin/logout.php" class="wbws-bnav-btn bnav-exit"><i class="fa-solid fa-right-from-bracket"></i><span>Exit</span></a>
</div></nav>
<script>(function(){const sc=document.getElementById('bnScroll'),sl=document.getElementById('bnScrollL'),sr=document.getElementById('bnScrollR');if(!sc)return;function upd(){sl.classList.toggle('visible',sc.scrollLeft>10);sr.classList.toggle('visible',sc.scrollLeft<sc.scrollWidth-sc.clientWidth-10);}sc.addEventListener('scroll',upd,{passive:true});setTimeout(upd,100);sc.querySelectorAll('.wbws-bnav-btn[data-sec]').forEach(b=>{b.addEventListener('click',function(){sc.querySelectorAll('.wbws-bnav-btn').forEach(x=>x.classList.remove('active'));this.classList.add('active');});});})();</script>
</body>
</html>
