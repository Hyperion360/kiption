import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { createRequire } from 'node:module';
import test from 'node:test';

const require = createRequire(import.meta.url);
const { scan } = require('../../public/assets/inline-editor/syntax.js');
const cases = JSON.parse(readFileSync(new URL('../fixtures/inline-editor-parity.json', import.meta.url), 'utf8'));
function requireSpans(fixture) {
  assert.ok(Array.isArray(fixture.spans), 'every parity row must state exact spans');
  return fixture.spans;
}

for (const fixture of cases) {
  test(`chapter spans preserve source and match reader styles: ${fixture.source}`, () => {
    const result = scan(fixture.source);
    assert.equal(result.spans.map(span => span.text).join(''), fixture.source);
    let offset = 0;
    for (const span of result.spans) {
      assert.equal(span.start, offset);
      assert.equal(span.end, offset + span.text.length);
      offset = span.end;
    }
    assert.equal(offset, fixture.source.length);
    assert.deepEqual(result.styledRanges, fixture.styledRanges);
    assert.deepEqual(result.spans.map(({ kind, start, end }) => ({ kind, start, end })), requireSpans(fixture));
    assert.equal(JSON.stringify(result).includes('src='), false);
  });
}

test('rejects a parity fixture without expected span boundaries', () => {
  assert.throws(() => requireSpans({ source: 'missing' }), /exact spans/);
});

test('source offsets count UTF-16 code units and never reinterpret raw HTML', () => {
  const source = '👩🏽‍💻 **Hi** <script>alert(1)</script>';
  const result = scan(source);
  assert.deepEqual(result.styledRanges, [{ kind: 'strong', start: 10, end: 12 }]);
  assert.equal(result.spans.map(span => span.text).join(''), source);
  assert.equal(result.spans.some(span => /^(script|image|url)$/.test(span.kind)), false);
});

test('marked-link label comparisons do not grow quadratically', () => {
  const countComparisons = count => {
    const source = Array(count).fill('[a *b*](https://e.test/)').join(' ');
    const originalSome = Array.prototype.some;
    let comparisons = 0;
    Array.prototype.some = function (predicate, thisArg) {
      return originalSome.call(this, function (...args) {
        comparisons++;
        return predicate.apply(this, args);
      }, thisArg);
    };
    try {
      const result = scan(source);
      assert.equal(result.styledRanges.length, count * 2);
    } finally {
      Array.prototype.some = originalSome;
    }
    return comparisons;
  };
  const small = countComparisons(1000);
  const large = countComparisons(4000);
  assert.ok(large <= small * 6 + 40000, `fourfold input used ${large} label comparisons`);
});

test('large chapters fall back to one literal span, and invalid source is refused', () => {
  const source = 'x'.repeat(2000001);
  assert.deepEqual(scan(source), {
    spans: [{ kind: 'literal', start: 0, end: source.length, text: source }],
    blocks: [], styledRanges: []
  });
  assert.deepEqual(scan(''), { spans: [], blocks: [], styledRanges: [] });
  assert.throws(() => scan(null), TypeError);
});
