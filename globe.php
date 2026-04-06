<?php
require_once __DIR__ . '/includes/auth.php';
require_auth();

$csrf_token = csrf_token();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="csrf-token" content="<?= htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8') ?>">
  <title>Globe Monitor — Monitor Central</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Mono:wght@400;500;600&family=Space+Grotesk:wght@300;400;500;600;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="css/style.css">
  <style>
    body.globe-page { margin: 0; overflow: hidden; background: #030508; }
    #globeCanvas { position: fixed; inset: 0; width: 100%; height: 100%; display: block; cursor: grab; }
    #globeCanvas:active { cursor: grabbing; }

    .globe-header {
      position: fixed; top: 0; left: 0; right: 300px;
      height: 48px; z-index: 20;
      background: rgba(13,17,23,0.85);
      border-bottom: 1px solid var(--border);
      display: flex; align-items: center;
      padding: 0 20px; gap: 20px;
      backdrop-filter: blur(10px);
    }
    .globe-header .logo {
      font-family: 'IBM Plex Mono', monospace;
      font-size: 0.9rem; color: var(--accent);
      white-space: nowrap;
    }
    .ip-feed {
      position: fixed; right: 0; top: 0; bottom: 0;
      width: 300px;
      background: rgba(13,17,23,0.92);
      border-left: 1px solid var(--border);
      overflow: hidden;
      backdrop-filter: blur(12px);
      z-index: 10;
      display: flex; flex-direction: column;
    }
    .ip-feed-header {
      padding: 12px 16px;
      border-bottom: 1px solid var(--border);
      font-size: 0.8rem; font-weight: 700;
      color: var(--text-dim);
      background: rgba(13,17,23,0.95);
      flex-shrink: 0;
    }
    .ip-feed-list { flex: 1; overflow-y: auto; }
    .ip-feed-item {
      padding: 8px 16px;
      border-bottom: 1px solid rgba(33,38,45,0.4);
      font-size: 0.75rem;
      animation: feedIn 0.3s ease;
      display: grid;
      grid-template-columns: 12px 1fr auto;
      gap: 6px; align-items: center;
    }
    .ip-feed-item .ip-text {
      font-family: 'IBM Plex Mono', monospace;
      color: var(--text);
      overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
    }
    .ip-feed-item .ip-country { color: var(--text-dim); text-align: right; }
    @keyframes feedIn {
      from { opacity: 0; transform: translateX(8px); }
      to   { opacity: 1; transform: translateX(0); }
    }
    .globe-controls {
      position: fixed; left: 16px; top: 64px;
      display: flex; flex-direction: column; gap: 6px;
      z-index: 20;
    }
    .stats-bar {
      position: fixed; left: 0; right: 300px; bottom: 0;
      height: 44px;
      background: rgba(13,17,23,0.85);
      border-top: 1px solid var(--border);
      display: flex; align-items: center;
      gap: 32px; padding: 0 20px;
      font-size: 0.78rem; color: var(--text-dim);
      backdrop-filter: blur(10px);
      z-index: 10;
    }
    .stats-bar span { display: flex; align-items: center; gap: 6px; }
    .stats-bar strong { color: var(--text); font-family: 'IBM Plex Mono', monospace; }
    .dot-live { width: 8px; height: 8px; background: var(--accent); border-radius: 50%; animation: pulse 2s infinite; flex-shrink: 0; }
    @keyframes pulse {
      0%, 100% { opacity: 1; } 50% { opacity: 0.3; }
    }

    /* ── Responsive ── */
    @media (max-width: 768px) {
      .globe-header { right: 0; }
      .ip-feed {
        width: 100%;
        top: auto; bottom: 44px;
        height: 220px;
        border-left: none;
        border-top: 1px solid var(--border);
      }
      .stats-bar { right: 0; }
      .globe-controls { top: 56px; }
    }
  </style>
</head>
<body class="globe-page">
  <canvas id="globeCanvas"></canvas>

  <div class="globe-header">
    <span class="logo">⬡ Monitor Central</span>
    <div class="dot-live"></div>
    <span style="font-size:0.8rem;color:var(--text-dim);">Live Globe View</span>
    <div style="margin-left:auto;">
      <a href="dashboard.php" class="btn btn-outline btn-sm">← Dashboard</a>
    </div>
  </div>

  <div class="ip-feed">
    <div class="ip-feed-header">🌐 Live Traffic Feed</div>
    <div class="ip-feed-list" id="ipFeedList"></div>
  </div>

  <div class="globe-controls">
    <button class="btn btn-outline btn-sm" id="btnZoomIn" title="Zoom in">+</button>
    <button class="btn btn-outline btn-sm" id="btnZoomOut" title="Zoom out">−</button>
    <button class="btn btn-outline btn-sm" id="btnReset" title="Reset view">↺</button>
    <button class="btn btn-outline btn-sm" id="btnClear" title="Clear arcs">✕</button>
  </div>

  <div class="stats-bar" id="statsBar">
    <span>🌐 Requests: <strong id="s-req">0</strong></span>
    <span>🔴 Blocked: <strong id="s-blk">0</strong></span>
    <span>📡 Live: <strong id="s-arcs">0</strong></span>
  </div>

  <script src="js/globe.js"></script>
  <script>
  document.getElementById('btnZoomIn').onclick  = function(){ window._globeZoomIn  && window._globeZoomIn(); };
  document.getElementById('btnZoomOut').onclick = function(){ window._globeZoomOut && window._globeZoomOut(); };
  document.getElementById('btnReset').onclick   = function(){ window._globeReset   && window._globeReset(); };
  document.getElementById('btnClear').onclick   = function(){ window._globeClear   && window._globeClear(); };
  </script>
</body>
</html>
