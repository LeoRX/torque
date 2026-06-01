<?php
// AJAX endpoint: return GPS coordinates for a single session.
// Used by the multi-session map overlay feature.
// Response: JSON array of [lon, lat] pairs, filtered to valid coordinates.

require_once('./db.php');
require_once('./auth_user.php');

header('Content-Type: application/json; charset=utf-8');

if (!isset($_GET['sid']) || !preg_match('/^\d+$/', $_GET['sid'])) {
    echo '[]'; exit;
}

$sid        = (int)$_GET['sid'];
$tableYear  = date('Y', intdiv($sid, 1000));
$tableMonth = date('m', intdiv($sid, 1000));
$table      = "{$db_table}_{$tableYear}_{$tableMonth}";

// Prefer corrected GPS from gps_corrections; fall back to raw kff1005/kff1006.
$_valid_raw = "r.kff1005 IS NOT NULL AND r.kff1006 IS NOT NULL AND r.kff1005 != 0 AND r.kff1006 != 0";
$result = mysqli_query($con, "
    SELECT
        COALESCE(gc.corrected_lon, r.kff1005) AS lon,
        COALESCE(gc.corrected_lat, r.kff1006) AS lat
    FROM " . quote_name($table) . " r
    LEFT JOIN gps_corrections gc
           ON gc.raw_table = " . quote_value($table) . "
          AND gc.session   = " . quote_value($sid) . "
          AND gc.torque_time_ms = r.time
    WHERE r.session = " . quote_value($sid) . "
      AND (gc.id IS NOT NULL OR ($_valid_raw))
    ORDER BY r.time ASC
");
// Raw-only fallback if gps_corrections does not exist yet (pre-migration)
if (!$result) {
    $result = mysqli_query($con, "SELECT r.kff1005 AS lon, r.kff1006 AS lat
        FROM " . quote_name($table) . " r
        WHERE r.session = " . quote_value($sid) . " AND ($_valid_raw)
        ORDER BY r.time ASC");
}

$points = [];
if ($result) {
    while ($row = mysqli_fetch_assoc($result)) {
        $lon = (float)$row['lon'];
        $lat = (float)$row['lat'];
        // Filter out invalid / zero-island coordinates
        if ($lon < -180 || $lon > 180 || $lat < -90 || $lat > 90) continue;
        if ($lon === 0.0 && $lat === 0.0) continue;
        $points[] = [$lon, $lat];
    }
    mysqli_free_result($result);
}

mysqli_close($con);
echo json_encode($points, JSON_UNESCAPED_UNICODE);
