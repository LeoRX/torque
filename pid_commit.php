<?php

require_once ("./db.php");
require_once ("./auth_user.php");

// Whitelist of editable fields and allowed SQL column types
$allowed_fields = ['description', 'units', 'type', 'min', 'max', 'populated', 'favorite'];
$allowed_types  = ['double', 'float', 'varchar(255)'];

if(!empty($_POST)) {
  foreach($_POST as $field_name => $val) {
    $field_id = strip_tags(trim($field_name));
    $val = strip_tags(trim($val));

    $split_data = explode(':', $field_id);
    if (count($split_data) < 2) { echo "Invalid Request"; continue; }
    $id = $split_data[1];
    $field_name = $split_data[0];

    // Validate field_name against whitelist
    if (!in_array($field_name, $allowed_fields, true)) {
      echo "Invalid Field";
      continue;
    }
    // Validate PID id — must be alphanumeric (e.g. k5, kff1001)
    if (!preg_match('/^[a-zA-Z0-9_]+$/', $id)) {
      echo "Invalid ID";
      continue;
    }

    if (!empty($id) && !empty($field_name)) {
      if ($field_name == 'populated' || $field_name == 'favorite') {
        $val = ($val == 'true') ? 1 : 0;
      } elseif ($field_name == 'type') {
        // Whitelist SQL types used in ALTER TABLE
        if (!in_array($val, $allowed_types, true)) {
          echo "Invalid Type";
          continue;
        }
      } elseif (in_array($field_name, ['min', 'max'])) {
        // Numeric fields — strip non-numeric chars
        $val = preg_replace('/[^0-9.\-]/', '', $val);
      } else {
        // Text fields — limit length
        $val = substr($val, 0, 255);
      }

      $query = "UPDATE $db_name.$db_keys_table SET ".quote_name($field_name)." = ".quote_value($val)." WHERE id = ".quote_value($id);
      mysqli_query($con, $query);
      if($field_name == 'type') {
        $table_list = mysqli_query($con, "SELECT table_name FROM INFORMATION_SCHEMA.tables WHERE table_schema = '$db_name' AND table_name LIKE '$db_table%' ORDER BY table_name DESC");
        while($row = mysqli_fetch_assoc($table_list)) {
          $db_table_name = $row["table_name"];
          // $val already validated against $allowed_types above — safe to use directly
          $query = "ALTER TABLE $db_name.`$db_table_name` MODIFY ".quote_name($id)." $val NOT NULL DEFAULT '0'";
          mysqli_query($con, $query);
        }
      }
      echo "Updated";
    } else {
      echo "Invalid Request";
    }
  }
} else {
  echo "Invalid Request";
}

mysqli_close($con);

?>
