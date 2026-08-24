<?php
/**
 * ============================================================
 * Branding & Assets API — Logo, Signature, Stamp/Seal Management
 * ============================================================
 * Manages system-wide branding assets:
 * - School logo (used across all dashboards, reports, ID cards)
 * - Head teacher signature (ID cards)
 * - Director/Admin signature (ID cards)
 * - School seal/stamp (ID cards, certificates)
 * ============================================================
 */
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/backend/services/IdCardLayout.php';

// ── Safety: verify DB connection is alive ──
if (!$conn || $conn->connect_error) {
    echo json_encode(['status' => 'error', 'message' => 'Database connection failed']);
    exit;
}

if (empty($_SESSION['admin_id'])) {
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
    exit;
}

$userRole = $_SESSION['admin_role'] ?? '';
$allowedRoles = ['super_admin', 'school_admin'];

if (!in_array($userRole, $allowedRoles)) {
    echo json_encode(['status' => 'error', 'message' => 'Access denied — only Super Admin and School Admin can manage branding']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrfToken = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!validateCsrf($csrfToken)) {
        echo json_encode(['status' => 'error', 'message' => 'Security token expired. Please reload the page.']);
        exit;
    }
}

// ── Asset directories ──
$baseDir = __DIR__ . '/id_cards/assets';
$dirs = [
    'logos'        => $baseDir . '/logos',
    'seals'        => $baseDir . '/seals',
    'signatures'   => $baseDir . '/signatures',
    'backgrounds'  => $baseDir . '/backgrounds',
];

// Branding schema/defaults are deployment-managed by migrations 012/013.

// ══════════════════════════════════════════════════════════════
// HELPER: Safe query that returns empty result instead of crash
// ══════════════════════════════════════════════════════════════
function brandQuery($conn, $sql) {
    try {
        $result = $conn->query($sql);
        if ($result === false) {
            reportInternalError('Branding query failed', $conn->error);
            return null;
        }
        return $result;
    } catch (\Throwable $e) {
        reportInternalError('Branding query failed', $e);
        return null;
    }
    return $result;
}

/**
 * Find a branding image on disk. Tiny / missing files count as absent.
 * @return array{exists:bool,size:int,web:string,disk:string}
 */
function brandResolveAssetFile(string $webPath): array
{
    $empty = ['exists' => false, 'size' => 0, 'web' => '', 'disk' => ''];
    $webPath = trim($webPath);
    if ($webPath === '' || $webPath[0] !== '/') {
        return $empty;
    }
    $candidates = [
        ($_SERVER['DOCUMENT_ROOT'] ?? '') . $webPath,
        dirname(__DIR__) . $webPath,
        __DIR__ . '/..' . $webPath,
    ];
    foreach ($candidates as $disk) {
        if ($disk && is_file($disk)) {
            $size = (int)filesize($disk);
            if ($size > 32) {
                return ['exists' => true, 'size' => $size, 'web' => $webPath, 'disk' => $disk];
            }
        }
    }
    return $empty;
}

$action = $_REQUEST['action'] ?? '';

switch ($action) {

    // ============================================================
    // GET ALL BRANDING ASSETS
    // ============================================================
    case 'get_assets':
        $result = brandQuery($conn, "SELECT * FROM system_branding WHERE asset_key != '_id_card_settings' ORDER BY id");
        $assets = [];
        $packagedDefaults = [
            'logo' => [
                ['disk' => __DIR__ . '/id_cards/assets/logos/school_logo.png', 'web' => '/admin/id_cards/assets/logos/school_logo.png'],
                ['disk' => dirname(__DIR__) . '/themes/fkss/assets/logos/school_logo.png', 'web' => '/themes/fkss/assets/logos/school_logo.png'],
            ],
            'card_bg' => [
                ['disk' => __DIR__ . '/id_cards/assets/backgrounds/id_card_bg.jpg', 'web' => '/admin/id_cards/assets/backgrounds/id_card_bg.jpg'],
            ],
        ];
        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $resolved = brandResolveAssetFile((string)($row['file_path'] ?? ''));
                $isPackaged = false;
                if (!$resolved['exists'] && isset($packagedDefaults[$row['asset_key']])) {
                    foreach ($packagedDefaults[$row['asset_key']] as $pack) {
                        if (is_file($pack['disk']) && filesize($pack['disk']) > 32) {
                            $resolved = ['exists' => true, 'size' => filesize($pack['disk']), 'web' => $pack['web'], 'disk' => $pack['disk']];
                            $isPackaged = true;
                            break;
                        }
                    }
                } elseif ($resolved['exists']) {
                    foreach ($packagedDefaults[$row['asset_key']] ?? [] as $pack) {
                        if ($resolved['web'] === $pack['web']) {
                            $isPackaged = empty($row['uploaded_by']);
                            break;
                        }
                    }
                }
                $row['file_exists'] = $resolved['exists'];
                $row['file_size'] = $resolved['size'];
                $row['is_packaged'] = $isPackaged;
                $bust = ($resolved['disk'] && is_file($resolved['disk'])) ? filemtime($resolved['disk']) : time();
                $row['web_url'] = $resolved['exists'] ? $resolved['web'] . '?v=' . $bust : null;
                if ($resolved['exists'] && empty($row['original_name'])) {
                    $row['original_name'] = basename($resolved['web']);
                }
                $assets[] = $row;
            }
        }
        
        $settings = \App\Services\IdCardLayout::load($conn);
        $schema = \App\Services\IdCardLayout::designerSchema();

        echo json_encode([
            'status' => 'success',
            'assets' => $assets,
            'settings' => $settings,
            'schema' => $schema,
        ], JSON_UNESCAPED_UNICODE);
        break;

    // ============================================================
    // UPLOAD / REPLACE ASSET
    // ============================================================
    case 'upload_asset':
        $assetKey = trim($_POST['asset_key'] ?? '');
        
        // Validate asset key format (only alphanumeric + underscore)
        if (!$assetKey || !preg_match('/^[a-z0-9_]{1,50}$/', $assetKey)) {
            echo json_encode(['status' => 'error', 'message' => 'Invalid asset key']);
            exit;
        }
        
        // Get asset record
        $stmt = $conn->prepare("SELECT * FROM system_branding WHERE asset_key = ?");
        if (!$stmt) {
            reportInternalError('Branding asset lookup prepare failed', $conn->error);
            echo json_encode(['status' => 'error', 'message' => 'Branding storage is temporarily unavailable.']);
            exit;
        }
        $stmt->bind_param("s", $assetKey);
        $stmt->execute();
        $asset = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        
        if (!$asset) {
            echo json_encode(['status' => 'error', 'message' => 'Unknown asset key: ' . $assetKey]);
            exit;
        }
        
        // Validate file upload
        if (empty($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
            $errors = [
                UPLOAD_ERR_INI_SIZE   => 'File too large (server limit: ' . ini_get('upload_max_filesize') . ')',
                UPLOAD_ERR_FORM_SIZE  => 'File too large (form limit)',
                UPLOAD_ERR_PARTIAL    => 'Upload incomplete — please try again',
                UPLOAD_ERR_NO_FILE    => 'No file selected',
                UPLOAD_ERR_NO_TMP_DIR => 'Server config error: no temp directory',
                UPLOAD_ERR_CANT_WRITE => 'Server error: cannot write to disk',
            ];
            $errCode = $_FILES['file']['error'] ?? UPLOAD_ERR_NO_FILE;
            echo json_encode(['status' => 'error', 'message' => $errors[$errCode] ?? 'Upload error (code: ' . $errCode . ')']);
            exit;
        }
        
        $file = $_FILES['file'];
        $tmpPath = (string)($file['tmp_name'] ?? '');
        $actualSize = $tmpPath !== '' ? @filesize($tmpPath) : false;
        $allowedTypes = ['image/png', 'image/jpeg', 'image/gif', 'image/webp'];
        $maxSize = 5 * 1024 * 1024; // 5MB
        if ($tmpPath === '' || !is_uploaded_file($tmpPath) || $actualSize === false || $actualSize <= 0) {
            echo json_encode(['status' => 'error', 'message' => 'The uploaded image could not be verified.']);
            exit;
        }
        
        // Check MIME type using finfo (not the browser-reported type)
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mimeType = finfo_file($finfo, $tmpPath);
        finfo_close($finfo);
        
        if (!in_array($mimeType, $allowedTypes, true) || @getimagesize($tmpPath) === false) {
            echo json_encode(['status' => 'error', 'message' => 'Use a valid PNG, JPG, GIF, or WebP image.']);
            exit;
        }
        
        if ($actualSize > $maxSize) {
            echo json_encode(['status' => 'error', 'message' => 'File too large (' . round($actualSize/1024/1024, 1) . 'MB). Maximum 5MB.']);
            exit;
        }
        
        // Determine file extension from MIME (more reliable than filename extension)
        $mimeToExt = [
            'image/png' => 'png', 'image/jpeg' => 'jpg', 'image/gif' => 'gif',
            'image/webp' => 'webp'
        ];
        $ext = $mimeToExt[$mimeType] ?? 'png';
        
        // Determine target directory and filename based on asset key
        $assetFileMap = [
            'logo'      => ['dir' => 'logos',        'name' => 'school_logo'],
            'seal'      => ['dir' => 'seals',        'name' => 'school_seal'],
            'sig_head'  => ['dir' => 'signatures',   'name' => 'head_signature'],
            'sig_admin' => ['dir' => 'signatures',   'name' => 'director_signature'],
            'card_bg'   => ['dir' => 'backgrounds',  'name' => 'id_card_bg'],
        ];
        
        if (isset($assetFileMap[$assetKey])) {
            $targetDir = $dirs[$assetFileMap[$assetKey]['dir']];
            $targetFile = $assetFileMap[$assetKey]['name'] . '.' . $ext;
        } else {
            // Custom asset — use sanitized key as filename
            $targetDir = $dirs['logos'];
            $targetFile = preg_replace('/[^a-z0-9_]/', '', $assetKey) . '.' . $ext;
        }
        
        if (!is_dir($targetDir) && !mkdir($targetDir, 0755, true) && !is_dir($targetDir)) {
            echo json_encode(['status' => 'error', 'message' => 'Upload storage is unavailable.']);
            exit;
        }
        $targetPath = $targetDir . '/' . $targetFile;
        $subDir = basename($targetDir); // 'logos', 'seals', or 'signatures'
        $webPath = '/admin/id_cards/assets/' . $subDir . '/' . $targetFile;
        
        // Stage the upload under a non-executable random name. The database and
        // visible asset are switched together; failures restore the old file.
        if (!is_writable($targetDir)) {
            echo json_encode(['status' => 'error', 'message' => 'Upload storage is unavailable.']);
            exit;
        }
        $tempPath = $targetDir . '/.' . bin2hex(random_bytes(16)) . '.tmp';
        if (!move_uploaded_file($tmpPath, $tempPath)) {
            echo json_encode(['status' => 'error', 'message' => 'Unable to stage the uploaded image.']);
            exit;
        }
        @chmod($tempPath, 0600);

        $stmt = $conn->prepare("UPDATE system_branding
            SET file_path = ?, original_name = ?, mime_type = ?, file_size = ?, uploaded_by = ?, uploaded_at = NOW()
            WHERE asset_key = ?");
        if (!$stmt) {
            @unlink($tempPath);
            reportInternalError('Branding asset update prepare failed', $conn->error);
            echo json_encode(['status' => 'error', 'message' => 'Unable to save the branding asset.']);
            exit;
        }

        $originalName = basename(str_replace('\\', '/', (string)($file['name'] ?? 'image')));
        $originalName = mb_substr(preg_replace('/[\x00-\x1F\x7F]+/u', '', $originalName), 0, 255);
        $fileSize = (int)$actualSize;
        $uploader = (int)($_SESSION['admin_id'] ?? 0);
        $stmt->bind_param("sssiis", $webPath, $originalName, $mimeType, $fileSize, $uploader, $assetKey);

        $backups = [];
        $activated = false;
        try {
            $conn->begin_transaction();
            if (!$stmt->execute()) {
                throw new RuntimeException($stmt->error ?: 'branding update failed');
            }

            $replaceFiles = [$targetPath];
            $oldResolved = brandResolveAssetFile((string)($asset['file_path'] ?? ''));
            $assetRoot = realpath($baseDir);
            if ($oldResolved['exists'] && $assetRoot !== false) {
                $oldReal = realpath($oldResolved['disk']);
                if ($oldReal !== false && strpos($oldReal, $assetRoot . DIRECTORY_SEPARATOR) === 0) {
                    $replaceFiles[] = $oldReal;
                }
            }
            foreach (array_unique($replaceFiles) as $replaceFile) {
                if (!is_file($replaceFile)) continue;
                $backup = $replaceFile . '.rollback-' . bin2hex(random_bytes(6));
                if (!rename($replaceFile, $backup)) {
                    throw new RuntimeException('could not stage existing branding file');
                }
                $backups[$replaceFile] = $backup;
            }

            if (!rename($tempPath, $targetPath)) {
                throw new RuntimeException('could not activate branding file');
            }
            $activated = true;
            @chmod($targetPath, 0644);
            if (!$conn->commit()) {
                throw new RuntimeException('could not commit branding update');
            }
            foreach ($backups as $backup) @unlink($backup);

            echo json_encode([
                'status' => 'success',
                'message' => $asset['asset_label'] . ' uploaded successfully!',
                'file_path' => $webPath,
                'web_url' => $webPath . '?v=' . time()
            ]);
        } catch (Throwable $error) {
            try { $conn->rollback(); } catch (Throwable $ignored) {}
            if ($activated && is_file($targetPath)) @unlink($targetPath);
            foreach ($backups as $original => $backup) {
                if (is_file($backup)) @rename($backup, $original);
            }
            if (is_file($tempPath)) @unlink($tempPath);
            reportInternalError('Branding asset activation failed', $error);
            echo json_encode(['status' => 'error', 'message' => 'Unable to activate the branding image.']);
        }
        $stmt->close();
        break;

    // ============================================================
    // DELETE ASSET (Reset to empty)
    // ============================================================
    case 'delete_asset':
        $assetKey = trim($_POST['asset_key'] ?? '');
        
        if (!$assetKey || !preg_match('/^[a-z0-9_]{1,50}$/', $assetKey)) {
            echo json_encode(['status' => 'error', 'message' => 'Invalid asset key']);
            exit;
        }
        
        // Get current file path
        $stmt = $conn->prepare("SELECT file_path, asset_label FROM system_branding WHERE asset_key = ?");
        if (!$stmt) {
            echo json_encode(['status' => 'error', 'message' => 'Database error']);
            exit;
        }
        $stmt->bind_param("s", $assetKey);
        $stmt->execute();
        $asset = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        
        if (!$asset) {
            echo json_encode(['status' => 'error', 'message' => 'Unknown asset']);
            exit;
        }
        
        // Delete uploaded file from disk — never remove the packaged school logo / church art
        $packagedKeep = [
            '/admin/id_cards/assets/backgrounds/id_card_bg.jpg',
            '/admin/id_cards/assets/logos/school_logo.png',
            '/themes/fkss/assets/logos/school_logo.png',
        ];
        if (!empty($asset['file_path']) && !in_array($asset['file_path'], $packagedKeep, true)) {
            $filePath = ($_SERVER['DOCUMENT_ROOT'] ?? dirname(__DIR__)) . $asset['file_path'];
            if (file_exists($filePath)) {
                @unlink($filePath);
            }
        }
        
        // Clear database record (keep the row, just null the file fields)
        $stmt = $conn->prepare("UPDATE system_branding 
            SET file_path = NULL, original_name = NULL, mime_type = NULL, file_size = 0, uploaded_by = NULL
            WHERE asset_key = ?");
        if ($stmt) {
            $stmt->bind_param("s", $assetKey);
            $stmt->execute();
            $stmt->close();
        }
        
        echo json_encode(['status' => 'success', 'message' => ($asset['asset_label'] ?? 'Asset') . ' removed']);
        break;

    // ============================================================
    // UPDATE LABEL (rename asset slot)
    // ============================================================
    case 'update_label':
        $assetKey = trim($_POST['asset_key'] ?? '');
        $newLabel = trim($_POST['label'] ?? '');
        
        if (!$assetKey || !$newLabel) {
            echo json_encode(['status' => 'error', 'message' => 'Key and label required']);
            exit;
        }
        
        // Sanitize label (max 100 chars, strip tags)
        $newLabel = mb_substr(strip_tags($newLabel), 0, 100);
        
        $stmt = $conn->prepare("UPDATE system_branding SET asset_label = ? WHERE asset_key = ?");
        if (!$stmt) {
            echo json_encode(['status' => 'error', 'message' => 'Database error']);
            exit;
        }
        $stmt->bind_param("ss", $newLabel, $assetKey);
        
        if ($stmt->execute() && $stmt->affected_rows >= 0) {
            echo json_encode(['status' => 'success', 'message' => 'Label updated']);
        } else {
            reportInternalError('Branding label update failed', $stmt->error);
            echo json_encode(['status' => 'error', 'message' => 'Unable to update the label.']);
        }
        $stmt->close();
        break;

    // ============================================================
    // ADD CUSTOM ASSET SLOT
    // ============================================================
    case 'add_asset':
        $assetKey = preg_replace('/[^a-z0-9_]/', '', strtolower(trim($_POST['asset_key'] ?? '')));
        $assetLabel = mb_substr(strip_tags(trim($_POST['asset_label'] ?? '')), 0, 100);
        
        if (!$assetKey || !$assetLabel) {
            echo json_encode(['status' => 'error', 'message' => 'Key and label required']);
            exit;
        }
        
        if (strlen($assetKey) > 50) {
            echo json_encode(['status' => 'error', 'message' => 'Key too long (max 50 chars)']);
            exit;
        }
        
        // Prevent reserved keys
        $reserved = ['logo', 'seal', 'sig_head', 'sig_admin', 'card_bg', '_id_card_settings'];
        if (in_array($assetKey, $reserved)) {
            echo json_encode(['status' => 'error', 'message' => 'That key is reserved']);
            exit;
        }
        
        $stmt = $conn->prepare("INSERT INTO system_branding (asset_key, asset_label) VALUES (?, ?)");
        if (!$stmt) {
            echo json_encode(['status' => 'error', 'message' => 'Database error']);
            exit;
        }
        $stmt->bind_param("ss", $assetKey, $assetLabel);
        
        if ($stmt->execute()) {
            echo json_encode(['status' => 'success', 'message' => 'New asset slot "' . $assetLabel . '" added']);
        } else {
            if ($conn->errno === 1062) {
                echo json_encode(['status' => 'error', 'message' => 'An asset with that key already exists']);
            } else {
                reportInternalError('Branding asset slot insert failed', $stmt->error);
                echo json_encode(['status' => 'error', 'message' => 'Unable to add the asset slot.']);
            }
        }
        $stmt->close();
        break;

    // ============================================================
    // REMOVE CUSTOM ASSET SLOT
    // ============================================================
    case 'remove_asset_slot':
        $assetKey = trim($_POST['asset_key'] ?? '');
        $protected = ['logo', 'seal', 'sig_head', 'sig_admin', 'card_bg', '_id_card_settings'];
        
        if (!$assetKey) {
            echo json_encode(['status' => 'error', 'message' => 'Asset key required']);
            exit;
        }
        
        if (in_array($assetKey, $protected)) {
            echo json_encode(['status' => 'error', 'message' => 'Cannot remove system default assets']);
            exit;
        }
        
        // Delete file from disk first
        $stmt = $conn->prepare("SELECT file_path FROM system_branding WHERE asset_key = ?");
        if ($stmt) {
            $stmt->bind_param("s", $assetKey);
            $stmt->execute();
            $asset = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            
            if ($asset && !empty($asset['file_path'])) {
                $fp = ($_SERVER['DOCUMENT_ROOT'] ?? dirname(__DIR__, 2)) . $asset['file_path'];
                if (file_exists($fp)) @unlink($fp);
            }
        }
        
        // Delete the DB row
        $stmt = $conn->prepare("DELETE FROM system_branding WHERE asset_key = ? AND asset_key NOT IN ('logo','seal','sig_head','sig_admin','_id_card_settings')");
        if ($stmt) {
            $stmt->bind_param("s", $assetKey);
            $stmt->execute();
            $stmt->close();
        }
        
        echo json_encode(['status' => 'success', 'message' => 'Asset slot removed']);
        break;

    // ============================================================
    // SAVE ID CARD DISPLAY SETTINGS (size, opacity)
    // ============================================================
    case 'save_settings':
        $rawSettings = trim($_POST['settings'] ?? '{}');
        
        // Validate and sanitize the JSON
        $decoded = json_decode($rawSettings, true);
        if (!is_array($decoded)) {
            echo json_encode(['status' => 'error', 'message' => 'Invalid settings format']);
            exit;
        }
        
        $clean = \App\Services\IdCardLayout::sanitize($decoded);
        $safeJson = json_encode($clean, JSON_UNESCAPED_SLASHES);
        
        // Upsert the settings row
        $conn->query("INSERT IGNORE INTO system_branding (asset_key, asset_label) 
            VALUES ('_id_card_settings', 'ID Card Display Settings')");
        
        $stmt = $conn->prepare("UPDATE system_branding SET original_name = ? WHERE asset_key = '_id_card_settings'");
        if ($stmt) {
            $stmt->bind_param("s", $safeJson);
            if ($stmt->execute()) {
                echo json_encode(['status' => 'success', 'message' => 'Saved. Every member ID card will use this layout.']);
            } else {
                reportInternalError('ID card layout save failed', $stmt->error);
                echo json_encode(['status' => 'error', 'message' => 'Unable to save the ID card layout.']);
            }
            $stmt->close();
        } else {
            echo json_encode(['status' => 'error', 'message' => 'Database error']);
        }
        break;

    default:
        echo json_encode(['status' => 'error', 'message' => 'Unknown action: ' . htmlspecialchars($action)]);
}

$conn->close();
