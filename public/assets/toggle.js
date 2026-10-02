/* Kiption enhancement layer, toggle module. The header's theme quick
 * toggle is a plain light/dark flip, the convention readers expect from a
 * header icon: on a dark page (Night, or Auto under a dark OS setting) it
 * goes to Paper, on any light page (Paper, Sepia, or Auto under a light OS
 * setting) it goes to Night. Its label always names the next state, so
 * the result of a click is never a guess. Sepia and Auto stay one tap away
 * in the reader's Text sheet and in Settings. The current state is read
 * from the root data-theme attribute ONLY: the server's theme cookie is
 * HttpOnly, invisible to every script; absence is Auto. Application goes
 * through Kip.setTheme when the prefs module is present (it also syncs the
 * Text sheet's checked swatch), else the same contract is set by hand:
 * attribute plus cookie. This is a device-local convenience: no member row
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
      if (typeof Kip.setTheme === 'function') { Kip.setTheme(next); }
      else { root.setAttribute('data-theme', next); Kip.cookie.set('theme', next); }
      label();
    });
  });
})(window.Kip);
