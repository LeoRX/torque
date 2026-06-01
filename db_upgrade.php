<?php
require_once('db.php');
// Web requests must be authenticated; CLI runs (docker exec / cron) are trusted
// because shell access to the container is already a higher privilege than login.
if (PHP_SAPI !== 'cli') {
  require_once('auth_user.php');
}

// ── Version tracking table ────────────────────────────────────────────────────
mysqli_query($con, "CREATE TABLE IF NOT EXISTS torque_schema_version (
  version     INT NOT NULL PRIMARY KEY,
  description VARCHAR(255),
  applied_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

function migration_applied($con, $version) {
  $r = mysqli_query($con, "SELECT 1 FROM torque_schema_version WHERE version = " . (int)$version);
  return $r && mysqli_num_rows($r) > 0;
}
function record_migration($con, $version, $desc) {
  mysqli_query($con, "INSERT IGNORE INTO torque_schema_version (version, description) VALUES (" .
    (int)$version . ", " . quote_value($desc) . ")");
}

// ── v10: idx_session on sessions table ────────────────────────────────────────
if (!migration_applied($con, 10)) {
  $r = mysqli_query($con, "SHOW INDEX FROM " . quote_name($db_sessions_table) . " WHERE Key_name = 'idx_session'");
  if ($r && mysqli_num_rows($r) == 0) {
    mysqli_query($con, "ALTER TABLE " . quote_name($db_sessions_table) . " ADD INDEX idx_session (session)");
  }
  record_migration($con, 10, 'Add idx_session index to sessions table');
  echo "Migration 10: sessions idx_session — done.\n";
} else {
  echo "Migration 10: already applied.\n";
}

// ── v11: idx_session_time on all raw_logs_* tables ────────────────────────────
if (!migration_applied($con, 11)) {
  $table_list = mysqli_query($con,
    "SELECT table_name FROM INFORMATION_SCHEMA.tables
     WHERE table_schema = " . quote_value($db_name) .
    " AND table_name LIKE " . quote_value($db_table . '_%') .
    " ORDER BY table_name DESC");
  while ($row = mysqli_fetch_assoc($table_list)) {
    $tbl = $row['table_name'];
    $idx_check = mysqli_query($con, "SHOW INDEX FROM " . quote_name($tbl) . " WHERE Key_name = 'idx_session_time'");
    if ($idx_check && mysqli_num_rows($idx_check) == 0) {
      mysqli_query($con, "ALTER TABLE " . quote_name($tbl) . " ADD INDEX idx_session_time (session, time)");
    }
  }
  record_migration($con, 11, 'Add idx_session_time to all raw_logs monthly tables');
  echo "Migration 11: raw_logs idx_session_time — done.\n";
} else {
  echo "Migration 11: already applied.\n";
}

// ── v20: csv_header column + index on torque_keys (plugin batch upload) ───────
if (!migration_applied($con, 20)) {
  $col_check = mysqli_query($con, "SHOW COLUMNS FROM " . quote_name($db_keys_table) . " LIKE 'csv_header'");
  if ($col_check && mysqli_num_rows($col_check) == 0) {
    mysqli_query($con, "ALTER TABLE " . quote_name($db_keys_table) . " ADD COLUMN csv_header VARCHAR(120) DEFAULT NULL");
  }
  $idx_check = mysqli_query($con, "SHOW INDEX FROM " . quote_name($db_keys_table) . " WHERE Key_name = 'idx_torque_keys_csv_header'");
  if ($idx_check && mysqli_num_rows($idx_check) == 0) {
    mysqli_query($con, "ALTER TABLE " . quote_name($db_keys_table) . " ADD INDEX idx_torque_keys_csv_header (csv_header)");
  }
  record_migration($con, 20, 'Add csv_header column and index to torque_keys');
  echo "Migration 20: csv_header column + index — done.\n";
} else {
  echo "Migration 20: already applied.\n";
}

// ── v23: GPS quality tracking columns on sessions ─────────────────────────────
if (!migration_applied($con, 23)) {
  $r = mysqli_query($con, "SHOW COLUMNS FROM " . quote_name($db_sessions_table) . " LIKE 'gps_points'");
  if ($r && mysqli_num_rows($r) == 0) {
    mysqli_query($con, "ALTER TABLE " . quote_name($db_sessions_table) . " ADD COLUMN gps_points INT NOT NULL DEFAULT 0");
  }
  $r2 = mysqli_query($con, "SHOW COLUMNS FROM " . quote_name($db_sessions_table) . " LIKE 'gps_valid_points'");
  if ($r2 && mysqli_num_rows($r2) == 0) {
    mysqli_query($con, "ALTER TABLE " . quote_name($db_sessions_table) . " ADD COLUMN gps_valid_points INT NOT NULL DEFAULT 0");
  }
  record_migration($con, 23, 'Add GPS quality tracking columns to sessions');
  echo "Migration 23: GPS quality columns — done.\n";
} else {
  echo "Migration 23: already applied.\n";
}

// ── v24: idx_timestart on sessions ────────────────────────────────────────────
if (!migration_applied($con, 24)) {
  $idx_ts = mysqli_query($con, "SHOW INDEX FROM " . quote_name($db_sessions_table) . " WHERE Key_name = 'idx_timestart'");
  if ($idx_ts && mysqli_num_rows($idx_ts) == 0) {
    mysqli_query($con, "ALTER TABLE " . quote_name($db_sessions_table) . " ADD INDEX idx_timestart (timestart)");
  }
  record_migration($con, 24, 'Add idx_timestart index to sessions table');
  echo "Migration 24: sessions idx_timestart — done.\n";
} else {
  echo "Migration 24: already applied.\n";
}

// ── CSV header seeds — always-run idempotent ON DUPLICATE KEY UPDATE ──────────
// These upsert csv_header into torque_keys; safe to run on every upgrade call.
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
  mysqli_query($con, "INSERT INTO " . quote_name($db_keys_table) .
    " (id, description, csv_header) VALUES (" .
    quote_value($kid) . ", " . quote_value($kdesc) . ", " . quote_value($kheader) . ")" .
    " ON DUPLICATE KEY UPDATE csv_header = " . quote_value($kheader));
}
echo "CSV header seeds refreshed.\n";

// ── v25: GPS correction tables (2026-06-01) ───────────────────────────────────
// gps_corrections stores corrected coordinates from external providers (e.g. Home
// Assistant history). gps_repair_queue tracks which rows were found invalid and
// their repair status. Both use torque_time_ms BIGINT to match raw_logs.time.
if (!migration_applied($con, 25)) {
  $r = mysqli_query($con, "SHOW TABLES LIKE 'gps_corrections'");
  if ($r && mysqli_num_rows($r) == 0) {
    mysqli_query($con, "CREATE TABLE gps_corrections (
      id                   BIGINT AUTO_INCREMENT PRIMARY KEY,
      session              VARCHAR(64) NOT NULL,
      raw_table            VARCHAR(64) NOT NULL,
      torque_time_ms       BIGINT NOT NULL,
      raw_lat              DOUBLE NULL,
      raw_lon              DOUBLE NULL,
      corrected_lat        DOUBLE NOT NULL,
      corrected_lon        DOUBLE NOT NULL,
      accuracy             DOUBLE NULL,
      source               VARCHAR(64) NOT NULL,
      source_entity        VARCHAR(128) NULL,
      source_updated_at_ms BIGINT NULL,
      reason               VARCHAR(64) NOT NULL,
      confidence           VARCHAR(32) NOT NULL DEFAULT 'high',
      created_at           TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
      updated_at           TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      UNIQUE KEY uniq_raw_point (raw_table, session, torque_time_ms),
      KEY idx_session_time (session, torque_time_ms)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
  }
  $r = mysqli_query($con, "SHOW TABLES LIKE 'gps_repair_queue'");
  if ($r && mysqli_num_rows($r) == 0) {
    mysqli_query($con, "CREATE TABLE gps_repair_queue (
      id             BIGINT AUTO_INCREMENT PRIMARY KEY,
      session        VARCHAR(64) NOT NULL,
      raw_table      VARCHAR(64) NOT NULL,
      torque_time_ms BIGINT NOT NULL,
      reason         VARCHAR(64) NOT NULL,
      created_at     TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
      processed_at   DATETIME NULL,
      last_error     TEXT NULL,
      UNIQUE KEY uniq_raw_repair (raw_table, session, torque_time_ms),
      KEY idx_pending (processed_at, created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
  }
  record_migration($con, 25, 'Add gps_corrections and gps_repair_queue tables');
  echo "Migration 25: GPS correction tables — done.\n";
} else {
  echo "Migration 25: already applied.\n";
}

mysqli_close($con);
echo "Upgrade complete.\n";
?>
