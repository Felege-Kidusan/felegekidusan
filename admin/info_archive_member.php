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
$reason = isset($input['reason']) ? trim((string)$input['reason']) : '';
$notes = isset($input['notes']) ? trim((string)$input['notes']) : '';
$allowedReasons = ['left_school','graduated','transferred','inactive_long','failed_observation','deceased','other'];
if (!in_array($reason, $allowedReasons, true) || mb_strlen($notes) > 2000) {
    echo json_encode(['status' => 'error', 'message' => 'Invalid archive reason or notes.']);
    exit;
}

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

// Archive metadata columns are deployment-managed by migration 013.
$archiveType = ($reason === 'failed_observation') ? 'failed_observation' : 'permanent_archive';
$archivedBy = (string)$_SESSION['admin_username'];

// Archive the member and preserve a reviewable reason trail.
$sql = "UPDATE members
        SET status='archived', archived_at=NOW(), archived_by=?, archive_reason=?,
            archive_notes=?, archive_type=?
        WHERE id=?";
$stmt = $conn->prepare($sql);
if ($stmt) {
    $stmt->bind_param('ssssi', $archivedBy, $reason, $notes, $archiveType, $id);
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
        error_log('Member archive update failed.');
        echo json_encode(['status' => 'error', 'message' => 'Unable to archive the member.']);
    }
    $stmt->close();
} else {
    error_log('Member archive prepare failed. Verify migration 013.');
    echo json_encode(['status' => 'error', 'message' => 'Archive storage is temporarily unavailable.']);
}

$conn->close();
