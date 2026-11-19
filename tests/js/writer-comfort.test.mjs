import test from 'node:test';
import assert from 'node:assert/strict';
import { createRequire } from 'node:module';
const require = createRequire(import.meta.url);
let comfort;
try { comfort = require('../../public/assets/writer-comfort.js'); } catch (error) { if (error.code !== 'MODULE_NOT_FOUND') throw error; }

class Node extends EventTarget {
  constructor(tag = 'DIV') { super(); this.tagName = tag; this.children = []; this.style = {}; this.attrs = {}; this.className = ''; this.tokens = new Set(); this.classList = { toggle: n => this.tokens.has(n) ? (this.tokens.delete(n), false) : (this.tokens.add(n), true), add: n => this.tokens.add(n), remove: n => this.tokens.delete(n), contains: n => this.tokens.has(n) }; }
  append(...nodes) { for (const node of nodes) { this.children.push(node); node.parentNode = this; } }
  appendChild(node) { this.append(node); return node; }
  remove() { if (this.parentNode) this.parentNode.children = this.parentNode.children.filter(n => n !== this); }
  setAttribute(k, v) { this.attrs[k] = v; }
  getAttribute(k) { return this.attrs[k]; }
  getBoundingClientRect() { return this.rect || { top: 0, bottom: 30, width: 300, height: 30 }; }
}
function fixture(options = {}) {
  const doc = { body: new Node(), documentElement: { clientWidth: 1000 }, activeElement: null, createElement: tag => new Node(tag.toUpperCase()), createTextNode: data => ({ data }), getSelection: () => null };
  const form = new Node('FORM'), box = new Node('TEXTAREA'), title = new Node('INPUT'), sticky = new Node();
  Object.assign(box, { value: 'A line\nAn emoji 👩🏽‍💻', selectionStart: 5, selectionEnd: 5, selectionDirection: 'none', scrollTop: 0, clientHeight: 200, wrap: 'soft', ownerDocument: doc, rect: { top: 200, bottom: 400, width: 300, height: 200 } });
  const label = new Node('LABEL'); label.insertAdjacentElement = (_, node) => form.append(node);
  box.closest = () => options.label ? label : null;
  box.insertAdjacentElement = (_, node) => (options.label ? label : form).append(node);
  Object.assign(form, { ownerDocument: doc, dataset: { writerLabels: JSON.stringify({ focus: 'Focus Mode', typewriter: 'Typewriter Scrolling' }) }, elements: { namedItem: () => box }, querySelector: () => options.noSticky ? null : sticky });
  sticky.rect = { top: 8, bottom: 120, width: 300, height: 112 };
  if (options.label) { label.append(box); form.append(label); } else form.append(box);
  form.append(title); doc.body.append(form); doc.activeElement = box;
  const env = Object.assign(new EventTarget(), { innerHeight: 800, scrollY: 300, visualViewport: options.viewport || null, getComputedStyle: () => ({ lineHeight: '20px', fontSize: '16px', whiteSpace: 'pre-wrap', overflowWrap: 'break-word', borderTopWidth: '1px' }), scrollTo: x => scrolls.push(x), requestAnimationFrame: fn => { frame = fn; return 1; }, cancelAnimationFrame: () => { frame = null; } });
  let frame = null; const scrolls = [];
  doc.defaultView = env;
  const api = comfort.attach(form, env), bar = form.children.find(n => n.className === 'writer-comfort'), mirror = doc.body.children.find(n => n.className === 'writer-caret-mirror');
  function event(type, target = box, props = {}) { const e = new Event(type); Object.defineProperty(e, 'target', { value: target }); for (const [key, value] of Object.entries(props)) Object.defineProperty(e, key, { value }); form.dispatchEvent(e); }
  function enable() { bar.children[0].dispatchEvent(new Event('click')); }
  function flush() { const fn = frame; frame = null; if (fn) fn(); }
  return { doc, form, box, label, title, sticky, env, api, bar, mirror, scrolls, event, enable, flush, pending: () => !!frame };
}

test('comfort module is available', () => assert.ok(comfort));
test('manual navigation and composition suspend typewriter decisions', () => {
  const s = { enabled: true, composing: false, paused: true, inserted: false, caret: 600, viewport: 800, scroll: 0 };
  assert.equal(comfort.scrollTarget(s), null);
  assert.equal(comfort.scrollTarget({ ...s, inserted: true }), 200);
  assert.equal(comfort.scrollTarget({ ...s, composing: true, inserted: true }), null);
  assert.equal(comfort.scrollTarget({ ...s, enabled: false, inserted: true }), null);
  assert.equal(comfort.scrollTarget({ ...s, inserted: true, caret: 10 }), 0);
});
test('short viewports reserve space below sticky Save before choosing a target', () => {
  assert.equal(comfort.scrollTarget({ enabled: true, inserted: true, caret: 200, viewport: 250, scroll: 300, minimum: 136, maximum: 222 }), 364);
  assert.equal(comfort.scrollTarget({ enabled: true, inserted: true, caret: 200, viewport: 120, scroll: 300, minimum: 136, maximum: 92 }), null);
});
test('buttons expose pressed state without changing text, focus, or submission controls', () => {
  const f = fixture(); const original = f.box.value;
  assert.equal(f.bar.children.length, 2); assert.equal(f.bar.children[1].type, 'button');
  assert.equal(f.bar.children[1].getAttribute('aria-pressed'), 'false');
  f.bar.children[1].dispatchEvent(new Event('click')); assert.equal(f.form.tokens.has('writer-focus'), true);
  f.bar.children[1].dispatchEvent(new Event('click')); assert.equal(f.form.tokens.has('writer-focus'), false);
  f.api.setFocus(true); assert.equal(f.form.tokens.has('writer-focus'), true); assert.equal(f.bar.children[1].getAttribute('aria-pressed'), 'true'); f.api.setFocus(false);
  f.enable(); assert.equal(f.bar.children[0].getAttribute('aria-pressed'), 'true');
  assert.equal(f.box.value, original); assert.equal(f.doc.activeElement, f.box); assert.equal(f.scrolls.length, 0);
  f.api.destroy(); assert.equal(f.doc.body.children.includes(f.mirror), false); assert.equal(f.form.children.includes(f.bar), false);
});
test('comfort buttons stay outside the textarea label', () => {
  const f = fixture({ label: true }); assert.ok(f.bar); assert.equal(f.label.children.length, 1); f.api.destroy();
});
test('only active text insertion schedules one measurement with a reused mirror', () => {
  const f = fixture(); f.event('input', f.box, { inputType: 'insertText' }); assert.equal(f.pending(), false);
  f.enable(); f.event('input', f.title, { inputType: 'insertText' }); assert.equal(f.pending(), false);
  f.event('input', f.box, { inputType: 'deleteContentBackward' }); assert.equal(f.pending(), false);
  f.event('input', f.box, { inputType: 'insertText', isComposing: true }); assert.equal(f.pending(), false);
  f.event('input', f.box, { inputType: 'insertText' }); f.event('input', f.box, { inputType: 'insertText' });
  assert.equal(f.pending(), true); f.flush(); assert.equal(f.scrolls.length, 1); assert.equal(f.scrolls[0].behavior, 'auto');
  assert.equal(f.mirror.children[0].data, f.box.value.slice(0, 5));
  assert.equal(f.mirror.children[1].children[0].data, f.box.value.slice(5));
  assert.equal(f.doc.body.children.filter(n => n.className === 'writer-caret-mirror').length, 1);
  const suffix = f.mirror.children[1].children[0]; f.box.selectionEnd = f.box.value.length;
  f.event('input', f.box, { inputType: 'insertText' }); f.flush();
  assert.equal(f.mirror.children[1].children[0], suffix); assert.equal(suffix.data, '\u200b');
  assert.equal(f.mirror.children[0].data, f.box.value);
  f.enable(); f.event('input', f.box, { inputType: 'insertText' }); assert.equal(f.pending(), false); f.api.destroy();
});
test('manual scrolling and selection cancel pending moves until another insertion', () => {
  const f = fixture(); f.enable();
  for (const [type, props] of [['wheel', {}], ['touchmove', {}], ['pointerdown', { clientX: 999 }], ['keydown', { key: 'PageDown' }], ['keydown', { key: 'ArrowLeft' }]]) {
    f.event('input', f.box, { inputType: 'insertText' }); const e = new Event(type); Object.assign(e, props); f.env.dispatchEvent(e);
    assert.equal(f.pending(), false, type); f.flush();
  }
  assert.equal(f.scrolls.length, 0); f.event('input', f.box, { inputType: 'insertText' }); f.flush(); assert.equal(f.scrolls.length, 1); f.api.destroy();
});
test('composition in other fields never moves the content view', () => {
  const f = fixture(); f.enable(); f.event('compositionstart', f.title); f.event('compositionend', f.title, { data: '日' }); assert.equal(f.pending(), false);
  f.event('compositionstart'); f.event('input', f.box, { inputType: 'insertCompositionText', isComposing: true }); assert.equal(f.pending(), false);
  f.box.value += '日'; f.event('compositionend', f.box, { data: '日' }); assert.equal(f.pending(), true); f.flush();
  assert.equal(f.scrolls.length, 1); f.event('compositionstart'); f.event('compositionend', f.box, { data: '' }); assert.equal(f.pending(), false); f.api.destroy();
});
test('caret scroll uses the visual viewport offset and the sticky bar bounds', () => {
  const f = fixture({ viewport: { height: 250, offsetTop: 50 } }); f.enable(); f.event('input', f.box, { inputType: 'insertText' }); f.flush();
  const yAfter = 200 - (f.scrolls[0].top - 300);
  assert.ok(yAfter >= f.sticky.rect.bottom + 16); assert.ok(yAfter < 300); f.api.destroy();
});
test('textarea internal scrolling happens before page movement and wrap is mirrored', () => {
  const f = fixture({ noSticky: true }); f.enable(); f.box.scrollTop = 80; f.box.wrap = 'off'; f.box.selectionDirection = 'backward'; f.box.selectionStart = 2;
  f.event('input', f.box, { inputType: 'insertText' }); f.flush(); assert.equal(f.box.scrollTop, 0); assert.equal(f.mirror.style.whiteSpace, 'pre'); assert.equal(f.mirror.children[0].data, f.box.value.slice(0, 2));
  f.mirror.children[1].rect = { top: 400, height: 20 }; f.event('input', f.box, { inputType: 'insertText' }); f.flush(); assert.ok(f.box.scrollTop > 0); f.api.destroy();
});
test('optional active control uses its native range and retains the textarea fallback', () => {
  const f = fixture(); const active = new Node(); active.contains = () => true; active.textContent = 'Active';
  f.box.kipWriter = { activeControl: active }; f.doc.getSelection = () => ({ focusNode: active, focusOffset: 1 });
  f.doc.createRange = () => ({ setStart() {}, collapse() {}, getBoundingClientRect: () => ({ top: 500, height: 20 }) });
  f.enable(); f.event('input', active, { inputType: 'insertText' }); f.flush(); assert.equal(f.scrolls.length, 1);
  f.doc.getSelection = () => null; f.event('input', active, { inputType: 'insertText' }); f.flush(); assert.equal(f.scrolls.length, 2); f.api.destroy();
});
test('mirror wrapping uses the textarea client width without reserving a second scrollbar', () => {
  const f = fixture(); f.box.clientWidth = 280;
  f.env.getComputedStyle = () => ({ boxSizing: 'border-box', borderLeftWidth: '1px', borderRightWidth: '1px', lineHeight: 'normal', fontSize: '20px', paddingLeft: '8px', paddingRight: '8px' });
  f.enable(); f.event('input', f.box, { inputType: 'insertText' }); f.flush(); assert.equal(f.mirror.style.width, '282px');
  f.env.getComputedStyle = () => ({ boxSizing: 'content-box', paddingLeft: '8px', paddingRight: '8px', lineHeight: 'normal' });
  f.event('input', f.box, { inputType: 'insertText' }); f.flush(); assert.equal(f.mirror.style.width, '264px'); f.api.destroy();
});
test('invalid initialization leaves no controls and repeated init attaches only once', () => {
  const f = fixture(); f.api.destroy(); f.form.kipComfort = undefined;
  const bad = { ownerDocument: f.doc, elements: { namedItem: () => null }, dataset: { writerLabels: '{}' } };
  const doc = { querySelectorAll: () => [bad, f.form] };
  comfort.init(doc); comfort.init(doc); assert.ok(f.form.kipComfort); assert.equal(f.form.children.filter(n => n.className === 'writer-comfort').length, 1); f.form.kipComfort.destroy();
});

test('schedule times convert between the stored UTC value and the writer\'s own clock', () => {
  const utc = '2099-01-01T09:00';
  assert.equal(comfort.localToUtc(comfort.utcToLocal(utc)), utc);
  const local = new Date(utc + ':00Z');
  assert.equal(comfort.utcToLocal(utc).slice(11, 13), String(local.getHours()).padStart(2, '0'));
  assert.equal(comfort.utcToLocal(''), ''); assert.equal(comfort.localToUtc(''), '');
  assert.equal(comfort.utcToLocal('not a time'), ''); assert.equal(comfort.localToUtc('nope'), '');
});

test('schedule composition turns a local date and time into the UTC wire value', () => {
  const local = '2030-06-15T09:30', utc = comfort.localToUtc(local);
  assert.equal(comfort.composeSchedule('2030-06-15', '09:30'), utc);
  assert.equal(comfort.utcToLocal(utc), local);
  assert.equal(comfort.composeSchedule('', '09:30'), '');
  assert.equal(comfort.composeSchedule('2030-06-15', ''), '');
  assert.deepEqual(comfort.splitSchedule(utc), { date: '2030-06-15', time: '09:30' });
  assert.deepEqual(comfort.splitSchedule(''), { date: '', time: '' });
  assert.equal(comfort.isPast('2000-01-01T00:00', Date.UTC(2030, 0, 1)), true);
  assert.equal(comfort.isPast('2040-01-01T00:00', Date.UTC(2030, 0, 1)), false);
  assert.equal(comfort.canPublish({ when: 'later', utc: '', audience: 'everyone' }), false);
  assert.equal(comfort.canPublish({ when: 'later', utc: '2000-01-01T00:00', now: Date.UTC(2030, 0, 1), audience: 'everyone' }), false);
  assert.equal(comfort.canPublish({ when: 'later', utc: '2040-01-01T00:00', now: Date.UTC(2030, 0, 1), audience: 'everyone' }), true);
  assert.equal(comfort.canPublish({ when: 'now', audience: 'password', protectedNow: false, password: '' }), false);
  assert.equal(comfort.canPublish({ when: 'now', audience: 'password', protectedNow: true, password: '' }), true);
});
test('switching to Everyone on a protected chapter asks the server to clear the password', () => {
  assert.deepEqual(comfort.accessFields('everyone', true, 'secret'), { clear: true, password: '' });
  assert.deepEqual(comfort.accessFields('everyone', false, ''), { clear: false, password: '' });
  assert.deepEqual(comfort.accessFields('password', true, 'new one'), { clear: false, password: 'new one' });
});
test('Esc closes the innermost layer first and leaves Focus last', () => {
  const all = { link: true, menu: true, dialog: true, panel: true, focus: true };
  const order = []; const open = { ...all };
  for (let i = 0; i < 6; i++) { const t = comfort.escTarget(open); if (!t) break; order.push(t); open[t] = false; }
  assert.deepEqual(order, ['link', 'menu', 'dialog', 'panel', 'focus']);
  assert.equal(comfort.escTarget({}), null);
});
test('Esc closing a panel returns focus to its toggle only from inside the panel', () => {
  const body = {}, inside = {}, editor = {};
  const panelEl = { contains: n => n === inside };
  assert.equal(comfort.refocusToggle(inside, panelEl, body), true);
  assert.equal(comfort.refocusToggle(body, panelEl, body), true);
  assert.equal(comfort.refocusToggle(null, panelEl, body), true);
  assert.equal(comfort.refocusToggle(editor, panelEl, body), false);
});
test('shortcuts: slash toggles Source, Shift+F toggles Focus, plain keys do nothing', () => {
  const k = (key, o = {}) => comfort.shortcutAction({ key, ...o });
  assert.equal(k('/', { ctrlKey: true }), 'source');
  assert.equal(k('/', { metaKey: true }), 'source');
  assert.equal(k('F', { ctrlKey: true, shiftKey: true }), 'focus');
  assert.equal(k('f', { metaKey: true, shiftKey: true }), 'focus');
  assert.equal(k('f', { ctrlKey: true }), null);
  assert.equal(k('/', {}), null);
  assert.equal(k('/', { ctrlKey: true, altKey: true }), null);
});
test('writer settings round trip through localStorage and survive a broken store', () => {
  const data = {}, store = { getItem: k => data[k] ?? null, setItem: (k, v) => { data[k] = v; } };
  assert.deepEqual(comfort.loadSettings(store), { brk: 'squares', para: 'indented', face: 'serif', width: 'medium', size: 20, smart: true });
  assert.equal(comfort.saveSettings(store, { brk: 'asterism', para: 'spaced', face: 'mono', width: 'wide', size: 24, smart: false }), true);
  assert.ok(data['kiption-editor-settings-v1']);
  assert.deepEqual(comfort.loadSettings(store), { brk: 'asterism', para: 'spaced', face: 'mono', width: 'wide', size: 24, smart: false });
  assert.equal(comfort.cleanSettings({ smart: 'false' }).smart, false);
  assert.equal(comfort.cleanSettings({ smart: 'true' }).smart, true);
  assert.equal(comfort.cleanSettings({ smart: 'junk' }).smart, true);
  assert.equal(comfort.cleanSettings({ size: 99, face: 'comic' }).size, 20);
  assert.equal(comfort.cleanSettings({ size: 99, face: 'comic' }).face, 'serif');
  const broken = { getItem() { throw new Error('no'); }, setItem() { throw new Error('no'); } };
  assert.equal(comfort.loadSettings(broken).size, 20);
  assert.equal(comfort.saveSettings(broken, {}), false);
});
test('typewriter only scrolls: it centres the caret and never edits the text', () => {
  assert.equal(comfort.scrollTarget({ enabled: true, inserted: true, caret: 600, viewport: 800, scroll: 100 }), 300);
  assert.equal(comfort.scrollTarget({ enabled: false, inserted: true, caret: 600, viewport: 800, scroll: 100 }), null);
  const f = fixture(); const before = f.box.value; f.enable(); f.event('input', f.box, { inputType: 'insertText' }); f.flush();
  assert.equal(f.box.value, before); f.api.destroy();
});

test('story answers gate Publish, and a draft only needs a rating', () => {
  const ok = { story: true, storyTitle: true, ratingChosen: true, when: 'now', audience: 'everyone' };
  assert.equal(comfort.canPublish(ok), true);
  assert.equal(comfort.canPublish({ ...ok, storyTitle: false }), false);
  assert.equal(comfort.canPublish({ ...ok, ratingChosen: false }), false);
  assert.equal(comfort.canPublish({ when: 'now', audience: 'everyone' }), true, 'a chapter editor is unaffected');
  assert.equal(comfort.canDraft({ story: true, storyTitle: false, ratingChosen: true }), true);
  assert.equal(comfort.canDraft({ story: true, storyTitle: true, ratingChosen: false }), false);
  assert.equal(comfort.canDraft({}), true);
  assert.equal(comfort.firstMissing({ storyTitle: false, ratingChosen: false }), 'story');
  assert.equal(comfort.firstMissing({ storyTitle: true, ratingChosen: false }), 'rating');
  assert.equal(comfort.firstMissing({ storyTitle: true, ratingChosen: true }), '');
});

function adoptFixture(stored, { title = '', body = '' } = {}) {
  const els = { title: { value: '' }, summary: { value: '' }, notes: { value: '' }, language: { value: 'en' } };
  const radio = { checked: false };
  const form = { elements: { namedItem: n => els[n] || null }, querySelector: sel => sel.includes('value="3"') ? radio : null };
  const kept = new Map(stored === undefined ? [] : [['kip:new-story-draft', stored]]);
  const ls = { getItem: k => kept.has(k) ? kept.get(k) : null, removeItem: k => kept.delete(k) };
  const storyTitle = { value: title };
  return { els, radio, kept, run: () => comfort.adoptOldDraft(form, ls, storyTitle, { value: body }) };
}
test('the old new-story draft is copied once into an empty form and then forgotten', () => {
  const f = adoptFixture(JSON.stringify({ title: 'Old', summary: 'Sum', notes: 'N', language: 'fr', rating_id: '3' }));
  f.run();
  assert.deepEqual([f.els.title.value, f.els.summary.value, f.els.notes.value, f.els.language.value, f.radio.checked], ['Old', 'Sum', 'N', 'fr', true]);
  assert.equal(f.kept.has('kip:new-story-draft'), false);
});
test('the old new-story draft never overwrites typed work, and bad data is just dropped', () => {
  const typed = adoptFixture(JSON.stringify({ title: 'Old' }), { body: 'Already writing' });
  typed.run();
  assert.equal(typed.els.title.value, '');
  assert.equal(typed.kept.has('kip:new-story-draft'), false);
  const named = adoptFixture(JSON.stringify({ title: 'Old' }), { title: 'Mine' });
  named.run();
  assert.equal(named.els.title.value, '');
  const bad = adoptFixture('{not json');
  assert.doesNotThrow(() => bad.run());
  assert.equal(adoptFixture(undefined).run(), undefined);
});

test('publish stays unavailable in a story editor until the chapter has text, a title and a rating', () => {
  const ok = { story: true, storyTitle: true, ratingChosen: true, bodyFilled: true, when: 'now', audience: 'everyone' };
  assert.equal(comfort.canPublish(ok), true);
  assert.equal(comfort.canPublish({ ...ok, bodyFilled: false }), false);
  assert.equal(comfort.firstMissing({ ...ok, bodyFilled: false }), 'body');
  assert.equal(comfort.firstMissing({ ...ok, storyTitle: false }), 'story');
  assert.equal(comfort.canDraft({ ...ok, bodyFilled: false }), true, 'a draft may be empty');
  assert.equal(comfort.canPublish({ when: 'now', audience: 'everyone' }), true, 'the chapter editor never sets bodyFilled');
});
test('formatted mode measures the editing surface, never the hidden field', () => {
  const f = fixture(); const surface = new Node(); surface.contains = () => true; surface.textContent = 'Rich';
  f.box.kipEpigraph = { mode: () => 'rich' }; f.box.nextElementSibling = { querySelector: () => surface };
  f.doc.getSelection = () => ({ focusNode: surface, focusOffset: 1 });
  f.doc.createRange = () => ({ setStart() {}, collapse() {}, getBoundingClientRect: () => ({ top: 500, height: 20 }) });
  f.enable(); f.event('input', surface, { inputType: 'insertText' }); f.flush(); assert.equal(f.scrolls.length, 1);
  f.event('input', f.box, { inputType: 'insertText' }); f.flush(); assert.equal(f.scrolls.length, 1);
  f.doc.getSelection = () => null; f.event('input', surface, { inputType: 'insertText' }); f.flush(); assert.equal(f.scrolls.length, 1); f.api.destroy();
});
