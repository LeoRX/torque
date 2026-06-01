<?php
require_once __DIR__ . '/LocationPoint.php';
require_once __DIR__ . '/LocationProvider.php';

class HomeAssistantProvider implements GpsLocationProvider {

    public function __construct(
        private readonly string $base_url,
        private readonly string $token,
        private readonly string $entity_id
    ) {}

    public function get_history(int $start_ms, int $end_ms): array {
        $start = gmdate('Y-m-d\TH:i:s\Z', intdiv($start_ms, 1000));
        $end   = gmdate('Y-m-d\TH:i:s\Z', intdiv($end_ms,   1000));

        // NOTE: do NOT send minimal_response — it makes HA drop attributes (and thus
        // latitude/longitude) for every state except the first and last in the window.
        // We need coordinates on every fix to match per-timestamp.
        $url = rtrim($this->base_url, '/')
             . '/api/history/period/' . rawurlencode($start)
             . '?end_time='          . rawurlencode($end)
             . '&filter_entity_id='  . rawurlencode($this->entity_id);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $this->token,
                'Content-Type: application/json',
            ],
        ]);
        $raw  = curl_exec($ch);
        $err  = curl_error($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($err)          { error_log("HomeAssistantProvider cURL error: $err");     return []; }
        if ($code !== 200) { error_log("HomeAssistantProvider HTTP $code for $url");  return []; }

        $data = json_decode($raw, true);
        if (!is_array($data)) return [];

        return self::parse_states($data, $this->entity_id);
    }

    /**
     * Parse a raw HA history API response into GpsLocationPoint array.
     *
     * Extracted as a static method so unit tests can call it without HTTP.
     *
     * @param array  $data       Decoded JSON: outer array of entity-state arrays
     * @param string $entity_id  Fallback entity label if 'source' attribute is absent
     * @return GpsLocationPoint[]  Sorted ascending by time_ms
     */
    public static function parse_states(array $data, string $entity_id): array {
        $points = [];
        foreach ($data as $entity_states) {
            if (!is_array($entity_states)) continue;
            // HA groups states per entity in each sub-array and labels entity_id on
            // (at least) the first state; carry it forward for points that omit it.
            $sublist_entity = null;
            foreach ($entity_states as $state) {
                if (isset($state['entity_id'])) $sublist_entity = $state['entity_id'];
                $attrs = $state['attributes'] ?? [];
                $lat   = $attrs['latitude']  ?? null;
                $lon   = $attrs['longitude'] ?? null;
                if ($lat === null || $lon === null) continue;

                $ts_str = $state['last_updated'] ?? $state['last_changed'] ?? null;
                if (!$ts_str) continue;
                $ts = strtotime($ts_str);
                if ($ts === false || $ts <= 0) continue;

                $points[] = new GpsLocationPoint(
                    time_ms:  $ts * 1000,
                    lat:      (float)$lat,
                    lon:      (float)$lon,
                    accuracy: isset($attrs['gps_accuracy']) ? (float)$attrs['gps_accuracy'] : null,
                    entity:   $state['entity_id'] ?? $sublist_entity ?? ($attrs['source'] ?? $entity_id)
                );
            }
        }
        usort($points, fn($a, $b) => $a->time_ms <=> $b->time_ms);
        return $points;
    }

    public function name(): string { return 'home_assistant'; }
}
