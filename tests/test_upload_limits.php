#!/usr/bin/env php
<?php
// Standalone unit tests for upload_limits.php (schema-growth guards).
// Run: php tests/test_upload_limits.php
// Exit 0 = all passed, Exit 1 = failures.

require_once __DIR__ . '/../upload_limits.php';

$pass = 0; $fail = 0;

function ok(string $name, bool $result): void {
    global $pass, $fail;
    if ($result) { echo "PASS: $name\n"; $pass++; }
    else         { echo "FAIL: $name\n"; $fail++; }
}

// ── torque_is_valid_kcode ────────────────────────────────────────────────────
foreach (['kc', 'kd', 'k5c', 'k2182', 'kff1005', 'kff1226', 'KFF1006', 'k12345678'] as $k) {
    ok("kcode valid: $k", torque_is_valid_kcode($k));
}
foreach (['k', 'kz', 'kZZ_bad', 'k123456789', 'kff1005;', 'k ff', 'profileName', 'notice', ''] as $k) {
    ok("kcode invalid: '$k'", !torque_is_valid_kcode($k));
}

// ── torque_session_ts_ok ─────────────────────────────────────────────────────
$now = 1761000000; // 2025-10-20
ok('session: normal ms timestamp',         torque_session_ts_ok('1760990000000', $now));
ok('session: 2008-01-01 boundary',         torque_session_ts_ok('1199145600000', $now));
ok('session: before 2008 rejected',        !torque_session_ts_ok('1199145599000', $now));
ok('session: +1 day (clock skew) ok',      torque_session_ts_ok((string)(($now + 86400) * 1000), $now));
ok('session: +3 days rejected',            !torque_session_ts_ok((string)(($now + 3 * 86400) * 1000), $now));
ok('session: year 33658 rejected',         !torque_session_ts_ok('999999999999999', $now));
ok('session: non-numeric rejected',        !torque_session_ts_ok('17610000000x0', $now));
ok('session: too short rejected',          !torque_session_ts_ok('123456789', $now));

// ── torque_count_kcolumns / torque_max_kcolumns ─────────────────────────────
ok('count: only k-codes counted',
    torque_count_kcolumns(['v', 'session', 'id', 'time', 'eml', 'kc', 'kd', 'kff1005', 'profileName']) === 3);
ok('max: default 300', torque_max_kcolumns() === 300);
putenv('TORQUE_MAX_KCOLUMNS=50');
ok('max: env override', torque_max_kcolumns() === 50);
putenv('TORQUE_MAX_KCOLUMNS=0');
ok('max: invalid env falls back to default', torque_max_kcolumns() === 300);
putenv('TORQUE_MAX_KCOLUMNS');

echo "\n$pass passed, $fail failed\n";
exit($fail > 0 ? 1 : 0);
