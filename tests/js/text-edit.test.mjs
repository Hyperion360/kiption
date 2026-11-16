import { test } from 'node:test';
import assert from 'node:assert/strict';
import { createRequire } from 'node:module';
const require = createRequire(import.meta.url);
const textEdit = require('../../public/assets/text-edit.js');

/** A fake textarea the tests can inspect, the shape insertText and the
 *  wrappers actually touch. */
const fakeBox = (value, selStart, selEnd) => ({
  value,
  selectionStart: selStart,
  selectionEnd: selEnd,
  setRangeText(t, s, e, mode) {
    this.value = value.slice(0, s) + t + value.slice(e);
    const caret = mode === 'end' ? s + t.length : s;
    this.selectionStart = this.selectionEnd = caret;
    this.rangeArgs = [t, s, e, mode];
  },
  setSelectionRange(a, b) { this.selectionStart = a; this.selectionEnd = b; },
  dispatchEvent(e) { this.dispatched = (this.dispatched ?? []).concat(e && e.type); },
});

const docThat = (ok) => ({ execCommand: (...args) => { this.lastArgs = args; return ok; } });

test('insertText prefers execCommand and never touches setRangeText', () => {
  const box = fakeBox('ab', 1, 1);
  const doc = { execCommand: (cmd, a, text) => { box.cmd = [cmd, a, text]; return true; } };
  textEdit.insertText(box, 'X', doc);
  assert.deepEqual(box.cmd, ['insertText', false, 'X']);
  assert.equal(box.value, 'ab', 'the browser did the edit');
  assert.equal(box.rangeArgs, undefined);
  assert.deepEqual(box.dispatched, ['input']);
});

test('insertText falls back to setRangeText when execCommand fails', () => {
  const box = fakeBox('ab', 1, 1);
  const doc = { execCommand: () => false };
  textEdit.insertText(box, 'X', doc);
  assert.equal(box.value, 'aXb');
  assert.deepEqual(box.rangeArgs, ['X', 1, 1, 'end']);
  assert.deepEqual(box.dispatched, ['input']);
});

test('wrapSelection wraps a plain selection and keeps it selected inside', () => {
  const box = fakeBox('say word now', 4, 8);
  textEdit.wrapSelection(box, '**', '**');
  assert.equal(box.value, 'say **word** now');
  assert.deepEqual([box.selectionStart, box.selectionEnd], [6, 10]);
  assert.deepEqual(box.dispatched, ['input']);
});

test('wrapSelection toggles already wrapped text off', () => {
  const box = fakeBox('say **word** now', 6, 10);
  textEdit.wrapSelection(box, '**', '**');
  assert.equal(box.value, 'say word now');
  assert.deepEqual([box.selectionStart, box.selectionEnd], [6, 10]);
});

test('wrapSelection with an empty selection places the caret between markers', () => {
  const box = fakeBox('ab', 1, 1);
  textEdit.wrapSelection(box, '*', '*');
  assert.equal(box.value, 'a**b');
  assert.equal(box.selectionStart, 2);
});

test('wrapSelection trims trailing whitespace out of the markers', () => {
  const box = fakeBox('a word ', 2, 7);
  textEdit.wrapSelection(box, '**', '**');
  assert.equal(box.value, 'a **word** ');
});

test('prefixLines prefixes every selected line with the quote marker', () => {
  const box = fakeBox('one\ntwo\nthree', 4, 8);
  textEdit.prefixLines(box, '> ');
  assert.equal(box.value, 'one\n> two\n> three');
});

test('prefixLines skips empty lines so blanks stay blanks', () => {
  const box = fakeBox('one\n\ntwo', 0, 7);
  textEdit.prefixLines(box, '> ');
  assert.equal(box.value, '> one\n\n> two');
});
