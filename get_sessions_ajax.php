<?php
// AJAX endpoint: return sessions within a Unix timestamp range (seconds).
// Called by the calendar date-range picker in torquehelpers.js.
// Response: JSON array of {id, label, duration, profile}

require_once('./db.php');
require_once('./auth_user.php');
require_once('./get_settings.php');

header('Content-Type: application/json; charset=utf-8');

// Validate inputs — must be positive integers (Unix seconds)
if (!isset($_GET['start'], $_GET['end'])
    || !preg_match('/^\d+$/', $_GET['start'])
    || !preg_match('/^\d+$/', $_GET['end'])) {
    echo '[]';
    exit;
}

$startSec = (int)$_GET['start'];
$endSec   = (int)$_GET['end'];
if ($startSec > $endSec) { $tmp = $startSec; $startSec = $endSec; $endSec = $tmp; }

// Include the full end day (add 86399 seconds so end date is inclusive)
$endSec += 86399;

// session column is 13-digit millisecond timestamp
$startMs = $startSec * 1000;
$endMs   = $endSec   * 1000;

$sql = "SELECT timestart, timeend, session, profileName, sessionsize
        FROM $db_sessions_table
        WHERE session >= $startMs AND session <= $endMs
          AND sessionsize >= $min_session_size
        GROUP BY session, profileName, timestart, timeend, sessionsize
        ORDER BY session DESC
        LIMIT 200";

$result = mysqli_query($con, $sql);
$out = [];

if ($result) {
    while ($row = mysqli_fetch_assoc($result)) {
        $sid     = $row['session'];
        $ts      = intdiv((int)$sid, 1000);
        $label   = tz_date('F d, Y  g:ia', $ts, $display_timezone ?? 'UTC');
        $durSec  = (int)(((int)$row['timeend'] - (int)$row['timestart']) / 1000);
        $dur     = gmdate('H:i:s', max(0, $durSec));
        $profile = $row['profileName'] ?? '';
        $out[]   = ['id' => $sid, 'label' => $label, 'duration' => $dur, 'profile' => $profile];
    }
    mysqli_free_result($result);
}

mysqli_close($con);
echo json_encode($out, JSON_UNESCAPED_UNICODE);
