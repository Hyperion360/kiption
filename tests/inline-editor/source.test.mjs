import assert from 'node:assert/strict';
import { createRequire } from 'node:module';
import test from 'node:test';

const require = createRequire(import.meta.url);
const { createSource } = require('../../public/assets/inline-editor/source.js');
const at = (anchor, focus = anchor) => ({ anchor, focus });
const edit = (from, to, insert, selection, kind = 'replace', time = 0) =>
  ({ from, to, insert, selection, kind, time });

test('keeps exact source and directed selections through replace, undo, and redo', () => {
  const source = '\r\n**Words**\r\n👩🏽‍💻 é\\*\r\n';
  const doc = createSource(source, at(10, 4));
  assert.equal(doc.text, source);
  assert.deepEqual(doc.selection, at(10, 4));
  assert.equal(doc.revision, 0);
  assert.equal(doc.apply(edit(4, 9, 'Hello', at(9, 4))), true);
  assert.equal(doc.text, source.slice(0, 4) + 'Hello' + source.slice(9));
  assert.equal(doc.revision, 1);
  assert.equal(doc.undo(), true);
  assert.equal(doc.text, source);
  assert.deepEqual(doc.selection, at(10, 4));
  assert.equal(doc.revision, 2);
  assert.equal(doc.redo(), true);
  assert.equal(doc.text, source.slice(0, 4) + 'Hello' + source.slice(9));
  assert.deepEqual(doc.selection, at(9, 4));
  assert.equal(doc.revision, 3);
});

test('view selection and no-op edits do not change source revision or history', () => {
  const input = at(0);
  const doc = createSource('ab', input);
  input.anchor = 99;
  assert.deepEqual(doc.selection, at(0));
  doc.select(at(2, 0));
  const copy = doc.selection;
  copy.anchor = 99;
  assert.deepEqual(doc.selection, at(2, 0));
  assert.equal(doc.apply(edit(0, 1, 'a', at(1))), false);
  assert.equal(doc.revision, 0);
  assert.deepEqual(doc.selection, at(1));
  assert.equal(doc.undo(), false);
  assert.equal(doc.redo(), false);
  assert.equal(doc.revision, 0);
});

test('rejects invalid edits before changing source, selection, or redo', () => {
  const doc = createSource('a');
  doc.apply(edit(1, 1, 'b', at(2)));
  doc.apply(edit(2, 2, 'c', at(3)));
  doc.undo();
  const snapshot = { text: doc.text, selection: doc.selection, revision: doc.revision };
  for (const selection of [null, {}, at(-1), at(1.5), at(3), at(NaN)]) {
    assert.throws(() => doc.select(selection), RangeError);
    assert.deepEqual({ text: doc.text, selection: doc.selection, revision: doc.revision }, snapshot);
  }
  for (const bad of [
    edit(-1, 0, 'x', at(1)), edit(0, 3, 'x', at(1)),
    edit(2, 1, 'x', at(1)), edit(0.5, 1, 'x', at(1)),
    edit(0, 1, null, at(1)), edit(0, 1, 'x', at(99)),
    edit(0, 1, 'x', at(1), 'typing', NaN),
    edit(0, 1, 'x', at(1), ''),
  ]) {
    assert.throws(() => doc.apply(bad), RangeError);
    assert.deepEqual({ text: doc.text, selection: doc.selection, revision: doc.revision }, snapshot);
  }
  assert.equal(doc.redo(), true);
  assert.equal(doc.text, 'abc');
  assert.throws(() => createSource(null), RangeError);
  assert.throws(() => createSource('x', at(2)), RangeError);
});

test('groups adjacent typing across redundant capture but separates a selection journey', () => {
  const doc = createSource('ab');
  doc.apply(edit(0, 0, 'x', at(1), 'typing', 1000));
  doc.select(at(1));
  doc.apply(edit(1, 1, 'y', at(2), 'typing', 1750));
  assert.equal(doc.text, 'xyab');
  doc.undo();
  assert.equal(doc.text, 'ab');
  doc.redo();
  doc.select(at(4, 2));
  doc.select(at(2));
  doc.apply(edit(2, 2, 'z', at(3), 'typing', 1800));
  doc.undo();
  assert.equal(doc.text, 'xyab');
  doc.undo();
  assert.equal(doc.text, 'ab');
});

test('no-op selection changes separate typing and redo cannot join old typing', () => {
  const doc = createSource('ab');
  doc.apply(edit(0, 0, 'x', at(1), 'typing', 1));
  assert.equal(doc.apply(edit(1, 2, 'a', at(2), 'replace', 2)), false);
  doc.select(at(1));
  doc.apply(edit(1, 1, 'y', at(2), 'typing', 3));
  doc.undo();
  assert.equal(doc.text, 'xab');
  doc.undo();
  assert.equal(doc.text, 'ab');
  doc.redo();
  doc.apply(edit(1, 1, 'z', at(2), 'typing', 4));
  doc.undo();
  assert.equal(doc.text, 'xab');
});

test('retains at least one hundred small undo groups and one large paste', () => {
  const doc = createSource('');
  for (let i = 0; i < 100; i++) {
    doc.apply(edit(i, i, 'x', at(i + 1), 'paste', i));
  }
  for (let i = 0; i < 100; i++) assert.equal(doc.undo(), true);
  assert.equal(doc.text, '');
  const big = 'x'.repeat(4_000_001);
  assert.equal(doc.apply(edit(0, 0, big, at(big.length), 'paste', 200)), true);
  assert.equal(doc.undo(), true);
  assert.equal(doc.text, '');
  doc.apply(edit(0, 0, 'a', at(1), 'paste', 201));
  doc.apply(edit(1, 1, big, at(big.length + 1), 'paste', 202));
  assert.equal(doc.undo(), true);
  assert.equal(doc.text, 'a');
  assert.equal(doc.undo(), false);
});

test('preserves surrogate code units and can select backward through them', () => {
  const doc = createSource('A😀B', at(3, 1));
  doc.apply(edit(1, 3, '😃', at(3, 1)));
  assert.equal(doc.text, 'A😃B');
  doc.undo();
  assert.equal(doc.text, 'A😀B');
  assert.deepEqual(doc.selection, at(3, 1));
});
