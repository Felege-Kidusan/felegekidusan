<?php
// Load config (handles session, DB, helpers)
require_once __DIR__ . '/config.php';

// If already logged in, send to dashboard
if (isset($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true) {
    header('Location: dashboard.php');
    exit;
}

// Generate CSRF token
$csrfToken = generateCsrfToken();
?>
<!DOCTYPE html>
<html lang="am">
<head>
  <meta charset="UTF-8">
  <title><?= ADMIN_PANEL_TITLE ?> Login</title>
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <link rel="icon" href="<?= SCHOOL_LOGO_PATH ?>">
  <link href="https://fonts.googleapis.com/css2?family=Noto+Serif+Ethiopic:wght@400;600;700&family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">

  <style>
    :root {
      --maroon: <?= THEME_PRIMARY ?>;
      --maroon-light: <?= THEME_PRIMARY_LIGHT ?>;
      --maroon-dark: <?= THEME_PRIMARY_DARK ?>;
      --gold: <?= THEME_ACCENT ?>;
      --gold-dark: <?= THEME_ACCENT_2 ?>;
    }

    * {
      box-sizing: border-box;
      margin: 0;
      padding: 0;
      font-family: 'Poppins', system-ui, -apple-system, sans-serif;
    }

    .amharic {
      font-family: 'Noto Serif Ethiopic', serif;
    }

    body {
      min-height: 100vh;
      display: flex;
      align-items: center;
      justify-content: center;
      padding: 1.25rem;
      background:
        radial-gradient(circle at 15% 15%, rgba(240,192,0,0.12), transparent 45%),
        radial-gradient(circle at 85% 85%, rgba(240,192,0,0.08), transparent 40%),
        linear-gradient(150deg, var(--maroon-dark), var(--maroon) 55%, #4a1208);
      position: relative;
      overflow: hidden;
    }

    /* Decorative gold corner ornaments */
    body::before, body::after {
      content: '';
      position: fixed;
      width: 280px;
      height: 280px;
      border: 2px solid rgba(240,192,0,0.18);
      border-radius: 50%;
      pointer-events: none;
    }
    body::before { top: -110px; left: -110px; }
    body::after  { bottom: -110px; right: -110px; }

    .auth-wrapper {
      width: 100%;
      max-width: 410px;
      position: relative;
      z-index: 2;
    }

    .login-card {
      background: rgba(255,255,255,0.97);
      border-radius: 1.5rem;
      padding: 2.25rem 1.85rem 1.85rem;
      box-shadow: 0 22px 60px rgba(0,0,0,0.4);
      border-top: 5px solid var(--gold);
      position: relative;
    }

    /* ===== HEADER ===== */
    .login-header {
      text-align: center;
      margin-bottom: 1.75rem;
    }

    .logo-badge {
      width: 110px;
      height: 110px;
      margin: 0 auto 1rem;
      border-radius: 50%;
      background: #fff;
      display: flex;
      align-items: center;
      justify-content: center;
      box-shadow: 0 6px 18px rgba(96,0,0,0.18);
      border: 3px solid var(--gold);
      padding: 6px;
    }

    .logo-badge img {
      width: 100%;
      height: 100%;
      object-fit: contain;
    }

    .login-header h1 {
      font-size: 1.35rem;
      color: var(--maroon);
      font-weight: 700;
      line-height: 1.3;
      margin-bottom: 0.25rem;
    }

    .login-header .subtitle-en {
      font-size: 0.8rem;
      color: var(--gold-dark);
      font-weight: 600;
      letter-spacing: 0.5px;
      text-transform: uppercase;
      margin-bottom: 0.5rem;
    }

    .login-header .panel-label {
      display: inline-block;
      font-size: 0.72rem;
      color: #6b7280;
      background: #f3f4f6;
      padding: 0.25rem 0.85rem;
      border-radius: 999px;
    }

    /* Gold divider */
    .gold-divider {
      height: 2px;
      background: linear-gradient(90deg, transparent, var(--gold), transparent);
      margin: 1.25rem 0;
    }

    /* ===== FORM ===== */
    .login-form {
      display: flex;
      flex-direction: column;
      gap: 1rem;
    }

    .form-group {
      display: flex;
      flex-direction: column;
      gap: 0.4rem;
    }

    .form-group label {
      font-size: 0.82rem;
      color: var(--maroon);
      font-weight: 600;
    }

    .input-wrap {
      position: relative;
    }

    .input-wrap i {
      position: absolute;
      left: 0.9rem;
      top: 50%;
      transform: translateY(-50%);
      color: var(--gold-dark);
      font-size: 0.9rem;
    }

    .form-group input {
      width: 100%;
      padding: 0.75rem 0.9rem 0.75rem 2.4rem;
      border-radius: 0.75rem;
      border: 1.5px solid #e5e7eb;
      font-size: 0.92rem;
      outline: none;
      transition: border-color 0.15s, box-shadow 0.15s;
      background: #fafafa;
      color: #111;
    }

    .form-group input:focus {
      border-color: var(--gold);
      box-shadow: 0 0 0 3px rgba(240,192,0,0.18);
      background: #fff;
    }

    .form-meta-row {
      display: flex;
      justify-content: space-between;
      align-items: center;
      font-size: 0.78rem;
    }

    .remember-me {
      display: flex;
      align-items: center;
      gap: 0.4rem;
      color: #6b7280;
      cursor: pointer;
    }

    .remember-me input[type="checkbox"] {
      width: 15px;
      height: 15px;
      accent-color: var(--maroon);
      cursor: pointer;
    }

    .small-link {
      color: var(--maroon-light);
      text-decoration: none;
      font-weight: 500;
    }
    .small-link:hover { text-decoration: underline; }

    /* ===== BUTTON ===== */
    .btn-primary {
      width: 100%;
      padding: 0.85rem 1rem;
      margin-top: 0.5rem;
      border-radius: 0.75rem;
      border: none;
      font-size: 0.95rem;
      font-weight: 600;
      cursor: pointer;
      color: var(--maroon-dark);
      background: linear-gradient(135deg, var(--gold), var(--gold-dark));
      box-shadow: 0 8px 20px rgba(240,192,0,0.35);
      transition: transform 0.12s, box-shadow 0.12s, filter 0.12s;
      letter-spacing: 0.3px;
    }

    .btn-primary:hover {
      transform: translateY(-1px);
      box-shadow: 0 12px 26px rgba(240,192,0,0.45);
      filter: brightness(1.03);
    }
    .btn-primary:active { transform: translateY(0); }

    /* ===== ALERTS ===== */
    .alert {
      margin-bottom: 1rem;
      padding: 0.7rem 0.85rem;
      border-radius: 0.7rem;
      font-size: 0.82rem;
      display: flex;
      align-items: center;
      gap: 0.5rem;
    }
    .alert-error {
      background: #fef2f2;
      color: #b91c1c;
      border: 1px solid #fecaca;
    }
    .alert-success {
      background: #f0fdf4;
      color: #15803d;
      border: 1px solid #bbf7d0;
    }

    /* ===== FOOTER ===== */
    .login-footer-text {
      margin-top: 1.5rem;
      text-align: center;
      color: #9ca3af;
      font-size: 0.72rem;
      line-height: 1.5;
    }
    .login-footer-text .parish {
      color: var(--maroon-light);
      font-weight: 500;
    }

    @media (max-width: 480px) {
      .login-card { padding: 1.85rem 1.35rem 1.5rem; }
      .logo-badge { width: 92px; height: 92px; }
      .login-header h1 { font-size: 1.2rem; }
    }
  </style>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body>
  <div class="auth-wrapper">
    <div class="login-card">
      <div class="login-header">
        <div class="logo-badge">
          <img src="<?= SCHOOL_LOGO_PATH ?>" alt="<?= SCHOOL_NAME_SHORT ?> Logo"
               onerror="this.style.display='none';this.parentElement.innerHTML='<span style=&quot;font-size:2.5rem;color:var(--maroon)&quot;><?= ADMIN_LOGO_ICON ?></span>';">
        </div>
        <h1 class="amharic"><?= SCHOOL_NAME_AMHARIC ?></h1>
        <div class="subtitle-en"><?= SCHOOL_TRANSLATION_EN ?> <?= SCHOOL_TYPE ?></div>
        <span class="panel-label"><i class="fa-solid fa-lock" style="font-size:0.65rem"></i> Admin Portal</span>
      </div>

      <div class="gold-divider"></div>

      <?php
      if (isset($_GET['error']) && $_GET['error'] !== '') {
          echo '<div class="alert alert-error"><i class="fa-solid fa-circle-exclamation"></i><span>' . e($_GET['error']) . '</span></div>';
      }
      if (isset($_GET['timeout'])) {
          echo '<div class="alert alert-error"><i class="fa-solid fa-clock"></i><span>Your session expired. Please log in again.</span></div>';
      }
      if (isset($_GET['success']) && $_GET['success'] !== '') {
          echo '<div class="alert alert-success"><i class="fa-solid fa-circle-check"></i><span>' . e($_GET['success']) . '</span></div>';
      }
      ?>

      <form class="login-form" action="backend/login.php" method="POST">
        <?= csrfField() ?>
        <div class="form-group">
          <label for="username">Username / Email</label>
          <div class="input-wrap">
            <i class="fa-solid fa-user"></i>
            <input type="text" id="username" name="username" required placeholder="Enter your username" autocomplete="username">
          </div>
        </div>

        <div class="form-group">
          <label for="password">Password</label>
          <div class="input-wrap">
            <i class="fa-solid fa-key"></i>
            <input type="password" id="password" name="password" required placeholder="Enter your password" autocomplete="current-password">
          </div>
        </div>

        <div class="form-meta-row">
          <label class="remember-me">
            <input type="checkbox" name="remember" value="1">
            <span>Remember me</span>
          </label>
          <a href="#" class="small-link">Forgot password?</a>
        </div>

        <button type="submit" class="btn-primary">
          <i class="fa-solid fa-right-to-bracket" style="margin-right:0.4rem"></i> Sign In
        </button>
      </form>

      <div class="login-footer-text">
        <div class="parish amharic"><?= PARISH_NAME_AM ?></div>
        <small>© <span id="year"></span> <?= ADMIN_FOOTER_TEXT ?></small>
      </div>
    </div>
  </div>

  <script>
    document.getElementById('year').textContent = '<?php require_once __DIR__ . "/backend/ethiopian_date.php"; echo ethio_date_format(new DateTime('now', new DateTimeZone('Africa/Addis_Ababa')), "Y"); ?>';
  </script>
</body>
</html>
