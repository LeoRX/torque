// actions.js — delegated event handling that replaces inline on*="" attributes.
//
// The Content-Security-Policy (db.php) no longer allows 'unsafe-inline' scripts,
// so markup declares behaviour with data-* attributes and this file wires it up.
// Only the explicit actions below can be triggered — markup cannot name arbitrary
// global functions.
//
//   data-action="toggle-panel"  data-panel="vars-section" [data-btn="btn-vars"]
//   data-action="dark-mode"
//   data-action="cal-close"   (day cells / Open button are handled in torquehelpers.js)
//   data-action="ai-send" [data-prompt="…"]
//   data-action="gps-repair" | "plot" | "chart-reset-zoom"
//   data-action="test-claude" | "test-ha"
//   data-action="toggle-visibility" data-target="<input id>"
//   <select data-submit-form="<form id>">           submit that form on change
//   <input  data-sync-to="<id>" [data-sync-hex]>     copy value to another input
//   <textarea data-enter-action="ai-send">           Enter (no Shift) triggers it
//   <form   data-confirm="message">                  confirm() before submitting
(function () {
  'use strict';

  function call(name) {
    var fn = window[name];
    if (typeof fn === 'function') {
      return fn.apply(window, Array.prototype.slice.call(arguments, 1));
    }
  }

  // Used on pages that don't load session.js's richer toggleDarkMode()
  function fallbackDarkMode() {
    var html = document.documentElement;
    var next = html.getAttribute('data-bs-theme') === 'dark' ? 'light' : 'dark';
    html.setAttribute('data-bs-theme', next);
    try { localStorage.setItem('torque-theme', next); } catch (e) {}
    var btn = document.getElementById('darkModeBtn');
    if (btn) btn.innerHTML = next === 'dark' ? '<i class="bi bi-sun"></i>' : '<i class="bi bi-moon-stars"></i>';
  }

  var actions = {
    'toggle-panel': function (el) {
      var btn = el.dataset.btn ? document.getElementById(el.dataset.btn) : el;
      call('torqueToggle', el.dataset.panel, btn);
    },
    'dark-mode': function () {
      if (typeof window.toggleDarkMode === 'function') { window.toggleDarkMode(); }
      else { fallbackDarkMode(); }
    },
    'cal-close': function () { if (window._torqueCal) window._torqueCal.close(); },
    'ai-send': function (el) {
      if (el.dataset.prompt) { call('aiSend', el.dataset.prompt); } else { call('aiSend'); }
    },
    'gps-repair':       function () { call('torqueRepairGps'); },
    'plot':             function () { call('onSubmitIt'); },
    'chart-reset-zoom': function () { if (window.torqueChart) window.torqueChart.resetZoom(); },
    'test-claude':      function () { call('testClaudeConnection'); },
    'test-ha':          function () { call('testHaConnection'); },
    'toggle-visibility': function (el) {
      var i = document.getElementById(el.dataset.target);
      if (i) i.type = (i.type === 'password') ? 'text' : 'password';
    }
  };

  document.addEventListener('click', function (ev) {
    var el = ev.target.closest('[data-action]');
    if (!el || el.tagName === 'FORM') return;
    var fn = actions[el.dataset.action];
    if (fn) fn(el, ev);
  });

  document.addEventListener('change', function (ev) {
    var el = ev.target.closest('[data-submit-form]');
    if (!el) return;
    var form = document.getElementById(el.dataset.submitForm);
    if (form) form.submit();
  });

  document.addEventListener('input', function (ev) {
    var el = ev.target.closest('[data-sync-to]');
    if (!el) return;
    if (el.hasAttribute('data-sync-hex') && !/^#[0-9A-Fa-f]{6}$/.test(el.value)) return;
    var dst = document.getElementById(el.dataset.syncTo);
    if (dst) dst.value = el.value;
  });

  document.addEventListener('keydown', function (ev) {
    var el = ev.target.closest('[data-enter-action]');
    if (!el || ev.key !== 'Enter' || ev.shiftKey) return;
    var fn = actions[el.dataset.enterAction];
    if (fn) { ev.preventDefault(); fn(el, ev); }
  });

  document.addEventListener('submit', function (ev) {
    var form = ev.target.closest('form[data-confirm]');
    if (form && !window.confirm(form.dataset.confirm)) ev.preventDefault();
  });
})();
