# HUD Widget Enhancements — Design Spec
**Date:** 2026-04-30
**Scope:** `session.php`, `static/js/torquehelpers.js`, `static/css/hud.css`, `get_settings.php`, `settings.php`, `db_upgrade.php` — no new tables, no schema migrations
**Approach:** Three independent enhancements to the existing HUD widget; all delivered together

---

## 1. Overview

Three enhancements to the Dark Racing HUD widget introduced in the previous redesign:

1. **Always-on gauges** — gauges populate from session averages at page load without requiring the user to plot any variables first. Chart hover still updates gauges in real-time; session averages are the fallback.
2. **Configurable PIDs + labels** — each gauge and the fuel stat can be pointed at any OBD k-code via `settings.php`. Display labels, scale min/max, and value suffixes are also configurable.
3. **Draggable widget + position memory** — the HUD widget becomes a floating draggable panel. All draggable panels (HUD widget, Variables panel, Summary panel) remember their last position in `localStorage` and restore it on page load.

---

## 2. Always-On Gauges (PHP Injection)

### 2.1 Problem

`_initGauges()` currently calls `_findDatasetByKCode()` to source values from Chart.js datasets. When no variables are plotted, all gauge arcs stay at zero and display `—`.

### 2.2 Solution

PHP queries session-average values for each configured gauge PID at page load and injects them as a JS variable:

```php
// Computed in session.php after $hudConfig is built
$_avg_sql = "SELECT AVG(`{$g1pid}`) g1, AVG(`{$g2pid}`) g2,
                    AVG(`{$g3pid}`) g3, AVG(`{$fuelpid}`) fuel
             FROM {$db_table_full} WHERE session={$session_id}";
$_avg_row = mysqli_fetch_assoc(mysqli_query($con, $_avg_sql));
```

Injected into the page:

```js
var _hudSessionAvg = {
  gauge1: 1840.5,   // average of configured gauge 1 PID for this session
  gauge2: 87.2,
  gauge3: 42.1,
  fuel:   8.4       // null if PID column absent / no data
};
```

### 2.3 Behaviour

- **Page load:** `_initGauges()` uses `_hudSessionAvg` values. Arcs animate to these values with the existing 1.2 s sweep.
- **Chart plotted:** When the user plots a variable matching a gauge's k-code, `_updateGauges(tsMs)` takes over on hover, showing the per-datapoint value in real-time.
- **Chart mouse-leave:** Gauges return to `_hudSessionAvg` values (not zero).
- **No session / no data:** `_hudSessionAvg` values are `null`; gauges show `—` and arcs stay empty — identical to current behaviour.
- **Duration / Distance stats:** Already sourced from `_routeData` (GPS, always available). No change needed.
- **Fuel stat:** Uses `_hudSessionAvg.fuel` when `kff5203` (or the configured PID) is not plotted; chart dataset takes priority when plotted.

### 2.4 Safety

The dynamic column name in the SQL query uses the k-code from `torque_settings`. K-codes are validated on save in `settings.php` against the `torque_keys` table, so SQL injection via a crafted setting value is not possible. `quote_name()` is used for column identifiers.

---

## 3. Configurable PIDs + Labels

### 3.1 New Settings

Nineteen new entries added to `$_setting_defaults` in `get_settings.php` under group `hud`:

| Key | Default | Type | Description |
|-----|---------|------|-------------|
| `hud_gauge1_pid` | `kc` | string | k-code for gauge 1 (RPM arc) |
| `hud_gauge1_label` | `RPM` | string | Label displayed below arc |
| `hud_gauge1_min` | `0` | float | Scale minimum value |
| `hud_gauge1_max` | `8000` | float | Scale maximum value (0 = use `_maxSpeed`) |
| `hud_gauge1_suffix` | `` | string | Appended to displayed value (e.g. `°`) |
| `hud_gauge2_pid` | `k5` | string | k-code for gauge 2 (Coolant arc) |
| `hud_gauge2_label` | `COOLANT` | string | |
| `hud_gauge2_min` | `40` | float | |
| `hud_gauge2_max` | `120` | float | |
| `hud_gauge2_suffix` | `°` | string | |
| `hud_gauge3_pid` | `kd` | string | k-code for gauge 3 (Speed arc) |
| `hud_gauge3_label` | `km/h` | string | |
| `hud_gauge3_min` | `0` | float | |
| `hud_gauge3_max` | `0` | float | 0 = use `_maxSpeed` dynamically |
| `hud_gauge3_suffix` | `` | string | |
| `hud_stat_dur_label` | `DURATION` | string | Duration stat label |
| `hud_stat_dist_label` | `DISTANCE` | string | Distance stat label |
| `hud_stat_fuel_pid` | `kff5203` | string | k-code for fuel stat |
| `hud_stat_fuel_label` | `L/100km` | string | Fuel stat label |

### 3.2 PHP Config Object

`session.php` reads these settings and builds a PHP array, then JSON-encodes it into the page:

```js
var _hudConfig = {
  gauge1: { pid:"kc",      label:"RPM",      min:0,  max:8000, suffix:"" },
  gauge2: { pid:"k5",      label:"COOLANT",  min:40, max:120,  suffix:"°" },
  gauge3: { pid:"kd",      label:"km/h",     min:0,  max:0,    suffix:"" },
  fuel:   { pid:"kff5203", label:"L/100km" }
};
```

### 3.3 HUD Widget HTML — Dynamic Labels

The hardcoded labels in `session.php` become PHP expressions:

```html
<div class="hud-gauge-label"><?php echo htmlspecialchars($hud_gauge1_label); ?></div>
```

Similarly for the stats row labels.

### 3.4 JS Changes

**`_findDatasetByKCode(kcode)`** — replaces `_findDatasetByKeyword()` for gauge lookups. Matches `ds.kcode` property on each dataset (see §3.5).

**`_initGauges()`** — uses `_hudConfig.gauge1.pid` etc. for dataset lookup; uses `_hudConfig.gauge1.min/max` for fraction calculation; uses `_hudConfig.gauge1.suffix` for value display.

**`_updateGauges(tsMs)`** — same pattern: reads config per gauge rather than hardcoded constants.

**Fraction calculation:**
```js
// max=0 means use _maxSpeed
var max = cfg.max > 0 ? cfg.max : (window._maxSpeed || 120);
var fraction = (value - cfg.min) / (max - cfg.min);
```

### 3.5 Dataset k-code Embedding

In the PHP loop that builds `torqueDatasets`, add a `kcode` property to each dataset object:

```js
kcode: '<?php echo $kcode; ?>',
```

This allows `_findDatasetByKCode('kc')` to locate the RPM dataset regardless of what display label the user has assigned to it in `torque_keys`.

### 3.6 Settings Page

`settings.php` renders all groups from `torque_settings` automatically. A new **HUD Widget** section appears with the 19 settings. No changes to `settings.php` are required — the existing group-based rendering handles it.

The coolant temperature threshold logic (arc turns orange >95°C, red >105°C) remains hardcoded to gauge 2. If the user reassigns gauge 2 to a non-temperature PID, the threshold still fires but is visually meaningless. This is acceptable for the current iteration.

---

## 4. Draggable Widget + Position Memory

### 4.1 HUD Widget Drag Handle

A slim drag handle is prepended inside `#hud-widget`:

```html
<div class="hud-drag-handle" title="Drag to move">
  <span class="hud-drag-dots">⠿</span>
</div>
```

CSS:
```css
.hud-drag-handle {
  cursor: grab;
  text-align: center;
  color: rgba(0, 212, 255, 0.2);
  font-size: 10px;
  line-height: 1;
  margin: -4px -4px 6px;   /* bleeds to widget edges */
  padding: 2px 0;
  border-radius: 10px 10px 0 0;
  transition: color 0.15s;
  pointer-events: auto;
}
.hud-drag-handle:hover { color: rgba(0, 212, 255, 0.55); }
.hud-drag-handle:active { cursor: grabbing; }
```

### 4.2 pointer-events Fix

`#hud-widget` currently has `pointer-events: none`. This is changed to `pointer-events: auto` so the drag handle is interactive. The gauge and stats content divs get `pointer-events: none` so the map underneath remains clickable through the data area.

```css
#hud-widget                { pointer-events: auto; }
.hud-gauges, .hud-stats    { pointer-events: none; }
```

### 4.3 Drag Logic

The existing panel drag handler in `torquehelpers.js` is extended to include `#hud-widget` (previously excluded). The drag trigger is the `.hud-drag-handle` element rather than a `.torque-panel-header`.

The HUD widget drag code:
- Listens on `.hud-drag-handle` (mousedown / touchstart)
- Tracks mousemove/touchmove, clamps to viewport
- On mouseup/touchend: saves position to localStorage

### 4.4 Position Persistence

**Save** (on drag end, for any draggable panel):
```js
localStorage.setItem('torque-pos-' + panel.id,
  JSON.stringify({ left: panel.style.left, top: panel.style.top }));
```

**Restore** (on `$(document).ready`, before panels are shown):
```js
['hud-widget', 'vars-section', 'summary-section'].forEach(function(id) {
  var saved = localStorage.getItem('torque-pos-' + id);
  if (!saved) return;
  var pos = JSON.parse(saved);
  var el = document.getElementById(id);
  if (!el) return;
  el.style.left   = pos.left;
  el.style.top    = pos.top;
  el.style.right  = 'auto';
  el.style.bottom = 'auto';
});
```

Position is clamped to the visible viewport on restore (handles window resize between sessions).

---

## 5. db_upgrade.php

No schema migrations are required. The new `hud_*` settings are auto-seeded by `get_settings.php` on first page load after the update.

A commented documentation block is added to `db_upgrade.php`:

```php
// ── v2.1 HUD Widget Enhancements (2026-04-30) ────────────────────────────────
// New torque_settings keys added (auto-seeded by get_settings.php — no ALTER needed):
//   hud_gauge1_pid, hud_gauge1_label, hud_gauge1_min, hud_gauge1_max, hud_gauge1_suffix
//   hud_gauge2_pid, hud_gauge2_label, hud_gauge2_min, hud_gauge2_max, hud_gauge2_suffix
//   hud_gauge3_pid, hud_gauge3_label, hud_gauge3_min, hud_gauge3_max, hud_gauge3_suffix
//   hud_stat_dur_label, hud_stat_dist_label, hud_stat_fuel_pid, hud_stat_fuel_label
// ─────────────────────────────────────────────────────────────────────────────
```

---

## 6. File Map

| File | Change |
|------|--------|
| `get_settings.php` | Add 19 `hud_*` entries to `$_setting_defaults` |
| `session.php` | Build `$hudConfig` from settings; inject `_hudConfig` + `_hudSessionAvg`; add `kcode` to datasets; dynamic HUD labels; drag handle HTML |
| `static/js/torquehelpers.js` | Add `_findDatasetByKCode()`; update `_initGauges` + `_updateGauges` to use `_hudConfig`; add HUD drag logic; add localStorage save/restore for all draggable panels |
| `static/css/hud.css` | `pointer-events` fix; `.hud-drag-handle` styles |
| `settings.php` | No changes (auto-renders new `hud` group) |
| `db_upgrade.php` | Add documentation comment block only |

---

## 7. Out of Scope

- Per-gauge colour customisation (arc colours remain cyan/red/green)
- Coolant threshold (95°C/105°C) becoming configurable
- Panel position sync across browsers/devices (localStorage only)
- Mobile layout changes
- Any other settings page or PHP logic changes

---

## 8. Success Criteria

- Gauges show session-average values on page load with no chart plotting required
- Changing gauge 1 PID to `k5c` (oil temp) in settings causes the gauge to read oil temp on next page load, with correct label and scale
- HUD widget can be dragged; position is restored after page reload and after logout/login
- Variables panel and Summary panel positions are also remembered
- `php -l session.php` passes
- No new JS console errors
- All existing HUD functionality (chart hover, coolant threshold, sparklines) continues to work
