<?php
/**
 * ============================================================
 * FKSS Public Registration Submission Handler
 * ============================================================
 * Receives the registration/contact form from the PUBLIC website.
 * Stores as a lead in cms_registration_submissions (Option B).
 * No login required — but rate-limited and validated.
 * ============================================================
 */
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/school_config.php';

// DB connect (this file is in public_html root, config.php is in admin/)
require_once __DIR__ . '/admin/config.php';

function out($d) { echo json_encode($d, JSON_UNESCAPED_UNICODE); exit; }

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    out(['status' => 'error', 'message' => 'Invalid request.']);
}

if (!isset($conn) || $conn->connect_error) {
    out(['status' => 'error', 'message' => 'Service temporarily unavailable. Please try again later.']);
}

// ── Simple rate limiting: max 5 submissions per IP per hour ──
$ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
$cacheDir = __DIR__ . '/admin/uploads/cache';
if (!is_dir($cacheDir)) @mkdir($cacheDir, 0755, true);
$rateFile = $cacheDir . '/reg_' . md5($ip) . '.json';
$rate = ['count' => 0, 'first' => time()];
if (file_exists($rateFile)) {
    $rate = json_decode(file_get_contents($rateFile), true) ?: $rate;
    if (time() - $rate['first'] > 3600) $rate = ['count' => 0, 'first' => time()];
}
if ($rate['count'] >= 5) {
    out(['status' => 'error', 'message' => 'Too many submissions. Please try again later.']);
}

// ── Honeypot anti-spam: if the hidden "website" field is filled, it's a bot ──
if (!empty($_POST['website'])) {
    // Silently pretend success so the bot doesn't retry
    out(['status' => 'success', 'message' => 'Thank you! We will contact you soon.']);
}

// ── Collect & validate ──
function f($k) { return isset($_POST[$k]) ? trim($_POST[$k]) : ''; }

$fullName = f('full_name');
$phone    = f('phone');
$email    = f('email');
$age      = f('age');
$gender   = f('gender');
$address  = f('address');
$program  = f('program_interest');
$message  = f('message');

$errors = [];
if ($fullName === '') $errors[] = 'Full name is required.';
if ($phone === '')    $errors[] = 'Phone number is required.';
if (strlen($fullName) > 150) $errors[] = 'Name is too long.';
if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Email is not valid.';

if (!empty($errors)) {
    out(['status' => 'error', 'message' => implode("\n", $errors)]);
}

try {
    $stmt = $conn->prepare("INSERT INTO cms_registration_submissions
        (full_name, phone, email, age, gender, address, program_interest, message, ip_address)
        VALUES (?,?,?,?,?,?,?,?,?)");
    $emailVal = $email !== '' ? $email : null;
    $stmt->bind_param('sssssssss', $fullName, $phone, $emailVal, $age, $gender, $address, $program, $message, $ip);
    $stmt->execute();
    $stmt->close();

    // Update rate limit
    $rate['count']++;
    file_put_contents($rateFile, json_encode($rate));

    out(['status' => 'success', 'message' => 'Thank you! Your registration request has been received. We will contact you soon.']);
} catch (Throwable $e) {
    error_log("Public registration submit error: " . $e->getMessage());
    out(['status' => 'error', 'message' => 'Something went wrong. Please try again.']);
}
