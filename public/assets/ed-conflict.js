/* The editor's conflict screen. Replace is a native submit and needs nothing here.
 * This adds: copy buttons, hiding the editor behind the two cards, and "Use Archive
 * Version" swapping the archive's fields into the editor in place. Without it the
 * editor stays open below the cards and Use Archive Version is a plain link. */
'use strict';
(function (root, factory) {
  var api = factory(root);
  if (typeof module !== 'undefined' && module.exports) module.exports = api;
  if (root.Kip) { root.Kip.edConflict = api; root.Kip.init(function () { api.init(root.document); }); }
})(typeof window !== 'undefined' ? window : globalThis, function (root) {
  var NAMES = ['title', 'content', 'notes_before', 'notes_after', 'publish_at'];
  function fieldValue(el, name, value) {
    // A datetime-local input only takes the "T" spelling of the saved time.
    return name === 'publish_at' && el.type === 'datetime-local' ? String(value).replace(' ', 'T') : value;
  }
  function attach(form, env) {
    env = env || root;
    var section = form.querySelector('[data-ed-conflict]');
    if (!section) return null;
    var labels = {}; try { labels = JSON.parse(section.getAttribute('data-ed-conflict-labels') || '{}'); } catch (_) { /* Plain text below. */ }
    var server = {}; try { server = JSON.parse(form.dataset.writerServer || '{}'); } catch (_) { /* No archive fields: the link still works. */ }
    var col = form.querySelector('[data-ed-col]'), live = form.querySelector('[data-ed-live]');
    var toast = form.querySelector('[data-ed-toast]'), toastTimer = 0;
    // Sighted writers get the same words as a brief toast; the hidden live region is for screen readers.
    function say(message) {
      if (live) live.textContent = message || '';
      if (toast && message && env.setTimeout) { toast.textContent = message; toast.hidden = false; env.clearTimeout(toastTimer); toastTimer = env.setTimeout(function () { toast.hidden = true; }, 2600); }
    }
    if (col) col.setAttribute('data-ed-hide', '');
    // The writer's text is whatever the editor holds now; the archive's is what the 409 carried.
    function textOf(which) {
      if (which === 'theirs') return String(server.content || '');
      var box = form.elements.namedItem('content');
      return box ? String(box.value) : '';
    }
    function copy(which) {
      var text = textOf(which), clip = env.navigator && env.navigator.clipboard;
      if (!clip || !clip.writeText) { say(labels.copyFailed); return Promise.resolve(false); }
      try { return clip.writeText(text).then(function () { say(labels.copied); return true; }, function () { say(labels.copyFailed); return false; }); }
      catch (_) { say(labels.copyFailed); return Promise.resolve(false); }
    }
    function useArchive() {
      NAMES.forEach(function (name) {
        var el = form.elements.namedItem(name); if (!el || !(name in server)) return;
        var value = fieldValue(el, name, server[name]);
        if (name === 'content' && el.kipWriter) el.kipWriter.replace(value, 'restore'); else el.value = value;
      });
      // Only this explicit choice adopts the shown archive revision; the save then compares against it.
      var base = form.elements.namedItem('base_revision');
      if (base && section.dataset.edCurrent) base.value = section.dataset.edCurrent;
      section.remove();
      if (col) { col.removeAttribute('data-ed-hide'); col.removeAttribute('data-ed-conflicted'); }
      form.dispatchEvent(new env.Event('input', { bubbles: true }));
      say(labels.usedArchive);
      var title = form.elements.namedItem('title'); if (title && title.focus) title.focus();
    }
    var listeners = [];
    function on(target, type, fn) { target.addEventListener(type, fn); listeners.push(function () { target.removeEventListener(type, fn); }); }
    section.querySelectorAll('[data-ed-conflict-copy]').forEach(function (button) {
      on(button, 'click', function () { copy(button.getAttribute('data-ed-conflict-copy')); });
    });
    var link = section.querySelector('[data-ed-use-archive]');
    if (link) on(link, 'click', function (e) { e.preventDefault(); useArchive(); });
    return { copy: copy, useArchive: useArchive, destroy: function () { listeners.forEach(function (off) { off(); }); if (col) col.removeAttribute('data-ed-hide'); } };
  }
  function init(doc) {
    doc.querySelectorAll('form[data-js-module~="ed-conflict"]').forEach(function (form) {
      if (form.kipConflict) return;
      try { form.kipConflict = attach(form, root); } catch (_) { /* The native form keeps working. */ }
    });
  }
  return { attach: attach, init: init };
});
