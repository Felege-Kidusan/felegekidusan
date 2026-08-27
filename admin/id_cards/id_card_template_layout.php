<!-- FILE: /admin/id_cards/id_card_template_layout.php -->
<?php
require_once __DIR__ . '/../backend/services/MemberCodeFormat.php';
if (empty($idCardBg)) {
    $idCardBg = defined('ID_CARD_BACKGROUND') ? ID_CARD_BACKGROUND : '/admin/id_cards/assets/backgrounds/id_card_bg.jpg';
}
$idCardBgEsc = htmlspecialchars((string)$idCardBg, ENT_QUOTES, 'UTF-8');
$genderLabel = '--';
$g = strtolower(trim((string)($member['gender'] ?? '')));
if ($g === 'male' || $g === 'm' || $g === 'ወንድ') {
    $genderLabel = 'ወንድ';
} elseif ($g === 'female' || $g === 'f' || $g === 'ሴት') {
    $genderLabel = 'ሴት';
}
$cardStyle = !empty($ID_CARD_STYLE) ? $ID_CARD_STYLE : '';
$hideSide = $ID_CARD_SIDE ?? '';
$ID_CARD_LAYOUT = is_array($ID_CARD_LAYOUT ?? null) ? $ID_CARD_LAYOUT : [];
$idCardTxt = static function (string $key, string $fallback) use ($ID_CARD_LAYOUT): string {
    $v = trim((string)($ID_CARD_LAYOUT[$key] ?? ''));
    return $v !== '' ? $v : $fallback;
};
$sigHeadText = $idCardTxt('sig_head_text', defined('ID_CARD_SIG_HEAD_AM') ? ID_CARD_SIG_HEAD_AM : 'የሰንበት ት/ቤትቱ ሃላፊ ስምና ፊርማ');
$sigAdminText = $idCardTxt('sig_admin_text', defined('ID_CARD_SIG_ADMIN_AM') ? ID_CARD_SIG_ADMIN_AM : 'የደብሩ አስተዳደር ስምና ፊርማ');
$barBackText = $idCardTxt('bar_back_text', 'የአባል መረጃና የአደጋ ጊዜ ተጠሪ');
$emHeadText = $idCardTxt('em_head_text', 'የአደጋ ጊዜ ተጠሪ መረጃ');
$footFrontText = $idCardTxt('foot_front_text', (defined('SCHOOL_NAME_SHORT_AM') ? SCHOOL_NAME_SHORT_AM : '') . ' ' . (defined('SCHOOL_TYPE_AM') ? SCHOOL_TYPE_AM : ''));
$footBackText = $idCardTxt('foot_back_text', defined('ID_CARD_DISCLAIMER_AM') ? ID_CARD_DISCLAIMER_AM : '');
?>
<?php if ($hideSide !== 'back'): ?>
<div class="id-card-template id-front" style="<?= htmlspecialchars($cardStyle, ENT_QUOTES, 'UTF-8') ?>">
    <div class="id-card-bg" style="background-image:url('<?= $idCardBgEsc ?>')"></div>
    <div class="id-logo" data-idc="logo">
        <?php if (!empty($CONFIG['logo'])): ?>
        <img src="<?php echo htmlspecialchars($CONFIG['logo'], ENT_QUOTES, 'UTF-8'); ?>" alt="" style="width:100%;height:100%;object-fit:contain;background:transparent;" onerror="this.style.display='none'">
        <?php endif; ?>
    </div>
    <header class="id-head" data-idc="header_front">
        <div class="id-head-text">
            <p class="id-invoc" data-idc="invoc"><?= RELIGIOUS_INVOCATION ?></p>
            <h1 class="id-parish" data-idc="parish"><?= PARISH_NAME_AM ?></h1>
            <h2 class="id-title" data-idc="title_front"><?= ID_CARD_TITLE_AM ?></h2>
            <h3 class="id-title-en" data-idc="title_en_front"><?= ID_CARD_TITLE_EN ?></h3>
        </div>
    </header>
    <div class="id-bar" data-idc="bar_front"></div>
    <div class="id-body">
        <div class="id-photo" data-idc="photo">
            <?php if (!empty($member['student_photo_path'])): ?>
            <div style="width:100%;height:100%;background-image:url('<?php echo htmlspecialchars($member['student_photo_path'], ENT_QUOTES, 'UTF-8'); ?>');background-size:cover;background-position:center top;background-repeat:no-repeat;"></div>
            <?php endif; ?>
        </div>
        <div class="id-fields">
            <div class="id-row id-el-name" data-idc="name"><span class="id-lbl">ሙሉ ስም</span><span class="id-val"><?php echo htmlspecialchars($full_name); ?></span></div>
            <div class="id-row id-el-christian" data-idc="christian"><span class="id-lbl">የክርስትና ስም</span><span class="id-val"><?php echo htmlspecialchars($christian_name); ?></span></div>
            <div class="id-row id-row-split">
                <div class="id-el-gender" data-idc="gender"><span class="id-lbl">ጾታ</span><span class="id-val"><?php echo htmlspecialchars($genderLabel); ?></span></div>
                <div class="id-el-age" data-idc="age"><span class="id-lbl">ዕድሜ</span><span class="id-val"><?php echo htmlspecialchars((string)$age); ?></span></div>
            </div>
            <div class="id-row id-el-code" data-idc="code"><span class="id-lbl">የመታወቂያ ቁ.</span><span class="id-val id-code"><?php echo \App\Services\MemberCodeFormat::html((string)($member['member_code'] ?? '')); ?></span></div>
            <div class="id-signs">
                <div data-idc="sig_head">
                    <div class="id-sign-img"><?php if (!empty($CONFIG['sig_head'])): ?><img class="id-sign-head" src="<?php echo htmlspecialchars($CONFIG['sig_head'], ENT_QUOTES, 'UTF-8'); ?>" alt="" onerror="this.style.display='none'"><?php endif; ?></div>
                    <p class="id-sign-lbl id-sign-head-cap" data-idc-text="sig_head_text"><?php echo htmlspecialchars($sigHeadText); ?></p>
                </div>
                <div data-idc="sig_admin">
                    <div class="id-sign-img"><?php if (!empty($CONFIG['sig_admin'])): ?><img class="id-sign-admin" src="<?php echo htmlspecialchars($CONFIG['sig_admin'], ENT_QUOTES, 'UTF-8'); ?>" alt="" onerror="this.style.display='none'"><?php endif; ?></div>
                    <p class="id-sign-lbl id-sign-admin-cap" data-idc-text="sig_admin_text"><?php echo htmlspecialchars($sigAdminText); ?></p>
                </div>
            </div>
        </div>
    </div>
    <div class="id-seal" data-idc="seal">
        <img src="<?php echo htmlspecialchars($CONFIG['seal'], ENT_QUOTES, 'UTF-8'); ?>" alt="">
    </div>
    <footer class="id-foot" data-idc="foot_front">
        <h2 data-idc-text="foot_front_text"><?php echo htmlspecialchars($footFrontText); ?></h2>
    </footer>
</div>
<?php endif; ?>

<?php if ($hideSide !== 'front'): ?>
<div class="id-card-template id-back" style="<?= htmlspecialchars($cardStyle, ENT_QUOTES, 'UTF-8') ?>">
    <div class="id-card-bg" style="background-image:url('<?= $idCardBgEsc ?>')"></div>
    <header class="id-head id-head-simple" data-idc="header_back">
        <div class="id-head-text">
            <h2 class="id-title" data-idc="title_back"><?= ID_CARD_TITLE_AM ?></h2>
            <h3 class="id-title-en" data-idc="title_en_back"><?= ID_CARD_TITLE_EN ?></h3>
        </div>
    </header>
    <div class="id-bar id-bar-label" data-idc="bar_back" data-idc-text="bar_back_text"><?php echo htmlspecialchars($barBackText); ?></div>
    <div class="id-body id-body-back">
        <div class="id-fields">
            <div class="id-row id-el-phone" data-idc="phone"><span class="id-lbl">ስልክ ቁጥር</span><span class="id-val"><?php echo htmlspecialchars((string)($member['phone_number'] ?? '')); ?></span></div>
            <div class="id-row id-el-address" data-idc="address"><span class="id-lbl">የመኖሪያ አድራሻ</span><span class="id-val"><?php echo htmlspecialchars((string)($member['address'] ?? '')); ?></span></div>
            <h4 class="id-subhead" data-idc="em_head" data-idc-text="em_head_text"><?php echo htmlspecialchars($emHeadText); ?></h4>
            <div class="id-row id-el-em-name" data-idc="em_name"><span class="id-lbl">ሙሉ ስም</span><span class="id-val"><?php echo htmlspecialchars((string)$member['emergency_name']); ?></span></div>
            <div class="id-row id-el-em-phone" data-idc="em_phone"><span class="id-lbl">ስልክ ቁጥር</span><span class="id-val"><?php echo htmlspecialchars((string)$member['emergency_phone']); ?></span></div>
            <div class="id-row id-row-split">
                <div class="id-el-issue" data-idc="issue"><span class="id-lbl">የተሰጠበት ቀን</span><span class="id-val"><?php echo htmlspecialchars($issueDateEth); ?></span></div>
                <div class="id-el-expiry" data-idc="expiry"><span class="id-lbl">የሚያበቃበት ቀን</span><span class="id-val"><?php echo htmlspecialchars($expiryDateEth); ?></span></div>
            </div>
        </div>
        <div class="id-qr-wrap" data-idc="qr">
            <div class="id-qr">
                <?php if (!empty($member['qr_code_path'])): ?>
                    <img src="<?php echo htmlspecialchars($member['qr_code_path'], ENT_QUOTES, 'UTF-8'); ?>"
                         crossOrigin="anonymous"
                         alt=""
                         onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';">
                    <div class="id-qr-miss" style="display:none">QR ኮድ አልተገኘም</div>
                <?php else: ?>
                    <div class="id-qr-miss">QR ኮድ አልተፈጠረም</div>
                <?php endif; ?>
            </div>
            <p class="id-qr-hint">የመታወቂያውን ትክክለኛነት<br>ለማረጋገጥ ስካን ያድርጉ</p>
        </div>
    </div>
    <footer class="id-foot" data-idc="foot_back">
        <p data-idc-text="foot_back_text"><?php echo htmlspecialchars($footBackText); ?></p>
    </footer>
</div>
<?php endif; ?>
