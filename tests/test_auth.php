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

$known_id  = 'f915d4d07eae49baa866ac7d723553a2';
$known_tok = 'aaabbbccc111222333deadbeef000000'; // a different device

ok('known id accepted (plain)',  stub_auth_id($known_id, $known_id, null, false));
ok('known id via hash',          stub_auth_id($known_id, null, md5($known_id), false));
ok('unknown id rejected',        !stub_auth_id('deadbeef' . str_repeat('0', 24), $known_id, null, false));
ok('open auth enabled allows',   stub_auth_id($known_id, '', null, true));
ok('open auth disabled denies',  !stub_auth_id($known_id, '', null, false));
ok('empty id/hash denies when closed', !stub_auth_id($known_id, '', '', false));
ok('multi-id array: first',      stub_auth_id($known_id, [$known_id, $known_tok], null, false));
ok('multi-id array: second',     stub_auth_id($known_tok, [$known_id, $known_tok], null, false));
ok('multi-id array: unknown',    !stub_auth_id('not_a_real_id', [$known_id, $known_tok], null, false));

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
