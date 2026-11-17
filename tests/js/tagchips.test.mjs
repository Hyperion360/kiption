import { test } from 'node:test';
import assert from 'node:assert/strict';
import { createRequire } from 'node:module';
const { next, stateOf, matches, countOf } = createRequire(import.meta.url)('../../public/assets/tagchips.js');

test('a chip cycles off, include, exclude, off', () => {
  assert.equal(next('off'), 'include');
  assert.equal(next('include'), 'exclude');
  assert.equal(next('exclude'), 'off');
});
test('exclude wins when both boxes arrive checked', () => {
  assert.equal(stateOf(true, true), 'exclude');
  assert.equal(stateOf(true, false), 'include');
  assert.equal(stateOf(false, false), 'off');
  assert.equal(stateOf(false, null), 'off');
});
test('the finder matches names by case-insensitive substring', () => {
  assert.equal(matches('Slow Burn', ' burn '), true);
  assert.equal(matches('Slow Burn', 'xyz'), false);
  assert.equal(matches('Slow Burn', ''), true);
});
test('counts are read from the trailing parenthesis', () => {
  assert.equal(countOf('Slow Burn (12)'), '12');
  assert.equal(countOf('Slow Burn'), '0');
});
