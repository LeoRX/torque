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
function session_obd_summary($con, $db_table, $session_id_str) {
    $sid   = (int)$session_id_str;
    $ts    = intdiv($sid, 1000);
    $tbl   = $db_table . '_' . date('Y', $ts) . '_' . date('m', $ts);
    $q = mysqli_query($con, "SELECT
        AVG(CAST(NULLIF(k5,'')   AS DECIMAL(10,4))) AS coolant,
        AVG(CAST(NULLIF(k5c,'')  AS DECIMAL(10,4))) AS oil_temp,
        AVG(CAST(NULLIF(k6,'')   AS DECIMAL(10,4))) AS st_b1,
        AVG(CAST(NULLIF(k7,'')   AS DECIMAL(10,4))) AS lt_b1,
        AVG(CAST(NULLIF(k8,'')   AS DECIMAL(10,4))) AS st_b2,
        AVG(CAST(NULLIF(k9,'')   AS DECIMAL(10,4))) AS lt_b2,
        AVG(CAST(NULLIF(k10,'')  AS DECIMAL(10,4))) AS maf,
        AVG(CAST(NULLIF(k4,'')   AS DECIMAL(10,4))) AS eng_load,
        AVG(CAST(NULLIF(kc,'')   AS DECIMAL(10,4))) AS rpm,
        AVG(CAST(NULLIF(kd,'')   AS DECIMAL(10,4))) AS speed,
        MAX(CAST(NULLIF(kd,'')   AS DECIMAL(10,4))) AS max_speed,
        AVG(CAST(NULLIF(k2182,'') AS DECIMAL(10,4))) AS atf_temp,
        AVG(CAST(NULLIF(kb,'')   AS DECIMAL(10,4))) AS map_kpa,
        AVG(CAST(NULLIF(kff5203,'') AS DECIMAL(10,4))) AS l100km
        FROM `$tbl` WHERE session=$sid");
    if (!$q) return [];
    $r = mysqli_fetch_assoc($q);
    if (!$r) return [];
    $out = [];
    if ($r['lt_b1']    !== null) $out[] = "LTFT B1: "      . round((float)$r['lt_b1'],1)    . "%";
    if ($r['st_b1']    !== null) $out[] = "STFT B1: "      . round((float)$r['st_b1'],1)    . "%";
    if ($r['lt_b2']    !== null) $out[] = "LTFT B2: "      . round((float)$r['lt_b2'],1)    . "%";
    if ($r['st_b2']    !== null) $out[] = "STFT B2: "      . round((float)$r['st_b2'],1)    . "%";
    if ($r['coolant']  !== null) $out[] = "Coolant: "      . round((float)$r['coolant'],1)  . "°C";
    if ($r['oil_temp'] !== null) $out[] = "Oil: "          . round((float)$r['oil_temp'],1) . "°C";
    if ($r['atf_temp'] !== null) $out[] = "ATF: "          . round((float)$r['atf_temp'],1) . "°C";
    if ($r['rpm']      !== null) $out[] = "RPM: "          . round((float)$r['rpm']);
    if ($r['eng_load'] !== null) $out[] = "Load: "         . round((float)$r['eng_load'],1) . "%";
    if ($r['maf']      !== null) $out[] = "MAF: "          . round((float)$r['maf'],1)      . "g/s";
    if ($r['speed']    !== null) $out[] = "Avg spd: "      . round((float)$r['speed'],1)    . "km/h";
    if ($r['max_speed'] !== null) $out[] = "Max spd: "     . round((float)$r['max_speed'],1). "km/h";
    if ($r['l100km']   !== null) $out[] = "L/100km: "      . round((float)$r['l100km'],1);
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
$ctx      = [];
$tz_str   = $display_timezone ?? 'UTC';

// ── 1. Current session info + OBD averages ───────────────────────────────────
if ($session_id) {
    $sid_esc = (int)$session_id;
    $sq = mysqli_query($con, "SELECT session, timestart, timeend, sessionsize, profileName, profileFuelType
                              FROM $db_sessions_table WHERE session=$sid_esc LIMIT 1");
    if ($sq && ($sr = mysqli_fetch_assoc($sq))) {
        $ts  = intdiv((int)$sr['session'], 1000);
        $dur = max(0, (int)(((int)$sr['timeend'] - (int)$sr['timestart']) / 1000));
        $ctx[] = "CURRENT SESSION: " . tz_date('F j, Y g:ia', $ts, $tz_str)
               . " | Duration: " . gmdate('H:i:s', $dur)
               . " | Points: " . $sr['sessionsize']
               . " | Profile: " . ($sr['profileName'] ?: 'unknown');
        $obd = session_obd_summary($con, $db_table, $sr['session']);
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
                               FROM $db_sessions_table
                               WHERE CAST(timestart AS UNSIGNED) >= $ms_start
                                 AND CAST(timestart AS UNSIGNED) <= $ms_end
                               ORDER BY CAST(timestart AS UNSIGNED) ASC
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
            $obd2 = session_obd_summary($con, $db_table, $ds['session']);
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
                           FROM $db_sessions_table
                           WHERE CAST(timestart AS UNSIGNED) >= $recent_cutoff
                             AND sessionsize >= $min_session_size
                           ORDER BY CAST(timestart AS UNSIGNED) DESC
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
$lt_trend_q = mysqli_query($con, "SELECT DISTINCT
    CONCAT(YEAR(FROM_UNIXTIME(session/1000)),'_',DATE_FORMAT(FROM_UNIXTIME(session/1000),'%m')) AS ym
    FROM $db_sessions_table ORDER BY ym DESC LIMIT 24");
$trend_rows = [];
if ($lt_trend_q) {
    while ($r = mysqli_fetch_assoc($lt_trend_q)) {
        list($y,$m) = explode('_', $r['ym']);
        $tbl = "{$db_table}_{$y}_{$m}";
        $tr  = mysqli_query($con, "SELECT
            AVG(CAST(NULLIF(k9,'') AS DECIMAL(10,2))) AS lt_b2,
            AVG(CAST(NULLIF(k7,'') AS DECIMAL(10,2))) AS lt_b1
            FROM `$tbl`
            WHERE k5 IS NOT NULL AND k5 != ''
              AND CAST(k5 AS DECIMAL(10,2)) > 40");
        if ($tr && ($row = mysqli_fetch_assoc($tr)) && ($row['lt_b2'] !== null || $row['lt_b1'] !== null)) {
            $entry = str_replace('_','/',$r['ym']);
            if ($row['lt_b2'] !== null) $entry .= ' B2:' . round((float)$row['lt_b2'],1) . '%';
            if ($row['lt_b1'] !== null) $entry .= ' B1:' . round((float)$row['lt_b1'],1) . '%';
            $trend_rows[] = $entry;
        }
    }
}
if ($trend_rows) {
    $ctx[] = "LT FUEL TRIM TREND (monthly avg, newest first): " . implode(', ', array_slice($trend_rows, 0, 12));
}

// ── 5. Database stats ────────────────────────────────────────────────────────
$statsq = mysqli_query($con, "SELECT COUNT(*) AS cnt,
    FROM_UNIXTIME(MIN(session)/1000,'%Y-%m-%d') AS first_date,
    FROM_UNIXTIME(MAX(session)/1000,'%Y-%m-%d') AS last_date
    FROM $db_sessions_table WHERE sessionsize >= $min_session_size");
if ($statsq && ($sr2 = mysqli_fetch_assoc($statsq))) {
    $ctx[] = "DATABASE: {$sr2['cnt']} sessions from {$sr2['first_date']} to {$sr2['last_date']}";
}

mysqli_close($con);

// ── System prompt ────────────────────────────────────────────────────────────
$system_prompt = "You are TorqueAI, an automotive data assistant embedded in Open Torque Viewer — a web app that displays OBD2 data recorded by the Torque Pro Android app for a Toyota FJ Cruiser. You have direct access to the vehicle telemetry database and can analyse driving data, diagnose issues, explain OBD2 readings, and identify service needs.

DATA ACCESS: You have access to:
- The current session's OBD averages
- Per-session detail (averages for all key PIDs) for any date or date range the user asks about — these are pre-fetched and included below when a date is mentioned
- A list of recent sessions from the last 14 days
- Monthly LT fuel trim trend going back 12 months
- Overall database stats (total sessions and date range)

When the user asks about a specific date or period, look for the \"SESSIONS FOR ...\" block in the context below — it contains per-session summaries for that date. If no sessions are found for a requested date, say so clearly.

Fuel trim interpretation: negative LTFT/STFT = ECU removing fuel = running rich. Positive = running lean. Normal range ±5%. Bank 2 LTFT beyond -8% is significant; beyond -12% approaches P0175 (System Too Rich B2). Bank 1 equivalents: P0172.

Be concise and practical. Use metric units (°C, km/h, g/s) unless asked otherwise.
If asked about service history, infer ECU resets from step changes in the fuel trim trend toward 0.
If the user asks something unrelated to their vehicle data, politely redirect.

Current data context:
" . implode("\n", $ctx) . "";

// ── Build messages array ──────────────────────────────────────────────────────
$messages = [];
foreach ($history as $turn) {
    $role    = ($turn['role'] ?? '') === 'assistant' ? 'assistant' : 'user';
    $content = trim($turn['content'] ?? '');
    if ($content) $messages[] = ['role' => $role, 'content' => $content];
}
$messages[] = ['role' => 'user', 'content' => $user_message];

// ── Call Anthropic API ────────────────────────────────────────────────────────
$payload = json_encode([
    'model'      => $claude_model,
    'max_tokens' => $claude_max_tokens,
    'system'     => $system_prompt,
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
