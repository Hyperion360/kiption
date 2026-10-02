/* Kiption enhancement layer, keys module. Reader keyboard shortcuts: J/K
 * (or the arrow keys) move between chapters, F toggles focus mode, T opens
 * the text settings, Escape closes a sheet or leaves focus. Every
 * destination rides a data attribute the server rendered on .reader - this
 * file invents no URL - and typing in a field or an open dialog never
 * triggers one. The loader appends this file only when the page carries
 * [data-js-module="keys"], and window.Kip exists before any module runs. */
'use strict';
(function (Kip) {
  if (!Kip) { return; }
  Kip.init(function () {
    var reader = Kip.$('[data-js-module="keys"]');
    if (!reader) { return; }
    var bar = Kip.$('.hint-keys', reader);
    if (bar) { bar.hidden = false; } /* the module runs: the caps are real now */

    var go = function (url) { if (url) { window.location.href = url; } };
    var inFocus = function () { return window.location.search.indexOf('focus=1') > -1; };
    var openSheet = function () { return Kip.$('.sheet:target'); };
    /* True when the keystroke belongs to a form field or is aimed inside an
       open dialog: those surfaces own the keyboard. */
    var claimed = function (el) {
      if (!el || el.nodeType !== 1) { return false; }
      var tag = (el.tagName || '').toLowerCase();
      if (tag === 'input' || tag === 'textarea' || tag === 'select' || el.isContentEditable) { return true; }
      return el.closest('[role="dialog"]') !== null || el.closest('.sheet:target') !== null;
    };

    document.addEventListener('keydown', function (e) {
      if (e.metaKey || e.ctrlKey || e.altKey || e.defaultPrevented) { return; }
      var key = typeof e.key === 'string' ? e.key.toLowerCase() : '';
      var handled = false;
      if (key === 'escape') {
        /* Escape always works, from a field or an open sheet too. */
        if (openSheet()) { window.location.hash = 'sheet-close'; handled = true; }
        else if (inFocus() && reader.dataset.exitFocus) { go(reader.dataset.exitFocus); handled = true; }
      } else if (key === 'f') {
        if (claimed(e.target)) { return; }
        if (inFocus()) { if (reader.dataset.exitFocus) { go(reader.dataset.exitFocus); handled = true; } }
        else if (reader.dataset.focusUrl) { go(reader.dataset.focusUrl); handled = true; }
      } else {
        /* A dialog is open: navigation while it shows confuses, so J/K/T
           wait for Escape to close it. */
        if (claimed(e.target) || openSheet()) { return; }
        if (key === 'j' || key === 'arrowright') { go(reader.dataset.next); handled = true; }
        else if (key === 'k' || key === 'arrowleft') { go(reader.dataset.prev); handled = true; }
        else if (key === 't') {
          var panel = document.getElementById('text');
          if (panel) {
            var cs = window.getComputedStyle(panel);
            /* The sheet spell: hidden or fixed-overlay text settings open
               through the hash; a static panel scrolls into view. */
            if (cs.display === 'none' || cs.position === 'fixed') { window.location.hash = '#text'; }
            else { panel.scrollIntoView({ behavior: 'smooth', block: 'start' }); }
            handled = true;
          }
        }
      }
      if (handled) { e.preventDefault(); } /* only when the key was handled */
    });
  });
})(window.Kip);
