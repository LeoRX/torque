<?php
// AJAX endpoint: run the GPS repair worker for a single session on demand.
// Login-gated + CSRF-protected. Returns JSON { corrected, unresolved } or { error }.

require_once('./db.php');
require_once('./get_settings.php');
require_once('./auth_user.php'); // ensures the caller is logged in
require_once('./csrf.php');
require_once('./gps/GpsFunctions.php');
require_once('./gps/LocationPoint.php');
require_once('./gps/LocationProvider.php');
require_once('./gps/HomeAssistantProvider.php');
require_once('./gps/GpsRepairWorker.php');

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'POST required']);
    exit;
}

// Manual CSRF check (csrf_verify() emits HTML on failure, which would break JSON)
$tok = $_POST['csrf_token'] ?? '';
if (empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $tok)) {
    http_response_code(403);
    echo json_encode(['error' => 'Invalid request token. Reload the page and try again.']);
    exit;
}

$sid = isset($_POST['sid']) ? preg_replace('/\D/', '', $_POST['sid']) : '';
if ($sid === '') {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid session ID.']);
    exit;
}

$ha_enabled = !empty($settings['ha_enabled']) && $settings['ha_enabled'] !== '0';
$base = rtrim(trim($settings['ha_base_url'] ?? ''), '/');
$tokn = trim($settings['ha_token'] ?? '');
$ent  = trim($settings['ha_entity_id'] ?? '');

if (!$ha_enabled) { echo json_encode(['error' => 'GPS repair is disabled in Settings.']); exit; }
if ($base === '' || $tokn === '' || $ent === '') {
    echo json_encode(['error' => 'Home Assistant is not fully configured in Settings.']);
    exit;
}

$provider = new HomeAssistantProvider($base, $tokn, $ent);
$cfg = [
    'db_table'             => $db_table,
    'db_sessions_table'    => $db_sessions_table,
    'lookback_days'        => (int)  ($settings['gps_repair_lookback_days']   ?? 14),
    'min_age_minutes'      => (int)  ($settings['gps_repair_min_age_minutes'] ?? 5),
    'ha_tolerance_seconds' => (int)  ($settings['gps_ha_tolerance_seconds']   ?? 120),
    'ha_max_accuracy_m'    => (float)($settings['gps_ha_max_accuracy_m']      ?? 50),
    'stale_window_seconds' => (float)($settings['gps_stale_window_seconds']   ?? 60),
    'stale_min_speed_kmh'  => (float)($settings['gps_stale_min_speed_kmh']    ?? 10),
    'stale_max_movement_m' => (float)($settings['gps_stale_max_movement_m']   ?? 10),
];

$y     = date('Y', intdiv((int)$sid, 1000));
$m     = date('m', intdiv((int)$sid, 1000));
$table = "{$db_table}_{$y}_{$m}";

$worker = new GpsRepairWorker($con, $provider, $cfg, false);

// The worker logs progress via echo(); capture and discard it so the response is clean JSON.
ob_start();
[$corrected, $unresolved] = $worker->repair_session($sid, $table);
ob_end_clean();

mysqli_close($con);
echo json_encode(['corrected' => $corrected, 'unresolved' => $unresolved]);
