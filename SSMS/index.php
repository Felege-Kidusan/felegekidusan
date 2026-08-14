<?php
require_once __DIR__ . '/school_config.php';

// ── Load dynamic content from the CMS (graceful fallback if DB unavailable) ──
$cms = ['programs'=>[], 'schedule'=>[], 'teachers'=>[], 'gallery'=>[], 'featured'=>[], 'social'=>[]];
$_cmsConfigPath = __DIR__ . '/admin/config.php';
if (file_exists($_cmsConfigPath)) {
    require_once $_cmsConfigPath;
    if (isset($conn) && $conn instanceof mysqli && !$conn->connect_error) {
        $_safeFetch = function($sql) use ($conn) {
            $out = [];
            try {
                if ($res = $conn->query($sql)) {
                    while ($r = $res->fetch_assoc()) $out[] = $r;
                }
            } catch (Throwable $e) { /* table may not exist yet */ }
            return $out;
        };
        $cms['programs'] = $_safeFetch("SELECT * FROM cms_programs WHERE is_active=1 ORDER BY sort_order, id");
        $cms['schedule'] = $_safeFetch("SELECT * FROM cms_schedule WHERE is_active=1 ORDER BY sort_order, id");
        $cms['teachers'] = $_safeFetch("SELECT * FROM cms_teachers WHERE is_active=1 ORDER BY sort_order, id");
        $cms['gallery']  = $_safeFetch("SELECT * FROM cms_gallery_photos WHERE is_active=1 ORDER BY sort_order, id DESC");
        $cms['featured'] = $_safeFetch("SELECT * FROM cms_gallery_photos WHERE is_active=1 AND is_featured=1 ORDER BY sort_order, id DESC");
        $cms['social']   = $_safeFetch("SELECT * FROM cms_social_links WHERE is_active=1 ORDER BY sort_order, id");
    }
}
?>
<!DOCTYPE html>
<html lang="am">
<head>
<link rel="icon" href="<?= SCHOOL_LOGO_PATH ?>">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= SCHOOL_NAME_AMHARIC . ' - ' . SCHOOL_TRANSLATION_EN . ' ' . SCHOOL_TYPE; ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Noto+Serif+Ethiopic:wght@400;600;700&family=Poppins:wght@300;400;600;700&display=swap');
        
        :root {
            --fkss-maroon: <?= THEME_PRIMARY ?>;
            --fkss-maroon-light: <?= THEME_PRIMARY_LIGHT ?>;
            --fkss-maroon-dark: <?= THEME_PRIMARY_DARK ?>;
            --fkss-gold: <?= THEME_ACCENT ?>;
            --fkss-gold-dark: <?= THEME_ACCENT_2 ?>;
        }

        body {
            font-family: 'Poppins', sans-serif;
        }
        
        .amharic-text {
            font-family: 'Noto Serif Ethiopic', serif;
        }
        
        .hero-pattern {
            background-color: var(--fkss-maroon-dark);
            background-image: url("data:image/svg+xml,%3Csvg width='60' height='60' viewBox='0 0 60 60' xmlns='http://www.w3.org/2000/svg'%3E%3Cg fill='none' fill-rule='evenodd'%3E%3Cg fill='%23F0C000' fill-opacity='0.1'%3E%3Cpath d='M36 34v-4h-2v4h-4v2h4v4h2v-4h4v-2h-4zm0-30V0h-2v4h-4v2h4v4h2V6h4V4h-4zM6 34v-4H4v4H0v2h4v4h2v-4h4v-2H6zM6 4V0H4v4H0v2h4v4h2V6h4V4H6z'/%3E%3C/g%3E%3C/g%3E%3C/svg%3E");
        }
        
        .cross-icon {
            width: 56px;
            height: 56px;
            border-radius: 50%;
            background: #fff;
            border: 2px solid var(--fkss-gold);
            background-image: url('<?= SCHOOL_LOGO_PATH ?>');
            background-size: 80%;
            background-position: center;
            background-repeat: no-repeat;
        }
        
        .gradient-text {
            background: linear-gradient(135deg, var(--fkss-gold) 0%, var(--fkss-gold-dark) 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }
        
        .card-hover {
            transition: transform 0.3s ease, box-shadow 0.3s ease;
        }
        
        .card-hover:hover {
            transform: translateY(-5px);
            box-shadow: 0 20px 40px rgba(96,0,0,0.15);
        }
        
        .section-title::after {
            content: '';
            display: block;
            width: 80px;
            height: 4px;
            background: linear-gradient(90deg, var(--fkss-gold), var(--fkss-gold-dark));
            margin: 10px auto;
            border-radius: 2px;
        }

        /* ════════════════════════════════════════════════════════
           FKSS PALETTE REMAP
           The original template used Tailwind green-* + yellow-*.
           These overrides retheme the entire page to FKSS
           maroon + gold without editing every element by hand.
           ════════════════════════════════════════════════════════ */
        .text-green-900 { color: var(--fkss-maroon-dark) !important; }
        .text-green-800 { color: var(--fkss-maroon) !important; }
        .text-green-700 { color: var(--fkss-maroon) !important; }
        .text-green-600 { color: var(--fkss-maroon-light) !important; }

        .bg-green-900 { background-color: var(--fkss-maroon-dark) !important; }
        .bg-green-800 { background-color: var(--fkss-maroon) !important; }
        .bg-green-700 { background-color: var(--fkss-maroon) !important; }
        .bg-green-600 { background-color: var(--fkss-maroon-light) !important; }
        .bg-green-50  { background-color: #faf3e8 !important; }

        .border-green-600, .border-green-700, .border-green-800 { border-color: var(--fkss-maroon) !important; }

        .hover\:text-green-900:hover { color: var(--fkss-maroon-dark) !important; }
        .hover\:bg-green-900:hover { background-color: var(--fkss-maroon-dark) !important; }

        /* Tailwind gradient stops (from-green / to-green) */
        .from-green-800 { --tw-gradient-from: var(--fkss-maroon) !important; --tw-gradient-to: rgba(96,0,0,0) !important; --tw-gradient-stops: var(--tw-gradient-from), var(--tw-gradient-to) !important; }
        .from-green-700 { --tw-gradient-from: var(--fkss-maroon) !important; --tw-gradient-to: rgba(96,0,0,0) !important; --tw-gradient-stops: var(--tw-gradient-from), var(--tw-gradient-to) !important; }
        .to-green-600 { --tw-gradient-to: var(--fkss-maroon-light) !important; }

        /* Yellow → Gold remap */
        .text-yellow-300 { color: #ffe082 !important; }
        .text-yellow-400 { color: var(--fkss-gold) !important; }
        .text-yellow-500 { color: var(--fkss-gold-dark) !important; }
        .bg-yellow-500 { background-color: var(--fkss-gold) !important; }
        .hover\:bg-yellow-600:hover { background-color: var(--fkss-gold-dark) !important; }
        .border-yellow-500 { border-color: var(--fkss-gold) !important; }
        .hover\:bg-yellow-500:hover { background-color: var(--fkss-gold) !important; }

        nav {
            backdrop-filter: blur(10px);
            background-color: rgba(64, 0, 0, 0.95);
        }
    </style>
</head>
<body class="bg-gray-50">
    <!-- Navigation -->
    <nav class="fixed w-full z-50 shadow-lg">
        <div class="container mx-auto px-4">
            <div class="flex items-center justify-between py-4">
                <div class="flex items-center space-x-3">
                    <div class="cross-icon"></div>
                    <div>
                        <h1 class="text-white text-xl font-bold amharic-text"><?= SCHOOL_NAME_AMHARIC ?></h1>
                        <p class="text-yellow-400 text-xs"><?= SCHOOL_TRANSLATION_EN ?></p>
                    </div>
                </div>
                
                <!-- Mobile menu button -->
                <button id="mobile-menu-btn" class="md:hidden text-white">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"></path>
                    </svg>
                </button>
                
                <!-- Desktop menu -->
                <div class="hidden md:flex space-x-6">
                    <a href="#home" class="text-white hover:text-yellow-400 transition">Home</a>
                    <a href="#about" class="text-white hover:text-yellow-400 transition">About</a>
                    <a href="#programs" class="text-white hover:text-yellow-400 transition">Programs</a>
                    <a href="#schedule" class="text-white hover:text-yellow-400 transition">Schedule</a>
                    <a href="#gallery" class="text-white hover:text-yellow-400 transition">Gallery</a>
                    <a href="#contact" class="text-white hover:text-yellow-400 transition">Contact</a>
                </div>
            </div>
            
            <!-- Mobile menu -->
            <div id="mobile-menu" class="hidden md:hidden pb-4">
                <a href="#home" class="block text-white hover:text-yellow-400 py-2 transition">Home</a>
                <a href="#about" class="block text-white hover:text-yellow-400 py-2 transition">About</a>
                <a href="#programs" class="block text-white hover:text-yellow-400 py-2 transition">Programs</a>
                <a href="#schedule" class="block text-white hover:text-yellow-400 py-2 transition">Schedule</a>
                <a href="#gallery" class="block text-white hover:text-yellow-400 py-2 transition">Gallery</a>
                <a href="#contact" class="block text-white hover:text-yellow-400 py-2 transition">Contact</a>
            </div>
        </div>
    </nav>

    <!-- Hero Section -->
    <section id="home" class="hero-pattern pt-32 pb-20 text-white">
        <div class="container mx-auto px-4">
            <div class="text-center max-w-4xl mx-auto">
                <h1 class="text-5xl md:text-7xl font-bold mb-6 amharic-text"><?= SCHOOL_NAME_AMHARIC ?></h1>
                <h2 class="text-3xl md:text-4xl mb-4"><?= SCHOOL_TRANSLATION_EN ?> <?= SCHOOL_TYPE ?></h2>
                <p class="text-xl md:text-2xl mb-4 amharic-text"><?= DENOMINATION_AM ?> <?= SCHOOL_TYPE_AM ?></p>
                <p class="text-lg md:text-xl mb-8 text-yellow-300"><?= DENOMINATION_EN ?> <?= SCHOOL_TYPE ?></p>
                <div class="flex flex-col md:flex-row gap-4 justify-center">
                    <a href="#contact" class="bg-yellow-500 hover:bg-yellow-600 text-green-900 font-bold py-3 px-8 rounded-lg transition transform hover:scale-105">
                        Register Now
                    </a>
                    <a href="#about" class="border-2 border-yellow-500 hover:bg-yellow-500 hover:text-green-900 text-white font-bold py-3 px-8 rounded-lg transition transform hover:scale-105">
                        Learn More
                    </a>
                </div>
            </div>
        </div>
    </section>

    <!-- About Section -->
    <section id="about" class="py-20 bg-white">
        <div class="container mx-auto px-4">
            <h2 class="text-4xl font-bold text-center mb-4 text-green-900 section-title">About Our School</h2>
            <p class="text-center text-xl mb-12 text-gray-600 amharic-text">ስለ ትምህርት ቤታችን</p>
            
            <div class="grid md:grid-cols-2 gap-12 items-center max-w-6xl mx-auto">
                <div>
                    <div class="bg-gradient-to-br from-green-800 to-green-600 p-8 rounded-lg shadow-xl text-white">
                        <h3 class="text-2xl font-bold mb-4">Our Mission</h3>
                        <p class="mb-4">To nurture young souls in the teachings of the <?= DENOMINATION_EN ?>, fostering spiritual growth, moral values, and a deep connection with our faith and heritage.</p>
                        <p class="amharic-text text-yellow-300">የኛ ተልእኮ ልጆችን በ<?= DENOMINATION_AM ?> ትምህርቶች እያሳደግን መንፈሳዊ እድገትን፣ የሞራል እሴቶችን እና ከእምነታችን እና ከቅርሶቻችን ጋር ጥልቅ ግንኙነት መፍጠር ነው።</p>
                    </div>
                </div>
                
                <div class="space-y-6">
                    <div class="flex items-start space-x-4">
                        <div class="bg-yellow-500 p-3 rounded-lg flex-shrink-0">
                            <svg class="w-6 h-6 text-green-900" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.747 0 3.332.477 4.5 1.253v13C19.832 18.477 18.247 18 16.5 18c-1.746 0-3.332.477-4.5 1.253"></path>
                            </svg>
                        </div>
                        <div>
                            <h4 class="text-xl font-bold text-green-900 mb-2">Biblical Education</h4>
                            <p class="text-gray-600">Comprehensive study of Holy Scripture in both Ge'ez and Amharic languages.</p>
                        </div>
                    </div>
                    
                    <div class="flex items-start space-x-4">
                        <div class="bg-yellow-500 p-3 rounded-lg flex-shrink-0">
                            <svg class="w-6 h-6 text-green-900" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                            </svg>
                        </div>
                        <div>
                            <h4 class="text-xl font-bold text-green-900 mb-2">Cultural Heritage</h4>
                            <p class="text-gray-600">Preservation and teaching of <?= DENOMINATION_EN ?> traditions and practices.</p>
                        </div>
                    </div>
                    
                    <div class="flex items-start space-x-4">
                        <div class="bg-yellow-500 p-3 rounded-lg flex-shrink-0">
                            <svg class="w-6 h-6 text-green-900" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z"></path>
                            </svg>
                        </div>
                        <div>
                            <h4 class="text-xl font-bold text-green-900 mb-2">Community Building</h4>
                            <p class="text-gray-600">Creating a loving community where children grow together in faith.</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- Programs Section -->
    <section id="programs" class="py-20 bg-gray-100">
        <div class="container mx-auto px-4">
            <h2 class="text-4xl font-bold text-center mb-4 text-green-900 section-title">Our Programs</h2>
            <p class="text-center text-xl mb-12 text-gray-600 amharic-text">የትምህርት መርሃ ግብሮች</p>
            
            <div class="grid md:grid-cols-3 gap-8 max-w-6xl mx-auto">
                <?php if (!empty($cms['programs'])): ?>
                    <?php foreach ($cms['programs'] as $prog): ?>
                        <div class="bg-white rounded-lg shadow-lg overflow-hidden card-hover">
                            <div class="bg-gradient-to-r from-green-700 to-green-600 p-6 text-white">
                                <div class="text-3xl mb-2" style="color:var(--fkss-gold)"><i class="<?= e($prog['icon_class'] ?: 'fa-solid fa-book') ?>"></i></div>
                                <h3 class="text-2xl font-bold mb-2"><?= e($prog['title']) ?></h3>
                                <?php if (!empty($prog['title_am'])): ?><p class="text-sm amharic-text text-yellow-300"><?= e($prog['title_am']) ?></p><?php endif; ?>
                            </div>
                            <div class="p-6">
                                <?php if (!empty($prog['description'])): ?>
                                    <p class="text-gray-600 text-sm mb-4"><?= nl2br(e($prog['description'])) ?></p>
                                <?php endif; ?>
                                <?php
                                $features = array_filter(array_map('trim', explode("\n", $prog['features'] ?? '')));
                                if (!empty($features)):
                                ?>
                                <ul class="space-y-2 text-gray-700">
                                    <?php foreach ($features as $feat): ?>
                                        <li class="flex items-center"><span class="text-yellow-500 mr-2">✓</span><?= e($feat) ?></li>
                                    <?php endforeach; ?>
                                </ul>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                <!-- Fallback content (shown until programs are added in the admin) -->
                <div class="bg-white rounded-lg shadow-lg overflow-hidden card-hover">
                    <div class="bg-gradient-to-r from-green-700 to-green-600 p-6 text-white">
                        <h3 class="text-2xl font-bold mb-2">Little Lambs</h3>
                        <p class="text-sm amharic-text text-yellow-300">ትንንሽ በጎች</p>
                        <p class="mt-2 text-sm">Ages 4-6</p>
                    </div>
                    <div class="p-6">
                        <ul class="space-y-2 text-gray-700">
                            <li class="flex items-center"><span class="text-yellow-500 mr-2">✓</span>Basic Bible stories</li>
                            <li class="flex items-center"><span class="text-yellow-500 mr-2">✓</span>Simple prayers &amp; hymns</li>
                            <li class="flex items-center"><span class="text-yellow-500 mr-2">✓</span>Introduction to Ge'ez alphabet</li>
                            <li class="flex items-center"><span class="text-yellow-500 mr-2">✓</span>Arts &amp; crafts activities</li>
                        </ul>
                    </div>
                </div>
                <div class="bg-white rounded-lg shadow-lg overflow-hidden card-hover">
                    <div class="bg-gradient-to-r from-green-700 to-green-600 p-6 text-white">
                        <h3 class="text-2xl font-bold mb-2">Young Disciples</h3>
                        <p class="text-sm amharic-text text-yellow-300">ወጣት ደቀ መዛሙርት</p>
                        <p class="mt-2 text-sm">Ages 7-12</p>
                    </div>
                    <div class="p-6">
                        <ul class="space-y-2 text-gray-700">
                            <li class="flex items-center"><span class="text-yellow-500 mr-2">✓</span>In-depth Scripture study</li>
                            <li class="flex items-center"><span class="text-yellow-500 mr-2">✓</span>Ge'ez language lessons</li>
                            <li class="flex items-center"><span class="text-yellow-500 mr-2">✓</span>Church history &amp; saints</li>
                            <li class="flex items-center"><span class="text-yellow-500 mr-2">✓</span>Liturgical music training</li>
                        </ul>
                    </div>
                </div>
                <div class="bg-white rounded-lg shadow-lg overflow-hidden card-hover">
                    <div class="bg-gradient-to-r from-green-700 to-green-600 p-6 text-white">
                        <h3 class="text-2xl font-bold mb-2">Teen Ministry</h3>
                        <p class="text-sm amharic-text text-yellow-300">የወጣቶች አገልግሎት</p>
                        <p class="mt-2 text-sm">Ages 13-18</p>
                    </div>
                    <div class="p-6">
                        <ul class="space-y-2 text-gray-700">
                            <li class="flex items-center"><span class="text-yellow-500 mr-2">✓</span>Advanced theological studies</li>
                            <li class="flex items-center"><span class="text-yellow-500 mr-2">✓</span>Leadership development</li>
                            <li class="flex items-center"><span class="text-yellow-500 mr-2">✓</span>Community service projects</li>
                            <li class="flex items-center"><span class="text-yellow-500 mr-2">✓</span>Youth fellowship &amp; retreats</li>
                        </ul>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </section>

    <!-- Schedule Section -->
    <section id="schedule" class="py-20 bg-white">
        <div class="container mx-auto px-4">
            <h2 class="text-4xl font-bold text-center mb-4 text-green-900 section-title">Weekly Schedule</h2>
            <p class="text-center text-xl mb-12 text-gray-600 amharic-text">የሳምንት መርሃ ግብር</p>
            
            <div class="max-w-4xl mx-auto">
                <div class="bg-gradient-to-br from-green-800 to-green-600 rounded-lg shadow-xl overflow-hidden">
                    <div class="p-8">
                        <div class="space-y-6">
                            <?php if (!empty($cms['schedule'])): ?>
                                <?php foreach ($cms['schedule'] as $sch): ?>
                                    <div class="bg-white rounded-lg p-6 card-hover">
                                        <div class="flex flex-col md:flex-row md:items-center md:justify-between">
                                            <div class="mb-2 md:mb-0">
                                                <h3 class="text-2xl font-bold text-green-900 flex items-center">
                                                    <span class="text-yellow-500 mr-3">📅</span>
                                                    <?= e($sch['day_of_week']) ?><?php if (!empty($sch['day_of_week_am'])): ?> <span class="amharic-text">/ <?= e($sch['day_of_week_am']) ?></span><?php endif; ?>
                                                </h3>
                                            </div>
                                            <?php if (!empty($sch['time_label'])): ?>
                                            <div class="text-gray-600"><p class="font-semibold"><?= e($sch['time_label']) ?></p></div>
                                            <?php endif; ?>
                                        </div>
                                        <div class="mt-3 ml-10">
                                            <p class="text-gray-700"><strong><?= e($sch['activity']) ?></strong></p>
                                            <?php if (!empty($sch['activity_am'])): ?><p class="text-gray-700 amharic-text"><?= e($sch['activity_am']) ?></p><?php endif; ?>
                                            <?php if (!empty($sch['location'])): ?><p class="text-gray-600 text-sm mt-1"><i class="fa-solid fa-location-dot" style="color:var(--fkss-maroon)"></i> <?= e($sch['location']) ?></p><?php endif; ?>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php else: ?>
                            <!-- Fallback schedule (shown until entries are added in admin) -->
                            <div class="bg-white rounded-lg p-6 card-hover">
                                <div class="flex flex-col md:flex-row md:items-center md:justify-between">
                                    <div class="mb-4 md:mb-0"><h3 class="text-2xl font-bold text-green-900 flex items-center"><span class="text-yellow-500 mr-3">📅</span>Sunday / እሁድ</h3></div>
                                    <div class="text-gray-600"><p class="font-semibold">9:00 AM - 12:00 PM</p></div>
                                </div>
                                <div class="mt-4 ml-10">
                                    <p class="text-gray-700"><strong>9:00 - 9:30:</strong> Morning Prayer &amp; Hymns</p>
                                    <p class="text-gray-700"><strong>9:30 - 10:30:</strong> Bible Study (Age Groups)</p>
                                    <p class="text-gray-700"><strong>10:30 - 11:15:</strong> Ge'ez Language Class</p>
                                    <p class="text-gray-700"><strong>11:15 - 12:00:</strong> Church History &amp; Activities</p>
                                </div>
                            </div>
                            <div class="bg-white rounded-lg p-6 card-hover">
                                <div class="flex flex-col md:flex-row md:items-center md:justify-between">
                                    <div class="mb-4 md:mb-0"><h3 class="text-2xl font-bold text-green-900 flex items-center"><span class="text-yellow-500 mr-3">📅</span>Wednesday / ረቡዕ</h3></div>
                                    <div class="text-gray-600"><p class="font-semibold">6:00 PM - 7:30 PM</p></div>
                                </div>
                                <div class="mt-4 ml-10">
                                    <p class="text-gray-700"><strong>Evening Prayer Service</strong></p>
                                    <p class="text-gray-700">Youth Fellowship &amp; Discussion Groups</p>
                                </div>
                            </div>
                            <div class="bg-white rounded-lg p-6 card-hover">
                                <div class="flex flex-col md:flex-row md:items-center md:justify-between">
                                    <div class="mb-4 md:mb-0"><h3 class="text-2xl font-bold text-green-900 flex items-center"><span class="text-yellow-500 mr-3">📅</span>Saturday / ቅዳሜ</h3></div>
                                    <div class="text-gray-600"><p class="font-semibold">2:00 PM - 4:00 PM</p></div>
                                </div>
                                <div class="mt-4 ml-10">
                                    <p class="text-gray-700"><strong>Special Activities Day</strong></p>
                                    <p class="text-gray-700">Music Practice, Arts, Sports &amp; Community Service</p>
                                </div>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- Teachers Section -->
    <section class="py-20 bg-gray-100">
        <div class="container mx-auto px-4">
            <h2 class="text-4xl font-bold text-center mb-4 text-green-900 section-title">Our Teachers</h2>
            <p class="text-center text-xl mb-12 text-gray-600 amharic-text">መምህራኖቻችን</p>
            
            <div class="grid md:grid-cols-4 gap-6 max-w-6xl mx-auto">
                <?php if (!empty($cms['teachers'])): ?>
                    <?php foreach ($cms['teachers'] as $t): ?>
                        <div class="bg-white rounded-lg shadow-lg p-6 text-center card-hover">
                            <?php if (!empty($t['photo_path'])): ?>
                                <div class="w-24 h-24 rounded-full mx-auto mb-4 overflow-hidden border-4" style="border-color:var(--fkss-gold)">
                                    <img src="<?= e($t['photo_path']) ?>" class="w-full h-full object-cover" alt="<?= e($t['name']) ?>">
                                </div>
                            <?php else: ?>
                                <div class="w-24 h-24 bg-gradient-to-br from-green-600 to-green-800 rounded-full mx-auto mb-4 flex items-center justify-center text-white text-3xl"><i class="fa-solid fa-user"></i></div>
                            <?php endif; ?>
                            <h3 class="text-xl font-bold text-green-900 mb-1 amharic-text"><?= e($t['name_am'] ?: $t['name']) ?></h3>
                            <?php if (!empty($t['role_title'])): ?><p class="text-gray-600 text-sm mb-2"><?= e($t['role_title']) ?></p><?php endif; ?>
                            <?php if (!empty($t['bio'])): ?><p class="text-gray-700 text-sm"><?= e($t['bio']) ?></p><?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                <!-- Fallback teachers (shown until added in admin) -->
                <div class="bg-white rounded-lg shadow-lg p-6 text-center card-hover">
                    <div class="w-24 h-24 bg-gradient-to-br from-green-600 to-green-800 rounded-full mx-auto mb-4 flex items-center justify-center text-white text-3xl"><i class="fa-solid fa-user"></i></div>
                    <h3 class="text-xl font-bold text-green-900 mb-1">Abba Tekle</h3>
                    <p class="text-gray-600 text-sm mb-2">Head Teacher</p>
                    <p class="text-gray-700 text-sm">20+ years of service</p>
                </div>
                <div class="bg-white rounded-lg shadow-lg p-6 text-center card-hover">
                    <div class="w-24 h-24 bg-gradient-to-br from-green-600 to-green-800 rounded-full mx-auto mb-4 flex items-center justify-center text-white text-3xl"><i class="fa-solid fa-user"></i></div>
                    <h3 class="text-xl font-bold text-green-900 mb-1">Memhir Dawit</h3>
                    <p class="text-gray-600 text-sm mb-2">Ge'ez Teacher</p>
                    <p class="text-gray-700 text-sm">Biblical Languages</p>
                </div>
                <div class="bg-white rounded-lg shadow-lg p-6 text-center card-hover">
                    <div class="w-24 h-24 bg-gradient-to-br from-green-600 to-green-800 rounded-full mx-auto mb-4 flex items-center justify-center text-white text-3xl"><i class="fa-solid fa-user"></i></div>
                    <h3 class="text-xl font-bold text-green-900 mb-1">W/ro Marta</h3>
                    <p class="text-gray-600 text-sm mb-2">Children's Ministry</p>
                    <p class="text-gray-700 text-sm">Ages 4-8 Coordinator</p>
                </div>
                <div class="bg-white rounded-lg shadow-lg p-6 text-center card-hover">
                    <div class="w-24 h-24 bg-gradient-to-br from-green-600 to-green-800 rounded-full mx-auto mb-4 flex items-center justify-center text-white text-3xl"><i class="fa-solid fa-user"></i></div>
                    <h3 class="text-xl font-bold text-green-900 mb-1">Gashe Solomon</h3>
                    <p class="text-gray-600 text-sm mb-2">Music Director</p>
                    <p class="text-gray-700 text-sm">Traditional Hymns</p>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </section>

    <!-- Gallery Section -->
    <section id="gallery" class="py-20 bg-white">
        <div class="container mx-auto px-4">
            <h2 class="text-4xl font-bold text-center mb-4 text-green-900 section-title">Gallery</h2>
            <p class="text-center text-xl mb-12 text-gray-600 amharic-text">የፎቶ መድብር</p>
            
            <?php if (!empty($cms['featured'])): ?>
            <!-- Featured Slideshow -->
            <div class="max-w-4xl mx-auto mb-10">
                <div class="relative rounded-2xl overflow-hidden shadow-2xl" style="height:420px;background:#1a0606">
                    <?php foreach ($cms['featured'] as $i => $ph): ?>
                        <div class="gallery-slide" data-slide="<?= $i ?>" style="position:absolute;inset:0;opacity:<?= $i===0?'1':'0' ?>;transition:opacity 0.6s ease">
                            <img src="<?= e($ph['image_path']) ?>" style="width:100%;height:100%;object-fit:cover" alt="<?= e($ph['caption']??'') ?>">
                            <?php if (!empty($ph['caption']) || !empty($ph['caption_am'])): ?>
                            <div style="position:absolute;bottom:0;left:0;right:0;background:linear-gradient(transparent,rgba(64,0,0,0.85));padding:2.5rem 1.5rem 1.5rem;color:#fff">
                                <?php if (!empty($ph['caption_am'])): ?><p class="amharic-text text-xl font-bold"><?= e($ph['caption_am']) ?></p><?php endif; ?>
                                <?php if (!empty($ph['caption'])): ?><p class="text-sm" style="color:var(--fkss-gold)"><?= e($ph['caption']) ?></p><?php endif; ?>
                            </div>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                    <?php if (count($cms['featured']) > 1): ?>
                    <button onclick="slideMove(-1)" style="position:absolute;left:12px;top:50%;transform:translateY(-50%);width:44px;height:44px;border-radius:50%;background:rgba(240,192,0,0.9);color:var(--fkss-maroon-dark);border:none;cursor:pointer;font-size:1.1rem;display:flex;align-items:center;justify-content:center;z-index:5"><i class="fa-solid fa-chevron-left"></i></button>
                    <button onclick="slideMove(1)" style="position:absolute;right:12px;top:50%;transform:translateY(-50%);width:44px;height:44px;border-radius:50%;background:rgba(240,192,0,0.9);color:var(--fkss-maroon-dark);border:none;cursor:pointer;font-size:1.1rem;display:flex;align-items:center;justify-content:center;z-index:5"><i class="fa-solid fa-chevron-right"></i></button>
                    <div id="slideDots" style="position:absolute;bottom:12px;left:50%;transform:translateX(-50%);display:flex;gap:6px;z-index:5">
                        <?php foreach ($cms['featured'] as $i => $ph): ?>
                            <button onclick="slideGo(<?= $i ?>)" class="slide-dot" data-dot="<?= $i ?>" style="width:9px;height:9px;border-radius:50%;border:none;cursor:pointer;background:<?= $i===0?'var(--fkss-gold)':'rgba(255,255,255,0.5)' ?>"></button>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>

            <?php if (!empty($cms['gallery'])): ?>
            <!-- Photo Grid -->
            <div class="grid md:grid-cols-3 gap-6 max-w-6xl mx-auto">
                <?php foreach ($cms['gallery'] as $ph): ?>
                    <div class="relative h-64 rounded-lg shadow-lg overflow-hidden card-hover" style="background:#1a0606">
                        <img src="<?= e($ph['image_path']) ?>" style="width:100%;height:100%;object-fit:cover" alt="<?= e($ph['caption']??'') ?>">
                        <?php if (!empty($ph['caption']) || !empty($ph['caption_am'])): ?>
                        <div class="absolute bottom-0 left-0 right-0 text-white p-4" style="background:linear-gradient(transparent,rgba(64,0,0,0.8))">
                            <p class="font-semibold amharic-text"><?= e($ph['caption_am'] ?: $ph['caption']) ?></p>
                        </div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
            <?php else: ?>
            <!-- Fallback gallery (shown until photos are uploaded in admin) -->
            <div class="grid md:grid-cols-3 gap-6 max-w-6xl mx-auto">
                <div class="relative h-64 bg-gradient-to-br from-green-700 to-green-500 rounded-lg shadow-lg overflow-hidden card-hover">
                    <div class="absolute inset-0 flex items-center justify-center text-white text-6xl">📖</div>
                    <div class="absolute bottom-0 left-0 right-0 bg-black bg-opacity-50 text-white p-4"><p class="font-semibold">Bible Study Class</p></div>
                </div>
                <div class="relative h-64 bg-gradient-to-br from-yellow-600 to-yellow-400 rounded-lg shadow-lg overflow-hidden card-hover">
                    <div class="absolute inset-0 flex items-center justify-center text-white text-6xl">🎵</div>
                    <div class="absolute bottom-0 left-0 right-0 bg-black bg-opacity-50 text-white p-4"><p class="font-semibold">Music &amp; Hymns Practice</p></div>
                </div>
                <div class="relative h-64 bg-gradient-to-br from-red-700 to-red-500 rounded-lg shadow-lg overflow-hidden card-hover">
                    <div class="absolute inset-0 flex items-center justify-center text-white text-6xl">⛪</div>
                    <div class="absolute bottom-0 left-0 right-0 bg-black bg-opacity-50 text-white p-4"><p class="font-semibold">Sunday Service</p></div>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </section>

    <!-- Contact Section -->
    <section id="contact" class="py-20 bg-gradient-to-br from-green-800 to-green-600 text-white">
        <div class="container mx-auto px-4">
            <h2 class="text-4xl font-bold text-center mb-4 section-title">Contact Us</h2>
            <p class="text-center text-xl mb-12 amharic-text text-yellow-300">ያግኙን</p>
            
            <div class="grid md:grid-cols-2 gap-12 max-w-6xl mx-auto">
                <!-- Contact Information -->
                <div>
                    <h3 class="text-2xl font-bold mb-6">Get in Touch</h3>
                    
                    <div class="space-y-4">
                        <div class="flex items-start space-x-4">
                            <div class="bg-yellow-500 p-3 rounded-lg flex-shrink-0">
                                <svg class="w-6 h-6 text-green-900" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"></path>
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z"></path>
                                </svg>
                            </div>
                            <div>
                                <h4 class="font-bold text-lg mb-1">Location</h4>
                                <p><?= DENOMINATION_EN ?></p>
                                <p class="text-yellow-300">https://maps.app.goo.gl/iHSzmE5BphXrWo8Z8</p>
                            </div>
                        </div>
                        
                        <div class="flex items-start space-x-4">
                            <div class="bg-yellow-500 p-3 rounded-lg flex-shrink-0">
                                <svg class="w-6 h-6 text-green-900" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.516l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z"></path>
                                </svg>
                            </div>
                            <div>
                                <h4 class="font-bold text-lg mb-1">Phone</h4>
                                <p>+251 XXX XXX XXX</p>
                                <p class="text-sm text-yellow-300">Sunday: 8:00 AM - 1:00 PM</p>
                            </div>
                        </div>
                        
                        <div class="flex items-start space-x-4">
                            <div class="bg-yellow-500 p-3 rounded-lg flex-shrink-0">
                                <svg class="w-6 h-6 text-green-900" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"></path>
                                </svg>
                            </div>
                            <div>
                                <h4 class="font-bold text-lg mb-1">Email</h4>
                                <p>info@wulidebirhan.org</p>
                                <p class="text-sm text-yellow-300">We'll respond within 24 hours</p>
                            </div>
                        </div>
                        
                        <div class="flex items-start space-x-4">
                            <div class="bg-yellow-500 p-3 rounded-lg flex-shrink-0">
                                <svg class="w-6 h-6 text-green-900" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                                </svg>
                            </div>
                            <div>
                                <h4 class="font-bold text-lg mb-1">Office Hours</h4>
                                <p>Sunday: 8:30 AM - 12:30 PM</p>
                                <p>Wednesday: 5:30 PM - 8:00 PM</p>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Registration Form -->
                <div>
                    <h3 class="text-2xl font-bold mb-6">Register Your Child</h3>
                    <form id="registration-form" class="space-y-4">
                        <!-- Honeypot anti-spam (hidden from humans) -->
                        <input type="text" name="website" style="position:absolute;left:-9999px" tabindex="-1" autocomplete="off">
                        <div>
                            <label class="block mb-2 font-semibold">Parent/Guardian Name</label>
                            <input type="text" name="guardian_name_unused" class="w-full px-4 py-2 rounded-lg text-gray-900 focus:outline-none focus:ring-2 focus:ring-yellow-500" placeholder="Full Name">
                        </div>
                        <div>
                            <label class="block mb-2 font-semibold">Child's Name *</label>
                            <input type="text" name="full_name" required class="w-full px-4 py-2 rounded-lg text-gray-900 focus:outline-none focus:ring-2 focus:ring-yellow-500" placeholder="Child's Full Name">
                        </div>
                        <div>
                            <label class="block mb-2 font-semibold">Child's Age</label>
                            <select name="age" class="w-full px-4 py-2 rounded-lg text-gray-900 focus:outline-none focus:ring-2 focus:ring-yellow-500">
                                <option value="">Select Age</option>
                                <?php for ($a = 4; $a <= 18; $a++): ?><option value="<?= $a ?>"><?= $a ?> years</option><?php endfor; ?>
                            </select>
                        </div>
                        <div>
                            <label class="block mb-2 font-semibold">Phone Number *</label>
                            <input type="tel" name="phone" required class="w-full px-4 py-2 rounded-lg text-gray-900 focus:outline-none focus:ring-2 focus:ring-yellow-500" placeholder="+251 XXX XXX XXX">
                        </div>
                        <div>
                            <label class="block mb-2 font-semibold">Email</label>
                            <input type="email" name="email" class="w-full px-4 py-2 rounded-lg text-gray-900 focus:outline-none focus:ring-2 focus:ring-yellow-500" placeholder="your.email@example.com">
                        </div>
                        <div>
                            <label class="block mb-2 font-semibold">Message (Optional)</label>
                            <textarea name="message" rows="3" class="w-full px-4 py-2 rounded-lg text-gray-900 focus:outline-none focus:ring-2 focus:ring-yellow-500" placeholder="Any questions or special needs?"></textarea>
                        </div>
                        <button type="submit" id="regSubmitBtn" class="w-full bg-yellow-500 hover:bg-yellow-600 text-green-900 font-bold py-3 px-6 rounded-lg transition transform hover:scale-105">
                            Submit Registration
                        </button>
                        <div id="regFormMsg" class="hidden text-center p-3 rounded-lg text-sm"></div>
                    </form>
                </div>
            </div>
        </div>
    </section>

    <!-- Footer -->
    <footer class="bg-gray-900 text-white py-12">
        <div class="container mx-auto px-4">
            <div class="grid md:grid-cols-3 gap-8 mb-8">
                <div>
                    <div class="flex items-center space-x-3 mb-4">
                        <div class="cross-icon w-12 h-12"></div>
                        <div>
                            <h3 class="font-bold text-lg amharic-text"><?= SCHOOL_NAME_SHORT_AM ?></h3>
                            <p class="text-sm text-gray-400"><?= SCHOOL_TRANSLATION_EN ?></p>
                        </div>
                    </div>
                    <p class="text-gray-400 text-sm">Nurturing young souls in the Orthodox Tewahdo faith since 2010.</p>
                </div>
                
                <div>
                    <h4 class="font-bold text-lg mb-4">Quick Links</h4>
                    <ul class="space-y-2 text-gray-400">
                        <li><a href="#home" class="hover:text-yellow-400 transition">Home</a></li>
                        <li><a href="#about" class="hover:text-yellow-400 transition">About Us</a></li>
                        <li><a href="#programs" class="hover:text-yellow-400 transition">Programs</a></li>
                        <li><a href="#schedule" class="hover:text-yellow-400 transition">Schedule</a></li>
                        <li><a href="#contact" class="hover:text-yellow-400 transition">Contact</a></li>
                    </ul>
                </div>
                
                <div>
                    <h4 class="font-bold text-lg mb-4">Connect With Us</h4>
                    <div class="flex space-x-4">
                        <?php if (!empty($cms['social'])): ?>
                            <?php foreach ($cms['social'] as $soc): ?>
                                <a href="<?= e($soc['url']) ?>" target="_blank" rel="noopener" title="<?= e($soc['label'] ?: $soc['platform']) ?>" class="bg-yellow-500 hover:bg-yellow-600 w-11 h-11 flex items-center justify-center rounded-full transition transform hover:scale-110">
                                    <i class="<?= e($soc['icon_class']) ?> text-green-900 text-lg"></i>
                                </a>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <a href="#" class="bg-yellow-500 hover:bg-yellow-600 w-11 h-11 flex items-center justify-center rounded-full transition transform hover:scale-110"><i class="fa-brands fa-facebook text-green-900 text-lg"></i></a>
                            <a href="#" class="bg-yellow-500 hover:bg-yellow-600 w-11 h-11 flex items-center justify-center rounded-full transition transform hover:scale-110"><i class="fa-brands fa-telegram text-green-900 text-lg"></i></a>
                            <a href="#" class="bg-yellow-500 hover:bg-yellow-600 w-11 h-11 flex items-center justify-center rounded-full transition transform hover:scale-110"><i class="fa-brands fa-youtube text-green-900 text-lg"></i></a>
                        <?php endif; ?>
                    </div>
                    <p class="text-gray-400 text-sm mt-4 amharic-text">በማህበራዊ ሚዲያ ይከተሉን</p>
                </div>
            </div>
            
            <div class="border-t border-gray-800 pt-8 text-center text-gray-400 text-sm">
                <p>&copy; <?= COPYRIGHT_YEAR ?> <?= SCHOOL_NAME_AMHARIC ?> - <?= SCHOOL_TRANSLATION_EN ?> <?= SCHOOL_TYPE ?>. All rights reserved.</p>
                <p class="mt-2"><?= DENOMINATION_EN ?></p>
            </div>
        </div>
    </footer>

    <!-- JavaScript -->
    <script>
        // Mobile menu toggle
        const mobileMenuBtn = document.getElementById('mobile-menu-btn');
        const mobileMenu = document.getElementById('mobile-menu');
        
        mobileMenuBtn.addEventListener('click', () => {
            mobileMenu.classList.toggle('hidden');
        });
        
        // Close mobile menu when clicking on a link
        const mobileLinks = mobileMenu.querySelectorAll('a');
        mobileLinks.forEach(link => {
            link.addEventListener('click', () => {
                mobileMenu.classList.add('hidden');
            });
        });
        
        // Smooth scrolling for anchor links
        document.querySelectorAll('a[href^="#"]').forEach(anchor => {
            anchor.addEventListener('click', function (e) {
                e.preventDefault();
                const target = document.querySelector(this.getAttribute('href'));
                if (target) {
                    const offset = 80; // Account for fixed header
                    const targetPosition = target.offsetTop - offset;
                    window.scrollTo({
                        top: targetPosition,
                        behavior: 'smooth'
                    });
                }
            });
        });
        
        // ── Registration form: real submission to register_submit.php ──
        const registrationForm = document.getElementById('registration-form');
        if (registrationForm) {
            registrationForm.addEventListener('submit', async (e) => {
                e.preventDefault();
                const btn = document.getElementById('regSubmitBtn');
                const msg = document.getElementById('regFormMsg');
                const origText = btn.textContent;
                btn.disabled = true;
                btn.textContent = 'Submitting…';
                msg.className = 'hidden';

                try {
                    const fd = new FormData(registrationForm);
                    const r = await fetch('/register_submit.php', { method: 'POST', body: fd, headers: { 'Accept': 'application/json' } });
                    const ct = r.headers.get('content-type') || '';
                    let d;
                    if (ct.includes('application/json')) {
                        d = await r.json();
                    } else {
                        throw new Error('bad response');
                    }
                    if (d.status === 'success') {
                        msg.className = 'text-center p-3 rounded-lg text-sm';
                        msg.style.background = '#dcfce7';
                        msg.style.color = '#166534';
                        msg.textContent = d.message;
                        registrationForm.reset();
                    } else {
                        msg.className = 'text-center p-3 rounded-lg text-sm';
                        msg.style.background = '#fee2e2';
                        msg.style.color = '#991b1b';
                        msg.textContent = d.message || 'Something went wrong. Please try again.';
                    }
                } catch (err) {
                    msg.className = 'text-center p-3 rounded-lg text-sm';
                    msg.style.background = '#fee2e2';
                    msg.style.color = '#991b1b';
                    msg.textContent = 'Could not submit. Please check your connection and try again.';
                } finally {
                    btn.disabled = false;
                    btn.textContent = origText;
                }
            });
        }

        // ── Gallery featured slideshow ──
        let slideIndex = 0;
        const slides = document.querySelectorAll('.gallery-slide');
        const dots = document.querySelectorAll('.slide-dot');
        let slideTimer = null;

        function showSlide(n) {
            if (!slides.length) return;
            slideIndex = (n + slides.length) % slides.length;
            slides.forEach((s, i) => { s.style.opacity = (i === slideIndex) ? '1' : '0'; });
            dots.forEach((d, i) => { d.style.background = (i === slideIndex) ? 'var(--fkss-gold)' : 'rgba(255,255,255,0.5)'; });
        }
        window.slideMove = function(dir) { showSlide(slideIndex + dir); resetSlideTimer(); };
        window.slideGo = function(i) { showSlide(i); resetSlideTimer(); };
        function resetSlideTimer() {
            if (slideTimer) clearInterval(slideTimer);
            if (slides.length > 1) slideTimer = setInterval(() => showSlide(slideIndex + 1), 5000);
        }
        if (slides.length > 1) resetSlideTimer();
        
        // Navbar background on scroll
        const nav = document.querySelector('nav');
        window.addEventListener('scroll', () => {
            if (window.scrollY > 100) {
                nav.style.backgroundColor = 'rgba(64, 0, 0, 0.98)';
            } else {
                nav.style.backgroundColor = 'rgba(64, 0, 0, 0.95)';
            }
        });
        
        // Animate elements on scroll
        const observerOptions = {
            threshold: 0.1,
            rootMargin: '0px 0px -50px 0px'
        };
        
        const observer = new IntersectionObserver((entries) => {
            entries.forEach(entry => {
                if (entry.isIntersecting) {
                    entry.target.style.opacity = '1';
                    entry.target.style.transform = 'translateY(0)';
                }
            });
        }, observerOptions);
        
        // Observe all cards
        document.querySelectorAll('.card-hover').forEach(card => {
            card.style.opacity = '0';
            card.style.transform = 'translateY(20px)';
            card.style.transition = 'opacity 0.6s ease, transform 0.6s ease';
            observer.observe(card);
        });
    </script>
</body>
</html>