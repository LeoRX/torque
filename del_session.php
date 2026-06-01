<?php
// Included by session.php — db connection and auth already done by caller.

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_POST['deletesession'])) {
    return;
}
require_once __DIR__ . '/csrf.php';
csrf_verify();

$deletesession = preg_replace('/\D/', '', $_POST['deletesession']);
if (empty($deletesession)) { return; }

$tableYear     = date('Y', intdiv((int)$deletesession, 1000));
$tableMonth    = date('m', intdiv((int)$deletesession, 1000));
$db_table_full = "{$db_table}_{$tableYear}_{$tableMonth}";

$r1 = mysqli_query($con, "DELETE FROM " . quote_name($db_table_full)
    . " WHERE session = " . quote_value($deletesession));
$r2 = mysqli_query($con, "DELETE FROM " . quote_name($db_sessions_table)
    . " WHERE session = " . quote_value($deletesession));
if (!$r1 || !$r2) {
    error_log('del_session: DELETE failed for session '
        . $deletesession . ': ' . mysqli_error($con));
}

// Remove any GPS corrections / repair-queue rows for this session so they don't
// orphan. Suppressed (@) so a missing gps_corrections table (pre-migration) is harmless.
@mysqli_query($con, "DELETE FROM gps_corrections WHERE session = " . quote_value($deletesession));
@mysqli_query($con, "DELETE FROM gps_repair_queue WHERE session = " . quote_value($deletesession));
?>
