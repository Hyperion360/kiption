import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { createRequire } from 'node:module';
import vm from 'node:vm';
const require = createRequire(import.meta.url);
const status = require('../../public/assets/writer-status.js');
const { wordCount } = require('../../public/assets/wordcount.js');
const source = () => readFileSync(new URL('../../public/assets/writer-status.js', import.meta.url), 'utf8');
const field = (name, value = '', type = 'text') => ({ name, value, defaultValue: value, type, disabled: false });
const labels = { words: '{n} words', selection: '{n} selected words', unsaved: 'You have unsaved changes.', saved: 'All changes shown here are saved.', saving: 'Saving…' };

class Target extends EventTarget {
  listeners = new Map();
  addEventListener(type, fn) { super.addEventListener(type, fn); if (!this.listeners.has(type)) this.listeners.set(type, new Set()); this.listeners.get(type).add(fn); }
  removeEventListener(type, fn) { super.removeEventListener(type, fn); this.listeners.get(type)?.delete(fn); }
  count(type) { return this.listeners.get(type)?.size || 0; }
}
function fixture({ content = "don't stop", saved = false, rejected = false, beforeAttach = () => {} } = {}) {
  const env = new Target(), timers = new Map();
  let nextTimer = 0;
  env.setTimeout = fn => { timers.set(++nextTimer, fn); return nextTimer; };
  env.clearTimeout = id => timers.delete(id);
  env.flush = () => { const work = [...timers.values()]; timers.clear(); work.forEach(fn => fn()); };
  const form = new Target();
  const box = content === null ? null : Object.assign(field('content', content, 'textarea'), { selectionStart: 0, selectionEnd: 0 });
  form.elements = [field('title', 'Chapter'), field('access_password', '', 'password'), ...(box ? [box] : [])];
  form.elements.namedItem = name => form.elements.find(el => el.name === name) || null;
  form.dataset = { writerSaved: saved ? '1' : '0', writerRejected: rejected ? '1' : '0' };
  const state = { textContent: '' }, count = box ? { textContent: '' } : null;
  const selected = box ? { textContent: '' } : null;
  const button = box ? Object.assign(new Target(), { hidden: true }) : null;
  const nodes = { '[data-writer-state]': state, '[data-writer-count]': count, '[data-writer-selection]': selected, '[data-writer-count-selection]': button };
  form.querySelector = selector => nodes[selector];
  form.getAttribute = name => name === 'data-writer-labels' ? JSON.stringify(labels) : name === 'data-js-module' ? 'writer-status' : null;
  form.requestSubmit = () => { form.submits = (form.submits || 0) + 1; form.dispatchEvent(new Event('submit')); };
  const root = Object.assign(env, { Kip: { wordCount, init() {} }, document: {} });
  vm.runInNewContext(source(), { window: root }, { filename: new URL('../../public/assets/writer-status.js', import.meta.url).pathname });
  const api = root.Kip.writerStatus;
  beforeAttach(form);
  const attached = api.attach(form, env);
  function change(target = box, type = 'input') {
    const event = new Event(type); Object.defineProperty(event, 'target', { value: target }); form.dispatchEvent(event);
  }
  return { env, form, box, state, count, selected, button, api, attached, change, root };
}
function key(form, props) {
  const event = new Event('keydown', { cancelable: true }); Object.assign(event, { key: 's', ctrlKey: true, ...props }); form.dispatchEvent(event); return event;
}

test('snapshot tracks ordered text, selects, newlines, checkbox and radio changes, excluding secrets and noneditable controls', () => {
  const text = field('notes', 'one\ntwo', 'textarea'), select = field('rating', '2', 'select-one');
  const check = { ...field('tags[]', '9', 'checkbox'), checked: false }, radio = { ...field('status', 'draft', 'radio'), checked: true };
  const hidden = field('token', 'hidden-secret', 'hidden'), password = field('access_password', 'password-secret', 'password');
  const disabled = { ...field('ignored', 'disabled-secret'), disabled: true };
  const form = { elements: [text, select, check, radio, hidden, password, disabled, field('', 'unnamed'), field('save', 'go', 'submit'), field('count', '', 'button')] };
  const initial = status.snapshot(form);
  assert.deepEqual(JSON.parse(initial), [['notes', 'one\ntwo'], ['rating', '2'], ['tags[]', false], ['status', true]]);
  for (const item of [text, select, check, radio]) {
    const old = item.checked ?? item.value;
    if ('checked' in item) item.checked = !old; else item.value = 'different';
    assert.notEqual(status.snapshot(form), initial);
    if ('checked' in item) item.checked = old; else item.value = old;
  }
  password.value = 'new-secret'; hidden.value = 'other-token';
  assert.equal(status.snapshot(form), initial);
});

test('dirty state reflects fields independently of word count', () => {
  assert.equal(wordCount("don't stop"), 2);
  assert.deepEqual(status.summary('changed', 'initial', 2), { dirty: true, count: 2 });
  assert.deepEqual(status.summary('initial', 'initial', 999), { dirty: false, count: 999 });
});

test('initial counts and saved status come from the page; updates protect immediately and counts debounce', () => {
  const f = fixture({ saved: true });
  assert.equal(f.count.textContent, '2 words'); assert.equal(f.state.textContent, labels.saved);
  assert.equal(f.env.count('beforeunload'), 0); assert.equal(f.button.hidden, false);
  f.box.value = 'one two three'; f.change();
  assert.equal(f.state.textContent, labels.unsaved); assert.equal(f.env.count('beforeunload'), 1);
  assert.equal(f.count.textContent, '2 words'); f.env.flush(); assert.equal(f.count.textContent, '3 words');
  const leave = new Event('beforeunload', { cancelable: true }); f.env.dispatchEvent(leave); assert.equal(leave.defaultPrevented, true);
  f.box.value = "don't stop"; f.change(); assert.equal(f.env.count('beforeunload'), 0); assert.equal(f.state.textContent, labels.saved);
  f.attached.destroy(); assert.equal(f.form.count('input'), 0); assert.equal(f.env.count('pageshow'), 0);
});

test('a normal unsaved-page GET starts blank, and reverting visible fields clears protection', () => {
  const f = fixture(); assert.equal(f.state.textContent, '');
  f.form.elements[0].value = 'New title'; f.change(f.form.elements[0], 'change'); assert.equal(f.attached.dirty(), true);
  f.form.elements[0].value = 'Chapter'; f.change(f.form.elements[0], 'change'); assert.equal(f.state.textContent, '');
  f.attached.destroy();
});

test('rejected POST and touched passwords stay dirty without retaining password values', () => {
  const rejected = fixture({ rejected: true, saved: true });
  assert.equal(rejected.state.textContent, labels.unsaved); assert.equal(rejected.env.count('beforeunload'), 1); rejected.attached.destroy();
  const f = fixture(); const password = f.form.elements.namedItem('access_password');
  password.value = 'changed-secret'; f.change(password); password.value = ''; f.change(password);
  assert.equal(f.attached.dirty(), true); assert.equal(f.env.count('beforeunload'), 1);
  assert.equal(status.snapshot(f.form).includes('secret'), false); f.attached.destroy();
});

test('selection count is on demand and independent of live typing', () => {
  const f = fixture(); f.box.selectionEnd = 5;
  assert.equal(f.selected.textContent, ''); f.change(); assert.equal(f.selected.textContent, '');
  f.button.dispatchEvent(new Event('click')); assert.equal(f.selected.textContent, '1 selected words');
  f.box.selectionEnd = 0; f.button.dispatchEvent(new Event('click')); assert.equal(f.selected.textContent, '0 selected words'); f.attached.destroy();
});

test('story metadata works without chapter text or count controls', () => {
  const f = fixture({ content: null }); f.form.elements[0].value = 'Story title'; f.change(f.form.elements[0]);
  f.env.flush(); assert.equal(f.state.textContent, labels.unsaved); assert.equal(f.attached.dirty(), true); f.attached.destroy();
});

test('save shortcuts submit once, skip composition and unrelated keys, and pageshow restores dirty protection', () => {
  const f = fixture(); f.box.value += ' edited'; f.change();
  assert.equal(key(f.form, { isComposing: true }).defaultPrevented, false);
  assert.equal(key(f.form, { key: 'b' }).defaultPrevented, false);
  assert.equal(key(f.form, { ctrlKey: false }).defaultPrevented, false);
  assert.equal(f.form.submits, undefined);
  assert.equal(key(f.form, { key: 'S', ctrlKey: false, metaKey: true }).defaultPrevented, true);
  key(f.form, {}); assert.equal(f.form.submits, 1); assert.equal(f.state.textContent, labels.saving); assert.equal(f.env.count('beforeunload'), 0);
  f.env.dispatchEvent(new Event('pageshow')); assert.equal(f.env.count('beforeunload'), 1); assert.equal(f.state.textContent, labels.unsaved);
  f.attached.destroy(); assert.equal(f.env.count('beforeunload'), 0);
});

test('initialization is idempotent and failed or missing helpers leave native controls untouched', () => {
  const f = fixture(); f.attached.destroy();
  const doc = { querySelectorAll: () => [f.form] };
  f.api.init(doc); const attached = f.form.kipStatus; f.api.init(doc); assert.equal(f.form.kipStatus, attached); attached.destroy();
  delete f.root.Kip.wordCount; delete f.form.kipStatus; f.api.init(doc); assert.equal(f.form.kipStatus, undefined);
  f.root.Kip.wordCount = wordCount; f.form.getAttribute = () => '{broken'; f.api.init(doc); assert.equal(f.form.kipStatus, undefined);
  assert.equal(f.form.requestSubmit instanceof Function, true);
});

test('module loader appends ordered classic scripts and loads shared helpers once', () => {
  const scripts = [];
  const marker = { getAttribute: () => 'editor writer-status' };
  const document = {
    readyState: 'loading', addEventListener() {}, documentElement: { classList: { add() {} } },
    querySelectorAll: () => [marker], createElement: () => ({ async: true }), head: { appendChild: script => scripts.push(script) }
  };
  vm.runInNewContext(readFileSync(new URL('../../public/assets/app.js', import.meta.url), 'utf8'), { window: {}, document });
  assert.deepEqual(scripts.map(script => script.src), ['/assets/subset.js', '/assets/text-edit.js', '/assets/smart.js', '/assets/editor.js', '/assets/wordcount.js', '/assets/writer-status.js']);
  assert.ok(scripts.every(script => script.async === false));
  assert.equal(new Set(scripts.map(script => script.src)).size, scripts.length);
});


test('delayed attachment uses server defaults, preserving early text and checkbox edits as unsaved', () => {
  const f = fixture({ saved: true, beforeAttach(form) {
    form.elements.namedItem('content').value = 'Early writing while helpers load';
    form.elements.push({ ...field('completed', '1', 'checkbox'), defaultChecked: false, checked: true });
  } });
  assert.equal(f.attached.dirty(), true); assert.equal(f.state.textContent, labels.unsaved);
  assert.equal(f.env.count('beforeunload'), 1); assert.equal(f.count.textContent, '5 words');
  f.box.value = "don't stop"; f.change(); assert.equal(f.attached.dirty(), true);
  f.form.elements.at(-1).checked = false; f.change(f.form.elements.at(-1), 'change');
  assert.equal(f.attached.dirty(), false); assert.equal(f.state.textContent, labels.saved); f.attached.destroy();
});

test('default snapshots preserve checkbox/radio defaults and complete multi-select choices', () => {
  const option = (value, defaultSelected, selected = defaultSelected, disabled = false) => ({ value, defaultSelected, selected, disabled });
  const check = { ...field('completed', '1', 'checkbox'), defaultChecked: false, checked: true };
  const radio = { ...field('status', 'draft', 'radio'), defaultChecked: true, checked: false };
  const multi = { ...field('tags', 'b', 'select-multiple'), options: [option('a', true, false), option('b', false, true), option('c', true, true)] };
  const single = { ...field('rating', 'b', 'select-one'), size: 0, options: [option('a', true, false), option('b', false, true)] };
  const form = { elements: [check, radio, multi, single] };
  assert.deepEqual(JSON.parse(status.snapshot(form, true)), [['completed', false], ['status', true], ['tags', ['a', 'c']], ['rating', 'a']]);
  assert.deepEqual(JSON.parse(status.snapshot(form)), [['completed', true], ['status', false], ['tags', ['b', 'c']], ['rating', 'b']]);
  const original = status.snapshot(form); multi.options[2].selected = false;
  assert.notEqual(status.snapshot(form), original, 'changing a later selected option cannot hide behind select.value');
  single.options.forEach(item => { item.defaultSelected = false; });
  single.options[0].disabled = true;
  assert.equal(JSON.parse(status.snapshot({ elements: [single] }, true))[0][1], 'b', 'single select defaults to the first enabled option');
  single.options[0].disabled = false; single.options[0].parentElement = { tagName: 'OPTGROUP', disabled: true };
  assert.equal(JSON.parse(status.snapshot({ elements: [single] }, true))[0][1], 'b', 'disabled optgroup is skipped for implicit selection');
  single.size = 2;
  assert.equal(JSON.parse(status.snapshot({ elements: [single] }, true))[0][1], '', 'listbox with no default has no implicit selection');
  single.options[0].defaultSelected = single.options[1].defaultSelected = true;
  assert.equal(JSON.parse(status.snapshot({ elements: [single] }, true))[0][1], 'b', 'last explicit single-select default wins');
  assert.deepEqual(JSON.parse(status.snapshot({ elements: [{ ...multi, options: [] }, { ...single, options: [] }] }, true)), [['tags', []], ['rating', '']]);
});

test('passwords present before delayed attachment trigger only boolean dirty state', () => {
  const f = fixture({ saved: true, beforeAttach(form) { form.elements.namedItem('access_password').value = 'early-secret'; } });
  assert.equal(f.attached.dirty(), true); assert.equal(f.state.textContent, labels.unsaved);
  assert.equal(status.snapshot(f.form).includes('early-secret'), false);
  assert.equal(status.snapshot(f.form, true).includes('early-secret'), false);
  f.form.elements.namedItem('access_password').value = ''; f.change(f.form.elements.namedItem('access_password'));
  assert.equal(f.attached.dirty(), true); f.attached.destroy();
});

test('core records only a password-touched boolean if typing is cleared before status loads', () => {
  const f = fixture({ saved: true, beforeAttach(form) {
    const document = Object.assign(new Target(), {
      readyState: 'loading', documentElement: { classList: { add() {} } },
      querySelectorAll: () => [form], createElement: () => ({}), head: { appendChild() {} }
    });
    vm.runInNewContext(readFileSync(new URL('../../public/assets/app.js', import.meta.url), 'utf8'), { window: {}, document });
    const password = form.elements.namedItem('access_password'); password.form = form;
    for (const value of ['early-secret', '']) {
      password.value = value;
      const event = new Event('input'); Object.defineProperty(event, 'target', { value: password }); document.dispatchEvent(event);
    }
    assert.equal(form.kipPasswordTouched, true);
    assert.equal(JSON.stringify(form.kipPasswordTouched), 'true');
    const unrelated = { getAttribute: () => 'other-module' };
    const event = new Event('input'); Object.defineProperty(event, 'target', { value: { type: 'password', form: unrelated } }); document.dispatchEvent(event);
    assert.equal(unrelated.kipPasswordTouched, undefined);
  } });
  assert.equal(f.attached.dirty(), true); assert.equal(f.state.textContent, labels.unsaved);
  assert.equal(f.env.count('beforeunload'), 1); f.attached.destroy();
});
