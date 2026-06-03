<?php
require_once __DIR__ . '/GpsFunctions.php';
require_once __DIR__ . '/LocationPoint.php';
require_once __DIR__ . '/LocationProvider.php';

class GpsRepairWorker {

    public function __construct(
        private readonly mysqli              $con,
        private readonly GpsLocationProvider $provider,
        private readonly array               $cfg,
        private readonly bool                $dry_run = false
    ) {}

    /**
     * Build the worker $cfg array from the settings map. Single source of truth so
     * the CLI (repair.php), the on-demand endpoint (gps_repair_run.php), and any
     * future caller stay in lockstep on defaults and casts.
     */
    public static function config_from_settings(array $settings, string $db_table, string $db_sessions_table): array {
        return [
            'db_table'             => $db_table,
            'db_sessions_table'    => $db_sessions_table,
            'lookback_days'        => (int)  ($settings['gps_repair_lookback_days']   ?? 14),
            'min_age_minutes'      => (int)  ($settings['gps_repair_min_age_minutes'] ?? 5),
            'ha_tolerance_seconds' => (int)  ($settings['gps_ha_tolerance_seconds']   ?? 120),
            'ha_max_accuracy_m'    => (float)($settings['gps_ha_max_accuracy_m']      ?? 50),
            'stale_window_seconds' => (float)($settings['gps_stale_window_seconds']   ?? 60),
            'stale_min_speed_kmh'  => (float)($settings['gps_stale_min_speed_kmh']    ?? 10),
            'stale_max_movement_m' => (float)($settings['gps_stale_max_movement_m']   ?? 10),
        ];
    }

    /**
     * Main entry point.
     *
     * $options may contain:
     *   'session'       => string  — repair a single session ID only
     *   'lookback_days' => int     — override configured lookback period
     */
    public function run(array $options = []): void {
        $totC = 0; $totU = 0; $nSessions = 0;

        if (isset($options['session'])) {
            $sid = $options['session'];
            $y   = date('Y', intdiv((int)$sid, 1000));
            $m   = date('m', intdiv((int)$sid, 1000));
            [$c, $u] = $this->repair_session($sid, "{$this->cfg['db_table']}_{$y}_{$m}");
            $totC += $c; $totU += $u; $nSessions = 1;
        } else {
            $lookback_days = (int)($options['lookback_days'] ?? $this->cfg['lookback_days']);
            $now_ms        = (int)(microtime(true) * 1000);
            $cutoff_ms     = $now_ms - $lookback_days * 86400 * 1000;
            $max_ms        = $now_ms - (int)$this->cfg['min_age_minutes'] * 60 * 1000;

            $sessions = $this->get_sessions_in_range($cutoff_ms, $max_ms);
            $nSessions = count($sessions);
            $this->log("Found $nSessions sessions to scan (lookback={$lookback_days}d)");

            foreach ($sessions as $item) {
                [$c, $u] = $this->repair_session($item['session'], $item['table']);
                $totC += $c; $totU += $u;
            }
        }

        $summary = "scanned $nSessions sessions, corrected $totC, unresolved $totU"
                 . ($this->dry_run ? ' (dry-run)' : '');
        $this->log("Done — $summary");
        if (!$this->dry_run) $this->record_heartbeat($summary);
    }

    // ── session scanning ─────────────────────────────────────────────────────

    private function get_sessions_in_range(int $cutoff_ms, int $max_ms): array {
        $sql = "SELECT session FROM " . quote_name($this->cfg['db_sessions_table'])
             . " WHERE timestart >= " . quote_value((string)$cutoff_ms)
             . "   AND timestart <= " . quote_value((string)$max_ms)
             . " ORDER BY timestart DESC";
        $res = mysqli_query($this->con, $sql);
        if (!$res) return [];
        $out = [];
        while ($row = mysqli_fetch_assoc($res)) {
            $sid = $row['session'];
            $y   = date('Y', intdiv((int)$sid, 1000));
            $m   = date('m', intdiv((int)$sid, 1000));
            $out[] = ['session' => $sid, 'table' => "{$this->cfg['db_table']}_{$y}_{$m}"];
        }
        mysqli_free_result($res);
        return $out;
    }

    // ── per-session repair ───────────────────────────────────────────────────

    /** @return array{0:int,1:int} [corrected, unresolved] */
    public function repair_session(string $sid, string $table): array {
        // Guard: table must exist
        $chk = mysqli_query($this->con, "SHOW TABLES LIKE " . quote_value($table));
        if (!$chk || mysqli_num_rows($chk) === 0) {
            $this->log("Skipping session $sid — table $table not found");
            return [0, 0];
        }

        // Fetch GPS + OBD speed for every row in the session
        $sql = "SELECT time, kff1006 AS lat, kff1005 AS lon, kd AS speed_kmh"
             . " FROM " . quote_name($table)
             . " WHERE session = " . quote_value($sid)
             . " ORDER BY time ASC";
        $res = mysqli_query($this->con, $sql);
        if (!$res) {
            $this->log("Query failed for session $sid: " . mysqli_error($this->con));
            return [0, 0];
        }

        $rows = [];
        while ($r = mysqli_fetch_assoc($res)) {
            $rows[] = [
                'time_ms'   => (int)$r['time'],
                'lat'       => $r['lat']       !== null ? (float)$r['lat']       : null,
                'lon'       => $r['lon']       !== null ? (float)$r['lon']       : null,
                'speed_kmh' => $r['speed_kmh'] !== null ? (float)$r['speed_kmh'] : null,
            ];
        }
        mysqli_free_result($res);
        if (empty($rows)) return [0, 0];

        // Classify invalid rows
        $needs_repair = [];   // time_ms => reason string
        foreach ($rows as $row) {
            if (!GpsFunctions::is_valid_point($row['lat'], $row['lon'])) {
                $needs_repair[$row['time_ms']] = ($row['lat'] === null || $row['lon'] === null)
                    ? 'missing_gps'
                    : 'zero_gps';
            }
        }

        // Detect stale GPS windows (only on rows that have valid GPS)
        $valid_rows = array_values(
            array_filter($rows, fn($r) => GpsFunctions::is_valid_point($r['lat'], $r['lon']))
        );
        if (!empty($valid_rows)) {
            $stale_ms = GpsFunctions::find_stale_windows(
                $valid_rows,
                (float)$this->cfg['stale_window_seconds'],
                (float)$this->cfg['stale_min_speed_kmh'],
                (float)$this->cfg['stale_max_movement_m']
            );
            foreach ($stale_ms as $t) {
                $needs_repair[$t] = 'stale_gps';
            }
        }

        if (empty($needs_repair)) return [0, 0];

        // Skip rows already corrected (idempotency)
        $already = $this->get_already_corrected($table, $sid, array_keys($needs_repair));
        foreach ($already as $t) unset($needs_repair[$t]);
        if (empty($needs_repair)) {
            $this->log("Session $sid: all bad rows already corrected");
            return [0, 0];
        }

        $this->log("Session $sid: " . count($needs_repair) . " rows need repair");

        // Enqueue for tracking (idempotent INSERT IGNORE)
        if (!$this->dry_run) {
            $this->enqueue_rows($table, $sid, $needs_repair);
        }

        // Batch HA query — one request covers the whole session window ± 5 min
        $times  = array_keys($needs_repair);
        $lo_ms  = min($times) - 5 * 60 * 1000;
        $hi_ms  = max($times) + 5 * 60 * 1000;
        $ha_pts = $this->provider->get_history($lo_ms, $hi_ms);
        $this->log("  HA returned " . count($ha_pts) . " points for window");

        // Build lookup map for raw rows
        $rows_by_time = [];
        foreach ($rows as $r) $rows_by_time[$r['time_ms']] = $r;

        // Accuracy gate: drop provider points whose reported GPS accuracy is worse
        // than the configured maximum before matching (null accuracy is allowed).
        $max_acc   = (float)($this->cfg['ha_max_accuracy_m'] ?? 0);
        $ha_usable = array_values(array_filter(
            $ha_pts, fn($p) => GpsFunctions::accuracy_ok($p->accuracy, $max_acc)
        ));
        if (count($ha_usable) !== count($ha_pts)) {
            $this->log("  accuracy gate dropped " . (count($ha_pts) - count($ha_usable))
                . " of " . count($ha_pts) . " HA points (>{$max_acc}m)");
        }

        $tol_ms     = (int)$this->cfg['ha_tolerance_seconds'] * 1000;
        $corrected  = 0;
        $unresolved = 0;
        foreach ($needs_repair as $time_ms => $reason) {
            $nearest = $this->find_nearest_point($ha_usable, $time_ms);

            if ($nearest === null || abs($nearest->time_ms - $time_ms) > $tol_ms) {
                if (!$this->dry_run) $this->mark_queue_error($table, $sid, $time_ms, 'no_ha_point');
                $unresolved++;
                continue;
            }

            $delta_ms   = abs($nearest->time_ms - $time_ms);
            $confidence = GpsFunctions::confidence_for_delta($delta_ms);
            $raw        = $rows_by_time[$time_ms] ?? null;
            if ($this->dry_run) {
                $this->log("  [DRY-RUN] $time_ms → lat={$nearest->lat} lon={$nearest->lon}"
                    . " src={$nearest->entity} reason=$reason conf=$confidence"
                    . " delta=" . round($delta_ms / 1000) . "s");
            } else {
                $this->upsert_correction(
                    $sid, $table, $time_ms,
                    $raw['lat'] ?? null, $raw['lon'] ?? null,
                    $nearest->lat, $nearest->lon, $nearest->accuracy,
                    $this->provider->name(), $nearest->entity, $nearest->time_ms,
                    $reason, $confidence
                );
                $this->mark_queue_done($table, $sid, $time_ms);
            }
            $corrected++;
        }

        if (!$this->dry_run && $corrected > 0) {
            $this->update_session_repaired_count($sid);
        }

        // Second pass: derive a GPS-based speed (km/h) for each corrected row
        // from the now-final GPS sequence (corrected lat/lon where present,
        // else raw kff1006/kff1005). The raw kff1001 column is never written.
        if ($corrected > 0) {
            $n_speeds = $this->compute_corrected_speeds($sid, $table);
            if ($this->dry_run) {
                $this->log("  [DRY-RUN] would update $n_speeds corrected_speed_kmh values");
            } else if ($n_speeds > 0) {
                $this->log("  updated corrected_speed_kmh for $n_speeds row(s)");
            }
        }

        $this->log("  corrected=$corrected unresolved=$unresolved");
        return [$corrected, $unresolved];
    }

    /**
     * Walk the session's final GPS sequence (corrected where present, raw-valid
     * otherwise) in time order and write a derived km/h into gps_corrections for
     * every repaired row. Idempotent — re-running recomputes the same values.
     * Returns the number of rows whose speed was (or would be) updated.
     */
    private function compute_corrected_speeds(string $sid, string $table): int {
        $sql = "SELECT r.time AS time_ms,
                       COALESCE(gc.corrected_lat, r.kff1006) AS lat,
                       COALESCE(gc.corrected_lon, r.kff1005) AS lon,
                       gc.id AS corr_id"
             . " FROM " . quote_name($table) . " r"
             . gps_corr_join_sql($table, $sid)
             . " WHERE r.session = " . quote_value($sid)
             . " ORDER BY r.time ASC";
        $res = mysqli_query($this->con, $sql);
        if (!$res) {
            $this->log("  speed pass: query failed — " . mysqli_error($this->con));
            return 0;
        }

        $prev = null;     // ['time_ms' => int, 'lat' => float, 'lon' => float]
        $updates = [];    // corr_id => speed_kmh (or null)
        while ($r = mysqli_fetch_assoc($res)) {
            $time_ms = (int)$r['time_ms'];
            $lat = $r['lat'] !== null ? (float)$r['lat'] : null;
            $lon = $r['lon'] !== null ? (float)$r['lon'] : null;
            $corr_id = $r['corr_id'] !== null ? (int)$r['corr_id'] : null;

            if (!GpsFunctions::is_valid_point($lat, $lon)) continue;

            if ($corr_id !== null) {
                $speed = ($prev === null) ? null : GpsFunctions::compute_speed_kmh(
                    $prev['lat'], $prev['lon'], $prev['time_ms'],
                    $lat, $lon, $time_ms
                );
                $updates[$corr_id] = $speed;
            }
            $prev = ['time_ms' => $time_ms, 'lat' => $lat, 'lon' => $lon];
        }
        mysqli_free_result($res);

        if ($this->dry_run) return count($updates);

        foreach ($updates as $corr_id => $speed) {
            $val = $speed !== null ? quote_value((string)$speed) : 'NULL';
            mysqli_query($this->con,
                "UPDATE gps_corrections SET corrected_speed_kmh = $val WHERE id = " . (int)$corr_id
            );
        }
        return count($updates);
    }

    // ── DB helpers ───────────────────────────────────────────────────────────

    private function get_already_corrected(string $table, string $sid, array $times): array {
        if (empty($times)) return [];
        $in  = implode(',', array_map(fn($t) => quote_value((string)$t), $times));
        $sql = "SELECT torque_time_ms FROM gps_corrections"
             . " WHERE raw_table = " . quote_value($table)
             . "   AND session   = " . quote_value($sid)
             . "   AND torque_time_ms IN ($in)";
        $res = mysqli_query($this->con, $sql);
        if (!$res) return [];
        $done = [];
        while ($row = mysqli_fetch_row($res)) $done[] = (int)$row[0];
        mysqli_free_result($res);
        return $done;
    }

    private function enqueue_rows(string $table, string $sid, array $needs_repair): void {
        foreach ($needs_repair as $time_ms => $reason) {
            mysqli_query($this->con,
                "INSERT IGNORE INTO gps_repair_queue (session, raw_table, torque_time_ms, reason)"
                . " VALUES (" . quote_value($sid) . "," . quote_value($table) . ","
                . quote_value((string)$time_ms) . "," . quote_value($reason) . ")"
            );
        }
    }

    private function upsert_correction(
        string $sid, string $table, int $time_ms,
        ?float $raw_lat, ?float $raw_lon,
        float $c_lat, float $c_lon, ?float $accuracy,
        string $source, string $entity, int $src_ts_ms,
        string $reason, string $confidence
    ): void {
        $nl = $raw_lat  !== null ? quote_value((string)$raw_lat)  : 'NULL';
        $no = $raw_lon  !== null ? quote_value((string)$raw_lon)  : 'NULL';
        $na = $accuracy !== null ? quote_value((string)$accuracy) : 'NULL';

        mysqli_query($this->con,
            "INSERT INTO gps_corrections"
            . " (session, raw_table, torque_time_ms, raw_lat, raw_lon,"
            . "  corrected_lat, corrected_lon, accuracy,"
            . "  source, source_entity, source_updated_at_ms, reason, confidence)"
            . " VALUES ("
            . quote_value($sid) . "," . quote_value($table) . ","
            . quote_value((string)$time_ms) . ",$nl,$no,"
            . quote_value((string)$c_lat) . "," . quote_value((string)$c_lon) . ",$na,"
            . quote_value($source) . "," . quote_value($entity) . ","
            . quote_value((string)$src_ts_ms) . ","
            . quote_value($reason) . "," . quote_value($confidence)
            . ") ON DUPLICATE KEY UPDATE"
            . "  corrected_lat        = " . quote_value((string)$c_lat) . ","
            . "  corrected_lon        = " . quote_value((string)$c_lon) . ","
            . "  accuracy             = $na,"
            . "  source               = " . quote_value($source) . ","
            . "  source_entity        = " . quote_value($entity) . ","
            . "  source_updated_at_ms = " . quote_value((string)$src_ts_ms) . ","
            . "  reason               = " . quote_value($reason) . ","
            . "  confidence           = " . quote_value($confidence)
        );
    }

    private function mark_queue_done(string $table, string $sid, int $time_ms): void {
        mysqli_query($this->con,
            "UPDATE gps_repair_queue SET processed_at = NOW(), last_error = NULL"
            . " WHERE raw_table = " . quote_value($table)
            . "   AND session   = " . quote_value($sid)
            . "   AND torque_time_ms = " . quote_value((string)$time_ms)
        );
    }

    private function mark_queue_error(string $table, string $sid, int $time_ms, string $err): void {
        mysqli_query($this->con,
            "UPDATE gps_repair_queue SET processed_at = NOW(), last_error = " . quote_value($err)
            . " WHERE raw_table = " . quote_value($table)
            . "   AND session   = " . quote_value($sid)
            . "   AND torque_time_ms = " . quote_value((string)$time_ms)
        );
    }

    /**
     * Cache the corrected-point count back onto the sessions row so the UI can show it.
     * No-op (silently) if the gps_repaired_points column doesn't exist yet (pre-v26).
     */
    private function update_session_repaired_count(string $sid): void {
        $cnt = "(SELECT COUNT(*) FROM gps_corrections WHERE session = " . quote_value($sid) . ")";
        @mysqli_query($this->con,
            "UPDATE " . quote_name($this->cfg['db_sessions_table'])
            . " SET gps_repaired_points = $cnt WHERE session = " . quote_value($sid));
    }

    // ── stats / observability ──────────────────────────────────────────────────

    /**
     * Read-only summary of correction + queue state over the lookback window.
     * Used by `repair.php --stats`. Performs no writes.
     */
    public function stats(int $lookback_days): void {
        $now_ms    = (int)(microtime(true) * 1000);
        $cutoff_ms = $now_ms - $lookback_days * 86400 * 1000;

        $totC = $this->scalar("SELECT COUNT(*) FROM gps_corrections");
        $totQ = $this->scalar("SELECT COUNT(*) FROM gps_repair_queue");
        $pend = $this->scalar("SELECT COUNT(*) FROM gps_repair_queue WHERE processed_at IS NULL");
        $unres= $this->scalar("SELECT COUNT(*) FROM gps_repair_queue WHERE last_error IS NOT NULL");
        $this->log("Totals: corrections=$totC  queue=$totQ  pending=$pend  unresolved=$unres");

        $this->log("By source:");
        $res = mysqli_query($this->con,
            "SELECT source, confidence, COUNT(*) c FROM gps_corrections GROUP BY source, confidence ORDER BY source, confidence");
        if ($res) {
            while ($r = mysqli_fetch_assoc($res)) {
                $this->log("  {$r['source']} / {$r['confidence']}: {$r['c']}");
            }
            mysqli_free_result($res);
        }

        $this->log("Recent sessions (corrections in last {$lookback_days}d):");
        $res = mysqli_query($this->con,
            "SELECT session, COUNT(*) c, MIN(reason) reason FROM gps_corrections"
            . " WHERE torque_time_ms >= " . quote_value((string)$cutoff_ms)
            . " GROUP BY session ORDER BY session DESC LIMIT 20");
        if ($res) {
            while ($r = mysqli_fetch_assoc($res)) {
                $this->log("  session {$r['session']}: {$r['c']} corrected");
            }
            mysqli_free_result($res);
        }
    }

    /** Record a one-line heartbeat of the last run into torque_settings (upsert). */
    public function record_heartbeat(string $summary): void {
        $val = date('Y-m-d H:i:s') . ' — ' . $summary;
        mysqli_query($this->con,
            "INSERT INTO torque_settings (setting_key, setting_value, setting_type, setting_label, setting_group)"
            . " VALUES ('gps_repair_last_run', " . quote_value($val) . ", 'string', 'GPS Repair Last Run', 'gps_repair')"
            . " ON DUPLICATE KEY UPDATE setting_value = " . quote_value($val));
    }

    private function scalar(string $sql): int {
        $res = mysqli_query($this->con, $sql);
        if (!$res) return 0;
        $row = mysqli_fetch_row($res);
        mysqli_free_result($res);
        return (int)($row[0] ?? 0);
    }

    // ── utilities ────────────────────────────────────────────────────────────

    private function find_nearest_point(array $points, int $time_ms): ?GpsLocationPoint {
        $best = null; $best_d = PHP_INT_MAX;
        foreach ($points as $p) {
            $d = abs($p->time_ms - $time_ms);
            if ($d < $best_d) { $best_d = $d; $best = $p; }
        }
        return $best;
    }

    private function log(string $msg): void {
        echo '[' . date('Y-m-d H:i:s') . "] $msg\n";
    }
}
