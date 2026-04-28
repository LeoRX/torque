<?php

// ── Security headers (applied to all pages that include db.php) ──────────────
// Only send headers if not already sent (e.g. from a CLI context or unit test)
if (!headers_sent()) {
    header('X-Frame-Options: SAMEORIGIN');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: geolocation=(), microphone=(), camera=()');
}

// load database credentials
require_once ('creds.php');

// PHP 8.1+ changed mysqli to throw exceptions on query errors by default.
// Disable that so all existing code handles failures via return-false (original behaviour).
mysqli_report(MYSQLI_REPORT_OFF);

// Connect to Database
$con = mysqli_connect($db_host, $db_user, $db_pass,$db_name,$db_port) or die(mysqli_error($con));
mysqli_select_db($con, $db_name) or die(mysqli_error($con));

// helper function to quote a single identifier
// suitable for a single column name or table name
// the name will have quotes around it
function quote_name($name) {
  return "`" . str_replace("`", "``", $name) . "`";
}

// helper function to quote column names
// when constructing a query, give a list of column names, and
// it will return a properly-quoted string to put in the query
function quote_names($column_names) {
  $quoted_names = array();
  foreach ($column_names as $name) {
    $quoted_names[] = quote_name($name);
  }
  return implode(", ", $quoted_names);
}

// helper function to quote a single value
// suitable for a single value
// the value will have quotes around it
function quote_value($value) {
  global $con;
  return "'" . mysqli_real_escape_string($con, $value) . "'";
}

// helper function to quote multiple values
// when constructing a query, give a list of values, and
// it will return a properly-quoted string to put in the query
function quote_values($values) {
  $quoted_values = array();
  foreach ($values as $value) {
    $quoted_values[] = quote_value($value);
  }
  return implode(", ", $quoted_values);
}

?>
