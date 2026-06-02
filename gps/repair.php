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

$opts = getopt('', ['dry-run', 'session:', 'lookback-days:', 'stats', 'help']);

if (isset($opts['help'])) {
    echo "GPS Repair Worker — repair bad/stale Torque GPS from Home Assistant history\n\n";
    echo "Usage: php gps/repair.php [options]\n\n";
    echo "  --dry-run          Preview repairs without writing to the database\n";
    echo "  --session=<id>     Repair a single session ID only\n";
    echo "  --lookback-days=N  Override the configured lookback period (default: 14)\n";
    echo "  --stats            Print correction/queue statistics and exit (read-only)\n";
    echo "  --help             Show this message\n\n";
    echo "Configure HA URL, token, and entity in Settings → GPS Repair.\n";
    exit(0);
}

$dry_run = isset($opts['dry-run']);
$stats   = isset($opts['stats']);

$cfg      = GpsRepairWorker::config_from_settings($settings, $db_table, $db_sessions_table);
$provider = HomeAssistantProvider::from_settings($settings);

// --stats is read-only and needs no HA connectivity.
if ($stats) {
    $lookback = isset($opts['lookback-days']) ? (int)$opts['lookback-days'] : (int)$cfg['lookback_days'];
    (new GpsRepairWorker($con, $provider, $cfg, true))->stats($lookback);
    mysqli_close($con);
    exit(0);
}

if ($dry_run) echo "[DRY-RUN] No writes will occur.\n";

$ha_enabled = !empty($settings['ha_enabled']) && $settings['ha_enabled'] !== '0';
if (!$ha_enabled && !$dry_run) {
    echo "GPS repair is disabled. Enable it in Settings → GPS Repair, or pass --dry-run to preview.\n";
    exit(0);
}
if (!HomeAssistantProvider::is_configured($settings)) {
    echo "Error: ha_base_url, ha_token, and ha_entity_id must be configured in Settings → GPS Repair.\n";
    exit(1);
}

$worker = new GpsRepairWorker($con, $provider, $cfg, $dry_run);

$run_opts = [];
if (isset($opts['session']))       $run_opts['session']       = $opts['session'];
if (isset($opts['lookback-days'])) $run_opts['lookback_days'] = (int)$opts['lookback-days'];

$worker->run($run_opts);
mysqli_close($con);
