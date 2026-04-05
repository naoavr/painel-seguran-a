<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/functions.php';
require_auth();

$panel = $_GET['panel'] ?? 'dashboard';

$allowed_panels = [
    'dashboard', 'stats', 'sites_status', 'traffic', 'relations',
    'files', 'errors', 'ips', 'threat', 'sites_manage',
    'settings', 'cron', 'database', 'manual',
];

if (!in_array($panel, $allowed_panels, true)) {
    $panel = 'dashboard';
}

$current_user = get_current_user_data();

$panel_titles = [
    'dashboard'    => 'Dashboard',
    'stats'        => 'Traffic Statistics',
    'sites_status' => 'Sites Status',
    'traffic'      => 'Traffic Log',
    'relations'    => 'IP Relations',
    'files'        => 'File Monitor',
    'errors'       => 'Error Log',
    'ips'          => 'IP Intelligence',
    'threat'       => 'Threat Intel',
    'sites_manage' => 'Site Management',
    'settings'     => 'Settings',
    'cron'         => 'Cron Jobs',
    'database'     => 'Database',
    'manual'       => 'Documentation',
];

$panel_title = $panel_titles[$panel] ?? 'Monitor Central';

try {
    $db = DB::getInstance();
    $unread_count = (int)($db->fetchOne('SELECT COUNT(*) AS c FROM alerts WHERE is_read=0')['c'] ?? 0);
} catch (Exception $e) {
    $unread_count = 0;
}

$nav_items = [
    ['panel' => 'dashboard',    'icon' => '📊', 'label' => 'Dashboard'],
    ['panel' => 'sites_status', 'icon' => '🌐', 'label' => 'Sites Status'],
    ['panel' => 'traffic',      'icon' => '🌊', 'label' => 'Traffic Log'],
    ['panel' => 'stats',        'icon' => '📈', 'label' => 'Statistics'],
    ['panel' => 'relations',    'icon' => '🔗', 'label' => 'IP Relations'],
    ['panel' => 'ips',          'icon' => '🛡',  'label' => 'IP Intelligence'],
    ['panel' => 'errors',       'icon' => '⚠️', 'label' => 'Error Log'],
    ['panel' => 'files',        'icon' => '📁', 'label' => 'File Monitor'],
    ['panel' => 'threat',       'icon' => '🌐', 'label' => 'Threat Intel'],
    ['panel' => 'sites_manage', 'icon' => '⚙️', 'label' => 'Sites'],
    ['panel' => 'settings',     'icon' => '🔧', 'label' => 'Settings'],
    ['panel' => 'cron',         'icon' => '⏰', 'label' => 'Cron Jobs'],
    ['panel' => 'database',     'icon' => '🗄',  'label' => 'Database'],
    ['panel' => 'manual',       'icon' => '📖', 'label' => 'Manual'],
];
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="csrf-token" content="<?= csrf_token() ?>">
  <title><?= htmlspecialchars($panel_title, ENT_QUOTES, 'UTF-8') ?> — Monitor Central</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Mono:wght@400;500;600&family=Space+Grotesk:wght@300;400;500;600;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="css/style.css">
</head>
<body id="app-root">

  <aside class="sidebar">
    <div class="logo">⬡ Monitor Central</div>
    <nav>
      <div class="nav-section">Monitoring</div>
      <?php foreach (array_slice($nav_items, 0, 5) as $item): ?>
      <a href="dashboard.php?panel=<?= htmlspecialchars($item['panel'], ENT_QUOTES, 'UTF-8') ?>"
         data-panel="<?= htmlspecialchars($item['panel'], ENT_QUOTES, 'UTF-8') ?>"
         class="<?= $panel === $item['panel'] ? 'active' : '' ?>">
        <span class="nav-icon"><?= $item['icon'] ?></span>
        <?= htmlspecialchars($item['label'], ENT_QUOTES, 'UTF-8') ?>
      </a>
      <?php endforeach; ?>

      <div class="nav-section">Security</div>
      <?php foreach (array_slice($nav_items, 5, 4) as $item): ?>
      <a href="dashboard.php?panel=<?= htmlspecialchars($item['panel'], ENT_QUOTES, 'UTF-8') ?>"
         data-panel="<?= htmlspecialchars($item['panel'], ENT_QUOTES, 'UTF-8') ?>"
         class="<?= $panel === $item['panel'] ? 'active' : '' ?>">
        <span class="nav-icon"><?= $item['icon'] ?></span>
        <?= htmlspecialchars($item['label'], ENT_QUOTES, 'UTF-8') ?>
      </a>
      <?php endforeach; ?>

      <div class="nav-section">Admin</div>
      <?php foreach (array_slice($nav_items, 9) as $item): ?>
      <a href="dashboard.php?panel=<?= htmlspecialchars($item['panel'], ENT_QUOTES, 'UTF-8') ?>"
         data-panel="<?= htmlspecialchars($item['panel'], ENT_QUOTES, 'UTF-8') ?>"
         class="<?= $panel === $item['panel'] ? 'active' : '' ?>">
        <span class="nav-icon"><?= $item['icon'] ?></span>
        <?= htmlspecialchars($item['label'], ENT_QUOTES, 'UTF-8') ?>
      </a>
      <?php endforeach; ?>

      <a href="globe.php" target="_blank">
        <span class="nav-icon">🌍</span>Globe View
      </a>
    </nav>
    <div class="sidebar-footer">
      Monitor Central v2.4
    </div>
  </aside>

  <div class="topbar">
    <div style="display:flex;align-items:center;gap:12px;">
      <button class="menu-toggle" aria-label="Toggle sidebar">☰</button>
      <span class="panel-title"><?= htmlspecialchars($panel_title, ENT_QUOTES, 'UTF-8') ?></span>
    </div>
    <div class="topbar-right">
      <?php if ($unread_count > 0): ?>
      <a href="dashboard.php?panel=dashboard" class="alert-badge" title="<?= $unread_count ?> unread alerts">
        🔔
        <span class="badge-count"><?= min($unread_count, 99) ?></span>
      </a>
      <?php endif; ?>
      <div class="user-badge">
        <div class="avatar">
          <?= strtoupper(substr($current_user['username'] ?? 'A', 0, 1)) ?>
        </div>
        <span><?= htmlspecialchars($current_user['username'] ?? 'Admin', ENT_QUOTES, 'UTF-8') ?></span>
      </div>
      <a href="logout.php" class="btn btn-outline btn-sm">Logout</a>
    </div>
  </div>

  <main class="main-content">
    <?php include __DIR__ . "/panels/{$panel}.php"; ?>
  </main>

  <div class="toast-container" id="toast-container"></div>

  <script src="js/main.js"></script>
</body>
</html>
