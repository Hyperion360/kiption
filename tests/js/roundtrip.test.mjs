// Automated source contracts. Real DOM, browser menu Undo, IME, and device
// acceptance are separate checks; this does not claim a rich surface round trip.
import assert from 'node:assert/strict';
import { readFileSync, readdirSync } from 'node:fs';
import { createRequire } from 'node:module';
import test from 'node:test';
import vm from 'node:vm';
import { fileURLToPath } from 'node:url';
import { MiniDOMParser } from './minidom.mjs';
const require = createRequire(import.meta.url);
const source = require('../../public/assets/source-document.js');
const subset = require('../../public/assets/subset.js');
const editor = require('../../public/assets/editor.js');
const paste = require('../../public/assets/paste.js');
const sel = (anchor, focus = anchor) => ({ anchor, focus });

// The non-native value/selection facade runs the existing command table once.
// The source document records its resulting splice as one transaction.
function command(model, id) {
  const before = model.text, selection = model.selection;
  const box = {
    value: before,
    selectionStart: Math.min(selection.anchor, selection.focus),
    selectionEnd: Math.max(selection.anchor, selection.focus),
    setRangeText(text, start, end) {
      this.value = this.value.slice(0, start) + text + this.value.slice(end);
      this.selectionStart = this.selectionEnd = start + text.length;
    },
    setSelectionRange(start, end) { this.selectionStart = start; this.selectionEnd = end; }
  };
  editor.run(id, box, {});
  const d = source.difference(before, box.value);
  model.apply(d.start, d.end, d.insert, sel(box.selectionStart, box.selectionEnd), 'command', 1000);
}

const fixtures = new URL('../fixtures/roundtrip/', import.meta.url);
for (const file of readdirSync(fixtures).filter(name => name.endsWith('.md')).sort()) {
  test(`${file}: token inspection preserves source, gaps, selection, and history`, () => {
    const text = readFileSync(new URL(file, fixtures), 'utf8');
    const model = source.create(text, sel(text.length, 0));
    let position = 0, reconstructed = '';
    for (const block of subset.blocksOf(model.text)) {
      assert.equal(block.text, text.slice(block.start, block.end));
      reconstructed += text.slice(position, block.start) + block.text;
      position = block.end;
      for (const span of subset.inlines(block.text)) {
        assert.equal(span.raw, block.text.slice(span.start, span.end));
      }
    }
    reconstructed += text.slice(position);
    assert.equal(reconstructed, text);
    assert.equal(model.text, text);
    assert.deepEqual(model.selection, sel(text.length, 0));
    assert.equal(model.undo(), false);
  });
}

for (const [id, original, selection, expected] of [
  ['bold', 'say word now', sel(8, 4), 'say **word** now'],
  ['italic', 'say word now', sel(4, 8), 'say *word* now'],
  ['link', 'read this', sel(5, 9), 'read [this](https://)'],
  ['quote', 'one\n\ntwo', sel(0, 8), '> one\n\n> two'],
  ['break', 'The end.', sel(8), 'The end.\n\n---'],
  ['bold', 'say **word** now', sel(6, 10), 'say word now']
]) {
  test(`${id}: shared command changes source once and restores exact text and selection`, () => {
    const model = source.create(original, selection);
    command(model, id);
    assert.equal(model.text, expected);
    if (original.includes('**word**')) assert.deepEqual(model.selection, sel(4, 8));
    assert.equal(model.undo(), true);
    assert.equal(model.text, original);
    assert.deepEqual(model.selection, selection);
    assert.equal(model.undo(), false);
    assert.equal(model.redo(), true);
    assert.equal(model.text, expected);
  });
}

test('the existing paste converter feeds one exact transaction without punctuation rewriting', () => {
  const original = 'Before\r\n\r\nAfter é 👩🏽‍💻';
  const model = source.create(original, sel(6));
  const converted = paste.htmlToMarkdown('<p><b>"Wait--"</b> ... <a href="https://example.org/a_b/c--d">link</a></p>', new MiniDOMParser());
  assert.equal(converted, '**"Wait--"** ... [link](https://example.org/a_b/c--d)');
  const listeners = {};
  const box = { addEventListener(name, fn) { listeners[name] = fn; } };
  paste.attach(box, html => paste.htmlToMarkdown(html, new MiniDOMParser()), (_, text) => {
    model.apply(6, 6, text, sel(6 + text.length), 'paste', 1000);
  });
  let prevented = false;
  listeners.paste({ clipboardData: { getData(type) {
    return type === 'text/html' ? '<p><b>"Wait--"</b> ... <a href="https://example.org/a_b/c--d">link</a></p>' : '';
  } }, preventDefault() { prevented = true; } });
  assert.equal(prevented, true);
  assert.equal(model.text, original.slice(0, 6) + converted + original.slice(6));
  model.undo();
  assert.equal(model.text, original);
  assert.equal(model.undo(), false);
});

test('browser exports expose the same source and existing paste APIs', () => {
  const context = vm.createContext({ Kip: {} });
  for (const file of ['source-document', 'paste']) {
    const path = new URL(`../../public/assets/${file}.js`, import.meta.url);
    vm.runInContext(readFileSync(path, 'utf8'), context, { filename: fileURLToPath(path) });
  }
  assert.equal(context.Kip.sourceDocument.create('a\r\nb').text, 'a\r\nb');
  assert.equal(typeof context.Kip.paste?.htmlToMarkdown, 'function');
  assert.equal(typeof context.Kip.paste?.attach, 'function');
  assert.equal(context.Kip.paste.convertNodeTree, context.Kip.pasteToMarkdown);
});

for (const file of readdirSync(fixtures).filter(name => name.endsWith('.md')).sort()) {
  test(`${file}: formatted spans partition the exact source`, () => {
    const projection = require('../../public/assets/rich-projection.js');
    const text = readFileSync(new URL(file, fixtures), 'utf8');
    const parts = projection.spans(text, subset);
    assert.equal(parts.map(part => text.slice(part.start, part.end)).join(''), text);
    assert.equal(parts[0].start, 0);
    assert.equal(parts.at(-1).end, text.length);
    const active = require('../../public/assets/rich-active.js');
    assert.equal(active.readText({nodeType:3, data:text}), text, 'plaintext input distinguishes exact fixture source');
    parts.slice(1).forEach((part, index) => assert.equal(part.start, parts[index].end));
  });
}
test('empty source has one editable formatted span', () => {
  assert.deepEqual(require('../../public/assets/rich-projection.js').spans('', subset), [{ type: 'gap', start: 0, end: 0 }]);
});
