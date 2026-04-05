<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/csrf.php';

if (is_logged_in()) {
    header('Location: dashboard.php');
    exit;
}

$error   = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        $error = 'Invalid security token. Please try again.';
    } else {
        $username  = trim($_POST['username'] ?? '');
        $password  = $_POST['password'] ?? '';
        $client_ip = $_SERVER['HTTP_X_FORWARDED_FOR']
            ? explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'])[0]
            : ($_SERVER['REMOTE_ADDR'] ?? '127.0.0.1');
        $client_ip = trim($client_ip);

        if (empty($username) || empty($password)) {
            $error = 'Please enter username and password.';
        } elseif (!check_rate_limit($client_ip)) {
            $error = 'Too many login attempts. Please wait 1 minute.';
        } else {
            try {
                $db   = DB::getInstance();
                $user = $db->fetchOne(
                    'SELECT id, username, password_hash, is_active FROM dashboard_users WHERE username = ?',
                    [$username]
                );

                if ($user && $user['is_active'] && password_verify($password, $user['password_hash'])) {
                    $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
                    if (login_user((int)$user['id'], $client_ip, $ua)) {
                        header('Location: dashboard.php');
                        exit;
                    } else {
                        $error = 'Session creation failed. Please try again.';
                    }
                } else {
                    record_login_attempt($client_ip);
                    $error = 'Invalid username or password.';
                }
            } catch (Exception $e) {
                error_log('Login error: ' . $e->getMessage());
                $error = 'A system error occurred. Please try again.';
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Monitor Central — Login</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Mono:wght@400;600&family=Space+Grotesk:wght@300;400;500;600;700&display=swap" rel="stylesheet">
  <style>
    :root {
      --bg: #060810; --surface: #0d1117; --surface2: #161b22;
      --accent: #00c896; --accent-dim: #00956f; --danger: #ff2d55;
      --warn: #ff9500; --info: #0a84ff; --text: #e6edf3;
      --text-dim: #7d8590; --border: #21262d;
    }
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
    body {
      font-family: 'Space Grotesk', sans-serif;
      background: var(--bg);
      color: var(--text);
      min-height: 100vh;
      display: flex;
      align-items: center;
      justify-content: center;
      background-image: radial-gradient(ellipse at 20% 50%, rgba(0,200,150,0.04) 0%, transparent 60%),
                        radial-gradient(ellipse at 80% 20%, rgba(10,132,255,0.04) 0%, transparent 60%);
    }
    .login-wrap { width: 100%; max-width: 420px; padding: 24px; }
    .login-logo {
      text-align: center; margin-bottom: 32px;
      font-family: 'IBM Plex Mono', monospace;
    }
    .login-logo .icon { font-size: 3rem; line-height: 1; }
    .login-logo h1 { font-size: 1.6rem; color: var(--accent); margin-top: 8px; letter-spacing: 0.05em; }
    .login-logo p { color: var(--text-dim); font-size: 0.85rem; margin-top: 4px; }
    .login-card {
      background: var(--surface);
      border: 1px solid var(--border);
      border-radius: 12px;
      padding: 32px;
    }
    .form-group { margin-bottom: 20px; }
    label { display: block; font-size: 0.85rem; color: var(--text-dim); margin-bottom: 6px; font-weight: 500; }
    input[type="text"], input[type="password"] {
      width: 100%; background: var(--surface2); border: 1px solid var(--border);
      color: var(--text); border-radius: 8px; padding: 10px 14px;
      font-size: 0.95rem; font-family: inherit; transition: border-color 0.2s;
      outline: none;
    }
    input[type="text"]:focus, input[type="password"]:focus { border-color: var(--accent); }
    .btn-login {
      width: 100%; padding: 12px; background: var(--accent); color: #000;
      border: none; border-radius: 8px; font-size: 1rem; font-weight: 700;
      font-family: inherit; cursor: pointer; transition: background 0.2s, transform 0.1s;
      letter-spacing: 0.02em;
    }
    .btn-login:hover { background: var(--accent-dim); }
    .btn-login:active { transform: scale(0.98); }
    .alert-error {
      background: rgba(255,45,85,0.1); border: 1px solid rgba(255,45,85,0.3);
      color: #ff6b80; border-radius: 8px; padding: 10px 14px;
      font-size: 0.875rem; margin-bottom: 20px;
    }
    .login-footer { text-align: center; margin-top: 20px; color: var(--text-dim); font-size: 0.8rem; }
  </style>
</head>
<body>
  <div class="login-wrap">
    <div class="login-logo">
      <div class="icon">🔒</div>
      <h1>Monitor Central</h1>
      <p>Security Monitoring Dashboard</p>
    </div>
    <div class="login-card">
      <?php if ($error): ?>
        <div class="alert-error"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
      <?php endif; ?>
      <form method="POST" action="">
        <?= csrf_field() ?>
        <div class="form-group">
          <label for="username">Username</label>
          <input type="text" id="username" name="username" autocomplete="username" required
                 value="<?= htmlspecialchars($_POST['username'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
        </div>
        <div class="form-group">
          <label for="password">Password</label>
          <input type="password" id="password" name="password" autocomplete="current-password" required>
        </div>
        <button type="submit" class="btn-login">Sign In</button>
      </form>
    </div>
    <div class="login-footer">Monitor Central &copy; <?= date('Y') ?></div>
  </div>
</body>
</html>
