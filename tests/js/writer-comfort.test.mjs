import test from 'node:test';
import assert from 'node:assert/strict';
import { createRequire } from 'node:module';
const require = createRequire(import.meta.url);
let comfort;
try { comfort = require('../../public/assets/writer-comfort.js'); } catch (error) { if (error.code !== 'MODULE_NOT_FOUND') throw error; }

class Node extends EventTarget {
  constructor(tag = 'DIV') { super(); this.tagName = tag; this.children = []; this.style = {}; this.attrs = {}; this.className = ''; this.tokens = new Set(); this.classList = { toggle: n => this.tokens.has(n) ? (this.tokens.delete(n), false) : (this.tokens.add(n), true), remove: n => this.tokens.delete(n) }; }
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
  function enable() { bar.children[1].dispatchEvent(new Event('click')); }
  function flush() { const fn = frame; frame = null; if (fn) fn(); }
  return { doc, form, box, label, title, sticky, env, api, bar, mirror, scrolls, event, enable, flush, pending: () => !!frame };
}

test('comfort module is available', () => assert.ok(comfort));
test('manual navigation and composition suspend typewriter decisions', () => {
  const s = { enabled: true, composing: false, paused: true, inserted: false, caret: 600, viewport: 800, scroll: 0 };
  assert.equal(comfort.scrollTarget(s), null);
  assert.equal(comfort.scrollTarget({ ...s, inserted: true }), 240);
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
  assert.equal(f.bar.children.length, 2); assert.equal(f.bar.children[0].type, 'button');
  assert.equal(f.bar.children[0].getAttribute('aria-pressed'), 'false');
  f.bar.children[0].dispatchEvent(new Event('click')); assert.equal(f.form.tokens.has('writer-focus'), true);
  f.bar.children[0].dispatchEvent(new Event('click')); assert.equal(f.form.tokens.has('writer-focus'), false);
  f.enable(); assert.equal(f.bar.children[1].getAttribute('aria-pressed'), 'true');
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
