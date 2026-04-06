/* Monitor Central — globe.js (3-D orthographic globe) */
(function () {
  'use strict';

  var canvas     = document.getElementById('globeCanvas');
  var ctx;
  var W, H, cx, cy, R;   // R = base globe radius in px
  var scale      = 1;
  var rotLon     = 0;     // degrees — auto-rotates
  var rotLat     = 20;    // degrees — tilt
  var autoRotate = true;
  var lastIdleAt = 0;
  var IDLE_RESUME = 3000; // ms of inactivity before auto-rotation resumes

  var arcs       = [];
  var ripples    = [];
  var totalReqs  = 0;
  var totalBlock = 0;
  var lastFetch  = 0;
  var ipFeedList = null;
  var prevTs     = 0;

  var TO_RAD = Math.PI / 180;

  // Continent outlines as [lon, lat] pairs
  var continents = [
    // North America
    { color: 'rgba(0,200,150,0.15)', stroke: 'rgba(0,200,150,0.4)', points: [
      [-168,71],[-140,70],[-120,73],[-95,74],[-85,72],[-75,65],[-64,63],
      [-60,47],[-53,47],[-65,44],[-70,42],[-76,35],[-80,25],[-87,16],
      [-84,10],[-90,17],[-95,16],[-105,21],[-117,30],[-118,34],[-124,38],
      [-130,55],[-145,61],[-168,61]
    ]},
    // South America
    { color: 'rgba(0,200,150,0.12)', stroke: 'rgba(0,200,150,0.35)', points: [
      [-80,10],[-62,13],[-50,5],[-36,-5],[-37,-14],[-40,-22],[-43,-22],
      [-50,-28],[-53,-33],[-58,-38],[-63,-42],[-65,-55],[-68,-52],[-75,-40],
      [-72,-30],[-70,-18],[-75,-10],[-80,-2],[-80,10]
    ]},
    // Europe
    { color: 'rgba(10,132,255,0.15)', stroke: 'rgba(10,132,255,0.4)', points: [
      [-9,38],[-9,44],[-2,44],[0,46],[2,51],[5,58],[14,58],[18,60],
      [25,65],[28,71],[30,70],[28,65],[30,58],[25,55],[22,57],[18,57],
      [10,57],[8,55],[10,52],[14,54],[20,54],[24,58],[28,58],[30,62],
      [25,70],[18,70],[14,70],[8,71],[3,58],[2,50],[0,50],[-2,48],
      [-5,48],[-5,44],[-9,39],[-9,38]
    ]},
    // Africa
    { color: 'rgba(255,149,0,0.12)', stroke: 'rgba(255,149,0,0.35)', points: [
      [-5,37],[13,33],[25,32],[37,30],[43,12],[51,12],[44,5],[40,-11],
      [36,-18],[32,-29],[28,-34],[18,-34],[17,-29],[14,-22],[12,-18],
      [9,-5],[9,4],[0,5],[-5,5],[-10,8],[-15,12],[-17,15],[-13,17],
      [-10,25],[-5,33],[-5,37]
    ]},
    // Asia
    { color: 'rgba(10,132,255,0.12)', stroke: 'rgba(10,132,255,0.35)', points: [
      [26,36],[30,36],[36,34],[40,36],[50,38],[60,42],[66,36],[72,22],
      [77,8],[80,13],[100,2],[108,2],[120,20],[125,30],[130,34],[135,36],
      [140,38],[145,43],[142,47],[138,55],[140,60],[145,68],[150,68],
      [160,72],[170,68],[178,68],[178,62],[170,60],[160,55],[150,50],
      [140,48],[135,48],[130,45],[125,52],[120,52],[113,52],[105,52],
      [95,55],[85,55],[75,58],[65,62],[55,72],[50,70],[42,68],[38,68],
      [30,70],[28,65],[30,62],[28,58],[25,55],[32,50],[36,48],[40,48],
      [50,42],[52,40],[57,38],[62,34],[66,30],[62,22],[58,22],[52,22],
      [48,28],[44,34],[38,36],[35,33],[30,30],[28,36],[26,36]
    ]},
    // Australia
    { color: 'rgba(255,149,0,0.10)', stroke: 'rgba(255,149,0,0.3)', points: [
      [114,-22],[120,-20],[130,-11],[136,-12],[140,-15],[145,-17],[153,-28],
      [154,-38],[150,-39],[148,-42],[145,-40],[144,-38],[140,-36],[137,-35],
      [132,-33],[125,-34],[118,-32],[114,-28],[114,-22]
    ]},
  ];

  // ── Orthographic projection ───────────────────────────────────────────────
  // Projects (lon, lat) degrees onto canvas using current rotLon / rotLat.
  // Returns { x, y, visible } — visible is false for the back hemisphere.
  function project(lon, lat) {
    var phi  = lat  * TO_RAD;
    var lam  = lon  * TO_RAD;
    var phi0 = rotLat * TO_RAD;
    var lam0 = rotLon * TO_RAD;
    var dLam    = lam - lam0;
    var sinPhi  = Math.sin(phi),  cosPhi  = Math.cos(phi);
    var sinPhi0 = Math.sin(phi0), cosPhi0 = Math.cos(phi0);
    var cosDLam = Math.cos(dLam), sinDLam = Math.sin(dLam);
    var cosC = sinPhi0 * sinPhi + cosPhi0 * cosPhi * cosDLam;
    var r    = R * scale;
    return {
      x:       cx + r * cosPhi * sinDLam,
      y:       cy - r * (cosPhi0 * sinPhi - sinPhi0 * cosPhi * cosDLam),
      visible: cosC >= 0,
    };
  }

  // ── Sizing ────────────────────────────────────────────────────────────────
  function resize() {
    W  = canvas.width  = canvas.offsetWidth  || window.innerWidth;
    H  = canvas.height = canvas.offsetHeight || window.innerHeight;
    cx = W / 2;
    cy = H / 2;
    R  = Math.min(W, H) * 0.42;
  }

  // ── Globe base (ocean + atmosphere) ──────────────────────────────────────
  function drawSphere() {
    var r = R * scale;

    // Soft atmosphere halo
    var atmo = ctx.createRadialGradient(cx, cy, r * 0.9, cx, cy, r * 1.14);
    atmo.addColorStop(0, 'rgba(0,132,255,0.07)');
    atmo.addColorStop(1, 'rgba(0,0,0,0)');
    ctx.fillStyle = atmo;
    ctx.beginPath();
    ctx.arc(cx, cy, r * 1.14, 0, Math.PI * 2);
    ctx.fill();

    // Ocean fill with subtle radial shading
    var ocean = ctx.createRadialGradient(cx - r * 0.2, cy - r * 0.25, 0, cx, cy, r);
    ocean.addColorStop(0, '#0b1a2e');
    ocean.addColorStop(1, '#030a14');
    ctx.fillStyle = ocean;
    ctx.beginPath();
    ctx.arc(cx, cy, r, 0, Math.PI * 2);
    ctx.fill();
  }

  // ── Graticule (grid lines) ────────────────────────────────────────────────
  function drawGraticule() {
    ctx.strokeStyle = 'rgba(33,38,45,0.55)';
    ctx.lineWidth   = 0.5;

    // Longitude meridians every 30°
    for (var lon = -180; lon < 180; lon += 30) {
      ctx.beginPath();
      var pen = false;
      for (var lat = -88; lat <= 88; lat += 3) {
        var p = project(lon, lat);
        if (p.visible) { pen ? ctx.lineTo(p.x, p.y) : ctx.moveTo(p.x, p.y); pen = true; }
        else { pen = false; }
      }
      ctx.stroke();
    }

    // Latitude parallels every 30°
    for (var lat2 = -60; lat2 <= 60; lat2 += 30) {
      ctx.beginPath();
      var pen2 = false;
      for (var lon2 = -180; lon2 <= 180; lon2 += 3) {
        var p2 = project(lon2, lat2);
        if (p2.visible) { pen2 ? ctx.lineTo(p2.x, p2.y) : ctx.moveTo(p2.x, p2.y); pen2 = true; }
        else { pen2 = false; }
      }
      ctx.stroke();
    }
  }

  // ── Continent polygons ────────────────────────────────────────────────────
  // Subdivides each edge for smoother horizon clipping.
  function buildContinentPath(points) {
    var SUB = 4;
    var n   = points.length;
    ctx.beginPath();
    var pen = false;
    for (var i = 0; i < n; i++) {
      var a = points[i];
      var b = points[(i + 1) % n];
      for (var s = 0; s < SUB; s++) {
        var t   = s / SUB;
        var lon = a[0] + (b[0] - a[0]) * t;
        var lat = a[1] + (b[1] - a[1]) * t;
        var p   = project(lon, lat);
        if (p.visible) { pen ? ctx.lineTo(p.x, p.y) : ctx.moveTo(p.x, p.y); pen = true; }
        else { pen = false; }
      }
    }
  }

  function drawContinents() {
    continents.forEach(function (cont) {
      buildContinentPath(cont.points);
      ctx.fillStyle   = cont.color;
      ctx.fill();
      ctx.strokeStyle = cont.stroke;
      ctx.lineWidth   = 1;
      ctx.stroke();
    });
  }

  // ── Arcs ──────────────────────────────────────────────────────────────────
  function drawArcs(now) {
    arcs = arcs.filter(function (a) { return now - a.born < a.life; });
    arcs.forEach(function (a) {
      var progress = (now - a.born) / a.life;
      var alpha    = progress < 0.5 ? progress * 2 : 2 - progress * 2;

      ctx.save();
      ctx.globalAlpha = alpha * 0.85;
      ctx.strokeStyle = a.color;
      ctx.lineWidth   = 1.5;
      ctx.shadowColor = a.color;
      ctx.shadowBlur  = 6;

      ctx.beginPath();
      var steps = 60;
      var pen   = false;
      for (var i = 0; i <= Math.round(steps * progress); i++) {
        var t   = i / steps;
        var lon = a.srcLon + (a.dstLon - a.srcLon) * t;
        var lat = a.srcLat + (a.dstLat - a.srcLat) * t;
        var p   = project(lon, lat);
        if (p.visible) { pen ? ctx.lineTo(p.x, p.y) : ctx.moveTo(p.x, p.y); pen = true; }
        else { pen = false; }
      }
      ctx.stroke();

      // Tip dot
      var tp   = Math.min(progress, 1);
      var tLon = a.srcLon + (a.dstLon - a.srcLon) * tp;
      var tLat = a.srcLat + (a.dstLat - a.srcLat) * tp;
      var tp_p = project(tLon, tLat);
      if (tp_p.visible) {
        ctx.fillStyle  = a.color;
        ctx.shadowBlur = 8;
        ctx.beginPath();
        ctx.arc(tp_p.x, tp_p.y, 3, 0, Math.PI * 2);
        ctx.fill();
      }
      ctx.restore();
    });
  }

  // ── Ripples ───────────────────────────────────────────────────────────────
  function drawRipples(now) {
    ripples = ripples.filter(function (r) { return now - r.born < r.life; });
    ripples.forEach(function (r) {
      var p = project(r.lon, r.lat);
      if (!p.visible) return;
      var progress = (now - r.born) / r.life;
      ctx.save();
      ctx.strokeStyle = r.color;
      ctx.globalAlpha = (1 - progress) * 0.6;
      ctx.lineWidth   = 2;
      ctx.beginPath();
      ctx.arc(p.x, p.y, progress * 40, 0, Math.PI * 2);
      ctx.stroke();
      ctx.restore();
    });
  }

  // ── Server dots ───────────────────────────────────────────────────────────
  function drawServerDots() {
    var servers = window._globeServers || [];
    servers.forEach(function (s) {
      var lon = parseFloat(s.lon);
      var lat = parseFloat(s.lat);
      if (isNaN(lon) || isNaN(lat)) return;
      var p = project(lon, lat);
      if (!p.visible) return;
      ctx.save();
      ctx.fillStyle   = '#00c896';
      ctx.shadowColor = '#00c896';
      ctx.shadowBlur  = 12;
      ctx.beginPath();
      ctx.arc(p.x, p.y, 5, 0, Math.PI * 2);
      ctx.fill();
      ctx.restore();
      ctx.fillStyle = '#e6edf3';
      ctx.font = '11px Space Grotesk, sans-serif';
      ctx.fillText(s.name || s.domain, p.x + 8, p.y + 4);
    });
  }

  // ── Frame loop ────────────────────────────────────────────────────────────
  function frame(ts) {
    var dt  = Math.min((ts - prevTs) / 1000, 0.1);
    prevTs  = ts;
    var now = Date.now();

    // Resume auto-rotation after idle
    if (!autoRotate && lastIdleAt > 0 && now - lastIdleAt > IDLE_RESUME) {
      autoRotate = true;
    }
    if (autoRotate) {
      rotLon = (rotLon + dt * 6) % 360; // 6 °/s — full rotation in 60 s
    }

    // Background
    ctx.fillStyle = '#030508';
    ctx.fillRect(0, 0, W, H);

    drawSphere();

    // Clip graticule and continent fills to the globe circle
    var r = R * scale;
    ctx.save();
    ctx.beginPath();
    ctx.arc(cx, cy, r, 0, Math.PI * 2);
    ctx.clip();
    drawGraticule();
    drawContinents();
    ctx.restore();

    // Globe limb
    ctx.strokeStyle = 'rgba(10,132,255,0.3)';
    ctx.lineWidth   = 1.5;
    ctx.beginPath();
    ctx.arc(cx, cy, r, 0, Math.PI * 2);
    ctx.stroke();

    drawArcs(now);
    drawRipples(now);
    drawServerDots();

    requestAnimationFrame(frame);
  }

  // ── Arc / Ripple helpers ──────────────────────────────────────────────────
  // Store geographic coordinates so they project correctly as the globe rotates.
  function addArc(srcLon, srcLat, dstLon, dstLat, color, isBlocked) {
    arcs.push({
      srcLon: srcLon, srcLat: srcLat,
      dstLon: dstLon, dstLat: dstLat,
      color: color || (isBlocked ? '#ff2d55' : '#00c896'),
      born: Date.now(),
      life: 3000,
    });
    ripples.push({
      lon: dstLon, lat: dstLat,
      color: color || '#00c896',
      born: Date.now(), life: 1500,
    });
  }

  // ── Live feed ─────────────────────────────────────────────────────────────
  function addFeedItem(item) {
    if (!ipFeedList) return;
    var div = document.createElement('div');
    div.className = 'ip-feed-item';
    var flag  = item.country_code ? getFlagEmoji(item.country_code) : '🌐';
    var color = item.is_blocked ? '#ff2d55' : '#00c896';
    var dot   = document.createElement('span');
    dot.textContent = '●';
    dot.style.color = color;
    var ipSpan = document.createElement('span');
    ipSpan.className        = 'ip-text';
    ipSpan.style.fontFamily = 'IBM Plex Mono,monospace';
    ipSpan.style.color      = '#e6edf3';
    ipSpan.textContent      = item.ip || '';
    var countrySpan = document.createElement('span');
    countrySpan.className   = 'ip-country';
    countrySpan.textContent = flag + ' ' + (item.country || '');
    div.appendChild(dot);
    div.appendChild(ipSpan);
    div.appendChild(countrySpan);
    ipFeedList.insertBefore(div, ipFeedList.firstChild);
    var all = ipFeedList.querySelectorAll('.ip-feed-item');
    if (all.length > 30) all[all.length - 1].remove();
  }

  function getFlagEmoji(cc) {
    if (!cc || cc.length !== 2) return '🌐';
    cc = cc.toUpperCase();
    var base = 0x1F1E6;
    return String.fromCodePoint(base + cc.charCodeAt(0) - 65, base + cc.charCodeAt(1) - 65);
  }

  // ── Stats ─────────────────────────────────────────────────────────────────
  function updateStats() {
    var reqEl = document.getElementById('s-req');
    var blkEl = document.getElementById('s-blk');
    var arcEl = document.getElementById('s-arcs');
    if (reqEl) reqEl.textContent = totalReqs;
    if (blkEl) blkEl.textContent = totalBlock;
    if (arcEl) arcEl.textContent = arcs.length;
  }

  // ── Data fetch ────────────────────────────────────────────────────────────
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

        var srcLon = item.lon      != null && item.lon      !== '' ? parseFloat(item.lon)      : null;
        var srcLat = item.lat      != null && item.lat      !== '' ? parseFloat(item.lat)      : null;
        var dstLon = item.site_lon != null && item.site_lon !== '' ? parseFloat(item.site_lon) : null;
        var dstLat = item.site_lat != null && item.site_lat !== '' ? parseFloat(item.site_lat) : null;
        var color  = item.is_blocked ? '#ff2d55' : (item.abuse_score > 50 ? '#ff9500' : '#00c896');

        if (srcLon !== null && srcLat !== null && !isNaN(srcLon) && !isNaN(srcLat) &&
            dstLon !== null && dstLat !== null && !isNaN(dstLon) && !isNaN(dstLat)) {
          addArc(srcLon, srcLat, dstLon, dstLat, color, item.is_blocked);
        }
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

  // ── Interaction (drag to rotate, wheel to zoom) ───────────────────────────
  var isDragging = false;
  var dragStart  = { x: 0, y: 0, lon: 0, lat: 0 };

  function pauseAutoRotate() {
    autoRotate = false;
    lastIdleAt = 0;
  }

  function initInteraction() {
    canvas.addEventListener('mousedown', function (e) {
      isDragging = true;
      pauseAutoRotate();
      dragStart = { x: e.clientX, y: e.clientY, lon: rotLon, lat: rotLat };
      canvas.style.cursor = 'grabbing';
    });
    canvas.addEventListener('mousemove', function (e) {
      if (!isDragging) return;
      var dx = e.clientX - dragStart.x;
      var dy = e.clientY - dragStart.y;
      rotLon = (dragStart.lon - dx * 0.3 + 360) % 360;
      rotLat = Math.max(-80, Math.min(80, dragStart.lat + dy * 0.2));
    });
    canvas.addEventListener('mouseup', function () {
      isDragging = false;
      lastIdleAt = Date.now();
      canvas.style.cursor = 'default';
    });
    canvas.addEventListener('mouseleave', function () {
      if (isDragging) { isDragging = false; lastIdleAt = Date.now(); }
      canvas.style.cursor = 'default';
    });
    canvas.addEventListener('wheel', function (e) {
      e.preventDefault();
      var delta = e.deltaY > 0 ? 0.9 : 1.1;
      scale = Math.min(3, Math.max(0.3, scale * delta));
    }, { passive: false });

    // Touch
    var touch0 = null;
    canvas.addEventListener('touchstart', function (e) {
      if (e.touches.length === 1) {
        pauseAutoRotate();
        touch0 = { x: e.touches[0].clientX, y: e.touches[0].clientY, lon: rotLon, lat: rotLat };
      }
    }, { passive: true });
    canvas.addEventListener('touchmove', function (e) {
      if (e.touches.length === 1 && touch0) {
        var dx = e.touches[0].clientX - touch0.x;
        var dy = e.touches[0].clientY - touch0.y;
        rotLon = (touch0.lon - dx * 0.3 + 360) % 360;
        rotLat = Math.max(-80, Math.min(80, touch0.lat + dy * 0.2));
      }
    }, { passive: true });
    canvas.addEventListener('touchend', function () {
      touch0 = null;
      lastIdleAt = Date.now();
    }, { passive: true });
  }

  // ── Init ──────────────────────────────────────────────────────────────────
  function init() {
    ctx = canvas.getContext('2d');
    resize();
    window.addEventListener('resize', resize);

    ipFeedList = document.getElementById('ipFeedList');

    initInteraction();

    // Expose controls to globe.php buttons
    window._globeZoomIn  = function () { scale = Math.min(3, scale * 1.2); };
    window._globeZoomOut = function () { scale = Math.max(0.3, scale / 1.2); };
    window._globeReset   = function () { scale = 1; rotLon = 0; rotLat = 20; autoRotate = true; lastIdleAt = 0; };
    window._globeClear   = function () { arcs = []; };

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

