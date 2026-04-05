/* Monitor Central — main.js */
(function () {
  'use strict';

  // ── CSRF token from meta tag ──
  function getCsrfToken() {
    const meta = document.querySelector('meta[name="csrf-token"]');
    return meta ? meta.getAttribute('content') : '';
  }

  // ── AJAX helper ──
  window.apiCall = function (action, data, method) {
    method = method || 'POST';
    const csrfToken = getCsrfToken();
    const body = Object.assign({ action: action, _csrf_token: csrfToken }, data || {});
    return fetch('api/ajax.php', {
      method: method,
      headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
      body: JSON.stringify(body),
    }).then(function (r) { return r.json(); });
  };

  // ── Panel navigation ──
  window.loadPanel = function (name) {
    window.location.href = 'dashboard.php?panel=' + encodeURIComponent(name);
  };

  // ── Active sidebar state ──
  function setActiveSidebar() {
    const params = new URLSearchParams(window.location.search);
    const panel = params.get('panel') || 'dashboard';
    document.querySelectorAll('.sidebar nav a[data-panel]').forEach(function (a) {
      a.classList.toggle('active', a.getAttribute('data-panel') === panel);
    });
  }

  // ── Auto-refresh for dashboard panel ──
  var autoRefreshTimer = null;
  function startAutoRefresh(seconds) {
    clearInterval(autoRefreshTimer);
    autoRefreshTimer = setInterval(function () {
      const params = new URLSearchParams(window.location.search);
      if ((params.get('panel') || 'dashboard') === 'dashboard') {
        refreshDashboardStats();
      }
    }, seconds * 1000);
  }

  function refreshDashboardStats() {
    if (typeof window.apiCall !== 'function') return;
    window.apiCall('get_stats', {}, 'POST').then(function (res) {
      if (res.status === 'ok' && res.data) {
        Object.keys(res.data).forEach(function (k) {
          var el = document.querySelector('[data-stat="' + k + '"]');
          if (el) el.textContent = res.data[k];
        });
      }
    }).catch(function () {});
  }

  // ── Canvas Line Chart ──
  window.drawLineChart = function (canvasId, labels, datasets, options) {
    var canvas = document.getElementById(canvasId);
    if (!canvas) return;
    var ctx = canvas.getContext('2d');
    var opts = options || {};
    var w = canvas.width;
    var h = canvas.height;
    var pad = { top: 20, right: 20, bottom: 40, left: 50 };
    var chartW = w - pad.left - pad.right;
    var chartH = h - pad.top - pad.bottom;

    ctx.clearRect(0, 0, w, h);

    var allValues = [];
    datasets.forEach(function (ds) { allValues = allValues.concat(ds.data); });
    var maxVal = opts.maxVal || (Math.max.apply(null, allValues) || 1);
    var minVal = opts.minVal || 0;
    var range  = maxVal - minVal || 1;

    // Grid lines
    ctx.strokeStyle = 'rgba(33,38,45,0.8)';
    ctx.lineWidth = 1;
    for (var i = 0; i <= 4; i++) {
      var y = pad.top + (chartH / 4) * i;
      ctx.beginPath(); ctx.moveTo(pad.left, y); ctx.lineTo(pad.left + chartW, y); ctx.stroke();
      ctx.fillStyle = '#7d8590';
      ctx.font = '11px IBM Plex Mono, monospace';
      ctx.textAlign = 'right';
      ctx.fillText(Math.round(maxVal - (range / 4) * i), pad.left - 6, y + 4);
    }

    // X labels
    ctx.fillStyle = '#7d8590';
    ctx.font = '11px Space Grotesk, sans-serif';
    ctx.textAlign = 'center';
    labels.forEach(function (label, i) {
      var x = pad.left + (chartW / (labels.length - 1 || 1)) * i;
      ctx.fillText(label, x, h - 8);
    });

    // Lines
    var colors = opts.colors || ['#00c896', '#0a84ff', '#ff9500', '#ff2d55'];
    datasets.forEach(function (ds, di) {
      var color = ds.color || colors[di % colors.length];
      var data = ds.data;
      if (!data || data.length < 2) return;

      ctx.beginPath();
      ctx.strokeStyle = color;
      ctx.lineWidth = 2;
      ctx.lineJoin = 'round';

      data.forEach(function (v, i) {
        var x = pad.left + (chartW / (data.length - 1 || 1)) * i;
        var y = pad.top + chartH - ((v - minVal) / range) * chartH;
        if (i === 0) ctx.moveTo(x, y); else ctx.lineTo(x, y);
      });
      ctx.stroke();

      // Fill
      ctx.save();
      var grad = ctx.createLinearGradient(0, pad.top, 0, pad.top + chartH);
      grad.addColorStop(0, color.replace(')', ', 0.15)').replace('rgb', 'rgba'));
      grad.addColorStop(1, color.replace(')', ', 0)').replace('rgb', 'rgba'));
      ctx.fillStyle = grad;
      ctx.beginPath();
      data.forEach(function (v, i) {
        var x = pad.left + (chartW / (data.length - 1 || 1)) * i;
        var y = pad.top + chartH - ((v - minVal) / range) * chartH;
        if (i === 0) ctx.moveTo(x, y); else ctx.lineTo(x, y);
      });
      ctx.lineTo(pad.left + chartW, pad.top + chartH);
      ctx.lineTo(pad.left, pad.top + chartH);
      ctx.closePath();
      ctx.fill();
      ctx.restore();
    });
  };

  // ── Canvas Bar Chart ──
  window.drawBarChart = function (canvasId, labels, data, options) {
    var canvas = document.getElementById(canvasId);
    if (!canvas) return;
    var ctx = canvas.getContext('2d');
    var opts = options || {};
    var w = canvas.width;
    var h = canvas.height;
    var pad = { top: 20, right: 20, bottom: 40, left: 50 };
    var chartW = w - pad.left - pad.right;
    var chartH = h - pad.top - pad.bottom;
    var maxVal = opts.maxVal || (Math.max.apply(null, data) || 1);
    var color = opts.color || '#00c896';

    ctx.clearRect(0, 0, w, h);

    // Grid
    ctx.strokeStyle = 'rgba(33,38,45,0.8)';
    ctx.lineWidth = 1;
    for (var i = 0; i <= 4; i++) {
      var y = pad.top + (chartH / 4) * i;
      ctx.beginPath(); ctx.moveTo(pad.left, y); ctx.lineTo(pad.left + chartW, y); ctx.stroke();
      ctx.fillStyle = '#7d8590';
      ctx.font = '11px IBM Plex Mono, monospace';
      ctx.textAlign = 'right';
      ctx.fillText(Math.round(maxVal * (1 - i / 4)), pad.left - 6, y + 4);
    }

    var barW = Math.max(4, (chartW / data.length) * 0.7);
    var gap   = chartW / data.length;

    data.forEach(function (v, i) {
      var barH = (v / maxVal) * chartH;
      var x = pad.left + gap * i + (gap - barW) / 2;
      var y = pad.top + chartH - barH;

      ctx.fillStyle = color;
      ctx.beginPath();
      ctx.roundRect ? ctx.roundRect(x, y, barW, barH, [3, 3, 0, 0]) : ctx.rect(x, y, barW, barH);
      ctx.fill();

      ctx.fillStyle = '#7d8590';
      ctx.font = '10px Space Grotesk, sans-serif';
      ctx.textAlign = 'center';
      if (labels[i]) ctx.fillText(labels[i], x + barW / 2, h - 8);
    });
  };

  // ── Canvas Donut Chart ──
  window.drawDonutChart = function (canvasId, data, labels, options) {
    var canvas = document.getElementById(canvasId);
    if (!canvas) return;
    var ctx = canvas.getContext('2d');
    var opts = options || {};
    var w = canvas.width;
    var h = canvas.height;
    var cx = w / 2;
    var cy = h / 2;
    var radius = Math.min(w, h) * 0.4;
    var innerRadius = radius * 0.6;
    var colors = opts.colors || ['#00c896', '#0a84ff', '#ff9500', '#ff2d55', '#7d8590'];
    var total = data.reduce(function (s, v) { return s + v; }, 0) || 1;

    ctx.clearRect(0, 0, w, h);

    var startAngle = -Math.PI / 2;
    data.forEach(function (v, i) {
      var sliceAngle = (v / total) * Math.PI * 2;
      ctx.beginPath();
      ctx.moveTo(cx, cy);
      ctx.arc(cx, cy, radius, startAngle, startAngle + sliceAngle);
      ctx.closePath();
      ctx.fillStyle = colors[i % colors.length];
      ctx.fill();
      startAngle += sliceAngle;
    });

    // Inner hole
    ctx.beginPath();
    ctx.arc(cx, cy, innerRadius, 0, Math.PI * 2);
    ctx.fillStyle = getComputedStyle(document.documentElement).getPropertyValue('--surface2').trim() || '#161b22';
    ctx.fill();

    // Center text
    ctx.fillStyle = '#e6edf3';
    ctx.font = 'bold 18px IBM Plex Mono, monospace';
    ctx.textAlign = 'center';
    ctx.textBaseline = 'middle';
    ctx.fillText(total, cx, cy);

    // Legend
    if (opts.showLegend && labels) {
      var legendY = h - (labels.length * 20) - 10;
      labels.forEach(function (label, i) {
        ctx.fillStyle = colors[i % colors.length];
        ctx.fillRect(10, legendY + i * 20, 12, 12);
        ctx.fillStyle = '#7d8590';
        ctx.font = '11px Space Grotesk, sans-serif';
        ctx.textAlign = 'left';
        ctx.textBaseline = 'top';
        ctx.fillText(label + ' (' + data[i] + ')', 28, legendY + i * 20);
      });
    }
  };

  // ── Table search ──
  window.initTableSearch = function (inputId, tableId) {
    var input = document.getElementById(inputId);
    var table = document.getElementById(tableId);
    if (!input || !table) return;
    input.addEventListener('input', function () {
      var q = input.value.toLowerCase();
      table.querySelectorAll('tbody tr').forEach(function (row) {
        row.style.display = row.textContent.toLowerCase().includes(q) ? '' : 'none';
      });
    });
  };

  // ── Modal ──
  window.openModal = function (id) {
    var el = document.getElementById(id);
    if (el) el.classList.add('active');
  };

  window.closeModal = function (id) {
    var el = document.getElementById(id);
    if (el) el.classList.remove('active');
  };

  document.addEventListener('click', function (e) {
    if (e.target.classList.contains('modal-overlay')) {
      e.target.classList.remove('active');
    }
  });

  // ── Toast notifications ──
  (function () {
    var container = document.createElement('div');
    container.className = 'toast-container';
    document.body.appendChild(container);
    window._toastContainer = container;
  })();

  window.showToast = function (message, type) {
    type = type || 'success';
    var icons = { success: '✅', error: '❌', warning: '⚠️', info: 'ℹ️' };
    var toast = document.createElement('div');
    toast.className = 'toast ' + type;
    toast.innerHTML = '<span>' + (icons[type] || '') + '</span><span>' + String(message).replace(/</g, '&lt;') + '</span>';
    window._toastContainer.appendChild(toast);
    setTimeout(function () {
      toast.style.opacity = '0';
      toast.style.transition = 'opacity 0.3s';
      setTimeout(function () { toast.remove(); }, 300);
    }, 4000);
  };

  // ── Confirm dialog ──
  window.confirmAction = function (message) {
    return new Promise(function (resolve) {
      resolve(window.confirm(message));
    });
  };

  // ── Tab switcher ──
  window.initTabs = function (containerId) {
    var container = document.getElementById(containerId) || document;
    container.querySelectorAll('.tab-btn').forEach(function (btn) {
      btn.addEventListener('click', function () {
        var target = btn.getAttribute('data-tab');
        container.querySelectorAll('.tab-btn').forEach(function (b) { b.classList.remove('active'); });
        container.querySelectorAll('.tab-content').forEach(function (c) { c.classList.remove('active'); });
        btn.classList.add('active');
        var panel = document.getElementById(target);
        if (panel) panel.classList.add('active');
      });
    });
  };

  // ── Clipboard copy ──
  window.copyToClipboard = function (text) {
    if (navigator.clipboard) {
      navigator.clipboard.writeText(text).then(function () {
        showToast('Copied to clipboard', 'success');
      }).catch(function () {
        showToast('Copy failed', 'error');
      });
    } else {
      var ta = document.createElement('textarea');
      ta.value = text;
      ta.style.cssText = 'position:fixed;opacity:0;';
      document.body.appendChild(ta);
      ta.select();
      try { document.execCommand('copy'); showToast('Copied!', 'success'); }
      catch (e) { showToast('Copy failed', 'error'); }
      document.body.removeChild(ta);
    }
  };

  // ── Mobile sidebar toggle ──
  var menuToggle = document.querySelector('.menu-toggle');
  var sidebar    = document.querySelector('.sidebar');
  if (menuToggle && sidebar) {
    menuToggle.addEventListener('click', function () {
      sidebar.classList.toggle('open');
    });
  }

  // ── Init ──
  document.addEventListener('DOMContentLoaded', function () {
    setActiveSidebar();
    startAutoRefresh(30);
    initTabs('app-root');

    // form confirmations
    document.querySelectorAll('[data-confirm]').forEach(function (el) {
      el.addEventListener('click', function (e) {
        if (!window.confirm(el.getAttribute('data-confirm'))) {
          e.preventDefault();
        }
      });
    });

    // API key toggles
    document.querySelectorAll('[data-toggle-key]').forEach(function (btn) {
      btn.addEventListener('click', function () {
        var targetId = btn.getAttribute('data-toggle-key');
        var el = document.getElementById(targetId);
        if (!el) return;
        if (el.type === 'password') { el.type = 'text'; btn.textContent = '🙈'; }
        else { el.type = 'password'; btn.textContent = '👁'; }
      });
    });

    // Copy API key
    document.querySelectorAll('[data-copy]').forEach(function (btn) {
      btn.addEventListener('click', function () {
        var sourceId = btn.getAttribute('data-copy');
        var el = document.getElementById(sourceId);
        if (el) copyToClipboard(el.value || el.textContent);
      });
    });
  });

})();
