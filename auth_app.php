<?php
require_once ('creds.php');
require_once ('auth_functions.php');

//This variable will be evaluated at the end of this file to check if a user is authenticated
$logged_in = false;

// Bearer token gate — runs before all other auth if $bearer_token is set in creds.php
if (!empty($bearer_token ?? '')) {
    $auth_header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if ($auth_header === '' && function_exists('apache_request_headers')) {
        $hdrs = apache_request_headers();
        $auth_header = $hdrs['Authorization'] ?? $hdrs['authorization'] ?? '';
    }
    $token_ok = preg_match('/^Bearer\s+(.+)$/i', $auth_header, $m)
                && hash_equals($bearer_token, trim($m[1]));
    if ($token_ok) {
        $logged_in = true;
    } else {
        // Debug log — records what PHP actually received so header-stripping issues can be diagnosed
        $log_line = date('Y-m-d H:i:s')
            . "\tSERVER_AUTH="   . (isset($_SERVER['HTTP_AUTHORIZATION']) ? 'present' : 'missing')
            . "\tAPACHE_AUTH="   . ($auth_header !== '' ? 'present' : 'missing')
            . "\tHEADER_VALUE="  . ($auth_header !== '' ? 'present' : '(empty)')
            . "\tIP="            . ($_SERVER['REMOTE_ADDR'] ?? '')
            . "\n";
        @file_put_contents(__DIR__ . '/data/auth_debug.log', $log_line, FILE_APPEND | LOCK_EX);
        http_response_code(401);
        header('WWW-Authenticate: Bearer realm="Torque Upload"');
        echo 'ERROR. Bearer token authentication required.';
        exit(0);
    }
}


//Session makes no sense for the torque app, I assume it to have no cookie handling integrated
//session_set_cookie_params(0,dirname($_SERVER['SCRIPT_NAME']));
//session_start();

//if (!isset($_SESSION['torque_logged_in'])) {
//    $_SESSION['torque_logged_in'] = false;
//}
//$logged_in = (boolean)$_SESSION['torque_logged_in'];

//There are two ways to authenticate for Open Torque Viewer
//The uploading data provider running on Android transfers its torque ID, while the User Interface uses User/Password.
//Which method will be chosen depends on the variable set before including this file
// Set "$auth_user_with_torque_id" for Authetification with ID
// Set "$auth_user_with_user_pass" for Authetification with User/Password

// Default is authentication for App is the ID

if(!isset($auth_user_with_user_pass)) {
    $auth_user_with_user_pass = false;
}

// Note: auth_user() reads credentials from $_POST only (GET was removed as a security fix
// — credentials in GET params appear in server logs and browser history). If you use
// $auth_user_with_user_pass for app uploads, use Bearer token auth instead (set
// $bearer_token in creds.php and configure the plugin to send Authorization: Bearer <token>).
if (!$logged_in && $auth_user_with_user_pass)
{
    if ( auth_user() ) {
        $logged_in = true;
    }
}

//ATTENTION:
//The Torque App has no way to provide other authentication information than its torque ID.
//So, if no restriction of Torque IDs was defined in "creds.php", access to the file "upload_data.php" is always possible.

if(!isset($auth_user_with_torque_id)) {
    $auth_user_with_torque_id = true;
}

if (!$logged_in && $auth_user_with_torque_id)
{
    if ( auth_id() )
    {
        $logged_in = true;
    }
}



if (!$logged_in) {
    http_response_code(401);
    $txt  = "ERROR. Please authenticate with ";
    $txt .= ($auth_user_with_user_pass?"User/Password":"");
    $txt .= ( ($auth_user_with_user_pass && $auth_user_with_torque_id)?" or ":"");
    $txt .= ($auth_user_with_torque_id?"Torque-ID":"");
    echo $txt;
    exit(0);
}

?>