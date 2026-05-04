<?php
require_once("./db.php");
require_once("./auth_user.php"); // ensures user is logged in; redirects to login if not

// Validate session_id — must be a positive integer
if (!isset($_GET["sid"]) || !preg_match('/^\d+$/', $_GET["sid"])) {
    http_response_code(400);
    exit('Invalid session ID.');
}
$session_id = (int)$_GET["sid"];

// Validate filetype — whitelist only
$allowed_filetypes = ['csv', 'json'];
$filetype = isset($_GET["filetype"]) ? strtolower(trim($_GET["filetype"])) : '';
if (!in_array($filetype, $allowed_filetypes, true)) {
    http_response_code(400);
    exit('Invalid file type. Use csv or json.');
}

$tableYear  = date("Y", intdiv($session_id, 1000));
$tableMonth = date("m", intdiv($session_id, 1000));
$db_table_full = "{$db_table}_{$tableYear}_{$tableMonth}";

$tbl  = quote_name($db_table_full);
$stbl = quote_name($db_sessions_table);
$sql = mysqli_query($con,
    "SELECT * FROM $tbl
     JOIN $stbl ON $tbl.session = $stbl.session
     WHERE $tbl.session = " . quote_value($session_id) . "
     ORDER BY $tbl.time DESC"
);

if (!$sql) {
    http_response_code(500);
    exit('Query failed.');
}

if ($filetype === 'csv') {
    $csvfilename = "torque_session_" . $session_id . ".csv";
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $csvfilename . '"');

    // Header row
    $columns_total = mysqli_num_fields($sql);
    $headers = [];
    for ($c = 0; $c < $columns_total; $c++) {
        $prop = mysqli_fetch_field_direct($sql, $c);
        $headers[] = '"' . str_replace('"', '""', $prop->name) . '"';
    }
    echo implode(',', $headers) . "\n";

    // Data rows — stream directly rather than building one giant string
    while ($row = mysqli_fetch_array($sql, MYSQLI_NUM)) {
        $cells = array_map(function($v) {
            $v = (string)$v;
            // Neutralise spreadsheet formula injection (=, +, -, @, |, %)
            if ($v !== '' && strspn($v, '=+-@|%') === 1) {
                $v = "'" . $v;
            }
            return '"' . str_replace('"', '""', $v) . '"';
        }, $row);
        echo implode(',', $cells) . "\n";
    }

} else { // json
    $jsonfilename = "torque_session_" . $session_id . ".json";
    header('Content-Type: application/json; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $jsonfilename . '"');

    // Stream JSON row-by-row to avoid loading the full result set into memory
    echo '[';
    $first = true;
    while ($r = mysqli_fetch_assoc($sql)) {
        echo ($first ? '' : ',') . json_encode($r, JSON_UNESCAPED_UNICODE);
        $first = false;
    }
    echo ']';
}

mysqli_free_result($sql);
mysqli_close($con);
?>
