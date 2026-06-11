#!/usr/bin/env php
<?php
// Standalone unit tests for authentication logic.
// Run: php tests/test_auth.php
// Exit 0 = all passed, Exit 1 = failures.

$pass = 0; $fail = 0;

function ok(string $name, bool $result): void {
    global $pass, $fail;
    if ($result) { echo "PASS: $name\n"; $pass++; }
    else         { echo "FAIL: $name\n"; $fail++; }
}

// ── Bearer token regex ───────────────────────────────────────────────────────
// Mirrors the preg_match in auth_app.php

function bearer_matches(string $header, string $token): bool {
    return (bool)(preg_match('/^Bearer\s+(.+)$/i', $header, $m)
        && hash_equals($token, trim($m[1])));
}

$tok = 'secrettoken123';
ok('exact match',                bearer_matches("Bearer $tok", $tok));
ok('case-insensitive keyword',   bearer_matches("bearer $tok", $tok));
ok('extra spaces trimmed',       bearer_matches("Bearer  $tok", "  $tok") === false); // trim only trims m[1] tail
ok('wrong token rejected',       !bearer_matches("Bearer wrongtoken", $tok));
ok('missing Bearer prefix',      !bearer_matches($tok, $tok));
ok('empty header',               !bearer_matches('', $tok));
ok('Basic scheme rejected',      !bearer_matches("Basic dXNlcjpwYXNz", $tok));
ok('extra trailing space',       bearer_matches("Bearer $tok ", $tok)); // trim($m[1])

// ── auth_id() with stubs ─────────────────────────────────────────────────────
// Inline stub — mirrors auth_functions.php::auth_id() logic without DB/creds
//
// Torque ID semantics:
//   - The app sends   id = md5(raw_device_id)  — a 32-char hex string
//   - creds.php sets  $torque_id = raw_device_id  (plain, auth_id() hashes it)
//           OR        $torque_id_hash = md5(raw_device_id)  (pre-hashed)
// So $session_id (what the app sends) must equal md5($torque_id) to match.

function stub_auth_id(string $session_id, $torque_id, $torque_id_hash, bool $allow_open): bool {
    $auth_by_hash_possible = false;
    if (isset($torque_id) && !empty($torque_id)) {
        if (!is_array($torque_id)) $torque_id = [$torque_id];
        $torque_id_hash = array_map('md5', $torque_id);
        $auth_by_hash_possible = true;
    } elseif (isset($torque_id_hash) && !empty($torque_id_hash)) {
        if (!is_array($torque_id_hash)) $torque_id_hash = [$torque_id_hash];
        $auth_by_hash_possible = true;
    }
    if ($auth_by_hash_possible) {
        return in_array($session_id, $torque_id_hash);
    }
    return $allow_open;
}

// Raw device IDs as stored in creds.php $torque_id.
// The app sends md5() of these values.
$raw_id_a  = 'raw_device_id_alpha';
$raw_id_b  = 'raw_device_id_beta';
$hash_id_a = md5($raw_id_a);  // what Torque app actually sends as 'id'
$hash_id_b = md5($raw_id_b);

ok('known id accepted (plain)',       stub_auth_id($hash_id_a, $raw_id_a, null, false));
ok('known id via pre-hash',           stub_auth_id($hash_id_a, null, $hash_id_a, false));
ok('unknown id rejected',             !stub_auth_id('deadbeef' . str_repeat('0', 24), $raw_id_a, null, false));
ok('open auth enabled allows',        stub_auth_id($hash_id_a, '', null, true));
ok('open auth disabled denies',       !stub_auth_id($hash_id_a, '', null, false));
ok('empty id/hash denies when closed',!stub_auth_id($hash_id_a, '', '', false));
ok('multi-id array: first matches',   stub_auth_id($hash_id_a, [$raw_id_a, $raw_id_b], null, false));
ok('multi-id array: second matches',  stub_auth_id($hash_id_b, [$raw_id_a, $raw_id_b], null, false));
ok('multi-id array: unknown rejected',!stub_auth_id('not_a_real_id', [$raw_id_a, $raw_id_b], null, false));

// ── auth_id() real function ──────────────────────────────────────────────────
// Exercises auth_functions.php::auth_id() directly. The stub above quotes
// 'md5' correctly, which masked the unquoted-bareword `array_map(md5, ...)`
// bug in the real code (fatal "Undefined constant" on PHP 8+).

require_once __DIR__ . '/../auth_functions.php';

// Returns true/false from the real auth_id(), or null if it threw.
function real_auth_id(string $post_id, $id, $id_hash, bool $allow_open): ?bool {
    global $torque_id, $torque_id_hash, $allow_open_upload_auth;
    $_POST['id'] = $post_id;
    $torque_id = $id;
    $torque_id_hash = $id_hash;
    $allow_open_upload_auth = $allow_open;
    try {
        return auth_id();
    } catch (Throwable $e) {
        echo "  ERROR in auth_id(): " . $e->getMessage() . "\n";
        return null;
    }
}

$unknown_id = 'deadbeef' . str_repeat('0', 24); // valid 32-hex shape, not a known hash

ok('real: known id accepted (plain)', real_auth_id($hash_id_a, $raw_id_a, null, false) === true);
ok('real: known id via pre-hash',     real_auth_id($hash_id_a, null, $hash_id_a, false) === true);
ok('real: unknown id rejected',       real_auth_id($unknown_id, $raw_id_a, null, false) === false);
ok('real: multi-id second matches',   real_auth_id($hash_id_b, [$raw_id_a, $raw_id_b], null, false) === true);
ok('real: open auth enabled allows',  real_auth_id($hash_id_a, '', null, true) === true);
ok('real: open auth disabled denies', real_auth_id($hash_id_a, '', null, false) === false);

// ── auth_app.php flow simulation ─────────────────────────────────────────────
// Simulates the $logged_in state machine for the four cases that matter.

function sim_auth(bool $bearer_configured, bool $token_ok, bool $torque_id_configured): bool {
    $logged_in = false;
    if ($bearer_configured) {
        if ($token_ok) {
            $logged_in = true;
        } else {
            // would 401 + exit in real code
            return false;
        }
    }
    if (!$logged_in && $torque_id_configured) {
        $logged_in = true; // simplified: assume id matches
    }
    return $logged_in;
}

ok('bearer ok → logged in (no torque id needed)',     sim_auth(true,  true,  false));
ok('bearer wrong → not logged in',                    !sim_auth(true,  false, false));
ok('bearer wrong → not logged in even with torque id', !sim_auth(true,  false, true));
ok('no bearer + torque id ok → logged in',            sim_auth(false, false, true));
ok('no bearer + no torque id → not logged in',        !sim_auth(false, false, false));

// ── Summary ──────────────────────────────────────────────────────────────────
echo "\n$pass passed, $fail failed\n";
exit($fail > 0 ? 1 : 0);
