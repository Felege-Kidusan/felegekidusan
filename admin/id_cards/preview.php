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
$ID_CARD_STYLE = \App\Services\IdCardLayout::cssVars($layout);
$idCardBg = \App\Services\IdCardLayout::background($conn);
$ID_CARD_SIDE = $side;

$CONFIG = [
    'logo'      => '/admin/id_cards/assets/logos/school_logo.png',
    'seal'      => '/admin/id_cards/assets/seals/school_seal.png',
    'sig_head'  => '/admin/id_cards/assets/signatures/head_signature.png',
    'sig_admin' => '/admin/id_cards/assets/signatures/director_signature.png',
];
if ($conn && !$conn->connect_error) {
    $tableCheck = $conn->query("SHOW TABLES LIKE 'system_branding'");
    if ($tableCheck && $tableCheck->num_rows > 0) {
        $br = $conn->query("SELECT asset_key, file_path FROM system_branding");
        if ($br) {
            while ($row = $br->fetch_assoc()) {
                if (isset($CONFIG[$row['asset_key']]) && !empty($row['file_path'])) {
                    $CONFIG[$row['asset_key']] = $row['file_path'];
                }
            }
        }
    }
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
<link rel="stylesheet" href="/admin/css/id_card.css?v=20260819d">
<style>
html,body{margin:0;padding:0;background:#1e293b;overflow:hidden}
#stage{transform:scale(.52);transform-origin:top left}
</style>
</head>
<body>
<div id="stage">
<?php include __DIR__ . '/id_card_template_layout.php'; ?>
</div>
<script>
window.addEventListener('message', function (e) {
    if (e.origin !== window.location.origin) return;
    if (!e.data || e.data.type !== 'id-layout') return;
    var style = e.data.style || '';
    document.querySelectorAll('.id-card-template').forEach(function (el) {
        el.setAttribute('style', style);
    });
    if (e.data.bg) {
        document.querySelectorAll('.id-card-bg').forEach(function (el) {
            el.style.backgroundImage = 'url(' + e.data.bg + ')';
        });
    }
});
</script>
</body>
</html>
