#!/usr/bin/env php
<?php
// Standalone unit tests — no framework needed.
// Run: php tests/test_gps.php
// Exit 0 = all passed, Exit 1 = failures.

require_once __DIR__ . '/../gps/GpsFunctions.php';

$pass = 0; $fail = 0;

function ok(string $name, bool $result): void {
    global $pass, $fail;
    if ($result) { echo "PASS: $name\n"; $pass++; }
    else         { echo "FAIL: $name\n"; $fail++; }
}

// ── is_valid_point ──────────────────────────────────────────────────────────
ok('valid Melbourne',           GpsFunctions::is_valid_point(-37.888, 145.339));
ok('zero lat/lon invalid',     !GpsFunctions::is_valid_point(0, 0));
ok('null lat invalid',         !GpsFunctions::is_valid_point(null, 145.0));
ok('null lon invalid',         !GpsFunctions::is_valid_point(-37.0, null));
ok('both null invalid',        !GpsFunctions::is_valid_point(null, null));
ok('lat 91 invalid',           !GpsFunctions::is_valid_point(91.0, 0.0));
ok('lat -91 invalid',          !GpsFunctions::is_valid_point(-91.0, 0.0));
ok('lon 181 invalid',          !GpsFunctions::is_valid_point(0.0, 181.0));
ok('lon -181 invalid',         !GpsFunctions::is_valid_point(0.0, -181.0));
ok('lat 90 boundary valid',     GpsFunctions::is_valid_point(90.0, 1.0));
ok('lat -90 boundary valid',    GpsFunctions::is_valid_point(-90.0, 1.0));
ok('lon 180 boundary valid',    GpsFunctions::is_valid_point(1.0, 180.0));
ok('lon -180 boundary valid',   GpsFunctions::is_valid_point(1.0, -180.0));
ok('string zero invalid',      !GpsFunctions::is_valid_point('0', '0'));
ok('string coords valid',       GpsFunctions::is_valid_point('-37.8', '145.3'));
ok('positive lat valid',        GpsFunctions::is_valid_point(51.5, -0.1));

// ── haversine_m ──────────────────────────────────────────────────────────────
ok('same point = 0m',   GpsFunctions::haversine_m(-37.888, 145.339, -37.888, 145.339) < 0.01);
$d = GpsFunctions::haversine_m(-37.888, 145.339, -37.889, 145.339);
ok('~111m per 0.001° lat', $d > 100 && $d < 130);
$d = GpsFunctions::haversine_m(-37.888, 145.339, -37.888, 145.340);
ok('~89m per 0.001° lon at -38°', $d > 70 && $d < 110);

// ── find_stale_windows ───────────────────────────────────────────────────────

// Stationary (speed=0): never stale regardless of frozen GPS
$rows = [];
for ($i = 0; $i < 10; $i++) {
    $rows[] = ['time_ms' => $i * 2000, 'lat' => -37.888, 'lon' => 145.339, 'speed_kmh' => 0.0];
}
ok('stationary not stale',
    count(GpsFunctions::find_stale_windows($rows, 60, 10, 10)) === 0);

// Low speed (below threshold): not stale
$rows = [];
for ($i = 0; $i < 10; $i++) {
    $rows[] = ['time_ms' => $i * 2000, 'lat' => -37.888, 'lon' => 145.339, 'speed_kmh' => 5.0];
}
ok('below speed threshold not stale',
    count(GpsFunctions::find_stale_windows($rows, 60, 10, 10)) === 0);

// Moving OBD + GPS frozen: stale
$rows = [];
for ($i = 0; $i < 20; $i++) {
    $rows[] = ['time_ms' => $i * 2000, 'lat' => -37.888, 'lon' => 145.339, 'speed_kmh' => 50.0];
}
ok('frozen GPS while moving = stale',
    count(GpsFunctions::find_stale_windows($rows, 60, 10, 10)) > 0);

// Moving OBD + GPS moving normally: not stale
$rows = [];
for ($i = 0; $i < 20; $i++) {
    $rows[] = ['time_ms' => $i * 2000, 'lat' => -37.888 + $i * 0.001, 'lon' => 145.339, 'speed_kmh' => 50.0];
}
ok('moving GPS not stale',
    count(GpsFunctions::find_stale_windows($rows, 60, 10, 10)) === 0);

// Moving OBD + GPS frozen for first 30s, then moves: only first window stale
$rows = [];
for ($i = 0; $i < 30; $i++) {
    $lat = ($i < 15) ? -37.888 : -37.888 + ($i - 15) * 0.002;
    $rows[] = ['time_ms' => $i * 2000, 'lat' => $lat, 'lon' => 145.339, 'speed_kmh' => 60.0];
}
$stale = GpsFunctions::find_stale_windows($rows, 60, 10, 10);
ok('partial freeze: some rows stale, not all', count($stale) > 0 && count($stale) < 30);

// Null speed rows: treated as unknown, should not flag stale alone
$rows = [];
for ($i = 0; $i < 10; $i++) {
    $rows[] = ['time_ms' => $i * 2000, 'lat' => -37.888, 'lon' => 145.339, 'speed_kmh' => null];
}
ok('null speed not stale',
    count(GpsFunctions::find_stale_windows($rows, 60, 10, 10)) === 0);

// Empty rows: no stale results
ok('empty rows not stale',
    count(GpsFunctions::find_stale_windows([], 60, 10, 10)) === 0);

// Single row: can't form a window
$rows = [['time_ms' => 1000, 'lat' => -37.888, 'lon' => 145.339, 'speed_kmh' => 50.0]];
ok('single row not stale',
    count(GpsFunctions::find_stale_windows($rows, 60, 10, 10)) === 0);

// ── results ──────────────────────────────────────────────────────────────────
echo "\n--- GpsFunctions: $pass passed, $fail failed ---\n";
exit($fail > 0 ? 1 : 0);
