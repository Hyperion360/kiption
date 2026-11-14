import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { createRequire } from 'node:module';
const require = createRequire(import.meta.url);
const { convertNodeTree, unwrapGoogleUrl, normalizeMarkdown, attach } = require('../../public/assets/paste.js');

const el = (tag, attrs = {}, kids = []) => ({ nodeType: 1, tagName: tag.toUpperCase(), childNodes: kids,
  style: attrs.style || {}, getAttribute: (k) => attrs[k] ?? null });
const tx = (s) => ({ nodeType: 3, nodeValue: s });
const fixture = (name) => readFileSync(new URL('../fixtures/paste/' + name, import.meta.url), 'utf8').replace(/\n+$/, '');

test('Google redirect links unwrap to the real target', () => {
  assert.equal(unwrapGoogleUrl('https://www.google.com/url?q=https://example.org/a%20b&sa=D&ust=1'), 'https://example.org/a b');
  assert.equal(unwrapGoogleUrl('https://example.org/x'), 'https://example.org/x');
});

test('only https links survive', () => {
  assert.equal(unwrapGoogleUrl('javascript:alert(1)'), '');
  assert.equal(unwrapGoogleUrl('http://example.org'), '');
  assert.equal(unwrapGoogleUrl('https://www.google.com/url?q=javascript:alert(1)'), '');
  assert.equal(unwrapGoogleUrl('/relative'), '');
});

test('three or more blank lines collapse to one blank line and the result is trimmed', () => {
  assert.equal(normalizeMarkdown('a\n\n\n\nb\n\n'), 'a\n\nb');
});

test('Docs wrapper b with normal font-weight is NOT bold', () => {
  const tree = el('b', { style: { fontWeight: 'normal' } }, [el('p', {}, [tx('Plain text')])]);
  assert.equal(convertNodeTree(tree), 'Plain text');
});

test('a bare b or strong is bold (Word), but b with explicit normal weight is not (Docs)', () => {
  assert.equal(convertNodeTree(el('p', {}, [el('b', {}, [tx('word bold')])])), '**word bold**');
  assert.equal(convertNodeTree(el('p', {}, [el('strong', {}, [tx('s')])])), '**s**');
  assert.equal(convertNodeTree(el('p', {}, [el('b', { style: { fontWeight: 'normal' } }, [tx('n')])])), 'n');
});

test('span with font-weight 700 is bold, 400 is not', () => {
  assert.equal(convertNodeTree(el('p', {}, [el('span', { style: { fontWeight: '700' } }, [tx('loud')])])), '**loud**');
  assert.equal(convertNodeTree(el('p', {}, [el('span', { style: { fontWeight: '400' } }, [tx('quiet')])])), 'quiet');
});

test('italic comes from font-style, not from the tag name alone', () => {
  assert.equal(convertNodeTree(el('p', {}, [el('span', { style: { fontStyle: 'italic' } }, [tx('slant')])])), '*slant*');
  assert.equal(convertNodeTree(el('p', {}, [el('em', {}, [tx('tag')])])), '*tag*');
});

test('bold and italic nest as ***text***', () => {
  const s = el('span', { style: { fontWeight: '700', fontStyle: 'italic' } }, [tx('both')]);
  assert.equal(convertNodeTree(el('p', {}, [s])), '***both***');
  assert.equal(convertNodeTree(el('p', {}, [el('strong', {}, [el('b', {}, [tx('x')])])])), '**x**');
});

test('inner spaces stay outside the markers', () => {
  assert.equal(convertNodeTree(el('p', {}, [tx('a'), el('b', {}, [tx(' bold ')]), tx('b')])), 'a **bold** b');
});

test('headings become a bold paragraph; lists keep a literal marker', () => {
  assert.equal(convertNodeTree(el('h2', {}, [tx('Chapter One')])), '**Chapter One**');
  assert.equal(convertNodeTree(el('h2', {}, [el('b', {}, [tx('Chapter One')])])), '**Chapter One**');
  const ul = el('ul', {}, [el('li', {}, [tx('one')]), el('li', {}, [tx('two')])]);
  assert.equal(convertNodeTree(ul), '- one\n\n- two');
  const ol = el('ol', {}, [el('li', {}, [tx('a')]), el('li', {}, [tx('b')])]);
  assert.equal(convertNodeTree(ol), '1. a\n\n2. b');
});

test('scene break forms become ---', () => {
  assert.equal(convertNodeTree(el('hr')), '---');
  assert.equal(convertNodeTree(el('p', {}, [tx('* * *')])), '---');
  assert.equal(convertNodeTree(el('p', {}, [tx('***')])), '---');
  assert.equal(convertNodeTree(el('p', {}, [tx('~')])), '---');
});

test('links keep an https target and drop the rest', () => {
  const good = el('a', { href: 'https://www.google.com/url?q=https://example.org&sa=D' }, [tx('link')]);
  assert.equal(convertNodeTree(el('p', {}, [good])), '[link](https://example.org)');
  const bad = el('a', { href: 'javascript:alert(1)' }, [tx('click')]);
  assert.equal(convertNodeTree(el('p', {}, [bad])), 'click');
});

test('quotes keep a > prefix', () => {
  assert.equal(convertNodeTree(el('blockquote', {}, [el('p', {}, [tx('wise words')])])), '> wise words');
});

test('hostile tags yield only their text, never markup', () => {
  const tree = el('div', {}, [el('script', {}, [tx('alert(1)')]), el('iframe', { src: 'https://evil' }, [tx('x')]),
    el('p', { onerror: 'boom' }, [tx('safe')]), el('img', { src: 'https://x/y.png', alt: 'pic' })]);
  assert.equal(convertNodeTree(tree), 'safe');
});

test('non-breaking spaces and curly quotes are kept as text; empty paragraphs vanish', () => {
  assert.equal(convertNodeTree(el('div', {}, [el('p', {}, [tx('“Hi,” she said.')]), el('p', {}, [tx(' ')])])), '“Hi,” she said.');
  assert.equal(convertNodeTree(el('p', {}, [tx('a b')])), 'a b');
});

const docsBasic = () => el('b', { style: { fontWeight: 'normal' } }, [
  el('p', {}, [
    el('span', { style: { fontWeight: '400', fontStyle: 'italic' } }, [tx('slanted')]), tx(' and '),
    el('span', { style: { fontWeight: '700' } }, [tx('loud')]), tx(' '),
    el('a', { href: 'https://www.google.com/url?q=https://example.org&sa=D' }, [el('span', {}, [tx('link')])]),
  ]),
]);
const wordBasic = () => el('div', {}, [
  el('h1', {}, [tx('Chapter Seven')]),
  el('p', {}, [tx('She said '), el('i', {}, [tx('no')]), tx(', and '), el('b', {}, [tx('meant')]), tx(' it.')]),
  el('p', {}, [tx('* * *')]),
  el('ul', {}, [el('li', {}, [tx('first')]), el('li', {}, [tx('second')])]),
  el('p', {}, [tx('“Done.”')]),
]);
const hostile = () => el('div', {}, [
  el('script', {}, [tx('alert(1)')]),
  el('p', {}, [tx('before '), el('a', { href: 'javascript:alert(1)' }, [tx('trap')]), tx(' after')]),
  el('iframe', { src: 'https://evil.example' }, [tx('framed')]),
  el('p', { onerror: 'boom' }, [el('img', { src: 'https://x/y.png' }), tx('kept')]),
]);

for (const [name, build] of [['docs-basic', docsBasic], ['word-basic', wordBasic], ['hostile', hostile]]) {
  test('golden fixture ' + name + ' converts to its stored markdown', () => {
    assert.equal(convertNodeTree(build()), fixture(name + '.md'));
  });
}

function fakeBox() {
  const on = {};
  return { on, addEventListener: (t, f) => { on[t] = f; } };
}
function pasteEvent(data, box) {
  const ev = { clipboardData: { getData: (t) => data[t] || '' }, prevented: false, preventDefault() { this.prevented = true; } };
  box.on.paste(ev);
  return ev;
}

test('attach inserts the converted text and takes over the paste', () => {
  const box = fakeBox(); const got = [];
  attach(box, (h) => 'MD:' + h, (b, t) => got.push(t));
  const ev = pasteEvent({ 'text/html': '<p>x</p>', 'text/plain': 'x' }, box);
  assert.deepEqual(got, ['MD:<p>x</p>']);
  assert.equal(ev.prevented, true);
});

test('attach leaves plain pastes, shift pastes and failed conversions to the browser or the plain text', () => {
  let box = fakeBox(); let got = [];
  attach(box, () => 'md', (b, t) => got.push(t));
  assert.equal(pasteEvent({ 'text/plain': 'just text' }, box).prevented, false);
  box.on.keydown({ shiftKey: true, metaKey: true, key: 'V' });
  assert.equal(pasteEvent({ 'text/html': '<p>x</p>' }, box).prevented, false);
  assert.equal(pasteEvent({ 'text/html': '<p>x</p>' }, box).prevented, true, 'the shift flag clears after one paste');
  box = fakeBox(); got = [];
  attach(box, () => '', (b, t) => got.push(t));
  pasteEvent({ 'text/html': '<p>x</p>', 'text/plain': 'fallback' }, box);
  assert.deepEqual(got, ['fallback']);
});
