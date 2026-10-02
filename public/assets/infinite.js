/* Kiption enhancement layer, infinite module. Reader infinite scroll: when
 * the sentinel nears the viewport the module fetches the next chapter's
 * fragment and inserts its .chapter-unit before the sentinel. The module
 * invents no URL: .reader carries data-next-url (the next fragment
 * endpoint), each unit carries data-read-url (its own read URL, for
 * history) and the following fragment URL in data-next-url. One observer
 * per page, one fetch in flight, no prefetch beyond the rootMargin band;
 * the observer disconnects when a unit arrives without a next URL (the
 * last chapter) or when the fragment carries no unit at all. Appends never
 * re-wire anything: the keys and position modules already read the page
 * live. Noscript readers keep the plain next-chapter links; the sentinel
 * is the module's own creation, so the server-rendered bytes stay inert.
 * The loader appends this file only when the page carries
 * [data-js-module="infinite"], and window.Kip exists before any module
 * runs. */
'use strict';
(function (Kip) {
  if (!Kip) { return; }
  Kip.init(function () {
    var host = Kip.$('[data-js-module="infinite"]');
    if (!host) { return; }
    var reader = host.closest('.reader') || host;
    var nextUrl = reader.getAttribute('data-next-url') || '';
    if (nextUrl === '' || typeof window.IntersectionObserver !== 'function') { return; }
    var busy = false; /* one fetch in flight; a failed fetch unsets it */

    var units = Kip.$$('.chapter-unit', host);
    var last = units[units.length - 1];
    if (!last) { return; }
    /* The sentinel sits after the last chapter-unit (ahead of the noscript
       chapter-nav on the first page). It needs a real box: a display:none
       element never intersects, so CSS gives it one silent pixel. */
    var sentinel = document.createElement('div');
    sentinel.className = 'reader-sentinel';
    sentinel.setAttribute('aria-hidden', 'true');
    host.insertBefore(sentinel, last.nextElementSibling);

    var io = new IntersectionObserver(function (entries) {
      for (var i = 0; i < entries.length; i++) {
        if (entries[i].isIntersecting) { load(); return; }
      }
    }, { rootMargin: '600px' });
    io.observe(sentinel);

    var load = function () {
      if (busy || nextUrl === '') { return; }
      busy = true;
      fetch(nextUrl, { credentials: 'same-origin' }).then(function (res) {
        if (!res.ok) { throw new Error('fragment ' + res.status); }
        return res.text();
      }).then(function (html) {
        /* Parse with <template>: scripts inside a detached template never
           run, and the unit arrives as inert nodes ready to insert. */
        var t = document.createElement('template');
        t.innerHTML = String(html);
        var unit = t.content.querySelector('.chapter-unit');
        if (!unit) { io.disconnect(); busy = false; return; } /* empty or missing unit: stop */
        host.insertBefore(unit, sentinel);
        var next = unit.getAttribute('data-next-url') || '';
        nextUrl = next;
        reader.setAttribute('data-next-url', next);
        var readUrl = unit.getAttribute('data-read-url');
        if (readUrl) { window.history.replaceState(null, '', readUrl); }
        if (next === '') { io.disconnect(); } /* the last chapter: stop */
        busy = false;
      }).catch(function () { busy = false; });
    };
  });
})(window.Kip);
