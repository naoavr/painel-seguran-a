<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_auth();

$db = DB::getInstance();

$total_sites   = (int)($db->fetchOne('SELECT COUNT(*) AS c FROM sites WHERE is_active=1')['c'] ?? 0);
$blocked_ips   = (int)($db->fetchOne('SELECT COUNT(*) AS c FROM blocked_ips WHERE is_active=1')['c'] ?? 0);
$php_errors    = (int)($db->fetchOne("SELECT COUNT(*) AS c FROM error_log WHERE timestamp >= DATE_SUB(NOW(), INTERVAL 24 HOUR)")['c'] ?? 0);
$file_changes  = (int)($db->fetchOne("SELECT COUNT(*) AS c FROM file_change_log WHERE detected_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)")['c'] ?? 0);
$malware_count = (int)($db->fetchOne('SELECT COUNT(*) AS c FROM malware_scans WHERE is_resolved=0')['c'] ?? 0);
$unread_alerts = (int)($db->fetchOne('SELECT COUNT(*) AS c FROM alerts WHERE is_read=0')['c'] ?? 0);

$recent_alerts = $db->fetchAll(
    "SELECT a.*, s.domain FROM alerts a LEFT JOIN sites s ON s.id=a.site_id ORDER BY a.created_at DESC LIMIT 10"
);

$top_ips = $db->fetchAll(
    "SELECT ip, COUNT(*) AS hits, MAX(country) AS country, MAX(country_code) AS cc
     FROM traffic_log
     WHERE timestamp >= DATE_SUB(NOW(), INTERVAL 24 HOUR) AND ip IS NOT NULL
     GROUP BY ip ORDER BY hits DESC LIMIT 10"
);

$traffic_chart = $db->fetchAll(
    "SELECT DATE_FORMAT(hour,'%H:00') AS label, total_requests AS val
     FROM traffic_stats_hourly
     WHERE hour >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
     ORDER BY hour ASC"
);
$chart_labels = array_column($traffic_chart, 'label');
$chart_values = array_column($traffic_chart, 'val');
?>
<div class="page-header">
  <h2>📊 Dashboard</h2>
  <p>Security monitoring overview</p>
</div>

<div class="card-grid">
  <div class="stat-card">
    <div class="stat-icon">🌐</div>
    <div class="stat-value accent" data-stat="total_sites"><?= $total_sites ?></div>
    <div class="stat-label">Active Sites</div>
  </div>
  <div class="stat-card">
    <div class="stat-icon">🚫</div>
    <div class="stat-value warn" data-stat="blocked_ips"><?= $blocked_ips ?></div>
    <div class="stat-label">Blocked IPs</div>
  </div>
  <div class="stat-card">
    <div class="stat-icon">⚠️</div>
    <div class="stat-value <?= $php_errors > 0 ? 'danger' : 'accent' ?>" data-stat="php_errors"><?= $php_errors ?></div>
    <div class="stat-label">PHP Errors (24h)</div>
  </div>
  <div class="stat-card">
    <div class="stat-icon">📁</div>
    <div class="stat-value <?= $file_changes > 0 ? 'warn' : 'accent' ?>" data-stat="file_changes"><?= $file_changes ?></div>
    <div class="stat-label">File Changes (24h)</div>
  </div>
  <div class="stat-card">
    <div class="stat-icon">🦠</div>
    <div class="stat-value <?= $malware_count > 0 ? 'danger' : 'accent' ?>" data-stat="malware_count"><?= $malware_count ?></div>
    <div class="stat-label">Active Malware</div>
  </div>
  <div class="stat-card">
    <div class="stat-icon">🔔</div>
    <div class="stat-value <?= $unread_alerts > 0 ? 'warn' : 'accent' ?>" data-stat="unread_alerts"><?= $unread_alerts ?></div>
    <div class="stat-label">Unread Alerts</div>
  </div>
</div>

<div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:24px;">
  <div class="card">
    <div class="card-header">
      <h3>📈 Traffic (Last 24h)</h3>
    </div>
    <div class="canvas-wrap" style="height:200px;">
      <canvas id="trafficChart" width="600" height="200"></canvas>
    </div>
  </div>
  <div class="card">
    <div class="card-header">
      <h3>🔥 Top Attacking IPs</h3>
    </div>
    <div class="table-wrap">
      <table>
        <thead><tr><th>IP Address</th><th>Country</th><th>Requests</th></tr></thead>
        <tbody>
          <?php foreach ($top_ips as $row): ?>
          <tr>
            <td class="mono"><?= htmlspecialchars($row['ip'], ENT_QUOTES, 'UTF-8') ?></td>
            <td><?= get_flag_emoji($row['cc'] ?? '') ?> <?= htmlspecialchars($row['country'] ?? 'Unknown', ENT_QUOTES, 'UTF-8') ?></td>
            <td><span class="pill pill-danger"><?= (int)$row['hits'] ?></span></td>
          </tr>
          <?php endforeach; ?>
          <?php if (empty($top_ips)): ?>
          <tr><td colspan="3" class="text-muted text-center">No traffic data</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<div class="card">
  <div class="card-header">
    <h3>🔔 Recent Alerts</h3>
    <a href="dashboard.php?panel=ips" class="btn btn-outline btn-sm">View All</a>
  </div>
  <div class="table-wrap">
    <table>
      <thead>
        <tr><th>Severity</th><th>Type</th><th>Site</th><th>Message</th><th>Time</th></tr>
      </thead>
      <tbody>
        <?php foreach ($recent_alerts as $alert): ?>
        <tr>
          <td>
            <?php
            $sev = $alert['severity'];
            $cls = $sev === 'critical' ? 'danger' : ($sev === 'warning' ? 'warn' : 'info');
            ?>
            <span class="pill pill-<?= $cls ?>"><?= htmlspecialchars($sev, ENT_QUOTES, 'UTF-8') ?></span>
          </td>
          <td><?= htmlspecialchars($alert['type'], ENT_QUOTES, 'UTF-8') ?></td>
          <td class="text-muted"><?= htmlspecialchars($alert['domain'] ?? 'System', ENT_QUOTES, 'UTF-8') ?></td>
          <td><?= htmlspecialchars(truncate($alert['message'] ?? '', 60), ENT_QUOTES, 'UTF-8') ?></td>
          <td class="text-muted"><?= time_ago($alert['created_at']) ?></td>
        </tr>
        <?php endforeach; ?>
        <?php if (empty($recent_alerts)): ?>
        <tr><td colspan="5" class="text-muted text-center" style="padding:20px;">No alerts yet</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<script>
(function(){
  var labels = <?= json_encode($chart_labels) ?>;
  var vals   = <?= json_encode(array_map('intval', $chart_values)) ?>;
  var canvas = document.getElementById('trafficChart');
  if(canvas){ canvas.width = canvas.parentElement.offsetWidth || 600; }
  if(typeof drawBarChart === 'function'){
    drawBarChart('trafficChart', labels, vals, { color:'#00c896' });
  }
})();
</script>
