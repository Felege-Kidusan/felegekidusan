<?php
/**
 * Groups/Associations Management - School Sunday School
 * Comprehensive group registration, leadership, and membership management
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../backend/ethiopian_date.php';

$fullName = $_SESSION['admin_full_name'] ?? $_SESSION['admin_username'] ?? 'Info Dept';
$today = new DateTime('now', new DateTimeZone('Africa/Addis_Ababa'));
$todayFormatted = ethio_date_format($today, 'F j, Y');

// Get initial stats
$stats = ['total_groups' => 0, 'under_ss' => 0, 'parish' => 0, 'total_leaders' => 0, 'total_members' => 0];
$groups = [];

if (isset($conn)) {
    try {
    $r = $conn->query("SELECT COUNT(*) as cnt FROM wbws_groups");
    if ($r) $stats['total_groups'] = (int)$r->fetch_assoc()['cnt'];
    
    $r = $conn->query("SELECT COUNT(*) as cnt FROM wbws_groups WHERE is_under_sunday_school = 1");
    if ($r) $stats['under_ss'] = (int)$r->fetch_assoc()['cnt'];
    
    $stats['parish'] = $stats['total_groups'] - $stats['under_ss'];
    
    $r = $conn->query("SELECT COUNT(*) as cnt FROM wbws_group_leaders");
    if ($r) $stats['total_leaders'] = (int)$r->fetch_assoc()['cnt'];
    
    // Ensure group_members table exists
    $conn->query("CREATE TABLE IF NOT EXISTS wbws_group_members (
        id INT AUTO_INCREMENT PRIMARY KEY, group_id INT NOT NULL, full_name VARCHAR(200) NOT NULL,
        baptismal_name VARCHAR(100), gender ENUM('M','F') DEFAULT 'M', phone VARCHAR(30),
        city VARCHAR(60), sub_city VARCHAR(60), woreda VARCHAR(20), house_number VARCHAR(20),
        education_level VARCHAR(80), notes TEXT, is_active TINYINT(1) DEFAULT 1,
        created_by VARCHAR(100), created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (group_id) REFERENCES wbws_groups(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    
    $r = $conn->query("SELECT COUNT(*) as cnt FROM wbws_group_members WHERE is_active = 1");
    if ($r) $stats['total_members'] = (int)$r->fetch_assoc()['cnt'];
    
    // Get all groups with counts
    $sql = "SELECT g.*, 
            (SELECT COUNT(*) FROM wbws_group_leaders WHERE group_id = g.id) as leader_count,
            (SELECT COUNT(*) FROM wbws_group_members WHERE group_id = g.id AND is_active = 1) as member_count
            FROM wbws_groups g ORDER BY g.created_at DESC";
    $r = $conn->query($sql);
    if ($r) while ($row = $r->fetch_assoc()) $groups[] = $row;
    } catch (Exception $e) { /* tables may not exist yet */ }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Groups Management - <?= SCHOOL_NAME_SHORT ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css"/>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Noto+Serif+Ethiopic:wght@400;600&family=Inter:wght@300;400;500;600;700&display=swap');
        *{box-sizing:border-box;margin:0;padding:0;font-family:'Inter',system-ui,sans-serif}
        .eth{font-family:'Noto Serif Ethiopic',serif}
        body{min-height:100vh;background:#f1f5f9}
        .sidebar{width:260px;background:linear-gradient(180deg,#047857,#0f766e);padding:1.25rem;display:flex;flex-direction:column;position:fixed;top:0;left:0;height:100vh;color:#fff}
        .main{margin-left:260px;min-height:100vh;display:flex;flex-direction:column}
        .topbar{background:#fff;border-bottom:1px solid #e2e8f0;padding:1rem 1.5rem;display:flex;justify-content:space-between;align-items:center;position:sticky;top:0;z-index:10}
        .content{flex:1;padding:1.5rem}
        
        .nav-link{display:flex;align-items:center;gap:.65rem;padding:.65rem .85rem;border-radius:.65rem;color:rgba(255,255,255,.85);font-size:.85rem;transition:all .15s;cursor:pointer;border:none;background:rgba(255,255,255,.1);margin-bottom:.5rem;width:100%;text-align:left}
        .nav-link:hover,.nav-link.active{background:rgba(255,255,255,.2);color:#fff}
        .nav-link i{width:18px;text-align:center}
        
        .stat-card{background:#fff;border-radius:1rem;padding:1.25rem;box-shadow:0 1px 3px rgba(0,0,0,.1);border:1px solid #e2e8f0}
        .stat-icon{width:48px;height:48px;border-radius:12px;display:flex;align-items:center;justify-content:center;font-size:1.25rem}
        .stat-value{font-size:1.75rem;font-weight:700;color:#1e293b}
        .stat-label{font-size:.75rem;color:#64748b}
        
        .card{background:#fff;border-radius:1rem;box-shadow:0 1px 3px rgba(0,0,0,.1);border:1px solid #e2e8f0;overflow:hidden}
        .card-header{padding:1rem 1.25rem;border-bottom:1px solid #e2e8f0;display:flex;justify-content:space-between;align-items:center}
        .card-title{font-size:1rem;font-weight:600;color:#1e293b;display:flex;align-items:center;gap:.5rem}
        .card-body{padding:1.25rem}
        
        table{width:100%;border-collapse:collapse;font-size:.85rem}
        th,td{padding:.75rem 1rem;text-align:left;border-bottom:1px solid #f1f5f9}
        th{background:#f8fafc;color:#64748b;font-weight:600;font-size:.75rem;text-transform:uppercase}
        tr:hover{background:#f8fafc}
        
        .badge{display:inline-flex;align-items:center;padding:.25rem .6rem;border-radius:999px;font-size:.7rem;font-weight:600}
        .badge-green{background:#dcfce7;color:#166534}
        .badge-blue{background:#dbeafe;color:#1e40af}
        .badge-purple{background:#f3e8ff;color:#7c3aed}
        
        .btn{display:inline-flex;align-items:center;gap:.4rem;padding:.6rem 1.25rem;border-radius:999px;font-size:.8rem;font-weight:600;cursor:pointer;transition:all .15s;border:none}
        .btn-primary{background:linear-gradient(135deg,#059669,#10b981);color:#fff}
        .btn-primary:hover{box-shadow:0 4px 12px rgba(16,185,129,.4)}
        .btn-sm{padding:.45rem .85rem;font-size:.75rem}
        .btn-outline{background:transparent;border:1px solid #e2e8f0;color:#64748b}
        .btn-outline:hover{background:#f8fafc}
        .btn-danger{background:#ef4444;color:#fff}
        
        .modal{position:fixed;inset:0;background:rgba(0,0,0,.5);display:none;align-items:center;justify-content:center;z-index:50;padding:1rem}
        .modal.open{display:flex}
        .modal-content{background:#fff;border-radius:1rem;width:100%;max-width:600px;max-height:90vh;overflow-y:auto;animation:slideUp .3s ease}
        @keyframes slideUp{from{opacity:0;transform:translateY(20px)}to{opacity:1;transform:translateY(0)}}
        .modal-header{padding:1rem 1.25rem;border-bottom:1px solid #e2e8f0;display:flex;justify-content:space-between;align-items:center}
        .modal-body{padding:1.25rem}
        .modal-footer{padding:1rem 1.25rem;border-top:1px solid #e2e8f0;display:flex;justify-content:flex-end;gap:.5rem}
        
        .form-group{margin-bottom:1rem}
        .form-label{display:block;font-size:.8rem;font-weight:500;color:#374151;margin-bottom:.35rem}
        .form-input,.form-select{width:100%;padding:.6rem .85rem;border:1px solid #d1d5db;border-radius:.5rem;font-size:.85rem;transition:all .15s}
        .form-input:focus,.form-select:focus{outline:none;border-color:#10b981;box-shadow:0 0 0 3px rgba(16,185,129,.15)}
        .form-row{display:grid;grid-template-columns:1fr 1fr;gap:1rem}
        
        .empty-state{text-align:center;padding:3rem;color:#64748b}
        .empty-state i{font-size:3rem;color:#cbd5e1;margin-bottom:1rem}
        
        .action-btn{width:32px;height:32px;border-radius:8px;border:1px solid #e2e8f0;background:#fff;display:inline-flex;align-items:center;justify-content:center;cursor:pointer;transition:all .15s;color:#64748b}
        .action-btn:hover{background:#f1f5f9;color:#1e293b}
        .action-btn.danger:hover{background:#fef2f2;color:#dc2626;border-color:#fecaca}
        
        @media(max-width:768px){
            .sidebar{display:none}
            .main{margin-left:0}
            .form-row{grid-template-columns:1fr}
            .stat-grid{grid-template-columns:1fr 1fr!important}
        }
        
        .tab-btn{padding:.5rem 1rem;border-radius:.5rem;font-size:.8rem;font-weight:500;cursor:pointer;border:1px solid transparent;background:transparent;color:#64748b;transition:all .15s}
        .tab-btn.active{background:#dcfce7;color:#166534;border-color:#bbf7d0}
    </style>
<link rel="stylesheet" href="/admin/css/mobile.css">
</head>
<body>
    <aside class="sidebar">
        <div class="flex items-center gap-3 mb-6">
            <div class="w-11 h-11 rounded-xl bg-white/20 flex items-center justify-center">
                <i class="fa-solid fa-layer-group text-xl"></i>
            </div>
            <div>
                <div class="font-bold">Groups Management</div>
                <div class="text-xs text-emerald-100 eth"><?= DEPT_GROUPS_NAME ?></div>
            </div>
        </div>
        
        <nav class="flex-1">
            <a href="info-dept.php" class="nav-link"><i class="fa-solid fa-arrow-left"></i> Back to Info Dept</a>
            <button class="nav-link active" onclick="showTab('list')"><i class="fa-solid fa-list"></i> All Groups</button>
            <button class="nav-link" onclick="openGroupModal()"><i class="fa-solid fa-plus"></i> Register Group</button>
        </nav>
        
        <div class="mt-auto pt-4 border-t border-white/20">
            <div class="flex items-center gap-2 text-sm">
                <div class="w-8 h-8 rounded-full bg-white/20 flex items-center justify-center font-bold"><?= strtoupper(substr($fullName, 0, 1)) ?></div>
                <div class="truncate"><?= e($fullName) ?></div>
            </div>
        </div>
    </aside>

    <div class="main">
        <header class="topbar">
            <div>
                <h1 class="text-lg font-bold text-slate-800">Groups & Associations</h1>
                <p class="text-sm text-slate-500"><?= $todayFormatted ?></p>
            </div>
            <button class="btn btn-primary" onclick="openGroupModal()"><i class="fa-solid fa-plus"></i> New Group</button>
        </header>

        <div class="content">
            <!-- Mobile Header -->
            <div class="wbws-mob-header">
                <a href="/admin/dashboard.php" class="mob-back"><i class="fa-solid fa-arrow-left"></i></a>
                <div class="mob-title">
                    <h1>Groups</h1>
                    <p class="mob-sub"><?= $todayFormatted ?></p>
                </div>
                <div class="mob-avatar"><?= strtoupper(substr($fullName, 0, 1)) ?></div>
            </div>
            <!-- Stats -->
            <div class="grid grid-cols-2 md:grid-cols-5 gap-4 mb-6 stat-grid">
                <div class="stat-card">
                    <div class="flex items-center gap-3">
                        <div class="stat-icon bg-emerald-100 text-emerald-600"><i class="fa-solid fa-layer-group"></i></div>
                        <div><div class="stat-value"><?= $stats['total_groups'] ?></div><div class="stat-label">Total Groups</div></div>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="flex items-center gap-3">
                        <div class="stat-icon bg-blue-100 text-blue-600"><i class="fa-solid fa-church"></i></div>
                        <div><div class="stat-value"><?= $stats['under_ss'] ?></div><div class="stat-label">Under <?= SCHOOL_TYPE ?></div></div>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="flex items-center gap-3">
                        <div class="stat-icon bg-purple-100 text-purple-600"><i class="fa-solid fa-building-columns"></i></div>
                        <div><div class="stat-value"><?= $stats['parish'] ?></div><div class="stat-label">Parish Associations</div></div>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="flex items-center gap-3">
                        <div class="stat-icon bg-amber-100 text-amber-600"><i class="fa-solid fa-user-tie"></i></div>
                        <div><div class="stat-value"><?= $stats['total_leaders'] ?></div><div class="stat-label">Total Leaders</div></div>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="flex items-center gap-3">
                        <div class="stat-icon bg-rose-100 text-rose-600"><i class="fa-solid fa-users"></i></div>
                        <div><div class="stat-value"><?= $stats['total_members'] ?></div><div class="stat-label">Group Members</div></div>
                    </div>
                </div>
            </div>

            <!-- Groups List -->
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title"><i class="fa-solid fa-layer-group text-emerald-600"></i> Registered Groups</h3>
                    <div class="flex gap-2">
                        <button class="tab-btn active" onclick="filterGroups('all')">All</button>
                        <button class="tab-btn" onclick="filterGroups('ss')">Sunday School</button>
                        <button class="tab-btn" onclick="filterGroups('parish')">Parish</button>
                    </div>
                </div>
                <div class="overflow-x-auto">
                    <table id="groupsTable">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>Group Name / የማህበሩ ስም</th>
                                <th>Category</th>
                                <th>Est. Year</th>
                                <th>Initial (M/F)</th>
                                <th>Current (M/F)</th>
                                <th>Leaders</th>
                                <th>Members</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody id="groupsTableBody">
                            <?php if (empty($groups)): ?>
                            <tr><td colspan="9"><div class="empty-state"><i class="fa-solid fa-folder-open"></i><p>No groups registered yet</p></div></td></tr>
                            <?php else: foreach ($groups as $i => $g): ?>
                            <tr data-id="<?= $g['id'] ?>" data-category="<?= $g['is_under_sunday_school'] ? 'ss' : 'parish' ?>">
                                <td><?= $i + 1 ?></td>
                                <td><strong><?= e($g['group_name']) ?></strong></td>
                                <td>
                                    <?php if ($g['is_under_sunday_school']): ?>
                                    <span class="badge badge-green"><i class="fa-solid fa-church mr-1"></i> Sunday School</span>
                                    <?php else: ?>
                                    <span class="badge badge-purple"><i class="fa-solid fa-building-columns mr-1"></i> Parish</span>
                                    <?php endif; ?>
                                </td>
                                <td><?= esc($g['established_year'], '-') ?></td>
                                <td><?= $g['founding_male'] ?> / <?= $g['founding_female'] ?></td>
                                <td><strong><?= $g['current_male'] ?> / <?= $g['current_female'] ?></strong></td>
                                <td><span class="badge badge-blue"><?= $g['leader_count'] ?></span></td>
                                <td><span class="badge badge-green"><?= $g['member_count'] ?></span></td>
                                <td>
                                    <button class="action-btn" onclick="viewGroup(<?= $g['id'] ?>)" title="View"><i class="fa-solid fa-eye"></i></button>
                                    <button class="action-btn" onclick="editGroup(<?= $g['id'] ?>)" title="Edit"><i class="fa-solid fa-pen"></i></button>
                                    <button class="action-btn danger" onclick="deleteGroup(<?= $g['id'] ?>, '<?= e($g['group_name']) ?>')" title="Delete"><i class="fa-solid fa-trash"></i></button>
                                </td>
                            </tr>
                            <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- Group Modal -->
    <div id="groupModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 id="groupModalTitle" class="font-bold text-lg">Register New Group</h3>
                <button onclick="closeGroupModal()" class="action-btn"><i class="fa-solid fa-times"></i></button>
            </div>
            <form id="groupForm" onsubmit="saveGroup(event)">
                <div class="modal-body">
                    <input type="hidden" name="id" id="groupId">
                    <div class="form-group">
                        <label class="form-label">Group Name / የማህበሩ ስም *</label>
                        <input type="text" name="group_name" id="groupName" class="form-input" required placeholder="e.g. ቅዱስ ሚካኤል ማህበር">
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label">Year Established / የተመሰረተበት ዓ.ም</label>
                            <input type="text" name="established_year" id="establishedYear" class="form-input" placeholder="e.g. 2010">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Category</label>
                            <label class="flex items-center gap-2 mt-2">
                                <input type="checkbox" name="is_under_sunday_school" id="underSS" checked class="w-4 h-4 text-emerald-600">
                                <span class="text-sm">Under <?= SCHOOL_TYPE ?> / በሰንበት ት/ቤት ስር</span>
                            </label>
                        </div>
                    </div>
                    <div class="p-3 bg-slate-50 rounded-lg mb-4">
                        <div class="text-sm font-semibold text-slate-700 mb-2">Initial Membership / ሲመሰረት ያለው</div>
                        <div class="form-row">
                            <div class="form-group mb-0">
                                <label class="form-label">Male / ወንድ</label>
                                <input type="number" name="founding_male" id="foundingMale" class="form-input" value="0" min="0">
                            </div>
                            <div class="form-group mb-0">
                                <label class="form-label">Female / ሴት</label>
                                <input type="number" name="founding_female" id="foundingFemale" class="form-input" value="0" min="0">
                            </div>
                        </div>
                    </div>
                    <div class="p-3 bg-emerald-50 rounded-lg mb-4">
                        <div class="text-sm font-semibold text-emerald-700 mb-2">Current Membership / አሁን ያለው</div>
                        <div class="form-row">
                            <div class="form-group mb-0">
                                <label class="form-label">Male / ወንድ</label>
                                <input type="number" name="current_male" id="currentMale" class="form-input" value="0" min="0">
                            </div>
                            <div class="form-group mb-0">
                                <label class="form-label">Female / ሴት</label>
                                <input type="number" name="current_female" id="currentFemale" class="form-input" value="0" min="0">
                            </div>
                        </div>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Notes / ማስታወሻ</label>
                        <textarea name="notes" id="groupNotes" class="form-input" rows="2" placeholder="Additional notes..."></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" onclick="closeGroupModal()" class="btn btn-outline">Cancel</button>
                    <button type="submit" class="btn btn-primary"><i class="fa-solid fa-save"></i> Save Group</button>
                </div>
            </form>
        </div>
    </div>

    <!-- View Group Modal -->
    <div id="viewGroupModal" class="modal">
        <div class="modal-content" style="max-width:900px">
            <div class="modal-header">
                <h3 id="viewGroupTitle" class="font-bold text-lg">Group Details</h3>
                <button onclick="closeViewGroupModal()" class="action-btn"><i class="fa-solid fa-times"></i></button>
            </div>
            <div class="modal-body" id="viewGroupContent">
                <div class="text-center py-8 text-slate-400"><i class="fa-solid fa-spinner fa-spin text-2xl"></i></div>
            </div>
        </div>
    </div>

    <!-- Leader Modal -->
    <div id="leaderModal" class="modal">
        <div class="modal-content" style="max-width:500px">
            <div class="modal-header">
                <h3 id="leaderModalTitle" class="font-bold text-lg">Add Leader</h3>
                <button onclick="closeLeaderModal()" class="action-btn"><i class="fa-solid fa-times"></i></button>
            </div>
            <form id="leaderForm" onsubmit="saveLeader(event)">
                <div class="modal-body">
                    <input type="hidden" name="id" id="leaderId">
                    <input type="hidden" name="group_id" id="leaderGroupId">
                    <div class="form-group">
                        <label class="form-label">Full Name / ሙሉ ስም *</label>
                        <input type="text" name="leader_full_name" id="leaderName" class="form-input" required>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label">Gender / ጾታ *</label>
                            <select name="sex" id="leaderSex" class="form-select">
                                <option value="M">Male / ወንድ</option>
                                <option value="F">Female / ሴት</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Phone / ስልክ</label>
                            <input type="text" name="phone" id="leaderPhone" class="form-input">
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label">Education / የት/ደረጃ</label>
                            <select name="education_level" id="leaderEducation" class="form-select">
                                <option value="">Select...</option>
                                <option value="Elementary">Elementary</option>
                                <option value="High School">High School</option>
                                <option value="Diploma">Diploma</option>
                                <option value="Degree">Degree</option>
                                <option value="Masters">Masters</option>
                                <option value="PhD">PhD</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Responsibility / ሃላፊነት *</label>
                            <input type="text" name="responsibility" id="leaderResponsibility" class="form-input" required placeholder="e.g. Chair, Secretary">
                        </div>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Remarks / ምርመራ</label>
                        <input type="text" name="remark" id="leaderRemark" class="form-input">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" onclick="closeLeaderModal()" class="btn btn-outline">Cancel</button>
                    <button type="submit" class="btn btn-primary"><i class="fa-solid fa-save"></i> Save</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Member Modal -->
    <div id="memberModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 id="memberModalTitle" class="font-bold text-lg">Add Member</h3>
                <button onclick="closeMemberModal()" class="action-btn"><i class="fa-solid fa-times"></i></button>
            </div>
            <form id="memberForm" onsubmit="saveMember(event)">
                <div class="modal-body">
                    <input type="hidden" name="id" id="memberId">
                    <input type="hidden" name="group_id" id="memberGroupId">
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label">Full Name / ሙሉ ስም *</label>
                            <input type="text" name="full_name" id="memberName" class="form-input" required>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Baptismal Name / ስመ ጥምቀት</label>
                            <input type="text" name="baptismal_name" id="memberBaptismal" class="form-input">
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label">Gender / ጾታ *</label>
                            <select name="gender" id="memberGender" class="form-select">
                                <option value="M">Male / ወንድ</option>
                                <option value="F">Female / ሴት</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Phone / ስልክ</label>
                            <input type="text" name="phone" id="memberPhone" class="form-input">
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label">City / ከተማ</label>
                            <input type="text" name="city" id="memberCity" class="form-input">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Sub City / ክፍለ ከተማ</label>
                            <input type="text" name="sub_city" id="memberSubCity" class="form-input">
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label">Woreda / ወረዳ</label>
                            <input type="text" name="woreda" id="memberWoreda" class="form-input">
                        </div>
                        <div class="form-group">
                            <label class="form-label">House No. / ቤት ቁ.</label>
                            <input type="text" name="house_number" id="memberHouse" class="form-input">
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label">Education / የት/ደረጃ</label>
                            <select name="education_level" id="memberEducation" class="form-select">
                                <option value="">Select...</option>
                                <option value="Elementary">Elementary</option>
                                <option value="High School">High School</option>
                                <option value="Diploma">Diploma</option>
                                <option value="Degree">Degree</option>
                                <option value="Masters">Masters</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Notes / ማስታወሻ</label>
                            <input type="text" name="notes" id="memberNotes" class="form-input">
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" onclick="closeMemberModal()" class="btn btn-outline">Cancel</button>
                    <button type="submit" class="btn btn-primary"><i class="fa-solid fa-save"></i> Save</button>
                </div>
            </form>
        </div>
    </div>

    <script>
    const API = '../backend/groups_api.php';
    let currentGroupId = null;

    // Group CRUD
    function openGroupModal(id = null) {
        document.getElementById('groupForm').reset();
        document.getElementById('groupId').value = '';
        document.getElementById('groupModalTitle').textContent = 'Register New Group';
        if (id) {
            document.getElementById('groupModalTitle').textContent = 'Edit Group';
            fetch(`${API}?action=get_group&id=${id}`).then(r => r.json()).then(d => {
                if (d.success) {
                    const g = d.data;
                    document.getElementById('groupId').value = g.id;
                    document.getElementById('groupName').value = g.group_name;
                    document.getElementById('establishedYear').value = g.established_year || '';
                    document.getElementById('underSS').checked = g.is_under_sunday_school == 1;
                    document.getElementById('foundingMale').value = g.founding_male;
                    document.getElementById('foundingFemale').value = g.founding_female;
                    document.getElementById('currentMale').value = g.current_male;
                    document.getElementById('currentFemale').value = g.current_female;
                    document.getElementById('groupNotes').value = g.notes || '';
                }
            });
        }
        document.getElementById('groupModal').classList.add('open');
    }

    function closeGroupModal() { document.getElementById('groupModal').classList.remove('open'); }

    function saveGroup(e) {
        e.preventDefault();
        const form = new FormData(document.getElementById('groupForm'));
        form.append('action', 'save_group');
        if (!document.getElementById('underSS').checked) form.delete('is_under_sunday_school');
        fetch(API, { method: 'POST', body: form }).then(r => r.json()).then(d => {
            if (d.success) { closeGroupModal(); location.reload(); }
            else alert(d.message || 'Error saving group');
        });
    }

    function editGroup(id) { openGroupModal(id); }

    function deleteGroup(id, name) {
        if (!confirm(`Delete group "${name}"? This will also delete all leaders and members.`)) return;
        const form = new FormData();
        form.append('action', 'delete_group');
        form.append('id', id);
        fetch(API, { method: 'POST', body: form }).then(r => r.json()).then(d => {
            if (d.success) location.reload();
            else alert(d.message || 'Error');
        });
    }

    function filterGroups(cat) {
        document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
        event.target.classList.add('active');
        document.querySelectorAll('#groupsTableBody tr[data-id]').forEach(row => {
            if (cat === 'all' || row.dataset.category === cat) row.style.display = '';
            else row.style.display = 'none';
        });
    }

    // View Group Details
    function viewGroup(id) {
        currentGroupId = id;
        document.getElementById('viewGroupModal').classList.add('open');
        document.getElementById('viewGroupContent').innerHTML = '<div class="text-center py-8 text-slate-400"><i class="fa-solid fa-spinner fa-spin text-2xl"></i></div>';
        
        Promise.all([
            fetch(`${API}?action=get_group&id=${id}`).then(r => r.json()),
            fetch(`${API}?action=list_leaders&group_id=${id}`).then(r => r.json()),
            fetch(`${API}?action=list_members&group_id=${id}`).then(r => r.json())
        ]).then(([gRes, lRes, mRes]) => {
            if (!gRes.success) { alert('Error loading group'); return; }
            const g = gRes.data;
            const leaders = lRes.success ? lRes.data : [];
            const members = mRes.success ? mRes.data : [];
            
            document.getElementById('viewGroupTitle').textContent = g.group_name;
            
            let html = `
                <div class="grid grid-cols-2 md:grid-cols-4 gap-3 mb-4">
                    <div class="p-3 bg-slate-50 rounded-lg"><div class="text-xs text-slate-500">Category</div><div class="font-semibold">${g.is_under_sunday_school ? 'Sunday School' : 'Parish'}</div></div>
                    <div class="p-3 bg-slate-50 rounded-lg"><div class="text-xs text-slate-500">Est. Year</div><div class="font-semibold">${g.established_year || '-'}</div></div>
                    <div class="p-3 bg-slate-50 rounded-lg"><div class="text-xs text-slate-500">Initial (M/F)</div><div class="font-semibold">${g.founding_male} / ${g.founding_female}</div></div>
                    <div class="p-3 bg-emerald-50 rounded-lg"><div class="text-xs text-emerald-600">Current (M/F)</div><div class="font-semibold text-emerald-700">${g.current_male} / ${g.current_female}</div></div>
                </div>
                
                <div class="mb-4">
                    <div class="flex items-center justify-between mb-2">
                        <h4 class="font-semibold text-slate-700"><i class="fa-solid fa-user-tie text-amber-500 mr-2"></i>Leaders (${leaders.length})</h4>
                        <button onclick="openLeaderModal(${id})" class="btn btn-sm btn-primary"><i class="fa-solid fa-plus"></i> Add</button>
                    </div>
                    <table class="text-sm">
                        <thead><tr><th>Name</th><th>Gender</th><th>Phone</th><th>Responsibility</th><th></th></tr></thead>
                        <tbody>
                            ${leaders.length === 0 ? '<tr><td colspan="5" class="text-center text-slate-400 py-4">No leaders yet</td></tr>' : ''}
                            ${leaders.map(l => `<tr>
                                <td>${l.leader_full_name}</td>
                                <td>${l.sex === 'M' ? 'Male' : 'Female'}</td>
                                <td>${l.phone || '-'}</td>
                                <td>${l.responsibility || '-'}</td>
                                <td><button class="action-btn danger" onclick="deleteLeader(${l.id})"><i class="fa-solid fa-trash"></i></button></td>
                            </tr>`).join('')}
                        </tbody>
                    </table>
                </div>
                
                <div>
                    <div class="flex items-center justify-between mb-2">
                        <h4 class="font-semibold text-slate-700"><i class="fa-solid fa-users text-emerald-500 mr-2"></i>Members (${members.length})</h4>
                        <button onclick="openMemberModal(${id})" class="btn btn-sm btn-primary"><i class="fa-solid fa-plus"></i> Add</button>
                    </div>
                    <table class="text-sm">
                        <thead><tr><th>Name</th><th>Baptismal</th><th>Gender</th><th>Phone</th><th>Address</th><th></th></tr></thead>
                        <tbody>
                            ${members.length === 0 ? '<tr><td colspan="6" class="text-center text-slate-400 py-4">No members yet</td></tr>' : ''}
                            ${members.map(m => `<tr>
                                <td>${m.full_name}</td>
                                <td>${m.baptismal_name || '-'}</td>
                                <td>${m.gender === 'M' ? 'M' : 'F'}</td>
                                <td>${m.phone || '-'}</td>
                                <td>${[m.city, m.sub_city, m.woreda].filter(Boolean).join(', ') || '-'}</td>
                                <td><button class="action-btn danger" onclick="deleteMember(${m.id})"><i class="fa-solid fa-trash"></i></button></td>
                            </tr>`).join('')}
                        </tbody>
                    </table>
                </div>
            `;
            document.getElementById('viewGroupContent').innerHTML = html;
        });
    }

    function closeViewGroupModal() { document.getElementById('viewGroupModal').classList.remove('open'); }

    // Leader CRUD
    function openLeaderModal(groupId) {
        document.getElementById('leaderForm').reset();
        document.getElementById('leaderId').value = '';
        document.getElementById('leaderGroupId').value = groupId;
        document.getElementById('leaderModalTitle').textContent = 'Add Leader';
        document.getElementById('leaderModal').classList.add('open');
    }

    function closeLeaderModal() { document.getElementById('leaderModal').classList.remove('open'); }

    function saveLeader(e) {
        e.preventDefault();
        const form = new FormData(document.getElementById('leaderForm'));
        form.append('action', 'save_leader');
        fetch(API, { method: 'POST', body: form }).then(r => r.json()).then(d => {
            if (d.success) { closeLeaderModal(); viewGroup(currentGroupId); }
            else alert(d.message || 'Error');
        });
    }

    function deleteLeader(id) {
        if (!confirm('Remove this leader?')) return;
        const form = new FormData();
        form.append('action', 'delete_leader');
        form.append('id', id);
        fetch(API, { method: 'POST', body: form }).then(r => r.json()).then(d => {
            if (d.success) viewGroup(currentGroupId);
            else alert(d.message || 'Error');
        });
    }

    // Member CRUD
    function openMemberModal(groupId) {
        document.getElementById('memberForm').reset();
        document.getElementById('memberId').value = '';
        document.getElementById('memberGroupId').value = groupId;
        document.getElementById('memberModalTitle').textContent = 'Add Member';
        document.getElementById('memberModal').classList.add('open');
    }

    function closeMemberModal() { document.getElementById('memberModal').classList.remove('open'); }

    function saveMember(e) {
        e.preventDefault();
        const form = new FormData(document.getElementById('memberForm'));
        form.append('action', 'save_member');
        fetch(API, { method: 'POST', body: form }).then(r => r.json()).then(d => {
            if (d.success) { closeMemberModal(); viewGroup(currentGroupId); }
            else alert(d.message || 'Error');
        });
    }

    function deleteMember(id) {
        if (!confirm('Remove this member?')) return;
        const form = new FormData();
        form.append('action', 'delete_member');
        form.append('id', id);
        fetch(API, { method: 'POST', body: form }).then(r => r.json()).then(d => {
            if (d.success) viewGroup(currentGroupId);
            else alert(d.message || 'Error');
        });
    }
    </script>
</body>
</html>
