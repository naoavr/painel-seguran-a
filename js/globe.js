/* Monitor Central — globe.js */
(function () {
  'use strict';

  var canvas    = document.getElementById('globeCanvas');
  var ipFeed    = document.getElementById('ipFeed');
  var statsBar  = document.getElementById('statsBar');
  var ctx;
  var W, H;
  var arcs      = [];
  var ripples   = [];
  var feedItems = [];
  var totalReqs = 0;
  var totalBlock = 0;
  var lastFetch  = 0;

  // Simplified continent polygons [lon, lat] (normalized to 0-1 space)
  var continents = [
    // North America
    { color: 'rgba(0,200,150,0.15)', points: [
      [0.05,0.15],[0.28,0.12],[0.32,0.20],[0.30,0.35],[0.25,0.45],[0.20,0.50],[0.15,0.48],[0.08,0.35],[0.04,0.25]
    ]},
    // South America
    { color: 'rgba(0,200,150,0.12)', points: [
      [0.20,0.52],[0.30,0.50],[0.35,0.60],[0.32,0.75],[0.26,0.82],[0.20,0.78],[0.16,0.65],[0.17,0.55]
    ]},
    // Europe
    { color: 'rgba(10,132,255,0.15)', points: [
      [0.45,0.12],[0.58,0.10],[0.60,0.20],[0.58,0.28],[0.52,0.30],[0.47,0.28],[0.44,0.20]
    ]},
    // Africa
    { color: 'rgba(255,149,0,0.12)', points: [
      [0.47,0.32],[0.58,0.30],[0.62,0.45],[0.58,0.62],[0.50,0.68],[0.44,0.62],[0.42,0.48],[0.44,0.35]
    ]},
    // Asia
    { color: 'rgba(10,132,255,0.12)', points: [
      [0.58,0.08],[0.88,0.08],[0.92,0.22],[0.85,0.35],[0.72,0.42],[0.62,0.38],[0.58,0.28],[0.56,0.18]
    ]},
    // Australia
    { color: 'rgba(255,149,0,0.10)', points: [
      [0.75,0.55],[0.88,0.52],[0.92,0.62],[0.88,0.70],[0.78,0.72],[0.73,0.65],[0.72,0.58]
    ]},
  ];

  function lonLatToXY(lon, lat) {
    var x = ((lon + 180) / 360) * W;
    var y = ((90 - lat) / 180) * H;
    return { x: x, y: y };
  }

  function normToXY(nx, ny) {
    return { x: nx * W, y: ny * H };
  }

  function resize() {
    W = canvas.width  = window.innerWidth;
    H = canvas.height = window.innerHeight;
  }

  function drawMap() {
    // Background
    ctx.fillStyle = '#030508';
    ctx.fillRect(0, 0, W, H);

    // Grid lines
    ctx.strokeStyle = 'rgba(33,38,45,0.4)';
    ctx.lineWidth = 0.5;
    for (var lon = -180; lon <= 180; lon += 30) {
      var p = lonLatToXY(lon, 90);
      var p2 = lonLatToXY(lon, -90);
      ctx.beginPath(); ctx.moveTo(p.x, p.y); ctx.lineTo(p2.x, p2.y); ctx.stroke();
    }
    for (var lat = -60; lat <= 60; lat += 30) {
      var p3 = lonLatToXY(-180, lat);
      var p4 = lonLatToXY(180, lat);
      ctx.beginPath(); ctx.moveTo(p3.x, p3.y); ctx.lineTo(p4.x, p4.y); ctx.stroke();
    }

    // Continents
    continents.forEach(function (cont) {
      ctx.beginPath();
      cont.points.forEach(function (pt, i) {
        var xy = normToXY(pt[0], pt[1]);
        if (i === 0) ctx.moveTo(xy.x, xy.y);
        else ctx.lineTo(xy.x, xy.y);
      });
      ctx.closePath();
      ctx.fillStyle = cont.color;
      ctx.fill();
      ctx.strokeStyle = cont.color.replace('0.1', '0.3').replace('0.12', '0.3').replace('0.15', '0.3');
      ctx.lineWidth = 1;
      ctx.stroke();
    });
  }

  function drawArcs(now) {
    arcs = arcs.filter(function (a) { return now - a.born < a.life; });
    arcs.forEach(function (a) {
      var progress = (now - a.born) / a.life;
      var alpha = progress < 0.5 ? progress * 2 : 2 - progress * 2;

      var cp = {
        x: (a.sx + a.ex) / 2,
        y: Math.min(a.sy, a.ey) - Math.abs(a.ex - a.sx) * 0.4
      };

      ctx.save();
      ctx.globalAlpha = alpha * 0.8;
      ctx.strokeStyle = a.color;
      ctx.lineWidth = 1.5;
      ctx.shadowColor = a.color;
      ctx.shadowBlur = 6;

      // Draw partial arc up to progress
      ctx.beginPath();
      for (var t = 0; t <= progress; t += 0.02) {
        var mt = 1 - t;
        var x = mt * mt * a.sx + 2 * mt * t * cp.x + t * t * a.ex;
        var y = mt * mt * a.sy + 2 * mt * t * cp.y + t * t * a.ey;
        if (t === 0) ctx.moveTo(x, y); else ctx.lineTo(x, y);
      }
      ctx.stroke();

      // Dot at tip
      var tp = Math.min(progress, 1);
      var mt2 = 1 - tp;
      var tx = mt2 * mt2 * a.sx + 2 * mt2 * tp * cp.x + tp * tp * a.ex;
      var ty = mt2 * mt2 * a.sy + 2 * mt2 * tp * cp.y + tp * tp * a.ey;
      ctx.fillStyle = a.color;
      ctx.beginPath();
      ctx.arc(tx, ty, 3, 0, Math.PI * 2);
      ctx.fill();

      ctx.restore();
    });
  }

  function drawRipples(now) {
    ripples = ripples.filter(function (r) { return now - r.born < r.life; });
    ripples.forEach(function (r) {
      var progress = (now - r.born) / r.life;
      var radius   = progress * 40;
      var alpha    = (1 - progress) * 0.6;
      ctx.save();
      ctx.strokeStyle = r.color;
      ctx.globalAlpha = alpha;
      ctx.lineWidth = 2;
      ctx.beginPath();
      ctx.arc(r.x, r.y, radius, 0, Math.PI * 2);
      ctx.stroke();
      ctx.restore();
    });
  }

  function drawServerDots() {
    var servers = window._globeServers || [];
    servers.forEach(function (s) {
      var pos = lonLatToXY(s.lon, s.lat);
      ctx.save();
      ctx.fillStyle = '#00c896';
      ctx.shadowColor = '#00c896';
      ctx.shadowBlur = 12;
      ctx.beginPath();
      ctx.arc(pos.x, pos.y, 5, 0, Math.PI * 2);
      ctx.fill();
      ctx.restore();
      ctx.fillStyle = '#e6edf3';
      ctx.font = '11px Space Grotesk, sans-serif';
      ctx.fillText(s.name || s.domain, pos.x + 8, pos.y + 4);
    });
  }

  function frame() {
    var now = Date.now();
    drawMap();
    drawArcs(now);
    drawRipples(now);
    drawServerDots();
    requestAnimationFrame(frame);
  }

  function addArc(srcLon, srcLat, dstLon, dstLat, color, isBlocked) {
    var src = lonLatToXY(srcLon, srcLat);
    var dst = lonLatToXY(dstLon, dstLat);
    arcs.push({
      sx: src.x, sy: src.y,
      ex: dst.x, ey: dst.y,
      color: color || (isBlocked ? '#ff2d55' : '#00c896'),
      born: Date.now(),
      life: 3000,
    });
    ripples.push({ x: dst.x, y: dst.y, color: color || '#00c896', born: Date.now(), life: 1500 });
  }

  function addFeedItem(item) {
    var div = document.createElement('div');
    div.className = 'ip-feed-item';
    var flag = item.country_code ? getFlagEmoji(item.country_code) : '🌐';
    var color = item.is_blocked ? '#ff2d55' : '#00c896';
    var dot = document.createElement('span');
    dot.textContent = '●';
    dot.style.color = color;
    var ipSpan = document.createElement('span');
    ipSpan.style.fontFamily = 'IBM Plex Mono,monospace';
    ipSpan.style.color = '#e6edf3';
    ipSpan.textContent = item.ip || '';
    var countrySpan = document.createElement('span');
    countrySpan.textContent = flag + ' ' + (item.country || '');
    div.appendChild(dot);
    div.appendChild(ipSpan);
    div.appendChild(countrySpan);
    ipFeed.insertBefore(div, ipFeed.firstChild);
    var all = ipFeed.querySelectorAll('.ip-feed-item');
    if (all.length > 30) all[all.length - 1].remove();
  }

  function escHtml(s) {
    return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
  }

  function getFlagEmoji(cc) {
    if (!cc || cc.length !== 2) return '🌐';
    var base = 0x1F1E6;
    return String.fromCodePoint(base + cc.charCodeAt(0) - 65, base + cc.charCodeAt(1) - 65);
  }

  function updateStats() {
    if (!statsBar) return;
    statsBar.innerHTML = '<span>🌐 Requests: <strong id="s-req">' + totalReqs + '</strong></span>'
      + '<span>🔴 Blocked: <strong id="s-blk">' + totalBlock + '</strong></span>'
      + '<span>📡 Live arcs: <strong>' + arcs.length + '</strong></span>';
  }

  function fetchData() {
    var now = Date.now();
    if (now - lastFetch < 3000) return;
    lastFetch = now;

    fetch('api/ajax.php?action=get_globe_data', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
      body: JSON.stringify({ action: 'get_globe_data', _csrf_token: getCsrfToken() }),
    })
    .then(function (r) { return r.json(); })
    .then(function (res) {
      if (res.status !== 'ok') return;
      var data = res.data || {};
      window._globeServers = data.servers || [];

      (data.traffic || []).forEach(function (item) {
        totalReqs++;
        if (item.is_blocked) totalBlock++;

        var srcLon = parseFloat(item.lon) || 0;
        var srcLat = parseFloat(item.lat) || 0;
        var dstLon = parseFloat(item.site_lon) || 0;
        var dstLat = parseFloat(item.site_lat) || 0;
        var color  = item.is_blocked ? '#ff2d55' : (item.abuse_score > 50 ? '#ff9500' : '#00c896');

        addArc(srcLon, srcLat, dstLon, dstLat, color, item.is_blocked);
        addFeedItem(item);
      });
      updateStats();
    })
    .catch(function () {});
  }

  function getCsrfToken() {
    var meta = document.querySelector('meta[name="csrf-token"]');
    return meta ? meta.getAttribute('content') : '';
  }

  function init() {
    ctx = canvas.getContext('2d');
    resize();
    window.addEventListener('resize', resize);

    // Controls
    var ctrlWrap = document.querySelector('.globe-controls');
    if (ctrlWrap) {
      var clearBtn = document.createElement('button');
      clearBtn.className = 'btn btn-outline btn-sm';
      clearBtn.textContent = 'Clear';
      clearBtn.addEventListener('click', function () { arcs = []; });
      ctrlWrap.appendChild(clearBtn);
    }

    // Start fetch loop
    fetchData();
    setInterval(fetchData, 3000);
    setInterval(updateStats, 1000);

    requestAnimationFrame(frame);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }

})();
