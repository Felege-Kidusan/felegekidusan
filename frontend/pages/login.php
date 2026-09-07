<?php
/**
 * Login Page — frontend/pages/login.php
 * Uses theme CSS for all styling. No inline colors.
 */
require_once __DIR__ . '/../../config.php';

if (!empty($_SESSION['admin_logged_in'])) {
    header('Location: /frontend/pages/dashboard.php');
    exit;
}

$csrfToken = function_exists('generateCsrfToken') ? generateCsrfToken() : '';
$theme = defined('ACTIVE_THEME') ? ACTIVE_THEME : 'wbss';
?>
<!DOCTYPE html>
<html lang="<?= defined('SITE_LANG') ? SITE_LANG : 'en' ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e(defined('ADMIN_PANEL_TITLE') ? ADMIN_PANEL_TITLE : 'Login') ?></title>
    <link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><text y='.9em' font-size='90'><?= defined('ADMIN_LOGO_ICON') ? ADMIN_LOGO_ICON : '⛪' ?></text></svg>">
    <link rel="stylesheet" href="/themes/<?= $theme ?>/theme.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
</head>
<body class="school-login-page">
    <div class="school-login-wrapper">
        <div class="school-login-card">
            <img src="/themes/<?= $theme ?>/assets/logos/school_logo.png" 
                 class="school-login-logo" alt="Logo" 
                 onerror="this.style.display='none'">
            <h1 class="school-login-title"><?= e(defined('SCHOOL_NAME_SHORT') ? SCHOOL_NAME_SHORT : 'Login') ?></h1>
            <p class="school-login-sub amharic"><?= e(defined('SCHOOL_NAME_SHORT_AM') ? SCHOOL_NAME_SHORT_AM : '') ?></p>
            
            <div id="loginError" class="school-error-msg" style="display:none"></div>
            
            <form id="loginForm" method="post" action="<?= e(ssms_app_url('backend/auth/login.php')) ?>">
                <input type="hidden" id="csrf" name="csrf_token" value="<?= $csrfToken ?>">
                <div class="school-form-group">
                    <input type="text" id="username" name="username" autocomplete="username" class="school-input" placeholder="Username" required autofocus>
                </div>
                <div class="school-form-group" style="position:relative">
                    <input type="password" id="password" name="password" autocomplete="current-password" class="school-input" placeholder="Password" required style="padding-right:2.5rem">
                    <button type="button" id="togglePw" style="position:absolute;right:0.75rem;top:50%;transform:translateY(-50%);background:none;border:none;color:var(--school-text-dim);cursor:pointer">
                        <i class="fa-solid fa-eye"></i>
                    </button>
                </div>
                <button type="submit" id="loginBtn" class="btn-primary school-login-btn">
                    <i class="fa-solid fa-right-to-bracket"></i> Login
                </button>
            </form>
            
            <p style="font-size:0.65rem;color:rgba(255,255,255,0.3);margin-top:1.5rem"><?= e(defined('ADMIN_FOOTER_TEXT') ? ADMIN_FOOTER_TEXT : '') ?></p>
        </div>
    </div>
    
    <script>
    (function() {
        var btn = document.getElementById('loginBtn');
        var err = document.getElementById('loginError');
        
        function doLogin() {
            var un = document.getElementById('username').value.trim();
            var pw = document.getElementById('password').value;
            if (!un || !pw) { showErr('Enter username and password'); return; }
            
            btn.disabled = true;
            btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Logging in...';
            err.style.display = 'none';
            
            var fd = new FormData();
            fd.append('username', un);
            fd.append('password', pw);
            fd.append('csrf_token', document.getElementById('csrf').value);
            
            fetch(document.getElementById('loginForm').action, { method: 'POST', body: fd, credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
                .then(function(r) { return r.json(); })
                .then(function(d) {
                    if (d.status === 'success' || d.success) {
                        window.location.href = d.redirect || '/admin/dashboard.php';
                    } else {
                        showErr(d.message || 'Invalid credentials');
                        btn.disabled = false;
                        btn.innerHTML = '<i class="fa-solid fa-right-to-bracket"></i> Login';
                    }
                })
                .catch(function() {
                    showErr('Connection error');
                    btn.disabled = false;
                    btn.innerHTML = '<i class="fa-solid fa-right-to-bracket"></i> Login';
                });
        }
        
        function showErr(msg) { err.textContent = msg; err.style.display = 'block'; }
        
        document.getElementById('loginForm').addEventListener('submit', function(e) {
            e.preventDefault();
            if (!btn.disabled) doLogin();
        });
        
        document.getElementById('togglePw').addEventListener('click', function() {
            var inp = document.getElementById('password');
            var ico = this.querySelector('i');
            if (inp.type === 'password') { inp.type = 'text'; ico.className = 'fa-solid fa-eye-slash'; }
            else { inp.type = 'password'; ico.className = 'fa-solid fa-eye'; }
        });
    })();
    </script>
</body>
</html>
