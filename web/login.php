<?php
/**
 * Colorimetric Analyzer - Login Page (login.php)
 * Antarmuka masuk modern bertema Light Theme bersih & elegan
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth.php';

// Jika sudah login, langsung ke index
if (isLoggedIn()) {
    header('Location: index.php');
    exit;
}

$error = '';
$redirect = $_GET['redirect'] ?? 'index.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    // Cek kredensial
    if ($username === ADMIN_USERNAME && $password === ADMIN_PASSWORD) {
        $_SESSION['ca_logged_in'] = true;
        $_SESSION['ca_user'] = $username;
        $_SESSION['ca_login_time'] = time();

        // Hindari open redirect
        $target = (strpos($redirect, 'login.php') !== false || empty($redirect)) ? 'index.php' : $redirect;
        header('Location: ' . $target);
        exit;
    } else {
        $error = 'Username atau kata sandi yang Anda masukkan salah.';
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Masuk - <?= defined('APP_NAME') ? APP_NAME : 'Colorimetric Analyzer' ?></title>
  <link rel="stylesheet" href="assets/css/style.css?v=20261015_light_v4">
  <link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><text y='.9em' font-size='90'>🌈</text></svg>">
  <style>
    body {
      display: flex;
      align-items: center;
      justify-content: center;
      min-height: 100vh;
      padding: 1.5rem;
      background: #FFFFFF;
      background-image: 
        radial-gradient(circle at 15% 15%, rgba(37, 99, 235, 0.08) 0%, transparent 45%),
        radial-gradient(circle at 85% 85%, rgba(245, 158, 11, 0.08) 0%, transparent 45%);
    }
    .login-card {
      width: 100%;
      max-width: 440px;
      background: #FFFFFF;
      border: 2px solid #2563EB;
      border-radius: 20px;
      box-shadow: 0 12px 30px -5px rgba(37, 99, 235, 0.15), 0 8px 10px -6px rgba(0, 0, 0, 0.04);
      padding: 2.25rem;
    }
    .brand-icon-big {
      width: 60px;
      height: 60px;
      border-radius: 16px;
      background: #FACC15;
      display: flex;
      align-items: center;
      justify-content: center;
      color: #1E3A8A;
      font-size: 1.8rem;
      font-weight: 900;
      box-shadow: 0 4px 12px rgba(245, 158, 11, 0.3);
      margin: 0 auto 1.25rem auto;
      border: 2px solid #F59E0B;
    }
  </style>
</head>
<body>

  <div class="login-card">
    <div style="text-align: center; margin-bottom: 1.75rem;">
      <div class="brand-icon-big">CA</div>
      <h1 style="font-size: 1.5rem; font-weight: 800; color: #0F172A; letter-spacing: -0.02em;">
        Colorimetric Analyzer
      </h1>
      <p style="font-size: 0.88rem; color: #64748B; margin-top: 0.35rem;">
        Masuk ke Sistem Analisis Spektrofotometri
      </p>
    </div>

    <?php if (isset($_GET['msg']) && $_GET['msg'] === 'logged_out'): ?>
      <div class="alert alert-success" style="margin-bottom: 1.25rem; font-size: 0.85rem; padding: 0.75rem 1rem;">
        ✓ Anda telah berhasil keluar dari sistem.
      </div>
    <?php endif; ?>

    <?php if (!empty($error)): ?>
      <div class="alert alert-danger" style="margin-bottom: 1.25rem; font-size: 0.85rem; padding: 0.75rem 1rem;">
        ⚠️ <?= htmlspecialchars($error) ?>
      </div>
    <?php endif; ?>

    <form method="POST" action="login.php<?= !empty($redirect) ? '?redirect=' . urlencode($redirect) : '' ?>">
      <div class="form-group" style="margin-bottom: 1.15rem;">
        <label class="form-label" for="username">Username</label>
        <input 
          type="text" 
          id="username" 
          name="username" 
          class="form-control" 
          placeholder="admin" 
          value="<?= htmlspecialchars($_POST['username'] ?? 'admin') ?>" 
          required 
          autofocus
        >
      </div>

      <div class="form-group" style="margin-bottom: 1.5rem;">
        <label class="form-label" for="password">Kata Sandi</label>
        <input 
          type="password" 
          id="password" 
          name="password" 
          class="form-control" 
          placeholder="••••••••" 
          required
        >
      </div>

      <button type="submit" class="btn btn-primary" style="width: 100%; padding: 0.85rem; font-size: 1rem; font-weight: 600;">
        Masuk ke Dashboard
      </button>

      <div style="margin-top: 1.5rem; padding-top: 1.25rem; border-top: 1px solid #F1F5F9; text-align: center;">
        <div style="font-size: 0.8rem; color: #94A3B8;">
          Kredensial Default: <strong style="color: #475569;">admin</strong> / <strong style="color: #475569;">admin123</strong>
        </div>
      </div>
    </form>
  </div>

</body>
</html>
