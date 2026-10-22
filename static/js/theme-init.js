// Apply the saved light/dark theme before first paint (load synchronously in <head>).
// External file rather than an inline <script> so it works under the strict CSP.
(function () {
  var t = 'light';
  try { t = localStorage.getItem('torque-theme') || 'light'; } catch (e) {}
  document.documentElement.setAttribute('data-bs-theme', t);
})();
