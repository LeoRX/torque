<?php
//echo "<!-- Begin merge_sessions.php at ".date("H:i:s", microtime(true))." -->\r\n";
require_once("./db.php");
require_once("./auth_user.php");   // auth gate — redirects to login if not authenticated
require_once("./get_settings.php");
require_once("./get_sessions.php");
require_once('./csrf.php');
if ($_SERVER['REQUEST_METHOD'] === 'POST') { csrf_verify(); }

if (isset($_POST["mergesession"])) {
    $mergesession = preg_replace('/\D/', '', $_POST['mergesession']);
}

$sessionids = array();

// 2016.04.11 - edit by surfrock66 - Define some variables to be used in 
//  variable management later, specifically when choosing sessions to merge
$i=1;
$mergesess1 = "";
foreach ($_POST as $key => $value) {
    if ($key === 'mergesession' || $key === 'csrf_token') continue;
    if (!preg_match('/^\d{10,15}$/', $key)) continue;
    ${'mergesess' . $i} = $key;
    array_push($sessionids, $key);
    $i = $i + 1;
}
if (isset($mergesession)) {
    array_push($sessionids, $mergesession);
}

//if (isset($mergesession) && !empty($mergesession) && isset($mergesessionwith) && !empty($mergesessionwith) ) {
if (isset($mergesession) && !empty($mergesession) && isset($mergesess1) && !empty($mergesess1) ) {
    $mergesession_int = (int)$mergesession;
    $qrystr = "SELECT MIN(timestart) AS timestart, MAX(timeend) AS timeend, MIN(session) AS session, SUM(sessionsize) AS sessionsize"
        . " FROM " . quote_name($db_sessions_table)
        . " WHERE session = " . quote_value($mergesession_int);
    $i = 1;
    while (isset(${'mergesess' . $i}) && !empty(${'mergesess' . $i})) {
        $qrystr .= " OR session = " . quote_value((int)${'mergesess' . $i});
        $i++;
    }
    $mergeqry = mysqli_query($con, $qrystr);
    if (!$mergeqry) {
        error_log('merge_sessions: aggregate query failed: ' . mysqli_error($con));
        header('Location: session.php?id=' . $mergesession);
        exit;
    }
    $mergerow = mysqli_fetch_assoc($mergeqry);
    $newsession    = $mergerow['session'];
    $newtimestart  = $mergerow['timestart'];
    $newtimeend    = $mergerow['timeend'];
    $newsessionsize = $mergerow['sessionsize'];
    mysqli_free_result($mergeqry);

    foreach ($sessionids as $value) {
        $value_int = (int)$value;
        if ($value_int == $newsession) {
            $r = mysqli_query($con,
                "UPDATE " . quote_name($db_sessions_table)
                . " SET timestart = " . quote_value($newtimestart)
                . ", timeend = "      . quote_value($newtimeend)
                . ", sessionsize = "  . quote_value($newsessionsize)
                . " WHERE session = " . quote_value($newsession));
            if (!$r) {
                error_log('merge_sessions: sessions UPDATE failed for ' . $newsession . ': ' . mysqli_error($con));
            }
        } else {
            // Compute the per-session table from this session's own timestamp
            $val_year  = date('Y', intdiv($value_int, 1000));
            $val_month = date('m', intdiv($value_int, 1000));
            $val_table = "{$db_table}_{$val_year}_{$val_month}";

            $r1 = mysqli_query($con,
                "DELETE FROM " . quote_name($db_sessions_table)
                . " WHERE session = " . quote_value($value_int));
            $r2 = mysqli_query($con,
                "UPDATE " . quote_name($val_table)
                . " SET session = " . quote_value($newsession)
                . " WHERE session = " . quote_value($value_int));
            if (!$r1) {
                error_log('merge_sessions: DELETE from sessions failed for session ' . $value_int . ': ' . mysqli_error($con));
            }
            if (!$r2) {
                error_log('merge_sessions: raw_logs UPDATE failed for session ' . $value_int . ': ' . mysqli_error($con));
            }
        }
    }
    header('Location: session.php?id=' . $mergesession);
    exit;
} elseif (isset($mergesession) && !empty($mergesession)) {
?>
<!DOCTYPE html>
<html lang="en" data-torque-theme="<?php echo htmlspecialchars($app_theme); ?>">
  <head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Open Torque Viewer - Merge Sessions</title>
    <meta name="description" content="Open Torque Viewer">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.3/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="static/css/torque.css">
    <link rel="stylesheet" href="static/css/themes.css">
    <link rel="stylesheet" href="https://fonts.googleapis.com/css?family=Lato">
    <script>(function(){ var s=localStorage.getItem('torque-theme')||'light'; document.documentElement.setAttribute('data-bs-theme',s); })();</script>
    <style>
      @media (max-width: 767px) {
        .merge-table th:nth-child(5), .merge-table td:nth-child(5) { display: none; } /* hide datapoints */
        .merge-table td { font-size: 13px; white-space: nowrap; }
      }
      @media (max-width: 480px) {
        .merge-table th:nth-child(3), .merge-table td:nth-child(3) { display: none; } /* hide end time */
        .merge-table td { font-size: 12px; }
      }
    </style>
  </head>
  <body>
    <nav class="navbar navbar-dark bg-dark fixed-top" style="min-height:58px;">
      <div class="container-fluid">
        <a class="navbar-brand" href="session.php">Open Torque Viewer</a>
        <div class="d-flex gap-2">
          <button class="btn btn-sm btn-outline-light" id="darkModeBtn" onclick="toggleDarkMode()" title="Toggle Dark Mode"><i class="bi bi-moon-stars"></i></button>
          <a href="session.php" class="btn btn-sm btn-outline-light"><i class="bi bi-arrow-left me-1"></i>Back to Sessions</a>
        </div>
      </div>
    </nav>
    <div class="container-fluid pid-editor-wrapper">
      <form action="merge_sessions.php" method="post" id="formmerge">
        <input type="hidden" name="mergesession" value="<?php echo htmlspecialchars($mergesession, ENT_QUOTES, 'UTF-8'); ?>" />
        <?php echo csrf_field(); ?>
        <div class="card">
          <div class="card-header d-flex justify-content-between align-items-center">
            <h6 class="mb-0">Merge Sessions</h6>
            <button type="submit" class="btn btn-primary btn-sm">Merge Selected Sessions</button>
          </div>
          <div class="card-body p-0">
            <div class="table-responsive">
              <table class="table table-striped table-hover table-sm mb-0 merge-table">
                <thead class="table-light">
                  <tr>
                    <th>Merge?</th>
                    <th>Start Time</th>
                    <th>End Time</th>
                    <th>Session Duration</th>
                    <th>Number of Datapoints</th>
                    <th>Profile</th>
                  </tr>
                </thead>
                <tbody>
<?php
    $sessqry = mysqli_query($con, "SELECT timestart, timeend, session, profileName, sessionsize FROM " . quote_name($db_sessions_table) . " WHERE sessionsize >= " . quote_value((int)$min_session_size) . " ORDER BY session DESC");
    $i = 0;
    while ($sessqry && $x = mysqli_fetch_array($sessqry)) {
?>
                  <tr>
                    <td class="text-center"><input type="checkbox" class="form-check-input" name="<?php echo (int)$x['session']; ?>" <?php if ($x['session'] == $mergesession) { echo "checked disabled"; } ?>/></td>
                    <td><?php echo tz_date("F d, Y g:ia", (int)substr($x["timestart"], 0, -3), $display_timezone ?? 'UTC'); ?></td>
                    <td><?php echo tz_date("F d, Y g:ia", (int)substr($x["timeend"], 0, -3), $display_timezone ?? 'UTC'); ?></td>
                    <td><?php echo gmdate("H:i:s", (int)round(($x["timeend"] - $x["timestart"])/1000)); ?></td>
                    <td><?php echo (int)$x["sessionsize"]; ?></td>
                    <td><?php echo htmlspecialchars($x["profileName"] ?? '', ENT_QUOTES, 'UTF-8'); ?></td>
                  </tr>
<?php
    }
?>
                </tbody>
              </table>
            </div>
          </div>
        </div>
      </form>
    </div>
    <script>
      document.getElementById('formmerge').addEventListener('submit', function(e) {
        if (!confirm("Click OK to merge the selected session(s) with session <?php echo (int)$mergesession; ?>.\nPlease make sure what you're trying to do makes sense, this cannot be easily undone!")) {
          e.preventDefault();
        }
      });
    </script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.3/js/bootstrap.bundle.min.js"></script>
    <script>
      function toggleDarkMode() {
        var html = document.documentElement;
        var isDark = html.getAttribute('data-bs-theme') === 'dark';
        html.setAttribute('data-bs-theme', isDark ? 'light' : 'dark');
        var btn = document.getElementById('darkModeBtn');
        btn.innerHTML = isDark ? '<i class="bi bi-moon-stars"></i>' : '<i class="bi bi-sun"></i>';
        localStorage.setItem('torque-theme', isDark ? 'light' : 'dark');
      }
      (function(){ var s=localStorage.getItem('torque-theme')||'light'; var btn=document.getElementById('darkModeBtn'); if(btn) btn.innerHTML=s==='dark'?'<i class="bi bi-sun"></i>':'<i class="bi bi-moon-stars"></i>'; })();
    </script>
  </body>
</html>
<?php
    mysqli_free_result($sessqry);
}
mysqli_close($con);
//echo "<!-- End merge_sessions.php at ".date("H:i:s", microtime(true))." -->\r\n";
?>
