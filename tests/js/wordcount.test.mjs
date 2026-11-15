import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { createRequire } from 'node:module';
const require = createRequire(import.meta.url);
const { wordCount } = require('../../public/assets/wordcount.js');

const cases = JSON.parse(readFileSync(new URL('../fixtures/wordcount.json', import.meta.url), 'utf8'));

for (const c of cases) {
  test('word count of ' + JSON.stringify(c.md) + ' is ' + c.words, () => {
    assert.equal(wordCount(c.md), c.words);
  });
}
