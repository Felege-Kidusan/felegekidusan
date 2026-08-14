<?php
/**
 * School API v1 — Members Routes
 * GET    /members              — List members (paginated, searchable, filterable)
 * GET    /members/{id}         — Get single member with full details
 * POST   /members              — Register new member
 * PUT    /members/{id}         — Update member
 * GET    /members/{id}/attendance — Get member's attendance history
 */

$auth = apiRequireAuth();
$id = $ROUTE['id'];
$sub = $ROUTE['sub'];

// ============================================================
// GET /members — List all members (paginated)
// ============================================================
if ($method === 'GET' && $id === null) {
    list($page, $limit, $offset) = getPagination(100);
    
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
        $where[] = "(student_name LIKE ? OR father_name LIKE ? OR member_code LIKE ? OR phone_number LIKE ? OR full_name_am LIKE ?)";
        $s = "%{$search}%";
        $params = array_merge($params, [$s, $s, $s, $s, $s]);
        $types .= 'sssss';
    }
    
    $whereSql = implode(' AND ', $where);
    
    $orderBy = match($sort) {
        'newest' => 'created_at DESC',
        'oldest' => 'created_at ASC',
        'code' => 'member_code ASC',
        default => 'student_name ASC'
    };
    
    // Count total
    $countStmt = $conn->prepare("SELECT COUNT(*) as total FROM members WHERE {$whereSql}");
    if ($types) $countStmt->bind_param($types, ...$params);
    $countStmt->execute();
    $total = (int)$countStmt->get_result()->fetch_assoc()['total'];
    $countStmt->close();
    
    // Fetch page
    $sql = "SELECT id, member_code, student_name, father_name, grandfather_name, 
                   full_name_am, gender, date_of_birth, age_group, status, member_type,
                   current_section, phone_number, phone_primary, registration_type,
                   student_photo_path, created_at
            FROM members WHERE {$whereSql} ORDER BY {$orderBy} LIMIT {$limit} OFFSET {$offset}";
    $stmt = $conn->prepare($sql);
    if ($types) $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $members = [];
    while ($row = $result->fetch_assoc()) {
        // Clean up photo path for API response
        if ($row['student_photo_path']) {
            $row['photo_url'] = SITE_URL . '/' . ltrim($row['student_photo_path'], '/');
        } else {
            $row['photo_url'] = null;
        }
        $members[] = $row;
    }
    $stmt->close();
    
    paginated($members, $total, $page, $limit);
}

// ============================================================
// GET /members/{id} — Get single member with full details
// ============================================================
if ($method === 'GET' && is_int($id) && $sub === null) {
    $stmt = $conn->prepare("SELECT * FROM members WHERE id = ?");
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $member = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    
    if (!$member) err('Member not found', 404);
    
    // Add photo URL
    $member['photo_url'] = $member['student_photo_path'] 
        ? SITE_URL . '/' . ltrim($member['student_photo_path'], '/') 
        : null;
    
    // Get class enrollment if education tables exist
    try {
        $year = getCurrentAcademicYear();
        if ($year) {
            $stmt = $conn->prepare("SELECT c.class_name, c.class_name_en, c.class_code, ce.enrolled_at, ce.status 
                                    FROM class_enrollments ce JOIN classes c ON ce.class_id = c.id 
                                    WHERE ce.member_id = ? AND ce.academic_year_id = ? AND ce.status = 'active' LIMIT 1");
            $stmt->bind_param('ii', $id, $year['id']);
            $stmt->execute();
            $member['current_class'] = $stmt->get_result()->fetch_assoc() ?: null;
            $stmt->close();
        }
    } catch (Exception $e) { $member['current_class'] = null; }
    
    // Get groups
    try {
        $stmt = $conn->prepare("SELECT g.id, g.group_name FROM wbws_group_members gm 
                                JOIN wbws_groups g ON gm.group_id = g.id WHERE gm.member_id = ?");
        if ($stmt) {
            $stmt->bind_param('i', $id);
            $stmt->execute();
            $groups = [];
            $r = $stmt->get_result();
            while ($row = $r->fetch_assoc()) $groups[] = $row;
            $member['groups'] = $groups;
            $stmt->close();
        }
    } catch (Exception $e) { $member['groups'] = []; }
    
    ok($member);
}

// ============================================================
// GET /members/{id}/attendance — Member's attendance history
// ============================================================
if ($method === 'GET' && is_int($id) && $sub === 'attendance') {
    $days = min(365, max(7, (int)($_GET['days'] ?? 90)));
    $since = date('Y-m-d', strtotime("-{$days} days"));
    
    $stmt = $conn->prepare("SELECT attendance_date, status, notes, check_in_time 
                            FROM attendance WHERE member_id = ? AND attendance_date >= ? 
                            ORDER BY attendance_date DESC");
    $stmt->bind_param('is', $id, $since);
    $stmt->execute();
    $records = [];
    $r = $stmt->get_result();
    while ($row = $r->fetch_assoc()) $records[] = $row;
    $stmt->close();
    
    // Calculate stats
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
// POST /members — Register new member
// ============================================================
if ($method === 'POST' && $id === null) {
    // Only info_dept, school_admin, super_admin can register
    apiRequireRole($auth, ['info_dept', 'school_admin', 'super_admin']);
    
    $input = getBody();
    
    // Required fields
    $studentName = trim($input['student_name'] ?? '');
    $fatherName = trim($input['father_name'] ?? '');
    $gender = validateEnum($input['gender'] ?? '', ['male', 'female'], '');
    
    if (!$studentName || !$fatherName || !$gender) {
        err('student_name, father_name, and gender are required.');
    }
    
    // Optional fields
    $grandfatherName = trim($input['grandfather_name'] ?? '');
    $fullNameEn = trim($input['full_name_en'] ?? '');
    $christianName = trim($input['christian_name'] ?? '');
    $baptismalName = trim($input['baptismal_name'] ?? '');
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
    
    // Date of birth (Ethiopian)
    $dobDay = !empty($input['dob_ec_day']) ? (int)$input['dob_ec_day'] : null;
    $dobMonth = !empty($input['dob_ec_month']) ? (int)$input['dob_ec_month'] : null;
    $dobYear = !empty($input['dob_ec_year']) ? (int)$input['dob_ec_year'] : null;
    $dateOfBirth = validateDate($input['date_of_birth'] ?? '', null);
    
    // Build full Amharic name
    $fullNameAm = trim($studentName . ' ' . $fatherName . ' ' . $grandfatherName);
    
    // Generate member code
    $memberCode = null;
    try {
        $r = $conn->query("SELECT MAX(CAST(SUBSTRING(member_code, 5) AS UNSIGNED)) as max_num FROM members WHERE member_code LIKE 'WB-%'");
        $maxNum = $r ? (int)($r->fetch_assoc()['max_num'] ?? 0) : 0;
        $memberCode = 'WB-' . str_pad($maxNum + 1, 4, '0', STR_PAD_LEFT);
    } catch (Exception $e) {
        $memberCode = 'WB-' . str_pad(rand(1000, 9999), 4, '0', STR_PAD_LEFT);
    }
    
    $createdBy = $auth['uid'];
    
    $stmt = $conn->prepare("INSERT INTO members (
        member_code, student_name, father_name, grandfather_name, full_name_am, full_name_en,
        christian_name, baptismal_name, gender, date_of_birth, dob_ec_day, dob_ec_month, dob_ec_year,
        age_group, current_section, education_level, member_type, registration_type, status,
        phone_number, phone_primary, alt_phone_number, guardian_name, guardian_phone1, guardian_phone2,
        address, city, sub_city, woreda, created_by
    ) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
    
    if (!$stmt) err('Database error: ' . $conn->error, 500);
    
    $stmt->bind_param('ssssssssssiiissssssssssssssssi',
        $memberCode, $studentName, $fatherName, $grandfatherName, $fullNameAm, $fullNameEn,
        $christianName, $baptismalName, $gender, $dateOfBirth, $dobDay, $dobMonth, $dobYear,
        $ageGroup, $section, $educationLevel, $memberType, $regType, $status,
        $phone, $phonePrimary, $altPhone, $guardianName, $guardianPhone1, $guardianPhone2,
        $address, $city, $subCity, $woreda, $createdBy
    );
    
    if (!$stmt->execute()) {
        err('Failed to register member: ' . $stmt->error, 500);
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
// PUT /members/{id} — Update member
// ============================================================
if ($method === 'PUT' && is_int($id)) {
    apiRequireRole($auth, ['info_dept', 'school_admin', 'super_admin']);
    
    // Verify member exists
    $stmt = $conn->prepare("SELECT id FROM members WHERE id = ?");
    $stmt->bind_param('i', $id);
    $stmt->execute();
    if (!$stmt->get_result()->fetch_assoc()) err('Member not found', 404);
    $stmt->close();
    
    $input = getBody();
    
    // Build dynamic UPDATE — only update fields that are provided
    $allowedFields = [
        'student_name' => 's', 'father_name' => 's', 'grandfather_name' => 's',
        'full_name_en' => 's', 'christian_name' => 's', 'baptismal_name' => 's',
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
    
    // Update full_name_am if name components changed
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
    if (!$stmt) err('Database error: ' . $conn->error, 500);
    $stmt->bind_param($types, ...$params);
    
    if (!$stmt->execute()) {
        err('Failed to update member: ' . $stmt->error, 500);
    }
    $stmt->close();
    
    logApiAction($auth['uid'], $auth['usr'], 'Member Updated', "ID: {$id}");
    
    ok(['message' => 'Member updated successfully', 'id' => $id]);
}

err("No handler for {$method} /members" . ($id ? "/{$id}" : '') . ($sub ? "/{$sub}" : ''), 404);
