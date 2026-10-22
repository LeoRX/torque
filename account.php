<?php
// account.php — "My account": lets ANY signed-in user (admin or viewer) change
// their own password. Settings (and resetting other users' passwords) stays
// admin-only in settings.php.
require_once('./db.php');
require_once('./auth_user.php');
require_once('./csrf.php');

$_me      = (string)($_SESSION['torque_user'] ?? '');
$_role    = current_user_role();
$msg_ok   = '';
$msg_err  = '';

// Only accounts in torque_users have a password this page can change;
// creds.php users are managed through the container environment.
$_q = mysqli_query($con, "SELECT username FROM torque_users WHERE username=" . quote_value($_me) . " LIMIT 1");
$_is_db_user = ($_q && mysqli_fetch_assoc($_q));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $cur = (string)($_POST['current_password'] ?? '');
    $new = (string)($_POST['new_password'] ?? '');
    $rep = (string)($_POST['confirm_password'] ?? '');
    if (!$_is_db_user) {
        $msg_err = 'This account is defined in the container environment (APP_USER/APP_PASS); change it there.';
    } elseif (!_auth_user_check($_me, $cur)) {
        $msg_err = 'Your current password is incorrect.';
    } elseif (strlen($new) < 10) {
        $msg_err = 'New password must be at least 10 characters.';
    } elseif (!hash_equals($new, $rep)) {
        $msg_err = 'The new passwords do not match.';
    } else {
        $hash = password_hash($new, PASSWORD_BCRYPT);
        mysqli_query($con, "UPDATE torque_users SET password_hash=" . quote_value($hash) . " WHERE username=" . quote_value($_me));
        $msg_ok = 'Password changed.';
    }
}
mysqli_close($con);
?>
<!DOCTYPE html>
<html lang="en">
  <head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Open Torque Viewer – My Account</title>
    <script src="<?php echo htmlspecialchars(asset_url('static/js/theme-init.js')); ?>"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.3/css/bootstrap.min.css" integrity="sha512-jnSuA4Ss2PkkikSOLtYs8BlYIeeIK1h99ty4YfvRPAlzr377vr3CXDb7sb7eEEBYjDtcYj+AjBH3FLv5uSJuXg==" crossorigin="anonymous">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" integrity="sha384-XGjxtQfXaH2tnPFa9x+ruJTuLE3Aa6LhHSWRr1XeTyhezb4abCG4ccI5AkVDxqC+" crossorigin="anonymous">
    <style>
      html, body { overflow: auto !important; height: auto !important; }
      body { padding-top: 70px; }
    </style>
  </head>
  <body>
    <nav class="navbar navbar-dark bg-dark fixed-top">
      <div class="container-fluid gap-2">
        <a class="navbar-brand" href="session.php"><i class="bi bi-speedometer2 me-2"></i>Open Torque Viewer</a>
        <a href="session.php" class="btn btn-sm btn-outline-light ms-auto"><i class="bi bi-arrow-left me-1"></i>Back</a>
      </div>
    </nav>
    <div class="container" style="max-width:480px;">
      <div class="card">
        <div class="card-header d-flex align-items-center gap-2">
          <i class="bi bi-person-circle"></i>
          <h6 class="mb-0">My account</h6>
        </div>
        <div class="card-body">
          <p class="mb-3">
            Signed in as <strong><?php echo htmlspecialchars($_me); ?></strong>
            <span class="badge <?php echo $_role === 'admin' ? 'text-bg-primary' : 'text-bg-secondary'; ?> ms-1"><?php echo htmlspecialchars($_role); ?></span>
          </p>
          <?php if ($msg_ok): ?><div class="alert alert-success py-2"><?php echo htmlspecialchars($msg_ok); ?></div><?php endif; ?>
          <?php if ($msg_err): ?><div class="alert alert-danger py-2"><?php echo htmlspecialchars($msg_err); ?></div><?php endif; ?>
          <?php if ($_is_db_user): ?>
          <form method="post">
            <?php echo csrf_field(); ?>
            <div class="mb-2">
              <label class="form-label small" for="current_password">Current password</label>
              <input type="password" class="form-control form-control-sm" id="current_password" name="current_password" required autocomplete="current-password">
            </div>
            <div class="mb-2">
              <label class="form-label small" for="new_password">New password (min 10 characters)</label>
              <input type="password" class="form-control form-control-sm" id="new_password" name="new_password" required minlength="10" autocomplete="new-password">
            </div>
            <div class="mb-3">
              <label class="form-label small" for="confirm_password">Repeat new password</label>
              <input type="password" class="form-control form-control-sm" id="confirm_password" name="confirm_password" required minlength="10" autocomplete="new-password">
            </div>
            <button type="submit" class="btn btn-primary btn-sm w-100"><i class="bi bi-key me-1"></i>Change password</button>
          </form>
          <?php else: ?>
          <p class="text-muted small mb-0">This account is defined in the container environment (<code>APP_USER</code>/<code>APP_PASS</code>), so its password is changed there rather than here.</p>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </body>
</html>
