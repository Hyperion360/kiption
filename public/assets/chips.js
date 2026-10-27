/* Kiption enhancement layer, chips. Vanilla ES2017, no dependencies. The
 * filter-chips row scrolls sideways at narrow widths (overflow-x: auto in
 * reader.css) and touch devices hide scrollbars, so this module makes the
 * scroll legible twice: a right-edge fade while the row actually overflows
 * (reader.css styles .is-scrollable:not(.at-end); JS-gated so a page load
 * without scripting never shows a lying fade) and the active chip centered
 * into view on arrival. Without scripting the row keeps its native scroll
 * and the clipped-chip peek. */
'use strict';
(function () {
  var nav = document.querySelector('[data-js="filter-chips"]');
  if (!nav) { return; }
  function sync() {
    var over = nav.scrollWidth > nav.clientWidth + 1;
    nav.classList.toggle('is-scrollable', over);
    var atEnd = nav.scrollLeft + nav.clientWidth >= nav.scrollWidth - 1;
    nav.classList.toggle('at-end', atEnd);
  }
  function reveal() {
    var active = nav.querySelector('.chip.is-active');
    if (active) {
      // Rect deltas, not offsetLeft: offsetParent is whatever ancestor is
      // positioned, rarely the row itself, so offset math centers wrong.
      var before = nav.getBoundingClientRect();
      var chip = active.getBoundingClientRect();
      nav.scrollLeft += (chip.left + chip.width / 2) - (before.left + before.width / 2);
    }
    sync();
  }
  Kip.init(function () {
    reveal();
    window.addEventListener('resize', sync);
    nav.addEventListener('scroll', sync, { passive: true });
  });
})();
