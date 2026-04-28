<?php
require_once('db.php');
require_once('auth_user.php');
require_once('get_settings.php');

// Ensure torque_users table exists
mysqli_query($con, "CREATE TABLE IF NOT EXISTS torque_users (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  username      VARCHAR(100) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// Load existing DB users
$db_users = [];
$_uq = mysqli_query($con, "SELECT id, username FROM torque_users ORDER BY username");
while ($_ur = mysqli_fetch_assoc($_uq)) { $db_users[] = $_ur; }

// Handle credential actions
$cred_success = ''; $cred_error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (isset($_POST['add_user']) && !empty($_POST['new_username']) && !empty($_POST['new_password'])) {
    $nu = trim($_POST['new_username']); $np = $_POST['new_password'];
    if (strlen($nu) < 2)        { $cred_error = 'Username must be at least 2 characters.'; }
    elseif (strlen($np) < 6)    { $cred_error = 'Password must be at least 6 characters.'; }
    else {
      $hash = password_hash($np, PASSWORD_BCRYPT);
      $esc  = mysqli_real_escape_string($con, $nu);
      $eh   = mysqli_real_escape_string($con, $hash);
      if (mysqli_query($con, "INSERT INTO torque_users (username, password_hash) VALUES ('$esc','$eh')")) {
        $cred_success = "User '$nu' added successfully.";
      } else { $cred_error = 'Username already exists.'; }
    }
    $db_users = [];
    $_uq2 = mysqli_query($con, "SELECT id, username FROM torque_users ORDER BY username");
    while ($_ur2 = mysqli_fetch_assoc($_uq2)) { $db_users[] = $_ur2; }
  }
  if (isset($_POST['change_password']) && !empty($_POST['cp_username']) && !empty($_POST['cp_password'])) {
    $cu = trim($_POST['cp_username']); $cp = $_POST['cp_password'];
    if (strlen($cp) < 6) { $cred_error = 'New password must be at least 6 characters.'; }
    else {
      $hash = password_hash($cp, PASSWORD_BCRYPT);
      $esc  = mysqli_real_escape_string($con, $cu);
      $eh   = mysqli_real_escape_string($con, $hash);
      if (mysqli_query($con, "UPDATE torque_users SET password_hash='$eh' WHERE username='$esc'")) {
        if (mysqli_affected_rows($con) > 0) { $cred_success = "Password updated for '$cu'."; }
        else { $cred_error = "User '$cu' not found in database."; }
      }
    }
  }
  if (isset($_POST['delete_user']) && !empty($_POST['del_username'])) {
    $du  = trim($_POST['del_username']);
    $esc = mysqli_real_escape_string($con, $du);
    mysqli_query($con, "DELETE FROM torque_users WHERE username='$esc'");
    $cred_success = "User '$du' removed.";
    $db_users = [];
    $_uq3 = mysqli_query($con, "SELECT id, username FROM torque_users ORDER BY username");
    while ($_ur3 = mysqli_fetch_assoc($_uq3)) { $db_users[] = $_ur3; }
  }
}

// Handle form save
$save_success = false;
$save_error   = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_settings'])) {
  $allowed_keys = [
    'min_session_size','show_session_length','session_gap_threshold',
    'source_is_fahrenheit','use_fahrenheit','source_is_miles','use_miles',
    'hide_empty_variables','show_render_time','app_theme',
    'map_line_color','map_line_opacity','map_line_weight','mapbox_token','mapbox_style',
    'display_timezone',
    // AI Assistant
    'claude_enabled','claude_api_key','claude_model','claude_max_tokens',
    // (map_default_type and gmaps_api_key removed)
  ];
  // Boolean fields — unchecked checkboxes send nothing, so default to 0
  $boolean_keys = ['show_session_length','source_is_fahrenheit','use_fahrenheit',
                   'source_is_miles','use_miles','hide_empty_variables','show_render_time',
                   'claude_enabled'];
  foreach ($boolean_keys as $bk) {
    if (!isset($_POST[$bk])) $_POST[$bk] = '0';
  }
  foreach ($allowed_keys as $key) {
    if (array_key_exists($key, $_POST)) {
      $k = mysqli_real_escape_string($con, $key);
      $v = mysqli_real_escape_string($con, trim($_POST[$key]));
      mysqli_query($con, "UPDATE torque_settings SET setting_value='$v' WHERE setting_key='$k'");
    }
  }
  $save_success = true;
  // Reload settings after save
  $settings = [];
  $_sq2 = mysqli_query($con, "SELECT setting_key, setting_value FROM torque_settings");
  while ($_row = mysqli_fetch_assoc($_sq2)) { $settings[$_row['setting_key']] = $_row['setting_value']; }
  mysqli_free_result($_sq2);
  $app_theme = $settings['app_theme'] ?? 'default';
}

// Load full setting metadata for display
$all_settings = [];
$_msq = mysqli_query($con, "SELECT * FROM torque_settings ORDER BY setting_group, setting_key");
while ($_row = mysqli_fetch_assoc($_msq)) {
  $all_settings[$_row['setting_group']][$_row['setting_key']] = $_row;
}
mysqli_free_result($_msq);

$group_labels = [
  'sessions' => ['label' => 'Sessions',      'icon' => 'bi-collection'],
  'units'    => ['label' => 'Units',          'icon' => 'bi-speedometer'],
  'display'  => ['label' => 'Display',        'icon' => 'bi-palette'],
  'map'      => ['label' => 'Map',            'icon' => 'bi-map'],
  'ai'       => ['label' => 'AI Assistant',   'icon' => 'bi-robot'],
];

$claude_models = [
  'claude-haiku-4-5-20251001' => 'Claude Haiku 4.5 (fast, economical)',
  'claude-sonnet-4-6'         => 'Claude Sonnet 4.6 (balanced)',
  'claude-opus-4-6'           => 'Claude Opus 4.6 (most capable)',
];

$themes = [
  'default' => ['name' => 'Default',       'icon' => '🖥️',  'desc' => 'Clean & minimal',    'colors' => ['#212529','#0d6efd','#f8f9fa','#dee2e6']],
  'sports'  => ['name' => 'Sports Car',    'icon' => '🏎️',  'desc' => 'Carbon black & red',  'colors' => ['#0d0d0d','#cc0000','#1a0000','#ff4444']],
  '4wd'     => ['name' => '4WD Off-Road',  'icon' => '🚙',  'desc' => 'Olive & burnt orange', 'colors' => ['#2d3a1a','#c85a08','#e8dfc8','#d4a040']],
  'cartoon' => ['name' => 'Funky Cartoon', 'icon' => '🎨',  'desc' => 'Bold & playful',      'colors' => ['#9b27c7','#ff6b35','#ffe066','#FFD700']],
];
$mapbox_styles = [
  'mapbox://styles/mapbox/streets-v12'          => 'Streets',
  'mapbox://styles/mapbox/outdoors-v12'          => 'Outdoors',
  'mapbox://styles/mapbox/light-v11'             => 'Light',
  'mapbox://styles/mapbox/dark-v11'              => 'Dark',
  'mapbox://styles/mapbox/satellite-streets-v12' => 'Satellite + Streets',
  'mapbox://styles/mapbox/satellite-v9'          => 'Satellite Only',
  'mapbox://styles/mapbox/navigation-day-v1'     => 'Navigation Day',
  'mapbox://styles/mapbox/navigation-night-v1'   => 'Navigation Night',
];
?>
<!DOCTYPE html>
<html lang="en" data-torque-theme="<?php echo htmlspecialchars($app_theme); ?>">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Settings — Open Torque Viewer</title>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.3/css/bootstrap.min.css">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
  <link rel="stylesheet" href="static/css/torque.css">
  <link rel="stylesheet" href="static/css/themes.css">
  <link rel="stylesheet" href="https://fonts.googleapis.com/css?family=Lato:400,700">
  <style>
    /* Force page to scroll — torque.css sets html{overflow:hidden} for the map page */
    html, body { overflow-y: auto !important; height: auto !important; }

    /* Navbar=58px, save-bar≈46px → content needs padding for both */
    :root { --save-bar-height: 46px; }
    .settings-content-top { padding-top: calc(var(--navbar-height) + var(--save-bar-height) + 16px); }
    .settings-wrapper { padding-bottom: 40px; }
    .section-icon { font-size: 1.1rem; margin-right: 0.4rem; opacity: 0.8; }
    .setting-row { padding: 0.75rem 1rem; border-bottom: 1px solid rgba(0,0,0,0.06); }
    .setting-row:last-child { border-bottom: 0; }
    [data-bs-theme="dark"] .setting-row { border-color: rgba(255,255,255,0.06); }
    .setting-label { font-weight: 600; font-size: 0.9rem; margin-bottom: 0.1rem; }
    .setting-desc  { font-size: 0.78rem; color: #6c757d; }
    [data-bs-theme="dark"] .setting-desc { color: #9aa0a6; }

    /* Theme selector tiles */
    .theme-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(160px, 1fr)); gap: 1rem; padding: 1rem; }
    .theme-tile { position: relative; cursor: pointer; }
    .theme-tile input[type="radio"] { position: absolute; opacity: 0; pointer-events: none; }
    .theme-card {
      border: 2px solid rgba(0,0,0,0.12);
      border-radius: 10px;
      overflow: hidden;
      transition: all 0.2s;
      box-shadow: 0 2px 6px rgba(0,0,0,0.08);
    }
    .theme-tile input:checked + .theme-card {
      border-color: #0d6efd;
      box-shadow: 0 0 0 3px rgba(13,110,253,0.2), 0 4px 12px rgba(0,0,0,0.12);
    }
    .theme-tile input:checked + .theme-card .theme-check { display: flex; }
    .theme-swatches { display: flex; height: 50px; }
    .theme-swatch { flex: 1; }
    .theme-info { padding: 0.55rem 0.7rem; background: #fff; }
    [data-bs-theme="dark"] .theme-info { background: #2a2a3a; }
    .theme-check {
      display: none;
      position: absolute;
      top: 8px; right: 8px;
      width: 22px; height: 22px;
      border-radius: 50%;
      background: #0d6efd;
      align-items: center; justify-content: center;
      color: #fff; font-size: 0.7rem;
    }
    .theme-name { font-weight: 700; font-size: 0.85rem; }
    .theme-desc { font-size: 0.72rem; color: #6c757d; }
    [data-bs-theme="dark"] .theme-desc { color: #9aa0a6; }
    .theme-icon { font-size: 1.2rem; }

    .color-input-wrap { display: flex; align-items: center; gap: 0.6rem; }
    input[type="color"] { width: 42px; height: 36px; padding: 2px; border-radius: 6px; cursor: pointer; }
    .color-hex { font-family: monospace; width: 90px; }

    /* ── Dark mode overrides for settings-specific elements ── */
    [data-bs-theme="dark"] .card { background-color: #1e1e2e; border-color: rgba(255,255,255,0.1); }
    [data-bs-theme="dark"] .card-header { background-color: #252538; border-color: rgba(255,255,255,0.08); color: #e0e0e0; }
    [data-bs-theme="dark"] .card-footer { background-color: #1e1e2e; border-color: rgba(255,255,255,0.08); }
    [data-bs-theme="dark"] .list-group-item { background-color: #1e1e2e; border-color: rgba(255,255,255,0.08); color: #d0d0d8; }
    [data-bs-theme="dark"] .form-control,
    [data-bs-theme="dark"] .form-select {
      background-color: #2a2a3e;
      border-color: rgba(255,255,255,0.15);
      color: #e0e0e0;
    }
    [data-bs-theme="dark"] .form-control:focus,
    [data-bs-theme="dark"] .form-select:focus {
      background-color: #32324a;
      border-color: rgba(99,130,255,0.6);
      color: #fff;
      box-shadow: 0 0 0 3px rgba(99,130,255,0.15);
    }
    [data-bs-theme="dark"] .form-control::placeholder { color: rgba(255,255,255,0.35); }
    [data-bs-theme="dark"] .btn-outline-secondary { border-color: rgba(255,255,255,0.3); color: #ccc; }
    [data-bs-theme="dark"] .btn-outline-secondary:hover { background: rgba(255,255,255,0.1); color: #fff; border-color: rgba(255,255,255,0.5); }
    [data-bs-theme="dark"] .btn-outline-danger { border-color: #f87171; color: #f87171; }
    [data-bs-theme="dark"] .btn-outline-danger:hover { background: rgba(239,68,68,0.2); }
    /* Theme tiles in dark mode */
    [data-bs-theme="dark"] .theme-card { border-color: rgba(255,255,255,0.12); }
    [data-bs-theme="dark"] .theme-tile input:checked + .theme-card { border-color: #6382ff; box-shadow: 0 0 0 3px rgba(99,130,255,0.25), 0 4px 12px rgba(0,0,0,0.3); }
    /* Small text helpers */
    [data-bs-theme="dark"] small, [data-bs-theme="dark"] .text-muted { color: #9aa0b0 !important; }
    [data-bs-theme="dark"] code { background: rgba(255,255,255,0.1); color: #c8c8d8; padding: 1px 4px; border-radius: 3px; }

    .save-bar {
      position: fixed;
      top: var(--navbar-height);   /* flush against the navbar */
      left: 0; right: 0;
      z-index: 900;
      background: rgba(13,110,253,0.97);
      backdrop-filter: blur(4px);
      padding: 0.6rem 1.5rem;
      display: flex; align-items: center; justify-content: space-between;
      box-shadow: 0 2px 8px rgba(0,0,0,0.2);
    }
  </style>
  <script>
    (function(){
      var saved = localStorage.getItem('torque-theme') || 'light';
      document.documentElement.setAttribute('data-bs-theme', saved);
    })();
  </script>
</head>
<body class="settings-page">
  <nav class="navbar navbar-dark bg-dark fixed-top" style="min-height:58px;">
    <div class="container-fluid flex-nowrap gap-2">
      <a class="navbar-brand flex-shrink-0" href="session.php">Open Torque Viewer</a>
      <div class="d-flex align-items-center gap-2 ms-auto flex-shrink-0">
        <span class="navbar-text text-white-50 d-none d-sm-inline"><i class="bi bi-gear me-1"></i>Settings</span>
        <button class="btn btn-sm btn-outline-light" id="darkModeBtn" onclick="toggleDarkMode()" title="Toggle Dark Mode"><i class="bi bi-moon-stars"></i></button>
        <a href="session.php" class="btn btn-sm btn-outline-light" title="Back to sessions"><i class="bi bi-arrow-left me-1"></i>Back</a>
      </div>
    </div>
  </nav>

  <!-- Sticky save bar — sits right below the fixed navbar -->
  <div class="save-bar">
    <span class="text-white fw-semibold"><i class="bi bi-gear-fill me-2"></i>Application Settings</span>
    <div class="d-flex align-items-center gap-2">
      <a href="pid_edit.php" class="btn btn-outline-light btn-sm" title="Edit PID descriptions">
        <i class="bi bi-pencil-square me-1"></i>Edit PIDs
      </a>
      <button type="submit" name="save_settings" form="settingsForm" class="btn btn-outline-light btn-sm fw-semibold px-4">
        <i class="bi bi-floppy me-1"></i>Save Settings
      </button>
    </div>
  </div>

  <!-- Credentials section (padding-top accounts for fixed navbar + fixed save-bar) -->
  <div class="container-fluid settings-content-top settings-wrapper pb-0" style="max-width:900px;">
    <div class="card mb-4">
      <div class="card-header d-flex align-items-center">
        <i class="bi bi-shield-lock section-icon"></i>
        <h6 class="mb-0">Login Credentials</h6>
      </div>
      <div class="card-body">
        <p class="text-muted small mb-3">Manage web UI login accounts. Passwords are stored as bcrypt hashes. Accounts here take priority over credentials in <code>creds.php</code>.</p>

        <?php if ($cred_success): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert">
          <i class="bi bi-check-circle me-2"></i><?php echo htmlspecialchars($cred_success); ?>
          <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php endif; ?>
        <?php if ($cred_error): ?>
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
          <i class="bi bi-exclamation-triangle me-2"></i><?php echo htmlspecialchars($cred_error); ?>
          <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php endif; ?>

        <div class="row g-3">
          <!-- Current users -->
          <div class="col-md-4">
            <h6 class="fw-semibold mb-2"><i class="bi bi-people me-1"></i>Current Users</h6>
            <?php if (empty($db_users)): ?>
            <p class="text-muted small">No database users yet. Using <code>creds.php</code> credentials.</p>
            <?php else: ?>
            <ul class="list-group list-group-flush">
              <?php foreach ($db_users as $dbu): ?>
              <li class="list-group-item d-flex justify-content-between align-items-center px-0 py-2">
                <span><i class="bi bi-person-circle me-2 text-muted"></i><?php echo htmlspecialchars($dbu['username']); ?></span>
                <form method="post" class="mb-0" onsubmit="return confirm('Remove user <?php echo htmlspecialchars($dbu['username']); ?>?')">
                  <input type="hidden" name="del_username" value="<?php echo htmlspecialchars($dbu['username']); ?>">
                  <button type="submit" name="delete_user" class="btn btn-sm btn-outline-danger py-0 px-2" title="Remove"><i class="bi bi-trash3"></i></button>
                </form>
              </li>
              <?php endforeach; ?>
            </ul>
            <?php endif; ?>
          </div>

          <!-- Add user -->
          <div class="col-md-4">
            <h6 class="fw-semibold mb-2"><i class="bi bi-person-plus me-1"></i>Add User</h6>
            <form method="post">
              <div class="mb-2">
                <input type="text" class="form-control form-control-sm" name="new_username" placeholder="Username" required minlength="2" autocomplete="off">
              </div>
              <div class="mb-2">
                <input type="password" class="form-control form-control-sm" name="new_password" placeholder="Password (min 6 chars)" required minlength="6" autocomplete="new-password">
              </div>
              <button type="submit" name="add_user" class="btn btn-sm btn-primary w-100"><i class="bi bi-plus-circle me-1"></i>Add User</button>
            </form>
          </div>

          <!-- Change password -->
          <div class="col-md-4">
            <h6 class="fw-semibold mb-2"><i class="bi bi-key me-1"></i>Change Password</h6>
            <form method="post">
              <div class="mb-2">
                <select class="form-select form-select-sm" name="cp_username" required>
                  <option value="">— Select user —</option>
                  <?php foreach ($db_users as $dbu): ?>
                  <option value="<?php echo htmlspecialchars($dbu['username']); ?>"><?php echo htmlspecialchars($dbu['username']); ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="mb-2">
                <input type="password" class="form-control form-control-sm" name="cp_password" placeholder="New password (min 6 chars)" required minlength="6" autocomplete="new-password">
              </div>
              <button type="submit" name="change_password" class="btn btn-sm btn-outline-secondary w-100"><i class="bi bi-arrow-repeat me-1"></i>Update Password</button>
            </form>
          </div>
        </div>
      </div>
    </div>
  </div>

  <form method="post" id="settingsForm">
    <div class="container-fluid settings-wrapper" style="max-width:900px;">

      <?php if ($save_success): ?>
      <div class="alert alert-success alert-dismissible fade show mb-3" role="alert">
        <i class="bi bi-check-circle me-2"></i><strong>Settings saved successfully.</strong>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
      </div>
      <?php endif; ?>

      <?php foreach ($group_labels as $group_key => $group_meta): ?>
        <?php if (empty($all_settings[$group_key])) continue; ?>

        <div class="card mb-4">
          <div class="card-header d-flex align-items-center">
            <i class="bi <?php echo $group_meta['icon']; ?> section-icon"></i>
            <h6 class="mb-0"><?php echo $group_meta['label']; ?></h6>
          </div>

          <?php if ($group_key === 'display'): ?>
          <div class="card-body p-0">
            <?php foreach ($all_settings[$group_key] as $key => $row): ?>
              <?php if ($key === 'app_theme' || $key === 'display_timezone') continue; ?>
              <div class="setting-row d-flex align-items-center justify-content-between">
                <div>
                  <div class="setting-label"><?php echo htmlspecialchars($row['setting_label']); ?></div>
                  <div class="setting-desc"><?php echo htmlspecialchars($row['setting_description']); ?></div>
                </div>
                <div class="form-check form-switch mb-0 ms-3">
                  <input class="form-check-input" type="checkbox" role="switch"
                    name="<?php echo htmlspecialchars($key); ?>"
                    id="setting_<?php echo htmlspecialchars($key); ?>"
                    value="1"
                    <?php if (!empty($settings[$key])) echo 'checked'; ?>>
                </div>
              </div>
            <?php endforeach; ?>

            <!-- Timezone selector -->
            <?php
              $tz_regions = [
                'Australia'  => ['Australia/Melbourne','Australia/Sydney','Australia/Brisbane',
                                 'Australia/Adelaide','Australia/Perth','Australia/Darwin','Australia/Hobart'],
                'Pacific'    => ['Pacific/Auckland','Pacific/Fiji','Pacific/Honolulu','Pacific/Guam'],
                'Asia'       => ['Asia/Tokyo','Asia/Shanghai','Asia/Hong_Kong','Asia/Singapore',
                                 'Asia/Kolkata','Asia/Dubai','Asia/Karachi','Asia/Bangkok',
                                 'Asia/Jakarta','Asia/Seoul'],
                'Europe'     => ['Europe/London','Europe/Paris','Europe/Berlin','Europe/Amsterdam',
                                 'Europe/Rome','Europe/Madrid','Europe/Moscow','Europe/Istanbul'],
                'Americas'   => ['America/New_York','America/Chicago','America/Denver',
                                 'America/Los_Angeles','America/Anchorage','America/Toronto',
                                 'America/Vancouver','America/Sao_Paulo','America/Argentina/Buenos_Aires'],
                'Africa'     => ['Africa/Johannesburg','Africa/Cairo','Africa/Nairobi','Africa/Lagos'],
                'Other'      => ['UTC'],
              ];
              $cur_tz = $settings['display_timezone'] ?? 'Australia/Melbourne';
            ?>
            <div class="setting-row">
              <div class="setting-label mb-1"><i class="bi bi-clock-history me-1"></i>Display Timezone</div>
              <div class="setting-desc mb-2">Timezone used for displaying session dates and times. Uses IANA timezone identifiers.</div>
              <select class="form-select form-select-sm" style="max-width:320px;" name="display_timezone">
                <?php foreach ($tz_regions as $region => $tzlist): ?>
                <optgroup label="<?php echo $region; ?>">
                  <?php foreach ($tzlist as $tz): ?>
                  <?php
                    try {
                      $dto = new DateTime('now', new DateTimeZone($tz));
                      $offset = $dto->format('P');
                    } catch (Exception $e) { $offset = ''; }
                  ?>
                  <option value="<?php echo htmlspecialchars($tz); ?>"
                    <?php if ($cur_tz === $tz) echo 'selected'; ?>>
                    <?php echo htmlspecialchars($tz . ' (UTC' . $offset . ')'); ?>
                  </option>
                  <?php endforeach; ?>
                </optgroup>
                <?php endforeach; ?>
              </select>
            </div>
          </div>

          <!-- Theme selector -->
          <div class="card-footer p-0 border-top">
            <div class="px-3 pt-3 pb-1">
              <div class="setting-label"><i class="bi bi-palette me-1"></i>UI Theme</div>
              <div class="setting-desc">Choose a visual theme for the application and login page. Changes take effect after saving.</div>
            </div>
            <div class="theme-grid">
              <?php foreach ($themes as $theme_key => $theme): ?>
              <label class="theme-tile">
                <input type="radio" name="app_theme" value="<?php echo $theme_key; ?>"
                  <?php if (($settings['app_theme'] ?? 'default') === $theme_key) echo 'checked'; ?>>
                <div class="theme-card">
                  <div class="theme-check"><i class="bi bi-check"></i></div>
                  <div class="theme-swatches">
                    <?php foreach ($theme['colors'] as $color): ?>
                    <div class="theme-swatch" style="background:<?php echo $color; ?>"></div>
                    <?php endforeach; ?>
                  </div>
                  <div class="theme-info">
                    <div class="d-flex align-items-center gap-1">
                      <span class="theme-icon"><?php echo $theme['icon']; ?></span>
                      <span class="theme-name"><?php echo $theme['name']; ?></span>
                    </div>
                    <div class="theme-desc"><?php echo $theme['desc']; ?></div>
                  </div>
                </div>
              </label>
              <?php endforeach; ?>
            </div>
          </div>

          <?php elseif ($group_key === 'ai'): ?>
          <div class="card-body p-0">
            <!-- Enable toggle -->
            <div class="setting-row d-flex align-items-center justify-content-between">
              <div>
                <div class="setting-label">Enable AI Assistant</div>
                <div class="setting-desc">Show the <i class="bi bi-robot"></i> chat button in the main interface toolbar.</div>
              </div>
              <div class="form-check form-switch mb-0 ms-3">
                <input class="form-check-input" type="checkbox" role="switch"
                  name="claude_enabled" id="setting_claude_enabled" value="1"
                  <?php if (!empty($settings['claude_enabled']) && $settings['claude_enabled'] !== '0') echo 'checked'; ?>>
              </div>
            </div>
            <!-- API Key -->
            <div class="setting-row">
              <div class="setting-label mb-1">Claude API Key</div>
              <div class="setting-desc mb-2">Your Anthropic API key. Get one free at <a href="https://console.anthropic.com" target="_blank">console.anthropic.com</a> → API Keys.</div>
              <div class="input-group" style="max-width:500px;">
                <input type="password" class="form-control form-control-sm" id="claude_api_key_input"
                  name="claude_api_key" value="<?php echo htmlspecialchars($settings['claude_api_key'] ?? ''); ?>"
                  placeholder="sk-ant-api03-..." autocomplete="off">
                <button class="btn btn-outline-secondary btn-sm" type="button"
                  onclick="var i=document.getElementById('claude_api_key_input');i.type=i.type==='password'?'text':'password';">
                  <i class="bi bi-eye"></i>
                </button>
              </div>
            </div>
            <!-- Model -->
            <div class="setting-row">
              <div class="setting-label mb-1">Claude Model</div>
              <div class="setting-desc mb-2">Haiku is fast and cheap for Q&amp;A. Sonnet gives better analysis. Opus is most capable but slowest.</div>
              <select class="form-select form-select-sm" style="max-width:320px;" name="claude_model">
                <?php foreach ($claude_models as $m_val => $m_label): ?>
                <option value="<?php echo htmlspecialchars($m_val); ?>"
                  <?php if (($settings['claude_model'] ?? 'claude-haiku-4-5-20251001') === $m_val) echo 'selected'; ?>>
                  <?php echo htmlspecialchars($m_label); ?>
                </option>
                <?php endforeach; ?>
              </select>
            </div>
            <!-- Max tokens -->
            <div class="setting-row">
              <div class="setting-label mb-1">Max Response Tokens</div>
              <div class="setting-desc mb-2">Maximum length of AI responses (256–4096). Higher = longer answers but more API cost.</div>
              <input type="number" class="form-control form-control-sm" style="max-width:120px;"
                name="claude_max_tokens"
                value="<?php echo htmlspecialchars($settings['claude_max_tokens'] ?? '1024'); ?>"
                step="128" min="256" max="4096">
            </div>
            <!-- Test connection -->
            <div class="setting-row">
              <div class="setting-label mb-1">Test Connection</div>
              <div class="setting-desc mb-2">Send a test message to verify your API key works. Save settings first.</div>
              <button type="button" class="btn btn-outline-secondary btn-sm" id="claudeTestBtn" onclick="testClaudeConnection()">
                <i class="bi bi-send me-1"></i>Test API Key
              </button>
              <span id="claudeTestResult" class="ms-3 small"></span>
            </div>
          </div>

          <?php elseif ($group_key === 'map'): ?>
          <div class="card-body p-0">
            <?php foreach ($all_settings[$group_key] as $key => $row): ?>
            <div class="setting-row">
              <div class="setting-label mb-1"><?php echo htmlspecialchars($row['setting_label']); ?></div>
              <div class="setting-desc mb-2"><?php echo htmlspecialchars($row['setting_description']); ?></div>
              <?php $val = $settings[$key] ?? $row['setting_value']; ?>
              <?php if ($key === 'map_line_color'): ?>
                <div class="color-input-wrap">
                  <input type="color" id="color_<?php echo $key; ?>" value="<?php echo htmlspecialchars($val); ?>"
                    oninput="document.getElementById('hex_<?php echo $key; ?>').value=this.value">
                  <input type="text" class="form-control form-control-sm color-hex" id="hex_<?php echo $key; ?>"
                    name="<?php echo htmlspecialchars($key); ?>" value="<?php echo htmlspecialchars($val); ?>"
                    oninput="if(/^#[0-9A-Fa-f]{6}$/.test(this.value)) document.getElementById('color_<?php echo $key; ?>').value=this.value"
                    pattern="^#[0-9A-Fa-f]{6}$" maxlength="7">
                </div>
              <?php elseif ($key === 'mapbox_style'): ?>
                <select class="form-select form-select-sm" style="max-width:280px;"
                  name="<?php echo htmlspecialchars($key); ?>">
                  <?php foreach ($mapbox_styles as $ms_val => $ms_label): ?>
                  <option value="<?php echo htmlspecialchars($ms_val); ?>" <?php if ($val === $ms_val) echo 'selected'; ?>><?php echo $ms_label; ?></option>
                  <?php endforeach; ?>
                </select>
              <?php elseif ($key === 'mapbox_token'): ?>
                <div class="input-group" style="max-width:500px;">
                  <input type="password" class="form-control form-control-sm" id="mapbox_token_input"
                    name="mapbox_token" value="<?php echo htmlspecialchars($val); ?>"
                    placeholder="pk.eyJ1..." autocomplete="off">
                  <button class="btn btn-outline-secondary btn-sm" type="button"
                    onclick="var i=document.getElementById('mapbox_token_input');i.type=i.type==='password'?'text':'password';">
                    <i class="bi bi-eye"></i>
                  </button>
                </div>
                <div class="mt-1"><small class="text-muted">Get a free token at <a href="https://mapbox.com" target="_blank">mapbox.com</a> → Account → Access Tokens.</small></div>
              <?php elseif ($row['setting_type'] === 'float'): ?>
                <input type="number" class="form-control form-control-sm" style="max-width:120px;"
                  name="<?php echo htmlspecialchars($key); ?>" value="<?php echo htmlspecialchars($val); ?>"
                  step="0.05" min="0" max="1">
              <?php elseif ($row['setting_type'] === 'integer'): ?>
                <input type="number" class="form-control form-control-sm" style="max-width:120px;"
                  name="<?php echo htmlspecialchars($key); ?>" value="<?php echo htmlspecialchars($val); ?>"
                  step="1" min="1">
              <?php else: ?>
                <input type="text" class="form-control form-control-sm" style="max-width:400px;"
                  name="<?php echo htmlspecialchars($key); ?>" value="<?php echo htmlspecialchars($val); ?>">
              <?php endif; ?>
            </div>
            <?php endforeach; ?>
          </div>

          <?php else: ?>
          <div class="card-body p-0">
            <?php foreach ($all_settings[$group_key] as $key => $row): ?>
            <?php $val = $settings[$key] ?? $row['setting_value']; ?>
            <?php if ($row['setting_type'] === 'boolean'): ?>
              <div class="setting-row d-flex align-items-center justify-content-between">
                <div>
                  <div class="setting-label"><?php echo htmlspecialchars($row['setting_label']); ?></div>
                  <div class="setting-desc"><?php echo htmlspecialchars($row['setting_description']); ?></div>
                </div>
                <div class="form-check form-switch mb-0 ms-3">
                  <input class="form-check-input" type="checkbox" role="switch"
                    name="<?php echo htmlspecialchars($key); ?>"
                    id="setting_<?php echo htmlspecialchars($key); ?>"
                    value="1"
                    <?php if (!empty($val)) echo 'checked'; ?>>
                </div>
              </div>
            <?php else: ?>
              <div class="setting-row">
                <div class="setting-label mb-1"><?php echo htmlspecialchars($row['setting_label']); ?></div>
                <div class="setting-desc mb-2"><?php echo htmlspecialchars($row['setting_description']); ?></div>
                <input type="number" class="form-control form-control-sm" style="max-width:120px;"
                  name="<?php echo htmlspecialchars($key); ?>" value="<?php echo htmlspecialchars($val); ?>"
                  step="1" min="0">
              </div>
            <?php endif; ?>
            <?php endforeach; ?>
          </div>
          <?php endif; ?>

        </div>
      <?php endforeach; ?>

      <div class="text-center mt-2">
        <button type="submit" name="save_settings" class="btn btn-primary px-5">
          <i class="bi bi-floppy me-2"></i>Save Settings
        </button>
        <a href="session.php" class="btn btn-outline-secondary ms-2">Cancel</a>
      </div>

    </div>
  </form>

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
    // Sync dark mode button icon on load
    (function(){
      var saved = localStorage.getItem('torque-theme') || 'light';
      var btn = document.getElementById('darkModeBtn');
      if (btn) btn.innerHTML = saved === 'dark' ? '<i class="bi bi-sun"></i>' : '<i class="bi bi-moon-stars"></i>';
    })();

    // Live theme preview when clicking a tile
    document.querySelectorAll('input[name="app_theme"]').forEach(function(radio) {
      radio.addEventListener('change', function() {
        document.documentElement.setAttribute('data-torque-theme', this.value);
      });
    });

    function testClaudeConnection() {
      var btn = document.getElementById('claudeTestBtn');
      var res = document.getElementById('claudeTestResult');
      btn.disabled = true;
      res.textContent = 'Testing…';
      res.className = 'ms-3 small text-muted';
      fetch('claude_chat.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({message: 'Reply with exactly: "API key works."', history: [], session_id: ''})
      })
      .then(function(r) { return r.json(); })
      .then(function(d) {
        if (d.error) {
          res.textContent = 'Error: ' + d.error;
          res.className = 'ms-3 small text-danger';
        } else {
          res.textContent = 'Connected! Response: ' + (d.response || '').substring(0, 80);
          res.className = 'ms-3 small text-success';
        }
      })
      .catch(function(e) {
        res.textContent = 'Network error: ' + e.message;
        res.className = 'ms-3 small text-danger';
      })
      .finally(function() { btn.disabled = false; });
    }
  </script>
</body>
</html>
