<?php

require_once ("./db.php");
require_once ("./auth_user.php");

// Fetch all k* keys ordered by description
$keyqry = mysqli_query($con, "SELECT id,description,units,type,min,max,populated,favorite FROM ".$db_name.".".$db_keys_table." ORDER BY description") ;
$i = 0;
while ($x = mysqli_fetch_array($keyqry)) {
	if ((substr($x[0], 0, 1) == "k") ) {
		$keydata[$i] = array("id"=>$x[0], "description"=>$x[1], "units"=>$x[2], "type"=>$x[3], "min"=>$x[4], "max"=>$x[5], "populated"=>$x[6], "favorite"=>$x[7]);
		$i = $i + 1;
	}
}
mysqli_free_result($keyqry);
mysqli_close($con);

?>
<!DOCTYPE html>
<html lang="en">
  <head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Open Torque Viewer – PID Editor</title>
    <meta name="description" content="Open Torque Viewer – PID Editor">
    <!-- Apply saved theme before render to avoid flash -->
    <script>(function(){var t=localStorage.getItem('torque-theme')||'light';document.documentElement.setAttribute('data-bs-theme',t);})();</script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.3/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="https://fonts.googleapis.com/css?family=Lato:400,700">
    <link rel="stylesheet" href="static/css/torque.css">
    <style>
      /* ── PID editor page: allow vertical scroll (overrides the map-page global) ── */
      html, body {
        overflow: auto !important;
        height: auto !important;
      }

      body {
        padding-top: 58px;   /* clear fixed navbar */
        padding-bottom: 2rem;
        background-color: var(--bs-body-bg);
        color: var(--bs-body-color);
      }

      /* ── Table wrapper: fills width, scrolls horizontally on small screens ── */
      .pid-table-wrap {
        overflow-x: auto;
        -webkit-overflow-scrolling: touch;
      }

      /* ── Sticky table header stays visible while scrolling down ── */
      .pid-table thead th {
        position: sticky;
        top: 0;
        z-index: 2;
        white-space: nowrap;
        background-color: var(--bs-tertiary-bg);
        border-bottom: 2px solid var(--bs-border-color);
      }

      /* ── Contenteditable cells ── */
      td[contenteditable="true"] {
        cursor: text;
        min-width: 80px;
        outline: none;
        border-radius: 3px;
        transition: background 0.15s, box-shadow 0.15s;
      }
      td[contenteditable="true"]:hover {
        background: rgba(13, 110, 253, 0.06);
      }
      td[contenteditable="true"]:focus {
        background: rgba(13, 110, 253, 0.1);
        box-shadow: inset 0 0 0 2px rgba(13, 110, 253, 0.35);
      }

      /* ── Dark mode: contenteditable cells ── */
      [data-bs-theme="dark"] td[contenteditable="true"]:hover {
        background: rgba(99, 130, 255, 0.12);
      }
      [data-bs-theme="dark"] td[contenteditable="true"]:focus {
        background: rgba(99, 130, 255, 0.18);
        box-shadow: inset 0 0 0 2px rgba(99, 130, 255, 0.4);
      }

      /* ── Dark mode: table header ── */
      [data-bs-theme="dark"] .pid-table thead th {
        background-color: #252535;
        color: #c9cfe4;
        border-color: rgba(255,255,255,0.08);
      }

      /* ── Dark mode: table rows ── */
      [data-bs-theme="dark"] .table-striped > tbody > tr:nth-of-type(odd) > * {
        --bs-table-accent-bg: rgba(255,255,255,0.035);
        color: #d0d0e0;
      }
      [data-bs-theme="dark"] .table-hover > tbody > tr:hover > * {
        --bs-table-accent-bg: rgba(99,130,255,0.1);
      }

      /* ── Dark mode: select dropdowns inside table ── */
      [data-bs-theme="dark"] .form-select {
        background-color: #2a2a3e;
        border-color: rgba(255,255,255,0.15);
        color: #d0d0e0;
      }
      [data-bs-theme="dark"] .form-select:focus {
        border-color: rgba(99, 130, 255, 0.6);
        box-shadow: 0 0 0 0.2rem rgba(99, 130, 255, 0.2);
      }

      /* ── Dark mode: code badges ── */
      [data-bs-theme="dark"] code {
        color: #7dd3fc;
        background: rgba(125, 211, 252, 0.08);
        padding: 1px 4px;
        border-radius: 3px;
      }

      /* ── Dark mode: card ── */
      [data-bs-theme="dark"] .card {
        background-color: #1e1e2e;
        border-color: rgba(255,255,255,0.1);
      }
      [data-bs-theme="dark"] .card-header {
        background-color: #252535;
        border-color: rgba(255,255,255,0.08);
        color: #c9cfe4;
      }

      /* ── Fixed save-status toast (bottom-right) ── */
      #status-toast {
        position: fixed;
        bottom: 1.5rem;
        right: 1.5rem;
        z-index: 9999;
        min-width: 200px;
        display: none;
        border-radius: 8px;
        box-shadow: 0 4px 20px rgba(0,0,0,.2);
      }
    </style>
  </head>
  <body>

    <!-- ── Navbar ── -->
    <nav class="navbar navbar-dark bg-dark fixed-top">
      <div class="container-fluid gap-2">
        <a class="navbar-brand" href="session.php">
          <i class="bi bi-speedometer2 me-2"></i>Open Torque Viewer
        </a>
        <div class="ms-auto d-flex align-items-center gap-2">
          <span class="text-white-50 small d-none d-sm-inline">
            <?php echo count($keydata ?? []); ?> PIDs
          </span>
          <!-- Dark mode toggle -->
          <button id="darkModeBtn" class="btn btn-sm btn-outline-light" title="Toggle dark mode" onclick="
            var cur = document.documentElement.getAttribute('data-bs-theme');
            var next = cur === 'dark' ? 'light' : 'dark';
            document.documentElement.setAttribute('data-bs-theme', next);
            localStorage.setItem('torque-theme', next);
            this.innerHTML = next === 'dark'
              ? '<i class=\'bi bi-sun\'></i>'
              : '<i class=\'bi bi-moon-stars\'></i>';
          ">
            <i id="dm-icon" class="bi bi-moon-stars"></i>
          </button>
          <a href="session.php" class="btn btn-sm btn-outline-light">
            <i class="bi bi-arrow-left me-1"></i>Back
          </a>
        </div>
      </div>
    </nav>

    <!-- ── Main content ── -->
    <div class="container-fluid py-3">
      <div class="card shadow-sm">
        <div class="card-header d-flex align-items-center justify-content-between">
          <h6 class="mb-0"><i class="bi bi-cpu me-2"></i>PID Editor</h6>
          <small class="text-muted">Click any cell to edit — changes save automatically</small>
        </div>
        <div class="card-body p-0">
          <div class="pid-table-wrap">
            <table class="table table-striped table-hover table-sm mb-0 pid-table">
              <thead>
                <tr>
                  <th>ID</th>
                  <th>Description</th>
                  <th>Units</th>
                  <th>Variable Type</th>
                  <th>Min</th>
                  <th>Max</th>
                  <th class="text-center">Visible</th>
                  <th class="text-center">Favourite</th>
                </tr>
              </thead>
              <tbody>
<?php foreach ($keydata as $keycol) { ?>
<?php $kid = htmlspecialchars($keycol['id'], ENT_QUOTES, 'UTF-8'); ?>
                <tr>
                  <td><code><?php echo $kid; ?></code></td>
                  <td id="description:<?php echo $kid; ?>" contenteditable="true"><?php echo htmlspecialchars($keycol['description'] ?? '', ENT_QUOTES, 'UTF-8'); ?></td>
                  <td id="units:<?php echo $kid; ?>" contenteditable="true"><?php echo htmlspecialchars($keycol['units'] ?? '', ENT_QUOTES, 'UTF-8'); ?></td>
                  <td>
                    <select id="type:<?php echo $kid; ?>" contenteditable="true" class="form-select form-select-sm">
                      <option value="double"<?php if ($keycol['type'] == "double") echo ' selected'; ?>>double</option>
                      <option value="float"<?php if ($keycol['type'] == "float") echo ' selected'; ?>>float</option>
                      <option value="varchar(255)"<?php if ($keycol['type'] == "varchar(255)") echo ' selected'; ?>>varchar(255)</option>
                    </select>
                  </td>
                  <td id="min:<?php echo $kid; ?>" contenteditable="true"><?php echo htmlspecialchars($keycol['min'] ?? '', ENT_QUOTES, 'UTF-8'); ?></td>
                  <td id="max:<?php echo $kid; ?>" contenteditable="true"><?php echo htmlspecialchars($keycol['max'] ?? '', ENT_QUOTES, 'UTF-8'); ?></td>
                  <td class="text-center">
                    <input type="checkbox" class="form-check-input" id="populated:<?php echo $kid; ?>" contenteditable="true"<?php if ($keycol['populated']) echo " checked"; ?>>
                  </td>
                  <td class="text-center">
                    <input type="checkbox" class="form-check-input" id="favorite:<?php echo $kid; ?>" contenteditable="true"<?php if ($keycol['favorite']) echo " checked"; ?>>
                  </td>
                </tr>
<?php } ?>
              </tbody>
            </table>
          </div>
        </div>
      </div>
    </div>

    <!-- ── Save-status toast ── -->
    <div id="status-toast" class="alert mb-0"></div>

    <script src="https://cdnjs.cloudflare.com/ajax/libs/jquery/3.7.1/jquery.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.3/js/bootstrap.bundle.min.js"></script>
    <script>
      // Set correct dark-mode icon on page load
      (function() {
        var saved = localStorage.getItem('torque-theme') || 'light';
        var btn = document.getElementById('darkModeBtn');
        if (btn) btn.innerHTML = saved === 'dark'
          ? '<i class="bi bi-sun"></i>'
          : '<i class="bi bi-moon-stars"></i>';
      })();

      $(function() {
        var $toast = $('#status-toast');
        var _timer = null;

        function showStatus(data, isError) {
          if (!data) return;
          $toast
            .removeClass('alert-info alert-success alert-danger')
            .addClass(isError ? 'alert-danger' : 'alert-success')
            .text(data)
            .stop(true).fadeIn(180);
          clearTimeout(_timer);
          _timer = setTimeout(function() { $toast.fadeOut(400); }, 2800);
        }

        // Contenteditable <td> — save on blur
        $('td[contenteditable=true]').on('blur', function() {
          var field_pid = $(this).attr('id');
          var value = $(this).text().trim();
          $.post('pid_commit.php', field_pid + '=' + encodeURIComponent(value))
            .done(function(data) { showStatus(data, data !== 'Updated'); })
            .fail(function()     { showStatus('Save failed', true); });
        });

        // Also save on Enter (move to next row's same column)
        $('td[contenteditable=true]').on('keydown', function(e) {
          if (e.key === 'Enter') {
            e.preventDefault();
            $(this).blur();
          }
        });

        // Checkbox inputs
        $('input[contenteditable=true]').on('change', function() {
          var field_pid = $(this).attr('id');
          var value = $(this).is(':checked');
          $.post('pid_commit.php', field_pid + '=' + value)
            .done(function(data) { showStatus(data, data !== 'Updated'); })
            .fail(function()     { showStatus('Save failed', true); });
        });

        // Select dropdowns
        $('select[contenteditable=true]').on('change', function() {
          var field_pid = $(this).attr('id');
          var value = $(this).val();
          $.post('pid_commit.php', field_pid + '=' + encodeURIComponent(value))
            .done(function(data) { showStatus(data, data !== 'Updated'); })
            .fail(function()     { showStatus('Save failed', true); });
        });
      });
    </script>
  </body>
</html>
