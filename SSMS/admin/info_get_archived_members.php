<?php
/**
 * Get Archived Members - Fetch all archived members
 * AJAX endpoint
 */

header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/config.php';

// Check auth
if (empty($_SESSION['admin_username'])) {
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
    exit;
}

// Fetch all archived members
$sql = "SELECT 
            id, member_code, student_name, father_name, grandfather_name,
            baptismal_name, gender, age, current_section, age_group,
            phone_number, guardian_name, student_photo_path,
            status, registration_type, member_type,
            registered_at, archived_at, archived_by, archive_reason, archive_notes
        FROM members 
        WHERE status = 'archived' 
        ORDER BY archived_at DESC, student_name ASC";

$result = $conn->query($sql);

if (!$result) {
    echo json_encode(['status' => 'error', 'message' => 'Database error: ' . $conn->error]);
    exit;
}

$members = [];
while ($row = $result->fetch_assoc()) {
    $members[] = $row;
}

echo json_encode([
    'status' => 'success',
    'count' => count($members),
    'members' => $members
]);

$conn->close();
