# Security Hardening & GPS Diagnostics Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Fix credential leak, CSRF, XSS, silent write failures, cross-month session merge bug, and add GPS upload/display diagnostics with a per-session GPS quality flag.

**Architecture:** Eight independent-but-ordered tasks. Security fixes first (Tasks 1–3), then data-integrity bugs (Tasks 4–5), then GPS improvements (Tasks 6–8). Each task commits independently. Feature branch `fix/security-gps` kept off `main` until all tasks pass manual validation.

**Tech Stack:** PHP 8.2, MariaDB 11.4, Apache, no test framework — all tests are manual curl + browser verification steps documented per task.

---

## File Map

| File | Change |
|------|--------|
| `auth_functions.php` | Remove `$_GET` fallback from `get_user()` and `get_pass()` |
| `csrf.php` | **New** — `csrf_token()`, `csrf_field()`, `csrf_verify()` helpers |
| `del_session.php` | POST-only; add CSRF verify |
| `session.php` | CSRF field on delete form; XSS-fix profile option output |
| `merge_sessions.php` | POST form; CSRF; cross-month bug fix; quote_name/quote_value throughout; write-failure logging |
| `settings.php` | CSRF verify + field on all POST forms |
| `plot.php` | `json_encode()` for chart labels (XSS/JS-injection fix) |
| `upload_batch.php` | GPS column diagnostics in response; write-failure logging on CREATE TABLE + upsert |
| `get_session_gps.php` | Quote identifiers; return diagnostic header |
| `db_upgrade.php` | Add `gps_points` / `gps_valid_points` columns to `sessions` table |

---

## Task 1: Remove GET Credential Fallbacks

**Files:**
- Modify: `auth_functions.php`

The `get_user()` and `get_pass()` functions currently accept credentials from both `$_POST` and `$_GET`. GET parameters appear in server logs, browser history, and HTTP Referer headers. Since the login form already uses `method="post"`, the GET path is never used legitimately.

- [ ] **Step 1: Create feature branch**

```bash
git checkout -b fix/security-gps
```

- [ ] **Step 2: Edit `auth_functions.php`** — replace `get_user()` and `get_pass()`:

```php
function get_user(): string
{
    return isset($_POST["user"]) ? (string)$_POST["user"] : "";
}

function get_pass(): string
{
    return isset($_POST["pass"]) ? (string)$_POST["pass"] : "";
}
```

> `get_id()` is for the Torque app upload flow and must keep its `$_GET` fallback — the Torque app sends its ID as a GET param. Leave it unchanged.

- [ ] **Step 3: Lint**

```bash
php -l auth_functions.php
```
Expected: `No syntax errors detected`

- [ ] **Step 4: Manual test — login still works**

Open `http://localhost/session.php` in a browser. Enter valid credentials via the login form. Verify you reach the session page.

- [ ] **Step 5: Manual test — GET credentials rejected**

```bash
curl -s 'http://localhost/session.php?user=admin&pass=wrongpassword' | head -5
```
Expected: The login page HTML (not the session page). Credentials via GET must not authenticate.

- [ ] **Step 6: Commit**

```bash
git add auth_functions.php
git commit -m "fix: remove GET credential fallbacks from get_user() and get_pass()"
```

---

## Task 2: CSRF Token Helpers

**Files:**
- Create: `csrf.php`

A single file providing `csrf_token()`, `csrf_field()`, `csrf_verify()`. PHP session must be started before calling any of these — all guarded pages already start a session via `auth_user.php`.

- [ ] **Step 1: Create `csrf.php`**

```php
<?php
// CSRF protection helpers.
// Requires an active PHP session (started by auth_user.php before this is called).

function csrf_token(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrf_field(): string {
    return '<input type="hidden" name="csrf_token" value="'
        . htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') . '">';
}

/**
 * Verifies the CSRF token from $_POST['csrf_token'].
 * Terminates with HTTP 403 on failure.
 */
function csrf_verify(): void {
    $submitted = $_POST['csrf_token'] ?? '';
    if (empty($_SESSION['csrf_token'])
        || !hash_equals($_SESSION['csrf_token'], $submitted)) {
        http_response_code(403);
        die('Request validation failed. Please reload the page and try again.');
    }
}
?>
```

- [ ] **Step 2: Lint**

```bash
php -l csrf.php
```
Expected: `No syntax errors detected`

- [ ] **Step 3: Commit**

```bash
git add csrf.php
git commit -m "feat: add CSRF token helpers (csrf.php)"
```

---

## Task 3: CSRF on Destructive Actions + Fix GET Delete

**Files:**
- Modify: `del_session.php`
- Modify: `session.php` (delete form only)
- Modify: `settings.php`
- Modify: `merge_sessions.php`

### del_session.php

Currently accepts `deletesession` from `$_GET`, which means any link or image tag can trigger a deletion. Fix: POST-only with CSRF.

- [ ] **Step 1: Replace `del_session.php` entirely**

```php
<?php
// Included by session.php after auth_user.php and db.php are already loaded.
// Handles POST-only session deletion with CSRF verification.

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_POST['deletesession'])) {
    return; // nothing to do
}

require_once __DIR__ . '/csrf.php';
csrf_verify();

$deletesession = preg_replace('/\D/', '', $_POST['deletesession']);
if (empty($deletesession)) { return; }

$tableYear     = date('Y', intdiv((int)$deletesession, 1000));
$tableMonth    = date('m', intdiv((int)$deletesession, 1000));
$db_table_full = "{$db_table}_{$tableYear}_{$tableMonth}";

$r1 = mysqli_query($con, "DELETE FROM " . quote_name($db_table_full)
    . " WHERE session = " . quote_value($deletesession));
$r2 = mysqli_query($con, "DELETE FROM " . quote_name($db_sessions_table)
    . " WHERE session = " . quote_value($deletesession));

if (!$r1 || !$r2) {
    error_log('del_session: DELETE failed for session '
        . $deletesession . ': ' . mysqli_error($con));
}
?>
```

- [ ] **Step 2: Update the delete form in `session.php`**

Find this block (around line 999):
```php
<form method="post" action="session.php?deletesession=<?php echo $session_id; ?>" id="formdelete" data-session-name="<?php echo htmlspecialchars($seshdates[$session_id] ?? ''); ?>" style="display:none"></form>
```

Replace with:
```php
<form method="post" action="session.php" id="formdelete" data-session-name="<?php echo htmlspecialchars($seshdates[$session_id] ?? ''); ?>" style="display:none">
  <input type="hidden" name="deletesession" value="<?php echo (int)$session_id; ?>">
  <?php echo csrf_field(); ?>
</form>
```

Also add `require_once('./csrf.php');` near the top of `session.php`, after the other `require_once` lines (e.g., after `require_once('./auth_user.php');`).

- [ ] **Step 3: Add CSRF to `settings.php`**

At the top of `settings.php`, after `require_once('get_settings.php');`, add:
```php
require_once('csrf.php');
```

At the start of the `if ($_SERVER['REQUEST_METHOD'] === 'POST')` block (line 22), add as the very first line inside the block:
```php
  csrf_verify();
```

Add `<?php echo csrf_field(); ?>` inside **each** of the three credential forms and the main settings form. The three credential forms are small inline forms. Find each `<form method="post"` tag and add the CSRF field as the first element inside:

For the "Add user" form (around line 336):
```php
<form method="post">
  <?php echo csrf_field(); ?>
  <div class="mb-2">
```

For the "Change password" form (around line 350):
```php
<form method="post">
  <?php echo csrf_field(); ?>
  <div class="mb-2">
```

For the delete-user form (around line 323), it's a per-user inline form inside the loop:
```php
<form method="post" class="mb-0" onsubmit="return confirm('Remove user <?php echo htmlspecialchars($dbu['username']); ?>?')">
  <?php echo csrf_field(); ?>
  <input type="hidden" name="del_username" ...>
```

For the main `<form method="post" id="settingsForm">` (around line 370), add after the opening tag:
```php
<form method="post" id="settingsForm">
  <?php echo csrf_field(); ?>
```

- [ ] **Step 4: Add CSRF to `merge_sessions.php`**

At the top of `merge_sessions.php` (after `require_once("./get_sessions.php");`), add:
```php
require_once('./csrf.php');
```

Change the form from GET to POST. Find:
```php
<form action="merge_sessions.php" method="get" id="formmerge">
  <input type="hidden" name="mergesession" value="<?php echo htmlspecialchars($mergesession, ENT_QUOTES, 'UTF-8'); ?>" />
```
Replace with:
```php
<form action="merge_sessions.php" method="post" id="formmerge">
  <?php echo csrf_field(); ?>
  <input type="hidden" name="mergesession" value="<?php echo htmlspecialchars($mergesession, ENT_QUOTES, 'UTF-8'); ?>" />
```

Update the merge processing block at the top to read from `$_POST` instead of `$_GET`. Find:
```php
if (isset($_POST["mergesession"])) {
    $mergesession = preg_replace('/\D/', '', $_POST['mergesession']);
}
elseif (isset($_GET["mergesession"])) {
    $mergesession = preg_replace('/\D/', '', $_GET['mergesession']);
}
```
Replace with:
```php
if (isset($_POST["mergesession"])) {
    $mergesession = preg_replace('/\D/', '', $_POST['mergesession']);
}
```

Update the session IDs loop from `$_GET` to `$_POST`. Find:
```php
foreach ($_GET as $key => $value) {
    if ($key != "mergesession") {
        ${'mergesess' . $i} = $key;
        array_push($sessionids, $key);
        $i = $i + 1;
    } else {
        array_push($sessionids, $value);
    }
}
```
Replace with:
```php
foreach ($_POST as $key => $value) {
    if ($key === 'mergesession' || $key === 'csrf_token') continue;
    if (preg_match('/^\d{10,15}$/', $key)) {
        ${'mergesess' . $i} = $key;
        array_push($sessionids, $key);
        $i = $i + 1;
    }
}
array_push($sessionids, $mergesession);
```

Add `csrf_verify()` at the start of the merge-processing block. Find:
```php
if (isset($mergesession) && !empty($mergesession) && isset($mergesess1) && !empty($mergesess1) ) {
```
Add before it:
```php
if (isset($mergesession) && !empty($mergesession) && isset($mergesess1) && !empty($mergesess1)) {
    csrf_verify();
```

- [ ] **Step 5: Lint all changed files**

```bash
php -l del_session.php && php -l session.php && php -l settings.php && php -l merge_sessions.php
```
Expected: all `No syntax errors detected`

- [ ] **Step 6: Manual test — settings save**

Open settings page in browser. Change a setting (e.g., min session size), save. Verify the change persists.

- [ ] **Step 7: Manual test — CSRF token rejected on tampered POST**

```bash
curl -s -X POST http://localhost/settings.php \
  -b "PHPSESSID=<your_session_id>" \
  -d "save_settings=1&min_session_size=99&csrf_token=invalid"
```
Expected: response body contains `Request validation failed`.

- [ ] **Step 8: Manual test — delete via GET rejected**

```bash
curl -s 'http://localhost/session.php?deletesession=1234567890123' \
  -b "PHPSESSID=<your_session_id>"
```
Expected: page renders normally; session is NOT deleted (confirm by checking it still appears in the session list).

- [ ] **Step 9: Commit**

```bash
git add del_session.php session.php settings.php merge_sessions.php csrf.php
git commit -m "feat: CSRF protection on settings, delete, and merge actions; POST-only delete"
```

---

## Task 4: Fix XSS in Profile Dropdown and Chart Labels

**Files:**
- Modify: `session.php` (line ~949 — profile `<option>` output)
- Modify: `plot.php` (line 104 — chart label construction)

### session.php — Profile dropdown

- [ ] **Step 1: Fix `session.php` line ~949**

Find:
```php
            <option value="<?php echo $profilearray[$i]; ?>"<?php if ($filterprofile == $profilearray[$i]) echo ' selected'; ?>><?php echo $profilearray[$i]; ?></option>
```
Replace with:
```php
            <option value="<?php echo htmlspecialchars($profilearray[$i], ENT_QUOTES, 'UTF-8'); ?>"<?php if ($filterprofile == $profilearray[$i]) echo ' selected'; ?>><?php echo htmlspecialchars($profilearray[$i], ENT_QUOTES, 'UTF-8'); ?></option>
```

### plot.php — Chart labels

`$plotLabel[$i]` is built as `'"' . $description . $measurand . '"'`. If the description or measurand contains `"`, it produces broken JS (`label: "Engine "RPM""`). `json_encode()` correctly escapes all special characters.

- [ ] **Step 2: Fix `plot.php` line 104**

Find:
```php
        $plotLabel[$i]     = '"' . $keyarr[$kcode][0] . $plotMeasurand[$i] . '"';
```
Replace with:
```php
        $plotLabel[$i]     = json_encode($keyarr[$kcode][0] . $plotMeasurand[$i]);
```

> `json_encode()` produces a quoted, properly escaped JS string literal (e.g. `"Engine RPM (rpm)"`), so the `label: <?php echo $plotLabel[$i]; ?>` in session.php still works as valid JS.

- [ ] **Step 3: Lint**

```bash
php -l session.php && php -l plot.php
```
Expected: `No syntax errors detected`

- [ ] **Step 4: Manual test — chart labels still render**

Load a session with plotted variables. Open browser devtools → Console. Confirm no JS errors. Confirm chart legend shows the correct label text.

- [ ] **Step 5: Commit**

```bash
git add session.php plot.php
git commit -m "fix: htmlspecialchars profile options, json_encode chart labels (XSS/JS-injection)"
```

---

## Task 5: Fix Session Merge Cross-Month Bug + Write Failure Logging

**Files:**
- Modify: `merge_sessions.php`

The merge code uses `$db_table_full` derived from the **primary** session's month for all UPDATE statements. If the merged-in sessions are from a different month, their rows are in a different table and the UPDATE finds zero rows — leaving orphaned data.

Also, all `mysqli_query` calls are unchecked.

- [ ] **Step 1: Replace the merge processing block in `merge_sessions.php`**

Find the block starting at `if (isset($mergesession) && !empty($mergesession) && isset($mergesess1)` and ending before the `} elseif (isset($mergesession)` branch. Replace the inner processing:

```php
if (isset($mergesession) && !empty($mergesession) && isset($mergesess1) && !empty($mergesess1)) {
    csrf_verify();

    $mergesession_int = (int)$mergesession;

    // Aggregate time bounds and size across all selected sessions
    $qrystr = "SELECT MIN(timestart) AS timestart, MAX(timeend) AS timeend,
                      MIN(session) AS session, SUM(sessionsize) AS sessionsize
               FROM " . quote_name($db_sessions_table) .
              " WHERE session = " . quote_value($mergesession_int);
    $i = 1;
    while (isset(${'mergesess' . $i}) && !empty(${'mergesess' . $i})) {
        $qrystr .= " OR session = " . quote_value((int)${'mergesess' . $i});
        $i++;
    }

    $mergeqry = mysqli_query($con, $qrystr);
    if (!$mergeqry) {
        error_log('merge_sessions: aggregate query failed: ' . mysqli_error($con));
        die('Merge failed — check server log.');
    }
    $mergerow      = mysqli_fetch_assoc($mergeqry);
    $newsession    = $mergerow['session'];
    $newtimestart  = $mergerow['timestart'];
    $newtimeend    = $mergerow['timeend'];
    $newsessionsize = $mergerow['sessionsize'];
    mysqli_free_result($mergeqry);

    foreach ($sessionids as $value) {
        $value_int = (int)$value;
        if ($value_int === (int)$newsession) {
            // Primary session — update its metadata to cover the full merged range
            $r = mysqli_query($con,
                "UPDATE " . quote_name($db_sessions_table) .
                " SET timestart = " . quote_value($newtimestart) .
                ", timeend = "      . quote_value($newtimeend) .
                ", sessionsize = "  . quote_value($newsessionsize) .
                " WHERE session = " . quote_value($newsession));
            if (!$r) {
                error_log('merge_sessions: UPDATE sessions failed: ' . mysqli_error($con));
            }
        } else {
            // Non-primary session — delete its metadata entry
            $r = mysqli_query($con,
                "DELETE FROM " . quote_name($db_sessions_table) .
                " WHERE session = " . quote_value($value_int));
            if (!$r) {
                error_log('merge_sessions: DELETE sessions failed for '
                    . $value_int . ': ' . mysqli_error($con));
            }

            // Update raw data rows — use THIS session's own monthly table, not the primary's
            $val_year  = date('Y', intdiv($value_int, 1000));
            $val_month = date('m', intdiv($value_int, 1000));
            $val_table = "{$db_table}_{$val_year}_{$val_month}";
            $r = mysqli_query($con,
                "UPDATE " . quote_name($val_table) .
                " SET session = " . quote_value($newsession) .
                " WHERE session = " . quote_value($value_int));
            if (!$r) {
                error_log('merge_sessions: UPDATE raw_logs failed for session '
                    . $value_int . ' in ' . $val_table . ': ' . mysqli_error($con));
            }
        }
    }

    header('Location: session.php?id=' . (int)$newsession);
    exit;
```

Also fix the sessions list query (line ~129) to use helper functions:
Find:
```php
    $sessqry = mysqli_query($con, "SELECT timestart, timeend, session, profileName, sessionsize FROM $db_sessions_table WHERE sessionsize >= $min_session_size ORDER BY session desc") ;
```
Replace with:
```php
    $sessqry = mysqli_query($con,
        "SELECT timestart, timeend, session, profileName, sessionsize FROM " .
        quote_name($db_sessions_table) .
        " WHERE sessionsize >= " . quote_value((int)$min_session_size) .
        " ORDER BY session DESC");
```

- [ ] **Step 2: Lint**

```bash
php -l merge_sessions.php
```
Expected: `No syntax errors detected`

- [ ] **Step 3: Manual test — same-month merge**

Select two sessions from the same month in the merge UI. Merge them. Verify the merged session appears in the session list with the correct start/end times and combined data point count.

- [ ] **Step 4: Manual test — cross-month merge (if sessions exist in different months)**

Check the DB for sessions in different months:
```bash
# In MariaDB
SELECT session, FROM_UNIXTIME(session/1000) FROM sessions ORDER BY session DESC LIMIT 10;
```
If two sessions exist in different months, merge them. Verify the merged session shows data from both months in the chart.

- [ ] **Step 5: Commit**

```bash
git add merge_sessions.php
git commit -m "fix: merge cross-month sessions correctly; use quote_name/value; log write failures"
```

---

## Task 6: Stop Swallowing Write Failures in upload_batch.php

**Files:**
- Modify: `upload_batch.php`

Several `mysqli_query` calls (CREATE TABLE, upsert) have no error handling. Silent failures cause the upload to return `OK!` with data actually not stored.

- [ ] **Step 1: Add error check on CREATE TABLE (around line 42)**

Find:
```php
    mysqli_query($con,
        "CREATE TABLE " . quote_name($db_table_full) .
        " SELECT * FROM " . quote_name($newest_table) . " WHERE 1=0");
```
Replace with:
```php
    $r = mysqli_query($con,
        "CREATE TABLE " . quote_name($db_table_full) .
        " SELECT * FROM " . quote_name($newest_table) . " WHERE 1=0");
    if (!$r) {
        error_log('upload_batch: CREATE TABLE failed for ' . $db_table_full
            . ': ' . mysqli_error($con));
        echo "ERROR. Could not create target table — check server log.";
        exit;
    }
```

- [ ] **Step 2: Add error check on the sessions UPDATE (around line 223)**

Find the `mysqli_query($con, "UPDATE " . quote_name($db_sessions_table)` inside the `if ($sess_check && ...)` branch. Replace:
```php
    mysqli_query($con,
        "UPDATE " . quote_name($db_sessions_table) .
        " SET " . implode(', ', $update_fields) .
        " WHERE session = " . quote_value($session_id));
```
With:
```php
    $r = mysqli_query($con,
        "UPDATE " . quote_name($db_sessions_table) .
        " SET " . implode(', ', $update_fields) .
        " WHERE session = " . quote_value($session_id));
    if (!$r) {
        error_log('upload_batch: session UPDATE failed: ' . mysqli_error($con));
    }
```

- [ ] **Step 3: Add error check on the sessions INSERT (around line 228)**

Find:
```php
    mysqli_query($con,
        "INSERT INTO " . quote_name($db_sessions_table) . ...
```
Replace:
```php
    $r = mysqli_query($con,
        "INSERT INTO " . quote_name($db_sessions_table) .
        " (session, timestart, timeend, sessionsize, profileName, id, v) VALUES (" .
        quote_value($session_id) . ", " .
        quote_value($time_start) . ", " .
        quote_value($time_end)   . ", " .
        quote_value($row_count)  . ", " .
        quote_value($profile_name) . ", " .
        "'plugin_upload', " .
        quote_value($plugin_version) . ")");
    if (!$r) {
        error_log('upload_batch: session INSERT failed: ' . mysqli_error($con));
    }
```

- [ ] **Step 4: Lint**

```bash
php -l upload_batch.php
```
Expected: `No syntax errors detected`

- [ ] **Step 5: Manual test — normal upload still returns OK**

Use the Torque plugin or a test curl to do a normal upload. Verify `OK! N rows inserted` response.

- [ ] **Step 6: Commit**

```bash
git add upload_batch.php
git commit -m "fix: check and log write failures in upload_batch.php (CREATE TABLE, session upsert)"
```

---

## Task 7: GPS Upload Diagnostics in upload_batch.php

**Files:**
- Modify: `upload_batch.php`

After mapping CSV headers, check whether the GPS columns (kff1005 = longitude, kff1006 = latitude) mapped to any column in the uploaded file. Include this in the `OK!` response so the caller can detect silent GPS drops.

- [ ] **Step 1: Add GPS column detection after the `$col_map` loop (around line 108)**

Find:
```php
// Collect all unique k-codes that will be written
$all_kcodes = array_values(array_filter(array_unique($col_map)));
```

Insert after it:
```php
// ── GPS column presence check ─────────────────────────────────────────────
$gps_lat_mapped = in_array('kff1006', $col_map, true); // latitude
$gps_lon_mapped = in_array('kff1005', $col_map, true); // longitude
if (!$gps_lat_mapped || !$gps_lon_mapped) {
    $missing = [];
    if (!$gps_lon_mapped) $missing[] = 'kff1005 (GPS Longitude)';
    if (!$gps_lat_mapped) $missing[] = 'kff1006 (GPS Latitude)';
    error_log('upload_batch: GPS columns not found in CSV headers for session '
        . $session_id . ': ' . implode(', ', $missing));
}
```

- [ ] **Step 2: Include GPS mapping status in the final `OK!` response (around line 241)**

Find:
```php
echo "OK! $row_count rows inserted for session $session_id";
```
Replace with:
```php
$gps_status = ($gps_lat_mapped && $gps_lon_mapped)
    ? 'GPS mapped'
    : 'GPS MISSING from CSV headers (' . implode(', ', array_filter([
            !$gps_lon_mapped ? 'kff1005' : null,
            !$gps_lat_mapped ? 'kff1006' : null,
        ])) . ')';
echo "OK! $row_count rows inserted for session $session_id | $gps_status";
```

- [ ] **Step 3: Lint**

```bash
php -l upload_batch.php
```
Expected: `No syntax errors detected`

- [ ] **Step 4: Manual test — upload with GPS**

Upload a CSV that contains `GPS Longitude(°)` and `GPS Latitude(°)` columns. Verify response ends with `| GPS mapped`.

- [ ] **Step 5: Manual test — upload without GPS**

Upload a CSV that lacks GPS columns (or rename them temporarily). Verify response ends with `| GPS MISSING from CSV headers (kff1005, kff1006)`.

- [ ] **Step 6: Commit**

```bash
git add upload_batch.php
git commit -m "feat: GPS column detection in upload_batch.php — report mapping status in response"
```

---

## Task 8: GPS Quality Columns in sessions Table + Display Diagnostics

**Files:**
- Modify: `db_upgrade.php` (schema migration)
- Modify: `upload_batch.php` (populate GPS counts during CSV parse)
- Modify: `session.php` (use GPS counts for diagnostic message)

This task adds `gps_points` (total GPS rows with non-zero coordinates) and `gps_valid_points` (rows passing range validation) to the `sessions` table, populates them on batch upload, and uses them to show a specific diagnostic message instead of generic "No GPS data."

### db_upgrade.php migration

- [ ] **Step 1: Add GPS quality columns migration to `db_upgrade.php`**

After the `csv_header` column block (after line ~79), insert:

```php
  // ── v2.3 GPS Quality Columns (2026-05-23) ────────────────────────────────
  // gps_points:       rows uploaded with non-zero lat/lon
  // gps_valid_points: rows that also pass range validation (-90<lat<90, -180<lon<180)
  $gps_pts_check = mysqli_query($con,
      "SHOW COLUMNS FROM " . quote_name($db_sessions_table) . " LIKE 'gps_points'");
  if (mysqli_num_rows($gps_pts_check) == 0) {
      mysqli_query($con,
          "ALTER TABLE " . quote_name($db_sessions_table) .
          " ADD COLUMN gps_points INT NOT NULL DEFAULT 0");
  }
  $gps_valid_check = mysqli_query($con,
      "SHOW COLUMNS FROM " . quote_name($db_sessions_table) . " LIKE 'gps_valid_points'");
  if (mysqli_num_rows($gps_valid_check) == 0) {
      mysqli_query($con,
          "ALTER TABLE " . quote_name($db_sessions_table) .
          " ADD COLUMN gps_valid_points INT NOT NULL DEFAULT 0");
  }
```

- [ ] **Step 2: Run migration**

Open `http://localhost/db_upgrade.php` in a browser while logged in. Verify page loads without errors.

Then confirm columns exist:
```bash
# In MariaDB
DESCRIBE sessions;
```
Expected: `gps_points` and `gps_valid_points` columns present with `int(11) DEFAULT 0`.

### upload_batch.php — count GPS rows

- [ ] **Step 3: Initialise GPS counters alongside `$row_count`**

Find (around line 164):
```php
$batch     = [];
$row_count = 0;
```
Replace with:
```php
$batch            = [];
$row_count        = 0;
$gps_points       = 0;  // rows where kff1005 or kff1006 is non-zero
$gps_valid_points = 0;  // rows that also pass range validation
```

- [ ] **Step 4: Increment GPS counters inside the CSV row parse loop**

After `$batch[] = ['time' => $time_ms, ...]` and `$row_count++` (around line 193–194), add:

```php
    // GPS quality counting — use already-parsed $kvals (keyed by k-code)
    $gps_lon = isset($kvals['kff1005']) ? (float)$kvals['kff1005'] : 0.0;
    $gps_lat = isset($kvals['kff1006']) ? (float)$kvals['kff1006'] : 0.0;
    if ($gps_lon != 0.0 || $gps_lat != 0.0) {
        $gps_points++;
        if ($gps_lat >= -90  && $gps_lat <= 90
         && $gps_lon >= -180 && $gps_lon <= 180) {
            $gps_valid_points++;
        }
    }
```

- [ ] **Step 5: Include GPS counts in the sessions upsert**

In the `$update_fields` array (around line 215), add the GPS count fields:

```php
    $update_fields = [
        "timestart = "        . quote_value($time_start),
        "timeend = "          . quote_value($time_end),
        "sessionsize = "      . quote_value($row_count),
        "gps_points = "       . quote_value($gps_points),
        "gps_valid_points = " . quote_value($gps_valid_points),
    ];
```

In the INSERT branch (around line 228), add `gps_points, gps_valid_points` to the column list and values:

```php
    $r = mysqli_query($con,
        "INSERT INTO " . quote_name($db_sessions_table) .
        " (session, timestart, timeend, sessionsize, profileName, id, v, gps_points, gps_valid_points)" .
        " VALUES (" .
        quote_value($session_id)    . ", " .
        quote_value($time_start)    . ", " .
        quote_value($time_end)      . ", " .
        quote_value($row_count)     . ", " .
        quote_value($profile_name)  . ", " .
        "'plugin_upload', "                .
        quote_value($plugin_version). ", " .
        quote_value($gps_points)    . ", " .
        quote_value($gps_valid_points) . ")");
    if (!$r) {
        error_log('upload_batch: session INSERT failed: ' . mysqli_error($con));
    }
```

### session.php — GPS diagnostic message

Currently session.php sets `$mapHasGPS = (count($mapdata) > 0)` and the UI shows a generic "no GPS" message when false.

- [ ] **Step 6: Read GPS quality columns from the sessions query in session.php**

Find the sessions query in `session.php` (around line 37 where `$db_sessions_table` is queried to populate session metadata — look for where `$seshdates` is populated in `get_sessions.php`). 

In `get_sessions.php` (which already queries sessions), or directly in `session.php` after the GPS query (around line 83 where `$mapHasGPS` is set), add a diagnostic variable:

After the `$mapHasGPS = (count($mapdata) > 0);` line (around line 83), add:

```php
  // GPS diagnostic: distinguish no data / invalid data / valid data
  // Uses stored counts from sessions table if available; falls back to a count query.
  $gpsQuality = 'none'; // 'none' | 'invalid' | 'good'
  if ($mapHasGPS) {
      $gpsQuality = 'good';
  } else {
      // Check whether GPS was uploaded at all by querying the sessions table
      $_gq = mysqli_query($con,
          "SELECT gps_points, gps_valid_points FROM " . quote_name($db_sessions_table) .
          " WHERE session = " . quote_value($session_id) . " LIMIT 1");
      if ($_gq && $_gqr = mysqli_fetch_assoc($_gq)) {
          if ((int)$_gqr['gps_points'] > 0) {
              // GPS data was uploaded but all coordinates are out-of-range or 0,0
              $gpsQuality = 'invalid';
          }
          // else: gps_points == 0 → genuinely no GPS data recorded
      }
      // For old sessions where gps_points is 0 by default, fall back to a raw count
      if ($gpsQuality === 'none') {
          $_raw_gps = mysqli_query($con,
              "SELECT COUNT(*) AS cnt FROM " . quote_name($db_table_full) .
              " WHERE session = " . quote_value($session_id) .
              " AND (kff1005 != 0 OR kff1006 != 0) LIMIT 1");
          if ($_raw_gps && $_raw_row = mysqli_fetch_assoc($_raw_gps)) {
              if ((int)$_raw_row['cnt'] > 0) {
                  $gpsQuality = 'invalid';
              }
          }
      }
  }
```

- [ ] **Step 7: Pass `$gpsQuality` to JavaScript and update the "no GPS" message**

The "No GPS data" message is injected by JavaScript (not PHP HTML) in the Mapbox init block. It uses `_hasGPS` (a PHP-injected JS variable at around line 186):

```js
var _hasGPS = <?php echo (!$setZoomManually && $mapHasGPS) ? 'true' : 'false'; ?>;
```

Add `_gpsQuality` on the line immediately after that:

```php
      var _hasGPS     = <?php echo (!$setZoomManually && $mapHasGPS) ? 'true' : 'false'; ?>;
      var _gpsQuality = '<?php echo htmlspecialchars($gpsQuality ?? 'none', ENT_QUOTES, 'UTF-8'); ?>';
```

Then update the `if (!_hasGPS)` block (around line 261–270) to show a specific message:

Find:
```js
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
```

Replace with:

```js
        if (!_hasGPS) {
          var noGpsDiv = document.createElement('div');
          noGpsDiv.style.cssText =
            'position:absolute;top:50%;left:50%;transform:translate(-50%,-50%);z-index:10;' +
            'background:rgba(6,9,18,0.88);padding:12px 18px;border-radius:8px;' +
            'border:1px solid rgba(0,212,255,0.2);color:#8ab;' +
            'font-size:13px;text-align:center;box-shadow:0 0 24px rgba(0,212,255,0.06),0 4px 20px rgba(0,0,0,0.6);pointer-events:none;';
          var _noGpsMsg = _gpsQuality === 'invalid'
            ? 'GPS data recorded but all coordinates are invalid (out of range or 0°,0°)'
            : 'No GPS data recorded for this session';
          noGpsDiv.innerHTML = '<i class="bi bi-geo-alt-fill" style="font-size:1.5rem;color:#00d4ff;display:block;margin-bottom:4px;"></i>' + _noGpsMsg;
          mapEl.appendChild(noGpsDiv);
          return;
        }
```

- [ ] **Step 8: Lint all changed files**

```bash
php -l db_upgrade.php && php -l upload_batch.php && php -l session.php
```
Expected: all `No syntax errors detected`

- [ ] **Step 9: Run db_upgrade.php**

Open `http://localhost/db_upgrade.php` in a browser. Confirm no errors.

- [ ] **Step 10: Manual test — upload with GPS, verify counts stored**

Upload a CSV with GPS columns via the batch upload endpoint. Check the sessions row in the DB:
```bash
# In MariaDB
SELECT session, gps_points, gps_valid_points FROM sessions ORDER BY session DESC LIMIT 3;
```
Expected: `gps_points` > 0, `gps_valid_points` > 0 for the uploaded session.

- [ ] **Step 11: Manual test — session with invalid GPS shows correct message**

Load a session that has GPS data in the DB but all coordinates are 0 or out of range. Verify the map area shows "GPS data was recorded but all coordinates are invalid" rather than the generic "No GPS data recorded."

- [ ] **Step 12: Manual test — session with no GPS shows correct message**

Load a session that genuinely has no GPS columns recorded. Verify "No GPS data recorded for this session."

- [ ] **Step 13: Commit**

```bash
git add db_upgrade.php upload_batch.php session.php
git commit -m "feat: GPS quality columns in sessions table; upload counts GPS rows; UI shows specific diagnostic"
```

---

## Final: Validate + Merge to Main

- [ ] **Full smoke test on staging/local Docker**

```bash
docker run --rm -p 8080:80 \
  -e DB_HOST=host.docker.internal \
  -e DB_USER=torque \
  -e DB_PASS=torque \
  -e DB_NAME=torque \
  leorx/open-torque-viewer:latest
```

Open `http://localhost:8080/session.php`:
1. Login works
2. Sessions list loads
3. Select a session → chart and map render
4. Delete a session (confirm JS prompt, verify deletion)
5. Merge two sessions → redirects to merged session
6. Settings save → changes persist
7. Upload a batch CSV via plugin endpoint → `OK!` response with GPS status
8. Run `db_upgrade.php` → no errors

- [ ] **PHP lint all modified files**

```bash
php -l auth_functions.php csrf.php del_session.php session.php \
    merge_sessions.php settings.php plot.php upload_batch.php \
    get_session_gps.php db_upgrade.php
```
Expected: all pass.

- [ ] **Merge to main**

```bash
git checkout main
git merge --no-ff fix/security-gps -m "feat: security hardening + GPS diagnostics"
git push origin main
```
