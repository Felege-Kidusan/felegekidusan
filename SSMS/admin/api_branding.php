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
    'logos'      => $baseDir . '/logos',
    'seals'      => $baseDir . '/seals',
    'signatures' => $baseDir . '/signatures',
];

// Create directories if they don't exist
foreach ($dirs as $dir) {
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
}

// ══════════════════════════════════════════════════════════════
// BULLETPROOF TABLE CREATION — The #1 crash source was here
// The old code used try/catch, but MySQLi was NOT in exception 
// mode, so $conn->query() returned FALSE silently and the catch
// never fired. Then later queries crashed with "table doesn't exist".
// 
// FIX: Check return value explicitly + verify table exists after.
// Also suppress MySQLi exceptions during CREATE TABLE to avoid
// fatal errors on hosts with strict SQL modes.
// ══════════════════════════════════════════════════════════════
$brandingTableReady = false;

// ── Bulletproof table initialization ──
// Wrapped in Throwable catch for PHP 8.1+ where MySQLi throws exceptions by default
try {
    // Step 1: Check if table already exists (fast path)
    $tableCheck = $conn->query("SHOW TABLES LIKE 'system_branding'");
    if ($tableCheck && $tableCheck->num_rows > 0) {
        $brandingTableReady = true;
    }
} catch (\Throwable $e) {
    // Connection issue or other DB error — try to continue with table creation
    error_log("BRANDING TABLE CHECK ERROR: " . $e->getMessage());
}

if (!$brandingTableReady) {
    // Step 2: Table doesn't exist — create it
    $createSql = "CREATE TABLE IF NOT EXISTS `system_branding` (
        `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
        `asset_key` VARCHAR(50) NOT NULL,
        `asset_label` VARCHAR(100) NOT NULL DEFAULT '',
        `file_path` VARCHAR(500) DEFAULT NULL,
        `original_name` VARCHAR(255) DEFAULT NULL,
        `mime_type` VARCHAR(100) DEFAULT NULL,
        `file_size` INT UNSIGNED DEFAULT 0,
        `uploaded_by` INT UNSIGNED DEFAULT NULL,
        `uploaded_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        UNIQUE KEY `uk_asset_key` (`asset_key`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
    
    // Use Throwable catch for PHP 8.1+ mysqli exception mode
    $createResult = false;
    try {
        $createResult = $conn->query($createSql);
    } catch (\Throwable $e) {
        error_log("BRANDING TABLE CREATE EXCEPTION: " . $e->getMessage());
        $createResult = false;
    }
    
    if ($createResult === false) {
        error_log("BRANDING TABLE CREATE FAILED: " . $conn->error);
        echo json_encode([
            'status' => 'error', 
            'message' => 'System setup required. Could not create branding table. Please run the migration SQL manually.',
            'migration_url' => '/admin/migrations/005_create_system_branding.php'
        ]);
        exit;
    }
    
    // Verify table now exists
    try {
        $verifyCheck = $conn->query("SHOW TABLES LIKE 'system_branding'");
        if ($verifyCheck && $verifyCheck->num_rows > 0) {
            $brandingTableReady = true;
        }
    } catch (\Throwable $e) {
        error_log("BRANDING TABLE VERIFY ERROR: " . $e->getMessage());
    }
    
    if (!$brandingTableReady) {
        error_log("BRANDING TABLE: Created without error but table still missing. Possible permissions issue.");
        echo json_encode(['status' => 'error', 'message' => 'Table creation succeeded but table not found. Check database permissions.']);
        exit;
    }
    
    // Step 3: Seed default entries (only runs once when table is first created)
    $defaults = [
        ['logo',      'School Logo',                  '/admin/id_cards/assets/logos/school_logo.png'],
        ['seal',      'School Seal / Stamp',           '/admin/id_cards/assets/seals/school_seal.png'],
        ['sig_head',  'Head Teacher Signature',        '/admin/id_cards/assets/signatures/head_signature.png'],
        ['sig_admin', 'Director / Admin Signature',    '/admin/id_cards/assets/signatures/director_signature.png'],
    ];
    $seedStmt = $conn->prepare("INSERT IGNORE INTO system_branding (asset_key, asset_label, file_path) VALUES (?, ?, ?)");
    if ($seedStmt) {
        foreach ($defaults as $d) {
            $seedStmt->bind_param("sss", $d[0], $d[1], $d[2]);
            $seedStmt->execute();
        }
        $seedStmt->close();
    }
}

// ── Final safety gate ──
if (!$brandingTableReady) {
    echo json_encode(['status' => 'error', 'message' => 'Branding system not ready. Please contact the administrator.']);
    exit;
}

// ══════════════════════════════════════════════════════════════
// HELPER: Safe query that returns empty result instead of crash
// ══════════════════════════════════════════════════════════════
function brandQuery($conn, $sql) {
    try {
        $result = $conn->query($sql);
        if ($result === false) {
            error_log("BRANDING QUERY ERROR: " . $conn->error . " | SQL: " . $sql);
            return null;
        }
        return $result;
    } catch (\Throwable $e) {
        error_log("BRANDING QUERY EXCEPTION: " . $e->getMessage() . " | SQL: " . $sql);
        return null;
    }
    return $result;
}

$action = $_REQUEST['action'] ?? '';

switch ($action) {

    // ============================================================
    // GET ALL BRANDING ASSETS
    // ============================================================
    case 'get_assets':
        $result = brandQuery($conn, "SELECT * FROM system_branding WHERE asset_key != '_id_card_settings' ORDER BY id");
        $assets = [];
        if ($result) {
            while ($row = $result->fetch_assoc()) {
                // Check file existence using both DOCUMENT_ROOT and __DIR__ approaches
                $fileExists = false;
                if (!empty($row['file_path'])) {
                    // Method 1: DOCUMENT_ROOT (works in normal web context)
                    $absPath1 = ($_SERVER['DOCUMENT_ROOT'] ?? '') . $row['file_path'];
                    // Method 2: Relative from this file (fallback)
                    $absPath2 = realpath(__DIR__ . '/..' . $row['file_path']);
                    // Method 3: Direct from /admin/ base
                    $absPath3 = __DIR__ . '/id_cards/assets/' . basename(dirname($row['file_path'])) . '/' . basename($row['file_path']);
                    
                    $fileExists = file_exists($absPath1) || ($absPath2 && file_exists($absPath2)) || file_exists($absPath3);
                }
                $row['file_exists'] = $fileExists;
                $row['web_url'] = !empty($row['file_path']) ? $row['file_path'] . '?v=' . time() : null;
                $assets[] = $row;
            }
        }
        
        // Load display settings
        $settings = null;
        $sr = brandQuery($conn, "SELECT original_name FROM system_branding WHERE asset_key = '_id_card_settings'");
        if ($sr && $s = $sr->fetch_assoc()) {
            $decoded = json_decode($s['original_name'], true);
            if (is_array($decoded)) {
                // Sanitize: only allow known keys with numeric values
                $allowedKeys = ['logo_size','logo_opacity','seal_size','seal_opacity',
                                'sig_head_size','sig_head_opacity','sig_admin_size','sig_admin_opacity'];
                foreach ($decoded as $k => $v) {
                    if (in_array($k, $allowedKeys) && is_numeric($v)) {
                        $settings[$k] = (int)$v;
                    }
                }
            }
        }
        
        echo json_encode(['status' => 'success', 'assets' => $assets, 'settings' => $settings]);
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
            echo json_encode(['status' => 'error', 'message' => 'Database error: ' . $conn->error]);
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
        $allowedTypes = ['image/png', 'image/jpeg', 'image/gif', 'image/webp'];
        $maxSize = 5 * 1024 * 1024; // 5MB
        
        // Check MIME type using finfo (not the browser-reported type)
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mimeType = finfo_file($finfo, $file['tmp_name']);
        finfo_close($finfo);
        
        if (!in_array($mimeType, $allowedTypes)) {
            echo json_encode(['status' => 'error', 'message' => 'Invalid file type (' . $mimeType . '). Allowed: PNG, JPG, GIF, WebP']);
            exit;
        }
        
        if ($file['size'] > $maxSize) {
            echo json_encode(['status' => 'error', 'message' => 'File too large (' . round($file['size']/1024/1024, 1) . 'MB). Maximum 5MB.']);
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
            'logo'      => ['dir' => 'logos',      'name' => 'school_logo'],
            'seal'      => ['dir' => 'seals',      'name' => 'school_seal'],
            'sig_head'  => ['dir' => 'signatures',  'name' => 'head_signature'],
            'sig_admin' => ['dir' => 'signatures',  'name' => 'director_signature'],
        ];
        
        if (isset($assetFileMap[$assetKey])) {
            $targetDir = $dirs[$assetFileMap[$assetKey]['dir']];
            $targetFile = $assetFileMap[$assetKey]['name'] . '.' . $ext;
        } else {
            // Custom asset — use sanitized key as filename
            $targetDir = $dirs['logos'];
            $targetFile = preg_replace('/[^a-z0-9_]/', '', $assetKey) . '.' . $ext;
        }
        
        $targetPath = $targetDir . '/' . $targetFile;
        $subDir = basename($targetDir); // 'logos', 'seals', or 'signatures'
        $webPath = '/admin/id_cards/assets/' . $subDir . '/' . $targetFile;
        
        // Delete old file if exists and has different extension
        if (!empty($asset['file_path'])) {
            $oldFile = ($_SERVER['DOCUMENT_ROOT'] ?? dirname(__DIR__, 2)) . $asset['file_path'];
            if (file_exists($oldFile) && realpath($oldFile) !== realpath($targetPath)) {
                @unlink($oldFile);
            }
        }
        
        // Ensure target directory is writable
        if (!is_writable($targetDir)) {
            echo json_encode(['status' => 'error', 'message' => 'Upload directory is not writable. Please chmod 755 the assets folder.']);
            exit;
        }
        
        // Move uploaded file
        if (!move_uploaded_file($file['tmp_name'], $targetPath)) {
            echo json_encode(['status' => 'error', 'message' => 'Failed to save file. Check directory permissions on: ' . $subDir . '/']);
            exit;
        }
        
        // Make file readable
        @chmod($targetPath, 0644);
        
        // Update database
        $stmt = $conn->prepare("UPDATE system_branding 
            SET file_path = ?, original_name = ?, mime_type = ?, file_size = ?, uploaded_by = ?, uploaded_at = NOW()
            WHERE asset_key = ?");
        if (!$stmt) {
            echo json_encode(['status' => 'error', 'message' => 'Database error: ' . $conn->error]);
            exit;
        }
        $stmt->bind_param("ssssis", $webPath, $file['name'], $mimeType, $file['size'], $_SESSION['admin_id'], $assetKey);
        
        if ($stmt->execute()) {
            echo json_encode([
                'status' => 'success',
                'message' => $asset['asset_label'] . ' uploaded successfully!',
                'file_path' => $webPath,
                'web_url' => $webPath . '?v=' . time()
            ]);
        } else {
            echo json_encode(['status' => 'error', 'message' => 'File saved but database update failed: ' . $stmt->error]);
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
        
        // Delete file from disk
        if (!empty($asset['file_path'])) {
            $filePath = ($_SERVER['DOCUMENT_ROOT'] ?? dirname(__DIR__, 2)) . $asset['file_path'];
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
            echo json_encode(['status' => 'error', 'message' => 'Error updating label: ' . $stmt->error]);
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
        $reserved = ['logo', 'seal', 'sig_head', 'sig_admin', '_id_card_settings'];
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
                echo json_encode(['status' => 'error', 'message' => 'Database error: ' . $stmt->error]);
            }
        }
        $stmt->close();
        break;

    // ============================================================
    // REMOVE CUSTOM ASSET SLOT
    // ============================================================
    case 'remove_asset_slot':
        $assetKey = trim($_POST['asset_key'] ?? '');
        $protected = ['logo', 'seal', 'sig_head', 'sig_admin', '_id_card_settings'];
        
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
        
        // Only allow known keys with safe numeric values
        $allowedKeys = ['logo_size','logo_opacity','seal_size','seal_opacity',
                        'sig_head_size','sig_head_opacity','sig_admin_size','sig_admin_opacity'];
        $clean = [];
        foreach ($decoded as $k => $v) {
            if (in_array($k, $allowedKeys) && is_numeric($v)) {
                $clean[$k] = max(0, min(1000, (int)$v)); // Clamp to 0-1000
            }
        }
        $safeJson = json_encode($clean);
        
        // Upsert the settings row
        $conn->query("INSERT IGNORE INTO system_branding (asset_key, asset_label) 
            VALUES ('_id_card_settings', 'ID Card Display Settings')");
        
        $stmt = $conn->prepare("UPDATE system_branding SET original_name = ? WHERE asset_key = '_id_card_settings'");
        if ($stmt) {
            $stmt->bind_param("s", $safeJson);
            if ($stmt->execute()) {
                echo json_encode(['status' => 'success', 'message' => 'Display settings saved!']);
            } else {
                echo json_encode(['status' => 'error', 'message' => 'Failed to save: ' . $stmt->error]);
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
