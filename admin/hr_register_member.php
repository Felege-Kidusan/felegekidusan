<?php
/**
 * ============================================================
 * Member Registration API
 * ============================================================
 * POST /admin/hr_register_member.php
 *
 * Member codes use a bounded, high-entropy generator with a unique lookup.
 * No sequential MAX query or fixed 90,000-code ceiling.
 * ============================================================
 */

ob_start();
header('Content-Type: application/json; charset=utf-8');
require __DIR__ . '/config.php';
require_once __DIR__ . '/backend/services/MemberFileService.php';
require_once __DIR__ . '/backend/services/MemberCategory.php';
require_once __DIR__ . '/backend/services/IdentityCodeService.php';
require_once __DIR__ . '/backend/services/MemberRegistrationPolicy.php';
require_once __DIR__ . '/backend/services/MemberDuplicateService.php';
require_once __DIR__ . '/backend/services/ApiIdempotencyService.php';
require_once __DIR__ . '/backend/services/SecurityAuditService.php';

$_ethDateLoaded = false;
if (file_exists(__DIR__ . '/backend/ethiopian_date.php')) {
    try { require_once __DIR__ . '/backend/ethiopian_date.php'; $_ethDateLoaded = function_exists('ethio_date_format'); }
    catch (Throwable $e) { error_log('Registration: ethiopian_date load: ' . $e->getMessage()); }
}

$_stray = ob_get_clean();
if ($_stray) error_log('Registration: stray output: ' . substr($_stray, 0, 200));

// ── Clean JSON response helper ──
function jsonExit($data, $code = 200) {
    $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    if ($json === false) {
        $json = '{"status":"error","message":"Response encoding failed."}';
        $code = 500;
    }
    $idempotency = $GLOBALS['_member_registration_idempotency'] ?? null;
    unset($GLOBALS['_member_registration_idempotency']);
    if (is_array($idempotency)
        && ($idempotency['service'] ?? null) instanceof \App\Services\ApiIdempotencyService
        && is_array($idempotency['reservation'] ?? null)) {
        if (($data['status'] ?? '') === 'success' && $code >= 200 && $code < 300) {
            $idempotency['service']->complete($idempotency['reservation'], $json, $code);
        } else {
            $idempotency['service']->abandon($idempotency['reservation']);
        }
    }
    while (ob_get_level() > 0) ob_end_clean();
    if (!headers_sent()) { header('Content-Type: application/json; charset=utf-8'); http_response_code($code); }
    echo $json;
    exit;
}

/** @return mixed */
function registrationCanonicalize($value) {
    if (!is_array($value)) return $value;
    $keys = array_keys($value);
    if ($keys !== range(0, count($keys) - 1)) ksort($value, SORT_STRING);
    foreach ($value as $key => $item) $value[$key] = registrationCanonicalize($item);
    return $value;
}

function beginRegistrationIdempotency(\mysqli $conn, int $userId): void {
    $keyRaw = $_POST['registration_request_id'] ?? '';
    if (!is_scalar($keyRaw)) {
        jsonExit(['status' => 'error', 'message' => 'A valid registration request ID is required. Refresh and retry.'], 400);
    }
    $key = trim((string)$keyRaw);
    if (strlen($key) < 16 || strlen($key) > 80 || !preg_match('/^[A-Za-z0-9._-]+$/', $key)) {
        jsonExit(['status' => 'error', 'message' => 'A valid registration request ID is required. Refresh and retry.'], 400);
    }
    $payload = $_POST;
    // CSRF rotation and the UI's post-advisory override flag do not change the
    // underlying registration operation represented by this request ID.
    unset(
        $payload['csrf_token'],
        $payload['duplicate_override'],
        $payload['duplicate_override_reason']
    );
    $files = [];
    foreach ($_FILES as $field => $file) {
        if (!is_array($file)) continue;
        $name = $file['name'] ?? '';
        $size = $file['size'] ?? 0;
        $error = $file['error'] ?? UPLOAD_ERR_NO_FILE;
        $tmpName = $file['tmp_name'] ?? '';
        $meta = [
            'name' => is_scalar($name) ? basename((string)$name) : '[multiple]',
            'size' => is_scalar($size) ? (int)$size : 0,
            'error' => is_scalar($error) ? (int)$error : UPLOAD_ERR_NO_FILE,
        ];
        $tmp = is_scalar($tmpName) ? (string)$tmpName : '';
        if ($meta['error'] === UPLOAD_ERR_OK && $tmp !== '' && is_uploaded_file($tmp)) {
            $meta['sha256'] = hash_file('sha256', $tmp) ?: '';
        }
        $files[$field] = $meta;
    }
    $canonical = json_encode(
        registrationCanonicalize(['payload' => $payload, 'files' => $files]),
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE
    );
    $requestHash = hash('sha256', 'POST\0/admin/hr_register_member.php\0' . (string)$canonical);
    $service = new \App\Services\ApiIdempotencyService(
        $conn,
        ROOT_PATH . '/admin/uploads/cache'
    );
    $result = $service->begin($userId, $key, 'POST /admin/hr_register_member.php', $requestHash);
    $state = (string)($result['state'] ?? 'unavailable');
    if ($state === 'acquired') {
        $GLOBALS['_member_registration_idempotency'] = ['service' => $service, 'reservation' => $result];
        return;
    }
    if ($state === 'replay') {
        while (ob_get_level() > 0) ob_end_clean();
        http_response_code((int)($result['status_code'] ?? 200));
        header('Content-Type: application/json; charset=utf-8');
        header('Idempotency-Replayed: true');
        echo (string)($result['body'] ?? '');
        exit;
    }
    if ($state === 'conflict') {
        jsonExit(['status' => 'error', 'message' => 'This registration request ID was reused for different data.'], 409);
    }
    if ($state === 'processing') {
        header('Retry-After: ' . max(1, (int)($result['retry_after'] ?? 1)));
        jsonExit(['status' => 'error', 'message' => 'This registration is already processing.'], 409);
    }
    jsonExit(['status' => 'error', 'message' => 'Safe registration retry is temporarily unavailable.'], 503);
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

try {
    $registrationPolicy = \App\Services\MemberRegistrationPolicy::prepare(
        $_POST,
        (string)($_SESSION['admin_role'] ?? '')
    );
} catch (InvalidArgumentException $error) {
    jsonExit(['status' => 'error', 'message' => $error->getMessage()], 422);
}
$isQuickAdd = $registrationPolicy['quick_add'];
beginRegistrationIdempotency($conn, (int)$_SESSION['admin_id']);

// ── POST field helper ──
function field($n, $d = '') {
    global $isQuickAdd;
    if ($isQuickAdd && !in_array($n, [
        'full_name_am', 'gender', 'phone_number', 'current_section',
        'registration_type',
    ], true)) {
        return $d;
    }
    return isset($_POST[$n]) ? trim((string)$_POST[$n]) : $d;
}


// ╔══════════════════════════════════════════════════════════╗
// ║  1. COLLECT & VALIDATE FORM DATA                        ║
// ╚══════════════════════════════════════════════════════════╝

$registration_type = $registrationPolicy['registration_type'];
$member_type       = $registrationPolicy['member_type'];
$status            = $registrationPolicy['status'];

// --- Single Full Name input (required). Split into parts for DB compatibility. ---
$full_name_input  = $registrationPolicy['full_name_am'];
$baptismal_name   = field('baptismal_name');
$membership_tier  = $registrationPolicy['membership_tier'];

// Only Full Name is required
$errors = [];
if ($full_name_input === '') {
    $errors[] = "Full Name is required.";
}
if (!empty($errors)) {
    jsonExit(['status' => 'error', 'message' => implode("\n", $errors)]);
}

// Split full name into parts (space-separated: First Father Grandfather)
$nameParts        = preg_split('/\s+/', $full_name_input, 3);
$student_name     = $nameParts[0] ?? '';
$father_name      = $nameParts[1] ?? '';
$grandfather_name = $nameParts[2] ?? '';

// Build full name from the single input
$full_name_am = $full_name_input;
$full_name_en = null;
$gender       = $registrationPolicy['gender'];

// DOB & Age (all optional)
$dob_day   = (int) field('dob_day', 0);
$dob_month = (int) field('dob_month', 0);
$dob_year  = (int) field('dob_year', 0);
$date_of_birth   = null;
$age             = null;
// Auto-age sectioning DISABLED by design — section is set explicitly by HR
$age_group       = field('age_group') ?: null;
$current_section = $registrationPolicy['current_section'] ?: null;

if ($dob_year > 0) {
    if ($_ethDateLoaded) {
        try {
            $currentYearEC = (int) ethio_date_format(new DateTime('now', new DateTimeZone('Africa/Addis_Ababa')), 'Y');
            $age = max(0, $currentYearEC - $dob_year);
        } catch (Throwable $e) {
            error_log('Registration: date calc error: ' . $e->getMessage());
        }
    }
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
$phone_number     = $registrationPolicy['phone_number'];
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
$is_teacher     = !$isQuickAdd && isset($_POST['is_teacher']) ? 1 : 0;
$is_staff       = !$isQuickAdd && isset($_POST['is_staff']) ? 1 : 0;
$is_committee   = !$isQuickAdd && isset($_POST['is_committee']) ? 1 : 0;
$is_volunteer   = !$isQuickAdd && isset($_POST['is_volunteer']) ? 1 : 0;
$is_dept_head_1 = !$isQuickAdd && isset($_POST['is_dept_head_1']) ? 1 : 0;
$is_dept_head_2 = !$isQuickAdd && isset($_POST['is_dept_head_2']) ? 1 : 0;
$is_dept_head_3 = !$isQuickAdd && isset($_POST['is_dept_head_3']) ? 1 : 0;
$is_dept_head_4 = !$isQuickAdd && isset($_POST['is_dept_head_4']) ? 1 : 0;
$is_dept_head_5 = !$isQuickAdd && isset($_POST['is_dept_head_5']) ? 1 : 0;
$is_dept_head_6 = !$isQuickAdd && isset($_POST['is_dept_head_6']) ? 1 : 0;
$is_dept_head_7 = !$isQuickAdd && isset($_POST['is_dept_head_7']) ? 1 : 0;
$is_dept_head_8 = !$isQuickAdd && isset($_POST['is_dept_head_8']) ? 1 : 0;

// ── Member Code ──
$member_code_form = field('student_id');
$waiting_since = null;
$member_code   = null;

if ($registration_type === 'waiting') {
    $member_code   = null;
    $waiting_since = date('Y-m-d');
} else {
    // Ministry coding: students get {CategoryLetter}{Sequential} (A1, B4,
    // C12…). The letter derives from the manually assigned age group; the
    // retired fourth category normalizes onto ህጻናት. Staff codes are issued
    // by the Super Admin Identity hub when positions are assigned — never
    // guessed at registration time.
    $letter = \App\Services\MemberCategory::letterFor($age_group)
        ?? \App\Services\MemberCategory::LETTER_A;
    $member_code = \App\Services\IdentityCodeService::allocateStudent($conn, $letter);
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

function saveUploadedFile($fieldName) {
    $result = \App\Services\MemberFileService::storeRequestUpload($fieldName);
    return $result['error'] !== null ? ['error' => $result['error']] : $result['path'];
}

if ($registrationPolicy['allow_uploads']) {
    $student_photo_path      = saveUploadedFile('student_photo');
    $guardian_photo_path     = saveUploadedFile('guardian_photo');
    $doc_school_records_path = saveUploadedFile('doc_school_records');
    $doc_spiritual_path      = saveUploadedFile('doc_spiritual');
    $doc_signed_form_path    = saveUploadedFile('doc_signed_form');
} else {
    $student_photo_path = $guardian_photo_path = $doc_school_records_path = null;
    $doc_spiritual_path = $doc_signed_form_path = null;
}

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
    foreach ([$student_photo_path, $guardian_photo_path, $doc_school_records_path, $doc_spiritual_path, $doc_signed_form_path] as $uploadedPath) {
        if (is_string($uploadedPath)) \App\Services\MemberFileService::discard($uploadedPath);
    }
    jsonExit(['status' => 'error', 'message' => "Upload error:\n" . implode("\n", $uploadErrors)]);
}


// ╔══════════════════════════════════════════════════════════╗
// ║  3. DATABASE INSERT                                     ║
// ╚══════════════════════════════════════════════════════════╝

$conn->begin_transaction();
$filesCommitted = false;
$duplicateIdentityLock = null;

try {
    $data = [
        'member_code'          => $member_code,
        'registration_type'    => $registration_type,
        'member_type'          => $member_type,
        'status'               => $status,
        'membership_tier'      => $membership_tier,
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

    $upgrade_id = $registrationPolicy['allow_upgrade']
        ? (int)field('upgrade_member_id', 0)
        : 0;
    $newId = 0;
    $duplicateOverrideRaw = $_POST['duplicate_override'] ?? '';
    $duplicateOverrideRequested = $registrationPolicy['allow_upgrade']
        && is_scalar($duplicateOverrideRaw)
        && (string)$duplicateOverrideRaw === '1';
    $duplicateIdentityLock = \App\Services\MemberDuplicateService::acquireStrongIdentityLock(
        $conn,
        $data,
        5
    );
    $strongDuplicate = \App\Services\MemberDuplicateService::findStrongMatch(
        $conn,
        $data,
        $upgrade_id,
        true
    );
    if ($strongDuplicate !== null && !$duplicateOverrideRequested) {
        throw new \App\Services\DuplicateMemberException($strongDuplicate);
    }
    $duplicateOverride = $strongDuplicate !== null && $duplicateOverrideRequested;
    $duplicateOverrideReason = null;
    if ($duplicateOverride) {
        $reasonRaw = $_POST['duplicate_override_reason'] ?? '';
        $duplicateOverrideReason = is_scalar($reasonRaw) ? trim((string)$reasonRaw) : '';
        if ($duplicateOverrideReason === '' || strlen($duplicateOverrideReason) > 500
            || preg_match('//u', $duplicateOverrideReason) !== 1) {
            throw new InvalidArgumentException('A valid duplicate override reason is required.');
        }
    }

    if ($upgrade_id > 0) {
        // Remove fields we don't want to overwrite during upgrade
        unset($data['registered_at']);
        unset($data['waiting_since']);
        unset($data['member_code']);
        unset($data['registration_type']);
        
        $setClauses = [];
        $vals = [];
        $types = '';
        foreach ($data as $col => $v) {
            // If it's a file upload field and null, don't overwrite existing file unless requested
            if (str_ends_with($col, '_path') && $v === null && empty($_POST['replace_'.$col])) continue;
            
            $setClauses[] = "$col = ?";
            if ($v === null)    { $types .= 's'; $vals[] = null; }
            elseif (is_int($v)) { $types .= 'i'; $vals[] = $v; }
            else                { $types .= 's'; $vals[] = (string)$v; }
        }
        
        $types .= 'i';
        $vals[] = $upgrade_id;
        
        $sql = "UPDATE members SET " . implode(', ', $setClauses) . " WHERE id = ?";
        $stmt = $conn->prepare($sql);
        if (!$stmt) throw new Exception('Prepare failed: ' . $conn->error);
        $stmt->bind_param($types, ...$vals);
        if (!$stmt->execute()) throw new Exception('Execute failed: ' . $stmt->error);
        
        $newId = $upgrade_id;
        $stmt->close();
    } else {
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
    }

    if (!\App\Services\SecurityAuditService::record(
        $conn,
        $upgrade_id > 0 ? 'Member Registration Updated' : 'Member Registered',
        [
            'profile' => $isQuickAdd ? 'quick_add' : 'full',
            'registration_type' => $registration_type,
            'membership_tier' => $membership_tier,
            'duplicate_override' => $duplicateOverride,
            'duplicate_match_id' => $duplicateOverride ? (int)$strongDuplicate['id'] : null,
            'duplicate_override_reason' => $duplicateOverride ? $duplicateOverrideReason : null,
        ],
        'member',
        $newId
    )) {
        throw new RuntimeException('Registration audit recording failed.');
    }

    $conn->commit();
    \App\Services\MemberDuplicateService::releaseIdentityLock($conn, $duplicateIdentityLock);
    $duplicateIdentityLock = null;
    $filesCommitted = true;

    // Optional class assignment — never fail the registration if this misses.
    $enrollNote = '';
    $classId = (int) field('class_id', 0);
    if ($classId > 0 && $newId > 0) {
        try {
            require_once __DIR__ . '/backend/services/EnrollmentService.php';
            $enr = \App\Services\EnrollmentService::enroll(
                $conn,
                (int)$newId,
                $classId,
                null,
                (int)($_SESSION['admin_id'] ?? 0)
            );
            if (($enr['status'] ?? '') === 'success' && empty($enr['skipped'])) {
                $enrollNote = ' Enrolled in class.';
            } elseif (($enr['status'] ?? '') !== 'success') {
                $enrollNote = ' Saved without class (' . ($enr['message'] ?? 'enrollment skipped') . ').';
                error_log('HR register enroll: ' . ($enr['message'] ?? 'unknown'));
            }
        } catch (Throwable $e) {
            error_log('HR register enroll error: ' . $e->getMessage());
            $enrollNote = ' Saved without class.';
        }
    }

    // Post-registration workflow (non-fatal)
    try {
        if (file_exists(__DIR__ . '/backend/workflow.php')) {
            require_once __DIR__ . '/backend/workflow.php';
            if (function_exists('onMemberRegistered')) onMemberRegistered($conn, $newId);
        }
    } catch (Throwable $e) { error_log("Registration workflow error: " . $e->getMessage()); }

    jsonExit([
        'status'      => 'success',
        'message'     => 'Member registered successfully! Code: ' . ($member_code ?? 'Pending') . $enrollNote,
        'member_id'   => $newId,
        'member_code' => $member_code
    ]);

} catch (Throwable $e) {
    try { $conn->rollback(); } catch (Throwable $r) {}
    \App\Services\MemberDuplicateService::releaseIdentityLock($conn, $duplicateIdentityLock);
    $duplicateIdentityLock = null;
    if (!$filesCommitted) {
        foreach ([$student_photo_path, $guardian_photo_path, $doc_school_records_path, $doc_spiritual_path, $doc_signed_form_path] as $uploadedPath) {
            if (is_string($uploadedPath)) \App\Services\MemberFileService::discard($uploadedPath);
        }
    }
    error_log("Registration FAILED: " . $e->getMessage() . " | " . $e->getFile() . ":" . $e->getLine());

    if ($e instanceof InvalidArgumentException) {
        jsonExit(['status' => 'error', 'message' => $e->getMessage()], 422);
    }
    if ($e instanceof \App\Services\DuplicateRegistrationBusyException) {
        header('Retry-After: 2');
        jsonExit([
            'status' => 'error',
            'message' => 'A matching registration is already processing. Wait briefly and retry.',
        ], 409);
    }
    if ($e instanceof \App\Services\DuplicateMemberException) {
        if (!\App\Services\SecurityAuditService::record(
            $conn,
            'Member Registration Duplicate Blocked',
            ['profile' => $isQuickAdd ? 'quick_add' : 'full'],
            'member',
            (int)$e->member['id']
        )) {
            error_log('Could not audit blocked duplicate registration.');
        }
        jsonExit([
            'status' => 'duplicate',
            'message' => 'A strongly matching member already exists. Review that record or explicitly authorize a duplicate.',
            'match' => $e->member,
        ], 409);
    }

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
