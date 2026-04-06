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
        $action = $_POST['action'] ?? '';
        if ($action === 'add_site') {
            $domain = trim($_POST['domain'] ?? '');
            $name   = trim($_POST['name']   ?? '');
            if (!empty($domain)) {
                $apiKey = generate_api_key();
                $db->execute(
                    'INSERT INTO sites (domain, api_key, name, status) VALUES (?,?,?,?)',
                    [$domain, $apiKey, $name, 'unknown']
                );
                $msg = 'Site added. API Key: ' . $apiKey;
            } else {
                $msg = 'Domain is required.';
                $msgType = 'danger';
            }
        } elseif ($action === 'delete_site') {
            $id = (int)($_POST['site_id'] ?? 0);
            if ($id > 0) {
                $db->execute('UPDATE sites SET is_active=0 WHERE id=?', [$id]);
                $msg = 'Site removed.';
            }
        }
    }
}

$sites = $db->fetchAll('SELECT * FROM sites WHERE is_active=1 ORDER BY name ASC');
?>
<div class="page-header">
  <h2>⚙️ Site Management</h2>
  <p>Manage monitored sites and API keys</p>
</div>

<?php if ($msg): ?>
<div class="alert-bar <?= $msgType ?>"><?= htmlspecialchars($msg, ENT_QUOTES, 'UTF-8') ?></div>
<?php endif; ?>

<div style="display:grid;grid-template-columns:1fr 340px;gap:16px;align-items:start;">
  <div class="card">
    <div class="card-header">
      <h3>Registered Sites</h3>
      <span class="text-muted"><?= count($sites) ?> sites</span>
    </div>
    <div class="table-wrap">
      <table>
        <thead>
          <tr><th>Domain</th><th>Name</th><th>Status</th><th>API Key</th><th>Created</th><th>Actions</th></tr>
        </thead>
        <tbody>
          <?php foreach ($sites as $site): ?>
          <tr>
            <td style="font-weight:600;"><?= htmlspecialchars($site['domain'], ENT_QUOTES, 'UTF-8') ?></td>
            <td class="text-muted"><?= htmlspecialchars($site['name'] ?? '', ENT_QUOTES, 'UTF-8') ?></td>
            <td>
              <?php $stCls = $site['status'] === 'online' ? 'ok' : ($site['status'] === 'offline' ? 'danger' : 'muted'); ?>
              <span class="pill pill-<?= $stCls ?>"><?= htmlspecialchars($site['status'], ENT_QUOTES, 'UTF-8') ?></span>
            </td>
            <td>
              <div style="display:flex;align-items:center;gap:4px;">
                <input type="password" id="key_<?= (int)$site['id'] ?>"
                       value="<?= htmlspecialchars($site['api_key'], ENT_QUOTES, 'UTF-8') ?>"
                       readonly style="width:120px;font-family:IBM Plex Mono,monospace;font-size:0.72rem;padding:3px 6px;">
                <button class="btn btn-outline btn-xs" data-toggle-key="key_<?= (int)$site['id'] ?>" title="Show/Hide">👁</button>
                <button class="btn btn-outline btn-xs" data-copy="key_<?= (int)$site['id'] ?>" title="Copy">📋</button>
              </div>
            </td>
            <td class="text-muted"><?= htmlspecialchars(substr($site['created_at'], 0, 10), ENT_QUOTES, 'UTF-8') ?></td>
            <td>
              <form method="POST" action="dashboard.php?panel=sites_manage" style="display:inline;">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="delete_site">
                <input type="hidden" name="site_id" value="<?= (int)$site['id'] ?>">
                <button type="submit" class="btn btn-danger btn-xs"
                        onclick="return confirm('Remove this site? This will deactivate it but preserve data.')">🗑</button>
              </form>
            </td>
          </tr>
          <?php endforeach; ?>
          <?php if (empty($sites)): ?>
          <tr><td colspan="6" class="text-muted text-center" style="padding:20px;">No sites yet</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>

  <div class="card">
    <div class="card-header"><h3>Add New Site</h3></div>
    <form method="POST" action="dashboard.php?panel=sites_manage">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="add_site">
      <div class="form-group">
        <label>Domain *</label>
        <input type="text" name="domain" placeholder="e.g. example.com" required>
      </div>
      <div class="form-group">
        <label>Display Name</label>
        <input type="text" name="name" placeholder="e.g. My Store">
      </div>
      <button type="submit" class="btn btn-accent w-100">+ Add Site</button>
    </form>
    <div class="mt-3" style="background:var(--surface2);border-radius:6px;padding:12px;font-size:0.8rem;color:var(--text-dim);">
      <strong style="color:var(--text);">Integration:</strong><br>
      Add the following to your site's PHP before any output:<br>
      <pre style="margin-top:8px;font-size:0.72rem;overflow-x:auto;">define('MC_API_URL', '<?= htmlspecialchars(defined('APP_URL') ? APP_URL . '/ingest.php' : 'https://your-monitor.com/ingest.php', ENT_QUOTES, 'UTF-8') ?>');
define('MC_API_KEY', 'YOUR_API_KEY');
require_once 'agent.php';</pre>
    </div>
  </div>
</div>
