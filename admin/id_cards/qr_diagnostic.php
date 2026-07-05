<?php
// FILE: /admin/id_cards/qr_diagnostic.php
// RUN THIS ONCE TO CHECK YOUR QR CODE SETUP
// DELETE THIS FILE AFTER USE (for security)
require_once '../config.php';

echo "<pre style='font-family:monospace; font-size:14px; padding:20px;'>\n";
echo "=== QR CODE DIAGNOSTIC REPORT ===\n";
echo "Date: " . date('Y-m-d H:i:s') . "\n\n";

// 1. Check phpqrcode library
$qr_lib = __DIR__ . '/libs/phpqrcode/qrlib.php';
echo "1. QR Library Check:\n";
if (file_exists($qr_lib)) {
    echo "   ✅ phpqrcode library found at: libs/phpqrcode/qrlib.php\n";
} else {
    echo "   ❌ MISSING! phpqrcode library NOT found at: libs/phpqrcode/qrlib.php\n";
    echo "   📥 FIX: Download from https://sourceforge.net/projects/phpqrcode/\n";
    echo "   📁 Extract and upload 'phpqrcode' folder to: admin/id_cards/libs/phpqrcode/\n";
    echo "   📁 Make sure qrlib.php is at: admin/id_cards/libs/phpqrcode/qrlib.php\n";
}

// 2. Check assets/qr directory
echo "\n2. QR Assets Directory:\n";
$qr_dir = __DIR__ . '/assets/qr/';
if (is_dir($qr_dir)) {
    echo "   ✅ Directory exists: assets/qr/\n";
    if (is_writable($qr_dir)) {
        echo "   ✅ Directory is writable\n";
    } else {
        echo "   ❌ Directory is NOT writable — set it to 0755 owned by the web user: chmod 755 admin/id_cards/assets/qr/\n";
    }
    $files = glob($qr_dir . '*.png');
    echo "   📊 QR files found: " . count($files) . "\n";
} else {
    echo "   ❌ Directory does NOT exist\n";
    echo "   🔧 Attempting to create...\n";
    if (mkdir($qr_dir, 0755, true)) {
        echo "   ✅ Created successfully!\n";
    } else {
        echo "   ❌ Could not create! Manually create: admin/id_cards/assets/qr/ and chmod 755\n";
    }
}

// 3. Check assets directory chain
echo "\n3. Directory Chain:\n";
$assets_dir = __DIR__ . '/assets/';
echo "   assets/ exists: " . (is_dir($assets_dir) ? "✅" : "❌ MISSING") . "\n";
echo "   assets/qr/ exists: " . (is_dir($qr_dir) ? "✅" : "❌ MISSING") . "\n";
echo "   assets/logos/ exists: " . (is_dir($assets_dir . 'logos/') ? "✅" : "❌") . "\n";
echo "   assets/seals/ exists: " . (is_dir($assets_dir . 'seals/') ? "✅" : "❌") . "\n";
echo "   assets/signatures/ exists: " . (is_dir($assets_dir . 'signatures/') ? "✅" : "❌") . "\n";

// 4. Check GD library (required by phpqrcode)
echo "\n4. PHP GD Library:\n";
if (extension_loaded('gd')) {
    echo "   ✅ GD library is loaded\n";
    $gd_info = gd_info();
    echo "   Version: " . $gd_info['GD Version'] . "\n";
    echo "   PNG Support: " . ($gd_info['PNG Support'] ? "✅" : "❌") . "\n";
} else {
    echo "   ❌ GD library is NOT loaded! phpqrcode requires it.\n";
    echo "   FIX: Enable php-gd in your hosting panel\n";
}

// 5. Check members with QR issues
echo "\n5. Member QR Status in Database:\n";
$result = $conn->query("SELECT id, member_code, qr_code_path, id_card_status FROM members WHERE id_card_status = 'generated' OR qr_code_path IS NOT NULL LIMIT 20");
if ($result && $result->num_rows > 0) {
    echo "   Members with ID cards:\n";
    while ($row = $result->fetch_assoc()) {
        $qr_db_path = $row['qr_code_path'] ?? 'NULL';
        $qr_exists = false;
        
        if (!empty($row['qr_code_path'])) {
            // Check if file exists (convert web path to absolute)
            $clean_path = ltrim($row['qr_code_path'], '/');
            if (strpos($clean_path, 'admin/id_cards/') === 0) {
                $check_path = __DIR__ . '/' . substr($clean_path, strlen('admin/id_cards/'));
            } else {
                $check_path = $_SERVER['DOCUMENT_ROOT'] . '/' . $clean_path;
            }
            $qr_exists = file_exists($check_path);
        }
        
        $status_icon = $qr_exists ? "✅" : "❌";
        echo "   $status_icon Member #{$row['id']} ({$row['member_code']}): DB path={$qr_db_path} | File exists: " . ($qr_exists ? "YES" : "NO") . "\n";
    }
} else {
    echo "   ⚠️ No members found with generated ID cards\n";
}

// 6. Test QR generation
echo "\n6. QR Generation Test:\n";
if (file_exists($qr_lib) && extension_loaded('gd')) {
    require_once $qr_lib;
    $test_file = $qr_dir . 'test_diagnostic.png';
    
    if (!is_dir($qr_dir)) mkdir($qr_dir, 0755, true); // 0755, never world-writable

    QRcode::png("' . SITE_URL . '/test", $test_file, QR_ECLEVEL_L, 4, 2);
    
    if (file_exists($test_file)) {
        echo "   ✅ QR generation works! Test file created successfully.\n";
        unlink($test_file); // Clean up
    } else {
        echo "   ❌ QR generation FAILED! File was not created.\n";
    }
} else {
    echo "   ⏭️ Skipped (library or GD missing)\n";
}

echo "\n=== END DIAGNOSTIC ===\n";
echo "\n⚠️ DELETE THIS FILE AFTER USE: rm admin/id_cards/qr_diagnostic.php\n";
echo "</pre>";
?>
