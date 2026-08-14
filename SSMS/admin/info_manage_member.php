<?php
/**
 * Manage Member - View/Edit Member Details
 * Works with parent page's JavaScript functions
 * IDs must match: tab-preview, tab-edit, view-preview, view-edit
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/backend/ethiopian_date.php';

// Helper functions
if (!function_exists('sel')) {
    function sel($current, $value) { return ((string)$current === (string)$value) ? 'selected' : ''; }
}
if (!function_exists('chk')) {
    function chk($val) { return (!empty($val) && (int)$val === 1) ? 'checked' : ''; }
}
if (!function_exists('fixPath')) {
    function fixPath($path) {
        if (empty($path)) return '';
        if (strpos($path, 'http') === 0) return $path;
        $path = ltrim($path, '/');
        if (strpos($path, 'uploads/') === 0) return '/admin/' . $path;
        if (strpos($path, 'admin/') === 0) return '/' . $path;
        return '/' . $path;
    }
}
if (!function_exists('isImage')) {
    function isImage($path) { 
        if (empty($path)) return false; 
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION)); 
        return in_array($ext, ['jpg','jpeg','png','gif','webp','jfif','bmp']); 
    }
}
if (!function_exists('isPDF')) {
    function isPDF($path) { 
        if (empty($path)) return false; 
        return strtolower(pathinfo($path, PATHINFO_EXTENSION)) === 'pdf'; 
    }
}
if (!function_exists('field')) { 
    function field($n, $d = '') { return isset($_POST[$n]) ? trim($_POST[$n]) : $d; } 
}
if (!function_exists('saveUploadedFile')) {
    function saveUploadedFile($fieldName, $subDir) {
        if (!isset($_FILES[$fieldName]) || $_FILES[$fieldName]['error'] === UPLOAD_ERR_NO_FILE) return null;
        if ($_FILES[$fieldName]['error'] !== UPLOAD_ERR_OK) return null;
        // Validate file size (max 5MB)
        if ($_FILES[$fieldName]['size'] > 5 * 1024 * 1024) return null;
        // Validate extension
        $ext = strtolower(pathinfo($_FILES[$fieldName]['name'], PATHINFO_EXTENSION));
        $allowedExts = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'pdf', 'bmp'];
        if (!in_array($ext, $allowedExts)) return null;
        // For images, verify they are real images
        if (in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp'])) {
            if (@getimagesize($_FILES[$fieldName]['tmp_name']) === false) return null;
        }
        $uploadBase = __DIR__ . '/uploads/members'; 
        $relBase = 'uploads/members'; 
        $targetDir = $uploadBase . '/' . $subDir; 
        if (!is_dir($targetDir)) @mkdir($targetDir, 0775, true);
        if ($ext === '') $ext = 'bin';
        $safeName = $fieldName . '_' . time() . '_' . mt_rand(1000, 9999) . '.' . $ext; 
        $targetPath = $targetDir . '/' . $safeName;
        if (!move_uploaded_file($_FILES[$fieldName]['tmp_name'], $targetPath)) return null;
        return $relBase . '/' . $subDir . '/' . $safeName;
    }
}

// ============================================================
// HANDLE POST REQUEST (Update Member)
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json; charset=utf-8');
    
    // Global error handler for this request
    set_error_handler(function($severity, $message, $file, $line) {
        throw new ErrorException($message, 0, $severity, $file, $line);
    });
    
    try {
        // Validate CSRF token
        if (!validateCsrf()) {
            echo json_encode(['status' => 'error', 'message' => 'Security token expired. Please refresh and try again.']);
            exit;
        }
        
        if (!isset($conn) || $conn->connect_error) { 
            echo json_encode(['status' => 'error', 'message' => 'Database connection not available']); 
            exit; 
        }
        
        $id = isset($_POST['id']) ? (int)$_POST['id'] : 0; 
        if ($id <= 0) { 
            echo json_encode(['status' => 'error', 'message' => 'Invalid member ID']); 
            exit; 
        }
        
        $stmt = $conn->prepare('SELECT * FROM members WHERE id = ?'); 
        $stmt->bind_param('i', $id); 
        $stmt->execute(); 
        $cur = $stmt->get_result()->fetch_assoc(); 
        $stmt->close(); 
        
        if (!$cur) { 
            echo json_encode(['status' => 'error', 'message' => 'Member not found']); 
            exit; 
        }
        
        // Check if spiritual_education column exists (exception-safe)
        $hasSpiritualEduCol = false;
        try {
            $hasSpiritualEdu = $conn->query("SHOW COLUMNS FROM members LIKE 'spiritual_education'");
            $hasSpiritualEduCol = $hasSpiritualEdu && $hasSpiritualEdu->num_rows > 0;
        } catch (Exception $e) {}
        
        // If column doesn't exist, try to add it
        if (!$hasSpiritualEduCol) {
            try {
                $conn->query("ALTER TABLE `members` ADD COLUMN `spiritual_education` VARCHAR(100) DEFAULT NULL AFTER `education_level`");
                $hasSpiritualEduCol = true;
            } catch (Exception $e) {}
        }

    // Get form values
    $registration_type = field('registration_type', $cur['registration_type'] ?? 'waiting');
    $member_type       = field('member_type', $cur['member_type'] ?? 'regular');
    $status            = field('status', $cur['status'] ?? 'active');
    $student_name      = field('student_name', $cur['student_name'] ?? '');
    $baptismal_name    = field('baptismal_name', $cur['baptismal_name'] ?? '');
    $father_name       = field('father_name', $cur['father_name'] ?? '');
    $grandfather_name  = field('grandfather_name', $cur['grandfather_name'] ?? '');
    $gender            = field('gender', $cur['gender'] ?? 'male');
    $dob_day           = (int) field('dob_day', $cur['dob_ec_day'] ?? 0);
    $dob_month         = (int) field('dob_month', $cur['dob_ec_month'] ?? 0);
    $dob_year          = (int) field('dob_year', $cur['dob_ec_year'] ?? 0);
    
    // Calculate age
    $age = null; $age_group = null; $current_section = null;
    if ($dob_year > 0) { 
        $now = new DateTime('now', new DateTimeZone('Africa/Addis_Ababa')); 
        $y = (int)ethio_date_format($now, 'Y'); 
        $age = max(0, $y - $dob_year);
        if ($age <= 6) { $current_section = 'አጸደ ህጻናት'; $age_group = 'under6'; }
        elseif ($age <= 13) { $current_section = 'ህጻናት'; $age_group = '7_13'; }
        elseif ($age <= 17) { $current_section = 'ማዕከላዊያን'; $age_group = '14_17'; }
        else { $current_section = 'ወጣቶች'; $age_group = '18_plus'; }
    }
    // Ensure age_group is either a valid value or NULL (never empty string)
    if (empty($age_group)) { $age_group = null; }
    
    $education_level  = field('education_level', $cur['education_level'] ?? '');
    $spiritual_education = field('spiritual_education', $cur['spiritual_education'] ?? '');
    $work_profession  = field('work_profession', $cur['work_profession'] ?? '');
    $city             = field('city', $cur['city'] ?? '');
    $sub_city         = field('sub_city', $cur['sub_city'] ?? '');
    $woreda           = field('woreda', $cur['woreda'] ?? '');
    $mender           = field('mender', $cur['mender'] ?? '');
    $block_number     = field('block_number', $cur['block_number'] ?? '');
    $house_number     = field('house_number', $cur['house_number'] ?? '');
    $phone_number     = field('phone_number', $cur['phone_number'] ?? '');
    $alt_phone_number = field('alt_phone_number', $cur['alt_phone_number'] ?? '');
    $guardian_name    = field('guardian_name', $cur['guardian_name'] ?? '');
    $guardian_phone1  = field('guardian_phone1', $cur['guardian_phone1'] ?? '');
    $guardian_phone2  = field('guardian_phone2', $cur['guardian_phone2'] ?? '');
    
    $is_teacher   = isset($_POST['is_teacher']) ? 1 : 0;
    $is_staff     = isset($_POST['is_staff']) ? 1 : 0;
    $is_committee = isset($_POST['is_committee']) ? 1 : 0;
    $is_volunteer = isset($_POST['is_volunteer']) ? 1 : 0;

    // File uploads
    $student_photo_path      = saveUploadedFile('student_photo', 'photos') ?? $cur['student_photo_path'];
    $guardian_photo_path     = saveUploadedFile('guardian_photo', 'guardian_photos') ?? $cur['guardian_photo_path'];
    $doc_school_records_path = saveUploadedFile('doc_school_records', 'docs') ?? $cur['doc_school_records_path'];
    $doc_spiritual_path      = saveUploadedFile('doc_spiritual', 'docs') ?? $cur['doc_spiritual_path'];
    $doc_signed_form_path    = saveUploadedFile('doc_signed_form', 'docs') ?? $cur['doc_signed_form_path'];

    $full_name_am = trim($student_name . ' ' . $father_name . ' ' . $grandfather_name);

    // Build SQL dynamically based on available columns
    if ($hasSpiritualEduCol) {
        $sql = "UPDATE members SET 
            registration_type=?, member_type=?, status=?,
            student_name=?, baptismal_name=?, father_name=?, grandfather_name=?,
            full_name_am=?, gender=?, 
            dob_ec_day=?, dob_ec_month=?, dob_ec_year=?, age=?, age_group=?, current_section=?,
            education_level=?, spiritual_education=?, work_profession=?,
            city=?, sub_city=?, woreda=?, mender=?, block_number=?, house_number=?,
            phone_number=?, phone_primary=?, alt_phone_number=?,
            guardian_name=?, guardian_phone1=?, guardian_phone2=?, phone_guardian=?,
            is_teacher=?, is_staff=?, is_committee=?, is_volunteer=?,
            student_photo_path=?, guardian_photo_path=?,
            doc_school_records_path=?, doc_spiritual_path=?, doc_signed_form_path=?
            WHERE id=?";
        
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            echo json_encode(['status' => 'error', 'message' => 'SQL Error: ' . $conn->error]);
            exit;
        }
        $ageVal = $age !== null ? $age : 0;
        $stmt->bind_param('sssssssssiiiissssssssssssssssssiiiisssssi',
            $registration_type, $member_type, $status,
            $student_name, $baptismal_name, $father_name, $grandfather_name,
            $full_name_am, $gender, 
            $dob_day, $dob_month, $dob_year, $ageVal, $age_group, $current_section,
            $education_level, $spiritual_education, $work_profession,
            $city, $sub_city, $woreda, $mender, $block_number, $house_number,
            $phone_number, $phone_number, $alt_phone_number,
            $guardian_name, $guardian_phone1, $guardian_phone2, $guardian_phone1,
            $is_teacher, $is_staff, $is_committee, $is_volunteer,
            $student_photo_path, $guardian_photo_path,
            $doc_school_records_path, $doc_spiritual_path, $doc_signed_form_path,
            $id
        );
    } else {
        // Without spiritual_education column
        $sql = "UPDATE members SET 
            registration_type=?, member_type=?, status=?,
            student_name=?, baptismal_name=?, father_name=?, grandfather_name=?,
            full_name_am=?, gender=?, 
            dob_ec_day=?, dob_ec_month=?, dob_ec_year=?, age=?, age_group=?, current_section=?,
            education_level=?, work_profession=?,
            city=?, sub_city=?, woreda=?, mender=?, block_number=?, house_number=?,
            phone_number=?, phone_primary=?, alt_phone_number=?,
            guardian_name=?, guardian_phone1=?, guardian_phone2=?, phone_guardian=?,
            is_teacher=?, is_staff=?, is_committee=?, is_volunteer=?,
            student_photo_path=?, guardian_photo_path=?,
            doc_school_records_path=?, doc_spiritual_path=?, doc_signed_form_path=?
            WHERE id=?";
        
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            echo json_encode(['status' => 'error', 'message' => 'SQL Error: ' . $conn->error]);
            exit;
        }
        $ageVal = $age !== null ? $age : 0;
        $stmt->bind_param('sssssssssiiiisssssssssssssssssiiiisssssi',
            $registration_type, $member_type, $status,
            $student_name, $baptismal_name, $father_name, $grandfather_name,
            $full_name_am, $gender, 
            $dob_day, $dob_month, $dob_year, $ageVal, $age_group, $current_section,
            $education_level, $work_profession,
            $city, $sub_city, $woreda, $mender, $block_number, $house_number,
            $phone_number, $phone_number, $alt_phone_number,
            $guardian_name, $guardian_phone1, $guardian_phone2, $guardian_phone1,
            $is_teacher, $is_staff, $is_committee, $is_volunteer,
            $student_photo_path, $guardian_photo_path,
            $doc_school_records_path, $doc_spiritual_path, $doc_signed_form_path,
            $id
        );
    }
    
    if ($stmt->execute()) { 
        // Sync member_type based on role flags (cross-department sync)
        if (file_exists(__DIR__ . '/backend/member_sync.php')) {
            require_once __DIR__ . '/backend/member_sync.php';
            syncMemberType($conn, $id, [
                'is_teacher' => $is_teacher,
                'is_staff' => $is_staff,
                'is_committee' => $is_committee,
                'is_volunteer' => $is_volunteer,
            ]);
        }
        echo json_encode(['status' => 'success', 'message' => 'Member updated successfully']); 
    } else { 
        echo json_encode(['status' => 'error', 'message' => 'Database error: ' . $stmt->error]); 
    }
    $stmt->close();
    
    } catch (Exception $e) {
        echo json_encode(['status' => 'error', 'message' => 'Error: ' . $e->getMessage()]);
    }
    exit;
}

// ============================================================
// GET REQUEST - Display Member Details
// ============================================================
$memberId = isset($_GET['id']) ? (int)$_GET['id'] : 0; 
if ($memberId <= 0) die('<div style="padding:20px;color:#dc2626;">Invalid member ID</div>');

$stmt = $conn->prepare('SELECT * FROM members WHERE id = ?'); 
$stmt->bind_param('i', $memberId); 
$stmt->execute(); 
$m = $stmt->get_result()->fetch_assoc(); 
$stmt->close(); 

if (!$m) die('<div style="padding:20px;color:#dc2626;">Member not found</div>');

// Ensure optional columns have safe defaults (in case column doesn't exist in DB yet)
$m['spiritual_education'] = $m['spiritual_education'] ?? '';
$m['age_group'] = $m['age_group'] ?? '';

// Ethiopian month names
$ethMonths = [1=>'መስከረም',2=>'ጥቅምት',3=>'ኅዳር',4=>'ታኅሣሥ',5=>'ጥር',6=>'የካቲት',7=>'መጋቢት',8=>'ሚያዝያ',9=>'ግንቦት',10=>'ሰኔ',11=>'ሐምሌ',12=>'ነሐሴ',13=>'ጳጉሜ'];
$dobDisplay = '';
if ($m['dob_ec_day'] && $m['dob_ec_month'] && $m['dob_ec_year']) {
    $dobDisplay = $m['dob_ec_day'] . ' ' . ($ethMonths[(int)$m['dob_ec_month']] ?? '') . ' ' . $m['dob_ec_year'] . ' ዓ.ም';
}

$now = new DateTime('now', new DateTimeZone('Africa/Addis_Ababa'));
$printDate = ethio_date_format($now, 'F j, Y');
$fullName = trim($m['student_name'] . ' ' . $m['father_name'] . ' ' . $m['grandfather_name']);
?>
<style>
/* ========== MANAGE MEMBER STYLES ========== */
.mm-wrap { font-family: 'Segoe UI', system-ui, sans-serif; background: #f1f5f9; }

/* Header */
.mm-header { 
    background: linear-gradient(135deg, #047857 0%, #059669 50%, #10b981 100%); 
    padding: 20px; color: #fff; position: relative; 
}
.mm-header::after { 
    content: ''; position: absolute; bottom: 0; left: 0; right: 0; 
    height: 4px; background: linear-gradient(90deg, #34d399, #6ee7b7, #34d399); 
}
.mm-hdr-content { display: flex; align-items: center; gap: 16px; }
.mm-avatar { 
    width: 80px; height: 100px; /* Passport ratio 4:5 */
    border-radius: 8px; background: rgba(255,255,255,.15); 
    border: 3px solid rgba(255,255,255,.4); overflow: hidden; 
    display: flex; align-items: center; justify-content: center; cursor: pointer; 
    flex-shrink: 0; transition: all .2s;
}
.mm-avatar:hover { border-color: #fff; box-shadow: 0 0 20px rgba(255,255,255,.3); transform: scale(1.02); }
.mm-avatar img { width: 100%; height: 100%; object-fit: cover; }
.mm-avatar i { font-size: 32px; color: rgba(255,255,255,.5); }
.mm-hdr-info { flex: 1; }
.mm-hdr-name { font-size: 18px; font-weight: 700; margin-bottom: 4px; }
.mm-hdr-id { font-size: 13px; opacity: .9; display: flex; align-items: center; gap: 6px; }
.mm-status { 
    padding: 6px 14px; border-radius: 20px; font-size: 11px; font-weight: 700; 
    text-transform: uppercase; letter-spacing: .5px; 
}
.mm-status-active { background: #fff; color: #059669; }
.mm-status-warning { background: #fef3c7; color: #b45309; }
.mm-status-inactive { background: #fee2e2; color: #b91c1c; }
.mm-status-archived { background: #e5e7eb; color: #4b5563; }

/* Tabs - MUST use correct IDs: tab-preview, tab-edit */
.mm-tabs { 
    display: flex; background: #fff; position: sticky; top: 0; z-index: 20; 
    box-shadow: 0 2px 8px rgba(0,0,0,.06); border-bottom: 1px solid #e2e8f0;
}
.mm-tab { 
    flex: 1; padding: 14px 12px; display: flex; align-items: center; justify-content: center; gap: 8px;
    font-size: 14px; font-weight: 600; background: transparent; border: none; cursor: pointer;
    border-bottom: 3px solid transparent; color: #64748b; transition: all .2s;
}
.mm-tab:hover { background: #f8fafc; color: #334155; }
.mm-tab.border-emerald-600 { border-bottom-color: #059669 !important; }
.mm-tab.text-emerald-700 { color: #047857 !important; background: linear-gradient(to bottom, #ecfdf5, #fff); }
.mm-tab i { font-size: 16px; }

/* Action Buttons */
.mm-actions { display: flex; gap: 8px; padding: 12px 16px; background: #fff; border-bottom: 1px solid #e2e8f0; }
.mm-btn { 
    display: inline-flex; align-items: center; gap: 6px; padding: 10px 16px; 
    font-size: 13px; font-weight: 600; border-radius: 10px; border: none; cursor: pointer; transition: all .2s; 
}
.mm-btn-green { background: linear-gradient(135deg, #059669, #10b981); color: #fff; box-shadow: 0 2px 8px rgba(16,185,129,.25); }
.mm-btn-green:hover { transform: translateY(-1px); box-shadow: 0 4px 12px rgba(16,185,129,.35); }
.mm-btn-gray { background: #f1f5f9; color: #475569; }
.mm-btn-gray:hover { background: #e2e8f0; }

/* Content - MUST use correct IDs: view-preview, view-edit */
.mm-content { padding: 16px; padding-bottom: 100px; }

/* Cards */
.mm-card { 
    background: #fff; border-radius: 16px; margin-bottom: 16px; overflow: hidden; 
    box-shadow: 0 1px 3px rgba(0,0,0,.06); border: 1px solid #e5e7eb;
}
.mm-card-hdr { 
    display: flex; align-items: center; gap: 12px; padding: 14px 16px; 
    background: #fafbfc; border-bottom: 1px solid #f1f5f9; 
}
.mm-card-icon { 
    width: 40px; height: 40px; border-radius: 10px; 
    display: flex; align-items: center; justify-content: center; font-size: 16px; 
}
.mm-icon-green { background: #d1fae5; color: #059669; }
.mm-icon-blue { background: #dbeafe; color: #2563eb; }
.mm-icon-purple { background: #ede9fe; color: #7c3aed; }
.mm-icon-red { background: #fee2e2; color: #dc2626; }
.mm-icon-amber { background: #fef3c7; color: #d97706; }
.mm-card-title { font-size: 15px; font-weight: 700; color: #1e293b; }
.mm-card-body { padding: 16px; }

/* Photo Grid - Passport Size */
.mm-photos { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; }
.mm-photo-wrap { text-align: center; }
.mm-photo-label { font-size: 11px; font-weight: 700; color: #64748b; text-transform: uppercase; letter-spacing: .5px; margin-bottom: 10px; }
.mm-photo-box { 
    width: 100%; max-width: 120px; height: 150px; /* Passport 4:5 ratio */
    margin: 0 auto; border-radius: 10px; background: linear-gradient(135deg, #f8fafc, #f1f5f9); 
    border: 2px solid #e2e8f0; overflow: hidden; cursor: pointer; 
    display: flex; align-items: center; justify-content: center; position: relative; transition: all .2s;
}
.mm-photo-box:hover { border-color: #10b981; transform: translateY(-2px); box-shadow: 0 8px 20px rgba(16,185,129,.15); }
.mm-photo-box img { width: 100%; height: 100%; object-fit: cover; }
.mm-photo-box .mm-photo-overlay { 
    position: absolute; inset: 0; background: rgba(0,0,0,.5); 
    display: flex; align-items: center; justify-content: center; 
    opacity: 0; transition: opacity .2s; 
}
.mm-photo-box:hover .mm-photo-overlay { opacity: 1; }
.mm-photo-overlay i { color: #fff; font-size: 24px; }
.mm-photo-empty { display: flex; flex-direction: column; align-items: center; color: #94a3b8; }
.mm-photo-empty i { font-size: 36px; margin-bottom: 6px; }
.mm-photo-empty span { font-size: 11px; }

/* Documents */
.mm-docs { display: grid; grid-template-columns: repeat(3, 1fr); gap: 12px; }
.mm-doc { 
    padding: 16px 8px; background: #f8fafc; border-radius: 12px; text-align: center; 
    cursor: pointer; border: 2px solid transparent; transition: all .2s; 
}
.mm-doc:hover { transform: translateY(-2px); box-shadow: 0 4px 12px rgba(0,0,0,.08); }
.mm-doc.has-file { background: linear-gradient(135deg, #ecfdf5, #d1fae5); border-color: #a7f3d0; }
.mm-doc-icon { 
    width: 48px; height: 48px; border-radius: 12px; background: #fff; 
    display: flex; align-items: center; justify-content: center; margin: 0 auto 10px; font-size: 20px; 
}
.mm-doc.has-file .mm-doc-icon { background: #059669; color: #fff; }
.mm-doc-name { font-size: 11px; font-weight: 700; color: #334155; margin-bottom: 4px; }
.mm-doc-status { font-size: 10px; color: #64748b; }
.mm-doc.has-file .mm-doc-status { color: #059669; font-weight: 600; }

/* Info Rows */
.mm-info-row { display: flex; justify-content: space-between; align-items: center; padding: 12px 0; border-bottom: 1px solid #f1f5f9; }
.mm-info-row:last-child { border-bottom: none; }
.mm-info-label { font-size: 13px; color: #64748b; font-weight: 500; }
.mm-info-value { font-size: 14px; color: #1e293b; font-weight: 600; text-align: right; max-width: 55%; }

/* Badges */
.mm-badges { display: flex; flex-wrap: wrap; gap: 8px; margin-bottom: 16px; }
.mm-badge { padding: 6px 14px; border-radius: 20px; font-size: 12px; font-weight: 600; }
.mm-badge-slate { background: #f1f5f9; color: #475569; }
.mm-badge-green { background: #d1fae5; color: #047857; }
.mm-badge-blue { background: #dbeafe; color: #1d4ed8; }

/* Role Grid */
.mm-roles { display: grid; grid-template-columns: repeat(4, 1fr); gap: 8px; }
.mm-role { text-align: center; padding: 14px 6px; border-radius: 12px; background: #f8fafc; }
.mm-role.active { background: linear-gradient(135deg, #d1fae5, #a7f3d0); }
.mm-role i { font-size: 20px; color: #94a3b8; margin-bottom: 6px; display: block; }
.mm-role.active i { color: #059669; }
.mm-role span { font-size: 10px; font-weight: 600; color: #64748b; }
.mm-role.active span { color: #047857; }

/* Form Styles */
.mm-form-group { margin-bottom: 16px; }
.mm-label { display: block; font-size: 11px; font-weight: 700; color: #475569; margin-bottom: 6px; text-transform: uppercase; letter-spacing: .5px; }
.mm-input { 
    width: 100%; padding: 12px 14px; border: 2px solid #e2e8f0; border-radius: 10px; 
    font-size: 14px; background: #f8fafc; transition: all .2s; 
}
.mm-input:focus { outline: none; border-color: #10b981; background: #fff; box-shadow: 0 0 0 3px rgba(16,185,129,.1); }
.mm-row { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
.mm-row-3 { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 12px; }

/* Checkboxes */
.mm-checks { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; }
.mm-check { 
    display: flex; align-items: center; gap: 12px; padding: 14px; 
    background: #f8fafc; border-radius: 12px; cursor: pointer; border: 2px solid transparent; transition: all .2s; 
}
.mm-check:hover { background: #f1f5f9; }
.mm-check:has(input:checked) { background: #ecfdf5; border-color: #a7f3d0; }
.mm-check input { width: 20px; height: 20px; accent-color: #10b981; }
.mm-check span { font-size: 14px; font-weight: 500; color: #334155; }

/* File Status */
.mm-file-ok { display: flex; align-items: center; gap: 6px; font-size: 12px; color: #059669; margin-bottom: 8px; font-weight: 600; }

/* Save Footer */
.mm-footer { 
    position: fixed; bottom: 0; left: 0; right: 0; padding: 16px; 
    background: #fff; border-top: 1px solid #e2e8f0; box-shadow: 0 -4px 12px rgba(0,0,0,.05); z-index: 30; 
}
.mm-save { 
    width: 100%; padding: 16px; background: linear-gradient(135deg, #059669, #10b981); 
    color: #fff; font-size: 15px; font-weight: 700; border: none; border-radius: 12px; 
    cursor: pointer; display: flex; align-items: center; justify-content: center; gap: 10px; transition: all .2s; 
}
.mm-save:hover { transform: translateY(-1px); box-shadow: 0 4px 12px rgba(5,150,105,.3); }
.mm-save:disabled { opacity: .7; cursor: not-allowed; transform: none; }
/* ── MOBILE RESPONSIVE ── */
@media (max-width: 768px) {
    .mm-wrap { font-size: 14px; }
    .mm-header { padding: 12px; }
    .mm-hdr-content { flex-direction: column; text-align: center; gap: 8px; }
    .mm-photo-area { width: 70px !important; height: 70px !important; }
    .mm-tabs { overflow-x: auto; -webkit-overflow-scrolling: touch; gap: 2px; flex-wrap: nowrap; }
    .mm-tab { white-space: nowrap; font-size: 0.7rem; padding: 6px 10px; flex-shrink: 0; }
    .mm-row, .mm-row-3, .mm-photos, .mm-docs, .mm-roles, .mm-checks {
        grid-template-columns: 1fr !important;
    }
    .mm-field label { font-size: 0.7rem; }
    .mm-field input, .mm-field select, .mm-field textarea {
        font-size: 16px !important; /* prevent iOS zoom */
        padding: 8px !important;
    }
    .mm-actions { flex-wrap: wrap; gap: 6px; }
    .mm-actions button { font-size: 0.8rem; flex: 1; min-width: 120px; }
}
</style>

<div class="mm-wrap">
    <!-- Header -->
    <div class="mm-header">
        <div class="mm-hdr-content">
            <div class="mm-avatar" onclick="openDocFullscreen('<?= fixPath($m['student_photo_path']) ?>')">
                <?php if (!empty($m['student_photo_path'])): ?>
                    <img src="<?= fixPath($m['student_photo_path']) ?>" alt="Photo">
                <?php else: ?>
                    <i class="fas fa-user"></i>
                <?php endif; ?>
            </div>
            <div class="mm-hdr-info">
                <div class="mm-hdr-name"><?= e($m['student_name'] . ' ' . $m['father_name']) ?></div>
                <div class="mm-hdr-id">
                    <?php if ($m['member_code']): ?>
                        <i class="fas fa-id-card"></i> <?= e($m['member_code']) ?>
                    <?php else: ?>
                        <i class="fas fa-hourglass-half"></i> Waiting for ID
                    <?php endif; ?>
                </div>
            </div>
            <span class="mm-status mm-status-<?= $m['status'] ?>"><?= ucfirst($m['status']) ?></span>
        </div>
    </div>

    <!-- Tabs - IDs MUST be: tab-preview, tab-edit -->
    <div class="mm-tabs">
        <button id="tab-preview" class="mm-tab border-emerald-600 text-emerald-700" onclick="switchManageTab('preview')">
            <i class="fas fa-eye"></i> Preview
        </button>
        <button id="tab-edit" class="mm-tab border-transparent text-slate-500" onclick="switchManageTab('edit')">
            <i class="fas fa-edit"></i> Edit
        </button>
    </div>

    <!-- Action Buttons -->
    <div class="mm-actions">
        <button class="mm-btn mm-btn-green" onclick="window.open('/admin/print_member.php?id=<?= $m['id'] ?>', '_blank', 'width=900,height=700')">
            <i class="fas fa-file-pdf"></i> Export PDF
        </button>
        <button class="mm-btn mm-btn-gray" onclick="window.print()">
            <i class="fas fa-print"></i> Print
        </button>
    </div>

    <!-- Content -->
    <div class="mm-content">
        <!-- PREVIEW PANEL - ID MUST be: view-preview -->
        <div id="view-preview" style="display: block;">
            <!-- Photos -->
            <div class="mm-card">
                <div class="mm-card-hdr">
                    <div class="mm-card-icon mm-icon-green"><i class="fas fa-camera"></i></div>
                    <div class="mm-card-title">Photos</div>
                </div>
                <div class="mm-card-body">
                    <div class="mm-photos">
                        <div class="mm-photo-wrap">
                            <div class="mm-photo-label">Student Photo</div>
                            <div class="mm-photo-box" onclick="openDocFullscreen('<?= fixPath($m['student_photo_path']) ?>')">
                                <?php if (!empty($m['student_photo_path'])): ?>
                                    <img src="<?= fixPath($m['student_photo_path']) ?>" alt="Student">
                                    <div class="mm-photo-overlay"><i class="fas fa-search-plus"></i></div>
                                <?php else: ?>
                                    <div class="mm-photo-empty"><i class="fas fa-user"></i><span>No Photo</span></div>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="mm-photo-wrap">
                            <div class="mm-photo-label">Guardian Photo</div>
                            <div class="mm-photo-box" onclick="openDocFullscreen('<?= fixPath($m['guardian_photo_path']) ?>')">
                                <?php if (!empty($m['guardian_photo_path'])): ?>
                                    <img src="<?= fixPath($m['guardian_photo_path']) ?>" alt="Guardian">
                                    <div class="mm-photo-overlay"><i class="fas fa-search-plus"></i></div>
                                <?php else: ?>
                                    <div class="mm-photo-empty"><i class="fas fa-user"></i><span>No Photo</span></div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Documents -->
            <div class="mm-card">
                <div class="mm-card-hdr">
                    <div class="mm-card-icon mm-icon-blue"><i class="fas fa-folder-open"></i></div>
                    <div class="mm-card-title">Documents</div>
                </div>
                <div class="mm-card-body">
                    <div class="mm-docs">
                        <?php 
                        $docs = [
                            'doc_school_records_path' => ['School Records', 'fa-graduation-cap', '#2563eb'],
                            'doc_spiritual_path' => ['Spiritual', 'fa-church', '#7c3aed'],
                            'doc_signed_form_path' => ['Signed Form', 'fa-file-signature', '#059669']
                        ];
                        foreach ($docs as $key => $info): 
                            $path = $m[$key] ?? '';
                            $has = !empty($path);
                            $viewAction = $has ? (isPDF($path) ? "window.open('".fixPath($path)."','_blank')" : "openDocFullscreen('".fixPath($path)."')") : '';
                        ?>
                        <div class="mm-doc <?= $has ? 'has-file' : '' ?>" onclick="<?= $viewAction ?>">
                            <div class="mm-doc-icon" style="<?= !$has ? 'color:'.$info[2] : '' ?>">
                                <i class="fas <?= $info[1] ?>"></i>
                            </div>
                            <div class="mm-doc-name"><?= $info[0] ?></div>
                            <div class="mm-doc-status">
                                <?= $has ? '<i class="fas fa-check-circle"></i> View' : 'Not uploaded' ?>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>

            <!-- Personal Info -->
            <div class="mm-card">
                <div class="mm-card-hdr">
                    <div class="mm-card-icon mm-icon-green"><i class="fas fa-user"></i></div>
                    <div class="mm-card-title">Personal Information</div>
                </div>
                <div class="mm-card-body">
                    <div class="mm-info-row"><span class="mm-info-label">Full Name</span><span class="mm-info-value"><?= e($m['student_name']) ?></span></div>
                    <div class="mm-info-row"><span class="mm-info-label">Father Name</span><span class="mm-info-value"><?= e($m['father_name']) ?></span></div>
                    <div class="mm-info-row"><span class="mm-info-label">Grandfather</span><span class="mm-info-value"><?= esc($m['grandfather_name'], '—') ?></span></div>
                    <div class="mm-info-row"><span class="mm-info-label">Baptismal Name</span><span class="mm-info-value"><?= esc($m['baptismal_name'], '—') ?></span></div>
                    <div class="mm-info-row"><span class="mm-info-label">Gender</span><span class="mm-info-value"><?= $m['gender'] === 'male' ? 'ወንድ (Male)' : 'ሴት (Female)' ?></span></div>
                    <div class="mm-info-row"><span class="mm-info-label">Date of Birth</span><span class="mm-info-value"><?= $dobDisplay ?: '—' ?></span></div>
                    <div class="mm-info-row"><span class="mm-info-label">Age</span><span class="mm-info-value"><?= $m['age'] ? $m['age'] . ' years' : '—' ?></span></div>
                    <div class="mm-info-row"><span class="mm-info-label">Section</span><span class="mm-info-value"><?= esc($m['current_section'], '—') ?></span></div>
                    <div class="mm-info-row"><span class="mm-info-label">Education</span><span class="mm-info-value"><?= ucfirst(str_replace('_', ' ', $m['education_level'] ?: '—')) ?></span></div>
                    <div class="mm-info-row"><span class="mm-info-label">Spiritual Education</span><span class="mm-info-value" style="color: #059669; font-weight: 700;">
                        <?php 
                        $spEduLabels = [
                            'grade_1' => '1ኛ ክፍል', 'grade_2' => '2ኛ ክፍል', 'grade_3' => '3ኛ ክፍል',
                            'grade_4' => '4ኛ ክፍል', 'grade_5' => '5ኛ ክፍል', 'grade_6' => '6ኛ ክፍል',
                            'grade_7' => '7ኛ ክፍል', 'grade_8' => '8ኛ ክፍል', 'grade_9' => '9ኛ ክፍል',
                            'grade_10' => '10ኛ ክፍል', 'grade_11' => '11ኛ ክፍል', 'grade_12' => '12ኛ ክፍል',
                            'diploma' => 'ዲፕሎማ', 'degree' => 'ዲግሪ'
                        ];
                        echo $spEduLabels[$m['spiritual_education'] ?? ''] ?? ($m['spiritual_education'] ?: '—');
                        ?>
                    </span></div>
                    <div class="mm-info-row"><span class="mm-info-label">Profession</span><span class="mm-info-value"><?= esc($m['work_profession'], '—') ?></span></div>
                </div>
            </div>

            <!-- Contact -->
            <div class="mm-card">
                <div class="mm-card-hdr">
                    <div class="mm-card-icon mm-icon-blue"><i class="fas fa-phone"></i></div>
                    <div class="mm-card-title">Contact Information</div>
                </div>
                <div class="mm-card-body">
                    <div class="mm-info-row"><span class="mm-info-label">Phone</span><span class="mm-info-value"><?= esc($m['phone_number'], '—') ?></span></div>
                    <div class="mm-info-row"><span class="mm-info-label">Alt Phone</span><span class="mm-info-value"><?= esc($m['alt_phone_number'], '—') ?></span></div>
                    <div class="mm-info-row"><span class="mm-info-label">Guardian</span><span class="mm-info-value"><?= esc($m['guardian_name'], '—') ?></span></div>
                    <div class="mm-info-row"><span class="mm-info-label">Guardian Phone</span><span class="mm-info-value"><?= esc($m['guardian_phone1'], '—') ?></span></div>
                </div>
            </div>

            <!-- Address -->
            <div class="mm-card">
                <div class="mm-card-hdr">
                    <div class="mm-card-icon mm-icon-red"><i class="fas fa-map-marker-alt"></i></div>
                    <div class="mm-card-title">Address</div>
                </div>
                <div class="mm-card-body">
                    <div class="mm-info-row"><span class="mm-info-label">City</span><span class="mm-info-value"><?= esc($m['city'], '—') ?></span></div>
                    <div class="mm-info-row"><span class="mm-info-label">Sub City</span><span class="mm-info-value"><?= esc($m['sub_city'], '—') ?></span></div>
                    <div class="mm-info-row"><span class="mm-info-label">Woreda</span><span class="mm-info-value"><?= esc($m['woreda'], '—') ?></span></div>
                    <div class="mm-info-row"><span class="mm-info-label">Mender</span><span class="mm-info-value"><?= esc($m['mender'], '—') ?></span></div>
                    <div class="mm-info-row"><span class="mm-info-label">Block No.</span><span class="mm-info-value"><?= esc($m['block_number'], '—') ?></span></div>
                    <div class="mm-info-row"><span class="mm-info-label">House No.</span><span class="mm-info-value"><?= esc($m['house_number'], '—') ?></span></div>
                </div>
            </div>

            <!-- Status & Roles -->
            <div class="mm-card">
                <div class="mm-card-hdr">
                    <div class="mm-card-icon mm-icon-purple"><i class="fas fa-id-badge"></i></div>
                    <div class="mm-card-title">Status & Roles</div>
                </div>
                <div class="mm-card-body">
                    <div class="mm-badges">
                        <span class="mm-badge mm-badge-slate"><?= ucfirst($m['registration_type']) ?></span>
                        <span class="mm-badge mm-badge-green"><?= ucfirst(str_replace('_', ' ', $m['member_type'])) ?></span>
                        <span class="mm-badge mm-badge-blue"><?= $m['current_section'] ?: $m['age_group'] ?></span>
                    </div>
                    <div class="mm-roles">
                        <?php 
                        $roles = [
                            ['is_teacher', 'Teacher', 'chalkboard-user'],
                            ['is_staff', 'Staff', 'user-tie'],
                            ['is_committee', 'Committee', 'users'],
                            ['is_volunteer', 'Volunteer', 'hand-holding-heart']
                        ];
                        foreach ($roles as $r): $active = !empty($m[$r[0]]); 
                        ?>
                        <div class="mm-role <?= $active ? 'active' : '' ?>">
                            <i class="fas fa-<?= $r[2] ?>"></i>
                            <span><?= $r[1] ?></span>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- EDIT PANEL - ID MUST be: view-edit -->
        <div id="view-edit" class="hidden" style="display: none;">
            <form id="editMemberForm" onsubmit="submitEditForm(event)" enctype="multipart/form-data">
                <input type="hidden" name="id" value="<?= $m['id'] ?>">

                <!-- Photos -->
                <div class="mm-card">
                    <div class="mm-card-hdr">
                        <div class="mm-card-icon mm-icon-green"><i class="fas fa-camera"></i></div>
                        <div class="mm-card-title">Photos</div>
                    </div>
                    <div class="mm-card-body">
                        <div class="mm-photos">
                            <div class="mm-photo-wrap">
                                <div class="mm-photo-label">Student Photo</div>
                                <div class="mm-photo-box" style="cursor: default;">
                                    <?php if (!empty($m['student_photo_path'])): ?>
                                        <img src="<?= fixPath($m['student_photo_path']) ?>" alt="Student">
                                    <?php else: ?>
                                        <div class="mm-photo-empty"><i class="fas fa-user"></i></div>
                                    <?php endif; ?>
                                </div>
                                <input type="file" name="student_photo" accept="image/*" class="mm-input" style="margin-top:10px;padding:8px;">
                            </div>
                            <div class="mm-photo-wrap">
                                <div class="mm-photo-label">Guardian Photo</div>
                                <div class="mm-photo-box" style="cursor: default;">
                                    <?php if (!empty($m['guardian_photo_path'])): ?>
                                        <img src="<?= fixPath($m['guardian_photo_path']) ?>" alt="Guardian">
                                    <?php else: ?>
                                        <div class="mm-photo-empty"><i class="fas fa-user"></i></div>
                                    <?php endif; ?>
                                </div>
                                <input type="file" name="guardian_photo" accept="image/*" class="mm-input" style="margin-top:10px;padding:8px;">
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Documents -->
                <div class="mm-card">
                    <div class="mm-card-hdr">
                        <div class="mm-card-icon mm-icon-blue"><i class="fas fa-folder-open"></i></div>
                        <div class="mm-card-title">Documents</div>
                    </div>
                    <div class="mm-card-body">
                        <div class="mm-form-group">
                            <label class="mm-label">School Records</label>
                            <?php if (!empty($m['doc_school_records_path'])): ?>
                                <div class="mm-file-ok"><i class="fas fa-check-circle"></i> File uploaded</div>
                            <?php endif; ?>
                            <input type="file" name="doc_school_records" accept="image/*,.pdf" class="mm-input" style="padding:10px;">
                        </div>
                        <div class="mm-form-group">
                            <label class="mm-label">Spiritual Records</label>
                            <?php if (!empty($m['doc_spiritual_path'])): ?>
                                <div class="mm-file-ok"><i class="fas fa-check-circle"></i> File uploaded</div>
                            <?php endif; ?>
                            <input type="file" name="doc_spiritual" accept="image/*,.pdf" class="mm-input" style="padding:10px;">
                        </div>
                        <div class="mm-form-group">
                            <label class="mm-label">Signed Form</label>
                            <?php if (!empty($m['doc_signed_form_path'])): ?>
                                <div class="mm-file-ok"><i class="fas fa-check-circle"></i> File uploaded</div>
                            <?php endif; ?>
                            <input type="file" name="doc_signed_form" accept="image/*,.pdf" class="mm-input" style="padding:10px;">
                        </div>
                    </div>
                </div>

                <!-- Status -->
                <div class="mm-card">
                    <div class="mm-card-hdr">
                        <div class="mm-card-icon mm-icon-amber"><i class="fas fa-cog"></i></div>
                        <div class="mm-card-title">Status & Type</div>
                    </div>
                    <div class="mm-card-body">
                        <div class="mm-form-group">
                            <label class="mm-label">Status</label>
                            <select name="status" class="mm-input">
                                <option value="active" <?= sel($m['status'], 'active') ?>>Active</option>
                                <option value="warning" <?= sel($m['status'], 'warning') ?>>Warning</option>
                                <option value="inactive" <?= sel($m['status'], 'inactive') ?>>Inactive</option>
                                <option value="archived" <?= sel($m['status'], 'archived') ?>>Archived</option>
                            </select>
                        </div>
                        <div class="mm-row">
                            <div class="mm-form-group">
                                <label class="mm-label">Registration Type</label>
                                <select name="registration_type" class="mm-input">
                                    <option value="waiting" <?= sel($m['registration_type'], 'waiting') ?>>Waiting</option>
                                    <option value="transfer" <?= sel($m['registration_type'], 'transfer') ?>>Transfer</option>
                                    <option value="direct" <?= sel($m['registration_type'], 'direct') ?>>Direct</option>
                                </select>
                            </div>
                            <div class="mm-form-group">
                                <label class="mm-label">Member Type</label>
                                <select name="member_type" class="mm-input">
                                    <option value="regular" <?= sel($m['member_type'], 'regular') ?>>Regular</option>
                                    <option value="special_regular" <?= sel($m['member_type'], 'special_regular') ?>>Special Regular</option>
                                    <option value="honorary" <?= sel($m['member_type'], 'honorary') ?>>Honorary</option>
                                </select>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Personal Info -->
                <div class="mm-card">
                    <div class="mm-card-hdr">
                        <div class="mm-card-icon mm-icon-green"><i class="fas fa-user"></i></div>
                        <div class="mm-card-title">Personal Information</div>
                    </div>
                    <div class="mm-card-body">
                        <div class="mm-form-group">
                            <label class="mm-label">Student Name *</label>
                            <input type="text" name="student_name" value="<?= e($m['student_name']) ?>" required class="mm-input">
                        </div>
                        <div class="mm-row">
                            <div class="mm-form-group">
                                <label class="mm-label">Father Name *</label>
                                <input type="text" name="father_name" value="<?= e($m['father_name']) ?>" required class="mm-input">
                            </div>
                            <div class="mm-form-group">
                                <label class="mm-label">Grandfather</label>
                                <input type="text" name="grandfather_name" value="<?= e($m['grandfather_name']) ?>" class="mm-input">
                            </div>
                        </div>
                        <div class="mm-form-group">
                            <label class="mm-label">Baptismal Name</label>
                            <input type="text" name="baptismal_name" value="<?= e($m['baptismal_name']) ?>" class="mm-input">
                        </div>
                        <div class="mm-row">
                            <div class="mm-form-group">
                                <label class="mm-label">Gender</label>
                                <select name="gender" class="mm-input">
                                    <option value="male" <?= sel($m['gender'], 'male') ?>>Male</option>
                                    <option value="female" <?= sel($m['gender'], 'female') ?>>Female</option>
                                </select>
                            </div>
                            <div class="mm-form-group">
                                <label class="mm-label">Education</label>
                                <select name="education_level" class="mm-input">
                                    <option value="">Select...</option>
                                    <option value="kg" <?= sel($m['education_level'], 'kg') ?>>KG</option>
                                    <option value="elementary" <?= sel($m['education_level'], 'elementary') ?>>Elementary (1-8)</option>
                                    <option value="high" <?= sel($m['education_level'], 'high') ?>>High School (9-12)</option>
                                    <option value="diploma" <?= sel($m['education_level'], 'diploma') ?>>Diploma</option>
                                    <option value="degree" <?= sel($m['education_level'], 'degree') ?>>Degree</option>
                                    <option value="masters" <?= sel($m['education_level'], 'masters') ?>>Masters</option>
                                    <option value="phd" <?= sel($m['education_level'], 'phd') ?>>PhD</option>
                                </select>
                            </div>
                            <div class="mm-form-group">
                                <label class="mm-label" style="color: #059669;">✝ Spiritual Education</label>
                                <select name="spiritual_education" class="mm-input" style="border-color: #10b981; background: #ecfdf5;">
                                    <option value="">Select Level...</option>
                                    <option value="grade_1" <?= sel($m['spiritual_education'], 'grade_1') ?>>1ኛ ክፍል (Grade 1)</option>
                                    <option value="grade_2" <?= sel($m['spiritual_education'], 'grade_2') ?>>2ኛ ክፍል (Grade 2)</option>
                                    <option value="grade_3" <?= sel($m['spiritual_education'], 'grade_3') ?>>3ኛ ክፍል (Grade 3)</option>
                                    <option value="grade_4" <?= sel($m['spiritual_education'], 'grade_4') ?>>4ኛ ክፍል (Grade 4)</option>
                                    <option value="grade_5" <?= sel($m['spiritual_education'], 'grade_5') ?>>5ኛ ክፍል (Grade 5)</option>
                                    <option value="grade_6" <?= sel($m['spiritual_education'], 'grade_6') ?>>6ኛ ክፍል (Grade 6)</option>
                                    <option value="grade_7" <?= sel($m['spiritual_education'], 'grade_7') ?>>7ኛ ክፍል (Grade 7)</option>
                                    <option value="grade_8" <?= sel($m['spiritual_education'], 'grade_8') ?>>8ኛ ክፍል (Grade 8)</option>
                                    <option value="grade_9" <?= sel($m['spiritual_education'], 'grade_9') ?>>9ኛ ክፍል (Grade 9)</option>
                                    <option value="grade_10" <?= sel($m['spiritual_education'], 'grade_10') ?>>10ኛ ክፍል (Grade 10)</option>
                                    <option value="grade_11" <?= sel($m['spiritual_education'], 'grade_11') ?>>11ኛ ክፍል (Grade 11)</option>
                                    <option value="grade_12" <?= sel($m['spiritual_education'], 'grade_12') ?>>12ኛ ክፍል (Grade 12)</option>
                                    <option value="diploma" <?= sel($m['spiritual_education'], 'diploma') ?>>ዲፕሎማ (Diploma)</option>
                                    <option value="degree" <?= sel($m['spiritual_education'], 'degree') ?>>ዲግሪ (Degree)</option>
                                </select>
                            </div>
                        </div>
                        <div class="mm-form-group">
                            <label class="mm-label">Date of Birth (EC)</label>
                            <div class="mm-row-3">
                                <input type="number" name="dob_day" value="<?= $m['dob_ec_day'] ?>" placeholder="Day" min="1" max="30" class="mm-input">
                                <input type="number" name="dob_month" value="<?= $m['dob_ec_month'] ?>" placeholder="Month" min="1" max="13" class="mm-input">
                                <input type="number" name="dob_year" value="<?= $m['dob_ec_year'] ?>" placeholder="Year" class="mm-input">
                            </div>
                        </div>
                        <div class="mm-form-group">
                            <label class="mm-label">Profession</label>
                            <input type="text" name="work_profession" value="<?= e($m['work_profession']) ?>" class="mm-input">
                        </div>
                    </div>
                </div>

                <!-- Contact -->
                <div class="mm-card">
                    <div class="mm-card-hdr">
                        <div class="mm-card-icon mm-icon-blue"><i class="fas fa-phone"></i></div>
                        <div class="mm-card-title">Contact</div>
                    </div>
                    <div class="mm-card-body">
                        <div class="mm-row">
                            <div class="mm-form-group">
                                <label class="mm-label">Phone</label>
                                <input type="tel" name="phone_number" value="<?= e($m['phone_number']) ?>" class="mm-input">
                            </div>
                            <div class="mm-form-group">
                                <label class="mm-label">Alt Phone</label>
                                <input type="tel" name="alt_phone_number" value="<?= e($m['alt_phone_number']) ?>" class="mm-input">
                            </div>
                        </div>
                        <div class="mm-form-group">
                            <label class="mm-label">Guardian Name</label>
                            <input type="text" name="guardian_name" value="<?= e($m['guardian_name']) ?>" class="mm-input">
                        </div>
                        <div class="mm-row">
                            <div class="mm-form-group">
                                <label class="mm-label">Guardian Phone 1</label>
                                <input type="tel" name="guardian_phone1" value="<?= e($m['guardian_phone1']) ?>" class="mm-input">
                            </div>
                            <div class="mm-form-group">
                                <label class="mm-label">Guardian Phone 2</label>
                                <input type="tel" name="guardian_phone2" value="<?= e($m['guardian_phone2']) ?>" class="mm-input">
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Address -->
                <div class="mm-card">
                    <div class="mm-card-hdr">
                        <div class="mm-card-icon mm-icon-red"><i class="fas fa-map-marker-alt"></i></div>
                        <div class="mm-card-title">Address</div>
                    </div>
                    <div class="mm-card-body">
                        <div class="mm-row">
                            <div class="mm-form-group">
                                <label class="mm-label">City</label>
                                <input type="text" name="city" value="<?= e($m['city']) ?>" class="mm-input">
                            </div>
                            <div class="mm-form-group">
                                <label class="mm-label">Sub City</label>
                                <input type="text" name="sub_city" value="<?= e($m['sub_city']) ?>" class="mm-input">
                            </div>
                        </div>
                        <div class="mm-row">
                            <div class="mm-form-group">
                                <label class="mm-label">Woreda</label>
                                <input type="text" name="woreda" value="<?= e($m['woreda']) ?>" class="mm-input">
                            </div>
                            <div class="mm-form-group">
                                <label class="mm-label">Mender</label>
                                <input type="text" name="mender" value="<?= e($m['mender']) ?>" class="mm-input">
                            </div>
                        </div>
                        <div class="mm-row">
                            <div class="mm-form-group">
                                <label class="mm-label">Block No.</label>
                                <input type="text" name="block_number" value="<?= e($m['block_number']) ?>" class="mm-input">
                            </div>
                            <div class="mm-form-group">
                                <label class="mm-label">House No.</label>
                                <input type="text" name="house_number" value="<?= e($m['house_number']) ?>" class="mm-input">
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Roles -->
                <div class="mm-card">
                    <div class="mm-card-hdr">
                        <div class="mm-card-icon mm-icon-purple"><i class="fas fa-user-tag"></i></div>
                        <div class="mm-card-title">Roles</div>
                    </div>
                    <div class="mm-card-body">
                        <div class="mm-checks">
                            <label class="mm-check"><input type="checkbox" name="is_teacher" value="1" <?= chk($m['is_teacher']) ?>><span>Teacher</span></label>
                            <label class="mm-check"><input type="checkbox" name="is_staff" value="1" <?= chk($m['is_staff']) ?>><span>Staff</span></label>
                            <label class="mm-check"><input type="checkbox" name="is_committee" value="1" <?= chk($m['is_committee']) ?>><span>Committee</span></label>
                            <label class="mm-check"><input type="checkbox" name="is_volunteer" value="1" <?= chk($m['is_volunteer']) ?>><span>Volunteer</span></label>
                        </div>
                    </div>
                </div>

                <!-- Save Footer -->
                <div class="mm-footer">
                    <button type="submit" class="mm-save"><i class="fas fa-save"></i> Save Changes</button>
                </div>
            </form>
        </div>
    </div>
</div>
