<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_auth();

$db    = DB::getInstance();
$sites = $db->fetchAll('SELECT * FROM sites ORDER BY name ASC');
$now   = time();
?>
<div class="page-header flex-between">
  <div>
    <h2>🌐 Sites Status</h2>
    <p>Real-time status of all monitored sites</p>
  </div>
  <a href="dashboard.php?panel=sites_manage" class="btn btn-accent btn-sm">+ Manage Sites</a>
</div>

<?php if (empty($sites)): ?>
<div class="card text-center" style="padding:48px;">
  <div style="font-size:3rem;margin-bottom:16px;">🌐</div>
  <p class="text-muted">No sites configured yet.</p>
  <a href="dashboard.php?panel=sites_manage" class="btn btn-accent mt-2">Add Your First Site</a>
</div>
<?php else: ?>
<div class="sites-grid">
  <?php foreach ($sites as $site): ?>
  <?php
  $status = $site['status'];
  $statusCls = $status === 'online' ? 'ok' : ($status === 'offline' ? 'danger' : 'muted');
  $sslDays = 0;
  if (!empty($site['ssl_expiry'])) {
      $sslDays = (int)(( strtotime($site['ssl_expiry']) - $now ) / 86400);
  }
  $sslCls = $site['ssl_valid'] ? ($sslDays < 14 ? 'warn' : 'ok') : 'danger';
  ?>
  <div class="site-card">
    <div class="site-domain">
      <span class="pill pill-<?= $statusCls ?>"><?= htmlspecialchars($status, ENT_QUOTES, 'UTF-8') ?></span>
      <?= htmlspecialchars($site['domain'], ENT_QUOTES, 'UTF-8') ?>
    </div>
    <?php if (!empty($site['name'])): ?>
    <div class="text-muted" style="font-size:0.8rem;margin-bottom:8px;">
      <?= htmlspecialchars($site['name'], ENT_QUOTES, 'UTF-8') ?>
    </div>
    <?php endif; ?>
    <div class="site-meta">
      <span class="text-muted">SSL:</span>
      <span>
        <span class="pill pill-<?= $sslCls ?>">
          <?php if ($site['ssl_valid']): ?>
            ✓ <?= htmlspecialchars($site['ssl_expiry'] ?? '', ENT_QUOTES, 'UTF-8') ?>
            (<?= $sslDays ?> days)
          <?php else: ?>
            ✗ Invalid
          <?php endif; ?>
        </span>
      </span>

      <span class="text-muted">HTTP:</span>
      <span>
        <?php if ($site['http_status']): ?>
          <?php
          $code = (int)$site['http_status'];
          $httpCls = $code >= 500 ? 'danger' : ($code >= 400 ? 'warn' : ($code >= 200 ? 'ok' : 'muted'));
          ?>
          <span class="pill pill-<?= $httpCls ?>"><?= $code ?></span>
        <?php else: ?>
          <span class="text-muted">—</span>
        <?php endif; ?>
      </span>

      <span class="text-muted">Last Seen:</span>
      <span><?= $site['last_seen'] ? time_ago($site['last_seen']) : '<span class="text-muted">Never</span>' ?></span>

      <span class="text-muted">Visits Today:</span>
      <span><?= number_format((int)$site['visits_today']) ?></span>

      <span class="text-muted">Visits Total:</span>
      <span><?= number_format((int)$site['visits_total']) ?></span>
    </div>
  </div>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<script>
setTimeout(function(){ window.location.reload(); }, 60000);
</script>
