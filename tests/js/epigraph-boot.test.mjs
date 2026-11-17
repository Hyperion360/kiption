import { test } from 'node:test';
import assert from 'node:assert/strict';
import { createRequire } from 'node:module';
const require = createRequire(import.meta.url);
const { init } = require('../../public/assets/epigraph-boot.js');

const box = () => ({ hidden: true, events: [], dispatchEvent(e) { this.events.push(e.type); } });
const page = (boxes, EpigraphAuthor) => ({
  doc: { querySelectorAll: (sel) => { assert.equal(sel, 'textarea[data-js-module~="epigraph-boot"]'); return boxes; } },
  win: { EpigraphAuthor, Event: class { constructor(type) { this.type = type; } } },
});

test('mounts every marked textarea with images off and forwards changes as input events', () => {
  const b = box(), calls = [];
  const { doc, win } = page([b], { mount: (el, opts) => { calls.push([el, opts]); } });
  assert.equal(init(doc, win), 1);
  assert.equal(calls[0][0], b);
  assert.equal(calls[0][1].imagesAllowed, false);
  calls[0][1].onChange('x');
  assert.deepEqual(b.events, ['input']);
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
