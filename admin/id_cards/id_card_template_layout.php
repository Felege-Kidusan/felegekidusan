<!-- FILE: /admin/id_cards/id_card_template_layout.php -->
<?php
$idCardBg = defined('ID_CARD_BACKGROUND') ? ID_CARD_BACKGROUND : '/admin/id_cards/assets/backgrounds/id_card_bg.jpg';
$idCardBg = htmlspecialchars($idCardBg, ENT_QUOTES, 'UTF-8');
$genderLabel = '--';
$g = strtolower(trim((string)($member['gender'] ?? '')));
if ($g === 'male' || $g === 'm' || $g === 'ወንድ') {
    $genderLabel = 'ወንድ';
} elseif ($g === 'female' || $g === 'f' || $g === 'ሴት') {
    $genderLabel = 'ሴት';
}
$logoSize = max(90, (int)round(((int)$DISPLAY['logo_size']) * 1.2));
$sealSize = max(90, (int)$DISPLAY['seal_size']);
?>
<!-- FRONT SIDE -->
<div class="id-card-template id-front">
    <div class="id-card-bg" style="background-image:url('<?= $idCardBg ?>')"></div>
    <header class="id-head">
        <div class="id-logo" style="width:<?= $logoSize ?>px;height:<?= $logoSize ?>px;opacity:<?= $DISPLAY['logo_opacity'] / 100 ?>">
            <div style="width:100%;height:100%;background-image:url('<?php echo htmlspecialchars($CONFIG['logo'], ENT_QUOTES, 'UTF-8'); ?>');background-size:contain;background-position:center;background-repeat:no-repeat;"></div>
        </div>
        <div class="id-head-text">
            <p class="id-invoc"><?= RELIGIOUS_INVOCATION ?></p>
            <h1 class="id-parish"><?= PARISH_NAME_AM ?></h1>
            <h2 class="id-title"><?= ID_CARD_TITLE_AM ?></h2>
            <h3 class="id-title-en"><?= ID_CARD_TITLE_EN ?></h3>
        </div>
    </header>
    <div class="id-bar"></div>
    <div class="id-body">
        <div class="id-photo">
            <?php if (!empty($member['student_photo_path'])): ?>
            <div style="width:100%;height:100%;background-image:url('<?php echo htmlspecialchars($member['student_photo_path'], ENT_QUOTES, 'UTF-8'); ?>');background-size:cover;background-position:center top;background-repeat:no-repeat;"></div>
            <?php endif; ?>
        </div>
        <div class="id-fields">
            <div class="id-row"><span class="id-lbl">ሙሉ ስም</span><span class="id-val"><?php echo htmlspecialchars($full_name); ?></span></div>
            <div class="id-row"><span class="id-lbl">የክርስትና ስም</span><span class="id-val"><?php echo htmlspecialchars($christian_name); ?></span></div>
            <div class="id-row id-row-split">
                <div><span class="id-lbl">ጾታ</span><span class="id-val"><?php echo htmlspecialchars($genderLabel); ?></span></div>
                <div><span class="id-lbl">ዕድሜ</span><span class="id-val"><?php echo htmlspecialchars((string)$age); ?></span></div>
            </div>
            <div class="id-row"><span class="id-lbl">የመታወቂያ ቁ.</span><span class="id-val id-code"><?php echo htmlspecialchars((string)$member['member_code']); ?></span></div>
            <div class="id-signs">
                <div>
                    <div class="id-sign-img"><img src="<?php echo htmlspecialchars($CONFIG['sig_head'], ENT_QUOTES, 'UTF-8'); ?>" alt="" style="width:<?= (int)$DISPLAY['sig_head_size'] ?>px;opacity:<?= $DISPLAY['sig_head_opacity'] / 100 ?>"></div>
                    <p class="id-sign-lbl"><?= ID_CARD_SIG_HEAD_AM ?></p>
                </div>
                <div>
                    <div class="id-sign-img"><img src="<?php echo htmlspecialchars($CONFIG['sig_admin'], ENT_QUOTES, 'UTF-8'); ?>" alt="" style="width:<?= (int)$DISPLAY['sig_admin_size'] ?>px;opacity:<?= $DISPLAY['sig_admin_opacity'] / 100 ?>"></div>
                    <p class="id-sign-lbl"><?= ID_CARD_SIG_ADMIN_AM ?></p>
                </div>
            </div>
        </div>
        <div class="id-seal" style="width:<?= $sealSize ?>px;height:<?= $sealSize ?>px;opacity:<?= $DISPLAY['seal_opacity'] / 100 ?>">
            <img src="<?php echo htmlspecialchars($CONFIG['seal'], ENT_QUOTES, 'UTF-8'); ?>" alt="">
        </div>
    </div>
    <footer class="id-foot">
        <h2><?= SCHOOL_NAME_SHORT_AM ?> <?= SCHOOL_TYPE_AM ?></h2>
    </footer>
</div>

<!-- BACK SIDE -->
<div class="id-card-template id-back">
    <div class="id-card-bg" style="background-image:url('<?= $idCardBg ?>')"></div>
    <header class="id-head id-head-simple">
        <div class="id-head-text">
            <h2 class="id-title"><?= ID_CARD_TITLE_AM ?></h2>
            <h3 class="id-title-en"><?= ID_CARD_TITLE_EN ?></h3>
        </div>
    </header>
    <div class="id-bar id-bar-label">የአባል መረጃና የአደጋ ጊዜ ተጠሪ</div>
    <div class="id-body id-body-back">
        <div class="id-fields">
            <div class="id-row"><span class="id-lbl">ስልክ ቁጥር</span><span class="id-val"><?php echo htmlspecialchars((string)($member['phone_number'] ?? '')); ?></span></div>
            <div class="id-row"><span class="id-lbl">የመኖሪያ አድራሻ</span><span class="id-val"><?php echo htmlspecialchars((string)($member['address'] ?? '')); ?></span></div>
            <h4 class="id-subhead">የአደጋ ጊዜ ተጠሪ መረጃ</h4>
            <div class="id-row"><span class="id-lbl">ሙሉ ስም</span><span class="id-val"><?php echo htmlspecialchars((string)$member['emergency_name']); ?></span></div>
            <div class="id-row"><span class="id-lbl">ስልክ ቁጥር</span><span class="id-val"><?php echo htmlspecialchars((string)$member['emergency_phone']); ?></span></div>
            <div class="id-row id-row-split">
                <div><span class="id-lbl">የተሰጠበት ቀን</span><span class="id-val"><?php echo htmlspecialchars($issueDateEth); ?></span></div>
                <div><span class="id-lbl">የሚያበቃበት ቀን</span><span class="id-val"><?php echo htmlspecialchars($expiryDateEth); ?></span></div>
            </div>
        </div>
        <div class="id-qr-wrap">
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
    <footer class="id-foot">
        <p><?= ID_CARD_DISCLAIMER_AM ?></p>
    </footer>
</div>
