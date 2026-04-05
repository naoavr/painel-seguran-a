<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_auth();

$db = DB::getInstance();

$sites = $db->fetchAll('SELECT id, domain, name FROM sites WHERE is_active=1 ORDER BY name');

$relations = $db->fetchAll(
    "SELECT t.site_id, t.ip, s.domain, t.country_code,
            COUNT(*) AS hits
     FROM traffic_log t
     JOIN sites s ON s.id = t.site_id
     WHERE t.timestamp >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
       AND t.ip IS NOT NULL
     GROUP BY t.site_id, t.ip
     HAVING hits > 2
     ORDER BY hits DESC
     LIMIT 200"
);

$nodes  = [];
$edges  = [];
$siteIds = [];
$ipMap   = [];

foreach ($relations as $r) {
    $sid = 'site_' . $r['site_id'];
    if (!isset($siteIds[$sid])) {
        $siteIds[$sid] = true;
        $nodes[] = ['id' => $sid, 'label' => $r['domain'], 'type' => 'site', 'hits' => 0];
    }
    $ipKey = 'ip_' . md5($r['ip']);
    if (!isset($ipMap[$ipKey])) {
        $ipMap[$ipKey] = true;
        $nodes[] = ['id' => $ipKey, 'label' => $r['ip'], 'type' => 'ip', 'cc' => $r['country_code'] ?? ''];
    }
    $edges[] = ['from' => $ipKey, 'to' => $sid, 'weight' => (int)$r['hits']];
}

$blocked_ips = $db->fetchAll("SELECT ip FROM blocked_ips WHERE is_active=1");
$blocked_set = array_flip(array_column($blocked_ips, 'ip'));
?>
<div class="page-header">
  <h2>🔗 IP Relations</h2>
  <p>Visual map of IP ↔ site relationships (last 24h)</p>
</div>

<div class="card mb-3">
  <div class="flex-between mb-2">
    <span class="text-muted" style="font-size:0.85rem;">
      <?= count($nodes) ?> nodes · <?= count($edges) ?> connections
    </span>
    <div class="btn-group">
      <button class="btn btn-outline btn-sm" id="btnZoomIn">+ Zoom In</button>
      <button class="btn btn-outline btn-sm" id="btnZoomOut">− Zoom Out</button>
      <button class="btn btn-outline btn-sm" id="btnReset">Reset</button>
      <select id="siteFilter" class="btn btn-outline btn-sm" style="padding:4px 8px;">
        <option value="">All Sites</option>
        <?php foreach ($sites as $s): ?>
          <option value="site_<?= (int)$s['id'] ?>">
            <?= htmlspecialchars($s['name'] ?: $s['domain'], ENT_QUOTES, 'UTF-8') ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>
  </div>
  <div class="canvas-wrap" style="height:500px;background:#0a0d12;border-radius:6px;">
    <canvas id="relationsCanvas" style="width:100%;height:100%;"></canvas>
  </div>
</div>

<script>
(function(){
  var nodes   = <?= json_encode(array_values($nodes)) ?>;
  var edges   = <?= json_encode(array_values($edges)) ?>;
  var blocked = <?= json_encode(array_keys($blocked_set)) ?>;

  var canvas = document.getElementById('relationsCanvas');
  if(!canvas) return;
  var ctx    = canvas.getContext('2d');
  var scale  = 1, offsetX = 0, offsetY = 0;
  var W, H;
  var positions = {};
  var dragging = null, dragStart = null;
  var activeFilter = '';

  function resize(){
    W = canvas.width  = canvas.parentElement.offsetWidth;
    H = canvas.height = canvas.parentElement.offsetHeight;
    layout();
    draw();
  }

  function layout(){
    var sites = nodes.filter(function(n){ return n.type==='site'; });
    var ips   = nodes.filter(function(n){ return n.type==='ip'; });
    var cx = W/2, cy = H/2;
    var siteR = Math.min(W,H)*0.25;
    sites.forEach(function(n, i){
      var angle = (i/Math.max(1,sites.length)) * Math.PI*2;
      positions[n.id] = { x: cx + Math.cos(angle)*siteR, y: cy + Math.sin(angle)*siteR };
    });
    var ipR = Math.min(W,H)*0.42;
    ips.forEach(function(n, i){
      var angle = (i/Math.max(1,ips.length)) * Math.PI*2;
      positions[n.id] = { x: cx + Math.cos(angle)*ipR, y: cy + Math.sin(angle)*ipR };
    });
  }

  function draw(){
    ctx.save();
    ctx.clearRect(0,0,W,H);
    ctx.translate(offsetX, offsetY);
    ctx.scale(scale, scale);

    var visibleSite = activeFilter || null;

    // Edges
    edges.forEach(function(e){
      var from = positions[e.from], to = positions[e.to];
      if(!from || !to) return;
      if(visibleSite && e.to !== visibleSite) return;
      var isBlocked = blocked.includes(nodes.find(function(n){ return n.id===e.from; })?.label);
      ctx.save();
      ctx.globalAlpha = 0.3;
      ctx.strokeStyle = isBlocked ? '#ff2d55' : '#00c896';
      ctx.lineWidth = Math.max(0.5, Math.min(3, e.weight/10));
      ctx.beginPath();
      var mx = (from.x+to.x)/2, my = (from.y+to.y)/2 - 30;
      ctx.moveTo(from.x,from.y);
      ctx.quadraticCurveTo(mx,my,to.x,to.y);
      ctx.stroke();
      ctx.restore();
    });

    // Nodes
    nodes.forEach(function(n){
      var pos = positions[n.id];
      if(!pos) return;
      if(visibleSite && n.type==='ip'){
        var connected = edges.some(function(e){ return e.from===n.id && e.to===visibleSite; });
        if(!connected) return;
      }
      ctx.save();
      var r = n.type==='site' ? 16 : 8;
      var color = n.type==='site' ? '#0a84ff' : (blocked.includes(n.label) ? '#ff2d55' : '#00c896');
      ctx.fillStyle = color;
      ctx.shadowColor = color; ctx.shadowBlur = 10;
      ctx.beginPath(); ctx.arc(pos.x,pos.y,r,0,Math.PI*2); ctx.fill();
      ctx.shadowBlur=0;
      ctx.fillStyle='#e6edf3';
      ctx.font = n.type==='site' ? 'bold 11px Space Grotesk,sans-serif' : '9px IBM Plex Mono,monospace';
      ctx.textAlign='center';
      ctx.fillText(n.label, pos.x, pos.y + r + 12);
      ctx.restore();
    });

    ctx.restore();
  }

  document.getElementById('btnZoomIn').onclick  = function(){ scale = Math.min(4,scale*1.2); draw(); };
  document.getElementById('btnZoomOut').onclick = function(){ scale = Math.max(0.2,scale/1.2); draw(); };
  document.getElementById('btnReset').onclick   = function(){ scale=1;offsetX=0;offsetY=0;draw(); };
  document.getElementById('siteFilter').onchange = function(){
    activeFilter = this.value; draw();
  };

  // Drag
  canvas.addEventListener('mousedown',function(e){
    dragging=true;
    dragStart={x:e.clientX-offsetX,y:e.clientY-offsetY};
  });
  canvas.addEventListener('mousemove',function(e){
    if(!dragging) return;
    offsetX=e.clientX-dragStart.x;
    offsetY=e.clientY-dragStart.y;
    draw();
  });
  canvas.addEventListener('mouseup',function(){ dragging=false; });

  window.addEventListener('resize', resize);
  resize();
})();
</script>
