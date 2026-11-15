import { test } from 'node:test';
import assert from 'node:assert/strict';
import { createRequire } from 'node:module';
const require = createRequire(import.meta.url);
const subset = require('../../public/assets/subset.js');

test('scene break lines are exactly the three marker forms of three or more', () => {
  assert.equal(subset.isSceneLine('---'), true);
  assert.equal(subset.isSceneLine('***'), true);
  assert.equal(subset.isSceneLine('___'), true);
  assert.equal(subset.isSceneLine('-----'), true);
  assert.equal(subset.isSceneLine(' -- '), false, 'two dashes are just text');
  assert.equal(subset.isSceneLine(' --- '), true);
  assert.equal(subset.isSceneLine('- - -'), false);
  assert.equal(subset.isSceneLine('hello'), false);
  assert.equal(subset.isSceneLine(''), false);
});

test('blocksOf splits paragraphs, quotes and breaks with exact offsets', () => {
  const md = 'First para.\n\n> A quote line\n> another\n\n---\n\nLast.';
  const blocks = subset.blocksOf(md);
  const kinds = blocks.map((b) => b.type);
  assert.deepEqual(kinds, ['p', 'quote', 'break', 'p']);
  for (const b of blocks) {
    assert.equal(md.slice(b.start, b.end), b.text, 'offsets slice back to the text');
  }
  assert.equal(blocks[1].text.includes('another'), true, 'consecutive quote lines are one block');
  assert.equal(blocks[2].text.trim(), '---');
});

test('escapeLiteral escapes the three literal markers', () => {
  assert.equal(subset.escapeLiteral('*'), '\\*');
  assert.equal(subset.escapeLiteral('_'), '\\_');
  assert.equal(subset.escapeLiteral('\\'), '\\\\');
});

test('inlines finds bold, italic and links with offsets', () => {
  const text = 'plain **loud** and *soft* plus [label](https://a.b)';
  const spans = subset.inlines(text);
  const byKind = {};
  for (const s of spans) { (byKind[s.kind] ??= []).push(s); }
  assert.equal(byKind.bold.length, 1);
  assert.equal(byKind.italic.length, 1);
  assert.equal(byKind.link.length, 1);
  const link = byKind.link[0];
  assert.equal(link.label, 'label');
  assert.equal(link.url, 'https://a.b');
  for (const s of spans) {
    assert.equal(text.slice(s.start, s.end), s.raw, 'span offsets slice back to the raw match');
  }
});

test('underscore marks italic only at word edges, like the php renderer', () => {
  assert.equal(subset.inlines('snake_case_word').some((s) => s.kind === 'italic'), false);
  const spans = subset.inlines('a _soft_ spot');
  assert.equal(spans.some((s) => s.kind === 'italic' && s.text === 'soft'), true);
});

test('escaped markers are literal text, never spans', () => {
  const spans = subset.inlines('5 \\* 3 and 7 \\* 2 and \\_slow\\_');
  assert.equal(spans.some((s) => s.kind === 'bold' || s.kind === 'italic'), false);
});

test('linkTargetAt finds the address range for the smart punctuation guard', () => {
  const text = 'see [x](https://a.b/c_d) end';
  const at = text.indexOf('https://a.b/c_d');
  assert.equal(subset.linkTargetAt(text, at + 3), true, 'inside the address');
  assert.equal(subset.linkTargetAt(text, 1), false, 'outside');
});
