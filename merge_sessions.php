<?php
//echo "<!-- Begin merge_sessions.php at ".date("H:i:s", microtime(true))." -->\r\n";
require_once("./db.php");
require_once("./auth_user.php");   // auth gate — redirects to login if not authenticated
require_once("./get_settings.php");
require_once("./get_sessions.php");

if (!isset($_SESSION)) { session_start(); }

if (isset($_POST["mergesession"])) {
    $mergesession = preg_replace('/\D/', '', $_POST['mergesession']);
}
elseif (isset($_GET["mergesession"])) {
    $mergesession = preg_replace('/\D/', '', $_GET['mergesession']);
}

$sessionids = array();

// 2016.04.11 - edit by surfrock66 - Define some variables to be used in 
//  variable management later, specifically when choosing sessions to merge
$i=1;
$mergesess1 = "";
foreach ($_GET as $key => $value) {
    if ($key != "mergesession") {
        ${'mergesess' . $i} = $key;
        array_push($sessionids, $key);
        $i = $i + 1;
    } else {
        array_push($sessionids, $value);
    }
}

//if (isset($mergesession) && !empty($mergesession) && isset($mergesessionwith) && !empty($mergesessionwith) ) {
if (isset($mergesession) && !empty($mergesession) && isset($mergesess1) && !empty($mergesess1) ) {
    // Cast all session IDs to int to prevent SQL injection
    $mergesession_int = (int)$mergesession;
    $qrystr = "SELECT MIN(timestart) as timestart, MAX(timeend) as timeend, MIN(session) as session, SUM(sessionsize) as sessionsize FROM $db_sessions_table WHERE session = $mergesession_int";
    $i=1;
    while (isset(${'mergesess' . $i}) || !empty(${'mergesess' . $i})) {
        $qrystr = $qrystr . " OR session = " . (int)${'mergesess' . $i};
        $i = $i + 1;
    }
    $mergeqry = mysqli_query($con, $qrystr) ;
    $mergerow = mysqli_fetch_assoc($mergeqry);
    $newsession = $mergerow['session'];
    $newtimestart = $mergerow['timestart'];
    $newtimeend = $mergerow['timeend'];
    $newsessionsize = $mergerow['sessionsize'];
    mysqli_free_result($mergeqry);

    $tableYear = date( "Y", $mergesession/1000 );
    $tableMonth = date( "m", $mergesession/1000 );
    $db_table_full = "{$db_table}_{$tableYear}_{$tableMonth}";

    foreach ($sessionids as $value) {
        $value_int = (int)$value; // ensure integer — no SQL injection possible
        if ($value_int == $newsession) {
            $updatequery = "UPDATE $db_sessions_table SET timestart=$newtimestart, timeend=$newtimeend, sessionsize=$newsessionsize WHERE session=$newsession";
            mysqli_query($con, $updatequery);
        } else {
            $delquery = "DELETE FROM $db_sessions_table WHERE session = $value_int";
            mysqli_query($con, $delquery);
            $updatequery = "UPDATE $db_table_full SET session=$newsession WHERE session=$value_int";
            mysqli_query($con, $updatequery);
        }
    }
    //Show merged session
    $session_id = $mergesession;
    header('Location: session.php?id=' . $mergesession);
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
      <form action="merge_sessions.php" method="get" id="formmerge">
        <input type="hidden" name="mergesession" value="<?php echo htmlspecialchars($mergesession, ENT_QUOTES, 'UTF-8'); ?>" />
        <div class="card">
          <div class="card-header d-flex justify-content-between align-items-center">
            <h6 class="mb-0">Merge Sessions</h6>
            <button type="submit" class="btn btn-primary btn-sm">Merge Selected Sessions</button>
          </div>
          <div class="card-body p-0">
            <div class="table-responsive">
              <table class="table table-striped table-hover table-sm mb-0">
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
    $sessqry = mysqli_query($con, "SELECT timestart, timeend, session, profileName, sessionsize FROM $db_sessions_table WHERE sessionsize >= $min_session_size ORDER BY session desc") ;
    $i = 0;
    while ($x = mysqli_fetch_array($sessqry)) {
?>
                  <tr>
                    <td class="text-center"><input type="checkbox" class="form-check-input" name="<?php echo (int)$x['session']; ?>" <?php if ($x['session'] == $mergesession) { echo "checked disabled"; } ?>/></td>
                    <td><?php echo tz_date("F d, Y g:ia", (int)substr($x["timestart"], 0, -3), $display_timezone ?? 'UTC'); ?></td>
                    <td><?php echo tz_date("F d, Y g:ia", (int)substr($x["timeend"], 0, -3), $display_timezone ?? 'UTC'); ?></td>
                    <td><?php echo gmdate("H:i:s", ($x["timeend"] - $x["timestart"])/1000); ?></td>
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
