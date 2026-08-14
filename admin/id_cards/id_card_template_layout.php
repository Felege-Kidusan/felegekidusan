<!-- FILE: /admin/id_cards/id_card_template_layout.php -->
<!-- FRONT SIDE -->
<div class="id-card-template flex flex-col p-8 border-8 border-green-600 mb-10">
    <div class="text-center relative z-10">
        <div class="absolute top-0 left-0 rounded-full dashed-box flex items-center justify-center overflow-hidden bg-white" style="width:<?= $DISPLAY['logo_size'] * 112 / 100 ?>px;height:<?= $DISPLAY['logo_size'] * 112 / 100 ?>px;opacity:<?= $DISPLAY['logo_opacity'] / 100 ?>">
            <!-- Logo: use background-image for html2canvas compatibility -->
            <div style="width:100%;height:100%;background-image:url('<?php echo $CONFIG['logo']; ?>');background-size:cover;background-position:center;background-repeat:no-repeat;border-radius:50%"></div>
        </div>
        <p class="text-gray-600 text-xl font-bold"><?= RELIGIOUS_INVOCATION ?></p>
        <h1 class="text-wbws-green text-3xl font-extrabold mt-1"><?= PARISH_NAME_AM ?></h1>
        <h2 class="text-wbws-green text-2xl font-bold"><?= ID_CARD_TITLE_AM ?></h2>
        <h3 class="text-wbws-orange text-xl font-bold uppercase tracking-wide"><?= ID_CARD_TITLE_EN ?></h3>
    </div>
    <div class="w-full h-10 bg-wbws-green-gradient rounded-full my-4 mx-auto shadow-sm" style="width: 98%;"></div>
    <div class="flex flex-1 px-4 relative">
        <div class="w-1/3 flex flex-col items-center pt-2">
            <div class="w-60 h-72 dashed-box rounded-xl bg-gray-50 overflow-hidden" style="position:relative">
                <?php if(!empty($member['student_photo_path'])): ?>
                    <!--
                        CRITICAL FIX: html2canvas does NOT support object-fit: cover on <img> tags.
                        It renders the raw image at full natural size, breaking the crop/fit.
                        Solution: Use a <div> with background-image + background-size: cover.
                        html2canvas fully supports background-size: cover on divs.
                    -->
                    <div crossorigin="anonymous" style="
                        width: 100%;
                        height: 100%;
                        background-image: url('<?php echo $member['student_photo_path']; ?>');
                        background-size: cover;
                        background-position: center top;
                        background-repeat: no-repeat;
                    "></div>
                <?php endif; ?>
            </div>
        </div>
        <div class="w-2/3 pl-8 space-y-3 text-xl font-bold text-gray-800 relative z-20">
            <div class="flex items-end"><span class="text-wbws-green w-36 pb-1">ሙሉ ስም:</span><span class="flex-1 border-b-2 border-dashed border-gray-400 pl-2 text-black pb-1"><?php echo $full_name; ?></span></div>
            <div class="flex items-end"><span class="text-wbws-green w-48 pb-1">የክርስትና ስም:</span><span class="flex-1 border-b-2 border-dashed border-gray-400 pl-2 text-black pb-1"><?php echo $christian_name; ?></span></div>
            <div class="flex">
                <div class="flex items-end w-1/2"><span class="text-wbws-green w-20 pb-1">ጾታ:</span><span class="flex-1 border-b-2 border-dashed border-gray-400 pl-2 text-black pb-1"><?php echo $member['gender'] ?? '--'; ?></span></div>
                <div class="flex items-end w-1/2 pl-4"><span class="text-wbws-green w-24 pb-1">ዕድሜ:</span><span class="flex-1 border-b-2 border-dashed border-gray-400 pl-2 text-black pb-1"><?php echo $age; ?></span></div>
            </div>
            <div class="flex items-end mt-2"><span class="text-wbws-green w-48 pb-1">የመታወቂያ ቁ.:</span><span class="flex-1 border-b-2 border-dashed border-gray-400 pl-2 text-2xl font-mono text-black pb-1"><?php echo $member['member_code']; ?></span></div>
            <div class="grid grid-cols-2 gap-12 mt-6">
                <div class="text-center relative">
                    <div class="h-12 relative"><img src="<?php echo $CONFIG['sig_head']; ?>" class="signature-img absolute bottom-0 left-0 right-0" style="width:<?= $DISPLAY['sig_head_size'] ?>px;opacity:<?= $DISPLAY['sig_head_opacity'] / 100 ?>"></div>
                    <div class="border-b-2 border-dashed border-gray-600 w-full mb-1"></div>
                    <p class="text-wbws-green text-sm"><?= ID_CARD_SIG_HEAD_AM ?></p>
                </div>
                <div class="text-center relative">
                    <div class="h-12 relative"><img src="<?php echo $CONFIG['sig_admin']; ?>" class="signature-img absolute bottom-0 left-0 right-0" style="width:<?= $DISPLAY['sig_admin_size'] ?>px;opacity:<?= $DISPLAY['sig_admin_opacity'] / 100 ?>"></div>
                    <div class="border-b-2 border-dashed border-gray-600 w-full mb-1"></div>
                    <p class="text-wbws-green text-sm"><?= ID_CARD_SIG_ADMIN_AM ?></p>
                </div>
            </div>
        </div>
        <div class="seal-overlay rounded-full dashed-box flex items-center justify-center" style="width:<?= $DISPLAY['seal_size'] ?>px;height:<?= $DISPLAY['seal_size'] ?>px;opacity:<?= $DISPLAY['seal_opacity'] / 100 ?>"><img src="<?php echo $CONFIG['seal']; ?>" class="w-full h-full object-contain"></div>
    </div>
    <div class="mt-auto pt-1 text-center">
        <div class="w-full h-1.5 bg-black rounded-full mb-1 mx-auto" style="width: 98%;"></div>
        <h2 class="text-gray-900 text-2xl font-extrabold"><?= SCHOOL_NAME_SHORT_AM ?> <?= SCHOOL_TYPE_AM ?></h2>
    </div>
</div>

<!-- BACK SIDE -->
<div class="id-card-template flex flex-col p-8 border-8 border-green-600">
    <div class="text-center">
        <h2 class="text-wbws-green text-3xl font-bold"><?= ID_CARD_TITLE_AM ?></h2>
        <h3 class="text-wbws-orange text-xl font-bold"><?= ID_CARD_TITLE_EN ?></h3>
    </div>
    <div class="w-full h-12 bg-wbws-green-gradient rounded-full my-6 mx-auto flex items-center px-8 shadow-sm" style="width: 98%;">
        <span class="text-white text-2xl font-bold">የአባል መረጃና የአደጋ ጊዜ ተጠሪ</span>
    </div>
    <div class="flex flex-1 px-4 py-2">
        <div class="w-3/5 space-y-6 text-xl font-bold text-gray-800 pr-6">
            <div class="flex items-end"><span class="text-wbws-green w-36 pb-1">ስልክ ቁጥር:</span><span class="flex-1 border-b-2 border-dashed border-gray-400 pl-2 text-black pb-1"><?php echo $member['phone_number']; ?></span></div>
            <div class="flex items-end"><span class="text-wbws-green w-48 pb-1">የመኖሪያ አድራሻ:</span><span class="flex-1 border-b-2 border-dashed border-gray-400 pl-2 text-black text-lg pb-1"><?php echo $member['address']; ?></span></div>
            <h4 class="text-black font-extrabold mt-6 text-2xl border-b border-gray-200 pb-1">የአደጋ ጊዜ ተጠሪ መረጃ</h4>
            <div class="flex items-end"><span class="text-wbws-green w-36 text-lg pb-1">ሙሉ ስም:</span><span class="flex-1 border-b-2 border-dashed border-gray-400 pl-2 text-black pb-1"><?php echo $member['emergency_name']; ?></span></div>
            <div class="flex items-end"><span class="text-wbws-green w-36 text-lg pb-1">ስልክ ቁጥር:</span><span class="flex-1 border-b-2 border-dashed border-gray-400 pl-2 text-black pb-1"><?php echo $member['emergency_phone']; ?></span></div>
            <div class="flex items-end mt-4">
                 <div class="w-1/2 flex items-end pr-2">
                    <span class="text-wbws-green w-32 pb-1 text-sm">የተሰጠበት ቀን:</span>
                    <span class="flex-1 border-b-2 border-dashed border-gray-400 pl-2 text-black text-sm pb-1"><?php echo $issueDateEth; ?></span>
                </div>
                <div class="w-1/2 flex items-end pl-2">
                    <span class="text-wbws-green w-32 pb-1 text-sm">የሚያበቃበት ቀን:</span>
                    <span class="flex-1 border-b-2 border-dashed border-gray-400 pl-2 text-black text-sm pb-1"><?php echo $expiryDateEth; ?></span>
                </div>
            </div>
        </div>
        <div class="w-2/5 flex flex-col items-center justify-center pl-6 border-l-2 border-gray-100">
            <div class="dashed-box p-3 rounded-2xl bg-white mb-3">
                <?php if(!empty($member['qr_code_path'])): ?>
                    <img src="<?php echo $member['qr_code_path']; ?>" 
                         crossOrigin="anonymous"
                         class="w-52 h-52 object-contain"
                         onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';">
                    <div style="display:none; width:208px; height:208px;" class="items-center justify-center bg-gray-100 rounded text-center text-gray-400 text-sm font-bold p-4">
                        QR ኮድ አልተገኘም<br>
                        <span class="text-xs">ካርዱን እንደገና ያመንጩ</span>
                    </div>
                <?php else: ?>
                    <div style="width:208px; height:208px;" class="flex items-center justify-center bg-gray-100 rounded text-center text-gray-400 text-sm font-bold p-4">
                        QR ኮድ አልተፈጠረም<br>
                        <span class="text-xs">ካርዱን እንደገና ያመንጩ</span>
                    </div>
                <?php endif; ?>
            </div>
            <p class="text-center text-gray-500 text-sm mt-2 font-bold leading-tight">የመታወቂያውን ትክክለኛነት<br>ለማረጋገጥ ስካን ያድርጉ</p>
        </div>
    </div>
    <div class="mt-auto pt-2 text-center pb-2">
        <div class="w-full h-1.5 bg-black rounded-full mb-2 mx-auto" style="width: 98%;"></div>
        <p class="text-gray-600 text-sm font-bold">ማስታወሻ: ይህ መታወቂያ ካርድ እስከ የሚያበቃበት ቀን ብቻ ዋጋ አለው። ከጠፋ ለአስተዳደሩ ያሳውቁ።</p>
    </div>
</div>