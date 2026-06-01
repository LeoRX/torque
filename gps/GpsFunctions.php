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
        $n     = count($rows);
        $stale = [];

        for ($i = 0; $i < $n; $i++) {
            $end_ms = $rows[$i]['time_ms'] + (int)($window_s * 1000.0);

            // Collect valid-GPS rows in the window starting at row i
            $win = [];
            for ($j = $i; $j < $n && $rows[$j]['time_ms'] <= $end_ms; $j++) {
                if (self::is_valid_point($rows[$j]['lat'], $rows[$j]['lon'])) {
                    $win[] = $rows[$j];
                }
            }
            if (count($win) < 2) continue;

            // Require average OBD speed above threshold to avoid flagging stops/lights
            $speeds = array_filter(
                array_column($win, 'speed_kmh'),
                fn($s) => $s !== null && $s >= 0.0
            );
            if (empty($speeds)) continue;
            if (array_sum($speeds) / count($speeds) < $min_speed) continue;

            // Measure total GPS path length within the window
            $movement = 0.0;
            for ($k = 1; $k < count($win); $k++) {
                $movement += self::haversine_m(
                    $win[$k - 1]['lat'], $win[$k - 1]['lon'],
                    $win[$k]['lat'],     $win[$k]['lon']
                );
            }

            if ($movement < $max_movement) {
                foreach ($win as $row) {
                    $stale[$row['time_ms']] = true;
                }
            }
        }

        return array_keys($stale);
    }
}
