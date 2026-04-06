<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_auth();

$db = DB::getInstance();

$per_page   = 50;
$page       = max(1, (int)($_GET['page'] ?? 1));
$offset     = ($page - 1) * $per_page;

$site_id     = isset($_GET['site_id']) && (int)$_GET['site_id'] > 0 ? (int)$_GET['site_id'] : null;
$date_from   = $_GET['date_from'] ?? '';
$date_to     = $_GET['date_to']   ?? '';
$status_code = isset($_GET['status_code']) && (int)$_GET['status_code'] > 0 ? (int)$_GET['status_code'] : null;
$ip_filter   = trim($_GET['ip_filter'] ?? '');
$search      = trim($_GET['search']    ?? '');

$where  = 'WHERE 1=1';
$params = [];

if ($site_id !== null)     { $where .= ' AND t.site_id = ?'; $params[] = $site_id; }
if ($date_from !== '')     { $where .= ' AND t.timestamp >= ?'; $params[] = $date_from . ' 00:00:00'; }
if ($date_to !== '')       { $where .= ' AND t.timestamp <= ?'; $params[] = $date_to . ' 23:59:59'; }
if ($status_code !== null) { $where .= ' AND t.status_code = ?'; $params[] = $status_code; }
if ($ip_filter !== '')     { $where .= ' AND t.ip LIKE ?'; $params[] = '%' . $ip_filter . '%'; }
if ($search !== '')        { $where .= ' AND (t.url LIKE ? OR t.user_agent LIKE ?)'; $params[] = '%' . $search . '%'; $params[] = '%' . $search . '%'; }

$total_rows = (int)($db->fetchOne(
    "SELECT COUNT(*) AS c FROM traffic_log t $where", $params
)['c'] ?? 0);
$total_pages = max(1, (int)ceil($total_rows / $per_page));

$paged_params = $params;
$paged_params[] = $per_page;
$paged_params[] = $offset;
$rows = $db->fetchAll(
    "SELECT t.*, s.domain FROM traffic_log t LEFT JOIN sites s ON s.id=t.site_id
     $where ORDER BY t.timestamp DESC LIMIT ? OFFSET ?",
    $paged_params
);

$sites = $db->fetchAll('SELECT id, name, domain FROM sites WHERE is_active=1 ORDER BY name');

function build_page_url(int $pg): string {
    $q = $_GET;
    $q['panel'] = 'traffic';
    $q['page']  = $pg;
    return 'dashboard.php?' . http_build_query($q);
}
?>
<div class="page-header">
  <h2>🌊 Traffic Log</h2>
  <p><?= number_format($total_rows) ?> total records</p>
</div>

<div class="card mb-3">
  <form method="GET" action="dashboard.php" class="form-row">
    <input type="hidden" name="panel" value="traffic">
    <div class="form-group mb-0">
      <label>Site</label>
      <select name="site_id">
        <option value="">All Sites</option>
        <?php foreach ($sites as $s): ?>
          <option value="<?= (int)$s['id'] ?>" <?= $site_id === (int)$s['id'] ? 'selected' : '' ?>>
            <?= htmlspecialchars($s['name'] ?: $s['domain'], ENT_QUOTES, 'UTF-8') ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="form-group mb-0">
      <label>From</label>
      <input type="date" name="date_from" value="<?= htmlspecialchars($date_from, ENT_QUOTES, 'UTF-8') ?>">
    </div>
    <div class="form-group mb-0">
      <label>To</label>
      <input type="date" name="date_to" value="<?= htmlspecialchars($date_to, ENT_QUOTES, 'UTF-8') ?>">
    </div>
    <div class="form-group mb-0">
      <label>Status Code</label>
      <input type="number" name="status_code" placeholder="e.g. 404" min="100" max="599"
             value="<?= htmlspecialchars((string)($status_code ?? ''), ENT_QUOTES, 'UTF-8') ?>">
    </div>
    <div class="form-group mb-0">
      <label>IP Filter</label>
      <input type="text" name="ip_filter" placeholder="e.g. 192.168" value="<?= htmlspecialchars($ip_filter, ENT_QUOTES, 'UTF-8') ?>">
    </div>
    <div class="form-group mb-0">
      <label>Search URL/UA</label>
      <input type="text" name="search" placeholder="keyword" value="<?= htmlspecialchars($search, ENT_QUOTES, 'UTF-8') ?>">
    </div>
    <button type="submit" class="btn btn-accent">Filter</button>
    <a href="dashboard.php?panel=traffic" class="btn btn-outline">Reset</a>
  </form>
</div>

<div class="card">
  <div class="table-wrap">
    <table>
      <thead>
        <tr>
          <th>Time</th><th>Site</th><th>IP</th><th>Country</th>
          <th>Method</th><th>URL</th><th>Status</th><th>Response</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($rows as $row): ?>
        <?php
        $code = (int)$row['status_code'];
        $codeCls = $code >= 500 ? 'danger' : ($code >= 400 ? 'warn' : ($code >= 200 ? 'ok' : 'muted'));
        ?>
        <tr>
          <td class="mono text-muted" style="white-space:nowrap;"><?= htmlspecialchars(substr($row['timestamp'], 0, 16), ENT_QUOTES, 'UTF-8') ?></td>
          <td class="text-muted"><?= htmlspecialchars($row['domain'] ?? '', ENT_QUOTES, 'UTF-8') ?></td>
          <td class="mono"><?= htmlspecialchars($row['ip'] ?? '', ENT_QUOTES, 'UTF-8') ?></td>
          <td><?= get_flag_emoji($row['country_code'] ?? '') ?> <?= htmlspecialchars(truncate($row['country'] ?? '', 20), ENT_QUOTES, 'UTF-8') ?></td>
          <td><span class="pill pill-info"><?= htmlspecialchars($row['method'] ?? '', ENT_QUOTES, 'UTF-8') ?></span></td>
          <td class="mono" style="max-width:300px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="<?= htmlspecialchars($row['url'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
            <?= htmlspecialchars(truncate($row['url'] ?? '', 60), ENT_QUOTES, 'UTF-8') ?>
          </td>
          <td><span class="pill pill-<?= $codeCls ?>"><?= $code ?></span></td>
          <td class="mono text-muted"><?= $row['response_time'] ? number_format((float)$row['response_time'], 3) . 's' : '—' ?></td>
        </tr>
        <?php endforeach; ?>
        <?php if (empty($rows)): ?>
        <tr><td colspan="8" class="text-muted text-center" style="padding:20px;">No traffic records found</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>

  <?php if ($total_pages > 1): ?>
  <div class="pagination mt-2">
    <?php if ($page > 1): ?>
      <a href="<?= htmlspecialchars(build_page_url(1), ENT_QUOTES, 'UTF-8') ?>">«</a>
      <a href="<?= htmlspecialchars(build_page_url($page - 1), ENT_QUOTES, 'UTF-8') ?>">‹</a>
    <?php endif; ?>
    <?php
    $start = max(1, $page - 3);
    $end   = min($total_pages, $page + 3);
    for ($i = $start; $i <= $end; $i++): ?>
      <?php if ($i === $page): ?>
        <span class="current"><?= $i ?></span>
      <?php else: ?>
        <a href="<?= htmlspecialchars(build_page_url($i), ENT_QUOTES, 'UTF-8') ?>"><?= $i ?></a>
      <?php endif; ?>
    <?php endfor; ?>
    <?php if ($page < $total_pages): ?>
      <a href="<?= htmlspecialchars(build_page_url($page + 1), ENT_QUOTES, 'UTF-8') ?>">›</a>
      <a href="<?= htmlspecialchars(build_page_url($total_pages), ENT_QUOTES, 'UTF-8') ?>">»</a>
    <?php endif; ?>
    <span style="color:var(--text-dim);font-size:0.8rem;margin-left:8px;">Page <?= $page ?> of <?= $total_pages ?></span>
  </div>
  <?php endif; ?>
</div>
