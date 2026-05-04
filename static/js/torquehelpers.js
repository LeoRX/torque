// Open Torque Viewer - Helpers (Bootstrap 5 + Tom Select + Peity)

// ── Calendar date-range picker ──
document.addEventListener('DOMContentLoaded', function() {
  'use strict';
  var calPanel = document.getElementById('cal-panel');
  var calBtn   = document.getElementById('btn-cal');
  if (!calPanel || !calBtn) return;

  var MONTHS = ['January','February','March','April','May','June',
                'July','August','September','October','November','December'];
  var SHORT_MONTHS = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];

  // Current view: left calendar shows viewYear/viewMonth, right shows +1 month
  var now = new Date();
  var viewYear  = now.getFullYear();
  var viewMonth = now.getMonth() - 1; // 0-based
  if (viewMonth < 0) { viewMonth = 11; viewYear--; }

  var selStart      = null; // Date (midnight local)
  var selEnd        = null; // Date (midnight local)
  var selectedSids  = [];   // currently checked session IDs

  var CAL_KEY = 'torque-cal-state';

  function saveCalState() {
    try {
      sessionStorage.setItem(CAL_KEY, JSON.stringify({
        ss: selStart ? selStart.getTime() : null,
        se: selEnd   ? selEnd.getTime()   : null,
        vy: viewYear,
        vm: viewMonth
      }));
    } catch(e) {}
  }

  function loadCalState() {
    try {
      var raw = sessionStorage.getItem(CAL_KEY);
      if (!raw) return false;
      var s = JSON.parse(raw);
      if (s.ss) selStart = dateOnly(new Date(s.ss));
      if (s.se) selEnd   = dateOnly(new Date(s.se));
      if (s.vy !== undefined) viewYear  = s.vy;
      if (s.vm !== undefined) viewMonth = s.vm;
      return !!(selStart && selEnd); // true = has a complete range to restore
    } catch(e) { return false; }
  }

  // Restore state from previous navigation immediately
  var _hasRestoredRange = loadCalState();

  function dateOnly(d) { var r = new Date(d); r.setHours(0,0,0,0); return r; }
  function sameDay(a, b) { return a && b && a.getTime() === b.getTime(); }
  function pad(n) { return n < 10 ? '0' + n : '' + n; }
  function dateKey(d) { return d.getFullYear() + '-' + pad(d.getMonth()+1) + '-' + pad(d.getDate()); }
  function fromKey(k) { var p = k.split('-'); return dateOnly(new Date(+p[0], +p[1]-1, +p[2])); }
  function escH(s) { return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }

  function getRangeOrdered() {
    if (!selStart || !selEnd) return { s: selStart, e: selEnd };
    return selStart <= selEnd ? { s: selStart, e: selEnd } : { s: selEnd, e: selStart };
  }

  function buildMonthSelect(selectedMonth) {
    var html = '<select class="torque-cal-sel" data-calsel="month">';
    MONTHS.forEach(function(m, i) {
      html += '<option value="' + i + '"' + (i === selectedMonth ? ' selected' : '') + '>' + SHORT_MONTHS[i] + '</option>';
    });
    return html + '</select>';
  }

  function buildYearSelect(selectedYear) {
    var curYear = new Date().getFullYear();
    var html = '<select class="torque-cal-sel" data-calsel="year">';
    for (var y = curYear; y >= 2015; y--) {
      html += '<option value="' + y + '"' + (y === selectedYear ? ' selected' : '') + '>' + y + '</option>';
    }
    return html + '</select>';
  }

  function renderCal(el, year, month, isLeft) {
    var today    = dateOnly(new Date());
    var firstDow = new Date(year, month, 1).getDay();
    var lastDay  = new Date(year, month+1, 0).getDate();
    var range    = getRangeOrdered();

    var html = '<div class="torque-cal">';
    html += '<div class="torque-cal-header">';

    if (isLeft) {
      // Left: prev-arrow | month select | year select
      html += '<button class="torque-cal-nav" data-dir="-1">&#8249;</button>';
      html += '<div class="d-flex gap-1 align-items-center">' + buildMonthSelect(month) + buildYearSelect(year) + '</div>';
      html += '<div style="width:30px"></div>';
    } else {
      // Right: spacer | month name + year text | next-arrow
      html += '<div style="width:30px"></div>';
      html += '<strong style="font-size:.9rem">' + MONTHS[month] + ' ' + year + '</strong>';
      html += '<button class="torque-cal-nav" data-dir="1">&#8250;</button>';
    }
    html += '</div>';

    html += '<div class="torque-cal-grid">';
    ['Su','Mo','Tu','We','Th','Fr','Sa'].forEach(function(d) {
      html += '<div class="cal-day-name">' + d + '</div>';
    });
    for (var e = 0; e < firstDow; e++) html += '<div class="cal-day cal-empty"></div>';
    for (var d = 1; d <= lastDay; d++) {
      var td = dateOnly(new Date(year, month, d));
      var cls = 'cal-day';
      if (td > today)                               cls += ' cal-disabled';
      if (sameDay(td, today))                       cls += ' cal-today';
      if (range.s && sameDay(td, range.s))          cls += ' cal-start';
      else if (range.e && sameDay(td, range.e))     cls += ' cal-end';
      else if (range.s && range.e && td > range.s && td < range.e) cls += ' cal-in-range';
      var key = dateKey(td);
      // Pass event so we can stopPropagation before the calendar re-renders (prevents auto-close)
      var click = (td > today) ? '' : ' onclick="window._torqueCal.clickDay(event,\'' + key + '\')"';
      html += '<div class="' + cls + '"' + click + '>' + d + '</div>';
    }
    html += '</div></div>';
    el.innerHTML = html;
  }

  function renderBoth() {
    var lEl = document.getElementById('cal-left');
    var rEl = document.getElementById('cal-right');
    var ry = viewYear, rm = viewMonth + 1;
    if (rm > 11) { rm = 0; ry++; }
    if (lEl) renderCal(lEl, viewYear, viewMonth, true);
    if (rEl) renderCal(rEl, ry, rm, false);

    var hint = document.getElementById('cal-hint');
    if (hint) {
      if (!selStart)    hint.textContent = 'Click a start date.';
      else if (!selEnd) hint.textContent = 'Now click an end date (or the same day for a single-day search).';
      else              hint.textContent = '';
    }
  }

  function fetchSessions() {
    var range   = getRangeOrdered();
    var sessDiv = document.getElementById('cal-sessions');
    if (!sessDiv || !range.s || !range.e) return;

    selectedSids = [];
    var startTs = Math.floor(range.s.getTime() / 1000);
    var endTs   = Math.floor(range.e.getTime() / 1000);
    sessDiv.innerHTML = '<p class="text-center text-muted small py-2 mb-0"><i class="bi bi-hourglass-split me-1"></i>Loading sessions\u2026</p>';

    fetch('get_sessions_ajax.php?start=' + startTs + '&end=' + endTs)
      .then(function(r) { return r.json(); })
      .then(function(list) {
        if (!list.length) {
          sessDiv.innerHTML = '<p class="text-center text-muted small py-1 mb-0">No sessions found in this date range.</p>';
          return;
        }
        // Sort latest first regardless of server order
        list.sort(function(a, b) { return parseInt(b.id, 10) - parseInt(a.id, 10); });
        var n = list.length;
        var html = '<div class="cal-select-all-bar">';
        html += '<label class="cal-select-all-label">';
        html += '<input type="checkbox" id="cal-select-all" class="cal-select-all-cb">';
        html += '<span id="cal-select-all-text">Select all (' + n + ')</span>';
        html += '</label>';
        html += '</div>';
        html += '<div class="cal-sessions-list">';
        list.forEach(function(sess) {
          html += '<label class="cal-session-item" data-sid="' + escH(sess.id) + '">';
          html += '<input type="checkbox" class="cal-sess-cb flex-shrink-0" value="' + escH(sess.id) + '">';
          html += '<div class="flex-grow-1">';
          html += '<div style="font-size:.85rem;font-weight:600">' + escH(sess.label) + '</div>';
          html += '<div class="text-muted" style="font-size:.75rem">' + escH(sess.duration) + ' &bull; ' + escH(sess.profile) + '</div>';
          html += '</div>';
          // Color dot — shows track color on map when selected (hidden until checked)
          html += '<span class="cal-sess-color" style="display:none;width:10px;height:10px;border-radius:50%;flex-shrink:0;margin-left:6px;"></span>';
          html += '</label>';
        });
        html += '</div>';
        // Action bar — appears once sessions are checked
        html += '<div class="cal-action-bar" id="cal-action-bar" style="display:none">';
        html += '<span id="cal-sel-count" class="small text-muted"></span>';
        html += '<button id="cal-open-btn" class="btn btn-primary btn-sm" onclick="window._torqueCal.openSelected()">';
        html += '<i class="bi bi-arrow-right-circle me-1"></i>Open Session</button>';
        html += '</div>';
        sessDiv.innerHTML = html;
      })
      .catch(function() {
        sessDiv.innerHTML = '<p class="text-center text-danger small py-1 mb-0">Error loading sessions.</p>';
      });
  }

  window._torqueCal = {
    clickDay: function(e, key) {
      e.stopPropagation();
      var d = fromKey(key);
      if (!selStart || selEnd) {
        selStart = d; selEnd = null; selectedSids = [];
        var sd = document.getElementById('cal-sessions');
        if (sd) sd.innerHTML = '<p class="text-center text-muted small py-1 mb-0">Now click an end date (or the same day for a single-day search).</p>';
      } else {
        selEnd = d;
        fetchSessions();
      }
      saveCalState();
      renderBoth();
    },
    updateSelection: function() {
      var allCbs    = document.querySelectorAll('.cal-sess-cb');
      var checkedCbs = document.querySelectorAll('.cal-sess-cb:checked');
      selectedSids = Array.prototype.map.call(checkedCbs, function(cb) { return cb.value; });

      var bar     = document.getElementById('cal-action-bar');
      var count   = document.getElementById('cal-sel-count');
      var openBtn = document.getElementById('cal-open-btn');
      if (bar)   bar.style.display = selectedSids.length ? '' : 'none';
      if (count) count.textContent = selectedSids.length + ' session' + (selectedSids.length !== 1 ? 's' : '') + ' selected';
      if (openBtn) {
        var n = selectedSids.length;
        openBtn.innerHTML = '<i class="bi bi-arrow-right-circle me-1"></i>' +
          (n > 1 ? 'Open ' + n + ' Sessions' : 'Open Session');
      }

      // Sync "Select All" checkbox — checked=all, indeterminate=some, unchecked=none
      var selectAllCb = document.getElementById('cal-select-all');
      var selectAllTxt = document.getElementById('cal-select-all-text');
      if (selectAllCb) {
        var total = allCbs.length;
        var numChecked = selectedSids.length;
        if (numChecked === 0) {
          selectAllCb.checked = false;
          selectAllCb.indeterminate = false;
        } else if (numChecked === total) {
          selectAllCb.checked = true;
          selectAllCb.indeterminate = false;
        } else {
          selectAllCb.checked = false;
          selectAllCb.indeterminate = true;
        }
        if (selectAllTxt) {
          selectAllTxt.textContent = numChecked === total
            ? 'Deselect all (' + total + ')'
            : 'Select all (' + total + ')';
        }
      }

      // Track colours: index 0 = primary (blue speed-gradient shown as blue dot)
      //                index 1+ = MULTI_COLORS matching session.php _torqueDrawMulti
      var TRACK_COLORS = ['#0d6efd', '#e63946', '#2a9d8f', '#f4a261', '#9b5de5', '#00b4d8', '#fb8500', '#8ecae6'];
      // Highlight checked rows and update color dots
      document.querySelectorAll('.cal-session-item').forEach(function(item) {
        var cb  = item.querySelector('.cal-sess-cb');
        var dot = item.querySelector('.cal-sess-color');
        var checked = !!(cb && cb.checked);
        item.classList.toggle('selected', checked);
        if (dot) {
          var idx = selectedSids.indexOf(cb ? cb.value : '');
          dot.style.display    = idx >= 0 ? 'inline-block' : 'none';
          dot.style.background = idx >= 0 ? TRACK_COLORS[idx % TRACK_COLORS.length] : '';
          dot.title            = idx === 0 ? 'Primary track (speed gradient)' : 'Additional track';
        }
      });
    },
    openSelected: function() {
      if (!selectedSids.length) return;
      var url = 'session.php?id=' + encodeURIComponent(selectedSids[0]);
      if (selectedSids.length > 1) {
        url += '&multi=' + selectedSids.slice(1).map(encodeURIComponent).join(',');
      }
      window.location.href = url;
    },
    jumpMonth: function(m) { viewMonth = parseInt(m, 10); saveCalState(); renderBoth(); },
    jumpYear:  function(y) { viewYear  = parseInt(y, 10); saveCalState(); renderBoth(); },
    prevMonth: function() {
      viewMonth--; if (viewMonth < 0) { viewMonth = 11; viewYear--; }
      saveCalState(); renderBoth();
    },
    nextMonth: function() {
      viewMonth++; if (viewMonth > 11) { viewMonth = 0; viewYear++; }
      saveCalState(); renderBoth();
    },
    open: function() {
      calPanel.style.display = '';
      renderBoth();
      // If we have a saved range (e.g. returning from a session), refetch the session list
      if (selStart && selEnd) { fetchSessions(); }
    },
    close:  function() { calPanel.style.display = 'none'; },
    toggle: function() { if (calPanel.style.display === 'none') this.open(); else this.close(); }
  };

  // Delegated: nav buttons + month/year selects + session checkboxes
  calPanel.addEventListener('click', function(e) {
    var nb = e.target.closest('.torque-cal-nav');
    if (!nb) return;
    parseInt(nb.getAttribute('data-dir'), 10) < 0 ? _torqueCal.prevMonth() : _torqueCal.nextMonth();
  });

  calPanel.addEventListener('change', function(e) {
    // Individual session checkbox
    if (e.target.classList.contains('cal-sess-cb')) {
      _torqueCal.updateSelection();
      return;
    }
    // "Select All" master checkbox
    if (e.target.id === 'cal-select-all') {
      var checked = e.target.checked;
      document.querySelectorAll('.cal-sess-cb').forEach(function(cb) { cb.checked = checked; });
      _torqueCal.updateSelection();
      return;
    }
    // Month / year selectors
    var sel = e.target.closest('[data-calsel]');
    if (!sel) return;
    sel.getAttribute('data-calsel') === 'month'
      ? _torqueCal.jumpMonth(sel.value)
      : _torqueCal.jumpYear(sel.value);
  });

  calBtn.addEventListener('click', function(e) { e.stopPropagation(); _torqueCal.toggle(); });

  // Click outside to close — use composedPath so clicks that trigger re-renders don't false-fire
  document.addEventListener('click', function(e) {
    if (calPanel.style.display === 'none') return;
    var path = e.composedPath ? e.composedPath() : (e.path || [e.target]);
    var inside = path.some(function(n) { return n === calPanel || n === calBtn; });
    if (!inside) _torqueCal.close();
  });
}); // end DOMContentLoaded

// ── AI Chat ──────────────────────────────────────────────────────────────────
(function() {
  var _aiHistory  = [];  // [{role, content}]
  var _aiThinking = false;

  function escHtml(s) {
    return String(s || '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
  }

  // Very simple markdown: **bold**, *italic*, `code`, newlines
  function renderMd(s) {
    return escHtml(s)
      .replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>')
      .replace(/\*(.+?)\*/g,     '<em>$1</em>')
      .replace(/`(.+?)`/g,       '<code style="background:rgba(0,0,0,0.08);padding:1px 4px;border-radius:3px;font-family:monospace;">$1</code>')
      .replace(/\n/g, '<br>');
  }

  function scrollBottom() {
    var el = document.getElementById('ai-messages');
    if (el) el.scrollTop = el.scrollHeight;
  }

  function appendMsg(role, text, isThinking) {
    var el = document.getElementById('ai-messages');
    if (!el) return null;
    var div = document.createElement('div');
    div.className = isThinking ? 'ai-msg ai-msg-thinking'
                  : (role === 'user' ? 'ai-msg ai-msg-user' : 'ai-msg ai-msg-ai');
    div.innerHTML = isThinking ? '<i class="bi bi-hourglass-split me-1"></i>Thinking…'
                  : (role === 'user' ? escHtml(text) : renderMd(text));
    el.appendChild(div);
    scrollBottom();
    // Hide suggestions after first user message
    if (role === 'user') {
      var sugg = document.getElementById('ai-suggestions');
      if (sugg) sugg.style.display = 'none';
    }
    return div;
  }

  // Show welcome message the first time the panel opens
  var _aiGreeted = false;
  document.addEventListener('DOMContentLoaded', function() {
    var btn = document.getElementById('btn-ai');
    if (!btn) return;
    btn.addEventListener('click', function() {
      if (_aiGreeted) return;
      _aiGreeted = true;
      var sid = (typeof _aiSessionId !== 'undefined' && _aiSessionId) ? ' I can see your current session data.' : '';
      setTimeout(function() {
        appendMsg('ai', 'Hi! I\'m TorqueAI \u2014 I have access to your Torque Pro database.' + sid + '\n\nAsk me anything about your driving data, OBD readings, or car health. Try one of the suggestions below, or type your own question.');
        scrollBottom();
      }, 200);
    });
  });

  window.aiSend = function(preset) {
    if (_aiThinking) return;
    var input = document.getElementById('ai-input');
    var text  = preset || (input ? input.value.trim() : '');
    if (!text) return;
    if (input) input.value = '';

    appendMsg('user', text);
    _aiHistory.push({role: 'user', content: text});

    var thinkDiv = appendMsg('ai', '', true);
    _aiThinking = true;
    var sendBtn = document.getElementById('ai-send');
    if (sendBtn) sendBtn.disabled = true;

    var sid = typeof _aiSessionId !== 'undefined' ? _aiSessionId : '';

    fetch('claude_chat.php', {
      method: 'POST',
      headers: {'Content-Type': 'application/json'},
      body: JSON.stringify({message: text, history: _aiHistory.slice(0, -1), session_id: sid})
    })
    .then(function(r) { return r.json(); })
    .then(function(d) {
      if (thinkDiv) thinkDiv.remove();
      if (d.error) {
        appendMsg('ai', 'Error: ' + d.error);
      } else {
        var reply = d.response || '';
        appendMsg('ai', reply);
        _aiHistory.push({role: 'assistant', content: reply});
        // Keep history under 40 turns
        if (_aiHistory.length > 40) _aiHistory = _aiHistory.slice(-40);
      }
    })
    .catch(function(e) {
      if (thinkDiv) thinkDiv.remove();
      appendMsg('ai', 'Network error — check your connection.');
    })
    .finally(function() {
      _aiThinking = false;
      if (sendBtn) sendBtn.disabled = false;
      var inp = document.getElementById('ai-input');
      if (inp) inp.focus();
    });
  };
})();

// Validate plot form has at least 1 variable selected
function onSubmitIt() {
  var el = document.getElementById('plot_data');
  if (el && el.tomselect) {
    if (el.tomselect.items.length < 1) { return false; }
  }
  document.getElementById('formplotdata').submit();
}

$(document).ready(function(){

  // Read actual navbar height from CSS variable — single source of truth in hud.css
  var _navbarH = parseInt(getComputedStyle(document.documentElement).getPropertyValue('--navbar-height'), 10) || 46;

  // ── Restore saved panel positions from localStorage ──
  ['hud-widget', 'vars-section', 'summary-section'].forEach(function(id) {
    try {
      var saved = localStorage.getItem('torque-pos-' + id);
      if (!saved) return;
      var pos = JSON.parse(saved);
      var el = document.getElementById(id);
      if (!el || !pos || !pos.left || !pos.top) return;
      // Clamp to visible viewport (handles window resize between sessions)
      var leftPx = Math.min(Math.max(0, parseInt(pos.left, 10)), window.innerWidth  - 40);
      var topPx  = Math.min(Math.max(_navbarH, parseInt(pos.top,  10)), window.innerHeight - 40);
      el.style.left   = leftPx + 'px';
      el.style.top    = topPx  + 'px';
      el.style.right  = 'auto';
      el.style.bottom = 'auto';
    } catch(e) {}
  });

  // ── Auto-close navbar collapse when an action button is tapped on mobile ──
  var navActionBtns = document.getElementById('navbar-action-btns');
  if (navActionBtns) {
    navActionBtns.addEventListener('click', function(e) {
      if (e.target.closest('.btn, a')) {
        var collapseEl = document.getElementById('navbarCollapse');
        if (collapseEl) {
          var bsCollapse = bootstrap.Collapse.getInstance(collapseEl);
          if (bsCollapse) bsCollapse.hide();
        }
      }
    });
  }

  // "Show only variables with data" filter checkbox
  var filterCheck = document.getElementById('filterHasData');
  var plotSelect  = document.getElementById('plot_data');

  function applyHasDataFilter() {
    if (!plotSelect || !filterCheck) return;
    var filterOn = filterCheck.checked;
    Array.from(plotSelect.options).forEach(function(opt) {
      if (!opt.value) return; // skip blank placeholder
      var hasData = parseInt(opt.getAttribute('data-has-data') || '-1', 10);
      // Hide options with no data (has_data=0) when filter is on; always show unknowns (-1)
      opt.hidden = filterOn && hasData === 0;
    });
  }

  if (filterCheck) {
    filterCheck.addEventListener('change', applyHasDataFilter);
    applyHasDataFilter(); // apply on page load
  }

  // Initialize Tom Select on yearmonth multi-select in navbar
  if (document.getElementById('selyearmonth')) {
    new TomSelect('#selyearmonth', {
      plugins: ['remove_button', 'clear_button'],
      maxItems: null,           // unlimited selections
      maxOptions: null,         // show ALL months — default cap of 50 hides older years
      placeholder: 'All Months',
      hidePlaceholder: false,
      selectOnTab: false,
      closeAfterSelect: false,  // keep dropdown open for multi-select
      sortField: '$order',      // preserve DESC order from PHP/SQL; never re-sort alphabetically
      create: false
    });
  }

  // Initialize Tom Select on variable multi-select
  if (document.getElementById('plot_data')) {
    new TomSelect('#plot_data', {
      plugins: ['remove_button'],
      dropdownParent: 'body',   // render dropdown outside the panel so overflow:hidden doesn't clip it
      placeholder: 'Choose OBD2 data...',
      render: {
        option: function(data, escape) {
          // Grey out options with no data even in Tom Select dropdown
          var hasData = parseInt(data.hasData || '-1', 10);
          var style = hasData === 0 ? ' style="opacity:0.4;"' : '';
          return '<div' + style + '>' + escape(data.text) + '</div>';
        }
      },
      onInitialize: function() {
        // Copy has-data attribute into Tom Select's internal option objects
        var self = this;
        Array.from(plotSelect.options).forEach(function(opt) {
          if (opt.value && self.options[opt.value]) {
            self.options[opt.value].hasData = opt.getAttribute('data-has-data') || '-1';
          }
        });
      }
    });
  }

  // Initialize Peity sparklines
  $(".line").peity("line");

  // Delete confirmation — intercept navbar delete button
  var deleteBtn = document.getElementById('deletebtn');
  if (deleteBtn) {
    deleteBtn.addEventListener('click', function(e) {
      var deleteForm = document.getElementById('formdelete');
      var sessionText = deleteForm ? deleteForm.getAttribute('data-session-name') : 'this session';
      if (!confirm("Click OK to delete session (" + sessionText + ").")) {
        e.preventDefault();
        e.stopPropagation();
      }
    });
  }

  // Set dark mode button icon correctly on load
  var saved = localStorage.getItem('torque-theme') || 'light';
  var dmBtn = document.getElementById('darkModeBtn');
  if (dmBtn) {
    dmBtn.innerHTML = saved === 'dark'
      ? '<i class="bi bi-sun"></i>'
      : '<i class="bi bi-moon-stars"></i>';
  }

  // ── Floating panel drag-to-move ──
  document.querySelectorAll('.torque-panel-header').forEach(function(header) {
    var panel = header.closest('.torque-panel');
    // Chart panel spans full width — not draggable; calendar and AI are fixed-position
    if (!panel || panel.id === 'chart-section' || panel.id === 'cal-panel' || panel.id === 'ai-section') return;

    header.addEventListener('mousedown', function(e) {
      // Don't start drag when clicking a button inside the header
      if (e.target.closest('button')) return;
      e.preventDefault();

      var rect = panel.getBoundingClientRect();
      var startX   = e.clientX;
      var startY   = e.clientY;
      var startLeft = rect.left;
      var startTop  = rect.top;

      // Switch from CSS-defined right/bottom to explicit left/top so drag works
      panel.style.left   = startLeft + 'px';
      panel.style.top    = startTop  + 'px';
      panel.style.right  = 'auto';
      panel.style.bottom = 'auto';

      function onMouseMove(e) {
        var dx = e.clientX - startX;
        var dy = e.clientY - startY;
        var newLeft = Math.max(0, startLeft + dx);
        var newTop  = Math.max(_navbarH, startTop  + dy);
        // Clamp to right/bottom edges of viewport using panel's actual width
        newLeft = Math.min(window.innerWidth  - Math.min(panel.offsetWidth  || 40, window.innerWidth),  newLeft);
        newTop  = Math.min(window.innerHeight - Math.min(panel.offsetHeight || 40, window.innerHeight), newTop);
        panel.style.left = newLeft + 'px';
        panel.style.top  = newTop  + 'px';
      }

      function onMouseUp() {
        document.removeEventListener('mousemove', onMouseMove);
        document.removeEventListener('mouseup',   onMouseUp);
        try {
          localStorage.setItem('torque-pos-' + panel.id,
            JSON.stringify({ left: panel.style.left, top: panel.style.top }));
        } catch(e) {}
      }

      document.addEventListener('mousemove', onMouseMove);
      document.addEventListener('mouseup',   onMouseUp);
    });

    // Touch drag support (mobile)
    header.addEventListener('touchstart', function(e) {
      if (e.target.closest('button')) return;
      var touch = e.touches[0];
      var rect  = panel.getBoundingClientRect();
      var startX    = touch.clientX;
      var startY    = touch.clientY;
      var startLeft = rect.left;
      var startTop  = rect.top;

      panel.style.left   = startLeft + 'px';
      panel.style.top    = startTop  + 'px';
      panel.style.right  = 'auto';
      panel.style.bottom = 'auto';

      function onTouchMove(e) {
        e.preventDefault();
        var t = e.touches[0];
        var newLeft = Math.max(0, startLeft + t.clientX - startX);
        var newTop  = Math.max(_navbarH, startTop  + t.clientY - startY);
        newLeft = Math.min(window.innerWidth  - Math.min(panel.offsetWidth  || 40, window.innerWidth),  newLeft);
        newTop  = Math.min(window.innerHeight - Math.min(panel.offsetHeight || 40, window.innerHeight), newTop);
        panel.style.left = newLeft + 'px';
        panel.style.top  = newTop  + 'px';
      }

      function onTouchEnd() {
        header.removeEventListener('touchmove', onTouchMove);
        header.removeEventListener('touchend',  onTouchEnd);
        try {
          localStorage.setItem('torque-pos-' + panel.id,
            JSON.stringify({ left: panel.style.left, top: panel.style.top }));
        } catch(e) {}
      }

      header.addEventListener('touchmove', onTouchMove, { passive: false });
      header.addEventListener('touchend',  onTouchEnd);
    });
  });

  // ── HUD Widget: mobile collapse ──
  var hudPanel = document.getElementById('hud-widget');
  if (hudPanel) {
    // Start collapsed on mobile so the HUD doesn't block the map
    if (window.innerWidth < 768) hudPanel.classList.add('hud-collapsed');
    var hudCollapseBtn  = document.getElementById('hud-collapse-btn');
    var hudCollapseIcon = document.getElementById('hud-collapse-icon');
    if (hudCollapseBtn && hudCollapseIcon) {
      hudCollapseBtn.addEventListener('click', function(e) {
        e.stopPropagation();
        hudPanel.classList.toggle('hud-collapsed');
        hudCollapseIcon.className = hudPanel.classList.contains('hud-collapsed')
          ? 'bi bi-chevron-up' : 'bi bi-chevron-down';
      });
    }
  }

  // ── HUD Widget drag (via .hud-drag-handle) ──
  var hudHandle = document.querySelector('.hud-drag-handle');
  if (hudHandle && hudPanel) {
    hudHandle.addEventListener('mousedown', function(e) {
      e.preventDefault();
      var rect      = hudPanel.getBoundingClientRect();
      var startX    = e.clientX;
      var startY    = e.clientY;
      var startLeft = rect.left;
      var startTop  = rect.top;

      hudPanel.style.left   = startLeft + 'px';
      hudPanel.style.top    = startTop  + 'px';
      hudPanel.style.right  = 'auto';
      hudPanel.style.bottom = 'auto';

      function onHudMove(e) {
        var newLeft = Math.max(0, Math.min(window.innerWidth  - Math.min(hudPanel.offsetWidth  || 40, window.innerWidth),  startLeft + e.clientX - startX));
        var newTop  = Math.max(_navbarH, Math.min(window.innerHeight - Math.min(hudPanel.offsetHeight || 40, window.innerHeight), startTop  + e.clientY - startY));
        hudPanel.style.left = newLeft + 'px';
        hudPanel.style.top  = newTop  + 'px';
      }
      function onHudUp() {
        document.removeEventListener('mousemove', onHudMove);
        document.removeEventListener('mouseup',   onHudUp);
        try {
          localStorage.setItem('torque-pos-hud-widget',
            JSON.stringify({ left: hudPanel.style.left, top: hudPanel.style.top }));
        } catch(e) {}
      }
      document.addEventListener('mousemove', onHudMove);
      document.addEventListener('mouseup',   onHudUp);
    });

    hudHandle.addEventListener('touchstart', function(e) {
      var touch     = e.touches[0];
      var rect      = hudPanel.getBoundingClientRect();
      var startX    = touch.clientX;
      var startY    = touch.clientY;
      var startLeft = rect.left;
      var startTop  = rect.top;

      hudPanel.style.left   = startLeft + 'px';
      hudPanel.style.top    = startTop  + 'px';
      hudPanel.style.right  = 'auto';
      hudPanel.style.bottom = 'auto';

      function onHudTouchMove(e) {
        e.preventDefault();
        var t = e.touches[0];
        var newLeft = Math.max(0, Math.min(window.innerWidth  - Math.min(hudPanel.offsetWidth  || 40, window.innerWidth),  startLeft + t.clientX - startX));
        var newTop  = Math.max(_navbarH, Math.min(window.innerHeight - Math.min(hudPanel.offsetHeight || 40, window.innerHeight), startTop  + t.clientY - startY));
        hudPanel.style.left = newLeft + 'px';
        hudPanel.style.top  = newTop  + 'px';
      }
      function onHudTouchEnd() {
        hudHandle.removeEventListener('touchmove', onHudTouchMove);
        hudHandle.removeEventListener('touchend',  onHudTouchEnd);
        try {
          localStorage.setItem('torque-pos-hud-widget',
            JSON.stringify({ left: hudPanel.style.left, top: hudPanel.style.top }));
        } catch(e) {}
      }
      hudHandle.addEventListener('touchmove', onHudTouchMove, { passive: false });
      hudHandle.addEventListener('touchend',  onHudTouchEnd);
    });
  }

});

// ══════════════════════════════════════════════════════════════════
// HUD Gauge System
// ══════════════════════════════════════════════════════════════════

// Haversine distance in km between two lat/lon points
function _haversineKm(lat1, lon1, lat2, lon2) {
  var R = 6371;
  var dLat = (lat2 - lat1) * Math.PI / 180;
  var dLon = (lon2 - lon1) * Math.PI / 180;
  var a = Math.sin(dLat / 2) * Math.sin(dLat / 2) +
          Math.cos(lat1 * Math.PI / 180) * Math.cos(lat2 * Math.PI / 180) *
          Math.sin(dLon / 2) * Math.sin(dLon / 2);
  return R * 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1 - a));
}

// Find first Chart.js dataset whose kcode property exactly matches the given k-code
function _findDatasetByKCode(kcode) {
  if (!window.torqueChart || !kcode) return null;
  var ds = window.torqueChart.data.datasets;
  for (var i = 0; i < ds.length; i++) {
    if ((ds[i].kcode || '') === kcode) return ds[i];
  }
  return null;
}

// Find first Chart.js dataset whose label contains any of the given keywords (case-insensitive)
function _findDatasetByKeyword() {
  var keywords = Array.prototype.slice.call(arguments);
  if (!window.torqueChart) return null;
  var ds = window.torqueChart.data.datasets;
  for (var i = 0; i < ds.length; i++) {
    var lbl = (ds[i].label || '').toLowerCase();
    for (var k = 0; k < keywords.length; k++) {
      if (lbl.indexOf(keywords[k]) !== -1) return ds[i];
    }
  }
  return null;
}

// Mean of a Chart.js dataset's y values
function _datasetMean(ds) {
  if (!ds || !ds.data || !ds.data.length) return null;
  var sum = 0;
  for (var i = 0; i < ds.data.length; i++) sum += ds.data[i].y;
  return sum / ds.data.length;
}

// Set a gauge arc's fill fraction (0–1) and update label text
function _setGauge(arcId, valId, fraction, text) {
  var arc = document.getElementById(arcId);
  var val = document.getElementById(valId);
  if (!arc) return;
  var offset = (94 * (1 - Math.max(0, Math.min(1, fraction)))).toFixed(1);
  arc.setAttribute('stroke-dashoffset', offset);
  if (val) val.textContent = text !== undefined ? String(text) : '';
}

// Whether the first init (page-load sweep) has run
var _gaugesInitialised = false;

// Initialise gauges on page load — populate stats and animate to session averages
function _initGauges() {
  // On first call, apply a slow 1.2s sweep so the entry animation is dramatic
  var arcs = document.querySelectorAll('.hud-gauge-arc');
  if (!_gaugesInitialised) {
    for (var a = 0; a < arcs.length; a++) arcs[a].classList.add('hud-gauge-arc--loading');
    _gaugesInitialised = true;
    setTimeout(function() {
      for (var a = 0; a < arcs.length; a++) arcs[a].classList.remove('hud-gauge-arc--loading');
    }, 1300);
  }

  // ── Distance from GPS ──
  var distEl = document.getElementById('hud-stat-dist');
  if (distEl && window._routeData && _routeData.length > 1) {
    var dist = 0;
    for (var i = 1; i < _routeData.length; i++) {
      dist += _haversineKm(_routeData[i - 1][1], _routeData[i - 1][0],
                           _routeData[i][1],     _routeData[i][0]);
    }
    distEl.textContent = dist.toFixed(1) + ' km';
  }

  // ── Duration from GPS timestamps ──
  var durEl = document.getElementById('hud-stat-dur');
  if (durEl && window._routeData && _routeData.length > 1 && _routeData[0].length >= 4) {
    var dur = (_routeData[_routeData.length - 1][3] - _routeData[0][3]) / 60000;
    durEl.textContent = Math.round(dur) + ' min';
  }

  // ── Fuel stat: prefer chart dataset, fall back to session average ──
  var fuelEl = document.getElementById('hud-stat-fuel');
  if (fuelEl) {
    var fuelPid = (window._hudConfig && _hudConfig.fuel) ? _hudConfig.fuel.pid : 'kff5203';
    var fuelDs  = _findDatasetByKCode(fuelPid);
    var fuelAvg = _datasetMean(fuelDs);
    if (fuelAvg !== null) {
      fuelEl.textContent = fuelAvg.toFixed(1);
    } else if (window._hudSessionAvg && _hudSessionAvg.fuel !== null && _hudSessionAvg.fuel !== undefined) {
      fuelEl.textContent = _hudSessionAvg.fuel.toFixed(1);
    } else {
      fuelEl.textContent = '—';
    }
  }

  // ── Animate gauges: prefer chart dataset mean, fall back to session average ──
  var cfg = window._hudConfig || {};
  var avg = window._hudSessionAvg || {};

  // Gauge 1
  var g1cfg = cfg.gauge1 || {pid:'kc', min:0, max:8000, suffix:''};
  var g1ds  = _findDatasetByKCode(g1cfg.pid);
  var g1val = _datasetMean(g1ds);
  if (g1val === null && avg.gauge1 !== null && avg.gauge1 !== undefined) g1val = avg.gauge1;
  if (g1val !== null) {
    var g1max  = (g1cfg.max > 0) ? g1cfg.max : (window._maxSpeed || 120);
    var g1frac = (g1val - g1cfg.min) / (g1max - g1cfg.min);
    _setGauge('hud-gauge-rpm', 'hud-gauge-rpm-val', g1frac, Math.round(g1val) + (g1cfg.suffix || ''));
  }

  // Gauge 2
  var g2cfg = cfg.gauge2 || {pid:'k5', min:40, max:120, suffix:'°'};
  var g2ds  = _findDatasetByKCode(g2cfg.pid);
  var g2val = _datasetMean(g2ds);
  if (g2val === null && avg.gauge2 !== null && avg.gauge2 !== undefined) g2val = avg.gauge2;
  if (g2val !== null) {
    var g2max  = (g2cfg.max > 0) ? g2cfg.max : (window._maxSpeed || 120);
    var g2frac = (g2val - g2cfg.min) / (g2max - g2cfg.min);
    _setGauge('hud-gauge-coolant', 'hud-gauge-coolant-val', g2frac, Math.round(g2val) + (g2cfg.suffix || ''));
  }

  // Gauge 3
  var g3cfg = cfg.gauge3 || {pid:'kd', min:0, max:0, suffix:''};
  var g3ds  = _findDatasetByKCode(g3cfg.pid);
  var g3val = _datasetMean(g3ds);
  if (g3val === null && avg.gauge3 !== null && avg.gauge3 !== undefined) g3val = avg.gauge3;
  if (g3val !== null) {
    var g3max  = (g3cfg.max > 0) ? g3cfg.max : (window._maxSpeed || 120);
    var g3frac = (g3val - g3cfg.min) / (g3max - g3cfg.min);
    _setGauge('hud-gauge-speed', 'hud-gauge-speed-val', g3frac, Math.round(g3val) + (g3cfg.suffix || ''));
  }

  // Reset coolant arc to default colour (mouseleave may leave it at a warning threshold)
  var coolantArc = document.getElementById('hud-gauge-coolant');
  if (coolantArc) {
    coolantArc.setAttribute('stroke', '#ff6b6b');
    coolantArc.style.filter = '';
  }
}

// Update gauges from a chart timestamp — called on chart mousemove
function _updateGauges(tsMs) {
  var cfg = window._hudConfig || {};

  // Gauge 1
  var g1cfg = cfg.gauge1 || {pid:'kc', min:0, max:8000, suffix:''};
  var g1ds  = _findDatasetByKCode(g1cfg.pid);
  if (g1ds) {
    var g1val = _chartValueAtTime(g1ds.data, tsMs);
    if (g1val !== null) {
      var g1max = (g1cfg.max > 0) ? g1cfg.max : (window._maxSpeed || 120);
      _setGauge('hud-gauge-rpm', 'hud-gauge-rpm-val',
        (g1val - g1cfg.min) / (g1max - g1cfg.min),
        Math.round(g1val) + (g1cfg.suffix || ''));
    }
  }

  // Gauge 2 — with coolant temperature colour thresholds (hardcoded to gauge 2)
  var g2cfg = cfg.gauge2 || {pid:'k5', min:40, max:120, suffix:'°'};
  var g2ds  = _findDatasetByKCode(g2cfg.pid);
  if (g2ds) {
    var g2val = _chartValueAtTime(g2ds.data, tsMs);
    if (g2val !== null) {
      var g2max = (g2cfg.max > 0) ? g2cfg.max : (window._maxSpeed || 120);
      _setGauge('hud-gauge-coolant', 'hud-gauge-coolant-val',
        (g2val - g2cfg.min) / (g2max - g2cfg.min),
        Math.round(g2val) + (g2cfg.suffix || ''));
      var arc2 = document.getElementById('hud-gauge-coolant');
      if (arc2) {
        var col = g2val > 105 ? '#ff2222' : g2val > 95 ? '#ff9944' : '#ff6b6b';
        arc2.setAttribute('stroke', col);
        arc2.style.filter = 'drop-shadow(0 0 4px ' + col + ')';
      }
    }
  }

  // Gauge 3
  var g3cfg = cfg.gauge3 || {pid:'kd', min:0, max:0, suffix:''};
  var g3ds  = _findDatasetByKCode(g3cfg.pid);
  if (g3ds) {
    var g3val = _chartValueAtTime(g3ds.data, tsMs);
    if (g3val !== null) {
      var g3max = (g3cfg.max > 0) ? g3cfg.max : (window._maxSpeed || 120);
      _setGauge('hud-gauge-speed', 'hud-gauge-speed-val',
        (g3val - g3cfg.min) / (g3max - g3cfg.min),
        Math.round(g3val) + (g3cfg.suffix || ''));
    }
  }
}

// Recolour Peity sparklines to match HUD dataset colours
function _hudRecolourSparklines() {
  var colors = ['#00d4ff','#ff6b6b','#00ff88','#f4a261','#9b5de5','#00b4d8','#fb8500'];
  $('#summary-section .table tbody tr').each(function(i) {
    $(this).find('.line').peity('line', {
      stroke: colors[i % colors.length],
      fill: 'transparent',
      width: 60,
      height: 20
    });
  });
}

window.addEventListener('load', function() {
  setTimeout(_hudRecolourSparklines, 200);
});
