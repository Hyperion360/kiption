import assert from 'node:assert/strict';
import { createRequire } from 'node:module';
import test from 'node:test';
const require = createRequire(import.meta.url);
const { keyAction } = require('../../public/assets/queue-keys.js');

const press = (key, extra = {}) => ({ key, target: { tagName: 'BODY' }, ...extra });

test('J and K move, A approves, R removes', () => {
  assert.equal(keyAction(press('j')), 'next');
  assert.equal(keyAction(press('K')), 'prev');
  assert.equal(keyAction(press('a')), 'approve');
  assert.equal(keyAction(press('R')), 'remove');
});

test('other keys are left alone', () => {
  assert.equal(keyAction(press('x')), null);
  assert.equal(keyAction(press('Enter')), null);
});

test('typing in a field never triggers a shortcut', () => {
  for (const tagName of ['INPUT', 'TEXTAREA', 'SELECT']) {
    assert.equal(keyAction({ key: 'a', target: { tagName } }), null);
  }
  assert.equal(keyAction({ key: 'r', target: { tagName: 'DIV', isContentEditable: true } }), null);
});

test('a modifier key belongs to the browser', () => {
  assert.equal(keyAction(press('r', { metaKey: true })), null);
  assert.equal(keyAction(press('a', { ctrlKey: true })), null);
  assert.equal(keyAction(press('j', { altKey: true })), null);
});

test('a keystroke already handled elsewhere is skipped', () => {
  assert.equal(keyAction(press('j', { defaultPrevented: true })), null);
});
