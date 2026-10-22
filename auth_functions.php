<?php

//Get Username from Browser-Request
function get_user()
{
    return $_POST['user'] ?? '';
}


//Get Password from Browser-Request
function get_pass()
{
    return $_POST['pass'] ?? '';
}


//Get Torque-ID from Browser-Request
//The Torque app sends md5(device_id): exactly 32 hex chars. Anything else is rejected.
function get_id()
{
    $raw = $_POST['id'] ?? $_GET['id'] ?? '';
    if (is_string($raw) && 1 === preg_match('/^[\da-f]{32}$/i', trim($raw))) {
        return strtolower(trim($raw));
    }
    return "";
}


// ── Login throttling ─────────────────────────────────────────────────────────
// Failed web logins are counted per client IP (REMOTE_ADDR — real client via
// mod_remoteip behind Traefik). After LOGIN_MAX_FAILURES failures inside
// LOGIN_WINDOW_SECONDS, further attempts from that IP are refused until the
// window slides. Table is created lazily so no migration is required.
const LOGIN_MAX_FAILURES   = 10;
const LOGIN_WINDOW_SECONDS = 900;

function _login_attempts_table($con): bool
{
    return (bool)mysqli_query($con, "CREATE TABLE IF NOT EXISTS torque_login_attempts (
        id         INT AUTO_INCREMENT PRIMARY KEY,
        ip         VARCHAR(45) NOT NULL,
        attempt_ts INT NOT NULL,
        KEY idx_ip_ts (ip, attempt_ts)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

function login_is_throttled($con, string $ip): bool
{
    if (!$con || !_login_attempts_table($con)) { return false; }
    $since = time() - LOGIN_WINDOW_SECONDS;
    $q = mysqli_query($con, "SELECT COUNT(*) AS n FROM torque_login_attempts WHERE ip=" . quote_value($ip) . " AND attempt_ts > " . (int)$since);
    $row = $q ? mysqli_fetch_assoc($q) : null;
    return $row && (int)$row['n'] >= LOGIN_MAX_FAILURES;
}

function login_record_failure($con, string $ip): void
{
    if (!$con || !_login_attempts_table($con)) { return; }
    mysqli_query($con, "INSERT INTO torque_login_attempts (ip, attempt_ts) VALUES (" . quote_value($ip) . ", " . time() . ")");
    // Opportunistic cleanup of expired rows
    if (random_int(1, 20) === 1) {
        mysqli_query($con, "DELETE FROM torque_login_attempts WHERE attempt_ts < " . (int)(time() - LOGIN_WINDOW_SECONDS));
    }
}

function login_clear_failures($con, string $ip): void
{
    if (!$con || !_login_attempts_table($con)) { return; }
    mysqli_query($con, "DELETE FROM torque_login_attempts WHERE ip=" . quote_value($ip));
}


//True if User/Pass match DB users (torque_users table) or creds.php $users array.
//Sets global $login_throttled = true when the client IP is temporarily locked out.
function auth_user()
{
    global $users, $con, $login_throttled;
    $login_throttled = false;

    $user = get_user();
    $pass = get_pass();

    // Nothing submitted (normal unauthenticated page view) — not a login attempt
    if ($user === '' && $pass === '') {
        return false;
    }

    $ip  = $_SERVER['REMOTE_ADDR'] ?? '';
    $db  = isset($con) ? $con : null;
    if (login_is_throttled($db, $ip)) {
        $login_throttled = true;
        return false;
    }

    if (_auth_user_check($user, $pass)) {
        login_clear_failures($db, $ip);
        $_SESSION['torque_role'] = user_role_lookup((string)$_SESSION['torque_user']) ?? 'viewer';
        return true;
    }
    login_record_failure($db, $ip);
    return false;
}

function _auth_user_check(string $user, string $pass): bool
{
    global $users, $con;

    // Bcrypt (cost 12, PHP 8.4's default) hash of a random string: verified against when the username is
    // unknown so that response time doesn't reveal which usernames exist.
    static $dummy_hash = '$2y$12$5JeMoM5Z9lfvwFEWD78QNu7lfXE/pZQvpM4ItwKnnoxCQyX/VCz9K';

    // 1. Check DB users table first (bcrypt hashed passwords)
    if (isset($con)) {
        $tbl_check = mysqli_query($con, "SHOW TABLES LIKE 'torque_users'");
        if ($tbl_check && mysqli_num_rows($tbl_check) > 0) {
            $qry = mysqli_query($con, "SELECT username, password_hash FROM torque_users WHERE username=" . quote_value($user) . " LIMIT 1");
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

    // Equalise timing with the DB path above for unknown usernames
    password_verify($pass, $dummy_hash);

    // 2. Fallback: plain-text $users from creds.php
    if ( !isset($users) || empty($users) ) {
        return false; // no credentials defined → deny (fail closed)
    }
    foreach ($users as $key => $value) {
        // hash_equals on both fields: timing-safe comparison
        if (hash_equals((string)$users[$key]['user'], $user) && hash_equals((string)$users[$key]['pass'], (string)$pass)) {
            session_regenerate_id(true); // prevent session fixation
            $_SESSION['torque_user'] = $users[$key]['user'];
            return true;
        }
    }

    return false;
}


//True is Torque-ID matches any of the IDs or HASHes defined in creds.php
//If both IDs and HASHes are empty, uploads are refused unless $allow_open_upload_auth is set.
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

        $torque_id_hash = array_map('md5', $torque_id);
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
        // Strict, timing-safe comparison. A loose in_array() treats numeric-looking
        // "magic hashes" (e.g. "0e123...") as equal to each other.
        if ($session_id !== '') {
            foreach ($torque_id_hash as $known) {
                if (is_string($known) && hash_equals(strtolower(trim($known)), $session_id)) {
                    return true;
                }
            }
        }
    }
    // No IDs/HASHes defined. Fail closed by default; set $allow_open_upload_auth = true
    // in creds.php to re-enable unrestricted uploads (e.g. dev/LAN-only setups without
    // bearer token or device ID restrictions). Production should use bearer token instead.
    else
    {
        global $allow_open_upload_auth;
        return !empty($allow_open_upload_auth);
    }
    return false;
}

// ── Roles ────────────────────────────────────────────────────────────────────
// 'admin'  — everything, including Settings, user management and the web DB upgrade.
// 'viewer' — everything else: browse, export, AI chat, delete/merge sessions,
//            edit PIDs, run GPS repair, change own password (account.php).
// DB users carry a `role` column (migration 29; missing column → admin so an
// un-migrated install keeps working). creds.php users are always admin.

// Role of $user, or null if that account no longer exists anywhere.
function user_role_lookup(string $user): ?string
{
    global $users, $con;
    if ($user === '') {
        return null;
    }
    if (isset($con) && $con) {
        // SELECT * so this still works before migration 29 adds `role`
        $q = mysqli_query($con, "SELECT * FROM torque_users WHERE username=" . quote_value($user) . " LIMIT 1");
        if ($q && ($row = mysqli_fetch_assoc($q))) {
            return (($row['role'] ?? 'admin') === 'viewer') ? 'viewer' : 'admin';
        }
    }
    foreach (($users ?? []) as $u) {
        if (hash_equals((string)($u['user'] ?? ''), $user)) {
            return 'admin';
        }
    }
    return null;
}

// Role of the signed-in user ('admin' | 'viewer'); '' when not signed in.
function current_user_role(): string
{
    return (string)($_SESSION['torque_role'] ?? '');
}

function is_admin(): bool
{
    return current_user_role() === 'admin';
}

// Stop the request with 403 unless the signed-in user is an admin.
function require_admin(): void
{
    if (is_admin()) {
        return;
    }
    http_response_code(403);
    $wants_json = stripos($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json') !== false
               || stripos($_SERVER['CONTENT_TYPE'] ?? '', 'application/json') !== false;
    if ($wants_json) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['error' => 'Administrator access required.']);
    } else {
        header('Content-Type: text/html; charset=utf-8');
        echo '<!DOCTYPE html><meta charset="utf-8"><title>Access denied</title>'
           . '<div style="font-family:sans-serif;margin:3em auto;max-width:32em">'
           . '<h2>Administrator access required</h2>'
           . '<p>Your account can view and manage drive data but not change settings or users.</p>'
           . '<p><a href="session.php">Back to sessions</a> · <a href="account.php">My account</a></p></div>';
    }
    exit;
}

// Number of admin accounts (DB admins + creds.php users).
function admin_count(): int
{
    global $users, $con;
    $n = count($users ?? []);
    if (isset($con) && $con) {
        $q = mysqli_query($con, "SELECT COUNT(*) AS n FROM torque_users WHERE role='admin'");
        if ($q && ($row = mysqli_fetch_assoc($q))) {
            $n += (int)$row['n'];
        } else {
            // Pre-migration: no role column → every DB user is an admin
            $q = mysqli_query($con, "SELECT COUNT(*) AS n FROM torque_users");
            if ($q && ($row = mysqli_fetch_assoc($q))) { $n += (int)$row['n']; }
        }
    }
    return $n;
}

function logout_user()
{
    session_destroy();
    header("Location: ./session.php");
    die();
}

?>
