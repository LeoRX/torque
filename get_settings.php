<?php
// get_settings.php — Load app settings from DB, seed defaults on first run.
// Requires $con (MySQLi connection) to already be open.

mysqli_query($con, "CREATE TABLE IF NOT EXISTS torque_settings (
  setting_key   VARCHAR(100) NOT NULL,
  setting_value TEXT,
  setting_type  ENUM('string','integer','float','boolean','select') DEFAULT 'string',
  setting_label VARCHAR(255) NOT NULL,
  setting_description TEXT,
  setting_group VARCHAR(50) DEFAULT 'general',
  PRIMARY KEY (setting_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// key => [default_value, type, label, description, group]
$_setting_defaults = [
  'min_session_size'      => ['20',      'integer', 'Min Session Size',           'Sessions with fewer datapoints than this are hidden from the session list.',                 'sessions'],
  'show_session_length'   => ['1',       'boolean', 'Show Session Duration',      'Show drive duration next to each session in the dropdown.',                                  'sessions'],
  'session_gap_threshold' => ['10',      'integer', 'Session Gap Threshold (min)','Sessions separated by less than this many minutes are considered the same trip.',           'sessions'],
  'source_is_fahrenheit'  => ['0',       'boolean', 'Source Data in Fahrenheit',  'The Torque app is uploading temperature data in Fahrenheit.',                               'units'],
  'use_fahrenheit'        => ['0',       'boolean', 'Display in Fahrenheit',      'Show temperature values in Fahrenheit on screen.',                                          'units'],
  'source_is_miles'       => ['0',       'boolean', 'Source Data in Miles',       'The Torque app is uploading speed/distance in miles.',                                      'units'],
  'use_miles'             => ['0',       'boolean', 'Display in Miles',           'Show speed and distance values in miles on screen.',                                        'units'],
  'hide_empty_variables'  => ['1',       'boolean', 'Hide Empty Variables',       'Hide OBD2 channels that contain no data for the selected session.',                         'display'],
  'show_render_time'      => ['1',       'boolean', 'Show Page Render Time',      'Show page render timing information in the footer.',                                        'display'],
  'app_theme'             => ['default', 'select',  'UI Theme',                   'Visual theme for the application and login page.',                                          'display'],
  'map_line_color'        => ['#800000', 'string',  'Route Line Color',           'Hex color of the GPS route polyline on the map.',                                           'map'],
  'map_line_opacity'      => ['0.75',    'float',   'Route Line Opacity',         'Opacity of the GPS route line (0.0 = transparent, 1.0 = solid).',                          'map'],
  'map_line_weight'       => ['4',       'integer', 'Route Line Weight (px)',     'Stroke thickness of the GPS route line in pixels.',                                         'map'],
  'mapbox_token'          => ['',        'string',  'Mapbox Access Token',        'Required. Get a free token at mapbox.com. Paste your pk.eyJ1... token here.',              'map'],
  'mapbox_style'          => ['mapbox://styles/mapbox/streets-v12', 'select', 'Map Style', 'Visual style for the Mapbox map.',                                                       'map'],
  'display_timezone'      => ['Australia/Melbourne', 'string', 'Display Timezone', 'IANA timezone for displaying session dates and times (e.g. Australia/Melbourne, America/New_York, Europe/London, UTC).', 'display'],
  // AI Assistant
  'claude_enabled'        => ['0',      'boolean', 'Enable AI Assistant',        'Show the AI chat assistant button in the main interface.',                                  'ai'],
  'claude_api_key'        => ['',       'string',  'Claude API Key',             'Your Anthropic API key (sk-ant-...). Get one at console.anthropic.com.',                   'ai'],
  'claude_model'          => ['claude-haiku-4-5-20251001', 'select', 'Claude Model', 'Model to use. Haiku is fast/cheap; Sonnet is more capable.',                           'ai'],
  'claude_max_tokens'     => ['1024',   'integer', 'Max Response Tokens',        'Maximum response length (256–4096 tokens).',                                               'ai'],
];

// Remove obsolete Google Maps settings from DB (one-time cleanup)
mysqli_query($con, "DELETE FROM torque_settings WHERE setting_key IN ('map_default_type','gmaps_api_key')");

foreach ($_setting_defaults as $key => [$val, $type, $label, $desc, $group]) {
  $k = mysqli_real_escape_string($con, $key);
  $v = mysqli_real_escape_string($con, $val);
  $l = mysqli_real_escape_string($con, $label);
  $d = mysqli_real_escape_string($con, $desc);
  $g = mysqli_real_escape_string($con, $group);
  mysqli_query($con, "INSERT IGNORE INTO torque_settings
    (setting_key, setting_value, setting_type, setting_label, setting_description, setting_group)
    VALUES ('$k','$v','$type','$l','$d','$g')");
}

// Load all settings into $settings array
$settings = [];
$_sq = mysqli_query($con, "SELECT setting_key, setting_value FROM torque_settings");
while ($_row = mysqli_fetch_assoc($_sq)) {
  $settings[$_row['setting_key']] = $_row['setting_value'];
}
mysqli_free_result($_sq);

// Expose as typed PHP variables (backward compat)
$min_session_size     = (int)  ($settings['min_session_size']     ?? 20);
$show_session_length  = (bool) ($settings['show_session_length']  ?? 1);
$source_is_fahrenheit = (bool) ($settings['source_is_fahrenheit'] ?? 0);
$use_fahrenheit       = (bool) ($settings['use_fahrenheit']       ?? 0);
$source_is_miles      = (bool) ($settings['source_is_miles']      ?? 0);
$use_miles            = (bool) ($settings['use_miles']            ?? 0);
$hide_empty_variables = (bool) ($settings['hide_empty_variables'] ?? 1);
$app_theme            =        ($settings['app_theme']            ?? 'default');
$map_line_color       =        ($settings['map_line_color']       ?? '#800000');
$map_line_opacity     = (float)($settings['map_line_opacity']     ?? 0.75);
$map_line_weight      = (int)  ($settings['map_line_weight']      ?? 4);

$mapbox_token = $settings['mapbox_token'] ?? '';
$mapbox_style = $settings['mapbox_style'] ?? 'mapbox://styles/mapbox/streets-v12';

// Timezone for display — validated against PHP's known list; falls back to UTC on bad value
$display_timezone = $settings['display_timezone'] ?? 'Australia/Melbourne';
if (!in_array($display_timezone, DateTimeZone::listIdentifiers(DateTimeZone::ALL), true)) {
    $display_timezone = 'UTC';
}

/**
 * Format a Unix timestamp (in seconds) using an explicit IANA timezone.
 * Drop-in for date() that doesn't rely on date_default_timezone_set().
 */
function tz_date(string $format, int $ts, string $tz = 'UTC'): string {
    try {
        $dt = new DateTime('@' . $ts);
        $dt->setTimezone(new DateTimeZone($tz));
        return $dt->format($format);
    } catch (Exception $e) {
        return date($format, $ts); // last-resort fallback
    }
}

$claude_enabled    = !empty($settings['claude_enabled']) && $settings['claude_enabled'] !== '0';
$claude_api_key    = $settings['claude_api_key']    ?? '';
$claude_model      = $settings['claude_model']      ?? 'claude-haiku-4-5-20251001';
$claude_max_tokens = (int)($settings['claude_max_tokens'] ?? 1024);
?>
