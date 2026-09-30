<?php
/**
 * ============================================================
 * REST API v1 — Mobile App Telemetry & Fleet Analytics Router
 * ============================================================
 * Endpoints:
 *   POST /api/v1/telemetry/heartbeat  — Periodic device heartbeat / launch ping
 *   POST /api/v1/telemetry/event      — Telemetry event (sync, crash, update)
 *   POST /api/v1/telemetry            — General telemetry ingestion
 * ============================================================
 */

if ($method !== 'POST') {
    err('Method not allowed. Use POST.', 405);
}

$action = $ROUTE['id'] ?? 'heartbeat';

// Rate limiting by client IP to prevent flooding
if (isApiRateLimited('telemetry_ip', 120)) {
    err('Too many telemetry requests. Please slow down.', 429);
}

$input = getBody();
if (!is_array($input)) {
    $input = $_POST;
}

$installId = trim((string)($input['installation_id'] ?? ''));
if ($installId === '' || strlen($installId) < 8 || strlen($installId) > 64) {
    err('Invalid or missing installation_id.', 422);
}

// Clean and sanitize telemetry metrics
$appVersion = substr(trim((string)($input['app_version'] ?? '1.0.0')), 0, 32);
$appBuild = max(1, (int)($input['app_build'] ?? 1));
$osVersion = substr(trim((string)($input['os_version'] ?? '')), 0, 32);
$sdkInt = max(0, (int)($input['sdk_int'] ?? 0));
$deviceBrand = substr(trim((string)($input['device_brand'] ?? '')), 0, 64);
$deviceModel = substr(trim((string)($input['device_model'] ?? '')), 0, 64);
$abi = substr(trim((string)($input['abi'] ?? '')), 0, 32);
$ramMb = max(0, (int)($input['ram_mb'] ?? 0));
$isLowRam = !empty($input['is_low_ram']) ? 1 : 0;
$roleHint = !empty($input['role_hint']) ? substr(trim((string)$input['role_hint']), 0, 32) : null;

$eventType = substr(trim((string)($input['event_type'] ?? ($action === 'event' ? 'event' : 'launch'))), 0, 48);
$eventData = isset($input['event_data']) && is_array($input['event_data'])
    ? json_encode($input['event_data'], JSON_UNESCAPED_UNICODE)
    : (is_string($input['event_data'] ?? null) ? substr((string)$input['event_data'], 0, 2048) : null);

$ip = (string)($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
$ipHash = hash('sha256', $ip . '::' . date('Ymd'));

try {
    if (!isset($conn) || !($conn instanceof mysqli)) {
        err('Database unavailable.', 503);
    }

    // Upsert installation record
    $incLaunch = ($eventType === 'launch' || $eventType === 'heartbeat') ? 1 : 0;
    $incSyncSuccess = ($eventType === 'sync_success' || $eventType === 'sync_completed') ? 1 : 0;
    $incSyncFail = ($eventType === 'sync_failed' || $eventType === 'sync_error') ? 1 : 0;
    $incCrash = ($eventType === 'crash' || $eventType === 'crash_recorded') ? 1 : 0;

    $stmt = $conn->prepare("INSERT INTO app_installations (
        installation_id, app_version, app_build, os_version, sdk_int,
        device_brand, device_model, abi, ram_mb, is_low_ram,
        launch_count, sync_success_count, sync_fail_count, crash_count,
        last_role_hint, ip_hash, first_seen_at, last_seen_at
    ) VALUES (
        ?, ?, ?, ?, ?,
        ?, ?, ?, ?, ?,
        ?, ?, ?, ?,
        ?, ?, NOW(), NOW()
    ) ON DUPLICATE KEY UPDATE
        app_version = VALUES(app_version),
        app_build = VALUES(app_build),
        os_version = IF(VALUES(os_version) != '', VALUES(os_version), os_version),
        sdk_int = IF(VALUES(sdk_int) > 0, VALUES(sdk_int), sdk_int),
        device_brand = IF(VALUES(device_brand) != '', VALUES(device_brand), device_brand),
        device_model = IF(VALUES(device_model) != '', VALUES(device_model), device_model),
        abi = IF(VALUES(abi) != '', VALUES(abi), abi),
        ram_mb = IF(VALUES(ram_mb) > 0, VALUES(ram_mb), ram_mb),
        is_low_ram = VALUES(is_low_ram),
        launch_count = launch_count + ?,
        sync_success_count = sync_success_count + ?,
        sync_fail_count = sync_fail_count + ?,
        crash_count = crash_count + ?,
        last_role_hint = IF(VALUES(last_role_hint) IS NOT NULL, VALUES(last_role_hint), last_role_hint),
        ip_hash = VALUES(ip_hash),
        last_seen_at = NOW()");

    if (!$stmt) {
        throw new RuntimeException('Could not prepare telemetry heartbeat.');
    }
    try {
        if (!$stmt->bind_param(
            'ssisisssiiiiiissiiii',
            $installId, $appVersion, $appBuild, $osVersion, $sdkInt,
            $deviceBrand, $deviceModel, $abi, $ramMb, $isLowRam,
            $incLaunch, $incSyncSuccess, $incSyncFail, $incCrash,
            $roleHint, $ipHash,
            $incLaunch, $incSyncSuccess, $incSyncFail, $incCrash
        )) {
            throw new RuntimeException('Could not bind telemetry heartbeat.');
        }
        if (!$stmt->execute()) {
            throw new RuntimeException('Could not record telemetry heartbeat.');
        }
    } finally {
        $stmt->close();
    }

    // Insert event detail if present
    if ($eventType !== '' && $eventType !== 'heartbeat') {
        $evStmt = $conn->prepare("INSERT INTO app_telemetry_events (
            installation_id, event_type, event_data, app_version, app_build, created_at
        ) VALUES (?, ?, ?, ?, ?, NOW())");
        if ($evStmt) {
            $evStmt->bind_param('ssssi', $installId, $eventType, $eventData, $appVersion, $appBuild);
            $evStmt->execute();
            $evStmt->close();
        }
    }

    ok([
        'recorded' => true,
        'installation_id' => $installId,
        'server_time' => date('c'),
    ]);
} catch (Throwable $e) {
    reportInternalError('Telemetry recording error: ' . $e->getMessage());
    err('Failed to record telemetry.', 500);
}
