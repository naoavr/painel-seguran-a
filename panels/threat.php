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
        if ($action === 'add_feed') {
            $name     = trim($_POST['feed_name'] ?? '');
            $url      = trim($_POST['feed_url']  ?? '');
            $type     = $_POST['feed_type']       ?? '';
            $interval = (int)($_POST['update_interval'] ?? 3600);
            $allowed_types = ['ip_blocklist', 'domain_blocklist', 'malware_hashes'];
            if ($name && $url && in_array($type, $allowed_types, true)) {
                $db->execute(
                    'INSERT INTO threat_config (feed_name, feed_url, feed_type, is_enabled, update_interval) VALUES (?,?,?,1,?)',
                    [$name, $url, $type, $interval]
                );
                $msg = 'Feed added successfully.';
            } else {
                $msg = 'Invalid feed data.';
                $msgType = 'danger';
            }
        } elseif ($action === 'toggle_feed') {
            $id      = (int)($_POST['feed_id'] ?? 0);
            $enabled = (int)($_POST['enabled'] ?? 0);
            if ($id > 0) {
                $db->execute('UPDATE threat_config SET is_enabled=? WHERE id=?', [$enabled ? 0 : 1, $id]);
                $msg = 'Feed updated.';
            }
        } elseif ($action === 'delete_feed') {
            $id = (int)($_POST['feed_id'] ?? 0);
            if ($id > 0) {
                $db->execute('DELETE FROM threat_config WHERE id=?', [$id]);
                $msg = 'Feed deleted.';
            }
        }
    }
}

$feeds = $db->fetchAll('SELECT * FROM threat_config ORDER BY feed_name ASC');
?>
<div class="page-header">
  <h2>🌐 Threat Intelligence</h2>
  <p>Manage threat feed sources</p>
</div>

<?php if ($msg): ?>
<div class="alert-bar <?= $msgType ?>"><?= htmlspecialchars($msg, ENT_QUOTES, 'UTF-8') ?></div>
<?php endif; ?>

<div style="display:grid;grid-template-columns:1fr 340px;gap:16px;align-items:start;">
  <div class="card">
    <div class="card-header"><h3>Active Feeds</h3></div>
    <div class="table-wrap">
      <table>
        <thead>
          <tr><th>Name</th><th>Type</th><th>Status</th><th>Last Updated</th><th>Actions</th></tr>
        </thead>
        <tbody>
          <?php foreach ($feeds as $feed): ?>
          <tr>
            <td>
              <div style="font-weight:600;"><?= htmlspecialchars($feed['feed_name'], ENT_QUOTES, 'UTF-8') ?></div>
              <div class="mono text-muted" style="font-size:0.72rem;"><?= htmlspecialchars(truncate($feed['feed_url'], 50), ENT_QUOTES, 'UTF-8') ?></div>
            </td>
            <td>
              <?php
              $typeCls = $feed['feed_type'] === 'ip_blocklist' ? 'danger' : ($feed['feed_type'] === 'domain_blocklist' ? 'warn' : 'info');
              ?>
              <span class="pill pill-<?= $typeCls ?>"><?= htmlspecialchars($feed['feed_type'], ENT_QUOTES, 'UTF-8') ?></span>
            </td>
            <td>
              <form method="POST" action="dashboard.php?panel=threat" style="display:inline;">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="toggle_feed">
                <input type="hidden" name="feed_id" value="<?= (int)$feed['id'] ?>">
                <input type="hidden" name="enabled" value="<?= (int)$feed['is_enabled'] ?>">
                <button type="submit" class="btn btn-outline btn-xs">
                  <?= $feed['is_enabled'] ? '✅ Enabled' : '⛔ Disabled' ?>
                </button>
              </form>
            </td>
            <td class="text-muted">
              <?= $feed['last_updated'] ? time_ago($feed['last_updated']) : 'Never' ?>
            </td>
            <td>
              <form method="POST" action="dashboard.php?panel=threat" style="display:inline;">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="delete_feed">
                <input type="hidden" name="feed_id" value="<?= (int)$feed['id'] ?>">
                <button type="submit" class="btn btn-danger btn-xs"
                        onclick="return confirm('Delete this feed?')">🗑</button>
              </form>
            </td>
          </tr>
          <?php endforeach; ?>
          <?php if (empty($feeds)): ?>
          <tr><td colspan="5" class="text-muted text-center" style="padding:20px;">No feeds configured</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>

  <div class="card">
    <div class="card-header"><h3>Add New Feed</h3></div>
    <form method="POST" action="dashboard.php?panel=threat">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="add_feed">
      <div class="form-group">
        <label>Feed Name *</label>
        <input type="text" name="feed_name" placeholder="e.g. Emerging Threats" required>
      </div>
      <div class="form-group">
        <label>Feed URL *</label>
        <input type="url" name="feed_url" placeholder="https://..." required>
      </div>
      <div class="form-group">
        <label>Type *</label>
        <select name="feed_type" required>
          <option value="ip_blocklist">IP Blocklist</option>
          <option value="domain_blocklist">Domain Blocklist</option>
          <option value="malware_hashes">Malware Hashes</option>
        </select>
      </div>
      <div class="form-group">
        <label>Update Interval (seconds)</label>
        <input type="number" name="update_interval" value="3600" min="300">
      </div>
      <button type="submit" class="btn btn-accent w-100">+ Add Feed</button>
    </form>
  </div>
</div>
