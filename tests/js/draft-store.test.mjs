import assert from 'node:assert/strict';
import { createRequire } from 'node:module';
import test from 'node:test';
const require = createRequire(import.meta.url);
const { key, write, read, clearOwner, fields, enabled, setEnabled, remove } = require('../../public/assets/draft-store.js');
function memory() {
  const map = new Map();
  return { get length() { return map.size; }, key: i => [...map.keys()][i],
    getItem: k => map.get(k) ?? null, setItem: (k, v) => map.set(k, v), removeItem: k => map.delete(k) };
}
const base = 'a'.repeat(64), now = 100000000;
test('only matching recent whitelisted fields round trip', () => {
  const store = memory(), k = key('5', 'chapter:42');
  const value = fields({ title: 'Title', content: '**draft**\nUnicode: café 😀', access_password: 'secret', csrf: 'token', notes_before: 9 });
  assert.equal(write(store, k, base, value, now), true);
  assert.deepEqual(read(store, k, now + 1).fields, { title: 'Title', content: value.content, notes_before: '', notes_after: '', publish_at: '' });
  assert.ok(!store.getItem(k).includes('secret')); assert.ok(!store.getItem(k).includes('csrf'));
  assert.equal(read(store, key('6', 'chapter:42'), now), null);
  assert.notEqual(key('a:b', 'c'), key('a', 'b:c'));
});
test('new chapters have separate stable recovery identities', () => {
  const store = memory();
  for (const id of ['new:4', 'new:5', 'chapter:4']) assert.equal(write(store, key('5', id), 'new', fields({ content: id }), now), true);
  for (const id of ['new:4', 'new:5', 'chapter:4']) assert.equal(read(store, key('5', id), now).fields.content, id);
});
test('expiry allows the exact boundary and rejects one millisecond beyond', () => {
  const store = memory(), k = key('5', 'chapter:42');
  write(store, k, base, fields({ content: 'work' }), now);
  assert.ok(read(store, k, now + 86400000));
  assert.equal(read(store, k, now + 86400001), null); assert.equal(store.getItem(k), null);
});
test('malformed, unexpected, mismatched, and future records are removed', () => {
  for (const mutate of [v => '{', v => 'null', v => JSON.stringify({ ...v, v: 2 }), v => JSON.stringify({ ...v, key: key('6', 'chapter:42') }),
    v => JSON.stringify({ ...v, at: 0 }), v => JSON.stringify({ ...v, at: now + 300001 }), v => JSON.stringify({ ...v, at: '100' }),
    v => JSON.stringify({ ...v, base: 'invalid' }), v => JSON.stringify({ ...v, fields: [] }),
    v => JSON.stringify({ ...v, fields: { ...v.fields, password: 'unexpected' } }),
    v => JSON.stringify({ ...v, fields: { ...v.fields, content: null } })]) {
    const store = memory(), k = key('5', 'chapter:42');
    write(store, k, base, fields({ content: 'work' }), now); store.setItem(k, mutate(JSON.parse(store.getItem(k))));
    assert.equal(read(store, k, now), null); assert.equal(store.getItem(k), null);
  }
});
test('invalid keys and base tokens never write storage', () => {
  const store = memory();
  assert.equal(write(store, 'theme', base, fields({}), now), false);
  assert.equal(write(store, key('5', 'chapter:42'), 'bad', fields({}), now), false); assert.equal(store.length, 0);
});
test('record cap is serialized UTF-16 size, and does not evict other chapters', () => {
  const store = memory(), k = key('5', 'chapter:42'), other = key('5', 'chapter:43');
  write(store, other, base, fields({ content: 'keep' }), now);
  assert.equal(write(store, k, base, fields({ content: 'a'.repeat(2000000) }), now), false);
  assert.equal(read(store, other, now).fields.content, 'keep');
  store.setItem(k, 'a'.repeat(2000001)); assert.equal(read(store, k, now), null); assert.equal(store.getItem(k), null);
});
test('explicit consent and disabling affect only the current account', () => {
  const store = memory(); store.setItem('theme', 'dark');
  assert.equal(enabled(store, '5'), false); assert.equal(setEnabled(store, '5', true), true); assert.equal(enabled(store, '5'), true);
  for (const owner of ['5', '50', '6']) write(store, key(owner, 'chapter:42'), base, fields({ content: owner }), now);
  assert.equal(setEnabled(store, '5', false), true); assert.equal(enabled(store, '5'), false);
  assert.equal(store.getItem(key('5', 'chapter:42')), null); assert.equal(read(store, key('50', 'chapter:42'), now).fields.content, '50');
  assert.equal(read(store, key('6', 'chapter:42'), now).fields.content, '6'); assert.equal(store.getItem('theme'), 'dark');
});
test('storage access, quota, and removal failures are contained and truthful', () => {
  const denied = { getItem() { throw Error('denied'); }, setItem() { throw Error('quota'); }, removeItem() { throw Error('denied'); }, get length() { throw Error('denied'); } };
  assert.equal(write(denied, key('5', 'chapter:42'), base, fields({}), now), false);
  assert.equal(read(denied, key('5', 'chapter:42'), now), null); assert.equal(remove(denied, 'x'), false);
  assert.equal(enabled(denied, '5'), false); assert.equal(setEnabled(denied, '5', true), false); assert.equal(clearOwner(denied, '5'), false);
  const partial = memory(); partial.setItem(key('5', 'chapter:42'), 'work'); partial.removeItem = () => { throw Error('denied'); };
  assert.equal(clearOwner(partial, '5'), false); assert.equal(partial.getItem(key('5', 'chapter:42')), 'work');
});
