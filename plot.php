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

// Grab the session number
if (isset($_GET["id"]) && in_array($_GET["id"], $sids)) {
    $session_id = mysqli_real_escape_string($con, $_GET['id']);
    // Get the torque key->val mappings
    $keyquery = mysqli_query($con, "SELECT id,description,units FROM " . quote_name($db_name) . "." . quote_name($db_keys_table));
    $keyarr = [];
    while ($row = mysqli_fetch_assoc($keyquery)) {
        $keyarr[$row['id']] = [$row['description'], $row['units']];
    }
    // Build the SELECT column list from requested variables
    $selectstring = "time";
    $i = 1;
    while (isset($_GET["s$i"])) {
        $plotVar[$i]   = $_GET["s$i"];
        $selectstring .= "," . quote_name($plotVar[$i]);
        $i++;
    }
    // Get data for session
    $tableYear     = date("Y", intdiv((int)$session_id, 1000));
    $tableMonth    = date("m", intdiv((int)$session_id, 1000));
    $db_table_full = "{$db_table}_{$tableYear}_{$tableMonth}";
    $sessionqry = mysqli_query($con,
        "SELECT $selectstring FROM " . quote_name($db_table_full) .
        " WHERE session=" . quote_value($session_id) . " ORDER BY time DESC");
    while ($row = mysqli_fetch_assoc($sessionqry)) {
        $i = 1;
        while (isset($plotVar[$i])) {
            $kcode = $plotVar[$i];
            if (substri_count($keyarr[$kcode][0], "Speed") > 0) {
                $x = intval($row[$kcode]) * $speed_factor;
                $plotMeasurand[$i] = $speed_measurand;
            } elseif (substri_count($keyarr[$kcode][0], "Distance") > 0) {
                $x = intval($row[$kcode]) * $speed_factor;
                $plotMeasurand[$i] = $distance_measurand;
            } elseif (substri_count($keyarr[$kcode][0], "Temp") > 0) {
                $x = $temp_func(floatval($row[$kcode]));
                $plotMeasurand[$i] = $temp_measurand;
            } else {
                $x = $row[$kcode];
                $plotMeasurand[$i] = ' (' . $keyarr[$kcode][1] . ')';
            }
            $plotData[$i][]  = [$row['time'], $x];
            $plotSpark[$i][] = $x;
            $i++;
        }
    }
    $i = 1;
    while (isset($plotVar[$i])) {
        $kcode           = $plotVar[$i];
        $plotLabel[$i]     = '"' . $keyarr[$kcode][0] . $plotMeasurand[$i] . '"';
        $plotSparkData[$i] = implode(",", array_reverse($plotSpark[$i]));
        $plotMax[$i]       = round(max($plotSpark[$i]), 1);
        $plotMin[$i]       = round(min($plotSpark[$i]), 1);
        $plotAvg[$i]       = round(average($plotSpark[$i]), 1);
        $plotPcnt25[$i]    = round(calc_percentile($plotSpark[$i], 25), 1);
        $plotPcnt75[$i]    = round(calc_percentile($plotSpark[$i], 75), 1);
        $i++;
    }
}
//echo "<!-- End plot.php at ".date("H:i:s", microtime(true))." -->\r\n";
?>
