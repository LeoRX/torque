# HUD Widget Enhancements Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add always-on session-average gauges, configurable PIDs+labels via settings, and a draggable HUD widget with localStorage position memory for all floating panels.

**Architecture:** PHP injects `_hudConfig` (19 configurable settings) and `_hudSessionAvg` (SQL averages) at page load; JS gauge functions fall back to these values when no chart dataset matches; the HUD widget gains a drag handle wired to the existing panel drag pattern, with `localStorage` save/restore for three panels.

**Tech Stack:** PHP 8.2, MariaDB/MySQLi, vanilla JS (no new deps), CSS custom properties

---

## Task 1: Add 19 `hud_*` settings to `get_settings.php`

**Files:**
- Modify: `get_settings.php` (lines 16–38 `$_setting_defaults`, lines 63–101 typed vars block)

- [ ] **Step 1: Add the 19 new entries to `$_setting_defaults`**

In `get_settings.php`, find the closing `];` of `$_setting_defaults` (currently after the `claude_max_tokens` line, around line 38). Insert the new `hud` group entries **before** that closing bracket:

```php
  // HUD Widget
  'hud_gauge1_pid'        => ['kc',       'string',  'Gauge 1 PID',           'OBD k-code for gauge 1 (default: RPM).', 'hud'],
  'hud_gauge1_label'      => ['RPM',      'string',  'Gauge 1 Label',         'Label shown below gauge 1 arc.', 'hud'],
  'hud_gauge1_min'        => ['0',        'float',   'Gauge 1 Min',           'Scale minimum for gauge 1.', 'hud'],
  'hud_gauge1_max'        => ['8000',     'float',   'Gauge 1 Max',           'Scale maximum for gauge 1 (0 = use session max speed).', 'hud'],
  'hud_gauge1_suffix'     => ['',         'string',  'Gauge 1 Suffix',        'Appended to the displayed value (e.g. °).', 'hud'],
  'hud_gauge2_pid'        => ['k5',       'string',  'Gauge 2 PID',           'OBD k-code for gauge 2 (default: Coolant Temp).', 'hud'],
  'hud_gauge2_label'      => ['COOLANT',  'string',  'Gauge 2 Label',         'Label shown below gauge 2 arc.', 'hud'],
  'hud_gauge2_min'        => ['40',       'float',   'Gauge 2 Min',           'Scale minimum for gauge 2.', 'hud'],
  'hud_gauge2_max'        => ['120',      'float',   'Gauge 2 Max',           'Scale maximum for gauge 2.', 'hud'],
  'hud_gauge2_suffix'     => ['°',        'string',  'Gauge 2 Suffix',        'Appended to the displayed value.', 'hud'],
  'hud_gauge3_pid'        => ['kd',       'string',  'Gauge 3 PID',           'OBD k-code for gauge 3 (default: OBD Speed).', 'hud'],
  'hud_gauge3_label'      => ['km/h',     'string',  'Gauge 3 Label',         'Label shown below gauge 3 arc.', 'hud'],
  'hud_gauge3_min'        => ['0',        'float',   'Gauge 3 Min',           'Scale minimum for gauge 3.', 'hud'],
  'hud_gauge3_max'        => ['0',        'float',   'Gauge 3 Max',           'Scale maximum for gauge 3 (0 = use session max speed dynamically).', 'hud'],
  'hud_gauge3_suffix'     => ['',         'string',  'Gauge 3 Suffix',        'Appended to the displayed value.', 'hud'],
  'hud_stat_dur_label'    => ['DURATION', 'string',  'Duration Stat Label',   'Label for the duration statistic.', 'hud'],
  'hud_stat_dist_label'   => ['DISTANCE', 'string',  'Distance Stat Label',   'Label for the distance statistic.', 'hud'],
  'hud_stat_fuel_pid'     => ['kff5203',  'string',  'Fuel Stat PID',         'OBD k-code for the fuel consumption stat.', 'hud'],
  'hud_stat_fuel_label'   => ['L/100km',  'string',  'Fuel Stat Label',       'Label for the fuel statistic.', 'hud'],
```

The full `$_setting_defaults` array tail should look like this after the edit (from the `// AI Assistant` comment onward):

```php
  // AI Assistant
  'claude_enabled'        => ['0',      'boolean', 'Enable AI Assistant',        'Show the AI chat assistant button in the main interface.',                                  'ai'],
  'claude_api_key'        => ['',       'string',  'Claude API Key',             'Your Anthropic API key (sk-ant-...). Get one at console.anthropic.com.',                   'ai'],
  'claude_model'          => ['claude-haiku-4-5-20251001', 'select', 'Claude Model', 'Model to use. Haiku is fast/cheap; Sonnet is more capable.',                           'ai'],
  'claude_max_tokens'     => ['1024',   'integer', 'Max Response Tokens',        'Maximum response length (256–4096 tokens).',                                               'ai'],
  // HUD Widget
  'hud_gauge1_pid'        => ['kc',       'string',  'Gauge 1 PID',           'OBD k-code for gauge 1 (default: RPM).', 'hud'],
  'hud_gauge1_label'      => ['RPM',      'string',  'Gauge 1 Label',         'Label shown below gauge 1 arc.', 'hud'],
  'hud_gauge1_min'        => ['0',        'float',   'Gauge 1 Min',           'Scale minimum for gauge 1.', 'hud'],
  'hud_gauge1_max'        => ['8000',     'float',   'Gauge 1 Max',           'Scale maximum for gauge 1 (0 = use session max speed).', 'hud'],
  'hud_gauge1_suffix'     => ['',         'string',  'Gauge 1 Suffix',        'Appended to the displayed value (e.g. °).', 'hud'],
  'hud_gauge2_pid'        => ['k5',       'string',  'Gauge 2 PID',           'OBD k-code for gauge 2 (default: Coolant Temp).', 'hud'],
  'hud_gauge2_label'      => ['COOLANT',  'string',  'Gauge 2 Label',         'Label shown below gauge 2 arc.', 'hud'],
  'hud_gauge2_min'        => ['40',       'float',   'Gauge 2 Min',           'Scale minimum for gauge 2.', 'hud'],
  'hud_gauge2_max'        => ['120',      'float',   'Gauge 2 Max',           'Scale maximum for gauge 2.', 'hud'],
  'hud_gauge2_suffix'     => ['°',        'string',  'Gauge 2 Suffix',        'Appended to the displayed value.', 'hud'],
  'hud_gauge3_pid'        => ['kd',       'string',  'Gauge 3 PID',           'OBD k-code for gauge 3 (default: OBD Speed).', 'hud'],
  'hud_gauge3_label'      => ['km/h',     'string',  'Gauge 3 Label',         'Label shown below gauge 3 arc.', 'hud'],
  'hud_gauge3_min'        => ['0',        'float',   'Gauge 3 Min',           'Scale minimum for gauge 3.', 'hud'],
  'hud_gauge3_max'        => ['0',        'float',   'Gauge 3 Max',           'Scale maximum for gauge 3 (0 = use session max speed dynamically).', 'hud'],
  'hud_gauge3_suffix'     => ['',         'string',  'Gauge 3 Suffix',        'Appended to the displayed value.', 'hud'],
  'hud_stat_dur_label'    => ['DURATION', 'string',  'Duration Stat Label',   'Label for the duration statistic.', 'hud'],
  'hud_stat_dist_label'   => ['DISTANCE', 'string',  'Distance Stat Label',   'Label for the distance statistic.', 'hud'],
  'hud_stat_fuel_pid'     => ['kff5203',  'string',  'Fuel Stat PID',         'OBD k-code for the fuel consumption stat.', 'hud'],
  'hud_stat_fuel_label'   => ['L/100km',  'string',  'Fuel Stat Label',       'Label for the fuel statistic.', 'hud'],
];
```

- [ ] **Step 2: Expose the HUD settings as typed PHP variables**

After the `$claude_max_tokens` line (currently line 101), add typed variable exposure for all HUD settings. Append this block before the closing `?>`:

```php
// HUD Widget config — typed PHP variables
$hud_gauge1_pid     =        ($settings['hud_gauge1_pid']     ?? 'kc');
$hud_gauge1_label   =        ($settings['hud_gauge1_label']   ?? 'RPM');
$hud_gauge1_min     = (float)($settings['hud_gauge1_min']     ?? 0);
$hud_gauge1_max     = (float)($settings['hud_gauge1_max']     ?? 8000);
$hud_gauge1_suffix  =        ($settings['hud_gauge1_suffix']  ?? '');
$hud_gauge2_pid     =        ($settings['hud_gauge2_pid']     ?? 'k5');
$hud_gauge2_label   =        ($settings['hud_gauge2_label']   ?? 'COOLANT');
$hud_gauge2_min     = (float)($settings['hud_gauge2_min']     ?? 40);
$hud_gauge2_max     = (float)($settings['hud_gauge2_max']     ?? 120);
$hud_gauge2_suffix  =        ($settings['hud_gauge2_suffix']  ?? '°');
$hud_gauge3_pid     =        ($settings['hud_gauge3_pid']     ?? 'kd');
$hud_gauge3_label   =        ($settings['hud_gauge3_label']   ?? 'km/h');
$hud_gauge3_min     = (float)($settings['hud_gauge3_min']     ?? 0);
$hud_gauge3_max     = (float)($settings['hud_gauge3_max']     ?? 0);
$hud_gauge3_suffix  =        ($settings['hud_gauge3_suffix']  ?? '');
$hud_stat_dur_label =        ($settings['hud_stat_dur_label'] ?? 'DURATION');
$hud_stat_dist_label=        ($settings['hud_stat_dist_label']?? 'DISTANCE');
$hud_stat_fuel_pid  =        ($settings['hud_stat_fuel_pid']  ?? 'kff5203');
$hud_stat_fuel_label=        ($settings['hud_stat_fuel_label']?? 'L/100km');
```

- [ ] **Step 3: Verify PHP syntax**

```
php -l get_settings.php
```

Expected: `No syntax errors detected in get_settings.php`

- [ ] **Step 4: Commit**

```bash
git add get_settings.php
git commit -m "feat: add 19 hud_* settings to get_settings.php with typed PHP vars"
```

---

## Task 2: `session.php` — config object, avg query, kcode in datasets, dynamic labels, drag handle

**Files:**
- Modify: `session.php`
  - Before line 116 (`mysqli_close($con)`): add HUD config build + avg SQL query
  - Lines 496–511 (dataset loop): add `kcode` property
  - After the dataset loop (around line 512): inject `_hudConfig` + `_hudSessionAvg` JS vars
  - Lines 976, 989, 1002 (HUD widget HTML labels): make dynamic via PHP
  - Lines 1007, 1010, 1014, 1017 (HUD stats labels): make dynamic via PHP
  - Line 963 (HUD widget opening div): prepend `.hud-drag-handle`

- [ ] **Step 1: Build `$hudConfig` and query session averages before `mysqli_close`**

Find the block around line 113–116 in `session.php`:
```php
  //Close the MySQL connection, which is why we can't query years later
  mysqli_free_result($sessionqry);
  mysqli_close($con);
```

Insert the following **before** `mysqli_free_result($sessionqry);`:

```php
  // ── HUD Widget config (from settings) ──
  $hudConfig = [
    'gauge1' => ['pid' => $hud_gauge1_pid, 'label' => $hud_gauge1_label, 'min' => $hud_gauge1_min, 'max' => $hud_gauge1_max, 'suffix' => $hud_gauge1_suffix],
    'gauge2' => ['pid' => $hud_gauge2_pid, 'label' => $hud_gauge2_label, 'min' => $hud_gauge2_min, 'max' => $hud_gauge2_max, 'suffix' => $hud_gauge2_suffix],
    'gauge3' => ['pid' => $hud_gauge3_pid, 'label' => $hud_gauge3_label, 'min' => $hud_gauge3_min, 'max' => $hud_gauge3_max, 'suffix' => $hud_gauge3_suffix],
    'fuel'   => ['pid' => $hud_stat_fuel_pid, 'label' => $hud_stat_fuel_label],
  ];

  // ── HUD session averages — query AVG for each configured PID ──
  // k-codes come from torque_settings (validated on save); use quote_name() for safety.
  $hudSessionAvg = ['gauge1' => null, 'gauge2' => null, 'gauge3' => null, 'fuel' => null];
  $_g1col  = quote_name($hud_gauge1_pid);
  $_g2col  = quote_name($hud_gauge2_pid);
  $_g3col  = quote_name($hud_gauge3_pid);
  $_fcol   = quote_name($hud_stat_fuel_pid);
  $_avg_sql = "SELECT AVG($_g1col) AS g1, AVG($_g2col) AS g2,
                      AVG($_g3col) AS g3, AVG($_fcol) AS fuel
               FROM $db_table_full WHERE session=$session_id";
  $_avg_res = mysqli_query($con, $_avg_sql);
  if ($_avg_res) {
    $_avg_row = mysqli_fetch_assoc($_avg_res);
    $hudSessionAvg['gauge1'] = $_avg_row['g1'] !== null ? (float)$_avg_row['g1'] : null;
    $hudSessionAvg['gauge2'] = $_avg_row['g2'] !== null ? (float)$_avg_row['g2'] : null;
    $hudSessionAvg['gauge3'] = $_avg_row['g3'] !== null ? (float)$_avg_row['g3'] : null;
    $hudSessionAvg['fuel']   = $_avg_row['fuel'] !== null ? (float)$_avg_row['fuel'] : null;
    mysqli_free_result($_avg_res);
  }
```

After the insert, the block should read:
```php
  // ── HUD Widget config (from settings) ──
  $hudConfig = [...];

  // ── HUD session averages ... ──
  $hudSessionAvg = [...];
  ...

  //Close the MySQL connection, which is why we can't query years later
  mysqli_free_result($sessionqry);
  mysqli_close($con);
```

- [ ] **Step 2: Add `kcode` property to each dataset in the PHP loop**

Find the dataset object in the `while` loop (around line 497–511). The current dataset object ends with:
```php
          fill: true
        }<?php if ( isset(${'var'.($i+1)}) ) echo ","; ?>
```

Change the dataset object to add a `kcode` line. The full dataset object should now be:

```php
        {
          label: <?php echo "${'v'.$i.'_label'}"; ?>,
          kcode: '<?php echo htmlspecialchars(${'v'.$i}); ?>',
          data: s<?php echo $i; ?>.map(function(p){ return {x: p[0], y: p[1]}; }),
          borderWidth: 1.5,
          pointRadius: 0,
          pointHitRadius: 8,
          tension: 0.1,
          borderColor: _hudColors[(<?php echo $i-1; ?>) % _hudColors.length],
          backgroundColor: _hudColorsFill[(<?php echo $i-1; ?>) % _hudColorsFill.length],
          fill: true
        }<?php if ( isset(${'var'.($i+1)}) ) echo ","; ?>
```

Note: `plot.php` stores the raw k-code for variable `$i` in `${'v'.$i}` (e.g. `$v1 = 'kc'`). The human-readable label is `${'v'.$i.'_label'}`. So the kcode line uses `${'v'.$i}` directly — no separate kcode variable exists.

- [ ] **Step 3: Inject `_hudConfig` and `_hudSessionAvg` as JS variables**

Find the line immediately after the `torqueDatasets` closing `];` (around line 512). After that closing `];`, inject:

```php
      var _hudConfig = <?php echo json_encode($hudConfig, JSON_UNESCAPED_UNICODE); ?>;
      var _hudSessionAvg = <?php echo json_encode($hudSessionAvg); ?>;
```

The result should look like:
```js
      var torqueDatasets = [ ... ];
      var _hudConfig = {"gauge1":{"pid":"kc","label":"RPM","min":0,"max":8000,"suffix":""},...};
      var _hudSessionAvg = {"gauge1":1840.5,"gauge2":87.2,"gauge3":42.1,"fuel":8.4};
```

- [ ] **Step 4: Make HUD widget HTML labels dynamic**

In the HUD widget HTML (around lines 976, 989, 1002), replace the three hardcoded gauge labels:

Replace:
```html
          <div class="hud-gauge-label">RPM</div>
```
With:
```html
          <div class="hud-gauge-label"><?php echo htmlspecialchars($hud_gauge1_label); ?></div>
```

Replace:
```html
          <div class="hud-gauge-label">COOLANT</div>
```
With:
```html
          <div class="hud-gauge-label"><?php echo htmlspecialchars($hud_gauge2_label); ?></div>
```

Replace:
```html
          <div class="hud-gauge-label">km/h</div>
```
With:
```html
          <div class="hud-gauge-label"><?php echo htmlspecialchars($hud_gauge3_label); ?></div>
```

In the HUD stats row (around lines 1009, 1013, 1017), replace the three hardcoded stat labels:

Replace:
```html
          <div class="hud-stat-label">DURATION</div>
```
With:
```html
          <div class="hud-stat-label"><?php echo htmlspecialchars($hud_stat_dur_label); ?></div>
```

Replace:
```html
          <div class="hud-stat-label">DISTANCE</div>
```
With:
```html
          <div class="hud-stat-label"><?php echo htmlspecialchars($hud_stat_dist_label); ?></div>
```

Replace:
```html
          <div class="hud-stat-label">L/100km</div>
```
With:
```html
          <div class="hud-stat-label"><?php echo htmlspecialchars($hud_stat_fuel_label); ?></div>
```

- [ ] **Step 5: Add drag handle as first child of `#hud-widget`**

Find the HUD widget opening (around line 963):
```html
    <div id="hud-widget">
      <div class="hud-gauges">
```

Replace with:
```html
    <div id="hud-widget">
      <div class="hud-drag-handle" title="Drag to move"><span class="hud-drag-dots">⠿</span></div>
      <div class="hud-gauges">
```

- [ ] **Step 6: Verify PHP syntax**

```
php -l session.php
```

Expected: `No syntax errors detected in session.php`

- [ ] **Step 7: Check the rendered page**

Load the page in a browser (or via curl/wget). Verify:
- The page source contains `var _hudConfig = {` with the correct default values
- The page source contains `var _hudSessionAvg = {` with numeric values (not all null, assuming the session has data)
- Each dataset object has a `kcode:` property
- The HUD widget HTML shows PHP-rendered labels (defaults: RPM, COOLANT, km/h, DURATION, DISTANCE, L/100km)
- The `.hud-drag-handle` div is present as first child of `#hud-widget`

- [ ] **Step 8: Commit**

```bash
git add session.php
git commit -m "feat: inject _hudConfig + _hudSessionAvg, add kcode to datasets, dynamic HUD labels, drag handle HTML"
```

---

## Task 3: `torquehelpers.js` — `_findDatasetByKCode`, update gauge functions, HUD drag, localStorage

**Files:**
- Modify: `static/js/torquehelpers.js`
  - Lines 532–612 (panel drag block): add localStorage save on mouseup/touchend; add HUD drag handle logic after the panel loop
  - Lines 631–643 (`_findDatasetByKeyword`): add new `_findDatasetByKCode` function alongside it
  - Lines 667–728 (`_initGauges`): update to use `_hudConfig` + `_hudSessionAvg` fallback
  - Lines 731–761 (`_updateGauges`): update to use `_findDatasetByKCode` + `_hudConfig`

- [ ] **Step 1: Add `_findDatasetByKCode` function**

Find the `_findDatasetByKeyword` function (line 632). Add the new function **immediately before** it:

```js
// Find first Chart.js dataset whose kcode property exactly matches the given k-code
function _findDatasetByKCode(kcode) {
  if (!window.torqueChart || !kcode) return null;
  var ds = window.torqueChart.data.datasets;
  for (var i = 0; i < ds.length; i++) {
    if ((ds[i].kcode || '') === kcode) return ds[i];
  }
  return null;
}

```

- [ ] **Step 2: Update `_initGauges` to use `_hudConfig` and `_hudSessionAvg`**

Replace the entire `_initGauges` function (lines 667–728) with:

```js
// Initialise gauges on page load — populate stats and animate to session averages
function _initGauges() {
  // On first call, apply a slow 1.2s sweep so the entry animation is dramatic
  var arcs = document.querySelectorAll('.hud-gauge-arc');
  if (!_gaugesInitialised) {
    for (var a = 0; a < arcs.length; a++) arcs[a].classList.add('hud-gauge-arc--loading');
    _gaugesInitialised = true;
    setTimeout(function() {
      for (var a = 0; a < arcs.length; a++) arcs[a].classList.remove('hud-gauge-arc--loading');
    }, 1300);
  }

  // ── Distance from GPS ──
  var distEl = document.getElementById('hud-stat-dist');
  if (distEl && window._routeData && _routeData.length > 1) {
    var dist = 0;
    for (var i = 1; i < _routeData.length; i++) {
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

  // ── Fuel stat: prefer chart dataset, fall back to session average ──
  var fuelEl = document.getElementById('hud-stat-fuel');
  if (fuelEl) {
    var fuelPid = (window._hudConfig && _hudConfig.fuel) ? _hudConfig.fuel.pid : 'kff5203';
    var fuelDs  = _findDatasetByKCode(fuelPid);
    var fuelAvg = _datasetMean(fuelDs);
    if (fuelAvg !== null) {
      fuelEl.textContent = fuelAvg.toFixed(1);
    } else if (window._hudSessionAvg && _hudSessionAvg.fuel !== null && _hudSessionAvg.fuel !== undefined) {
      fuelEl.textContent = _hudSessionAvg.fuel.toFixed(1);
    } else {
      fuelEl.textContent = '—';
    }
  }

  // ── Animate gauges: prefer chart dataset, fall back to session average ──
  var cfg = window._hudConfig || {};
  var avg = window._hudSessionAvg || {};

  // Gauge 1
  var g1cfg = cfg.gauge1 || {pid:'kc', min:0, max:8000, suffix:''};
  var g1ds  = _findDatasetByKCode(g1cfg.pid);
  var g1val = _datasetMean(g1ds);
  if (g1val === null && avg.gauge1 !== null && avg.gauge1 !== undefined) g1val = avg.gauge1;
  if (g1val !== null) {
    var g1max = (g1cfg.max > 0) ? g1cfg.max : (window._maxSpeed || 120);
    var g1frac = (g1val - g1cfg.min) / (g1max - g1cfg.min);
    _setGauge('hud-gauge-rpm', 'hud-gauge-rpm-val', g1frac, Math.round(g1val) + (g1cfg.suffix || ''));
  }

  // Gauge 2
  var g2cfg = cfg.gauge2 || {pid:'k5', min:40, max:120, suffix:'°'};
  var g2ds  = _findDatasetByKCode(g2cfg.pid);
  var g2val = _datasetMean(g2ds);
  if (g2val === null && avg.gauge2 !== null && avg.gauge2 !== undefined) g2val = avg.gauge2;
  if (g2val !== null) {
    var g2max = (g2cfg.max > 0) ? g2cfg.max : (window._maxSpeed || 120);
    var g2frac = (g2val - g2cfg.min) / (g2max - g2cfg.min);
    _setGauge('hud-gauge-coolant', 'hud-gauge-coolant-val', g2frac, Math.round(g2val) + (g2cfg.suffix || ''));
  }

  // Gauge 3
  var g3cfg = cfg.gauge3 || {pid:'kd', min:0, max:0, suffix:''};
  var g3ds  = _findDatasetByKCode(g3cfg.pid);
  var g3val = _datasetMean(g3ds);
  if (g3val === null && avg.gauge3 !== null && avg.gauge3 !== undefined) g3val = avg.gauge3;
  if (g3val !== null) {
    var g3max = (g3cfg.max > 0) ? g3cfg.max : (window._maxSpeed || 120);
    var g3frac = (g3val - g3cfg.min) / (g3max - g3cfg.min);
    _setGauge('hud-gauge-speed', 'hud-gauge-speed-val', g3frac, Math.round(g3val) + (g3cfg.suffix || ''));
  }

  // Reset coolant arc to default colour (mouseleave may leave it at a warning threshold)
  var coolantArc = document.getElementById('hud-gauge-coolant');
  if (coolantArc) {
    coolantArc.setAttribute('stroke', '#ff6b6b');
    coolantArc.style.filter = '';
  }
}
```

- [ ] **Step 3: Update `_updateGauges` to use `_findDatasetByKCode` + `_hudConfig`**

Replace the entire `_updateGauges` function (lines 731–761) with:

```js
// Update gauges from a chart timestamp — called on chart mousemove
function _updateGauges(tsMs) {
  var cfg = window._hudConfig || {};

  // Gauge 1
  var g1cfg = cfg.gauge1 || {pid:'kc', min:0, max:8000, suffix:''};
  var g1ds  = _findDatasetByKCode(g1cfg.pid);
  if (g1ds) {
    var g1val = _chartValueAtTime(g1ds.data, tsMs);
    if (g1val !== null) {
      var g1max = (g1cfg.max > 0) ? g1cfg.max : (window._maxSpeed || 120);
      _setGauge('hud-gauge-rpm', 'hud-gauge-rpm-val',
        (g1val - g1cfg.min) / (g1max - g1cfg.min),
        Math.round(g1val) + (g1cfg.suffix || ''));
    }
  }

  // Gauge 2 — with coolant temperature colour thresholds (hardcoded to gauge 2)
  var g2cfg = cfg.gauge2 || {pid:'k5', min:40, max:120, suffix:'°'};
  var g2ds  = _findDatasetByKCode(g2cfg.pid);
  if (g2ds) {
    var g2val = _chartValueAtTime(g2ds.data, tsMs);
    if (g2val !== null) {
      var g2max = (g2cfg.max > 0) ? g2cfg.max : (window._maxSpeed || 120);
      _setGauge('hud-gauge-coolant', 'hud-gauge-coolant-val',
        (g2val - g2cfg.min) / (g2max - g2cfg.min),
        Math.round(g2val) + (g2cfg.suffix || ''));
      var arc2 = document.getElementById('hud-gauge-coolant');
      if (arc2) {
        var col = g2val > 105 ? '#ff2222' : g2val > 95 ? '#ff9944' : '#ff6b6b';
        arc2.setAttribute('stroke', col);
        arc2.style.filter = 'drop-shadow(0 0 4px ' + col + ')';
      }
    }
  }

  // Gauge 3
  var g3cfg = cfg.gauge3 || {pid:'kd', min:0, max:0, suffix:''};
  var g3ds  = _findDatasetByKCode(g3cfg.pid);
  if (g3ds) {
    var g3val = _chartValueAtTime(g3ds.data, tsMs);
    if (g3val !== null) {
      var g3max = (g3cfg.max > 0) ? g3cfg.max : (window._maxSpeed || 120);
      _setGauge('hud-gauge-speed', 'hud-gauge-speed-val',
        (g3val - g3cfg.min) / (g3max - g3cfg.min),
        Math.round(g3val) + (g3cfg.suffix || ''));
    }
  }
}
```

- [ ] **Step 4: Add localStorage position save to existing panel drag handlers**

Find the `onMouseUp` function inside the panel drag `mousedown` handler (lines 569–572):

```js
      function onMouseUp() {
        document.removeEventListener('mousemove', onMouseMove);
        document.removeEventListener('mouseup',   onMouseUp);
      }
```

Replace with:

```js
      function onMouseUp() {
        document.removeEventListener('mousemove', onMouseMove);
        document.removeEventListener('mouseup',   onMouseUp);
        try {
          localStorage.setItem('torque-pos-' + panel.id,
            JSON.stringify({ left: panel.style.left, top: panel.style.top }));
        } catch(e) {}
      }
```

Find the `onTouchEnd` function inside the panel drag `touchstart` handler (lines 604–607):

```js
      function onTouchEnd() {
        header.removeEventListener('touchmove', onTouchMove);
        header.removeEventListener('touchend',  onTouchEnd);
      }
```

Replace with:

```js
      function onTouchEnd() {
        header.removeEventListener('touchmove', onTouchMove);
        header.removeEventListener('touchend',  onTouchEnd);
        try {
          localStorage.setItem('torque-pos-' + panel.id,
            JSON.stringify({ left: panel.style.left, top: panel.style.top }));
        } catch(e) {}
      }
```

- [ ] **Step 5: Add localStorage position restore on document ready**

Find the `$(document).ready(function() {` opening (around line 1 of the document-ready block). Add the restore block as the **very first thing** inside the ready handler, before any other initialization. The ready handler starts around line 1:

```js
$(document).ready(function() {
```

Insert immediately after that line:

```js
  // ── Restore saved panel positions from localStorage ──
  ['hud-widget', 'vars-section', 'summary-section'].forEach(function(id) {
    try {
      var saved = localStorage.getItem('torque-pos-' + id);
      if (!saved) return;
      var pos = JSON.parse(saved);
      var el = document.getElementById(id);
      if (!el || !pos || !pos.left || !pos.top) return;
      // Clamp to visible viewport (handles window resize between sessions)
      var leftPx = Math.min(Math.max(0, parseInt(pos.left, 10)), window.innerWidth - 40);
      var topPx  = Math.min(Math.max(58, parseInt(pos.top,  10)), window.innerHeight - 40);
      el.style.left   = leftPx + 'px';
      el.style.top    = topPx  + 'px';
      el.style.right  = 'auto';
      el.style.bottom = 'auto';
    } catch(e) {}
  });

```

- [ ] **Step 6: Add HUD widget drag handler (after the panel drag loop)**

Find the end of the `.torque-panel-header` drag loop (around line 612, after `});` that closes the `forEach`):

```js
  });

});

// ══════════════════════════════════════════════════════════════════
// HUD Gauge System
```

Insert the HUD widget drag handler **between** the closing `});` of the forEach and the `});` that closes `$(document).ready`:

```js
  // ── HUD Widget drag (via .hud-drag-handle) ──
  var hudHandle = document.querySelector('.hud-drag-handle');
  var hudPanel  = document.getElementById('hud-widget');
  if (hudHandle && hudPanel) {
    hudHandle.addEventListener('mousedown', function(e) {
      e.preventDefault();
      var rect     = hudPanel.getBoundingClientRect();
      var startX   = e.clientX;
      var startY   = e.clientY;
      var startLeft = rect.left;
      var startTop  = rect.top;

      hudPanel.style.left   = startLeft + 'px';
      hudPanel.style.top    = startTop  + 'px';
      hudPanel.style.right  = 'auto';
      hudPanel.style.bottom = 'auto';

      function onHudMove(e) {
        var newLeft = Math.max(0, Math.min(window.innerWidth  - 40, startLeft + e.clientX - startX));
        var newTop  = Math.max(58, Math.min(window.innerHeight - 40, startTop  + e.clientY - startY));
        hudPanel.style.left = newLeft + 'px';
        hudPanel.style.top  = newTop  + 'px';
      }
      function onHudUp() {
        document.removeEventListener('mousemove', onHudMove);
        document.removeEventListener('mouseup',   onHudUp);
        try {
          localStorage.setItem('torque-pos-hud-widget',
            JSON.stringify({ left: hudPanel.style.left, top: hudPanel.style.top }));
        } catch(e) {}
      }
      document.addEventListener('mousemove', onHudMove);
      document.addEventListener('mouseup',   onHudUp);
    });

    hudHandle.addEventListener('touchstart', function(e) {
      var touch = e.touches[0];
      var rect  = hudPanel.getBoundingClientRect();
      var startX    = touch.clientX;
      var startY    = touch.clientY;
      var startLeft = rect.left;
      var startTop  = rect.top;

      hudPanel.style.left   = startLeft + 'px';
      hudPanel.style.top    = startTop  + 'px';
      hudPanel.style.right  = 'auto';
      hudPanel.style.bottom = 'auto';

      function onHudTouchMove(e) {
        e.preventDefault();
        var t = e.touches[0];
        var newLeft = Math.max(0, Math.min(window.innerWidth  - 40, startLeft + t.clientX - startX));
        var newTop  = Math.max(58, Math.min(window.innerHeight - 40, startTop  + t.clientY - startY));
        hudPanel.style.left = newLeft + 'px';
        hudPanel.style.top  = newTop  + 'px';
      }
      function onHudTouchEnd() {
        hudHandle.removeEventListener('touchmove', onHudTouchMove);
        hudHandle.removeEventListener('touchend',  onHudTouchEnd);
        try {
          localStorage.setItem('torque-pos-hud-widget',
            JSON.stringify({ left: hudPanel.style.left, top: hudPanel.style.top }));
        } catch(e) {}
      }
      hudHandle.addEventListener('touchmove', onHudTouchMove, { passive: false });
      hudHandle.addEventListener('touchend',  onHudTouchEnd);
    });
  }

```

- [ ] **Step 7: Open browser and verify**

Check the browser console for zero JS errors. Then verify:
- Gauges show numeric values on page load (not `—`) when session has data
- Dragging the `.hud-drag-handle` moves the HUD widget
- Reloading the page restores the HUD widget to the dragged position
- Dragging vars-section / summary-section and reloading restores their positions
- Plotting a variable and hovering the chart updates the gauges in real-time
- Moving the mouse off the chart returns gauges to session-average values

- [ ] **Step 8: Commit**

```bash
git add static/js/torquehelpers.js
git commit -m "feat: _findDatasetByKCode, _initGauges/_updateGauges use _hudConfig+_hudSessionAvg, HUD drag + localStorage"
```

---

## Task 4: `hud.css` — drag handle styles and pointer-events fix

**Files:**
- Modify: `static/css/hud.css` (append to end of file)

- [ ] **Step 1: Fix `pointer-events` and add drag handle styles**

Open `static/css/hud.css` and find the `#hud-widget` rule. It currently contains `pointer-events: none`. The entire widget needs `pointer-events: auto` so the drag handle registers mouse events, while the data areas pass clicks through to the map.

Find the existing `#hud-widget` block and change `pointer-events: none` to `pointer-events: auto`. Then append the following new rules at the **end of the file**:

```css
/* ── HUD pointer-events: data areas pass through to map ── */
.hud-gauges,
.hud-stats { pointer-events: none; }

/* ── HUD drag handle ── */
.hud-drag-handle {
  cursor: grab;
  text-align: center;
  color: rgba(0, 212, 255, 0.2);
  font-size: 10px;
  line-height: 1;
  margin: -4px -4px 6px;
  padding: 2px 0;
  border-radius: 10px 10px 0 0;
  transition: color 0.15s;
  pointer-events: auto;
  user-select: none;
  -webkit-user-select: none;
}
.hud-drag-handle:hover  { color: rgba(0, 212, 255, 0.55); }
.hud-drag-handle:active { cursor: grabbing; }
```

- [ ] **Step 2: Verify in browser**

With the CSS applied:
- The HUD drag handle (braille dots ⠿) is faintly visible and brightens on hover
- Clicking the gauge/stats area of the HUD does NOT capture clicks (map remains interactive under it)
- The drag handle registers click/drag

- [ ] **Step 3: Commit**

```bash
git add static/css/hud.css
git commit -m "feat: HUD drag handle styles and pointer-events fix"
```

---

## Task 5: `db_upgrade.php` — add v2.1 documentation comment block

**Files:**
- Modify: `db_upgrade.php` (append before closing `?>` or at end of commented-out block)

- [ ] **Step 1: Read the end of `db_upgrade.php` to find the right insertion point**

The file is all commented-out ALTER TABLE statements. Find the last commented block and add a new documentation comment after it.

- [ ] **Step 2: Add the v2.1 comment block**

Append the following block at the end of the commented section (before the closing `?>` if one exists, or at the end of the file):

```php
# ── v2.1 HUD Widget Enhancements (2026-04-30) ────────────────────────────────
# New torque_settings keys added (auto-seeded by get_settings.php — no ALTER needed):
#   hud_gauge1_pid, hud_gauge1_label, hud_gauge1_min, hud_gauge1_max, hud_gauge1_suffix
#   hud_gauge2_pid, hud_gauge2_label, hud_gauge2_min, hud_gauge2_max, hud_gauge2_suffix
#   hud_gauge3_pid, hud_gauge3_label, hud_gauge3_min, hud_gauge3_max, hud_gauge3_suffix
#   hud_stat_dur_label, hud_stat_dist_label, hud_stat_fuel_pid, hud_stat_fuel_label
# No ALTER TABLE required — get_settings.php INSERT IGNORE seeds all defaults on first load.
# ─────────────────────────────────────────────────────────────────────────────
```

- [ ] **Step 3: Commit**

```bash
git add db_upgrade.php
git commit -m "docs: add v2.1 HUD Widget Enhancements comment block to db_upgrade.php"
```

---

## Self-Review

**Spec coverage check:**

| Spec requirement | Covered by task |
|---|---|
| PHP injects `_hudSessionAvg` from `AVG()` SQL | Task 2 Step 1 |
| `_initGauges` uses session averages as fallback | Task 3 Step 2 |
| Chart hover updates gauges; mouseleave returns to avg (not zero) | Task 3 Step 2 (`_initGauges` called on mouseleave — existing wiring in session.php) |
| 19 new `hud_*` settings in `get_settings.php` | Task 1 Steps 1–2 |
| `$hudConfig` built from settings, injected as `_hudConfig` | Task 2 Steps 1, 3 |
| Dynamic HTML labels via `htmlspecialchars()` | Task 2 Step 4 |
| `kcode` property on each dataset | Task 2 Step 2 |
| `_findDatasetByKCode(kcode)` | Task 3 Step 1 |
| `_updateGauges` uses `_findDatasetByKCode` + `_hudConfig` | Task 3 Step 3 |
| `.hud-drag-handle` HTML | Task 2 Step 5 |
| Drag handle CSS (grab cursor, faint cyan dots) | Task 4 Step 1 |
| `pointer-events: auto` on `#hud-widget` | Task 4 Step 1 |
| `pointer-events: none` on `.hud-gauges, .hud-stats` | Task 4 Step 1 |
| HUD drag handler on `.hud-drag-handle` | Task 3 Step 6 |
| localStorage save on drag end (all panels) | Task 3 Steps 4, 6 |
| localStorage restore on page load (all 3 panels) | Task 3 Step 5 |
| Viewport clamping on restore | Task 3 Step 5 |
| `db_upgrade.php` comment block | Task 5 |
| `AVG()` query uses `quote_name()` for column identifiers | Task 2 Step 1 |
| `quote_name()` call on k-code before SQL | Task 2 Step 1 |
| `php -l` passes | Task 1 Step 3, Task 2 Step 6 |

**Placeholder scan:** No TBD, TODO, or vague steps found.

**Type consistency:** 
- `_hudConfig.gauge1.pid/min/max/suffix` used in `_initGauges` and `_updateGauges` — consistent.
- `_hudSessionAvg.gauge1/gauge2/gauge3/fuel` — PHP `json_encode` keys match JS property access — consistent.
- `_findDatasetByKCode(kcode)` signature consistent across all call sites.
- `_setGauge('hud-gauge-rpm', 'hud-gauge-rpm-val', fraction, text)` — all call sites provide 4 args — consistent.

**Edge case: `quote_name()` availability** — `db.php` defines `quote_name()`. `session.php` includes `db.php` early. ✅

**Edge case: kcode variable scope** — `plot.php` sets `$v1 = $_GET['s1']` (the raw k-code like `kc`). The dataset loop in `session.php` uses `${'v'.$i}` for the k-code. Confirmed: `kcode: '<?php echo htmlspecialchars(${'v'.$i}); ?>'` is correct and will output e.g. `kcode: 'kc'`. ✅
