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
        if ($action === 'cleanup_traffic') {
            $days = max(1, (int)($_POST['days'] ?? 30));
            $db->execute("DELETE FROM traffic_log WHERE timestamp < DATE_SUB(NOW(), INTERVAL ? DAY)", [$days]);
            $msg = 'Traffic log cleaned up (older than ' . $days . ' days).';
        } elseif ($action === 'cleanup_errors') {
            $days = max(1, (int)($_POST['days'] ?? 30));
            $db->execute("DELETE FROM error_log WHERE timestamp < DATE_SUB(NOW(), INTERVAL ? DAY)", [$days]);
            $msg = 'Error log cleaned up (older than ' . $days . ' days).';
        }
    }
}

$tables_to_check = [
    'dashboard_users', 'dashboard_sessions', 'sites', 'traffic_log',
    'error_log', 'ip_reputation', 'blocked_ips', 'file_change_log',
    'monitored_files', 'malware_scans', 'alerts', 'ip_alerts',
    'threat_config', 'system_config', 'traffic_stats_hourly',
];

$table_stats = [];
foreach ($tables_to_check as $tbl) {
    try {
        $stat = $db->fetchOne('SHOW TABLE STATUS LIKE ?', [$tbl]);
        if ($stat) {
            $table_stats[] = $stat;
        }
    } catch (Exception $e) {
        $table_stats[] = ['Name' => $tbl, 'Rows' => 'N/A', 'Data_length' => 0, 'Index_length' => 0];
    }
}

$traffic_count = (int)($db->fetchOne('SELECT COUNT(*) AS c FROM traffic_log')['c'] ?? 0);
$error_count   = (int)($db->fetchOne('SELECT COUNT(*) AS c FROM error_log')['c'] ?? 0);
?>
<div class="page-header">
  <h2>🗄 Database</h2>
  <p>Table status and maintenance</p>
</div>

<?php if ($msg): ?>
<div class="alert-bar <?= $msgType ?>"><?= htmlspecialchars($msg, ENT_QUOTES, 'UTF-8') ?></div>
<?php endif; ?>

<div class="card mb-3">
  <div class="card-header"><h3>Table Status</h3></div>
  <div class="table-wrap">
    <table>
      <thead>
        <tr><th>Table</th><th>Rows</th><th>Data Size</th><th>Index Size</th><th>Total Size</th></tr>
      </thead>
      <tbody>
        <?php foreach ($table_stats as $stat): ?>
        <?php
        $dataLen  = isset($stat['Data_length'])  ? (int)$stat['Data_length']  : 0;
        $idxLen   = isset($stat['Index_length']) ? (int)$stat['Index_length'] : 0;
        $total    = $dataLen + $idxLen;
        $rows_est = $stat['Rows'] ?? 'N/A';
        ?>
        <tr>
          <td class="mono"><?= htmlspecialchars($stat['Name'] ?? '', ENT_QUOTES, 'UTF-8') ?></td>
          <td><?= is_numeric($rows_est) ? number_format((int)$rows_est) : htmlspecialchars((string)$rows_est, ENT_QUOTES, 'UTF-8') ?></td>
          <td><?= format_bytes($dataLen) ?></td>
          <td><?= format_bytes($idxLen) ?></td>
          <td><?= format_bytes($total) ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:16px;">
  <div class="card">
    <div class="card-header"><h3>🧹 Cleanup Traffic Log</h3></div>
    <p class="text-muted mb-2" style="font-size:0.875rem;">
      Current records: <strong><?= number_format($traffic_count) ?></strong>
    </p>
    <form method="POST" action="dashboard.php?panel=database">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="cleanup_traffic">
      <div class="form-group">
        <label>Delete records older than (days)</label>
        <input type="number" name="days" value="30" min="1" max="365">
      </div>
      <button type="submit" class="btn btn-danger btn-sm"
              onclick="return confirm('This will permanently delete old traffic records. Continue?')">
        🗑 Clean Traffic Log
      </button>
    </form>
  </div>

  <div class="card">
    <div class="card-header"><h3>🧹 Cleanup Error Log</h3></div>
    <p class="text-muted mb-2" style="font-size:0.875rem;">
      Current records: <strong><?= number_format($error_count) ?></strong>
    </p>
    <form method="POST" action="dashboard.php?panel=database">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="cleanup_errors">
      <div class="form-group">
        <label>Delete records older than (days)</label>
        <input type="number" name="days" value="30" min="1" max="365">
      </div>
      <button type="submit" class="btn btn-danger btn-sm"
              onclick="return confirm('This will permanently delete old error records. Continue?')">
        🗑 Clean Error Log
      </button>
    </form>
  </div>
</div>

<div class="card">
  <div class="card-header"><h3>💾 Backup Instructions</h3></div>
  <p class="text-muted mb-2" style="font-size:0.875rem;">
    To backup the Monitor Central database, run the following command on your server:
  </p>
  <pre>mysqldump -u YOUR_DB_USER -p <?= htmlspecialchars(defined('DB_NAME') ? DB_NAME : 'iddigital_monitor', ENT_QUOTES, 'UTF-8') ?> > backup_$(date +%Y%m%d_%H%M%S).sql</pre>
  <p class="text-muted mt-2" style="font-size:0.875rem;">
    To restore: <code>mysql -u YOUR_DB_USER -p <?= htmlspecialchars(defined('DB_NAME') ? DB_NAME : 'iddigital_monitor', ENT_QUOTES, 'UTF-8') ?> &lt; backup_file.sql</code>
  </p>
</div>
