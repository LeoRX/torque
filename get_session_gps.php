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

// Try with kff1001 (GPS speed), fall back if column missing in older tables
$sql_full  = "SELECT kff1005, kff1006 FROM `$table` WHERE session=$sid AND kff1005 != 0 AND kff1006 != 0 ORDER BY time ASC";
$sql_basic = "SELECT kff1005, kff1006 FROM `$table` WHERE session=$sid AND kff1005 != 0 AND kff1006 != 0 ORDER BY time ASC";

$result = mysqli_query($con, $sql_full);
if (!$result) $result = mysqli_query($con, $sql_basic);

$points = [];
if ($result) {
    while ($row = mysqli_fetch_row($result)) {
        $lon = (float)$row[0];
        $lat = (float)$row[1];
        // Filter out invalid / zero-island coordinates
        if ($lon < -180 || $lon > 180 || $lat < -90 || $lat > 90) continue;
        if ($lon === 0.0 && $lat === 0.0) continue;
        $points[] = [$lon, $lat];
    }
    mysqli_free_result($result);
}

mysqli_close($con);
echo json_encode($points, JSON_UNESCAPED_UNICODE);
