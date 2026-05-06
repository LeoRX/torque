<?php
require_once('db.php');
require_once('auth_app.php');

$session_id = $_GET['session_id'] ?? '';
if (!preg_match('/^\d{10,15}$/', $session_id)) {
    header('Content-Type: application/json');
    echo json_encode(['exists' => false]);
    exit;
}

$res = mysqli_query($con,
    "SELECT 1 FROM " . quote_name($db_sessions_table) .
    " WHERE session = " . quote_value($session_id) . " LIMIT 1"
);
$exists = $res && mysqli_num_rows($res) > 0;

mysqli_close($con);
header('Content-Type: application/json');
echo json_encode(['exists' => $exists]);
?>
