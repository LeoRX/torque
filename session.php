<?php
//echo "<!-- Begin session.php at ".date("H:i:s", microtime(true))." -->\r\n";
$loadstart = date("g:i:s A", (int)microtime(true));
$loadmicrostart = explode(' ', microtime());
$loadmicrostart = $loadmicrostart[1] + $loadmicrostart[0];
ini_set('memory_limit', '256M');
require_once("./db.php");
require_once("./get_settings.php");
require_once("./auth_user.php");
require_once("./del_session.php");
//require_once("./merge_sessions.php");
require_once("./get_sessions.php");
require_once("./get_columns.php");
require_once("./plot.php");

$_SESSION['recent_session_id'] = strval(max($sids));
// $display_timezone is set by get_settings.php (included above)

// Capture the session ID if one has been chosen already
if (isset($_GET["id"])) {
  $session_id = preg_replace('/\D/', '', $_GET['id']);
}

// Call exit function
if (isset($_GET['logout'])) {
    logout_user();
}

// $filteryearmonth is already set (and sanitised) by get_sessions.php above.

// Define some variables to be used in variable management later, specifically when choosing default vars to plot
$i=1;
$var1 = "";
while ( isset($_POST["s$i"]) || isset($_GET["s$i"]) ) {
  ${'var' . $i} = "";
  if (isset($_POST["s$i"])) {
    ${'var' . $i} = $_POST["s$i"];
  }
  elseif (isset($_GET["s$i"])) {
    ${'var' . $i} = $_GET["s$i"];
  }
  $i = $i + 1;
}

// From the output of the get_sessions.php file, populate the page with info from
//  the current session. Using successful existence of a session as a trigger, 
//  populate some other variables as well.
if (isset($sids[0])) {
  if (!isset($session_id)) {
    $session_id = $sids[0];
  }
  //For the merge function, we need to find out, what would be the next session
  $idx = array_search( $session_id, $sids);
  $session_id_next = "";
  if($idx>0) {
    $session_id_next = $sids[$idx-1];
  }
  $tableYear = date( "Y", intdiv((int)$session_id, 1000) );
  $tableMonth = date( "m", intdiv((int)$session_id, 1000) );
  $db_table_full = "{$db_table}_{$tableYear}_{$tableMonth}";
  // Get GPS data + speed for the selected session (used by Leaflet heatmap)
  // Try with kff1001 (GPS speed sensor) first; fall back if column doesn't exist in older tables
  $_gps_sql_full = "SELECT kff1005, kff1006, COALESCE(NULLIF(kd,0), NULLIF(kff1001,0), 0) AS speed, time FROM $db_table_full WHERE session=$session_id AND kff1005 != 0 AND kff1006 != 0 ORDER BY time ASC";
  $_gps_sql_basic = "SELECT kff1005, kff1006, COALESCE(NULLIF(kd,0), 0) AS speed, time FROM $db_table_full WHERE session=$session_id AND kff1005 != 0 AND kff1006 != 0 ORDER BY time ASC";
  $sessionqry = mysqli_query($con, $_gps_sql_full);
  if (!$sessionqry) {
    $sessionqry = mysqli_query($con, $_gps_sql_basic);
  }
  $mapdata  = array();
  $maxSpeed = 0;
  while ($geo = mysqli_fetch_assoc($sessionqry)) {
    $spd = floatval($geo['speed']);
    $mapdata[] = array(floatval($geo['kff1005']), floatval($geo['kff1006']), $spd, (int)$geo['time']);
    if ($spd > $maxSpeed) $maxSpeed = $spd;
  }
  $imapdata = json_encode($mapdata);
  if ($maxSpeed < 10) $maxSpeed = 120; // sensible default if no speed data

  // Session exists — always show chart/variable sections
  $setZoomManually = 0;
  // Separately track whether GPS data exists (for map behaviour)
  $mapHasGPS = (count($mapdata) > 0);

  // Query the list of years and months where sessions have been logged, to be used later
  // YYYY_MM with zero-padded month (via %m) sorts correctly as a plain string DESC.
  // Using SELECT DISTINCT + ORDER BY Suffix avoids GROUP BY / ORDER BY ambiguity in MariaDB.
  $yearmonthquery = mysqli_query($con, "SELECT DISTINCT
    CONCAT(YEAR(FROM_UNIXTIME(session/1000)), '_', DATE_FORMAT(FROM_UNIXTIME(session/1000),'%m')) as Suffix,
    CONCAT(MONTHNAME(FROM_UNIXTIME(session/1000)), ' ', YEAR(FROM_UNIXTIME(session/1000))) as Description
    FROM $db_sessions_table
    ORDER BY Suffix DESC");
  if (!$yearmonthquery) { $yearmonthquery = null; }
  $yearmonthsuffixarray = array();
  $yearmonthdescarray = array();
  $i = 0;
  while($yearmonthquery && $row = mysqli_fetch_assoc($yearmonthquery)) {
    $yearmonthsuffixarray[$i] = $row['Suffix'];
    $yearmonthdescarray[$i] = $row['Description'];
    $i = $i + 1;
  }

  // Query the list of profiles where sessions have been logged, to be used later
  $profilequery = mysqli_query($con, "SELECT distinct profileName FROM $db_sessions_table ORDER BY profileName asc");
  if (!$profilequery) { $profilequery = null; }
  $profilearray = array();
  $i = 0;
  while($profilequery && $row = mysqli_fetch_assoc($profilequery)) {
    // Skip blank / unset profile names — they belong under "All Profiles"
    if ($row['profileName'] === '' || $row['profileName'] === 'Not Specified' || $row['profileName'] === null) continue;
    $profilearray[$i] = $row['profileName'];
    $i = $i + 1;
  }

  // ── HUD Widget config (from settings) ──
  $hudConfig = [
    'gauge1' => ['pid' => $hud_gauge1_pid, 'label' => $hud_gauge1_label, 'min' => $hud_gauge1_min, 'max' => $hud_gauge1_max, 'suffix' => $hud_gauge1_suffix],
    'gauge2' => ['pid' => $hud_gauge2_pid, 'label' => $hud_gauge2_label, 'min' => $hud_gauge2_min, 'max' => $hud_gauge2_max, 'suffix' => $hud_gauge2_suffix],
    'gauge3' => ['pid' => $hud_gauge3_pid, 'label' => $hud_gauge3_label, 'min' => $hud_gauge3_min, 'max' => $hud_gauge3_max, 'suffix' => $hud_gauge3_suffix],
    'fuel'   => ['pid' => $hud_stat_fuel_pid, 'label' => $hud_stat_fuel_label],
  ];

  // ── HUD session averages — query AVG for each configured PID ──
  // k-codes come from torque_settings (validated on save); use quote_name() for column identifiers.
  $hudSessionAvg = ['gauge1' => null, 'gauge2' => null, 'gauge3' => null, 'fuel' => null];
  $_g1col  = quote_name($hud_gauge1_pid);
  $_g2col  = quote_name($hud_gauge2_pid);
  $_g3col  = quote_name($hud_gauge3_pid);
  $_fcol   = quote_name($hud_stat_fuel_pid);
  $_avg_sql = "SELECT AVG($_g1col) AS g1, AVG($_g2col) AS g2,
                      AVG($_g3col) AS g3, AVG($_fcol) AS fuel
               FROM $db_table_full WHERE session=$session_id";
  $_avg_res = mysqli_query($con, $_avg_sql);
  if ($_avg_res) {
    $_avg_row = mysqli_fetch_assoc($_avg_res);
    $hudSessionAvg['gauge1'] = $_avg_row['g1']   !== null ? (float)$_avg_row['g1']   : null;
    $hudSessionAvg['gauge2'] = $_avg_row['g2']   !== null ? (float)$_avg_row['g2']   : null;
    $hudSessionAvg['gauge3'] = $_avg_row['g3']   !== null ? (float)$_avg_row['g3']   : null;
    $hudSessionAvg['fuel']   = $_avg_row['fuel'] !== null ? (float)$_avg_row['fuel'] : null;
    mysqli_free_result($_avg_res);
  }

  //Close the MySQL connection, which is why we can't query years later
  mysqli_free_result($sessionqry);
  mysqli_close($con);
} else {
  //Default map in case there's no sessions to query.  Very unlikely this will get used.
  $imapdata = '[]';
  $maxSpeed = 0;
  $setZoomManually = 1;
}

?>
<!DOCTYPE html>
<html lang="en" data-torque-theme="<?php echo htmlspecialchars($app_theme); ?>">
  <head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Open Torque Viewer</title>
    <meta name="description" content="Open Torque Viewer">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.3/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/tom-select/2.3.1/css/tom-select.bootstrap5.min.css">
    <link rel="stylesheet" href="static/css/torque.css">
    <link rel="stylesheet" href="static/css/themes.css">
    <link rel="stylesheet" href="static/css/hud.css">
    <link rel="stylesheet" href="https://fonts.googleapis.com/css?family=Lato">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jquery/3.7.1/jquery.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.3/js/bootstrap.bundle.min.js"></script>
    <script src="static/js/jquery.peity.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/tom-select/2.3.1/js/tom-select.complete.min.js"></script>
<?php if ($setZoomManually === 0) { ?>
    <script src="https://cdn.jsdelivr.net/npm/hammerjs@2.0.8/hammer.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chartjs-adapter-date-fns@3.0.0/dist/chartjs-adapter-date-fns.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chartjs-plugin-zoom@2.0.1/dist/chartjs-plugin-zoom.min.js"></script>
<?php } ?>
    <!-- Mapbox GL JS -->
    <link href="https://api.mapbox.com/mapbox-gl-js/v3.3.0/mapbox-gl.css" rel="stylesheet">
    <script src="https://api.mapbox.com/mapbox-gl-js/v3.3.0/mapbox-gl.js"></script>
    <script>
      var _routeData  = <?php echo $imapdata; ?>;
      var _maxSpeed   = <?php echo (float)$maxSpeed; ?>;
      var _noSession  = <?php echo $setZoomManually ? 'true' : 'false'; ?>;
      var _hasGPS     = <?php echo (!$setZoomManually && $mapHasGPS) ? 'true' : 'false'; ?>;
      var _mbToken      = <?php echo json_encode($mapbox_token); ?>;
      var _mbStyle      = <?php echo json_encode($mapbox_style); ?>;
      var _mbDarkStyle  = 'mapbox://styles/mapbox/dark-v11';
      var _lineWeight = <?php echo (int)$map_line_weight; ?>;
      var _lineOpacity= <?php echo (float)$map_line_opacity; ?>;

      document.addEventListener('DOMContentLoaded', function () {
        var mapEl = document.getElementById('map-canvas');

        if (!_mbToken) {
          mapEl.style.cssText += 'display:flex;align-items:center;justify-content:center;';
          mapEl.innerHTML = '<div class="text-center text-muted p-4">' +
            '<i class="bi bi-map" style="font-size:2.5rem;display:block;margin-bottom:.5rem;"></i>' +
            'No Mapbox token — add yours in <a href="settings.php">Settings &rarr; Map</a>.</div>';
          return;
        }

        mapboxgl.accessToken = _mbToken;
        // Pick correct style for the current light/dark preference
        var _initDark = (localStorage.getItem('torque-theme') === 'dark');
        var map = new mapboxgl.Map({
          container: 'map-canvas',
          style: _initDark ? _mbDarkStyle : _mbStyle,
          center: [0, 0],
          zoom: 1,
          projection: 'mercator'   // disable globe — always flat street map
        });
        map.addControl(new mapboxgl.NavigationControl(), 'top-right');
        window._torqueMap = map;

        // Handle tile/token errors: auto-clear stale Mapbox cache on first 403, then show banner
        map.on('error', function(e) {
          var status = e.error && e.error.status;
          if (status === 401 || status === 403) {
            console.warn('[Torque] Mapbox ' + status + ' — tile auth failed.');
            // First occurrence: clear Mapbox GL's IndexedDB tile cache and reload silently.
            // This fixes stale-cache 403s without any user action.
            if (!sessionStorage.getItem('torque-mapbox-cache-cleared')) {
              sessionStorage.setItem('torque-mapbox-cache-cleared', '1');
              if (typeof mapboxgl.clearStorage === 'function') {
                console.info('[Torque] Clearing Mapbox tile cache and reloading…');
                mapboxgl.clearStorage(function() { window.location.reload(); });
              } else {
                window.location.reload();
              }
              return;
            }
            // Second occurrence after reload: cache was already cleared, token is genuinely invalid.
            if (!document.getElementById('map-token-warn')) {
              var warn = document.createElement('div');
              warn.id = 'map-token-warn';
              warn.style.cssText = 'position:absolute;bottom:44px;left:50%;transform:translateX(-50%);' +
                'background:rgba(220,38,38,0.92);color:#fff;padding:7px 16px;border-radius:8px;' +
                'font-size:12px;z-index:10;white-space:nowrap;box-shadow:0 2px 8px rgba(0,0,0,0.3);';
              warn.innerHTML = '<i class="bi bi-exclamation-triangle-fill me-1"></i>' +
                'Mapbox token invalid or expired \u2014 ' +
                '<a href="settings.php" style="color:#fecaca;text-decoration:underline">update it in Settings</a>';
              mapEl.appendChild(warn);
            }
          } else {
            console.error('[Torque] Mapbox error:', e.error && e.error.message ? e.error.message : e);
          }
        });

        if (_noSession) {
          map.on('load', function() {
            new mapboxgl.Popup({ closeOnClick: false, closeButton: false })
              .setLngLat([0, 0])
              .setHTML('<div class="p-2 small text-muted">Select a session to see the route.</div>')
              .addTo(map);
          });
          return;
        }

        if (!_hasGPS) {
          var noGpsDiv = document.createElement('div');
          noGpsDiv.style.cssText =
            'position:absolute;top:50%;left:50%;transform:translate(-50%,-50%);z-index:10;' +
            'background:rgba(6,9,18,0.88);padding:12px 18px;border-radius:8px;' +
            'border:1px solid rgba(0,212,255,0.2);color:#8ab;' +
            'font-size:13px;text-align:center;box-shadow:0 0 24px rgba(0,212,255,0.06),0 4px 20px rgba(0,0,0,0.6);pointer-events:none;';
          noGpsDiv.innerHTML = '<i class="bi bi-geo-alt-fill" style="font-size:1.5rem;color:#00d4ff;display:block;margin-bottom:4px;"></i>No GPS data for this session';
          mapEl.appendChild(noGpsDiv);
          return;
        }

        // Debug: show first few raw points before filtering
        console.log('[Torque] Raw GPS count:', _routeData.length);
        if (_routeData.length > 0) {
          console.log('[Torque] First point [lon,lat,spd]:', _routeData[0]);
          console.log('[Torque] Last point  [lon,lat,spd]:', _routeData[_routeData.length-1]);
        }

        // _routeData is [lon, lat, speed] — kff1005=lon, kff1006=lat
        _routeData = _routeData.filter(function(p) {
          return p[0] >= -180 && p[0] <= 180    // lon (kff1005)
              && p[1] >= -90  && p[1] <= 90     // lat (kff1006)
              && !(p[0] === 0 && p[1] === 0);
        });
        console.log('[Torque] Valid GPS count after filter:', _routeData.length);

        if (_routeData.length === 0) {
          var noGpsDiv2 = document.createElement('div');
          noGpsDiv2.style.cssText =
            'position:absolute;top:50%;left:50%;transform:translate(-50%,-50%);z-index:10;' +
            'background:rgba(6,9,18,0.88);padding:12px 18px;border-radius:8px;' +
            'border:1px solid rgba(0,212,255,0.2);color:#8ab;' +
            'font-size:13px;text-align:center;box-shadow:0 0 24px rgba(0,212,255,0.06),0 4px 20px rgba(0,0,0,0.6);pointer-events:none;';
          noGpsDiv2.innerHTML = '<i class="bi bi-geo-alt-fill" style="font-size:1.5rem;color:#00d4ff;display:block;margin-bottom:4px;"></i>No valid GPS data for this session';
          mapEl.appendChild(noGpsDiv2);
          return;
        }

        // Already [lon, lat] — pass directly to Mapbox
        var coords = _routeData.map(function(p) { return [p[0], p[1]]; });

        // Detect stationary sessions: bounding box < ~10 m means all points are the same location.
        // A zero-length LineString is invalid GeoJSON; Mapbox silently fails to draw it.
        var lons = _routeData.map(function(p) { return p[0]; });
        var lats = _routeData.map(function(p) { return p[1]; });
        var lonSpan = Math.max.apply(null, lons) - Math.min.apply(null, lons);
        var latSpan = Math.max.apply(null, lats) - Math.min.apply(null, lats);
        var _isStationary = (lonSpan < 0.0001 && latSpan < 0.0001); // ≈10 m threshold

        // Fit bounds immediately (before style loads) so the viewport is correct
        var bounds = new mapboxgl.LngLatBounds();
        coords.forEach(function(c) { bounds.extend(c); });

        // For stationary sessions draw a parked-pin marker instead of a route polyline.
        if (_isStationary) {
          var pinLng = lons[0], pinLat = lats[0];
          window._torqueDrawRoute = function drawRoute() {
            try {
              // Remove any previous layers
              if (map.getLayer('route'))         { map.removeLayer('route'); }
              if (map.getLayer('route-outline')) { map.removeLayer('route-outline'); }
              if (map.getSource('route'))        { map.removeSource('route'); }
              // Draw a cyan pin marker at the parked location
              var pinEl = document.createElement('div');
              pinEl.style.cssText =
                'width:18px;height:18px;border-radius:50%;' +
                'background:#00d4ff;border:3px solid rgba(0,212,255,0.4);' +
                'box-shadow:0 0 0 6px rgba(0,212,255,0.15),0 2px 8px rgba(0,0,0,0.5);' +
                'cursor:default;';
              new mapboxgl.Marker({ element: pinEl, anchor: 'center' })
                .setLngLat([pinLng, pinLat])
                .addTo(map);
              map.flyTo({ center: [pinLng, pinLat], zoom: 16, duration: 0 });
              // Label
              var infoEl = document.createElement('div');
              infoEl.style.cssText =
                'position:absolute;bottom:44px;left:50%;transform:translateX(-50%);z-index:10;' +
                'background:rgba(6,9,18,0.88);color:#8ab;padding:5px 12px;border-radius:8px;' +
                'border:1px solid rgba(0,212,255,0.2);font-size:11px;white-space:nowrap;' +
                'box-shadow:0 2px 8px rgba(0,0,0,0.4);pointer-events:none;';
              infoEl.innerHTML = '<i class="bi bi-p-circle-fill me-1" style="color:#00d4ff"></i>Stationary session — no route to plot';
              mapEl.appendChild(infoEl);
            } catch(e) { console.error('Stationary pin error:', e); }
          };
          // Fire immediately or on load
          if (map.loaded()) { window._torqueDrawRoute(); }
          else { map.once('load', function() { window._torqueDrawRoute(); }); }
          return; // Skip normal route-drawing path
        }

        // Cap gradient to 50 evenly-spaced samples to avoid Mapbox expression size limits
        var MAX_STOPS = 50;
        var rawFractions = [];
        var n = _routeData.length;
        var step = Math.max(1, Math.floor(n / MAX_STOPS));
        for (var si = 0; si < n; si += step) {
          rawFractions.push(_maxSpeed > 0 ? Math.min(1, _routeData[si][2] / _maxSpeed) : 0);
        }
        // Ensure at least 2 stops (required by interpolate)
        if (rawFractions.length < 2) { rawFractions = [0, 0]; }
        var fractions = rawFractions;

        // drawRoute: called once the style is ready; exposed globally for dark-mode style swaps
        window._torqueDrawRoute = function drawRoute() {
          try {
            // Clean up previous layers/source if style was swapped
            if (map.getLayer('route'))         { map.removeLayer('route'); }
            if (map.getLayer('route-outline')) { map.removeLayer('route-outline'); }
            if (map.getSource('route'))        { map.removeSource('route'); }

            // Build gradient stops: positions 0.0→1.0, colours as rgb strings
            var gradientStops = [];
            var fn = fractions.length;
            for (var i = 0; i < fn; i++) {
              var pos = fn > 1 ? i / (fn - 1) : 0;
              var r   = fractions[i]; // 0=slow(blue) → 1=fast(red)
              // interpolate hue 240(blue)→0(red) via green/yellow
              var h = (1 - r) * 240;
              // hsl to rgb conversion
              var s = 1.0, l = 0.45;
              var c = (1 - Math.abs(2*l - 1)) * s;
              var x = c * (1 - Math.abs((h/60) % 2 - 1));
              var m = l - c/2;
              var rr,gg,bb;
              if      (h < 60)  { rr=c;  gg=x;  bb=0; }
              else if (h < 120) { rr=x;  gg=c;  bb=0; }
              else if (h < 180) { rr=0;  gg=c;  bb=x; }
              else if (h < 240) { rr=0;  gg=x;  bb=c; }
              else              { rr=x;  gg=0;  bb=c; }
              var R = Math.round((rr+m)*255), G = Math.round((gg+m)*255), B = Math.round((bb+m)*255);
              var col = 'rgb('+R+','+G+','+B+')';
              gradientStops.push(pos);
              gradientStops.push(col);
            }

            map.addSource('route', {
              type: 'geojson',
              lineMetrics: true,
              data: {
                type: 'Feature',
                geometry: { type: 'LineString', coordinates: coords }
              }
            });

            // Outline
            map.addLayer({
              id: 'route-outline',
              type: 'line',
              source: 'route',
              layout: { 'line-join': 'round', 'line-cap': 'round' },
              paint: {
                'line-width': _lineWeight + 3,
                'line-color': '#111',
                'line-opacity': Math.min(1, _lineOpacity + 0.2)
              }
            });

            // Speed gradient line
            var gradExpr = ['interpolate', ['linear'], ['line-progress']].concat(gradientStops);
            map.addLayer({
              id: 'route',
              type: 'line',
              source: 'route',
              layout: { 'line-join': 'round', 'line-cap': 'round' },
              paint: {
                'line-width': _lineWeight,
                'line-opacity': _lineOpacity,
                'line-gradient': gradExpr
              }
            });

            map.fitBounds(bounds, { padding: 50, maxZoom: 17, duration: 0 });

            // Speed legend — CSS class handles all colours (incl. dark mode)
            var _oldLegend = mapEl.querySelector('.torque-speed-legend');
            if (_oldLegend) { _oldLegend.remove(); }
            var legend = document.createElement('div');
            legend.className = 'torque-speed-legend';
            legend.innerHTML =
              '<strong>Speed</strong><br>' +
              '<span style="background:linear-gradient(90deg,hsl(240,100%,45%),hsl(120,100%,45%),hsl(60,100%,45%),hsl(0,100%,45%));' +
              'display:block;width:110px;height:8px;border-radius:4px;margin:3px 0;"></span>' +
              '<div style="display:flex;justify-content:space-between;width:110px;">' +
              '<span>0</span><span>' + Math.round(_maxSpeed / 2) + '</span>' +
              '<span>' + Math.round(_maxSpeed) + ' km/h</span></div>';
            mapEl.appendChild(legend);
          } catch(e) {
            console.error('Route draw error:', e);
          }
        }

        console.log('[Torque] GPS points:', _routeData.length, '| maxSpeed:', _maxSpeed, '| hasGPS:', _hasGPS);

        // ── Multi-session overlay: draws additional sessions from ?multi=SID2,SID3 ──
        window._torqueDrawMulti = function(m) {
          var params  = new URLSearchParams(window.location.search);
          var mp      = params.get('multi');
          if (!mp) return;
          var sids = mp.split(',').filter(function(s) { return /^\d+$/.test(s); });
          if (!sids.length) return;

          // Distinct colours for each additional session (index 0 = primary sid from PHP, already drawn)
          var COLORS = ['#e63946', '#2a9d8f', '#f4a261', '#9b5de5', '#00b4d8', '#fb8500', '#8ecae6'];

          // Build or update session legend (top-left of map)
          var mapEl = document.getElementById('map-canvas');
          var legend = document.getElementById('torque-session-legend');
          if (!legend && mapEl) {
            legend = document.createElement('div');
            legend.id = 'torque-session-legend';
            legend.style.cssText =
              'position:absolute;top:50px;left:10px;z-index:5;background:rgba(255,255,255,0.88);' +
              'backdrop-filter:blur(4px);border-radius:8px;padding:7px 10px;font-size:11px;' +
              'box-shadow:0 2px 8px rgba(0,0,0,0.18);max-width:200px;';
            mapEl.appendChild(legend);
          }
          if (legend) {
            // Primary session label from PHP-rendered page title
            var primarySid = new URLSearchParams(window.location.search).get('id') || '';
            var d = primarySid ? new Date(parseInt(primarySid, 10)) : null;
            var primaryLabel = d ? d.toLocaleDateString() + ' ' + d.toLocaleTimeString([], {hour:'2-digit',minute:'2-digit'}) : 'Primary session';
            var legendHtml = '<div style="font-weight:700;margin-bottom:4px;color:#333;">Sessions</div>';
            legendHtml += '<div style="display:flex;align-items:center;gap:6px;margin-bottom:3px;">';
            legendHtml += '<span style="display:inline-block;width:22px;height:4px;border-radius:2px;background:linear-gradient(90deg,hsl(240,100%,45%),hsl(0,100%,45%));flex-shrink:0;"></span>';
            legendHtml += '<span style="color:#333;">' + primaryLabel + '</span></div>';
            sids.forEach(function(sid2, idx2) {
              var d2 = new Date(parseInt(sid2, 10));
              var lbl2 = d2 ? d2.toLocaleDateString() + ' ' + d2.toLocaleTimeString([], {hour:'2-digit',minute:'2-digit'}) : sid2;
              legendHtml += '<div style="display:flex;align-items:center;gap:6px;margin-bottom:3px;">';
              legendHtml += '<span style="display:inline-block;width:22px;height:4px;border-radius:2px;background:' + COLORS[idx2 % COLORS.length] + ';flex-shrink:0;"></span>';
              legendHtml += '<span style="color:#333;">' + lbl2 + '</span></div>';
            });
            legend.innerHTML = legendHtml;
          }

          sids.forEach(function(sid, idx) {
            var color = COLORS[idx % COLORS.length];
            var srcId = 'multi-route-' + sid;
            // Remove stale layers/source (e.g. after a style swap)
            try {
              if (m.getLayer(srcId + '-line'))    m.removeLayer(srcId + '-line');
              if (m.getLayer(srcId + '-outline')) m.removeLayer(srcId + '-outline');
              if (m.getSource(srcId))             m.removeSource(srcId);
            } catch(e2) {}

            fetch('get_session_gps.php?sid=' + encodeURIComponent(sid))
              .then(function(r) { return r.json(); })
              .then(function(pts) {
                if (!Array.isArray(pts) || !pts.length) return;
                var valid = pts.filter(function(p) {
                  return p[0] >= -180 && p[0] <= 180 && p[1] >= -90 && p[1] <= 90
                      && !(p[0] === 0 && p[1] === 0);
                });
                if (!valid.length) return;
                try {
                  m.addSource(srcId, {
                    type: 'geojson',
                    data: { type: 'Feature', geometry: { type: 'LineString', coordinates: valid } }
                  });
                  m.addLayer({
                    id: srcId + '-outline', type: 'line', source: srcId,
                    layout: { 'line-join': 'round', 'line-cap': 'round' },
                    paint: { 'line-width': _lineWeight + 3, 'line-color': '#111',
                             'line-opacity': Math.min(1, _lineOpacity + 0.2) }
                  });
                  m.addLayer({
                    id: srcId + '-line', type: 'line', source: srcId,
                    layout: { 'line-join': 'round', 'line-cap': 'round' },
                    paint: { 'line-width': _lineWeight, 'line-color': color,
                             'line-opacity': _lineOpacity }
                  });
                } catch(e3) {
                  console.warn('[Torque] Multi-session layer error:', sid, e3);
                }
              })
              .catch(function(e) {
                console.warn('[Torque] Multi-session GPS fetch failed:', sid, e);
              });
          });
        };

        // Safe load: fire immediately if style already loaded, otherwise wait
        if (map.loaded()) {
          console.log('[Torque] Map already loaded, drawing route immediately');
          window._torqueDrawRoute();
          window._torqueDrawMulti(map);
        } else {
          console.log('[Torque] Waiting for map load event');
          map.once('load', function() {
            console.log('[Torque] Map load fired, drawing route');
            window._torqueDrawRoute();
            window._torqueDrawMulti(map);
          });
        }
      });
    </script>
<?php if ($setZoomManually === 0 && $var1 != "") { ?>
    <script>
<?php   $i=1; ?>
<?php   while ( isset(${'var' . $i }) && !empty(${'var' . $i }) ) { ?>
      var s<?php echo $i; ?> = [<?php foreach(${"d".$i} as $b) {echo "[".$b[0].", ".$b[1]."],";} ?>];
<?php     $i = $i + 1; ?>
<?php   } ?>
      var _hudColors     = ['#00d4ff','#ff6b6b','#00ff88','#f4a261','#9b5de5','#00b4d8','#fb8500'];
      var _hudColorsFill = ['rgba(0,212,255,0.08)','rgba(255,107,107,0.08)','rgba(0,255,136,0.06)',
                            'rgba(244,162,97,0.07)','rgba(155,93,229,0.07)','rgba(0,180,216,0.07)','rgba(251,133,0,0.07)'];
      var torqueDatasets = [
<?php   $i=1; ?>
<?php   while ( isset(${'var' . $i }) && !empty(${'var' . $i }) ) { ?>
        {
          label: <?php echo "${'v'.$i.'_label'}"; ?>,
          kcode: '<?php echo htmlspecialchars(${'v'.$i}); ?>',
          data: s<?php echo $i; ?>.map(function(p){ return {x: p[0], y: p[1]}; }),
          borderWidth: 1.5,
          pointRadius: 0,
          pointHitRadius: 8,
          tension: 0.1,
          borderColor: _hudColors[(<?php echo $i-1; ?>) % _hudColors.length],
          backgroundColor: _hudColorsFill[(<?php echo $i-1; ?>) % _hudColorsFill.length],
          fill: true
        }<?php if ( isset(${'var'.($i+1)}) ) echo ","; ?>

<?php     $i = $i + 1; ?>
<?php   } ?>
      ];
      // Apply Chart.js colours to match current light/dark theme
      function _applyChartTheme(isDark) {
        if (typeof Chart === 'undefined') return;
        // HUD is always dark — ignore isDark, use consistent dark colours
        var text = '#8ab';
        var grid = 'rgba(0, 212, 255, 0.07)';
        Chart.defaults.color       = text;
        Chart.defaults.borderColor = grid;
        if (window.torqueChart) {
          var s = window.torqueChart.options.scales;
          if (s.x) {
            s.x.ticks = Object.assign(s.x.ticks || {}, { color: text });
            s.x.grid  = Object.assign(s.x.grid  || {}, { color: grid });
            if (s.x.title) s.x.title.color = text;
          }
          if (s.y) {
            s.y.ticks = Object.assign(s.y.ticks || {}, { color: text });
            s.y.grid  = Object.assign(s.y.grid  || {}, { color: grid });
          }
          var lbl = window.torqueChart.options.plugins.legend.labels;
          if (lbl) lbl.color = text;
          window.torqueChart.update('none'); // 'none' = no animation
        }
      }

      // ── Map-hover crosshair: draws a vertical line on the chart when the map route is hovered ──
      window._mapHoverTs = null;
      Chart.register({
        id: 'mapCrosshair',
        afterDraw: function(chart) {
          if (window._mapHoverTs == null) return;
          var scale = chart.scales.x;
          if (!scale) return;
          var x = scale.getPixelForValue(window._mapHoverTs);
          var ca = chart.chartArea;
          if (x < ca.left || x > ca.right) return;
          var ctx2 = chart.ctx;
          ctx2.save();
          ctx2.beginPath();
          ctx2.moveTo(x, ca.top);
          ctx2.lineTo(x, ca.bottom);
          ctx2.lineWidth = 1.5;
          ctx2.strokeStyle = 'rgba(239,68,68,0.75)';
          ctx2.setLineDash([5, 3]);
          ctx2.stroke();
          ctx2.restore();
        }
      });

      window.addEventListener('load', function() {
        // Set colours before creating the chart so the initial render is correct
        _applyChartTheme(localStorage.getItem('torque-theme') === 'dark');
        var ctx = document.getElementById('chartCanvas').getContext('2d');
        window.torqueChart = new Chart(ctx, {
          type: 'line',
          data: { datasets: torqueDatasets },
          options: {
            responsive: true,
            maintainAspectRatio: false,
            interaction: { mode: 'index', intersect: false },
            scales: {
              x: {
                type: 'time',
                time: {
                  displayFormats: { hour: 'h:mm a', minute: 'h:mm a', second: 'h:mm:ss a' },
                  tooltipFormat: 'MM/dd/yyyy h:mm:ss a'
                },
                title: { display: true, text: 'Time' }
              },
              y: { title: { display: false } }
            },
            plugins: {
              legend: { position: 'top', labels: { usePointStyle: true, padding: 15 } },
              tooltip: { mode: 'index', intersect: false },
              zoom: {
                pan: { enabled: true, mode: 'x' },
                zoom: {
                  wheel: { enabled: true },
                  pinch: { enabled: true },
                  drag: { enabled: true, backgroundColor: 'rgba(13,110,253,0.12)' },
                  mode: 'x'
                }
              }
            }
          }
        });
        // ── Initialise HUD gauges after chart is ready ──
        setTimeout(_initGauges, 100);
      });
    </script>
<?php } ?>
    <script src="static/js/torquehelpers.js"></script>
<?php if ($setZoomManually === 0 && $mapHasGPS) { ?>
    <script>
    // ── Chart ↔ Map Crosshair ─────────────────────────────────────────────────
    // _routeData = [[lon, lat, speed, ts_ms], ...] sorted ASC by time (index 3)

    // Binary-search for the GPS point whose timestamp is closest to tsMs
    function _gpsPointAtTime(tsMs) {
      if (!_routeData || !_routeData.length || _routeData[0].length < 4) return null;
      var lo = 0, hi = _routeData.length - 1;
      while (lo < hi) {
        var mid = (lo + hi) >> 1;
        if (_routeData[mid][3] < tsMs) lo = mid + 1; else hi = mid;
      }
      if (lo > 0 && Math.abs(_routeData[lo-1][3] - tsMs) < Math.abs(_routeData[lo][3] - tsMs)) lo--;
      return _routeData[lo];
    }

    // Nearest GPS point to a lat/lng position (used for map hover)
    function _nearestGpsPoint(lng, lat) {
      if (!_routeData || !_routeData.length || _routeData[0].length < 4) return null;
      var best = null, bestD = Infinity;
      for (var i = 0; i < _routeData.length; i++) {
        var p = _routeData[i];
        var dx = p[0] - lng, dy = p[1] - lat;
        var d = dx * dx + dy * dy;
        if (d < bestD) { bestD = d; best = p; }
      }
      return best;
    }

    // Find the chart dataset value closest to tsMs (data may be in any order)
    function _chartValueAtTime(data, tsMs) {
      if (!data || !data.length) return null;
      var best = null, bestDiff = Infinity;
      for (var i = 0; i < data.length; i++) {
        var diff = Math.abs(data[i].x - tsMs);
        if (diff < bestDiff) { bestDiff = diff; best = data[i].y; }
      }
      return best;
    }

    // Build HTML for the map popup (time + all chart variable values).
    // Uses CSS classes (tcp-*) defined in torque.css so dark/light mode is handled by CSS,
    // not by snapshotting the theme at render time with inline styles.
    function _buildMapPopupHTML(tsMs) {
      var d = new Date(tsMs);
      var timeStr = d.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit', second: '2-digit' });
      var html = '<div style="line-height:1.6;min-width:160px;">';
      html += '<div class="tcp-time">' + timeStr + '</div>';
      if (window.torqueChart) {
        var datasets = window.torqueChart.data.datasets;
        for (var i = 0; i < datasets.length; i++) {
          var ds  = datasets[i];
          var val = _chartValueAtTime(ds.data, tsMs);
          if (val === null) continue;
          var col = (typeof ds.borderColor === 'string') ? ds.borderColor : '#0d6efd';
          var rounded = Math.round(val * 10) / 10;
          html += '<div class="tcp-row">';
          html += '<span class="tcp-dot" style="background:' + col + ';"></span>';
          html += '<span class="tcp-label">' + ds.label + '</span>';
          html += '<span class="tcp-value">' + rounded + '</span>';
          html += '</div>';
        }
      } else {
        html += '<div class="tcp-hint">Plot variables to see values here</div>';
      }
      html += '</div>';
      return html;
    }

    // ── Map dot marker (shown when hovering the chart) ──
    var _mapDotEl = (function() {
      var el = document.createElement('div');
      el.className = 'hud-map-dot';
      el.style.cssText =
        'width:12px;height:12px;border-radius:50%;' +
        'pointer-events:none;display:none;';
      return el;
    })();
    var _mapDotMarker = null;

    function _showMapDot(tsMs) {
      var pt = _gpsPointAtTime(tsMs);
      if (!pt || !window._torqueMap) return;
      if (!_mapDotMarker) {
        _mapDotMarker = new mapboxgl.Marker({ element: _mapDotEl, anchor: 'center' })
          .setLngLat([pt[0], pt[1]]).addTo(window._torqueMap);
      } else {
        _mapDotMarker.setLngLat([pt[0], pt[1]]);
      }
      _mapDotEl.style.display = 'block';
    }
    function _hideMapDot() {
      _mapDotEl.style.display = 'none';
    }

    window.addEventListener('load', function() {
      // ── Chart canvas → map dot ──
      var canvas = document.getElementById('chartCanvas');
      if (canvas) {
        canvas.addEventListener('mousemove', function(e) {
          if (!window.torqueChart) return;
          var pts = window.torqueChart.getElementsAtEventForMode(e, 'index', { intersect: false }, true);
          if (pts.length) {
            var tsMs = window.torqueChart.data.datasets[0].data[pts[0].index].x;
            _showMapDot(tsMs);
            _updateGauges(tsMs);
          } else {
            _hideMapDot();
          }
        });
        canvas.addEventListener('mouseleave', function() {
          _hideMapDot();
          _initGauges();
        });
      }

      // ── Map route hover → popup + chart crosshair ──
      function _waitForMap(cb) {
        if (window._torqueMap) {
          if (window._torqueMap.loaded()) { cb(window._torqueMap); }
          else { window._torqueMap.once('load', function(){ cb(window._torqueMap); }); }
        } else {
          var t = setInterval(function(){
            if (window._torqueMap) { clearInterval(t); _waitForMap(cb); }
          }, 150);
        }
      }

      _waitForMap(function(map) {
        var popup = new mapboxgl.Popup({
          closeButton: false, closeOnClick: false,
          maxWidth: '260px', className: 'torque-crosshair-popup'
        });

        // Apply theme-aware styles directly to the Mapbox popup container via JS.
        // CSS !important can't reliably override Mapbox's dynamically-injected stylesheet,
        // so we set background/color/border as inline styles on the content element.
        function _applyPopupTheme() {
          var el = popup.getElement ? popup.getElement() : null;
          if (!el) return;
          var content = el.querySelector('.mapboxgl-popup-content');
          var tip     = el.querySelector('.mapboxgl-popup-tip');
          if (tip) tip.style.display = 'none';
          if (!content) return;
          content.style.padding      = '8px 12px';
          content.style.borderRadius = '8px';
          content.style.fontFamily   = 'inherit';
          content.style.fontSize     = '12px';
          content.style.boxShadow    = '0 4px 18px rgba(0,0,0,0.22)';
          content.style.pointerEvents = 'none';
          // HUD is always dark
          content.style.background   = 'rgba(6, 9, 18, 0.92)';
          content.style.color        = '#8ab';
          content.style.border       = '1px solid rgba(0, 212, 255, 0.22)';
        }

        // Re-theme the popup whenever data-bs-theme changes on <html>
        new MutationObserver(function() {
          if (popup.isOpen()) _applyPopupTheme();
        }).observe(document.documentElement, { attributes: true, attributeFilter: ['data-bs-theme'] });

        map.on('mousemove', 'route', function(e) {
          map.getCanvas().style.cursor = 'crosshair';
          var pt = _nearestGpsPoint(e.lngLat.lng, e.lngLat.lat);
          if (!pt) { popup.remove(); return; }
          popup.setLngLat([pt[0], pt[1]]).setHTML(_buildMapPopupHTML(pt[3])).addTo(map);
          _applyPopupTheme(); // style the container each time HTML is set
          // Draw vertical crosshair on chart
          window._mapHoverTs = pt[3];
          if (window.torqueChart) window.torqueChart.draw();
          // Update HUD gauges to values at this map position
          if (typeof _updateGauges === 'function') _updateGauges(pt[3]);
        });
        map.on('mouseleave', 'route', function() {
          map.getCanvas().style.cursor = '';
          popup.remove();
          window._mapHoverTs = null;
          if (window.torqueChart) window.torqueChart.draw();
          // Reset HUD gauges to session averages
          if (typeof _initGauges === 'function') _initGauges();
        });
      });
    });
    </script>
<?php } ?>
    <script>
      function torqueToggle(id, btn) {
        var el = document.getElementById(id);
        if (!el) return;
        var hidden = el.classList.contains('torque-panel--hidden') || el.style.display === 'none';
        if (hidden) {
          el.style.display = '';
          requestAnimationFrame(function() {
            el.classList.remove('torque-panel--hidden');
          });
        } else {
          el.classList.add('torque-panel--hidden');
          setTimeout(function() { el.style.display = 'none'; }, 150);
        }
        if (btn) btn.classList.toggle('active', hidden);
        if (id === 'chart-section') {
          document.body.classList.toggle('chart-open', hidden);
          if (hidden && window.torqueChart) {
            setTimeout(function(){ window.torqueChart.resize(); }, 350);
          }
        }
        if (window._torqueMap) {
          setTimeout(function(){ window._torqueMap.resize(); }, 350);
        }
      }

      function toggleDarkMode() {
        var html = document.documentElement;
        var isDark = html.getAttribute('data-bs-theme') === 'dark';
        var nowDark = !isDark;
        html.setAttribute('data-bs-theme', nowDark ? 'dark' : 'light');
        var btn = document.getElementById('darkModeBtn');
        btn.innerHTML = nowDark
          ? '<i class="bi bi-sun"></i>'
          : '<i class="bi bi-moon-stars"></i>';
        btn.title = nowDark ? 'Dimmed mode' : 'Full neon mode';
        localStorage.setItem('torque-theme', nowDark ? 'dark' : 'light');

        // Update Chart.js colours
        if (typeof _applyChartTheme === 'function') { _applyChartTheme(nowDark); }

        // Swap Mapbox style to match dark/light mode
        if (window._torqueMap) {
          var newStyle = nowDark ? _mbDarkStyle : _mbStyle;
          window._torqueMap.setStyle(newStyle);
          // Re-draw route after the new style finishes loading
          if (window._torqueDrawRoute) {
            window._torqueMap.once('style.load', function() {
              window._torqueDrawRoute();
              if (window._torqueDrawMulti) window._torqueDrawMulti(window._torqueMap);
            });
          }
        }
      }

      // Apply saved theme immediately to avoid flash
      (function(){
        var saved = localStorage.getItem('torque-theme') || 'light';
        document.documentElement.setAttribute('data-bs-theme', saved);
      })();
    </script>
  </head>
  <body>
    <nav class="navbar navbar-dark bg-dark fixed-top hud-navbar">
      <div class="container-fluid flex-nowrap gap-2">

        <!-- Brand -->
        <a class="navbar-brand flex-shrink-0 hud-brand" href="session.php">⬡&nbsp;TORQUE</a>

        <!-- Filter + Session selection — horizontal row -->
        <form id="navfilterform" class="d-flex align-items-center gap-2 flex-nowrap flex-grow-1" method="post" role="form" action="url.php?id=<?php echo $session_id; ?>">
          <select id="selprofile" name="selprofile" class="form-select form-select-sm navbar-filter flex-shrink-0" style="max-width:130px;" onchange="document.getElementById('navfilterform').submit()">
            <option value="ALL"<?php if ($filterprofile == '%' || $filterprofile == 'ALL' || empty($filterprofile)) echo ' selected'; ?>>All Profiles</option>
<?php $i = 0; while(isset($profilearray[$i])) { ?>
            <option value="<?php echo $profilearray[$i]; ?>"<?php if ($filterprofile == $profilearray[$i]) echo ' selected'; ?>><?php echo $profilearray[$i]; ?></option>
<?php   $i = $i + 1; } ?>
          </select>
          <!-- Calendar date range picker toggle -->
          <button type="button" id="btn-cal" class="btn btn-sm btn-outline-light flex-shrink-0" title="Select date range by calendar"><i class="bi bi-calendar3"></i></button>
          <!-- Merge / Delete for current session -->
<?php if(isset($session_id) && !empty($session_id)){ ?>
          <button type="submit" form="formmerge" class="btn btn-sm btn-outline-primary flex-shrink-0" title="Merge session">
            <i class="bi bi-diagram-2"></i>
          </button>
          <button type="submit" form="formdelete" class="btn btn-sm btn-outline-danger flex-shrink-0" title="Delete session" id="deletebtn">
            <i class="bi bi-trash3"></i>
          </button>
<?php } ?>
        </form>

        <!-- Right-side icon controls -->
        <div class="d-flex align-items-center gap-1 flex-shrink-0 ms-auto">
<?php if ($setZoomManually === 0) { ?>
          <button id="btn-vars" class="btn btn-sm btn-outline-light" onclick="torqueToggle('vars-section', this)" title="Toggle Variables"><i class="bi bi-sliders"></i></button>
          <button id="btn-chart" class="btn btn-sm btn-outline-light<?php if ($var1 != "") echo ' active'; ?>" onclick="torqueToggle('chart-section', this)" title="Toggle Chart"><i class="bi bi-bar-chart-line"></i></button>
          <button id="btn-summary" class="btn btn-sm btn-outline-light<?php if ($var1 != "") echo ' active'; ?>" onclick="torqueToggle('summary-section', this)" title="Toggle Data Summary"><i class="bi bi-table"></i></button>
          <button id="btn-export" class="btn btn-sm btn-outline-light" onclick="torqueToggle('export-section', this)" title="Toggle Export"><i class="bi bi-download"></i></button>
<?php } ?>
<?php if ($claude_enabled): ?>
          <!-- AI Chat -->
          <button id="btn-ai" class="btn btn-sm btn-outline-light" onclick="torqueToggle('ai-section', this)" title="AI Assistant"><i class="bi bi-robot"></i></button>
<?php endif; ?>
          <!-- Settings -->
          <a href="settings.php" class="btn btn-sm btn-outline-light" title="Settings"><i class="bi bi-gear"></i></a>
          <!-- Dark mode toggle -->
          <button class="btn btn-sm btn-outline-light" id="darkModeBtn" onclick="toggleDarkMode()" title="Toggle Dark Mode"><i class="bi bi-moon-stars"></i></button>
<?php if ( !empty($_SESSION['torque_user']) ) { ?>
          <span class="navbar-text navbar-user ms-1 flex-shrink-0">
            <?php echo htmlspecialchars($_SESSION['torque_user']); ?>
            <a href="session.php?logout=true" class="ms-2" title="Logout">
              <img width="20" height="20" src="./static/logout.png" alt="Logout">
            </a>
          </span>
<?php } ?>
        </div>
      </div>
    </nav>

    <!-- Hidden forms for merge/delete (triggered by navbar buttons) -->
<?php if(isset($session_id) && !empty($session_id)){ ?>
    <form method="post" action="merge_sessions.php?mergesession=<?php echo $session_id; ?>" id="formmerge" style="display:none"></form>
    <form method="post" action="session.php?deletesession=<?php echo $session_id; ?>" id="formdelete" data-session-name="<?php echo htmlspecialchars($seshdates[$session_id] ?? ''); ?>" style="display:none"></form>
<?php } ?>

    <!-- Calendar date range picker panel -->
    <div id="cal-panel" class="torque-panel" style="display:none">
      <div class="torque-panel-header">
        <h6><i class="bi bi-calendar3 me-2"></i>Select Date Range</h6>
        <button class="torque-panel-close" onclick="window._torqueCal && _torqueCal.close()">×</button>
      </div>
      <div class="torque-panel-body p-3">
        <p id="cal-hint" class="text-muted small text-center mb-3">Click a start date, then an end date.</p>
        <div class="d-flex gap-4 justify-content-center flex-wrap">
          <div id="cal-left"></div>
          <div id="cal-right"></div>
        </div>
        <div id="cal-sessions" class="mt-3">
          <p class="text-center text-muted small mb-0">Select a date range above to see available sessions.</p>
        </div>
      </div>
    </div>

<?php if ($claude_enabled): ?>
    <!-- ── AI Chat panel ── -->
    <div class="torque-panel" id="ai-section" style="display:none">
      <div class="torque-panel-header">
        <h6><i class="bi bi-robot me-2"></i>TORQUE<span style="color:var(--hud-red)">AI</span>&nbsp;<span style="background:rgba(0,255,136,0.15);border:1px solid rgba(0,255,136,0.35);color:#00ff88;font-size:7px;padding:1px 5px;border-radius:10px;letter-spacing:1px;vertical-align:middle;">ONLINE</span></h6>
        <button class="torque-panel-close" onclick="torqueToggle('ai-section', document.getElementById('btn-ai'))">×</button>
      </div>
      <div class="torque-panel-body d-flex flex-column" style="padding:0;height:420px;">
        <div id="ai-messages" class="ai-messages flex-grow-1"></div>
        <div class="ai-suggestions" id="ai-suggestions">
          <button class="ai-suggestion-btn" onclick="aiSend('What does my current session data show?')">What does my current session show?</button>
          <button class="ai-suggestion-btn" onclick="aiSend('Is my engine running healthy based on the data?')">Is my engine healthy?</button>
          <button class="ai-suggestion-btn" onclick="aiSend('When was my car last serviced based on the fuel trim history?')">When was it last serviced?</button>
          <button class="ai-suggestion-btn" onclick="aiSend('What should I service next and why?')">What should I service next?</button>
        </div>
        <div class="ai-input-bar">
          <input type="text" id="ai-input" class="ai-input" placeholder="Ask about your car data…" autocomplete="off"
            onkeydown="if(event.key==='Enter'&&!event.shiftKey){aiSend();event.preventDefault();}">
          <button class="ai-send-btn" id="ai-send" onclick="aiSend()" title="Send">
            <i class="bi bi-send-fill"></i>
          </button>
        </div>
      </div>
    </div>
    <script>
      var _aiSessionId = <?php echo json_encode($session_id ?? ''); ?>;
    </script>
<?php endif; ?>

    <!-- Full-screen map canvas (sized by CSS) -->
    <div id="map-canvas"></div>

<?php if ($setZoomManually === 0): ?>
    <script>
      // HUD config and session averages — always emitted when a session exists
      var _hudConfig = <?php echo json_encode($hudConfig, JSON_UNESCAPED_UNICODE); ?>;
      var _hudSessionAvg = <?php echo json_encode($hudSessionAvg); ?>;
      // Initialise HUD gauges once page (including HUD widget HTML) is fully loaded.
      // This ensures gauges populate from session averages even when no chart variables
      // are plotted yet. When the chart IS plotted, the chart-ready setTimeout also
      // calls _initGauges — calling twice is harmless.
      window.addEventListener('load', function() {
        if (typeof _initGauges === 'function') _initGauges();
      });
    </script>
    <!-- ── HUD Widget — live arc gauges pinned to map ── -->
    <div id="hud-widget">
      <div class="hud-drag-handle" title="Drag to move"><span class="hud-drag-dots">⠿</span></div>
      <div class="hud-gauges">

        <div class="hud-gauge-wrap">
          <div class="hud-gauge-val hud-gauge-val--cyan" id="hud-gauge-rpm-val">&#x2014;</div>
          <svg width="70" height="40" viewBox="0 0 70 46" class="hud-gauge-svg">
            <path d="M 8 46 A 30 30 0 0 1 62 46" class="hud-gauge-track"/>
            <path d="M 8 46 A 30 30 0 0 1 62 46"
                  class="hud-gauge-arc hud-gauge-arc--cyan"
                  id="hud-gauge-rpm"
                  stroke-dasharray="94"
                  stroke-dashoffset="94"/>
          </svg>
          <div class="hud-gauge-label"><?php echo htmlspecialchars($hud_gauge1_label); ?></div>
        </div>

        <div class="hud-gauge-wrap">
          <div class="hud-gauge-val hud-gauge-val--red" id="hud-gauge-coolant-val">&#x2014;</div>
          <svg width="70" height="40" viewBox="0 0 70 46" class="hud-gauge-svg">
            <path d="M 8 46 A 30 30 0 0 1 62 46" class="hud-gauge-track"/>
            <path d="M 8 46 A 30 30 0 0 1 62 46"
                  class="hud-gauge-arc hud-gauge-arc--red"
                  id="hud-gauge-coolant"
                  stroke-dasharray="94"
                  stroke-dashoffset="94"/>
          </svg>
          <div class="hud-gauge-label"><?php echo htmlspecialchars($hud_gauge2_label); ?></div>
        </div>

        <div class="hud-gauge-wrap">
          <div class="hud-gauge-val hud-gauge-val--green" id="hud-gauge-speed-val">&#x2014;</div>
          <svg width="70" height="40" viewBox="0 0 70 46" class="hud-gauge-svg">
            <path d="M 8 46 A 30 30 0 0 1 62 46" class="hud-gauge-track"/>
            <path d="M 8 46 A 30 30 0 0 1 62 46"
                  class="hud-gauge-arc hud-gauge-arc--green"
                  id="hud-gauge-speed"
                  stroke-dasharray="94"
                  stroke-dashoffset="94"/>
          </svg>
          <div class="hud-gauge-label"><?php echo htmlspecialchars($hud_gauge3_label); ?></div>
        </div>

      </div>
      <div class="hud-stats">
        <div class="hud-stat">
          <div class="hud-stat-val hud-stat-val--cyan" id="hud-stat-dur">&#x2014;</div>
          <div class="hud-stat-label"><?php echo htmlspecialchars($hud_stat_dur_label); ?></div>
        </div>
        <div class="hud-stat">
          <div class="hud-stat-val" id="hud-stat-dist">&#x2014;</div>
          <div class="hud-stat-label"><?php echo htmlspecialchars($hud_stat_dist_label); ?></div>
        </div>
        <div class="hud-stat">
          <div class="hud-stat-val hud-stat-val--green" id="hud-stat-fuel">&#x2014;</div>
          <div class="hud-stat-label"><?php echo htmlspecialchars($hud_stat_fuel_label); ?></div>
        </div>
      </div>
    </div>
<?php endif; ?>

<?php if ($setZoomManually === 0) { ?>

    <!-- ── Floating panel: Variables ── -->
    <div class="torque-panel" id="vars-section" style="display:none">
      <div class="torque-panel-header">
        <h6>Select Variables</h6>
        <div class="d-flex align-items-center gap-2">
          <button type="button" class="btn btn-primary btn-sm py-0 px-3" onclick="onSubmitIt()">Plot!</button>
          <button class="torque-panel-close" onclick="torqueToggle('vars-section', document.getElementById('btn-vars'))">×</button>
        </div>
      </div>
      <div class="torque-panel-body p-3">
        <form method="post" role="form" action="url.php?makechart=y&seshid=<?php echo urlencode($session_id); ?><?php if ($filteryearmonth !== '') echo '&yearmonth='.urlencode($filteryearmonth); ?>" id="formplotdata">
          <div class="form-check form-switch mb-2 ms-1">
            <input class="form-check-input" type="checkbox" role="switch" id="filterHasData" checked>
            <label class="form-check-label small" for="filterHasData">Show only variables with data</label>
          </div>
          <select data-placeholder="Choose OBD2 data..." multiple class="form-select" id="plot_data" name="plotdata[]">
            <option value=""></option>
<?php   foreach ($coldata as $xcol) {
          $hasData = isset($xcol['has_data']) ? intval($xcol['has_data']) : -1;
?>
            <option value="<?php echo htmlspecialchars($xcol['colname']); ?>"
              data-has-data="<?php echo $hasData; ?>"
              <?php $i = 1; while ( isset(${'var' . $i}) ) { if ( (${'var' . $i} == $xcol['colname'] ) OR ( $xcol['colfavorite'] == 1 ) ) { echo " selected"; } $i = $i + 1; } ?>><?php echo htmlspecialchars($xcol['colcomment']); ?></option>
<?php   } ?>
          </select>
        </form>
      </div>
    </div>

    <!-- ── Floating panel: Chart (full-width bottom) ── -->
    <div class="torque-panel" id="chart-section"<?php if ($var1 == "") echo ' style="display:none"'; ?>>
      <div class="torque-panel-header">
        <h6>Chart</h6>
        <div class="d-flex align-items-center gap-2">
<?php if ($var1 != "") { ?>
          <button class="btn btn-sm btn-outline-secondary py-0 px-2" onclick="window.torqueChart && window.torqueChart.resetZoom()" title="Reset Zoom"><i class="bi bi-zoom-out"></i></button>
<?php } ?>
          <button class="torque-panel-close" onclick="torqueToggle('chart-section', document.getElementById('btn-chart'))">×</button>
        </div>
      </div>
      <div class="torque-panel-body">
<?php if ($var1 == "") { ?>
        <div class="alert alert-warning text-center m-3 mb-2">No Variables Selected to Plot!</div>
<?php } else { ?>
        <div class="chart-container">
          <canvas id="chartCanvas"></canvas>
        </div>
<?php } ?>
      </div>
    </div>

    <!-- ── Floating panel: Data Summary ── -->
    <div class="torque-panel" id="summary-section"<?php if ($var1 == "") echo ' style="display:none"'; ?>>
      <div class="torque-panel-header">
        <h6>Data Summary</h6>
        <button class="torque-panel-close" onclick="torqueToggle('summary-section', document.getElementById('btn-summary'))">×</button>
      </div>
      <div class="torque-panel-body">
<?php   if ( $var1 <> "" ) { ?>
        <div class="table-responsive">
          <table class="table table-striped table-hover table-sm mb-0">
            <thead class="table-light">
              <tr>
                <th>Name</th><th>Min/Max</th><th>25th Pcnt</th><th>75th Pcnt</th><th>Mean</th><th>Sparkline</th>
              </tr>
            </thead>
            <tbody>
<?php     $i=1; while ( isset(${'var' . $i }) ) { ?>
              <tr>
                <td><strong><?php echo htmlspecialchars(substr(${'v' . $i . '_label'}, 1, -1)); ?></strong></td>
                <td><?php echo htmlspecialchars(${'min' . $i}.'/'.${'max' . $i}); ?></td>
                <td><?php echo htmlspecialchars(${'pcnt25data' . $i}); ?></td>
                <td><?php echo htmlspecialchars(${'pcnt75data' . $i}); ?></td>
                <td><?php echo htmlspecialchars(${'avg' . $i}); ?></td>
                <td><span class="line"><?php echo htmlspecialchars(${'sparkdata' . $i}); ?></span></td>
              </tr>
<?php       $i = $i + 1; } ?>
            </tbody>
          </table>
        </div>
<?php   } else { ?>
        <div class="p-3"><div class="alert alert-warning text-center mb-0">No Variables Selected to Plot!</div></div>
<?php   } ?>
      </div>
    </div>

    <!-- ── Floating panel: Export ── -->
    <div class="torque-panel" id="export-section" style="display:none">
      <div class="torque-panel-header">
        <h6>Export Data</h6>
        <button class="torque-panel-close" onclick="torqueToggle('export-section', document.getElementById('btn-export'))">×</button>
      </div>
      <div class="torque-panel-body p-3">
        <div class="btn-group w-100 mb-2" role="group">
          <a class="btn btn-outline-secondary" href="<?php echo './export.php?sid='.urlencode($session_id).'&filetype=csv'; ?>">CSV</a>
          <a class="btn btn-outline-secondary" href="<?php echo './export.php?sid='.urlencode($session_id).'&filetype=json'; ?>">JSON</a>
        </div>
        <?php if (!empty($settings['show_render_time'])): ?>
        <div class="text-muted" style="font-size:10px;">
          Render: <?php echo htmlspecialchars($loadstart); ?><br>
          Load: <?php $loadmicroend = explode(' ', microtime()); $loadmicroend = $loadmicroend[1] + $loadmicroend[0]; echo round($loadmicroend - $loadmicrostart, 3); ?>s<br>
          Session: <?php echo htmlspecialchars($session_id); ?>
        </div>
        <?php endif; ?>
      </div>
    </div>

    <script>
    // Apply chart-open class on initial page load if chart is already visible
    (function() {
      var chartEl = document.getElementById('chart-section');
      if (chartEl && chartEl.style.display !== 'none') {
        document.body.classList.add('chart-open');
      }
    })();
    </script>

<?php } // end setZoomManually === 0 ?>
  </body>
</html>
