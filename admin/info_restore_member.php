<?php
/**
 * Restore Member - Move member back from archive to active
 * AJAX endpoint
 */

header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/config.php';

// Check auth
if (empty($_SESSION['admin_username'])) {
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
    exit;
}

// Get JSON input
$input = json_decode(file_get_contents('php://input'), true);

// Validate CSRF token (from JSON body)
$csrfToken = $input['csrf_token'] ?? '';
if (empty($csrfToken) || empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $csrfToken)) {
    echo json_encode(['status' => 'error', 'message' => 'Security token expired. Please refresh and try again.']);
    exit;
}

$id = isset($input['id']) ? (int)$input['id'] : 0;

if ($id <= 0) {
    echo json_encode(['status' => 'error', 'message' => 'Invalid member ID']);
    exit;
}

// Verify member exists and is archived
$checkStmt = $conn->prepare("SELECT id, student_name, father_name, status FROM members WHERE id = ?");
$checkStmt->bind_param("i", $id);
$checkStmt->execute();
$result = $checkStmt->get_result();
$member = $result->fetch_assoc();
$checkStmt->close();

if (!$member) {
    echo json_encode(['status' => 'error', 'message' => 'Member not found']);
    exit;
}

if ($member['status'] !== 'archived') {
    echo json_encode(['status' => 'error', 'message' => 'Member is not in archive']);
    exit;
}

// Check which columns exist
$columns = [];
$colResult = $conn->query("SHOW COLUMNS FROM members");
while ($col = $colResult->fetch_assoc()) {
    $columns[] = $col['Field'];
}

// Build dynamic update query - restore to active and clear archive fields
$updateParts = ["status = 'active'"];
$params = [];
$types = '';

if (in_array('archived_at', $columns)) {
    $updateParts[] = "archived_at = NULL";
}
if (in_array('archived_by', $columns)) {
    $updateParts[] = "archived_by = NULL";
}
if (in_array('archive_reason', $columns)) {
    $updateParts[] = "archive_reason = NULL";
}
if (in_array('archive_notes', $columns)) {
    $updateParts[] = "archive_notes = NULL";
}
if (in_array('restored_at', $columns)) {
    $updateParts[] = "restored_at = NOW()";
}
if (in_array('restored_by', $columns)) {
    $updateParts[] = "restored_by = ?";
    $params[] = $_SESSION['admin_username'];
    $types .= 's';
}

$params[] = $id;
$types .= 'i';

$sql = "UPDATE members SET " . implode(', ', $updateParts) . " WHERE id = ?";
$stmt = $conn->prepare($sql);

if ($stmt) {
    if (!empty($types)) {
        $stmt->bind_param($types, ...$params);
    }
    
    if ($stmt->execute()) {
        $memberName = $member['student_name'] . ' ' . $member['father_name'];
        echo json_encode([
            'status' => 'success', 
            'message' => $memberName . ' has been restored to active members'
        ]);
    } else {
        echo json_encode(['status' => 'error', 'message' => 'Database error: ' . $stmt->error]);
    }
    $stmt->close();
} else {
    echo json_encode(['status' => 'error', 'message' => 'Failed to prepare query: ' . $conn->error]);
}

$conn->close();
