<?php
// url.php — redirect builder; no DB access needed
if (!headers_sent()) {
    header('X-Frame-Options: SAMEORIGIN');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: strict-origin-when-cross-origin');
}

session_set_cookie_params(0, dirname($_SERVER['SCRIPT_NAME']));
if (!isset($_SESSION)) { session_start(); }

// Session ID — digits only
if (isset($_GET["seshid"])) {
    $seshid = preg_replace('/\D/', '', $_GET["seshid"]);
} elseif (isset($_POST["seshidtag"])) {
    $seshid = preg_replace('/\D/', '', $_POST["seshidtag"]);
} elseif (isset($_GET["id"])) {
    $seshid = preg_replace('/\D/', '', $_GET["id"]);
} else {
    $seshid = isset($_SESSION['recent_session_id']) ? preg_replace('/\D/', '', $_SESSION['recent_session_id']) : '';
}

// Build redirect URL
$outurl = "session.php?id=" . urlencode($seshid);

// Profile filter — length-limit only; passed through urlencode, not into DB here
if (isset($_POST["selprofile"]) && $_POST["selprofile"] && $_POST["selprofile"] !== 'ALL') {
    $outurl = $outurl . "&profile=" . urlencode(mb_substr(trim($_POST["selprofile"]), 0, 255));
} elseif (isset($_GET["profile"]) && $_GET["profile"] && $_GET["profile"] !== 'ALL') {
    $outurl = $outurl . "&profile=" . urlencode(mb_substr(trim($_GET["profile"]), 0, 255));
}

// Year/month filter (may be array from multi-select)
if (isset($_POST["selyearmonth"])) {
    if (is_array($_POST["selyearmonth"])) {
        $ymParts = array_filter(array_map(function($v){ return preg_replace('/[^0-9_]/', '', trim($v)); }, $_POST["selyearmonth"]));
        $ym = implode(',', $ymParts);
    } else {
        $ym = preg_replace('/[^0-9_,]/', '', trim($_POST["selyearmonth"]));
    }
    if ($ym) { $outurl = $outurl . "&yearmonth=" . urlencode($ym); }
} elseif (isset($_GET["yearmonth"]) && $_GET["yearmonth"]) {
    $outurl = $outurl . "&yearmonth=" . urlencode(preg_replace('/[^0-9_,]/', '', $_GET["yearmonth"]));
}

// Plot variable keys — must match Torque key pattern (k followed by hex/alphanumeric)
if (isset($_GET["makechart"])) {
    if (isset($_POST["plotdata"])) {
        $plotdataarray = $_POST["plotdata"];
        $i = 1;
        while (isset($plotdataarray[$i-1]) && $plotdataarray[$i-1] <> "Plot!") {
            $key = $plotdataarray[$i-1];
            // Validate: Torque keys are 'k' followed by hex chars (e.g. k5, kc, kff1005)
            if (preg_match('/^k[a-fA-F0-9]+$/', $key)) {
                $outurl = $outurl . "&s$i=" . urlencode($key);
            }
            $i = $i + 1;
        }
    }
}

header("Location: " . $outurl);
