/* Run runWriterStatusDOMTests(Kip) in a browser with writer-status.js loaded.
 * Native validation and submit events require a real browser DOM. */
async function runWriterStatusDOMTests(Kip) {
  'use strict';
  var passed = [];
  function check(condition, name) { if (!condition) throw new Error(name); passed.push(name); }
  var form = document.createElement('form');
  form.dataset.jsModule = 'writer-status';
  form.dataset.writerSaved = '0';
  form.dataset.writerRejected = '0';
  form.dataset.writerLabels = JSON.stringify({ words: '{n} words', selection: '{n} selected words', unsaved: 'Unsaved', saved: 'Saved', saving: 'Saving' });
  form.innerHTML = '<input name="title" required value="Chapter"><textarea name="content" required>one two</textarea><input type="password" name="access_password"><button type="submit">Save</button><span data-writer-state></span><output data-writer-count></output><button type="button" data-writer-count-selection hidden>Count Selection</button><span data-writer-selection></span>';
  document.body.appendChild(form);
  Kip.writerStatus.init(document);
  var box = form.elements.namedItem('content'), state = form.querySelector('[data-writer-state]');
  var count = form.querySelector('[data-writer-count]'), save = form.querySelector('button[type=submit]');
  var submissions = 0;
  form.addEventListener('submit', function (event) { event.preventDefault(); submissions++; });
  function input(value) { box.value = value; box.dispatchEvent(new Event('input', { bubbles: true })); }
  function shortcut(options) { return form.dispatchEvent(new KeyboardEvent('keydown', Object.assign({ key: 's', ctrlKey: true, bubbles: true, cancelable: true }, options))); }
  try {
    check(state.textContent === '' && !save.disabled, 'pristine form has an enabled native Save');
    check(count.textContent === '2 words', 'initial count is visible');
    input(''); shortcut();
    check(submissions === 0 && state.textContent === 'Unsaved' && !save.disabled, 'empty required chapter blocks save without showing Saving');
    input('three whole words');
    check(state.textContent === 'Unsaved' && count.textContent === '2 words', 'dirty feedback is immediate while counting is debounced');
    await new Promise(function (resolve) { setTimeout(resolve, 180); });
    check(count.textContent === '3 words', 'debounced count uses the shared word rule');
    box.setSelectionRange(0, 5);
    form.querySelector('[data-writer-count-selection]').click();
    check(form.querySelector('[data-writer-selection]').textContent === '1 selected words', 'selection count uses the native textarea selection');
    shortcut({ isComposing: true }); check(submissions === 0, 'composition does not trigger Save');
    shortcut(); shortcut(); check(submissions === 1 && state.textContent === 'Saving', 'valid Save shortcut submits exactly once');
    window.dispatchEvent(new PageTransitionEvent('pageshow', { persisted: true }));
    check(state.textContent === 'Unsaved' && form.kipStatus.dirty(), 'restored dirty page does not claim Saved');
    input('one two'); check(!form.kipStatus.dirty(), 'reverting text restores the initial clean state');
    var password = form.elements.namedItem('access_password');
    password.value = 'temporary'; password.dispatchEvent(new Event('input', { bubbles: true }));
    password.value = ''; password.dispatchEvent(new Event('input', { bubbles: true }));
    check(form.kipStatus.dirty() && !Kip.writerStatus.snapshot(form).includes('temporary'), 'password edits protect the page without joining the snapshot');
    form.kipStatus.destroy(); delete form.kipStatus;
    form.dataset.writerRejected = '1'; Kip.writerStatus.init(document);
    check(form.kipStatus.dirty() && state.textContent === 'Unsaved', 'rejected page starts dirty before typing');
    return passed;
  } finally {
    if (form.kipStatus) form.kipStatus.destroy();
    form.remove();
  }
}
