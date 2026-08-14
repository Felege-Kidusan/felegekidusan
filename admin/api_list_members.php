<?php
/**
 * ============================================================
 * Members List API
 * Returns all NON-ARCHIVED members for the manage section
 * ============================================================
 */
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/config.php';

// Authentication check
if (empty($_SESSION['admin_id'])) {
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
    exit;
}

// CSRF validation for POST requests
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrfToken = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!validateCsrf($csrfToken)) {
        echo json_encode(['status' => 'error', 'message' => 'Security token expired. Please refresh.']);
        exit;
    }
}

// IMPORTANT: Exclude archived members - they are only shown in archive section
$sql = "SELECT 
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
    student_photo_path,
    created_at
FROM members
WHERE status != 'archived'
ORDER BY id DESC";

// Report the REAL error (a missing column etc.) instead of a generic
// message, so a failure is diagnosable instead of hanging the UI silently.
try {
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        echo json_encode(['status' => 'error', 'message' => 'Query prepare failed: ' . $conn->error]);
        exit;
    }
    $stmt->execute();
    $result = $stmt->get_result();
    $members = [];
    while ($row = $result->fetch_assoc()) {
        $members[] = $row;
    }
    $stmt->close();
    echo json_encode(['status' => 'success', 'members' => $members]);
} catch (Throwable $e) {
    error_log('api_list_members error: ' . $e->getMessage());
    echo json_encode(['status' => 'error', 'message' => 'Could not load members: ' . $e->getMessage()]);
}
