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

// Rate limiting by client IP to prevent flooding. The installation bucket below
// prevents one installation from rotating IPs to evade the companion limit.
if (isApiRateLimited('telemetry_ip', 120)) {
    err('Too many telemetry requests. Please slow down.', 429);
}

// Telemetry is intentionally small: device facts plus a bounded typed event.
$input = getBody(8192);
if (!is_array($input)) {
    $input = $_POST;
}

$readString = static function (
    string $field,
    string $default,
    int $maxLength,
    bool $nullable = false
) use (&$input): ?string {
    if (!array_key_exists($field, $input)) return $nullable ? null : $default;
    $value = $input[$field];
    if ($value === null && $nullable) return null;
    if (!is_string($value)) err("Invalid {$field}.", 422);
    $value = trim($value);
    if (strlen($value) > $maxLength) err("Invalid {$field}.", 422);
    return $value;
};

$readInt = static function (
    string $field,
    int $default,
    int $min,
    int $max
) use (&$input): int {
    if (!array_key_exists($field, $input)) return $default;
    $value = $input[$field];
    if (is_int($value)) {
        $parsed = $value;
    } elseif (is_string($value) && preg_match('/^\\d+$/', $value)) {
        $parsed = (int)$value;
    } else {
        err("Invalid {$field}.", 422);
    }
    if ($parsed < $min || $parsed > $max) err("Invalid {$field}.", 422);
    return $parsed;
};

$installIdValue = $input['installation_id'] ?? null;
if (!is_string($installIdValue)
    || !preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $installIdValue)) {
    err('Invalid or missing installation_id.', 422);
}
$installId = strtolower(trim($installIdValue));
apiEnforceRateLimits([
    [
        'action' => 'telemetry_installation',
        'subject' => $installId,
        'limit' => 60,
        'window' => 60,
    ],
]);

// Clean and type-check telemetry metrics. Invalid structures are rejected,
// rather than coerced into misleading zeroes or durable strings.
$appVersion = $readString('app_version', '1.0.0', 32) ?? '1.0.0';
$appBuild = $readInt('app_build', 1, 1, 4294967295);
$osVersion = $readString('os_version', '', 32) ?? '';
$sdkInt = $readInt('sdk_int', 0, 0, 65535);
$deviceBrand = $readString('device_brand', '', 64) ?? '';
$deviceModel = $readString('device_model', '', 64) ?? '';
$abi = $readString('abi', '', 32) ?? '';
$ramMb = $readInt('ram_mb', 0, 0, 1048576);
$isLowRamValue = $input['is_low_ram'] ?? false;
if (is_bool($isLowRamValue)) {
    $isLowRam = $isLowRamValue ? 1 : 0;
} elseif (is_int($isLowRamValue) && ($isLowRamValue === 0 || $isLowRamValue === 1)) {
    $isLowRam = $isLowRamValue;
} else {
    err('Invalid is_low_ram.', 422);
}
$roleHint = $readString('role_hint', '', 32, true);
if ($roleHint === '') $roleHint = null;

$eventTypeValue = $input['event_type'] ?? ($action === 'event' ? 'event' : 'launch');
if (!is_string($eventTypeValue)) err('Invalid event_type.', 422);
$eventType = trim($eventTypeValue);
$allowedEventTypes = [
    'event', 'launch', 'heartbeat',
    'sync_success', 'sync_completed', 'sync_failed', 'sync_error',
    'sync_pass_completed', 'crash', 'crash_recorded', 'update_downloaded',
];
if ($eventType === '' || strlen($eventType) > 48
    || !in_array($eventType, $allowedEventTypes, true)) {
    err('Unsupported telemetry event type.', 422);
}

$eventDataInput = $input['event_data'] ?? null;
if ($eventDataInput !== null && !is_array($eventDataInput)) {
    err('Telemetry event_data must be an object.', 422);
}
$eventData = null;
$normalisedEventData = null;
$crashDedupeKey = null;
$syncPassSuccess = 0;
$syncPassFail = 0;

$assertAllowedKeys = static function (array $data, array $allowed): void {
    foreach (array_keys($data) as $key) {
        if (!is_string($key) || !in_array($key, $allowed, true)) {
            err('Unsupported telemetry event field.', 422);
        }
    }
};
$readEventCount = static function (array $data, string $key): int {
    if (!array_key_exists($key, $data)) return 0;
    $value = $data[$key];
    if (is_int($value)) {
        $parsed = $value;
    } elseif (is_string($value) && preg_match('/^\\d+$/', $value)) {
        $parsed = (int)$value;
    } else {
        err("Invalid telemetry count: {$key}.", 422);
    }
    if ($parsed < 0 || $parsed > 100000) {
        err("Invalid telemetry count: {$key}.", 422);
    }
    return $parsed;
};

if ($eventType === 'sync_pass_completed') {
    $data = is_array($eventDataInput) ? $eventDataInput : [];
    $assertAllowedKeys($data, [
        'operations', 'attempts', 'retries', 'succeeded',
        'waiting_retry', 'needs_attention',
    ]);
    $normalisedEventData = [];
    foreach ([
        'operations', 'attempts', 'retries', 'succeeded',
        'waiting_retry', 'needs_attention',
    ] as $countKey) {
        $normalisedEventData[$countKey] = $readEventCount($data, $countKey);
    }
    $syncPassSuccess = $normalisedEventData['succeeded'];
    $syncPassFail = min(
        100000,
        $normalisedEventData['waiting_retry'] + $normalisedEventData['needs_attention']
    );
} elseif ($eventType === 'crash' || $eventType === 'crash_recorded') {
    $data = is_array($eventDataInput) ? $eventDataInput : [];
    // Raw summaries are deliberately rejected. Updated clients send only a
    // hash identity; old callers must not persist stack traces at this boundary.
    $assertAllowedKeys($data, ['crash_key', 'kind']);
    $crashKey = $data['crash_key'] ?? null;
    $kind = $data['kind'] ?? null;
    if (!is_string($crashKey)
        || !preg_match('/^[0-9a-f]{64}$/i', $crashKey)
        || !is_string($kind)
        || !in_array($kind, ['native', 'dart'], true)) {
        err('Crash telemetry requires a hash key and kind.', 422);
    }
    $crashDedupeKey = strtolower($crashKey);
    $normalisedEventData = [
        'crash_key' => $crashDedupeKey,
        'kind' => $kind,
    ];
} elseif ($eventType === 'update_downloaded') {
    $data = is_array($eventDataInput) ? $eventDataInput : [];
    $assertAllowedKeys($data, ['target_version', 'target_build']);
    $targetVersion = $data['target_version'] ?? null;
    if (!is_string($targetVersion) || trim($targetVersion) === '' || strlen($targetVersion) > 32) {
        err('Update telemetry requires target_version.', 422);
    }
    $normalisedEventData = [
        'target_version' => trim($targetVersion),
        'target_build' => $readEventCount($data, 'target_build'),
    ];
} elseif ($eventType === 'sync_completed' || $eventType === 'sync_failed'
    || $eventType === 'sync_success' || $eventType === 'sync_error') {
    $data = is_array($eventDataInput) ? $eventDataInput : [];
    $assertAllowedKeys($data, ['items_count', 'error_code']);
    $normalisedEventData = [
        'items_count' => $readEventCount($data, 'items_count'),
    ];
    if (array_key_exists('error_code', $data)) {
        if (!is_string($data['error_code'])
            || !in_array($data['error_code'], ['operations_pending_retry', 'unknown'], true)) {
            err('Invalid telemetry error_code.', 422);
        }
        $normalisedEventData['error_code'] = $data['error_code'];
    }
} else {
    $data = is_array($eventDataInput) ? $eventDataInput : [];
    $assertAllowedKeys($data, []);
    if ($data !== []) $normalisedEventData = [];
}

if ($normalisedEventData !== null) {
    $eventData = json_encode($normalisedEventData, JSON_UNESCAPED_UNICODE);
    if (!is_string($eventData) || strlen($eventData) > 2048) {
        err('Telemetry event_data is too large.', 422);
    }
}

$ip = (string)($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
$ipHashSecret = defined('TELEMETRY_HASH_SECRET') && TELEMETRY_HASH_SECRET !== ''
    ? TELEMETRY_HASH_SECRET
    : JWT_SECRET;
$ipHash = hash_hmac('sha256', $ip . '::' . gmdate('Ymd'), $ipHashSecret);

$transactionOpen = false;
try {
    if (!isset($conn) || !($conn instanceof mysqli)) {
        err('Database unavailable.', 503);
    }

    $conn->begin_transaction();
    $transactionOpen = true;

    // Insert event detail before the installation upsert. Crash events carry a
    // unique hash identity; a duplicate delivery is accepted as a no-op and
    // must not increment the lifetime crash counter a second time.
    $eventInserted = true;
    if ($eventType !== '' && $eventType !== 'heartbeat') {
        $evStmt = $conn->prepare("INSERT INTO app_telemetry_events (
            installation_id, event_type, event_data, dedupe_key, app_version, app_build, created_at
        ) VALUES (?, ?, ?, ?, ?, ?, NOW())");
        if (!$evStmt) {
            throw new RuntimeException('Could not prepare telemetry event.');
        }
        try {
            if (!$evStmt->bind_param(
                'sssssi',
                $installId, $eventType, $eventData, $crashDedupeKey,
                $appVersion, $appBuild
            )) {
                throw new RuntimeException('Could not bind telemetry event.');
            }
            if (!$evStmt->execute()) {
                $duplicateCrash = $crashDedupeKey !== null && $evStmt->errno === 1062;
                if (!$duplicateCrash) {
                    throw new RuntimeException('Could not record telemetry event.');
                }
                $eventInserted = false;
            }
        } finally {
            $evStmt->close();
        }
    }

    // Upsert installation record
    $incLaunch = ($eventType === 'launch' || $eventType === 'heartbeat') ? 1 : 0;
    $incSyncSuccess = ($eventType === 'sync_success' || $eventType === 'sync_completed')
        ? 1
        : ($eventType === 'sync_pass_completed' ? $syncPassSuccess : 0);
    $incSyncFail = ($eventType === 'sync_failed' || $eventType === 'sync_error')
        ? 1
        : ($eventType === 'sync_pass_completed' ? $syncPassFail : 0);
    $incCrash = ($eventType === 'crash' || $eventType === 'crash_recorded') && $eventInserted
        ? 1
        : 0;

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

    $conn->commit();
    $transactionOpen = false;

    ok([
        'recorded' => true,
        'installation_id' => $installId,
        'server_time' => date('c'),
    ]);
} catch (Throwable $e) {
    if ($transactionOpen && isset($conn) && $conn instanceof mysqli) {
        $conn->rollback();
    }
    reportInternalError('Telemetry recording error: ' . $e->getMessage());
    err('Failed to record telemetry.', 500);
}
