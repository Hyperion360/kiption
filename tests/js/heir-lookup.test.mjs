import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import vm from 'node:vm';
const code = readFileSync(new URL('../../public/assets/heir-lookup.js', import.meta.url), 'utf8');

function harness() {
  const timers = new Map(), requests = [];
  let next = 0, now = 0;
  const attrs = {};
  const input = { value: '', events: {}, addEventListener(k, fn) { this.events[k] = fn; } };
  const status = { textContent: '', setAttribute: (k, v) => { attrs[k] = v; }, removeAttribute: k => { delete attrs[k]; } };
  const card = { getAttribute: k => (k === 'data-heir-url' ? '/adminmembers/heir?for=7' : null),
    querySelector: s => (s === 'input[name="penname"]' ? input : s === '[data-heir-status]' ? status : null) };
  const document = { querySelectorAll: () => [card] };
  const window = { Kip: { init: fn => fn() }, fetch: (url) => new Promise(resolve => requests.push({ url, resolve })) };
  vm.runInNewContext(code, { window, document, encodeURIComponent, String, Array,
    setTimeout: (fn, ms) => { timers.set(++next, { at: now + ms, fn }); return next; }, clearTimeout: id => timers.delete(id) });
  return {
    input, status, attrs, requests,
    type(v) { input.value = v; input.events.input(); },
    tick(ms) { now += ms; for (const [id, t] of [...timers]) if (t.at <= now) { timers.delete(id); t.fn(); } },
    async answer(i, body) { requests[i].resolve({ ok: true, json: () => Promise.resolve(body) }); for (let n = 0; n < 8; n++) await Promise.resolve(); },
  };
}

test('typing waits for a pause, asks once, and shows the server words', async () => {
  const h = harness();
  h.type('wr'); h.tick(100); h.type('wren'); h.tick(249);
  assert.equal(h.requests.length, 0);
  h.tick(1);
  assert.equal(h.requests.length, 1);
  assert.equal(h.requests[0].url, '/adminmembers/heir?for=7&penname=wren');
  await h.answer(0, { state: 'found', message: 'Found wren, an admin.' });
  assert.equal(h.status.textContent, 'Found wren, an admin.');
  assert.equal(h.attrs['data-state'], 'found');
});

test('a late answer for an older name is ignored, and a not-found shows as a problem', async () => {
  const h = harness();
  h.type('a'); h.tick(250); h.type('ab'); h.tick(250);
  await h.answer(1, { state: 'none', message: 'No active member goes by that name.' });
  await h.answer(0, { state: 'found', message: 'Found a, a member.' });
  assert.equal(h.status.textContent, 'No active member goes by that name.');
  assert.equal(h.attrs['data-state'], 'problem');
});

test('clearing the field clears the line without a request', () => {
  const h = harness();
  h.status.textContent = 'old'; h.type(''); h.tick(250);
  assert.equal(h.requests.length, 0);
  assert.equal(h.status.textContent, '');
});
