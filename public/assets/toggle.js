/* Kiption enhancement layer, toggle module. The header's theme quick
 * toggle: one click cycles paper -> sepia -> night -> auto -> paper, and
 * from auto (or an absent attribute, which IS auto: the server omits
 * data-theme for it) the first click lands on night. The current state is
 * read from the root data-theme attribute ONLY: the server's theme cookie
 * is HttpOnly, invisible to every script, and a legacy dark/light cookie
 * reads as unknown too - the attribute is the truth the server rendered.
 * Application goes through Kip.setTheme when the prefs module
 * is present (it also syncs the Text sheet's checked swatch), else the
 * same contract is set by hand: attribute plus cookie, delete-on-empty
 * (absence is auto, exactly like the server renders it). This is a
 * device-local convenience: no member row is written - the Text sheet's
 * Save stays the durable write. The loader appends this file only when
 * the page carries [data-js-module="toggle"], and window.Kip exists
 * before any module runs. */
'use strict';
(function (Kip) {
  if (!Kip) { return; }
  Kip.init(function () {
    var btn = Kip.$('[data-js-module="toggle"]');
    if (!btn) { return; }
    /* The full value set, in cycle order, matching App\Theme::VALUES. */
    var CYCLE = ['paper', 'sepia', 'night', 'auto'];
    btn.addEventListener('click', function () {
      var root = document.documentElement;
      var i = CYCLE.indexOf(root.getAttribute('data-theme'));
      /* An absent attribute (auto) or anything unknown starts at night. */
      var next = i > -1 ? CYCLE[(i + 1) % CYCLE.length] : 'night';
      if (typeof Kip.setTheme === 'function') { Kip.setTheme(next); return; }
      /* Without the prefs module (no Text sheet on this page): the same
         contract, set by hand. */
      if (next === 'auto') {
        root.removeAttribute('data-theme');
        Kip.cookie.set('theme', '');
      } else {
        root.setAttribute('data-theme', next);
        Kip.cookie.set('theme', next);
      }
    });
  });
})(window.Kip);
