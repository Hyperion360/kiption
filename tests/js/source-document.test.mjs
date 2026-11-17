import assert from 'node:assert/strict';
import { createRequire } from 'node:module';
import test from 'node:test';
const require = createRequire(import.meta.url);
const { create, difference } = require('../../public/assets/source-document.js');
const sel = (anchor, focus = anchor) => ({ anchor, focus });

test('edits preserve untouched source code units and undo the backward selection', () => {
  const original = '\r\n**Words**\r\n\r\n👩🏽‍💻 é\\*\r\n';
  const model = create(original, sel(7, 4));
  assert.equal(model.apply(4, 7, 'New', sel(7), 'replace', 1000), true);
  assert.equal(model.text, original.slice(0, 4) + 'New' + original.slice(7));
  assert.equal(model.undo(), true);
  assert.equal(model.text, original);
  assert.deepEqual(model.selection, sel(7, 4));
  assert.equal(model.redo(), true);
  assert.equal(model.text, original.slice(0, 4) + 'New' + original.slice(7));
  assert.deepEqual(model.selection, sel(7));
});

test('view selections and no-op edits never create history', () => {
  const input = sel(0);
  const model = create('a\n\nb', input);
  input.anchor = 99;
  model.select(sel(4, 1));
  const snapshot = model.selection;
  snapshot.anchor = 99;
  assert.deepEqual(model.selection, sel(4, 1));
  assert.equal(model.apply(0, 1, 'a', sel(1), 'replace', 0), false);
  assert.equal(model.text, 'a\n\nb');
  assert.deepEqual(model.selection, sel(1));
  assert.equal(model.undo(), false);
  assert.equal(model.redo(), false);
});

test('invalid selections and edits leave source, selection, and both histories unchanged', () => {
  const model = create('a');
  model.apply(1, 1, 'b', sel(2), 'paste', 0);
  model.apply(2, 2, 'c', sel(3), 'paste', 1);
  model.undo();
  for (const bad of [null, {}, sel(-1), sel(1.5), sel(3), sel(NaN)]) {
    assert.throws(() => model.select(bad), RangeError);
    assert.throws(() => model.apply(1, 1, 'x', bad && { ...bad, focus: 99 }, 'paste', 2), RangeError);
    assert.equal(model.text, 'ab');
    assert.deepEqual(model.selection, sel(2));
  }
  for (const args of [[-1, 0, 'x'], [0, 3, 'x'], [2, 1, 'x'], [0.5, 1, 'x'], [0, 1, null]]) {
    assert.throws(() => model.apply(...args, sel(1), 'paste', 2), RangeError);
    assert.equal(model.text, 'ab');
  }
  assert.throws(() => model.apply(0, 1, 'a', sel(99), 'paste', 2), RangeError);
  assert.equal(model.redo(), true);
  assert.equal(model.text, 'abc');
  model.undo(); model.undo();
  assert.equal(model.text, 'a');
  assert.throws(() => create('x', sel(2)), RangeError);
});

test('difference retains the common prefix and suffix without splitting surrogate pairs', () => {
  assert.deepEqual(difference('same old tail', 'same new tail'), { start: 5, end: 8, insert: 'new' });
  assert.deepEqual(difference('same', 'same'), { start: 4, end: 4, insert: '' });
  assert.deepEqual(difference('a😀b', 'a😃b'), { start: 1, end: 3, insert: '😃' });
  assert.deepEqual(difference('a😀b', 'a𠀀b'), { start: 1, end: 3, insert: '𠀀' });
  for (const [before, after] of [['', '😀'], ['😀', ''], ['é', 'é'], ['\r\nx', '\r\ny'], ['a😀', 'a🨀']]) {
    const d = difference(before, after);
    assert.equal(before.slice(0, d.start) + d.insert + before.slice(d.end), after);
  }
});

test('adjacent typing groups at the 750 ms boundary and separates beyond it', () => {
  const model = create('');
  model.apply(0, 0, 'a', sel(1), 'typing', 0);
  model.apply(1, 1, 'b', sel(2), 'typing', 750);
  model.apply(2, 2, 'c', sel(3), 'typing', 1501);
  model.undo(); assert.equal(model.text, 'ab');
  model.undo(); assert.equal(model.text, '');
  model.redo(); assert.equal(model.text, 'ab');
});

test('selection movement away and back separates typing from the previous burst', () => {
  const model = create('ab', sel(0));
  model.apply(0, 0, 'x', sel(1), 'typing', 1000);
  model.select(sel(3));
  model.select(sel(1));
  model.apply(1, 1, 'y', sel(2), 'typing', 1100);
  model.undo(); assert.equal(model.text, 'xab');
  model.undo(); assert.equal(model.text, 'ab');
});

test('backward selection movement separates typing even when caret returns', () => {
  const model = create('ab', sel(0));
  model.apply(0, 0, 'x', sel(1), 'typing', 1000);
  model.select(sel(3, 1));
  model.select(sel(1));
  model.apply(1, 1, 'y', sel(2), 'typing', 1100);
  model.undo(); assert.equal(model.text, 'xab');
  model.undo(); assert.equal(model.text, 'ab');
});

test('redundant selection capture and no-op edits preserve adjacent typing group', () => {
  const model = create('ab', sel(0));
  model.apply(0, 0, 'x', sel(1), 'typing', 1000);
  model.select(sel(1));
  assert.equal(model.apply(0, 1, 'x', sel(1), 'input', 1050), false);
  model.apply(1, 1, 'y', sel(2), 'typing', 1100);
  model.undo(); assert.equal(model.text, 'ab');
});

test('a no-op edit that moves selection separates subsequent typing', () => {
  const model = create('ab', sel(0));
  model.apply(0, 0, 'x', sel(1), 'typing', 1000);
  assert.equal(model.apply(0, 1, 'x', sel(3), 'input', 1050), false);
  model.select(sel(1));
  model.apply(1, 1, 'y', sel(2), 'typing', 1100);
  model.undo(); assert.equal(model.text, 'xab');
  model.undo(); assert.equal(model.text, 'ab');
});

test('typing after undo and redo starts a new history group', () => {
  const model = create('');
  model.apply(0, 0, 'a', sel(1), 'typing', 1000);
  model.apply(1, 1, 'b', sel(2), 'typing', 1100);
  model.undo(); assert.equal(model.text, '');
  model.redo(); assert.equal(model.text, 'ab');
  model.apply(2, 2, 'c', sel(3), 'typing', 1200);
  model.undo(); assert.equal(model.text, 'ab');
  model.undo(); assert.equal(model.text, '');
});

test('paste, deletion, selected typing, and backward timestamps remain separate transactions', () => {
  const model = create('');
  model.apply(0, 0, 'a', sel(1), 'typing', 1000);
  model.apply(1, 1, 'b', sel(2), 'typing', 999);
  model.apply(2, 2, '**c**', sel(7), 'paste', 1001);
  model.select(sel(7, 6));
  model.apply(7, 7, 'd', sel(8), 'typing', 1002);
  model.apply(7, 8, '', sel(7), 'delete', 1003);
  for (const expected of ['ab**c**d', 'ab**c**', 'ab', 'a', '']) {
    model.undo(); assert.equal(model.text, expected);
  }
});

test('an edit after undo discards redo while a selection-only change keeps it', () => {
  const model = create('a');
  model.apply(1, 1, 'b', sel(2), 'paste', 0);
  model.undo(); model.apply(0, 1, 'a', sel(1), 'paste', 1);
  assert.equal(model.redo(), true);
  model.undo(); model.apply(1, 1, 'c', sel(2), 'paste', 2);
  assert.equal(model.redo(), false);
  assert.equal(model.text, 'ac');
});

test('history retains the newest hundred transactions', () => {
  const model = create('');
  for (let i = 0; i < 105; i++) model.apply(i, i, 'x', sel(i + 1), 'paste', i);
  let count = 0;
  while (model.undo()) count++;
  assert.equal(count, 100);
  assert.equal(model.text, 'xxxxx');
  while (model.redo()) count--;
  assert.equal(count, 0);
  assert.equal(model.text, 'x'.repeat(105));
});

test('text history is bounded while a single large paste remains undoable', () => {
  const chunk = 'x'.repeat(2000001);
  const model = create('');
  model.apply(0, 0, chunk, sel(chunk.length), 'paste', 0);
  model.apply(chunk.length, chunk.length, chunk, sel(chunk.length * 2), 'paste', 1);
  assert.equal(model.undo(), true);
  assert.equal(model.text, chunk);
  assert.equal(model.undo(), false);
  const large = create('before');
  const paste = 'y'.repeat(4000001);
  large.apply(0, 6, paste, sel(paste.length), 'paste', 0);
  assert.equal(large.undo(), true);
  assert.equal(large.text, 'before');
  assert.equal(large.redo(), true);
  assert.equal(large.text, paste);
});

test('a completed composition is one exact transaction', () => {
  const model = create('one é two', sel(4, 6));
  model.apply(4, 6, '中文👩🏽‍💻', sel(4 + '中文👩🏽‍💻'.length), 'composition', 1000);
  assert.equal(model.text, 'one 中文👩🏽‍💻 two');
  model.undo();
  assert.equal(model.text, 'one é two');
  assert.deepEqual(model.selection, sel(4, 6));
  assert.equal(model.undo(), false);
});

test('one thousand seeded splices match a reference and undo to the retained checkpoint', () => {
  let seed = 0x51ced;
  const random = n => { seed = (Math.imul(seed, 1664525) + 1013904223) >>> 0; return seed % n; };
  let reference = '\r\n👩🏽‍💻 é **source**\n';
  const model = create(reference);
  const checkpoints = [];
  const inserts = ['a', '\r\n', '👩🏽‍💻', 'é', '\\*', '中文', ''];
  for (let i = 0; i < 1000; i++) {
    const start = random(reference.length + 1);
    const end = start + random(reference.length - start + 1);
    const insert = inserts[random(inserts.length)];
    const next = reference.slice(0, start) + insert + reference.slice(end);
    model.select(sel(end, start));
    if (next !== reference) checkpoints.push({ text: reference, selection: sel(end, start) });
    model.apply(start, end, insert, sel(start + insert.length), 'replace', i);
    reference = next;
    assert.equal(model.text, reference, `splice ${i}`);
  }
  const final = reference;
  let count = 0;
  for (const checkpoint of checkpoints.slice(-100).reverse()) {
    assert.equal(model.undo(), true);
    assert.equal(model.text, checkpoint.text);
    assert.deepEqual(model.selection, checkpoint.selection);
    count++;
  }
  assert.equal(count, 100);
  assert.equal(model.undo(), false);
  while (model.redo()) {}
  assert.equal(model.text, final);
});
