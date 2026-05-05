# CLAUDE.md

## Project Overview

**Open Torque Viewer** — A PHP web application that displays OBD2 driving data recorded by the
Torque Pro Android app. Provides session browsing, interactive charting, GPS map visualisation,
data export, and an AI assistant (TorqueAI powered by Claude).

**GitHub**: https://github.com/LeoRX/torque (fork of https://github.com/econpy/torque)
**Docker Hub**: `leorx/open-torque-viewer` (`:arm64` for ARM NAS; `:latest` = multi-arch amd64+arm64)

> Private deployment details (live URLs, IPs, Portainer access, container names) are in
> `.claude/deployment.md` — gitignored, local only.

---

## Stack

| Layer | Technology |
|---|---|
| Container | `php:8.2-apache` — Apache + mod_php, single process, no FPM/supervisord |
| Reverse proxy | Traefik v2 — TLS via Let's Encrypt |
| Backend | PHP 8.2 |
| Database | MariaDB 11.4 (external, configured via env vars) |
| Frontend | Bootstrap 5.3.3, jQuery 3.7.1, Chart.js 4.4, Mapbox GL JS, Tom Select 2.3 |
| AI assistant | Anthropic Claude API (`claude_chat.php`) |

---

## Directory Structure

```
torque/
├── CLAUDE.md                  ← This file (public coding context)
├── creds.php                  ← DB credentials + login users (GITIGNORED — never commit)
├── creds.example.php          ← Template for creds.php
├── db.php                     ← MySQLi connection + helper functions (quote_name, quote_value)
├── get_settings.php           ← Loads torque_settings from DB; exposes $display_timezone, tz_date(), and all hud_* vars
├── get_sessions.php           ← Builds session lists for the UI ($sids, $seshdates, etc.)
├── get_sessions_ajax.php      ← AJAX endpoint for calendar/session browser
├── get_session_gps.php        ← Returns GPS [lon,lat,speed,time] JSON for map rendering
├── get_columns.php            ← Returns available OBD2 PIDs (k-codes) for a session
├── session.php                ← Main page: map + chart + HUD widget + data summary; injects _hudConfig, _hudSessionAvg
├── plot.php                   ← Fetches OBD time-series data for Chart.js
├── auth_user.php              ← Login page HTML + session auth logic
├── auth_app.php               ← Torque Pro app authentication (upload auth)
├── auth_functions.php         ← get_user(), auth_user(), auth_id(), logout_user()
├── claude_chat.php            ← AI assistant API proxy (Anthropic messages endpoint)
├── settings.php               ← Settings UI + form handler
├── pid_edit.php               ← PID description editor UI
├── pid_commit.php             ← AJAX handler for PID edits
├── merge_sessions.php         ← Session merge UI
├── del_session.php            ← Session deletion handler
├── export.php                 ← CSV / JSON export
├── upload_data.php            ← Torque Pro data upload receiver
├── url.php                    ← URL helpers
├── index.php                  ← Redirect to session.php
├── db_upgrade.php             ← Schema migration utility
├── parse_functions.php        ← Data parsing helpers
├── static/
│   ├── css/torque.css         ← All custom CSS (Bootstrap 5 overrides, dark mode, map popup, responsive breakpoints)
│   ├── css/hud.css            ← Dark Racing HUD theme: design tokens, navbar, gauges, panels, drag handle, mobile layout
│   └── js/torquehelpers.js    ← Tom Select init, session AJAX, chart helpers, HUD gauge system, panel drag, mobile collapse
└── data/                      ← Runtime data directory (gitignored)
```

---

## Database Schema

### Connection
Configured via environment variables → `entrypoint.sh` generates `creds.php` at container startup.
For non-Docker use, copy `creds.example.php` → `creds.php` and fill in values.

### Core Tables

**`raw_logs_YYYY_MM`** — Monthly-partitioned OBD2 data (e.g., `raw_logs_2024_03`)
- `session` — Session ID (ms epoch timestamp string, e.g. `1709123456789`)
- `time` — Datapoint timestamp (ms epoch)
- `k*` columns — OBD2 parameter values (see k-code reference below)
- Index: `idx_session_time` on (`session`, `time`)

**`sessions`** — Session metadata
- `session`, `timestart`, `timeend`, `sessionsize`
- `profileName`, `profileFuelType`, `profileWeight`, `profileVe`, `profileFuelCost`
- `id` (device hash), `v` (app version), `eml` (uploader email)

**`torque_keys`** — PID/k-code metadata
- `id` (k-code), `description`, `units`, `type`, `populated`, `favorite`

**`torque_settings`** — App settings (key/value store)
- All settings are seed-defaulted by `get_settings.php` on first run

**`torque_users`** — Bcrypt-hashed user accounts (auth preferred over creds.php array)

### Session ID Format
- Millisecond Unix epoch (10–15 digits)
- Divide by 1000 → Unix timestamp in seconds
- Year/month determines which `raw_logs_YYYY_MM` table to query

---

## Key K-Code Reference

| K-Code | Name | Units |
|--------|------|-------|
| `kc` | Engine RPM | rpm |
| `k4` | Engine Load | % |
| `k5` | Coolant Temperature | °C |
| `k5c` | Oil Temperature | °C |
| `kf` | Intake Air Temp | °C |
| `kb` | Intake Manifold Pressure | kPa |
| `k10` | Mass Air Flow | g/s |
| `kd` | OBD Speed | km/h |
| `k6` / `k7` | Short/Long-term Fuel Trim B1 | % |
| `k8` / `k9` | Short/Long-term Fuel Trim B2 | % |
| `k2182` | ATF Temperature | °C |
| `kff1005` / `kff1006` | GPS Longitude / Latitude | ° |
| `kff1001` | GPS Speed | km/h |
| `kff1010` | GPS Altitude | m |
| `kff5203` | Fuel Consumption | L/100km |
| `kff1226` | Horsepower | hp |

Fuel trim interpretation: negative = running rich (ECU removing fuel); positive = running lean
(ECU adding fuel). LT trim B2 beyond ±4–5% is fault territory (DTC P0172/P0175).

---

## Settings System

All settings are stored in `torque_settings` and loaded by `get_settings.php`.

| Setting Key | PHP Variable | Default | Notes |
|---|---|---|---|
| `display_timezone` | `$display_timezone` | `Australia/Melbourne` | IANA timezone; validated via `DateTimeZone::listIdentifiers()` |
| `min_session_size` | `$min_session_size` | `20` | Hide sessions with fewer data points |
| `mapbox_token` | `$mapbox_token` | `''` | Required for map features |
| `claude_enabled` | `$claude_enabled` | `0` | Enable AI assistant |
| `claude_api_key` | `$claude_api_key` | `''` | Anthropic API key (stored in DB, not files) |
| `claude_model` | `$claude_model` | `claude-haiku-4-5-20251001` | Model selection |
| `source_is_fahrenheit` | `$source_is_fahrenheit` | `0` | Source data unit |
| `use_miles` | `$use_miles` | `0` | Display unit |
| `hud_gauge1_pid` | `$hud_gauge1_pid` | `kc` | k-code for HUD gauge 1 (RPM arc) |
| `hud_gauge1_label` | `$hud_gauge1_label` | `RPM` | Label below gauge 1 arc |
| `hud_gauge1_min` | `$hud_gauge1_min` | `0` | Scale minimum for gauge 1 |
| `hud_gauge1_max` | `$hud_gauge1_max` | `8000` | Scale maximum for gauge 1 (0 = use `_maxSpeed`) |
| `hud_gauge1_suffix` | `$hud_gauge1_suffix` | `` | Appended to displayed value |
| `hud_gauge2_pid` | `$hud_gauge2_pid` | `k5` | k-code for HUD gauge 2 (Coolant arc) |
| `hud_gauge2_label` | `$hud_gauge2_label` | `COOLANT` | Label below gauge 2 arc |
| `hud_gauge2_min` | `$hud_gauge2_min` | `40` | Scale minimum for gauge 2 |
| `hud_gauge2_max` | `$hud_gauge2_max` | `120` | Scale maximum for gauge 2 |
| `hud_gauge2_suffix` | `$hud_gauge2_suffix` | `°` | Appended to displayed value |
| `hud_gauge3_pid` | `$hud_gauge3_pid` | `kd` | k-code for HUD gauge 3 (Speed arc) |
| `hud_gauge3_label` | `$hud_gauge3_label` | `km/h` | Label below gauge 3 arc |
| `hud_gauge3_min` | `$hud_gauge3_min` | `0` | Scale minimum for gauge 3 |
| `hud_gauge3_max` | `$hud_gauge3_max` | `0` | Scale maximum for gauge 3 (0 = use `_maxSpeed`) |
| `hud_gauge3_suffix` | `$hud_gauge3_suffix` | `` | Appended to displayed value |
| `hud_stat_dur_label` | `$hud_stat_dur_label` | `DURATION` | Duration stat label |
| `hud_stat_dist_label` | `$hud_stat_dist_label` | `DISTANCE` | Distance stat label |
| `hud_stat_fuel_pid` | `$hud_stat_fuel_pid` | `kff5203` | k-code for fuel stat |
| `hud_stat_fuel_label` | `$hud_stat_fuel_label` | `L/100km` | Fuel stat label |

### `tz_date()` Helper (in `get_settings.php`)
```php
tz_date(string $format, int $ts, string $tz = 'UTC'): string
```
Use this everywhere a timestamp is displayed. **Never use `date()` directly for user-facing output.**

---

## Authentication

- **Login**: POST to `session.php` with fields `user` and `pass`
- **Auth flow**: `auth_functions.php::auth_user()` checks `torque_users` DB table first (bcrypt), then falls back to `$users[]` array in `creds.php`
- **Session**: `$_SESSION['torque_logged_in']` + `$_SESSION['torque_user']`
- **Torque Pro upload auth**: `auth_app.php` via `auth_id()` — checks `$torque_id` / `$torque_id_hash` in `creds.php`
- **Bearer token gate**: `auth_app.php` checks `$bearer_token` (from `creds.php`) before any other auth. If set, requires `Authorization: Bearer <token>` header — enables HTTPS uploads. Controlled by `BEARER_TOKEN` env var; empty = disabled (backwards-compatible).

---

## Frontend Architecture

**All CDNs — no local vendor files:**
- Bootstrap 5.3.3 (CSS + JS bundle)
- jQuery 3.7.1
- Chart.js 4.4 + chartjs-adapter-date-fns + chartjs-plugin-zoom
- Mapbox GL JS (latest)
- Tom Select 2.3
- Peity.js (sparklines, keep as-is)

**Dark mode**: `data-bs-theme="dark"` on `<html>`. Toggle button in navbar sets `localStorage.theme`.

**Tom Select init** (`torquehelpers.js`):
- `#seshidtag`: session picker, `onChange` submits form
- `#selprofile`, `#selyearmonth`: filter selects, no search
- `#plot_data`: multi-select for OBD variable selection — **must use `dropdownParent: 'body'`** (panel overflow clips dropdown otherwise)

**Chart ↔ Map sync** (`session.php`):
- `_routeData` = `[lon, lat, speed, time_ms]` array (GPS query includes `time` column)
- Chart hover → `_showMapDot(tsMs)` moves a Mapbox Marker along the route
- Map route hover → `_nearestGpsPoint()` → sets `window._mapHoverTs` → Chart.js `mapCrosshair` plugin draws vertical line
- Map popup theming: use `_applyPopupTheme()` with inline styles (not CSS — Mapbox injects its own stylesheet at runtime which overrides static CSS even with `!important`)
- `MutationObserver` on `document.documentElement` watches `data-bs-theme` to re-apply popup theme on toggle

**Navbar** (`session.php`):
- Uses `navbar-expand-md` — below 768px collapses to hamburger. Always-visible: brand, profile select, calendar button. Hidden behind toggle: merge/delete, all icon buttons, username.
- The collapse panel auto-closes when any action button is tapped (delegated listener on `#navbar-action-btns` in `torquehelpers.js`).

**Responsive layout** (`torque.css`, `hud.css`):
- `--navbar-height` is defined **only in `hud.css`** (46px) — it was removed from `torque.css` to eliminate a conflict. `torquehelpers.js` reads it via `getComputedStyle` into `_navbarH` at runtime.
- Floating panels use `min(Npx, calc(100vw - 16px))` widths — they never overflow the viewport.
- Chart height: `min(300px, 38vh)`. Map bottom tracks chart height via matching `body.chart-open #map-canvas` rule.
- Two responsive breakpoints: `@media (max-width: 767px)` and `@media (max-width: 480px)`.

**HUD Widget** (`#hud-widget`, `static/css/hud.css`, `torquehelpers.js`):
- Three SVG arc gauges (cyan/red/green) + three stat cells (duration, distance, fuel)
- **Always-on**: `session.php` injects `_hudConfig` (gauge PIDs/labels/scales from settings) and `_hudSessionAvg` (SQL `AVG()` per PID) into every session page. `_initGauges()` populates arcs from session averages on load; `_updateGauges(tsMs)` takes over on chart hover; mouseleave returns to averages (not zero).
- **Dataset lookup**: each Chart.js dataset has a `kcode` property (raw k-code e.g. `kc`). `_findDatasetByKCode(kcode)` matches on this — reliable regardless of display label. `_findDatasetByKeyword()` still exists but is no longer used by the gauge system.
- **Draggable**: `.hud-drag-handle` (braille dots ⠿) at top of widget triggers mouse/touch drag. `#hud-widget` has `pointer-events: auto`; `.hud-gauges` and `.hud-stats` have `pointer-events: none` so the map remains clickable through the data area.
- **Mobile**: on screens ≤767px the widget repositions to bottom-left, starts collapsed (`.hud-collapsed` class added on load), and exposes a `#hud-collapse-btn` chevron to toggle visibility. `body.chart-open` pushes it above the chart strip via CSS.
- **Position memory**: all three floating panels (`hud-widget`, `vars-section`, `summary-section`) save position to `localStorage` key `torque-pos-{id}` on drag-end and restore on `$(document).ready` with viewport clamping. Drag clamping uses the panel's actual `offsetWidth`/`offsetHeight` — not a hardcoded pixel margin.
- **Coolant threshold**: gauge 2 arc colour changes orange >95°C, red >105°C — hardcoded to gauge 2 regardless of which PID is configured there.

---

## AI Assistant (TorqueAI)

- **Endpoint**: `claude_chat.php` — POST `{ message, history, session_id }`
- **Model**: Configured via settings (`$claude_model`)
- **Context injected**: Current session OBD averages, LT fuel trim trend (last 12 months), DB stats
- **System prompt identity**: "TorqueAI" — automotive data assistant

---

## CI/CD

- **Trigger**: push to `main` branch
- **Steps**: PHP lint (`php -l`) → secret scan → multi-arch Docker build (amd64 + arm64) → push to Docker Hub
- **Tags pushed**: `:latest`, `:YYYY-MM-DD`, `:arm64` (single-platform, for ARM NAS compatibility)
- **Registry cache**: `leorx/open-torque-viewer:buildcache`
- **Secrets required**: `DOCKERHUB_USERNAME`, `DOCKERHUB_TOKEN` (set in GitHub repo settings)

---

## Before Making Any Changes

**Always sync with remote before starting work:**

```bash
git fetch origin
git status        # confirm clean working tree
git rebase origin/main   # or origin/<current-branch> if already on a feature branch
```

If the rebase has conflicts, resolve them before proceeding. Never start editing files on a stale local branch.

---

## Common Pitfalls

- **Monthly table routing**: Always derive `raw_logs_YYYY_MM` from `session` ID via `date('Y', intdiv($session_id, 1000))`. Never assume a fixed table name.
- **Fuel trim B2 uses `k9`** (long-term) and `k8` (short-term). B1 uses `k7` / `k6`.
- **GPS must include `time` column** in queries — the chart↔map crosshair sync requires timestamps in `_routeData[3]`.
- **Profile filter**: Skip `''` and `'Not Specified'` entries. "All Profiles" uses SQL wildcard `%`.
- **Session ordering**: Always `ORDER BY session DESC` for newest-first.
- **`creds.php` is gitignored** — never tracked. Copy from `creds.example.php` or let `entrypoint.sh` generate it.
- **Mapbox popup styling**: Use JS inline styles via `_applyPopupTheme()`, not CSS classes. Mapbox's runtime stylesheet wins specificity battles.
- **Tom Select in panels**: Always set `dropdownParent: 'body'` to escape `overflow: hidden` panels.
- **Never use `date()` directly** for user-facing timestamps — always use `tz_date()`.
- **All DB queries**: use `quote_name()`/`quote_value()` from `db.php`. Never raw string interpolation. This includes every table identifier (`$db_table_full`, `$db_sessions_table`, `$db_keys_table`, `$newest_table`, `$db_table_name`) and INFORMATION_SCHEMA string values (`table_schema`, `table_name` literals). `quote_name()` wraps in backticks; `quote_value()` escapes and wraps in single quotes.
- **`_hudConfig` / `_hudSessionAvg` scope**: these are injected in their own `<script>` block inside `<?php if ($setZoomManually === 0): ?>` — NOT inside the `$var1 != ""` block that only runs when chart variables are plotted. They must remain in the always-emitted block so always-on gauges work before any variables are plotted.
- **HUD avg query placement**: the `AVG()` SQL query for `_hudSessionAvg` must run **before** `mysqli_close($con)` (currently line ~142 in `session.php`). Don't move it below the connection close.
- **`--navbar-height` single source**: defined only in `hud.css` as `46px`. Do NOT re-add it to `torque.css` — that caused a conflict where 58px overrode the correct 46px. `torquehelpers.js` reads it via `getComputedStyle` into `_navbarH`.
- **Navbar is `navbar-expand-md`**: collapses below 768px. Action buttons live inside `#navbarCollapse` / `#navbar-action-btns`. If you add a new navbar button it must go inside that div or it won't appear on desktop.
- **`plot.php` named arrays**: chart variable data is stored in 11 named indexed arrays — `$plotVar`, `$plotData`, `$plotMeasurand`, `$plotSpark`, `$plotLabel`, `$plotSparkData`, `$plotMax`, `$plotMin`, `$plotAvg`, `$plotPcnt25`, `$plotPcnt75` — all keyed from `$i = 1`. Do NOT reintroduce PHP variable variables (`${'v'.$i}` etc.). `session.php` re-initialises `$plotVar[]` after `include plot.php` (safe — same GET/POST source); `$plotData`, `$plotLabel`, etc. are untouched. `$var1 = $plotVar[1] ?? ""` is a kept alias for the many `if ($var1 != "")` guards throughout `session.php` — do not remove it.
- **Chart height and HUD mobile bottom are coupled**: `hud.css` has `body.chart-open #hud-widget { bottom: calc(min(240px, 38vh) + 8px) }` — this must match the chart height in the `@media (max-width: 767px)` block in `torque.css`. Keep them in sync if you change chart height.

---

## Vehicle Context

- **Toyota ATF temperature** tracked via k-code `k2182` (single key for both converter and pan sensors — hardware limitation of OBD PID 2182)
