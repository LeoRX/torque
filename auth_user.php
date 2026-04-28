<?php
require_once ('creds.php');
require_once ('auth_functions.php');

//session.cookie_path = "/torque/";
session_set_cookie_params(0,dirname($_SERVER['SCRIPT_NAME'])); 
if (!isset($_SESSION)) { session_start(); }

//This variable will be evaluated at the end of this file to check if a user is authenticated
$logged_in = false;

if (!isset($_SESSION['torque_logged_in'])) {
  $_SESSION['torque_logged_in'] = false;
}
$logged_in = (boolean)$_SESSION['torque_logged_in'];

//There are two ways to authenticate for Open Torque Viewer
//The uploading data provider running on Android uses its torque ID, while the User Interface uses User/Password.
//Which method will be chosed depends on the variable set before including this file
// Set "$auth_user_with_torque_id" for Authetification with ID
// Set "$auth_user_with_user_pass" for Authetification with User/Password
// Default is authentication with user/pass

if(!isset($auth_user_with_user_pass)) {
  $auth_user_with_user_pass = true;
}

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
  $auth_user_with_torque_id = false;
}

if (!$logged_in && $auth_user_with_torque_id)
{
  if ( auth_id() ) {
    $session_id = get_id();
    $logged_in = true;
  }
}

$_SESSION['torque_logged_in'] = $logged_in;

if (!$logged_in) {
  // Load theme setting for login page (requires $con from db.php, already included by caller)
  $_login_theme = 'default';
  if (isset($con)) {
    $_tq = mysqli_query($con, "SELECT setting_value FROM torque_settings WHERE setting_key='app_theme' LIMIT 1");
    if ($_tq && $_tr = mysqli_fetch_assoc($_tq)) { $_login_theme = $_tr['setting_value']; }
  }
?>
<!DOCTYPE html>
<html lang="en" data-torque-theme="<?php echo htmlspecialchars($_login_theme); ?>">
  <head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Open Torque Viewer - Login</title>
    <meta name="description" content="Open Torque Viewer">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.3/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="https://fonts.googleapis.com/css?family=Lato:400,700">
    <link rel="stylesheet" href="static/css/themes.css">
    <style>
      * { font-family: 'Lato', sans-serif; }

      body {
        min-height: 100vh;
        display: flex;
        align-items: center;
        justify-content: center;
        /* Dark automotive dashboard background */
        background-color: #0d0f14;
        background-image:
          radial-gradient(ellipse at 20% 50%, rgba(0, 80, 160, 0.18) 0%, transparent 60%),
          radial-gradient(ellipse at 80% 50%, rgba(160, 20, 20, 0.15) 0%, transparent 60%),
          /* Subtle grid lines like a dashboard */
          linear-gradient(rgba(255,255,255,0.02) 1px, transparent 1px),
          linear-gradient(90deg, rgba(255,255,255,0.02) 1px, transparent 1px);
        background-size: 100% 100%, 100% 100%, 40px 40px, 40px 40px;
      }

      /* Animated speedometer arc in background */
      .bg-arc {
        position: fixed;
        top: 50%;
        left: 50%;
        transform: translate(-50%, -50%);
        width: 700px;
        height: 700px;
        border-radius: 50%;
        border: 1px solid rgba(255,255,255,0.04);
        pointer-events: none;
      }
      .bg-arc::before {
        content: '';
        position: absolute;
        inset: 30px;
        border-radius: 50%;
        border: 1px solid rgba(255,255,255,0.03);
      }
      .bg-arc::after {
        content: '';
        position: absolute;
        inset: 60px;
        border-radius: 50%;
        border: 1px solid rgba(255,255,255,0.025);
      }

      .login-outer {
        position: relative;
        z-index: 10;
        width: 100%;
        max-width: 400px;
        padding: 1rem;
      }

      .login-logo {
        text-align: center;
        margin-bottom: 2rem;
      }
      .login-logo .icon-wrap {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        width: 72px;
        height: 72px;
        border-radius: 50%;
        background: linear-gradient(135deg, #1a3a6e 0%, #0d1f3c 100%);
        border: 2px solid rgba(66, 133, 244, 0.4);
        box-shadow: 0 0 30px rgba(66, 133, 244, 0.2);
        margin-bottom: 1rem;
      }
      .login-logo .bi {
        font-size: 2rem;
        color: #4285f4;
      }
      .login-logo h1 {
        font-size: 1.4rem;
        font-weight: 700;
        color: #e8eaed;
        letter-spacing: 0.5px;
        margin: 0;
      }
      .login-logo p {
        color: #9aa0a6;
        font-size: 0.85rem;
        margin: 0.25rem 0 0;
      }

      .login-card {
        background: rgba(26, 28, 35, 0.92);
        border: 1px solid rgba(255,255,255,0.08);
        border-radius: 12px;
        backdrop-filter: blur(10px);
        box-shadow: 0 20px 60px rgba(0,0,0,0.5), 0 0 0 1px rgba(255,255,255,0.04);
        padding: 2rem;
      }

      .form-control {
        background-color: rgba(255,255,255,0.06) !important;
        border: 1px solid rgba(255,255,255,0.12) !important;
        color: #e8eaed !important;
        border-radius: 8px;
      }
      .form-control::placeholder { color: #6c757d !important; }
      .form-control:focus {
        background-color: rgba(255,255,255,0.09) !important;
        border-color: rgba(66, 133, 244, 0.6) !important;
        box-shadow: 0 0 0 3px rgba(66, 133, 244, 0.15) !important;
        color: #e8eaed !important;
      }

      .form-label { color: #9aa0a6; font-size: 0.85rem; }

      .input-icon {
        position: relative;
      }
      .input-icon .bi {
        position: absolute;
        left: 12px;
        top: 50%;
        transform: translateY(-50%);
        color: #6c757d;
        font-size: 1rem;
        z-index: 5;
      }
      .input-icon .form-control {
        padding-left: 2.4rem;
      }

      .btn-login {
        background: linear-gradient(135deg, #1a56db 0%, #1a3a9e 100%);
        border: none;
        border-radius: 8px;
        font-weight: 600;
        letter-spacing: 0.3px;
        padding: 0.6rem;
        transition: all 0.2s;
        box-shadow: 0 4px 15px rgba(26, 86, 219, 0.3);
      }
      .btn-login:hover {
        background: linear-gradient(135deg, #2563eb 0%, #1d4ed8 100%);
        box-shadow: 0 6px 20px rgba(26, 86, 219, 0.45);
        transform: translateY(-1px);
      }

      /* Gauges decorative strip at bottom */
      .gauge-strip {
        display: flex;
        justify-content: center;
        gap: 1.5rem;
        margin-top: 1.5rem;
        opacity: 0.35;
      }
      .gauge-strip span {
        color: #9aa0a6;
        font-size: 0.7rem;
        letter-spacing: 1px;
        text-transform: uppercase;
      }
    </style>
  </head>
  <body class="login-body">
    <div class="bg-arc"></div>
    <div class="login-outer">
      <div class="login-logo">
        <div class="icon-wrap">
          <i class="bi bi-speedometer2"></i>
        </div>
        <h1>Open Torque Viewer</h1>
        <p>OBD2 Telemetry Dashboard</p>
      </div>
      <div class="login-card">
        <form method="post" action="session.php" id="formlogin">
          <div class="mb-3">
            <label class="form-label">Username</label>
            <div class="input-icon">
              <i class="bi bi-person"></i>
              <input type="text" class="form-control" name="user" value="" placeholder="Enter username" autocomplete="username" required>
            </div>
          </div>
          <div class="mb-4">
            <label class="form-label">Password</label>
            <div class="input-icon">
              <i class="bi bi-lock"></i>
              <input type="password" class="form-control" name="pass" value="" placeholder="Enter password" autocomplete="current-password" required>
            </div>
          </div>
          <button type="submit" class="btn btn-login btn-primary w-100" name="Login" value="Login">
            <i class="bi bi-box-arrow-in-right me-2"></i>Sign In
          </button>
        </form>
        <div class="gauge-strip">
          <span><i class="bi bi-speedometer2"></i> RPM</span>
          <span><i class="bi bi-thermometer-half"></i> TEMP</span>
          <span><i class="bi bi-lightning-charge"></i> VOLT</span>
          <span><i class="bi bi-geo-alt"></i> GPS</span>
        </div>
      </div>
    </div>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.3/js/bootstrap.bundle.min.js"></script>
    <script>
      (function(){
        var saved = localStorage.getItem('torque-theme') || 'light';
        document.documentElement.setAttribute('data-bs-theme', saved);
      })();
    </script>
  </body>
</html>
<?php
  exit(0);
} else {
  //Prepare session
  //Connect to Sql, ...
}
?>
