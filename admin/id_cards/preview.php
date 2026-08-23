<?php
/**
 * Sample ID card for the designer. Dummy names only — no real member PII.
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/libs/eth_date_helper.php';
require_once __DIR__ . '/../backend/services/IdCardLayout.php';

$role = (string)($_SESSION['admin_role'] ?? '');
if (!in_array($role, ['super_admin', 'school_admin'], true)) {
    http_response_code(403);
    echo 'Not allowed.';
    exit;
}

$side = ($_GET['side'] ?? 'front') === 'back' ? 'back' : 'front';
$layout = \App\Services\IdCardLayout::load($conn);
$idCardBg = \App\Services\IdCardLayout::background($conn);
$ID_CARD_STYLE = \App\Services\IdCardLayout::cssVars($layout, $idCardBg);
$ID_CARD_SIDE = $side;

$CONFIG = [
    'logo'      => '/admin/id_cards/assets/logos/school_logo.png',
    'seal'      => '',
    'sig_head'  => '',
    'sig_admin' => '',
];
$docRoot = rtrim((string)($_SERVER['DOCUMENT_ROOT'] ?? dirname(__DIR__, 2)), '/');
$assetOnDisk = static function (string $web) use ($docRoot): bool {
    if ($web === '' || $web[0] !== '/') {
        return false;
    }
    foreach ([$docRoot . $web, dirname(__DIR__, 2) . $web] as $disk) {
        if (is_file($disk) && filesize($disk) > 32) {
            return true;
        }
    }
    return false;
};
if ($conn && !$conn->connect_error) {
    $tableCheck = $conn->query("SHOW TABLES LIKE 'system_branding'");
    if ($tableCheck && $tableCheck->num_rows > 0) {
        $br = $conn->query("SELECT asset_key, file_path FROM system_branding");
        if ($br) {
            while ($row = $br->fetch_assoc()) {
                $key = (string)($row['asset_key'] ?? '');
                $path = (string)($row['file_path'] ?? '');
                if (isset($CONFIG[$key]) && $path !== '' && $assetOnDisk($path)) {
                    $CONFIG[$key] = $path;
                }
            }
        }
    }
}
if (!$assetOnDisk($CONFIG['logo'])) {
    $themeLogo = defined('SCHOOL_LOGO_PATH') ? SCHOOL_LOGO_PATH : '/themes/fkss/assets/logos/school_logo.png';
    $CONFIG['logo'] = $assetOnDisk($themeLogo) ? $themeLogo : '';
}

$member = [
    'gender' => 'male',
    'member_code' => '69711',
    'phone_number' => '+251911000000',
    'address' => 'Addis Ababa, Bole',
    'student_photo_path' => '',
    'qr_code_path' => '',
    'emergency_name' => 'መስፍን ታደሰ',
    'emergency_phone' => '+251922000000',
];
$full_name = 'መስፍን መስፍን መስፍን';
$christian_name = 'መስፍን';
$age = 18;
$issueDateEth = 'ሰኔ 12 ቀን 2018 ዓ.ም';
$expiryDateEth = 'ሰኔ 12 ቀን 2022 ዓ.ም';
$DISPLAY = [
    'logo_size' => (int)$layout['logo_size'],
    'logo_opacity' => (int)$layout['logo_opacity'],
    'seal_size' => (int)$layout['seal_size'],
    'seal_opacity' => (int)$layout['seal_opacity'],
    'sig_head_size' => (int)$layout['sig_head_size'],
    'sig_head_opacity' => (int)$layout['sig_head_opacity'],
    'sig_admin_size' => (int)$layout['sig_admin_size'],
    'sig_admin_opacity' => (int)$layout['sig_admin_opacity'],
];
?>
<!DOCTYPE html>
<html lang="am">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>ID preview</title>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Noto+Sans+Ethiopic:wght@400;700;900&display=swap">
<link rel="stylesheet" href="/admin/css/id_card.css?v=20260823b">
<style>
html,body{margin:0;padding:0;background:transparent;overflow:hidden}
</style>
</head>
<body>
<?php include __DIR__ . '/id_card_template_layout.php'; ?>
<script>
(function () {
    var edit = <?= json_encode(($_GET['edit'] ?? '') === '1') ?>;
    if (edit) {
        document.querySelectorAll('.id-card-template').forEach(function (el) {
            el.classList.add('idc-edit');
        });
        document.body.addEventListener('click', function (e) {
            var hit = e.target.closest('[data-idc]');
            if (!hit) return;
            e.preventDefault();
            if (window.parent && window.parent !== window) {
                window.parent.postMessage({ type: 'id-pick', id: hit.getAttribute('data-idc') }, window.location.origin);
            }
        });
    }
    function mark(id) {
        document.querySelectorAll('[data-idc]').forEach(function (el) {
            el.classList.toggle('idc-on', id && el.getAttribute('data-idc') === id);
        });
    }
    window.addEventListener('message', function (e) {
        if (e.origin !== window.location.origin) return;
        if (!e.data || e.data.type !== 'id-layout') return;
        var style = e.data.style || '';
        document.querySelectorAll('.id-card-template').forEach(function (el) {
            el.setAttribute('style', style);
            if (edit) el.classList.add('idc-edit');
        });
        if (e.data.bg) {
            document.querySelectorAll('.id-card-bg').forEach(function (el) {
                el.style.backgroundImage = 'url(' + e.data.bg + ')';
            });
        }
        if (e.data.pick) mark(e.data.pick);
    });
})();
</script>
</body>
</html>
