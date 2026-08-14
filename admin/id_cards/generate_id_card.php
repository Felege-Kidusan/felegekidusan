<?php
// FILE: /admin/id_cards/generate_id_card.php
require_once '../config.php'; 

// BULLETPROOF: Check if QR library exists
$qr_lib_path = __DIR__ . '/libs/phpqrcode/qrlib.php';
if (!file_exists($qr_lib_path)) {
    die("Error: QR Code library not found at: libs/phpqrcode/qrlib.php — Please upload the phpqrcode library to this folder.");
}
require_once $qr_lib_path;

if (!isset($_GET['member_id'])) die("Error: Member ID is required.");

$member_id = intval($_GET['member_id']);
$action = isset($_GET['action']) ? $_GET['action'] : 'generate';

// 1. Fetch Member
$stmt = $conn->prepare("SELECT * FROM members WHERE id = ?");
$stmt->bind_param("i", $member_id);
$stmt->execute();
$member = $stmt->get_result()->fetch_assoc();

if (!$member) die("Member not found.");

// 2. Member Code Logic
if (empty($member['member_code'])) {
    $new_code = MEMBER_CODE_FORMAT . str_pad($member['id'], 4, '0', STR_PAD_LEFT);
    $stmt2 = $conn->prepare("UPDATE members SET member_code = ? WHERE id = ?");
    $stmt2->bind_param("si", $new_code, $member_id);
    $stmt2->execute();
    $member['member_code'] = $new_code;
}

// 3. QR Code Generation (Bulletproof)
$qr_content = (defined('SITE_URL') ? SITE_URL : SITE_URL) . '/member.php?code=' . $member['member_code'];
$qr_filename = 'qr_' . $member['member_code'] . '.png';
$qr_dir = __DIR__ . '/assets/qr/';
$qr_abs_path = $qr_dir . $qr_filename;
$qr_web_path = '/admin/id_cards/assets/qr/' . $qr_filename;

// Create directory with full chain if needed
// 0755 = owner can write, others can only read. NEVER use 0777 (world-writable)
// under the web root — a world-writable web folder is a remote-code-execution risk.
if (!is_dir($qr_dir)) {
    if (!mkdir($qr_dir, 0755, true)) {
        die("Error: Could not create QR directory: " . $qr_dir . " — Check folder permissions.");
    }
}

// Check directory is writable
if (!is_writable($qr_dir)) {
    die("Error: QR directory is not writable: " . $qr_dir . " — set the assets/qr/ folder to 0755 and ensure it is owned by the web user.");
}

// Generate QR code
QRcode::png($qr_content, $qr_abs_path, QR_ECLEVEL_L, 4, 2);

// Verify QR was actually created
if (!file_exists($qr_abs_path)) {
    die("Error: QR code generation failed. File was not created at: " . $qr_abs_path);
}

// 4. Update Database (using prepared statements for security)
if ($action == 'renew' || ($member['id_card_status'] ?? 'none') == 'none') {
    $sql = "UPDATE members SET qr_code_path = ?, id_card_status = 'generated', id_card_generated_at = NOW() WHERE id = ?";
} else {
    $sql = "UPDATE members SET qr_code_path = ? WHERE id = ?";
}

$stmt = $conn->prepare($sql);
$stmt->bind_param("si", $qr_web_path, $member_id);
$stmt->execute();

// 5. Redirect back to View
header("Location: view_id_card.php?member_id=" . $member_id);
exit;
?>