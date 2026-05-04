<?php
//echo "<!-- Begin get_columns.php at ".date("H:i:s", microtime(true))." -->\r\n";
// this page relies on being included from another page that has already connected to db

// Create array of column name/comments for chart data selector form
// 2015.08.21 - edit by surfrock66 - Rather than pull from the column comments,
//   oull from a new database created which manages variables. Include
//   a column flagging whether a variable is populated or not.
$colqry = mysqli_query($con, "SELECT id,description,type,favorite FROM " . quote_name($db_keys_table) . " WHERE populated = 1 ORDER BY description");
while ($x = mysqli_fetch_array($colqry)) {
  if ((substr($x[0], 0, 1) == "k") && ($x[2] == "float")) {
    $coldata[] = array("colname"=>$x[0], "colcomment"=>$x[1], "colfavorite"=>$x[3]);
  }
}

$numcols = strval(count($coldata)+1);
mysqli_free_result($colqry);

//TODO: Do this once in a dedicated file
if (isset($_POST["id"])) {
  $session_id = preg_replace('/\D/', '', $_POST['id']);
}
elseif (isset($_GET["id"])) {
  $session_id = preg_replace('/\D/', '', $_GET['id']);
}

$coldataempty = array();

// Check which columns have non-zero data for the selected session
if (!empty($coldata) && isset($session_id) && !empty($session_id)) {
  $tableYear  = date("Y", intdiv((int)$session_id, 1000));
  $tableMonth = date("m", intdiv((int)$session_id, 1000));
  $db_table_check = "{$db_table}_{$tableYear}_{$tableMonth}";
  // Build a single MAX() query across all tracked columns
  $max_selects = array();
  foreach ($coldata as $cd) {
    $max_selects[] = "MAX(" . quote_name($cd['colname']) . ") AS " . quote_name($cd['colname']);
  }
  // Use try/catch: PHP 8.1+ throws mysqli_sql_exception if a column doesn't exist in older tables
  try {
    $maxqry = mysqli_query($con, "SELECT " . implode(", ", $max_selects) .
      " FROM " . quote_name($db_table_check) . " WHERE session = " . quote_value(strval($session_id)));
    if ($maxqry) {
      $maxrow = mysqli_fetch_assoc($maxqry);
      mysqli_free_result($maxqry);
      for ($ci = 0; $ci < count($coldata); $ci++) {
        $mv = $maxrow[$coldata[$ci]['colname']] ?? null;
        $coldata[$ci]['has_data'] = (!is_null($mv) && floatval($mv) != 0.0) ? 1 : 0;
      }
    } else {
      for ($ci = 0; $ci < count($coldata); $ci++) {
        $coldata[$ci]['has_data'] = -1;
      }
    }
  } catch (Exception $e) {
    // Table doesn't exist or a column is missing — mark all as unknown
    for ($ci = 0; $ci < count($coldata); $ci++) {
      $coldata[$ci]['has_data'] = -1;
    }
  }
} else {
  // No session selected — mark all as unknown
  for ($ci = 0; $ci < count($coldata); $ci++) {
    $coldata[$ci]['has_data'] = -1;
  }
}
//echo "<!-- End get_columns.php at ".date("H:i:s", microtime(true))." -->\r\n";
?>
