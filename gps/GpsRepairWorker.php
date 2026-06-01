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
     * Main entry point.
     *
     * $options may contain:
     *   'session'       => string  — repair a single session ID only
     *   'lookback_days' => int     — override configured lookback period
     */
    public function run(array $options = []): void {
        if (isset($options['session'])) {
            $sid = $options['session'];
            $y   = date('Y', intdiv((int)$sid, 1000));
            $m   = date('m', intdiv((int)$sid, 1000));
            $this->repair_session($sid, "{$this->cfg['db_table']}_{$y}_{$m}");
            return;
        }

        $lookback_days = (int)($options['lookback_days'] ?? $this->cfg['lookback_days']);
        $now_ms        = (int)(microtime(true) * 1000);
        $cutoff_ms     = $now_ms - $lookback_days * 86400 * 1000;
        $max_ms        = $now_ms - (int)$this->cfg['min_age_minutes'] * 60 * 1000;

        $sessions = $this->get_sessions_in_range($cutoff_ms, $max_ms);
        $this->log("Found " . count($sessions) . " sessions to scan (lookback={$lookback_days}d)");

        foreach ($sessions as $item) {
            $this->repair_session($item['session'], $item['table']);
        }
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

    public function repair_session(string $sid, string $table): void {
        // Guard: table must exist
        $chk = mysqli_query($this->con, "SHOW TABLES LIKE " . quote_value($table));
        if (!$chk || mysqli_num_rows($chk) === 0) {
            $this->log("Skipping session $sid — table $table not found");
            return;
        }

        // Fetch GPS + OBD speed for every row in the session
        $sql = "SELECT time, kff1006 AS lat, kff1005 AS lon, kd AS speed_kmh"
             . " FROM " . quote_name($table)
             . " WHERE session = " . quote_value($sid)
             . " ORDER BY time ASC";
        $res = mysqli_query($this->con, $sql);
        if (!$res) {
            $this->log("Query failed for session $sid: " . mysqli_error($this->con));
            return;
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
        if (empty($rows)) return;

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

        if (empty($needs_repair)) return;

        // Skip rows already corrected (idempotency)
        $already = $this->get_already_corrected($table, $sid, array_keys($needs_repair));
        foreach ($already as $t) unset($needs_repair[$t]);
        if (empty($needs_repair)) {
            $this->log("Session $sid: all bad rows already corrected");
            return;
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

        $corrected  = 0;
        $unresolved = 0;
        foreach ($needs_repair as $time_ms => $reason) {
            $nearest = $this->find_nearest_point($ha_pts, $time_ms);
            $tol_ms  = (int)$this->cfg['ha_tolerance_seconds'] * 1000;

            if ($nearest === null || abs($nearest->time_ms - $time_ms) > $tol_ms) {
                if (!$this->dry_run) $this->mark_queue_error($table, $sid, $time_ms, 'no_ha_point');
                $unresolved++;
                continue;
            }

            $raw = $rows_by_time[$time_ms] ?? null;
            if ($this->dry_run) {
                $delta_s = round(abs($nearest->time_ms - $time_ms) / 1000);
                $this->log("  [DRY-RUN] $time_ms → lat={$nearest->lat} lon={$nearest->lon}"
                    . " src={$nearest->entity} reason=$reason delta={$delta_s}s");
            } else {
                $this->upsert_correction(
                    $sid, $table, $time_ms,
                    $raw['lat'] ?? null, $raw['lon'] ?? null,
                    $nearest->lat, $nearest->lon, $nearest->accuracy,
                    $this->provider->name(), $nearest->entity, $nearest->time_ms,
                    $reason, 'high'
                );
                $this->mark_queue_done($table, $sid, $time_ms);
            }
            $corrected++;
        }

        $this->log("  corrected=$corrected unresolved=$unresolved");
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
            . "  reason               = " . quote_value($reason)
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
