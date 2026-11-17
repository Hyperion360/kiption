/* Kiption enhancement layer, adminforms module. Three small helps on every
 * admin form:
 *   - leaving a screen with changes that were typed and not saved asks
 *     first (the browser's own question, so it needs no wording here);
 *   - a long text box grows with what is typed instead of scrolling inside
 *     itself;
 *   - a field with a length limit shows how much of it is used while the
 *     owner is in it (the wording comes from data-count-text).
 *   - a select inside a form marked data-autosubmit saves when it changes
 *     (the members role picker; without scripting its Set button shows).
 * With scripting off the forms work as they always did. */
'use strict';
(function (Kip) {
  if (!Kip) { return; }
  Kip.init(function () {
    var shell = document.querySelector('[data-js-module="adminforms"]');
    var main = shell && shell.querySelector('main');
    if (!main) { return; }

    function counts(el) {
      var form = el && el.form;
      return !!form && (form.getAttribute('method') || '').toLowerCase() === 'post' && !form.hasAttribute('data-unsaved-skip');
    }
    /* Unsaved changes, per form: saving one section of a screen must not
       forget what was typed in another. A screen that came back with an
       error and still holds what was typed starts out as changed; one that
       came back with empty fields has nothing to lose. */
    var dirty = [];
    function mark(el) { if (counts(el) && dirty.indexOf(el.form) < 0) { dirty.push(el.form); } }
    if (main.querySelector('.form-error, .error') !== null) {
      Array.prototype.forEach.call(main.querySelectorAll('input[type="text"], input:not([type]), input[type="email"], input[type="url"], input[type="number"], textarea'), function (f) {
        if (f.value !== '') { mark(f); }
      });
    }
    main.addEventListener('input', function (e) { mark(e.target); });
    main.addEventListener('change', function (e) { mark(e.target); });
    main.addEventListener('change', function (e) {
      var form = e.target && e.target.form;
      if (e.target.tagName === 'SELECT' && form && form.hasAttribute('data-autosubmit')) {
        if (form.requestSubmit) { form.requestSubmit(); } else { form.submit(); }
      }
    });
    document.addEventListener('submit', function (e) {
      var i = dirty.indexOf(e.target);
      if (i >= 0) { dirty.splice(i, 1); }
    });
    window.addEventListener('beforeunload', function (e) {
      if (dirty.length === 0) { return; }
      e.preventDefault();
      e.returnValue = '';
    });

    /* Text boxes that grow. */
    function grow(t) {
      t.style.height = 'auto';
      t.style.height = Math.min(t.scrollHeight + 2, Math.round(window.innerHeight * 0.7)) + 'px';
    }
    Array.prototype.forEach.call(main.querySelectorAll('textarea'), function (t) {
      t.addEventListener('input', function () { grow(t); });
      if (t.value !== '') { grow(t); }
    });

    /* How much of a limit is used. */
    var wording = shell.getAttribute('data-count-text') || '';
    function count(field) {
      var max = parseInt(field.getAttribute('maxlength') || '', 10);
      if (!wording || !max || field.type === 'hidden' || field.type === 'search') { return; }
      var out = field._count;
      if (!out) {
        out = field._count = document.createElement('span');
        out.className = 'char-count';
        (field.closest('label') || field).insertAdjacentElement('afterend', out);
      }
      var used = field.value.length;
      out.textContent = wording.replace('{n}', String(used)).replace('{max}', String(max));
      out.classList.toggle('char-count-near', used >= max * 0.9);
      out.hidden = false;
    }
    main.addEventListener('focusin', function (e) { if (e.target.matches('input[maxlength], textarea[maxlength]')) { count(e.target); } });
    main.addEventListener('input', function (e) { if (e.target.matches('input[maxlength], textarea[maxlength]')) { count(e.target); } });
    main.addEventListener('focusout', function (e) {
      var out = e.target._count;
      if (out && !out.classList.contains('char-count-near')) { out.hidden = true; }
    });
  });
})(window.Kip);
