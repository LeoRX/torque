# Open Torque Viewer

A self-hosted web application for viewing and analysing OBD2 driving data recorded by the [Torque Pro](https://play.google.com/store/apps/details?id=org.prowl.torque) Android app.

Browse sessions, plot OBD2 time-series data against interactive charts, follow your route on a Mapbox map, export data as CSV/JSON, and get AI-powered diagnostics via TorqueAI (powered by Claude).

> **Based on** [econpy/torque](https://github.com/econpy/torque) by [@econpy](https://github.com/econpy) — the original PHP receiver and viewer that made all of this possible. This fork extends it with a Docker-first deployment model, Traefik/HTTPS support, a modernised frontend, and an AI assistant.

---

## Features

- **Session browser** — calendar and list views, filterable by vehicle profile and month
- **Interactive charts** — plot any OBD2 PID over time; zoom, pan, multi-select via Chart.js
- **GPS map** — Mapbox GL route overlay with chart↔map crosshair sync; speed-coloured route line that breaks at GPS dropouts (no fake straight lines), with green/red start/finish markers
- **GPS repair** — backfills missing, zero, or frozen GPS from Home Assistant location history into a separate table (raw data never overwritten); repaired points shown as dots, runnable on a schedule or on demand
- **TorqueAI** — Claude-powered assistant with session context (OBD averages, fuel trim trends)
- **Data export** — CSV and JSON per session
- **Dark mode** — persistent, toggle in navbar
- **Mobile-responsive** — hamburger navbar on phones, viewport-relative chart and panel sizing, collapsible HUD widget
- **Docker-ready** — single `php:8.2-apache` container, credentials injected at runtime via env vars
- **Traefik-compatible** — HTTPS via Let's Encrypt, separate HTTP-only upload endpoint for Torque Pro
- **Plugin batch upload** — `upload_batch.php` receives complete trip CSV files from the companion Android plugin; `check_session.php` lets the plugin check for existing sessions before uploading

---

## Quick Start (Docker)

### 1. Clone and configure

```bash
git clone https://github.com/LeoRX/torque
cd torque
cp .env.example .env
```

Edit `.env` and fill in your database credentials:

```env
DB_HOST=host.docker.internal   # or your MariaDB hostname/IP
DB_PORT=3306
DB_USER=torque
DB_PASS=your_db_password_here
DB_NAME=torque

APP_USER=torque                # web login username
APP_PASS=your_login_password_here

TORQUE_ID=                     # optional: restrict uploads to one device
TORQUE_ID_HASH=                # optional: MD5 hash of device ID
```

### 2. Start the container

```bash
docker compose up -d
```

The container serves plain HTTP on port 80. `creds.php` is generated automatically at startup from your env vars — you never commit credentials.

### 3. Configure Torque Pro

In the Torque Pro app:
- **Settings → Data Logging & Upload → Webserver URL**: `http://YOUR_HOST/upload_data.php`
- Enable "Upload to web-server"

### 4. Plugin Upload (optional)

The companion Android plugin uploads complete trip logs retrospectively via:

- `POST /upload_batch.php` — receives `session_id`, `tracklog` (CSV), and optionally `profile` (properties file)
- `GET /check_session.php?session_id=<id>` — returns `{"exists":true/false}` so the plugin can skip already-uploaded sessions

Both endpoints use the same authentication as the main upload endpoint. The **Settings → Plugin Upload** section controls how duplicate data points are handled when a session was already received via real-time upload.

---

## Running Behind Traefik

The included `docker-compose.yml` has Traefik labels pre-configured for:

| Route | Entrypoint | Notes |
|-------|-----------|-------|
| `torque.yourdomain.com` | HTTPS (websecure) | Web UI, auto Let's Encrypt |
| `torqueupload.yourdomain.com` | HTTP (web) | Default — no auth required |
| `torqueupload.yourdomain.com` | HTTPS (websecure) | With `BEARER_TOKEN` set (see below) |

Update the domain labels in `docker-compose.yml` and join your Traefik network:

```yaml
networks:
  default:
    external: true
    name: your_traefik_network
```

---

## HTTPS Uploads with Bearer Token

By default the upload endpoint runs on plain HTTP. To serve it over HTTPS:

**1. Generate a token**

```bash
openssl rand -hex 32
```

**2. Add it to `.env`**

```env
BEARER_TOKEN=your_generated_token_here
```

**3. Configure Torque Pro**

In the app: **Settings → Data Logging & Upload → Web Logging**
- Enable "Send Authorization Header"
- Paste the same token
- Change the Webserver URL to `https://torqueupload.yourdomain.com/upload_data.php`

**4. Apply the HTTPS upload Traefik override**

```bash
docker compose -f docker-compose.yml -f docker-compose.https-upload.yml up -d
```

> **Non-Traefik users**: The bearer gate lives in PHP (`auth_app.php`) and works with any reverse proxy or direct HTTPS setup — just set `BEARER_TOKEN` and terminate TLS however you prefer.

---

## Database Setup

### MariaDB / MySQL

```sql
CREATE DATABASE torque CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'torque'@'%' IDENTIFIED BY 'your_password';
GRANT ALL PRIVILEGES ON torque.* TO 'torque'@'%';
```

The app creates tables automatically on first upload. Run the schema migration utility if upgrading from an older installation:

```bash
docker compose exec torque php /var/www/html/db_upgrade.php
```

---

## Configuration (Settings UI)

After logging in, go to **Settings** to configure:

| Setting | Notes |
|---------|-------|
| Mapbox token | Required for GPS map. Free tier at [mapbox.com](https://mapbox.com) |
| Display timezone | IANA timezone string, e.g. `Australia/Melbourne` |
| Unit system | km/h ↔ mph, Celsius ↔ Fahrenheit |
| Min session size | Hide sessions with fewer data points (filters noise) |
| TorqueAI | Enable + paste your [Anthropic API key](https://console.anthropic.com) |
| GPS Repair | Enable + Home Assistant URL, token, and entity (see below) |

---

## GPS Repair (Home Assistant)

Torque Pro sometimes uploads bad GPS — coordinates stuck at `0,0`, missing, or frozen at one
spot while the car keeps moving. The GPS repair subsystem detects those rows and backfills
corrected coordinates from your **Home Assistant** location history, **without ever modifying the
raw uploaded data**. Corrected points are stored in a separate `gps_corrections` table and shown
on the map as amber dots; the route always prefers a correction when one exists, otherwise raw GPS.

### Setup

1. **In Home Assistant**, make sure the Recorder is storing your device tracker. Add (or confirm)
   your tracker in `configuration.yaml` and **restart HA**:
   ```yaml
   recorder:
     include:
       entities:
         - device_tracker.your_phone   # or person.your_name
   ```
2. **Create a long-lived access token** in HA: Profile → Long-Lived Access Tokens.
3. **In Open Torque Viewer → Settings → GPS Repair**, set:
   - **Enable GPS Repair**
   - **Home Assistant URL** (e.g. `https://ha.example.com`, no trailing slash)
   - **HA Access Token** (the token from step 2)
   - **HA Entity ID** (e.g. `device_tracker.your_phone`; comma-separate multiple)
   - Use **Test Home Assistant** to confirm connectivity and that recent points are returned.

The remaining tuning options (match tolerance, accuracy gate, stale-GPS thresholds, route-line
gap thresholds, and the repair schedule) all have sensible defaults and are editable on the same page.

### How repairs run

- **Scheduled (default):** the container runs the repair job automatically. Turn it on/off and pick
  the cadence (Hourly … Weekly, default **Weekly**) under **Settings → GPS Repair**. Container env
  `GPS_REPAIR_CRON=0` hard-disables it; `GPS_REPAIR_TICK` (seconds, default 300) tunes how often the
  scheduler checks whether it's time to run.
- **On demand:** open a session that has a GPS problem and click the **Repair GPS** button on the map.
- **Manually / CLI:**
  ```bash
  docker compose exec torque php /var/www/html/gps/repair.php --dry-run --lookback-days=1   # preview
  docker compose exec torque php /var/www/html/gps/repair.php --session=<id>                # one session
  docker compose exec torque php /var/www/html/gps/repair.php --stats                       # show counts
  docker compose exec torque php /var/www/html/gps/repair.php                               # full run
  ```

### Good to know

- **Forward-only.** Only drives that happened *after* HA started recording the tracker can be
  repaired. Earlier drives have no history to draw from (and HA Recorder retention is typically ~14 days).
- **Raw data is never overwritten** — corrections live in their own table and are applied at read time.
- **Uploads never wait on Home Assistant.** Repair runs separately; if HA is unreachable the rows are
  simply left for the next run.
- The location source is pluggable (a `GpsLocationProvider` interface), so other providers
  (e.g. Dawarich) can be added later without touching the repair worker.

Requires migrations v25/v26 — run `db_upgrade.php` (see Database Setup) after upgrading.

---

## Running Without Docker

1. Copy `creds.example.php` → `creds.php` and fill in real values
2. Ensure PHP 8.2+ with `mysqli` and `mbstring` extensions
3. Point your web server document root at the repo directory
4. Create `.htaccess` if using Apache (mod_rewrite recommended)

---

## Project Structure

```
torque/
├── upload_data.php        ← Torque Pro upload receiver
├── session.php            ← Main UI: map + chart + summary
├── auth_functions.php     ← Login / session auth
├── claude_chat.php        ← TorqueAI (Anthropic API proxy)
├── db.php                 ← MySQLi connection + query helpers
├── get_settings.php       ← Settings loader + tz_date() helper
├── settings.php           ← Settings UI
├── export.php             ← CSV / JSON export
├── gps/                   ← GPS repair subsystem (detection, HA provider, worker, CLI)
│   └── repair.php         ← Repair worker CLI entry point
├── docker/
│   └── entrypoint.sh      ← Generates creds.php from env vars; starts the repair scheduler loop
├── static/
│   ├── css/torque.css
│   └── js/torquehelpers.js
├── Dockerfile
├── docker-compose.yml
└── .env.example
```

---

## Credits

This project is a fork of **[econpy/torque](https://github.com/econpy/torque)** by [@econpy](https://github.com/econpy), which provided the original Torque Pro upload receiver and PHP viewer. Without that foundation, none of this would exist.

Extended by [@LeoRX](https://github.com/LeoRX) with:
- Docker / `php:8.2-apache` containerisation
- Environment-variable credential injection
- Traefik + Let's Encrypt integration
- Mapbox GL JS map with chart↔map crosshair sync
- Bootstrap 5 dark-mode UI
- TorqueAI assistant (Claude API)
- GPS repair / enrichment from Home Assistant location history
- GitHub Actions CI/CD (PHP lint, secret scan, multi-arch Docker build)

---

## License

See the original [econpy/torque](https://github.com/econpy/torque) repository for licence terms. Extended work by LeoRX is provided under the same terms.
