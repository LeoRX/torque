<?php
//echo "<!-- Begin plot.php at ".date("H:i:s", microtime(true))." -->\r\n";
require_once("./db.php");
require_once("./parse_functions.php");

// Convert data units
// TODO: Use the userDefault fields to do these conversions dynamically

//Speed conversion
if (!$source_is_miles && $use_miles) {
    $speed_factor = 0.621371;
    $speed_measurand = ' (mph)';
    $distance_measurand = ' (miles)';
} elseif ($source_is_miles && $use_miles) {
    $speed_factor = 1.0;
    $speed_measurand = ' (mph)';
    $distance_measurand = ' (miles)';
} elseif ($source_is_miles && !$use_miles) {
    $speed_factor = 1.609344;
    $speed_measurand = ' (km/h)';
    $distance_measurand = ' (km)';
} else {
    $speed_factor = 1.0;
    $speed_measurand = ' (km/h)';
    $distance_measurand = ' (km)';
}

//Temperature Conversion
if (!$source_is_fahrenheit && $use_fahrenheit) { //From Celsius to Fahrenheit
    $temp_func = function ($temp) { return $temp*9.0/5.0+32.0; };
    $temp_measurand = ' (&deg;F)';
} elseif ($source_is_fahrenheit && $use_fahrenheit) { //Just Fahrenheit
    $temp_func = function ($temp) { return $temp; };
    $temp_measurand = ' (&deg;F)';
} elseif ($source_is_fahrenheit && !$use_fahrenheit) { //From Fahrenheit to Celsius
    $temp_func = function ($temp) { return ($temp-32.0)*5.0/9.0; };
    $temp_measurand = ' (&deg;C)';
} else { //Just Celsius
    $temp_func = function ($temp) { return $temp; };
    $temp_measurand = ' (&deg;C)';
}

$plotVar      = [];
$plotData     = [];
$plotMeasurand = [];
$plotSpark    = [];
$plotLabel    = [];
$plotSparkData = [];
$plotMax      = [];
$plotMin      = [];
$plotAvg      = [];
$plotPcnt25   = [];
$plotPcnt75   = [];
$plotNote     = [];   // '' = has data; otherwise why the series is empty (shown in Data Summary)
$plotConv     = [];   // per-variable unit conversion: 'speed', 'temp' or '' (none)

// $session_id is resolved by session.php (requested ?id= if it exists, else the newest).
// Nothing to do unless a session is open and at least one variable was requested.
if ($session_id !== '' && isset($_GET["s1"])) {
    // Get the torque key->val mappings
    $keyquery = mysqli_query($con, "SELECT id,description,units FROM " . quote_name($db_name) . "." . quote_name($db_keys_table));
    if (!$keyquery) {
        error_log('plot: keys query failed: ' . mysqli_error($con));
    }
    $keyarr = [];
    while ($keyquery && ($row = mysqli_fetch_assoc($keyquery))) {
        $keyarr[$row['id']] = [$row['description'], $row['units']];
    }
    if ($keyquery) { mysqli_free_result($keyquery); }

    // Columns that actually exist in this session's monthly table. A PID can be in
    // torque_keys but missing from an older table; it must not fail the whole SELECT.
    $tableYear     = date("Y", intdiv((int)$session_id, 1000));
    $tableMonth    = date("m", intdiv((int)$session_id, 1000));
    $db_table_full = "{$db_table}_{$tableYear}_{$tableMonth}";
    $tableCols = [];
    $colquery = mysqli_query($con, "SHOW COLUMNS FROM " . quote_name($db_table_full));
    if (!$colquery) {
        error_log('plot: cannot read columns of ' . $db_table_full . ': ' . mysqli_error($con));
    }
    while ($colquery && ($row = mysqli_fetch_row($colquery))) {
        $tableCols[$row[0]] = true;
    }
    if ($colquery) { mysqli_free_result($colquery); }

    // Build the SELECT column list from requested variables
    // Validate each k-code against the known-keys whitelist before use as a column name.
    // quote_name() backtick-escapes identifiers (prevents SQL injection), but without
    // this check an authenticated user could read arbitrary columns from raw_logs tables.
    // The unit label and conversion depend only on the PID, so pick them once here.
    $selectstring = "time";
    $i = 1;
    $plotIdx = 1;
    while (isset($_GET["s$i"])) {
        $candidate = $_GET["s$i"];
        if (is_string($candidate) && array_key_exists($candidate, $keyarr)) {
            $plotVar[$plotIdx] = $candidate;
            $desc  = (string)$keyarr[$candidate][0];
            $units = (string)$keyarr[$candidate][1];
            if (substri_count($desc, "Speed") > 0) {
                $plotConv[$plotIdx]      = 'speed';
                $plotMeasurand[$plotIdx] = $speed_measurand;
            } elseif (substri_count($desc, "Distance") > 0) {
                $plotConv[$plotIdx]      = 'speed';
                $plotMeasurand[$plotIdx] = $distance_measurand;
            } elseif (substri_count($desc, "Temp") > 0) {
                $plotConv[$plotIdx]      = 'temp';
                $plotMeasurand[$plotIdx] = $temp_measurand;
            } else {
                $plotConv[$plotIdx]      = '';
                $plotMeasurand[$plotIdx] = $units !== '' ? ' (' . $units . ')' : '';
            }
            $plotData[$plotIdx]  = [];
            $plotSpark[$plotIdx] = [];
            if (isset($tableCols[$candidate])) {
                $selectstring .= "," . quote_name($candidate);
                $plotNote[$plotIdx] = '';
            } else {
                $plotNote[$plotIdx] = 'Not recorded in this month\'s data';
                if ($tableCols) {
                    error_log('plot: column ' . $candidate . ' missing from ' . $db_table_full);
                }
            }
            $plotIdx++;
        }
        $i++;
    }
    // Get data for session (only when at least one requested PID has a column)
    if ($selectstring !== "time") {
        $sessionqry = mysqli_query($con,
            "SELECT $selectstring FROM " . quote_name($db_table_full) .
            " WHERE session=" . quote_value($session_id) . " ORDER BY time DESC");
        if (!$sessionqry) {
            error_log('plot: data query failed for ' . $db_table_full . ': ' . mysqli_error($con));
            foreach ($plotNote as $n => $note) {
                if ($note === '') { $plotNote[$n] = 'Data query failed (see server log)'; }
            }
        }
        while ($sessionqry && ($row = mysqli_fetch_assoc($sessionqry))) {
            $i = 1;
            while (isset($plotVar[$i])) {
                $kcode = $plotVar[$i];
                if (!array_key_exists($kcode, $row)) { $i++; continue; }
                if ($plotConv[$i] === 'speed') {
                    $x = intval($row[$kcode]) * $speed_factor;
                } elseif ($plotConv[$i] === 'temp') {
                    $x = $temp_func(floatval($row[$kcode]));
                } else {
                    $x = (float)$row[$kcode];
                }
                $plotData[$i][]  = [(int)$row['time'], $x];
                $plotSpark[$i][] = $x;
                $i++;
            }
        }
        if ($sessionqry) { mysqli_free_result($sessionqry); }
    }
    $i = 1;
    while (isset($plotVar[$i])) {
        $kcode           = $plotVar[$i];
        $plotLabel[$i]     = json_encode($keyarr[$kcode][0] . $plotMeasurand[$i]);
        $plotSparkData[$i] = implode(",", array_reverse($plotSpark[$i]));
        if ($plotSpark[$i]) {
            $plotMax[$i]    = round(max($plotSpark[$i]), 1);
            $plotMin[$i]    = round(min($plotSpark[$i]), 1);
            $plotAvg[$i]    = round(average($plotSpark[$i]), 1);
            $plotPcnt25[$i] = round(calc_percentile($plotSpark[$i], 25), 1);
            $plotPcnt75[$i] = round(calc_percentile($plotSpark[$i], 75), 1);
        } else {
            // Empty series: chart it with no points; the Data Summary shows $plotNote instead of stats.
            $plotMax[$i] = $plotMin[$i] = $plotAvg[$i] = $plotPcnt25[$i] = $plotPcnt75[$i] = '';
            if ($plotNote[$i] === '') { $plotNote[$i] = 'No data in this session'; }
        }
        $i++;
    }
}
//echo "<!-- End plot.php at ".date("H:i:s", microtime(true))." -->\r\n";
?>
