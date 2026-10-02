/* Kiption enhancement layer, infinite module. Two shapes share it:
 *
 * Reader: when the sentinel nears the viewport the module fetches the next
 * chapter's fragment and inserts its .chapter-unit before the sentinel. An
 * append is often a prefetch, so nothing reader-facing changes on insert.
 * The reader chrome follows the ACTIVE chapter instead: the last unit
 * whose article top has crossed the viewport middle (the position
 * module's rule). On activation the module copies that unit's server-built
 * unit-state template into the page: the read URL into history
 * (data-read-url, keeping ?focus=1), the keys module's prev/next/focus/
 * exit/Text targets, the Text form's return_to, the bar's chapter count,
 * both bookmark controls (member forms, or login links once a session has
 * expired), the contents list's current row, and the visible focus-mode
 * exit links; once chapters stack it retires the opening chapter's static
 * prev/next block. For members it then POSTs the unit's data-progress-url
 * for each newly reached chapter, counting a chapter only once the server
 * accepts it (the server never moves progress backwards).
 *
 * Listing (Recent, categories): the list carries data-next-url, the older
 * page's URL. The module fetches it with fragment=1, appends the returned
 * li.story-card nodes before an li sentinel, and bumps the page number;
 * a page shorter than the first one, or an empty one, ends the chain.
 *
 * The module invents no URL or label: every target and every control is
 * server-rendered. One fetch in flight; a failed fetch re-arms the
 * observer a few times with a growing delay, then stops. Noscript readers
 * keep the plain next-chapter and pager links; the sentinel is the
 * module's own creation, so the server-rendered bytes stay inert. The
 * loader appends this file only when the page carries
 * [data-js-module="infinite"], and window.Kip exists before any module
 * runs. */
'use strict';
(function (Kip) {
  if (!Kip) { return; }
  var MAX_RETRIES = 3;

  /* The shared fetch loop: one observer on the sentinel, one fetch in
     flight, bounded retries. fetchNext resolves true while more remain. */
  var chain = function (sentinel, fetchNext) {
    var busy = false, retries = 0;
    var io = new IntersectionObserver(function (entries) {
      for (var i = 0; i < entries.length; i++) {
        if (entries[i].isIntersecting) { load(); return; }
      }
    }, { rootMargin: '600px' });
    var rearm = function () { io.unobserve(sentinel); io.observe(sentinel); };
    var load = function () {
      if (busy) { return; }
      busy = true;
      fetchNext().then(function (more) {
        busy = false; retries = 0;
        if (!more) { io.disconnect(); return; }
        rearm(); /* the sentinel may still be in the band: keep filling */
      }).catch(function () { busy = false; retry(); });
    };
    var retry = function () {
      if (++retries > MAX_RETRIES) { io.disconnect(); return; }
      window.setTimeout(rearm, 2000 * retries);
    };
    io.observe(sentinel);
  };

  var getHtml = function (url) {
    return fetch(url, { credentials: 'same-origin' }).then(function (res) {
      if (!res.ok) { throw new Error('fragment ' + res.status); }
      return res.text();
    }).then(function (html) {
      /* Parse with <template>: scripts inside a detached template never
         run, and the nodes arrive inert, ready to insert. */
      var t = document.createElement('template');
      t.innerHTML = String(html);
      return t.content;
    });
  };

  var reader = function (host) {
    var shell = host.closest('.reader') || host;
    var nextUrl = shell.getAttribute('data-next-url') || '';
    var units = Kip.$$('.chapter-unit', host);
    var opening = units[units.length - 1];
    if (!opening) { return; }
    var token = Kip.$('.reader input[name="_token"]');
    var positionOf = function (unit) {
      var st = unit.querySelector('template.unit-state');
      return st ? parseInt(st.getAttribute('data-position'), 10) || 0 : 0;
    };
    /* The page open already recorded the opening chapter. acked only moves
       on a 2xx, so a failed write is retried the next time that chapter
       (or a later one) becomes active; pending stops duplicate sends. */
    var acked = positionOf(opening), pending = {};
    var current = opening;

    var swap = function (selector, replacement) {
      var live = Kip.$(selector);
      if (live && replacement) { live.replaceWith(replacement.cloneNode(true)); }
    };
    var activate = function (unit) {
      if (unit === current) { return; }
      current = unit;
      var state = unit.querySelector('template.unit-state');
      var readUrl = unit.getAttribute('data-read-url') || '';
      if (readUrl) { window.history.replaceState(null, '', readUrl + window.location.search); }
      if (!state) { return; }
      ['data-prev', 'data-next', 'data-focus-url', 'data-exit-focus', 'data-text-url'].forEach(function (k) {
        shell.setAttribute(k, state.getAttribute(k) || '');
      });
      var back = Kip.$('.text-settings input[name="return_to"]');
      if (back && readUrl) { back.value = readUrl; }
      var exit = state.getAttribute('data-exit-focus');
      if (exit) { Kip.$$('.focus-hint a, .reader-dock a[href^="/story/read/"]').forEach(function (a) { a.setAttribute('href', exit); }); }
      var parts = state.content;
      var slot = function (name) { var s = parts.querySelector('[data-slot="' + name + '"]'); return s ? s.firstElementChild : null; };
      swap('.reader-bar .bar-count', parts.querySelector('.bar-count'));
      swap('.rt-ctls > form, .rt-ctls > a[href="/auth/login"]', slot('rt'));
      swap('.reader-bar > form.bar-bookmark, .reader-bar > a[href="/auth/login"]', slot('bar'));
      Kip.$$('#contents .chapter-list li').forEach(function (li) {
        var a = li.querySelector('a');
        var on = !!a && a.getAttribute('href') === readUrl;
        li.classList.toggle('is-current', on);
        if (a) { if (on) { a.setAttribute('aria-current', 'page'); } else { a.removeAttribute('aria-current'); } }
      });
      var progressUrl = state.getAttribute('data-progress-url');
      var n = positionOf(unit);
      if (progressUrl && token && n > acked && !pending[n]) {
        pending[n] = true;
        var body = new FormData();
        body.append('_token', token.value);
        fetch(progressUrl, { method: 'POST', body: body, credentials: 'same-origin', keepalive: true })
          .then(function (res) { if (res.ok && n > acked) { acked = n; } })
          .catch(function () {})
          .then(function () { delete pending[n]; });
      }
    };

    /* Activation: an article intersects the top half of the viewport once
       its top has crossed the middle (until it scrolls away above). The
       last such article in document order is the one being read. */
    var visible = [];
    var watcher = new IntersectionObserver(function (entries) {
      entries.forEach(function (e) {
        var unit = e.target.closest('.chapter-unit');
        var at = visible.indexOf(unit);
        if (e.isIntersecting && at < 0) { visible.push(unit); }
        if (!e.isIntersecting && at >= 0) { visible.splice(at, 1); }
      });
      var all = Kip.$$('.chapter-unit', host);
      var active = null;
      all.forEach(function (u) { if (visible.indexOf(u) >= 0) { active = u; } });
      if (active) { activate(active); }
    }, { rootMargin: '0px 0px -50% 0px' });
    units.forEach(function (u) { var a = u.querySelector('.h-entry'); if (a) { watcher.observe(a); } });

    if (nextUrl === '') { return; }
    /* The sentinel sits after the last chapter-unit (ahead of the noscript
       chapter-nav on the first page). It needs a real box: a display:none
       element never intersects, so CSS gives it one silent pixel. */
    var sentinel = document.createElement('div');
    sentinel.className = 'reader-sentinel';
    sentinel.setAttribute('aria-hidden', 'true');
    host.insertBefore(sentinel, opening.nextElementSibling);
    chain(sentinel, function () {
      return getHtml(nextUrl).then(function (doc) {
        var unit = doc.querySelector('.chapter-unit');
        if (!unit) { return false; } /* empty or missing unit: stop */
        host.insertBefore(unit, sentinel);
        shell.classList.add('is-stacked');
        var article = unit.querySelector('.h-entry');
        if (article) { watcher.observe(article); }
        nextUrl = unit.getAttribute('data-next-url') || '';
        shell.setAttribute('data-next-url', nextUrl);
        return nextUrl !== ''; /* the last chapter: stop */
      });
    });
  };

  var listing = function (list) {
    var next = list.getAttribute('data-next-url') || '';
    var perPage = Kip.$$('.story-card', list).length;
    if (next === '' || perPage === 0) { return; }
    var url = new URL(next, window.location.href);
    var sentinel = document.createElement('li');
    sentinel.className = 'reader-sentinel';
    sentinel.setAttribute('aria-hidden', 'true');
    list.appendChild(sentinel);
    chain(sentinel, function () {
      var fetchUrl = new URL(url.href);
      fetchUrl.searchParams.set('fragment', '1');
      return getHtml(fetchUrl.href).then(function (doc) {
        var cards = doc.querySelectorAll('.story-card');
        for (var i = 0; i < cards.length; i++) { list.insertBefore(cards[i], sentinel); }
        url.searchParams.set('page', String((parseInt(url.searchParams.get('page'), 10) || 1) + 1));
        list.setAttribute('data-next-url', url.pathname + url.search);
        return cards.length >= perPage;
      });
    });
  };

  Kip.init(function () {
    var host = Kip.$('[data-js-module="infinite"]');
    if (!host || typeof window.IntersectionObserver !== 'function') { return; }
    if (host.querySelector('.chapter-unit')) { reader(host); }
    else if (host.classList.contains('story-list')) { listing(host); }
  });
})(window.Kip);
