<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_auth();

$db = DB::getInstance();

$site_id   = isset($_GET['site_id']) && (int)$_GET['site_id'] > 0 ? (int)$_GET['site_id'] : null;
$date_from = $_GET['date_from'] ?? '';
$date_to   = $_GET['date_to']   ?? '';
$err_type  = trim($_GET['err_type'] ?? '');

$fatal_types = ['E_ERROR', 'E_PARSE', 'E_CORE_ERROR', 'E_COMPILE_ERROR'];

$sites = $db->fetchAll('SELECT id, domain, name FROM sites WHERE is_active=1 ORDER BY name');

$where  = "WHERE e.type IN ('E_ERROR','E_PARSE','E_CORE_ERROR','E_COMPILE_ERROR')";
$params = [];

if ($site_id)   { $where .= ' AND e.site_id=?'; $params[] = $site_id; }
if ($date_from) { $where .= ' AND e.timestamp >= ?'; $params[] = $date_from . ' 00:00:00'; }
if ($date_to)   { $where .= ' AND e.timestamp <= ?'; $params[] = $date_to . ' 23:59:59'; }
if ($err_type && in_array($err_type, $fatal_types, true)) {
    $where .= ' AND e.type=?'; $params[] = $err_type;
}

$errors = $db->fetchAll(
    "SELECT e.*, s.domain FROM error_log e
     LEFT JOIN sites s ON s.id=e.site_id
     $where ORDER BY e.timestamp DESC LIMIT 200",
    $params
);

$total = count($errors);
?>
<div class="page-header">
  <h2>⚠️ Error Log</h2>
  <p>PHP fatal errors — <?= $total ?> records</p>
</div>

<div class="card mb-3">
  <form method="GET" action="dashboard.php" class="form-row">
    <input type="hidden" name="panel" value="errors">
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
      <label>Error Type</label>
      <select name="err_type">
        <option value="">All Fatal Types</option>
        <?php foreach ($fatal_types as $ft): ?>
          <option value="<?= $ft ?>" <?= $err_type === $ft ? 'selected' : '' ?>><?= $ft ?></option>
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
    <button type="submit" class="btn btn-accent">Filter</button>
    <a href="dashboard.php?panel=errors" class="btn btn-outline">Reset</a>
    <div class="form-group mb-0" style="align-self:flex-end;">
      <label class="flex-center gap-1" style="cursor:pointer;">
        <input type="checkbox" id="groupSimilar" style="width:auto;"> Group similar
      </label>
    </div>
  </form>
</div>

<div class="card">
  <div class="table-wrap">
    <table id="errorsTable">
      <thead>
        <tr><th>Time</th><th>Site</th><th>Type</th><th>Message</th><th>File</th><th>Line</th></tr>
      </thead>
      <tbody>
        <?php foreach ($errors as $err): ?>
        <?php
        $typeCls = in_array($err['type'], ['E_PARSE', 'E_COMPILE_ERROR', 'E_CORE_ERROR'], true) ? 'danger' : 'warn';
        ?>
        <tr data-msg="<?= htmlspecialchars($err['message'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
          <td class="mono text-muted" style="white-space:nowrap;"><?= htmlspecialchars(substr($err['timestamp'], 0, 16), ENT_QUOTES, 'UTF-8') ?></td>
          <td class="text-muted"><?= htmlspecialchars($err['domain'] ?? '', ENT_QUOTES, 'UTF-8') ?></td>
          <td><span class="pill pill-<?= $typeCls ?>"><?= htmlspecialchars($err['type'] ?? '', ENT_QUOTES, 'UTF-8') ?></span></td>
          <td style="max-width:300px;" title="<?= htmlspecialchars($err['message'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
            <?= htmlspecialchars(truncate($err['message'] ?? '', 80), ENT_QUOTES, 'UTF-8') ?>
          </td>
          <td class="mono text-muted" style="max-width:200px;overflow:hidden;text-overflow:ellipsis;" title="<?= htmlspecialchars($err['file'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
            <?= htmlspecialchars(basename($err['file'] ?? ''), ENT_QUOTES, 'UTF-8') ?>
          </td>
          <td class="mono"><?= (int)$err['line'] ?></td>
        </tr>
        <?php endforeach; ?>
        <?php if (empty($errors)): ?>
        <tr><td colspan="6" class="text-muted text-center" style="padding:20px;">No fatal errors found 🎉</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<script>
document.getElementById('groupSimilar').addEventListener('change', function(){
  var rows = document.querySelectorAll('#errorsTable tbody tr[data-msg]');
  if(!this.checked){
    rows.forEach(function(r){ r.style.display=''; }); return;
  }
  var seen = {};
  rows.forEach(function(r){
    var msg = r.getAttribute('data-msg').substring(0,60);
    if(seen[msg]){ r.style.display='none'; } else { seen[msg]=true; r.style.display=''; }
  });
});
</script>
