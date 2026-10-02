/* Kiption enhancement layer, toggle module. The header's theme quick
 * toggle is a plain light/dark flip, the convention readers expect from a
 * header icon: on a dark page (Night, or Auto under a dark OS setting) it
 * goes to Paper, on any light page (Paper, Sepia, or Auto under a light OS
 * setting) it goes to Night. Its label always names the next state, so
 * the result of a click is never a guess. Sepia and Auto stay one tap away
 * in the reader's Text sheet and in Settings. The current state is read
 * from the root data-theme attribute: it is the state the page actually
 * applied (the theme cookie is script-writable, Cookie::pref, but can lag
 * a cached render); absence is Auto. Application goes through the core
 * Kip.setTheme (app.js): attribute, cookie, and the Text sheet's swatch
 * when that sheet is on the page. This is a device-local convenience: no member row
 * is written - Save and Settings stay the durable writes. */
'use strict';
(function (Kip) {
  if (!Kip) { return; }
  Kip.init(function () {
    var btn = Kip.$('[data-js-module="toggle"]');
    if (!btn) { return; }
    var root = document.documentElement;
    function isDark() {
      var t = root.getAttribute('data-theme');
      if (t === 'night') { return true; }
      if (t) { return false; }
      return !!(window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches);
    }
    function label() {
      var text = btn.getAttribute(isDark() ? 'data-label-light' : 'data-label-dark');
      if (text) { btn.setAttribute('aria-label', text); btn.setAttribute('title', text); }
    }
    label();
    btn.addEventListener('click', function () {
      var next = isDark() ? 'paper' : 'night';
      Kip.setTheme(next);
      label();
    });
  });
})(window.Kip);
