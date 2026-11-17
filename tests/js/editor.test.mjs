import { test } from 'node:test';
import { readFileSync } from 'node:fs';
import vm from 'node:vm';
import { fileURLToPath } from 'node:url';
import assert from 'node:assert/strict';
import { createRequire } from 'node:module';
const require = createRequire(import.meta.url);
const editor = require('../../public/assets/editor.js');
const textEdit = require('../../public/assets/text-edit.js');

const fakeBox = (value, selStart, selEnd) => ({
  value,
  selectionStart: selStart,
  selectionEnd: selEnd,
  setRangeText(t, s, e, mode) {
    this.value = value.slice(0, s) + t + value.slice(e);
    this.selectionStart = this.selectionEnd = s + t.length;
  },
  setSelectionRange(a, b) { this.selectionStart = a; this.selectionEnd = b; },
  dispatchEvent(e) { this.dispatched = (this.dispatched ?? []).concat(e && e.type); },
});

test('handleKey maps the three shortcuts and ignores everything else', () => {
  assert.equal(editor.handleKey({ composing: false }, 'b', true, false), 'bold');
  assert.equal(editor.handleKey({ composing: false }, 'i', false, true), 'italic');
  assert.equal(editor.handleKey({ composing: false }, 'k', true, true), 'link');
  assert.equal(editor.handleKey({ composing: false }, 'b', false, false), null, 'no modifier, no shortcut');
  assert.equal(editor.handleKey({ composing: false }, 'x', true, false), null);
});

test('an active IME composition suppresses every shortcut', () => {
  assert.equal(editor.handleKey({ composing: true }, 'b', true, false), null);
  assert.equal(editor.handleKey({ composing: true }, 'i', true, false), null);
  assert.equal(editor.handleKey({ composing: true }, 'k', true, false), null);
});

test('bold wraps the selection through the shared helpers', () => {
  const box = fakeBox('say word now', 4, 8);
  editor.run('bold', box);
  assert.equal(box.value, 'say **word** now');
});

test('bold on a multi line selection wraps each non empty line separately', () => {
  const box = fakeBox('one\ntwo', 0, 6);
  editor.run('bold', box);
  assert.equal(box.value, '**one**\n**two**');
});

test('bold toggles off an already wrapped selection', () => {
  const box = fakeBox('a **word** b', 4, 8);
  editor.run('bold', box);
  assert.equal(box.value, 'a word b');
});

test('italic wraps with single asterisks', () => {
  const box = fakeBox('say word', 4, 8);
  editor.run('italic', box);
  assert.equal(box.value, 'say *word*');
});

test('link uses the selection as the label and parks the caret on the address', () => {
  const box = fakeBox('read this', 5, 9);
  editor.run('link', box);
  assert.equal(box.value, 'read [this](https://)');
  assert.deepEqual([box.selectionStart, box.selectionEnd], [12, 20], 'the address is selected for typing');
});

test('link with an empty selection leaves the caret on the label', () => {
  const box = fakeBox('ab', 1, 1);
  editor.run('link', box);
  assert.equal(box.value, 'a[](https://)b');
  assert.equal(box.selectionStart, 2);
});

test('quote prefixes the selected lines', () => {
  const box = fakeBox('one\ntwo\nthree', 4, 8);
  editor.run('quote', box);
  assert.equal(box.value, 'one\n> two\n> three');
});

test('break inserts a scene break with a blank line before it', () => {
  const box = fakeBox('The end.', 8, 8);
  editor.run('break', box);
  assert.equal(box.value, 'The end.\n\n---');
});


// Exercise the actual browser listener and smart module, including event.isComposing.
function wiredEditor(value, caret) {
  const listeners = {};
  const box = {
    value, selectionStart: caret, selectionEnd: caret, dataset: {},
    setRangeText(text, start, end) {
      this.value = this.value.slice(0, start) + text + this.value.slice(end);
      this.selectionStart = this.selectionEnd = start + text.length;
    },
    setSelectionRange(start, end) { this.selectionStart = start; this.selectionEnd = end; },
    addEventListener(name, fn) { (listeners[name] ||= []).push(fn); },
    dispatchEvent(event) { (listeners[event.type] || []).forEach(fn => fn(event)); },
    closest() { return null; }
  };
  const doc = {
    querySelectorAll() { return [box]; },
    createElement() { return { setAttribute() {}, appendChild() {} }; }
  };
  const ready = [];
  const context = vm.createContext({ Kip: { init(fn) { ready.push(fn); } }, Event, document: doc });
  for (const file of ['subset', 'text-edit', 'smart', 'editor']) {
    const path = new URL(`../../public/assets/${file}.js`, import.meta.url);
    vm.runInContext(readFileSync(path, 'utf8'), context, { filename: fileURLToPath(path) });
  }
  ready.forEach(fn => fn(doc));
  const key = (key, extra = {}) => {
    const event = { type: 'keydown', key, ctrlKey: false, metaKey: false, altKey: false,
      prevented: false, preventDefault() { this.prevented = true; }, ...extra };
    box.dispatchEvent(event);
    return event;
  };
  return { box, key };
}

test('browser composition state blocks punctuation before smart conversion, then resumes it', () => {
  const { box, key } = wiredEditor('wait-', 5);
  box.dispatchEvent({ type: 'compositionstart' });
  assert.equal(key('-').prevented, false);
  assert.equal(box.value, 'wait-');
  assert.equal(key('b', { ctrlKey: true }).prevented, false);
  box.dispatchEvent({ type: 'compositionend' });
  assert.equal(key('-').prevented, true);
  assert.equal(box.value, 'wait\u2014');
});

test('native isComposing blocks punctuation and shortcuts without compositionstart', () => {
  const { box, key } = wiredEditor('word', 4);
  assert.equal(key('"', { isComposing: true }).prevented, false);
  assert.equal(key('b', { ctrlKey: true, isComposing: true }).prevented, false);
  assert.equal(box.value, 'word');
});

test('the wired punctuation listener preserves link targets and escaped markers', () => {
  for (const [text, caret, pressed] of [
    ['see [x](https://x/a-b)', 19, '-'],
    ['x \\-', 4, '-']
  ]) {
    const { box, key } = wiredEditor(text, caret);
    assert.equal(key(pressed).prevented, false);
    assert.equal(box.value, text);
  }
});
