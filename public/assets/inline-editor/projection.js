/* Editable Markdown projection. All offsets refer to the source string. */
'use strict';
(function (root, factory) {
  var api = factory(root);
  if (typeof module !== 'undefined' && module.exports) module.exports = api;
  if (root.KipInline) root.KipInline.projection = api;
})(typeof window !== 'undefined' ? window : globalThis, function (root) {
  function create(doc, model, options) {
    if (!doc || !model || typeof model.text !== 'string') throw new TypeError('projection inputs');
    var syntax = (options && options.syntax) || (root.KipInline && root.KipInline.syntax);
    if (!syntax || typeof syntax.scan !== 'function') throw new TypeError('syntax scanner');
    var element = doc.createElement('div');
    element.className = 'ki-document';
    element.contentEditable = 'true';
    element.setAttribute('role', 'textbox');
    element.setAttribute('aria-multiline', 'true');
    element.setAttribute('aria-label', 'Chapter Text');
    var lines = [], frozen = false, renderedRevision = -1;
    var mappedText = new WeakMap(), mappedSpan = new WeakMap(), mappedLine = new WeakMap();
    function lineRanges(source) {
      var result = [], start = 0;
      for (var i = 0; i <= source.length; i++) {
        if (i === source.length || source[i] === '\n') {
          result.push({start:start, end:i}); start = i + 1;
        }
      }
      return result;
    }
    function pieces(source, range, scan) {
      var cuts = [range.start, range.end];
      for (var span of scan.spans) {
        if (span.end <= range.start || span.start >= range.end) continue;
        cuts.push(Math.max(range.start, span.start), Math.min(range.end, span.end));
      }
      for (var style of scan.styledRanges) {
        if (style.end <= range.start || style.start >= range.end) continue;
        cuts.push(Math.max(range.start, style.start), Math.min(range.end, style.end));
      }
      cuts.sort(function (a, b) { return a - b; });
      var out = [];
      for (var i = 1; i < cuts.length; i++) {
        var from = cuts[i - 1], to = cuts[i];
        if (to === from) continue;
        var span = scan.spans.find(function (candidate) { return candidate.start <= from && candidate.end >= to; });
        var styles = scan.styledRanges.filter(function (candidate) { return candidate.start <= from && candidate.end >= to; }).map(function (candidate) { return candidate.kind; });
        out.push({start:from-range.start,end:to-range.start,text:source.slice(from,to),kind:span ? span.kind : 'literal',styles:styles});
      }
      return out;
    }
    function makeLine(range, parts, blockKind) {
      var line = doc.createElement('div');
      line.className = 'ki-line';
      line.dataset.line = '1';
      line.dataset.block = blockKind;
      var entry = {element:line,start:range.start,end:range.end,parts:parts,signature:JSON.stringify([parts,blockKind]),syntaxNodes:[]};
      mappedLine.set(line, entry);
      if (parts.length === 0) {
        line.dataset.empty = '1'; line.appendChild(doc.createElement('br'));
      }
      for (var part of parts) {
        var span = doc.createElement('span');
        span.className = 'ki-token ki-' + part.kind + part.styles.map(function (style) { return ' ki-' + style; }).join('');
        span.dataset.kind = part.kind;
        var node = doc.createTextNode(part.text);
        span.appendChild(node); line.appendChild(span);
        mappedText.set(node, {entry:entry,start:part.start,end:part.end});
        mappedSpan.set(span, {entry:entry,start:part.start,end:part.end});
        if (part.kind === 'syntax') { span.hidden = true; entry.syntaxNodes.push(span); }
      }
      return entry;
    }
    function render() {
      if (frozen && model.revision !== renderedRevision) throw new RangeError('composition active');
      var browserSelection = doc.getSelection();
      var restore = model.revision !== renderedRevision && browserSelection &&
        browserSelection.anchorNode && element.contains(browserSelection.anchorNode) &&
        browserSelection.focusNode && element.contains(browserSelection.focusNode);
      var source = model.text, scan = syntax.scan(source), ranges = lineRanges(source), next = [];
      for (var i = 0; i < ranges.length; i++) {
        var range = ranges[i], parts = pieces(source, range, scan);
        var block = scan.blocks.find(function (candidate) { return candidate.start === range.start && candidate.end === range.end; });
        var blockKind = block ? block.kind : 'paragraph';
        var signature = JSON.stringify([parts,blockKind]);
        var previous = lines[i];
        if (previous && previous.signature === signature) {
          previous.start = range.start; previous.end = range.end; next.push(previous);
        } else {
          var fresh = makeLine(range, parts, blockKind);
          if (previous) element.replaceChild(fresh.element, previous.element);
          else element.appendChild(fresh.element);
          next.push(fresh);
        }
      }
      for (var old = ranges.length; old < lines.length; old++) lines[old].element.remove();
      lines = next;
      renderedRevision = model.revision;
      if (restore) restoreSelection(model.selection);
    }
    function assertOffset(offset) {
      if (!Number.isInteger(offset) || offset < 0 || offset > model.text.length) throw new RangeError('source offset');
    }
    function assertAffinity(affinity) {
      if (affinity !== 'before' && affinity !== 'after') throw new RangeError('affinity');
    }
    function toDom(offset, affinity) {
      affinity = affinity || 'after'; assertOffset(offset); assertAffinity(affinity);
      var entry = lines.find(function (candidate) { return candidate.start <= offset && offset <= candidate.end; });
      if (!entry) throw new RangeError('unmapped source offset');
      var relative = offset - entry.start;
      // The active line exposes all of its delimiters while keeping content styled.
      for (var syntaxNode of entry.syntaxNodes) syntaxNode.hidden = false;
      var segments = entry.element.querySelectorAll('.ki-token');
      if (segments.length === 0) return {node:entry.element,offset:0,affinity:affinity};
      var selected = null;
      if (affinity === 'after') {
        for (var span of segments) {
          var mapping = mappedSpan.get(span);
          if (mapping.start <= relative && relative < mapping.end) { selected = {span:span,mapping:mapping}; break; }
        }
        if (!selected) { var last = segments[segments.length-1]; selected = {span:last,mapping:mappedSpan.get(last)}; }
      } else {
        for (var j = segments.length - 1; j >= 0; j--) {
          var prior = segments[j], mappingBefore = mappedSpan.get(prior);
          if (mappingBefore.start < relative && relative <= mappingBefore.end) { selected = {span:prior,mapping:mappingBefore}; break; }
        }
        if (!selected) { var first = segments[0]; selected = {span:first,mapping:mappedSpan.get(first)}; }
      }
      return {node:selected.span.firstChild,offset:relative-selected.mapping.start,affinity:affinity};
    }
    function fromDom(node, offset, affinity) {
      affinity = affinity || 'after'; assertAffinity(affinity);
      if (!Number.isInteger(offset) || offset < 0) throw new RangeError('DOM offset');
      var text = mappedText.get(node);
      if (text) {
        if (offset > text.end - text.start || !element.contains(node)) throw new RangeError('DOM offset');
        return text.entry.start + text.start + offset;
      }
      var span = mappedSpan.get(node);
      if (span) {
        if (offset > node.childNodes.length || !element.contains(node)) throw new RangeError('DOM offset');
        return span.entry.start + (offset === 0 ? span.start : span.end);
      }
      var line = mappedLine.get(node);
      if (line) {
        if (offset > node.childNodes.length || !element.contains(node)) throw new RangeError('DOM offset');
        if (offset === 0) return line.start;
        if (offset === node.childNodes.length) return line.end;
        var prior = mappedSpan.get(node.childNodes[offset - 1]);
        var next = mappedSpan.get(node.childNodes[offset]);
        if (!prior || !next) throw new RangeError('unmapped DOM boundary');
        return line.start + (affinity === 'before' ? prior.end : next.start);
      }
      if (node === element) {
        if (offset > lines.length) throw new RangeError('DOM offset');
        if (offset === 0) return 0;
        if (offset === lines.length) return model.text.length;
        return affinity === 'before' ? lines[offset - 1].end : lines[offset].start;
      }
      throw new RangeError('DOM endpoint outside editor');
    }
    function restoreSelection(selection) {
      if (!selection) selection = model.selection;
      var anchor = toDom(selection.anchor, 'after');
      // One source caret must be one native DOM point, even at a span edge.
      var focus = selection.anchor === selection.focus ? anchor : toDom(selection.focus, 'before');
      var browser = doc.getSelection();
      if (!browser) throw new RangeError('selection API unavailable');
      if (browser.setBaseAndExtent) browser.setBaseAndExtent(anchor.node, anchor.offset, focus.node, focus.offset);
      else { browser.collapse(anchor.node, anchor.offset); browser.extend(focus.node, focus.offset); }
      var actual = readSelection();
      if (actual.anchor !== selection.anchor || actual.focus !== selection.focus) {
        throw new RangeError('unmappable selection');
      }
    }
    function readSelection() {
      var browser = doc.getSelection();
      if (!browser || !browser.anchorNode || !browser.focusNode) throw new RangeError('selection unavailable');
      return {anchor:fromDom(browser.anchorNode,browser.anchorOffset,'after'),focus:fromDom(browser.focusNode,browser.focusOffset,'before')};
    }
    function hideSyntax() {
      for (var entry of lines) for (var node of entry.syntaxNodes) node.hidden = true;
    }
    function freeze() { frozen = true; }
    function unfreeze() { frozen = false; }
    render();
    return {element:element,render:render,toDom:toDom,fromDom:fromDom,restoreSelection:restoreSelection,readSelection:readSelection,hideSyntax:hideSyntax,freeze:freeze,unfreeze:unfreeze};
  }
  return {create:create};
});
