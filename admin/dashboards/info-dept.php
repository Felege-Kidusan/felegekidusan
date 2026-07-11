<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../backend/ethiopian_date.php';
require_once __DIR__ . '/../backend/calendar_system.php';
// session is already started and role checked in dashboard.php

// ------------------------------------------------------------
// Auth / session context
// ------------------------------------------------------------
$userName = (string) ($_SESSION['admin_full_name'] ?? $_SESSION['admin_username'] ?? 'Information Department');
$userRole = (string) ($_SESSION['admin_role'] ?? 'Information Department');
$username = $_SESSION['admin_username'] ?? '';

$today = new DateTime('now', new DateTimeZone('Africa/Addis_Ababa'));
$todayFormatted = ethio_date_format($today, 'F j, Y');

// Determine path prefix for AJAX calls
$isInDashboardSubdir = false;
if (isset($_SERVER['SCRIPT_NAME']) && strpos($_SERVER['SCRIPT_NAME'], '/dashboards/') !== false) {
    $isInDashboardSubdir = true;
}
$ajaxPrefix = $isInDashboardSubdir ? '../' : '';

// ------------------------------------------------------------
// Helper: safe query + fetch helpers
// ------------------------------------------------------------
function db_fetch_all_assoc(mysqli $conn, string $sql, array $params = []): array
{
    $rows = [];
    if (!$stmt = $conn->prepare($sql)) {
        return $rows;
    }
    if (!empty($params)) {
        $types = '';
        $bind  = [];
        foreach ($params as $p) {
            if (is_int($p)) {
                $types .= 'i';
            } elseif (is_float($p)) {
                $types .= 'd';
            } else {
                $types .= 's';
            }
            $bind[] = $p;
        }
        $stmt->bind_param($types, ...$bind);
    }
    if ($stmt->execute()) {
        $res = $stmt->get_result();
        if ($res) {
            while ($row = $res->fetch_assoc()) {
                $rows[] = $row;
            }
        }
    }
    $stmt->close();
    return $rows;
}

// ------------------------------------------------------------
// Live data: members, stats, recent
// ------------------------------------------------------------
$membersList = [];
$recentMembers = [];
$genderCounts = ['male' => 0, 'female' => 0];
$sectionCounts = [
    'under6'   => 0,
    '7_13'     => 0,
    '14_17'    => 0,
    '18_plus'  => 0,
];
$statusCounts = [
    'active'   => 0,
    'warning'  => 0,
    'inactive' => 0,
    'archived' => 0,
];

$totalMembers = 0;
$memberTypeRegular = 0;
$memberTypeWaiting = 0; // registration waiting (no ID yet)
$memberTypeHonor   = 0;

$atRiskStudents = 0; // placeholder: define logic later

if (isset($conn)) {
    // Pull latest 400 members for table (paginate if needed)
    $membersList = db_fetch_all_assoc(
        $conn,
        "SELECT 
            id,
            member_code,
            registration_type,
            member_type,
            status,
            age_group,
            current_section,
            student_name,
            father_name,
            grandfather_name,
            baptismal_name,
            gender,
            phone_number,
            alt_phone_number,
            guardian_name,
            guardian_phone1,
            guardian_phone2,
            city,
            sub_city,
            woreda,
            mender,
            block_number,
            house_number,
            work_profession,
            education_level,
            created_at
         FROM members
         WHERE status != 'archived'
         ORDER BY id DESC
         LIMIT 400"
    );

    // Recent 10
    $recentMembers = db_fetch_all_assoc(
        $conn,
        "SELECT 
            student_name,
            father_name,
            grandfather_name,
            member_type,
            status,
            current_section,
            created_at
         FROM members
         WHERE status != 'archived'
         ORDER BY id DESC
         LIMIT 10"
    );

    // Aggregations - Total excludes archived, but we count archived separately
    $aggCounts = db_fetch_all_assoc(
        $conn,
        "SELECT 
            SUM(status != 'archived')           AS total_members,
            SUM(member_type='regular' AND status != 'archived')      AS regular_cnt,
            SUM(member_type='honorary' AND status != 'archived')     AS honor_cnt,
            SUM(registration_type='waiting' AND status != 'archived') AS waiting_cnt,
            SUM(gender='male' AND status != 'archived')              AS male_cnt,
            SUM(gender='female' AND status != 'archived')            AS female_cnt,
            SUM(status='active')            AS active_cnt,
            SUM(status='warning')           AS warning_cnt,
            SUM(status='inactive')          AS inactive_cnt,
            SUM(status='archived')          AS archived_cnt,
            SUM(age_group='under6' AND status != 'archived')         AS under6_cnt,
            SUM(age_group='7_13' AND status != 'archived')           AS ag_7_13_cnt,
            SUM(age_group='14_17' AND status != 'archived')          AS ag_14_17_cnt,
            SUM(age_group='18_plus' AND status != 'archived')        AS ag_18_plus_cnt
         FROM members"
    );

    if (!empty($aggCounts)) {
        $row = $aggCounts[0];
        $totalMembers      = (int) ($row['total_members'] ?? 0);
        $memberTypeRegular = (int) ($row['regular_cnt'] ?? 0);
        $memberTypeHonor   = (int) ($row['honor_cnt'] ?? 0);
        $memberTypeWaiting = (int) ($row['waiting_cnt'] ?? 0);

        $genderCounts['male']   = (int) ($row['male_cnt'] ?? 0);
        $genderCounts['female'] = (int) ($row['female_cnt'] ?? 0);

        $statusCounts['active']   = (int) ($row['active_cnt'] ?? 0);
        $statusCounts['warning']  = (int) ($row['warning_cnt'] ?? 0);
        $statusCounts['inactive'] = (int) ($row['inactive_cnt'] ?? 0);
        $statusCounts['archived'] = (int) ($row['archived_cnt'] ?? 0);

        $sectionCounts['under6']   = (int) ($row['under6_cnt'] ?? 0);
        $sectionCounts['7_13']     = (int) ($row['ag_7_13_cnt'] ?? 0);
        $sectionCounts['14_17']    = (int) ($row['ag_14_17_cnt'] ?? 0);
        $sectionCounts['18_plus']  = (int) ($row['ag_18_plus_cnt'] ?? 0);
    }

    // Example "at risk" heuristic placeholder: warning + inactive (non-archived)
    $atRiskStudents = $statusCounts['warning'] + $statusCounts['inactive'];
}

// For display labels mapping
function sectionLabelFromGroup(?string $ageGroup): string
{
    return match ($ageGroup) {
        'under6'  => 'አጸደ ህጻናት',
        '7_13'    => 'ህጻናት',
        '14_17'   => 'ማዕከላዊያን',
        '18_plus' => 'ወጣቶች',
        default   => '',
    };
}

// ------------------------------------------------------------
// Recent members normalized for UI
// ------------------------------------------------------------
$recentMembers = array_map(function ($row) {
    $name = trim(($row['student_name'] ?? '') . ' ' . ($row['father_name'] ?? '') . ' ' . ($row['grandfather_name'] ?? ''));
    return [
        'name'   => $name ?: '—',
        'type'   => $row['member_type'] ?? 'መደበኛ',
        'status' => ucfirst($row['status'] ?? 'Active'),
        'section'=> $row['current_section'] ?? '',
        'date'   => !empty($row['created_at']) ? ethio_date_format(new DateTime($row['created_at'], new DateTimeZone('Africa/Addis_Ababa')), 'M j, Y') : '',
    ];
}, $recentMembers);

// ------------------------------------------------------------
// Helpers: next member code (simple illustrative logic)
// ------------------------------------------------------------
function generate_next_member_code(mysqli $conn): string
{
    $res = db_fetch_all_assoc($conn, "SELECT member_code FROM members WHERE member_code IS NOT NULL AND member_code <> '' ORDER BY id DESC LIMIT 1");
    $last = $res[0]['member_code'] ?? null;
    if (!$last) return '0001';
    $num = preg_replace('/\D/', '', $last);
    $next = str_pad((int)$num + 1, 4, '0', STR_PAD_LEFT);
    return $next;
}

$nextMemberCode = isset($conn) ? generate_next_member_code($conn) : '0001';

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title><?= DEPT_INFO_NAME_EN ?> - <?= SCHOOL_NAME_SHORT_AM ?></title>
    <?= wbws_calendar_scripts($conn) ?>
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    
    <!-- CSRF Token for AJAX requests -->
    <meta name="csrf-token" content="<?= generateCsrfToken() ?>">
    <script>
        // Global CSRF token for fetch requests
        const CSRF_TOKEN = '<?= generateCsrfToken() ?>';
    </script>

    <link rel="icon"
          href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><text y='.9em' font-size='90'>⛪</text></svg>">

    <script src="https://cdn.tailwindcss.com"></script>
    <script src="/admin/js/chart.umd.min.js"></script>
    <link rel="stylesheet"
          href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css"/>

    <style>
        @import url('https://fonts.googleapis.com/css2?family=Noto+Serif+Ethiopic:wght@400;600;700&family=Poppins:wght@300;400;600;700&display=swap');

        :root {
            --emerald-600: #059669;
            --emerald-700: #047857;
            --emerald-800: #065f46;
            --card-radius: 1.25rem;
        }

        body {
            font-family: 'Poppins', sans-serif;
            -webkit-font-smoothing: antialiased;
            background: #f5f7fb;
        }

        .amharic-text {
            font-family: 'Noto Serif Ethiopic', serif;
        }

        .sidebar-gradient {
            width: 260px;
            background: linear-gradient(180deg, #064e3b 0%, #047857 40%, #16a34a 100%);
            position: sticky;
            top: 0;
            align-self: flex-start;
            height: 100vh;
            overflow-y: auto;
            color: #ecfdf5;
            padding: 18px 16px 20px;
        }

        .stat-card {
            border-radius: 1.5rem;
            color: #ffffff;
            box-shadow: 0 12px 25px rgba(15, 118, 110, 0.35);
            transition: transform 0.18s ease-out, box-shadow 0.18s ease-out;
        }

        .stat-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 16px 32px rgba(15, 118, 110, 0.45);
        }

        .panel {
            border-radius: 1rem;
            background: #ffffff;
            box-shadow: 0 8px 20px rgba(148, 163, 184, 0.35);
        }

        .content-section { display: none; }
        .content-section.active { display: block; }

        /* Mobile / app-style tweaks */
        @media (max-width: 768px) {
            body {
                background: #eef2f7;
                padding-bottom: 76px; /* space for bottom nav */
            }

            .mobile-touch-target {
                min-height: 48px;
                min-width: 48px;
            }

            .mobile-card {
                border-radius: 1.25rem;
                box-shadow: 0 4px 12px rgba(0, 0, 0, 0.10);
                background: rgba(255, 255, 255, 0.92);
                backdrop-filter: blur(6px);
            }

            .panel {
                border-radius: 1.25rem;
            }
        }

        /* Settings tabs */
        .stab{padding:7px 14px;border-radius:10px;font-size:11px;font-weight:500;cursor:pointer;border:1px solid #e2e8f0;background:#fff;color:#64748b;transition:all .15s;white-space:nowrap}
        .stab:hover{background:#f8fafc;border-color:#cbd5e1}
        .stab-on{background:#0f172a!important;color:#fff!important;border-color:#0f172a!important}
        .settings-pane{animation:fadeSlide .25s ease-out}
        @keyframes fadeSlide{from{opacity:0;transform:translateY(6px)}to{opacity:1;transform:translateY(0)}}
        .sys-row{display:flex;justify-content:space-between;align-items:center;padding:8px 0;border-bottom:1px solid #f1f5f9}
        .sys-row:last-child{border-bottom:none}
        .sys-label{font-size:11px;color:#64748b}.sys-val{font-size:12px;font-weight:600;color:#1e293b}
        /* Attendance tabs */
        .atab{padding:7px 14px;border-radius:10px;font-size:11px;font-weight:500;cursor:pointer;border:1px solid #fed7aa;background:#fff;color:#9a3412;transition:all .15s;white-space:nowrap}
        .atab:hover{background:#fff7ed;border-color:#fb923c}
        .atab-on{background:#ea580c!important;color:#fff!important;border-color:#ea580c!important}
        .att-pane{animation:fadeSlide .25s ease-out}
        .att-badge{display:inline-flex;align-items:center;padding:2px 8px;border-radius:20px;font-size:9px;font-weight:600}
        .att-present{background:#d1fae5;color:#065f46}.att-absent{background:#fee2e2;color:#991b1b}
        .att-late{background:#fef3c7;color:#92400e}.att-excused{background:#dbeafe;color:#1e40af}

        .bottom-nav-shadow {
            box-shadow: 0 -4px 16px rgba(15, 23, 42, 0.25);
        }

        .nav-pill-active {
            background: rgba(255, 223, 0, 1);
        }

        /* Modal / sheet */
        .sheet {
            position: fixed;
            inset: 0;
            background: rgba(0, 0, 0, 0.5);
            display: none;
            align-items: flex-end;
            justify-content: center;
            z-index: 80;
        }
        .sheet.open { display: flex; }
        .sheet .sheet-body {
            width: 100%;
            max-width: 100%;
            height: 100%;
            background: #f8fafc;
            border-radius: 0;
            box-shadow: 0 -10px 40px rgba(0,0,0,0.2);
            overflow-y: auto;
            display: flex;
            flex-direction: column;
            animation: sheet-slide-up 0.3s ease-out;
        }

        @keyframes sheet-slide-up {
            from { transform: translateY(100%); opacity: 0; }
            to { transform: translateY(0); opacity: 1; }
        }

        /* Desktop: Side panel style */
        @media (min-width: 768px) {
            .sheet {
                align-items: stretch;
                justify-content: flex-end;
                background: rgba(0, 0, 0, 0.4);
            }
            .sheet .sheet-body {
                max-width: 800px;
                height: 100%;
                border-radius: 0;
                border-left: 1px solid #e2e8f0;
                animation: sheet-slide-left 0.3s ease-out;
            }
        }

        @keyframes sheet-slide-left {
            from { transform: translateX(100%); opacity: 0; }
            to { transform: translateX(0); opacity: 1; }
        }
        /* Desktop: Make it cover the main content area (right side) */
        @media (min-width: 768px) {
            .sheet {
                left: 16rem; /* Offset by sidebar width (w-64 = 16rem) */
                width: calc(100% - 16rem);
                background: transparent; /* No overlay needed if it covers content */
            }
            .sheet .sheet-body {
                max-width: 100%;
                border-left: 1px solid #e2e8f0;
            }
        }

        /* Animations */
        .toast-enter {
            animation: toast-in 0.25s ease-out;
        }
        @keyframes toast-in {
            from { transform: translateY(-16px); opacity: 0; }
            to   { transform: translateY(0); opacity: 1; }
        }

        /* Badge colors */
        .badge-active   { background: #ecfdf3; color: #166534; border: 1px solid #bbf7d0; }
        .badge-warning  { background: #fff7ed; color: #9a3412; border: 1px solid #fed7aa; }
        .badge-inactive { background: #f8fafc; color: #475569; border: 1px solid #cbd5e1; }
        .badge-archived { background: #e2e8f0; color: #334155; border: 1px solid #cbd5e1; }

        /* Advanced search pills */
        .filter-pill {
            border: 1px solid #e2e8f0;
            padding: 6px 10px;
            border-radius: 999px;
            font-size: 11px;
            color: #475569;
            cursor: pointer;
        }
        .filter-pill.active {
            background: #d1fae5;
            border-color: #34d399;
            color: #065f46;
        }

        /* Editable row icons */
        .action-btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 30px;
            height: 30px;
            border-radius: 10px;
            border: 1px solid #e2e8f0;
            background: #fff;
            transition: all 0.12s ease;
        }
        .action-btn:hover {
            transform: translateY(-1px);
            box-shadow: 0 6px 16px rgba(15,23,42,0.12);
            border-color: #cbd5e1;
        }

        /* Sticky top bar mobile */
        .mobile-sticky-header {
            position: sticky;
            top: 0;
            z-index: 30;
            background: linear-gradient(180deg, #047857 0%, #0f766e 100%);
            color: #fff;
            padding: 12px 16px;
        }

        /* Hide scrollbar for bottom nav */
        .hide-scrollbar {
            -ms-overflow-style: none;
            scrollbar-width: none;
        }
        .hide-scrollbar::-webkit-scrollbar {
            display: none;
        }

        /* Active bottom nav button */
        .nav-bottom-active {
            background: rgba(255, 255, 255, 0.2);
            opacity: 1 !important;
        }

        /* Inline labels */
        .label-soft {
            font-size: 10px;
            text-transform: uppercase;
            letter-spacing: 0.6px;
            color: #94a3b8;
            font-weight: 700;
        }
    </style>
<link rel="stylesheet" href="/admin/css/mobile.css">
<?php include __DIR__ . "/../theme.php"; ?>
</head>
<body class="bg-slate-100">

<div class="min-h-screen flex flex-col md:flex-row">

    <!-- Desktop Sidebar -->
    <aside class="hidden md:flex sidebar-gradient school-sidebar text-white w-64 flex-col py-5 px-4">
        <div class="flex items-center mb-6">
            <div class="w-11 h-11 rounded-2xl bg-white/15 flex items-center justify-center shadow-md">
                <i class="fa-solid fa-id-card-clip text-xl"></i>
            </div>
            <div class="ml-3">
                <div class="text-sm font-bold amharic-text">Information Department</div>
                <div class="text-[11px] text-emerald-100 amharic-text"><?= SCHOOL_NAME_SHORT_AM ?> <?= DEPT_INFO_NAME ?></div>
            </div>
        </div>

        <nav class="flex-1 flex flex-col space-y-1 text-sm">
            <a href="#" data-section="dashboard"
               class="mobile-touch-target flex items-center gap-3 px-3 py-2 rounded-xl bg-white/10 hover:bg-white/20 transition">
                <span class="w-8 h-8 rounded-xl bg-white/15 flex items-center justify-center">
                    <i class="fa-solid fa-gauge text-sm"></i>
                </span>
                <span class="font-semibold">Dashboard</span>
            </a>

            <a href="#" data-section="members"
               class="mobile-touch-target flex items-center gap-3 px-3 py-2 rounded-xl bg-white/10 hover:bg-white/20 transition">
                <span class="w-8 h-8 rounded-xl bg-white/15 flex items-center justify-center">
                    <i class="fa-solid fa-users text-sm"></i>
                </span>
                <span class="font-semibold">All Members</span>
            </a>

            <button data-section="manage"
                    class="mobile-touch-target flex items-center gap-3 px-3 py-2 rounded-xl bg-white/10 hover:bg-white/20 transition">
                <span class="w-8 h-8 rounded-xl bg-white/15 flex items-center justify-center">
                    <i class="fa-solid fa-pen-to-square text-sm"></i>
                </span>
                <span class="font-semibold">Manage Members</span>
            </button>

            <button data-section="archive"
                    class="mobile-touch-target flex items-center gap-3 px-3 py-2 rounded-xl bg-white/10 hover:bg-white/20 transition">
                <span class="w-8 h-8 rounded-xl bg-white/15 flex items-center justify-center">
                    <i class="fa-regular fa-folder-open text-sm"></i>
                </span>
                <span class="font-semibold">Old Members Archive</span>
            </button>

                <button data-section="idcards"
                        class="mobile-touch-target flex items-center gap-3 px-3 py-2 rounded-xl bg-white/10 hover:bg-white/20 transition">
                    <span class="w-8 h-8 rounded-xl bg-white/15 flex items-center justify-center">
                        <i class="fa-solid fa-id-card text-sm"></i>
                    </span>
                    <span class="font-semibold">ID Cards</span>
                </button>

            <a href="/admin/groups.php"
                    class="mobile-touch-target flex items-center gap-3 px-3 py-2 rounded-xl bg-white/10 hover:bg-white/20 transition">
                <span class="w-8 h-8 rounded-xl bg-white/15 flex items-center justify-center">
                    <i class="fa-solid fa-layer-group text-sm"></i>
                </span>
                <span class="font-semibold">Groups</span>
            </a>




            <button data-section="attendance"
                    class="mobile-touch-target flex items-center gap-3 px-3 py-2 rounded-xl bg-white/10 hover:bg-white/20 transition">
                <span class="w-8 h-8 rounded-xl bg-white/15 flex items-center justify-center">
                    <i class="fa-solid fa-clipboard-check text-sm"></i>
                </span>
                <span class="font-semibold">Attendance & Status</span>
            </button>

            <button data-section="reports"
                    class="mobile-touch-target flex items-center gap-3 px-3 py-2 rounded-xl bg-white/10 hover:bg-white/20 transition">
                <span class="w-8 h-8 rounded-xl bg-white/15 flex items-center justify-center">
                    <i class="fa-solid fa-file-lines text-sm"></i>
                </span>
                <span class="font-semibold">Exports & Reports</span>
            </button>

            <button data-section="settings"
                    class="mobile-touch-target flex items-center gap-3 px-3 py-2 rounded-xl bg-white/10 hover:bg-white/20 transition">
                <span class="w-8 h-8 rounded-xl bg-white/15 flex items-center justify-center">
                    <i class="fa-solid fa-gear text-sm"></i>
                </span>
                <span class="font-semibold">Settings</span>
            </button>

            <button data-section="attakers"
                    class="mobile-touch-target flex items-center gap-3 px-3 py-2 rounded-xl bg-white/10 hover:bg-white/20 transition">
                <span class="w-8 h-8 rounded-xl bg-amber-500/30 flex items-center justify-center">
                    <i class="fa-solid fa-user-check text-sm"></i>
                </span>
                <span class="font-semibold">Attendance Takers</span>
            </button>

        </nav>

        <div class="mt-5 space-y-2">
            <div class="flex items-center gap-2">
                <div class="w-9 h-9 rounded-full bg-white/90 flex items-center justify-center text-xs font-bold text-emerald-700">
                    <?= strtoupper(substr($userName, 0, 1)) ?>
                </div>
                <div class="text-[11px] leading-tight">
                    <div class="font-semibold truncate max-w-[150px]"><?= e($userName) ?></div>
                    <div class="uppercase text-[10px] text-emerald-100"><?= e($userRole) ?></div>
                </div>
            </div>
            <a href="/admin/logout.php"
               class="w-full flex items-center justify-center gap-2 px-3 py-2 rounded-xl bg-red-500/95 text-white text-xs font-semibold shadow hover:bg-red-600 transition">
                <span>Logout</span>
                <i class="fa-solid fa-power-off text-xs"></i>
            </a>
        </div>
    </aside>

    <!-- Main -->
    <div class="flex-1 flex flex-col">
        <!-- Mobile top header -->
        <div class="mobile-sticky-header md:hidden flex items-center justify-between">
            <div class="flex items-center gap-3">
                <div class="w-10 h-10 bg-white/15 rounded-full flex items-center justify-center">
                    <i class="fa-solid fa-circle-info text-lg"></i>
                </div>
                <div>
                    <div class="text-sm font-bold">Information Department</div>
                    <div class="text-[11px] text-emerald-100 amharic-text">የመረጃ ቁጥጥር · መመዝገብ · ሪፖርት</div>
                </div>
            </div>
            <div class="text-right text-[11px]">
                <div class="font-semibold"><?= e($todayFormatted) ?></div>
                <div class="text-emerald-100"><?= e($userName) ?></div>
            </div>
        </div>

        <header class="hidden md:flex bg-emerald-700 text-white px-3 sm:px-6 py-3 sm:py-4 items-center justify-between shadow-md">
            <div class="flex items-center gap-3">
                <button class="md:hidden w-9 h-9 rounded-full bg-white/10 flex items-center justify-center">
                    <i class="fa-solid fa-bars text-sm"></i>
                </button>
                <div>
                    <div class="flex items-center gap-2">
                        <span class="w-8 h-8 rounded-full bg-white/15 flex items-center justify-center">
                            <i class="fa-solid fa-circle-info text-lg"></i>
                        </span>
                        <div>
                            <h1 class="text-base sm:text-lg font-bold amharic-text">
                                Information Department
                            </h1>
                            <p class="text-[11px] sm:text-xs text-emerald-100 amharic-text">
                                የመረጃ ቁጥጥር · የአባላት መመዝገብ እና ሪፖርት
                            </p>
                        </div>
                    </div>
                </div>
            </div>

            <div class="flex items-center gap-3">
                <!-- Notification Bell -->
                <?php 
                include __DIR__ . '/../components/notification_bell.php';
                echo renderNotificationBell();
                ?>
                
                <div class="hidden sm:flex flex-col text-right text-xs">
                    <span class="font-semibold"><?= e($todayFormatted) ?></span>
                    <span class="text-emerald-100"><?= e($userName) ?></span>
                </div>
                <div class="w-9 h-9 rounded-full bg-white/95 flex items-center justify-center text-xs font-bold text-emerald-700 sm:hidden">
                    <?= strtoupper(substr($userName, 0, 1)) ?>
                </div>
            </div>
        </header>

        <main class="flex-1 p-3 sm:p-5 space-y-4 sm:space-y-5">

            <!-- DASHBOARD -->
            <section id="section-dashboard" class="content-section active">
                <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-2 mb-2 sm:mb-3">
                    <div>
                        <div class="flex items-center gap-2 text-emerald-700">
                            <i class="fa-solid fa-gauge-high text-sm"></i>
                            <span class="uppercase tracking-wide text-[11px] font-semibold">Dashboard</span>
                        </div>
                        <h2 class="text-lg sm:text-xl font-bold text-slate-800">
                            Information Department Overview
                        </h2>
                    </div>
                    <div class="flex items-center gap-2 text-xs">
                        <span class="px-3 py-1.5 rounded-full border border-emerald-200 text-emerald-700 bg-white">
                            Live statistics
                        </span>
                    </div>
                </div>

                <div class="grid grid-cols-2 lg:grid-cols-4 gap-3 sm:gap-4 mb-4">
                    <div class="stat-card p-3 sm:p-4 flex flex-col justify-between" style="background:linear-gradient(135deg, <?= THEME_PRIMARY_DARK ?>, <?= THEME_PRIMARY ?>, <?= THEME_PRIMARY_LIGHT ?>)">
                        <div class="flex items-center justify-between">
                            <div class="text-xs uppercase tracking-wide">Total Members</div>
                            <div class="w-8 h-8 rounded-2xl bg-white/15 flex items-center justify-center">
                                <i class="fa-solid fa-users text-sm"></i>
                            </div>
                        </div>
                        <div class="mt-2 text-2xl sm:text-3xl font-bold">
                            <?= $totalMembers ?>
                        </div>
                        <div class="mt-1 text-[11px] sm:text-xs opacity-80">
                            Active (non-archived) members
                        </div>
                    </div>

                    <div class="stat-card p-3 sm:p-4 flex flex-col justify-between" style="background:linear-gradient(135deg, <?= THEME_ACCENT ?>, <?= THEME_ACCENT_2 ?>)">
                        <div class="flex items-center justify-between">
                            <div class="text-xs uppercase tracking-wide">By Member Type</div>
                            <div class="w-8 h-8 rounded-2xl bg-white/15 flex items-center justify-center">
                                <i class="fa-solid fa-tags text-sm"></i>
                            </div>
                        </div>
                        <div class="mt-2 text-[11px] sm:text-xs space-y-0.5">
                            <div class="flex justify-between">
                                <span>መደበኛ</span>
                                <span class="font-semibold"><?= $memberTypeRegular ?></span>
                            </div>
                            <div class="flex justify-between">
                                <span>የመጠባበቂያ</span>
                                <span class="font-semibold"><?= $memberTypeWaiting ?></span>
                            </div>
                            <div class="flex justify-between">
                                <span>የክብር አባላት</span>
                                <span class="font-semibold"><?= $memberTypeHonor ?></span>
                            </div>
                        </div>
                    </div>

                    <div class="stat-card bg-gradient-to-tr from-amber-500 via-orange-400 to-yellow-400 p-3 sm:p-4 flex flex-col justify-between">
                        <div class="flex items-center justify-between">
                            <div class="text-xs uppercase tracking-wide">By Status</div>
                            <div class="w-8 h-8 rounded-2xl bg-white/15 flex items-center justify-center">
                                <i class="fa-solid fa-heart-pulse text-sm"></i>
                            </div>
                        </div>
                        <div class="mt-2 text-[11px] sm:text-xs space-y-0.5">
                            <div class="flex justify-between">
                                <span>Active</span>
                                <span class="font-semibold"><?= $statusCounts['active'] ?></span>
                            </div>
                            <div class="flex justify-between">
                                <span>Warning</span>
                                <span class="font-semibold"><?= $statusCounts['warning'] ?></span>
                            </div>
                            <div class="flex justify-between">
                                <span>Inactive</span>
                                <span class="font-semibold"><?= $statusCounts['inactive'] ?></span>
                            </div>
                            <div class="flex justify-between">
                                <span>Archived</span>
                                <span class="font-semibold"><?= $statusCounts['archived'] ?></span>
                            </div>
                        </div>
                    </div>

                    <div class="stat-card bg-gradient-to-tr from-red-500 via-rose-500 to-orange-400 p-3 sm:p-4 flex flex-col justify-between">
                        <div class="flex items-center justify-between">
                            <div class="text-xs uppercase tracking-wide">At-risk Students</div>
                            <div class="w-8 h-8 rounded-2xl bg-white/15 flex items-center justify-center">
                                <i class="fa-solid fa-triangle-exclamation text-sm"></i>
                            </div>
                        </div>
                        <div class="mt-2 text-2xl sm:text-3xl font-bold">
                            <?= $atRiskStudents ?>
                        </div>
                        <div class="mt-1 text-[11px] sm:text-xs opacity-80">
                            Warning + inactive (non-archived)
                        </div>
                    </div>
                </div>

                <div class="grid grid-cols-1 lg:grid-cols-2 gap-4 mb-4">
                    <div class="panel p-4 mobile-card">
                        <div class="flex items-center justify-between mb-2">
                            <div>
                                <h3 class="text-sm font-semibold text-slate-800 flex items-center gap-2">
                                    <span class="w-7 h-7 rounded-xl bg-emerald-100 flex items-center justify-center text-emerald-600">
                                        <i class="fa-solid fa-child"></i>
                                    </span>
                                    <span>Section Distribution (by current section)</span>
                                </h3>
                                <p class="text-[11px] text-slate-500">
                                    Live numbers from members table
                                </p>
                            </div>
                        </div>

                        <div class="grid grid-cols-2 gap-3 mt-3">
                            <div class="p-3 rounded-2xl bg-emerald-50 border border-emerald-100">
                                <div class="text-[11px] text-emerald-700 amharic-text">አጸደ ህጻናት</div>
                                <div class="text-xl font-bold text-emerald-900 mt-1"><?= $sectionCounts['under6'] ?></div>
                                <div class="text-[11px] text-emerald-500">Section</div>
                            </div>
                            <div class="p-3 rounded-2xl bg-sky-50 border border-sky-100">
                                <div class="text-[11px] text-sky-700 amharic-text">ህጻናት</div>
                                <div class="text-xl font-bold text-sky-900 mt-1"><?= $sectionCounts['7_13'] ?></div>
                                <div class="text-[11px] text-sky-500">Section</div>
                            </div>
                            <div class="p-3 rounded-2xl bg-amber-50 border border-amber-100">
                                <div class="text-[11px] text-amber-700 amharic-text">ማዕከላዊያን</div>
                                <div class="text-xl font-bold text-amber-900 mt-1"><?= $sectionCounts['14_17'] ?></div>
                                <div class="text-[11px] text-amber-500">Section</div>
                            </div>
                            <div class="p-3 rounded-2xl bg-rose-50 border border-rose-100">
                                <div class="text-[11px] text-rose-700 amharic-text">ወጣቶች</div>
                                <div class="text-xl font-bold text-rose-900 mt-1"><?= $sectionCounts['18_plus'] ?></div>
                                <div class="text-[11px] text-rose-500">Section</div>
                            </div>
                        </div>

                        <div class="mt-4 h-28">
                            <canvas id="sectionChart"></canvas>
                        </div>
                    </div>

                    <div class="panel p-4 mobile-card">
                        <div class="flex items-center justify-between mb-2">
                            <div>
                                <h3 class="text-sm font-semibold text-slate-800 flex items-center gap-2">
                                    <span class="w-7 h-7 rounded-xl bg-sky-100 flex items-center justify-center text-sky-600">
                                        <i class="fa-solid fa-venus-mars"></i>
                                    </span>
                                    <span>Gender Distribution</span>
                                </h3>
                                <p class="text-[11px] text-slate-500">
                                    Visual balance of male vs female members
                                </p>
                            </div>
                        </div>

                        <div class="mt-3 h-28">
                            <canvas id="genderChart"></canvas>
                        </div>

                        <div class="mt-3 grid grid-cols-2 gap-2 text-[11px]">
                            <div class="flex items-center gap-2">
                                <span class="w-2 h-2 rounded-full bg-sky-500"></span>
                                <span>Male</span>
                                <span class="ml-auto font-semibold"><?= $genderCounts['male'] ?></span>
                            </div>
                            <div class="flex items-center gap-2">
                                <span class="w-2 h-2 rounded-full bg-rose-500"></span>
                                <span>Female</span>
                                <span class="ml-auto font-semibold"><?= $genderCounts['female'] ?></span>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="panel p-4 mobile-card">
                    <div class="flex items-center justify-between mb-3">
                        <div>
                            <h3 class="text-sm font-semibold text-slate-800 flex items-center gap-2">
                                <span class="w-7 h-7 rounded-xl bg-emerald-100 flex items-center justify-center text-emerald-600">
                                    <i class="fa-solid fa-clock-rotate-left"></i>
                                </span>
                                <span>Recent Members</span>
                            </h3>
                            <p class="text-[11px] text-slate-500">
                                Latest registrations handled by the Information Department
                            </p>
                        </div>
                        <button class="hidden sm:inline-flex items-center gap-1 text-[11px] text-emerald-700 hover:text-emerald-900">
                            View all →
                        </button>
                    </div>

                    <div class="overflow-x-auto -mx-2 sm:mx-0">
                        <table class="min-w-full text-[11px] sm:text-xs">
                            <thead>
                            <tr class="text-left text-slate-500 border-b border-slate-200">
                                <th class="py-2 px-2 sm:px-3">Name</th>
                                <th class="py-2 px-2 sm:px-3">Type</th>
                                <th class="py-2 px-2 sm:px-3">Status</th>
                                <th class="py-2 px-2 sm:px-3">Section</th>
                                <th class="py-2 px-2 sm:px-3">Registered</th>
                            </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100">
                            <?php foreach ($recentMembers as $row): ?>
                                <tr class="hover:bg-slate-50">
                                    <td class="py-2 px-2 sm:px-3 amharic-text"><?= e($row['name']) ?></td>
                                    <td class="py-2 px-2 sm:px-3 amharic-text">
                                        <?= e($row['type']) ?>
                                    </td>
                                    <td class="py-2 px-2 sm:px-3">
                                        <?php
                                        $statusChip = 'badge-active';
                                        if (stripos($row['status'], 'Warning') !== false) $statusChip = 'badge-warning';
                                        if (stripos($row['status'], 'Inactive') !== false) $statusChip = 'badge-inactive';
                                        ?>
                                        <span class="chip <?= $statusChip ?>"><?= e($row['status']) ?></span>
                                    </td>
                                    <td class="py-2 px-2 sm:px-3 amharic-text">
                                        <?= esc($row['section'], '—') ?>
                                    </td>
                                    <td class="py-2 px-2 sm:px-3">
                                        <?= e($row['date'] ?? '') ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </section>

            <!-- ALL MEMBERS -->
            <section id="section-members" class="content-section">
                <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 mb-3">
                    <div>
                        <h3 class="text-sm font-semibold text-slate-800 flex items-center gap-2">
                            <span class="w-7 h-7 rounded-xl bg-emerald-100 flex items-center justify-center text-emerald-600">
                                <i class="fa-solid fa-users"></i>
                            </span>
                            <span>All Members</span>
                        </h3>
                        <p class="text-[11px] text-slate-500">
                            Full registration and management for Information Department members.
                        </p>
                    </div>
                    <div class="flex gap-2">
                        <button type="button"
                                onclick="toggleMemberRegistrationForm(true)"
                                id="toggleMemberFormBtn"
                                class="mobile-touch-target inline-flex items-center justify-center gap-2 px-4 py-2 rounded-xl text-white text-xs sm:text-sm font-semibold shadow active:scale-95" style="background:linear-gradient(135deg, <?= THEME_PRIMARY ?>, <?= THEME_PRIMARY_LIGHT ?>)">
                            <i class="fa-solid fa-user-plus text-xs"></i>
                            <span>Register New Member</span>
                        </button>
                    </div>
                </div>

                <div id="membersListPlaceholder" class="space-y-4">
                    <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
                        <div>
                            <h2 class="text-base md:text-lg font-semibold text-slate-900 amharic-text">
                                ሁሉም አባላት (All Members)
                            </h2>
                            <p class="text-xs text-slate-500">
                                Showing latest <?php echo count($membersList); ?> members. Use search and filters to narrow down.
                            </p>
                        </div>

                        <div class="flex flex-wrap gap-2 md:justify-end w-full md:w-auto">
                            <div class="relative flex-1 min-w-[240px]">
                                <input id="memberSearchInput"
                                       type="text"
                                       class="pl-9 pr-3 py-2 rounded-xl border border-slate-200 text-xs bg-white focus:outline-none focus:ring-2 focus:ring-emerald-500 focus:border-emerald-500 w-full"
                                       placeholder="Search any field (name, code, phone, profession, education, city...)">
                                <span class="absolute left-3 top-2.5 text-slate-400 text-sm">🔍</span>
                            </div>

                            <div class="flex flex-wrap gap-2">
                                <select id="filterRegistrationType"
                                        class="text-xs border border-slate-200 rounded-xl px-2 py-2 bg-white focus:outline-none focus:ring-1 focus:ring-emerald-500">
                                    <option value="">Reg. Type (All)</option>
                                    <option value="waiting">Waiting</option>
                                    <option value="transfer">Transfer</option>
                                    <option value="direct">Direct</option>
                                </select>

                                <select id="filterMemberType"
                                        class="text-xs border border-slate-200 rounded-xl px-2 py-2 bg-white focus:outline-none focus:ring-1 focus:ring-emerald-500">
                                    <option value="">Member Type (All)</option>
                                    <option value="regular">Regular</option>
                                    <option value="special_regular">Special Regular</option>
                                    <option value="honorary">Honorary</option>
                                </select>

                                <select id="filterStatus"
                                        class="text-xs border border-slate-200 rounded-xl px-2 py-2 bg-white focus:outline-none focus:ring-1 focus:ring-emerald-500">
                                    <option value="">Status (All)</option>
                                    <option value="active">Active</option>
                                    <option value="warning">Warning</option>
                                    <option value="inactive">Inactive</option>
                                    <option value="archived">Archived</option>
                                </select>

                                <select id="filterGender"
                                        class="text-xs border border-slate-200 rounded-xl px-2 py-2 bg-white focus:outline-none focus:ring-1 focus:ring-emerald-500">
                                    <option value="">Gender (All)</option>
                                    <option value="male">Male</option>
                                    <option value="female">Female</option>
                                </select>

                                <select id="filterAgeGroup"
                                        class="text-xs border border-slate-200 rounded-xl px-2 py-2 bg-white focus:outline-none focus:ring-1 focus:ring-emerald-500">
                                    <option value="">Section (All)</option>
                                    <option value="under6">አጸደ ህጻናት</option>
                                    <option value="7_13">ህጻናት</option>
                                    <option value="14_17">ማዕከላዊያን</option>
                                    <option value="18_plus">ወጣቶች</option>
                                </select>
                            </div>
                        </div>
                    </div>

                    <div class="bg-white/90 backdrop-blur rounded-2xl border border-slate-100 shadow-sm overflow-hidden">
                        <div class="overflow-x-auto">
                            <table class="min-w-full text-xs">
                                <thead class="bg-slate-50 border-b border-slate-100">
                                <tr class="text-[11px] text-slate-500">
                                    <th class="px-3 py-2 text-left">#</th>
                                    <th class="px-3 py-2 text-left">Member</th>
                                    <th class="px-3 py-2 text-left">Code</th>
                                    <th class="px-3 py-2 text-left">Reg. Type</th>
                                    <th class="px-3 py-2 text-left">Member Type</th>
                                    <th class="px-3 py-2 text-left">Gender</th>
                                    <th class="px-3 py-2 text-left">Section</th>
                                    <th class="px-3 py-2 text-left">Status</th>
                                    <th class="px-3 py-2 text-left">Phone</th>
                                    <th class="px-3 py-2 text-left">Location</th>
                                    <th class="px-3 py-2 text-left">Actions</th>
                                </tr>
                                </thead>
                                <tbody id="membersTableBody" class="divide-y divide-slate-100">
                                <?php if (empty($membersList)): ?>
                                    <tr>
                                        <td colspan="11" class="px-3 py-6 text-center text-slate-400 text-xs">
                                            No members found yet. Register the first member to see them here.
                                        </td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($membersList as $index => $m):
                                        $fullName = trim(($m['student_name'] ?? '') . ' ' . ($m['father_name'] ?? '') . ' ' . ($m['grandfather_name'] ?? ''));
                                        $location = trim(($m['city'] ?? '') . ' / ' . ($m['sub_city'] ?? ''));
                                        $sectionLabel = sectionLabelFromGroup($m['age_group'] ?? '');
                                        $searchBlob = strtolower(
                                            implode(' ', [
                                                $fullName,
                                                $m['member_code'] ?? '',
                                                $m['phone_number'] ?? '',
                                                $m['alt_phone_number'] ?? '',
                                                $m['work_profession'] ?? '',
                                                $m['education_level'] ?? '',
                                                $m['city'] ?? '',
                                                $m['sub_city'] ?? '',
                                                $sectionLabel,
                                                $m['registration_type'] ?? '',
                                                $m['member_type'] ?? '',
                                                $m['status'] ?? '',
                                            ])
                                        );
                                        ?>
                                        <tr class="member-row hover:bg-emerald-50/40 transition"
                                            data-search="<?= e($searchBlob) ?>"
                                            data-regtype="<?= e($m['registration_type'] ?? '') ?>"
                                            data-mtype="<?= e($m['member_type'] ?? '') ?>"
                                            data-status="<?= e($m['status'] ?? '') ?>"
                                            data-gender="<?= e($m['gender'] ?? '') ?>"
                                            data-agegroup="<?= e($m['age_group'] ?? '') ?>">
                                            <td class="px-3 py-2 text-[11px] text-slate-400">
                                                <?php echo $index + 1; ?>
                                            </td>
                                            <td class="px-3 py-2">
                                                <div class="flex flex-col">
                                                    <span class="text-[11px] font-medium text-slate-900 amharic-text">
                                                        <?php echo esc($fullName, '—'); ?>
                                                    </span>
                                                    <span class="text-[10px] text-slate-400">
                                                        <?php echo e($m['current_section'] ?? $sectionLabel); ?>
                                                    </span>
                                                </div>
                                            </td>
                                            <td class="px-3 py-2 text-[11px] text-slate-700">
                                                <?php echo esc($m['member_code'], 'Pending'); ?>
                                            </td>
                                            <td class="px-3 py-2 text-[11px]">
                                                <?php
                                                $rt = $m['registration_type'] ?? '';
                                                $rtLabel = $rt === 'transfer' ? 'Transfer'
                                                    : ($rt === 'direct' ? 'Direct' : 'Waiting');
                                                $rtColor = $rt === 'transfer' ? 'bg-blue-100 text-blue-700'
                                                    : ($rt === 'direct' ? 'bg-emerald-100 text-emerald-700'
                                                        : 'bg-amber-100 text-amber-700');
                                                ?>
                                                <span class="inline-flex px-2 py-0.5 rounded-full text-[10px] font-medium <?php echo $rtColor; ?>">
                                                    <?php echo $rtLabel; ?>
                                                </span>
                                            </td>
                                            <td class="px-3 py-2 text-[11px] text-slate-700">
                                                <?php
                                                $mt = $m['member_type'] ?? '';
                                                $mtLabel = $mt === 'special_regular' ? 'Special Regular'
                                                    : ($mt === 'honorary' ? 'Honorary' : 'Regular');
                                                ?>
                                                <?php echo $mtLabel; ?>
                                            </td>
                                            <td class="px-3 py-2 text-[11px] text-slate-700">
                                                <?php echo $m['gender'] === 'female' ? 'Female' : 'Male'; ?>
                                            </td>
                                            <td class="px-3 py-2 text-[11px] text-slate-700">
                                                <?php echo e($sectionLabel); ?>
                                            </td>
                                            <td class="px-3 py-2 text-[11px]">
                                                <?php
                                                $st = $m['status'] ?? 'active';
                                                $stLabel = ucfirst($st);
                                                $stColor = $st === 'inactive' ? 'badge-inactive'
                                                    : ($st === 'warning' ? 'badge-warning'
                                                        : ($st === 'archived' ? 'badge-archived'
                                                            : 'badge-active'));
                                                ?>
                                                <span class="chip <?= $stColor ?>">
                                                    <?php echo $stLabel; ?>
                                                </span>
                                            </td>
                                            <td class="px-3 py-2 text-[11px] text-slate-700">
                                                <?php echo e($m['phone_number'] ?? ''); ?>
                                            </td>
                                            <td class="px-3 py-2 text-[11px] text-slate-500">
                                                <?php echo e($location ?? ''); ?>
                                            </td>
                                            <td class="px-3 py-2 text-[11px] text-slate-500">
                                                <div class="flex gap-1">
                                                    <button class="action-btn" title="Edit"
                                                            onclick="openManageSheet(<?= (int)$m['id'] ?>)">
                                                        <i class="fa-solid fa-pen text-[11px] text-emerald-600"></i>
                                                    </button>
                                                                     <a class="action-btn" title="Generate ID"
                                                                         href="/admin/id_cards/view_id_card.php?member_id=<?= (int)$m['id'] ?>"
                                                       target="_blank"
                                                       rel="noopener noreferrer">
                                                        <i class="fa-solid fa-id-card text-[11px] text-slate-700"></i>
                                                    </a>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                        <div class="px-3 py-2 border-t border-slate-100 text-[10px] text-slate-400 flex items-center justify-between">
                            <span>Showing latest <?php echo count($membersList); ?> records</span>
                            <span id="membersVisibleCount"></span>
                        </div>
                    </div>
                </div>

                <!-- Registration form -->
                <div id="memberRegistrationWrapper" class="panel p-4 sm:p-5 mobile-card hidden">
                    <form id="memberRegistrationForm"
                          method="post"
                          action="info_register_member.php"
                          enctype="multipart/form-data"
                          onsubmit="handleMemberFormSubmitWithCheck(event)">

                        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-2 mb-4">
                            <div>
                                <h3 class="text-sm font-semibold text-slate-800 flex items-center gap-2">
                                    <span class="w-7 h-7 rounded-xl bg-emerald-100 flex items-center justify-center text-emerald-600">
                                        <i class="fa-solid fa-user-pen text-xs"></i>
                                    </span>
                                    <span>Register New Member</span>
                                </h3>
                                <p class="text-[11px] text-slate-500">
                                    Live logic: Waiting has pending ID; Direct/Transfer auto-assign ID + card-ready.
                                </p>
                            </div>
                            <button type="button"
                                    onclick="toggleMemberRegistrationForm(false)"
                                    class="inline-flex items-center gap-1 px-3 py-1.5 rounded-full border border-slate-200 text-[11px] text-slate-600 hover:bg-slate-50">
                                <i class="fa-solid fa-xmark text-[10px]"></i>
                                <span>Close form</span>
                            </button>
                        </div>

                        <div class="grid grid-cols-1 lg:grid-cols-2 gap-4 mb-4">
                            <div class="bg-slate-50 border border-slate-200 rounded-xl p-3 sm:p-4">
                                <h4 class="text-xs font-semibold text-slate-800 mb-2 flex items-center gap-2">
                                    <span class="w-6 h-6 rounded-lg bg-slate-200 flex items-center justify-center">
                                        <i class="fa-solid fa-barcode text-[10px]"></i>
                                    </span>
                                    <span>Auto Member ID</span>
                                </h4>

                                <p class="text-[11px] text-slate-500 mb-1">
                                    Next Member ID:
                                    <span id="nextMemberIdDisplay" class="font-semibold text-slate-800"><?= e($nextMemberCode) ?></span>
                                </p>
                                <p class="text-[10px] text-slate-400">
                                    Waiting-list members will not get an ID until they complete 3 months.
                                </p>

                                <input type="hidden" name="student_id" id="studentIdField" value="">
                            </div>

                            <div class="bg-emerald-50 border border-emerald-100 rounded-xl p-3 sm:p-4">
                                <h4 class="text-xs font-semibold text-emerald-900 mb-2 flex items-center gap-2">
                                    <span class="w-6 h-6 rounded-lg bg-emerald-100 flex items-center justify-center">
                                        <i class="fa-solid fa-clipboard-check text-[10px]"></i>
                                    </span>
                                    <span>Registration Background *</span>
                                </h4>

                                <div class="grid grid-cols-2 sm:grid-cols-4 gap-2">
                                    <button type="button"
                                            class="registration-type-btn w-full text-left px-3 py-2 rounded-xl border border-emerald-300 bg-white text-[11px] sm:text-xs"
                                            data-registration-type="waiting"
                                            onclick="selectRegistrationType('waiting', this)">
                                        <div class="amharic-text text-[13px]">አዲስ ተመዝጋቢ</div>
                                        <div class="text-[10px] text-slate-500">Waiting (list)</div>
                                    </button>

                                    <button type="button"
                                            class="registration-type-btn w-full text-left px-3 py-2 rounded-xl border border-slate-200 bg-white text-[11px] sm:text-xs"
                                            data-registration-type="transfer"
                                            onclick="selectRegistrationType('transfer', this)">
                                        <div class="amharic-text text-[13px]">የተዛወረ</div>
                                        <div class="text-[10px] text-slate-500">Transfer</div>
                                    </button>

                                    <button type="button"
                                            class="registration-type-btn w-full text-left px-3 py-2 rounded-xl border border-slate-200 bg-white text-[11px] sm:text-xs"
                                            data-registration-type="direct"
                                            onclick="selectRegistrationType('direct', this)">
                                        <div class="amharic-text text-[13px]">ቀጥታ መመዝገብ</div>
                                        <div class="text-[10px] text-slate-500">Direct</div>
                                    </button>
                                </div>

                                <input type="hidden" name="registration_type" id="registrationTypeField" value="waiting">
                            </div>
                        </div>

                        <div class="grid grid-cols-1 lg:grid-cols-2 gap-4 mb-4">
                            <div class="bg-emerald-50 border border-emerald-100 rounded-xl p-3 sm:p-4">
                                <h4 class="text-xs font-semibold text-emerald-900 mb-2 flex items-center gap-2">
                                    <span class="w-6 h-6 rounded-lg bg-emerald-100 flex items-center justify-center">
                                        <i class="fa-solid fa-user-tag text-[10px]"></i>
                                    </span>
                                    <span>Member Type *</span>
                                </h4>

                                <div class="grid grid-cols-1 sm:grid-cols-3 gap-2">
                                    <button type="button"
                                            class="member-type-btn w-full text-left px-3 py-2 rounded-xl border border-emerald-300 bg-white text-[11px] sm:text-xs"
                                            data-member-type="regular"
                                            onclick="selectMemberTypeFull('regular', this)">
                                        <div class="amharic-text text-[13px]">መደበኛ</div>
                                        <div class="text-[10px] text-slate-500">Regular</div>
                                    </button>

                                    <button type="button"
                                            class="member-type-btn w-full text-left px-3 py-2 rounded-xl border border-slate-200 bg-white text-[11px] sm:text-xs"
                                            data-member-type="special_regular"
                                            onclick="selectMemberTypeFull('special_regular', this)">
                                        <div class="amharic-text text-[13px]">ልዩ መደበኛ</div>
                                        <div class="text-[10px] text-slate-500">Student + role</div>
                                    </button>

                                    <button type="button"
                                            class="member-type-btn w-full text-left px-3 py-2 rounded-xl border border-slate-200 bg-white text-[11px] sm:text-xs"
                                            data-member-type="honorary"
                                            onclick="selectMemberTypeFull('honorary', this)">
                                        <div class="amharic-text text-[13px]">የክብር አባላት</div>
                                        <div class="text-[10px] text-slate-500">Honorary</div>
                                    </button>
                                </div>

                                <input type="hidden" name="member_type" id="memberTypeFieldFull" value="regular">
                            </div>

                            <div class="bg-slate-50 border border-slate-200 rounded-xl p-3 sm:p-4">
                                <h4 class="text-xs font-semibold text-slate-800 mb-2 flex items-center gap-2">
                                    <span class="w-6 h-6 rounded-lg bg-slate-200 flex items-center justify-center">
                                        <i class="fa-solid fa-circle-info text-[10px]"></i>
                                    </span>
                                    <span>Status</span>
                                </h4>

                                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                                    <div>
                                        <label class="block text-[11px] font-medium text-slate-700 mb-1">
                                            Member Status
                                        </label>
                                        <select name="status"
                                                class="mobile-touch-target w-full px-3 py-2 rounded-xl border border-slate-200 text-xs focus:ring-emerald-200 focus:border-emerald-400">
                                            <option value="active">Active</option>
                                            <option value="warning">Warning</option>
                                            <option value="inactive">Inactive</option>
                                        </select>
                                    </div>

                                    <div class="text-[10px] text-slate-500 flex items-center">
                                        <span>Section equals age group (auto from DOB).</span>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Registration Date for Existing Members -->
                        <div class="bg-blue-50 border border-blue-200 rounded-xl p-3 sm:p-4 mb-4">
                            <h4 class="text-xs font-semibold text-blue-900 mb-2 flex items-center gap-2">
                                <span class="w-6 h-6 rounded-lg bg-blue-200 flex items-center justify-center">
                                    <i class="fa-solid fa-calendar-plus text-[10px]"></i>
                                </span>
                                <span>Original Registration Date (For Existing Members)</span>
                            </h4>
                            <p class="text-[10px] text-blue-700 mb-3">
                                <i class="fa-solid fa-info-circle mr-1"></i>
                                If this member was registered before the system was created, enter their original registration date. Leave empty for new members (today's date will be used).
                            </p>

                            <div class="flex items-center gap-3 mb-3">
                                <label class="inline-flex items-center gap-2 cursor-pointer">
                                    <input type="checkbox" id="useCustomRegDate" onchange="toggleCustomRegDate()" 
                                           class="rounded border-blue-300 text-blue-600 focus:ring-blue-200">
                                    <span class="text-xs font-medium text-blue-800">Set custom registration date</span>
                                </label>
                            </div>

                            <div id="customRegDateFields" class="hidden">
                                <label class="block text-[11px] font-medium text-slate-700 mb-1">
                                    Registration Date (Ethiopian Calendar - E.C.)
                                </label>
                                <div class="grid grid-cols-3 gap-2">
                                    <input type="number" name="reg_date_day" id="regDateDay"
                                           min="1" max="30"
                                           class="mobile-touch-target w-full px-3 py-2 rounded-xl border border-blue-200 text-xs focus:ring-blue-200 focus:border-blue-400 bg-white"
                                           placeholder="Day">

                                    <select name="reg_date_month" id="regDateMonth"
                                            class="mobile-touch-target w-full px-3 py-2 rounded-xl border border-blue-200 text-xs focus:ring-blue-200 focus:border-blue-400 bg-white">
                                        <option value="">Month</option>
                                        <option value="1">መስከረም (1)</option>
                                        <option value="2">ጥቅምት (2)</option>
                                        <option value="3">ኅዳር (3)</option>
                                        <option value="4">ታኅሣሥ (4)</option>
                                        <option value="5">ጥር (5)</option>
                                        <option value="6">የካቲት (6)</option>
                                        <option value="7">መጋቢት (7)</option>
                                        <option value="8">ሚያዝያ (8)</option>
                                        <option value="9">ግንቦት (9)</option>
                                        <option value="10">ሰኔ (10)</option>
                                        <option value="11">ሐምሌ (11)</option>
                                        <option value="12">ነሐሴ (12)</option>
                                        <option value="13">ጳጉሜ (13)</option>
                                    </select>

                                    <input type="number" name="reg_date_year" id="regDateYear"
                                           min="1990" max="2020"
                                           class="mobile-touch-target w-full px-3 py-2 rounded-xl border border-blue-200 text-xs focus:ring-blue-200 focus:border-blue-400 bg-white"
                                           placeholder="Year (E.C.)">
                                </div>
                                <p class="mt-2 text-[10px] text-blue-600">
                                    <i class="fa-solid fa-calculator mr-1"></i>
                                    <span id="regDateDuration">Enter date to see membership duration</span>
                                </p>
                            </div>
                        </div>

                        <div id="roleFlagsSection"
                             class="bg-amber-50 border border-amber-200 rounded-xl p-3 sm:p-4 mb-4 hidden">
                            <h4 class="text-xs font-semibold text-amber-900 mb-2 flex items-center gap-2">
                                <span class="w-6 h-6 rounded-lg bg-amber-200 flex items-center justify-center">
                                    <i class="fa-solid fa-user-gear text-[10px]"></i>
                                </span>
                                <span>Role Flags (for ልዩ መደበኛ)</span>
                            </h4>

                            <p class="text-[11px] text-amber-800 mb-2">
                                Mark responsibilities. These are simple flags we’ll use for filters/reports later.
                            </p>

                            <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 gap-2 text-[11px]">
                                <?php
                                $flags = [
                                    'is_teacher' => 'መምህር (Teacher)',
                                    'is_staff' => 'ሰራተኛ (Staff)',
                                    'is_committee' => 'ኮሚቴ (Committee)',
                                    'is_volunteer' => 'በፈቃደኝነት (Volunteer)',
                                    'is_dept_head_1' => 'Dept Head 1',
                                    'is_dept_head_2' => 'Dept Head 2',
                                    'is_dept_head_3' => 'Dept Head 3',
                                    'is_dept_head_4' => 'Dept Head 4',
                                    'is_dept_head_5' => 'Dept Head 5',
                                    'is_dept_head_6' => 'Dept Head 6',
                                    'is_dept_head_7' => 'Dept Head 7',
                                    'is_dept_head_8' => 'Dept Head 8',
                                ];
                                foreach ($flags as $name => $label): ?>
                                    <label class="inline-flex items-center gap-2">
                                        <input type="checkbox" name="<?= $name ?>" class="rounded border-amber-300 text-amber-600">
                                        <span><?= $label ?></span>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                        </div>

                        <!-- Personal Info -->
                        <div class="bg-white border border-slate-200 rounded-xl p-3 sm:p-4 mb-4">
                            <h4 class="text-xs font-semibold text-slate-800 mb-3 flex items-center gap-2">
                                <span class="w-6 h-6 rounded-lg bg-emerald-100 flex items-center justify-center text-emerald-600">
                                    <i class="fa-solid fa-user"></i>
                                </span>
                                <span>Personal Information</span>
                            </h4>

                            <div class="grid grid-cols-1 lg:grid-cols-[auto,minmax(0,1fr)] gap-4">
                                <div class="flex flex-col items-center gap-2">
                                    <div class="w-24 h-32 rounded-2xl bg-slate-50 border-2 border-yellow-400 overflow-hidden flex items-center justify-center">
                                        <img id="studentPhotoPreview" src="" alt="Preview"
                                             class="hidden w-full h-full object-cover">
                                        <span id="studentPhotoPlaceholder" class="text-[11px] text-slate-400 text-center px-2">
                                            3×4 Photo
                                        </span>
                                    </div>
                                    <label class="text-[11px] text-emerald-700 cursor-pointer">
                                        <span class="px-3 py-1.5 rounded-xl border border-emerald-300 bg-emerald-50 hover:bg-emerald-100">
                                            Upload Member Photo
                                        </span>
                                        <input type="file" name="student_photo" accept="image/*" class="hidden"
                                               onchange="previewImage(this, 'studentPhotoPreview', 'studentPhotoPlaceholder')">
                                    </label>
                                </div>

                                <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                                    <div>
                                        <label class="block text-[11px] font-medium text-slate-700 mb-1">
                                            Student Name (Amharic) *
                                        </label>
                                        <input type="text" name="student_name" required
                                               class="mobile-touch-target w-full px-3 py-2 rounded-xl border border-slate-200 text-xs placeholder:text-[11px] focus:ring-emerald-200 focus:border-emerald-400"
                                               placeholder="ሙሉ ስም">
                                    </div>

                                    <div>
                                        <label class="block text-[11px] font-medium text-slate-700 mb-1">
                                            Baptismal Name (የክርስትና ስም)
                                        </label>
                                        <input type="text" name="baptismal_name"
                                               class="mobile-touch-target w-full px-3 py-2 rounded-xl border border-slate-200 text-xs placeholder:text-[11px] focus:ring-emerald-200 focus:border-emerald-400">
                                    </div>

                                    <div>
                                        <label class="block text-[11px] font-medium text-slate-700 mb-1">
                                            Father's Name *
                                        </label>
                                        <input type="text" name="father_name" required
                                               class="mobile-touch-target w-full px-3 py-2 rounded-xl border border-slate-200 text-xs placeholder:text-[11px] focus:ring-emerald-200 focus:border-emerald-400">
                                    </div>

                                    <div>
                                        <label class="block text-[11px] font-medium text-slate-700 mb-1">
                                            Grandfather's Name
                                        </label>
                                        <input type="text" name="grandfather_name"
                                               class="mobile-touch-target w-full px-3 py-2 rounded-xl border border-slate-200 text-xs placeholder:text-[11px] focus:ring-emerald-200 focus:border-emerald-400">
                                    </div>

                                    <div class="md:col-span-2">
                                        <label class="block text-[11px] font-medium text-slate-700 mb-1">
                                            Date of Birth (E.C.) *
                                        </label>
                                        <div class="grid grid-cols-3 gap-2">
                                            <input type="number" name="dob_day" id="dobDay"
                                                   min="1" max="30"
                                                   class="mobile-touch-target w-full px-3 py-2 rounded-xl border border-slate-200 text-xs focus:ring-emerald-200 focus:border-emerald-400"
                                                   placeholder="Day"
                                                   oninput="calculateAgeSection()">

                                            <select name="dob_month" id="dobMonth"
                                                    class="mobile-touch-target w-full px-3 py-2 rounded-xl border border-slate-200 text-xs focus:ring-emerald-200 focus:border-emerald-400"
                                                    onchange="calculateAgeSection()">
                                                <option value="">Month</option>
                                            </select>

                                            <input type="number" name="dob_year" id="dobYear"
                                                   min="1950" max="2100"
                                                   class="mobile-touch-target w-full px-3 py-2 rounded-xl border border-slate-200 text-xs focus:ring-emerald-200 focus:border-emerald-400"
                                                   placeholder="Year (E.C.)"
                                                   oninput="calculateAgeSection()">
                                        </div>
                                        <p class="mt-1 text-[10px] text-slate-400">
                                            Section auto-calculated (uses current Ethiopian year).
                                        </p>
                                    </div>

                                    <div>
                                        <label class="block text-[11px] font-medium text-slate-700 mb-1">
                                            Age (Auto)
                                        </label>
                                        <input type="text" id="ageDisplay" readonly
                                               class="w-full px-3 py-2 rounded-xl border border-slate-200 text-xs bg-slate-50 text-slate-700">
                                        <input type="hidden" name="age" id="ageField">
                                    </div>

                                    <div>
                                        <label class="block text-[11px] font-medium text-slate-700 mb-1">
                                            Section (Auto)
                                        </label>
                                        <input type="text" id="sectionDisplay" readonly
                                               class="w-full px-3 py-2 rounded-xl border border-slate-200 text-xs bg-slate-50 text-slate-700">
                                        <input type="hidden" name="current_section" id="currentSectionField">
                                        <input type="hidden" name="age_group" id="ageGroupField">
                                    </div>

                                    <div>
                                        <label class="block text-[11px] font-medium text-slate-700 mb-1">
                                            Gender *
                                        </label>
                                        <select name="gender" required
                                                class="mobile-touch-target w-full px-3 py-2 rounded-xl border border-slate-200 text-xs focus:ring-emerald-200 focus:border-emerald-400">
                                            <option value="male">Male</option>
                                            <option value="female">Female</option>
                                        </select>
                                    </div>

                                    <div>
                                        <label class="block text-[11px] font-medium text-slate-700 mb-1">
                                            Education Level
                                        </label>
                                        <select name="education_level"
                                                class="mobile-touch-target w-full px-3 py-2 rounded-xl border border-slate-200 text-xs focus:ring-emerald-200 focus:border-emerald-400">
                                            <option value="">Select</option>
                                            <option value="kg">KG</option>
                                            <option value="elementary">Elementary (1–8)</option>
                                            <option value="high">High School (9–12)</option>
                                            <option value="tvet_level1">TVET Level I</option>
                                            <option value="tvet_level2">TVET Level II</option>
                                            <option value="tvet_level3">TVET Level III</option>
                                            <option value="diploma">Diploma</option>
                                            <option value="advanced_diploma">Advanced Diploma</option>
                                            <option value="degree">Degree</option>
                                            <option value="masters">Masters</option>
                                            <option value="phd">PhD</option>
                                            <option value="other">ሌላ</option>
                                        </select>
                                    </div>

                                    <div>
                                        <label class="block text-[11px] font-medium text-slate-700 mb-1">
                                            <i class="fa-solid fa-cross text-emerald-500 mr-1"></i>
                                            Spiritual Education (የመንፈሳዊ ትምህርት ደረጃ)
                                        </label>
                                        <select name="spiritual_education"
                                                class="mobile-touch-target w-full px-3 py-2 rounded-xl border border-emerald-200 text-xs focus:ring-emerald-200 focus:border-emerald-400 bg-emerald-50">
                                            <option value="">Select Level</option>
                                            <option value="grade_1">1ኛ ክፍል (Grade 1)</option>
                                            <option value="grade_2">2ኛ ክፍል (Grade 2)</option>
                                            <option value="grade_3">3ኛ ክፍል (Grade 3)</option>
                                            <option value="grade_4">4ኛ ክፍል (Grade 4)</option>
                                            <option value="grade_5">5ኛ ክፍል (Grade 5)</option>
                                            <option value="grade_6">6ኛ ክፍል (Grade 6)</option>
                                            <option value="grade_7">7ኛ ክፍል (Grade 7)</option>
                                            <option value="grade_8">8ኛ ክፍል (Grade 8)</option>
                                            <option value="grade_9">9ኛ ክፍል (Grade 9)</option>
                                            <option value="grade_10">10ኛ ክፍል (Grade 10)</option>
                                            <option value="grade_11">11ኛ ክፍል (Grade 11)</option>
                                            <option value="grade_12">12ኛ ክፍል (Grade 12)</option>
                                            <option value="diploma">ዲፕሎማ (Diploma)</option>
                                            <option value="degree">ዲግሪ (Degree)</option>
                                        </select>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Address -->
                        <div class="bg-white border border-slate-200 rounded-xl p-3 sm:p-4 mb-4">
                            <h4 class="text-xs font-semibold text-slate-800 mb-3 flex items-center gap-2">
                                <span class="w-6 h-6 rounded-lg bg-sky-100 flex items-center justify-center text-sky-600">
                                    <i class="fa-solid fa-location-dot"></i>
                                </span>
                                <span>Member Address</span>
                            </h4>

                            <div class="grid grid-cols-1 md:grid-cols-3 gap-3 mb-3">
                                <div>
                                    <label class="block text-[11px] font-medium text-slate-700 mb-1">
                                        City / Region *
                                    </label>
                                    <select name="city" id="cityField"
                                            class="mobile-touch-target w-full px-3 py-2 rounded-xl border border-slate-200 text-xs focus:ring-emerald-200 focus:border-emerald-400"
                                            onchange="updateSubCities()">
                                        <option value="">Select city</option>
                                        <option value="addis_ababa">Addis Ababa</option>
                                        <option value="oromia">Oromia</option>
                                    </select>
                                </div>

                                <div>
                                    <label class="block text-[11px] font-medium text-slate-700 mb-1">
                                        Sub City / Town
                                    </label>
                                    <select name="sub_city" id="subCityField"
                                            class="mobile-touch-target w-full px-3 py-2 rounded-xl border border-slate-200 text-xs focus:ring-emerald-200 focus:border-emerald-400">
                                        <option value="">Select sub city</option>
                                    </select>
                                </div>

                                <div class="grid grid-cols-3 gap-2">
                                    <div>
                                        <label class="block text-[11px] font-medium text-slate-700 mb-1">
                                            Woreda
                                        </label>
                                        <input type="number" name="woreda" min="1" max="20"
                                               class="mobile-touch-target w-full px-3 py-2 rounded-xl border border-slate-200 text-xs focus:ring-emerald-200 focus:border-emerald-400">
                                    </div>
                                    <div>
                                        <label class="block text-[11px] font-medium text-slate-700 mb-1">
                                            Mender
                                        </label>
                                        <input type="number" name="mender" min="1" max="8"
                                               class="mobile-touch-target w-full px-3 py-2 rounded-xl border border-slate-200 text-xs focus:ring-emerald-200 focus:border-emerald-400">
                                    </div>
                                    <div>
                                        <label class="block text-[11px] font-medium text-slate-700 mb-1">
                                            Block
                                        </label>
                                        <input type="text" name="block_number" maxlength="4"
                                               class="mobile-touch-target w-full px-3 py-2 rounded-xl border border-slate-200 text-xs focus:ring-emerald-200 focus:border-emerald-400">
                                    </div>
                                </div>
                            </div>

                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                                <div>
                                    <label class="block text-[11px] font-medium text-slate-700 mb-1">
                                        House Number
                                    </label>
                                    <input type="text" name="house_number" maxlength="4"
                                           class="mobile-touch-target w-full px-3 py-2 rounded-xl border border-slate-200 text-xs focus:ring-emerald-200 focus:border-emerald-400">
                                </div>

                                <div>
                                    <label class="block text-[11px] font-medium text-slate-700 mb-1">
                                        Work / Skill / Profession (searchable)
                                    </label>
                                    <input type="text" name="work_profession"
                                           list="professionsList"
                                           class="mobile-touch-target w-full px-3 py-2 rounded-xl border border-slate-200 text-xs placeholder:text-[11px] focus:ring-emerald-200 focus:border-emerald-400"
                                           placeholder="IT, Singer, Designer…">
                                    <datalist id="professionsList">
                                        <option value="IT">
                                        <option value="Teacher">
                                        <option value="Nurse">
                                        <option value="Doctor">
                                        <option value="Engineer">
                                        <option value="Driver">
                                        <option value="Farmer">
                                        <option value="Singer">
                                        <option value="Designer">
                                        <option value="Carpenter">
                                        <option value="Tailor">
                                        <option value="Accountant">
                                        <option value="Merchant">
                                    </datalist>
                                </div>
                            </div>
                        </div>

                        <!-- Phones -->
                        <div class="bg-white border border-slate-200 rounded-xl p-3 sm:p-4 mb-4">
                            <h4 class="text-xs font-semibold text-slate-800 mb-3 flex items-center gap-2">
                                <span class="w-6 h-6 rounded-lg bg-emerald-100 flex items-center justify-center text-emerald-600">
                                    <i class="fa-solid fa-phone"></i>
                                </span>
                                <span>Phone Numbers</span>
                            </h4>

                            <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                                <div>
                                    <label class="block text-[11px] font-medium text-slate-700 mb-1">
                                        Phone Number *
                                    </label>
                                    <input type="tel" name="phone_number" required
                                           class="mobile-touch-target w-full px-3 py-2 rounded-xl border border-slate-200 text-xs placeholder:text-[11px] focus:ring-emerald-200 focus:border-emerald-400"
                                           placeholder="+251 9…">
                                </div>
                                <div>
                                    <label class="block text-[11px] font-medium text-slate-700 mb-1">
                                        Other Phone Number
                                    </label>
                                    <input type="tel" name="alt_phone_number"
                                           class="mobile-touch-target w-full px-3 py-2 rounded-xl border border-slate-200 text-xs placeholder:text-[11px] focus:ring-emerald-200 focus:border-emerald-400"
                                           placeholder="Optional">
                                </div>
                            </div>
                        </div>

                        <!-- Guardian -->
                        <div class="bg-white border border-slate-200 rounded-xl p-3 sm:p-4 mb-4">
                            <h4 class="text-xs font-semibold text-slate-800 mb-3 flex items-center gap-2">
                                <span class="w-6 h-6 rounded-lg bg-amber-100 flex items-center justify-center text-amber-600">
                                    <i class="fa-solid fa-user-shield"></i>
                                </span>
                                <span>Guardian / Parent Information</span>
                            </h4>

                            <div class="grid grid-cols-1 lg:grid-cols-[auto,minmax(0,1fr)] gap-4">
                                <div class="flex flex-col items-center gap-2">
                                    <div class="w-24 h-32 rounded-2xl bg-slate-50 border-2 border-yellow-400 overflow-hidden flex items-center justify-center">
                                        <img id="guardianPhotoPreview" src="" alt="Preview"
                                             class="hidden w-full h-full object-cover">
                                        <span id="guardianPhotoPlaceholder" class="text-[11px] text-slate-400 text-center px-2">
                                            3×4 Guardian Photo
                                        </span>
                                    </div>
                                    <label class="text-[11px] text-amber-700 cursor-pointer">
                                        <span class="px-3 py-1.5 rounded-xl border border-amber-300 bg-amber-50 hover:bg-amber-100">
                                            Upload Guardian Photo
                                        </span>
                                        <input type="file" name="guardian_photo" accept="image/*" class="hidden"
                                               onchange="previewImage(this, 'guardianPhotoPreview', 'guardianPhotoPlaceholder')">
                                    </label>
                                </div>

                                <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                                    <div class="md:col-span-2">
                                        <label class="block text-[11px] font-medium text-slate-700 mb-1">
                                            Guardian Full Name *
                                        </label>
                                        <input type="text" name="guardian_name" required
                                               class="mobile-touch-target w-full px-3 py-2 rounded-xl border border-slate-200 text-xs placeholder:text-[11px] focus:ring-emerald-200 focus:border-emerald-400">
                                    </div>

                                    <div>
                                        <label class="block text-[11px] font-medium text-slate-700 mb-1">
                                            Guardian Phone 1 *
                                        </label>
                                        <input type="tel" name="guardian_phone1" required
                                               class="mobile-touch-target w-full px-3 py-2 rounded-xl border border-slate-200 text-xs placeholder:text-[11px] focus:ring-emerald-200 focus:border-emerald-400"
                                               placeholder="+251 9…">
                                    </div>

                                    <div>
                                        <label class="block text-[11px] font-medium text-slate-700 mb-1">
                                            Guardian Phone 2
                                        </label>
                                        <input type="tel" name="guardian_phone2"
                                               class="mobile-touch-target w-full px-3 py-2 rounded-xl border border-slate-200 text-xs placeholder:text-[11px] focus:ring-emerald-200 focus:border-emerald-400"
                                               placeholder="Optional">
                                    </div>

                                    <div class="md:col-span-2 flex items-center justify-between gap-2">
                                        <span class="text-[11px] text-slate-500">
                                            Guardian Address (same structure as member).
                                        </span>
                                        <button type="button"
                                                onclick="copyMemberAddressToGuardian()"
                                                class="px-3 py-1.5 rounded-full border border-emerald-200 text-[11px] text-emerald-700 bg-emerald-50 hover:bg-emerald-100">
                                            Same as member
                                        </button>
                                    </div>

                                    <div>
                                        <label class="block text-[11px] font-medium text-slate-700 mb-1">
                                            City / Region
                                        </label>
                                        <select name="guardian_city" id="guardianCityField"
                                                class="mobile-touch-target w-full px-3 py-2 rounded-xl border border-slate-200 text-xs focus:ring-emerald-200 focus:border-emerald-400"
                                                onchange="updateGuardianSubCities()">
                                            <option value="">Select city</option>
                                            <option value="addis_ababa">Addis Ababa</option>
                                            <option value="oromia">Oromia</option>
                                        </select>
                                    </div>

                                    <div>
                                        <label class="block text-[11px] font-medium text-slate-700 mb-1">
                                            Sub City / Town
                                        </label>
                                        <select name="guardian_sub_city" id="guardianSubCityField"
                                                class="mobile-touch-target w-full px-3 py-2 rounded-xl border border-slate-200 text-xs focus:ring-emerald-200 focus:border-emerald-400">
                                            <option value="">Select sub city</option>
                                        </select>
                                    </div>

                                    <div>
                                        <label class="block text-[11px] font-medium text-slate-700 mb-1">
                                            Woreda
                                        </label>
                                        <input type="number" name="guardian_woreda" id="guardianWoredaField"
                                               min="1" max="50"
                                               class="mobile-touch-target w-full px-3 py-2 rounded-xl border border-slate-200 text-xs focus:ring-emerald-200 focus:border-emerald-400">
                                    </div>

                                    <div>
                                        <label class="block text-[11px] font-medium text-slate-700 mb-1">
                                            Mender
                                        </label>
                                        <input type="number" name="guardian_mender" id="guardianMenderField"
                                               min="1" max="50"
                                               class="mobile-touch-target w-full px-3 py-2 rounded-xl border border-slate-200 text-xs focus:ring-emerald-200 focus:border-emerald-400">
                                    </div>

                                    <div>
                                        <label class="block text-[11px] font-medium text-slate-700 mb-1">
                                            Block
                                        </label>
                                        <input type="text" name="guardian_block_number" id="guardianBlockField"
                                               maxlength="3"
                                               class="mobile-touch-target w-full px-3 py-2 rounded-xl border border-slate-200 text-xs focus:ring-emerald-200 focus:border-emerald-400">
                                    </div>

                                    <div>
                                        <label class="block text-[11px] font-medium text-slate-700 mb-1">
                                            House Number
                                        </label>
                                        <input type="text" name="guardian_house" id="guardianHouseField"
                                               maxlength="4"
                                               class="mobile-touch-target w-full px-3 py-2 rounded-xl border border-slate-200 text-xs focus:ring-emerald-200 focus:border-emerald-400">
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Documents -->
                        <div class="bg-white border border-slate-200 rounded-xl p-3 sm:p-4 mb-6">
                            <h4 class="text-xs font-semibold text-slate-800 mb-3 flex items-center gap-2">
                                <span class="w-6 h-6 rounded-lg bg-slate-200 flex items-center justify-center text-slate-700">
                                    <i class="fa-solid fa-file-arrow-up text-[10px]"></i>
                                </span>
                                <span>Upload Documents</span>
                            </h4>

                            <div class="grid grid-cols-1 md:grid-cols-2 gap-3 text-[11px]">
                                <div>
                                    <label class="block font-medium text-slate-700 mb-1">
                                        Previous School Records
                                    </label>
                                    <input type="file" name="doc_school_records"
                                           accept=".pdf,.doc,.docx,image/*"
                                           class="block w-full text-xs text-slate-600">
                                </div>

                                <div>
                                    <label class="block font-medium text-slate-700 mb-1">
                                        Spiritual Education Document
                                    </label>
                                    <input type="file" name="doc_spiritual"
                                           accept=".pdf,.doc,.docx,image/*"
                                           class="block w-full text-xs text-slate-600">
                                </div>

                                <div>
                                    <label class="block font-medium text-slate-700 mb-1">
                                        Signed Form
                                        <span class="text-red-500">*</span>
                                        <span class="block text-[10px] text-slate-400">
                                            Required for all registration types except Waiting list.
                                        </span>
                                    </label>
                                    <input type="file" name="doc_signed_form" id="docSignedFormField"
                                           accept=".pdf,.doc,.docx,image/*"
                                           class="block w-full text-xs text-slate-600">
                                </div>
                            </div>
                        </div>

                        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
                            <p class="text-[11px] text-slate-500">
                                *Required. Waiting-list: signed form optional; full ID + card only after 3 months (backend logic later).
                            </p>
                            <div class="flex gap-2 justify-end">
                                <button type="button"
                                        onclick="resetMemberForm()"
                                        class="px-3 py-2 rounded-xl border border-slate-200 text-xs text-slate-600 bg-white hover:bg-slate-50">
                                    Clear
                                </button>
                                <button type="submit"
                                        class="px-4 py-2 rounded-xl bg-emerald-600 text-white text-xs font-semibold shadow hover:bg-emerald-700 active:scale-95">
                                    Save Member
                                </button>
                            </div>
                        </div>
                    </form>
                </div>
            </section>

            <!-- MANAGE (edit, archive) -->
            <section id="section-manage" class="content-section">
                <div class="panel p-4 mobile-card">
                    <div class="flex items-center justify-between mb-3">
                        <div>
                            <h3 class="text-sm font-semibold text-slate-800 flex items-center gap-2">
                                <span class="w-7 h-7 rounded-xl bg-indigo-100 flex items-center justify-center text-indigo-600">
                                    <i class="fa-solid fa-pen-to-square"></i>
                                </span>
                                <span>Manage Members</span>
                            </h3>
                            <p class="text-xs text-slate-500">
                                Search, Edit, and Manage all active members.
                            </p>
                        </div>
                    </div>

                    <!-- Search & Filter Bar (Duplicated from All Members for convenience) -->
                    <div class="bg-white border border-slate-200 rounded-xl p-3 mb-4 shadow-sm">
                        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3 mb-3">
                            <div class="relative">
                                <i class="fa-solid fa-search absolute left-3 top-1/2 -translate-y-1/2 text-slate-400 text-xs"></i>
                                <input type="text" id="manageSearchInput" placeholder="Search anything: names, phone, address, guardian, profession, ID..."
                                       class="w-full pl-9 pr-3 py-2 rounded-xl border border-slate-200 text-xs focus:ring-indigo-200 focus:border-indigo-400">
                            </div>
                            <select id="manageFilterType" class="w-full px-3 py-2 rounded-xl border border-slate-200 text-xs">
                                <option value="">All Registration Types</option>
                                <option value="waiting">Waiting</option>
                                <option value="direct">Direct</option>
                                <option value="transfer">Transfer</option>
                            </select>
                            <select id="manageFilterStatus" class="w-full px-3 py-2 rounded-xl border border-slate-200 text-xs">
                                <option value="">All Status</option>
                                <option value="active">Active</option>
                                <option value="warning">Warning</option>
                                <option value="inactive">Inactive</option>
                                <option value="archived">Archived</option>
                            </select>
                            <select id="manageFilterMemberType" class="w-full px-3 py-2 rounded-xl border border-slate-200 text-xs">
                                <option value="">All Member Types</option>
                                <option value="regular">Regular</option>
                                <option value="honorary">Honorary</option>
                                <option value="special_regular">Special Regular</option>
                            </select>
                        </div>
                        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3">
                            <select id="manageFilterGender" class="w-full px-3 py-2 rounded-xl border border-slate-200 text-xs">
                                <option value="">All Gender</option>
                                <option value="male">Male</option>
                                <option value="female">Female</option>
                            </select>
                            <select id="manageFilterCity" class="w-full px-3 py-2 rounded-xl border border-slate-200 text-xs">
                                <option value="">All Cities</option>
                                <option value="addis_ababa">Addis Ababa</option>
                                <option value="oromia">Oromia</option>
                            </select>
                            <select id="manageFilterAgeGroup" class="w-full px-3 py-2 rounded-xl border border-slate-200 text-xs">
                                <option value="">All Age Groups</option>
                                <option value="under6">Under 6</option>
                                <option value="7_13">7 - 13</option>
                                <option value="14_17">14 - 17</option>
                                <option value="18_plus">18+</option>
                            </select>
                            <select id="manageFilterEducation" class="w-full px-3 py-2 rounded-xl border border-slate-200 text-xs">
                                <option value="">All Education Levels</option>
                                <option value="illiterate">Illiterate</option>
                                <option value="elementary">Elementary</option>
                                <option value="high_school">High School</option>
                                <option value="certificate">Certificate</option>
                                <option value="diploma">Diploma</option>
                                <option value="degree">Degree</option>
                                <option value="masters">Masters</option>
                                <option value="phd">PhD</option>
                            </select>
                        </div>
                        <div class="mt-3 flex flex-col sm:flex-row gap-2 justify-end">
                            <button type="button" onclick="resetManageFilters()" class="px-4 py-2 rounded-xl border border-slate-200 text-xs font-semibold text-slate-600 bg-white hover:bg-slate-50">Reset</button>
                            <button onclick="applyManageFilters()" class="px-4 py-2 rounded-xl bg-indigo-600 text-white text-xs font-bold hover:bg-indigo-700">Filter</button>
                        </div>
                    </div>

                    <!-- Members List Table -->
                    <div class="overflow-x-auto border rounded-xl bg-white shadow-sm">
                        <table class="w-full text-left border-collapse">
                            <thead class="bg-slate-50 text-[11px] text-slate-500 uppercase tracking-wider">
                                <tr>
                                    <th class="px-4 py-3 font-semibold">Member</th>
                                    <th class="px-4 py-3 font-semibold">Type</th>
                                    <th class="px-4 py-3 font-semibold">Status</th>
                                    <th class="px-4 py-3 font-semibold text-right">Actions</th>
                                </tr>
                            </thead>
                            <tbody id="manageMembersTableBody" class="divide-y divide-slate-100 text-xs text-slate-700">
                                <!-- Populated by JS -->
                                <tr><td colspan="4" class="p-4 text-center text-slate-400">Loading members...</td></tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </section>

<script>
// Manage Section Scripts
let allManageMembers = []; // Store data for client-side filtering
const embeddedManageMembers = <?php echo json_encode($membersList, JSON_UNESCAPED_UNICODE); ?>;

function loadManageMembers() {
    const tbody = document.getElementById('manageMembersTableBody');
    if(!tbody) return;
    
    tbody.innerHTML = '<tr><td colspan="4" class="p-4 text-center text-slate-400">Loading...</td></tr>';

    // If we already rendered data from PHP, reuse it immediately (no network flash)
    if (allManageMembers.length === 0 && Array.isArray(embeddedManageMembers) && embeddedManageMembers.length > 0) {
        allManageMembers = embeddedManageMembers;
        renderManageTable(allManageMembers);
    }

    // Reuse the same data source as "All Members" if possible, or fetch fresh
    // For now, we'll assume we can fetch the same list. 
    // Ideally, we should have a dedicated API endpoint, but we can parse the existing PHP array if it was exposed to JS,
    // or better, make an AJAX call. 
    // Since we don't have a dedicated "list members" API, we will use the PHP rendered list from "All Members" section 
    // if we are on the same page, OR we can create a simple API.
    // Let's create a simple API endpoint for this: admin/api_list_members.php
    
    fetch('/admin/api_list_members.php', {credentials: 'same-origin'})
        .then(r => {
            if (!r.ok) throw new Error('HTTP ' + r.status);
            return r.json();
        })
        .then(data => {
            if(data.status === 'success') {
                allManageMembers = data.members;
                renderManageTable(allManageMembers);
            } else {
                const fallback = Array.isArray(embeddedManageMembers) ? embeddedManageMembers : [];
                if (fallback.length > 0) {
                    allManageMembers = fallback;
                    renderManageTable(allManageMembers);
                } else {
                    tbody.innerHTML = '<tr><td colspan="4" class="p-4 text-center text-red-400">Failed to load</td></tr>';
                }
            }
        })
        .catch(e => {
            console.error(e);
            const fallback = Array.isArray(embeddedManageMembers) ? embeddedManageMembers : [];
            if (fallback.length > 0) {
                allManageMembers = fallback;
                renderManageTable(allManageMembers);
            } else {
                tbody.innerHTML = '<tr><td colspan="4" class="p-4 text-center text-red-400">Error loading data</td></tr>';
            }
        });
}

function renderManageTable(members) {
    const tbody = document.getElementById('manageMembersTableBody');
    tbody.innerHTML = '';
    
    if(members.length === 0) {
        tbody.innerHTML = '<tr><td colspan="4" class="p-4 text-center text-slate-400">No members found</td></tr>';
        return;
    }

    // Escape any member-supplied text before putting it into innerHTML.
    // Student/father names are entered by staff and stored in the DB, so an
    // unescaped name like <img src=x onerror=...> would run in THIS admin's
    // browser (stored XSS). This local helper guarantees escaping is applied
    // regardless of where escapeHtml() is defined in the page.
    const esc = s => String(s == null ? '' : s).replace(/[&<>"']/g,
        c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));

    members.forEach(m => {
        const row = document.createElement('tr');
        row.className = 'hover:bg-slate-50 transition';
        const fullName = ((m.student_name || '') + ' ' + (m.father_name || '')).trim();
        row.innerHTML = `
            <td class="px-4 py-3">
                <div class="font-bold text-slate-800">${esc(m.student_name)} ${esc(m.father_name)}</div>
                <div class="text-[10px] text-slate-500">${esc(m.member_code || 'No ID')}</div>
            </td>
            <td class="px-4 py-3 capitalize">
                <span class="px-2 py-0.5 rounded-full bg-slate-100 text-slate-600 text-[10px] font-bold border border-slate-200">
                    ${esc(m.registration_type)}
                </span>
            </td>
            <td class="px-4 py-3">
                <span class="px-2 py-0.5 rounded-full ${getStatusColor(m.status)} text-[10px] font-bold border border-white shadow-sm">
                    ${esc(m.status)}
                </span>
            </td>
            <td class="px-4 py-3 text-right">
                <div class="flex items-center justify-end gap-2">
                    <button onclick="openManageSheet(${parseInt(m.id, 10)})" class="px-3 py-1.5 rounded-lg bg-indigo-50 text-indigo-600 hover:bg-indigo-100 font-bold text-[11px] transition">
                        <i class="fa-solid fa-pen-to-square mr-1"></i> Manage
                    </button>
                    <button data-id="${parseInt(m.id, 10)}" data-name="${esc(fullName)}" onclick="archiveMember(this.dataset.id, this.dataset.name)" class="px-3 py-1.5 rounded-lg bg-amber-50 text-amber-600 hover:bg-amber-100 font-bold text-[11px] transition" title="Move to Archive">
                        <i class="fa-solid fa-box-archive"></i>
                    </button>
                </div>
            </td>
        `;
        tbody.appendChild(row);
    });
}

function getStatusColor(status) {
    if(status === 'active') return 'bg-emerald-100 text-emerald-700';
    if(status === 'warning') return 'bg-amber-100 text-amber-700';
    if(status === 'inactive') return 'bg-slate-100 text-slate-600';
    return 'bg-gray-100 text-gray-600';
}

function applyManageFilters() {
    const q = document.getElementById('manageSearchInput').value.toLowerCase();
    const type = document.getElementById('manageFilterType').value;
    const status = document.getElementById('manageFilterStatus').value;
    const memberType = document.getElementById('manageFilterMemberType').value;
    const gender = document.getElementById('manageFilterGender').value;
    const city = document.getElementById('manageFilterCity').value;
    const ageGroup = document.getElementById('manageFilterAgeGroup').value;
    const education = document.getElementById('manageFilterEducation').value;

    const filtered = allManageMembers.filter(m => {
        const haystack = [
            m.student_name,
            m.father_name,
            m.grandfather_name,
            m.baptismal_name,
            m.member_code,
            m.phone_number,
            m.alt_phone_number,
            m.guardian_name,
            m.guardian_phone1,
            m.guardian_phone2,
            m.city,
            m.sub_city,
            m.woreda,
            m.mender,
            m.block_number,
            m.house_number,
            m.work_profession,
            m.education_level,
            m.registration_type,
            m.member_type,
            m.status,
        ].filter(Boolean).join(' ').toLowerCase();

        const matchText = haystack.includes(q);
        const matchType = type ? m.registration_type === type : true;
        const matchStatus = status ? m.status === status : true;
        const matchMemberType = memberType ? m.member_type === memberType : true;
        const matchGender = gender ? m.gender === gender : true;
        const matchCity = city ? m.city === city : true;
        const matchAge = ageGroup ? m.age_group === ageGroup : true;
        const matchEdu = education ? m.education_level === education : true;

        return matchText && matchType && matchStatus && matchMemberType && matchGender && matchCity && matchAge && matchEdu;
    });

    renderManageTable(filtered);
}

// Reset filters to defaults
function resetManageFilters() {
    document.getElementById('manageSearchInput').value = '';
    document.getElementById('manageFilterType').value = '';
    document.getElementById('manageFilterStatus').value = '';
    document.getElementById('manageFilterMemberType').value = '';
    document.getElementById('manageFilterGender').value = '';
    document.getElementById('manageFilterCity').value = '';
    document.getElementById('manageFilterAgeGroup').value = '';
    document.getElementById('manageFilterEducation').value = '';
    applyManageFilters();
}

// Hook into navigation to load data when tab is shown
document.querySelectorAll('[data-section="manage"]').forEach(btn => {
    btn.addEventListener('click', () => {
        loadManageMembers();
    });
});

// Live filtering on input/change
document.addEventListener('DOMContentLoaded', () => {
    const inputs = [
        'manageSearchInput',
        'manageFilterType',
        'manageFilterStatus',
        'manageFilterMemberType',
        'manageFilterGender',
        'manageFilterCity',
        'manageFilterAgeGroup',
        'manageFilterEducation'
    ];
    inputs.forEach(id => {
        const el = document.getElementById(id);
        if (!el) return;
        el.addEventListener('input', applyManageFilters);
        el.addEventListener('change', applyManageFilters);
    });
});
</script>

            <!-- ARCHIVE -->
            <section id="section-archive" class="content-section">
                <div class="panel p-4 mobile-card">
                    <!-- Header -->
                    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 mb-4">
                        <div>
                            <h3 class="text-sm font-semibold text-slate-800 mb-1">
                                <i class="fa-solid fa-box-archive text-amber-500 mr-2"></i>Old Members Archive
                            </h3>
                            <p class="text-xs text-slate-500">
                                Archived / left members. You can restore them back to active if they return.
                            </p>
                        </div>
                        <div class="flex items-center gap-2">
                            <span id="archivedCount" class="px-3 py-1.5 bg-amber-100 text-amber-700 rounded-full text-xs font-bold">
                                Loading...
                            </span>
                            <button onclick="loadArchivedMembers()" class="p-2 text-slate-500 hover:text-slate-700 hover:bg-slate-100 rounded-lg transition">
                                <i class="fa-solid fa-sync"></i>
                            </button>
                        </div>
                    </div>
                    
                    <!-- Search -->
                    <div class="mb-4">
                        <div class="relative">
                            <i class="fa-solid fa-search absolute left-3 top-1/2 -translate-y-1/2 text-slate-400 text-sm"></i>
                            <input type="text" id="archiveSearch" placeholder="Search archived members..." 
                                   oninput="filterArchivedMembers()"
                                   class="w-full pl-10 pr-4 py-2.5 border border-slate-200 rounded-xl text-sm focus:ring-2 focus:ring-amber-500 focus:border-amber-500 transition">
                        </div>
                    </div>
                    
                    <!-- Archived Members List -->
                    <div id="archivedMembersList" class="space-y-3">
                        <div class="text-center py-8 text-slate-400">
                            <i class="fa-solid fa-spinner fa-spin text-2xl mb-2"></i>
                            <p class="text-sm">Loading archived members...</p>
                        </div>
                    </div>
                </div>
            </section>

            <!-- ID CARDS -->
          <!-- ID CARD SECTION START -->
<section id="section-idcards" class="content-section hidden">
    <div class="panel p-4 mobile-card">
        <div class="flex justify-between items-center mb-4">
            <div>
                <h3 class="text-sm font-semibold text-slate-800 mb-1">ID Card Management</h3>
                <p class="text-xs text-slate-500">Generate and Print Digital IDs for eligible members.</p>
            </div>
            <button onclick="location.reload()" class="p-2 text-slate-500 hover:text-slate-700">
                <i class="fa-solid fa-sync"></i>
            </button>
        </div>

        <div class="overflow-x-auto border rounded-lg">
            <table class="w-full text-left border-collapse">
                <thead class="bg-slate-50 text-xs text-slate-500 uppercase">
                    <tr>
                        <th class="px-4 py-3 font-medium">Member Name</th>
                        <th class="px-4 py-3 font-medium">Code</th>
                        <th class="px-4 py-3 font-medium">Member Type</th>
                        <th class="px-4 py-3 font-medium">Status</th>
                        <th class="px-4 py-3 font-medium text-right">Action</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100 text-sm text-slate-700">
                    <?php
                    // Helper map for member types
                    $typeMap = [
                        'direct' => 'መደበኛ (Regular)',
                        'transfer' => 'ልዩ መደበኛ (Student+)',
                        'honorary' => 'የክብር አባላት'
                    ];

                    $id_sql = "SELECT id, student_name, father_name, member_code, registration_type, id_card_status, id_card_generated_at
                               FROM members 
                               WHERE status = 'active' 
                               AND registration_type IN ('direct', 'transfer', 'honorary') 
                               ORDER BY student_name ASC";
                    
                    if (isset($conn)) {
                        $id_result = $conn->query($id_sql);
                        
                        if ($id_result && $id_result->num_rows > 0) {
                            while ($row = $id_result->fetch_assoc()) {
                                $full_name = $row['student_name'] . ' ' . $row['father_name'];
                                $status = $row['id_card_status']; 
                                $code = $row['member_code'] ? $row['member_code'] : 'Pending';
                                
                                // Map Type
                                $displayType = $typeMap[$row['registration_type']] ?? $row['registration_type'];

                                // Check Expiry (4 Years)
                                $is_expired = false;
                                if ($status == 'generated' && !empty($row['id_card_generated_at'])) {
                                    $exp_date = date('Y-m-d', strtotime($row['id_card_generated_at'] . ' + 4 years'));
                                    if (date('Y-m-d') > $exp_date) {
                                        $is_expired = true;
                                    }
                                }
                                ?>
                                <tr class="hover:bg-slate-50 transition">
                                    <td class="px-4 py-3 font-medium"><?php echo e($full_name); ?></td>
                                    <td class="px-4 py-3">
                                        <span class="bg-slate-100 text-slate-600 px-2 py-0.5 rounded text-xs font-mono">
                                            <?php echo e($code); ?>
                                        </span>
                                    </td>
                                    <td class="px-4 py-3 text-xs font-semibold text-slate-600">
                                        <?php echo e($displayType); ?>
                                    </td>
                                    <td class="px-4 py-3">
                                        <?php if($status == 'generated'): ?>
                                            <?php if($is_expired): ?>
                                                <span class="bg-red-100 text-red-700 px-2 py-0.5 rounded-full text-xs font-bold animate-pulse">EXPIRED</span>
                                            <?php else: ?>
                                                <span class="bg-emerald-100 text-emerald-700 px-2 py-0.5 rounded-full text-xs font-semibold">Generated</span>
                                            <?php endif; ?>
                                        <?php else: ?>
                                            <span class="bg-amber-100 text-amber-700 px-2 py-0.5 rounded-full text-xs font-semibold">Not Created</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="px-4 py-3 text-right">
                                        <?php if($status == 'generated'): ?>
                                            <!-- View Button -->
                                            <a href="/admin/id_cards/view_id_card.php?member_id=<?php echo $row['id']; ?>" 
                                               target="_blank"
                                               class="inline-flex items-center gap-1 bg-white border border-slate-200 text-slate-700 hover:bg-slate-50 px-3 py-1.5 rounded text-xs font-medium shadow-sm">
                                               <i class="fa-solid fa-eye"></i> View
                                            </a>
                                            <!-- Renew Button (Only if Expired) -->
                                            <?php if($is_expired): ?>
                                                <a href="/admin/id_cards/generate_id_card.php?member_id=<?php echo $row['id']; ?>&action=renew" 
                                                   onclick="return confirm('Renew this ID? This will set the issue date to today.')"
                                                   class="inline-flex items-center gap-1 bg-blue-600 text-white hover:bg-blue-700 px-3 py-1.5 rounded text-xs font-medium shadow-sm ml-2">
                                                   <i class="fa-solid fa-rotate"></i> Renew
                                                </a>
                                            <?php endif; ?>
                                        <?php else: ?>
                                            <!-- Generate Button -->
                                            <a href="/admin/id_cards/generate_id_card.php?member_id=<?php echo $row['id']; ?>" 
                                               class="inline-flex items-center gap-1 bg-emerald-600 text-white hover:bg-emerald-700 px-3 py-1.5 rounded text-xs font-medium shadow-sm transition">
                                               <i class="fa-solid fa-wand-magic-sparkles"></i> Generate
                                            </a>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php
                            }
                        } else {
                            echo "<tr><td colspan='5' class='px-4 py-8 text-center text-slate-400'>No eligible members found.</td></tr>";
                        }
                    }
                    ?>
                </tbody>
            </table>
        </div>
    </div>
</section>
<!-- ID CARD SECTION END -->

            <!-- GROUPS -->
            <section id="section-groups" class="content-section">
                <div class="panel p-4 mobile-card">
                    <h3 class="text-sm font-semibold text-slate-800 mb-1">Groups</h3>
                    <p class="text-xs text-slate-500">
                        Group & section management UI placeholder. Add CRUD later.
                    </p>
                </div>
            </section>

            <!-- ATTENDANCE -->
            <section id="section-attendance" class="content-section">

                <!-- Header -->
                <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3 mb-5">
                    <div>
                        <h2 class="text-xl font-bold text-slate-800 flex items-center gap-2">
                            <span class="w-9 h-9 rounded-2xl bg-orange-100 flex items-center justify-center"><i class="fa-solid fa-clipboard-check text-orange-600"></i></span>
                            Attendance & Status
                        </h2>
                        <p class="text-xs text-slate-500 amharic-text mt-1">የአባላት ቆጠራና ሁኔታ</p>
                    </div>
                </div>

                <!-- Sub-Tabs -->
                <div class="flex gap-2 mb-5 overflow-x-auto hide-scrollbar pb-1">
                    <button onclick="showAttTab(this,'attOverview')" class="atab atab-on"><i class="fa-solid fa-chart-pie mr-1"></i>Overview</button>
                    <button onclick="showAttTab(this,'attDaily')" class="atab"><i class="fa-solid fa-calendar-day mr-1"></i>Daily Report</button>
                    <button onclick="showAttTab(this,'attMember')" class="atab"><i class="fa-solid fa-user-clock mr-1"></i>Member Lookup</button>
                    <button onclick="showAttTab(this,'attRisk')" class="atab"><i class="fa-solid fa-triangle-exclamation mr-1"></i>At-Risk</button>
                    <button onclick="showAttTab(this,'attStatus')" class="atab"><i class="fa-solid fa-user-pen mr-1"></i>Status Mgmt</button>
                </div>

                <!-- ===== OVERVIEW ===== -->
                <div id="attOverview" class="att-pane">
                    <div id="attOvLoad" class="text-center py-12"><div class="inline-block w-5 h-5 border-2 border-slate-200 border-t-orange-500 rounded-full animate-spin"></div><p class="text-xs text-slate-400 mt-2">Loading...</p></div>
                    <div id="attOvContent" style="display:none">
                        <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-3 mb-4" id="attKpis"></div>
                        <div class="grid grid-cols-1 lg:grid-cols-2 gap-4 mb-4">
                            <div class="panel p-4"><h4 class="text-xs font-semibold text-slate-600 mb-3"><i class="fa-solid fa-chart-bar mr-1 text-orange-400"></i>Last 7 Days</h4><div style="height:200px"><canvas id="attWeekChart"></canvas></div></div>
                            <div class="panel p-4"><h4 class="text-xs font-semibold text-slate-600 mb-3"><i class="fa-solid fa-chart-line mr-1 text-blue-400"></i>Weekly Summary (4 Weeks)</h4><div style="height:200px"><canvas id="attMonthChart"></canvas></div></div>
                        </div>
                        <div class="panel p-4 mb-4">
                            <h4 class="text-xs font-semibold text-slate-600 mb-3"><i class="fa-solid fa-user-xmark mr-1 text-red-400"></i>Top Absentees (Last 30 Days)</h4>
                            <div id="attAbsentees" class="text-xs text-slate-400">Loading...</div>
                        </div>
                    </div>
                </div>

                <!-- ===== DAILY REPORT ===== -->
                <div id="attDaily" class="att-pane" style="display:none">
                    <div class="panel p-4 mb-4">
                        <div class="flex flex-col sm:flex-row sm:items-center gap-3 mb-3">
                            <div>
                                <label class="block text-[10px] font-semibold text-slate-500 mb-1 uppercase">Select Date</label>
                                <input type="date" id="attDailyDate" class="px-3 py-2 rounded-xl border border-slate-200 text-sm" onchange="loadDailyReport()">
                            </div>
                            <button onclick="loadDailyReport()" class="px-4 py-2 bg-orange-500 text-white rounded-xl text-xs font-semibold hover:bg-orange-600 mt-4 sm:mt-0"><i class="fa-solid fa-magnifying-glass mr-1"></i>Load</button>
                        </div>
                        <div id="attDailySummary" class="grid grid-cols-2 sm:grid-cols-4 gap-3 mb-3" style="display:none"></div>
                        <div id="attDailyTable" style="display:none;max-height:400px;overflow:auto">
                            <table class="w-full text-xs border-collapse">
                                <thead class="sticky top-0 bg-slate-50"><tr><th class="px-3 py-2 text-left font-semibold text-slate-500">#</th><th class="px-3 py-2 text-left font-semibold text-slate-500">Name</th><th class="px-3 py-2 text-left font-semibold text-slate-500">Code</th><th class="px-3 py-2 text-left font-semibold text-slate-500">Gender</th><th class="px-3 py-2 text-left font-semibold text-slate-500">Attendance</th><th class="px-3 py-2 text-left font-semibold text-slate-500">Notes</th></tr></thead>
                                <tbody id="attDailyBody"></tbody>
                            </table>
                        </div>
                        <div id="attDailyEmpty" class="text-center py-8 text-slate-400" style="display:none"><i class="fa-solid fa-calendar-xmark text-3xl mb-2"></i><p class="text-sm">No attendance recorded for this date</p></div>
                    </div>
                </div>

                <!-- ===== MEMBER LOOKUP ===== -->
                <div id="attMember" class="att-pane" style="display:none">
                    <div class="panel p-4 mb-4">
                        <label class="block text-[10px] font-semibold text-slate-500 mb-1 uppercase">Search Member</label>
                        <div class="flex gap-2">
                            <input type="text" id="attMemSearch" class="flex-1 px-3 py-2 rounded-xl border border-slate-200 text-sm" placeholder="Name, code, or phone..." onkeyup="if(event.key==='Enter')searchMemberAtt()">
                            <button onclick="searchMemberAtt()" class="px-4 py-2 bg-orange-500 text-white rounded-xl text-xs font-semibold hover:bg-orange-600"><i class="fa-solid fa-search"></i></button>
                        </div>
                    </div>
                    <div id="attMemResults" style="display:none"></div>
                    <div id="attMemDetail" style="display:none"></div>
                </div>

                <!-- ===== AT-RISK MEMBERS ===== -->
                <div id="attRisk" class="att-pane" style="display:none">
                    <div class="panel p-4 mb-4">
                        <div class="flex flex-col sm:flex-row sm:items-end gap-3 mb-3">
                            <div>
                                <label class="block text-[10px] font-semibold text-slate-500 mb-1 uppercase">Period (Days)</label>
                                <select id="attRiskDays" class="px-3 py-2 rounded-xl border border-slate-200 text-sm">
                                    <option value="14">Last 14 days</option>
                                    <option value="30" selected>Last 30 days</option>
                                    <option value="60">Last 60 days</option>
                                    <option value="90">Last 90 days</option>
                                </select>
                            </div>
                            <div>
                                <label class="block text-[10px] font-semibold text-slate-500 mb-1 uppercase">Attendance Below</label>
                                <select id="attRiskThresh" class="px-3 py-2 rounded-xl border border-slate-200 text-sm">
                                    <option value="75">75%</option>
                                    <option value="50" selected>50%</option>
                                    <option value="25">25%</option>
                                    <option value="1">Never attended</option>
                                </select>
                            </div>
                            <button onclick="loadAtRisk()" class="px-4 py-2 bg-red-500 text-white rounded-xl text-xs font-semibold hover:bg-red-600"><i class="fa-solid fa-triangle-exclamation mr-1"></i>Find At-Risk</button>
                        </div>
                        <div id="attRiskCount" class="text-xs text-slate-400 mb-2"></div>
                        <div id="attRiskList" style="max-height:450px;overflow:auto"></div>
                    </div>
                </div>

                <!-- ===== STATUS MANAGEMENT ===== -->
                <div id="attStatus" class="att-pane" style="display:none">
                    <div class="panel p-4 mb-4">
                        <h4 class="text-sm font-semibold text-slate-700 mb-1"><i class="fa-solid fa-user-pen mr-1 text-violet-500"></i>Update Member Status</h4>
                        <p class="text-[10px] text-slate-400 mb-3">Search for a member and update their status based on attendance patterns</p>
                        <div class="flex gap-2 mb-4">
                            <input type="text" id="attStatusSearch" class="flex-1 px-3 py-2 rounded-xl border border-slate-200 text-sm" placeholder="Search member name, code, or phone..." onkeyup="if(event.key==='Enter')searchForStatus()">
                            <button onclick="searchForStatus()" class="px-4 py-2 bg-violet-500 text-white rounded-xl text-xs font-semibold hover:bg-violet-600"><i class="fa-solid fa-search"></i></button>
                        </div>
                        <div id="attStatusResults"></div>
                    </div>
                    <div class="panel p-4 mb-4">
                        <h4 class="text-sm font-semibold text-slate-700 mb-3"><i class="fa-solid fa-users-gear mr-1 text-amber-500"></i>Quick Status Overview</h4>
                        <div class="grid grid-cols-2 sm:grid-cols-4 gap-3" id="attStatusKpis">
                            <div class="bg-emerald-50 p-3 rounded-xl text-center"><div class="text-lg font-bold text-emerald-700"><?= $statusCounts['active'] ?? 0 ?></div><div class="text-[10px] text-emerald-600">Active</div></div>
                            <div class="bg-amber-50 p-3 rounded-xl text-center"><div class="text-lg font-bold text-amber-700"><?= $statusCounts['warning'] ?? 0 ?></div><div class="text-[10px] text-amber-600">Warning</div></div>
                            <div class="bg-red-50 p-3 rounded-xl text-center"><div class="text-lg font-bold text-red-700"><?= $statusCounts['inactive'] ?? 0 ?></div><div class="text-[10px] text-red-600">Inactive</div></div>
                            <div class="bg-slate-100 p-3 rounded-xl text-center"><div class="text-lg font-bold text-slate-500"><?= $statusCounts['archived'] ?? 0 ?></div><div class="text-[10px] text-slate-500">Archived</div></div>
                        </div>
                    </div>
                </div>

            </section>

            <!-- REPORTS -->
            <section id="section-reports" class="content-section">
                <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3 mb-4">
                    <div>
                        <h3 class="text-sm font-semibold text-slate-800 flex items-center gap-2">
                            <span class="w-7 h-7 rounded-xl bg-blue-100 flex items-center justify-center text-blue-600"><i class="fa-solid fa-file-lines"></i></span>
                            <span>Exports & Reports</span>
                        </h3>
                        <p class="text-xs text-slate-500 amharic-text">ሪፖርቶችና ወጪዎች</p>
                    </div>
                    <a href="/admin/reports.php" class="inline-flex items-center gap-2 px-4 py-2 rounded-xl bg-gradient-to-r from-blue-600 to-indigo-600 text-white text-xs font-semibold shadow hover:from-blue-700 hover:to-indigo-700 transition">
                        <i class="fa-solid fa-chart-line"></i> Open Advanced Analytics Center
                        <i class="fa-solid fa-arrow-up-right-from-square text-[10px] opacity-70"></i>
                    </a>
                </div>

                <!-- Quick notice -->
                <div class="panel p-3 mb-4" style="background:linear-gradient(135deg,#eff6ff,#eef2ff);border:1px solid #c7d2fe;">
                    <div class="flex items-center gap-3">
                        <div class="w-10 h-10 bg-blue-100 rounded-xl flex items-center justify-center flex-shrink-0">
                            <i class="fa-solid fa-chart-pie text-blue-600"></i>
                        </div>
                        <div>
                            <div class="text-xs font-semibold text-blue-800">Advanced Analytics Available</div>
                            <div class="text-[10px] text-blue-600">Interactive charts, multi-filter data explorer, and export to CSV, PDF & Word — all in one place.</div>
                        </div>
                    </div>
                </div>

                <!-- Quick Export Buttons -->
                <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4 mb-6">
                    <a href="/admin/export_pdf.php?format=csv&filter=all" class="panel p-4 hover:shadow-md transition block">
                        <div class="flex items-center gap-3">
                            <div class="w-12 h-12 bg-emerald-100 rounded-xl flex items-center justify-center"><i class="fa-solid fa-users text-emerald-600 text-lg"></i></div>
                            <div>
                                <div class="font-semibold text-slate-800 text-sm">All Members</div>
                                <div class="text-[10px] text-slate-500">Export full member list as CSV</div>
                            </div>
                        </div>
                        <div class="mt-3 text-right"><span class="text-xs text-emerald-600 font-medium"><i class="fa-solid fa-download mr-1"></i>Download CSV</span></div>
                    </a>
                    <a href="/admin/export_pdf.php?format=csv&filter=active" class="panel p-4 hover:shadow-md transition block">
                        <div class="flex items-center gap-3">
                            <div class="w-12 h-12 bg-blue-100 rounded-xl flex items-center justify-center"><i class="fa-solid fa-user-check text-blue-600 text-lg"></i></div>
                            <div>
                                <div class="font-semibold text-slate-800 text-sm">Active Members Only</div>
                                <div class="text-[10px] text-slate-500">Active status members</div>
                            </div>
                        </div>
                        <div class="mt-3 text-right"><span class="text-xs text-blue-600 font-medium"><i class="fa-solid fa-download mr-1"></i>Download CSV</span></div>
                    </a>
                    <a href="/admin/export_pdf.php?format=pdf&filter=all" target="_blank" class="panel p-4 hover:shadow-md transition block">
                        <div class="flex items-center gap-3">
                            <div class="w-12 h-12 bg-red-100 rounded-xl flex items-center justify-center"><i class="fa-solid fa-file-pdf text-red-500 text-lg"></i></div>
                            <div>
                                <div class="font-semibold text-slate-800 text-sm">PDF Report</div>
                                <div class="text-[10px] text-slate-500">Print-ready member list</div>
                            </div>
                        </div>
                        <div class="mt-3 text-right"><span class="text-xs text-red-500 font-medium"><i class="fa-solid fa-print mr-1"></i>Open & Print</span></div>
                    </a>
                    <a href="/admin/export_pdf.php?format=csv&filter=waiting" class="panel p-4 hover:shadow-md transition block">
                        <div class="flex items-center gap-3">
                            <div class="w-12 h-12 bg-amber-100 rounded-xl flex items-center justify-center"><i class="fa-solid fa-hourglass-half text-amber-600 text-lg"></i></div>
                            <div>
                                <div class="font-semibold text-slate-800 text-sm">Waiting Members</div>
                                <div class="text-[10px] text-slate-500">Registration type = waiting</div>
                            </div>
                        </div>
                        <div class="mt-3 text-right"><span class="text-xs text-amber-600 font-medium"><i class="fa-solid fa-download mr-1"></i>Download CSV</span></div>
                    </a>
                    <a href="/admin/export_pdf.php?format=csv&filter=no_id" class="panel p-4 hover:shadow-md transition block">
                        <div class="flex items-center gap-3">
                            <div class="w-12 h-12 bg-red-100 rounded-xl flex items-center justify-center"><i class="fa-solid fa-id-card text-red-500 text-lg"></i></div>
                            <div>
                                <div class="font-semibold text-slate-800 text-sm">Members Without ID Card</div>
                                <div class="text-[10px] text-slate-500">ID card not generated yet</div>
                            </div>
                        </div>
                        <div class="mt-3 text-right"><span class="text-xs text-red-500 font-medium"><i class="fa-solid fa-download mr-1"></i>Download CSV</span></div>
                    </a>
                    <a href="/admin/export_pdf.php?format=docx&filter=all" class="panel p-4 hover:shadow-md transition block">
                        <div class="flex items-center gap-3">
                            <div class="w-12 h-12 bg-indigo-100 rounded-xl flex items-center justify-center"><i class="fa-solid fa-file-word text-indigo-600 text-lg"></i></div>
                            <div>
                                <div class="font-semibold text-slate-800 text-sm">Word Document</div>
                                <div class="text-[10px] text-slate-500">All members as Word doc</div>
                            </div>
                        </div>
                        <div class="mt-3 text-right"><span class="text-xs text-indigo-600 font-medium"><i class="fa-solid fa-download mr-1"></i>Download DOC</span></div>
                    </a>
                </div>

                <!-- Summary Stats Report -->
                <div class="panel p-4">
                    <h4 class="text-sm font-semibold text-slate-700 mb-3"><i class="fa-solid fa-chart-bar mr-1 text-blue-500"></i> Quick Summary Stats</h4>
                    <div class="grid grid-cols-2 md:grid-cols-4 gap-3 text-center mb-4">
                        <div class="bg-emerald-50 p-3 rounded-xl"><div class="text-lg font-bold text-emerald-700"><?= $totalMembers ?></div><div class="text-[10px] text-emerald-600">Total Active</div></div>
                        <div class="bg-blue-50 p-3 rounded-xl"><div class="text-lg font-bold text-blue-700"><?= $genderCounts['male'] ?></div><div class="text-[10px] text-blue-600">Male</div></div>
                        <div class="bg-pink-50 p-3 rounded-xl"><div class="text-lg font-bold text-pink-700"><?= $genderCounts['female'] ?></div><div class="text-[10px] text-pink-600">Female</div></div>
                        <div class="bg-amber-50 p-3 rounded-xl"><div class="text-lg font-bold text-amber-700"><?= $memberTypeWaiting ?></div><div class="text-[10px] text-amber-600">Waiting</div></div>
                    </div>
                    <div class="grid grid-cols-2 md:grid-cols-4 gap-3 text-center">
                        <div class="bg-emerald-50 p-3 rounded-xl"><div class="text-lg font-bold text-emerald-700"><?= $sectionCounts['under6'] ?></div><div class="text-[10px] text-emerald-600 amharic-text">አጸደ ህጻናት</div></div>
                        <div class="bg-sky-50 p-3 rounded-xl"><div class="text-lg font-bold text-sky-700"><?= $sectionCounts['7_13'] ?></div><div class="text-[10px] text-sky-600 amharic-text">ህጻናት</div></div>
                        <div class="bg-amber-50 p-3 rounded-xl"><div class="text-lg font-bold text-amber-700"><?= $sectionCounts['14_17'] ?></div><div class="text-[10px] text-amber-600 amharic-text">ማዕከላዊያን</div></div>
                        <div class="bg-rose-50 p-3 rounded-xl"><div class="text-lg font-bold text-rose-700"><?= $sectionCounts['18_plus'] ?></div><div class="text-[10px] text-rose-600 amharic-text">ወጣቶች</div></div>
                    </div>
                </div>
            </section>

            <!-- ATTENDANCE TAKERS -->
            <section id="section-attakers" class="content-section">
                <div class="flex flex-col md:flex-row md:items-center justify-between gap-4 mb-4">
                    <div>
                        <h2 class="text-xl font-bold text-slate-800">Attendance Taker Accounts</h2>
                        <p class="text-sm text-slate-500">Create login accounts for attendance takers</p>
                    </div>
                    <button onclick="openAttakerModal()" class="px-4 py-2 bg-amber-500 text-white rounded-xl font-medium hover:bg-amber-600 transition flex items-center gap-2">
                        <i class="fa-solid fa-user-plus"></i> Create Attendance Taker
                    </button>
                </div>

                <div class="panel overflow-hidden">
                    <table class="w-full text-sm">
                        <thead class="bg-slate-50">
                            <tr>
                                <th class="px-4 py-3 text-left font-semibold text-slate-600">Username</th>
                                <th class="px-4 py-3 text-left font-semibold text-slate-600">Full Name</th>
                                <th class="px-4 py-3 text-left font-semibold text-slate-600">Linked Member</th>
                                <th class="px-4 py-3 text-left font-semibold text-slate-600">Status</th>
                                <th class="px-4 py-3 text-left font-semibold text-slate-600">Actions</th>
                            </tr>
                        </thead>
                        <tbody id="attakersTableBody">
                            <?php
                            $attakersResult = $conn->query("SELECT u.*, m.student_name, m.father_name FROM users u LEFT JOIN members m ON u.member_id = m.id WHERE u.role = 'attendance_taker' ORDER BY u.full_name");
                            $hasAttakers = false;
                            if ($attakersResult):
                                while ($att = $attakersResult->fetch_assoc()):
                                    $hasAttakers = true;
                            ?>
                            <tr class="border-t border-slate-100 hover:bg-slate-50">
                                <td class="px-4 py-3 font-medium"><?= e($att['username']) ?></td>
                                <td class="px-4 py-3"><?= e($att['full_name']) ?></td>
                                <td class="px-4 py-3">
                                    <?php if ($att['member_id']): ?>
                                        <span class="text-emerald-600"><?= e($att['student_name'] . ' ' . $att['father_name']) ?></span>
                                    <?php else: ?>
                                        <span class="text-slate-400">—</span>
                                    <?php endif; ?>
                                </td>
                                <td class="px-4 py-3">
                                    <span class="px-2 py-1 rounded-full text-xs font-medium <?= $att['is_active'] ? 'bg-emerald-100 text-emerald-700' : 'bg-red-100 text-red-700' ?>">
                                        <?= $att['is_active'] ? 'Active' : 'Inactive' ?>
                                    </span>
                                </td>
                                <td class="px-4 py-3">
                                    <button onclick="toggleAttakerStatus(<?= $att['id'] ?>, <?= $att['is_active'] ?>)" 
                                            class="text-<?= $att['is_active'] ? 'red' : 'emerald' ?>-600 hover:text-<?= $att['is_active'] ? 'red' : 'emerald' ?>-800" 
                                            title="<?= $att['is_active'] ? 'Deactivate' : 'Activate' ?>">
                                        <i class="fa-solid fa-<?= $att['is_active'] ? 'ban' : 'check' ?>"></i>
                                    </button>
                                </td>
                            </tr>
                            <?php
                                endwhile;
                            endif;
                            if (!$hasAttakers):
                            ?>
                            <tr>
                                <td colspan="5" class="px-4 py-8 text-center text-slate-400">
                                    <i class="fa-solid fa-user-check text-3xl mb-2"></i>
                                    <p>No attendance taker accounts created yet</p>
                                </td>
                            </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </section>

            <!-- SETTINGS -->
            <section id="section-settings" class="content-section">

                <!-- Settings Header -->
                <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3 mb-5">
                    <div>
                        <h2 class="text-xl font-bold text-slate-800 flex items-center gap-2">
                            <span class="w-9 h-9 rounded-2xl bg-slate-100 flex items-center justify-center"><i class="fa-solid fa-gear text-slate-600"></i></span>
                            Settings
                        </h2>
                        <p class="text-xs text-slate-500 amharic-text mt-1">የክፍል ቅንብሮች</p>
                    </div>
                </div>

                <!-- Settings Sub-Tabs -->
                <div class="flex gap-2 mb-5 overflow-x-auto hide-scrollbar pb-1">
                    <button onclick="showSettingsTab(this,'stProfile')" class="stab stab-on"><i class="fa-solid fa-user mr-1"></i>My Profile</button>
                    <button onclick="showSettingsTab(this,'stDept')" class="stab"><i class="fa-solid fa-building mr-1"></i>Department</button>
                    <button onclick="showSettingsTab(this,'stPrefs')" class="stab"><i class="fa-solid fa-sliders mr-1"></i>Preferences</button>
                    <button onclick="showSettingsTab(this,'stSystem')" class="stab"><i class="fa-solid fa-server mr-1"></i>System</button>
                </div>

                <!-- ===== MY PROFILE ===== -->
                <div id="stProfile" class="settings-pane">
                    <div class="grid grid-cols-1 lg:grid-cols-3 gap-4">
                        <!-- Profile Card -->
                        <div class="panel p-5 text-center">
                            <div class="w-20 h-20 rounded-full bg-gradient-to-br from-emerald-400 to-teal-600 flex items-center justify-center mx-auto mb-3 shadow-lg">
                                <span class="text-3xl font-bold text-white" id="spAvatar"><?= strtoupper(substr($userName, 0, 1)) ?></span>
                            </div>
                            <h3 class="font-bold text-slate-800 text-lg" id="spName"><?= e($userName) ?></h3>
                            <p class="text-xs text-slate-500 mb-1" id="spUsername">@<?= e($username) ?></p>
                            <span class="inline-flex items-center px-3 py-1 rounded-full text-[10px] font-semibold bg-emerald-100 text-emerald-700 uppercase"><?= e($userRole) ?></span>
                            <div class="mt-4 space-y-2 text-xs text-slate-500 text-left">
                                <div class="flex justify-between"><span>Email</span><span class="font-medium text-slate-700" id="spEmail">Loading...</span></div>
                                <div class="flex justify-between"><span>Member Since</span><span class="font-medium text-slate-700" id="spCreated">—</span></div>
                                <div class="flex justify-between"><span>Last Login</span><span class="font-medium text-slate-700" id="spLastLogin">—</span></div>
                                <div class="flex justify-between"><span>Total Logins</span><span class="font-medium text-slate-700" id="spLogins">—</span></div>
                            </div>
                        </div>

                        <!-- Edit Profile Form -->
                        <div class="panel p-5 lg:col-span-2">
                            <h4 class="font-semibold text-slate-700 mb-4 flex items-center gap-2"><i class="fa-solid fa-pen-to-square text-emerald-500"></i> Edit Profile</h4>
                            <div class="space-y-4">
                                <div>
                                    <label class="block text-[10px] font-semibold text-slate-500 mb-1 uppercase">Username</label>
                                    <input type="text" id="profUsername" class="w-full px-3 py-2.5 rounded-xl border border-slate-200 bg-slate-50 text-sm text-slate-500 cursor-not-allowed" disabled>
                                    <p class="text-[10px] text-slate-400 mt-1">Username cannot be changed</p>
                                </div>
                                <div>
                                    <label class="block text-[10px] font-semibold text-slate-500 mb-1 uppercase">Full Name <span class="text-red-400">*</span></label>
                                    <input type="text" id="profName" class="w-full px-3 py-2.5 rounded-xl border border-slate-200 text-sm focus:ring-2 focus:ring-emerald-200 focus:border-emerald-400" placeholder="Your full name">
                                </div>
                                <div>
                                    <label class="block text-[10px] font-semibold text-slate-500 mb-1 uppercase">Email</label>
                                    <input type="email" id="profEmail" class="w-full px-3 py-2.5 rounded-xl border border-slate-200 text-sm focus:ring-2 focus:ring-emerald-200 focus:border-emerald-400" placeholder="your@email.com">
                                </div>
                                <button onclick="saveProfile()" class="px-5 py-2.5 bg-emerald-600 text-white rounded-xl text-xs font-semibold hover:bg-emerald-700 transition flex items-center gap-2">
                                    <i class="fa-solid fa-check"></i> Save Changes
                                </button>
                            </div>

                            <hr class="my-6 border-slate-100">

                            <h4 class="font-semibold text-slate-700 mb-4 flex items-center gap-2"><i class="fa-solid fa-lock text-amber-500"></i> Change Password</h4>
                            <div class="space-y-3">
                                <div>
                                    <label class="block text-[10px] font-semibold text-slate-500 mb-1 uppercase">Current Password</label>
                                    <input type="password" id="pwdCurrent" class="w-full px-3 py-2.5 rounded-xl border border-slate-200 text-sm focus:ring-2 focus:ring-amber-200 focus:border-amber-400" placeholder="Enter current password">
                                </div>
                                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                                    <div>
                                        <label class="block text-[10px] font-semibold text-slate-500 mb-1 uppercase">New Password</label>
                                        <input type="password" id="pwdNew" class="w-full px-3 py-2.5 rounded-xl border border-slate-200 text-sm focus:ring-2 focus:ring-amber-200 focus:border-amber-400" placeholder="Min 6 characters">
                                    </div>
                                    <div>
                                        <label class="block text-[10px] font-semibold text-slate-500 mb-1 uppercase">Confirm New Password</label>
                                        <input type="password" id="pwdConfirm" class="w-full px-3 py-2.5 rounded-xl border border-slate-200 text-sm focus:ring-2 focus:ring-amber-200 focus:border-amber-400" placeholder="Repeat new password">
                                    </div>
                                </div>
                                <button onclick="changePassword()" class="px-5 py-2.5 bg-amber-500 text-white rounded-xl text-xs font-semibold hover:bg-amber-600 transition flex items-center gap-2">
                                    <i class="fa-solid fa-key"></i> Change Password
                                </button>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- ===== DEPARTMENT INFO ===== -->
                <div id="stDept" class="settings-pane" style="display:none">
                    <div class="panel p-5">
                        <h4 class="font-semibold text-slate-700 mb-4 flex items-center gap-2"><i class="fa-solid fa-building text-blue-500"></i> Department & Church Information</h4>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div>
                                <label class="block text-[10px] font-semibold text-slate-500 mb-1 uppercase">Department Name (English)</label>
                                <input type="text" id="deptNameEn" class="w-full px-3 py-2.5 rounded-xl border border-slate-200 text-sm focus:ring-2 focus:ring-blue-200 focus:border-blue-400" placeholder="Information Department">
                            </div>
                            <div>
                                <label class="block text-[10px] font-semibold text-slate-500 mb-1 uppercase">Department Name (Amharic)</label>
                                <input type="text" id="deptNameAm" class="w-full px-3 py-2.5 rounded-xl border border-slate-200 text-sm amharic-text focus:ring-2 focus:ring-blue-200 focus:border-blue-400" placeholder="ማብራሪያ ክፍል">
                            </div>
                            <div>
                                <label class="block text-[10px] font-semibold text-slate-500 mb-1 uppercase">Church/School Name (English)</label>
                                <input type="text" id="churchNameEn" class="w-full px-3 py-2.5 rounded-xl border border-slate-200 text-sm focus:ring-2 focus:ring-blue-200 focus:border-blue-400" placeholder="<?= SCHOOL_TRANSLATION_EN ?> <?= SCHOOL_TYPE ?>">
                            </div>
                            <div>
                                <label class="block text-[10px] font-semibold text-slate-500 mb-1 uppercase">Church/School Name (Amharic)</label>
                                <input type="text" id="churchNameAm" class="w-full px-3 py-2.5 rounded-xl border border-slate-200 text-sm amharic-text focus:ring-2 focus:ring-blue-200 focus:border-blue-400" placeholder="<?= SCHOOL_NAME_AMHARIC ?>">
                            </div>
                            <div class="md:col-span-2">
                                <label class="block text-[10px] font-semibold text-slate-500 mb-1 uppercase">Department Description</label>
                                <textarea id="deptDesc" rows="3" class="w-full px-3 py-2.5 rounded-xl border border-slate-200 text-sm focus:ring-2 focus:ring-blue-200 focus:border-blue-400" placeholder="Brief description of the department..."></textarea>
                            </div>
                        </div>
                        <div class="mt-4">
                            <button onclick="saveDeptSettings()" class="px-5 py-2.5 bg-blue-600 text-white rounded-xl text-xs font-semibold hover:bg-blue-700 transition flex items-center gap-2">
                                <i class="fa-solid fa-save"></i> Save Department Info
                            </button>
                        </div>
                    </div>
                </div>

                <!-- ===== SYSTEM PREFERENCES ===== -->
                <div id="stPrefs" class="settings-pane" style="display:none">
                    <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
                        <!-- Member Code Settings -->
                        <div class="panel p-5">
                            <h4 class="font-semibold text-slate-700 mb-4 flex items-center gap-2"><i class="fa-solid fa-barcode text-violet-500"></i> Member Code Format</h4>
                            <div class="space-y-3">
                                <div>
                                    <label class="block text-[10px] font-semibold text-slate-500 mb-1 uppercase">Code Prefix</label>
                                    <input type="text" id="codePrefix" class="w-full px-3 py-2.5 rounded-xl border border-slate-200 text-sm focus:ring-2 focus:ring-violet-200 focus:border-violet-400" placeholder="e.g. WB (leave empty for no prefix)">
                                    <p class="text-[10px] text-slate-400 mt-1">Result: <span id="codePreview" class="font-mono font-bold text-violet-600">0001</span></p>
                                </div>
                                <div>
                                    <label class="block text-[10px] font-semibold text-slate-500 mb-1 uppercase">Number of Digits</label>
                                    <select id="codeDigits" class="w-full px-3 py-2.5 rounded-xl border border-slate-200 text-sm focus:ring-2 focus:ring-violet-200 focus:border-violet-400" onchange="updateCodePreview()">
                                        <option value="3">3 digits (001)</option>
                                        <option value="4" selected>4 digits (0001)</option>
                                        <option value="5">5 digits (00001)</option>
                                        <option value="6">6 digits (000001)</option>
                                    </select>
                                </div>
                                <div class="flex items-center gap-3">
                                    <label class="relative inline-flex items-center cursor-pointer">
                                        <input type="checkbox" id="autoGenCode" class="sr-only peer" checked>
                                        <div class="w-9 h-5 bg-slate-200 peer-focus:ring-2 peer-focus:ring-violet-200 rounded-full peer peer-checked:after:translate-x-full after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:rounded-full after:h-4 after:w-4 after:transition-all peer-checked:bg-violet-500"></div>
                                    </label>
                                    <span class="text-xs text-slate-600">Auto-generate member codes on registration</span>
                                </div>
                            </div>
                        </div>

                        <!-- Default Values -->
                        <div class="panel p-5">
                            <h4 class="font-semibold text-slate-700 mb-4 flex items-center gap-2"><i class="fa-solid fa-list-check text-teal-500"></i> Registration Defaults</h4>
                            <div class="space-y-3">
                                <div>
                                    <label class="block text-[10px] font-semibold text-slate-500 mb-1 uppercase">Default Age Group</label>
                                    <select id="defAgeGroup" class="w-full px-3 py-2.5 rounded-xl border border-slate-200 text-sm focus:ring-2 focus:ring-teal-200 focus:border-teal-400">
                                        <option value="">None (manual selection)</option>
                                        <option value="under6">Under 6 (አጸደ ህጻናት)</option>
                                        <option value="7_13">7-13 (ህጻናት)</option>
                                        <option value="14_17">14-17 (ማዕከላዊያን)</option>
                                        <option value="18_plus">18+ (ወጣቶች)</option>
                                    </select>
                                </div>
                                <div>
                                    <label class="block text-[10px] font-semibold text-slate-500 mb-1 uppercase">Default Member Type</label>
                                    <select id="defMemType" class="w-full px-3 py-2.5 rounded-xl border border-slate-200 text-sm focus:ring-2 focus:ring-teal-200 focus:border-teal-400">
                                        <option value="regular">Regular</option>
                                        <option value="honorary">Honorary</option>
                                    </select>
                                </div>
                                <div>
                                    <label class="block text-[10px] font-semibold text-slate-500 mb-1 uppercase">Default Registration Type</label>
                                    <select id="defRegType" class="w-full px-3 py-2.5 rounded-xl border border-slate-200 text-sm focus:ring-2 focus:ring-teal-200 focus:border-teal-400">
                                        <option value="direct">Direct</option>
                                        <option value="transfer">Transfer</option>
                                        <option value="waiting">Waiting</option>
                                    </select>
                                </div>
                            </div>
                        </div>

                        <!-- Validation Rules -->
                        <div class="panel p-5">
                            <h4 class="font-semibold text-slate-700 mb-4 flex items-center gap-2"><i class="fa-solid fa-shield-check text-rose-500"></i> Validation Rules</h4>
                            <div class="space-y-3">
                                <div class="flex items-center gap-3">
                                    <label class="relative inline-flex items-center cursor-pointer">
                                        <input type="checkbox" id="phoneRequired" class="sr-only peer">
                                        <div class="w-9 h-5 bg-slate-200 peer-focus:ring-2 peer-focus:ring-rose-200 rounded-full peer peer-checked:after:translate-x-full after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:rounded-full after:h-4 after:w-4 after:transition-all peer-checked:bg-rose-500"></div>
                                    </label>
                                    <span class="text-xs text-slate-600">Require phone number on registration</span>
                                </div>
                                <div class="flex items-center gap-3">
                                    <label class="relative inline-flex items-center cursor-pointer">
                                        <input type="checkbox" id="idAutoGen" class="sr-only peer">
                                        <div class="w-9 h-5 bg-slate-200 peer-focus:ring-2 peer-focus:ring-rose-200 rounded-full peer peer-checked:after:translate-x-full after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:rounded-full after:h-4 after:w-4 after:transition-all peer-checked:bg-rose-500"></div>
                                    </label>
                                    <span class="text-xs text-slate-600">Auto-generate ID cards on registration</span>
                                </div>
                                <div>
                                    <label class="block text-[10px] font-semibold text-slate-500 mb-1 uppercase">Guardian Required Under Age</label>
                                    <select id="guardianAge" class="w-full px-3 py-2.5 rounded-xl border border-slate-200 text-sm focus:ring-2 focus:ring-rose-200 focus:border-rose-400">
                                        <option value="0">Not required</option>
                                        <option value="10">Under 10</option>
                                        <option value="14">Under 14</option>
                                        <option value="16">Under 16</option>
                                        <option value="18">Under 18 (all minors)</option>
                                    </select>
                                </div>
                            </div>
                        </div>

                        <!-- Save Prefs Button -->
                        <div class="panel p-5 flex items-center justify-between">
                            <div>
                                <h4 class="font-semibold text-slate-700 flex items-center gap-2"><i class="fa-solid fa-floppy-disk text-emerald-500"></i> Save All Preferences</h4>
                                <p class="text-[10px] text-slate-400 mt-1">Click to save member code format, defaults, and validation rules</p>
                            </div>
                            <button onclick="savePreferences()" class="px-5 py-2.5 bg-emerald-600 text-white rounded-xl text-xs font-semibold hover:bg-emerald-700 transition flex items-center gap-2">
                                <i class="fa-solid fa-save"></i> Save Preferences
                            </button>
                        </div>
                    </div>
                </div>

                <!-- ===== SYSTEM / DATA MANAGEMENT ===== -->
                <div id="stSystem" class="settings-pane" style="display:none">
                    <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
                        <!-- System Stats -->
                        <div class="panel p-5">
                            <h4 class="font-semibold text-slate-700 mb-4 flex items-center gap-2"><i class="fa-solid fa-chart-simple text-indigo-500"></i> System Statistics</h4>
                            <div id="sysStats" class="space-y-3">
                                <div class="text-center py-4"><div class="inline-block w-5 h-5 border-2 border-slate-200 border-t-emerald-500 rounded-full animate-spin"></div><p class="text-xs text-slate-400 mt-2">Loading...</p></div>
                            </div>
                        </div>

                        <!-- Data Management -->
                        <div class="panel p-5">
                            <h4 class="font-semibold text-slate-700 mb-4 flex items-center gap-2"><i class="fa-solid fa-database text-cyan-500"></i> Data Management</h4>
                            <div class="space-y-3">
                                <a href="/admin/export_pdf.php?format=csv&filter=all" class="flex items-center gap-3 p-3 rounded-xl bg-slate-50 hover:bg-emerald-50 transition cursor-pointer">
                                    <div class="w-9 h-9 bg-emerald-100 rounded-lg flex items-center justify-center"><i class="fa-solid fa-file-csv text-emerald-600"></i></div>
                                    <div><div class="text-sm font-medium text-slate-700">Export All Members (CSV)</div><div class="text-[10px] text-slate-400">Download complete member database</div></div>
                                </a>
                                <a href="/admin/export_pdf.php?format=pdf&filter=all" target="_blank" class="flex items-center gap-3 p-3 rounded-xl bg-slate-50 hover:bg-red-50 transition cursor-pointer">
                                    <div class="w-9 h-9 bg-red-100 rounded-lg flex items-center justify-center"><i class="fa-solid fa-file-pdf text-red-500"></i></div>
                                    <div><div class="text-sm font-medium text-slate-700">Full PDF Report</div><div class="text-[10px] text-slate-400">Print-ready member list</div></div>
                                </a>
                                <div onclick="clearCache()" class="flex items-center gap-3 p-3 rounded-xl bg-slate-50 hover:bg-amber-50 transition cursor-pointer">
                                    <div class="w-9 h-9 bg-amber-100 rounded-lg flex items-center justify-center"><i class="fa-solid fa-broom text-amber-600"></i></div>
                                    <div><div class="text-sm font-medium text-slate-700">Clear Cache</div><div class="text-[10px] text-slate-400">Remove temporary files and rate limit data</div></div>
                                </div>
                                <a href="/admin/system_health.php" class="flex items-center gap-3 p-3 rounded-xl bg-slate-50 hover:bg-blue-50 transition cursor-pointer">
                                    <div class="w-9 h-9 bg-blue-100 rounded-lg flex items-center justify-center"><i class="fa-solid fa-stethoscope text-blue-600"></i></div>
                                    <div><div class="text-sm font-medium text-slate-700">System Health Check</div><div class="text-[10px] text-slate-400">Database diagnostics & auto-fix</div></div>
                                </a>
                                <a href="/admin/reports.php" class="flex items-center gap-3 p-3 rounded-xl bg-slate-50 hover:bg-violet-50 transition cursor-pointer">
                                    <div class="w-9 h-9 bg-violet-100 rounded-lg flex items-center justify-center"><i class="fa-solid fa-chart-line text-violet-600"></i></div>
                                    <div><div class="text-sm font-medium text-slate-700">Advanced Analytics</div><div class="text-[10px] text-slate-400">Charts, reports, filtered exports</div></div>
                                </a>
                            </div>
                        </div>

                        <!-- Recent Activity -->
                        <div class="panel p-5 lg:col-span-2">
                            <h4 class="font-semibold text-slate-700 mb-3 flex items-center gap-2"><i class="fa-solid fa-timeline text-orange-500"></i> Recent Activity Log</h4>
                            <div id="sysActivity" class="text-xs text-slate-400">Loading...</div>
                        </div>
                    </div>
                </div>

            </section>
        </main>

        <!-- Bottom Nav (mobile) -->
        <nav class="md:hidden fixed bottom-0 left-0 right-0 bg-emerald-700 text-white bottom-nav-shadow z-50">
            <div class="flex overflow-x-auto hide-scrollbar px-2 py-2 gap-1">
                <button data-section="dashboard"
                        class="flex flex-col items-center min-w-[64px] px-2 py-1.5 rounded-xl mobile-touch-target nav-bottom-active">
                    <i class="fa-solid fa-chart-pie text-base mb-0.5"></i>
                    <span class="text-[10px] whitespace-nowrap">Dashboard</span>
                </button>
                <button data-section="members"
                        class="flex flex-col items-center min-w-[64px] px-2 py-1.5 rounded-xl mobile-touch-target opacity-80">
                    <i class="fa-solid fa-users text-base mb-0.5"></i>
                    <span class="text-[10px] whitespace-nowrap">Members</span>
                </button>
                <button data-section="manage"
                        class="flex flex-col items-center min-w-[64px] px-2 py-1.5 rounded-xl mobile-touch-target opacity-80">
                    <i class="fa-solid fa-pen-to-square text-base mb-0.5"></i>
                    <span class="text-[10px] whitespace-nowrap">Manage</span>
                </button>
                <button data-section="archive"
                        class="flex flex-col items-center min-w-[64px] px-2 py-1.5 rounded-xl mobile-touch-target opacity-80">
                    <i class="fa-solid fa-box-archive text-base mb-0.5"></i>
                    <span class="text-[10px] whitespace-nowrap">Archive</span>
                </button>
                <button data-section="idcards"
                        class="flex flex-col items-center min-w-[64px] px-2 py-1.5 rounded-xl mobile-touch-target opacity-80">
                    <i class="fa-solid fa-id-card text-base mb-0.5"></i>
                    <span class="text-[10px] whitespace-nowrap">ID Cards</span>
                </button>
                <button data-section="groups"
                        class="flex flex-col items-center min-w-[64px] px-2 py-1.5 rounded-xl mobile-touch-target opacity-80">
                    <i class="fa-solid fa-layer-group text-base mb-0.5"></i>
                    <span class="text-[10px] whitespace-nowrap">Groups</span>
                </button>
                <button data-section="attendance"
                        class="flex flex-col items-center min-w-[64px] px-2 py-1.5 rounded-xl mobile-touch-target opacity-80">
                    <i class="fa-solid fa-clipboard-check text-base mb-0.5"></i>
                    <span class="text-[10px] whitespace-nowrap">Attendance</span>
                </button>
                <button data-section="reports"
                        class="flex flex-col items-center min-w-[64px] px-2 py-1.5 rounded-xl mobile-touch-target opacity-80">
                    <i class="fa-solid fa-file-lines text-base mb-0.5"></i>
                    <span class="text-[10px] whitespace-nowrap">Reports</span>
                </button>
                <button data-section="settings"
                        class="flex flex-col items-center min-w-[64px] px-2 py-1.5 rounded-xl mobile-touch-target opacity-80">
                    <i class="fa-solid fa-gear text-base mb-0.5"></i>
                    <span class="text-[10px] whitespace-nowrap">Settings</span>
                </button>
                <button data-section="attakers"
                        class="flex flex-col items-center min-w-[64px] px-2 py-1.5 rounded-xl mobile-touch-target opacity-80">
                    <i class="fa-solid fa-user-check text-base mb-0.5"></i>
                    <span class="text-[10px] whitespace-nowrap">Att. Takers</span>
                </button>
            </div>
        </nav>
    </div>
</div>

<!-- Attendance Taker Modal -->
<div id="attakerModal" class="fixed inset-0 bg-black/50 backdrop-blur-sm z-50 hidden items-center justify-center p-4">
    <div class="bg-white rounded-2xl max-w-md w-full shadow-2xl">
        <div class="bg-gradient-to-r from-amber-500 to-orange-600 text-white p-4 rounded-t-2xl">
            <div class="flex items-center justify-between">
                <h3 class="font-bold text-lg"><i class="fa-solid fa-user-check mr-2"></i> Create Attendance Taker</h3>
                <button onclick="closeAttakerModal()" class="w-8 h-8 bg-white/20 rounded-lg flex items-center justify-center hover:bg-white/30">&times;</button>
            </div>
        </div>
        <form id="attakerForm" class="p-5">
            <div class="mb-4">
                <label class="block text-xs font-medium text-slate-500 mb-1">Link to Member (Optional)</label>
                <select name="member_id" id="attakerMemberId" class="w-full px-3 py-2 border border-slate-200 rounded-xl text-sm focus:ring-2 focus:ring-amber-500 focus:border-amber-500">
                    <option value="">-- Select Member --</option>
                    <?php
                    $membersForAttaker = $conn->query("SELECT id, member_code, student_name, father_name FROM members WHERE status = 'active' ORDER BY student_name LIMIT 500");
                    while ($ma = $membersForAttaker->fetch_assoc()):
                    ?>
                    <option value="<?= $ma['id'] ?>"><?= e($ma['student_name'] . ' ' . $ma['father_name']) ?> (<?= e($ma['member_code']) ?>)</option>
                    <?php endwhile; ?>
                </select>
            </div>
            
            <div class="mb-4">
                <label class="block text-xs font-medium text-slate-500 mb-1">Full Name *</label>
                <input type="text" name="full_name" id="attakerFullName" class="w-full px-3 py-2 border border-slate-200 rounded-xl text-sm focus:ring-2 focus:ring-amber-500 focus:border-amber-500" required placeholder="Full name">
            </div>
            
            <div class="mb-4">
                <label class="block text-xs font-medium text-slate-500 mb-1">Username *</label>
                <input type="text" name="username" id="attakerUsername" class="w-full px-3 py-2 border border-slate-200 rounded-xl text-sm focus:ring-2 focus:ring-amber-500 focus:border-amber-500" required placeholder="Login username">
            </div>
            
            <div class="mb-4">
                <label class="block text-xs font-medium text-slate-500 mb-1">Email</label>
                <input type="email" name="email" id="attakerEmail" class="w-full px-3 py-2 border border-slate-200 rounded-xl text-sm focus:ring-2 focus:ring-amber-500 focus:border-amber-500" placeholder="Email address (optional)">
            </div>
            
            <div class="mb-5">
                <label class="block text-xs font-medium text-slate-500 mb-1">Password *</label>
                <input type="password" name="password" id="attakerPassword" class="w-full px-3 py-2 border border-slate-200 rounded-xl text-sm focus:ring-2 focus:ring-amber-500 focus:border-amber-500" required placeholder="Secure password">
            </div>
            
            <div class="flex gap-3">
                <button type="button" onclick="closeAttakerModal()" class="flex-1 px-4 py-2 bg-slate-100 text-slate-600 rounded-xl font-medium hover:bg-slate-200 transition">Cancel</button>
                <button type="submit" class="flex-1 px-4 py-2 bg-amber-500 text-white rounded-xl font-medium hover:bg-amber-600 transition flex items-center justify-center gap-2">
                    <i class="fa-solid fa-user-plus"></i> Create Account
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Loading overlay -->
<div id="formLoadingOverlay"
     class="fixed inset-0 bg-black/40 flex items-center justify-center z-50 hidden">
    <div class="bg-white rounded-2xl shadow-lg px-6 py-4 flex items-center gap-3">
        <div class="animate-spin rounded-full h-6 w-6 border-2 border-emerald-500 border-t-transparent"></div>
        <div class="text-sm font-medium text-emerald-700">
            Saving member, please wait…
        </div>
    </div>
</div>

<!-- Success toast -->
<div id="memberSuccessToast" class="fixed inset-x-0 top-0 z-[60] hidden">
    <div class="mx-auto mt-4 w-full max-w-3xl px-4">
        <div class="toast-enter bg-emerald-500 text-white px-5 py-4 rounded-2xl shadow-2xl
                text-[15px] font-semibold flex items-center justify-between gap-3">
            <div class="flex items-center gap-2">
                <span class="text-xl">✅</span>
                <span id="memberSuccessToastText">Member registered successfully.</span>
            </div>
            <button type="button"
                    onclick="document.getElementById('memberSuccessToast').classList.add('hidden')"
                    class="text-xs uppercase tracking-wide bg-emerald-600/70 hover:bg-emerald-700 px-3 py-1 rounded-xl">
                Close
            </button>
        </div>
    </div>
</div>

<!-- Manage sheet -->
<div id="manageSheet" class="sheet">
    <div class="sheet-body flex flex-col h-full bg-slate-50">
        <!-- Header -->
        <div class="flex items-center justify-between px-6 py-4 bg-white border-b border-slate-200 sticky top-0 z-10">
            <div>
                <div class="label-soft">Manage Member</div>
                <div class="text-lg font-bold text-slate-800" id="manageSheetTitle">Member Details</div>
            </div>
            <button class="w-8 h-8 rounded-full bg-slate-100 hover:bg-slate-200 flex items-center justify-center text-slate-500 transition" onclick="closeManageSheet()">
                <i class="fa-solid fa-xmark text-lg"></i>
            </button>
        </div>
        
        <!-- Content -->
        <div id="manageSheetContent" class="flex-1 overflow-y-auto p-6">
            Loading...
        </div>
    </div>
</div>

<script>
    const emerald = '#16a34a';
    const emeraldLight = '#22c55e';
    const gold = '#facc15';
    const sky = '#0ea5e9';
    const rose = '#f97373';

    // Simple section navigation (sidebar + bottom nav)
    function navigateToSection(name) {
        closeManageSheet(); // Close any open modal
        document.querySelectorAll('.content-section').forEach(sec => sec.classList.remove('active'));
        const target = document.getElementById('section-' + name);
        if (target) target.classList.add('active');
        if (name === 'manage') {
            loadManageMembers();
        }

        // Update URL so refresh stays on this section
        const url = new URL(window.location);
        url.searchParams.set('section', name);
        history.replaceState(null, '', url);

        // Highlight desktop pills
        document.querySelectorAll('aside [data-section]').forEach(btn => {
            btn.classList.remove('nav-pill-active', 'bg-white/20');
        });
        document.querySelectorAll('aside [data-section="' + name + '"]').forEach(btn => {
            btn.classList.add('nav-pill-active', 'bg-white/20');
        });

        // Highlight bottom nav
        document.querySelectorAll('nav [data-section]').forEach(btn => {
            btn.classList.remove('opacity-100');
            btn.classList.add('opacity-80');
        });
        document.querySelectorAll('nav [data-section="' + name + '"]').forEach(btn => {
            btn.classList.remove('opacity-80');
            btn.classList.add('opacity-100');
        });
    }

    document.querySelectorAll('[data-section]').forEach(el => {
        el.addEventListener('click', function (e) {
            e.preventDefault();
            const name = this.getAttribute('data-section');
            if (name) navigateToSection(name);
        });
    });

    // Initialize default section
    const urlParams = new URLSearchParams(window.location.search);
    const sectionParam = urlParams.get('section');
    if (sectionParam) {
        navigateToSection(sectionParam);
    } else {
        navigateToSection('dashboard');
    }

    // Charts
    document.addEventListener('DOMContentLoaded', () => {
        const sectionCtx = document.getElementById('sectionChart').getContext('2d');
        new Chart(sectionCtx, {
            type: 'bar',
            data: {
                labels: ['አጸደ ህጻናት', 'ህጻናት', 'ማዕከላዊያን', 'ወጣቶች'],
                datasets: [{
                    data: [
                        <?= (int)$sectionCounts['under6'] ?>,
                        <?= (int)$sectionCounts['7_13'] ?>,
                        <?= (int)$sectionCounts['14_17'] ?>,
                        <?= (int)$sectionCounts['18_plus'] ?>
                    ],
                    backgroundColor: [
                        'rgba(22,163,74,0.9)',
                        'rgba(34,197,94,0.9)',
                        'rgba(250,204,21,0.9)',
                        'rgba(248,113,113,0.9)'
                    ],
                    borderRadius: 12,
                    borderWidth: 0
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {legend: {display: false}},
                scales: {
                    x: {
                        grid: {display: false},
                        ticks: {color: '#64748b', font: {size: 10}}
                    },
                    y: {
                        grid: {color: '#e2e8f0'},
                        ticks: {color: '#64748b', font: {size: 10}, precision: 0}
                    }
                }
            }
        });

        const genderCtx = document.getElementById('genderChart').getContext('2d');
        new Chart(genderCtx, {
            type: 'bar',
            data: {
                labels: ['Male', 'Female'],
                datasets: [{
                    data: [<?= (int)$genderCounts['male'] ?>, <?= (int)$genderCounts['female'] ?>],
                    backgroundColor: ['rgba(59,130,246,0.9)', 'rgba(244,63,94,0.9)'],
                    borderRadius: 12,
                    borderWidth: 0
                }]
            },
            options: {
                indexAxis: 'y',
                responsive: true,
                maintainAspectRatio: false,
                plugins: {legend: {display: false}},
                scales: {
                    x: {
                        grid: {color: '#e2e8f0'},
                        ticks: {color: '#64748b', font: {size: 10}, precision: 0}
                    },
                    y: {
                        grid: {display: false},
                        ticks: {color: '#64748b', font: {size: 10}}
                    }
                }
            }
        });
    });

    // --- Member registration UI helpers ---

    function toggleMemberRegistrationForm(show) {
        const wrapper = document.getElementById('memberRegistrationWrapper');
        const list = document.getElementById('membersListPlaceholder');

        if (!wrapper || !list) return;

        if (show === true) {
            wrapper.classList.remove('hidden');
            list.classList.add('hidden');
            navigateToSection('members');
        } else if (show === false) {
            wrapper.classList.add('hidden');
            list.classList.remove('hidden');
        } else {
            if (wrapper.classList.contains('hidden')) {
                wrapper.classList.remove('hidden');
                list.classList.add('hidden');
            } else {
                wrapper.classList.add('hidden');
                list.classList.remove('hidden');
            }
        }
    }

    function selectRegistrationType(type, btn) {
        const hidden = document.getElementById('registrationTypeField');
        if (hidden) hidden.value = type;

        const idDisplay = document.getElementById('nextMemberIdDisplay');
        const idField = document.getElementById('studentIdField');
        const signedInput = document.getElementById('docSignedFormField');

        // Waiting list: no ID + signed form not required
        if (type === 'waiting') {
            if (idDisplay) idDisplay.textContent = 'Pending';
            if (idField) idField.value = '';
        } else {
            if (idDisplay) idDisplay.textContent = idDisplay.textContent || '<?= e($nextMemberCode) ?>';
            if (idField) idField.value = idField.value || '<?= e($nextMemberCode) ?>';
        }

        // Signed form required ONLY for transfer or direct
        if (signedInput) signedInput.required = (type === 'transfer' || type === 'direct');

        document.querySelectorAll('.registration-type-btn').forEach(b => {
            b.classList.remove('ring-2', 'ring-emerald-500', 'bg-emerald-50', 'border-emerald-300');
            b.classList.add('border-slate-200', 'bg-white');
        });

        if (btn) {
            btn.classList.remove('border-slate-200', 'bg-white');
            btn.classList.add('ring-2', 'ring-emerald-500', 'bg-emerald-50', 'border-emerald-300');
        }
    }

    function selectMemberTypeFull(type, btn) {
        const hidden = document.getElementById('memberTypeFieldFull');
        if (hidden) hidden.value = type;

        const roleSection = document.getElementById('roleFlagsSection');
        if (roleSection) {
            if (type === 'special_regular') {
                roleSection.classList.remove('hidden');
            } else {
                roleSection.classList.add('hidden');
            }
        }

        document.querySelectorAll('.member-type-btn').forEach(b => {
            b.classList.remove('ring-2', 'ring-emerald-500', 'bg-emerald-50', 'border-emerald-300');
            b.classList.add('border-slate-200', 'bg-white');
        });

        if (btn) {
            btn.classList.remove('border-slate-200', 'bg-white');
            btn.classList.add('ring-2', 'ring-emerald-500', 'bg-emerald-50', 'border-emerald-300');
        }
    }

    function previewImage(input, imgId, placeholderId) {
        const file = input.files && input.files[0];
        const img = document.getElementById(imgId);
        const placeholder = document.getElementById(placeholderId);

        if (!img || !placeholder) return;

        if (file) {
            const reader = new FileReader();
            reader.onload = e => {
                img.src = e.target.result;
                img.classList.remove('hidden');
                placeholder.classList.add('hidden');
            };
            reader.readAsDataURL(file);
        } else {
            img.src = '';
            img.classList.add('hidden');
            placeholder.classList.remove('hidden');
        }
    }

    function resetMemberForm() {
        const form = document.getElementById('memberRegistrationForm');
        if (!form) return;
        form.reset();

        // Default registration type: waiting
        const defaultRegBtn = document.querySelector('.registration-type-btn[data-registration-type="waiting"]');
        if (defaultRegBtn) selectRegistrationType('waiting', defaultRegBtn);

        // Default member type: regular
        const defaultTypeBtn = document.querySelector('.member-type-btn[data-member-type="regular"]');
        if (defaultTypeBtn) selectMemberTypeFull('regular', defaultTypeBtn);

        // Clear previews
        previewImage({files: []}, 'studentPhotoPreview', 'studentPhotoPlaceholder');
        previewImage({files: []}, 'guardianPhotoPreview', 'guardianPhotoPlaceholder');

        // Reset age/section
        ['ageDisplay','ageField','sectionDisplay','currentSectionField','ageGroupField'].forEach(id=>{
            const el = document.getElementById(id);
            if (el) el.value = '';
        });
    }

    // Ethiopian months for DOB dropdown
    function initEthiopianMonths() {
        const monthSelect = document.getElementById('dobMonth');
        if (!monthSelect) return;

        const months = [
            {value: '1', label: 'መስከረም'},
            {value: '2', label: 'ጥቅምት'},
            {value: '3', label: 'ህዳር'},
            {value: '4', label: 'ታኅሣስ'},
            {value: '5', label: 'ጥር'},
            {value: '6', label: 'የካቲት'},
            {value: '7', label: 'መጋቢት'},
            {value: '8', label: 'ሚያዝያ'},
            {value: '9', label: 'ግንቦት'},
            {value: '10', label: 'ሰኔ'},
            {value: '11', label: 'ሐምሌ'},
            {value: '12', label: 'ነሐሴ'},
            {value: '13', label: 'ጳጉሜ'}
        ];

        monthSelect.innerHTML = '<option value="">Month</option>';
        months.forEach(m => {
            const opt = document.createElement('option');
            opt.value = m.value;
            opt.textContent = m.label;
            monthSelect.appendChild(opt);
        });
    }

    // Calculate age & section (same value used as school section)
    function calculateAgeSection() {
        const year = parseInt(document.getElementById('dobYear')?.value || '0', 10);
        const currentYearEC = <?php echo (int) ethio_date_format(new DateTime('now', new DateTimeZone('Africa/Addis_Ababa')), 'Y'); ?>;

        if (!year || year > currentYearEC) {
            ['ageDisplay','ageField','sectionDisplay','currentSectionField','ageGroupField'].forEach(id=>{
                const el = document.getElementById(id);
                if (el) el.value = '';
            });
            return;
        }

        let age = currentYearEC - year;
        if (age < 0) age = 0;

        let sectionLabel = '';
        let ageGroup = '';

        if (age <= 6) {
            sectionLabel = 'አጸደ ህጻናት';
            ageGroup = 'under6';
        } else if (age >= 7 && age <= 13) {
            sectionLabel = 'ህጻናት';
            ageGroup = '7_13';
        } else if (age >= 14 && age <= 17) {
            sectionLabel = 'ማዕከላዊያን';
            ageGroup = '14_17';
        } else {
            sectionLabel = 'ወጣቶች';
            ageGroup = '18_plus';
        }

        if (document.getElementById('ageDisplay')) document.getElementById('ageDisplay').value = age.toString();
        if (document.getElementById('ageField')) document.getElementById('ageField').value = age.toString();
        if (document.getElementById('sectionDisplay')) document.getElementById('sectionDisplay').value = sectionLabel;
        if (document.getElementById('currentSectionField')) document.getElementById('currentSectionField').value = sectionLabel;
        if (document.getElementById('ageGroupField')) document.getElementById('ageGroupField').value = ageGroup;
    }

    // Toggle custom registration date fields
    function toggleCustomRegDate() {
        const checkbox = document.getElementById('useCustomRegDate');
        const fieldsDiv = document.getElementById('customRegDateFields');
        
        if (checkbox && fieldsDiv) {
            if (checkbox.checked) {
                fieldsDiv.classList.remove('hidden');
            } else {
                fieldsDiv.classList.add('hidden');
                // Clear fields when hidden
                document.getElementById('regDateDay').value = '';
                document.getElementById('regDateMonth').value = '';
                document.getElementById('regDateYear').value = '';
                document.getElementById('regDateDuration').textContent = 'Enter date to see membership duration';
            }
        }
    }

    // Calculate membership duration from registration date
    function calculateRegDuration() {
        const day = parseInt(document.getElementById('regDateDay')?.value || '0', 10);
        const month = parseInt(document.getElementById('regDateMonth')?.value || '0', 10);
        const year = parseInt(document.getElementById('regDateYear')?.value || '0', 10);
        const durationEl = document.getElementById('regDateDuration');
        
        if (!durationEl) return;
        
        const currentYearEC = <?php echo (int) ethio_date_format(new DateTime('now', new DateTimeZone('Africa/Addis_Ababa')), 'Y'); ?>;
        const currentMonthEC = <?php echo (int) ethio_date_format(new DateTime('now', new DateTimeZone('Africa/Addis_Ababa')), 'n'); ?>;
        
        if (!year || year <= 0 || year > currentYearEC) {
            durationEl.textContent = 'Enter date to see membership duration';
            return;
        }
        
        // Calculate duration
        let years = currentYearEC - year;
        let months = currentMonthEC - (month || 1);
        
        if (months < 0) {
            years--;
            months += 13; // Ethiopian calendar has 13 months
        }
        
        let durationText = '';
        if (years > 0 && months > 0) {
            durationText = `${years} ዓመት ${months} ወር (${years} year${years > 1 ? 's' : ''} ${months} month${months > 1 ? 's' : ''})`;
        } else if (years > 0) {
            durationText = `${years} ዓመት (${years} year${years > 1 ? 's' : ''})`;
        } else if (months > 0) {
            durationText = `${months} ወር (${months} month${months > 1 ? 's' : ''})`;
        } else {
            durationText = 'Less than a month';
        }
        
        durationEl.innerHTML = '<i class="fa-solid fa-clock mr-1"></i> Membership duration: <strong>' + durationText + '</strong>';
    }

    // Add event listeners for registration date fields
    document.addEventListener('DOMContentLoaded', function() {
        ['regDateDay', 'regDateMonth', 'regDateYear'].forEach(id => {
            const el = document.getElementById(id);
            if (el) {
                el.addEventListener('input', calculateRegDuration);
                el.addEventListener('change', calculateRegDuration);
            }
        });
    });

    // City -> Sub city mapping
    const CITY_SUBCITY_MAP = {
        addis_ababa: [
            'Arada', 'Bole', 'Nifas Silk-Lafto', 'Kirkos', 'Lideta',
            'Yeka', 'Kolfe Keranio', 'Gullele', 'Akaki Kality', 'Addis Ketema'
        ],
        oromia: [
            'koye feche', 'Adama', 'Bishoftu', 'Jimma', 'Shashamane', 'Ambo',
            'Asella', 'Nekemte', 'Holeta'
        ]
    };

    function updateSubCities() {
        const city = document.getElementById('cityField')?.value || '';
        const subSelect = document.getElementById('subCityField');
        if (!subSelect) return;

        subSelect.innerHTML = '<option value="">Select sub city</option>';

        if (CITY_SUBCITY_MAP[city]) {
            CITY_SUBCITY_MAP[city].forEach(name => {
                const opt = document.createElement('option');
                opt.value = name.toLowerCase().replace(/\s+/g, '_');
                opt.textContent = name;
                subSelect.appendChild(opt);
            });
        }
    }

    function updateGuardianSubCities() {
        const city = document.getElementById('guardianCityField')?.value || '';
        const subSelect = document.getElementById('guardianSubCityField');
        if (!subSelect) return;

        subSelect.innerHTML = '<option value="">Select sub city</option>';

        if (CITY_SUBCITY_MAP[city]) {
            CITY_SUBCITY_MAP[city].forEach(name => {
                const opt = document.createElement('option');
                opt.value = name.toLowerCase().replace(/\s+/g, '_');
                opt.textContent = name;
                subSelect.appendChild(opt);
            });
        }
    }

    function copyMemberAddressToGuardian() {
        const city = document.getElementById('cityField')?.value || '';
        const subCity = document.getElementById('subCityField')?.value || '';
        const woreda = document.querySelector('[name="woreda"]')?.value || '';
        const mender = document.querySelector('[name="mender"]')?.value || '';
        const block = document.querySelector('[name="block_number"]')?.value || '';
        const house = document.querySelector('[name="house_number"]')?.value || '';

        const gCity = document.getElementById('guardianCityField');
        const gSubCity = document.getElementById('guardianSubCityField');
        const gWoreda = document.getElementById('guardianWoredaField');
        const gMender = document.getElementById('guardianMenderField');
        const gBlock = document.getElementById('guardianBlockField');
        const gHouse = document.getElementById('guardianHouseField');

        if (gCity) {
            gCity.value = city;
            updateGuardianSubCities();
        }
        setTimeout(() => {
            if (gSubCity && subCity) gSubCity.value = subCity;
        }, 50);

        if (gWoreda) gWoreda.value = woreda;
        if (gMender) gMender.value = mender;
        if (gBlock) gBlock.value = block;
        if (gHouse) gHouse.value = house;
    }

    // Init defaults on load
    document.addEventListener('DOMContentLoaded', () => {
        // Default registration type: waiting
        const defaultRegBtn = document.querySelector('.registration-type-btn[data-registration-type="waiting"]');
        if (defaultRegBtn) selectRegistrationType('waiting', defaultRegBtn);

        // Default member type: regular
        const defaultTypeBtn = document.querySelector('.member-type-btn[data-member-type="regular"]');
        if (defaultTypeBtn) selectMemberTypeFull('regular', defaultTypeBtn);

        // Ethiopian months
        initEthiopianMonths();
    });

    function showMemberSuccessToast(message) {
        const toast = document.getElementById('memberSuccessToast');
        if (!toast) return;

        const span = document.getElementById('memberSuccessToastText');
        if (span && message) span.textContent = message;

        toast.classList.remove('hidden');
        setTimeout(() => toast.classList.add('hidden'), 4000);
    }

    function handleMemberFormSubmit(event) {
        event.preventDefault();

        const form = document.getElementById('memberRegistrationForm');
        if (!form) return;

        const overlay = document.getElementById('formLoadingOverlay');
        if (overlay) overlay.classList.remove('hidden');

        const formData = new FormData(form);
        formData.append('csrf_token', CSRF_TOKEN);

        fetch('<?php echo $ajaxPrefix; ?>info_register_member.php', {
            method: 'POST',
            body: formData,
            headers: { 'Accept': 'application/json' }
        })
            .then(response => {
                const ct = response.headers.get('content-type') || '';
                if (!ct.includes('application/json')) {
                    throw { _type: 'session_expired', message: 'Session expired. The page will reload.' };
                }
                return response.json();
            })
            .then(data => {
                if (overlay) overlay.classList.add('hidden');

                if (data && (data.status === 'session_expired' || data.status === 'csrf_expired' || data.action === 'reload')) {
                    alert(data.message || 'Session expired. The page will reload.');
                    window.location.reload();
                    return;
                }

                if (data && data.status === 'success') {
                    resetMemberForm();
                    showMemberSuccessToast(data.message || 'Member registered successfully.');
                    const wrapper = document.getElementById('memberRegistrationWrapper');
                    if (wrapper) wrapper.scrollIntoView({ behavior: 'smooth', block: 'start' });
                } else {
                    alert(data && data.message ? data.message : 'Saving failed. Please try again.');
                }
            })
            .catch(err => {
                if (overlay) overlay.classList.add('hidden');
                if (err && err._type === 'session_expired') {
                    alert(err.message); window.location.reload(); return;
                }
                if (err instanceof SyntaxError) {
                    alert('Session may have expired. The page will reload.'); window.location.reload(); return;
                }
                console.error(err);
                alert('Connection error. Please check your internet and try again.\n\nIf this keeps happening, refresh the page first.');
            });
    }

    // Advanced search / filters
    function applyMembersFilters() {
        const searchInput = document.getElementById('memberSearchInput');
        const regTypeSel  = document.getElementById('filterRegistrationType');
        const mTypeSel    = document.getElementById('filterMemberType');
        const statusSel   = document.getElementById('filterStatus');
        const genderSel   = document.getElementById('filterGender');
        const ageSel      = document.getElementById('filterAgeGroup');
        const rows        = document.querySelectorAll('#membersTableBody .member-row');
        const visibleCountLabel = document.getElementById('membersVisibleCount');

        if (!rows.length) {
            if (visibleCountLabel) visibleCountLabel.textContent = '';
            return;
        }

        const searchVal = searchInput ? searchInput.value.trim().toLowerCase() : '';
        const regVal    = regTypeSel ? regTypeSel.value : '';
        const mTypeVal  = mTypeSel ? mTypeSel.value : '';
        const statusVal = statusSel ? statusSel.value : '';
        const genderVal = genderSel ? genderSel.value : '';
        const ageVal    = ageSel ? ageSel.value : '';

        let visible = 0;

        rows.forEach(row => {
            let ok = true;

            const rowSearch = row.getAttribute('data-search') || '';
            const rowReg    = row.getAttribute('data-regtype') || '';
            const rowMType  = row.getAttribute('data-mtype') || '';
            const rowStatus = row.getAttribute('data-status') || '';
            const rowGender = row.getAttribute('data-gender') || '';
            const rowAge    = row.getAttribute('data-agegroup') || '';

            if (searchVal && !rowSearch.includes(searchVal)) ok = false;
            if (ok && regVal && rowReg !== regVal) ok = false;
            if (ok && mTypeVal && rowMType !== mTypeVal) ok = false;
            if (ok && statusVal && rowStatus !== statusVal) ok = false;
            if (ok && genderVal && rowGender !== genderVal) ok = false;
            if (ok && ageVal && rowAge !== ageVal) ok = false;

            if (ok) {
                row.classList.remove('hidden');
                visible++;
            } else {
                row.classList.add('hidden');
            }
        });

        if (visibleCountLabel) visibleCountLabel.textContent = visible + ' matching member' + (visible === 1 ? '' : 's');
    }

    document.addEventListener('DOMContentLoaded', function () {
        const searchInput = document.getElementById('memberSearchInput');
        const regTypeSel  = document.getElementById('filterRegistrationType');
        const mTypeSel    = document.getElementById('filterMemberType');
        const statusSel   = document.getElementById('filterStatus');
        const genderSel   = document.getElementById('filterGender');
        const ageSel      = document.getElementById('filterAgeGroup');

        if (searchInput) searchInput.addEventListener('input', applyMembersFilters);
        if (regTypeSel)  regTypeSel.addEventListener('change', applyMembersFilters);
        if (mTypeSel)    mTypeSel.addEventListener('change', applyMembersFilters);
        if (statusSel)   statusSel.addEventListener('change', applyMembersFilters);
        if (genderSel)   genderSel.addEventListener('change', applyMembersFilters);
        if (ageSel)      ageSel.addEventListener('change', applyMembersFilters);

        applyMembersFilters();
    });

    // Manage sheet (edit)
    function openManageSheet(id) {
        const sheet = document.getElementById('manageSheet');
        const content = document.getElementById('manageSheetContent');
        const title = document.getElementById('manageSheetTitle');
        if (!sheet || !content) return;

        sheet.classList.add('open');
        content.innerHTML = 'Loading...';

        const cacheBust = Date.now();
        fetch('/admin/info_manage_member.php?id=' + encodeURIComponent(id) + '&v=' + cacheBust)
            .then(res => res.text())
            .then(html => {
                content.innerHTML = html;
                if (title) title.textContent = 'Member #' + id;
                // Default to Preview tab
                switchManageTab('preview');
            })
            .catch(() => {
                content.innerHTML = '<div class="text-red-600 text-sm">Failed to load member.</div>';
            });
    }

    function closeManageSheet() {
        const sheet = document.getElementById('manageSheet');
        if (sheet) sheet.classList.remove('open');
    }

    // Archive action - Show verification modal
    let archiveMemberId = null;
    let archiveMemberName = '';
    
    function archiveMember(id, name) {
        archiveMemberId = id;
        archiveMemberName = name || 'this member';
        
        // Update modal content
        document.getElementById('archiveMemberName').textContent = archiveMemberName;
        document.getElementById('archiveReason').value = '';
        document.getElementById('archiveNotes').value = '';
        document.getElementById('archiveConfirmText').value = '';
        document.getElementById('confirmArchiveBtn').disabled = true;
        
        // Show modal
        document.getElementById('archiveModal').classList.remove('hidden');
        document.getElementById('archiveModal').classList.add('flex');
    }
    
    function closeArchiveModal() {
        document.getElementById('archiveModal').classList.add('hidden');
        document.getElementById('archiveModal').classList.remove('flex');
        archiveMemberId = null;
    }
    
    function checkArchiveConfirmation() {
        const confirmText = document.getElementById('archiveConfirmText').value.trim();
        const reason = document.getElementById('archiveReason').value;
        const btn = document.getElementById('confirmArchiveBtn');
        
        // Must type "ARCHIVE" and select a reason
        if (confirmText === 'ARCHIVE' && reason !== '') {
            btn.disabled = false;
            btn.classList.remove('bg-slate-300', 'cursor-not-allowed');
            btn.classList.add('bg-red-600', 'hover:bg-red-700');
        } else {
            btn.disabled = true;
            btn.classList.add('bg-slate-300', 'cursor-not-allowed');
            btn.classList.remove('bg-red-600', 'hover:bg-red-700');
        }
    }
    
    function confirmArchive() {
        if (!archiveMemberId) return;
        
        const reason = document.getElementById('archiveReason').value;
        const notes = document.getElementById('archiveNotes').value;
        const btn = document.getElementById('confirmArchiveBtn');
        
        btn.disabled = true;
        btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin mr-2"></i> Archiving...';
        
        fetch('/admin/info_archive_member.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({
                id: archiveMemberId,
                reason: reason,
                notes: notes,
                csrf_token: CSRF_TOKEN
            })
        }).then(r=>r.json()).then(data=>{
            if (data.status === 'success') {
                closeArchiveModal();
                showToast('✓ ' + data.message, 'success');
                setTimeout(() => window.location.reload(), 1500);
            } else {
                showToast(data.message || 'Archive failed.', 'error');
                btn.disabled = false;
                btn.innerHTML = '<i class="fa-solid fa-box-archive mr-2"></i> Archive Member';
            }
        }).catch(()=>{
            showToast('Network error. Please try again.', 'error');
            btn.disabled = false;
            btn.innerHTML = '<i class="fa-solid fa-box-archive mr-2"></i> Archive Member';
        });
    }
    
    // Restore member from archive
    function restoreMember(id, name) {
        if (!confirm('Restore "' + name + '" back to active members?')) return;
        
        fetch('/admin/info_restore_member.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({id: id, csrf_token: CSRF_TOKEN})
        }).then(r=>r.json()).then(data=>{
            if (data.status === 'success') {
                showToast('✓ ' + data.message, 'success');
                setTimeout(() => window.location.reload(), 1500);
            } else {
                showToast(data.message || 'Restore failed.', 'error');
            }
        }).catch(()=>{
            showToast('Network error.', 'error');
        });
    }
    
    // Toast notification
    function showToast(message, type) {
        const toast = document.createElement('div');
        toast.className = 'fixed top-4 right-4 z-[200] px-6 py-4 rounded-xl shadow-2xl text-white font-semibold text-sm transform transition-all duration-300 translate-x-full';
        toast.style.background = type === 'success' ? 'linear-gradient(135deg, #059669, #10b981)' : 'linear-gradient(135deg, #dc2626, #ef4444)';
        toast.innerHTML = message;
        document.body.appendChild(toast);
        
        setTimeout(() => toast.classList.remove('translate-x-full'), 100);
        setTimeout(() => {
            toast.classList.add('translate-x-full');
            setTimeout(() => toast.remove(), 300);
        }, 3000);
    }

    // ---------------------------------------------------------
    // ARCHIVED MEMBERS FUNCTIONS
    // ---------------------------------------------------------
    let archivedMembersData = [];
    
    function loadArchivedMembers() {
        const listContainer = document.getElementById('archivedMembersList');
        const countBadge = document.getElementById('archivedCount');
        
        listContainer.innerHTML = `
            <div class="text-center py-8 text-slate-400">
                <i class="fa-solid fa-spinner fa-spin text-2xl mb-2"></i>
                <p class="text-sm">Loading archived members...</p>
            </div>
        `;
        
        fetch('/admin/info_get_archived_members.php')
            .then(r => r.json())
            .then(data => {
                if (data.status === 'success') {
                    archivedMembersData = data.members || [];
                    countBadge.textContent = archivedMembersData.length + ' Members';
                    renderArchivedMembers(archivedMembersData);
                } else {
                    listContainer.innerHTML = `
                        <div class="text-center py-8 text-red-400">
                            <i class="fa-solid fa-exclamation-circle text-2xl mb-2"></i>
                            <p class="text-sm">${data.message || 'Failed to load archived members'}</p>
                        </div>
                    `;
                }
            })
            .catch(() => {
                listContainer.innerHTML = `
                    <div class="text-center py-8 text-red-400">
                        <i class="fa-solid fa-wifi text-2xl mb-2"></i>
                        <p class="text-sm">Network error. Please try again.</p>
                    </div>
                `;
            });
    }
    
    function renderArchivedMembers(members) {
        const listContainer = document.getElementById('archivedMembersList');
        
        if (members.length === 0) {
            listContainer.innerHTML = `
                <div class="text-center py-12 text-slate-400">
                    <i class="fa-solid fa-box-open text-4xl mb-3 text-slate-300"></i>
                    <p class="text-sm font-medium">No archived members found</p>
                    <p class="text-xs mt-1">Archived members will appear here</p>
                </div>
            `;
            return;
        }
        
        const reasonLabels = {
            'left_school': 'ከት/ቤት ወጥቷል',
            'graduated': 'ተመርቋል',
            'transferred': 'ተዛውሯል',
            'inactive_long': 'ረጅም ጊዜ ቦዝ',
            'deceased': 'አርፏል',
            'other': 'ሌላ'
        };
        
        listContainer.innerHTML = members.map(m => {
            const fullName = (m.student_name || '') + ' ' + (m.father_name || '');
            const photo = m.student_photo_path ? fixImagePath(m.student_photo_path) : '';
            const section = m.current_section || m.age_group || '—';
            const reason = reasonLabels[m.archive_reason] || m.archive_reason || 'Unknown';
            const archivedDate = m.archived_at ? (typeof WBWSCalendar!=='undefined'?WBWSCalendar.formatDate(m.archived_at,'medium'):new Date(m.archived_at).toLocaleDateString('en-GB')) : '—';
            
            return `
                <div class="bg-white border border-slate-200 rounded-xl p-4 hover:shadow-md transition">
                    <div class="flex items-start gap-4">
                        <!-- Photo -->
                        <div class="w-14 h-14 rounded-xl bg-slate-100 overflow-hidden flex-shrink-0 border-2 border-amber-200">
                            ${photo 
                                ? `<img src="${photo}" class="w-full h-full object-cover" alt="">` 
                                : `<div class="w-full h-full flex items-center justify-center text-slate-400"><i class="fa-solid fa-user text-xl"></i></div>`
                            }
                        </div>
                        
                        <!-- Info -->
                        <div class="flex-1 min-w-0">
                            <div class="flex items-start justify-between gap-2">
                                <div>
                                    <h4 class="font-bold text-slate-800 text-sm truncate">${escapeHtml(fullName)}</h4>
                                    <p class="text-xs text-slate-500 mt-0.5">
                                        <span class="bg-slate-100 px-2 py-0.5 rounded">${m.member_code || 'No ID'}</span>
                                        <span class="mx-1">•</span>
                                        ${section}
                                    </p>
                                </div>
                                <span class="px-2 py-1 bg-amber-100 text-amber-700 rounded-lg text-[10px] font-bold flex-shrink-0">
                                    ARCHIVED
                                </span>
                            </div>
                            
                            <!-- Archive Info -->
                            <div class="mt-3 flex flex-wrap items-center gap-2 text-xs">
                                <span class="px-2 py-1 bg-slate-100 text-slate-600 rounded-lg">
                                    <i class="fa-solid fa-tag mr-1"></i> ${reason}
                                </span>
                                <span class="text-slate-400">
                                    <i class="fa-solid fa-calendar mr-1"></i> ${archivedDate}
                                </span>
                            </div>
                            
                            <!-- Actions -->
                            <div class="mt-3 flex gap-2">
                                <button onclick="restoreMember(${m.id}, '${fullName.replace(/'/g, "\\'")}')" 
                                        class="flex-1 px-3 py-2 bg-emerald-50 text-emerald-600 hover:bg-emerald-100 rounded-lg text-xs font-bold transition">
                                    <i class="fa-solid fa-rotate-left mr-1"></i> Restore to Active
                                </button>
                                <button onclick="openManageSheet(${m.id})" 
                                        class="px-3 py-2 bg-slate-100 text-slate-600 hover:bg-slate-200 rounded-lg text-xs font-bold transition">
                                    <i class="fa-solid fa-eye mr-1"></i> View
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            `;
        }).join('');
    }
    
    function filterArchivedMembers() {
        const query = document.getElementById('archiveSearch').value.toLowerCase().trim();
        
        if (!query) {
            renderArchivedMembers(archivedMembersData);
            return;
        }
        
        const filtered = archivedMembersData.filter(m => {
            const fullName = ((m.student_name || '') + ' ' + (m.father_name || '') + ' ' + (m.grandfather_name || '')).toLowerCase();
            const code = (m.member_code || '').toLowerCase();
            return fullName.includes(query) || code.includes(query);
        });
        
        renderArchivedMembers(filtered);
        document.getElementById('archivedCount').textContent = filtered.length + ' / ' + archivedMembersData.length;
    }
    
    function fixImagePath(path) {
        if (!path) return '';
        if (path.startsWith('http')) return path;
        path = path.replace(/^\/+/, '');
        if (path.startsWith('uploads/')) return '/admin/' + path;
        if (path.startsWith('admin/')) return '/' + path;
        return '/' + path;
    }
    
    function escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }
    
    // Auto-load archived members when archive section is shown
    document.addEventListener('DOMContentLoaded', () => {
        // Observe section visibility
        const observer = new IntersectionObserver((entries) => {
            entries.forEach(entry => {
                if (entry.isIntersecting && entry.target.id === 'section-archive') {
                    loadArchivedMembers();
                    observer.unobserve(entry.target);
                }
            });
        });
        
        const archiveSection = document.getElementById('section-archive');
        if (archiveSection) {
            observer.observe(archiveSection);
        }
        
        // Also load when clicking archive nav
        document.querySelectorAll('[data-section="archive"]').forEach(btn => {
            btn.addEventListener('click', () => {
                setTimeout(loadArchivedMembers, 100);
            });
        });
    });

    // ---------------------------------------------------------
    // MANAGE SHEET FUNCTIONS (Tabs, Preview, Edit)
    // ---------------------------------------------------------
    function switchManageTab(tab) {
        const previewBtn = document.getElementById('tab-preview');
        const editBtn = document.getElementById('tab-edit');
        const viewPreview = document.getElementById('view-preview');
        const editForm = document.getElementById('view-edit');

        if (!previewBtn || !editBtn || !viewPreview || !editForm) return;

        if (tab === 'preview') {
            previewBtn.classList.add('border-emerald-600', 'text-emerald-700');
            previewBtn.classList.remove('border-transparent', 'text-slate-500');
            editBtn.classList.remove('border-emerald-600', 'text-emerald-700');
            editBtn.classList.add('border-transparent', 'text-slate-500');
            
            viewPreview.classList.remove('hidden');
            viewPreview.style.display = 'block';
            editForm.classList.add('hidden');
            editForm.style.display = 'none';
        } else {
            editBtn.classList.add('border-emerald-600', 'text-emerald-700');
            editBtn.classList.remove('border-transparent', 'text-slate-500');
            previewBtn.classList.remove('border-emerald-600', 'text-emerald-700');
            previewBtn.classList.add('border-transparent', 'text-slate-500');
            
            editForm.classList.remove('hidden');
            editForm.style.display = 'block';
            viewPreview.classList.add('hidden');
            viewPreview.style.display = 'none';
        }
    }

    // In-page Image Viewer Logic
    let viewerScale = 1;
    let viewerRotation = 0;
    let viewerPx = 0, viewerPy = 0;
    let viewerIsDragging = false;
    let viewerStartX, viewerStartY;

    function openDocFullscreen(src) {
        const overlay = document.getElementById('imageViewerOverlay');
        const img = document.getElementById('viewer-img');
        if(!overlay || !img) return;

        // Reset state
        viewerScale = 1;
        viewerRotation = 0;
        viewerPx = 0;
        viewerPy = 0;
        
        // Resolve absolute path
        const fullUrl = new URL(src, window.location.href).href;
        img.src = fullUrl;
        updateViewerTransform();

        overlay.classList.remove('hidden');
        overlay.classList.add('flex');
    }

    function closeDocFullscreen() {
        const overlay = document.getElementById('imageViewerOverlay');
        if(overlay) {
            overlay.classList.add('hidden');
            overlay.classList.remove('flex');
        }
    }

    function updateViewerTransform() {
        const img = document.getElementById('viewer-img');
        if(img) {
            img.style.transform = `translate(${viewerPx}px, ${viewerPy}px) scale(${viewerScale}) rotate(${viewerRotation}deg)`;
        }
    }

    function adjustViewerZoom(delta) {
        viewerScale += delta;
        if(viewerScale < 0.1) viewerScale = 0.1;
        if(viewerScale > 5) viewerScale = 5;
        updateViewerTransform();
    }

    function resetViewerView() {
        viewerScale = 1;
        viewerRotation = 0;
        viewerPx = 0;
        viewerPy = 0;
        updateViewerTransform();
    }

    function rotateViewerImg() {
        viewerRotation += 90;
        updateViewerTransform();
    }

    // Setup viewer event listeners once
    document.addEventListener('DOMContentLoaded', () => {
        const container = document.getElementById('img-container');
        if(!container) return;

        // Wheel Zoom
        container.addEventListener('wheel', (e) => {
            e.preventDefault();
            adjustViewerZoom(e.deltaY > 0 ? -0.1 : 0.1);
        }, { passive: false });

        // Drag Panning
        container.addEventListener('mousedown', (e) => {
            viewerIsDragging = true;
            viewerStartX = e.clientX - viewerPx;
            viewerStartY = e.clientY - viewerPy;
            container.style.cursor = 'grabbing';
        });

        window.addEventListener('mousemove', (e) => {
            if(!viewerIsDragging) return;
            e.preventDefault();
            viewerPx = e.clientX - viewerStartX;
            viewerPy = e.clientY - viewerStartY;
            updateViewerTransform();
        });

        window.addEventListener('mouseup', () => {
            viewerIsDragging = false;
            if(container) container.style.cursor = 'grab';
        });
    });

    function submitEditForm(e) {
        e.preventDefault();
        const form = e.target;
        const formData = new FormData(form);
        formData.append('csrf_token', CSRF_TOKEN);
        const btn = form.querySelector('button[type="submit"]');
        
        if(btn) {
            btn.disabled = true;
            btn.textContent = 'Saving...';
        }

        fetch('/admin/info_manage_member.php', {
            method: 'POST',
            body: formData
        })
        .then(r => r.json())
        .then(data => {
            if(data.status === 'success') {
                alert('Saved successfully!');
                closeManageSheet();
                window.location.reload(); // Refresh to see changes
            } else {
                alert('Error: ' + data.message);
            }
        })
        .catch(err => {
            alert('Network error');
            console.error(err);
        })
        .finally(() => {
            if(btn) {
                btn.disabled = false;
                btn.textContent = 'Save Changes';
            }
        });
    }
</script>

<!-- Archive Verification Modal -->
<div id="archiveModal" class="fixed inset-0 z-[150] bg-slate-900/80 backdrop-blur-sm hidden items-center justify-center p-4">
    <div class="bg-white rounded-2xl shadow-2xl max-w-md w-full overflow-hidden animate-in">
        <!-- Header -->
        <div class="bg-gradient-to-r from-amber-500 to-orange-500 px-6 py-4">
            <div class="flex items-center gap-3">
                <div class="w-12 h-12 bg-white/20 rounded-xl flex items-center justify-center">
                    <i class="fa-solid fa-box-archive text-white text-xl"></i>
                </div>
                <div class="text-white">
                    <h3 class="font-bold text-lg">Archive Member</h3>
                    <p class="text-white/80 text-sm">Move to old members</p>
                </div>
            </div>
        </div>
        
        <!-- Body -->
        <div class="p-6">
            <!-- Warning -->
            <div class="bg-amber-50 border border-amber-200 rounded-xl p-4 mb-5">
                <div class="flex gap-3">
                    <i class="fa-solid fa-triangle-exclamation text-amber-500 text-lg mt-0.5"></i>
                    <div>
                        <p class="text-amber-800 font-semibold text-sm mb-1">You are about to archive:</p>
                        <p id="archiveMemberName" class="text-amber-900 font-bold text-base">Member Name</p>
                    </div>
                </div>
            </div>
            
            <!-- Reason -->
            <div class="mb-4">
                <label class="block text-sm font-semibold text-slate-700 mb-2">
                    <i class="fa-solid fa-clipboard-list mr-1 text-slate-400"></i> Reason for archiving *
                </label>
                <select id="archiveReason" onchange="checkArchiveConfirmation()" 
                        class="w-full px-4 py-3 border border-slate-200 rounded-xl text-sm focus:ring-2 focus:ring-amber-500 focus:border-amber-500">
                    <option value="">-- Select a reason --</option>
                    <option value="left_school">ከትምህርት ቤት ወጥቷል/ች (Left School)</option>
                    <option value="graduated">ተመርቋል/ች (Graduated)</option>
                    <option value="transferred">ወደ ሌላ ቦታ ተዛውሯል/ች (Transferred)</option>
                    <option value="inactive_long">ረጅም ጊዜ አልተገኘም/ች (Long Inactive)</option>
                    <option value="deceased">አርፏል/ች (Deceased)</option>
                    <option value="other">ሌላ (Other)</option>
                </select>
            </div>
            
            <!-- Notes -->
            <div class="mb-5">
                <label class="block text-sm font-semibold text-slate-700 mb-2">
                    <i class="fa-solid fa-note-sticky mr-1 text-slate-400"></i> Additional Notes (Optional)
                </label>
                <textarea id="archiveNotes" rows="2" placeholder="Any additional information..."
                          class="w-full px-4 py-3 border border-slate-200 rounded-xl text-sm focus:ring-2 focus:ring-amber-500 focus:border-amber-500 resize-none"></textarea>
            </div>
            
            <!-- Confirmation -->
            <div class="bg-red-50 border border-red-200 rounded-xl p-4 mb-5">
                <label class="block text-sm font-semibold text-red-700 mb-2">
                    <i class="fa-solid fa-keyboard mr-1"></i> Type "ARCHIVE" to confirm
                </label>
                <input type="text" id="archiveConfirmText" oninput="checkArchiveConfirmation()" 
                       placeholder="Type ARCHIVE here..."
                       class="w-full px-4 py-3 border-2 border-red-200 rounded-xl text-sm font-mono text-center uppercase tracking-widest focus:ring-2 focus:ring-red-500 focus:border-red-500">
            </div>
        </div>
        
        <!-- Footer -->
        <div class="bg-slate-50 px-6 py-4 flex gap-3">
            <button onclick="closeArchiveModal()" 
                    class="flex-1 px-4 py-3 bg-white border border-slate-200 text-slate-600 font-semibold rounded-xl hover:bg-slate-100 transition">
                <i class="fa-solid fa-times mr-2"></i> Cancel
            </button>
            <button id="confirmArchiveBtn" onclick="confirmArchive()" disabled
                    class="flex-1 px-4 py-3 bg-slate-300 text-white font-semibold rounded-xl cursor-not-allowed transition">
                <i class="fa-solid fa-box-archive mr-2"></i> Archive Member
            </button>
        </div>
    </div>
</div>

<!-- Duplicate Detection Modal -->
<div id="duplicateModal" class="fixed inset-0 z-[160] bg-slate-900/80 backdrop-blur-sm hidden items-center justify-center p-4">
    <div class="bg-white rounded-2xl shadow-2xl max-w-lg w-full max-h-[90vh] overflow-hidden animate-in">
        <!-- Header -->
        <div class="bg-gradient-to-r from-red-500 to-rose-500 px-6 py-4">
            <div class="flex items-center gap-3">
                <div class="w-12 h-12 bg-white/20 rounded-xl flex items-center justify-center">
                    <i class="fa-solid fa-user-group text-white text-xl"></i>
                </div>
                <div class="text-white">
                    <h3 class="font-bold text-lg">Duplicate Member Found!</h3>
                    <p class="text-white/80 text-sm">This member may already exist</p>
                </div>
            </div>
        </div>
        
        <!-- Body -->
        <div class="p-6 overflow-y-auto max-h-[60vh]">
            <!-- Warning -->
            <div class="bg-red-50 border border-red-200 rounded-xl p-4 mb-4">
                <div class="flex gap-3">
                    <i class="fa-solid fa-triangle-exclamation text-red-500 text-lg mt-0.5"></i>
                    <div>
                        <p class="text-red-800 font-semibold text-sm">A similar member already exists in the system!</p>
                        <p class="text-red-600 text-xs mt-1">Please review the existing member(s) below before registering.</p>
                    </div>
                </div>
            </div>
            
            <!-- Duplicate Member Cards -->
            <div id="duplicateMembersList" class="space-y-3">
                <!-- Dynamically populated -->
            </div>
        </div>
        
        <!-- Footer -->
        <div class="bg-slate-50 px-6 py-4 border-t">
            <p class="text-xs text-slate-500 mb-3 text-center">
                <i class="fa-solid fa-info-circle mr-1"></i>
                If this is a different person, click "Register Anyway"
            </p>
            <div class="flex gap-3">
                <button onclick="closeDuplicateModal()" 
                        class="flex-1 px-4 py-3 bg-white border border-slate-200 text-slate-600 font-semibold rounded-xl hover:bg-slate-100 transition">
                    <i class="fa-solid fa-times mr-2"></i> Cancel
                </button>
                <button onclick="proceedWithRegistration()" 
                        class="flex-1 px-4 py-3 bg-amber-500 hover:bg-amber-600 text-white font-semibold rounded-xl transition">
                    <i class="fa-solid fa-user-plus mr-2"></i> Register Anyway
                </button>
            </div>
        </div>
    </div>
</div>

<script>
// ---------------------------------------------------------
// DUPLICATE DETECTION SYSTEM
// ---------------------------------------------------------
let duplicateCheckPending = false;
let duplicateCheckTimer = null;
let skipDuplicateCheck = false;
let pendingFormData = null;

// Check for duplicates when name fields change
function setupDuplicateCheck() {
    const studentNameField = document.querySelector('input[name="student_name"]');
    const fatherNameField = document.querySelector('input[name="father_name"]');
    const grandfatherNameField = document.querySelector('input[name="grandfather_name"]');
    const phoneField = document.querySelector('input[name="phone_number"]');
    
    const fields = [studentNameField, fatherNameField, grandfatherNameField, phoneField];
    
    fields.forEach(field => {
        if (field) {
            field.addEventListener('blur', () => {
                clearTimeout(duplicateCheckTimer);
                duplicateCheckTimer = setTimeout(checkForDuplicates, 500);
            });
        }
    });
}

function checkForDuplicates() {
    if (skipDuplicateCheck) return;
    
    const studentName = document.querySelector('input[name="student_name"]')?.value?.trim() || '';
    const fatherName = document.querySelector('input[name="father_name"]')?.value?.trim() || '';
    const grandfatherName = document.querySelector('input[name="grandfather_name"]')?.value?.trim() || '';
    const phone = document.querySelector('input[name="phone_number"]')?.value?.trim() || '';
    
    // Need at least student and father name
    if (studentName.length < 2 || fatherName.length < 2) return;
    
    duplicateCheckPending = true;
    
    const params = new URLSearchParams({
        student_name: studentName,
        father_name: fatherName,
        grandfather_name: grandfatherName,
        phone: phone
    });
    
    fetch('/admin/api_check_duplicate.php?' + params.toString())
        .then(r => r.json())
        .then(data => {
            duplicateCheckPending = false;
            if (data.found && data.matches && data.matches.length > 0) {
                showDuplicateWarning(data.matches);
            }
        })
        .catch(() => {
            duplicateCheckPending = false;
        });
}

function showDuplicateWarning(matches) {
    const container = document.getElementById('duplicateMembersList');
    if (!container) return;
    
    container.innerHTML = matches.map(m => {
        const fullName = (m.student_name || '') + ' ' + (m.father_name || '') + ' ' + (m.grandfather_name || '');
        const isArchived = m.is_archived || m.status === 'archived';
        const statusClass = isArchived ? 'bg-amber-100 text-amber-700' : 'bg-emerald-100 text-emerald-700';
        const statusText = isArchived ? 'ARCHIVED' : 'ACTIVE';
        const photoHtml = m.student_photo_path 
            ? `<img src="${m.student_photo_path}" class="w-full h-full object-cover">`
            : `<i class="fa-solid fa-user text-slate-400 text-xl"></i>`;
        
        return `
            <div class="bg-white border-2 ${isArchived ? 'border-amber-300' : 'border-emerald-300'} rounded-xl p-4">
                <div class="flex gap-4">
                    <div class="w-16 h-20 rounded-lg bg-slate-100 overflow-hidden flex items-center justify-center flex-shrink-0">
                        ${photoHtml}
                    </div>
                    <div class="flex-1 min-w-0">
                        <div class="flex items-start justify-between gap-2 mb-2">
                            <h4 class="font-bold text-slate-800 text-sm">${escapeHtml(fullName)}</h4>
                            <span class="px-2 py-1 ${statusClass} rounded-lg text-[10px] font-bold flex-shrink-0">
                                ${statusText}
                            </span>
                        </div>
                        <div class="space-y-1 text-xs text-slate-600">
                            <p><i class="fa-solid fa-id-card w-4 text-slate-400"></i> ${m.member_code || 'No ID'}</p>
                            <p><i class="fa-solid fa-users w-4 text-slate-400"></i> ${m.current_section || m.age_group || '—'}</p>
                            <p><i class="fa-solid fa-phone w-4 text-slate-400"></i> ${m.phone_number || '—'}</p>
                            ${m.match_reasons ? `<p class="text-red-500 text-[10px] mt-1"><i class="fa-solid fa-exclamation-circle"></i> ${m.match_reasons.join(', ')}</p>` : ''}
                        </div>
                        <div class="mt-3 flex gap-2">
                            ${isArchived 
                                ? `<button onclick="restoreAndClose(${m.id}, '${fullName.replace(/'/g, "\\'")}')" 
                                        class="flex-1 px-3 py-2 bg-emerald-500 hover:bg-emerald-600 text-white rounded-lg text-xs font-bold transition">
                                    <i class="fa-solid fa-rotate-left mr-1"></i> Restore This Member
                                   </button>`
                                : `<button onclick="viewExistingMember(${m.id})" 
                                        class="flex-1 px-3 py-2 bg-blue-500 hover:bg-blue-600 text-white rounded-lg text-xs font-bold transition">
                                    <i class="fa-solid fa-eye mr-1"></i> View Member
                                   </button>`
                            }
                        </div>
                    </div>
                </div>
            </div>
        `;
    }).join('');
    
    // Show modal
    document.getElementById('duplicateModal').classList.remove('hidden');
    document.getElementById('duplicateModal').classList.add('flex');
}

function closeDuplicateModal() {
    document.getElementById('duplicateModal').classList.add('hidden');
    document.getElementById('duplicateModal').classList.remove('flex');
    pendingFormData = null;
}

function proceedWithRegistration() {
    skipDuplicateCheck = true;
    
    // Save form data BEFORE closing modal (closeDuplicateModal sets pendingFormData = null)
    const formDataToSubmit = pendingFormData;
    
    closeDuplicateModal();
    
    // Submit with the saved copy
    if (formDataToSubmit) {
        submitRegistrationForm(formDataToSubmit);
    }
    
    pendingFormData = null;
    setTimeout(() => { skipDuplicateCheck = false; }, 2000);
}

function restoreAndClose(id, name) {
    closeDuplicateModal();
    // Clear the registration form
    resetMemberForm();
    toggleMemberRegistrationForm(false);
    
    // Restore the member
    restoreMember(id, name);
}

function viewExistingMember(id) {
    closeDuplicateModal();
    // Clear and close registration form
    resetMemberForm();
    toggleMemberRegistrationForm(false);
    
    // Open manage sheet
    openManageSheet(id);
}

// Modified form submit to check duplicates first
function handleMemberFormSubmitWithCheck(event) {
    event.preventDefault();
    
    if (skipDuplicateCheck) {
        // Submit normally
        handleMemberFormSubmit(event);
        return;
    }
    
    const form = document.getElementById('memberRegistrationForm');
    if (!form) return;
    
    const studentName = form.querySelector('input[name="student_name"]')?.value?.trim() || '';
    const fatherName = form.querySelector('input[name="father_name"]')?.value?.trim() || '';
    const grandfatherName = form.querySelector('input[name="grandfather_name"]')?.value?.trim() || '';
    const phone = form.querySelector('input[name="phone_number"]')?.value?.trim() || '';
    
    // Store form data for later submission
    pendingFormData = new FormData(form);
    
    // Check for duplicates
    const params = new URLSearchParams({
        student_name: studentName,
        father_name: fatherName,
        grandfather_name: grandfatherName,
        phone: phone
    });
    
    const overlay = document.getElementById('formLoadingOverlay');
    if (overlay) overlay.classList.remove('hidden');
    
    fetch('/admin/api_check_duplicate.php?' + params.toString())
        .then(r => r.json())
        .then(data => {
            if (overlay) overlay.classList.add('hidden');
            
            if (data.found && data.matches && data.matches.length > 0) {
                // Show duplicate warning modal
                showDuplicateWarning(data.matches);
            } else {
                // No duplicates, proceed with registration
                submitRegistrationForm(pendingFormData);
                pendingFormData = null;
            }
        })
        .catch(() => {
            if (overlay) overlay.classList.add('hidden');
            // On error, proceed anyway
            submitRegistrationForm(pendingFormData);
            pendingFormData = null;
        });
}

function submitRegistrationForm(formData) {
    const overlay = document.getElementById('formLoadingOverlay');
    if (overlay) overlay.classList.remove('hidden');
    
    // CRITICAL: Add CSRF token — the form doesn't have a hidden field for it
    if (!formData.has('csrf_token')) {
        formData.append('csrf_token', CSRF_TOKEN);
    }
    
    fetch('/admin/info_register_member.php', {
        method: 'POST',
        body: formData,
        headers: { 'Accept': 'application/json' }
    })
    .then(response => {
        // ── Key fix: check for non-JSON responses before parsing ──
        const contentType = response.headers.get('content-type') || '';
        if (!contentType.includes('application/json')) {
            // Server returned HTML (likely a redirect to login page)
            if (response.redirected || response.url.includes('index.php')) {
                throw { _type: 'session_expired', message: 'Session expired. Redirecting to login...' };
            }
            throw { _type: 'server_error', message: 'Server returned an unexpected response. Please try again.' };
        }
        return response.json();
    })
    .then(data => {
        if (overlay) overlay.classList.add('hidden');
        
        if (!data || typeof data !== 'object') {
            alert('Unexpected server response. Please try again.');
            return;
        }
        
        // ── Handle session expiration ──
        if (data.status === 'session_expired' || data.action === 'reload') {
            alert(data.message || 'Your session has expired. The page will reload.');
            window.location.reload();
            return;
        }
        
        // ── Handle CSRF token expiration ──
        if (data.status === 'csrf_expired') {
            alert(data.message || 'Security token expired. The page will reload.');
            window.location.reload();
            return;
        }
        
        // ── Handle success ──
        if (data.status === 'success') {
            resetMemberForm();
            showMemberSuccessToast(data.message || 'Member registered successfully.');
            const wrapper = document.getElementById('memberRegistrationWrapper');
            if (wrapper) wrapper.scrollIntoView({ behavior: 'smooth', block: 'start' });
            return;
        }
        
        // ── Handle known errors with real messages ──
        alert(data.message || 'Registration failed. Please try again.');
    })
    .catch(err => {
        if (overlay) overlay.classList.add('hidden');
        
        // ── Structured errors from our checks above ──
        if (err && err._type === 'session_expired') {
            alert(err.message);
            window.location.reload();
            return;
        }
        if (err && err._type === 'server_error') {
            alert(err.message);
            return;
        }
        
        // ── Actual network errors ──
        console.error('Registration error:', err);
        
        // Check if it's a JSON parse error (server returned HTML)
        if (err instanceof SyntaxError && err.message.includes('Unexpected token')) {
            alert('Your session may have expired. The page will reload.');
            window.location.reload();
            return;
        }
        
        // Check if offline
        if (!navigator.onLine) {
            alert('You appear to be offline. Please check your internet connection and try again.');
            return;
        }
        
        // Generic network error
        alert('Connection error. Please check your internet and try again.\n\nIf this keeps happening, try refreshing the page first.');
    });
}

// Initialize duplicate check on page load
document.addEventListener('DOMContentLoaded', setupDuplicateCheck);

// ============================================================
// ATTENDANCE TAKER ACCOUNT MANAGEMENT
// ============================================================
function openAttakerModal() {
    document.getElementById('attakerModal').classList.remove('hidden');
    document.getElementById('attakerModal').classList.add('flex');
    document.getElementById('attakerForm').reset();
}

function closeAttakerModal() {
    document.getElementById('attakerModal').classList.add('hidden');
    document.getElementById('attakerModal').classList.remove('flex');
}

// Auto-fill name from selected member
document.getElementById('attakerMemberId')?.addEventListener('change', function() {
    const selected = this.options[this.selectedIndex];
    if (selected && selected.value) {
        const name = selected.textContent.split('(')[0].trim();
        document.getElementById('attakerFullName').value = name;
    }
});

// Handle form submission
document.getElementById('attakerForm')?.addEventListener('submit', function(e) {
    e.preventDefault();
    
    const formData = new FormData(this);
    formData.append('role', 'attendance_taker');
    formData.append('csrf_token', '<?= $csrfToken ?? '' ?>');
    
    fetch('<?= $ajaxPrefix ?>backend/user-save.php', {
        method: 'POST',
        body: formData
    })
    .then(r => r.json())
    .then(data => {
        if (data.status === 'success') {
            closeAttakerModal();
            // Show success toast
            const toast = document.getElementById('memberSuccessToast');
            const toastMsg = toast?.querySelector('span') || toast;
            if (toastMsg) toastMsg.textContent = 'Attendance taker account created!';
            toast?.classList.remove('hidden');
            setTimeout(() => toast?.classList.add('hidden'), 3000);
            // Reload page to show new user
            setTimeout(() => location.reload(), 1500);
        } else {
            alert(data.message || 'Error creating account');
        }
    })
    .catch(err => {
        console.error(err);
        alert('Network error. Please try again.');
    });
});

function toggleAttakerStatus(userId, currentStatus) {
    if (!confirm(currentStatus ? 'Deactivate this account?' : 'Activate this account?')) return;
    
    const formData = new FormData();
    formData.append('user_id', userId);
    formData.append('action', 'toggle_status');
    formData.append('csrf_token', '<?= $csrfToken ?? '' ?>');
    
    fetch('<?= $ajaxPrefix ?>backend/user-toggle.php', {
        method: 'POST',
        body: formData
    })
    .then(r => r.json())
    .then(data => {
        if (data.status === 'success' || data.success) {
            location.reload();
        } else {
            alert(data.message || 'Error toggling status');
        }
    })
    .catch(err => {
        console.error(err);
        alert('Network error');
    });
}

// ============================================================
// ATTENDANCE & STATUS SECTION FUNCTIONS
// ============================================================
const _aa = '/admin/api_attendance_info.php?action=';
const hasChartJs = typeof Chart !== 'undefined';
let _attCharts = {};

function showAttTab(btn, id) {
    document.querySelectorAll('.att-pane').forEach(p => p.style.display = 'none');
    document.querySelectorAll('.atab').forEach(b => b.classList.remove('atab-on'));
    document.getElementById(id).style.display = 'block';
    btn.classList.add('atab-on');
    if (id === 'attOverview') loadAttOverview();
    if (id === 'attDaily' && !document.getElementById('attDailyDate').value) {
        document.getElementById('attDailyDate').value = new Date().toISOString().split('T')[0];
    }
}

function attToast(msg, ok) {
    const t = document.createElement('div');
    t.style.cssText = 'position:fixed;bottom:24px;right:24px;padding:12px 20px;border-radius:12px;color:#fff;font-size:13px;z-index:200;background:' + (ok !== false ? '#ea580c' : '#dc2626');
    t.innerHTML = msg;
    document.body.appendChild(t);
    setTimeout(() => { t.style.opacity = '0'; setTimeout(() => t.remove(), 300); }, 3000);
}

function attBadge(status) {
    const m = {present:'att-present',absent:'att-absent',late:'att-late',excused:'att-excused'};
    return '<span class="att-badge ' + (m[status]||'att-excused') + '">' + (status||'—') + '</span>';
}

function statusBadge(s) {
    const m = {active:'background:#d1fae5;color:#065f46',warning:'background:#fef3c7;color:#92400e',inactive:'background:#fee2e2;color:#991b1b'};
    return '<span style="display:inline-flex;align-items:center;padding:2px 8px;border-radius:20px;font-size:9px;font-weight:600;' + (m[s]||'') + '">' + (s||'—') + '</span>';
}

// --- Overview ---
let _attOvLoaded = false;
function loadAttOverview() {
    if (_attOvLoaded) return;
    fetch(_aa + 'overview', {credentials:'same-origin'}).then(r => r.json()).then(d => {
        if (d.status !== 'success') { document.getElementById('attOvLoad').innerHTML = '<p class="text-red-400 text-xs">' + escapeHtml(d.message||'Error') + '</p>'; return; }
        _attOvLoaded = true;
        document.getElementById('attOvLoad').style.display = 'none';
        document.getElementById('attOvContent').style.display = 'block';
        const data = d.data, t = data.today, ms = data.member_status;
        const todayRate = data.total_active > 0 ? Math.round((parseInt(t.present_cnt)||0) / data.total_active * 100) : 0;
        document.getElementById('attKpis').innerHTML = [
            {l:"Today's Present",v:t.present_cnt||0,c:'#16a34a',b:'#d1fae5'},
            {l:"Today's Absent",v:t.absent_cnt||0,c:'#dc2626',b:'#fee2e2'},
            {l:"Today's Late",v:t.late_cnt||0,c:'#d97706',b:'#fef3c7'},
            {l:'Today Rate',v:todayRate+'%',c:'#2563eb',b:'#dbeafe'},
            {l:'Never Attended',v:data.never_attended||0,c:'#7c3aed',b:'#ede9fe'},
            {l:'At Warning',v:ms.warning_cnt||0,c:'#ea580c',b:'#ffedd5'}
        ].map(k => '<div style="background:'+k.b+';padding:12px;border-radius:14px;text-align:center"><div style="font-size:20px;font-weight:700;color:'+k.c+'">'+k.v+'</div><div style="font-size:9px;font-weight:500;color:'+k.c+'80">'+k.l+'</div></div>').join('');

        // Week chart
        if (hasChartJs && data.week_trend.length > 0) {
            const wt = data.week_trend;
            if (_attCharts.week) _attCharts.week.destroy();
            _attCharts.week = new Chart(document.getElementById('attWeekChart').getContext('2d'), {
                type:'bar', data:{labels:wt.map(w=>w.day.slice(5)), datasets:[
                    {label:'Present',data:wt.map(w=>w.present_cnt),backgroundColor:'#16a34a',borderRadius:4},
                    {label:'Absent',data:wt.map(w=>w.absent_cnt),backgroundColor:'#ef4444',borderRadius:4}
                ]}, options:{responsive:true,maintainAspectRatio:false,plugins:{legend:{position:'top',labels:{boxWidth:10,font:{size:10}}}},scales:{y:{beginAtZero:true,stacked:true},x:{stacked:true,ticks:{font:{size:9}}}}}
            });
        }
        // Monthly chart
        if (hasChartJs && data.monthly_weeks.length > 0) {
            const mw = data.monthly_weeks;
            if (_attCharts.month) _attCharts.month.destroy();
            _attCharts.month = new Chart(document.getElementById('attMonthChart').getContext('2d'), {
                type:'line', data:{labels:mw.map(w=>'Wk '+w.week_start.slice(5)), datasets:[
                    {label:'Present',data:mw.map(w=>w.present_cnt),borderColor:'#16a34a',backgroundColor:'rgba(22,163,74,.1)',fill:true,tension:.4,pointRadius:4},
                    {label:'Absent',data:mw.map(w=>w.absent_cnt),borderColor:'#ef4444',backgroundColor:'transparent',tension:.4,borderDash:[4,4],pointRadius:3}
                ]}, options:{responsive:true,maintainAspectRatio:false,plugins:{legend:{position:'top',labels:{boxWidth:10,font:{size:10}}}},scales:{y:{beginAtZero:true}}}
            });
        }
        // Top absentees
        const abs = data.top_absentees;
        document.getElementById('attAbsentees').innerHTML = abs.length === 0 ? '<p class="text-xs text-emerald-500"><i class="fa-solid fa-check-circle mr-1"></i>No frequent absentees found!</p>' :
            '<div style="max-height:250px;overflow:auto"><table class="w-full text-xs"><thead class="sticky top-0 bg-slate-50"><tr><th class="px-2 py-1.5 text-left font-semibold text-slate-500">Name</th><th class="px-2 py-1.5 text-left font-semibold text-slate-500">Code</th><th class="px-2 py-1.5 text-center font-semibold text-slate-500">Absent</th><th class="px-2 py-1.5 text-center font-semibold text-slate-500">Rate</th><th class="px-2 py-1.5 text-center font-semibold text-slate-500">Status</th></tr></thead><tbody>' +
            abs.map(a => '<tr class="border-t border-slate-100 hover:bg-orange-50 cursor-pointer" onclick="viewMemberAtt('+a.member_id+')"><td class="px-2 py-1.5 font-medium">'+escapeHtml(a.student_name+' '+a.father_name)+'</td><td class="px-2 py-1.5"><code class="text-[10px] bg-slate-100 px-1 rounded">'+(a.member_code||'—')+'</code></td><td class="px-2 py-1.5 text-center text-red-600 font-bold">'+a.absent_days+'</td><td class="px-2 py-1.5 text-center"><span style="color:'+(a.rate<50?'#dc2626':a.rate<75?'#d97706':'#16a34a')+'">'+a.rate+'%</span></td><td class="px-2 py-1.5 text-center">'+statusBadge(a.member_status)+'</td></tr>').join('') +
            '</tbody></table></div>';
    }).catch(err => { document.getElementById('attOvLoad').innerHTML = '<p class="text-xs text-red-400">Error: '+escapeHtml(err.message)+'</p>'; });
}

// --- Daily Report ---
function loadDailyReport() {
    const date = document.getElementById('attDailyDate').value;
    if (!date) return;
    fetch(_aa + 'daily_report&date=' + date, {credentials:'same-origin'}).then(r => r.json()).then(d => {
        if (d.status !== 'success') { attToast(d.message||'Error', false); return; }
        const s = d.summary, recs = d.records;
        if (recs.length === 0) {
            document.getElementById('attDailySummary').style.display = 'none';
            document.getElementById('attDailyTable').style.display = 'none';
            document.getElementById('attDailyEmpty').style.display = 'block';
            return;
        }
        document.getElementById('attDailyEmpty').style.display = 'none';
        document.getElementById('attDailySummary').style.display = 'grid';
        document.getElementById('attDailySummary').innerHTML = [
            {l:'Total',v:s.total,c:'#1e293b',b:'#f1f5f9'},{l:'Present',v:s.present,c:'#16a34a',b:'#d1fae5'},
            {l:'Absent',v:s.absent,c:'#dc2626',b:'#fee2e2'},{l:'Late',v:s.late,c:'#d97706',b:'#fef3c7'}
        ].map(k => '<div style="background:'+k.b+';padding:10px;border-radius:12px;text-align:center"><div style="font-size:18px;font-weight:700;color:'+k.c+'">'+k.v+'</div><div style="font-size:9px;color:'+k.c+'80">'+k.l+'</div></div>').join('');
        document.getElementById('attDailyTable').style.display = 'block';
        document.getElementById('attDailyBody').innerHTML = recs.map((r,i) =>
            '<tr class="border-t border-slate-100 hover:bg-slate-50"><td class="px-3 py-2">'+(i+1)+'</td><td class="px-3 py-2 font-medium">'+escapeHtml((r.student_name||'')+' '+(r.father_name||''))+'</td><td class="px-3 py-2"><code class="text-[10px] bg-slate-100 px-1 rounded">'+(r.member_code||'—')+'</code></td><td class="px-3 py-2">'+(r.gender==='male'?'M':'F')+'</td><td class="px-3 py-2">'+attBadge(r.status)+'</td><td class="px-3 py-2 text-slate-400">'+(r.notes||'—')+'</td></tr>'
        ).join('');
    });
}

// --- Member Lookup ---
function searchMemberAtt() {
    const q = document.getElementById('attMemSearch').value.trim();
    if (!q) return;
    fetch(_aa + 'search_members&q=' + encodeURIComponent(q), {credentials:'same-origin'}).then(r => r.json()).then(d => {
        if (d.members.length === 0) { document.getElementById('attMemResults').style.display = 'block'; document.getElementById('attMemResults').innerHTML = '<div class="panel p-4 text-center text-slate-400 text-xs">No members found</div>'; return; }
        document.getElementById('attMemResults').style.display = 'block';
        document.getElementById('attMemDetail').style.display = 'none';
        document.getElementById('attMemResults').innerHTML = '<div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-2">' +
            d.members.map(m => '<div class="panel p-3 cursor-pointer hover:shadow-md transition" onclick="viewMemberAtt('+m.id+')"><div class="flex items-center gap-2"><div class="w-8 h-8 rounded-full flex items-center justify-center text-xs font-bold '+(m.gender==='male'?'bg-blue-100 text-blue-600':'bg-pink-100 text-pink-600')+'">'+escapeHtml((m.student_name||'?').charAt(0))+'</div><div><div class="text-xs font-semibold text-slate-700">'+escapeHtml(m.student_name+' '+m.father_name)+'</div><div class="text-[10px] text-slate-400">'+(m.member_code||'—')+' · '+statusBadge(m.status)+'</div></div></div></div>').join('') +
            '</div>';
    });
}

function viewMemberAtt(memberId) {
    fetch(_aa + 'member_attendance&member_id=' + memberId, {credentials:'same-origin'}).then(r => r.json()).then(d => {
        if (d.status !== 'success') { attToast(d.message, false); return; }
        const m = d.member, st = d.stats, recs = d.records;
        // Switch to member tab if not there
        document.querySelectorAll('.att-pane').forEach(p => p.style.display = 'none');
        document.getElementById('attMember').style.display = 'block';
        document.querySelectorAll('.atab').forEach(b => b.classList.remove('atab-on'));
        document.querySelectorAll('.atab')[2].classList.add('atab-on');
        document.getElementById('attMemResults').style.display = 'none';
        document.getElementById('attMemDetail').style.display = 'block';
        document.getElementById('attMemDetail').innerHTML =
            '<div class="panel p-4 mb-3"><div class="flex items-center gap-3 mb-3"><div class="w-12 h-12 rounded-full flex items-center justify-center text-lg font-bold '+(m.gender==='male'?'bg-blue-100 text-blue-600':'bg-pink-100 text-pink-600')+'">'+escapeHtml((m.student_name||'?').charAt(0))+'</div><div><h4 class="font-bold text-slate-800">'+escapeHtml(m.student_name+' '+m.father_name+(m.grandfather_name?' '+m.grandfather_name:''))+'</h4><div class="text-xs text-slate-400">'+(m.member_code||'—')+' · '+(m.age_group||'')+' · '+(m.phone_number||'No phone')+' · '+statusBadge(m.status)+'</div></div></div>' +
            '<div class="grid grid-cols-2 sm:grid-cols-5 gap-2 mb-3">' +
            '<div style="background:#f0fdf4;padding:8px;border-radius:10px;text-align:center"><div style="font-size:16px;font-weight:700;color:#16a34a">'+st.present+'</div><div style="font-size:9px;color:#16a34a">Present</div></div>' +
            '<div style="background:#fee2e2;padding:8px;border-radius:10px;text-align:center"><div style="font-size:16px;font-weight:700;color:#dc2626">'+st.absent+'</div><div style="font-size:9px;color:#dc2626">Absent</div></div>' +
            '<div style="background:#fef3c7;padding:8px;border-radius:10px;text-align:center"><div style="font-size:16px;font-weight:700;color:#d97706">'+st.late+'</div><div style="font-size:9px;color:#d97706">Late</div></div>' +
            '<div style="background:#f1f5f9;padding:8px;border-radius:10px;text-align:center"><div style="font-size:16px;font-weight:700;color:#1e293b">'+st.total+'</div><div style="font-size:9px;color:#64748b">Total Days</div></div>' +
            '<div style="background:'+(st.rate>=75?'#d1fae5':st.rate>=50?'#fef3c7':'#fee2e2')+';padding:8px;border-radius:10px;text-align:center"><div style="font-size:16px;font-weight:700;color:'+(st.rate>=75?'#065f46':st.rate>=50?'#92400e':'#991b1b')+'">'+st.rate+'%</div><div style="font-size:9px">Rate</div></div></div>' +
            '<button onclick="document.getElementById(\'attMemDetail\').style.display=\'none\';document.getElementById(\'attMemResults\').style.display=\'block\'" class="text-xs text-orange-600 mb-3 hover:underline"><i class="fa-solid fa-arrow-left mr-1"></i>Back to results</button></div>' +
            (recs.length === 0 ? '<div class="panel p-4 text-center text-slate-400 text-xs">No attendance records in the last 90 days</div>' :
            '<div class="panel" style="max-height:350px;overflow:auto"><table class="w-full text-xs"><thead class="sticky top-0 bg-slate-50"><tr><th class="px-3 py-2 text-left font-semibold text-slate-500">Date</th><th class="px-3 py-2 text-left font-semibold text-slate-500">Status</th><th class="px-3 py-2 text-left font-semibold text-slate-500">Check-in</th><th class="px-3 py-2 text-left font-semibold text-slate-500">Notes</th></tr></thead><tbody>' +
            recs.map(r => '<tr class="border-t border-slate-100"><td class="px-3 py-2">'+r.attendance_date+'</td><td class="px-3 py-2">'+attBadge(r.status)+'</td><td class="px-3 py-2 text-slate-400">'+(r.check_in_time||'—')+'</td><td class="px-3 py-2 text-slate-400">'+(r.notes||'—')+'</td></tr>').join('') +
            '</tbody></table></div>');
    });
}

// --- At-Risk ---
function loadAtRisk() {
    const days = document.getElementById('attRiskDays').value;
    const thresh = document.getElementById('attRiskThresh').value;
    fetch(_aa + 'at_risk_members&days='+days+'&threshold='+thresh, {credentials:'same-origin'}).then(r => r.json()).then(d => {
        if (d.status !== 'success') return;
        const ms = d.members;
        document.getElementById('attRiskCount').innerHTML = '<span class="font-semibold text-red-600">'+ms.length+'</span> members below '+thresh+'% attendance in last '+days+' days';
        document.getElementById('attRiskList').innerHTML = ms.length === 0 ? '<div class="text-center py-8 text-emerald-500 text-xs"><i class="fa-solid fa-check-circle text-2xl mb-2"></i><p>All members have good attendance!</p></div>' :
            '<table class="w-full text-xs"><thead class="sticky top-0 bg-slate-50"><tr><th class="px-2 py-1.5 text-left">Name</th><th class="px-2 py-1.5">Code</th><th class="px-2 py-1.5">Present</th><th class="px-2 py-1.5">Absent</th><th class="px-2 py-1.5">Rate</th><th class="px-2 py-1.5">Status</th><th class="px-2 py-1.5">Action</th></tr></thead><tbody>' +
            ms.map(m => '<tr class="border-t border-slate-100 hover:bg-red-50"><td class="px-2 py-1.5 font-medium">'+escapeHtml(m.student_name+' '+m.father_name)+'</td><td class="px-2 py-1.5"><code class="text-[10px] bg-slate-100 px-1 rounded">'+(m.member_code||'—')+'</code></td><td class="px-2 py-1.5 text-center text-emerald-600">'+(m.present_days||0)+'</td><td class="px-2 py-1.5 text-center text-red-600 font-bold">'+(m.absent_days||0)+'</td><td class="px-2 py-1.5 text-center" style="color:'+(m.rate<50?'#dc2626':'#d97706')+'">'+(m.rate||0)+'%</td><td class="px-2 py-1.5 text-center">'+statusBadge(m.status)+'</td><td class="px-2 py-1.5 text-center"><button onclick="quickStatusChange('+m.id+',\''+escapeHtml(m.student_name)+'\',\''+m.status+'\')" class="text-[10px] px-2 py-1 bg-amber-100 text-amber-700 rounded-lg hover:bg-amber-200"><i class="fa-solid fa-pen"></i></button></td></tr>').join('') +
            '</tbody></table>';
    });
}

// --- Status Management ---
function searchForStatus() {
    const q = document.getElementById('attStatusSearch').value.trim();
    if (!q) return;
    fetch(_aa + 'search_members&q=' + encodeURIComponent(q), {credentials:'same-origin'}).then(r => r.json()).then(d => {
        document.getElementById('attStatusResults').innerHTML = d.members.length === 0 ? '<p class="text-xs text-slate-400 text-center py-4">No members found</p>' :
            '<div class="space-y-2">' + d.members.map(m =>
                '<div class="flex items-center justify-between p-3 rounded-xl bg-slate-50 hover:bg-slate-100 transition">' +
                '<div class="flex items-center gap-2"><div class="w-8 h-8 rounded-full flex items-center justify-center text-xs font-bold '+(m.gender==='male'?'bg-blue-100 text-blue-600':'bg-pink-100 text-pink-600')+'">'+escapeHtml((m.student_name||'?').charAt(0))+'</div>' +
                '<div><div class="text-xs font-semibold text-slate-700">'+escapeHtml(m.student_name+' '+m.father_name)+'</div><div class="text-[10px] text-slate-400">'+(m.member_code||'—')+' · '+(m.age_group||'')+'</div></div></div>' +
                '<div class="flex items-center gap-2">'+statusBadge(m.status)+
                '<button onclick="quickStatusChange('+m.id+',\''+escapeHtml(m.student_name+' '+m.father_name).replace(/'/g,"\\'")+'\',\''+m.status+'\')" class="px-3 py-1.5 bg-violet-100 text-violet-700 rounded-lg text-[10px] font-semibold hover:bg-violet-200"><i class="fa-solid fa-pen mr-1"></i>Change</button></div></div>'
            ).join('') + '</div>';
    });
}

function quickStatusChange(memberId, name, currentStatus) {
    const opts = ['active','warning','inactive'].filter(s => s !== currentStatus);
    const newStatus = prompt('Change status for ' + name + '\\nCurrent: ' + currentStatus + '\\n\\nType new status: ' + opts.join(', '));
    if (!newStatus || !['active','warning','inactive'].includes(newStatus)) { if (newStatus !== null) attToast('Invalid status', false); return; }
    const reason = prompt('Reason for change (optional):') || '';
    fetch('/admin/api_attendance_info.php?action=update_status', {
        method:'POST', credentials:'same-origin', headers:{'Content-Type':'application/json'},
        body: JSON.stringify({member_id: memberId, new_status: newStatus, reason: reason})
    }).then(r => r.json()).then(d => {
        attToast(d.message, d.status === 'success');
        if (d.status === 'success') { searchForStatus(); _attOvLoaded = false; }
    }).catch(() => attToast('Network error', false));
}

// Auto-load overview when attendance section opens
(function() {
    var ab = document.querySelector('[data-section="attendance"]');
    if (ab) ab.addEventListener('click', function() { setTimeout(loadAttOverview, 200); });
})();

// ============================================================
// SETTINGS SECTION FUNCTIONS
// ============================================================
function showSettingsTab(btn, id) {
    document.querySelectorAll('.settings-pane').forEach(p => p.style.display = 'none');
    document.querySelectorAll('.stab').forEach(b => b.classList.remove('stab-on'));
    document.getElementById(id).style.display = 'block';
    btn.classList.add('stab-on');
    if (id === 'stProfile' && !document.getElementById('profName').value) loadProfile();
    if (id === 'stDept' && !document.getElementById('deptNameEn').value) loadDeptSettings();
    if (id === 'stPrefs' && !document.getElementById('codeDigits')._loaded) loadPreferences();
    if (id === 'stSystem') loadSystemInfo();
}

function settingsToast(msg, ok) {
    const t = document.createElement('div');
    t.style.cssText = 'position:fixed;bottom:24px;right:24px;padding:12px 20px;border-radius:12px;color:#fff;font-size:13px;z-index:200;background:' + (ok !== false ? '#16a34a' : '#dc2626');
    t.innerHTML = '<i class="fa-solid fa-' + (ok !== false ? 'check-circle' : 'exclamation-circle') + ' mr-2"></i>' + msg;
    document.body.appendChild(t);
    setTimeout(() => { t.style.opacity = '0'; setTimeout(() => t.remove(), 300); }, 3000);
}

function sApiPost(action, data) {
    return fetch('/admin/api_settings.php?action=' + action, {
        method: 'POST', credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(data)
    }).then(r => r.json());
}
function sApiGet(action) {
    return fetch('/admin/api_settings.php?action=' + action, { credentials: 'same-origin' }).then(r => r.json());
}

// --- Profile ---
function loadProfile() {
    sApiGet('profile_get').then(d => {
        if (d.status !== 'success') return;
        const u = d.user;
        document.getElementById('profUsername').value = u.username || '';
        document.getElementById('profName').value = u.full_name || '';
        document.getElementById('profEmail').value = u.email || '';
        document.getElementById('spEmail').textContent = u.email || '—';
        document.getElementById('spCreated').textContent = u.created_at ? (typeof WBWSCalendar!=='undefined'?WBWSCalendar.formatDate(u.created_at,'medium'):new Date(u.created_at).toLocaleDateString()) : '—';
        document.getElementById('spLastLogin').textContent = u.last_login ? (typeof WBWSCalendar!=='undefined'?WBWSCalendar.formatDate(u.last_login,'medium'):new Date(u.last_login).toLocaleDateString()) : 'Never';
        document.getElementById('spLogins').textContent = d.login_count || '0';
    });
}

function saveProfile() {
    const name = document.getElementById('profName').value.trim();
    const email = document.getElementById('profEmail').value.trim();
    if (!name) { settingsToast('Name is required', false); return; }
    sApiPost('profile_update', { full_name: name, email: email }).then(d => {
        settingsToast(d.message, d.status === 'success');
        if (d.status === 'success') {
            document.getElementById('spName').textContent = name;
            document.getElementById('spEmail').textContent = email || '—';
            document.getElementById('spAvatar').textContent = name.charAt(0).toUpperCase();
        }
    }).catch(() => settingsToast('Network error', false));
}

function changePassword() {
    const cur = document.getElementById('pwdCurrent').value;
    const nw = document.getElementById('pwdNew').value;
    const cf = document.getElementById('pwdConfirm').value;
    if (!cur || !nw || !cf) { settingsToast('All password fields required', false); return; }
    if (nw !== cf) { settingsToast('New passwords do not match', false); return; }
    if (nw.length < 6) { settingsToast('Min 6 characters required', false); return; }
    sApiPost('password_change', { current_password: cur, new_password: nw, confirm_password: cf }).then(d => {
        settingsToast(d.message, d.status === 'success');
        if (d.status === 'success') { document.getElementById('pwdCurrent').value = ''; document.getElementById('pwdNew').value = ''; document.getElementById('pwdConfirm').value = ''; }
    }).catch(() => settingsToast('Network error', false));
}

// --- Department ---
function loadDeptSettings() {
    sApiGet('dept_get').then(d => {
        if (d.status !== 'success') return;
        const s = d.settings;
        document.getElementById('deptNameEn').value = s.dept_name_en || '';
        document.getElementById('deptNameAm').value = s.dept_name_am || '';
        document.getElementById('churchNameEn').value = s.church_name_en || '';
        document.getElementById('churchNameAm').value = s.church_name_am || '';
        document.getElementById('deptDesc').value = s.dept_description || '';
    });
}

function saveDeptSettings() {
    sApiPost('dept_save', {
        dept_name_en: document.getElementById('deptNameEn').value, dept_name_am: document.getElementById('deptNameAm').value,
        church_name_en: document.getElementById('churchNameEn').value, church_name_am: document.getElementById('churchNameAm').value,
        dept_description: document.getElementById('deptDesc').value
    }).then(d => settingsToast(d.message, d.status === 'success')).catch(() => settingsToast('Network error', false));
}

// --- Preferences ---
function loadPreferences() {
    document.getElementById('codeDigits')._loaded = true;
    sApiGet('dept_get').then(d => {
        if (d.status !== 'success') return;
        const s = d.settings;
        document.getElementById('codePrefix').value = s.member_code_prefix || '';
        document.getElementById('codeDigits').value = s.member_code_digits || '4';
        document.getElementById('autoGenCode').checked = s.auto_generate_code === '1';
        document.getElementById('defAgeGroup').value = s.default_age_group || '';
        document.getElementById('defMemType').value = s.default_member_type || 'regular';
        document.getElementById('defRegType').value = s.default_registration_type || 'direct';
        document.getElementById('phoneRequired').checked = s.phone_required === '1';
        document.getElementById('idAutoGen').checked = s.id_card_auto_generate === '1';
        document.getElementById('guardianAge').value = s.guardian_required_under || '14';
        updateCodePreview();
    });
}

function updateCodePreview() {
    const prefix = document.getElementById('codePrefix') ? document.getElementById('codePrefix').value : '';
    const digits = parseInt(document.getElementById('codeDigits').value || '4');
    const preview = document.getElementById('codePreview');
    if (preview) preview.textContent = prefix + '1'.padStart(digits, '0');
}

function savePreferences() {
    sApiPost('dept_save', {
        member_code_prefix: document.getElementById('codePrefix').value, member_code_digits: document.getElementById('codeDigits').value,
        auto_generate_code: document.getElementById('autoGenCode').checked ? '1' : '0', default_age_group: document.getElementById('defAgeGroup').value,
        default_member_type: document.getElementById('defMemType').value, default_registration_type: document.getElementById('defRegType').value,
        phone_required: document.getElementById('phoneRequired').checked ? '1' : '0', id_card_auto_generate: document.getElementById('idAutoGen').checked ? '1' : '0',
        guardian_required_under: document.getElementById('guardianAge').value
    }).then(d => settingsToast(d.message, d.status === 'success')).catch(() => settingsToast('Network error', false));
}

// --- System Info ---
function loadSystemInfo() {
    sApiGet('system_info').then(d => {
        if (d.status !== 'success') return;
        const i = d.info, m = i.members, u = i.users, db = i.database, c = i.cache;
        document.getElementById('sysStats').innerHTML =
            '<div class="sys-row"><span class="sys-label">Total Members</span><span class="sys-val">' + (m.total || 0) + '</span></div>' +
            '<div class="sys-row"><span class="sys-label">Active Members</span><span class="sys-val" style="color:#16a34a">' + (m.active || 0) + '</span></div>' +
            '<div class="sys-row"><span class="sys-label">Archived</span><span class="sys-val" style="color:#94a3b8">' + (m.archived || 0) + '</span></div>' +
            '<div class="sys-row"><span class="sys-label">User Accounts</span><span class="sys-val">' + (u.total || 0) + ' (' + (u.active || 0) + ' active)</span></div>' +
            '<div class="sys-row"><span class="sys-label">Database Size</span><span class="sys-val">' + (db.size_mb || '?') + ' MB</span></div>' +
            '<div class="sys-row"><span class="sys-label">Tables</span><span class="sys-val">' + (i.tables || 0) + '</span></div>' +
            '<div class="sys-row"><span class="sys-label">Total DB Rows</span><span class="sys-val">' + Number(db.total_rows || 0).toLocaleString() + '</span></div>' +
            '<div class="sys-row"><span class="sys-label">Photos</span><span class="sys-val">' + (i.photos || 0) + '</span></div>' +
            '<div class="sys-row"><span class="sys-label">Cache</span><span class="sys-val">' + (c.files || 0) + ' files (' + (c.size_kb || 0) + ' KB)</span></div>' +
            '<div class="sys-row"><span class="sys-label">PHP</span><span class="sys-val">' + (i.php_version || '?') + '</span></div>' +
            '<div class="sys-row"><span class="sys-label">Server</span><span class="sys-val" style="font-size:10px">' + escapeHtml(i.server || '?') + '</span></div>';
        const act = i.recent_activity || [];
        document.getElementById('sysActivity').innerHTML = act.length === 0 ? '<p class="text-xs text-slate-400">No recent activity</p>' :
            '<div class="space-y-2">' + act.map(a =>
                '<div class="flex items-center gap-3 p-2 rounded-lg bg-slate-50">' +
                '<div class="w-7 h-7 bg-blue-100 rounded-full flex items-center justify-center"><i class="fa-solid fa-clock-rotate-left text-blue-500 text-[10px]"></i></div>' +
                '<div class="flex-1"><span class="text-xs font-medium text-slate-700">' + escapeHtml(a.username || '') + '</span> <span class="text-[10px] text-slate-400">' + escapeHtml(a.action || '') + '</span></div>' +
                '<span class="text-[10px] text-slate-400">' + (a.created_at ? new Date(a.created_at).toLocaleString() : '') + '</span></div>'
            ).join('') + '</div>';
    }).catch(() => { document.getElementById('sysStats').innerHTML = '<p class="text-xs text-red-400">Failed to load</p>'; });
}

function clearCache() {
    if (!confirm('Clear all cache files?')) return;
    sApiPost('clear_cache', {}).then(d => { settingsToast(d.message, d.status === 'success'); if (d.status === 'success') loadSystemInfo(); }).catch(() => settingsToast('Error', false));
}

// Auto-load profile when settings section first opens
(function() {
    var sb = document.querySelector('[data-section="settings"]');
    if (sb) sb.addEventListener('click', function() { setTimeout(function() { if (document.getElementById('profName') && !document.getElementById('profName').value) loadProfile(); }, 200); });
    if (document.getElementById('codePrefix')) document.getElementById('codePrefix').addEventListener('input', updateCodePreview);
    if (document.getElementById('codeDigits')) document.getElementById('codeDigits').addEventListener('change', updateCodePreview);
})();
</script>

<style>
@keyframes animate-in {
    from { opacity: 0; transform: scale(0.95) translateY(-10px); }
    to { opacity: 1; transform: scale(1) translateY(0); }
}
.animate-in { animation: animate-in 0.2s ease-out; }
</style>

<!-- Image Viewer Overlay -->
<div id="imageViewerOverlay" class="fixed inset-0 z-[100] bg-slate-900/95 hidden items-center justify-center backdrop-blur-sm">
    <div id="img-container" class="w-full h-full flex items-center justify-center cursor-grab overflow-hidden relative">
        <img id="viewer-img" src="" class="max-w-[90%] max-h-[90%] shadow-2xl transition-transform duration-100 ease-out" draggable="false">
    </div>

    <div class="fixed bottom-8 left-1/2 -translate-x-1/2 bg-slate-800/90 backdrop-blur border border-white/10 rounded-full px-4 py-2 flex items-center gap-4 shadow-xl z-[101]">
        <button onclick="adjustViewerZoom(-0.2)" class="w-10 h-10 rounded-full bg-white/5 hover:bg-white/20 text-slate-200 flex items-center justify-center transition" title="Zoom Out">
            <i class="fa-solid fa-minus"></i>
        </button>
        <button onclick="resetViewerView()" class="w-10 h-10 rounded-full bg-white/5 hover:bg-white/20 text-slate-200 flex items-center justify-center transition" title="Reset">
            <i class="fa-solid fa-compress"></i>
        </button>
        <button onclick="adjustViewerZoom(0.2)" class="w-10 h-10 rounded-full bg-white/5 hover:bg-white/20 text-slate-200 flex items-center justify-center transition" title="Zoom In">
            <i class="fa-solid fa-plus"></i>
        </button>
        <div class="w-px h-6 bg-slate-600"></div>
        <button onclick="rotateViewerImg()" class="w-10 h-10 rounded-full bg-white/5 hover:bg-white/20 text-slate-200 flex items-center justify-center transition" title="Rotate">
            <i class="fa-solid fa-rotate-right"></i>
        </button>
        <button onclick="closeDocFullscreen()" class="w-10 h-10 rounded-full bg-red-500/20 hover:bg-red-500 text-red-200 hover:text-white flex items-center justify-center transition" title="Close">
            <i class="fa-solid fa-xmark"></i>
        </button>
    </div>
</div>
</body>
</html>