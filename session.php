<?php
//echo "<!-- Begin session.php at ".date("H:i:s", microtime(true))." -->\r\n";
$loadstart = date("g:i:s A", (int)microtime(true));
$loadmicrostart = explode(' ', microtime());
$loadmicrostart = $loadmicrostart[1] + $loadmicrostart[0];
ini_set('memory_limit', '256M');
require_once("./db.php");
require_once("./get_settings.php");
require_once("./auth_user.php");
require_once("./del_session.php");
require_once('./csrf.php');
//require_once("./merge_sessions.php");
require_once("./get_sessions.php");
require_once("./get_columns.php");
require_once("./plot.php");

if (!empty($sids)) {
    $_SESSION['recent_session_id'] = strval(max($sids));
}
// $display_timezone is set by get_settings.php (included above)

// Capture the session ID if one has been chosen already
if (isset($_GET["id"])) {
  $session_id = preg_replace('/\D/', '', $_GET['id']);
}

// Call exit function
if (isset($_GET['logout'])) {
    logout_user();
}

// $filteryearmonth is already set (and sanitised) by get_sessions.php above.

// Define some variables to be used in variable management later, specifically when choosing default vars to plot
$plotVar = [];
$i = 1;
while ( isset($_POST["s$i"]) || isset($_GET["s$i"]) ) {
  if (isset($_POST["s$i"])) {
    $plotVar[$i] = $_POST["s$i"];
  } elseif (isset($_GET["s$i"])) {
    $plotVar[$i] = $_GET["s$i"];
  }
  $i = $i + 1;
}
$var1 = $plotVar[1] ?? "";

// From the output of the get_sessions.php file, populate the page with info from
//  the current session. Using successful existence of a session as a trigger,
//  populate some other variables as well.
if (isset($sids[0])) {
  if (!isset($session_id)) {
    $session_id = $sids[0];
  }
  //For the merge function, we need to find out, what would be the next session
  $idx = array_search( $session_id, $sids);
  $session_id_next = "";
  if($idx>0) {
    $session_id_next = $sids[$idx-1];
  }
  $tableYear = date( "Y", intdiv((int)$session_id, 1000) );
  $tableMonth = date( "m", intdiv((int)$session_id, 1000) );
  $db_table_full = "{$db_table}_{$tableYear}_{$tableMonth}";
  // Get GPS data + speed for the selected session (used by map route + chart crosshair).
  // Prefers corrected coordinates from gps_corrections where available; falls back to raw GPS.
  // Fifth element of each _routeData entry is the GPS source ('torque' or 'home_assistant').
  $_gps_tbl    = quote_name($db_table_full);
  $_gps_ctbl   = quote_name('gps_corrections');
  $_gps_sid    = quote_value($session_id);
  $_gps_rtbl   = quote_value($db_table_full);
  $_valid_raw  = "r.kff1005 IS NOT NULL AND r.kff1006 IS NOT NULL
                  AND r.kff1005 != 0 AND r.kff1006 != 0
                  AND r.kff1005 BETWEEN -180 AND 180
                  AND r.kff1006 BETWEEN -90 AND 90";
  $_gps_sql_full  = "SELECT
        COALESCE(gc.corrected_lon, r.kff1005) AS lon,
        COALESCE(gc.corrected_lat, r.kff1006) AS lat,
        COALESCE(NULLIF(r.kd,0), NULLIF(r.kff1001,0), 0) AS speed,
        r.time,
        IF(gc.id IS NOT NULL, gc.source, 'torque') AS gps_source
      FROM $_gps_tbl r
      LEFT JOIN $_gps_ctbl gc
             ON gc.raw_table = $_gps_rtbl
            AND gc.session   = $_gps_sid
            AND gc.torque_time_ms = r.time
      WHERE r.session = $_gps_sid
        AND (gc.id IS NOT NULL OR ($_valid_raw))
      ORDER BY r.time ASC";
  $_gps_sql_basic = "SELECT
        COALESCE(gc.corrected_lon, r.kff1005) AS lon,
        COALESCE(gc.corrected_lat, r.kff1006) AS lat,
        COALESCE(NULLIF(r.kd,0), 0) AS speed,
        r.time,
        IF(gc.id IS NOT NULL, gc.source, 'torque') AS gps_source
      FROM $_gps_tbl r
      LEFT JOIN $_gps_ctbl gc
             ON gc.raw_table = $_gps_rtbl
            AND gc.session   = $_gps_sid
            AND gc.torque_time_ms = r.time
      WHERE r.session = $_gps_sid
        AND (gc.id IS NOT NULL OR ($_valid_raw))
      ORDER BY r.time ASC";
  // Raw-only fallback: used when gps_corrections does not exist yet (pre-migration)
  // or kff1001 is missing. Never references the corrections table so it always works.
  $_gps_sql_raw = "SELECT r.kff1005 AS lon, r.kff1006 AS lat,
        COALESCE(NULLIF(r.kd,0), 0) AS speed, r.time, 'torque' AS gps_source
      FROM $_gps_tbl r
      WHERE r.session = $_gps_sid AND ($_valid_raw)
      ORDER BY r.time ASC";
  $sessionqry = mysqli_query($con, $_gps_sql_full);
  if (!$sessionqry) {
    $sessionqry = mysqli_query($con, $_gps_sql_basic);
  }
  if (!$sessionqry) {
    $sessionqry = mysqli_query($con, $_gps_sql_raw);
  }
  $mapdata  = array();
  $maxSpeed = 0;
  while ($sessionqry && $geo = mysqli_fetch_assoc($sessionqry)) {
    $lon = floatval($geo['lon']);
    $lat = floatval($geo['lat']);
    $spd = floatval($geo['speed']);
    // Final validity guard in case a correction contains unusual coordinates
    if ($lon < -180 || $lon > 180 || $lat < -90 || $lat > 90) continue;
    if ($lon === 0.0 && $lat === 0.0) continue;
    $mapdata[] = [$lon, $lat, $spd, (int)$geo['time'], $geo['gps_source']];
    if ($spd > $maxSpeed) $maxSpeed = $spd;
  }
  $imapdata = json_encode($mapdata);
  if ($maxSpeed < 10) $maxSpeed = 120; // sensible default if no speed data

  // Count repaired points among the rendered route (source != 'torque').
  $repairedCount = 0;
  foreach ($mapdata as $_md) {
    if (($_md[4] ?? 'torque') !== 'torque') $repairedCount++;
  }

  // Session exists — always show chart/variable sections
  $setZoomManually = 0;
  // Separately track whether GPS data exists (for map behaviour)
  $mapHasGPS = (count($mapdata) > 0);

  // GPS quality: query sessions table to distinguish "no GPS" from "GPS recorded but no fix"
  $gpsQuality = 'unknown'; // fallback if column doesn't exist yet (pre-migration sessions)
  if ($mapHasGPS) {
      $gpsQuality = 'ok';
  } else {
      $_sess_gps_q = mysqli_query($con,
          "SELECT gps_points FROM " . quote_name($db_sessions_table) .
          " WHERE session = " . quote_value($session_id) . " LIMIT 1");
      if ($_sess_gps_q) {
          $_sgrow = mysqli_fetch_assoc($_sess_gps_q);
          if (is_array($_sgrow)) {
              $gpsQuality = ((int)$_sgrow['gps_points'] > 0) ? 'recorded_no_fix' : 'none';
          }
      }
  }

  // Query the list of years and months where sessions have been logged, to be used later
  // YYYY_MM with zero-padded month (via %m) sorts correctly as a plain string DESC.
  // Using SELECT DISTINCT + ORDER BY Suffix avoids GROUP BY / ORDER BY ambiguity in MariaDB.
  $yearmonthquery = mysqli_query($con, "SELECT DISTINCT
    CONCAT(YEAR(FROM_UNIXTIME(session/1000)), '_', DATE_FORMAT(FROM_UNIXTIME(session/1000),'%m')) as Suffix,
    CONCAT(MONTHNAME(FROM_UNIXTIME(session/1000)), ' ', YEAR(FROM_UNIXTIME(session/1000))) as Description
    FROM " . quote_name($db_sessions_table) . "
    ORDER BY Suffix DESC");
  if (!$yearmonthquery) { $yearmonthquery = null; }
  $yearmonthsuffixarray = array();
  $yearmonthdescarray = array();
  $i = 0;
  while($yearmonthquery && $row = mysqli_fetch_assoc($yearmonthquery)) {
    $yearmonthsuffixarray[$i] = $row['Suffix'];
    $yearmonthdescarray[$i] = $row['Description'];
    $i = $i + 1;
  }

  // Query the list of profiles where sessions have been logged, to be used later
  $profilequery = mysqli_query($con, "SELECT distinct profileName FROM " . quote_name($db_sessions_table) . " ORDER BY profileName asc");
  if (!$profilequery) { $profilequery = null; }
  $profilearray = array();
  $i = 0;
  while($profilequery && $row = mysqli_fetch_assoc($profilequery)) {
    // Skip blank / unset profile names — they belong under "All Profiles"
    if ($row['profileName'] === '' || $row['profileName'] === 'Not Specified' || $row['profileName'] === null) continue;
    $profilearray[$i] = $row['profileName'];
    $i = $i + 1;
  }

  // ── HUD Widget config (from settings) ──
  $hudConfig = [
    'gauge1' => ['pid' => $hud_gauge1_pid, 'label' => $hud_gauge1_label, 'min' => $hud_gauge1_min, 'max' => $hud_gauge1_max, 'suffix' => $hud_gauge1_suffix],
    'gauge2' => ['pid' => $hud_gauge2_pid, 'label' => $hud_gauge2_label, 'min' => $hud_gauge2_min, 'max' => $hud_gauge2_max, 'suffix' => $hud_gauge2_suffix],
    'gauge3' => ['pid' => $hud_gauge3_pid, 'label' => $hud_gauge3_label, 'min' => $hud_gauge3_min, 'max' => $hud_gauge3_max, 'suffix' => $hud_gauge3_suffix],
    'fuel'   => ['pid' => $hud_stat_fuel_pid, 'label' => $hud_stat_fuel_label],
  ];

  // ── HUD session averages — query AVG for each configured PID ──
  // k-codes come from torque_settings (validated on save); use quote_name() for column identifiers.
  $hudSessionAvg = ['gauge1' => null, 'gauge2' => null, 'gauge3' => null, 'fuel' => null];
  $_g1col  = quote_name($hud_gauge1_pid);
  $_g2col  = quote_name($hud_gauge2_pid);
  $_g3col  = quote_name($hud_gauge3_pid);
  $_fcol   = quote_name($hud_stat_fuel_pid);
  $_avg_sql = "SELECT AVG($_g1col) AS g1, AVG($_g2col) AS g2,
                      AVG($_g3col) AS g3, AVG($_fcol) AS fuel
               FROM " . quote_name($db_table_full) . " WHERE session=" . quote_value($session_id);
  $_avg_res = mysqli_query($con, $_avg_sql);
  if ($_avg_res) {
    $_avg_row = mysqli_fetch_assoc($_avg_res);
    $hudSessionAvg['gauge1'] = $_avg_row['g1']   !== null ? (float)$_avg_row['g1']   : null;
    $hudSessionAvg['gauge2'] = $_avg_row['g2']   !== null ? (float)$_avg_row['g2']   : null;
    $hudSessionAvg['gauge3'] = $_avg_row['g3']   !== null ? (float)$_avg_row['g3']   : null;
    $hudSessionAvg['fuel']   = $_avg_row['fuel'] !== null ? (float)$_avg_row['fuel'] : null;
    mysqli_free_result($_avg_res);
  }

  //Close the MySQL connection, which is why we can't query years later
  mysqli_free_result($sessionqry);
  mysqli_close($con);
} else {
  //Default map in case there's no sessions to query.  Very unlikely this will get used.
  $imapdata = '[]';
  $maxSpeed = 0;
  $setZoomManually = 1;
}

?>
<!DOCTYPE html>
<html lang="en" data-torque-theme="<?php echo htmlspecialchars($app_theme); ?>">
  <head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="icon" type="image/svg+xml" href="static/favicon.svg">
    <title>Open Torque Viewer</title>
    <meta name="description" content="Open Torque Viewer">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.3/css/bootstrap.min.css"
      integrity="sha512-jnSuA4Ss2PkkikSOLtYs8BlYIeeIK1h99ty4YfvRPAlzr377vr3CXDb7sb7eEEBYjDtcYj+AjBH3FLv5uSJuXg=="
      crossorigin="anonymous">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css"
      integrity="sha384-XGjxtQfXaH2tnPFa9x+ruJTuLE3Aa6LhHSWRr1XeTyhezb4abCG4ccI5AkVDxqC+"
      crossorigin="anonymous">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/tom-select/2.3.1/css/tom-select.bootstrap5.min.css"
      integrity="sha512-w7Qns0H5VYP5I+I0F7sZId5lsVxTH217LlLUPujdU+nLMWXtyzsRPOP3RCRWTC8HLi77L4rZpJ4agDW3QnF7cw=="
      crossorigin="anonymous">
    <link rel="stylesheet" href="static/css/torque.css">
    <link rel="stylesheet" href="static/css/themes.css">
    <link rel="stylesheet" href="static/css/hud.css">
    <link rel="stylesheet" href="https://fonts.googleapis.com/css?family=Lato">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jquery/3.7.1/jquery.min.js"
      integrity="sha512-v2CJ7UaYy4JwqLDIrZUI/4hqeoQieOmAZNXBeQyjo21dadnwR+8ZaIJVT8EE2iyI61OV8e6M8PP2/4hpQINQ/g=="
      crossorigin="anonymous"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.3/js/bootstrap.bundle.min.js"
      integrity="sha512-7Pi/otdlbbCR+LnW+F7PwFcSDJOuUJB3OxtEHbg4vSMvzvJjde4Po1v4BR9Gdc9aXNUNFVUY+SK51wWT8WF0Gg=="
      crossorigin="anonymous"></script>
    <script src="static/js/jquery.peity.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/tom-select/2.3.1/js/tom-select.complete.min.js"
      integrity="sha512-zdXqksVc9s0d2eoJGdQ2cEhS4mb62qJueasTG4HjCT9J8f9x5gXCQGSdeilD+C7RqvUi1b4DdD5XaGjJZSlP9Q=="
      crossorigin="anonymous"></script>
<?php if ($setZoomManually === 0) { ?>
    <script src="https://cdn.jsdelivr.net/npm/hammerjs@2.0.8/hammer.min.js"
      integrity="sha384-Cs3dgUx6+jDxxuqHvVH8Onpyj2LF1gKZurLDlhqzuJmUqVYMJ0THTWpxK5Z086Zm"
      crossorigin="anonymous"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js"
      integrity="sha384-NrKB+u6Ts6AtkIhwPixiKTzgSKNblyhlk0Sohlgar9UHUBzai/sgnNNWWd291xqt"
      crossorigin="anonymous"></script>
    <script src="https://cdn.jsdelivr.net/npm/chartjs-adapter-date-fns@3.0.0/dist/chartjs-adapter-date-fns.bundle.min.js"
      integrity="sha384-cVMg8E3QFwTvGCDuK+ET4PD341jF3W8nO1auiXfuZNQkzbUUiBGLsIQUE+b1mxws"
      crossorigin="anonymous"></script>
    <script src="https://cdn.jsdelivr.net/npm/chartjs-plugin-zoom@2.0.1/dist/chartjs-plugin-zoom.min.js"
      integrity="sha384-zPzbVRXfR492Sd5D+HydTYCxxgHAfgVO8KERbLlpeH5unsmbAEXrscGUUqLZG9BM"
      crossorigin="anonymous"></script>
<?php } ?>
    <!-- Mapbox GL JS -->
    <link href="https://api.mapbox.com/mapbox-gl-js/v3.3.0/mapbox-gl.css" rel="stylesheet">
    <script src="https://api.mapbox.com/mapbox-gl-js/v3.3.0/mapbox-gl.js"></script>
    <script>
      (function(){var s=localStorage.getItem('torque-theme')||'light';document.documentElement.setAttribute('data-bs-theme',s);})();
      var _routeData   = <?php echo $imapdata; ?>;
      var _maxSpeed    = <?php echo (float)$maxSpeed; ?>;
      var _noSession   = <?php echo $setZoomManually ? 'true' : 'false'; ?>;
      var _hasGPS      = <?php echo (!$setZoomManually && ($mapHasGPS ?? false)) ? 'true' : 'false'; ?>;
      var _gpsQuality  = <?php echo json_encode($gpsQuality ?? 'unknown'); ?>;
      var _mbToken     = <?php echo json_encode($mapbox_token); ?>;
      var _mbStyle     = <?php echo json_encode($mapbox_style); ?>;
      var _lineWeight  = <?php echo (int)$map_line_weight; ?>;
      var _lineOpacity = <?php echo (float)$map_line_opacity; ?>;
      var _chartSeries = <?php
        $_series = [];
        if ($setZoomManually === 0 && isset($var1) && $var1 !== '') {
          $i = 1;
          while (isset($plotVar[$i]) && !empty($plotVar[$i])) {
            $_series[] = ['label' => json_decode($plotLabel[$i]), 'kcode' => $plotVar[$i], 'data' => $plotData[$i]];
            $i++;
          }
        }
        echo json_encode($_series, JSON_UNESCAPED_UNICODE);
      ?>;
      var _hudConfig     = <?php echo isset($hudConfig)     ? json_encode($hudConfig,     JSON_UNESCAPED_UNICODE) : 'null'; ?>;
      var _hudSessionAvg = <?php echo isset($hudSessionAvg) ? json_encode($hudSessionAvg) : 'null'; ?>;
      var _aiSessionId   = <?php echo ($claude_enabled && isset($session_id)) ? json_encode($session_id) : "''"; ?>;
    </script>
    <script src="static/js/torquehelpers.js"></script>
    <script src="static/js/session.js"></script>
  </head>
  <body>
    <nav class="navbar navbar-expand-md navbar-dark bg-dark fixed-top hud-navbar">
      <div class="container-fluid gap-2">

        <!-- Brand — always visible -->
        <a class="navbar-brand flex-shrink-0 hud-brand" href="session.php">⬡&nbsp;TORQUE</a>

        <!-- Profile filter + calendar — always visible (most essential session control) -->
        <form id="navfilterform" class="d-flex align-items-center gap-2 flex-shrink-0" method="post" role="form" action="url.php?id=<?php echo $session_id; ?>">
          <select id="selprofile" name="selprofile" class="form-select form-select-sm navbar-filter" style="max-width:130px;" onchange="document.getElementById('navfilterform').submit()">
            <option value="ALL"<?php if ($filterprofile == '%' || $filterprofile == 'ALL' || empty($filterprofile)) echo ' selected'; ?>>All Profiles</option>
<?php $i = 0; while(isset($profilearray[$i])) { ?>
            <option value="<?php echo htmlspecialchars($profilearray[$i], ENT_QUOTES, 'UTF-8'); ?>"<?php if ($filterprofile == $profilearray[$i]) echo ' selected'; ?>><?php echo htmlspecialchars($profilearray[$i], ENT_QUOTES, 'UTF-8'); ?></option>
<?php   $i = $i + 1; } ?>
          </select>
          <button type="button" id="btn-cal" class="btn btn-sm btn-outline-light flex-shrink-0" title="Select date range by calendar"><i class="bi bi-calendar3"></i></button>
        </form>

        <!-- Hamburger toggler — only visible on mobile (< md = 768px) -->
        <button class="navbar-toggler ms-auto" type="button" data-bs-toggle="collapse" data-bs-target="#navbarCollapse" aria-controls="navbarCollapse" aria-expanded="false" aria-label="Toggle navigation">
          <span class="navbar-toggler-icon"></span>
        </button>

        <!-- Collapsible section: merge/delete + all action buttons + username -->
        <div class="collapse navbar-collapse" id="navbarCollapse">
          <div class="d-flex align-items-center gap-1 flex-wrap flex-md-nowrap ms-md-auto py-1 py-md-0" id="navbar-action-btns">
<?php if(isset($session_id) && !empty($session_id)){ ?>
            <button type="submit" form="formmerge" class="btn btn-sm btn-outline-primary flex-shrink-0" title="Merge session">
              <i class="bi bi-diagram-2"></i>
            </button>
            <button type="submit" form="formdelete" class="btn btn-sm btn-outline-danger flex-shrink-0" title="Delete session" id="deletebtn">
              <i class="bi bi-trash3"></i>
            </button>
<?php } ?>
<?php if ($setZoomManually === 0) { ?>
            <button id="btn-vars" class="btn btn-sm btn-outline-light" onclick="torqueToggle('vars-section', this)" title="Toggle Variables"><i class="bi bi-sliders"></i></button>
            <button id="btn-chart" class="btn btn-sm btn-outline-light<?php if ($var1 != "") echo ' active'; ?>" onclick="torqueToggle('chart-section', this)" title="Toggle Chart"><i class="bi bi-bar-chart-line"></i></button>
            <button id="btn-summary" class="btn btn-sm btn-outline-light<?php if ($var1 != "") echo ' active'; ?>" onclick="torqueToggle('summary-section', this)" title="Toggle Data Summary"><i class="bi bi-table"></i></button>
            <button id="btn-export" class="btn btn-sm btn-outline-light" onclick="torqueToggle('export-section', this)" title="Toggle Export"><i class="bi bi-download"></i></button>
<?php } ?>
<?php if ($claude_enabled): ?>
            <button id="btn-ai" class="btn btn-sm btn-outline-light" onclick="torqueToggle('ai-section', this)" title="AI Assistant"><i class="bi bi-robot"></i></button>
<?php endif; ?>
            <a href="settings.php" class="btn btn-sm btn-outline-light" title="Settings"><i class="bi bi-gear"></i></a>
            <button class="btn btn-sm btn-outline-light" id="darkModeBtn" onclick="toggleDarkMode()" title="Toggle Dark Mode"><i class="bi bi-moon-stars"></i></button>
<?php if ( !empty($_SESSION['torque_user']) ) { ?>
            <span class="navbar-text navbar-user ms-1 flex-shrink-0">
              <?php echo htmlspecialchars($_SESSION['torque_user']); ?>
              <a href="session.php?logout=true" class="ms-2" title="Logout">
                <img width="20" height="20" src="./static/logout.png" alt="Logout">
              </a>
            </span>
<?php } ?>
          </div>
        </div><!-- /navbar-collapse -->

      </div>
    </nav>

    <!-- Hidden forms for merge/delete (triggered by navbar buttons) -->
<?php if(isset($session_id) && !empty($session_id)){ ?>
    <form method="post" action="merge_sessions.php" id="formmerge" style="display:none">
      <input type="hidden" name="mergesession" value="<?php echo htmlspecialchars((string)$session_id, ENT_QUOTES, 'UTF-8'); ?>">
      <?php echo csrf_field(); ?>
    </form>
    <form method="post" action="session.php" id="formdelete" data-session-name="<?php echo htmlspecialchars($seshdates[$session_id] ?? ''); ?>" style="display:none">
      <input type="hidden" name="deletesession" value="<?php echo htmlspecialchars((string)$session_id, ENT_QUOTES, 'UTF-8'); ?>">
      <?php echo csrf_field(); ?>
    </form>
<?php } ?>

    <!-- Calendar date range picker panel -->
    <div id="cal-panel" class="torque-panel" style="display:none">
      <div class="torque-panel-header">
        <h6><i class="bi bi-calendar3 me-2"></i>Select Date Range</h6>
        <button class="torque-panel-close" onclick="window._torqueCal && _torqueCal.close()">×</button>
      </div>
      <div class="torque-panel-body p-3">
        <p id="cal-hint" class="text-muted small text-center mb-3">Click a start date, then an end date.</p>
        <div class="d-flex gap-4 justify-content-center flex-wrap">
          <div id="cal-left"></div>
          <div id="cal-right"></div>
        </div>
        <div id="cal-sessions" class="mt-3">
          <p class="text-center text-muted small mb-0">Select a date range above to see available sessions.</p>
        </div>
      </div>
    </div>

<?php if ($claude_enabled): ?>
    <!-- ── AI Chat panel ── -->
    <div class="torque-panel" id="ai-section" style="display:none">
      <div class="torque-panel-header">
        <h6><i class="bi bi-robot me-2"></i>TORQUE<span style="color:var(--hud-red)">AI</span>&nbsp;<span style="background:rgba(0,255,136,0.15);border:1px solid rgba(0,255,136,0.35);color:#00ff88;font-size:7px;padding:1px 5px;border-radius:10px;letter-spacing:1px;vertical-align:middle;">ONLINE</span></h6>
        <button class="torque-panel-close" onclick="torqueToggle('ai-section', document.getElementById('btn-ai'))">×</button>
      </div>
      <div class="torque-panel-body d-flex flex-column" style="padding:0;height:420px;">
        <div id="ai-messages" class="ai-messages flex-grow-1"></div>
        <div class="ai-suggestions" id="ai-suggestions">
          <button class="ai-suggestion-btn" onclick="aiSend('What does my current session data show?')">What does my current session show?</button>
          <button class="ai-suggestion-btn" onclick="aiSend('Is my engine running healthy based on the data?')">Is my engine healthy?</button>
          <button class="ai-suggestion-btn" onclick="aiSend('When was my car last serviced based on the fuel trim history?')">When was it last serviced?</button>
          <button class="ai-suggestion-btn" onclick="aiSend('What should I service next and why?')">What should I service next?</button>
        </div>
        <div class="ai-input-bar">
          <input type="text" id="ai-input" class="ai-input" placeholder="Ask about your car data…" autocomplete="off"
            onkeydown="if(event.key==='Enter'&&!event.shiftKey){aiSend();event.preventDefault();}">
          <button class="ai-send-btn" id="ai-send" onclick="aiSend()" title="Send">
            <i class="bi bi-send-fill"></i>
          </button>
        </div>
      </div>
    </div>
<?php endif; ?>

    <!-- Full-screen map canvas (sized by CSS) -->
    <div id="map-canvas"></div>

<?php if ($setZoomManually === 0): ?>
    <!-- ── HUD Widget — live arc gauges pinned to map ── -->
    <div id="hud-widget">
      <div class="hud-drag-handle d-flex align-items-center justify-content-between" title="Drag to move">
        <span class="hud-drag-dots">⠿</span>
        <button id="hud-collapse-btn" title="Collapse HUD"><i class="bi bi-chevron-down" id="hud-collapse-icon"></i></button>
      </div>
      <div class="hud-gauges">

        <div class="hud-gauge-wrap">
          <div class="hud-gauge-val hud-gauge-val--cyan" id="hud-gauge-rpm-val">&#x2014;</div>
          <svg width="70" height="40" viewBox="0 0 70 46" class="hud-gauge-svg">
            <path d="M 8 46 A 30 30 0 0 1 62 46" class="hud-gauge-track"/>
            <path d="M 8 46 A 30 30 0 0 1 62 46"
                  class="hud-gauge-arc hud-gauge-arc--cyan"
                  id="hud-gauge-rpm"
                  stroke-dasharray="94"
                  stroke-dashoffset="94"/>
          </svg>
          <div class="hud-gauge-label"><?php echo htmlspecialchars($hud_gauge1_label); ?></div>
        </div>

        <div class="hud-gauge-wrap">
          <div class="hud-gauge-val hud-gauge-val--red" id="hud-gauge-coolant-val">&#x2014;</div>
          <svg width="70" height="40" viewBox="0 0 70 46" class="hud-gauge-svg">
            <path d="M 8 46 A 30 30 0 0 1 62 46" class="hud-gauge-track"/>
            <path d="M 8 46 A 30 30 0 0 1 62 46"
                  class="hud-gauge-arc hud-gauge-arc--red"
                  id="hud-gauge-coolant"
                  stroke-dasharray="94"
                  stroke-dashoffset="94"/>
          </svg>
          <div class="hud-gauge-label"><?php echo htmlspecialchars($hud_gauge2_label); ?></div>
        </div>

        <div class="hud-gauge-wrap">
          <div class="hud-gauge-val hud-gauge-val--green" id="hud-gauge-speed-val">&#x2014;</div>
          <svg width="70" height="40" viewBox="0 0 70 46" class="hud-gauge-svg">
            <path d="M 8 46 A 30 30 0 0 1 62 46" class="hud-gauge-track"/>
            <path d="M 8 46 A 30 30 0 0 1 62 46"
                  class="hud-gauge-arc hud-gauge-arc--green"
                  id="hud-gauge-speed"
                  stroke-dasharray="94"
                  stroke-dashoffset="94"/>
          </svg>
          <div class="hud-gauge-label"><?php echo htmlspecialchars($hud_gauge3_label); ?></div>
        </div>

      </div>
      <div class="hud-stats">
        <div class="hud-stat">
          <div class="hud-stat-val hud-stat-val--cyan" id="hud-stat-dur">&#x2014;</div>
          <div class="hud-stat-label"><?php echo htmlspecialchars($hud_stat_dur_label); ?></div>
        </div>
        <div class="hud-stat">
          <div class="hud-stat-val" id="hud-stat-dist">&#x2014;</div>
          <div class="hud-stat-label"><?php echo htmlspecialchars($hud_stat_dist_label); ?></div>
        </div>
        <div class="hud-stat">
          <div class="hud-stat-val hud-stat-val--green" id="hud-stat-fuel">&#x2014;</div>
          <div class="hud-stat-label"><?php echo htmlspecialchars($hud_stat_fuel_label); ?></div>
        </div>
      </div>
    </div>
<?php endif; ?>

<?php if ($setZoomManually === 0) { ?>

    <!-- ── Floating panel: Variables ── -->
    <div class="torque-panel" id="vars-section" style="display:none">
      <div class="torque-panel-header">
        <h6>Select Variables</h6>
        <div class="d-flex align-items-center gap-2">
          <button type="button" class="btn btn-primary btn-sm py-0 px-3" onclick="onSubmitIt()">Plot!</button>
          <button class="torque-panel-close" onclick="torqueToggle('vars-section', document.getElementById('btn-vars'))">×</button>
        </div>
      </div>
      <div class="torque-panel-body p-3">
        <form method="post" role="form" action="url.php?makechart=y&seshid=<?php echo urlencode($session_id); ?><?php if ($filteryearmonth !== '') echo '&yearmonth='.urlencode($filteryearmonth); ?>" id="formplotdata">
          <div class="form-check form-switch mb-2 ms-1">
            <input class="form-check-input" type="checkbox" role="switch" id="filterHasData" checked>
            <label class="form-check-label small" for="filterHasData">Show only variables with data</label>
          </div>
          <select data-placeholder="Choose OBD2 data..." multiple class="form-select" id="plot_data" name="plotdata[]">
            <option value=""></option>
<?php   foreach ($coldata as $xcol) {
          $hasData = isset($xcol['has_data']) ? intval($xcol['has_data']) : -1;
?>
            <option value="<?php echo htmlspecialchars($xcol['colname']); ?>"
              data-has-data="<?php echo $hasData; ?>"
              <?php $i = 1; while ( isset($plotVar[$i]) ) { if ( ($plotVar[$i] == $xcol['colname'] ) || ( $xcol['colfavorite'] == 1 ) ) { echo " selected"; } $i = $i + 1; } ?>><?php echo htmlspecialchars($xcol['colcomment']); ?></option>
<?php   } ?>
          </select>
        </form>
      </div>
    </div>

    <!-- ── Floating panel: Chart (full-width bottom) ── -->
    <div class="torque-panel" id="chart-section"<?php if ($var1 == "") echo ' style="display:none"'; ?>>
      <div class="torque-panel-header">
        <h6>Chart</h6>
        <div class="d-flex align-items-center gap-2">
<?php if ($var1 != "") { ?>
          <button class="btn btn-sm btn-outline-secondary py-0 px-2" onclick="window.torqueChart && window.torqueChart.resetZoom()" title="Reset Zoom"><i class="bi bi-zoom-out"></i></button>
<?php } ?>
          <button class="torque-panel-close" onclick="torqueToggle('chart-section', document.getElementById('btn-chart'))">×</button>
        </div>
      </div>
      <div class="torque-panel-body">
<?php if ($var1 == "") { ?>
        <div class="alert alert-warning text-center m-3 mb-2">No Variables Selected to Plot!</div>
<?php } else { ?>
        <div class="chart-container">
          <canvas id="chartCanvas"></canvas>
        </div>
<?php } ?>
      </div>
    </div>

    <!-- ── Floating panel: Data Summary ── -->
    <div class="torque-panel" id="summary-section"<?php if ($var1 == "") echo ' style="display:none"'; ?>>
      <div class="torque-panel-header">
        <h6>Data Summary</h6>
        <button class="torque-panel-close" onclick="torqueToggle('summary-section', document.getElementById('btn-summary'))">×</button>
      </div>
      <div class="torque-panel-body">
<?php   if (($repairedCount ?? 0) > 0) { ?>
        <div class="px-3 pt-2 small" style="color:#ff9500;">
          <i class="bi bi-geo-alt-fill me-1"></i><?php echo (int)$repairedCount; ?> GPS point<?php echo $repairedCount === 1 ? '' : 's'; ?> repaired from Home Assistant
        </div>
<?php   } ?>
<?php   if ( $var1 !== "" ) { ?>
        <div class="table-responsive">
          <table class="table table-striped table-hover table-sm mb-0">
            <thead class="table-light">
              <tr>
                <th>Name</th><th>Min/Max</th><th>25th Pcnt</th><th>75th Pcnt</th><th>Mean</th><th>Sparkline</th>
              </tr>
            </thead>
            <tbody>
<?php     $i=1; while ( isset($plotVar[$i]) ) { ?>
              <tr>
                <td><strong><?php echo htmlspecialchars(substr($plotLabel[$i], 1, -1)); ?></strong></td>
                <td><?php echo htmlspecialchars($plotMin[$i].'/'.$plotMax[$i]); ?></td>
                <td><?php echo htmlspecialchars($plotPcnt25[$i]); ?></td>
                <td><?php echo htmlspecialchars($plotPcnt75[$i]); ?></td>
                <td><?php echo htmlspecialchars($plotAvg[$i]); ?></td>
                <td><span class="line"><?php echo htmlspecialchars($plotSparkData[$i]); ?></span></td>
              </tr>
<?php       $i = $i + 1; } ?>
            </tbody>
          </table>
        </div>
<?php   } else { ?>
        <div class="p-3"><div class="alert alert-warning text-center mb-0">No Variables Selected to Plot!</div></div>
<?php   } ?>
      </div>
    </div>

    <!-- ── Floating panel: Export ── -->
    <div class="torque-panel" id="export-section" style="display:none">
      <div class="torque-panel-header">
        <h6>Export Data</h6>
        <button class="torque-panel-close" onclick="torqueToggle('export-section', document.getElementById('btn-export'))">×</button>
      </div>
      <div class="torque-panel-body p-3">
        <div class="btn-group w-100 mb-2" role="group">
          <a class="btn btn-outline-secondary" href="<?php echo './export.php?sid='.urlencode($session_id).'&filetype=csv'; ?>">CSV</a>
          <a class="btn btn-outline-secondary" href="<?php echo './export.php?sid='.urlencode($session_id).'&filetype=json'; ?>">JSON</a>
        </div>
        <?php if (!empty($settings['show_render_time'])): ?>
        <div class="text-muted" style="font-size:10px;">
          Render: <?php echo htmlspecialchars($loadstart); ?><br>
          Load: <?php $loadmicroend = explode(' ', microtime()); $loadmicroend = $loadmicroend[1] + $loadmicroend[0]; echo round($loadmicroend - $loadmicrostart, 3); ?>s<br>
          Session: <?php echo htmlspecialchars($session_id); ?>
        </div>
        <?php endif; ?>
      </div>
    </div>

<?php } // end setZoomManually === 0 ?>
  </body>
</html>
