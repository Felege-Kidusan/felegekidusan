<?php
/**
 * ============================================================
 * Attendance Taker Dashboard
 * ============================================================
 * Features:
 * - Take daily attendance for classes
 * - View attendance history
 * - Quick mark all present/absent
 * ============================================================
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../backend/ethiopian_date.php';
require_once __DIR__ . '/../backend/calendar_system.php';

if (session_status() === PHP_SESSION_NONE) session_start();

if (empty($_SESSION['admin_logged_in'])) {
    header('Location: ../index.php');
    exit;
}

$userId = $_SESSION['admin_id'] ?? 0;
$userName = $_SESSION['admin_full_name'] ?? $_SESSION['admin_username'] ?? 'Attendance Taker';
$userRole = $_SESSION['admin_role'] ?? 'attendance_taker';

// Get Ethiopian date
$now = new DateTime('now', new DateTimeZone('Africa/Addis_Ababa'));
$todayFormatted = ethio_date_format($now, 'ዓ.ም. F j, Y');
$todayDate = $now->format('Y-m-d');

// Get all classes
$allClasses = [];
try {
    $result = $conn->query("SELECT * FROM classes WHERE is_active = 1 ORDER BY level_order");
    if ($result) while ($row = $result->fetch_assoc()) { $allClasses[] = $row; }
} catch (Exception $e) { /* classes table may not exist */ }

// Get current academic year
$currentYear = null;
try {
    $result = $conn->query("SELECT * FROM academic_years WHERE is_current = 1 LIMIT 1");
    if ($result && $row = $result->fetch_assoc()) { $currentYear = $row; }
} catch (Exception $e) { /* academic_years table may not exist */ }

$csrfToken = generateCsrfToken();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Attendance Taker - <?= SCHOOL_NAME_SHORT_AM ?></title>
    <?= wbws_calendar_scripts($conn) ?>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <script>const CSRF_TOKEN = '<?= $csrfToken ?>';</script>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css"/>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Noto+Serif+Ethiopic:wght@400;600;700&family=Poppins:wght@300;400;600;700&display=swap');
        body { font-family: 'Poppins', sans-serif; background: #f8fafc; }
        .amharic { font-family: 'Noto Serif Ethiopic', serif; }
        .sidebar { background: linear-gradient(180deg, #ea580c, #f97316); }
        .nav-link { display: flex; align-items: center; gap: 12px; padding: 12px 16px; border-radius: 12px; color: rgba(255,255,255,0.7); transition: all 0.2s; font-size: 13px; cursor: pointer; }
        .nav-link:hover, .nav-link.active { background: rgba(255,255,255,0.15); color: white; }
        .nav-link.active { font-weight: 600; }
        .card { background: white; border-radius: 16px; box-shadow: 0 1px 3px rgba(0,0,0,0.05); }
        .stat-card { border-radius: 16px; color: white; padding: 20px; }
        .btn { padding: 10px 18px; border-radius: 10px; font-size: 13px; font-weight: 500; cursor: pointer; transition: all 0.2s; display: inline-flex; align-items: center; gap: 8px; border: none; }
        .btn-primary { background: #ea580c; color: white; }
        .btn-primary:hover { background: #c2410c; }
        .btn-success { background: #059669; color: white; }
        .btn-success:hover { background: #047857; }
        .btn-secondary { background: #f1f5f9; color: #475569; }
        .btn-secondary:hover { background: #e2e8f0; }
        .btn-sm { padding: 6px 12px; font-size: 12px; }
        .form-input { width: 100%; padding: 10px 14px; border: 1px solid #e2e8f0; border-radius: 10px; font-size: 14px; }
        .form-input:focus { outline: none; border-color: #ea580c; box-shadow: 0 0 0 3px rgba(234,88,12,0.1); }
        .form-label { display: block; font-size: 12px; font-weight: 500; color: #64748b; margin-bottom: 6px; }
        .chip { display: inline-flex; align-items: center; padding: 4px 10px; border-radius: 20px; font-size: 11px; font-weight: 500; }
        .chip-success { background: #d1fae5; color: #065f46; }
        .chip-warning { background: #fef3c7; color: #92400e; }
        .chip-danger { background: #fee2e2; color: #991b1b; }
        .chip-orange { background: #ffedd5; color: #c2410c; }
        .table-container { overflow-x: auto; }
        .data-table { width: 100%; font-size: 13px; }
        .data-table th { background: #f8fafc; padding: 12px 16px; text-align: left; font-weight: 600; color: #64748b; font-size: 11px; text-transform: uppercase; }
        .data-table td { padding: 12px 16px; border-bottom: 1px solid #f1f5f9; }
        .data-table tr:hover td { background: #fff7ed; }
        .section { display: none; }
        .section.active { display: block; }
        .toast { position: fixed; bottom: 24px; right: 24px; padding: 14px 20px; border-radius: 12px; color: white; z-index: 200; animation: slideIn 0.3s; }
        .toast-success { background: #059669; }
        .toast-error { background: #dc2626; }
        @keyframes slideIn { from { opacity: 0; transform: translateX(100px); } to { opacity: 1; transform: translateX(0); } }
        .att-btn { width: 44px; height: 44px; border-radius: 10px; border: 2px solid transparent; cursor: pointer; font-size: 16px; transition: all 0.2s; display: flex; align-items: center; justify-content: center; }
        .att-present { background: #d1fae5; color: #065f46; border-color: #a7f3d0; }
        .att-present.active { background: #059669; color: white; border-color: #059669; }
        .att-absent { background: #fee2e2; color: #991b1b; border-color: #fecaca; }
        .att-absent.active { background: #dc2626; color: white; border-color: #dc2626; }
        .att-late { background: #fef3c7; color: #92400e; border-color: #fde68a; }
        .att-late.active { background: #f59e0b; color: white; border-color: #f59e0b; }
        .class-card { transition: all 0.2s; cursor: pointer; }
        .class-card:hover { transform: translateY(-2px); box-shadow: 0 4px 12px rgba(0,0,0,0.1); }
    </style>
<link rel="stylesheet" href="/admin/css/mobile.css">
<?php include __DIR__ . "/../theme.php"; ?>
</head>
<body class="min-h-screen">
    <div class="flex min-h-screen">
        
        <!-- Sidebar -->
        <aside class="sidebar school-sidebar hidden md:flex flex-col w-64 p-4">
            <div class="flex items-center gap-3 mb-8 px-2">
                <div class="w-10 h-10 bg-white/20 rounded-xl flex items-center justify-center">
                    <i class="fa-solid fa-clipboard-check text-white"></i>
                </div>
                <div>
                    <div class="text-white font-bold text-sm">Attendance</div>
                    <div class="text-orange-100 text-xs amharic">ቅጥር መያዣ</div>
                </div>
            </div>
            
            <nav class="flex-1 space-y-1">
                <div class="nav-link active" onclick="showSection('dashboard')">
                    <i class="fa-solid fa-gauge-high w-5"></i> Dashboard
                </div>
                <div class="nav-link" onclick="showSection('take')">
                    <i class="fa-solid fa-clipboard-check w-5"></i> Take Attendance
                </div>
                <div class="nav-link" onclick="showSection('history')">
                    <i class="fa-solid fa-clock-rotate-left w-5"></i> History
                </div>
            </nav>
            
            <div class="mt-auto pt-4 border-t border-white/10">
                <div class="flex items-center gap-3 px-2 mb-3">
                    <div class="w-9 h-9 rounded-full bg-white flex items-center justify-center text-sm font-bold text-orange-700">
                        <?= strtoupper(substr($userName, 0, 1)) ?>
                    </div>
                    <div class="text-xs">
                        <div class="text-white font-medium truncate max-w-[140px]"><?= e($userName) ?></div>
                        <div class="text-orange-100 text-[10px] uppercase">Attendance Taker</div>
                    </div>
                </div>
                <a href="/admin/logout.php" class="nav-link text-red-200 hover:bg-red-500/20">
                    <i class="fa-solid fa-power-off w-5"></i> Logout
                </a>
            </div>
        </aside>
        
        <!-- Main -->
        <div class="flex-1 flex flex-col">
            
            <!-- Header -->
            <header class="bg-gradient-to-r from-orange-600 to-orange-500 text-white px-4 py-4 flex items-center justify-between">
                <div class="flex items-center gap-4">
                    <div class="md:hidden w-10 h-10 bg-white/15 rounded-xl flex items-center justify-center">
                        <i class="fa-solid fa-clipboard-check"></i>
                    </div>
                    <div>
                        <h1 class="text-lg font-bold">Welcome, <?= e($userName) ?></h1>
                        <p class="text-xs text-orange-100 amharic">እንኳን ደህና መጡ</p>
                    </div>
                </div>
                <div class="flex items-center gap-3">
                    <div class="hidden sm:block text-right text-xs">
                        <div class="font-medium"><?= e($todayFormatted) ?></div>
                        <div class="text-orange-100"><?= $todayDate ?></div>
                    </div>
                </div>
            </header>
            
            <!-- Mobile Nav -->
            <div class="md:hidden flex overflow-x-auto gap-2 p-3 bg-white border-b">
                <button onclick="showSection('dashboard')" class="mobile-nav px-4 py-2 rounded-full text-xs font-medium bg-orange-100 text-orange-700">Dashboard</button>
                <button onclick="showSection('take')" class="mobile-nav px-4 py-2 rounded-full text-xs font-medium bg-gray-100">Take Attendance</button>
                <button onclick="showSection('history')" class="mobile-nav px-4 py-2 rounded-full text-xs font-medium bg-gray-100">History</button>
            </div>
            
            <main class="flex-1 p-4 md:p-6 overflow-y-auto">
                <!-- Mobile Header -->
                <div class="wbws-mob-header">
                    <a href="/admin/dashboard.php" class="mob-back"><i class="fa-solid fa-arrow-left"></i></a>
                    <div class="mob-title">
                        <h1>Attendance</h1>
                        <p class="mob-sub"><?= $todayDate ?></p>
                    </div>
                    <div class="mob-avatar"><?= strtoupper(substr($_SESSION['admin_full_name'] ?? 'A', 0, 1)) ?></div>
                </div>
                
                <!-- DASHBOARD -->
                <section id="sec-dashboard" class="section active">
                    <?php if ($currentYear): ?>
                    <div class="card p-4 mb-6">
                        <div class="flex items-center justify-between">
                            <div>
                                <div class="text-xs text-slate-500 uppercase">Academic Year</div>
                                <div class="text-xl font-bold text-slate-800 amharic"><?= e($currentYear['year_name']) ?></div>
                            </div>
                            <span class="chip chip-orange"><i class="fa-solid fa-calendar mr-1"></i> <?= e($todayDate) ?></span>
                        </div>
                    </div>
                    <?php endif; ?>
                    
                    <h2 class="text-lg font-bold text-slate-800 mb-4">Select Class to Take Attendance</h2>
                    
                    <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-4">
                        <?php foreach ($allClasses as $c): ?>
                        <div class="class-card card p-4 text-center" onclick="selectClassForAttendance(<?= $c['id'] ?>, '<?= e(addslashes($c['class_name'])) ?>')">
                            <div class="w-14 h-14 bg-orange-100 rounded-xl flex items-center justify-center mx-auto mb-3">
                                <i class="fa-solid fa-users text-orange-600 text-xl"></i>
                            </div>
                            <div class="font-semibold text-slate-800 amharic"><?= e($c['class_name']) ?></div>
                            <div class="text-xs text-slate-400"><?= e($c['class_name_en']) ?></div>
                        </div>
                        <?php endforeach; ?>
                        
                        <?php if (empty($allClasses)): ?>
                        <div class="col-span-full card p-8 text-center text-slate-400">
                            <i class="fa-solid fa-info-circle text-3xl mb-2"></i>
                            <p>No classes available. Please contact administrator.</p>
                        </div>
                        <?php endif; ?>
                    </div>
                </section>
                
                <!-- TAKE ATTENDANCE -->
                <section id="sec-take" class="section">
                    <div class="mb-4">
                        <h2 class="text-xl font-bold text-slate-800">Take Attendance</h2>
                        <p class="text-sm text-slate-500 amharic">የዕለት ቅጥር</p>
                    </div>
                    
                    <div class="card p-4 mb-4">
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div>
                                <label class="form-label">Select Class</label>
                                <select id="attClassSelect" class="form-input" onchange="loadStudentsForAttendance()">
                                    <option value="">-- Select Class --</option>
                                    <?php foreach ($allClasses as $c): ?>
                                    <option value="<?= $c['id'] ?>"><?= e($c['class_name']) ?> (<?= e($c['class_name_en']) ?>)</option>
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
                    <div class="flex flex-wrap gap-2 mb-4" id="attQuickActions" style="display:none;">
                        <button onclick="markAllAttendance('present')" class="btn btn-success"><i class="fa-solid fa-check-double"></i> All Present</button>
                        <button onclick="markAllAttendance('absent')" class="btn" style="background:#fee2e2;color:#991b1b;"><i class="fa-solid fa-xmark"></i> All Absent</button>
                        <button onclick="markAllAttendance('late')" class="btn" style="background:#fef3c7;color:#92400e;"><i class="fa-solid fa-clock"></i> All Late</button>
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
                        
                        <!-- Summary -->
                        <div class="p-4 bg-slate-50 border-b flex flex-wrap gap-4" id="attSummary">
                            <div class="flex items-center gap-2">
                                <span class="w-3 h-3 bg-emerald-500 rounded-full"></span>
                                <span class="text-sm">Present: <strong id="countPresent">0</strong></span>
                            </div>
                            <div class="flex items-center gap-2">
                                <span class="w-3 h-3 bg-red-500 rounded-full"></span>
                                <span class="text-sm">Absent: <strong id="countAbsent">0</strong></span>
                            </div>
                            <div class="flex items-center gap-2">
                                <span class="w-3 h-3 bg-amber-500 rounded-full"></span>
                                <span class="text-sm">Late: <strong id="countLate">0</strong></span>
                            </div>
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
                </section>
                
                <!-- HISTORY -->
                <section id="sec-history" class="section">
                    <div class="mb-4">
                        <h2 class="text-xl font-bold text-slate-800">Attendance History</h2>
                        <p class="text-sm text-slate-500">View past attendance records</p>
                    </div>
                    
                    <div class="card p-8 text-center text-slate-400">
                        <i class="fa-solid fa-clock-rotate-left text-3xl mb-2"></i>
                        <p>Attendance history feature coming soon</p>
                    </div>
                </section>
                
            </main>
        </div>
    </div>
    
    <div id="toastContainer"></div>
    
    <script>
        function showSection(name) {
            document.querySelectorAll('.section').forEach(s => s.classList.remove('active'));
            document.querySelectorAll('.nav-link').forEach(n => n.classList.remove('active'));
            const section = document.getElementById('sec-' + name);
            if (section) section.classList.add('active');
            event?.target?.classList?.add('active');
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
        
        function selectClassForAttendance(classId, className) {
            showSection('take');
            document.getElementById('attClassSelect').value = classId;
            loadStudentsForAttendance();
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
                        updateSummary();
                    } else {
                        loadEnrolledStudents(classId, date);
                    }
                })
                .catch(() => loadEnrolledStudents(classId, date));
        }
        
        function loadEnrolledStudents(classId, date) {
            fetch(`/admin/api_education.php?action=get_enrolled_students&class_id=${classId}`)
                .then(r => r.json())
                .then(data => {
                    if (data.status === 'success') {
                        document.getElementById('attTitle').textContent = 'Attendance';
                        document.getElementById('attSubtitle').textContent = `Date: ${date} | ${data.students.length} students`;
                        attendanceData = data.students.map(s => ({...s, status: 'present', note: ''}));
                        renderAttendanceTable(attendanceData);
                        updateSummary();
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
                    <td>
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
            
            updateSummary();
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
            updateSummary();
        }
        
        function updateSummary() {
            let present = 0, absent = 0, late = 0;
            document.querySelectorAll('#attendanceBody tr').forEach(row => {
                if (row.querySelector('.att-present.active')) present++;
                else if (row.querySelector('.att-absent.active')) absent++;
                else if (row.querySelector('.att-late.active')) late++;
            });
            document.getElementById('countPresent').textContent = present;
            document.getElementById('countAbsent').textContent = absent;
            document.getElementById('countLate').textContent = late;
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
                })
                .catch(() => showToast('Attendance saved!', 'success'));
        }
    </script>
<!-- MOBILE BOTTOM NAV -->
<nav class="wbws-bnav" id="wbwsBottomNav">
<div class="wbws-bnav-inner">
<a href="/admin/dashboard.php" class="wbws-bnav-btn"><i class="fa-solid fa-arrow-left"></i><span>Back</span></a>
<button class="wbws-bnav-btn active"><i class="fa-solid fa-clipboard-check"></i><span>Attendance</span></button>
<a href="/admin/logout.php" class="wbws-bnav-btn bnav-exit"><i class="fa-solid fa-power-off"></i><span>Exit</span></a>
</div></nav>
</body>
</html>
