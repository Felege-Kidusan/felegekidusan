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

// Archive metadata columns are deployment-managed by migration 013.
$restoredBy = (string)$_SESSION['admin_username'];
$stmt = $conn->prepare(
    "UPDATE members
     SET status='active', archived_at=NULL, archived_by=NULL, archive_reason=NULL,
         archive_notes=NULL, archive_type=NULL, restored_at=NOW(), restored_by=?
     WHERE id=?"
);
if ($stmt) {
    $stmt->bind_param('si', $restoredBy, $id);
    if ($stmt->execute()) {
        $memberName = $member['student_name'] . ' ' . $member['father_name'];
        echo json_encode([
            'status' => 'success', 
            'message' => $memberName . ' has been restored to active members'
        ]);
    } else {
        error_log('Member restore update failed.');
        echo json_encode(['status' => 'error', 'message' => 'Unable to restore the member.']);
    }
    $stmt->close();
} else {
    error_log('Member restore prepare failed. Verify migration 013.');
    echo json_encode(['status' => 'error', 'message' => 'Archive storage is temporarily unavailable.']);
}

$conn->close();
