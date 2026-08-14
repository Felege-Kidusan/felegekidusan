<?php
/**
 * Check Duplicate Member - Prevents registering same member twice
 * Checks both active members and archived members
 * Returns matching member data if found
 */

header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/config.php';

// Check auth
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

// Get input - can be GET or POST
$studentName = isset($_REQUEST['student_name']) ? trim($_REQUEST['student_name']) : '';
$fatherName = isset($_REQUEST['father_name']) ? trim($_REQUEST['father_name']) : '';
$grandfatherName = isset($_REQUEST['grandfather_name']) ? trim($_REQUEST['grandfather_name']) : '';
$phone = isset($_REQUEST['phone']) ? trim($_REQUEST['phone']) : '';

// Need at least student name and father name to check
if (empty($studentName) || empty($fatherName)) {
    echo json_encode([
        'status' => 'success', 
        'found' => false,
        'message' => 'Insufficient data to check duplicates'
    ]);
    exit;
}

// Normalize names for comparison (remove extra spaces, lowercase)
$studentNameNorm = mb_strtolower(preg_replace('/\s+/', ' ', $studentName), 'UTF-8');
$fatherNameNorm = mb_strtolower(preg_replace('/\s+/', ' ', $fatherName), 'UTF-8');
$grandfatherNameNorm = mb_strtolower(preg_replace('/\s+/', ' ', $grandfatherName), 'UTF-8');

// Search for potential duplicates
// Match criteria: Same student name + father name (exact or similar)
// OR same phone number
$matches = [];

// Query 1: Exact name match
$sql = "SELECT 
    id, member_code, student_name, father_name, grandfather_name,
    baptismal_name, gender, age, current_section, phone_number,
    guardian_name, status, student_photo_path, registered_at, created_at
FROM members 
WHERE (
    LOWER(TRIM(student_name)) = ? AND LOWER(TRIM(father_name)) = ?
)";
$params = [$studentNameNorm, $fatherNameNorm];
$types = 'ss';

// Add grandfather check if provided
if (!empty($grandfatherNameNorm)) {
    $sql .= " OR (LOWER(TRIM(student_name)) = ? AND LOWER(TRIM(father_name)) = ? AND LOWER(TRIM(grandfather_name)) = ?)";
    $params[] = $studentNameNorm;
    $params[] = $fatherNameNorm;
    $params[] = $grandfatherNameNorm;
    $types .= 'sss';
}

// Add phone check if provided
if (!empty($phone)) {
    $phoneClean = preg_replace('/[^0-9]/', '', $phone);
    if (strlen($phoneClean) >= 9) {
        $sql .= " OR (phone_number LIKE ? OR alt_phone_number LIKE ? OR guardian_phone1 LIKE ?)";
        $phoneLike = '%' . substr($phoneClean, -9) . '%';
        $params[] = $phoneLike;
        $params[] = $phoneLike;
        $params[] = $phoneLike;
        $types .= 'sss';
    }
}

$sql .= " ORDER BY status ASC, id DESC LIMIT 5"; // Active first, then archived

$stmt = $conn->prepare($sql);
if ($stmt) {
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $result = $stmt->get_result();
    
    while ($row = $result->fetch_assoc()) {
        // Calculate match score
        $score = 0;
        $matchReasons = [];
        
        $rowStudentName = mb_strtolower(trim($row['student_name']), 'UTF-8');
        $rowFatherName = mb_strtolower(trim($row['father_name']), 'UTF-8');
        $rowGrandfatherName = mb_strtolower(trim($row['grandfather_name'] ?? ''), 'UTF-8');
        
        // Exact student name match
        if ($rowStudentName === $studentNameNorm) {
            $score += 40;
            $matchReasons[] = 'Student name matches';
        } elseif (similar_text($rowStudentName, $studentNameNorm) > strlen($studentNameNorm) * 0.7) {
            $score += 25;
            $matchReasons[] = 'Student name similar';
        }
        
        // Exact father name match
        if ($rowFatherName === $fatherNameNorm) {
            $score += 40;
            $matchReasons[] = 'Father name matches';
        } elseif (similar_text($rowFatherName, $fatherNameNorm) > strlen($fatherNameNorm) * 0.7) {
            $score += 25;
            $matchReasons[] = 'Father name similar';
        }
        
        // Grandfather name match (bonus)
        if (!empty($grandfatherNameNorm) && !empty($rowGrandfatherName)) {
            if ($rowGrandfatherName === $grandfatherNameNorm) {
                $score += 20;
                $matchReasons[] = 'Grandfather name matches';
            }
        }
        
        // Phone match (strong indicator)
        if (!empty($phone)) {
            $phoneClean = preg_replace('/[^0-9]/', '', $phone);
            $rowPhone = preg_replace('/[^0-9]/', '', $row['phone_number'] ?? '');
            if (strlen($phoneClean) >= 9 && strlen($rowPhone) >= 9) {
                if (substr($phoneClean, -9) === substr($rowPhone, -9)) {
                    $score += 30;
                    $matchReasons[] = 'Phone number matches';
                }
            }
        }
        
        // Only include if score is significant
        if ($score >= 60) {
            $row['match_score'] = $score;
            $row['match_reasons'] = $matchReasons;
            $row['is_archived'] = ($row['status'] === 'archived');
            
            // Fix photo path
            if (!empty($row['student_photo_path'])) {
                $path = $row['student_photo_path'];
                if (strpos($path, 'http') !== 0) {
                    $path = ltrim($path, '/');
                    if (strpos($path, 'uploads/') === 0) {
                        $row['student_photo_path'] = '/admin/' . $path;
                    }
                }
            }
            
            $matches[] = $row;
        }
    }
    $stmt->close();
}

// Sort by score descending
usort($matches, function($a, $b) {
    return $b['match_score'] - $a['match_score'];
});

if (count($matches) > 0) {
    echo json_encode([
        'status' => 'success',
        'found' => true,
        'count' => count($matches),
        'matches' => $matches,
        'message' => 'Potential duplicate member(s) found!'
    ]);
} else {
    echo json_encode([
        'status' => 'success',
        'found' => false,
        'message' => 'No duplicates found'
    ]);
}

$conn->close();
