# HUD Frontend Redesign — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Transform `session.php`'s frontend into a Dark Racing HUD with animated SVG arc gauges, neon accents, and fully restyled panels — zero backend or PHP logic changes.

**Architecture:** A new `static/css/hud.css` loaded after `torque.css` overrides all visuals via CSS custom properties. A new JS section in `torquehelpers.js` drives animated SVG arc gauges and Haversine distance. The HUD widget HTML is injected into `session.php`. Chart.js dataset colours are set to neon values. All other PHP, DB, and core JS logic is unchanged.

**Tech Stack:** CSS custom properties, SVG `stroke-dashoffset` animation, Chart.js 4.4 (existing), Mapbox GL JS (existing), Peity.js (existing), Tom Select 2.3 (existing), PHP 8.2 (no logic changes)

---

## File Map

| File | Action | Responsibility |
|------|--------|---------------|
| `static/css/hud.css` | **Create** | All HUD visual overrides — tokens, navbar, widget, panels, chart, map elements, scrollbars |
| `static/js/torquehelpers.js` | **Modify** | Add `_haversineKm`, `_initGauges`, `_updateGauges`, `_findDatasetByKeyword`, `_computeFuelAvg` |
| `session.php` | **Modify** | Add `hud.css` link; HUD widget HTML; update navbar brand; update `torqueToggle` for transitions; update `toggleDarkMode`; assign neon chart colours |

`torque.css` — **not touched**.

---

### Task 1: Create `hud.css` with design tokens and base styles

**Files:**
- Create: `static/css/hud.css`
- Modify: `session.php` (add one `<link>` line)

- [ ] **Step 1: Create `static/css/hud.css`**

```css
/* ═══════════════════════════════════════════════════════════════
   Open Torque Viewer — Dark Racing HUD Theme
   Loaded after torque.css. All overrides live here.
   ═══════════════════════════════════════════════════════════════ */

/* ── Design tokens ── */
:root {
  --hud-bg:        #060912;
  --hud-bg-map:    #0a0e1a;
  --hud-cyan:      #00d4ff;
  --hud-red:       #ff6b6b;
  --hud-green:     #00ff88;
  --hud-border:    rgba(0, 212, 255, 0.2);
  --hud-glow-cyan: 0 0 12px rgba(0, 212, 255, 0.4);
  --navbar-height: 46px;
}

/* ── Base ── */
html, body { background: var(--hud-bg-map) !important; }

/* Light mode = de-saturated dark (no white theme for session.php) */
[data-bs-theme="light"] {
  --hud-cyan:   rgba(0, 180, 210, 0.65);
  --hud-green:  rgba(0, 180, 100, 0.65);
  --hud-red:    rgba(210, 80, 80, 0.65);
  --hud-border: rgba(0, 212, 255, 0.12);
  --hud-glow-cyan: 0 0 8px rgba(0, 212, 255, 0.2);
}
[data-bs-theme="light"] body { background: #0d1117 !important; }
```

- [ ] **Step 2: Add `hud.css` link in `session.php`**

In `session.php`, find:
```html
    <link rel="stylesheet" href="static/css/themes.css">
```
Add immediately after it:
```html
    <link rel="stylesheet" href="static/css/hud.css">
```

- [ ] **Step 3: Verify PHP lint**

```bash
php -l session.php
```
Expected: `No syntax errors detected in session.php`

- [ ] **Step 4: Commit**

```bash
git add static/css/hud.css session.php
git commit -m "feat: create hud.css with design tokens and base styles"
```

---

### Task 2: Restyle the navbar

**Files:**
- Modify: `static/css/hud.css` (append navbar rules)
- Modify: `session.php` (brand text + remove inline min-height)

- [ ] **Step 1: Update navbar brand in `session.php`**

Find (around line 831):
```html
        <a class="navbar-brand flex-shrink-0" href="session.php">Open Torque Viewer</a>
```
Replace with:
```html
        <a class="navbar-brand flex-shrink-0 hud-brand" href="session.php">⬡&nbsp;TORQUE</a>
```

Find (around line 827):
```html
    <nav class="navbar navbar-dark bg-dark fixed-top" style="min-height:58px;">
```
Replace with:
```html
    <nav class="navbar navbar-dark bg-dark fixed-top hud-navbar">
```

- [ ] **Step 2: Append navbar CSS to `hud.css`**

```css
/* ── Navbar ── */
.hud-navbar {
  background: var(--hud-bg) !important;
  border-bottom: 1px solid var(--hud-border);
  min-height: var(--navbar-height) !important;
  padding-top: 0;
  padding-bottom: 0;
}

.hud-brand {
  color: var(--hud-cyan) !important;
  font-size: 13px !important;
  font-weight: 800 !important;
  letter-spacing: 3px !important;
  text-transform: uppercase !important;
  text-shadow: 0 0 12px rgba(0, 212, 255, 0.6) !important;
}
.hud-brand:hover { color: #fff !important; text-shadow: 0 0 16px rgba(0,212,255,0.9) !important; }

/* Navbar filter selects — override torque.css */
.hud-navbar .navbar-filter {
  background: rgba(0, 212, 255, 0.06) !important;
  border-color: var(--hud-border) !important;
  color: var(--hud-cyan) !important;
  font-size: 10px;
  letter-spacing: 0.5px;
}
.hud-navbar .navbar-filter option { background: var(--hud-bg); color: #ccc; }

/* Icon buttons */
.hud-navbar .btn-outline-light {
  border-color: rgba(0, 212, 255, 0.22) !important;
  color: #445 !important;
  width: 28px; height: 28px;
  padding: 0;
  display: inline-flex; align-items: center; justify-content: center;
  font-size: 12px;
  transition: border-color 0.15s, color 0.15s, box-shadow 0.15s;
}
.hud-navbar .btn-outline-light:hover,
.hud-navbar .btn-outline-light.active {
  border-color: var(--hud-cyan) !important;
  color: var(--hud-cyan) !important;
  background: rgba(0, 212, 255, 0.06) !important;
  box-shadow: var(--hud-glow-cyan) !important;
}

/* Merge button */
.hud-navbar .btn-outline-primary {
  border-color: rgba(0, 212, 255, 0.35) !important;
  color: var(--hud-cyan) !important;
  width: 28px; height: 28px; padding: 0;
  display: inline-flex; align-items: center; justify-content: center;
  font-size: 12px;
}
.hud-navbar .btn-outline-primary:hover {
  background: rgba(0, 212, 255, 0.12) !important;
}

/* Delete button */
.hud-navbar .btn-outline-danger {
  border-color: rgba(255, 107, 107, 0.4) !important;
  color: var(--hud-red) !important;
  width: 28px; height: 28px; padding: 0;
  display: inline-flex; align-items: center; justify-content: center;
  font-size: 12px;
}
.hud-navbar .btn-outline-danger:hover {
  background: rgba(255, 107, 107, 0.12) !important;
}

/* Navbar user text */
.hud-navbar .navbar-user { color: #445 !important; font-size: 10px; }

/* Adjust panel default top positions for new navbar height */
#vars-section    { top: calc(var(--navbar-height) + 10px); }
#summary-section { top: calc(var(--navbar-height) + 10px); }
#cal-panel       { top: var(--navbar-height); }
#ai-section      { top: calc(var(--navbar-height) + 8px); }
```

- [ ] **Step 3: Verify PHP lint**

```bash
php -l session.php
```
Expected: `No syntax errors detected in session.php`

- [ ] **Step 4: Commit**

```bash
git add static/css/hud.css session.php
git commit -m "feat: restyle navbar with HUD dark theme and neon brand"
```

---

### Task 3: Inject HUD widget HTML into `session.php`

**Files:**
- Modify: `session.php` (add HUD widget div)

- [ ] **Step 1: Add HUD widget HTML after `<div id="map-canvas"></div>`**

In `session.php`, find:
```html
    <!-- Full-screen map canvas (sized by CSS) -->
    <div id="map-canvas"></div>
```
Replace with:
```html
    <!-- Full-screen map canvas (sized by CSS) -->
    <div id="map-canvas"></div>

<?php if ($setZoomManually === 0): ?>
    <!-- ── HUD Widget — live arc gauges pinned to map ── -->
    <div id="hud-widget">
      <div class="hud-gauges">

        <div class="hud-gauge-wrap">
          <svg width="70" height="50" viewBox="0 0 70 50" class="hud-gauge-svg">
            <path d="M 8 46 A 30 30 0 0 1 62 46" class="hud-gauge-track"/>
            <path d="M 8 46 A 30 30 0 0 1 62 46"
                  class="hud-gauge-arc hud-gauge-arc--cyan"
                  id="hud-gauge-rpm"
                  stroke="#00d4ff"
                  stroke-dasharray="94"
                  stroke-dashoffset="94"/>
            <text x="35" y="38" class="hud-gauge-val" id="hud-gauge-rpm-val">—</text>
          </svg>
          <div class="hud-gauge-label">RPM</div>
        </div>

        <div class="hud-gauge-wrap">
          <svg width="70" height="50" viewBox="0 0 70 50" class="hud-gauge-svg">
            <path d="M 8 46 A 30 30 0 0 1 62 46" class="hud-gauge-track"/>
            <path d="M 8 46 A 30 30 0 0 1 62 46"
                  class="hud-gauge-arc hud-gauge-arc--red"
                  id="hud-gauge-coolant"
                  stroke="#ff6b6b"
                  stroke-dasharray="94"
                  stroke-dashoffset="94"/>
            <text x="35" y="38" class="hud-gauge-val" id="hud-gauge-coolant-val">—</text>
          </svg>
          <div class="hud-gauge-label">COOLANT</div>
        </div>

        <div class="hud-gauge-wrap">
          <svg width="70" height="50" viewBox="0 0 70 50" class="hud-gauge-svg">
            <path d="M 8 46 A 30 30 0 0 1 62 46" class="hud-gauge-track"/>
            <path d="M 8 46 A 30 30 0 0 1 62 46"
                  class="hud-gauge-arc hud-gauge-arc--green"
                  id="hud-gauge-speed"
                  stroke="#00ff88"
                  stroke-dasharray="94"
                  stroke-dashoffset="94"/>
            <text x="35" y="38" class="hud-gauge-val" id="hud-gauge-speed-val">—</text>
          </svg>
          <div class="hud-gauge-label">km/h</div>
        </div>

      </div>
      <div class="hud-stats">
        <div class="hud-stat">
          <div class="hud-stat-val hud-stat-val--cyan" id="hud-stat-dur">—</div>
          <div class="hud-stat-label">DURATION</div>
        </div>
        <div class="hud-stat">
          <div class="hud-stat-val" id="hud-stat-dist">—</div>
          <div class="hud-stat-label">DISTANCE</div>
        </div>
        <div class="hud-stat">
          <div class="hud-stat-val hud-stat-val--green" id="hud-stat-fuel">—</div>
          <div class="hud-stat-label">L/100km</div>
        </div>
      </div>
    </div>
<?php endif; ?>
```

- [ ] **Step 2: Verify PHP lint**

```bash
php -l session.php
```
Expected: `No syntax errors detected in session.php`

- [ ] **Step 3: Commit**

```bash
git add session.php
git commit -m "feat: inject HUD widget HTML into session.php"
```

---

### Task 4: HUD widget CSS (container, gauges, stats)

**Files:**
- Modify: `static/css/hud.css` (append HUD widget rules)

- [ ] **Step 1: Append HUD widget CSS to `hud.css`**

```css
/* ── HUD Widget ── */
#hud-widget {
  position: fixed;
  top: calc(var(--navbar-height) + 12px);
  left: 12px;
  z-index: 10;
  background: rgba(6, 9, 18, 0.82);
  border: 1px solid rgba(0, 212, 255, 0.22);
  border-radius: 10px;
  backdrop-filter: blur(8px);
  -webkit-backdrop-filter: blur(8px);
  box-shadow: 0 0 24px rgba(0, 212, 255, 0.08), 0 4px 20px rgba(0, 0, 0, 0.6);
  padding: 12px 14px;
  min-width: 224px;
  pointer-events: none;
  user-select: none;
}

.hud-gauges {
  display: flex;
  gap: 8px;
  align-items: flex-end;
  margin-bottom: 10px;
}

.hud-gauge-wrap { text-align: center; }

.hud-gauge-svg { display: block; overflow: visible; }

.hud-gauge-track {
  fill: none;
  stroke: rgba(255, 255, 255, 0.07);
  stroke-width: 5;
  stroke-linecap: round;
}

.hud-gauge-arc {
  fill: none;
  stroke-width: 5;
  stroke-linecap: round;
  transition: stroke-dashoffset 0.25s ease-out, stroke 0.2s ease;
}
.hud-gauge-arc--cyan  { filter: drop-shadow(0 0 4px #00d4ff); }
.hud-gauge-arc--red   { filter: drop-shadow(0 0 4px #ff6b6b); }
.hud-gauge-arc--green { filter: drop-shadow(0 0 4px #00ff88); }

.hud-gauge-val {
  fill: currentColor;
  font-size: 11px;
  font-weight: 700;
  font-family: 'Courier New', monospace;
  text-anchor: middle;
  dominant-baseline: auto;
}
.hud-gauge-arc--cyan  ~ .hud-gauge-val,
svg:has(.hud-gauge-arc--cyan) .hud-gauge-val  { fill: #00d4ff; }
svg:has(.hud-gauge-arc--red) .hud-gauge-val   { fill: #ff6b6b; }
svg:has(.hud-gauge-arc--green) .hud-gauge-val { fill: #00ff88; }

.hud-gauge-label {
  color: rgba(100, 120, 160, 0.7);
  font-size: 8px;
  letter-spacing: 1px;
  margin-top: -2px;
  font-family: 'Courier New', monospace;
}

/* Stats row */
.hud-stats {
  display: flex;
  justify-content: space-between;
  border-top: 1px solid rgba(0, 212, 255, 0.1);
  padding-top: 8px;
  gap: 6px;
}

.hud-stat { text-align: center; flex: 1; }

.hud-stat-val {
  color: #8ab;
  font-size: 10px;
  font-weight: 600;
  font-family: 'Courier New', monospace;
  letter-spacing: 0.5px;
}
.hud-stat-val--cyan  { color: var(--hud-cyan); }
.hud-stat-val--green { color: var(--hud-green); }

.hud-stat-label {
  color: rgba(60, 80, 100, 0.8);
  font-size: 7px;
  letter-spacing: 1px;
  margin-top: 1px;
  font-family: 'Courier New', monospace;
}
```

- [ ] **Step 2: Commit**

```bash
git add static/css/hud.css
git commit -m "feat: add HUD widget CSS — container, arc gauges, stats row"
```

---

### Task 5: Gauge JS — `_initGauges`, `_updateGauges`, Haversine distance

**Files:**
- Modify: `static/js/torquehelpers.js` (append gauge functions)
- Modify: `session.php` (wire `_initGauges` and `_updateGauges` into chart events)

- [ ] **Step 1: Append gauge JS to `torquehelpers.js`**

Add at the end of `static/js/torquehelpers.js`:

```js
// ══════════════════════════════════════════════════════════════════
// HUD Gauge System
// ══════════════════════════════════════════════════════════════════

// Haversine distance in km between two lat/lon points
function _haversineKm(lat1, lon1, lat2, lon2) {
  var R = 6371;
  var dLat = (lat2 - lat1) * Math.PI / 180;
  var dLon = (lon2 - lon1) * Math.PI / 180;
  var a = Math.sin(dLat / 2) * Math.sin(dLat / 2) +
          Math.cos(lat1 * Math.PI / 180) * Math.cos(lat2 * Math.PI / 180) *
          Math.sin(dLon / 2) * Math.sin(dLon / 2);
  return R * 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1 - a));
}

// Find first Chart.js dataset whose label contains any of the given keywords (case-insensitive)
function _findDatasetByKeyword() {
  var keywords = Array.prototype.slice.call(arguments);
  if (!window.torqueChart) return null;
  var ds = window.torqueChart.data.datasets;
  for (var i = 0; i < ds.length; i++) {
    var lbl = (ds[i].label || '').toLowerCase();
    for (var k = 0; k < keywords.length; k++) {
      if (lbl.indexOf(keywords[k]) !== -1) return ds[i];
    }
  }
  return null;
}

// Mean of a Chart.js dataset's y values
function _datasetMean(ds) {
  if (!ds || !ds.data || !ds.data.length) return null;
  var sum = 0;
  for (var i = 0; i < ds.data.length; i++) sum += ds.data[i].y;
  return sum / ds.data.length;
}

// Set a gauge arc's fill fraction (0–1) and update label text
function _setGauge(arcId, valId, fraction, text) {
  var arc = document.getElementById(arcId);
  var val = document.getElementById(valId);
  if (!arc) return;
  var offset = (94 * (1 - Math.max(0, Math.min(1, fraction)))).toFixed(1);
  arc.setAttribute('stroke-dashoffset', offset);
  if (val) val.textContent = text !== undefined ? text : '';
}

// Initialise gauges on page load — populate stats and animate to session averages
function _initGauges() {
  // ── Distance from GPS ──
  var distEl = document.getElementById('hud-stat-dist');
  if (distEl && window._routeData && _routeData.length > 1) {
    var dist = 0;
    for (var i = 1; i < _routeData.length; i++) {
      // _routeData[i] = [lon, lat, speed, ts]
      dist += _haversineKm(_routeData[i - 1][1], _routeData[i - 1][0],
                           _routeData[i][1],     _routeData[i][0]);
    }
    distEl.textContent = dist.toFixed(1) + ' km';
  }

  // ── Duration from GPS timestamps ──
  var durEl = document.getElementById('hud-stat-dur');
  if (durEl && window._routeData && _routeData.length > 1 && _routeData[0].length >= 4) {
    var dur = (_routeData[_routeData.length - 1][3] - _routeData[0][3]) / 60000;
    durEl.textContent = Math.round(dur) + ' min';
  }

  // ── Fuel average from chart data ──
  var fuelEl = document.getElementById('hud-stat-fuel');
  if (fuelEl) {
    var fuelDs = _findDatasetByKeyword('fuel', 'l/100', 'consumption');
    var fuelAvg = _datasetMean(fuelDs);
    fuelEl.textContent = fuelAvg !== null ? fuelAvg.toFixed(1) : '—';
  }

  // ── Animate gauges to session averages ──
  var rpmDs     = _findDatasetByKeyword('rpm');
  var coolantDs = _findDatasetByKeyword('coolant', 'temp');
  var speedDs   = _findDatasetByKeyword('speed', 'km/h');

  var rpmAvg     = _datasetMean(rpmDs);
  var coolantAvg = _datasetMean(coolantDs);
  var speedAvg   = _datasetMean(speedDs);

  if (rpmAvg !== null)     _setGauge('hud-gauge-rpm',     'hud-gauge-rpm-val',     rpmAvg / 8000,                                    Math.round(rpmAvg));
  if (coolantAvg !== null) _setGauge('hud-gauge-coolant', 'hud-gauge-coolant-val', (coolantAvg - 40) / 80,                           Math.round(coolantAvg) + '°');
  if (speedAvg !== null)   _setGauge('hud-gauge-speed',   'hud-gauge-speed-val',   speedAvg / (window._maxSpeed || 120),             Math.round(speedAvg));
}

// Update gauges from a chart timestamp — called on chart mousemove
function _updateGauges(tsMs) {
  var rpmDs     = _findDatasetByKeyword('rpm');
  var coolantDs = _findDatasetByKeyword('coolant', 'temp');
  var speedDs   = _findDatasetByKeyword('speed', 'km/h');

  // RPM
  if (rpmDs) {
    var rpmVal = _chartValueAtTime(rpmDs.data, tsMs); // _chartValueAtTime defined in session.php
    if (rpmVal !== null) _setGauge('hud-gauge-rpm', 'hud-gauge-rpm-val', rpmVal / 8000, Math.round(rpmVal));
  }

  // Coolant — with colour threshold
  if (coolantDs) {
    var cVal = _chartValueAtTime(coolantDs.data, tsMs);
    if (cVal !== null) {
      _setGauge('hud-gauge-coolant', 'hud-gauge-coolant-val', (cVal - 40) / 80, Math.round(cVal) + '°');
      var arc = document.getElementById('hud-gauge-coolant');
      if (arc) {
        var col = cVal > 105 ? '#ff2222' : cVal > 95 ? '#ff9944' : '#ff6b6b';
        arc.setAttribute('stroke', col);
        arc.style.filter = 'drop-shadow(0 0 4px ' + col + ')';
      }
    }
  }

  // Speed
  if (speedDs) {
    var sVal = _chartValueAtTime(speedDs.data, tsMs);
    if (sVal !== null) _setGauge('hud-gauge-speed', 'hud-gauge-speed-val', sVal / (window._maxSpeed || 120), Math.round(sVal));
  }
}
```

- [ ] **Step 2: Wire `_initGauges` and `_updateGauges` into `session.php`**

In `session.php`, find the `window.addEventListener('load', function()` block that creates the Chart (around line 551). After `window.torqueChart = new Chart(ctx, {...});`, add:

```js
        // ── Initialise HUD gauges after chart is ready ──
        setTimeout(_initGauges, 100); // slight delay lets Chart.js finish rendering
```

In `session.php`, find the `chartCanvas` `mousemove` handler (around line 693):
```js
        canvas.addEventListener('mousemove', function(e) {
          if (!window.torqueChart) return;
          var pts = window.torqueChart.getElementsAtEventForMode(e, 'index', { intersect: false }, true);
          if (pts.length) {
            _showMapDot(window.torqueChart.data.datasets[0].data[pts[0].index].x);
          } else {
            _hideMapDot();
          }
        });
```
Replace with:
```js
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
          // Return gauges to session averages
          _initGauges();
        });
```

Note: remove the existing standalone `canvas.addEventListener('mouseleave', _hideMapDot);` line (it is now included above).

- [ ] **Step 3: Verify PHP lint**

```bash
php -l session.php
```
Expected: `No syntax errors detected in session.php`

- [ ] **Step 4: Commit**

```bash
git add static/js/torquehelpers.js session.php
git commit -m "feat: add HUD gauge JS — init, update, haversine distance"
```

---

### Task 6: Chart strip restyling

**Files:**
- Modify: `static/css/hud.css` (append chart rules)
- Modify: `session.php` (add neon dataset colours)

- [ ] **Step 1: Add neon colour arrays before the `torqueDatasets` block in `session.php`**

In `session.php`, find (around line 487):
```js
      var torqueDatasets = [
```
Insert immediately before it:
```js
      var _hudColors     = ['#00d4ff','#ff6b6b','#00ff88','#f4a261','#9b5de5','#00b4d8','#fb8500'];
      var _hudColorsFill = ['rgba(0,212,255,0.08)','rgba(255,107,107,0.08)','rgba(0,255,136,0.06)',
                            'rgba(244,162,97,0.07)','rgba(155,93,229,0.07)','rgba(0,180,216,0.07)','rgba(251,133,0,0.07)'];
      var torqueDatasets = [
```

- [ ] **Step 2: Add `borderColor`, `backgroundColor`, `fill` to each dataset in the PHP loop**

In `session.php`, find the dataset object template inside the PHP while loop. It currently ends with:
```js
          tension: 0.1,
          fill: false
```
Replace `fill: false` with (note the PHP index expression):
```js
          tension: 0.1,
          borderColor: _hudColors[(<?php echo $i-1; ?>) % _hudColors.length],
          backgroundColor: _hudColorsFill[(<?php echo $i-1; ?>) % _hudColorsFill.length],
          fill: true
```

- [ ] **Step 3: Append chart CSS to `hud.css`**

```css
/* ── Chart strip ── */
#chart-section {
  background: var(--hud-bg) !important;
  border-top: 1px solid var(--hud-border) !important;
}

#chart-section .torque-panel-header {
  background: #040810 !important;
  border-bottom: 1px solid rgba(0, 212, 255, 0.1) !important;
}

#chart-section .torque-panel-header h6 {
  color: var(--hud-cyan) !important;
  font-size: 9px !important;
  letter-spacing: 2px !important;
  text-transform: uppercase !important;
}

/* Reset zoom button */
#chart-section .btn-outline-secondary {
  border-color: rgba(0, 212, 255, 0.3) !important;
  color: var(--hud-cyan) !important;
  font-size: 9px;
}
#chart-section .btn-outline-secondary:hover {
  background: rgba(0, 212, 255, 0.08) !important;
}

/* Subtle glow on the chart canvas */
#chartCanvas {
  filter: drop-shadow(0 0 3px rgba(0, 212, 255, 0.2));
}

/* Chart tooltip override — dark glass */
.chartjs-tooltip {
  background: var(--hud-bg) !important;
  border: 1px solid var(--hud-border) !important;
  border-radius: 6px !important;
  color: #ccc !important;
  font-family: 'Courier New', monospace !important;
}
```

- [ ] **Step 4: Verify PHP lint**

```bash
php -l session.php
```
Expected: `No syntax errors detected in session.php`

- [ ] **Step 5: Update `_applyChartTheme` in `session.php` to always use HUD dark colours**

Find `function _applyChartTheme(isDark)` in `session.php`:
```js
      function _applyChartTheme(isDark) {
        if (typeof Chart === 'undefined') return;
        var text = isDark ? '#c8c8d8' : '#555';
        var grid = isDark ? 'rgba(255,255,255,0.1)' : 'rgba(0,0,0,0.08)';
```
Replace with:
```js
      function _applyChartTheme(isDark) {
        if (typeof Chart === 'undefined') return;
        // HUD is always dark — ignore isDark, use consistent dark colours
        var text = '#8ab';
        var grid = 'rgba(0, 212, 255, 0.07)';
```

- [ ] **Step 6: Commit**

```bash
git add static/css/hud.css session.php
git commit -m "feat: restyle chart strip — dark background, neon dataset colours, glow"
```

---

### Task 7: Floating panel base styles + open/close transitions

**Files:**
- Modify: `static/css/hud.css` (append panel base rules)
- Modify: `session.php` (update `torqueToggle` for CSS transitions)

- [ ] **Step 1: Append panel base CSS to `hud.css`**

```css
/* ── Floating panels — base ── */
.torque-panel {
  background: var(--hud-bg) !important;
  border-color: var(--hud-border) !important;
  box-shadow: 0 0 20px rgba(0, 212, 255, 0.06), 0 4px 24px rgba(0, 0, 0, 0.7) !important;
  transition: opacity 0.15s ease, transform 0.15s ease;
}

.torque-panel--hidden {
  opacity: 0 !important;
  transform: translateY(6px) !important;
  pointer-events: none !important;
}

.torque-panel-header {
  background: #07101f !important;
  border-bottom-color: rgba(0, 212, 255, 0.12) !important;
}

.torque-panel-header h6 {
  color: var(--hud-cyan) !important;
  font-size: 10px !important;
  letter-spacing: 2px !important;
  text-transform: uppercase !important;
}

.torque-panel-close { color: var(--hud-cyan) !important; opacity: 0.4 !important; }
.torque-panel-close:hover { opacity: 1 !important; }
```

- [ ] **Step 2: Update `torqueToggle` in `session.php` for CSS transitions**

Find `torqueToggle` in `session.php` (around line 772):
```js
      function torqueToggle(id, btn) {
        var el = document.getElementById(id);
        if (!el) return;
        var hidden = el.style.display === 'none';
        el.style.display = hidden ? '' : 'none';
        if (btn) btn.classList.toggle('active', hidden);
        // Chart panel drives the map-shrink body class
        if (id === 'chart-section') {
          document.body.classList.toggle('chart-open', hidden);
          if (hidden && window.torqueChart) {
            setTimeout(function(){ window.torqueChart.resize(); }, 350);
          }
        }
        // Mapbox always needs resize() after any panel state change
        if (window._torqueMap) {
          setTimeout(function(){ window._torqueMap.resize(); }, 350);
        }
      }
```
Replace with:
```js
      function torqueToggle(id, btn) {
        var el = document.getElementById(id);
        if (!el) return;
        var hidden = el.classList.contains('torque-panel--hidden') || el.style.display === 'none';
        if (hidden) {
          el.style.display = '';
          requestAnimationFrame(function() {
            el.classList.remove('torque-panel--hidden');
          });
        } else {
          el.classList.add('torque-panel--hidden');
          setTimeout(function() { el.style.display = 'none'; }, 150);
        }
        if (btn) btn.classList.toggle('active', hidden);
        // Chart panel drives the map-shrink body class
        if (id === 'chart-section') {
          document.body.classList.toggle('chart-open', hidden);
          if (hidden && window.torqueChart) {
            setTimeout(function(){ window.torqueChart.resize(); }, 350);
          }
        }
        // Mapbox always needs resize() after any panel state change
        if (window._torqueMap) {
          setTimeout(function(){ window._torqueMap.resize(); }, 350);
        }
      }
```

- [ ] **Step 3: Verify PHP lint**

```bash
php -l session.php
```
Expected: `No syntax errors detected in session.php`

- [ ] **Step 4: Commit**

```bash
git add static/css/hud.css session.php
git commit -m "feat: panel base HUD styles and smooth open/close transitions"
```

---

### Task 8: Variables panel — Tom Select chip colours, Plot button

**Files:**
- Modify: `static/css/hud.css` (append variables panel rules)

- [ ] **Step 1: Append variables panel CSS to `hud.css`**

```css
/* ── Variables panel ── */
#vars-section .torque-panel-body { background: var(--hud-bg); }

/* Tom Select control in vars panel */
#vars-section .ts-wrapper .ts-control {
  background: #0a1628 !important;
  border-color: var(--hud-border) !important;
  color: var(--hud-cyan) !important;
  min-height: 60px !important;
}
#vars-section .ts-wrapper .ts-control input {
  color: rgba(0,212,255,0.5) !important;
  background: transparent !important;
}
#vars-section .ts-wrapper .ts-control input::placeholder { color: rgba(0,212,255,0.3); }

/* Chip colours by position — matches chart dataset colour order */
#vars-section .ts-wrapper .ts-control .item { border-radius: 3px !important; font-size: 10px !important; border: 1px solid !important; }
#vars-section .ts-wrapper .ts-control .item:nth-child(1) { color: #00d4ff !important; border-color: #00d4ff !important; background: rgba(0,212,255,0.12) !important; }
#vars-section .ts-wrapper .ts-control .item:nth-child(2) { color: #ff6b6b !important; border-color: #ff6b6b !important; background: rgba(255,107,107,0.12) !important; }
#vars-section .ts-wrapper .ts-control .item:nth-child(3) { color: #00ff88 !important; border-color: #00ff88 !important; background: rgba(0,255,136,0.10) !important; }
#vars-section .ts-wrapper .ts-control .item:nth-child(4) { color: #f4a261 !important; border-color: #f4a261 !important; background: rgba(244,162,97,0.10) !important; }
#vars-section .ts-wrapper .ts-control .item:nth-child(5) { color: #9b5de5 !important; border-color: #9b5de5 !important; background: rgba(155,93,229,0.10) !important; }
#vars-section .ts-wrapper .ts-control .item:nth-child(6) { color: #00b4d8 !important; border-color: #00b4d8 !important; background: rgba(0,180,216,0.10) !important; }

/* Dropdown */
#vars-section .ts-wrapper .ts-dropdown {
  background: #07101f !important;
  border-color: var(--hud-border) !important;
}
#vars-section .ts-wrapper .ts-dropdown .option { color: #8ab; font-size: 12px; }
#vars-section .ts-wrapper .ts-dropdown .option:hover,
#vars-section .ts-wrapper .ts-dropdown .option.active { background: rgba(0,212,255,0.1) !important; color: var(--hud-cyan) !important; }
#vars-section .ts-wrapper .ts-dropdown .option.selected { color: var(--hud-cyan) !important; font-weight: 600; }

/* Plot button */
#vars-section .btn-primary {
  background: var(--hud-cyan) !important;
  border-color: var(--hud-cyan) !important;
  color: #000 !important;
  font-weight: 800 !important;
  letter-spacing: 1px !important;
  font-size: 10px !important;
}
#vars-section .btn-primary:hover { background: #00eeff !important; box-shadow: var(--hud-glow-cyan) !important; }

/* "Show only variables with data" checkbox */
#filterHasData { accent-color: var(--hud-cyan); }
#vars-section .form-check-label { color: #445; font-size: 11px; }
```

- [ ] **Step 2: Commit**

```bash
git add static/css/hud.css
git commit -m "feat: restyle variables panel — neon chip colours and Plot button"
```

---

### Task 9: Data Summary panel

**Files:**
- Modify: `static/css/hud.css` (append summary panel rules)
- Modify: `static/js/torquehelpers.js` (add Peity recolour call)

- [ ] **Step 1: Append Data Summary CSS to `hud.css`**

```css
/* ── Data Summary panel ── */
#summary-section .torque-panel-body { background: var(--hud-bg); padding: 0; }

#summary-section .table {
  color: #8ab !important;
  font-size: 11px;
  margin-bottom: 0;
}
#summary-section .table td,
#summary-section .table th {
  border-color: rgba(0, 212, 255, 0.07) !important;
  padding: 6px 10px;
}
#summary-section thead.table-light,
#summary-section thead.table-light th {
  background: #040810 !important;
  color: rgba(0,212,255,0.5) !important;
  font-size: 9px;
  letter-spacing: 1px;
  border-color: rgba(0,212,255,0.1) !important;
}

/* Odd row subtle stripe */
#summary-section .table tbody tr:nth-child(odd) td { background: rgba(255,255,255,0.015) !important; }
#summary-section .table tbody tr:hover td { background: rgba(0,212,255,0.04) !important; }

/* Coloured glow dot before variable name — via ::before on first td */
#summary-section .table tbody tr:nth-child(1) td:first-child::before { content:''; display:inline-block; width:7px; height:7px; border-radius:50%; background:#00d4ff; box-shadow:0 0 6px #00d4ff; margin-right:7px; vertical-align:middle; }
#summary-section .table tbody tr:nth-child(2) td:first-child::before { content:''; display:inline-block; width:7px; height:7px; border-radius:50%; background:#ff6b6b; box-shadow:0 0 6px #ff6b6b; margin-right:7px; vertical-align:middle; }
#summary-section .table tbody tr:nth-child(3) td:first-child::before { content:''; display:inline-block; width:7px; height:7px; border-radius:50%; background:#00ff88; box-shadow:0 0 6px #00ff88; margin-right:7px; vertical-align:middle; }
#summary-section .table tbody tr:nth-child(4) td:first-child::before { content:''; display:inline-block; width:7px; height:7px; border-radius:50%; background:#f4a261; box-shadow:0 0 6px #f4a261; margin-right:7px; vertical-align:middle; }
#summary-section .table tbody tr:nth-child(5) td:first-child::before { content:''; display:inline-block; width:7px; height:7px; border-radius:50%; background:#9b5de5; box-shadow:0 0 6px #9b5de5; margin-right:7px; vertical-align:middle; }
#summary-section .table tbody tr:nth-child(6) td:first-child::before { content:''; display:inline-block; width:7px; height:7px; border-radius:50%; background:#00b4d8; box-shadow:0 0 6px #00b4d8; margin-right:7px; vertical-align:middle; }

/* Mean column — neon colour per row, monospace */
#summary-section .table tbody tr:nth-child(1) td:nth-child(5) { color:#00d4ff !important; font-family:'Courier New',monospace; font-weight:700; }
#summary-section .table tbody tr:nth-child(2) td:nth-child(5) { color:#ff6b6b !important; font-family:'Courier New',monospace; font-weight:700; }
#summary-section .table tbody tr:nth-child(3) td:nth-child(5) { color:#00ff88 !important; font-family:'Courier New',monospace; font-weight:700; }
#summary-section .table tbody tr:nth-child(4) td:nth-child(5) { color:#f4a261 !important; font-family:'Courier New',monospace; font-weight:700; }
#summary-section .table tbody tr:nth-child(5) td:nth-child(5) { color:#9b5de5 !important; font-family:'Courier New',monospace; font-weight:700; }
#summary-section .table tbody tr:nth-child(6) td:nth-child(5) { color:#00b4d8 !important; font-family:'Courier New',monospace; font-weight:700; }
```

- [ ] **Step 2: Add Peity recolouring to `torquehelpers.js`**

Add at the end of the existing Tom Select init section in `torquehelpers.js` (after the existing `$(document).ready` or `window.addEventListener('load'` block), or append to the HUD section added in Task 5:

```js
// Recolour Peity sparklines to match HUD dataset colours
function _hudRecolourSparklines() {
  var colors = ['#00d4ff','#ff6b6b','#00ff88','#f4a261','#9b5de5','#00b4d8','#fb8500'];
  $('#summary-section .table tbody tr').each(function(i) {
    $(this).find('.line').peity('line', {
      stroke: colors[i % colors.length],
      fill: 'transparent',
      width: 60,
      height: 20
    });
  });
}

// Call after chart is ready (gauges init triggers first, sparklines after)
window.addEventListener('load', function() {
  setTimeout(_hudRecolourSparklines, 200);
});
```

- [ ] **Step 3: Commit**

```bash
git add static/css/hud.css static/js/torquehelpers.js
git commit -m "feat: restyle data summary panel — glow dots, neon means, coloured sparklines"
```

---

### Task 10: Export panel + AI Chat panel

**Files:**
- Modify: `static/css/hud.css` (append export + AI rules)

- [ ] **Step 1: Append Export panel CSS to `hud.css`**

```css
/* ── Export panel ── */
#export-section .torque-panel-body { background: var(--hud-bg); }

#export-section .btn-group { gap: 8px; }
#export-section .btn-outline-secondary {
  border-color: var(--hud-border) !important;
  color: var(--hud-cyan) !important;
  background: rgba(0, 212, 255, 0.04) !important;
  border-radius: 6px !important;
  padding: 12px 8px !important;
  flex-direction: column !important;
  display: flex !important;
  align-items: center !important;
  gap: 4px !important;
  font-size: 11px !important;
  letter-spacing: 1px !important;
  font-weight: 600 !important;
  transition: box-shadow 0.15s, background 0.15s !important;
}
#export-section .btn-outline-secondary:hover {
  background: rgba(0, 212, 255, 0.1) !important;
  box-shadow: var(--hud-glow-cyan) !important;
}
#export-section .btn-outline-secondary::before {
  content: '↓';
  font-size: 18px;
  line-height: 1;
  color: var(--hud-cyan);
}

/* Render time text */
#export-section .text-muted { color: #334 !important; font-size: 9px !important; font-family: 'Courier New', monospace; }
```

- [ ] **Step 2: Append AI Chat panel CSS to `hud.css`**

```css
/* ── AI Chat panel ── */
#ai-section .torque-panel-body { background: var(--hud-bg); }

/* Header — TORQUEAI badge */
#ai-section .torque-panel-header { position: relative; }

/* Messages */
.ai-msg-user {
  background: rgba(0, 212, 255, 0.18) !important;
  color: var(--hud-cyan) !important;
  border: 1px solid rgba(0, 212, 255, 0.25) !important;
  border-bottom-right-radius: 3px !important;
}
.ai-msg-ai {
  background: rgba(255, 255, 255, 0.04) !important;
  color: #8ab !important;
  border: 1px solid rgba(255, 255, 255, 0.06) !important;
  border-bottom-left-radius: 3px !important;
}
.ai-msg-thinking {
  background: rgba(255, 255, 255, 0.03) !important;
  color: #445 !important;
}

/* Suggestion pills */
.ai-suggestion-btn {
  border-color: var(--hud-border) !important;
  color: var(--hud-cyan) !important;
  border-radius: 14px !important;
  font-size: 10px !important;
}
.ai-suggestion-btn:hover { background: rgba(0, 212, 255, 0.08) !important; }

/* Input bar */
.ai-input-bar { background: var(--hud-bg) !important; border-top-color: var(--hud-border) !important; }
.ai-input {
  background: #0a1628 !important;
  border-color: var(--hud-border) !important;
  color: #8ab !important;
}
.ai-input:focus { border-color: var(--hud-cyan) !important; box-shadow: 0 0 0 2px rgba(0,212,255,0.15) !important; }
.ai-input::placeholder { color: #334 !important; }
.ai-send-btn {
  background: var(--hud-cyan) !important;
  color: #000 !important;
}
.ai-send-btn:hover { background: #00eeff !important; box-shadow: var(--hud-glow-cyan) !important; }
.ai-send-btn:disabled { background: #1a2030 !important; color: #334 !important; }

/* Suggestions divider */
.ai-suggestions { border-top-color: var(--hud-border) !important; background: #040810; }
```

- [ ] **Step 3: Update the AI panel header in `session.php` to add TORQUEAI label and ONLINE badge**

Find in `session.php`:
```html
        <h6><i class="bi bi-robot me-2"></i>TorqueAI</h6>
```
Replace with:
```html
        <h6><i class="bi bi-robot me-2"></i>TORQUE<span style="color:var(--hud-red)">AI</span>&nbsp;<span style="background:rgba(0,255,136,0.15);border:1px solid rgba(0,255,136,0.35);color:#00ff88;font-size:7px;padding:1px 5px;border-radius:10px;letter-spacing:1px;vertical-align:middle;">ONLINE</span></h6>
```

- [ ] **Step 4: Verify PHP lint**

```bash
php -l session.php
```
Expected: `No syntax errors detected in session.php`

- [ ] **Step 5: Commit**

```bash
git add static/css/hud.css session.php
git commit -m "feat: restyle export and AI chat panels with HUD theme"
```

---

### Task 11: Calendar panel

**Files:**
- Modify: `static/css/hud.css` (append calendar rules)

- [ ] **Step 1: Append calendar panel CSS to `hud.css`**

```css
/* ── Calendar panel ── */
#cal-panel {
  background: var(--hud-bg) !important;
  border-color: var(--hud-border) !important;
}

#cal-panel .torque-panel-header { background: #07101f !important; }
#cal-panel .torque-panel-body { background: var(--hud-bg); }

/* Calendar nav and header */
.torque-cal-nav { color: var(--hud-cyan) !important; opacity: 0.6; }
.torque-cal-nav:hover { opacity: 1 !important; background: rgba(0,212,255,0.08) !important; }

.torque-cal-sel {
  background: rgba(0,212,255,0.06) !important;
  border-color: var(--hud-border) !important;
  color: var(--hud-cyan) !important;
}

/* Day names */
.cal-day-name { color: rgba(0,212,255,0.35) !important; }

/* Day cells */
.cal-day { color: #8ab; }
.cal-day:hover { background: rgba(0,212,255,0.1) !important; }
.cal-today { box-shadow: inset 0 0 0 1px var(--hud-cyan) !important; color: var(--hud-cyan) !important; }
.cal-start, .cal-end { background: var(--hud-cyan) !important; color: #000 !important; font-weight: 700; }
.cal-in-range { background: rgba(0,212,255,0.12) !important; }
.cal-disabled { opacity: 0.15; }

/* Select-all bar */
.cal-select-all-bar { background: rgba(0,212,255,0.04) !important; border-color: var(--hud-border) !important; }
.cal-select-all-label { color: var(--hud-cyan) !important; }
.cal-select-all-cb { accent-color: var(--hud-cyan); }

/* Sessions list */
.cal-sessions-list { border-color: var(--hud-border) !important; }
.cal-session-item { color: #8ab !important; border-color: rgba(0,212,255,0.07) !important; }
.cal-session-item:hover { background: rgba(0,212,255,0.07) !important; }
.cal-session-item.selected { background: rgba(0,212,255,0.14) !important; }
.cal-sess-cb { accent-color: var(--hud-cyan); }

/* Action bar */
.cal-action-bar { border-top-color: var(--hud-border) !important; }
#cal-hint { color: #334 !important; }
```

- [ ] **Step 2: Commit**

```bash
git add static/css/hud.css
git commit -m "feat: restyle calendar panel with HUD dark glass theme"
```

---

### Task 12: Map visual polish — route glow, pulse dot, speed legend, overlays

**Files:**
- Modify: `static/css/hud.css` (append map element rules)
- Modify: `session.php` (add `line-blur` paint property call + pulse dot CSS class)

- [ ] **Step 1: Append map element CSS to `hud.css`**

```css
/* ── Map visual polish ── */

/* Speed legend */
.torque-speed-legend {
  background: rgba(6, 9, 18, 0.82) !important;
  border: 1px solid var(--hud-border) !important;
  color: #8ab !important;
  font-family: 'Courier New', monospace !important;
  font-size: 10px !important;
  box-shadow: 0 0 12px rgba(0,212,255,0.06), 0 2px 8px rgba(0,0,0,0.5) !important;
}
.torque-speed-legend strong { color: var(--hud-cyan); letter-spacing: 1px; font-size: 9px; }

/* No-GPS overlay — dark glass */
#map-canvas > div[style*="rgba(255,255,255"] {
  background: rgba(6, 9, 18, 0.88) !important;
  color: #8ab !important;
  border: 1px solid var(--hud-border) !important;
}

/* Map dot pulse animation */
@keyframes hudDotPulse {
  0%   { box-shadow: 0 0 0 0 rgba(0,212,255,0.5), 0 2px 6px rgba(0,0,0,0.4); }
  70%  { box-shadow: 0 0 0 8px rgba(0,212,255,0), 0 2px 6px rgba(0,0,0,0.4); }
  100% { box-shadow: 0 0 0 0 rgba(0,212,255,0), 0 2px 6px rgba(0,0,0,0.4); }
}
.hud-map-dot {
  background: #0a0e1a !important;
  border: 2px solid var(--hud-cyan) !important;
  animation: hudDotPulse 1.5s ease-out infinite !important;
}

/* Multi-session legend dark glass */
#torque-session-legend {
  background: rgba(6, 9, 18, 0.88) !important;
  border: 1px solid var(--hud-border) !important;
  color: #8ab !important;
}
#torque-session-legend div { color: #8ab !important; }
```

- [ ] **Step 2: Add `line-blur` paint property and map dot class in `session.php`**

In `session.php`, find `window._torqueDrawRoute = function drawRoute() {` (around line 287). After the line `map.addLayer({ id: 'route', ... });` (the speed gradient layer), add:

```js
            // Glow effect on the route line
            map.setPaintProperty('route', 'line-blur', 2);
```

In `session.php`, find where `_mapDotEl` is created (around line 663):
```js
    var _mapDotEl = (function() {
      var el = document.createElement('div');
      el.style.cssText =
        'width:14px;height:14px;border-radius:50%;' +
        'background:#fff;border:3px solid #0d6efd;' +
        'box-shadow:0 0 0 3px rgba(13,110,253,0.25),0 2px 6px rgba(0,0,0,0.4);' +
        'pointer-events:none;display:none;';
      return el;
    })();
```
Replace with:
```js
    var _mapDotEl = (function() {
      var el = document.createElement('div');
      el.className = 'hud-map-dot';
      el.style.cssText =
        'width:12px;height:12px;border-radius:50%;' +
        'pointer-events:none;display:none;';
      return el;
    })();
```

- [ ] **Step 3: Update `_applyPopupTheme` in `session.php` for HUD colours**

Find `function _applyPopupTheme()` in `session.php`. Replace the `content.style.background` and related lines:
```js
          content.style.background   = dark ? '#1e1e2e' : '#ffffff';
          content.style.color        = dark ? '#e0e0e0' : '#222222';
          content.style.border       = dark ? '1px solid rgba(255,255,255,0.15)'
                                            : '1px solid rgba(0,0,0,0.1)';
```
With:
```js
          // HUD is always dark
          content.style.background   = 'rgba(6, 9, 18, 0.92)';
          content.style.color        = '#8ab';
          content.style.border       = '1px solid rgba(0, 212, 255, 0.22)';
```

- [ ] **Step 4: Verify PHP lint**

```bash
php -l session.php
```
Expected: `No syntax errors detected in session.php`

- [ ] **Step 5: Commit**

```bash
git add static/css/hud.css session.php
git commit -m "feat: map polish — route glow, pulsing HUD dot, dark speed legend, HUD popup"
```

---

### Task 13: Scrollbars, monospace numbers, dark mode repurpose

**Files:**
- Modify: `static/css/hud.css` (append scrollbar + font + dark mode rules)
- Modify: `session.php` (update `toggleDarkMode` to keep dark background)

- [ ] **Step 1: Append scrollbar and font CSS to `hud.css`**

```css
/* ── Custom scrollbars ── */
.torque-panel ::-webkit-scrollbar        { width: 5px; }
.torque-panel ::-webkit-scrollbar-track  { background: var(--hud-bg); }
.torque-panel ::-webkit-scrollbar-thumb  { background: rgba(0,212,255,0.25); border-radius: 3px; }
.torque-panel ::-webkit-scrollbar-thumb:hover { background: rgba(0,212,255,0.55); }

/* ── Monospace numbers in gauges + table stats ── */
.hud-gauge-val,
.hud-stat-val,
#summary-section .table td:nth-child(2),
#summary-section .table td:nth-child(3),
#summary-section .table td:nth-child(4),
#summary-section .table td:nth-child(5) {
  font-family: 'Courier New', monospace;
}
```

- [ ] **Step 2: Update `toggleDarkMode` in `session.php` to keep the body dark in both modes**

Find `toggleDarkMode` in `session.php` (around line 791):
```js
      function toggleDarkMode() {
        var html = document.documentElement;
        var isDark = html.getAttribute('data-bs-theme') === 'dark';
        var nowDark = !isDark;
        html.setAttribute('data-bs-theme', nowDark ? 'dark' : 'light');
        var btn = document.getElementById('darkModeBtn');
        btn.innerHTML = nowDark
          ? '<i class="bi bi-sun"></i>'
          : '<i class="bi bi-moon-stars"></i>';
        localStorage.setItem('torque-theme', nowDark ? 'dark' : 'light');
```
Replace with:
```js
      function toggleDarkMode() {
        var html = document.documentElement;
        var isDark = html.getAttribute('data-bs-theme') === 'dark';
        var nowDark = !isDark;
        // HUD is always dark — 'light' = de-saturated dark (dimmed glows via CSS)
        html.setAttribute('data-bs-theme', nowDark ? 'dark' : 'light');
        var btn = document.getElementById('darkModeBtn');
        btn.innerHTML = nowDark
          ? '<i class="bi bi-sun"></i>'
          : '<i class="bi bi-moon-stars"></i>';
        btn.title = nowDark ? 'Full neon mode' : 'Dimmed mode';
        localStorage.setItem('torque-theme', nowDark ? 'dark' : 'light');
```

- [ ] **Step 3: Verify PHP lint**

```bash
php -l session.php
```
Expected: `No syntax errors detected in session.php`

- [ ] **Step 4: Commit**

```bash
git add static/css/hud.css session.php
git commit -m "feat: scrollbars, monospace numbers, dark mode de-saturated variant"
```

---

## Final Verification Checklist

- [ ] **PHP lint clean:** `php -l session.php` → no errors
- [ ] **No JS console errors:** Open the page, open DevTools, check Console tab — should be silent
- [ ] **Navbar:** Height ~46px, brand glows cyan, icon buttons dim by default / glow cyan when active
- [ ] **HUD widget visible:** Top-left of map, frosted glass, three arc gauges showing `—` until variables are plotted
- [ ] **Gauge animation:** Plot RPM/Coolant/Speed → reload → gauges animate to session average on load; hover chart → gauges track in real time
- [ ] **Session stats:** Duration and distance populate from GPS data; fuel populates if `kff5203` is plotted
- [ ] **Chart strip:** Deep black background, cyan/red/green dataset lines with area fill
- [ ] **All panels:** Open/close with fade+slide transition; headers in caps cyan; dark glass background
- [ ] **Map dot:** Pulsing cyan ring on chart hover
- [ ] **Speed legend:** Dark glass style
- [ ] **Calendar panel:** Cyan selection highlights
- [ ] **AI chat:** TORQUEAI header with ONLINE badge, neon message bubbles
- [ ] **Scrollbars:** Thin cyan thumb on all panels
- [ ] **Dark mode toggle:** Full neon ↔ dimmed glows — background stays dark in both

```bash
git log --oneline -13
```
Expected: 13 HUD commits listed from Task 1 through Task 13.
