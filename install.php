<?php
/**
 * Monitor Central — Installation Wizard
 */

session_start();

if (is_file(__DIR__ . '/config.php')) {
    $existing = file_get_contents(__DIR__ . '/config.php');
    if (strpos($existing, 'your_db_user') === false) {
        die('<b>Monitor Central is already installed.</b> Please delete install.php for security.');
    }
}

$step = max(1, min(6, (int)($_GET['step'] ?? 1)));

$errors   = [];
$messages = [];

function esc(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

function check_req(): array {
    $reqs = [];
    $reqs[] = ['PHP Version >= 8.0', version_compare(PHP_VERSION, '8.0.0', '>='), PHP_VERSION];
    $reqs[] = ['PDO MySQL',   extension_loaded('pdo_mysql'), extension_loaded('pdo_mysql') ? 'Loaded' : 'Missing'];
    $reqs[] = ['cURL',        extension_loaded('curl'),      extension_loaded('curl')      ? 'Loaded' : 'Missing'];
    $reqs[] = ['OpenSSL',     extension_loaded('openssl'),   extension_loaded('openssl')   ? 'Loaded' : 'Missing'];
    $reqs[] = ['JSON',        extension_loaded('json'),      extension_loaded('json')      ? 'Loaded' : 'Missing'];
    $reqs[] = ['config.php writable', is_writable(__DIR__), is_writable(__DIR__) ? 'OK' : 'Not writable'];
    return $reqs;
}

function all_pass(array $reqs): bool {
    foreach ($reqs as $r) { if (!$r[1]) return false; }
    return true;
}

// Step 2: Test DB connection
$db_error = '';
if ($step === 2 && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $db_host = trim($_POST['db_host'] ?? 'localhost');
    $db_name = trim($_POST['db_name'] ?? '');
    $db_user = trim($_POST['db_user'] ?? '');
    $db_pass = $_POST['db_pass'] ?? '';
    if ($db_name && $db_user) {
        try {
            $dsn = 'mysql:host=' . $db_host . ';charset=utf8mb4';
            $pdo = new PDO($dsn, $db_user, $db_pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            $pdo->exec('CREATE DATABASE IF NOT EXISTS `' . $db_name . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
            $_SESSION['install_db'] = compact('db_host', 'db_name', 'db_user', 'db_pass');
            header('Location: install.php?step=3');
            exit;
        } catch (PDOException $e) {
            $db_error = 'Connection failed: ' . esc($e->getMessage());
        }
    }
}

// Step 3: Run schema
if ($step === 3 && !empty($_SESSION['install_db'])) {
    try {
        $d   = $_SESSION['install_db'];
        $dsn = 'mysql:host=' . $d['db_host'] . ';dbname=' . $d['db_name'] . ';charset=utf8mb4';
        $pdo = new PDO($dsn, $d['db_user'], $d['db_pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $sql = file_get_contents(__DIR__ . '/sql/schema.sql');
        if ($sql) {
            $statements = array_filter(array_map('trim', explode(';', $sql)));
            foreach ($statements as $stmt) {
                if (!empty($stmt)) $pdo->exec($stmt);
            }
        }
        header('Location: install.php?step=4');
        exit;
    } catch (Exception $e) {
        $errors[] = 'Schema error: ' . esc($e->getMessage());
        $step = 3;
    }
}

// Step 4: Admin user
if ($step === 4 && $_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_SESSION['install_db'])) {
    $admin_user  = trim($_POST['admin_user']  ?? 'admin');
    $admin_email = trim($_POST['admin_email'] ?? '');
    $admin_pass  = $_POST['admin_pass']  ?? '';
    $admin_pass2 = $_POST['admin_pass2'] ?? '';
    if (strlen($admin_pass) < 8) {
        $errors[] = 'Password must be at least 8 characters.';
    } elseif ($admin_pass !== $admin_pass2) {
        $errors[] = 'Passwords do not match.';
    } else {
        try {
            $d   = $_SESSION['install_db'];
            $dsn = 'mysql:host=' . $d['db_host'] . ';dbname=' . $d['db_name'] . ';charset=utf8mb4';
            $pdo = new PDO($dsn, $d['db_user'], $d['db_pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            $hash = password_hash($admin_pass, PASSWORD_BCRYPT, ['cost' => 12]);
            $pdo->prepare(
                'INSERT INTO dashboard_users (username, password_hash, email, is_active) VALUES (?,?,?,1)
                 ON DUPLICATE KEY UPDATE password_hash=VALUES(password_hash), email=VALUES(email)'
            )->execute([$admin_user, $hash, $admin_email]);
            $_SESSION['install_admin'] = $admin_user;
            header('Location: install.php?step=5');
            exit;
        } catch (Exception $e) {
            $errors[] = 'Admin creation failed: ' . esc($e->getMessage());
        }
    }
}

// Step 5: Write config.php
if ($step === 5 && !empty($_SESSION['install_db'])) {
    $d       = $_SESSION['install_db'];
    $secret  = bin2hex(random_bytes(32));
    $appUrl  = (!empty($_SERVER['HTTPS']) ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . dirname($_SERVER['SCRIPT_NAME'] ?? '/');
    $content = '<?php' . PHP_EOL
        . "define('DB_HOST', " . var_export($d['db_host'], true) . ");" . PHP_EOL
        . "define('DB_NAME', " . var_export($d['db_name'], true) . ");" . PHP_EOL
        . "define('DB_USER', " . var_export($d['db_user'], true) . ");" . PHP_EOL
        . "define('DB_PASS', " . var_export($d['db_pass'], true) . ");" . PHP_EOL
        . "define('DB_CHARSET', 'utf8mb4');" . PHP_EOL
        . "define('APP_URL', " . var_export(rtrim($appUrl, '/'), true) . ");" . PHP_EOL
        . "define('APP_SECRET', " . var_export($secret, true) . ");" . PHP_EOL
        . "define('SESSION_LIFETIME', 3600);" . PHP_EOL
        . "define('ABUSEIPDB_API_KEY', '');" . PHP_EOL
        . "define('IPAPI_KEY', '');" . PHP_EOL;

    if (file_put_contents(__DIR__ . '/config.php', $content)) {
        header('Location: install.php?step=6');
        exit;
    } else {
        $errors[] = 'Could not write config.php. Please check directory permissions.';
    }
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Monitor Central — Installation</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@400;600;700&display=swap" rel="stylesheet">
  <style>
    :root { --bg:#060810;--surface:#0d1117;--surface2:#161b22;--accent:#00c896;--danger:#ff2d55;--warn:#ff9500;--text:#e6edf3;--text-dim:#7d8590;--border:#21262d; }
    *,*::before,*::after{box-sizing:border-box;margin:0;padding:0;}
    body{font-family:'Space Grotesk',sans-serif;background:var(--bg);color:var(--text);min-height:100vh;display:flex;flex-direction:column;align-items:center;justify-content:center;padding:24px;}
    .wizard{width:100%;max-width:560px;}
    .wizard-logo{text-align:center;margin-bottom:24px;}
    .wizard-logo h1{font-size:1.6rem;color:var(--accent);}
    .wizard-logo p{color:var(--text-dim);font-size:0.85rem;}
    .steps{display:flex;gap:0;margin-bottom:24px;border-bottom:1px solid var(--border);padding-bottom:16px;}
    .step-item{flex:1;text-align:center;font-size:0.75rem;color:var(--text-dim);}
    .step-item.active{color:var(--accent);font-weight:700;}
    .step-item.done{color:#00c896;}
    .card{background:var(--surface);border:1px solid var(--border);border-radius:10px;padding:28px;}
    .card h2{font-size:1.1rem;margin-bottom:20px;}
    .form-group{margin-bottom:16px;}
    label{display:block;font-size:0.82rem;color:var(--text-dim);margin-bottom:5px;font-weight:600;}
    input{width:100%;background:var(--surface2);border:1px solid var(--border);color:var(--text);border-radius:6px;padding:9px 12px;font-family:inherit;font-size:0.9rem;outline:none;}
    input:focus{border-color:var(--accent);}
    .btn{display:inline-flex;align-items:center;gap:6px;padding:10px 20px;border-radius:6px;font-family:inherit;font-size:0.9rem;font-weight:700;cursor:pointer;border:1px solid transparent;transition:all .15s;}
    .btn-accent{background:var(--accent);color:#000;}
    .btn-accent:hover{background:#00956f;}
    .btn-outline{background:transparent;color:var(--text);border-color:var(--border);}
    .alert-bar{border-radius:6px;padding:10px 14px;margin-bottom:16px;font-size:0.875rem;}
    .alert-bar.danger{background:rgba(255,45,85,.1);border:1px solid rgba(255,45,85,.3);color:#ff6b80;}
    .alert-bar.success{background:rgba(0,200,150,.1);border:1px solid rgba(0,200,150,.3);color:#00c896;}
    .req-table{width:100%;border-collapse:collapse;font-size:0.85rem;}
    .req-table td{padding:8px 10px;border-bottom:1px solid var(--border);}
    .ok{color:#00c896;} .fail{color:#ff2d55;}
    pre{background:var(--surface2);border-radius:6px;padding:12px;font-size:0.8rem;overflow-x:auto;margin-top:8px;}
  </style>
</head>
<body>
<div class="wizard">
  <div class="wizard-logo">
    <div style="font-size:2.5rem;">⬡</div>
    <h1>Monitor Central</h1>
    <p>Installation Wizard</p>
  </div>

  <div class="steps">
    <?php
    $step_labels = ['Requirements', 'Database', 'Schema', 'Admin User', 'Config', 'Complete'];
    foreach ($step_labels as $i => $label):
      $n = $i + 1;
      $cls = $n === $step ? 'active' : ($n < $step ? 'done' : '');
    ?>
    <div class="step-item <?= $cls ?>"><?= $n < $step ? '✓ ' : ($n . '. ') ?><?= $label ?></div>
    <?php endforeach; ?>
  </div>

  <?php foreach ($errors as $e): ?>
    <div class="alert-bar danger"><?= $e ?></div>
  <?php endforeach; ?>

  <div class="card">

    <?php if ($step === 1): ?>
      <h2>📋 System Requirements</h2>
      <?php $reqs = check_req(); ?>
      <table class="req-table">
        <?php foreach ($reqs as $req): ?>
        <tr>
          <td><?= esc($req[0]) ?></td>
          <td class="<?= $req[1] ? 'ok' : 'fail' ?>"><?= $req[1] ? '✓' : '✗' ?></td>
          <td style="color:var(--text-dim);"><?= esc((string)$req[2]) ?></td>
        </tr>
        <?php endforeach; ?>
      </table>
      <div style="margin-top:20px;">
        <?php if (all_pass($reqs)): ?>
          <a href="install.php?step=2" class="btn btn-accent">Next: Database →</a>
        <?php else: ?>
          <p style="color:var(--danger);font-size:0.875rem;margin-bottom:12px;">Please fix the requirements above before continuing.</p>
          <a href="install.php?step=1" class="btn btn-outline">Re-check</a>
        <?php endif; ?>
      </div>

    <?php elseif ($step === 2): ?>
      <h2>🗄 Database Configuration</h2>
      <?php if ($db_error): ?><div class="alert-bar danger"><?= $db_error ?></div><?php endif; ?>
      <form method="POST" action="install.php?step=2">
        <div class="form-group">
          <label>DB Host</label>
          <input type="text" name="db_host" value="localhost" required>
        </div>
        <div class="form-group">
          <label>Database Name</label>
          <input type="text" name="db_name" value="iddigital_monitor" required>
        </div>
        <div class="form-group">
          <label>DB Username</label>
          <input type="text" name="db_user" required>
        </div>
        <div class="form-group">
          <label>DB Password</label>
          <input type="password" name="db_pass">
        </div>
        <button type="submit" class="btn btn-accent">Test & Connect →</button>
      </form>

    <?php elseif ($step === 3): ?>
      <h2>⚙️ Running Schema</h2>
      <p style="color:var(--text-dim);margin-bottom:16px;">Creating database tables from sql/schema.sql...</p>
      <meta http-equiv="refresh" content="1;url=install.php?step=3">
      <div style="text-align:center;padding:20px;">
        <div style="width:32px;height:32px;border:3px solid var(--border);border-top-color:var(--accent);border-radius:50%;animation:spin .7s linear infinite;display:inline-block;"></div>
        <p style="margin-top:12px;color:var(--text-dim);">Processing...</p>
      </div>
      <style>@keyframes spin{to{transform:rotate(360deg);}}</style>

    <?php elseif ($step === 4): ?>
      <h2>👤 Create Admin User</h2>
      <form method="POST" action="install.php?step=4">
        <div class="form-group">
          <label>Username</label>
          <input type="text" name="admin_user" value="admin" required>
        </div>
        <div class="form-group">
          <label>Email</label>
          <input type="email" name="admin_email" placeholder="admin@yourdomain.com">
        </div>
        <div class="form-group">
          <label>Password (min 8 chars)</label>
          <input type="password" name="admin_pass" required minlength="8">
        </div>
        <div class="form-group">
          <label>Confirm Password</label>
          <input type="password" name="admin_pass2" required minlength="8">
        </div>
        <button type="submit" class="btn btn-accent">Create Admin →</button>
      </form>

    <?php elseif ($step === 5): ?>
      <h2>✍️ Writing Configuration</h2>
      <p style="color:var(--text-dim);margin-bottom:16px;">Writing config.php...</p>
      <meta http-equiv="refresh" content="1;url=install.php?step=5">
      <div style="text-align:center;padding:20px;">
        <div style="width:32px;height:32px;border:3px solid var(--border);border-top-color:var(--accent);border-radius:50%;animation:spin .7s linear infinite;display:inline-block;"></div>
      </div>
      <style>@keyframes spin{to{transform:rotate(360deg);}}</style>

    <?php elseif ($step === 6): ?>
      <h2>🎉 Installation Complete!</h2>
      <div class="alert-bar success">Monitor Central has been installed successfully!</div>
      <p style="color:var(--text-dim);margin-bottom:20px;">
        Admin user: <strong style="color:var(--text);"><?= esc($_SESSION['install_admin'] ?? 'admin') ?></strong>
      </p>
      <div style="background:var(--surface2);border-radius:6px;padding:16px;margin-bottom:20px;">
        <p style="color:var(--warn);font-weight:700;margin-bottom:8px;">⚠️ Security Warning</p>
        <p style="color:var(--text-dim);font-size:0.875rem;">
          <strong>Delete install.php immediately</strong> to prevent unauthorized reinstallation:
        </p>
        <pre>rm <?= esc(__FILE__) ?></pre>
      </div>
      <a href="index.php" class="btn btn-accent">→ Go to Dashboard</a>
    <?php endif; ?>

  </div>
</div>
</body>
</html>
