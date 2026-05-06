<?php
require_once('db.php');
require_once('auth_app.php');

if (!$logged_in) {
    http_response_code(401);
    echo "ERROR. Authentication required.";
    exit;
}

require_once('get_settings.php');

// ── 1. Validate session_id ────────────────────────────────────────────────
$session_id = trim($_POST['session_id'] ?? ''); // overrides any $session_id set by auth_app.php
if (!preg_match('/^\d{10,15}$/', $session_id)) {
    echo "ERROR. Invalid session_id.";
    exit;
}

// ── 2. Determine target monthly table ─────────────────────────────────────
$ts_sec    = intdiv((int)$session_id, 1000);
$tableYear = date('Y', $ts_sec);
$tableMonth = date('m', $ts_sec);
$db_table_full = "{$db_table}_{$tableYear}_{$tableMonth}";

// ── 3. Find newest existing monthly table (template for new table creation) ──
$newest_table = '';
$newest_list = mysqli_query($con,
    "SELECT table_name FROM INFORMATION_SCHEMA.tables
     WHERE table_schema = " . quote_value($db_name) .
    " AND table_name LIKE " . quote_value($db_table . '%') .
    " ORDER BY table_name DESC LIMIT 1");
if ($newest_list && ($nr = mysqli_fetch_assoc($newest_list))) {
    $newest_table = $nr['table_name'];
}

if (empty($newest_table)) {
    echo "ERROR. No existing raw_logs table found to clone schema from.";
    exit;
}

// ── 4. Auto-create target table if it doesn't exist ───────────────────────
$table_exists = mysqli_query($con,
    "SELECT table_name FROM INFORMATION_SCHEMA.tables
     WHERE table_schema = " . quote_value($db_name) .
    " AND table_name = " . quote_value($db_table_full));
if (!($table_exists && mysqli_fetch_assoc($table_exists))) {
    mysqli_query($con,
        "CREATE TABLE " . quote_name($db_table_full) .
        " SELECT * FROM " . quote_name($newest_table) . " WHERE 1=0");
}

// ── 5. Parse profile.properties.txt if present ───────────────────────────
$profile_name  = '';
if (isset($_FILES['profile']) && $_FILES['profile']['error'] === UPLOAD_ERR_OK) {
    $lines = file($_FILES['profile']['tmp_name'],
        FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (str_starts_with(trim($line), '#')) continue;
        $parts = explode('=', $line, 2);
        if (count($parts) !== 2) continue;
        $k = trim($parts[0]);
        $v = trim($parts[1]);
        if ($k === 'profile')  $profile_name  = $v;
    }
}

// ── 6. Load CSV header → k-code map from torque_keys ─────────────────────
$header_map = [];
$hmap_res = mysqli_query($con,
    "SELECT id, csv_header FROM " . quote_name($db_keys_table) .
    " WHERE csv_header IS NOT NULL");
if (!$hmap_res) {
    echo "ERROR. csv_header column missing from torque_keys — run db_upgrade.php first.";
    exit;
}
while ($hrow = mysqli_fetch_assoc($hmap_res)) {
    $header_map[trim($hrow['csv_header'])] = $hrow['id'];
}

// ── 7. Open and parse CSV ────────────────────────────────────────────────
if (!isset($_FILES['tracklog']) || $_FILES['tracklog']['error'] !== UPLOAD_ERR_OK) {
    echo "ERROR. tracklog file missing or upload error.";
    exit;
}

$csv_path = $_FILES['tracklog']['tmp_name'];
$handle   = fopen($csv_path, 'r');
if ($handle === false) {
    echo "ERROR. Cannot read tracklog file.";
    exit;
}

$raw_headers = fgetcsv($handle);
if (!$raw_headers) {
    fclose($handle);
    echo "ERROR. Empty CSV or missing header row.";
    exit;
}
$headers = array_map('trim', $raw_headers);

// Map each CSV column index to a k-code (null if not in header_map)
$col_map  = [];
$time_col = false;
foreach ($headers as $i => $h) {
    if ($h === 'Device Time') {
        $time_col = $i;
    }
    $col_map[$i] = $header_map[$h] ?? null;
}

// Collect all unique k-codes that will be written
$all_kcodes = array_values(array_filter(array_unique($col_map)));

// ── 8. Ensure all needed k-code columns exist in all monthly tables ───────
// Get current columns from the target table
$dbfields = [];
$cols_res = mysqli_query($con, "SHOW COLUMNS FROM " . quote_name($db_table_full));
if ($cols_res && mysqli_num_rows($cols_res) > 0) {
    while ($cr = mysqli_fetch_assoc($cols_res)) {
        $dbfields[] = $cr['Field'];
    }
}

foreach ($all_kcodes as $kcode) {
    if (in_array($kcode, $dbfields)) continue;

    // Not in target table — scan all monthly tables and add if missing
    $all_tables = mysqli_query($con,
        "SELECT table_name FROM INFORMATION_SCHEMA.tables
         WHERE table_schema = " . quote_value($db_name) .
        " AND table_name LIKE " . quote_value($db_table . '%') .
        " ORDER BY table_name DESC");

    while ($tr = mysqli_fetch_assoc($all_tables)) {
        $tname = $tr['table_name'];
        $tc_res = mysqli_query($con, "SHOW COLUMNS FROM " . quote_name($tname));
        $tfields = [];
        while ($tc = mysqli_fetch_assoc($tc_res)) {
            $tfields[] = $tc['Field'];
        }
        if (!in_array($kcode, $tfields)) {
            // Use float for k-codes that are known numeric; VARCHAR for string ones
            $type_sql = "float NOT NULL DEFAULT '0'";
            mysqli_query($con,
                "ALTER TABLE " . quote_name($tname) .
                " ADD " . quote_name($kcode) . " $type_sql");
        }
    }
    $dbfields[] = $kcode;

    // Ensure k-code is registered in torque_keys
    $key_exists = mysqli_query($con,
        "SELECT id FROM " . quote_name($db_keys_table) .
        " WHERE id = " . quote_value($kcode));
    if (!($key_exists && mysqli_fetch_assoc($key_exists))) {
        mysqli_query($con,
            "INSERT INTO " . quote_name($db_keys_table) .
            " (id, description, type, populated) VALUES (" .
            quote_value($kcode) . ", " .
            quote_value($kcode) . ", 'varchar(255)', '1')");
    }
}

// ── 9. Read rows into batches and bulk-insert ─────────────────────────────
$overwrite_flag = trim($_POST['overwrite'] ?? '0');
$duplicate_mode = ($overwrite_flag === '1') ? 'overwrite' : ($batch_duplicate_mode ?? 'ignore');

$batch     = [];
$row_count = 0;
$time_start = (int)$session_id;
$time_end   = (int)$session_id;

while (($row = fgetcsv($handle)) !== false) {
    // Parse Device Time → ms epoch
    $time_ms = (int)$session_id;
    if ($time_col !== false && isset($row[$time_col]) && trim($row[$time_col]) !== '-' && trim($row[$time_col]) !== '') {
        $parsed = strtotime(trim($row[$time_col]));
        if ($parsed !== false && $parsed > 0) {
            $time_ms = $parsed * 1000;
        }
    }
    if ($time_ms < $time_start) $time_start = $time_ms;
    if ($time_ms > $time_end)   $time_end   = $time_ms;

    // Build k-code => value pairs (skip dashes and unmapped columns)
    $kvals = [];
    foreach ($col_map as $i => $kcode) {
        if ($kcode === null) continue;
        $v = isset($row[$i]) ? trim($row[$i]) : '-';
        if ($v === '-' || $v === '') continue;
        if (strtolower($v) === 'infinity') $v = '-1';
        $kvals[$kcode] = $v;
    }

    if (empty($kvals)) continue;

    $batch[] = ['time' => $time_ms, 'session' => $session_id, 'kvals' => $kvals];
    $row_count++;

    if (count($batch) >= 200) {
        _insert_batch($con, $db_table_full, $batch, $duplicate_mode);
        $batch = [];
    }
}
fclose($handle);

if (!empty($batch)) {
    _insert_batch($con, $db_table_full, $batch, $duplicate_mode);
}

// ── 10. Upsert sessions table ────────────────────────────────────────────
$plugin_version = trim($_POST['plugin_version'] ?? '1.0');
$sess_check = mysqli_query($con,
    "SELECT session FROM " . quote_name($db_sessions_table) .
    " WHERE session = " . quote_value($session_id) . " LIMIT 1");

if ($sess_check && mysqli_fetch_assoc($sess_check)) {
    // Session already exists — update time bounds, size, profile
    mysqli_query($con,
        "UPDATE " . quote_name($db_sessions_table) .
        " SET timestart = " . quote_value($time_start) .
        ", timeend = " . quote_value($time_end) .
        ", sessionsize = " . quote_value($row_count) .
        ", profileName = " . quote_value($profile_name) .
        " WHERE session = " . quote_value($session_id));
} else {
    mysqli_query($con,
        "INSERT INTO " . quote_name($db_sessions_table) .
        " (session, timestart, timeend, sessionsize, profileName, id, v) VALUES (" .
        quote_value($session_id) . ", " .
        quote_value($time_start) . ", " .
        quote_value($time_end)   . ", " .
        quote_value($row_count)  . ", " .
        quote_value($profile_name) . ", " .
        "'plugin_upload', " .
        quote_value($plugin_version) . ")");
}

mysqli_close($con);
echo "OK! $row_count rows inserted for session $session_id";


// ── Helper: bulk-insert a batch of rows ──────────────────────────────────
function _insert_batch($con, $table, $batch, $mode) {
    // Collect all unique k-codes across this batch
    $all_cols = [];
    foreach ($batch as $r) {
        foreach (array_keys($r['kvals']) as $c) {
            $all_cols[$c] = true;
        }
    }
    $all_cols = array_keys($all_cols);

    $col_list = quote_name('session') . ', ' . quote_name('time');
    foreach ($all_cols as $c) {
        $col_list .= ', ' . quote_name($c);
    }

    $value_clauses = [];
    foreach ($batch as $r) {
        $vals = quote_value($r['session']) . ', ' . quote_value($r['time']);
        foreach ($all_cols as $c) {
            $vals .= ', ' . (isset($r['kvals'][$c]) ? quote_value($r['kvals'][$c]) : 'NULL');
        }
        $value_clauses[] = '(' . $vals . ')';
    }

    $values_sql = implode(', ', $value_clauses);

    if ($mode === 'overwrite') {
        $update_parts = [];
        foreach ($all_cols as $c) {
            $qc = quote_name($c);
            $update_parts[] = "$qc = VALUES($qc)";
        }
        $sql = "INSERT INTO " . quote_name($table) .
               " ($col_list) VALUES $values_sql" .
               " ON DUPLICATE KEY UPDATE " . implode(', ', $update_parts);
    } else {
        $sql = "INSERT IGNORE INTO " . quote_name($table) .
               " ($col_list) VALUES $values_sql";
    }

    if (!mysqli_query($con, $sql)) {
        error_log('upload_batch: INSERT failed: ' . mysqli_error($con));
    }
}
?>
