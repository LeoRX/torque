# Open Torque Viewer

A self-hosted web application for viewing and analysing OBD2 driving data recorded by the [Torque Pro](https://play.google.com/store/apps/details?id=org.prowl.torque) Android app.

Browse sessions, plot OBD2 time-series data against interactive charts, follow your route on a Mapbox map, export data as CSV/JSON, and get AI-powered diagnostics via TorqueAI (powered by Claude).

> **Based on** [econpy/torque](https://github.com/econpy/torque) by [@econpy](https://github.com/econpy) — the original PHP receiver and viewer that made all of this possible. This fork extends it with a Docker-first deployment model, Traefik/HTTPS support, a modernised frontend, and an AI assistant.

---

## Features

- **Session browser** — calendar and list views, filterable by vehicle profile and month
- **Interactive charts** — plot any OBD2 PID over time; zoom, pan, multi-select via Chart.js
- **GPS map** — Mapbox GL route overlay with chart↔map crosshair sync
- **TorqueAI** — Claude-powered assistant with session context (OBD averages, fuel trim trends)
- **Data export** — CSV and JSON per session
- **Dark mode** — persistent, toggle in navbar
- **Mobile-responsive** — hamburger navbar on phones, viewport-relative chart and panel sizing, collapsible HUD widget
- **Docker-ready** — single `php:8.2-apache` container, credentials injected at runtime via env vars
- **Traefik-compatible** — HTTPS via Let's Encrypt, separate HTTP-only upload endpoint for Torque Pro

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
├── docker/
│   └── entrypoint.sh      ← Generates creds.php from env vars at startup
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
- GitHub Actions CI/CD (PHP lint, secret scan, multi-arch Docker build)

---

## License

See the original [econpy/torque](https://github.com/econpy/torque) repository for licence terms. Extended work by LeoRX is provided under the same terms.
