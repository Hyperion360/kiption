/* Kiption enhancement layer, position module. Live reading position: the
 * reader's story-span progressbar, its aria-valuenow, and the percent
 * readout track the scroll as it happens. The module invents no number:
 * the server stamps each chapter's span of the story as
 * data-p-start/data-p-end on the progressbar, and scroll position is only
 * ever mapped into that band (an appended fragment stamps its own span on
 * its article; the active article's stamp wins while it is the one being
 * read). Writes go to the inline style and the accessibility tree, never
 * to a cookie; the bar gains is-live, which retires the CSS scroll-timeline
 * animation so the fill is interpolated once, here. The loader appends this file
 * only when the page carries [data-js-module="position"], and window.Kip
 * exists before any module runs. */
'use strict';
(function (Kip) {
  if (!Kip) { return; }
  Kip.init(function () {
    var bar = Kip.$('[data-js-module="position"]');
    if (!bar || !bar.dataset.pEnd) { return; } /* no server-stamped band, no module */
    var main = Kip.$('.reader-main');
    var pct = Kip.$('[data-js="reader-pct"]');
    var num = function (v) { var n = parseFloat(v); return isFinite(n) ? n : 0; };

    /* The active article: the LAST .h-entry whose top sits above the
       viewport middle. Appended fragments arrive in document order, so a
       later article always wins once it exists. */
    var active = function () {
      var mid = window.innerHeight / 2;
      var found = null;
      if (main) {
        Kip.$$('.h-entry', main).forEach(function (a) {
          if (a.getBoundingClientRect().top <= mid) { found = a; }
        });
      }
      return found;
    };

    /* The fraction of the active article the reader has passed: vertical
       for the ordinary page scroller; in pages mode the active chapter's
       own column deck, measured geometrically so stacked chapters each
       report their own span (the deck-wide scrollLeft/span readout stalled
       or jumped once infinite scroll stacked unequal decks). */
    var fraction = function () {
      if (main && document.documentElement.getAttribute('data-mode') === 'pages') {
        var a2 = active();
        var deck = a2 && a2.querySelector('.prose');
        if (deck) {
          var mr = main.getBoundingClientRect();
          var dr = deck.getBoundingClientRect();
          var span = dr.width - mr.width;
          if (span > 0) {
            var f = (mr.left - dr.left) / span;
            return f < 0 ? 0 : (f > 1 ? 1 : f);
          }
          return 0;
        }
        var spanAll = main.scrollWidth - main.clientWidth;
        if (spanAll > 0) {
          var fAll = main.scrollLeft / spanAll;
          return fAll < 0 ? 0 : (fAll > 1 ? 1 : fAll);
        }
      }
      var a = active();
      if (!a) { return 0; } /* before the first article: the band's start */
      var r = a.getBoundingClientRect();
      if (r.height <= 0) { return 0; }
      var t = (window.innerHeight / 2 - r.top) / r.height;
      return t < 0 ? 0 : (t > 1 ? 1 : t);
    };

    var paint = function () {
      /* The band: the active article's own stamp when it carries one (an
         appended fragment does), else the server attributes on the bar. */
      var src = active();
      if (!src || src.dataset.pEnd === undefined) { src = bar; }
      var start = num(src.dataset.pStart);
      var end = num(src.dataset.pEnd);
      var at = Math.round(start + (end - start) * fraction());
      bar.style.setProperty('--p-end', at + '%');
      bar.setAttribute('aria-valuenow', String(at));
      if (pct) {
        var txt = pct.textContent || '';
        if (/^\d+%$/.test(txt.trim())) { pct.textContent = at + '%'; }
        else if (/Chapter .*·\s*\d+%$/.test(txt)) { pct.textContent = txt.replace(/\d+%$/, at + '%'); }
      }
    };

    /* One rAF in flight at a time: scroll events coalesce into the next
       frame's single paint. Passive: this module never scrolls the page. */
    var queued = false;
    var schedule = function () {
      if (queued) { return; }
      queued = true;
      window.requestAnimationFrame(function () { queued = false; paint(); });
    };
    bar.classList.add('is-live'); /* this module owns the fill now (reader.css) */
    window.addEventListener('scroll', schedule, { passive: true });
    if (main) { main.addEventListener('scroll', schedule, { passive: true }); }
    schedule(); /* the first paint matches wherever a deep link landed */
  });
})(window.Kip);
