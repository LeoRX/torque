<?php
require_once('db.php');
$ok = ($con && mysqli_ping($con));
if ($con) { mysqli_close($con); }
http_response_code($ok ? 200 : 503);
header('Content-Type: text/plain');
echo $ok ? 'OK' : 'DB_DOWN';
