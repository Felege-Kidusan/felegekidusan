<?php require_once __DIR__ . '/../school_config.php'; ?>
<!DOCTYPE html>
<html lang="am" dir="ltr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width,initial-scale=1,maximum-scale=1,user-scalable=no,viewport-fit=cover">
    <meta name="theme-color" content="#064e3b">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="format-detection" content="telephone=no">
    <meta name="description" content="<?= SCHOOL_NAME_SHORT_AM ?> <?= SCHOOL_TYPE_AM ?> — <?= SCHOOL_TRANSLATION_EN ?> <?= SCHOOL_TYPE ?> Management">
    <title><?= SCHOOL_NAME_SHORT ?></title>
    <link rel="manifest" href="/app/manifest.php">
    <link rel="icon" type="image/svg+xml" href="/app/icons/icon-192.svg">
    <link rel="apple-touch-icon" href="/app/icons/apple-touch-icon.png">
    <!-- Preconnect for faster font/icon loading -->
    <link rel="preconnect" href="https://fonts.googleapis.com" crossorigin>
    <link rel="preconnect" href="https://cdnjs.cloudflare.com" crossorigin>
    <!-- Critical inline CSS — renders INSTANTLY before external CSS loads -->
    <style>
        *{margin:0;padding:0;box-sizing:border-box;-webkit-tap-highlight-color:transparent}
        html,body{height:100%;overflow:hidden;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',system-ui,sans-serif;background:#0a1628;color:#e2e8f0}
        #splash{position:fixed;inset:0;background:linear-gradient(155deg,#0a1628 0%,#0f2d1f 40%,#064e3b 70%,#059669 100%);display:flex;flex-direction:column;align-items:center;justify-content:center;z-index:9999;transition:opacity .4s}
        #splash.out{opacity:0;pointer-events:none}
        .sp-icon{width:80px;height:80px;border-radius:22px;background:rgba(167,243,208,.12);display:flex;align-items:center;justify-content:center;margin-bottom:20px;backdrop-filter:blur(12px);border:1px solid rgba(167,243,208,.15)}
        .sp-icon svg{width:44px;height:44px}
        .sp-title{font-size:28px;font-weight:800;color:#fff;letter-spacing:1px}
        .sp-sub{color:rgba(255,255,255,.4);font-size:13px;margin-top:6px}
        .sp-loader{width:32px;height:32px;border:3px solid rgba(255,255,255,.1);border-top-color:#6ee7b7;border-radius:50%;animation:sp-spin .7s linear infinite;margin-top:32px}
        @keyframes sp-spin{to{transform:rotate(360deg)}}
        .sp-err{display:none;background:rgba(239,68,68,.15);border:1px solid rgba(239,68,68,.25);color:#fca5a5;padding:14px 20px;border-radius:14px;margin-top:24px;font-size:13px;text-align:center;max-width:320px;line-height:1.5}
        .sp-err a{color:#93c5fd;text-decoration:underline}
        .sp-err.show{display:block}
        /* Hide app shell until loaded */
        #app-root{display:none;height:100%}
        #app-root.ready{display:flex;flex-direction:column}
    </style>
    <!-- External CSS (non-blocking) -->
    <link rel="stylesheet" href="/app/css/app.css" media="print" onload="this.media='all'">
    <noscript><link rel="stylesheet" href="/app/css/app.css"></noscript>
    <!-- Font Awesome — loaded async to not block rendering -->
    <link rel="preload" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" as="style" onload="this.onload=null;this.rel='stylesheet'">
    <noscript><link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css"></noscript>

    <script>
    // School config injected by PHP — used by app.js, sw.js
    window.SCHOOL = {
        name: <?= json_encode(SCHOOL_NAME) ?>,
        short_name: <?= json_encode(SCHOOL_NAME_SHORT) ?>,
        name_am: <?= json_encode(SCHOOL_NAME_SHORT_AM . ' ' . SCHOOL_TYPE_AM) ?>,
        tagline_am: <?= json_encode(SCHOOL_TAGLINE_AM) ?>,
        translation: <?= json_encode(SCHOOL_TRANSLATION_EN) ?>,
        school_type: <?= json_encode(SCHOOL_TYPE) ?>,
        domain: <?= json_encode(SITE_DOMAIN) ?>,
        site_url: <?= json_encode(SITE_URL) ?>,
        cache_prefix: <?= json_encode(PWA_CACHE_PREFIX) ?>,
        cache_name: <?= json_encode(PWA_CACHE_NAME) ?>,
        developer: <?= json_encode(DEVELOPER_SHOW_CREDIT ? 'Powered by ' . DEVELOPER_NAME : '') ?>,
        logo_icon: <?= json_encode(ADMIN_LOGO_ICON) ?>
    };
    </script>
</head>
<body>
    <!-- SPLASH SCREEN — Shows instantly while JS loads -->
    <div id="splash">
        <div class="sp-icon">
            <svg viewBox="0 0 48 48" fill="none" xmlns="http://www.w3.org/2000/svg">
                <path d="M24 4L8 14v20l16 10 16-10V14L24 4z" fill="rgba(167,243,208,.2)" stroke="#6ee7b7" stroke-width="2"/>
                <text x="24" y="30" text-anchor="middle" font-family="Arial" font-size="18" font-weight="800" fill="#6ee7b7">W</text>
            </svg>
        </div>
        <div class="sp-title"><?= SCHOOL_NAME_SHORT ?></div>
        <div class="sp-sub"><?= SCHOOL_NAME_SHORT_AM ?> <?= SCHOOL_TYPE_AM ?></div>
        <div class="sp-loader" id="sp-loader"></div>
        <div class="sp-err" id="sp-err">
            <strong>Unable to load app</strong><br>
            Check your internet connection and <a href="javascript:location.reload()">try again</a>
        </div>
    </div>

    <!-- APP ROOT — Hidden until JS boots -->
    <div id="app-root"></div>

    <!-- Toast Container -->
    <div id="toast-c"></div>

    <!-- APP JS — ES5-compatible build for maximum device support -->
    <script src="/app/js/app.js"></script>
    <!-- Fallback: If JS fails completely after 8 seconds -->
    <script>
        setTimeout(function(){
            var s=document.getElementById('sp-loader');
            var e=document.getElementById('sp-err');
            if(s&&s.parentNode&&!document.getElementById('app-root').classList.contains('ready')){
                s.style.display='none';
                if(e)e.classList.add('show');
            }
        },8000);
    </script>
</body>
</html>
