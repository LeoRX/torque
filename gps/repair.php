#!/usr/bin/env php
<?php
// GPS repair CLI worker.
//
// Usage:
//   php gps/repair.php                         # repair last N days (per settings)
//   php gps/repair.php --dry-run               # preview without writing to DB
//   php gps/repair.php --session=1234567890123 # repair one session ID
//   php gps/repair.php --lookback-days=7       # override lookback period
//   php gps/repair.php --help

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only.\n");
}

// Change to repo root so relative includes in db.php etc. resolve correctly
chdir(__DIR__ . '/..');

require_once 'db.php';
require_once 'get_settings.php';
require_once 'gps/GpsFunctions.php';
require_once 'gps/LocationPoint.php';
require_once 'gps/LocationProvider.php';
require_once 'gps/HomeAssistantProvider.php';
require_once 'gps/GpsRepairWorker.php';

$opts = getopt('', ['dry-run', 'session:', 'lookback-days:', 'help']);

if (isset($opts['help'])) {
    echo "GPS Repair Worker — repair bad/stale Torque GPS from Home Assistant history\n\n";
    echo "Usage: php gps/repair.php [options]\n\n";
    echo "  --dry-run          Preview repairs without writing to the database\n";
    echo "  --session=<id>     Repair a single session ID only\n";
    echo "  --lookback-days=N  Override the configured lookback period (default: 14)\n";
    echo "  --help             Show this message\n\n";
    echo "Configure HA URL, token, and entity in Settings → GPS Repair.\n";
    exit(0);
}

$dry_run = isset($opts['dry-run']);
if ($dry_run) echo "[DRY-RUN] No writes will occur.\n";

// Load settings (seeded by get_settings.php include above)
$ha_enabled  = !empty($settings['ha_enabled']) && $settings['ha_enabled'] !== '0';
$ha_base_url = trim($settings['ha_base_url'] ?? '');
$ha_token    = trim($settings['ha_token']    ?? '');
$ha_entity   = trim($settings['ha_entity_id'] ?? 'device_tracker.sm_s938b');

if (!$ha_enabled && !$dry_run) {
    echo "GPS repair is disabled. Enable it in Settings → GPS Repair, or pass --dry-run to preview.\n";
    exit(0);
}
if (!$ha_base_url || !$ha_token) {
    echo "Error: ha_base_url and ha_token must be configured in Settings → GPS Repair.\n";
    exit(1);
}

$provider = new HomeAssistantProvider($ha_base_url, $ha_token, $ha_entity);

$cfg = [
    'db_table'              => $db_table,
    'db_sessions_table'     => $db_sessions_table,
    'lookback_days'         => (int)  ($settings['gps_repair_lookback_days']   ?? 14),
    'min_age_minutes'       => (int)  ($settings['gps_repair_min_age_minutes'] ?? 5),
    'ha_tolerance_seconds'  => (int)  ($settings['gps_ha_tolerance_seconds']   ?? 120),
    'stale_window_seconds'  => (float)($settings['gps_stale_window_seconds']   ?? 60),
    'stale_min_speed_kmh'   => (float)($settings['gps_stale_min_speed_kmh']    ?? 10),
    'stale_max_movement_m'  => (float)($settings['gps_stale_max_movement_m']   ?? 10),
];

$worker = new GpsRepairWorker($con, $provider, $cfg, $dry_run);

$run_opts = [];
if (isset($opts['session']))       $run_opts['session']       = $opts['session'];
if (isset($opts['lookback-days'])) $run_opts['lookback_days'] = (int)$opts['lookback-days'];

$worker->run($run_opts);
mysqli_close($con);
