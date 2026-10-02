/* Kiption enhancement layer, core. Vanilla ES2017, no dependencies, loaded
 * with defer. Everything here is progressive enhancement: pages are fully
 * usable with scripting disabled (forms POST, sheets open via :target, the
 * pager links). Feature modules live in their own files (prefs.js,
 * position.js, keys.js, infinite.js, toggle.js) and load only when their
 * module marker is present in the markup; the loader below appends them with
 * defer so helpers exist before any module runs. Wiring travels in
 * data-js-* attributes set by the views; this file contains no content. */
'use strict';
(function () {
  var Kip = {
    $: function (sel, root) { return (root || document).querySelector(sel); },
    $$: function (sel, root) { return Array.prototype.slice.call((root || document).querySelectorAll(sel)); },
    cookie: {
      set: function (name, value) {
        /* Secure on HTTPS, matching the server's App\Cookie, so a control
           move never strips the attribute the server set. */
        var secure = location.protocol === 'https:' ? '; secure' : '';
        /* An empty value DELETES (the server's default-clearing economy):
           a year-long empty cookie would still make every request miss the
           static cache. */
        if (value === '') {
          document.cookie = name + '=; path=/; max-age=0; samesite=lax' + secure;
          return;
        }
        document.cookie = name + '=' + encodeURIComponent(value) + '; path=/; max-age=31536000; samesite=lax' + secure;
      }
    },
    /* Run a module init now if the DOM is ready, else on DOMContentLoaded
       (defer scripts run before that event, but dynamically appended ones
       may land after it). */
    init: function (fn) {
      if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', fn); }
      else { fn(); }
    }
  };
  window.Kip = Kip;
  document.documentElement.classList.add('js'); /* CSS reveals JS-only affordances */
  ['prefs', 'position', 'keys', 'infinite', 'toggle'].forEach(function (m) {
    if (document.querySelector('[data-js-module="' + m + '"]')) {
      var s = document.createElement('script');
      s.src = '/assets/' + m + '.js';
      s.defer = true;
      document.head.appendChild(s);
    }
  });
})();
