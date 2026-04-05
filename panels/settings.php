<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/csrf.php';
require_auth();

$db  = DB::getInstance();
$msg = '';
$msgType = 'success';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        $msg = 'Invalid security token.';
        $msgType = 'danger';
    } else {
        $allowed_keys = [
            'abuseipdb_api_key', 'ipapi_key', 'alert_email',
            'session_lifetime', 'max_login_attempts',
        ];
        foreach ($allowed_keys as $key) {
            if (isset($_POST[$key])) {
                $value = trim($_POST[$key]);
                $db->execute(
                    'INSERT INTO system_config (config_key, config_value) VALUES (?,?)
                     ON DUPLICATE KEY UPDATE config_value=VALUES(config_value)',
                    [$key, $value]
                );
            }
        }
        $msg = 'Settings saved successfully.';
    }
}

$config = [];
$rows = $db->fetchAll('SELECT config_key, config_value FROM system_config');
foreach ($rows as $row) {
    $config[$row['config_key']] = $row['config_value'];
}

function cfg(array $config, string $key, string $default = ''): string {
    return htmlspecialchars($config[$key] ?? $default, ENT_QUOTES, 'UTF-8');
}
?>
<div class="page-header">
  <h2>⚙️ Settings</h2>
  <p>System configuration</p>
</div>

<?php if ($msg): ?>
<div class="alert-bar <?= $msgType ?>"><?= htmlspecialchars($msg, ENT_QUOTES, 'UTF-8') ?></div>
<?php endif; ?>

<div style="max-width:680px;">
  <form method="POST" action="dashboard.php?panel=settings">
    <?= csrf_field() ?>

    <div class="card mb-3">
      <div class="card-header"><h3>API Keys</h3></div>
      <div class="form-group">
        <label>AbuseIPDB API Key</label>
        <div style="display:flex;gap:8px;">
          <input type="password" id="abuseipdb_key" name="abuseipdb_api_key"
                 value="<?= cfg($config, 'abuseipdb_api_key') ?>"
                 placeholder="Get free key at abuseipdb.com">
          <button type="button" class="btn btn-outline btn-sm" data-toggle-key="abuseipdb_key">👁</button>
        </div>
      </div>
      <div class="form-group">
        <label>ip-api.com Key (Pro, optional)</label>
        <div style="display:flex;gap:8px;">
          <input type="password" id="ipapi_key" name="ipapi_key"
                 value="<?= cfg($config, 'ipapi_key') ?>"
                 placeholder="Leave empty for free tier">
          <button type="button" class="btn btn-outline btn-sm" data-toggle-key="ipapi_key">👁</button>
        </div>
      </div>
    </div>

    <div class="card mb-3">
      <div class="card-header"><h3>Notifications</h3></div>
      <div class="form-group">
        <label>Alert Email</label>
        <input type="email" name="alert_email" value="<?= cfg($config, 'alert_email') ?>"
               placeholder="admin@yourdomain.com">
      </div>
    </div>

    <div class="card mb-3">
      <div class="card-header"><h3>Security</h3></div>
      <div class="form-group">
        <label>Session Lifetime (seconds)</label>
        <input type="number" name="session_lifetime" min="300" max="86400"
               value="<?= cfg($config, 'session_lifetime', '3600') ?>">
      </div>
      <div class="form-group">
        <label>Max Login Attempts (per minute per IP)</label>
        <input type="number" name="max_login_attempts" min="1" max="20"
               value="<?= cfg($config, 'max_login_attempts', '5') ?>">
      </div>
    </div>

    <button type="submit" class="btn btn-accent">💾 Save Settings</button>
  </form>
</div>
