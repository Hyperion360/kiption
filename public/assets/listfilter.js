/* Kiption enhancement layer, listfilter module. A long admin list narrows
 * as the owner types. The marked element holds a search box and names its
 * rows in data-filter-rows; a row stays when its text contains what was
 * typed. An element marked data-filter-none shows when no row is left, and
 * one marked data-filter-note shows while anything is typed (the members
 * list uses it to say Enter searches every page). With scripting off the
 * box stays hidden, or keeps its plain search form, and the list is whole. */
'use strict';
(function (Kip) {
  if (!Kip) { return; }
  Kip.init(function () {
    Array.prototype.forEach.call(document.querySelectorAll('[data-js-module="listfilter"]'), function (wrap) {
      var box = wrap.querySelector('input[type="search"]');
      var rows = document.querySelectorAll(wrap.getAttribute('data-filter-rows') || '');
      if (!box || !rows.length) { return; }
      var none = wrap.querySelector('[data-filter-none]');
      var note = wrap.querySelector('[data-filter-note]');
      wrap.hidden = false;
      function narrow() {
        var q = box.value.trim().toLowerCase();
        var left = 0;
        Array.prototype.forEach.call(rows, function (row) {
          row.hidden = q !== '' && row.textContent.toLowerCase().indexOf(q) === -1;
          if (!row.hidden) { left++; }
        });
        if (none) { none.hidden = left > 0; }
        if (note) { note.hidden = q === ''; }
      }
      box.addEventListener('input', narrow);
      if (box.value !== '' && wrap.hasAttribute('data-filter-fresh')) { narrow(); }
    });
  });
})(window.Kip);
