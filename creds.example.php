<?php
// creds.example.php — Template showing the structure of creds.php.
// Copy to creds.php and fill in real values if running WITHOUT Docker.
// When running in Docker, entrypoint.sh generates creds.php automatically from
// environment variables — you do NOT need to create creds.php manually.

// ── Database connection ────────────────────────────────────────────────────────
$db_host = '10.1.1.253';            // MariaDB host (IP or hostname)
$db_user = 'torque';                 // MySQL username
$db_pass = 'your_db_password_here'; // MySQL password
$db_port = 3306;                     // MySQL port (default 3306)
$db_name = 'torque';                 // Database name

// ── Table names (do not change unless you renamed them) ───────────────────────
$db_table          = 'raw_logs';
$db_keys_table     = 'torque_keys';
$db_sessions_table = 'sessions';

// ── Optional: Google Maps API key ─────────────────────────────────────────────
// Create a key at https://developers.google.com/maps/documentation/javascript/
// Leave empty string to disable map features.
$gmapsApiKey = '';

// ── Optional: Torque Pro device authentication ────────────────────────────────
// Enter your Torque app device ID or its MD5 hash to restrict uploads.
// Leave both empty to allow any Torque Pro device to upload data.
$torque_id      = ''; // e.g. 123456789012345
$torque_id_hash = ''; // e.g. 58b9b9268acaef64ac6a80b0543357e6

// ── Web login users (legacy — prefer torque_users DB table for new accounts) ──
$users = [];
$users[] = ['user' => 'torque', 'pass' => 'your_login_password_here'];
// Add more users as needed:
// $users[] = ['user' => 'second', 'pass' => 'anotherpassword'];
