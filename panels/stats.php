<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_auth();

$db = DB::getInstance();

$site_id   = (int)($_GET['site_id'] ?? 0);
$date_from = $_GET['date_from'] ?? date('Y-m-d', strtotime('-7 days'));
$date_to   = $_GET['date_to']   ?? date('Y-m-d');
$sites     = $db->fetchAll('SELECT id, name, domain FROM sites WHERE is_active=1 ORDER BY name');

$where  = 'WHERE hour >= ? AND hour <= ?';
$params = [$date_from . ' 00:00:00', $date_to . ' 23:59:59'];
if ($site_id > 0) {
    $where  .= ' AND site_id = ?';
    $params[] = $site_id;
}

$hourly = $db->fetchAll(
    "SELECT DATE_FORMAT(hour,'%Y-%m-%d %H:00') AS label,
            SUM(total_requests) AS reqs, SUM(unique_ips) AS uniq,
            SUM(error_count) AS errs, SUM(blocked_count) AS blocked
     FROM traffic_stats_hourly $where
     GROUP BY hour ORDER BY hour ASC LIMIT 168",
    $params
);

$daily = $db->fetchAll(
    "SELECT DATE(hour) AS day,
            SUM(total_requests) AS reqs, SUM(unique_ips) AS uniq,
            SUM(error_count) AS errs, SUM(blocked_count) AS blocked
     FROM traffic_stats_hourly $where
     GROUP BY DATE(hour) ORDER BY day DESC",
    $params
);

$chart_labels = array_column($hourly, 'label');
$chart_reqs   = array_map('intval', array_column($hourly, 'reqs'));
$chart_errs   = array_map('intval', array_column($hourly, 'errs'));

$csv_link = 'api/ajax.php?action=export_stats&site_id=' . $site_id
    . '&date_from=' . urlencode($date_from)
    . '&date_to='   . urlencode($date_to);
?>
<div class="page-header flex-between">
  <div>
    <h2>📈 Traffic Statistics</h2>
    <p>Hourly and daily traffic breakdown</p>
  </div>
  <a href="<?= htmlspecialchars($csv_link, ENT_QUOTES, 'UTF-8') ?>" class="btn btn-outline btn-sm">⬇ Export CSV</a>
</div>

<div class="card mb-3">
  <form method="GET" action="dashboard.php" class="form-row" style="align-items:flex-end;">
    <input type="hidden" name="panel" value="stats">
    <div class="form-group mb-0">
      <label>Site</label>
      <select name="site_id">
        <option value="0">All Sites</option>
        <?php foreach ($sites as $s): ?>
          <option value="<?= (int)$s['id'] ?>" <?= $site_id === (int)$s['id'] ? 'selected' : '' ?>>
            <?= htmlspecialchars($s['name'] ?: $s['domain'], ENT_QUOTES, 'UTF-8') ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="form-group mb-0">
      <label>Date From</label>
      <input type="date" name="date_from" value="<?= htmlspecialchars($date_from, ENT_QUOTES, 'UTF-8') ?>">
    </div>
    <div class="form-group mb-0">
      <label>Date To</label>
      <input type="date" name="date_to" value="<?= htmlspecialchars($date_to, ENT_QUOTES, 'UTF-8') ?>">
    </div>
    <button type="submit" class="btn btn-accent">Apply</button>
  </form>
</div>

<div class="card mb-3">
  <div class="card-header"><h3>Hourly Traffic</h3></div>
  <div class="canvas-wrap" style="height:220px;">
    <canvas id="statsChart" height="220"></canvas>
  </div>
</div>

<div class="card">
  <div class="card-header"><h3>Daily Summary</h3></div>
  <div class="table-wrap">
    <table>
      <thead>
        <tr><th>Date</th><th>Requests</th><th>Unique IPs</th><th>Errors</th><th>Blocked</th></tr>
      </thead>
      <tbody>
        <?php foreach ($daily as $row): ?>
        <tr>
          <td class="mono"><?= htmlspecialchars($row['day'], ENT_QUOTES, 'UTF-8') ?></td>
          <td><?= number_format((int)$row['reqs']) ?></td>
          <td><?= number_format((int)$row['uniq']) ?></td>
          <td><?= $row['errs'] > 0 ? '<span class="pill pill-danger">' . (int)$row['errs'] . '</span>' : '0' ?></td>
          <td><?= $row['blocked'] > 0 ? '<span class="pill pill-warn">' . (int)$row['blocked'] . '</span>' : '0' ?></td>
        </tr>
        <?php endforeach; ?>
        <?php if (empty($daily)): ?>
        <tr><td colspan="5" class="text-muted text-center" style="padding:20px;">No data for selected range</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<script>
(function(){
  var labels = <?= json_encode(array_map(fn($l) => substr($l, 5, 8), $chart_labels)) ?>;
  var reqs   = <?= json_encode($chart_reqs) ?>;
  var errs   = <?= json_encode($chart_errs) ?>;
  var canvas = document.getElementById('statsChart');
  if(canvas){ canvas.width = canvas.parentElement.offsetWidth || 800; }
  if(typeof drawLineChart === 'function'){
    drawLineChart('statsChart', labels, [
      { data: reqs, color: '#00c896', label: 'Requests' },
      { data: errs, color: '#ff2d55', label: 'Errors' }
    ]);
  }
})();
</script>
