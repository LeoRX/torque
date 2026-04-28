<?php

//Get Username from Browser-Request
function get_user()
{
    if (isset($_POST["user"])) {
        $user = $_POST['user'];
    }
    elseif (isset($_GET["user"])) {
        $user = $_GET['user'];
    }
	else
    {
        $user = "";
    }

    return $user;
}


//Get Password from Browser-Request
function get_pass()
{
    if (isset($_POST["pass"])) {
        $pass = $_POST['pass'];
    }
    elseif (isset($_GET["pass"])) {
        $pass = $_GET['pass'];
    }
	else
    {
        $pass = "";
    }

    return $pass;
}


//Get Torque-ID from Browser-Request
function get_id()
{
    $id = "";

    if (isset($_POST["id"])) {
        if (1 === preg_match('/[\da-f]{32}/i', $_POST['id'], $matches))
        {
            $id = $matches[0];
        }
    }
    elseif (isset($_GET["id"])) {
        if (1 === preg_match('/[\da-f]{32}/i', $_GET['id'], $matches))
        {
            $id = $matches[0];
        }
    }
    
    return $id;
}


//True if User/Pass match DB users (torque_users table) or creds.php $users array.
function auth_user()
{
    global $users, $con;

    $user = get_user();
    $pass = get_pass();

    // 1. Check DB users table first (bcrypt hashed passwords)
    if (isset($con)) {
        $tbl_check = mysqli_query($con, "SHOW TABLES LIKE 'torque_users'");
        if ($tbl_check && mysqli_num_rows($tbl_check) > 0) {
            $u = mysqli_real_escape_string($con, $user);
            $qry = mysqli_query($con, "SELECT username, password_hash FROM torque_users WHERE username='$u' LIMIT 1");
            if ($qry && $row = mysqli_fetch_assoc($qry)) {
                if (password_verify($pass, $row['password_hash'])) {
                    session_regenerate_id(true); // prevent session fixation
                    $_SESSION['torque_user'] = $row['username'];
                    return true;
                }
                // Username found in DB but wrong password — don't fall through
                return false;
            }
            // Username not in DB — fall through to creds.php check
        }
    }

    // 2. Fallback: plain-text $users from creds.php
    if ( !isset($users) || empty($users) ) {
        return true; // no credentials defined → open access
    }
    foreach ($users as $key => $value) {
        if ($user == $users[$key]['user'] && $pass == $users[$key]['pass']) {
            session_regenerate_id(true); // prevent session fixation
            $_SESSION['torque_user'] = $users[$key]['user'];
            return true;
        }
    }

    return false;
}


//True is Torque-ID matches any of the IDs or HASHes defined in creds.php
//If both IDs and HASHes are empty, all IDs are accepted.
function auth_id()
{
    global $torque_id, $torque_id_hash;
    // Prepare authentification of Torque Instance that uploads data to this server
    // If $torque_id is defined, this will overwrite $torque_id_hash from creds.php

    $session_id = get_id();

    // Parse IDs from "creds.php", if IDs are defined these will overrule HASHES
    $auth_by_hash_possible = false;
    if (isset($torque_id) && !empty($torque_id))
    {
        if (!is_array($torque_id))
            $torque_id = array($torque_id);

        $torque_id_hash = array_map(md5,$torque_id);
        $auth_by_hash_possible = true;
    }
    // Parse HASHES
    elseif (isset($torque_id_hash) && !empty($torque_id_hash))
    {
        if (!is_array($torque_id_hash))
            $torque_id_hash = array($torque_id_hash);
        $auth_by_hash_possible = true;
    }

    // Authenticate torque instance: Check if we know its HASH
    if ($auth_by_hash_possible)
    {
        if (in_array($session_id, $torque_id_hash) )
        {
            return true;
        }
    }
    //No IDs/HASHEs defined: Allow everything
    else
    {
        return true;
    }
    return false;
}

function logout_user()
{
    session_destroy();
    header("Location: ./session.php");
    die();
}

?>
