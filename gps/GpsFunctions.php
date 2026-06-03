<?php
// GPS validity and staleness detection.
// Pure functions — no database or HTTP dependencies.
class GpsFunctions {

    /**
     * Returns true if the lat/lon pair is a plausible real-world coordinate.
     * Rejects null, (0,0), and out-of-range values.
     */
    public static function is_valid_point(mixed $lat, mixed $lon): bool {
        if ($lat === null || $lon === null) return false;
        $lat = (float)$lat;
        $lon = (float)$lon;
        if ($lat === 0.0 && $lon === 0.0) return false;
        if ($lat < -90.0 || $lat > 90.0)  return false;
        if ($lon < -180.0 || $lon > 180.0) return false;
        return true;
    }

    /**
     * Map a timestamp delta (between the Torque row and the matched HA point) to a
     * confidence label. The closer in time, the more we trust the substitution.
     */
    public static function confidence_for_delta(int $delta_ms): string {
        $delta_ms = abs($delta_ms);
        if ($delta_ms <= 30000) return 'high';     // within 30s
        if ($delta_ms <= 90000) return 'medium';   // within 90s
        return 'low';
    }

    /**
     * Whether a provider point's GPS accuracy is acceptable.
     * $max_m <= 0 disables the gate; null accuracy (unknown) is accepted.
     */
    public static function accuracy_ok(?float $accuracy_m, float $max_m): bool {
        if ($max_m <= 0)          return true;
        if ($accuracy_m === null) return true;
        return $accuracy_m <= $max_m;
    }

    /**
     * Great-circle distance in metres between two WGS84 points (Haversine formula).
     */
    public static function haversine_m(float $lat1, float $lon1, float $lat2, float $lon2): float {
        $R  = 6371000.0;
        $p1 = deg2rad($lat1); $p2 = deg2rad($lat2);
        $dp = deg2rad($lat2 - $lat1);
        $dl = deg2rad($lon2 - $lon1);
        $a  = sin($dp / 2) ** 2 + cos($p1) * cos($p2) * sin($dl / 2) ** 2;
        return $R * 2.0 * atan2(sqrt($a), sqrt(1.0 - $a));
    }

    /**
     * Derive GPS speed (km/h) between two timestamped points. Returns null when
     * the time delta is too small to be meaningful (avoids division spikes from
     * duplicate-ms rows) or when either coordinate is missing.
     */
    public static function compute_speed_kmh(
        ?float $lat1, ?float $lon1, int $t1_ms,
        ?float $lat2, ?float $lon2, int $t2_ms
    ): ?float {
        if ($lat1 === null || $lon1 === null || $lat2 === null || $lon2 === null) return null;
        $dt_s = abs($t2_ms - $t1_ms) / 1000.0;
        if ($dt_s < 0.5) return null;
        $d_m  = self::haversine_m($lat1, $lon1, $lat2, $lon2);
        return ($d_m / $dt_s) * 3.6;
    }

    /**
     * Find rows with frozen GPS while OBD speed shows the car is moving.
     *
     * Each element of $rows must have keys:
     *   time_ms   int        — millisecond epoch
     *   lat       float|null — GPS latitude  (kff1006)
     *   lon       float|null — GPS longitude (kff1005)
     *   speed_kmh float|null — OBD speed     (kd)
     *
     * Rows MUST be sorted ascending by time_ms.
     * Only rows with valid lat/lon are evaluated; rows with invalid GPS skip silently.
     *
     * Returns an array of time_ms integers for rows inside stale windows.
     */
    public static function find_stale_windows(
        array $rows,
        float $window_s     = 60.0,
        float $min_speed    = 10.0,
        float $max_movement = 10.0
    ): array {
        $stale = [];
        $run   = [];

        // A frozen fix should persist long enough to be meaningful before we
        // queue repair work. This catches real 20-30s+ freezes while ignoring
        // tiny duplicate-coordinate bursts from normal GPS jitter.
        $min_duration_ms = (int)(min(30.0, max(10.0, $window_s / 2.0)) * 1000.0);

        $flush_run = function () use (&$run, &$stale, $min_duration_ms, $min_speed, $max_movement): void {
            $moving = array_values(array_filter(
                $run,
                fn($r) => $r['speed_kmh'] !== null && $r['speed_kmh'] >= $min_speed
            ));

            if (count($moving) < 2) {
                $run = [];
                return;
            }

            $duration_ms = $moving[array_key_last($moving)]['time_ms'] - $moving[0]['time_ms'];
            if ($duration_ms < $min_duration_ms) {
                $run = [];
                return;
            }

            $movement = 0.0;
            for ($k = 1; $k < count($moving); $k++) {
                $movement += self::haversine_m(
                    $moving[$k - 1]['lat'], $moving[$k - 1]['lon'],
                    $moving[$k]['lat'],     $moving[$k]['lon']
                );
            }
            if ($movement < $max_movement) {
                foreach ($moving as $row) {
                    $stale[$row['time_ms']] = true;
                }
            }

            $run = [];
        };

        foreach ($rows as $row) {
            if (!self::is_valid_point($row['lat'], $row['lon'])) {
                $flush_run();
                continue;
            }

            if ($row['speed_kmh'] === null || $row['speed_kmh'] < $min_speed) {
                $flush_run();
                continue;
            }

            if (empty($run)) {
                $run[] = $row;
                continue;
            }

            $first = $run[0];
            $drift_m = self::haversine_m(
                $first['lat'], $first['lon'],
                $row['lat'],   $row['lon']
            );

            if ($drift_m < $max_movement) {
                $run[] = $row;
                continue;
            }

            $flush_run();
            $run[] = $row;
        }

        $flush_run();
        return array_keys($stale);
    }
}
