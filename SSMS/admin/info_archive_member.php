<?php
/**
 * Archive Member - Move member to old members archive
 * AJAX endpoint with archive reason support
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
$reason = isset($input['reason']) ? trim($input['reason']) : '';
$notes = isset($input['notes']) ? trim($input['notes']) : '';

if ($id <= 0) {
    echo json_encode(['status' => 'error', 'message' => 'Invalid member ID']);
    exit;
}

// Verify member exists and is not already archived
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

if ($member['status'] === 'archived') {
    echo json_encode(['status' => 'error', 'message' => 'Member is already archived']);
    exit;
}

// Check which columns exist
$columns = [];
$colResult = $conn->query("SHOW COLUMNS FROM members");
while ($col = $colResult->fetch_assoc()) {
    $columns[] = $col['Field'];
}

$hasArchivedAt = in_array('archived_at', $columns);
$hasArchivedBy = in_array('archived_by', $columns);
$hasArchiveReason = in_array('archive_reason', $columns);
$hasArchiveNotes = in_array('archive_notes', $columns);

// Build dynamic update query
$updateParts = ["status = 'archived'"];
$params = [];
$types = '';

if ($hasArchivedAt) {
    $updateParts[] = "archived_at = NOW()";
}

if ($hasArchivedBy) {
    $updateParts[] = "archived_by = ?";
    $params[] = $_SESSION['admin_username'];
    $types .= 's';
}

if ($hasArchiveReason && $reason) {
    $updateParts[] = "archive_reason = ?";
    $params[] = $reason;
    $types .= 's';
}

if ($hasArchiveNotes && $notes) {
    $updateParts[] = "archive_notes = ?";
    $params[] = $notes;
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
        
        // Trigger workflow notifications
        try {
            require_once __DIR__ . '/backend/workflow.php';
            onMemberArchived($conn, $id, $reason);
        } catch (Exception $e) {
            error_log("Workflow notification error: " . $e->getMessage());
        }
        
        echo json_encode([
            'status' => 'success', 
            'message' => $memberName . ' has been moved to archive successfully'
        ]);
    } else {
        echo json_encode(['status' => 'error', 'message' => 'Database error: ' . $stmt->error]);
    }
    $stmt->close();
} else {
    echo json_encode(['status' => 'error', 'message' => 'Failed to prepare query: ' . $conn->error]);
}

$conn->close();
