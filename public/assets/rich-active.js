/* Native and plaintext active regions share UTF-16 source offsets. */
'use strict';
(function (root, factory) {
  var api = factory();
  if (typeof module !== 'undefined' && module.exports) module.exports = api;
  if (root.Kip) root.Kip.richActive = api;
})(typeof window !== 'undefined' ? window : globalThis, function () {
  function isBlock(node) { return node && (node.nodeName === 'DIV' || node.nodeName === 'P'); }
  // Text and DOM boundaries come from one traversal, including empty lines.
  // Never clone a partial range: a clone loses the preceding block boundary.
  function scan(root) {
    var text = '', positions = new WeakMap(), points = [];
    function walk(node) {
      var start = text.length;
      if (node.nodeType === 3) {
        positions.set(node, start); text += node.data; points.push({node:node, start:start, end:text.length}); return;
      }
      if (node.nodeType !== 1) return;
      if (node.nodeName === 'BR') { positions.set(node, [start]); text += '\n'; return; }
      if (!['DIV', 'P', 'SPAN'].includes(node.nodeName)) throw Error('unsupported input structure');
      var children = Array.from(node.childNodes), boundaries = [];
      positions.set(node, boundaries);
      if (children.length === 1 && children[0].nodeName === 'BR') {
        positions.set(children[0], [start]); boundaries.push(start, start); return;
      }
      children.forEach(function(child, i) {
        if (i && (isBlock(child) || isBlock(children[i - 1]))) text += '\n';
        boundaries.push(text.length); walk(child);
      });
      boundaries.push(text.length);
    }
    walk(root);
    return {text:text, positions:positions, points:points};
  }
  function readText(node) { return scan(node).text; }
  function offset(root, node, position) {
    var map = scan(root), entry = map.positions.get(node);
    if (entry === undefined) throw Error('selection outside input');
    return typeof entry === 'number' ? entry + Math.min(position, node.data.length) : entry[Math.min(position, entry.length - 1)];
  }
  function textSelection(box) {
    return box.selectionDirection === 'backward' ? {anchor:box.selectionEnd, focus:box.selectionStart} : {anchor:box.selectionStart, focus:box.selectionEnd};
  }
  function setTextSelection(box, selection) {
    box.setSelectionRange(Math.min(selection.anchor, selection.focus), Math.max(selection.anchor, selection.focus), selection.anchor > selection.focus ? 'backward' : 'forward');
  }
  function create(doc, candidate, labels, original) {
    if (!['a','b'].includes(candidate)) throw Error('unknown candidate');
    var el = doc.createElement(candidate === 'a' ? 'textarea' : 'div'), saved = {anchor:0, focus:0};
    if (candidate === 'b') {
      el.contentEditable = 'plaintext-only';
      var selectionApi = doc.getSelection();
      if (el.contentEditable !== 'plaintext-only' || !selectionApi || (!selectionApi.setBaseAndExtent && !(selectionApi.collapse && selectionApi.extend))) throw Error('plaintext input unsupported');
      el.setAttribute('role', 'textbox'); el.setAttribute('aria-multiline', 'true');
    }
    el.className = 'writer-active'; el.setAttribute('aria-label', labels.text);
    el.spellcheck = original ? original.spellcheck : true; el.lang = original ? original.lang : '';
    if (original) {
      var style = doc.defaultView.getComputedStyle(original);
      ['font', 'lineHeight', 'letterSpacing', 'direction'].forEach(function (property) { el.style[property] = style[property]; });
      if (candidate === 'a') el.wrap = original.wrap;
    }
    function value() { return candidate === 'a' ? el.value : readText(el); }
    function selection() {
      if (candidate === 'a') return textSelection(el);
      var sel = doc.getSelection();
      if (sel && el.contains(sel.anchorNode) && el.contains(sel.focusNode)) saved = {anchor:offset(el,sel.anchorNode,sel.anchorOffset),focus:offset(el,sel.focusNode,sel.focusOffset)};
      return {anchor:saved.anchor, focus:saved.focus};
    }
    function restore() {
      var map = scan(el), sel = doc.getSelection();
      function point(position) {
        for (var i = 0; i < map.points.length; i++) {
          var p = map.points[i]; if (position >= p.start && position <= p.end) return [p.node,position-p.start];
        }
        // Empty browser placeholder or a caret at a structural newline.
        var found = [el,0];
        function visit(node) {
          var boundaries = map.positions.get(node);
          if (Array.isArray(boundaries)) boundaries.forEach(function(n,i){if(n===position)found=[node,i];});
          Array.from(node.childNodes || []).forEach(visit);
        }
        visit(el); return found;
      }
      var a = point(saved.anchor), f = point(saved.focus);
      if (sel.setBaseAndExtent) sel.setBaseAndExtent(a[0],a[1],f[0],f[1]);
      else {sel.collapse(a[0],a[1]);sel.extend(f[0],f[1]);}
    }
    if (candidate === 'b') el.addEventListener('focus', restore);
    function resize() {
      if (candidate !== 'a') return;
      el.style.height = 'auto';
      // Include the border in the border-box height. A spurious scrollbar
      // would narrow the wrapped text when a longer region becomes active.
      el.style.height = (el.scrollHeight + el.offsetHeight - el.clientHeight) + 'px';
    }
    function set(text, next, focus) {
      saved = {anchor:next.anchor, focus:next.focus};
      if (candidate === 'a') {
        el.value = text; if (focus) el.focus(); setTextSelection(el, next);
        resize();
      } else {
        el.replaceChildren(doc.createTextNode(text)); if (focus) el.focus(); restore();
      }
    }
    return {element:el, value:value, selection:selection, set:set, resize:resize};
  }
  return {create:create, readText:readText, offset:offset, textSelection:textSelection, setTextSelection:setTextSelection};
});
