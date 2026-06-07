<?php
// claude_chat.php — Anthropic Claude API proxy with Torque DB context
// POST body (JSON): { message: string, history: [{role,content}], session_id: string }
// Response (JSON):  { response: string } or { error: string }

require_once('./db.php');
require_once('./auth_user.php');
require_once('./get_settings.php');

header('Content-Type: application/json; charset=utf-8');

// Must have AI enabled and an API key
if (!$claude_enabled || empty($claude_api_key)) {
    echo json_encode(['error' => 'AI assistant is not configured. Add your API key in Settings → AI Assistant.']);
    exit;
}

// Rate limiting: max 1 request per 5 seconds per session
$_rate_key = 'claude_last_request';
$_now = time();
if (isset($_SESSION[$_rate_key]) && ($_now - $_SESSION[$_rate_key]) < 5) {
    http_response_code(429);
    echo json_encode(['error' => 'Please wait a moment before sending another message.']);
    exit;
}
$_SESSION[$_rate_key] = $_now;

// Parse request body
$raw  = file_get_contents('php://input');
$body = json_decode($raw, true);
if (!is_array($body) || empty($body['message'])) {
    echo json_encode(['error' => 'Invalid request.']);
    exit;
}

$user_message = mb_substr(trim(strip_tags($body['message'])), 0, 2000); // max 2000 chars
$history      = is_array($body['history'] ?? null) ? $body['history'] : [];
$session_id   = preg_replace('/\D/', '', $body['session_id'] ?? '');

if (empty($user_message)) {
    echo json_encode(['error' => 'Message cannot be empty.']);
    exit;
}

// Limit history to last 20 turns
$history = array_slice($history, -20);

// ── Helper: query per-session OBD averages from the correct raw_logs table ──
function session_obd_summary($con, $db_table, $session_id_str, $dur_seconds = 0) {
    $sid   = (int)$session_id_str;
    $ts    = intdiv($sid, 1000);
    $tbl   = $db_table . '_' . date('Y', $ts) . '_' . date('m', $ts);
    $q = mysqli_query($con, "SELECT
        AVG(CAST(NULLIF(k5,'')    AS DECIMAL(10,4))) AS coolant,
        MAX(CAST(NULLIF(k5,'')    AS DECIMAL(10,4))) AS max_coolant,
        AVG(CAST(NULLIF(k5c,'')   AS DECIMAL(10,4))) AS oil_temp,
        MAX(CAST(NULLIF(k5c,'')   AS DECIMAL(10,4))) AS max_oil_temp,
        AVG(CAST(NULLIF(k6,'')    AS DECIMAL(10,4))) AS st_b1,
        AVG(CAST(NULLIF(k7,'')    AS DECIMAL(10,4))) AS lt_b1,
        AVG(CAST(NULLIF(k8,'')    AS DECIMAL(10,4))) AS st_b2,
        AVG(CAST(NULLIF(k9,'')    AS DECIMAL(10,4))) AS lt_b2,
        AVG(CAST(NULLIF(k10,'')   AS DECIMAL(10,4))) AS maf,
        AVG(CAST(NULLIF(k4,'')    AS DECIMAL(10,4))) AS eng_load,
        MAX(CAST(NULLIF(k4,'')    AS DECIMAL(10,4))) AS max_eng_load,
        AVG(CAST(NULLIF(kc,'')    AS DECIMAL(10,4))) AS rpm,
        MAX(CAST(NULLIF(kc,'')    AS DECIMAL(10,4))) AS max_rpm,
        AVG(CAST(NULLIF(kd,'')    AS DECIMAL(10,4))) AS speed,
        MAX(CAST(NULLIF(kd,'')    AS DECIMAL(10,4))) AS max_speed,
        AVG(CAST(NULLIF(k2182,'') AS DECIMAL(10,4))) AS atf_temp,
        MAX(CAST(NULLIF(k2182,'') AS DECIMAL(10,4))) AS max_atf_temp,
        AVG(CAST(NULLIF(kb,'')    AS DECIMAL(10,4))) AS map_kpa,
        AVG(CAST(NULLIF(kff5203,'') AS DECIMAL(10,4))) AS l100km,
        AVG(CAST(NULLIF(kff1001,'') AS DECIMAL(10,4))) AS gps_speed
        FROM " . quote_name($tbl) . " WHERE session=" . quote_value($sid));
    if (!$q) return [];
    $r = mysqli_fetch_assoc($q);
    if (!$r) return [];
    $out = [];
    if ($r['lt_b1']       !== null) $out[] = "LTFT B1: "        . round((float)$r['lt_b1'],1)       . "%";
    if ($r['st_b1']       !== null) $out[] = "STFT B1: "        . round((float)$r['st_b1'],1)       . "%";
    if ($r['lt_b2']       !== null) $out[] = "LTFT B2: "        . round((float)$r['lt_b2'],1)       . "%";
    if ($r['st_b2']       !== null) $out[] = "STFT B2: "        . round((float)$r['st_b2'],1)       . "%";
    if ($r['coolant']     !== null) $out[] = "Coolant avg: "    . round((float)$r['coolant'],1)     . "°C";
    if ($r['max_coolant'] !== null) $out[] = "Coolant peak: "   . round((float)$r['max_coolant'],1) . "°C";
    if ($r['oil_temp']    !== null) $out[] = "Oil avg: "        . round((float)$r['oil_temp'],1)    . "°C";
    if ($r['max_oil_temp'] !== null) $out[] = "Oil peak: "      . round((float)$r['max_oil_temp'],1). "°C";
    if ($r['atf_temp']    !== null) $out[] = "ATF avg: "        . round((float)$r['atf_temp'],1)    . "°C";
    if ($r['max_atf_temp'] !== null) $out[] = "ATF peak: "      . round((float)$r['max_atf_temp'],1). "°C";
    if ($r['rpm']         !== null) $out[] = "RPM avg: "        . round((float)$r['rpm']);
    if ($r['max_rpm']     !== null) $out[] = "RPM peak: "       . round((float)$r['max_rpm']);
    if ($r['eng_load']    !== null) $out[] = "Load avg: "       . round((float)$r['eng_load'],1)    . "%";
    if ($r['max_eng_load'] !== null) $out[] = "Load peak: "     . round((float)$r['max_eng_load'],1). "%";
    if ($r['maf']         !== null) $out[] = "MAF: "            . round((float)$r['maf'],1)         . "g/s";
    if ($r['speed']       !== null) $out[] = "Avg spd: "        . round((float)$r['speed'],1)       . "km/h";
    if ($r['max_speed']   !== null) $out[] = "Max spd: "        . round((float)$r['max_speed'],1)   . "km/h";
    if ($r['l100km']      !== null) $out[] = "L/100km: "        . round((float)$r['l100km'],1);
    // Estimate trip distance from GPS speed (falls back to OBD speed) × duration
    if ($dur_seconds > 0) {
        $spd = $r['gps_speed'] ?? $r['speed'];
        if ($spd !== null) {
            $dist_km = round((float)$spd * $dur_seconds / 3600, 1);
            if ($dist_km > 0) $out[] = "~{$dist_km} km";
        }
    }
    return $out;
}

// ── Helper: detect date range from natural-language message ─────────────────
function detect_date_range($message, $timezone) {
    $msg = mb_strtolower($message);
    $tz  = new DateTimeZone($timezone ?: 'UTC');
    $now = new DateTime('now', $tz);

    // Named relative dates
    $patterns = [
        '/\byesterday\b/'                          => ['-1 day',  0],
        '/\btoday\b/'                              => [null,      0],
        '/\bthis\s+week\b/'                        => ['monday',  7],
        '/\blast\s+week\b/'                        => ['-2 week monday', 7],
        '/\bthis\s+month\b/'                       => ['first day of this month', null],
        '/\blast\s+month\b/'                       => ['first day of last month', null],
        '/\blast\s+(\d+)\s+days?\b/'               => ['dynamic', null],
    ];
    foreach ($patterns as $pat => $cfg) {
        if (!preg_match($pat, $msg, $pm)) continue;
        $start = clone $now;
        $end   = clone $now;
        if ($cfg[0] === 'dynamic') {
            $days = (int)$pm[1];
            $start->modify("-{$days} days")->setTime(0,0,0);
            return [$start, $end];
        }
        if ($cfg[0] === null) {
            // today
            $start->setTime(0,0,0);
            return [$start, $end];
        }
        if ($cfg[1] === null) {
            // first of month
            $start->modify($cfg[0])->setTime(0,0,0);
            $end->modify('last day of this month')->setTime(23,59,59);
            return [$start, $end];
        }
        $start->modify($cfg[0])->setTime(0,0,0);
        $end = clone $start;
        $end->modify("+{$cfg[1]} days")->setTime(23,59,59);
        return [$start, $end];
    }

    // Specific date: "15 April", "April 15", "15/4", "15-04-2026", "2026-04-15"
    $months = ['jan'=>1,'feb'=>2,'mar'=>3,'apr'=>4,'may'=>5,'jun'=>6,
               'jul'=>7,'aug'=>8,'sep'=>9,'oct'=>10,'nov'=>11,'dec'=>12];
    $month_re = 'jan(?:uary)?|feb(?:ruary)?|mar(?:ch)?|apr(?:il)?|may|jun(?:e)?|'
              . 'jul(?:y)?|aug(?:ust)?|sep(?:t(?:ember)?)?|oct(?:ober)?|nov(?:ember)?|dec(?:ember)?';

    if (preg_match('/\b(\d{1,2})\s*(?:st|nd|rd|th)?\s+(' . $month_re . ')(?:\s+(\d{4}))?\b/i', $message, $m2) ||
        preg_match('/\b(' . $month_re . ')\s+(\d{1,2})(?:\s+(\d{4}))?\b/i', $message, $m2r)) {
        $matched = isset($m2[0]) ? $m2 : $m2r;
        $day     = (int)(is_numeric($matched[1]) ? $matched[1] : $matched[2]);
        $mon_str = strtolower(substr(is_numeric($matched[1]) ? $matched[2] : $matched[1], 0, 3));
        $mon     = $months[$mon_str] ?? null;
        $year    = isset($matched[3]) && $matched[3] ? (int)$matched[3] : (int)$now->format('Y');
        if ($mon && $day) {
            $start = new DateTime("{$year}-{$mon}-{$day} 00:00:00", $tz);
            $end   = new DateTime("{$year}-{$mon}-{$day} 23:59:59", $tz);
            return [$start, $end];
        }
    }
    if (preg_match('/\b(\d{4})-(\d{2})-(\d{2})\b/', $message, $m3)) {
        $start = new DateTime("{$m3[1]}-{$m3[2]}-{$m3[3]} 00:00:00", $tz);
        $end   = new DateTime("{$m3[1]}-{$m3[2]}-{$m3[3]} 23:59:59", $tz);
        return [$start, $end];
    }
    return null;
}

// ── Build DB context for the system prompt ──────────────────────────────────
$ctx           = [];
$tz_str        = $display_timezone ?? 'UTC';
$q_sess_tbl    = quote_name($db_sessions_table);

$ctx[] = "TODAY: " . tz_date('l, F j, Y', time(), $tz_str) . " (local time: " . tz_date('g:ia T', time(), $tz_str) . ")";

// ── 1. Current session info + OBD averages ───────────────────────────────────
if ($session_id) {
    $sid_esc = (int)$session_id;
    $sq = mysqli_query($con, "SELECT session, timestart, timeend, sessionsize, profileName, profileFuelType
                              FROM $q_sess_tbl WHERE session=" . quote_value($sid_esc) . " LIMIT 1");
    if ($sq && ($sr = mysqli_fetch_assoc($sq))) {
        $ts  = intdiv((int)$sr['session'], 1000);
        $dur = max(0, (int)(((int)$sr['timeend'] - (int)$sr['timestart']) / 1000));
        $ctx[] = "CURRENT SESSION: " . tz_date('F j, Y g:ia', $ts, $tz_str)
               . " | Duration: " . gmdate('H:i:s', $dur)
               . " | Points: " . $sr['sessionsize']
               . " | Profile: " . ($sr['profileName'] ?: 'unknown');
        $obd = session_obd_summary($con, $db_table, $sr['session'], $dur);
        if ($obd) $ctx[] = "CURRENT SESSION OBD AVERAGES: " . implode(' | ', $obd);
    }
}

// ── 2. Date-filtered sessions (when user mentions a date) ────────────────────
$date_range = detect_date_range($user_message, $tz_str);
if ($date_range) {
    list($dr_start, $dr_end) = $date_range;
    $ms_start = $dr_start->getTimestamp() * 1000;
    $ms_end   = $dr_end->getTimestamp()   * 1000;
    $label    = $dr_start->format('D M j Y');
    if ($dr_start->format('Y-m-d') !== $dr_end->format('Y-m-d'))
        $label .= ' to ' . $dr_end->format('D M j Y');

    $dq = mysqli_query($con, "SELECT session, timestart, timeend, sessionsize, profileName
                               FROM $q_sess_tbl
                               WHERE timestart + 0 >= $ms_start
                                 AND timestart + 0 <= $ms_end
                               ORDER BY timestart + 0 ASC
                               LIMIT 30");
    $date_sessions = [];
    if ($dq) while ($dr = mysqli_fetch_assoc($dq)) $date_sessions[] = $dr;

    if ($date_sessions) {
        $ctx[] = "SESSIONS FOR $label (" . count($date_sessions) . " found):";
        foreach ($date_sessions as $ds) {
            $ts2  = intdiv((int)$ds['session'], 1000);
            $dur2 = max(0, (int)(((int)$ds['timeend'] - (int)$ds['timestart']) / 1000));
            $line = "  Session " . $ds['session']
                  . " | " . tz_date('g:ia', $ts2, $tz_str)
                  . " | " . gmdate('H:i:s', $dur2) . " duration"
                  . " | " . $ds['sessionsize'] . " pts";
            $obd2 = session_obd_summary($con, $db_table, $ds['session'], $dur2);
            if ($obd2) $line .= " | " . implode(', ', $obd2);
            $ctx[] = $line;
        }
    } else {
        $ctx[] = "SESSIONS FOR $label: No sessions found in that date range.";
    }
}

// ── 3. Recent sessions list (last 14 days, up to 40 sessions) ───────────────
$recent_cutoff = (time() - 14 * 86400) * 1000;
$rq = mysqli_query($con, "SELECT session, timestart, timeend, sessionsize
                           FROM $q_sess_tbl
                           WHERE timestart + 0 >= $recent_cutoff
                             AND sessionsize >= $min_session_size
                           ORDER BY timestart + 0 DESC
                           LIMIT 40");
$recent_sessions = [];
if ($rq) {
    while ($rr = mysqli_fetch_assoc($rq)) {
        $ts3  = intdiv((int)$rr['session'], 1000);
        $dur3 = max(0, (int)(((int)$rr['timeend'] - (int)$rr['timestart']) / 1000));
        $recent_sessions[] = tz_date('M j g:ia', $ts3, $tz_str)
            . ' (' . gmdate('H:i', $dur3) . ', ' . $rr['sessionsize'] . 'pts, id:' . $rr['session'] . ')';
    }
}
if ($recent_sessions) {
    $ctx[] = "RECENT SESSIONS (last 14 days, newest first): " . implode(' | ', $recent_sessions);
}

// ── 4. LT fuel trim trend (monthly, last 12 months) ─────────────────────────
// Query 1: discover which monthly raw_logs tables exist (avoids per-month queries)
$tbl_q = mysqli_query($con, "SELECT table_name
    FROM INFORMATION_SCHEMA.tables
    WHERE table_schema = " . quote_value($db_name) . "
      AND table_name LIKE " . quote_value($db_table . '_%') . "
    ORDER BY table_name DESC LIMIT 12");
$trend_rows = [];
if ($tbl_q && mysqli_num_rows($tbl_q) > 0) {
    // Query 2: UNION ALL across all discovered monthly tables — one round-trip
    $union_parts = [];
    while ($tr = mysqli_fetch_assoc($tbl_q)) {
        $t = $tr['table_name'];
        // Extract YYYY_MM suffix from table name for grouping label
        $suffix = substr($t, strlen($db_table) + 1);
        $union_parts[] = "SELECT " . quote_value($suffix) . " AS ym,"
            . " AVG(CAST(NULLIF(k9,'') AS DECIMAL(10,2))) AS lt_b2,"
            . " AVG(CAST(NULLIF(k7,'') AS DECIMAL(10,2))) AS lt_b1"
            . " FROM " . quote_name($t)
            . " WHERE k5 IS NOT NULL AND k5 != '' AND CAST(k5 AS DECIMAL(10,2)) > 40";
    }
    $union_sql = implode(' UNION ALL ', $union_parts);
    $tr2 = mysqli_query($con, $union_sql);
    if ($tr2) {
        while ($row = mysqli_fetch_assoc($tr2)) {
            if ($row['lt_b2'] === null && $row['lt_b1'] === null) continue;
            $entry = str_replace('_', '/', $row['ym']);
            if ($row['lt_b2'] !== null) $entry .= ' B2:' . round((float)$row['lt_b2'], 1) . '%';
            if ($row['lt_b1'] !== null) $entry .= ' B1:' . round((float)$row['lt_b1'], 1) . '%';
            $trend_rows[] = $entry;
        }
    }
}
if ($trend_rows) {
    $ctx[] = "LT FUEL TRIM TREND (monthly avg, newest first): " . implode(', ', $trend_rows);
}

// ── 5. Database stats ────────────────────────────────────────────────────────
$statsq = mysqli_query($con, "SELECT COUNT(*) AS cnt,
    FROM_UNIXTIME(MIN(session)/1000,'%Y-%m-%d') AS first_date,
    FROM_UNIXTIME(MAX(session)/1000,'%Y-%m-%d') AS last_date
    FROM $q_sess_tbl WHERE sessionsize >= " . quote_value($min_session_size));
if ($statsq && ($sr2 = mysqli_fetch_assoc($statsq))) {
    $ctx[] = "DATABASE: {$sr2['cnt']} sessions from {$sr2['first_date']} to {$sr2['last_date']}";
}

mysqli_close($con);

// ── System prompt ────────────────────────────────────────────────────────────
// Static rules: stable across requests → eligible for Anthropic prompt caching.
// Min tokens to cache: 1024 (Sonnet/Opus) · 2048 (Haiku). Cache TTL: 5 minutes.
$static_rules = "You are TorqueAI, an automotive data assistant embedded in Open Torque Viewer — a web app that displays OBD2 driving data recorded by the Torque Pro Android app. You have direct access to the vehicle telemetry database and can analyse driving patterns, diagnose issues, explain OBD2 sensor readings, identify service needs, and track vehicle health trends over time.

DATA ACCESS:
- Current session OBD averages (if viewing a session)
- Per-session summaries for any date or date range the user asks about — pre-fetched and in the dynamic context when a date is mentioned
- Recent sessions list (last 14 days)
- Monthly LT fuel trim trend (last 12 months)
- Database stats (total sessions, date range)
- Current vehicle profile name and fuel type (if recorded)

When the user mentions a date, look for the \"SESSIONS FOR ...\" block in the dynamic context below. If no sessions are found, say so clearly.

---

## FUEL TRIM INTERPRETATION

Fuel trim measures the ECU's real-time correction to the base fuel map.
- NEGATIVE trim = ECU removing fuel = engine running RICH (excess fuel)
- POSITIVE trim = ECU adding fuel = engine running LEAN (insufficient fuel)

Short-term fuel trim (STFT) responds immediately to sensor readings. Long-term fuel trim (LTFT) is the learned correction accumulated over many drive cycles — the more diagnostic of the two.

Normal range: STFT ±10%, LTFT ±5%

Bank assignment:
- Bank 1 (B1): cylinder bank containing cylinder #1 — k6 (STFT B1), k7 (LTFT B1)
- Bank 2 (B2): opposite bank — k8 (STFT B2), k9 (LTFT B2)

LTFT diagnostic thresholds:
- 0% to ±5%: Normal operating range
- −5% to −8%: Mild rich bias — monitor; check O2 sensor activity
- −8% to −12%: Significant rich running — investigate; DTC P0172/P0175 possible
- Beyond −12%: Rich fault territory — P0172 (B1) or P0175 (B2) likely set
- +5% to +10%: Mild lean bias — check for small vacuum leaks
- +10% to +15%: Significant lean running — vacuum leak or fuel delivery issue; P0171/P0174 possible
- Beyond +15%: Lean fault territory — P0171 (B1) or P0174 (B2) likely set

Common causes of rich running (negative LTFT):
- Leaking fuel injector(s) — excess fuel delivery
- High fuel pressure (failing fuel pressure regulator)
- Coolant temp sensor fault — ECU over-fuels a \"cold\" engine
- MAF sensor contamination — over-reads airflow so ECU trims fuel negative
- EVAP purge valve stuck open — draws fuel vapour into intake

Common causes of lean running (positive LTFT):
- Vacuum/boost leaks — unmetered air enters after the MAF sensor
- Weak fuel pump or clogged fuel filter — low fuel pressure
- Dirty or partially-blocked injectors — under-delivery
- MAF sensor under-reading (oil contamination or dirty element)
- Exhaust leak upstream of O2 sensor — dilutes exhaust oxygen signal

ECU reset indicator: A step-change in LTFT toward 0% indicates the ECU's adaptive memory was cleared (battery disconnect, scan tool reset, or battery failure). The direction LTFT drifts back to in subsequent sessions reveals the underlying condition.

---

## TEMPERATURE INTERPRETATION

Coolant Temperature (k5):
- Cold start: <60°C — avoid sustained high RPM until warm
- 60–80°C: Warming up
- 80–95°C: Normal operating range; thermostat should maintain this
- 95–105°C: Elevated — verify coolant level, inspect thermostat and radiator cap
- >105°C: Warning — investigate cooling system; do not continue ignoring
- >110°C: Critical — stop driving if sustained; risk of head gasket or engine damage

Engine Oil Temperature (k5c):
- <60°C: Cold — allow warm-up; avoid high revs and hard loads
- 80–110°C: Normal operating range
- 110–130°C: Elevated — check oil level and quality; consider switching to full-synthetic
- >130°C: High risk — accelerated oxidation and viscosity breakdown; investigate cause

ATF Temperature (k2182 — Toyota A750F/A750E):
Toyota FJ Cruiser, 4Runner, Tacoma, and Land Cruiser Prado with the A750F/A750E automatic:
- <40°C: Cold start — normal; avoid aggressive gear changes until warm
- 40–70°C: Warming up
- 70–100°C: Normal operating range
- 100–110°C: Acceptable for short-term towing or mountain passes
- 110–120°C: Elevated — consider a drain-and-fill service if this occurs regularly
- >120°C: High risk — accelerated varnish formation and seal degradation
- Service note: Toyota sealed gearbox (self-draining pan, no dipstick) — ATF condition matters more than level. Recommend drain-and-fill every 40,000–50,000 km regardless of temperature readings. ATF dark red or brown colour indicates oxidation.

Intake Air Temperature (kf):
- Should track ambient temperature at idle or low speed
- IAT well above ambient at speed indicates heat soak from engine bay (normal on hot days)
- IAT >50°C with cool ambient may indicate a restricted airbox or recirculating hot air

---

## ENGINE LOAD & MAF

Engine Load (k4 — % of maximum torque available at current RPM):
- <15%: Idle, coasting, engine braking
- 15–50%: Light to moderate load (typical city or steady highway cruise)
- 50–70%: Sustained moderate load (hills, headwind, mild towing)
- 70–90%: Hard acceleration or heavy towing
- >90%: Near-maximum power demand

MAF — Mass Airflow (k10, in g/s):
- Idle: 2–6 g/s typical
- 80 km/h cruise: 8–15 g/s typical
- Wide-open throttle: 60–150+ g/s (varies by engine displacement)
- Discrepancy between MAF reading and expected airflow for the given load/RPM can indicate a dirty MAF sensor, air leak downstream of the sensor, or incorrect MAF calibration

OBD Speed (kd) vs GPS Speed (kff1001):
- A consistent offset (OBD reads higher than GPS) indicates tire wear or wheel/tyre size change
- A GPS speed that tracks OBD closely validates sensor accuracy

---

## SERVICE THRESHOLDS — WHEN TO RECOMMEND ACTION

Fuel trim:
- Recommend investigation when LTFT B1 or B2 exceeds ±8% on average across multiple sessions
- A persistent lean or rich trend (same direction over multiple months) warrants diagnostic attention even if below fault thresholds

Cooling system:
- Recommend inspection when session-average coolant temperature regularly exceeds 95°C
- Recommend coolant flush every 2 years or 40,000 km regardless of readings

ATF (Toyota A750F/A750E):
- Recommend drain-and-fill when ATF temperature regularly exceeds 110°C during normal driving
- Recommend drain-and-fill on mileage schedule even if temperatures look normal (sealed system)

Oil:
- Elevated oil temperature (>120°C average) combined with high engine load suggests a need for higher-viscosity or full-synthetic oil, or a cooling system inspection

---

## RESPONSE STYLE

Be concise and practical. Prioritise actionable findings. Use metric units (°C, km/h, g/s) unless the user asks otherwise. When quoting a reading, always include the unit. For multi-session comparisons, highlight the trend rather than listing every individual value. If asked about service history, infer ECU resets from LTFT step-changes toward zero. If the user asks about something unrelated to their vehicle data, politely redirect.

Trip distance in the context is estimated from average GPS speed × duration — accurate to within ~5% for normal driving but may under-read for heavy stop-and-go sessions.";

// Dynamic context: changes per request (current date, session data, recent sessions, fuel trend).
$dynamic_ctx = "Current data context:\n" . implode("\n", $ctx);

// ── Build messages array ──────────────────────────────────────────────────────
$messages = [];
foreach ($history as $turn) {
    $role    = ($turn['role'] ?? '') === 'assistant' ? 'assistant' : 'user';
    $content = mb_substr(trim($turn['content'] ?? ''), 0, 4000);
    if ($content) $messages[] = ['role' => $role, 'content' => $content];
}
$messages[] = ['role' => 'user', 'content' => $user_message];

// ── Call Anthropic API ────────────────────────────────────────────────────────
// System prompt uses two content blocks:
//   [0] static rules — cache_control marks the cache checkpoint (stable across requests)
//   [1] dynamic context — changes per request; not cached
$payload = json_encode([
    'model'      => $claude_model,
    'max_tokens' => $claude_max_tokens,
    'system'     => [
        ['type' => 'text', 'text' => $static_rules, 'cache_control' => ['type' => 'ephemeral']],
        ['type' => 'text', 'text' => $dynamic_ctx],
    ],
    'messages'   => $messages,
]);

$ch = curl_init('https://api.anthropic.com/v1/messages');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => $payload,
    CURLOPT_TIMEOUT        => 30,
    CURLOPT_HTTPHEADER     => [
        'Content-Type: application/json',
        'x-api-key: ' . $claude_api_key,
        'anthropic-version: 2023-06-01',
        'anthropic-beta: prompt-caching-2024-07-31',
    ],
]);

$raw_response = curl_exec($ch);
$curl_err     = curl_error($ch);
$http_code    = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($curl_err) {
    echo json_encode(['error' => 'Network error: ' . $curl_err]);
    exit;
}

$api_resp = json_decode($raw_response, true);

if ($http_code !== 200) {
    $msg = $api_resp['error']['message'] ?? "HTTP $http_code";
    echo json_encode(['error' => $msg]);
    exit;
}

$text = $api_resp['content'][0]['text'] ?? '';
echo json_encode(['response' => $text]);
