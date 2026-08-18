<?php
/**
 * Members List API — paginated, filterable.
 */
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/config.php';

if (empty($_SESSION['admin_id'])) {
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrfToken = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!validateCsrf($csrfToken)) {
        echo json_encode(['status' => 'error', 'message' => 'Security token expired. Please refresh.']);
        exit;
    }
}

$q = trim((string)($_GET['q'] ?? $_POST['q'] ?? ''));
$regType = trim((string)($_GET['registration_type'] ?? ''));
$memberType = trim((string)($_GET['member_type'] ?? ''));
$status = trim((string)($_GET['status'] ?? ''));
$gender = trim((string)($_GET['gender'] ?? ''));
$ageGroup = trim((string)($_GET['age_group'] ?? ''));
$page = max(1, (int)($_GET['page'] ?? 1));
$limit = min(200, max(1, (int)($_GET['limit'] ?? 50)));
$offset = ($page - 1) * $limit;

$w = ["status != 'archived'"];
$p = [];
$t = '';

if ($status !== '' && in_array($status, ['active', 'warning', 'inactive', 'archived'], true)) {
    $w = ['status = ?'];
    $p[] = $status;
    $t .= 's';
}
if ($regType !== '' && in_array($regType, ['waiting', 'transfer', 'direct'], true)) {
    $w[] = 'registration_type = ?';
    $p[] = $regType;
    $t .= 's';
}
if ($memberType !== '' && in_array($memberType, ['regular', 'special_regular', 'honorary'], true)) {
    $w[] = 'member_type = ?';
    $p[] = $memberType;
    $t .= 's';
}
if ($gender !== '' && in_array($gender, ['male', 'female'], true)) {
    $w[] = 'gender = ?';
    $p[] = $gender;
    $t .= 's';
}
if ($ageGroup !== '' && in_array($ageGroup, ['under6', '7_13', '14_17', '18_plus'], true)) {
    $w[] = 'age_group = ?';
    $p[] = $ageGroup;
    $t .= 's';
}
if ($q !== '') {
    $w[] = '(student_name LIKE ? OR father_name LIKE ? OR grandfather_name LIKE ? OR member_code LIKE ? OR baptismal_name LIKE ? OR phone_number LIKE ? OR work_profession LIKE ? OR city LIKE ?)';
    $st = '%' . $q . '%';
    array_push($p, $st, $st, $st, $st, $st, $st, $st, $st);
    $t .= 'ssssssss';
}

$wc = implode(' AND ', $w);

try {
    $countSql = "SELECT COUNT(*) AS total FROM members WHERE $wc";
    $stmt = $conn->prepare($countSql);
    if (!$stmt) {
        echo json_encode(['status' => 'error', 'message' => 'Query prepare failed: ' . $conn->error]);
        exit;
    }
    if ($t !== '') {
        $stmt->bind_param($t, ...$p);
    }
    $stmt->execute();
    $total = (int)($stmt->get_result()->fetch_assoc()['total'] ?? 0);
    $stmt->close();

    $sql = "SELECT
        id, member_code, registration_type, member_type, status, age_group, current_section,
        student_name, father_name, grandfather_name, baptismal_name, gender,
        phone_number, alt_phone_number, guardian_name, guardian_phone1, guardian_phone2,
        city, sub_city, woreda, mender, block_number, house_number,
        work_profession, education_level, student_photo_path, created_at
    FROM members
    WHERE $wc
    ORDER BY id DESC
    LIMIT ? OFFSET ?";

    $fp = $p;
    $ft = $t . 'ii';
    $fp[] = $limit;
    $fp[] = $offset;
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        echo json_encode(['status' => 'error', 'message' => 'Query prepare failed: ' . $conn->error]);
        exit;
    }
    $stmt->bind_param($ft, ...$fp);
    $stmt->execute();
    $result = $stmt->get_result();
    $members = [];
    while ($row = $result->fetch_assoc()) {
        $members[] = $row;
    }
    $stmt->close();

    echo json_encode([
        'status' => 'success',
        'members' => $members,
        'total' => $total,
        'page' => $page,
        'limit' => $limit,
        'pages' => $limit > 0 ? (int)ceil($total / $limit) : 1,
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('api_list_members error: ' . $e->getMessage());
    echo json_encode(['status' => 'error', 'message' => 'Could not load members.']);
}
