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
    } else {
        $action = $_POST['action'] ?? '';
        if ($action === 'block_ip') {
            $ip     = trim($_POST['ip'] ?? '');
            $reason = trim($_POST['reason'] ?? '');
            $siteId = (int)($_POST['site_id'] ?? 0);
            if (filter_var($ip, FILTER_VALIDATE_IP)) {
                $db->execute(
                    'INSERT INTO blocked_ips (site_id, ip, reason, blocked_by, is_active) VALUES (?,?,?,?,1)',
                    [$siteId ?: null, $ip, $reason, 'manual']
                );
                update_htaccess_block($ip);
                $msg = 'IP ' . htmlspecialchars($ip, ENT_QUOTES, 'UTF-8') . ' blocked successfully.';
            } else {
                $msg = 'Invalid IP address.';
                $msgType = 'danger';
            }
        } elseif ($action === 'unblock_ip') {
            $id = (int)($_POST['block_id'] ?? 0);
            if ($id > 0) {
                $db->execute('UPDATE blocked_ips SET is_active=0 WHERE id=?', [$id]);
                $msg = 'IP unblocked.';
            }
        } elseif ($action === 'check_reputation') {
            $ip = trim($_POST['ip'] ?? '');
            if (filter_var($ip, FILTER_VALIDATE_IP)) {
                $info = check_abuseipdb($ip);
                $msg = $info ? 'Reputation checked for ' . htmlspecialchars($ip, ENT_QUOTES, 'UTF-8') . '. Abuse score: ' . ($info['abuseConfidenceScore'] ?? 'N/A') : 'Check complete (no API key configured).';
            }
        }
    }
}

$reputations = $db->fetchAll(
    'SELECT * FROM ip_reputation ORDER BY abuse_score DESC LIMIT 200'
);
$blocked = $db->fetchAll(
    'SELECT b.*, s.domain FROM blocked_ips b LEFT JOIN sites s ON s.id=b.site_id WHERE b.is_active=1 ORDER BY b.blocked_at DESC LIMIT 200'
);
$sites = $db->fetchAll('SELECT id, domain, name FROM sites WHERE is_active=1 ORDER BY name');
?>
<div class="page-header">
  <h2>🛡 IP Intelligence</h2>
  <p>Reputation database and block list</p>
</div>

<?php if ($msg): ?>
<div class="alert-bar <?= $msgType ?>"><?= htmlspecialchars($msg, ENT_QUOTES, 'UTF-8') ?></div>
<?php endif; ?>

<div class="tabs" id="ips-tabs">
  <button class="tab-btn active" data-tab="tab-reputation">🔍 Reputation (<?= count($reputations) ?>)</button>
  <button class="tab-btn" data-tab="tab-blocked">🚫 Blocked IPs (<?= count($blocked) ?>)</button>
  <button class="tab-btn" data-tab="tab-block-form">➕ Block IP</button>
</div>

<div id="tab-reputation" class="tab-content active">
  <div class="card">
    <div class="table-wrap">
      <table>
        <thead>
          <tr><th>IP</th><th>Abuse Score</th><th>Country</th><th>ISP</th><th>Flags</th><th>Reports</th><th>Last Checked</th></tr>
        </thead>
        <tbody>
          <?php foreach ($reputations as $rep): ?>
          <?php
          $score = (int)$rep['abuse_score'];
          $scoreCls = $score >= 75 ? 'danger' : ($score >= 25 ? 'warn' : ($score > 0 ? 'info' : 'ok'));
          ?>
          <tr>
            <td class="mono"><?= htmlspecialchars($rep['ip'], ENT_QUOTES, 'UTF-8') ?></td>
            <td><span class="pill pill-<?= $scoreCls ?>"><?= $score ?>%</span></td>
            <td><?= htmlspecialchars($rep['country'] ?? '', ENT_QUOTES, 'UTF-8') ?></td>
            <td class="text-muted"><?= htmlspecialchars(truncate($rep['isp'] ?? '', 30), ENT_QUOTES, 'UTF-8') ?></td>
            <td>
              <?= $rep['is_tor']   ? '<span class="pill pill-danger">TOR</span> '   : '' ?>
              <?= $rep['is_proxy'] ? '<span class="pill pill-warn">PROXY</span> '   : '' ?>
              <?= $rep['is_vpn']   ? '<span class="pill pill-info">VPN</span>'       : '' ?>
            </td>
            <td><?= (int)$rep['total_reports'] ?></td>
            <td class="text-muted"><?= $rep['last_checked'] ? time_ago($rep['last_checked']) : '—' ?></td>
          </tr>
          <?php endforeach; ?>
          <?php if (empty($reputations)): ?>
          <tr><td colspan="7" class="text-muted text-center" style="padding:20px;">No reputation data yet</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<div id="tab-blocked" class="tab-content">
  <div class="card">
    <div class="table-wrap">
      <table>
        <thead>
          <tr><th>IP</th><th>Site</th><th>Reason</th><th>Blocked By</th><th>Blocked At</th><th>Expires</th><th>Action</th></tr>
        </thead>
        <tbody>
          <?php foreach ($blocked as $b): ?>
          <tr>
            <td class="mono"><?= htmlspecialchars($b['ip'], ENT_QUOTES, 'UTF-8') ?></td>
            <td class="text-muted"><?= htmlspecialchars($b['domain'] ?? 'All', ENT_QUOTES, 'UTF-8') ?></td>
            <td><?= htmlspecialchars(truncate($b['reason'] ?? '', 40), ENT_QUOTES, 'UTF-8') ?></td>
            <td class="text-muted"><?= htmlspecialchars($b['blocked_by'] ?? '', ENT_QUOTES, 'UTF-8') ?></td>
            <td class="text-muted"><?= time_ago($b['blocked_at']) ?></td>
            <td class="text-muted"><?= $b['expires_at'] ? htmlspecialchars($b['expires_at'], ENT_QUOTES, 'UTF-8') : '∞' ?></td>
            <td>
              <form method="POST" action="dashboard.php?panel=ips" style="display:inline;">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="unblock_ip">
                <input type="hidden" name="block_id" value="<?= (int)$b['id'] ?>">
                <button type="submit" class="btn btn-outline btn-xs"
                        onclick="return confirm('Unblock this IP?')">✕ Unblock</button>
              </form>
            </td>
          </tr>
          <?php endforeach; ?>
          <?php if (empty($blocked)): ?>
          <tr><td colspan="7" class="text-muted text-center" style="padding:20px;">No blocked IPs</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<div id="tab-block-form" class="tab-content">
  <div class="card">
    <h3 style="margin-bottom:16px;">Block an IP Address</h3>
    <form method="POST" action="dashboard.php?panel=ips">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="block_ip">
      <div class="form-group">
        <label>IP Address *</label>
        <input type="text" name="ip" placeholder="e.g. 192.168.1.1" required>
      </div>
      <div class="form-group">
        <label>Site (optional)</label>
        <select name="site_id">
          <option value="">All Sites (global)</option>
          <?php foreach ($sites as $s): ?>
            <option value="<?= (int)$s['id'] ?>">
              <?= htmlspecialchars($s['name'] ?: $s['domain'], ENT_QUOTES, 'UTF-8') ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="form-group">
        <label>Reason</label>
        <input type="text" name="reason" placeholder="e.g. Brute force attempt">
      </div>
      <button type="submit" class="btn btn-danger">🚫 Block IP</button>
    </form>
    <hr style="border-color:var(--border);margin:24px 0;">
    <h3 style="margin-bottom:16px;">Check IP Reputation</h3>
    <form method="POST" action="dashboard.php?panel=ips">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="check_reputation">
      <div class="form-group">
        <label>IP Address</label>
        <input type="text" name="ip" placeholder="e.g. 1.2.3.4" required>
      </div>
      <button type="submit" class="btn btn-info">🔍 Check AbuseIPDB</button>
    </form>
  </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function(){
  initTabs('ips-tabs');
});
</script>
