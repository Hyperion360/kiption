import { test } from 'node:test';
import assert from 'node:assert/strict';
import { createRequire } from 'node:module';
const require = createRequire(import.meta.url);
const conflict = require('../../public/assets/ed-conflict.js');

const labels = { copied: 'Copied to the clipboard.', copyFailed: 'Copy did not work.', usedArchive: 'Now showing the archive version.' };
const archive = { title: 'Archive Title', content: 'No one spoke.', notes_before: '', notes_after: 'After', publish_at: '2026-11-18 21:31' };

function fixture({ clipboard, kipWriter = false, withToast = false } = {}) {
  const form = new EventTarget(), events = [], attrs = new Map();
  const field = (name, value, type = 'text') => ({ name, value, type, focus() { this.focused = true; } });
  const fields = [field('title', 'My Title'), field('content', 'Nobody said anything.', 'textarea'), field('notes_before', 'Mine'), field('notes_after', ''), field('publish_at', '', 'datetime-local'), field('base_revision', 'a'.repeat(64), 'hidden')];
  const writerCalls = [];
  if (kipWriter) fields[1].kipWriter = { replace: (value, why) => { writerCalls.push([value, why]); fields[1].value = value; } };
  form.elements = { namedItem: name => fields.find(f => f.name === name) || null };
  form.dataset = { writerServer: JSON.stringify(archive) };
  form.dispatchEvent = form.dispatchEvent.bind(form);
  form.addEventListener('input', () => events.push('input'));
  const col = { removed: new Set(), attrs, setAttribute(k, v) { attrs.set(k, v); }, removeAttribute(k) { attrs.delete(k); this.removed.add(k); } };
  const live = { textContent: '' };
  const listeners = {};
  const button = which => ({ getAttribute: () => which, addEventListener: (t, fn) => { listeners['copy-' + which] = fn; }, removeEventListener() {} });
  const link = { addEventListener: (t, fn) => { listeners.link = fn; }, removeEventListener() {} };
  const section = {
    removed: false, dataset: { edCurrent: 'c'.repeat(64) },
    getAttribute: () => JSON.stringify(labels),
    remove() { this.removed = true; },
    querySelectorAll: () => [button('mine'), button('theirs')],
    querySelector: sel => (sel === '[data-ed-use-archive]' ? link : null),
  };
  const toast = { textContent: '', hidden: true };
  form.querySelector = sel => ({ '[data-ed-conflict]': section, '[data-ed-col]': col, '[data-ed-live]': live, '[data-ed-toast]': withToast ? toast : null })[sel] || null;
  const env = { Event, navigator: { clipboard }, setTimeout: () => 1, clearTimeout() {} };
  const api = conflict.attach(form, env);
  return { api, form, fields, col, live, toast, section, listeners, events, writerCalls };
}

test('without a conflict section the form is left alone', () => {
  const form = { querySelector: () => null };
  assert.equal(conflict.attach(form, {}), null);
});

test('the editor hides behind the cards once the script runs', () => {
  const f = fixture();
  assert.equal(f.col.attrs.get('data-ed-hide'), '');
  f.api.destroy();
  assert.equal(f.col.attrs.has('data-ed-hide'), false, 'destroy restores the open editor');
});

test('copy takes the live editor text for your version and the archive fields for the other', async () => {
  const copied = [];
  const f = fixture({ clipboard: { writeText: async text => { copied.push(text); } } });
  f.fields[1].value = 'Typed since the conflict.';
  assert.equal(await f.api.copy('mine'), true);
  assert.equal(f.live.textContent, labels.copied);
  assert.equal(await f.api.copy('theirs'), true);
  assert.deepEqual(copied, ['Typed since the conflict.', 'No one spoke.']);
});

test('a denied or missing clipboard says so and never throws', async () => {
  const denied = fixture({ clipboard: { writeText: () => Promise.reject(new Error('denied')) } });
  assert.equal(await denied.api.copy('mine'), false);
  assert.equal(denied.live.textContent, labels.copyFailed);
  const missing = fixture({});
  assert.equal(await missing.api.copy('theirs'), false);
  assert.equal(missing.live.textContent, labels.copyFailed);
});

test('use archive swaps the archive fields in, adopts only the shown revision, and opens the editor', () => {
  const f = fixture();
  let prevented = false;
  f.listeners.link({ preventDefault: () => { prevented = true; } });
  assert.equal(prevented, true);
  assert.equal(f.fields[0].value, 'Archive Title');
  assert.equal(f.fields[1].value, 'No one spoke.');
  assert.equal(f.fields[2].value, '');
  assert.equal(f.fields[3].value, 'After');
  assert.equal(f.fields[4].value, '2026-11-18T21:31', 'a datetime-local field takes the T spelling');
  assert.equal(f.fields[5].value, 'c'.repeat(64));
  assert.equal(f.section.removed, true, 'the cards and their draft flag leave the form');
  assert.equal(f.col.attrs.has('data-ed-hide'), false);
  assert.ok(f.col.removed.has('data-ed-conflicted'));
  assert.equal(f.live.textContent, labels.usedArchive);
  assert.equal(f.fields[0].focused, true);
  assert.deepEqual(f.events, ['input']);
});

test('use archive goes through the rich editor when one is mounted', () => {
  const f = fixture({ kipWriter: true });
  f.api.useArchive();
  assert.deepEqual(f.writerCalls, [['No one spoke.', 'restore']]);
});

test('the revision is not adopted by copying or by anything but the explicit choice', async () => {
  const f = fixture({ clipboard: { writeText: async () => {} } });
  await f.api.copy('theirs');
  assert.equal(f.fields[5].value, 'a'.repeat(64));
});

test('copy and Use Archive Version also show their message as a visible toast', async () => {
  const f = fixture({ clipboard: { writeText: async () => {} }, withToast: true });
  await f.api.copy('mine');
  assert.equal(f.toast.textContent, labels.copied);
  assert.equal(f.toast.hidden, false);
  f.api.useArchive();
  assert.equal(f.toast.textContent, labels.usedArchive);
  assert.equal(f.live.textContent, labels.usedArchive);
});
