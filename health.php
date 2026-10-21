<?php
require_once('db.php');
try {
    $ok = ($con && mysqli_query($con, 'SELECT 1') !== false);
} catch (Throwable $e) {
    $ok = false;
}
if ($con) { mysqli_close($con); }
http_response_code($ok ? 200 : 503);
header('Content-Type: text/plain');
echo $ok ? 'OK' : 'DB_DOWN';
