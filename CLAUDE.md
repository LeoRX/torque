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
├── get_settings.php           ← Loads torque_settings from DB; exposes $display_timezone, tz_date()
├── get_sessions.php           ← Builds session lists for the UI ($sids, $seshdates, etc.)
├── get_sessions_ajax.php      ← AJAX endpoint for calendar/session browser
├── get_session_gps.php        ← Returns GPS [lon,lat,speed,time] JSON for map rendering
├── get_columns.php            ← Returns available OBD2 PIDs (k-codes) for a session
├── session.php                ← Main page: map + chart + data summary (HTML output ~500 lines)
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
│   ├── css/torque.css         ← All custom CSS (Bootstrap 5 overrides, dark mode, map popup)
│   └── js/torquehelpers.js    ← Tom Select init, session AJAX, chart helpers
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
- **All DB queries**: use `quote_name()`/`quote_value()` from `db.php`. Never raw string interpolation.

---

## Vehicle Context

- **Toyota ATF temperature** tracked via k-code `k2182` (single key for both converter and pan sensors — hardware limitation of OBD PID 2182)
