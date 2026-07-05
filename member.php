<?php
// FILE: /member.php — FKSS Member Verification (QR scan landing page)

// 1. Load Configuration
if (file_exists('admin/config.php')) { require_once 'admin/config.php'; } 
elseif (file_exists('config.php')) { require_once 'config.php'; } 
else { die("System Error: Config not found."); }

// 2. Load Date Helper
if (file_exists('admin/id_cards/libs/eth_date_helper.php')) {
    require_once 'admin/id_cards/libs/eth_date_helper.php';
} else {
    function toEthiopianDate($d){ return date('d/m/Y', strtotime($d)); }
    function isExpired($d){ return false; }
}

$code = isset($_GET['code']) ? $_GET['code'] : '';
$stmt = $conn->prepare("SELECT * FROM members WHERE member_code = ?");
$stmt->bind_param("s", $code);
$stmt->execute();
$member = $stmt->get_result()->fetch_assoc();

// 3. Logic
$exists = ($member && $member['status'] !== 'archived');
$expired = $exists ? isExpired($member['id_card_generated_at']) : false;

// Calculate Age
$currentYearEth = (int)date('Y') - 8;
if ($exists && !empty($member['dob_ec_year'])) {
    $age = $currentYearEth - $member['dob_ec_year'];
} elseif ($exists && !empty($member['date_of_birth'])) {
    $age = date('Y') - date('Y', strtotime($member['date_of_birth']));
} else {
    $age = '--';
}

// Calculate Dates
$issueDateEth = $exists ? toEthiopianDate($member['id_card_generated_at']) : '-';
$expDateGregorian = $exists ? date('Y-m-d', strtotime($member['id_card_generated_at'] . ' + 4 years')) : '';
$expDateEth = $exists ? toEthiopianDate($expDateGregorian) : '-';

// Address Construction
$address = 'Not Registered';
if ($exists) {
    $parts = [];
    if (!empty($member['city'])) $parts[] = $member['city'];
    if (!empty($member['sub_city'])) $parts[] = $member['sub_city'];
    if (!empty($member['woreda'])) $parts[] = 'Wor. ' . $member['woreda'];
    if (!empty($member['house_number'])) $parts[] = 'H.No ' . $member['house_number'];
    
    if (!empty($parts)) {
        $address = implode(', ', $parts);
    } elseif (!empty($member['address'])) {
        $address = $member['address'];
    }
}

// Fix Image Path
if ($exists && !empty($member['student_photo_path'])) {
    $p = ltrim($member['student_photo_path'], '/');
    if (strpos($p, 'admin/') === 0) {
        $p = substr($p, 6);
    }
    $member['student_photo_path'] = 'admin/' . $p;
}

// Member Type Map
function getMemberLabel($type) {
    $map = [
        'direct' => 'መደበኛ (Regular)',
        'transfer' => 'ልዩ መደበኛ (Student + Role)',
        'honorary' => 'የክብር አባላት (Honorary)'
    ];
    return isset($map[$type]) ? $map[$type] : ucfirst($type);
}
?>
<!DOCTYPE html>
<html lang="am">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Verification - <?php echo e($code); ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Noto+Serif+Ethiopic:wght@400;600;700&family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="icon" href="<?= SCHOOL_LOGO_PATH ?>">
    <style>
        :root {
            --maroon: <?= THEME_PRIMARY ?>;
            --maroon-dark: <?= THEME_PRIMARY_DARK ?>;
            --gold: <?= THEME_ACCENT ?>;
            --gold-dark: <?= THEME_ACCENT_2 ?>;
        }
        body {
            font-family: 'Poppins', 'Noto Serif Ethiopic', sans-serif;
            background:
              radial-gradient(circle at 12% 12%, rgba(240,192,0,0.10), transparent 40%),
              linear-gradient(160deg, #faf7f0, #f3ece0);
            min-height: 100vh;
        }
        .amharic { font-family: 'Noto Serif Ethiopic', serif; }
        .verify-card {
            box-shadow: 0 20px 55px rgba(96,0,0,0.18);
        }
        .header-maroon {
            background: linear-gradient(150deg, var(--maroon-dark), var(--maroon) 60%, #4a1208);
            position: relative;
            overflow: hidden;
        }
        .header-maroon::before {
            content: '';
            position: absolute;
            top: -60px; right: -60px;
            width: 180px; height: 180px;
            border: 2px solid rgba(240,192,0,0.18);
            border-radius: 50%;
        }
        .gold-ring { border-color: var(--gold) !important; }
        .status-active {
            background: linear-gradient(135deg, var(--gold), var(--gold-dark));
            color: var(--maroon-dark);
        }
    </style>
</head>
<body class="flex items-center justify-center p-4">

    <div class="max-w-md w-full bg-white rounded-3xl overflow-hidden verify-card border border-amber-100">
        
        <!-- Header -->
        <div class="header-maroon p-6 text-center">
            <div class="w-24 h-24 bg-white rounded-full mx-auto flex items-center justify-center shadow-lg mb-3 overflow-hidden border-4 gold-ring relative z-10" style="padding:5px">
                <img src="<?= SCHOOL_LOGO_PATH ?>" 
                     alt="<?= SCHOOL_NAME_SHORT ?> Logo"
                     onerror="this.style.display='none'; this.nextElementSibling.style.display='block';" 
                     class="w-full h-full object-contain">
                <span class="hidden text-3xl" style="color:var(--maroon)"><?= ADMIN_LOGO_ICON ?></span>
            </div>
            <h1 class="text-white font-bold text-xl amharic relative z-10"><?= SCHOOL_NAME_AMHARIC ?></h1>
            <p class="text-amber-200/90 text-xs mt-1 relative z-10 font-medium tracking-wide">DIGITAL MEMBER VERIFICATION</p>
        </div>

        <div class="p-6">
            <?php if ($exists): ?>
                
                <!-- Status Badge -->
                <div class="flex justify-center -mt-10 mb-6 relative z-20">
                    <?php if($expired): ?>
                        <span class="bg-red-100 text-red-700 border-4 border-white px-6 py-2 rounded-full font-bold shadow-md flex items-center gap-2">
                            <i class="fa-solid fa-triangle-exclamation"></i> EXPIRED
                        </span>
                    <?php else: ?>
                        <span class="status-active border-4 border-white px-6 py-2 rounded-full font-bold shadow-md flex items-center gap-2">
                            <i class="fa-solid fa-circle-check"></i> Active Member
                        </span>
                    <?php endif; ?>
                </div>

                <div class="text-center space-y-4">
                    <!-- Photo -->
                    <div class="w-32 h-32 mx-auto rounded-2xl overflow-hidden border-4 <?php echo $expired ? 'border-red-400' : 'gold-ring'; ?> shadow-sm bg-gray-100 flex items-center justify-center">
                        <?php if(!empty($member['student_photo_path'])): ?>
                            <img src="<?php echo e($member['student_photo_path']); ?>" class="w-full h-full object-cover">
                        <?php else: ?>
                            <div class="text-gray-400 text-sm font-bold">No Photo</div>
                        <?php endif; ?>
                    </div>

                    <!-- Name -->
                    <div>
                        <h2 class="text-2xl font-bold text-gray-800 amharic">
                            <?php echo e($member['student_name']) . ' ' . e($member['father_name']) . ' ' . e($member['grandfather_name']); ?>
                        </h2>
                        <p class="inline-block mt-2 font-mono text-sm px-3 py-1 rounded-full" style="background:#faf3e0;color:var(--maroon)">
                            <i class="fa-solid fa-id-badge mr-1" style="color:var(--gold-dark)"></i><?php echo e($member['member_code']); ?>
                        </p>
                    </div>

                    <!-- Info Grid -->
                    <div class="text-left mt-6 bg-amber-50/40 p-4 rounded-2xl border border-amber-100 space-y-3">
                        
                        <div class="flex justify-between border-b border-amber-100 pb-2">
                            <span class="text-xs text-gray-500 uppercase tracking-wide">Age</span>
                            <span class="font-bold text-gray-700"><?php echo $age; ?> Years</span>
                        </div>

                        <div class="flex justify-between border-b border-amber-100 pb-2">
                            <span class="text-xs text-gray-500 uppercase tracking-wide">Gender</span>
                            <span class="font-bold text-gray-700 capitalize"><?php echo e($member['gender'] ?? '--'); ?></span>
                        </div>

                        <div class="flex justify-between border-b border-amber-100 pb-2">
                            <span class="text-xs text-gray-500 uppercase tracking-wide">Baptismal Name</span>
                            <span class="font-bold text-gray-700 amharic"><?php echo e($member['baptismal_name'] ?? '---'); ?></span>
                        </div>

                        <div class="flex justify-between border-b border-amber-100 pb-2">
                            <span class="text-xs text-gray-500 uppercase tracking-wide">Member Type</span>
                            <span class="font-bold text-gray-700 capitalize amharic"><?php echo getMemberLabel($member['registration_type']); ?></span>
                        </div>

                        <!-- Address -->
                        <div class="border-b border-amber-100 pb-2">
                            <p class="text-xs text-gray-500 uppercase tracking-wide mb-1">Address</p>
                            <p class="font-bold text-gray-700 text-sm leading-tight">
                                <i class="fa-solid fa-location-dot mr-1" style="color:var(--maroon)"></i>
                                <?php echo e($address); ?>
                            </p>
                        </div>

                        <!-- Dates (Ethiopian) -->
                        <div class="flex justify-between pt-1">
                            <div>
                                <p class="text-[10px] text-gray-500 uppercase tracking-wide">Issued Date</p>
                                <p class="font-bold text-gray-800 text-sm"><?php echo $issueDateEth; ?></p>
                            </div>
                            <div class="text-right">
                                <p class="text-[10px] text-gray-500 uppercase tracking-wide">Expiry Date</p>
                                <p class="font-bold <?php echo $expired?'text-red-600':'text-gray-800'; ?> text-sm">
                                    <?php echo $expDateEth; ?>
                                </p>
                            </div>
                        </div>

                    </div>

                    <!-- Verified stamp line -->
                    <div class="flex items-center justify-center gap-2 pt-2 text-xs text-gray-400">
                        <i class="fa-solid fa-shield-halved" style="color:var(--gold-dark)"></i>
                        Verified by <?= SCHOOL_NAME_SHORT ?> Digital System
                    </div>
                </div>

            <?php else: ?>
                <div class="text-center py-10">
                    <div class="w-16 h-16 mx-auto rounded-full bg-red-50 flex items-center justify-center mb-4">
                        <i class="fa-solid fa-user-xmark text-2xl text-red-400"></i>
                    </div>
                    <h2 class="text-xl font-bold text-gray-800">Member Not Found</h2>
                    <p class="text-gray-500 text-sm mt-2">This code does not match any active member record.</p>
                </div>
            <?php endif; ?>
        </div>
        <div class="bg-amber-50/60 px-6 py-4 border-t border-amber-100 text-center">
            <a href="<?= SITE_URL ?>" class="font-semibold text-sm hover:underline" style="color:var(--maroon)">
                <i class="fa-solid fa-globe mr-1" style="color:var(--gold-dark)"></i><?= SITE_DOMAIN ?>
            </a>
        </div>
    </div>
</body>
</html>
<?php 
if(isset($conn)) $conn->close(); 
exit; 
?>
