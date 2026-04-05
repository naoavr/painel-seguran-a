<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/csrf.php';
require_auth();

$db = DB::getInstance();
$msg = '';
$msgType = 'success';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        $msg = 'Invalid security token.';
        $msgType = 'danger';
    } elseif (isset($_POST['action']) && $_POST['action'] === 'resolve_malware') {
        $id = (int)($_POST['malware_id'] ?? 0);
        if ($id > 0) {
            $db->execute('UPDATE malware_scans SET is_resolved=1 WHERE id=?', [$id]);
            $msg = 'Malware scan marked as resolved.';
        }
    }
}

$sites = $db->fetchAll('SELECT id, domain, name FROM sites WHERE is_active=1 ORDER BY name');
$site_filter = isset($_GET['site_id']) && (int)$_GET['site_id'] > 0 ? (int)$_GET['site_id'] : null;

$fcWhere  = 'WHERE 1=1';
$fcParams = [];
if ($site_filter) { $fcWhere .= ' AND f.site_id=?'; $fcParams[] = $site_filter; }

$file_changes = $db->fetchAll(
    "SELECT f.*, s.domain FROM file_change_log f
     LEFT JOIN sites s ON s.id=f.site_id
     $fcWhere ORDER BY f.detected_at DESC LIMIT 100",
    $fcParams
);

$msWhere  = 'WHERE m.is_resolved=0';
$msParams = [];
if ($site_filter) { $msWhere .= ' AND m.site_id=?'; $msParams[] = $site_filter; }

$malware = $db->fetchAll(
    "SELECT m.*, s.domain FROM malware_scans m
     LEFT JOIN sites s ON s.id=m.site_id
     $msWhere ORDER BY m.detected_at DESC LIMIT 100",
    $msParams
);
?>
<div class="page-header flex-between">
  <div>
    <h2>📁 File Monitor</h2>
    <p>Track file changes and malware detections</p>
  </div>
  <form method="GET" action="dashboard.php" style="display:flex;gap:8px;align-items:center;">
    <input type="hidden" name="panel" value="files">
    <select name="site_id" onchange="this.form.submit()">
      <option value="">All Sites</option>
      <?php foreach ($sites as $s): ?>
        <option value="<?= (int)$s['id'] ?>" <?= $site_filter === (int)$s['id'] ? 'selected' : '' ?>>
          <?= htmlspecialchars($s['name'] ?: $s['domain'], ENT_QUOTES, 'UTF-8') ?>
        </option>
      <?php endforeach; ?>
    </select>
  </form>
</div>

<?php if ($msg): ?>
<div class="alert-bar <?= $msgType ?>"><?= htmlspecialchars($msg, ENT_QUOTES, 'UTF-8') ?></div>
<?php endif; ?>

<?php if (!empty($malware)): ?>
<div class="card mb-3">
  <div class="card-header">
    <h3>🦠 Active Malware Detections (<?= count($malware) ?>)</h3>
  </div>
  <div class="table-wrap">
    <table>
      <thead>
        <tr><th>Site</th><th>File</th><th>Threat</th><th>Pattern</th><th>Detected</th><th>Action</th></tr>
      </thead>
      <tbody>
        <?php foreach ($malware as $m): ?>
        <tr>
          <td class="text-muted"><?= htmlspecialchars($m['domain'] ?? '', ENT_QUOTES, 'UTF-8') ?></td>
          <td class="mono" style="max-width:200px;overflow:hidden;text-overflow:ellipsis;" title="<?= htmlspecialchars($m['file_path'], ENT_QUOTES, 'UTF-8') ?>">
            <?= htmlspecialchars(basename($m['file_path'] ?? ''), ENT_QUOTES, 'UTF-8') ?>
          </td>
          <td><span class="pill pill-danger"><?= htmlspecialchars($m['threat_name'] ?? '', ENT_QUOTES, 'UTF-8') ?></span></td>
          <td class="mono text-muted" style="max-width:150px;overflow:hidden;text-overflow:ellipsis;">
            <?= htmlspecialchars(truncate($m['pattern_matched'] ?? '', 40), ENT_QUOTES, 'UTF-8') ?>
          </td>
          <td class="text-muted"><?= time_ago($m['detected_at']) ?></td>
          <td>
            <form method="POST" action="dashboard.php?panel=files<?= $site_filter ? '&site_id='.$site_filter : '' ?>" style="display:inline;">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="resolve_malware">
              <input type="hidden" name="malware_id" value="<?= (int)$m['id'] ?>">
              <button type="submit" class="btn btn-outline btn-xs"
                      onclick="return confirm('Mark as resolved?')">✓ Resolve</button>
            </form>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<div class="card">
  <div class="card-header">
    <h3>📝 File Change Log</h3>
    <span class="text-muted" style="font-size:0.8rem;"><?= count($file_changes) ?> recent changes</span>
  </div>
  <div class="table-wrap">
    <table>
      <thead>
        <tr><th>Site</th><th>File</th><th>Change</th><th>Old Hash</th><th>New Hash</th><th>Size Δ</th><th>Detected</th></tr>
      </thead>
      <tbody>
        <?php foreach ($file_changes as $fc): ?>
        <?php
        $typeCls = $fc['change_type'] === 'added' ? 'ok' : ($fc['change_type'] === 'deleted' ? 'danger' : 'warn');
        $sizeDiff = ((int)$fc['new_size'] - (int)$fc['old_size']);
        ?>
        <tr>
          <td class="text-muted"><?= htmlspecialchars($fc['domain'] ?? '', ENT_QUOTES, 'UTF-8') ?></td>
          <td class="mono" style="max-width:250px;overflow:hidden;text-overflow:ellipsis;" title="<?= htmlspecialchars($fc['file_path'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
            <?= htmlspecialchars(truncate($fc['file_path'] ?? '', 50), ENT_QUOTES, 'UTF-8') ?>
          </td>
          <td><span class="pill pill-<?= $typeCls ?>"><?= htmlspecialchars($fc['change_type'] ?? '', ENT_QUOTES, 'UTF-8') ?></span></td>
          <td class="mono text-muted"><?= htmlspecialchars(substr($fc['old_hash'] ?? '', 0, 12), ENT_QUOTES, 'UTF-8') ?></td>
          <td class="mono text-muted"><?= htmlspecialchars(substr($fc['new_hash'] ?? '', 0, 12), ENT_QUOTES, 'UTF-8') ?></td>
          <td class="mono <?= $sizeDiff > 0 ? 'text-warn' : ($sizeDiff < 0 ? 'text-danger' : '') ?>">
            <?= $sizeDiff >= 0 ? '+' : '' ?><?= number_format($sizeDiff) ?> B
          </td>
          <td class="text-muted"><?= time_ago($fc['detected_at']) ?></td>
        </tr>
        <?php endforeach; ?>
        <?php if (empty($file_changes)): ?>
        <tr><td colspan="7" class="text-muted text-center" style="padding:20px;">No file changes recorded</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>
