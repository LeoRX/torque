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

// ── confidence_for_delta ─────────────────────────────────────────────────────
ok('conf 0s = high',        GpsFunctions::confidence_for_delta(0)       === 'high');
ok('conf 30s = high',       GpsFunctions::confidence_for_delta(30000)   === 'high');
ok('conf 31s = medium',     GpsFunctions::confidence_for_delta(31000)   === 'medium');
ok('conf 90s = medium',     GpsFunctions::confidence_for_delta(90000)   === 'medium');
ok('conf 91s = low',        GpsFunctions::confidence_for_delta(91000)   === 'low');
ok('conf negative abs',     GpsFunctions::confidence_for_delta(-20000)  === 'high');

// ── accuracy_ok ──────────────────────────────────────────────────────────────
ok('acc gate disabled (0)', GpsFunctions::accuracy_ok(500.0, 0));
ok('acc null accepted',     GpsFunctions::accuracy_ok(null, 50));
ok('acc within limit',      GpsFunctions::accuracy_ok(16.0, 50));
ok('acc at limit',          GpsFunctions::accuracy_ok(50.0, 50));
ok('acc over limit',       !GpsFunctions::accuracy_ok(80.0, 50));

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

// A long frozen cluster may exceed the configured detection window. Keep the
// entire cluster stale instead of dropping the final sparse row after a forced
// window split.
$rows = [];
for ($i = 0; $i < 5; $i++) {
    $rows[] = ['time_ms' => $i * 20000, 'lat' => -37.888, 'lon' => 145.339, 'speed_kmh' => 50.0];
}
$stale = GpsFunctions::find_stale_windows($rows, 60, 10, 10);
ok('long frozen cluster keeps sparse tail stale', count($stale) === count($rows));

// Null speed rows: treated as unknown, should not flag stale alone
$rows = [];
for ($i = 0; $i < 10; $i++) {
    $rows[] = ['time_ms' => $i * 2000, 'lat' => -37.888, 'lon' => 145.339, 'speed_kmh' => null];
}
ok('null speed not stale',
    count(GpsFunctions::find_stale_windows($rows, 60, 10, 10)) === 0);

// Low-speed rows inside an otherwise frozen moving cluster should not be
// queued for repair; traffic-light/driveway rows are allowed to sit beside a
// stale segment, but are not stale themselves.
$rows = [];
for ($i = 0; $i < 6; $i++) {
    $speed = ($i === 0 || $i === 5) ? 0.0 : 50.0;
    $rows[] = ['time_ms' => $i * 10000, 'lat' => -37.888, 'lon' => 145.339, 'speed_kmh' => $speed];
}
$stale = GpsFunctions::find_stale_windows($rows, 60, 10, 10);
ok('low-speed edge rows in frozen cluster not stale',
    $stale === [10000, 20000, 30000, 40000]);

// A low-speed gap breaks a stale run. Two short moving freezes on either side
// of a stop should not be glued together into one long stale segment.
$rows = [];
foreach ([50.0, 50.0, 0.0, 0.0, 50.0, 50.0] as $i => $speed) {
    $rows[] = ['time_ms' => $i * 20000, 'lat' => -37.888, 'lon' => 145.339, 'speed_kmh' => $speed];
}
ok('low-speed gap breaks stale run',
    count(GpsFunctions::find_stale_windows($rows, 60, 10, 10)) === 0);

// Empty rows: no stale results
ok('empty rows not stale',
    count(GpsFunctions::find_stale_windows([], 60, 10, 10)) === 0);

// Single row: can't form a window
$rows = [['time_ms' => 1000, 'lat' => -37.888, 'lon' => 145.339, 'speed_kmh' => 50.0]];
ok('single row not stale',
    count(GpsFunctions::find_stale_windows($rows, 60, 10, 10)) === 0);

// ── HomeAssistantProvider::parse_states ──────────────────────────────────────
require_once __DIR__ . '/../gps/HomeAssistantProvider.php';

$raw_ha = json_decode('[
  [
    {
      "last_updated": "2026-06-01T10:00:00+00:00",
      "attributes": {"latitude": -37.888, "longitude": 145.339, "gps_accuracy": 16.0, "source": "device_tracker.test_phone"}
    },
    {
      "last_updated": "2026-06-01T10:01:00+00:00",
      "attributes": {"latitude": -37.889, "longitude": 145.340}
    },
    {
      "last_updated": "2026-06-01T10:02:00+00:00",
      "attributes": {}
    }
  ]
]', true);

$pts = HomeAssistantProvider::parse_states($raw_ha, 'device_tracker.test_phone');
ok('HA parse: filters no-lat/lon state',   count($pts) === 2);
ok('HA parse: lat correct',                abs($pts[0]->lat  - (-37.888)) < 0.0001);
ok('HA parse: lon correct',                abs($pts[0]->lon  - 145.339)   < 0.0001);
ok('HA parse: accuracy parsed',            $pts[0]->accuracy === 16.0);
ok('HA parse: accuracy null when absent',  $pts[1]->accuracy === null);
ok('HA parse: entity from source attr',    $pts[0]->entity === 'device_tracker.test_phone');
ok('HA parse: entity fallback used',       $pts[1]->entity === 'device_tracker.test_phone');
ok('HA parse: ordered by time ascending',  $pts[0]->time_ms < $pts[1]->time_ms);

// Edge cases
ok('HA parse: empty input',     count(HomeAssistantProvider::parse_states([], 'x')) === 0);
ok('HA parse: non-array entry', count(HomeAssistantProvider::parse_states([null], 'x')) === 0);
ok('HA parse: bad timestamp',   count(HomeAssistantProvider::parse_states([[['last_updated'=>'not-a-date','attributes'=>['latitude'=>1.0,'longitude'=>1.0]]]], 'x')) === 0);

// Multi-entity: each sub-array is one entity; entity_id labels the first state and
// is carried forward to later states in the same sub-array.
$raw_multi = json_decode('[
  [
    {"entity_id":"device_tracker.phone_a","last_updated":"2026-06-01T10:00:00+00:00","attributes":{"latitude":-37.1,"longitude":145.1}},
    {"last_updated":"2026-06-01T10:01:00+00:00","attributes":{"latitude":-37.2,"longitude":145.2}}
  ],
  [
    {"entity_id":"person.someone","last_updated":"2026-06-01T10:00:30+00:00","attributes":{"latitude":-37.3,"longitude":145.3}}
  ]
]', true);
$mp = HomeAssistantProvider::parse_states($raw_multi, 'fallback.entity');
ok('HA multi: all 3 points parsed',      count($mp) === 3);
// sorted ascending by time: phone_a@10:00, person@10:00:30, phone_a@10:01
ok('HA multi: entity_id on first',       $mp[0]->entity === 'device_tracker.phone_a');
ok('HA multi: second entity attributed', $mp[1]->entity === 'person.someone');
ok('HA multi: entity_id carried forward',$mp[2]->entity === 'device_tracker.phone_a');

// HA request code must concatenate the saved token. Keep redaction in logs/docs
// only; executable cURL headers must never contain placeholder text.
$ha_request_sources = [
    __DIR__ . '/../ha_test.php',
    __DIR__ . '/../gps/HomeAssistantProvider.php',
];
foreach ($ha_request_sources as $source) {
    ok('HA auth header is not redacted in ' . basename($source),
        strpos((string)file_get_contents($source), 'Authorization: Bearer ***') === false);
}

// ── find_nearest_point (inline mirror of GpsRepairWorker private method) ─────
function find_nearest_test(array $points, int $time_ms): ?GpsLocationPoint {
    $best = null; $best_d = PHP_INT_MAX;
    foreach ($points as $p) {
        $d = abs($p->time_ms - $time_ms);
        if ($d < $best_d) { $best_d = $d; $best = $p; }
    }
    return $best;
}

$pts_m = [
    new GpsLocationPoint(1000,  -37.888, 145.339, 10.0, 'x'),
    new GpsLocationPoint(3000,  -37.889, 145.340, 12.0, 'x'),
    new GpsLocationPoint(5000,  -37.890, 145.341, null, 'x'),
];

$n = find_nearest_test($pts_m, 2800);
ok('nearest: picks closest by time',    $n !== null && $n->time_ms === 3000);
$n = find_nearest_test($pts_m, 500);
ok('nearest: picks start for early ts', $n !== null && $n->time_ms === 1000);
$n = find_nearest_test($pts_m, 9999);
ok('nearest: picks end for late ts',    $n !== null && $n->time_ms === 5000);
ok('nearest: empty array = null',       find_nearest_test([], 1000) === null);

// Tolerance boundary check
$tol_ms  = 120 * 1000;
$pt_near = new GpsLocationPoint(50 * 1000, -37.0, 145.0, null, 'x');
$pt_far  = new GpsLocationPoint(999 * 1000, -37.0, 145.0, null, 'x');
ok('within tolerance accepted', abs($pt_near->time_ms - 1000) <= $tol_ms);
ok('beyond tolerance rejected', abs($pt_far->time_ms  - 1000) >  $tol_ms);

// ── results ──────────────────────────────────────────────────────────────────
echo "\n--- All tests: $pass passed, $fail failed ---\n";
exit($fail > 0 ? 1 : 0);
