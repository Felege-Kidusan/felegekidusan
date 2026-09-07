<?php
/** Public website enquiry/registration LEADS, not member-account creation. */
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
require_once __DIR__ . '/admin/config.php';
require_once __DIR__ . '/admin/backend/services/SecurityRateLimiter.php';
require_once __DIR__ . '/admin/backend/services/LedgerValidation.php';

use App\Services\LedgerValidation as Input;
use App\Services\LedgerInputException;

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    jsonResponse(['status'=>'error','message'=>'Use the registration form to submit your request.'], 405);
}
if (!($conn instanceof mysqli) || $conn->connect_error) {
    jsonResponse(['status'=>'error','message'=>'Service temporarily unavailable. Please try again later.'], 503);
}

// Honeypot replies do not store a row or identify the anti-spam check.
if (!empty($_POST['website'])) {
    jsonResponse(['status'=>'success','message'=>'Thank you! We will contact you soon.']);
}

try {
    $fullName = Input::text($_POST['full_name'] ?? '', 'Full name', 150, true);
    $phone = Input::text($_POST['phone'] ?? '', 'Phone number', 30, true);
    $email = Input::text($_POST['email'] ?? '', 'Email', 120);
    $age = Input::text($_POST['age'] ?? '', 'Age', 10);
    $gender = Input::choice($_POST['gender'] ?? '', ['', 'male', 'female'], 'Gender');
    $address = Input::text($_POST['address'] ?? '', 'Address', 255);
    $program = Input::text($_POST['program_interest'] ?? '', 'Program', 150);
    $message = Input::text($_POST['message'] ?? '', 'Message', 5000);
    $normalPhone = validatePhone($phone);
    if ($normalPhone === null) throw new LedgerInputException('Please enter a valid phone number.');
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) throw new LedgerInputException('Email is not valid.');
    // The public form currently offers ages 4–18; no guessed age is stored.
    if ($age !== '') $age = (string)Input::integer($age, 'Age', 4, 18);
    $phone = $normalPhone;

    // Reserve atomically before INSERT. The old read/increment/write JSON
    // file admitted concurrent submissions and lost increments.
    $ip = (string)($_SERVER['REMOTE_ADDR'] ?? 'unknown');
    $limiter = new \App\Services\SecurityRateLimiter($pdo, __DIR__ . '/admin/uploads/cache');
    $limit = $limiter->consume('public-registration', $ip, 5, 3600);
    if (!$limit['allowed']) {
        header('Retry-After: ' . max(1, (int)$limit['retry_after']));
        jsonResponse(['status'=>'error','message'=>'Too many submissions. Please try again later.'], 429);
    }

    $stmt = $conn->prepare('INSERT INTO cms_registration_submissions
        (full_name,phone,email,age,gender,address,program_interest,message,ip_address)
        VALUES (?,?,?,?,?,?,?,?,?)');
    $emailVal = $email !== '' ? $email : null;
    $stmt->bind_param('sssssssss', $fullName, $phone, $emailVal, $age, $gender, $address, $program, $message, $ip);
    $stmt->execute(); $stmt->close();
    jsonResponse(['status'=>'success','message'=>'Thank you! Your registration request has been received. We will contact you soon.']);
} catch (LedgerInputException $error) {
    jsonResponse(['status'=>'error','message'=>$error->publicMessage], $error->httpStatus);
} catch (Throwable $error) {
    reportInternalError('Public registration submission failed', $error);
    jsonResponse(['status'=>'error','message'=>'Something went wrong. Please try again.'], 500);
}
