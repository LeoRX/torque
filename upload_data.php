<?php
require_once ('db.php');
require_once ('auth_app.php');

// ── Upload debug log (14-day rolling retention) ───────────────────────────────
// One file per session in data/upload_log/SESSIONID.log.
// Each line: server_timestamp TAB raw_query_string
// Files are removed automatically after 14 days.
// To inspect: cat data/upload_log/SESSIONID.log
(function() {
  $log_dir = __DIR__ . '/data/upload_log';

  // Create directory if it doesn't exist — failure is non-fatal, never break uploads
  if (!is_dir($log_dir) && !mkdir($log_dir, 0755, true) && !is_dir($log_dir)) {
    return; // can't create dir — skip logging silently
  }

  // Opportunistic cleanup: scan for files older than 14 days.
  // Runs on ~1 in 50 requests to avoid glob() overhead on every call.
  if (rand(1, 50) === 1) {
    $cutoff = time() - (14 * 86400);
    foreach (glob($log_dir . '/*.log') ?: [] as $f) {
      $mtime = filemtime($f);
      if ($mtime !== false && $mtime < $cutoff) {
        unlink($f); // best-effort; ignore if already gone
      }
    }
  }

  // Log this datapoint: validate session ID first (same check as main code below)
  if (!isset($_GET['session']) || !preg_match('/^\d{10,15}$/', $_GET['session'])) {
    return; // malformed request — main code will reject it too
  }

  $log_file = $log_dir . '/' . $_GET['session'] . '.log';
  $line     = date('Y-m-d H:i:s') . "\t" . $_SERVER['QUERY_STRING'] . "\n";
  file_put_contents($log_file, $line, FILE_APPEND | LOCK_EX); // failure is non-fatal
})();
// ─────────────────────────────────────────────────────────────────────────────

$newest_table_list = mysqli_query($con, "SELECT table_name FROM INFORMATION_SCHEMA.tables WHERE table_schema = " . quote_value($db_name) . " AND table_name LIKE " . quote_value($db_table . '%') . " ORDER BY table_name DESC LIMIT 1;");
$newest_table = "";
while( $row = mysqli_fetch_assoc($newest_table_list) ) {
  $newest_table = $row["table_name"];
}
// Create an array of all the existing fields in the database
$result = mysqli_query($con, "SHOW COLUMNS FROM " . quote_name($newest_table)) ;
if (mysqli_num_rows($result) > 0) {
  while ($row = mysqli_fetch_assoc($result)) {
    $dbfields[]=($row['Field']);
  }
}
// Iterate over all the k* _GET arguments to check that a field exists
if (sizeof($_GET) > 0) {
  $keys = array();
  $values = array();
  $sesskeys = array();
  $sessvalues = array();
  $datakeys = array();
  $datavalues = array();
  $sessuploadid = "";
  $sesstime = "0";
  $sessprofilename = "";
  $sessprofilefueltype = "";
  $sessprofileweight = "0";
  $sessprofileve = "0";
  $sessprofilefuelcost = "0";
  // Validate session ID — must be a numeric millisecond timestamp
  if (!isset($_GET["session"]) || !preg_match('/^\d{10,15}$/', $_GET["session"])) {
    echo "ERROR!"; exit;
  }
  $session_id = $_GET["session"];
  $tableYear = date( "Y", (int)$session_id/1000 );
  $tableMonth = date( "m", (int)$session_id/1000 );
  $db_table_full = "{$db_table}_{$tableYear}_{$tableMonth}";
  // If the desired table name doesn't exist, create it copying columns from the previous month's table
  $current_table_list_query = "SELECT table_name FROM INFORMATION_SCHEMA.tables WHERE table_schema = " . quote_value($db_name) . " AND table_name = " . quote_value($db_table_full);
  $current_table_list = mysqli_query($con, $current_table_list_query);
  if ( ! mysqli_fetch_assoc($current_table_list) ) {
    mysqli_query($con, "CREATE TABLE " . quote_name($db_table_full) . " SELECT * FROM " . quote_name($newest_table) . " WHERE 1=0") ;
  }

  // Track which categories of data appeared in this request.
  // $submitval is overwritten on every loop iteration, so checking it post-loop only
  // reflects the *last* key processed.  If a notice key (submitval=4) follows k* keys
  // (submitval=2), the k* INSERT block at the bottom would never fire.  These flags
  // record every category seen across all iterations and are checked after the loop.
  $has_session_keys = false;  // v, eml, time, id, session
  $has_kdata        = false;  // k* OBD data columns
  $has_profile      = false;  // profile* keys

  // Initialise session tracking vars; the session-check block below sets them from the
  // DB (or from $sesstime for a new session), but guard against k-data-only requests.
  $sessTimeStart = $sesstime;
  $sessTimeEnd   = $sesstime;
  $sessSize      = 0;

  foreach ($_GET as $key => $value) {
    // We will operate on 5 data sets which are defined by 5 "submit values"
    //   0 = Data we aren't dealing with, do nothing
    //   1 = Session data; Any value higher than this requires an entry in the sessions table
    //   2 = Data; There is a column check, then the data is added to the raw table
    //   3 = Profile data; Update the profile data to the sessions table
    //   4 = Notice data; Alert/event data...I'm doing nothing with this yet
    if (in_array($key, array("v", "eml", "time", "id", "session"))) {
      // Keep non k*,  non profile, and non notice columns listed here
      if ($key == 'session') {
        $sessuploadid = $value;
      }
      if ($key == 'time') {
        $sesstime = $value;
      }
      $sesskeys[] = $key;
      $sessvalues[] = $value;
      $submitval = 1;
      $has_session_keys = true;
    } else if (preg_match("/^k/", $key)) {
      // Keep columns starting with k
      $keys[] = $key;
      // My Torque app tries to pass "Infinity" in for some values...catch that error, set to -1
      if ($value == 'Infinity') {
        $values[] = -1;
      } else {
        $values[] = $value;
      }
      $submitval = 2;
      $has_kdata = true;
    } else if (preg_match("/^profile/", $key)) {
      if ($key == 'profileName') {
        $sessprofilename = $value;
      }
      if ($key == 'profileFuelType') {
        $sessprofilefueltype = $value;
      }
      if ($key == 'profileWeight') {
        $sessprofileweight = $value;
      }
      if ($key == 'profileVe') {
        $sessprofileve = $value;
      }
      if ($key == 'profileFuelCost') {
        $sessprofilefuelcost = $value;
      }
      $submitval = 3;
      $has_profile = true;
    } else if (in_array($key, array("notice", "noticeClass"))) {
      $keys[] = $key;
      $values[] = $value;
      $submitval = 4;
    } else {
      $submitval = 0;
    }
    // If the field is a data field and doesn't already exist, add it to the database
    if (!in_array($key, $dbfields) and $submitval == 2) {
      // If the value isn't already in the latest DB table, we better check every DB table
      $table_list = mysqli_query($con, "SELECT table_name FROM INFORMATION_SCHEMA.tables WHERE table_schema = " . quote_value($db_name) . " AND table_name LIKE " . quote_value($db_table . '%') . " ORDER BY table_name DESC;");
      while( $row = mysqli_fetch_assoc($table_list) ) {
        $db_table_name = $row["table_name"];
        // Create an array of all the existing fields in the database
        $result = mysqli_query($con, "SHOW COLUMNS FROM " . quote_name($db_table_name)) ;
        if (mysqli_num_rows($result) > 0) {
          $dbfields_per_table = array();
          while ($row = mysqli_fetch_assoc($result)) {
            $dbfields_per_table[]=($row['Field']);
          }
        }
        if (!in_array($key, $dbfields_per_table) and $submitval == 2) {
          // In PHP float and double are the same, so start with float as a default
          if ( is_float($value) ) {
            // Add field if it's a float to EVERY raw values table
            $sqlalter = "ALTER TABLE " . quote_name($db_table_name) . " ADD ".quote_name($key)." float NOT NULL default '0'";
          } else {
            // Add field if it's a string to EVERY raw values table, specifically varchar(255)
            $sqlalter = "ALTER TABLE " . quote_name($db_table_name) . " ADD ".quote_name($key)." VARCHAR(255) NOT NULL default 'Not Specified'";
          }
          mysqli_query($con, $sqlalter) ;
        }
      }
    }
    $sqlkeyquery = "SELECT id FROM " . quote_name($db_keys_table) . " WHERE id=".quote_value($key);
    $result = mysqli_query($con, $sqlkeyquery);
    $row = mysqli_fetch_assoc($result);
    if ( ! $row and $submitval == 2 ) {
      $sqlalterkey = "INSERT INTO " . quote_name($db_keys_table) . " (id, description, type, populated) VALUES (".quote_value($key).", ".quote_value($key).", 'varchar(255)', '1')";
      mysqli_query($con, $sqlalterkey) ;
    }
  }
  // The way session uploads work, there's a separate HTTP call for each datapoint.  This is why raw logs is
  //  so huge, and has so much repeating data. This is my attempt to flatten the redundant data into the
  //  sessions table; this code checks if there is already a row for the current session, and if there is, only
  //  update the ending time and the count of datapoints.  If there isn't a row, insert one.

  // No matter what, if session keys were present, make sure a session record exists.
  if ( $has_session_keys && (sizeof($sesskeys) === sizeof($sessvalues)) && sizeof($sesskeys) > 0 ) {
    $sessionqrystring = "SELECT session, timestart, timeend, sessionsize FROM " . quote_name($db_sessions_table) . " WHERE session LIKE ".quote_value($sessuploadid);
    $sessionqry = mysqli_query($con, $sessionqrystring) ;
    $row = mysqli_fetch_assoc($sessionqry);
    if ( ! $row ) {
      $sessioninsertstring = "INSERT INTO " . quote_name($db_sessions_table) . " (".quote_names($sesskeys).", timestart, sessionsize) VALUES (".quote_values($sessvalues).", $sesstime, '1')";
      mysqli_query($con, $sessioninsertstring) ;
      // Initialize session tracking vars for the first datapoint of a new session.
      // Without this, $sessTimeStart stays uninitialized (""), causing UPDATE to write
      // an empty timestart — the root cause of the 1970-01-01 epoch display bug.
      $sessTimeStart = $sesstime;
      $sessTimeEnd = $sesstime;
      $sessSize = 1;
    } else {
      $sessTimeStart = $row['timestart'];
      $sessTimeEnd = $row['timeend'];
      $sessSize = $row['sessionsize'];
    }
  }
  // Prepare for inserting a value into the full data table
  $datakeys = $keys;
  $datavalues = $values;
  $datakeys[] = 'session';
  $datavalues[] = $sessuploadid;
  $datakeys[] = 'time';
  $datavalues[] = $sesstime;
  if ( $has_kdata && ( sizeof($datakeys) === sizeof($datavalues) ) && sizeof($datakeys) > 0 ) {
    // Now insert the data for all the fields into the raw logs table
    $sql = "INSERT INTO " . quote_name($db_table_full) . " (".quote_names($datakeys).") VALUES (".quote_values($datavalues).")";
    mysqli_query($con, $sql) ;
    // Update session variables
    // If this is the earliest timestamp for this session, update the "Session start time"
    if ( $sessTimeStart > $sesstime ) {
      $sessTimeStart = $sesstime;
    }
    // If this is the latest timestamp for this session, update the "Session end time"
    if ( $sessTimeEnd < $sesstime ) {
      $sessTimeEnd = $sesstime;
    }
    // Increment the session size counter
    $sessSize = $sessSize + 1;
    // Update the session table
    $dataqrystring = "UPDATE " . quote_name($db_sessions_table) . " SET timestart = ".quote_value($sessTimeStart).", timeend = ".quote_value($sessTimeEnd).", sessionsize = ".quote_value($sessSize)." WHERE session = ".quote_value($sessuploadid);
    mysqli_query($con, $dataqrystring) ;
  }
  if ( $has_profile ) {
    $profileqrystring = "UPDATE " . quote_name($db_sessions_table) . " SET profileName = ".quote_value($sessprofilename).", profileFuelType = ".quote_value($sessprofilefueltype).", profileWeight = ".quote_value($sessprofileweight).", profileVe = ".quote_value($sessprofileve).", profileFuelCost = ".quote_value($sessprofilefuelcost)." WHERE session = ".quote_value($sessuploadid);
    mysqli_query($con, $profileqrystring) ;
  }
}
mysqli_close($con);

// Return the response required by Torque
echo "OK!";
?>
