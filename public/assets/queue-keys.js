/* Kiption enhancement layer, queue-keys module. The review queue's
 * shortcuts: J and K step through the waiting items, A approves the one in
 * the pane, R opens its Remove reason. The server renders the hint line
 * hidden, the links and the buttons; this file reveals the hints and
 * invents no URL. Typing in a field never triggers a shortcut. The loader
 * appends this file only when the page carries
 * [data-js-module~="queue-keys"]. */
'use strict';
(function (root) {
  var ACTIONS = { j: 'next', k: 'prev', a: 'approve', r: 'remove' };
  function isTyping(el) {
    if (!el || !el.tagName) { return false; }
    var tag = String(el.tagName).toLowerCase();
    return tag === 'input' || tag === 'textarea' || tag === 'select' || !!el.isContentEditable;
  }
  /* What a keystroke asks for, or null when it is not ours to take. */
  function keyAction(e) {
    if (e.metaKey || e.ctrlKey || e.altKey || e.defaultPrevented || isTyping(e.target)) { return null; }
    var key = typeof e.key === 'string' ? e.key.toLowerCase() : '';
    return Object.prototype.hasOwnProperty.call(ACTIONS, key) ? ACTIONS[key] : null;
  }
  if (typeof module !== 'undefined' && module.exports) { module.exports = { keyAction: keyAction }; }
  var Kip = root.Kip;
  if (!Kip || !root.document) { return; }
  Kip.init(function () {
    var hints = Kip.$('[data-js-module~="queue-keys"]');
    if (!hints) { return; }
    var page = hints.closest('.queue-page') || document;
    hints.hidden = false; /* the module runs: the caps are real now */
    page.classList.add('has-keys');
    var panel = Kip.$('.queue-remove', page);
    var cancel = Kip.$('[data-queue-cancel]', page);
    if (cancel) {
      /* A plain link without this script; with it, Cancel just closes the panel in place. */
      cancel.addEventListener('click', function (e) {
        e.preventDefault();
        if (panel) { panel.open = false; var s = panel.querySelector('summary'); if (s) { s.focus(); } }
      });
    }
    /* A ready-made reason shows its words, so the moderator can edit them
       before sending; without this script the server fills them in. */
    var words = Kip.$('textarea[name="reason"]', page);
    var said = '';
    Array.prototype.forEach.call(page.querySelectorAll('input[name="reason_preset"]'), function (radio) {
      radio.addEventListener('change', function () {
        if (words && (words.value === '' || words.value === said)) { said = radio.getAttribute('data-text') || ''; words.value = said; }
      });
    });
    function step(delta) {
      var links = Array.prototype.slice.call(page.querySelectorAll('.queue-list a'));
      var at = -1;
      links.forEach(function (a, i) { if (a.getAttribute('aria-current') === 'true') { at = i; } });
      var to = links[at + delta];
      if (to) { root.location.href = to.href; }
    }
    document.addEventListener('keydown', function (e) {
      var action = keyAction(e);
      if (!action) { return; }
      if (action === 'next') { step(1); }
      else if (action === 'prev') { step(-1); }
      else if (action === 'approve') {
        var ok = Kip.$('[data-queue-approve]', page);
        if (!ok) { return; }
        ok.click();
      } else if (action === 'remove') {
        if (!panel) { return; }
        panel.open = true;
        var first = panel.querySelector('input, textarea');
        if (first) { first.focus(); }
      }
      e.preventDefault();
    });
  });
})(typeof window !== 'undefined' ? window : globalThis);
