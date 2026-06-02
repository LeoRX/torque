// session.js — Interactive logic for session.php
// All PHP data is injected via inline globals in the data-bridge <script> block.
// Requires: torquehelpers.js (loaded before this file), Mapbox GL JS, Chart.js (conditional)

// ── Constants ─────────────────────────────────────────────────────────────────
var _mbDarkStyle   = 'mapbox://styles/mapbox/dark-v11';
var _hudColors     = ['#00d4ff','#ff6b6b','#00ff88','#f4a261','#9b5de5','#00b4d8','#fb8500'];
var _hudColorsFill = ['rgba(0,212,255,0.08)','rgba(255,107,107,0.08)','rgba(0,255,136,0.06)',
                      'rgba(244,162,97,0.07)','rgba(155,93,229,0.07)','rgba(0,180,216,0.07)','rgba(251,133,0,0.07)'];

// ── Chart datasets — built from PHP-injected _chartSeries ────────────────────
var torqueDatasets = (_chartSeries && _chartSeries.length > 0)
  ? _chartSeries.map(function(s, idx) {
      return {
        label: s.label,
        kcode: s.kcode,
        data:  s.data.map(function(p) { return {x: p[0], y: p[1]}; }),
        borderWidth:    1.5,
        pointRadius:    0,
        pointHitRadius: 8,
        tension:        0.1,
        borderColor:     _hudColors[idx % _hudColors.length],
        backgroundColor: _hudColorsFill[idx % _hudColorsFill.length],
        fill: true
      };
    })
  : [];

// ── Chart theme ───────────────────────────────────────────────────────────────
function _applyChartTheme(isDark) {
  if (typeof Chart === 'undefined') return;
  var text = '#8ab';
  var grid = 'rgba(0, 212, 255, 0.07)';
  Chart.defaults.color       = text;
  Chart.defaults.borderColor = grid;
  if (window.torqueChart) {
    var s = window.torqueChart.options.scales;
    if (s.x) {
      s.x.ticks = Object.assign(s.x.ticks || {}, { color: text });
      s.x.grid  = Object.assign(s.x.grid  || {}, { color: grid });
      if (s.x.title) s.x.title.color = text;
    }
    if (s.y) {
      s.y.ticks = Object.assign(s.y.ticks || {}, { color: text });
      s.y.grid  = Object.assign(s.y.grid  || {}, { color: grid });
    }
    var lbl = window.torqueChart.options.plugins.legend.labels;
    if (lbl) lbl.color = text;
    window.torqueChart.update('none');
  }
}

// ── Global UI functions ───────────────────────────────────────────────────────
function torqueToggle(id, btn) {
  var el = document.getElementById(id);
  if (!el) return;
  var hidden = el.classList.contains('torque-panel--hidden') || el.style.display === 'none';
  if (hidden) {
    el.style.display = '';
    requestAnimationFrame(function() { el.classList.remove('torque-panel--hidden'); });
  } else {
    el.classList.add('torque-panel--hidden');
    setTimeout(function() { el.style.display = 'none'; }, 150);
  }
  if (btn) btn.classList.toggle('active', hidden);
  if (id === 'chart-section') {
    document.body.classList.toggle('chart-open', hidden);
    if (hidden && window.torqueChart) {
      setTimeout(function() { window.torqueChart.resize(); }, 350);
    }
  }
  if (window._torqueMap) {
    setTimeout(function() { window._torqueMap.resize(); }, 350);
  }
}

function toggleDarkMode() {
  var html   = document.documentElement;
  var isDark = html.getAttribute('data-bs-theme') === 'dark';
  var nowDark = !isDark;
  html.setAttribute('data-bs-theme', nowDark ? 'dark' : 'light');
  var btn = document.getElementById('darkModeBtn');
  if (btn) {
    btn.innerHTML = nowDark ? '<i class="bi bi-sun"></i>' : '<i class="bi bi-moon-stars"></i>';
    btn.title     = nowDark ? 'Dimmed mode' : 'Full neon mode';
  }
  localStorage.setItem('torque-theme', nowDark ? 'dark' : 'light');

  if (typeof _applyChartTheme === 'function') { _applyChartTheme(nowDark); }

  if (window._torqueMap) {
    var newStyle = nowDark ? _mbDarkStyle : _mbStyle;
    window._torqueMap.setStyle(newStyle);
    if (window._torqueDrawRoute) {
      window._torqueMap.once('style.load', function() {
        window._torqueDrawRoute();
        if (window._torqueDrawMulti) window._torqueDrawMulti(window._torqueMap);
      });
    }
  }
}

// ── Sync dark mode button icon on page load ───────────────────────────────────
document.addEventListener('DOMContentLoaded', function() {
  var saved = localStorage.getItem('torque-theme') || 'light';
  var btn   = document.getElementById('darkModeBtn');
  if (btn) btn.innerHTML = saved === 'dark' ? '<i class="bi bi-sun"></i>' : '<i class="bi bi-moon-stars"></i>';

  // Apply chart-open class if chart panel is already visible on load
  var chartEl = document.getElementById('chart-section');
  if (chartEl && chartEl.style.display !== 'none') {
    document.body.classList.add('chart-open');
  }
});

// ── Map initialization ────────────────────────────────────────────────────────
document.addEventListener('DOMContentLoaded', function() {
  var mapEl = document.getElementById('map-canvas');
  if (!mapEl) return;

  if (!_mbToken) {
    mapEl.style.cssText += 'display:flex;align-items:center;justify-content:center;';
    mapEl.innerHTML = '<div class="text-center text-muted p-4">' +
      '<i class="bi bi-map" style="font-size:2.5rem;display:block;margin-bottom:.5rem;"></i>' +
      'No Mapbox token — add yours in <a href="settings.php">Settings &rarr; Map</a>.</div>';
    return;
  }

  mapboxgl.accessToken = _mbToken;
  var _initDark = (localStorage.getItem('torque-theme') === 'dark');
  var map = new mapboxgl.Map({
    container:  'map-canvas',
    style:      _initDark ? _mbDarkStyle : _mbStyle,
    center:     [0, 0],
    zoom:       1,
    projection: 'mercator'
  });
  map.addControl(new mapboxgl.NavigationControl(), 'top-right');
  window._torqueMap = map;

  map.on('error', function(e) {
    var status = e.error && e.error.status;
    if (status === 401 || status === 403) {
      console.warn('[Torque] Mapbox ' + status + ' — tile auth failed.');
      if (!sessionStorage.getItem('torque-mapbox-cache-cleared')) {
        sessionStorage.setItem('torque-mapbox-cache-cleared', '1');
        if (typeof mapboxgl.clearStorage === 'function') {
          console.info('[Torque] Clearing Mapbox tile cache and reloading…');
          mapboxgl.clearStorage(function() { window.location.reload(); });
        } else {
          window.location.reload();
        }
        return;
      }
      if (!document.getElementById('map-token-warn')) {
        var warn = document.createElement('div');
        warn.id = 'map-token-warn';
        warn.style.cssText = 'position:absolute;bottom:44px;left:50%;transform:translateX(-50%);' +
          'background:rgba(220,38,38,0.92);color:#fff;padding:7px 16px;border-radius:8px;' +
          'font-size:12px;z-index:10;white-space:nowrap;box-shadow:0 2px 8px rgba(0,0,0,0.3);';
        warn.innerHTML = '<i class="bi bi-exclamation-triangle-fill me-1"></i>' +
          'Mapbox token invalid or expired — ' +
          '<a href="settings.php" style="color:#fecaca;text-decoration:underline">update it in Settings</a>';
        mapEl.appendChild(warn);
      }
    } else {
      console.error('[Torque] Mapbox error:', e.error && e.error.message ? e.error.message : e);
    }
  });

  if (_noSession) {
    map.on('load', function() {
      new mapboxgl.Popup({ closeOnClick: false, closeButton: false })
        .setLngLat([0, 0])
        .setHTML('<div class="p-2 small text-muted">Select a session to see the route.</div>')
        .addTo(map);
    });
    return;
  }

  if (!_hasGPS) {
    var noGpsDiv = document.createElement('div');
    noGpsDiv.style.cssText =
      'position:absolute;top:50%;left:50%;transform:translate(-50%,-50%);z-index:10;' +
      'background:rgba(6,9,18,0.88);padding:12px 18px;border-radius:8px;' +
      'border:1px solid rgba(0,212,255,0.2);color:#8ab;' +
      'font-size:13px;text-align:center;box-shadow:0 0 24px rgba(0,212,255,0.06),0 4px 20px rgba(0,0,0,0.6);pointer-events:none;';
    var _noGpsMsg = _gpsQuality === 'recorded_no_fix'
      ? 'GPS recorded but no satellite fix'
      : 'No GPS data for this session';
    noGpsDiv.innerHTML = '<i class="bi bi-geo-alt-fill" style="font-size:1.5rem;color:#00d4ff;display:block;margin-bottom:4px;"></i>' + _noGpsMsg;
    mapEl.appendChild(noGpsDiv);
    return;
  }

  _routeData = _routeData.filter(function(p) {
    return p[0] >= -180 && p[0] <= 180
        && p[1] >= -90  && p[1] <= 90
        && !(p[0] === 0 && p[1] === 0);
  });

  if (_routeData.length === 0) {
    var noGpsDiv2 = document.createElement('div');
    noGpsDiv2.style.cssText =
      'position:absolute;top:50%;left:50%;transform:translate(-50%,-50%);z-index:10;' +
      'background:rgba(6,9,18,0.88);padding:12px 18px;border-radius:8px;' +
      'border:1px solid rgba(0,212,255,0.2);color:#8ab;' +
      'font-size:13px;text-align:center;box-shadow:0 0 24px rgba(0,212,255,0.06),0 4px 20px rgba(0,0,0,0.6);pointer-events:none;';
    noGpsDiv2.innerHTML = '<i class="bi bi-geo-alt-fill" style="font-size:1.5rem;color:#00d4ff;display:block;margin-bottom:4px;"></i>No valid GPS data for this session';
    mapEl.appendChild(noGpsDiv2);
    return;
  }

  var coords = _routeData.map(function(p) { return [p[0], p[1]]; });

  var lons = _routeData.map(function(p) { return p[0]; });
  var lats = _routeData.map(function(p) { return p[1]; });
  var lonSpan = Math.max.apply(null, lons) - Math.min.apply(null, lons);
  var latSpan = Math.max.apply(null, lats) - Math.min.apply(null, lats);
  var _gpsFixed = (lonSpan < 0.0001 && latSpan < 0.0001);

  var bounds = new mapboxgl.LngLatBounds();
  coords.forEach(function(c) { bounds.extend(c); });

  if (_gpsFixed) {
    var _gpsFrozen = (_maxSpeed > 5);
    var pinLng    = lons[0], pinLat = lats[0];
    var pinColor  = _gpsFrozen ? '#f4a261' : '#00d4ff';
    var pinGlow   = _gpsFrozen ? 'rgba(244,162,97,0.15)' : 'rgba(0,212,255,0.15)';
    var pinBorder = _gpsFrozen ? 'rgba(244,162,97,0.4)'  : 'rgba(0,212,255,0.4)';
    var pinMsg    = _gpsFrozen
      ? '<i class="bi bi-exclamation-triangle-fill me-1" style="color:#f4a261"></i>GPS position frozen — route unavailable (phone lost GPS lock)'
      : '<i class="bi bi-p-circle-fill me-1" style="color:#00d4ff"></i>Parked session — no route to plot';

    window._torqueDrawRoute = function() {
      try {
        if (map.getLayer('route'))         { map.removeLayer('route'); }
        if (map.getLayer('route-outline')) { map.removeLayer('route-outline'); }
        if (map.getSource('route'))        { map.removeSource('route'); }
        var pinEl = document.createElement('div');
        pinEl.style.cssText =
          'width:18px;height:18px;border-radius:50%;cursor:default;' +
          'background:' + pinColor + ';border:3px solid ' + pinBorder + ';' +
          'box-shadow:0 0 0 6px ' + pinGlow + ',0 2px 8px rgba(0,0,0,0.5);';
        new mapboxgl.Marker({ element: pinEl, anchor: 'center' })
          .setLngLat([pinLng, pinLat]).addTo(map);
        map.flyTo({ center: [pinLng, pinLat], zoom: 15, duration: 0 });
        var infoEl = document.createElement('div');
        infoEl.style.cssText =
          'position:absolute;bottom:44px;left:50%;transform:translateX(-50%);z-index:10;' +
          'background:rgba(6,9,18,0.88);color:#8ab;padding:5px 12px;border-radius:8px;' +
          'border:1px solid rgba(0,212,255,0.2);font-size:11px;white-space:nowrap;' +
          'box-shadow:0 2px 8px rgba(0,0,0,0.4);pointer-events:none;';
        infoEl.innerHTML = pinMsg;
        mapEl.appendChild(infoEl);
      } catch(e) { console.error('Fixed-GPS pin error:', e); }
    };
    if (map.loaded()) { window._torqueDrawRoute(); }
    else { map.once('load', function() { window._torqueDrawRoute(); }); }
    return;
  }

  // Speed → colour (blue = slow … red = fast), matching the legend gradient.
  function _speedColor(speed) {
    var r = _maxSpeed > 0 ? Math.min(1, Math.max(0, speed / _maxSpeed)) : 0;
    var h = (1 - r) * 240, s = 1.0, l = 0.45;
    var c = (1 - Math.abs(2 * l - 1)) * s;
    var x = c * (1 - Math.abs((h / 60) % 2 - 1));
    var m = l - c / 2, rr, gg, bb;
    if      (h < 60)  { rr = c; gg = x; bb = 0; }
    else if (h < 120) { rr = x; gg = c; bb = 0; }
    else if (h < 180) { rr = 0; gg = c; bb = x; }
    else if (h < 240) { rr = 0; gg = x; bb = c; }
    else              { rr = x; gg = 0; bb = c; }
    return 'rgb(' + Math.round((rr + m) * 255) + ',' + Math.round((gg + m) * 255) + ',' + Math.round((bb + m) * 255) + ')';
  }
  // A "gap" is a GPS dropout: don't draw a connecting line across it (it would
  // imply a path we don't have). Break on a large time OR distance jump.
  // Thresholds come from Settings (injected as _routeGapSec / _routeGapM).
  var GAP_TIME_MS = (typeof _routeGapSec === 'number' ? _routeGapSec : 30) * 1000;
  var GAP_DIST_M  = (typeof _routeGapM   === 'number' ? _routeGapM   : 300);

  window._torqueDrawRoute = function() {
    try {
      if (map.getLayer('route'))           { map.removeLayer('route'); }
      if (map.getLayer('route-outline'))   { map.removeLayer('route-outline'); }
      if (map.getLayer('route-repaired'))  { map.removeLayer('route-repaired'); }
      if (map.getLayer('route-endpoints')) { map.removeLayer('route-endpoints'); }
      if (map.getSource('route'))          { map.removeSource('route'); }
      if (map.getSource('route-repaired')) { map.removeSource('route-repaired'); }
      if (map.getSource('route-endpoints')){ map.removeSource('route-endpoints'); }

      // Build per-segment coloured lines, skipping gaps so no fake straight
      // connectors are drawn. Each kept span is its own 2-point LineString.
      var segFeatures = [];
      for (var i = 1; i < _routeData.length; i++) {
        var a = _routeData[i - 1], b = _routeData[i];
        var dtMs  = Math.abs((b[3] || 0) - (a[3] || 0));
        var distM = _haversineKm(a[1], a[0], b[1], b[0]) * 1000; // shared helper (torquehelpers.js)
        if (dtMs > GAP_TIME_MS || distM > GAP_DIST_M) continue; // dropout — leave a gap
        segFeatures.push({
          type: 'Feature',
          properties: { color: _speedColor(b[2] || 0) },
          geometry: { type: 'LineString', coordinates: [[a[0], a[1]], [b[0], b[1]]] }
        });
      }

      map.addSource('route', {
        type: 'geojson',
        data: { type: 'FeatureCollection', features: segFeatures }
      });
      map.addLayer({
        id: 'route-outline', type: 'line', source: 'route',
        layout: { 'line-join': 'round', 'line-cap': 'round' },
        paint: { 'line-width': _lineWeight + 3, 'line-color': '#111',
                 'line-opacity': Math.min(1, _lineOpacity + 0.2) }
      });
      map.addLayer({
        id: 'route', type: 'line', source: 'route',
        layout: { 'line-join': 'round', 'line-cap': 'round' },
        paint: { 'line-width': _lineWeight, 'line-opacity': _lineOpacity, 'line-color': ['get', 'color'] }
      });
      map.fitBounds(bounds, { padding: 50, maxZoom: 17, duration: 0 });

      // Mark GPS points repaired from an external source (e.g. Home Assistant).
      // _routeData[i][4] carries the source: 'torque' (raw) or 'home_assistant'.
      var repairedFeatures = [];
      for (var ri = 0; ri < _routeData.length; ri++) {
        var rp = _routeData[ri];
        if (rp.length >= 5 && rp[4] && rp[4] !== 'torque') {
          repairedFeatures.push({
            type: 'Feature',
            geometry: { type: 'Point', coordinates: [rp[0], rp[1]] },
            properties: { source: rp[4] }
          });
        }
      }
      window._routeRepairedCount = repairedFeatures.length;
      if (repairedFeatures.length) {
        map.addSource('route-repaired', {
          type: 'geojson',
          data: { type: 'FeatureCollection', features: repairedFeatures }
        });
        map.addLayer({
          id: 'route-repaired', type: 'circle', source: 'route-repaired',
          paint: {
            'circle-radius': Math.max(3, _lineWeight),
            'circle-color': '#ff9500',
            'circle-opacity': 0.9,
            'circle-stroke-width': 1.5,
            'circle-stroke-color': '#fff'
          }
        });
      }

      // Start (green) and Finish (red) markers at the first/last route points.
      var _first = _routeData[0], _last = _routeData[_routeData.length - 1];
      if (_first && _last) {
        map.addSource('route-endpoints', {
          type: 'geojson',
          data: { type: 'FeatureCollection', features: [
            { type: 'Feature', properties: { kind: 'start' }, geometry: { type: 'Point', coordinates: [_first[0], _first[1]] } },
            { type: 'Feature', properties: { kind: 'end'   }, geometry: { type: 'Point', coordinates: [_last[0],  _last[1]]  } }
          ] }
        });
        map.addLayer({
          id: 'route-endpoints', type: 'circle', source: 'route-endpoints',
          paint: {
            'circle-radius': Math.max(6, _lineWeight + 3),
            'circle-color': ['match', ['get', 'kind'], 'start', '#22c55e', 'end', '#ef4444', '#888'],
            'circle-stroke-width': 2.5,
            'circle-stroke-color': '#fff'
          }
        });
      }

      var _oldLegend = mapEl.querySelector('.torque-speed-legend');
      if (_oldLegend) { _oldLegend.remove(); }
      var legend = document.createElement('div');
      legend.className = 'torque-speed-legend';
      legend.innerHTML =
        '<strong>Speed</strong><br>' +
        '<span style="background:linear-gradient(90deg,hsl(240,100%,45%),hsl(120,100%,45%),hsl(60,100%,45%),hsl(0,100%,45%));' +
        'display:block;width:110px;height:8px;border-radius:4px;margin:3px 0;"></span>' +
        '<div style="display:flex;justify-content:space-between;width:110px;">' +
        '<span>0</span><span>' + Math.round(_maxSpeed / 2) + '</span>' +
        '<span>' + Math.round(_maxSpeed) + ' km/h</span></div>' +
        (window._routeRepairedCount
          ? '<div style="display:flex;align-items:center;gap:6px;margin-top:6px;">' +
            '<span style="display:inline-block;width:10px;height:10px;border-radius:50%;' +
            'background:#ff9500;border:1.5px solid #fff;flex-shrink:0;"></span>' +
            '<span>Repaired GPS (' + window._routeRepairedCount + ')</span></div>'
          : '') +
        '<div style="display:flex;align-items:center;gap:6px;margin-top:6px;">' +
        '<span style="display:inline-block;width:10px;height:10px;border-radius:50%;' +
        'background:#22c55e;border:1.5px solid #fff;flex-shrink:0;"></span><span>Start</span>' +
        '<span style="display:inline-block;width:10px;height:10px;border-radius:50%;margin-left:8px;' +
        'background:#ef4444;border:1.5px solid #fff;flex-shrink:0;"></span><span>Finish</span></div>';
      mapEl.appendChild(legend);
    } catch(e) { console.error('Route draw error:', e); }
  };

  // ── Multi-session overlay ─────────────────────────────────────────────────
  window._torqueDrawMulti = function(m) {
    var params = new URLSearchParams(window.location.search);
    var mp     = params.get('multi');
    if (!mp) return;
    var sids = mp.split(',').map(Number).filter(function(n) { return Number.isFinite(n) && n > 0; });
    if (!sids.length) return;

    var COLORS = ['#e63946','#2a9d8f','#f4a261','#9b5de5','#00b4d8','#fb8500','#8ecae6'];

    var legend = document.getElementById('torque-session-legend');
    if (!legend && mapEl) {
      legend = document.createElement('div');
      legend.id = 'torque-session-legend';
      legend.style.cssText =
        'position:absolute;top:50px;left:10px;z-index:5;background:rgba(255,255,255,0.88);' +
        'backdrop-filter:blur(4px);border-radius:8px;padding:7px 10px;font-size:11px;' +
        'box-shadow:0 2px 8px rgba(0,0,0,0.18);max-width:200px;';
      mapEl.appendChild(legend);
    }
    if (legend) {
      var primarySid = new URLSearchParams(window.location.search).get('id') || '';
      var d = primarySid ? new Date(parseInt(primarySid, 10)) : null;
      var primaryLabel = (d && !isNaN(d))
        ? d.toLocaleDateString() + ' ' + d.toLocaleTimeString([], {hour:'2-digit',minute:'2-digit'})
        : 'Primary session';

      // Build with DOM APIs so no user-supplied value reaches innerHTML
      while (legend.firstChild) legend.removeChild(legend.firstChild);
      var hdr = document.createElement('div');
      hdr.style.cssText = 'font-weight:700;margin-bottom:4px;color:#333;';
      hdr.textContent = 'Sessions';
      legend.appendChild(hdr);

      function _legendRow(bg, label) {
        var row    = document.createElement('div');
        row.style.cssText = 'display:flex;align-items:center;gap:6px;margin-bottom:3px;';
        var swatch = document.createElement('span');
        swatch.style.cssText = 'display:inline-block;width:22px;height:4px;border-radius:2px;flex-shrink:0;';
        swatch.style.background = bg;
        var lbl    = document.createElement('span');
        lbl.style.color = '#333';
        lbl.textContent = label;
        row.appendChild(swatch);
        row.appendChild(lbl);
        legend.appendChild(row);
      }

      _legendRow('linear-gradient(90deg,hsl(240,100%,45%),hsl(0,100%,45%))', primaryLabel);
      var colorIdx = 0;
      sids.forEach(function(sid2) {
        var ci  = colorIdx++;
        var d2  = new Date(sid2);
        var lbl = !isNaN(d2)
          ? d2.toLocaleDateString() + ' ' + d2.toLocaleTimeString([], {hour:'2-digit',minute:'2-digit'})
          : 'Session ' + (ci + 1);
        _legendRow(COLORS[ci % COLORS.length], lbl);
      });
    }

    sids.forEach(function(sid, idx) {
      var color = COLORS[idx % COLORS.length];
      var srcId = 'multi-route-' + sid;
      try {
        if (m.getLayer(srcId + '-line'))    m.removeLayer(srcId + '-line');
        if (m.getLayer(srcId + '-outline')) m.removeLayer(srcId + '-outline');
        if (m.getSource(srcId))             m.removeSource(srcId);
      } catch(e2) {}

      fetch('get_session_gps.php?sid=' + encodeURIComponent(sid))
        .then(function(r) { return r.json(); })
        .then(function(pts) {
          if (!Array.isArray(pts) || !pts.length) return;
          var valid = pts.filter(function(p) {
            return p[0] >= -180 && p[0] <= 180 && p[1] >= -90 && p[1] <= 90
                && !(p[0] === 0 && p[1] === 0);
          });
          if (!valid.length) return;
          try {
            m.addSource(srcId, { type: 'geojson',
              data: { type: 'Feature', geometry: { type: 'LineString', coordinates: valid } } });
            m.addLayer({ id: srcId + '-outline', type: 'line', source: srcId,
              layout: { 'line-join': 'round', 'line-cap': 'round' },
              paint: { 'line-width': _lineWeight + 3, 'line-color': '#111',
                       'line-opacity': Math.min(1, _lineOpacity + 0.2) } });
            m.addLayer({ id: srcId + '-line', type: 'line', source: srcId,
              layout: { 'line-join': 'round', 'line-cap': 'round' },
              paint: { 'line-width': _lineWeight, 'line-color': color, 'line-opacity': _lineOpacity } });
          } catch(e3) { console.warn('[Torque] Multi-session layer error:', sid, e3); }
        })
        .catch(function(e) { console.warn('[Torque] Multi-session GPS fetch failed:', sid, e); });
    });
  };

  if (map.loaded()) {
    window._torqueDrawRoute();
    window._torqueDrawMulti(map);
  } else {
    map.once('load', function() {
      window._torqueDrawRoute();
      window._torqueDrawMulti(map);
    });
  }
});

// ── Chart setup ───────────────────────────────────────────────────────────────
if (typeof Chart !== 'undefined') {
  // Map-hover crosshair plugin
  window._mapHoverTs = null;
  Chart.register({
    id: 'mapCrosshair',
    afterDraw: function(chart) {
      if (window._mapHoverTs == null) return;
      var scale = chart.scales.x;
      if (!scale) return;
      var x  = scale.getPixelForValue(window._mapHoverTs);
      var ca = chart.chartArea;
      if (x < ca.left || x > ca.right) return;
      var ctx2 = chart.ctx;
      ctx2.save();
      ctx2.beginPath();
      ctx2.moveTo(x, ca.top);
      ctx2.lineTo(x, ca.bottom);
      ctx2.lineWidth   = 1.5;
      ctx2.strokeStyle = 'rgba(239,68,68,0.75)';
      ctx2.setLineDash([5, 3]);
      ctx2.stroke();
      ctx2.restore();
    }
  });

  window.addEventListener('load', function() {
    _applyChartTheme(localStorage.getItem('torque-theme') === 'dark');

    if (torqueDatasets.length > 0) {
      var ctx = document.getElementById('chartCanvas');
      if (!ctx) return;
      window.torqueChart = new Chart(ctx.getContext('2d'), {
        type: 'line',
        data: { datasets: torqueDatasets },
        options: {
          responsive: true,
          maintainAspectRatio: false,
          interaction: { mode: 'index', intersect: false },
          scales: {
            x: {
              type: 'time',
              time: {
                displayFormats: { hour: 'h:mm a', minute: 'h:mm a', second: 'h:mm:ss a' },
                tooltipFormat:  'MM/dd/yyyy h:mm:ss a'
              },
              title: { display: true, text: 'Time' }
            },
            y: { title: { display: false } }
          },
          plugins: {
            legend:  { position: 'top', labels: { usePointStyle: true, padding: 15 } },
            tooltip: { mode: 'index', intersect: false },
            zoom: {
              pan:  { enabled: true, mode: 'x' },
              zoom: {
                wheel: { enabled: true },
                pinch: { enabled: true },
                drag:  { enabled: true, backgroundColor: 'rgba(13,110,253,0.12)' },
                mode:  'x'
              }
            }
          }
        }
      });
      setTimeout(_initGauges, 100);
    }
  });
}

// ── HUD init on load ──────────────────────────────────────────────────────────
if (!_noSession && _hudConfig) {
  window.addEventListener('load', function() {
    if (typeof _initGauges === 'function') _initGauges();
  });
}

// ── Chart ↔ Map crosshair (only when session has GPS) ────────────────────────
if (!_noSession && _hasGPS) {
  function _gpsPointAtTime(tsMs) {
    if (!_routeData || !_routeData.length || _routeData[0].length < 4) return null;
    var lo = 0, hi = _routeData.length - 1;
    while (lo < hi) {
      var mid = (lo + hi) >> 1;
      if (_routeData[mid][3] < tsMs) lo = mid + 1; else hi = mid;
    }
    if (lo > 0 && Math.abs(_routeData[lo-1][3] - tsMs) < Math.abs(_routeData[lo][3] - tsMs)) lo--;
    return _routeData[lo];
  }

  function _nearestGpsPoint(lng, lat) {
    if (!_routeData || !_routeData.length || _routeData[0].length < 4) return null;
    var best = null, bestD = Infinity;
    for (var i = 0; i < _routeData.length; i++) {
      var p = _routeData[i];
      var dx = p[0] - lng, dy = p[1] - lat;
      var d  = dx * dx + dy * dy;
      if (d < bestD) { bestD = d; best = p; }
    }
    return best;
  }

  function _chartValueAtTime(data, tsMs) {
    if (!data || !data.length) return null;
    var best = null, bestDiff = Infinity;
    for (var i = 0; i < data.length; i++) {
      var diff = Math.abs(data[i].x - tsMs);
      if (diff < bestDiff) { bestDiff = diff; best = data[i].y; }
    }
    return best;
  }

  function _buildMapPopupHTML(tsMs, source) {
    var d       = new Date(tsMs);
    var timeStr = d.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit', second: '2-digit' });
    var html    = '<div style="line-height:1.6;min-width:160px;">';
    html += '<div class="tcp-time">' + timeStr + '</div>';
    if (source && source !== 'torque') {
      var srcLabel = (source === 'home_assistant') ? 'Home Assistant' : source;
      html += '<div class="tcp-repaired" style="display:flex;align-items:center;gap:5px;' +
              'margin:2px 0 4px;color:#ff9500;font-size:11px;font-weight:600;">' +
              '<span style="display:inline-block;width:8px;height:8px;border-radius:50%;' +
              'background:#ff9500;border:1px solid #fff;flex-shrink:0;"></span>' +
              'GPS repaired · ' + srcLabel + '</div>';
    }
    if (window.torqueChart) {
      var datasets = window.torqueChart.data.datasets;
      for (var i = 0; i < datasets.length; i++) {
        var ds  = datasets[i];
        var val = _chartValueAtTime(ds.data, tsMs);
        if (val === null) continue;
        var col     = (typeof ds.borderColor === 'string') ? ds.borderColor : '#0d6efd';
        var rounded = Math.round(val * 10) / 10;
        html += '<div class="tcp-row">';
        html += '<span class="tcp-dot" style="background:' + col + ';"></span>';
        html += '<span class="tcp-label">' + ds.label + '</span>';
        html += '<span class="tcp-value">' + rounded + '</span>';
        html += '</div>';
      }
    } else {
      html += '<div class="tcp-hint">Plot variables to see values here</div>';
    }
    html += '</div>';
    return html;
  }

  var _mapDotEl = (function() {
    var el = document.createElement('div');
    el.className = 'hud-map-dot';
    el.style.cssText = 'width:12px;height:12px;border-radius:50%;pointer-events:none;display:none;';
    return el;
  })();
  var _mapDotMarker = null;

  function _showMapDot(tsMs) {
    var pt = _gpsPointAtTime(tsMs);
    if (!pt || !window._torqueMap) return;
    if (!_mapDotMarker) {
      _mapDotMarker = new mapboxgl.Marker({ element: _mapDotEl, anchor: 'center' })
        .setLngLat([pt[0], pt[1]]).addTo(window._torqueMap);
    } else {
      _mapDotMarker.setLngLat([pt[0], pt[1]]);
    }
    _mapDotEl.style.display = 'block';
  }
  function _hideMapDot() { _mapDotEl.style.display = 'none'; }

  window.addEventListener('load', function() {
    var canvas = document.getElementById('chartCanvas');
    if (canvas) {
      canvas.addEventListener('mousemove', function(e) {
        if (!window.torqueChart) return;
        var pts = window.torqueChart.getElementsAtEventForMode(e, 'index', { intersect: false }, true);
        if (pts.length) {
          var tsMs = window.torqueChart.data.datasets[0].data[pts[0].index].x;
          _showMapDot(tsMs);
          _updateGauges(tsMs);
        } else {
          _hideMapDot();
        }
      });
      canvas.addEventListener('mouseleave', function() {
        _hideMapDot();
        _initGauges();
      });
    }

    function _waitForMap(cb) {
      if (window._torqueMap) {
        if (window._torqueMap.loaded()) { cb(window._torqueMap); }
        else { window._torqueMap.once('load', function() { cb(window._torqueMap); }); }
      } else {
        var t = setInterval(function() {
          if (window._torqueMap) { clearInterval(t); _waitForMap(cb); }
        }, 150);
      }
    }

    _waitForMap(function(map) {
      var popup = new mapboxgl.Popup({
        closeButton: false, closeOnClick: false,
        maxWidth: '260px', className: 'torque-crosshair-popup'
      });

      function _applyPopupTheme() {
        var el = popup.getElement ? popup.getElement() : null;
        if (!el) return;
        var content = el.querySelector('.mapboxgl-popup-content');
        var tip     = el.querySelector('.mapboxgl-popup-tip');
        if (tip) tip.style.display = 'none';
        if (!content) return;
        content.style.padding       = '8px 12px';
        content.style.borderRadius  = '8px';
        content.style.fontFamily    = 'inherit';
        content.style.fontSize      = '12px';
        content.style.boxShadow     = '0 4px 18px rgba(0,0,0,0.22)';
        content.style.pointerEvents = 'none';
        content.style.background    = 'rgba(6, 9, 18, 0.92)';
        content.style.color         = '#8ab';
        content.style.border        = '1px solid rgba(0, 212, 255, 0.22)';
      }

      new MutationObserver(function() {
        if (popup.isOpen()) _applyPopupTheme();
      }).observe(document.documentElement, { attributes: true, attributeFilter: ['data-bs-theme'] });

      map.on('mousemove', 'route', function(e) {
        map.getCanvas().style.cursor = 'crosshair';
        var pt = _nearestGpsPoint(e.lngLat.lng, e.lngLat.lat);
        if (!pt) { popup.remove(); return; }
        popup.setLngLat([pt[0], pt[1]]).setHTML(_buildMapPopupHTML(pt[3], pt[4])).addTo(map);
        _applyPopupTheme();
        window._mapHoverTs = pt[3];
        if (window.torqueChart) window.torqueChart.draw();
        if (typeof _updateGauges === 'function') _updateGauges(pt[3]);
      });
      map.on('mouseleave', 'route', function() {
        map.getCanvas().style.cursor = '';
        popup.remove();
        window._mapHoverTs = null;
        if (window.torqueChart) window.torqueChart.draw();
        if (typeof _initGauges === 'function') _initGauges();
      });
    });
  });
}
