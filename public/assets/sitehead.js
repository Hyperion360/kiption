/* Kiption enhancement layer, sitehead module. The header works with scripting
 * off: Browse, the theme picker and the account menu are <details>, and the
 * phone menu is a :target sheet. This only adds what a plain page cannot:
 * Esc closes an open panel and hands focus back to its button, a click
 * outside closes it, "/" jumps to the search box, and the theme buttons say
 * which one is on (aria-pressed, read from the root data-theme). */
'use strict';
(function (root) {
  function isTyping(el) {
    if (!el || !el.tagName) { return false; }
    var tag = String(el.tagName).toLowerCase();
    return tag === 'input' || tag === 'textarea' || tag === 'select' || !!el.isContentEditable;
  }
  if (typeof module !== 'undefined' && module.exports) { module.exports = { isTyping: isTyping }; }
  var Kip = root.Kip;
  if (!Kip || !root.document) { return; }
  Kip.init(function () {
    var head = Kip.$('[data-js-module~="sitehead"]');
    if (!head) { return; }
    var panels = Array.prototype.slice.call(head.querySelectorAll('details[name="site-menus"]'));
    function closeAll(except) {
      panels.forEach(function (d) { if (d !== except && d.open) { d.open = false; } });
    }
    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape') {
        var open = panels.filter(function (d) { return d.open; })[0];
        if (open) { open.open = false; var s = open.querySelector('summary'); if (s) { s.focus(); } }
        return;
      }
      if (e.key === '/' && !e.ctrlKey && !e.metaKey && !e.altKey && !isTyping(document.activeElement)) {
        var box = head.querySelector('.nav-search input');
        if (box && box.offsetParent !== null) { e.preventDefault(); closeAll(null); box.focus(); box.select(); }
      }
    });
    document.addEventListener('click', function (e) {
      panels.forEach(function (d) { if (d.open && !d.contains(e.target)) { d.open = false; } });
    });
    var theme = document.documentElement.getAttribute('data-theme') || 'auto';
    Array.prototype.forEach.call(document.querySelectorAll('.theme-seg button[name="theme"]'), function (b) {
      b.setAttribute('aria-pressed', b.value === theme ? 'true' : 'false');
    });
  });
})(typeof window !== 'undefined' ? window : globalThis);
