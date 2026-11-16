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
    var segments = [];
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
    // PHP splits paragraphs only at consecutive LF characters, then applies
    // its ASCII trim set. JavaScript trim would also discard NBSP.
    var edgeSpace = /[ \n\r\t\v\0]/;
    for (var at = 0; at < lines.length;) {
      if (lines[at].text === '') {
        blocks.push({ kind: 'blank', start: lines[at].start, end: lines[at].end });
        at++;
        continue;
      }
      var until = at + 1;
      while (until < lines.length && lines[until].text !== '') until++;
      var first = lines[at], last = lines[until - 1];
      var blockStart = first.start, blockEnd = last.end;
      while (blockStart < blockEnd && edgeSpace.test(source[blockStart])) blockStart++;
      while (blockEnd > blockStart && edgeSpace.test(source[blockEnd - 1])) blockEnd--;
      var whole = source.slice(blockStart, blockEnd);
      if (whole === '') {
        for (var emptyLine = at; emptyLine < until; emptyLine++) {
          blocks.push({ kind: 'blank', start: lines[emptyLine].start, end: lines[emptyLine].end });
        }
        at = until;
        continue;
      }
      segments.push({ start: blockStart, end: blockEnd });
      var kind = /^(?:-{3,}|\*{3,}|_{3,})$/.test(whole) ? 'scene' :
        whole[0] === '>' ? 'quote' : 'paragraph';
      for (var line = at; line < until; line++) {
        blocks.push({ kind: kind, start: lines[line].start, end: lines[line].end });
      }
      if (kind === 'scene') {
        paint(blockStart, blockEnd, 'scene');
        hold(first.start, last.end);
        style('scene', blockStart, blockEnd);
      } else if (kind === 'quote') {
        for (var q = at; q < until; q++) {
          var markerAt = q === at ? blockStart : lines[q].start;
          var marker = /^>\s?/.exec(source.slice(markerAt, lines[q].end));
          if (marker) {
            paint(markerAt, markerAt + marker[0].length, 'syntax');
            hold(markerAt, markerAt + marker[0].length);
          }
        }
        var firstMarker = /^>\s?/.exec(whole);
        style('quote', blockStart + (firstMarker ? firstMarker[0].length : 0), blockEnd);
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
    var labels = [];
    for (var segment of segments) {
      var blockText = source.slice(segment.start, segment.end);
      link.lastIndex = 0;
      while ((match = link.exec(blockText)) !== null) {
        var from = segment.start + match.index, to = from + match[0].length;
        if (!free(from, to)) continue;
        if (match[1] === '!') { hold(from, to); continue; }
        var labelFrom = from + 1, labelTo = labelFrom + match[2].length;
        labels.push({ start: labelFrom, end: labelTo });
        hold(from, labelFrom); hold(labelTo, to);
        if (!match[3].startsWith('https://')) continue;
        paint(from, labelFrom, 'syntax'); paint(labelFrom, labelTo, 'link');
        paint(labelTo, to, 'syntax'); style('link', labelFrom, labelTo);
      }
    }

    // PHP protects link tokens before formatting surrounding text. Use a
    // same-length mask for those markers, then scan labels separately.
    for (var maskedAt = 0; maskedAt < source.length; maskedAt++) {
      if (reserved[maskedAt] && '*_'.includes(source[maskedAt])) masked[maskedAt] = '\uE000';
    }
    var innerMask = masked.join('');
    var outer = masked.slice();
    for (var label of labels) {
      for (var labelAt = label.start; labelAt < label.end; labelAt++) {
        if ('*_'.includes(source[labelAt])) outer[labelAt] = '\uE000';
      }
    }
    var outerMask = outer.join('');

    function marks(re, kind, open, close, text, labelOnly) {
      var m;
      while ((m = re.exec(text)) !== null) {
        var from = m.index, to = from + m[0].length;
        var innerFrom = from + open, innerTo = to - close;
        if (labelOnly && !labels.some(function (label) { return from >= label.start && to <= label.end; })) continue;
        if (!free(from, innerFrom) || !free(innerTo, to)) continue;
        paint(from, innerFrom, 'syntax'); paintContent(innerFrom, innerTo, kind);
        paint(innerTo, to, 'syntax'); hold(from, innerFrom); hold(innerTo, to);
        style(kind, innerFrom, innerTo);
      }
    }
    marks(/\*\*([^*\n]+)\*\*/g, 'strong', 2, 2, outerMask, false);
    marks(/(?<!\*)\*([^*\n]+)\*(?!\*)/g, 'em', 1, 1, outerMask, false);
    marks(/(?<![\p{L}\p{N}_])_(?!\s)([^_\n]*[^_\s])_(?![\p{L}\p{N}_])/gu, 'em', 1, 1, outerMask, false);
    marks(/\*\*([^*\n]+)\*\*/g, 'strong', 2, 2, innerMask, true);
    marks(/(?<!\*)\*([^*\n]+)\*(?!\*)/g, 'em', 1, 1, innerMask, true);
    marks(/(?<![\p{L}\p{N}_])_(?!\s)([^_\n]*[^_\s])_(?![\p{L}\p{N}_])/gu, 'em', 1, 1, innerMask, true);

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
