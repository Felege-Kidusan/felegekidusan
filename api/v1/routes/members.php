<?php
/**
 * School API v1 — Members
 * GET    /members                 — paginated list (Info/Admin: +phones; Education: name/code only)
 * GET    /members/{id}            — one member (field set depends on role)
 * POST   /members                 — register (Info / School Admin / Super Admin)
 * PUT    /members/{id}            — update (same roles)
 * GET    /members/{id}/attendance — attendance history (assigned class or PII role)
 */

$auth = apiRequireAuth();
$id = $ROUTE['id'];
$sub = $ROUTE['sub'];
$year = getCurrentAcademicYear();
$yearId = $year ? (int)$year['id'] : 0;

// ============================================================
// GET /members — List
// ============================================================
if ($method === 'GET' && $id === null) {
    if (!apiCanBrowseMembers($auth)) {
        err('Members list is only for Information and Education staff.', 403);
    }
    if (isApiRateLimited('members_list', 60)) {
        err('Too many requests. Please wait a moment.', 429);
    }

    list($page, $limit, $offset) = getPagination(50);
    $canPii = apiCanViewMemberPii($auth);

    $search = trim($_GET['search'] ?? '');
    $status = validateEnum($_GET['status'] ?? '', ['active', 'warning', 'inactive', 'archived'], '');
    $gender = validateEnum($_GET['gender'] ?? '', ['male', 'female'], '');
    $section = trim($_GET['section'] ?? '');
    $ageGroup = trim($_GET['age_group'] ?? '');
    $sort = validateEnum($_GET['sort'] ?? '', ['name', 'newest', 'oldest', 'code'], 'name');

    $where = ["status != 'archived'"];
    $params = [];
    $types = '';

    if ($status) { $where[] = "status = ?"; $params[] = $status; $types .= 's'; }
    if ($gender) { $where[] = "gender = ?"; $params[] = $gender; $types .= 's'; }
    if ($section) { $where[] = "current_section = ?"; $params[] = $section; $types .= 's'; }
    if ($ageGroup) { $where[] = "age_group = ?"; $params[] = $ageGroup; $types .= 's'; }
    if ($search) {
        if ($canPii) {
            $where[] = "(student_name LIKE ? OR father_name LIKE ? OR member_code LIKE ? OR phone_number LIKE ? OR full_name_am LIKE ?)";
            $s = "%{$search}%";
            $params = array_merge($params, [$s, $s, $s, $s, $s]);
            $types .= 'sssss';
        } else {
            $where[] = "(student_name LIKE ? OR father_name LIKE ? OR member_code LIKE ? OR full_name_am LIKE ? OR baptismal_name LIKE ?)";
            $s = "%{$search}%";
            $params = array_merge($params, [$s, $s, $s, $s, $s]);
            $types .= 'sssss';
        }
    }

    $whereSql = implode(' AND ', $where);
    $orderBy = match ($sort) {
        'newest' => 'created_at DESC',
        'oldest' => 'created_at ASC',
        'code' => 'member_code ASC',
        default => 'student_name ASC'
    };

    $countStmt = $conn->prepare("SELECT COUNT(*) as total FROM members WHERE {$whereSql}");
    if ($types) $countStmt->bind_param($types, ...$params);
    $countStmt->execute();
    $total = (int)$countStmt->get_result()->fetch_assoc()['total'];
    $countStmt->close();

    $sql = "SELECT id, member_code, student_name, father_name, grandfather_name,
                   full_name_am, gender, age_group, status, member_type,
                   current_section, student_photo_path, created_at,
                   phone_number, phone_primary
            FROM members WHERE {$whereSql} ORDER BY {$orderBy} LIMIT {$limit} OFFSET {$offset}";
    $stmt = $conn->prepare($sql);
    if ($types) $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $result = $stmt->get_result();

    $members = [];
    while ($row = $result->fetch_assoc()) {
        $row['photo_url'] = apiPhotoUrl($row['student_photo_path'] ?? null);
        unset($row['student_photo_path']);
        $members[] = $canPii ? apiMemberStaffRow($row) : apiMemberSafeRow($row);
    }
    $stmt->close();

    paginated($members, $total, $page, $limit);
}

// ============================================================
// GET /members/{id}
// ============================================================
if ($method === 'GET' && is_int($id) && $sub === null) {
    $canPii = apiCanViewMemberPii($auth);
    $canEdu = apiRoleIs($auth, apiRolesEducation());
    $canTeacher = apiIsClassRestricted($auth);

    if (!$canPii && !$canEdu && !$canTeacher) {
        err('You cannot open member records.', 403);
    }
    if ($canTeacher && !$canPii && !$canEdu && !apiTeacherCanSeeMember($conn, $auth, $id, $yearId)) {
        err('You can only open students in your assigned classes.', 403);
    }

    $stmt = $conn->prepare(
        "SELECT id, member_code, student_name, father_name, grandfather_name, full_name_am,
                gender, date_of_birth, age_group, status, member_type, current_section,
                education_level, registration_type, baptismal_name, christian_name,
                phone_number, phone_primary, alt_phone_number,
                guardian_name, guardian_phone1, guardian_phone2,
                address, city, sub_city, woreda, student_photo_path, created_at
         FROM members WHERE id = ?"
    );
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $member = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$member) err('Member not found', 404);

    $member['photo_url'] = apiPhotoUrl($member['student_photo_path'] ?? null);
    unset($member['student_photo_path']);

    try {
        if ($year) {
            $stmt = $conn->prepare(
                "SELECT c.class_name, c.class_name_en, c.class_code, ce.enrolled_at, ce.status
                 FROM class_enrollments ce JOIN classes c ON ce.class_id = c.id
                 WHERE ce.member_id = ? AND ce.academic_year_id = ? AND ce.status = 'active' LIMIT 1"
            );
            $stmt->bind_param('ii', $id, $year['id']);
            $stmt->execute();
            $member['current_class'] = $stmt->get_result()->fetch_assoc() ?: null;
            $stmt->close();
        }
    } catch (Exception $e) {
        $member['current_class'] = null;
    }

    ok($canPii ? apiMemberStaffRow($member) : apiMemberSafeRow($member));
}

// ============================================================
// GET /members/{id}/attendance
// ============================================================
if ($method === 'GET' && is_int($id) && $sub === 'attendance') {
    $canPii = apiCanViewMemberPii($auth);
    $canEdu = apiRoleIs($auth, apiRolesEducation());
    $canTeacher = apiIsClassRestricted($auth);
    if (!$canPii && !$canEdu && !($canTeacher && apiTeacherCanSeeMember($conn, $auth, $id, $yearId))) {
        err('You cannot view this attendance history.', 403);
    }

    $days = min(365, max(7, (int)($_GET['days'] ?? 90)));
    $since = date('Y-m-d', strtotime("-{$days} days"));

    $stmt = $conn->prepare(
        "SELECT attendance_date, status, notes, check_in_time
         FROM attendance WHERE member_id = ? AND attendance_date >= ?
         ORDER BY attendance_date DESC"
    );
    $stmt->bind_param('is', $id, $since);
    $stmt->execute();
    $records = [];
    $r = $stmt->get_result();
    while ($row = $r->fetch_assoc()) $records[] = $row;
    $stmt->close();

    $total = count($records);
    $present = count(array_filter($records, fn($r) => $r['status'] === 'present'));
    $absent = count(array_filter($records, fn($r) => $r['status'] === 'absent'));
    $late = count(array_filter($records, fn($r) => $r['status'] === 'late'));
    $rate = $total > 0 ? round($present / $total * 100, 1) : 0;

    ok([
        'member_id' => $id,
        'days_covered' => $days,
        'stats' => [
            'total_days' => $total,
            'present' => $present,
            'absent' => $absent,
            'late' => $late,
            'attendance_rate' => $rate
        ],
        'records' => $records
    ]);
}

// ============================================================
// POST /members — Register
// ============================================================
if ($method === 'POST' && $id === null) {
    apiRequireRole($auth, apiRolesPii());

    $input = getBody();

    $studentName = trim($input['student_name'] ?? '');
    $fatherName = trim($input['father_name'] ?? '');
    $gender = validateEnum($input['gender'] ?? '', ['male', 'female'], '');

    if (!$studentName || !$fatherName || !$gender) {
        err('student_name, father_name, and gender are required.');
    }

    $grandfatherName = trim($input['grandfather_name'] ?? '');
    $fullNameEn = trim($input['full_name_en'] ?? '');
    $baptismalName = trim($input['baptismal_name'] ?? $input['christian_name'] ?? '');
    $phone = trim($input['phone_number'] ?? '');
    $phonePrimary = trim($input['phone_primary'] ?? '');
    $altPhone = trim($input['alt_phone_number'] ?? '');
    $guardianName = trim($input['guardian_name'] ?? '');
    $guardianPhone1 = trim($input['guardian_phone1'] ?? '');
    $guardianPhone2 = trim($input['guardian_phone2'] ?? '');
    $address = trim($input['address'] ?? '');
    $city = trim($input['city'] ?? '');
    $subCity = trim($input['sub_city'] ?? '');
    $woreda = trim($input['woreda'] ?? '');
    $section = trim($input['current_section'] ?? '');
    $educationLevel = trim($input['education_level'] ?? '');
    $memberType = validateEnum($input['member_type'] ?? '', ['regular', 'special_regular', 'honorary'], 'regular');
    $regType = validateEnum($input['registration_type'] ?? '', ['waiting', 'transfer', 'direct'], 'waiting');
    $status = validateEnum($input['status'] ?? '', ['active', 'warning', 'inactive'], 'active');
    $ageGroup = $input['age_group'] ?? null;
    if ($ageGroup === '') $ageGroup = null;

    $dobDay = !empty($input['dob_ec_day']) ? (int)$input['dob_ec_day'] : null;
    $dobMonth = !empty($input['dob_ec_month']) ? (int)$input['dob_ec_month'] : null;
    $dobYear = !empty($input['dob_ec_year']) ? (int)$input['dob_ec_year'] : null;
    $dateOfBirth = validateDate($input['date_of_birth'] ?? '', null);

    $fullNameAm = trim($studentName . ' ' . $fatherName . ' ' . $grandfatherName);

    require_once __DIR__ . '/../../../admin/backend/services/EnrollmentService.php';
    $memberCode = \App\Services\EnrollmentService::generateMemberCode($conn, $ageGroup);

    $createdBy = $auth['uid'];

    $stmt = $conn->prepare("INSERT INTO members (
        member_code, student_name, father_name, grandfather_name, full_name_am, full_name_en,
        baptismal_name, gender, date_of_birth, dob_ec_day, dob_ec_month, dob_ec_year,
        age_group, current_section, education_level, member_type, registration_type, status,
        phone_number, phone_primary, alt_phone_number, guardian_name, guardian_phone1, guardian_phone2,
        address, city, sub_city, woreda, created_by
    ) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");

    if (!$stmt) {
        reportInternalError('API member statement preparation failed', $conn->error);
        err('Member storage is temporarily unavailable.', 500);
    }

    $stmt->bind_param('sssssssssiiissssssssssssssssi',
        $memberCode, $studentName, $fatherName, $grandfatherName, $fullNameAm, $fullNameEn,
        $baptismalName, $gender, $dateOfBirth, $dobDay, $dobMonth, $dobYear,
        $ageGroup, $section, $educationLevel, $memberType, $regType, $status,
        $phone, $phonePrimary, $altPhone, $guardianName, $guardianPhone1, $guardianPhone2,
        $address, $city, $subCity, $woreda, $createdBy
    );

    if (!$stmt->execute()) {
        reportInternalError('API member registration failed', $stmt->error);
        err('Unable to register the member.', 500);
    }

    $newId = $conn->insert_id;
    $stmt->close();

    logApiAction($auth['uid'], $auth['usr'], 'Member Registered', "ID: {$newId}, Name: {$studentName} {$fatherName}");

    ok([
        'message' => 'Member registered successfully',
        'id' => $newId,
        'member_code' => $memberCode,
    ], 201);
}

// ============================================================
// PUT /members/{id}
// ============================================================
if ($method === 'PUT' && is_int($id)) {
    apiRequireRole($auth, apiRolesPii());

    $stmt = $conn->prepare("SELECT id FROM members WHERE id = ?");
    $stmt->bind_param('i', $id);
    $stmt->execute();
    if (!$stmt->get_result()->fetch_assoc()) err('Member not found', 404);
    $stmt->close();

    $input = getBody();
    if (array_key_exists('christian_name', $input) && !array_key_exists('baptismal_name', $input)) {
        $input['baptismal_name'] = $input['christian_name'];
    }

    $allowedFields = [
        'student_name' => 's', 'father_name' => 's', 'grandfather_name' => 's',
        'full_name_en' => 's', 'baptismal_name' => 's',
        'gender' => 's', 'date_of_birth' => 's', 'age_group' => 's',
        'current_section' => 's', 'education_level' => 's', 'member_type' => 's',
        'status' => 's', 'phone_number' => 's', 'phone_primary' => 's',
        'alt_phone_number' => 's', 'guardian_name' => 's', 'guardian_phone1' => 's',
        'guardian_phone2' => 's', 'address' => 's', 'city' => 's', 'sub_city' => 's',
        'woreda' => 's', 'work_profession' => 's', 'emergency_name' => 's',
        'emergency_phone' => 's',
        'dob_ec_day' => 'i', 'dob_ec_month' => 'i', 'dob_ec_year' => 'i',
    ];

    $sets = [];
    $params = [];
    $types = '';

    foreach ($allowedFields as $field => $type) {
        if (array_key_exists($field, $input)) {
            $val = $input[$field];
            if ($val === '' || $val === null) $val = null;
            $sets[] = "`{$field}` = ?";
            $params[] = $val;
            $types .= $type;
        }
    }

    if (isset($input['student_name']) || isset($input['father_name']) || isset($input['grandfather_name'])) {
        $sn = trim($input['student_name'] ?? '');
        $fn = trim($input['father_name'] ?? '');
        $gn = trim($input['grandfather_name'] ?? '');
        if ($sn || $fn) {
            $sets[] = "full_name_am = ?";
            $params[] = trim("{$sn} {$fn} {$gn}");
            $types .= 's';
        }
    }

    if (empty($sets)) {
        err('No fields to update. Send at least one field.');
    }

    $params[] = $id;
    $types .= 'i';

    $sql = "UPDATE members SET " . implode(', ', $sets) . " WHERE id = ?";
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        reportInternalError('API member statement preparation failed', $conn->error);
        err('Member storage is temporarily unavailable.', 500);
    }
    $stmt->bind_param($types, ...$params);

    if (!$stmt->execute()) {
        reportInternalError('API member update failed', $stmt->error);
        err('Unable to update the member.', 500);
    }
    $stmt->close();

    logApiAction($auth['uid'], $auth['usr'], 'Member Updated', "ID: {$id}");

    ok(['message' => 'Member updated successfully', 'id' => $id]);
}

err("No handler for {$method} /members" . ($id ? "/{$id}" : '') . ($sub ? "/{$sub}" : ''), 404);
