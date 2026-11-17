'use strict';
(function (root, factory) {
  var api = factory();
  if (typeof module !== 'undefined' && module.exports) module.exports = api;
  if (root.Kip) root.Kip.richProjection = api;
})(typeof window !== 'undefined' ? window : globalThis, function () {
  function spans(source, subset) {
    var out = [], cursor = 0;
    subset.blocksOf(source).forEach(function (block) {
      if (block.start > cursor) out.push({ type: 'gap', start: cursor, end: block.start });
      out.push({ type: block.type, start: block.start, end: block.end }); cursor = block.end;
    });
    if (cursor < source.length || !out.length) out.push({ type: 'gap', start: cursor, end: source.length });
    return out;
  }
  function renderSpan(doc, source, span, subset, labels) {
    var nodeMap = new WeakMap(), tag = span.type === 'quote' ? 'blockquote' : span.type === 'p' ? 'p' : 'div';
    var element = doc.createElement(tag); element.className = 'writer-block writer-' + span.type;
    function text(parent, value, start) {
      var node = doc.createTextNode(value); parent.appendChild(node); nodeMap.set(node, { start: start, end: start + value.length });
    }
    function plain(parent, value, start) {
      var cursor = 0;
      for (var i = 0; i < value.length - 1; i++) {
        if (value[i] !== '\\' || !'*_\\'.includes(value[i + 1])) continue;
        text(parent, value.slice(cursor, i), start + cursor);
        // The slash is source syntax, not a visible character. Keep the
        // visible character mapped to its original source offset.
        text(parent, value[i + 1], start + i + 1);
        cursor = ++i + 1;
      }
      text(parent, value.slice(cursor), start + cursor);
    }
    function inline(parent, value, start) {
      var matches = subset.inlines(value), cursor = 0;
      var ambiguous = /!\[|\*{3}|\*[^*\n]*_[^\n]*\*|_[^_\n]*\*[^\n]*_/.test(value) ||
        matches.some(function (match, i) { return /[*_\[\]\\]/.test(match.text) || (i > 0 && match.start <= matches[i - 1].end); });
      if (ambiguous) { plain(parent, value, start); return; }
      matches.forEach(function (match) {
        plain(parent, value.slice(cursor, match.start), start + cursor);
        var child = doc.createElement(match.kind === 'bold' ? 'strong' : match.kind === 'italic' ? 'em' : 'span');
        if (match.kind === 'link') child.className = 'writer-link';
        text(child, match.text, start + match.start + (match.kind === 'bold' ? 2 : 1));
        parent.appendChild(child); cursor = match.end;
      });
      plain(parent, value.slice(cursor), start + cursor);
    }
    var raw = source.slice(span.start, span.end);
    if (span.type === 'break') {
      element.appendChild(doc.createElement('hr')); element.setAttribute('aria-label', labels.scene);
    } else if (span.type === 'quote') {
      var cursor = span.start;
      raw.split('\n').forEach(function (line, index, lines) {
        var skip = line.startsWith('> ') ? 2 : line.startsWith('>') ? 1 : 0;
        inline(element, line.slice(skip), cursor + skip); cursor += line.length;
        if (index < lines.length - 1) { text(element, '\n', cursor); cursor++; }
      });
    } else {
      if (span.type === 'gap') text(element, raw, span.start);
      else inline(element, raw, span.start);
    }
    function edge(node, last) {
      if (node.nodeType === 3) { var map = nodeMap.get(node); return map ? (last ? map.end : map.start) : null; }
      var children = Array.from(node.childNodes); if (last) children.reverse();
      for (var i = 0; i < children.length; i++) { var value = edge(children[i], last); if (value !== null) return value; }
      return null;
    }
    function localPoint(node, offset) {
      if (!element.contains(node) && node !== element) return null;
      if (node === element && offset === 0) return span.start;
      if (node === element && offset === element.childNodes.length) return span.end;
      if (node.nodeType === 3) { var map = nodeMap.get(node); return map ? Math.min(map.end, map.start + offset) : null; }
      var next = node.childNodes[offset], previous = node.childNodes[offset - 1];
      if (next) { var start = edge(next, false); if (start !== null) return start; }
      if (previous) { var end = edge(previous, true); if (end !== null) return end; }
      return offset === 0 ? span.start : span.end;
    }
    var delta=0;
    var result={element:element,start:span.start,end:span.end,
      point:function(node,offset){var position=localPoint(node,offset);return position===null?null:position+delta;},
      shift:function(amount){delta+=amount;result.start+=amount;result.end+=amount;}};
    return result;
  }
  return { spans: spans, renderSpan: renderSpan };
});
