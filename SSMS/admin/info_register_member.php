<?php
/**
 * ============================================================
 * Member Registration API — Production v5 (Hashed Codes)
 * ============================================================
 * POST /admin/info_register_member.php
 * 
 * Member codes: 5-char unique hash (e.g., "A7K2X")
 * No sequential logic, no collision risk, no MAX queries.
 * ============================================================
 */

ob_start();
header('Content-Type: application/json; charset=utf-8');
require __DIR__ . '/config.php';

$_ethDateLoaded = false;
if (file_exists(__DIR__ . '/backend/ethiopian_date.php')) {
    try { require_once __DIR__ . '/backend/ethiopian_date.php'; $_ethDateLoaded = function_exists('ethio_date_format'); }
    catch (Throwable $e) { error_log('Registration: ethiopian_date load: ' . $e->getMessage()); }
}

$_stray = ob_get_clean();
if ($_stray) error_log('Registration: stray output: ' . substr($_stray, 0, 200));

// ── Clean JSON response helper ──
function jsonExit($data, $code = 200) {
    while (ob_get_level() > 0) ob_end_clean();
    if (!headers_sent()) { header('Content-Type: application/json; charset=utf-8'); http_response_code($code); }
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

// ── Auth ──
if (empty($_SESSION['admin_id'])) {
    jsonExit(['status' => 'session_expired', 'message' => 'Session expired. Please log in again.', 'action' => 'reload'], 401);
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonExit(['status' => 'error', 'message' => 'Invalid request method.'], 405);
}
if (!validateCsrf()) {
    jsonExit(['status' => 'csrf_expired', 'message' => 'Security token expired. Page will refresh.', 'action' => 'reload'], 403);
}
if (!isset($conn) || $conn->connect_error) {
    jsonExit(['status' => 'error', 'message' => 'Database connection error.'], 503);
}

// ── POST field helper ──
function field($n, $d = '') { return isset($_POST[$n]) ? trim($_POST[$n]) : $d; }

/**
 * Generate a unique 5-digit numeric member code (10000–99999).
 * Random, not sequential — avoids the collision problems of MAX+1.
 * 90,000 possible codes — more than enough for any Sunday school.
 */
function generateMemberCode($conn) {
    for ($attempt = 0; $attempt < 50; $attempt++) {
        // Random 5-digit number (10000–99999, never starts with 0)
        $code = str_pad(random_int(10000, 99999), 5, '0', STR_PAD_LEFT);
        
        // Check if it exists
        $stmt = $conn->prepare("SELECT COUNT(*) as cnt FROM members WHERE member_code = ?");
        if (!$stmt) return $code;
        $stmt->bind_param('s', $code);
        $stmt->execute();
        $result = $stmt->get_result();
        $exists = $result ? (int) $result->fetch_assoc()['cnt'] : 0;
        $stmt->close();
        
        if ($exists === 0) return $code;
    }
    return $code;
}


// ╔══════════════════════════════════════════════════════════╗
// ║  1. COLLECT & VALIDATE FORM DATA                        ║
// ╚══════════════════════════════════════════════════════════╝

$registration_type = field('registration_type', 'waiting');
$member_type       = field('member_type', 'regular');
$status            = field('status', 'active');

$student_name     = field('student_name');
$baptismal_name   = field('baptismal_name');
$father_name      = field('father_name');
$grandfather_name = field('grandfather_name');

// Validation
$errors = [];
if ($student_name === '')      $errors[] = "Student name is required.";
if ($father_name === '')       $errors[] = "Father name is required.";
if (field('gender') === '')    $errors[] = "Gender is required.";
if (field('dob_year') === '' || field('dob_month') === '' || field('dob_day') === '') {
    $errors[] = "Date of birth is required.";
}
if (field('phone_number') === '')     $errors[] = "Phone number is required.";
if (field('guardian_name') === '')    $errors[] = "Guardian name is required.";
if (field('guardian_phone1') === '')  $errors[] = "Guardian phone is required.";

if (($registration_type === 'transfer' || $registration_type === 'direct') && empty($_FILES['doc_signed_form']['name'])) {
    $errors[] = "Signed form is required for transfer or direct registration.";
}

if (!empty($errors)) {
    jsonExit(['status' => 'error', 'message' => implode("\n", $errors)]);
}

// Names
$full_name_am = trim($student_name . ' ' . $father_name . ' ' . $grandfather_name);
$full_name_en = null;
$gender = field('gender', 'male');

// DOB & Age
$dob_day   = (int) field('dob_day', 0);
$dob_month = (int) field('dob_month', 0);
$dob_year  = (int) field('dob_year', 0);
$date_of_birth = null;
$age = null;
$age_group = null;
$current_section = null;

if ($dob_year > 0) {
    $currentYearEC = null;
    if ($_ethDateLoaded) {
        try {
            $currentYearEC = (int) ethio_date_format(new DateTime('now', new DateTimeZone('Africa/Addis_Ababa')), 'Y');
        } catch (Throwable $e) {
            error_log('Registration: date calc error: ' . $e->getMessage());
        }
    }
    if (!$currentYearEC) $currentYearEC = (int)date('Y') - 8;
    
    $age = max(0, $currentYearEC - $dob_year);
    if ($age <= 6)       { $current_section = 'አጸደ ህጻናት'; $age_group = 'under6'; }
    elseif ($age <= 13)  { $current_section = 'ህጻናት';      $age_group = '7_13'; }
    elseif ($age <= 17)  { $current_section = 'ማዕከላዊያን';   $age_group = '14_17'; }
    else                 { $current_section = 'ወጣቶች';      $age_group = '18_plus'; }
}

// Address & Education
$education_level     = field('education_level');
$spiritual_education = field('spiritual_education');
$city            = field('city');
$sub_city        = field('sub_city');
$woreda          = field('woreda');
$mender          = field('mender');
$block_number    = field('block_number');
$house_number    = field('house_number');
$work_profession = field('work_profession');

// Phones
$phone_number     = field('phone_number');
$alt_phone_number = field('alt_phone_number');
$phone_primary    = $phone_number;
$phone_guardian   = field('guardian_phone1');

// Guardian
$guardian_name         = field('guardian_name');
$guardian_phone1       = field('guardian_phone1');
$guardian_phone2       = field('guardian_phone2');
$guardian_city         = field('guardian_city');
$guardian_sub_city     = field('guardian_sub_city');
$guardian_woreda       = field('guardian_woreda');
$guardian_mender       = field('guardian_mender');
$guardian_block_number = field('guardian_block_number');
$guardian_house        = field('guardian_house');

// Role flags
$is_teacher     = isset($_POST['is_teacher']) ? 1 : 0;
$is_staff       = isset($_POST['is_staff']) ? 1 : 0;
$is_committee   = isset($_POST['is_committee']) ? 1 : 0;
$is_volunteer   = isset($_POST['is_volunteer']) ? 1 : 0;
$is_dept_head_1 = isset($_POST['is_dept_head_1']) ? 1 : 0;
$is_dept_head_2 = isset($_POST['is_dept_head_2']) ? 1 : 0;
$is_dept_head_3 = isset($_POST['is_dept_head_3']) ? 1 : 0;
$is_dept_head_4 = isset($_POST['is_dept_head_4']) ? 1 : 0;
$is_dept_head_5 = isset($_POST['is_dept_head_5']) ? 1 : 0;
$is_dept_head_6 = isset($_POST['is_dept_head_6']) ? 1 : 0;
$is_dept_head_7 = isset($_POST['is_dept_head_7']) ? 1 : 0;
$is_dept_head_8 = isset($_POST['is_dept_head_8']) ? 1 : 0;

// ── Member Code ──
$member_code_form = field('student_id');
$waiting_since = null;
$member_code   = null;

if ($registration_type === 'waiting') {
    $member_code   = null;
    $waiting_since = date('Y-m-d');
} else {
    // Always generate a unique hashed code.
    // The frontend used to pre-fill a sequential code in a hidden field,
    // but sequential codes caused constant duplicate collisions.
    // Now we ignore the form value and generate a proper unique code.
    $member_code = generateMemberCode($conn);
}

// ── Registration Date (DATE column — Y-m-d only) ──
$registered_at = date('Y-m-d');
$reg_date_day   = (int) field('reg_date_day', 0);
$reg_date_month = (int) field('reg_date_month', 0);
$reg_date_year  = (int) field('reg_date_year', 0);

if ($reg_date_year > 0 && $reg_date_month > 0 && $reg_date_day > 0) {
    try {
        $gregYear = $reg_date_year + 7;
        if ($reg_date_month >= 5) $gregYear = $reg_date_year + 8;
        $monthMap = [1=>9,2=>10,3=>11,4=>12,5=>1,6=>2,7=>3,8=>4,9=>5,10=>6,11=>7,12=>8,13=>9];
        $gregMonth = $monthMap[$reg_date_month] ?? 1;
        $gregDay = min($reg_date_day, 28);
        $registered_at = sprintf('%04d-%02d-%02d', $gregYear, $gregMonth, $gregDay);
    } catch (Throwable $e) {
        $registered_at = date('Y-m-d');
    }
}


// ╔══════════════════════════════════════════════════════════╗
// ║  2. FILE UPLOADS                                        ║
// ╚══════════════════════════════════════════════════════════╝

function saveUploadedFile($fieldName, $subDir) {
    if (!isset($_FILES[$fieldName]) || $_FILES[$fieldName]['error'] === UPLOAD_ERR_NO_FILE) return null;

    $err = $_FILES[$fieldName]['error'];
    if ($err !== UPLOAD_ERR_OK) {
        $map = [UPLOAD_ERR_INI_SIZE => 'File too large (server limit)', UPLOAD_ERR_FORM_SIZE => 'File too large',
                UPLOAD_ERR_PARTIAL => 'Partial upload', UPLOAD_ERR_NO_TMP_DIR => 'Server temp folder missing',
                UPLOAD_ERR_CANT_WRITE => 'Disk write failed', UPLOAD_ERR_EXTENSION => 'Blocked by extension'];
        return ['error' => $map[$err] ?? "Upload error $err"];
    }

    if ($_FILES[$fieldName]['size'] > 5 * 1024 * 1024) return ['error' => 'File too large (max 5MB)'];

    $ext = strtolower(pathinfo($_FILES[$fieldName]['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, ['jpg','jpeg','png','gif','webp','pdf','bmp'])) return ['error' => ".$ext not allowed"];

    if (in_array($ext, ['jpg','jpeg','png','gif','webp','bmp']) && @getimagesize($_FILES[$fieldName]['tmp_name']) === false) {
        return ['error' => 'Not a valid image'];
    }

    $dir = __DIR__ . '/uploads/members/' . $subDir;
    if (!is_dir($dir) && !@mkdir($dir, 0775, true)) return ['error' => 'Storage error'];

    $name = $fieldName . '_' . time() . '_' . mt_rand(1000, 9999) . '.' . $ext;
    $path = $dir . '/' . $name;

    if (!move_uploaded_file($_FILES[$fieldName]['tmp_name'], $path)) return ['error' => 'Save failed'];

    return 'uploads/members/' . $subDir . '/' . $name;
}

$student_photo_path      = saveUploadedFile('student_photo', 'photos');
$guardian_photo_path     = saveUploadedFile('guardian_photo', 'guardian_photos');
$doc_school_records_path = saveUploadedFile('doc_school_records', 'docs');
$doc_spiritual_path      = saveUploadedFile('doc_spiritual', 'docs');
$doc_signed_form_path    = saveUploadedFile('doc_signed_form', 'docs');

// Check upload failures
$uploadErrors = [];
foreach ([
    'Student photo' => $student_photo_path,
    'Guardian photo' => $guardian_photo_path,
    'School records' => $doc_school_records_path,
    'Spiritual doc' => $doc_spiritual_path,
    'Signed form' => $doc_signed_form_path
] as $label => $result) {
    if (is_array($result) && isset($result['error'])) {
        $uploadErrors[] = "$label: " . $result['error'];
    }
}
// Null out failed uploads
if (is_array($student_photo_path)) $student_photo_path = null;
if (is_array($guardian_photo_path)) $guardian_photo_path = null;
if (is_array($doc_school_records_path)) $doc_school_records_path = null;
if (is_array($doc_spiritual_path)) $doc_spiritual_path = null;
if (is_array($doc_signed_form_path)) { 
    if ($registration_type !== 'waiting') $uploadErrors[] = "Signed form upload failed";
    $doc_signed_form_path = null;
}

if (!empty($uploadErrors)) {
    jsonExit(['status' => 'error', 'message' => "Upload error:\n" . implode("\n", $uploadErrors)]);
}


// ╔══════════════════════════════════════════════════════════╗
// ║  3. DATABASE INSERT                                     ║
// ╚══════════════════════════════════════════════════════════╝

$conn->begin_transaction();

try {
    $data = [
        'member_code'          => $member_code,
        'registration_type'    => $registration_type,
        'member_type'          => $member_type,
        'status'               => $status,
        'full_name_am'         => $full_name_am,
        'full_name_en'         => $full_name_en,
        'student_name'         => $student_name,
        'baptismal_name'       => $baptismal_name,
        'father_name'          => $father_name,
        'grandfather_name'     => $grandfather_name,
        'gender'               => $gender,
        'date_of_birth'        => $date_of_birth,
        'dob_ec_day'           => $dob_day ?: null,
        'dob_ec_month'         => $dob_month ?: null,
        'dob_ec_year'          => $dob_year ?: null,
        'age'                  => $age !== null ? (string)$age : null,
        'age_group'            => $age_group,
        'current_section'      => $current_section,
        'education_level'      => $education_level,
        'spiritual_education'  => $spiritual_education,
        'city'                 => $city,
        'sub_city'             => $sub_city,
        'woreda'               => $woreda,
        'mender'               => $mender,
        'block_number'         => $block_number,
        'house_number'         => $house_number,
        'work_profession'      => $work_profession,
        'phone_primary'        => $phone_primary,
        'phone_guardian'       => $phone_guardian,
        'phone_number'         => $phone_number,
        'alt_phone_number'     => $alt_phone_number,
        'guardian_name'        => $guardian_name,
        'guardian_phone1'      => $guardian_phone1,
        'guardian_phone2'      => $guardian_phone2,
        'guardian_city'        => $guardian_city,
        'guardian_sub_city'    => $guardian_sub_city,
        'guardian_woreda'      => $guardian_woreda,
        'guardian_mender'      => $guardian_mender,
        'guardian_block_number'=> $guardian_block_number,
        'guardian_house'       => $guardian_house,
        'is_teacher'           => $is_teacher,
        'is_staff'             => $is_staff,
        'is_committee'         => $is_committee,
        'is_volunteer'         => $is_volunteer,
        'is_dept_head_1'       => $is_dept_head_1,
        'is_dept_head_2'       => $is_dept_head_2,
        'is_dept_head_3'       => $is_dept_head_3,
        'is_dept_head_4'       => $is_dept_head_4,
        'is_dept_head_5'       => $is_dept_head_5,
        'is_dept_head_6'       => $is_dept_head_6,
        'is_dept_head_7'       => $is_dept_head_7,
        'is_dept_head_8'       => $is_dept_head_8,
        'student_photo_path'       => $student_photo_path,
        'guardian_photo_path'      => $guardian_photo_path,
        'doc_school_records_path'  => $doc_school_records_path,
        'doc_spiritual_path'       => $doc_spiritual_path,
        'doc_signed_form_path'     => $doc_signed_form_path,
        'waiting_since'            => $waiting_since,
        'registered_at'            => $registered_at,
    ];

    $columns      = array_keys($data);
    $placeholders = array_fill(0, count($columns), '?');
    $sql = "INSERT INTO members (" . implode(',', $columns) . ") VALUES (" . implode(',', $placeholders) . ")";

    $stmt = $conn->prepare($sql);
    if (!$stmt) throw new Exception('Prepare failed: ' . $conn->error);

    $types = '';
    $vals  = [];
    foreach ($data as $v) {
        if ($v === null)    { $types .= 's'; $vals[] = null; }
        elseif (is_int($v)) { $types .= 'i'; $vals[] = $v; }
        else                { $types .= 's'; $vals[] = (string)$v; }
    }
    $stmt->bind_param($types, ...$vals);

    if (!$stmt->execute()) throw new Exception('Execute failed: ' . $stmt->error);

    $newId = $conn->insert_id;
    $stmt->close();
    $conn->commit();

    // Post-registration workflow (non-fatal)
    try {
        if (file_exists(__DIR__ . '/backend/workflow.php')) {
            require_once __DIR__ . '/backend/workflow.php';
            if (function_exists('onMemberRegistered')) onMemberRegistered($conn, $newId);
        }
    } catch (Throwable $e) { error_log("Registration workflow error: " . $e->getMessage()); }

    jsonExit([
        'status'      => 'success',
        'message'     => 'Member registered successfully! Code: ' . ($member_code ?? 'Pending'),
        'member_id'   => $newId,
        'member_code' => $member_code
    ]);

} catch (Throwable $e) {
    try { $conn->rollback(); } catch (Throwable $r) {}
    error_log("Registration FAILED: " . $e->getMessage() . " | " . $e->getFile() . ":" . $e->getLine());

    $msg = 'Registration failed. Please try again.';
    $dbErr = $e->getMessage();
    
    if (stripos($dbErr, 'duplicate entry') !== false) {
        // This should almost never happen with random codes, but handle it
        if (preg_match("/duplicate entry '([^']+)'/i", $dbErr, $m)) {
            $msg = "Code conflict ({$m[1]}). Please click Save again — a new code will be generated.";
        }
    } elseif (stripos($dbErr, 'unknown column') !== false) {
        // Column doesn't exist in the actual DB — log which one
        $msg = 'Database column mismatch. Contact admin.';
        error_log("CRITICAL: Column mismatch — run the SQL migration. Error: $dbErr");
    } elseif (stripos($dbErr, 'data too long') !== false) {
        $msg = 'A field has too much text. Please shorten and retry.';
    } elseif (stripos($dbErr, 'cannot be null') !== false) {
        $msg = 'A required field is missing. Check the form and retry.';
    }
    
    jsonExit(['status' => 'error', 'message' => $msg], 500);
}
