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
├── upload_batch.php           ← Plugin batch CSV upload receiver (POST multipart)
├── check_session.php          ← Session existence check for plugin pre-upload query
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
| `batch_duplicate_mode` | `$batch_duplicate_mode` | `ignore` | Plugin upload duplicate handling: `ignore` = INSERT IGNORE; `overwrite` = ON DUPLICATE KEY UPDATE |

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
- **Plugin upload auth**: `upload_batch.php` and `check_session.php` both require `auth_app.php` — same bearer token / Torque ID / user+password flow as `upload_data.php`.

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

## GPS Repair / Enrichment (Home Assistant)

Torque Pro occasionally uploads bad GPS (frozen at one point, `0,0`, or null) while OBD data
keeps changing. The GPS repair subsystem detects these rows and backfills corrected coordinates
from Home Assistant location history — **without ever overwriting raw uploaded data**.

### Components (`gps/` directory)
| File | Responsibility |
|---|---|
| `gps/GpsFunctions.php` | Pure logic: `is_valid_point()`, `haversine_m()`, `find_stale_windows()`, `confidence_for_delta()`, `accuracy_ok()` |
| `gps/LocationPoint.php` | `GpsLocationPoint` immutable value object |
| `gps/LocationProvider.php` | `GpsLocationProvider` interface — implement to add Dawarich / direct Recorder / interpolation |
| `gps/HomeAssistantProvider.php` | HA REST History API provider; `parse_states()` is static + unit-testable; attributes each point to its `entity_id` (supports comma-separated multi-entity) |
| `gps/GpsRepairWorker.php` | Orchestration: scan sessions → detect bad rows → batch HA query → accuracy-gate → upsert corrections; `stats()` + `record_heartbeat()` |
| `gps/repair.php` | CLI entry point (`--dry-run`, `--session=<id>`, `--lookback-days=N`, `--stats`, `--help`) |
| `ha_test.php` | Login-gated AJAX endpoint behind the Settings "Test Home Assistant" button; reports HTTP status + recent point count, never logs the token |
| `gps_repair_run.php` | Login-gated + CSRF AJAX endpoint; runs the worker for a single session on demand (the in-map "Repair GPS" button) |
| `tests/test_gps.php` | Standalone PHP unit tests (no framework) — `php tests/test_gps.php` |

### Data model (migrations v25 + v26 + v28 in `db_upgrade.php`)
- **`gps_corrections`** (v25) — corrected points. Unique key `(raw_table, session, torque_time_ms)` → upserts are idempotent. Stores `source` (`home_assistant`), `source_entity`, `reason` (`zero_gps`/`missing_gps`/`stale_gps`), `confidence` (`high`/`medium`/`low`), and the original `raw_lat`/`raw_lon`.
- **`gps_repair_queue`** (v25) — tracks which rows were flagged and their processing status/last_error.
- **`sessions.gps_repaired_points`** (v26) — cached count of corrections per session, refreshed by the worker.
- **`gps_corrections.corrected_speed_kmh`** (v28, DOUBLE NULL) — derived GPS speed at each repaired point (km/h). Computed by `GpsRepairWorker::compute_corrected_speeds()` in a second pass after corrections are upserted; walks the final GPS sequence (corrected where present, raw-valid otherwise) and divides haversine distance by time delta. The first corrected row of a session has no prior point → stored as NULL. Idempotent — re-running recomputes the same values. The raw `kff1001` column is never written.
- `torque_time_ms` is BIGINT to join directly against `raw_logs_*.time` (ms epoch).
- `del_session.php` deletes matching `gps_corrections` + `gps_repair_queue` rows when a session is removed.

### Read path
Both `session.php` (main map query) and `get_session_gps.php` (multi-session overlay) `LEFT JOIN gps_corrections`
and prefer `corrected_lat/lon` over raw `kff1006/kff1005`. Each has a **raw-only fallback query** so the page
never crashes if the table is missing (pre-migration). `session.php` exposes the GPS source as the 5th element
of each `_routeData` entry (`'torque'` or `'home_assistant'`) and shows a repaired count in the Data Summary panel.
`static/js/session.js` reads `_routeData[i][4]` to render an amber `route-repaired` circle layer over repaired
points, a legend entry with the repaired count, and a "GPS repaired · Home Assistant" badge in the route hover popup.
The route line is drawn as **per-segment speed-coloured lines that break at GPS dropouts** (gap when consecutive
fixes are more than `gps_route_gap_seconds` apart OR `gps_route_gap_meters` apart — both configurable in Settings)
— so it never draws a fake straight connector across missing data. Repaired
points appear as amber dots on top; **green Start / red Finish** circles mark the first/last route points
(`route-endpoints` layer). Local CSS/JS in `session.php` are cache-busted with `?v=<filemtime>` so deploys load fresh.
`export.php` appends `gps_corrected_lon`, `gps_corrected_lat`, `gps_corrected_speed_kmh`, and `gps_source` columns to CSV/JSON (raw columns untouched).
`session.php`'s main GPS query also coalesces `gc.corrected_speed_kmh` into the route speed expression — OBD `kd` still takes priority (it doesn't depend on GPS), then the repaired speed, then raw `kff1001`, then 0. So when a repaired row had both bad GPS and missing/zero OBD speed, the route now shows the derived speed instead of a 0 segment.
When a session has a GPS problem and HA repair is enabled, `session.php` shows an in-map **"Repair GPS"** button
(`$gpsRepairOffer`) that POSTs to `gps_repair_run.php` to repair just that session on demand, then reloads.

### Settings (group `gps_repair`, seeded in `get_settings.php`, editable in `settings.php`)
`ha_enabled`, `ha_base_url`, `ha_token`, `ha_entity_id` (comma-separated entities allowed),
`gps_repair_lookback_days` (14), `gps_repair_min_age_minutes` (5), `gps_ha_tolerance_seconds` (120),
`gps_ha_max_accuracy_m` (50; 0 = no limit), `gps_stale_window_seconds` (60), `gps_stale_min_speed_kmh` (10),
`gps_stale_max_movement_m` (10), `gps_repair_cron` (scheduler on/off, default on), `gps_repair_interval`
(cadence seconds, default 604800 = weekly), `gps_route_gap_seconds` (30) / `gps_route_gap_meters` (300)
(map route-line break thresholds, injected into session.js). HA token lives in the DB, never in code. The worker
writes a read-only `gps_repair_last_run` heartbeat (shown on the Settings page); the scheduler tracks `gps_repair_last_run_ts`.

**Shared helpers (DRY):** `asset_url()` and `gps_corr_join_sql()` live in `db.php`;
`GpsRepairWorker::config_from_settings()` and `HomeAssistantProvider::from_settings()` / `::is_configured()`
build the worker bootstrap once for repair.php / gps_repair_run.php / ha_test.php. The JS reuses
`_haversineKm()` from torquehelpers.js (no duplicate distance fn).

### Detection & matching
- **Invalid**: lat/lon null, `(0,0)`, or out of range → `missing_gps` / `zero_gps`.
- **Stale**: `GpsFunctions::find_stale_windows()` groups consecutive valid GPS rows whose coordinates barely drift while OBD speed (`kd`) stays above threshold. A frozen cluster must persist long enough to be meaningful (derived from `gps_stale_window_seconds`, default 60s) before rows are flagged `stale_gps`, so brief duplicate-coordinate bursts do not mark an entire drive stale. Stationary/low-speed rows are never flagged (avoids traffic-light/driveway false positives).
- **Accuracy gate**: HA points with `gps_accuracy` worse than `gps_ha_max_accuracy_m` are dropped before matching (null accuracy passes; 0 disables).
- **Confidence**: by |Torque−HA| timestamp delta — `high` ≤30s, `medium` ≤90s, else `low`.

### Operational requirements & gotchas
- **HA recorder must be recording the tracker entity.** History returns `[]` for any window before recording started. Verify the entity is included in HA `recorder:` config and **restart HA** after changing it. Use the Settings "Test Home Assistant" button or `repair.php --stats` to sanity-check.
- **Forward-only.** Only sessions driven *after* HA began recording the entity can be repaired. Earlier sessions are unrecoverable (HA Recorder retention is also ~14 days).
- **Never send `minimal_response=true`** to the HA history API — it strips `attributes` (lat/lon) from all but the first/last state. The provider requests full state history.
- **Never redact executable HA auth headers.** `ha_test.php` and `gps/HomeAssistantProvider.php` must build the auth header by concatenating the literal `Authorization: Bearer ` prefix with the saved token at runtime; redaction belongs in logs/docs only. `tests/test_gps.php` guards against placeholder text appearing in those request headers.
- **`db_upgrade.php` runs from CLI** (`docker exec p_torque php /var/www/html/db_upgrade.php`); it skips the web-auth gate under `PHP_SAPI === 'cli'`. From a browser it still requires login.
- The worker **never blocks uploads** and **never writes to HA**. If HA is down, `get_history()` returns `[]` and the row is left uncorrected (logged), to retry next run.
- Queries by **Torque row timestamp**, never "current HA location".

### Running & scheduling
```bash
docker exec p_torque php /var/www/html/gps/repair.php --dry-run --lookback-days=1   # preview
docker exec p_torque php /var/www/html/gps/repair.php --session=<id>                # one session
docker exec p_torque php /var/www/html/gps/repair.php --stats                       # read-only summary
docker exec p_torque php /var/www/html/gps/repair.php                               # full lookback, live
```
- **In-container scheduler (default):** `docker/entrypoint.sh` runs a short loop (every `GPS_REPAIR_TICK`
  seconds, default 300) calling `gps/scheduler_tick.php`, which reads **`gps_repair_cron`** (on/off) and
  **`gps_repair_interval`** (cadence: Hourly…Weekly, default Weekly) from the Settings page and runs
  `gps/repair.php` only when the interval has elapsed (tracked via `gps_repair_last_run_ts`). The schedule
  is DB-controlled — change it in Settings, no restart needed. Env `GPS_REPAIR_CRON=0` is an ops-level hard
  kill that stops the loop. Output is prefixed `[gps-repair]` in `docker logs`. No host cron required.
- **On-demand:** the in-map "Repair GPS" button (per session) via `gps_repair_run.php`. The button is
  hidden for drives older than `gps_repair_lookback_days` (default 14d) since HA Recorder history has expired.
- **Host cron (alternative):** `*/5 * * * * docker exec p_torque php /var/www/html/gps/repair.php`

### Adding another provider
Implement `GpsLocationProvider` (`get_history()` + `name()`), then instantiate it in `gps/repair.php`.
`GpsRepairWorker` depends only on the interface — no other changes needed.

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
- **`upload_batch.php` column pre-scan**: All k-code columns are verified/added to every monthly table BEFORE batch inserts begin. The ADD COLUMN loop runs once per unique k-code, not per row.
- **Device Time parsing**: `strtotime()` on Torque's `13-Mar.-2018 17:14:41.361` format — verify it returns `!== false && > 0` before using; fall back to `(int)$session_id` otherwise.
- **`_insert_batch` function**: Uses `error_log()` on failed INSERT. The function is prefixed with `_` to avoid conflicts with any future PHP built-ins.

---

## Vehicle Context

- **Toyota ATF temperature** tracked via k-code `k2182` (single key for both converter and pan sensors — hardware limitation of OBD PID 2182)
