/* Form status and counts. Saving starts only on a valid native submit;
 * saved status comes from the server-rendered page. No draft data is stored. */
'use strict';
(function (root, factory) {
  var api = factory(root);
  if (typeof module !== 'undefined' && module.exports) module.exports = api;
  if (root.Kip) { root.Kip.writerStatus = api; root.Kip.init(function () { api.init(root.document); }); }
})(typeof window !== 'undefined' ? window : globalThis, function (root) {
  function value(el, defaults) {
    if (['checkbox', 'radio'].includes(el.type)) return defaults ? el.defaultChecked : el.checked;
    if (el.type === 'select-multiple' || (defaults && el.type === 'select-one')) {
      var options = Array.from(el.options);
      var selected = options.filter(function (option) { return defaults ? option.defaultSelected : option.selected; });
      if (el.type === 'select-multiple') return selected.map(function (option) { return option.value; });
      // A single select uses the last explicit default, or its first enabled
      // option when rendered as a dropdown with no explicit selection.
      var option = selected[selected.length - 1];
      if (!option && el.size <= 1) option = options.find(function (item) {
        return !item.disabled && !(item.parentElement && item.parentElement.tagName === 'OPTGROUP' && item.parentElement.disabled);
      });
      return option ? option.value : '';
    }
    return defaults ? el.defaultValue : el.value;
  }
  function snapshot(form, defaults) {
    return JSON.stringify(Array.from(form.elements).filter(function (el) {
      return el.name && !el.disabled && !['hidden', 'password', 'submit', 'button'].includes(el.type);
    }).map(function (el) {
      return [el.name, value(el, defaults)];
    }));
  }
  function summary(current, initial, count) { return { dirty: current !== initial, count: count }; }
  function attach(form, env) {
    env = env || root;
    var initial = snapshot(form, true), submitting = false, timer, rejected = form.dataset.writerRejected === '1';
    var touchedPassword = form.kipPasswordTouched === true || Array.from(form.elements).some(function (el) {
      return el.type === 'password' && el.value !== '';
    });
    var box = form.elements.namedItem('content'), state = form.querySelector('[data-writer-state]');
    var count = form.querySelector('[data-writer-count]'), selected = form.querySelector('[data-writer-selection]');
    var button = form.querySelector('[data-writer-count-selection]'), bound = false;
    var labels = JSON.parse(form.getAttribute('data-writer-labels') || '{}');
    var listeners = [];
    function on(target, type, fn) { target.addEventListener(type, fn); listeners.push(function () { target.removeEventListener(type, fn); }); }
    function unload(e) { e.preventDefault(); e.returnValue = ''; }
    function say(message) { if (state && state.textContent !== message) state.textContent = message; }
    function dirty() { return rejected || touchedPassword || snapshot(form) !== initial; }
    function protect() {
      var needed = dirty() && !submitting;
      if (needed === bound) return;
      env[needed ? 'addEventListener' : 'removeEventListener']('beforeunload', unload);
      bound = needed;
    }
    function words() {
      if (box && count && root.Kip && root.Kip.wordCount) count.textContent = labels.words.replace('{n}', root.Kip.wordCount(box.value));
    }
    function update(e) {
      if (e && e.target.type === 'password') touchedPassword = true;
      submitting = false; protect();
      say(dirty() ? labels.unsaved : (form.dataset.writerSaved === '1' ? labels.saved : ''));
      env.clearTimeout(timer); timer = env.setTimeout(words, 150);
    }
    on(form, 'input', update); on(form, 'change', update);
    on(form, 'submit', function () { submitting = true; protect(); say(labels.saving); });
    on(form, 'keydown', function (e) {
      if (!e.isComposing && (e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 's') {
        e.preventDefault(); if (!submitting) form.requestSubmit();
      }
    });
    on(env, 'pageshow', function () { submitting = false; update(); });
    if (button && box && selected) on(button, 'click', function () {
      var text = box.value.slice(box.selectionStart, box.selectionEnd);
      selected.textContent = labels.selection.replace('{n}', root.Kip.wordCount(text));
    });
    update(); words(); if(button)button.hidden=false;
    return { dirty: dirty, destroy: function () {
      env.clearTimeout(timer); listeners.forEach(function (off) { off(); }); env.removeEventListener('beforeunload', unload);
    } };
  }
  function init(doc) {
    if (!root.Kip || !root.Kip.wordCount) return;
    doc.querySelectorAll('form[data-js-module~="writer-status"]').forEach(function (form) {
      if (form.kipStatus) return;
      try { form.kipStatus = attach(form); } catch (_) { /* Native form remains available. */ }
    });
  }
  return { snapshot: snapshot, summary: summary, attach: attach, init: init };
});
