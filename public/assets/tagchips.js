/* Kiption enhancement layer, tag chips. Vanilla ES2017, no dependencies.
 * The search rail ships a plain include/exclude checkbox pair for every tag
 * (the no-script form). This module hides that pair and shows one chip per
 * tag instead: a click cycles off, include, leave out, off, writing the two
 * checkboxes underneath and firing their change event so the search module
 * refreshes the results. A finder narrows the chips by name. */
'use strict';
(function () {
  var STATES = ['off', 'include', 'exclude'];
  // A tag with no include box (a leave-out kept from a link, say) only toggles leave out and off.
  function next(state, canInclude) {
    if (canInclude === false) { return state === 'exclude' ? 'off' : 'exclude'; }
    return STATES[(STATES.indexOf(state) + 1) % STATES.length];
  }
  function stateOf(inc, exc) { return exc ? 'exclude' : inc ? 'include' : 'off'; }
  function matches(name, needle) { return needle === '' || name.toLowerCase().indexOf(needle.trim().toLowerCase()) !== -1; }
  function countOf(text) { var m = /\((\d+)\)\s*$/.exec(text || ''); return m ? m[1] : '0'; }
  if (typeof module !== 'undefined' && module.exports) { module.exports = { next: next, stateOf: stateOf, matches: matches, countOf: countOf }; }
  if (typeof document === 'undefined') { return; }
  document.querySelectorAll('[data-js-module~="tagchips"]').forEach(function (root) {
    var fallback = root.querySelector('[data-tag-fallback]');
    var holder = root.querySelector('[data-tag-chips]');
    var find = root.querySelector('[data-tag-find]');
    if (!fallback || !holder) { return; }
    var none = find && find.querySelector('[data-tag-none]');
    var finder = find && find.querySelector('input');
    var made = [];
    var seen = {};
    var group = null;
    var headings = [];
    fallback.querySelectorAll('input[name="tag[]"], input[name="xtag[]"]').forEach(function (first) {
      if (seen[first.value]) { return; }
      seen[first.value] = true;
      var inc = null;
      var xc = null;
      fallback.querySelectorAll('input[name="tag[]"]').forEach(function (i) { if (i.value === first.value) { inc = i; } });
      fallback.querySelectorAll('input[name="xtag[]"]').forEach(function (x) { if (x.value === first.value) { xc = x; } });
      var label = (inc || xc).closest('label');
      var named = label && label.querySelector('[data-count-label]');
      var name = named ? named.getAttribute('data-count-label') : (label ? label.textContent.trim() : first.value);
      var fieldset = (inc || xc).closest('fieldset');
      var legend = fieldset && fieldset.querySelector('legend');
      var groupName = legend ? legend.textContent.trim() : '';
      if (groupName && groupName !== group) {
        group = groupName;
        var heading = document.createElement('span');
        heading.className = 'tag-group';
        heading.textContent = groupName;
        holder.appendChild(heading);
        headings.push({ el: heading, chips: [] });
      }
      var button = document.createElement('button');
      button.type = 'button';
      button.className = 'tag-chip';
      var text = document.createElement('span');
      text.textContent = name;
      var count = document.createElement('span');
      count.className = 'count';
      button.appendChild(text);
      button.appendChild(count);
      function paint() {
        var state = stateOf(inc && inc.checked, xc && xc.checked);
        button.setAttribute('data-state', state);
        var tpl = holder.getAttribute('data-state-' + state) || '{label}';
        button.setAttribute('aria-label', tpl.replace('{label}', name));
        var from = state === 'exclude' && xc ? xc.closest('label') : label;
        var span = from && from.querySelector('[data-search-count]');
        count.textContent = countOf(span && span.textContent);
      }
      button.addEventListener('click', function () {
        var state = next(stateOf(inc && inc.checked, xc && xc.checked), !!inc);
        if (inc) { inc.checked = state === 'include'; }
        if (xc) { xc.checked = state === 'exclude'; }
        paint();
        (inc || xc).dispatchEvent(new Event('change', { bubbles: true }));
      });
      holder.appendChild(button);
      made.push({ name: name, button: button, paint: paint });
      if (headings.length && groupName) { headings[headings.length - 1].chips.push(button); }
      paint();
    });
    function narrow() {
      var needle = finder ? finder.value : '';
      var shown = 0;
      made.forEach(function (c) {
        var ok = matches(c.name, needle);
        c.button.hidden = !ok;
        if (ok) { shown += 1; }
      });
      headings.forEach(function (h) { h.el.hidden = !h.chips.some(function (b) { return !b.hidden; }); });
      if (none) { none.hidden = shown !== 0; }
    }
    if (finder) {
      finder.addEventListener('input', narrow);
      finder.addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); } });
    }
    var form = root.closest('form');
    if (form) { form.addEventListener('kip:counts', function () { made.forEach(function (c) { c.paint(); }); }); }
    fallback.hidden = true;
    holder.hidden = false;
    if (find) { find.hidden = false; }
    var hint = root.querySelector('[data-tag-hint]');
    if (hint) { hint.hidden = false; }
    narrow();
  });
})();
