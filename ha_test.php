<?php
// AJAX endpoint: test Home Assistant connectivity for the GPS repair feature.
// Returns JSON { http, points } on success, or { error } on failure.
// Read-only — performs no writes and never echoes the token.

require_once('./db.php');
require_once('./auth_user.php'); // ensures the caller is logged in
require_once('./get_settings.php');
require_once('./csrf.php');
require_once('./gps/LocationPoint.php');
require_once('./gps/LocationProvider.php');
require_once('./gps/HomeAssistantProvider.php');

header('Content-Type: application/json; charset=utf-8');

// POST + CSRF only: this endpoint makes an outbound request with the saved token.
if ($_SERVER['REQUEST_METHOD'] !== 'POST'
    || !hash_equals((string)($_SESSION['csrf_token'] ?? ''), (string)($_POST['csrf_token'] ?? ''))) {
    http_response_code(403);
    echo json_encode(['error' => 'Request validation failed. Reload the page and try again.']);
    exit;
}

if (!HomeAssistantProvider::is_configured($settings)) {
    echo json_encode(['error' => 'HA URL, token, and entity must be configured and saved first.']);
    exit;
}
$base = rtrim(trim($settings['ha_base_url'] ?? ''), '/');
$tok  = trim($settings['ha_token'] ?? '');
$ent  = trim($settings['ha_entity_id'] ?? '');

$start = gmdate('Y-m-d\TH:i:s\Z', time() - 1800); // last 30 min
$end   = gmdate('Y-m-d\TH:i:s\Z', time());
$url   = $base . '/api/history/period/' . rawurlencode($start)
       . '?end_time='         . rawurlencode($end)
       . '&filter_entity_id=' . rawurlencode($ent);

[$raw, $err, $code] = HomeAssistantProvider::http_get($url, $tok);

if ($err !== '') {
    // Generic message: raw curl errors would let this endpoint be used to probe
    // which internal hosts/ports exist. The detail goes to the server log only.
    error_log('ha_test: connection failed: ' . $err);
    echo json_encode(['error' => 'Could not connect to Home Assistant — check the URL (http:// or https://) and that HA is reachable from the container.']);
    exit;
}
if ($code === 401 || $code === 403) {
    echo json_encode(['error' => 'Authentication failed (HTTP ' . $code . ') — check the access token.']);
    exit;
}
if ($code !== 200) {
    echo json_encode(['error' => 'HTTP ' . $code . ' from Home Assistant — check the URL and entity.']);
    exit;
}

$data   = json_decode((string)$raw, true);
$points = is_array($data) ? HomeAssistantProvider::parse_states($data, $ent) : [];

echo json_encode(['http' => $code, 'points' => count($points)]);
