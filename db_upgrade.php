<?php
  require_once ('db.php');
  require_once ('auth_app.php');

#  mysqli_query($con, "ALTER TABLE $db_keys_table ADD COLUMN favorite TINYINT(1) NOT NULL DEFAULT 0") or die(mysqli_error($con));
#  // Update existing tables to handle new data structures
#  $table_list = mysqli_query($con, "SELECT table_name FROM INFORMATION_SCHEMA.tables WHERE table_schema = '$db_name' and table_name like '$db_table%' ORDER BY table_name DESC;");
#  while( $row = mysqli_fetch_assoc($table_list) ) {
#    $db_table_name = $row["table_name"];
#    // Change the GPS Latitude and Longitude datapoints from Float to Double to improve accuracy
#    $sqlLatQuery = "ALTER TABLE $db_table_name MODIFY kff1006 DOUBLE NOT NULL DEFAULT '0'";
#    mysqli_query($con, $sqlLatQuery) or die(mysqli_error($con));
#    $sqlLongQuery = "ALTER TABLE $db_table_name MODIFY kff1005 DOUBLE NOT NULL DEFAULT '0'";
#    mysqli_query($con, $sqlLongQuery) or die(mysqli_error($con));
#    // Delete columns which are now redundant and just stored with the session
#    $sqlVQuery = "ALTER TABLE $db_table_name DROP COLUMN v";
#    mysqli_query($con, $sqlVQuery) or die(mysqli_error($con));
#    $sqlIdQuery = "ALTER TABLE $db_table_name DROP COLUMN id";
#    mysqli_query($con, $sqlIdQuery) or die(mysqli_error($con));
#    $sqlEmlQuery = "ALTER TABLE $db_table_name DROP COLUMN eml";
#    mysqli_query($con, $sqlEmlQuery) or die(mysqli_error($con));
#    $sqlProfileNameQuery = "ALTER TABLE $db_table_name DROP COLUMN profileName";
#    mysqli_query($con, $sqlProfileNameQuery) or die(mysqli_error($con));
#    $sqlProfileFuelTypeQuery = "ALTER TABLE $db_table_name DROP COLUMN profileFuelType";
#    mysqli_query($con, $sqlProfileFuelTypeQuery) or die(mysqli_error($con));
#    $sqlProfileWeightQuery = "ALTER TABLE $db_table_name DROP COLUMN profileWeight";
#    mysqli_query($con, $sqlProfileWeightQuery) or die(mysqli_error($con));
#    $sqlProfileVeQuery = "ALTER TABLE $db_table_name DROP COLUMN profileVe";
#    mysqli_query($con, $sqlProfileVeQuery) or die(mysqli_error($con));
#    $sqlProfileFuelCostQuery = "ALTER TABLE $db_table_name DROP COLUMN profileFuelCost";
#    mysqli_query($con, $sqlProfileFuelCostQuery) or die(mysqli_error($con));
#    
#  }

#  // Split the raw logs table into per-month tables 
#  $sessionYears = mysqli_query($con, "SELECT DISTINCT CONCAT(YEAR(FROM_UNIXTIME(session/1000)), '_', DATE_FORMAT(FROM_UNIXTIME(session/1000),'%m')) as Suffix, YEAR(FROM_UNIXTIME(session/1000)) as Year, MONTH(FROM_UNIXTIME(session/1000)) as Month FROM $db_table");
#  while( $row = mysqli_fetch_assoc( $sessionYears ) ) {
#    $suffix = $row['Suffix'];
#    $year = $row['Year'];
#    $month = $row['Month'];
#    $new_table_name = "{$db_table}_{$suffix}";
#    $table_create_query = "CREATE TABLE $new_table_name SELECT * FROM $db_table WHERE YEAR(FROM_UNIXTIME(session/1000)) LIKE '$year' and MONTH(FROM_UNIXTIME(session/1000)) LIKE '$month'";
#    mysqli_query($con, $table_create_query) or die(mysqli_error($con));
#  }

#  // Clear the raw_logs table; we still want it as a shell, just empty
#  mysqli_query($con, "DELETE FROM $db_table") or die(mysqli_error($con));

  // Add indexes to sessions table for faster lookups
  // session is used in WHERE/JOIN on nearly every page
  $result = mysqli_query($con, "SHOW INDEX FROM $db_sessions_table WHERE Key_name = 'idx_session'");
  if (mysqli_num_rows($result) == 0) {
    mysqli_query($con, "ALTER TABLE $db_sessions_table ADD INDEX idx_session (session)");
  }

  // Add indexes to all raw_logs monthly tables
  // These tables are queried by session and ordered by time on every page load
  $table_list = mysqli_query($con, "SELECT table_name FROM INFORMATION_SCHEMA.tables WHERE table_schema = '$db_name' AND table_name LIKE '{$db_table}_%' ORDER BY table_name DESC;");
  while ($row = mysqli_fetch_assoc($table_list)) {
    $tbl = $row["table_name"];
    $idx_check = mysqli_query($con, "SHOW INDEX FROM `$tbl` WHERE Key_name = 'idx_session_time'");
    if (mysqli_num_rows($idx_check) == 0) {
      mysqli_query($con, "ALTER TABLE `$tbl` ADD INDEX idx_session_time (session, time)");
    }
  }

  // ── v2.2 Plugin Batch Upload (2026-05-07) ────────────────────────────────
  // Add csv_header lookup column to torque_keys so upload_batch.php can map
  // CSV column headers to k-codes without hardcoding them in PHP.
  $col_check = mysqli_query($con, "SHOW COLUMNS FROM " . quote_name($db_keys_table) . " LIKE 'csv_header'");
  if (mysqli_num_rows($col_check) == 0) {
    mysqli_query($con, "ALTER TABLE " . quote_name($db_keys_table) . " ADD COLUMN csv_header VARCHAR(120) DEFAULT NULL");
  }

  // Index for fast header→k-code lookup
  $idx_check = mysqli_query($con, "SHOW INDEX FROM " . quote_name($db_keys_table) . " WHERE Key_name = 'idx_torque_keys_csv_header'");
  if (mysqli_num_rows($idx_check) == 0) {
    mysqli_query($con, "ALTER TABLE " . quote_name($db_keys_table) . " ADD INDEX idx_torque_keys_csv_header (csv_header)");
  }

  // Seed known CSV header → k-code mappings (safe to re-run; only updates csv_header)
  $header_seeds = [
    ['kff1006', 'GPS Latitude',             'GPS Latitude(°)'],
    ['kff1005', 'GPS Longitude',            'GPS Longitude(°)'],
    ['kff1001', 'GPS Speed',                'GPS Speed(km/h)'],
    ['kff1008', 'GPS Bearing',              'GPS Bearing(°)'],
    ['kff1009', 'GPS Accuracy',             'GPS Accuracy(m)'],
    ['kff1204', 'GPS Satellites',           'GPS Satellites'],
    ['kc',      'Engine RPM',              'Engine RPM(rpm)'],
    ['k4',      'Engine Load',             'Engine Load(%)'],
    ['k5',      'Engine Coolant Temp',     'Engine Coolant Temperature(°F)'],
    ['kf',      'Intake Air Temp',         'Intake Air Temperature(°F)'],
    ['kd',      'OBD Speed',              'Speed (GPS)(km/h)'],
    ['k11',     'Throttle Position',       'Throttle Position(%)'],
    ['k6',      'Short Term Fuel Trim B1', 'Short Term Fuel Trim Bank 1(%)'],
    ['k7',      'Long Term Fuel Trim B1',  'Long Term Fuel Trim Bank 1(%)'],
    ['k8',      'Short Term Fuel Trim B2', 'Short Term Fuel Trim Bank 2(%)'],
    ['k9',      'Long Term Fuel Trim B2',  'Long Term Fuel Trim Bank 2(%)'],
    ['k33',     'Barometer',              'Barometer (on Android device)(mb)'],
    ['kff5203', 'Fuel Consumption Rate',   'Litres Per 100 Kilometer(Long Term Average)(l/100km)'],
    ['kff1010', 'GPS Altitude',           'GPS Altitude(m)'],
    ['kff1226', 'Horsepower',             'Horsepower (At the wheels)(hp)'],

    ['kff1200', 'CO2 g/km (Average)',      'CO₂ in g/km (Average)(g/km)'],
    ['kff1201', 'CO2 g/km (Instant)',      'CO₂ in g/km (Instantaneous)(g/km)'],
    ['kff1202', 'MPG Instant',            'Miles Per Gallon(Instant)(mpg)'],
    ['kff1203', 'MPG Average',            'Miles Per Gallon(Long Term Average)(mpg)'],
    ['kff1205', 'Fuel Flow Rate',         'Fuel flow rate/minute(cc/min)'],
    ['kff1206', 'Volumetric Efficiency',  'Volumetric Efficiency (Calculated)(%)'],
    ['k1c',     'OBD Standards',          'OBD Standards'],
    ['k2f',     'Fuel Level',             'Fuel Level(%)'],
    ['k5e',     'Engine Fuel Rate',       'Engine Fuel Rate(L/hr)'],
    ['k42',     'Control Module Voltage', 'Control Module Voltage(V)'],
    ['k43',     'Absolute Load Value',    'Engine Load(Absolute)(%)'],
    ['k44',     'Air Fuel Ratio Cmd',     'Air Fuel Ratio(Commanded)(:1)'],
    ['k45',     'Relative Throttle',      'Relative Throttle Position(%)'],
    ['k47',     'Throttle B',             'Throttle Position B(%)'],
    ['k49',     'Accelerator Pedal D',    'Accelerator Pedal Position D(%)'],
    ['k4a',     'Accelerator Pedal E',    'Accelerator Pedal Position E(%)'],
    ['k4c',     'Commanded Throttle',     'Commanded Throttle Actuator(%)'],
    ['k4d',     'Run Time MIL',           'Run time with MIL on(min)'],
    ['k4e',     'Time Since Codes Clear', 'Time since trouble codes cleared(min)'],
    ['k52',     'Ethanol Fuel Pct',       'Ethanol Fuel Percent(%)'],
    ['k5a',     'Relative Accel Pedal',   'Relative Accelerator Pedal Position(%)'],
    ['k5b',     'Hybrid Battery Life',    'Hybrid Battery Pack Remaining Life(%)'],
    ['k5c',     'Engine Oil Temp',        'Engine Oil Temperature(°F)'],
    ['k5d',     'Fuel Injection Timing',  'Fuel Injection Timing(°)'],
    ['k61',     'Drivers Demand Torque',  'Drivers demand engine % torque(%)'],
    ['k62',     'Actual Engine Torque',   'Actual engine % torque(%)'],
    ['k63',     'Engine Reference Torque','Engine reference torque(Nm)'],
    ['k67',     'Turbo Inlet Pressure',   'Turbo Boost & Vacuum Gauge(psi)'],
    ['k6b',     'EGR Commanded',          'EGR Commanded(%)'],
    ['k6c',     'EGR Error',              'EGR Error(%)'],
  ];
  foreach ($header_seeds as [$kid, $kdesc, $kheader]) {
    $qid   = quote_value($kid);
    $qdesc = quote_value($kdesc);
    $qhdr  = quote_value($kheader);
    mysqli_query($con, "INSERT INTO " . quote_name($db_keys_table) . "
      (id, description, csv_header)
      VALUES ($qid, $qdesc, $qhdr)
      ON DUPLICATE KEY UPDATE csv_header = $qhdr");
  }
  // ── end v2.2 ─────────────────────────────────────────────────────────────

  echo "Upgrade complete.\n";

# ── v2.1 HUD Widget Enhancements (2026-04-30) ────────────────────────────────
# New torque_settings keys added (auto-seeded by get_settings.php — no ALTER needed):
#   hud_gauge1_pid, hud_gauge1_label, hud_gauge1_min, hud_gauge1_max, hud_gauge1_suffix
#   hud_gauge2_pid, hud_gauge2_label, hud_gauge2_min, hud_gauge2_max, hud_gauge2_suffix
#   hud_gauge3_pid, hud_gauge3_label, hud_gauge3_min, hud_gauge3_max, hud_gauge3_suffix
#   hud_stat_dur_label, hud_stat_dist_label, hud_stat_fuel_pid, hud_stat_fuel_label
# No ALTER TABLE required — get_settings.php INSERT IGNORE seeds all defaults on first load.
# ─────────────────────────────────────────────────────────────────────────────
?>

