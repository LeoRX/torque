<?php
// Immutable value object representing a single location fix from any provider.
class GpsLocationPoint {
    public function __construct(
        public readonly int    $time_ms,   // ms epoch (matches raw_logs.time)
        public readonly float  $lat,
        public readonly float  $lon,
        public readonly ?float $accuracy,  // metres, null if unknown
        public readonly string $entity     // source entity ID string
    ) {}
}
