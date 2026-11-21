import { test } from 'node:test';
import assert from 'node:assert/strict';
import { createRequire } from 'node:module';
const require = createRequire(import.meta.url);
const { init } = require('../../public/assets/epigraph-boot.js');

const box = (attrs = {}) => ({ getAttribute: (n) => attrs[n] ?? null, hidden: true, events: [], dispatchEvent(e) { this.events.push(e.type); } });
const page = (boxes, EpigraphAuthor) => ({
  doc: { querySelectorAll: (sel) => { assert.equal(sel, 'textarea[data-js-module~="epigraph-boot"]'); return boxes; } },
  win: { EpigraphAuthor, Event: class { constructor(type) { this.type = type; } } },
});

test('mounts with images off by default and forwards changes as input events', () => {
  const b = box(), calls = [];
  const { doc, win } = page([b], { mount: (el, opts) => { calls.push([el, opts]); } });
  assert.equal(init(doc, win), 1);
  assert.equal(calls[0][0], b);
  assert.equal(calls[0][1].imagesAllowed, false);
  calls[0][1].onChange('x');
  assert.equal(calls[0][1].imageLibrary, undefined);
  assert.deepEqual(b.events, ['input']);
});

test('the server-rendered upload address and token turn images on', () => {
  const b = box({ 'data-epigraph-upload-url': '/authorimages/upload', 'data-epigraph-csrf': 'tok' }), calls = [];
  const { doc, win } = page([b], { mount: (el, opts) => { calls.push(opts); } });
  init(doc, win);
  assert.equal(calls[0].imagesAllowed, true);
  assert.deepEqual(calls[0].imageLibrary, { uploadUrl: '/authorimages/upload', csrfToken: 'tok' });
});

test('the intake size cap is forwarded as imageMaxBytes when the server renders it', () => {
  const b = box({ 'data-epigraph-upload-url': '/authorimages/upload', 'data-epigraph-csrf': 'tok', 'data-epigraph-max-bytes': '10485760' }), calls = [];
  const { doc, win } = page([b], { mount: (el, opts) => { calls.push(opts); } });
  init(doc, win);
  assert.equal(calls[0].imageMaxBytes, 10485760);
});

test('a missing or junk size cap sends no imageMaxBytes', () => {
  for (const v of [undefined, '', 'abc', '0', '-5']) {
    const b = box(v === undefined ? {} : { 'data-epigraph-max-bytes': v }), calls = [];
    const { doc, win } = page([b], { mount: (el, opts) => { calls.push(opts); } });
    init(doc, win);
    assert.equal(calls[0].imageMaxBytes, undefined, String(v));
  }
});

test('the images-off note reaches the editor only while images are off', () => {
  const off = box({ 'data-epigraph-images-off': 'Ask the administrator.' }), on = box({ 'data-epigraph-upload-url': '/u', 'data-epigraph-csrf': 't', 'data-epigraph-images-off': 'Stale.' }), calls = [];
  const { doc, win } = page([off, on], { mount: (el, opts) => { calls.push(opts); } });
  init(doc, win);
  assert.equal(calls[0].imagesAllowed, false);
  assert.equal(calls[0].imagesOffMessage, 'Ask the administrator.');
  assert.equal(calls[1].imagesOffMessage, undefined);
});

test('a missing bundle changes nothing', () => {
  const b = box();
  assert.equal(init(page([b], undefined).doc, page([b], undefined).win), 0);
  assert.equal(b.hidden, true);
});

test('a failed mount leaves the textarea visible and the others still mount', () => {
  const bad = box(), good = box();
  let n = 0;
  const { doc, win } = page([bad, good], { mount: () => { if (n++ === 0) throw new Error('boom'); } });
  assert.equal(init(doc, win), 1);
  assert.equal(bad.hidden, false);
});

/* A tiny element stand-in: enough DOM for the mode note. */
const el = (tag) => ({
  tagName: tag, hidden: false, textContent: '', className: '', attrs: {}, children: [], listeners: {},
  setAttribute(n, v) { this.attrs[n] = v; },
  append(...c) { this.children.push(...c); },
  replaceChildren(...c) { this.children = c; },
  after(n) { this.next = n; },
  addEventListener(t, f) { (this.listeners[t] ??= []).push(f); },
  click() { (this.listeners.click ?? []).forEach((f) => f()); },
  querySelector(sel) { return sel === 'button' ? (this.children.find((c) => c.tagName === 'button') ?? null) : null; },
  focus() { this.focused = true; },
});
const modePage = (api) => {
  const button = el('button');
  const wrap = el('div'); wrap.querySelector = (sel) => (sel === '.epigraph-mode' ? button : null);
  const labels = { modeBlocked: 'Stays in Source.', modeReview: 'Rewrite the markup?', convert: 'Convert', keepSource: 'Keep Source' };
  const b = box({ 'data-writer-labels': JSON.stringify(labels) });
  b.nextElementSibling = wrap;
  const doc = { querySelectorAll: () => [b], createElement: el };
  const win = { EpigraphAuthor: { mount: () => { button.listeners.click = [() => api.showRich()]; return api; } }, Event: class { constructor(t) { this.type = t; } } };
  init(doc, win);
  button.wrap = wrap;
  return button;
};

test('a chapter the editor would rewrite asks before switching instead of doing nothing', () => {
  let state = 'source', review = { original: 'a\n\n* * *\n\nb', candidate: 'a\n\n---\n\nb' }, accepted = 0;
  const api = { mode: () => state, blocker: () => null, review: () => review, showRich: () => 'review',
    acceptConversion: () => { accepted++; state = 'rich'; review = null; }, cancelConversion: () => { review = null; }, focus() {} };
  const button = modePage(api);
  button.click();
  const note = button.next;
  assert.ok(note, 'a note follows the mode button');
  assert.equal(note.hidden, false);
  assert.equal(note.attrs.role, 'status');
  assert.match(note.children[0].textContent, /Rewrite the markup/);
  const [convert, keep] = note.children.slice(1);
  assert.equal(convert.textContent, 'Convert');
  assert.equal(keep.textContent, 'Keep Source');
  convert.click();
  assert.equal(accepted, 1);
  assert.equal(note.hidden, true);
});

test('the Ctrl or Cmd + / shortcut shows the same note and puts focus on Convert', () => {
  let review = { original: 'a\n\n* * *\n\nb', candidate: 'a\n\n---\n\nb' };
  const api = { mode: () => 'source', blocker: () => null, review: () => review, showRich: () => 'review', acceptConversion() {}, cancelConversion: () => { review = null; }, focus() {} };
  const button = modePage(api);
  const key = (e) => button.wrap.listeners.keydown.forEach((f) => f(e));
  key({ key: '/', metaKey: false, ctrlKey: false });
  assert.equal(button.next.hidden, true, 'a bare slash does nothing');
  key({ key: '/', metaKey: true });
  const note = button.next;
  assert.equal(note.hidden, false);
  assert.equal(note.children[1].focused, true, 'Convert has focus');
});

test('a chapter the editor cannot show says why it stays in Source', () => {
  const api = { mode: () => 'source', blocker: () => 'raw-html', review: () => null, showRich: () => 'blocked', acceptConversion() {}, cancelConversion() {}, focus() {} };
  const button = modePage(api);
  button.click();
  assert.equal(button.next.hidden, false);
  assert.match(button.next.children[0].textContent, /Stays in Source/);
});

test('keeping the source dismisses the question', () => {
  let cancelled = 0;
  const api = { mode: () => 'source', blocker: () => null, review: () => ({ original: 'x', candidate: 'y' }), showRich: () => 'review', acceptConversion() {}, cancelConversion: () => { cancelled++; }, focus() {} };
  const button = modePage(api);
  button.click();
  button.next.children[2].click();
  assert.equal(cancelled, 1);
  assert.equal(button.next.hidden, true);
});
