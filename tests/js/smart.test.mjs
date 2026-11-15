import { test } from 'node:test';
import assert from 'node:assert/strict';
import { createRequire } from 'node:module';
const require = createRequire(import.meta.url);
const smart = require('../../public/assets/smart.js');

/** What the $key press does to the box: null means the keystroke stays
 *  natural (no preventDefault, no insert). A result means the keystroke is
 *  replaced: the range before the caret is swapped for the text, through
 *  the shared insert helper, so one undo removes the conversion. */
const fakeBox = (value, caret) => ({ value, selectionStart: caret, selectionEnd: caret,
  setRangeText(t, a, b) { this.value = value.slice(0, a) + t + value.slice(b); this.selectionStart = this.selectionEnd = a + t.length; },
  setSelectionRange(a, b) { this.selectionStart = a; this.selectionEnd = b; },
  dispatchEvent(ev) { this.dispatched = (this.dispatched ?? []).concat(ev && ev.type); } });

test('a word-internal apostrophe becomes a right single curly quote', () => {
  const box = fakeBox('don', 3);
  assert.deepEqual(smart.forKey(box, "'"), { from: 3, to: 3, text: '’' });
});

test('a straight quote after whitespace opens curly', () => {
  const box = fakeBox('say ', 4);
  assert.deepEqual(smart.forKey(box, '"'), { from: 4, to: 4, text: '“' });
  assert.deepEqual(smart.forKey(fakeBox("say '", 5), '"'), { from: 5, to: 5, text: '”' });
});

test('a straight quote after a letter closes curly', () => {
  assert.deepEqual(smart.forKey(fakeBox('he said', 7), '"'), { from: 7, to: 7, text: '”' });
});

test('the second hyphen of an inline pair becomes an em dash', () => {
  const box = fakeBox('wait-', 5);
  assert.deepEqual(smart.forKey(box, '-'), { from: 4, to: 5, text: '—' });
  const late = fakeBox('a long wait-', 12);
  assert.deepEqual(smart.forKey(late, '-'), { from: 11, to: 12, text: '—' }, 'the pair converts anywhere inline');
});

test('hyphens at line start stay a scene break in every width', () => {
  assert.equal(smart.forKey(fakeBox('--', 2), '-'), null, 'the third hyphen keeps typing');
  assert.equal(smart.forKey(fakeBox('x\n--', 4), '-'), null, 'after a newline the line starts fresh');
  assert.equal(smart.forKey(fakeBox('- oh - ', 7), '-'), null, 'no word before the pair on the line, no conversion');
});

test('the third dot becomes an ellipsis', () => {
  assert.deepEqual(smart.forKey(fakeBox('wait..', 6), '.'), { from: 4, to: 6, text: '…' });
  assert.equal(smart.forKey(fakeBox('wait....', 8), '.'), null, 'four dots are literal');
  assert.equal(smart.forKey(fakeBox('wait.', 5), '.'), null, 'one dot stays');
});

test('nothing converts inside a link target', () => {
  const link = 'see [x](https://a.b/c--d..e) end';
  const urlAt = link.indexOf('c--');
  assert.equal(smart.forKey(fakeBox(link, urlAt + 3), '-'), null, 'hyphen inside the address');
  assert.equal(smart.forKey(fakeBox(link, urlAt + 8), '.'), null, 'dot inside the address');
  const label = 'a -- b [label](https://x) end';
  assert.deepEqual(smart.forKey(fakeBox(label, 4), '-'), { from: 3, to: 4, text: '—' }, 'outside the target the pair still converts');
});

test('an escaped marker never converts', () => {
  assert.equal(smart.forKey(fakeBox('x \\-', 4), '-'), null, 'the backslash keeps the pair literal');
});

test('other keys pass through untouched', () => {
  assert.equal(smart.forKey(fakeBox('abc', 3), 'x'), null);
  assert.equal(smart.forKey(fakeBox('abc', 3), 'Enter'), null);
});
