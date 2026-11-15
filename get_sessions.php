<?php
//echo "<!-- Begin get_sessions.php at ".date("H:i:s", microtime(true))." -->\r\n";
// this page relies on being included from another page that has already connected to db

if (!isset($_SESSION)) { 
	session_set_cookie_params(0,dirname($_SERVER['SCRIPT_NAME']));
	session_start(); 
}

// Process the possibilities for the year and month filter: Set in POST, Set in GET, select all possible year/months, or the default: select the current year/month
if ( isset($_POST["selyearmonth"]) ) {
	if (is_array($_POST["selyearmonth"])) {
		// Multi-select array from Tom Select
		$ymParts = array_filter(array_map(function($v){ return preg_replace('/[^0-9_]/', '', trim($v)); }, $_POST["selyearmonth"]));
		$filteryearmonth = implode(',', $ymParts);
	} else {
		$filteryearmonth = preg_replace('/[^0-9_,]/', '', trim($_POST["selyearmonth"]));
	}
} elseif ( isset($_GET["yearmonth"])) {
	$filteryearmonth = preg_replace('/[^0-9_,]/', '', trim($_GET["yearmonth"]));
} else {
	$filteryearmonth = tz_date('Y_m', time(), $display_timezone ?? 'UTC');
}

// Process the 4 possibilities for the profile filter: Set in POST, Set in GET, select all possible profiles, or no filter as default
if ( isset($_POST["selprofile"]) ) {
	$filterprofile = mb_substr(trim($_POST["selprofile"]), 0, 255);
} elseif ( isset($_GET["profile"])) {
	$filterprofile = mb_substr(trim($_GET["profile"]), 0, 255);
} else {
	$filterprofile = "%";
}
if ( $filterprofile == "ALL" || $filterprofile === '' ) {
	$filterprofile = "%";
}


// Build the MySQL select string based on the inputs (year_month or session id)
$sessionqrystring = "SELECT timestart, timeend, session, profileName, sessionsize FROM " . quote_name($db_sessions_table) . " ";

// Build year/month filter (supports comma-separated list for multi-select)
$ymValues = array_filter(array_map('trim', explode(',', $filteryearmonth)));
if (count($ymValues) > 1) {
	$ymEscaped = array_map(function($v) { return quote_value($v); }, $ymValues);
	$sqlqryyearmonth = "CONCAT(YEAR(FROM_UNIXTIME(session/1000)), '_', DATE_FORMAT(FROM_UNIXTIME(session/1000),'%m')) IN (" . implode(',', $ymEscaped) . ") ";
} elseif (count($ymValues) === 1) {
	$sqlqryyearmonth = "CONCAT(YEAR(FROM_UNIXTIME(session/1000)), '_', DATE_FORMAT(FROM_UNIXTIME(session/1000),'%m')) LIKE " . quote_value(reset($ymValues)) . " ";
} else {
	$sqlqryyearmonth = "";
}

$sqlqryprofile = "profileName LIKE " . quote_value($filterprofile) . " " ;

// The session the user explicitly opened (?id=), normalised once for every
// consumer (session.php, get_columns.php, plot.php): digits only, '' when absent.
$requested_sid = (isset($_GET['id']) && is_string($_GET['id'])) ? ltrim(preg_replace('/\D/', '', $_GET['id']), '0') : '';

// min_session_size is applied in SQL (not afterwards in PHP) so the "last 20"
// fallback below still fires when every session in the filter is too small.
$sqlqrysize = "sessionsize >= " . (int)$min_session_size;
$filters = array();
if ( $filteryearmonth !== "" && $filteryearmonth !== "%" && $sqlqryyearmonth !== "" ) {
	$filters[] = $sqlqryyearmonth;
}
if ( $filterprofile <> "%" ) {
	$filters[] = $sqlqryprofile;
}
$filters[] = $sqlqrysize;
$sessionqrystring .= "WHERE ( " . implode(" AND ", $filters) . " )";
// A session opened explicitly is always listed, whatever its size or the filters.
if ( $requested_sid !== '' ) {
	$sessionqrystring .= " OR ( session = " . quote_value($requested_sid) . " )";
}
$sessionqrystring = $sessionqrystring . " GROUP BY session, profileName, timestart, timeend, sessionsize ORDER BY session DESC";
// Get list of unique session IDs
$sessionqry = mysqli_query($con, $sessionqrystring);

// If you get no results (or query failed), just pull the last 20
if (!$sessionqry || mysqli_num_rows( $sessionqry ) == 0 ) {
	$sessionqry = mysqli_query($con, "SELECT timestart, timeend, session, profileName, sessionsize FROM " . quote_name($db_sessions_table) . " WHERE " . $sqlqrysize . " GROUP BY session, profileName, timestart, timeend, sessionsize ORDER BY session DESC LIMIT 20");
	if (!$sessionqry) {
		error_log('get_sessions: session list query failed: ' . mysqli_error($con));
	}
}

// Create an array mapping session IDs to date strings
// (always initialised: callers such as plot.php use them even when no session qualifies)
$sids = array();
$seshdates = array();
$seshsizes = array();
$seshprofile = array();
while ($sessionqry && ($row = mysqli_fetch_assoc($sessionqry))) {
    $session_duration_str = gmdate("H:i:s", (int)(((int)$row["timeend"] - (int)$row["timestart"])/1000));
    $session_profileName = $row["profileName"];
    $sid = $row["session"];
    $sids[] = preg_replace('/\D/', '', $sid);
    $seshdates[$sid] = tz_date("F d, Y  g:ia", (int)substr($sid, 0, -3), $display_timezone ?? 'UTC');
    $seshsizes[$sid] = " (Length $session_duration_str)";
    $seshprofile[$sid] = " ($session_profileName Profile)";
}

if ($sessionqry) { mysqli_free_result($sessionqry); }
//echo "<!-- End get_sessions.php at ".date("H:i:s", microtime(true))." -->\r\n";

?>
