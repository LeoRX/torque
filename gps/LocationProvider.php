<?php
// Abstraction over any external location history source.
// Implement this interface to add Dawarich, direct HA Recorder MariaDB,
// or interpolation providers without touching GpsRepairWorker.
interface GpsLocationProvider {
    /**
     * Fetch location history for a time window.
     *
     * @param int $start_ms  Start of window (ms epoch, inclusive)
     * @param int $end_ms    End of window   (ms epoch, inclusive)
     * @return GpsLocationPoint[]  Ordered ascending by time_ms. Empty array on failure.
     */
    public function get_history(int $start_ms, int $end_ms): array;

    /** Short lowercase identifier stored in gps_corrections.source. */
    public function name(): string;
}
