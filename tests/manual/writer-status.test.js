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

/* Review regressions: attach after editing has begun, and count actual live
 * region DOM mutations rather than only comparing the resulting string. */
async function runWriterStatusReviewDOMTests(Kip) {
  'use strict';
  var checks = [];
  function check(condition, name) { checks.push({ name: name, passed: !!condition }); }
  var form = document.createElement('form');
  form.dataset.jsModule = 'writer-status';
  form.dataset.writerSaved = '1';
  form.dataset.writerLabels = JSON.stringify({ unsaved: 'Unsaved', saved: 'Saved', saving: 'Saving', words: '{n} words', selection: '{n} selected words' });
  form.innerHTML = '<textarea name="content">Stored text</textarea><input type="checkbox" name="completed"><input type="radio" name="rating" value="one" checked><input type="radio" name="rating" value="two"><select name="category"><option value="a">A</option><option value="b" selected>B</option></select><select name="tags" multiple><option value="a" selected>A</option><option value="b">B</option><option value="c" selected>C</option></select><button type="submit">Save</button><span data-writer-state role="status" aria-live="polite">Saved</span>';
  document.body.appendChild(form);
  var state = form.querySelector('[data-writer-state]'), box = form.elements.content;
  var observer, mutations = 0;
  try {
    var initial = Kip.writerStatus.snapshot(form);
    box.value = 'Stored text with early typing';
    form.elements.completed.checked = true;
    form.elements.rating[1].checked = true;
    form.elements.category.value = 'a';
    form.elements.tags.options[2].selected = false;
    box.dispatchEvent(new Event('input', { bubbles: true }));
    check(!form.kipStatus, 'typing happens before status attaches');
    await new Promise(function (resolve) { setTimeout(resolve, 20); });
    Kip.writerStatus.init(document);
    check(form.kipStatus.dirty() && state.textContent === 'Unsaved', 'delayed status does not adopt early edits as saved');
    check(Kip.writerStatus.snapshot(form, true) === initial, 'server defaults preserve radio, checkbox, single-select, and every multi-select choice');
    form.reset(); box.dispatchEvent(new Event('input', { bubbles: true }));
    check(!form.kipStatus.dirty() && state.textContent === 'Saved', 'native reset restores the server baseline');
    observer = new MutationObserver(function (records) { mutations += records.length; });
    observer.observe(state, { childList: true, characterData: true, subtree: true });
    box.value += ' first edit'; box.dispatchEvent(new Event('input', { bubbles: true }));
    await Promise.resolve();
    check(mutations === 1, 'first dirty transition mutates the live region once');
    mutations = 0;
    for (var i = 0; i < 3; i++) {
      box.value += ' more'; box.dispatchEvent(new Event('input', { bubbles: true }));
    }
    await Promise.resolve();
    check(mutations === 0, 'additional dirty keystrokes do not mutate the live region');
    form.addEventListener('submit', function (event) { event.preventDefault(); });
    form.requestSubmit(); await Promise.resolve();
    check(mutations === 1 && state.textContent === 'Saving', 'Saving transition is announced once');
    mutations = 0; form.requestSubmit(); await Promise.resolve();
    check(mutations === 0, 'identical Saving state does not mutate the live region');
    return checks;
  } finally {
    if (observer) observer.disconnect();
    if (form.kipStatus) form.kipStatus.destroy();
    form.remove();
  }
}
