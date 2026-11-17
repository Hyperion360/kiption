import assert from 'node:assert/strict';
import { createRequire } from 'node:module';
import test from 'node:test';
const require = createRequire(import.meta.url);
const { isTyping } = require('../../public/assets/sitehead.js');

test('the / shortcut stays out of the way while typing', () => {
  assert.equal(isTyping({ tagName: 'INPUT' }), true);
  assert.equal(isTyping({ tagName: 'TEXTAREA' }), true);
  assert.equal(isTyping({ tagName: 'DIV', isContentEditable: true }), true);
  assert.equal(isTyping({ tagName: 'BODY' }), false);
  assert.equal(isTyping(null), false);
});
