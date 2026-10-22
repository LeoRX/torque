<?php
// upload_limits.php — guards that stop uploads from growing the schema without bound.
// Pure functions (no DB, no side effects) so tests/test_upload_limits.php can load them.
//
// Each upload can create a monthly raw_logs_YYYY_MM table (from the session timestamp)
// and add a column per unseen k-code. Without limits a client could create tables for
// absurd years or add columns until MariaDB's column/row-size limits break all inserts.

// Max k* columns per raw_logs table; new k-codes beyond this are dropped (logged).
// Override with env TORQUE_MAX_KCOLUMNS.
function torque_max_kcolumns(): int {
    $env = (int)getenv('TORQUE_MAX_KCOLUMNS');
    return $env > 0 ? $env : 300;
}

// Torque PIDs are "k" + 1–8 hex digits: kc, k5c, k2182, kff1005, kff1226 …
function torque_is_valid_kcode(string $key): bool {
    return 1 === preg_match('/^k[0-9a-f]{1,8}$/i', $key);
}

// Session IDs are ms epoch timestamps. Accept 2008-01-01 (before Torque Pro existed)
// up to 2 days in the future (clock skew). Anything else would route to a nonsense
// raw_logs_YYYY_MM table.
function torque_session_ts_ok(string $session_ms, ?int $now = null): bool {
    if (!preg_match('/^\d{10,15}$/', $session_ms)) {
        return false;
    }
    $sec = intdiv((int)$session_ms, 1000);
    $now = $now ?? time();
    return $sec >= 1199145600 && $sec <= $now + 2 * 86400;
}

// Number of k* columns in a list of column names (SHOW COLUMNS output).
function torque_count_kcolumns(array $columns): int {
    $n = 0;
    foreach ($columns as $c) {
        if (torque_is_valid_kcode((string)$c)) { $n++; }
    }
    return $n;
}
