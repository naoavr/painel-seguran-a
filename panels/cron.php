<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_auth();

$db = DB::getInstance();
$config = [];
$rows = $db->fetchAll("SELECT config_key, config_value FROM system_config WHERE config_key LIKE 'cron_%'");
foreach ($rows as $r) {
    $config[$r['config_key']] = $r['config_value'];
}

$cron_tasks = [
    ['key' => 'cron_last_run_threat_feeds', 'name' => 'Update Threat Feeds',    'action' => 'cron_threat_feeds',  'schedule' => '0 * * * *',    'desc' => 'Download and update IP/domain blocklists from configured feeds'],
    ['key' => 'cron_last_run_ssl_check',    'name' => 'Check SSL Certificates', 'action' => 'cron_ssl_check',     'schedule' => '0 6 * * *',    'desc' => 'Verify SSL certificate validity and expiry for all sites'],
    ['key' => 'cron_last_run_cleanup',      'name' => 'Database Cleanup',       'action' => 'cron_cleanup',       'schedule' => '0 2 * * *',    'desc' => 'Remove old traffic logs and error logs (>30 days)'],
];

$app_path = defined('APP_URL') ? APP_URL : 'https://your-domain.com/monitor';
?>
<div class="page-header">
  <h2>⏰ Cron Jobs</h2>
  <p>Scheduled task management</p>
</div>

<div class="card mb-3">
  <div class="card-header"><h3>Scheduled Tasks</h3></div>
  <div class="table-wrap">
    <table>
      <thead>
        <tr><th>Task</th><th>Description</th><th>Schedule</th><th>Last Run</th><th>Run Now</th></tr>
      </thead>
      <tbody>
        <?php foreach ($cron_tasks as $task): ?>
        <tr>
          <td style="font-weight:600;"><?= htmlspecialchars($task['name'], ENT_QUOTES, 'UTF-8') ?></td>
          <td class="text-muted"><?= htmlspecialchars($task['desc'], ENT_QUOTES, 'UTF-8') ?></td>
          <td><code><?= htmlspecialchars($task['schedule'], ENT_QUOTES, 'UTF-8') ?></code></td>
          <td class="text-muted">
            <?= !empty($config[$task['key']]) ? time_ago($config[$task['key']]) : 'Never' ?>
          </td>
          <td>
            <button class="btn btn-outline btn-sm run-cron-btn"
                    data-action="<?= htmlspecialchars($task['action'], ENT_QUOTES, 'UTF-8') ?>">
              ▶ Run
            </button>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<div class="card mb-3">
  <div class="card-header"><h3>📋 Crontab Setup</h3></div>
  <p class="text-muted mb-2" style="font-size:0.875rem;">Add these lines to your server's crontab (<code>crontab -e</code>):</p>
  <pre># Monitor Central Cron Jobs
# Update threat feeds every hour
0 * * * * php <?= htmlspecialchars(dirname(dirname(__FILE__)), ENT_QUOTES, 'UTF-8') ?>/cron/threat_feeds.php >> /var/log/mc_cron.log 2>&1

# SSL certificate check daily at 6am
0 6 * * * php <?= htmlspecialchars(dirname(dirname(__FILE__)), ENT_QUOTES, 'UTF-8') ?>/cron/ssl_check.php >> /var/log/mc_cron.log 2>&1

# Database cleanup daily at 2am
0 2 * * * php <?= htmlspecialchars(dirname(dirname(__FILE__)), ENT_QUOTES, 'UTF-8') ?>/cron/cleanup.php >> /var/log/mc_cron.log 2>&1</pre>
</div>

<div class="card">
  <div class="card-header"><h3>🔌 API-Based Cron (Alternative)</h3></div>
  <p class="text-muted mb-2" style="font-size:0.875rem;">
    If you can't set server crons, use an external cron service to call these URLs (requires login session or API key):
  </p>
  <div style="display:flex;flex-direction:column;gap:8px;">
    <?php foreach ($cron_tasks as $task): ?>
    <div style="display:flex;align-items:center;gap:8px;">
      <code style="flex:1;"><?= htmlspecialchars($app_path . '/api/ajax.php?action=' . $task['action'], ENT_QUOTES, 'UTF-8') ?></code>
      <button class="btn btn-outline btn-xs"
              onclick="copyToClipboard('<?= htmlspecialchars($app_path . '/api/ajax.php?action=' . $task['action'], ENT_QUOTES, 'UTF-8') ?>')">📋</button>
    </div>
    <?php endforeach; ?>
  </div>
</div>

<script>
document.querySelectorAll('.run-cron-btn').forEach(function(btn){
  btn.addEventListener('click', function(){
    var action = btn.getAttribute('data-action');
    btn.disabled = true;
    btn.textContent = '⏳ Running...';
    apiCall(action, {}).then(function(res){
      if(res.status === 'ok'){
        showToast(res.message || 'Task completed', 'success');
      } else {
        showToast(res.message || 'Task failed', 'error');
      }
      btn.disabled = false;
      btn.textContent = '▶ Run';
    }).catch(function(){
      showToast('Request failed', 'error');
      btn.disabled = false;
      btn.textContent = '▶ Run';
    });
  });
});
</script>
