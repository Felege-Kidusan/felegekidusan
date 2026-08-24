<?php
/**
 * Authenticated controller for private guardian photos and member documents.
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/backend/services/MemberFileService.php';

requireAuth();
if (!hasRole(['super_admin', 'school_admin', 'info_dept', 'hr_dept'])) {
    http_response_code(403);
    exit('Access denied.');
}

$memberId = (int)($_GET['member_id'] ?? 0);
$field = (string)($_GET['field'] ?? '');
$allowedFields = [
    'guardian_photo_path' => 'guardian_photo',
    'doc_school_records_path' => 'school_records',
    'doc_spiritual_path' => 'spiritual_document',
    'doc_signed_form_path' => 'signed_form',
];
if ($memberId <= 0 || !isset($allowedFields[$field])) {
    http_response_code(404);
    exit('File not found.');
}

$stmt = $conn->prepare("SELECT `{$field}` AS stored_path FROM members WHERE id=? LIMIT 1");
if (!$stmt) {
    reportInternalError('Private member file lookup prepare failed', $conn->error);
    http_response_code(503);
    exit('File is temporarily unavailable.');
}
$stmt->bind_param('i', $memberId);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();
$stmt->close();

$path = $row ? \App\Services\MemberFileService::resolvePrivatePath((string)($row['stored_path'] ?? '')) : null;
$mime = $path ? \App\Services\MemberFileService::mimeForPath($path) : null;
if ($path === null || $mime === null) {
    http_response_code(404);
    exit('File not found.');
}

$extensionByMime = [
    'image/jpeg' => 'jpg',
    'image/png' => 'png',
    'image/gif' => 'gif',
    'image/webp' => 'webp',
    'image/bmp' => 'bmp',
    'image/x-ms-bmp' => 'bmp',
    'application/pdf' => 'pdf',
];
$downloadName = $allowedFields[$field] . '_' . $memberId . '.' . ($extensionByMime[$mime] ?? 'bin');

if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}
header('Content-Type: ' . $mime);
header('Content-Length: ' . (string)filesize($path));
header('Content-Disposition: inline; filename="' . $downloadName . '"');
header('Cache-Control: private, no-store, max-age=0');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');
header('Cross-Origin-Resource-Policy: same-origin');
header("Content-Security-Policy: default-src 'none'; sandbox");
readfile($path);
exit;
