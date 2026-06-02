#!/usr/bin/env php
<?php
// Scheduler tick — invoked frequently by the container loop (entrypoint.sh).
// Decides, from DB settings, whether it's time to run the GPS repair job, so the
// cadence is controlled from the Settings page (no container restart needed).

if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only.\n"); }

chdir(__DIR__ . '/..');
require 'db.php';
require 'get_settings.php';

$cron_on = !empty($settings['gps_repair_cron']) && $settings['gps_repair_cron'] !== '0';
$ha_on   = !empty($settings['ha_enabled'])      && $settings['ha_enabled']      !== '0';
if (!$cron_on || !$ha_on) { mysqli_close($con); exit(0); }

// Don't bother running (and don't advance the clock) if HA isn't fully configured.
if (trim($settings['ha_base_url'] ?? '') === '' || trim($settings['ha_token'] ?? '') === ''
    || trim($settings['ha_entity_id'] ?? '') === '') {
    mysqli_close($con);
    exit(0);
}

$interval = max(300, (int)($settings['gps_repair_interval'] ?? 604800));
$last     = (int)($settings['gps_repair_last_run_ts'] ?? 0);
$now      = time();
if (($now - $last) < $interval) { mysqli_close($con); exit(0); }

echo "[scheduler] interval elapsed (" . $interval . "s) — running repair\n";
$rc = 0;
passthru(PHP_BINARY . ' ' . escapeshellarg(__DIR__ . '/repair.php'), $rc);

// Advance the run clock ONLY on success, so a transient failure (exit != 0) is
// retried on the next tick instead of being suppressed for a whole interval.
if ($rc === 0) {
    mysqli_query($con,
        "INSERT INTO torque_settings (setting_key, setting_value, setting_type, setting_label, setting_group)"
        . " VALUES ('gps_repair_last_run_ts', " . quote_value((string)$now) . ", 'integer', 'GPS Repair Last Run TS', 'gps_repair')"
        . " ON DUPLICATE KEY UPDATE setting_value = " . quote_value((string)$now));
} else {
    echo "[scheduler] repair exited with code $rc — will retry next tick\n";
}
mysqli_close($con);
