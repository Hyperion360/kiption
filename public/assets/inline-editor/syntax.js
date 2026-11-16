/* Source ranges for the chapter Markdown subset. This module never builds HTML. */
'use strict';
(function (root, factory) {
  var api = factory();
  if (typeof module !== 'undefined' && module.exports) module.exports = api;
  if (root.KipInline) root.KipInline.syntax = api;
})(typeof window !== 'undefined' ? window : globalThis, function () {
  var MAX_DECORATED_UNITS = 2000000;

  function scan(source) {
    if (typeof source !== 'string') throw new TypeError('source must be a string');
    if (source.length === 0) return { spans: [], blocks: [], styledRanges: [] };
    if (source.length > MAX_DECORATED_UNITS) return {
      spans: [{ kind: 'literal', start: 0, end: source.length, text: source }],
      blocks: [], styledRanges: []
    };

    var kinds = Array(source.length).fill('literal');
    var reserved = Array(source.length).fill(false);
    var styles = [];
    var blocks = [];
    function paint(from, to, kind) { for (var i = from; i < to; i++) kinds[i] = kind; }
    function paintContent(from, to, kind) {
      for (var i = from; i < to; i++) {
        if (kinds[i] !== 'syntax' && kinds[i] !== 'scene' && (kind !== 'strong' || kinds[i] !== 'link')) {
          kinds[i] = kind;
        }
      }
    }
    function hold(from, to) { for (var i = from; i < to; i++) reserved[i] = true; }
    function free(from, to) {
      for (var i = from; i < to; i++) if (reserved[i]) return false;
      return true;
    }
    function style(kind, from, to) {
      if (from < to) styles.push({ kind: kind, start: from, end: to });
    }

    var lines = [], start = 0;
    for (var pos = 0; pos <= source.length; pos++) {
      if (pos === source.length || source[pos] === '\n') {
        lines.push({ start: start, end: pos, text: source.slice(start, pos) });
        start = pos + 1;
      }
    }
    for (var at = 0; at < lines.length;) {
      if (lines[at].text.trim() === '') {
        blocks.push({ kind: 'blank', start: lines[at].start, end: lines[at].end });
        at++;
        continue;
      }
      var until = at + 1;
      while (until < lines.length && lines[until].text.trim() !== '') until++;
      var first = lines[at], last = lines[until - 1];
      var whole = source.slice(first.start, last.end).trim();
      var kind = /^(?:-{3,}|\*{3,}|_{3,})$/.test(whole) ? 'scene' :
        first.text[0] === '>' ? 'quote' : 'paragraph';
      for (var line = at; line < until; line++) {
        blocks.push({ kind: kind, start: lines[line].start, end: lines[line].end });
      }
      if (kind === 'scene') {
        var sceneStart = first.start + first.text.indexOf(whole);
        paint(sceneStart, sceneStart + whole.length, 'scene');
        hold(first.start, last.end);
        style('scene', sceneStart, sceneStart + whole.length);
      } else if (kind === 'quote') {
        for (var q = at; q < until; q++) {
          var marker = /^>\s?/.exec(lines[q].text);
          if (marker) {
            paint(lines[q].start, lines[q].start + marker[0].length, 'syntax');
            hold(lines[q].start, lines[q].start + marker[0].length);
          }
        }
        var firstMarker = /^>\s?/.exec(first.text);
        style('quote', first.start + (firstMarker ? firstMarker[0].length : 0), last.end);
      }
      at = until;
    }

    // Keep escape positions in place so all ranges remain source UTF-16 offsets.
    var masked = source.split('');
    for (var e = 0; e + 1 < source.length; e++) {
      if (source[e] === '\\' && '*_\\'.includes(source[e + 1])) {
        masked[e] = '\uE001'; masked[e + 1] = '\uE000'; e++;
      }
    }
    // Reserve link addresses before scanning marks; the reader never formats URLs.
    var link = /(!?)\[([^\]]*)\]\((https?:\/\/[^\s)]+)\)/g, match;
    while ((match = link.exec(source)) !== null) {
      var from = match.index, to = from + match[0].length;
      if (!free(from, to)) continue;
      if (match[1] === '!') { hold(from, to); continue; }
      var labelFrom = from + 1, labelTo = labelFrom + match[2].length;
      hold(from, labelFrom); hold(labelTo, to);
      if (!match[3].startsWith('https://')) continue;
      paint(from, labelFrom, 'syntax'); paint(labelFrom, labelTo, 'link');
      paint(labelTo, to, 'syntax'); style('link', labelFrom, labelTo);
    }

    // A protected URL may contain marker characters. Mask them without
    // changing string length, so enclosing emphasis still sees one text run.
    for (var maskedAt = 0; maskedAt < source.length; maskedAt++) {
      if (reserved[maskedAt] && '*_'.includes(source[maskedAt])) masked[maskedAt] = '\uE000';
    }
    masked = masked.join('');

    function marks(re, kind, open, close) {
      var m;
      while ((m = re.exec(masked)) !== null) {
        var from = m.index, to = from + m[0].length;
        var innerFrom = from + open, innerTo = to - close;
        if (!free(from, innerFrom) || !free(innerTo, to)) continue;
        paint(from, innerFrom, 'syntax'); paintContent(innerFrom, innerTo, kind);
        paint(innerTo, to, 'syntax'); hold(from, innerFrom); hold(innerTo, to);
        style(kind, innerFrom, innerTo);
      }
    }
    marks(/\*\*([^*\n]+)\*\*/g, 'strong', 2, 2);
    marks(/(?<!\*)\*([^*\n]+)\*(?!\*)/g, 'em', 1, 1);
    marks(/(?<![\p{L}\p{N}_])_(?!\s)([^_\n]*[^_\s])_(?![\p{L}\p{N}_])/gu, 'em', 1, 1);

    var spans = [], spanStart = 0, current = kinds[0];
    for (var i = 1; i <= source.length; i++) {
      if (i === source.length || kinds[i] !== current) {
        spans.push({ kind: current, start: spanStart, end: i, text: source.slice(spanStart, i) });
        spanStart = i; current = kinds[i];
      }
    }
    styles.sort(function (a, b) { return a.start - b.start || a.end - b.end; });
    return { spans: spans, blocks: blocks, styledRanges: styles };
  }

  return { scan: scan };
});
