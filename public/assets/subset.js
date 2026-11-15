/* Kiption enhancement layer, markdown subset. ONE module that defines this
 * site's markdown subset for every editor feature: the toolbar, the rich
 * view, smart punctuation and the tests all read the same tables instead of
 * re-stating the rules. Pure functions, no DOM, no insertion logic: the
 * shapes follow the PHP renderer in App\Markdown (underscore marks italic
 * only at a word edge, backslash escapes make a literal marker, a scene
 * break is a line of at least three -, * or _). Node loads it through
 * module.exports; the browser gets Kip.subset. */
'use strict';
(function (root, factory) {
  var api = factory();
  if (typeof module !== 'undefined' && module.exports) { module.exports = api; }
  if (root.Kip) { root.Kip.subset = api; }
})(typeof window !== 'undefined' ? window : globalThis, function () {
  var SCENE = /^\s*(?:-{3,}|\*{3,}|_{3,})\s*$/;
  var LINK = /\[([^\]]*)\]\((https?:\/\/[^\s)]+)\)/g;
  var WORD_EDGE = '(?<![\\p{L}\\p{N}_])';

  function isSceneLine(line) { return SCENE.test(line); }

  /** Split source into blocks with exact source offsets: paragraphs,
   *  one-block quotes, scene breaks. The text is the RAW source slice, so
   *  slice(start, end) always reproduces it; the writer reads the raw and
   *  the toggle maps selections against the offsets. */
  function blocksOf(md) {
    var lines = String(md).split('\n');
    var out = [];
    var pos = 0;
    var current = null;
    var flush = function () {
      if (current) { out.push(current); current = null; }
    };
    for (var i = 0; i < lines.length; i++) {
      var line = lines[i];
      var lineStart = pos;
      var lineEnd = pos + line.length;
      pos = lineEnd + 1; /* the newline this split consumed */
      if (line.trim() === '') { flush(); continue; }
      var type = isSceneLine(line) ? 'break' : (line.charAt(0) === '>' ? 'quote' : 'p');
      if (current && current.type === type && type !== 'break') {
        current.text = md.slice(current.start, lineEnd);
        current.end = lineEnd;
      } else {
        flush();
        current = { type: type, text: line, start: lineStart, end: lineEnd };
      }
    }
    flush();
    return out;
  }

  function escapeLiteral(ch) {
    return ch === '\\' ? '\\\\' : '\\' + ch;
  }

  /** Indexes of characters a backslash makes literal, so the inline scanner
   *  leaves them alone (the same three markers the PHP renderer honors). */
  function escapedIndexes(text) {
    var set = {};
    for (var i = 0; i < text.length; i++) {
      var ch = text.charAt(i);
      if (ch === '\\' && i + 1 < text.length && '*_\\'.indexOf(text.charAt(i + 1)) > -1) {
        set[i + 1] = true;
        i++;
      }
    }
    return set;
  }

  /** Markup spans of one paragraph's text: bold, italic, link. Each span
   *  carries raw (the source slice), text (the inner text), start and end
   *  (absolute offsets of the RAW match), and label plus url for links. */
  function inlines(text) {
    var out = [];
    var escaped = escapedIndexes(text);
    var push = function (kind, raw, inner, start, extra) {
      out.push(Object.assign({ kind: kind, raw: raw, text: inner, start: start, end: start + raw.length }, extra || {}));
    };
    var re = /\*\*([^*\n]+)\*\*|\*([^*\n]+)\*|\[([^\]]*)\]\((https?:\/\/[^\s)]+)\)|_[^_\n]+_/g;
    var m;
    while ((m = re.exec(text)) !== null) {
      if (escaped[m.index]) { continue; }
      if (m[1] !== undefined) { push('bold', m[0], m[1], m.index); continue; }
      if (m[2] !== undefined) { push('italic', m[0], m[2], m.index); continue; }
      if (m[3] !== undefined) { push('link', m[0], m[3], m.index, { label: m[3], url: m[4] }); continue; }
      /* underscore italic: the same word-edge rule the PHP renderer uses */
      var before = m.index === 0 ? '' : text.charAt(m.index - 1);
      var after = text.charAt(m.index + m[0].length);
      var inner = m[0].slice(1, -1);
      var opensOk = before === '' || !/[\p{L}\p{N}_]/u.test(before);
      var innerOk = inner.charAt(0) !== ' ' && inner.slice(-1) !== ' ';
      var closesOk = after === '' || !/[\p{L}\p{N}_]/u.test(after);
      if (opensOk && innerOk && closesOk) { push('italic', m[0], inner, m.index); }
    }
    return out;
  }

  /** True when the offset sits inside a link's ADDRESS (the smart
   *  punctuation guard: never convert inside a link target). */
  function linkTargetAt(text, offset) {
    var re = new RegExp(LINK.source, 'g');
    var m;
    while ((m = re.exec(text)) !== null) {
      var urlAt = m.index + m[0].indexOf(m[2]);
      if (offset >= urlAt && offset < urlAt + m[2].length) { return true; }
    }
    return false;
  }

  return {
    isSceneLine: isSceneLine,
    blocksOf: blocksOf,
    escapeLiteral: escapeLiteral,
    inlines: inlines,
    linkTargetAt: linkTargetAt,
    sceneForms: ['---', '***', '___'],
  };
});
