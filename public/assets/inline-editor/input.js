/* Browser input to source transactions. The editable DOM is never submitted. */
'use strict';
(function (root, factory) {
  var api = factory();
  if (typeof module !== 'undefined' && module.exports) module.exports = api;
  if (root.document) {
    root.KipInline = root.KipInline || {};
    root.KipInline.input = api;
  }
})(typeof window !== 'undefined' ? window : globalThis, function () {
  var MAX_SOURCE = 2000000;

  function difference(before, after) {
    var from = 0, oldEnd = before.length, newEnd = after.length;
    while (from < oldEnd && from < newEnd && before[from] === after[from]) from++;
    while (oldEnd > from && newEnd > from && before[oldEnd - 1] === after[newEnd - 1]) {
      oldEnd--; newEnd--;
    }
    return {from:from,to:oldEnd,text:after.slice(from,newEnd)};
  }

  function sourceSelection(control) {
    var backward = control.selectionDirection === 'backward';
    return backward ? {anchor:control.selectionEnd,focus:control.selectionStart} :
      {anchor:control.selectionStart,focus:control.selectionEnd};
  }

  function setSourceSelection(control, selection) {
    control.setSelectionRange(Math.min(selection.anchor,selection.focus),
      Math.max(selection.anchor,selection.focus), selection.anchor > selection.focus ? 'backward' : 'forward');
  }

  function adjacentGrapheme(text, offset, backward) {
    if (typeof Intl === 'undefined' || typeof Intl.Segmenter !== 'function') throw new RangeError('grapheme segmenter unavailable');
    var segments = new Intl.Segmenter(undefined,{granularity:'grapheme'}).segment(text);
    if (typeof segments.containing !== 'function') throw new RangeError('grapheme lookup unavailable');
    var part = segments.containing(backward ? offset - 1 : offset);
    return part ? {from:part.index,to:part.index+part.segment.length} : null;
  }

  function nativeBlockChange(model, view) {
    var root = view.element, doc = root.ownerDocument;
    if (!doc || !doc.getSelection || !root.childNodes) throw new RangeError('native DOM unavailable');
    // Include text nodes: Element.children would silently ignore a browser
    // mutation inserted directly between line blocks.
    var lines = model.text.split('\n'), children = Array.from(root.childNodes);
    if (children.length !== lines.length) throw new RangeError('native block count changed');
    var changed = -1;
    for (var i = 0; i < children.length; i++) {
      var line = children[i];
      if (line.nodeType !== 1 || line.nodeName !== 'DIV' || !line.classList.contains('ki-line')) {
        throw new RangeError('native block structure');
      }
      for (var child of line.childNodes) {
        if (child.nodeName === 'BR' && line.childNodes.length === 1) continue;
        if (child.nodeName !== 'SPAN' || !child.classList.contains('ki-token')) throw new RangeError('native inline structure');
        for (var nested of child.childNodes) if (nested.nodeType !== 3) throw new RangeError('native inline structure');
      }
      if (line.textContent !== lines[i]) {
        if (changed !== -1) throw new RangeError('multiple native blocks changed');
        changed = i;
      }
    }
    if (changed < 0) return null;
    var active = children[changed], selection = doc.getSelection();
    if (!selection || !selection.anchorNode || !selection.focusNode ||
        !active.contains(selection.anchorNode) || !active.contains(selection.focusNode)) {
      throw new RangeError('native selection escaped block');
    }
    function localOffset(node, offset) {
      var range = doc.createRange();
      range.setStart(active, 0); range.setEnd(node, offset);
      return range.toString().length;
    }
    var start = 0;
    for (var j = 0; j < changed; j++) start += lines[j].length + 1;
    return {from:start,to:start+lines[changed].length,text:active.textContent,
      selection:{anchor:start+localOffset(selection.anchorNode,selection.anchorOffset),
        focus:start+localOffset(selection.focusNode,selection.focusOffset)}};
  }

  function create(model, view, options) {
    if (!model || !view || !view.element || typeof model.apply !== 'function') throw new TypeError('input contract');
    options = options || {};
    var source = options.source || null, active = view.element, listeners = [];
    var composing = false, pendingHistory = null, failed = false, plainNext = false;
    var now = options.now || Date.now;
    function listen(control, type, fn) {
      control.addEventListener(type, fn);
      listeners.push(function () { control.removeEventListener(type, fn); });
    }
    function fail(control, retained) {
      failed = true;
      if (composing) { composing = false; view.unfreeze(); }
      var uncertain = retained === undefined ? (control === source ? control.value : active.textContent || '') : retained;
      if (options.onFailure) options.onFailure(uncertain);
    }
    function capture(control) {
      model.select(control === source ? sourceSelection(control) : view.readSelection());
    }
    function publish(control) {
      if (source) { source.value = model.text; setSourceSelection(source, model.selection); }
      view.render();
      if (control !== source) view.restoreSelection(model.selection);
    }
    function replace(control, text, kind) {
      capture(control);
      var selection = model.selection, from = Math.min(selection.anchor, selection.focus);
      var to = Math.max(selection.anchor, selection.focus);
      if (model.text.length - (to - from) + text.length > MAX_SOURCE) throw new RangeError('input too large');
      model.apply({from:from,to:to,insert:text,
        selection:{anchor:from+text.length,focus:from+text.length},kind:kind,time:now()});
      publish(control);
    }
    function history(control, redo) {
      if (composing) return;
      if (redo) model.redo(); else model.undo();
      publish(control);
    }
    function deletion(control, backward) {
      capture(control);
      var selection = model.selection, from = Math.min(selection.anchor,selection.focus),
        to = Math.max(selection.anchor,selection.focus);
      if (from === to) {
        if ((backward && from === 0) || (!backward && to === model.text.length)) return;
        var grapheme = adjacentGrapheme(model.text,from,backward);
        if (!grapheme) throw new RangeError('grapheme boundary unavailable');
        from = grapheme.from; to = grapheme.to;
      }
      model.apply({from:from,to:to,insert:'',selection:{anchor:from,focus:from},
        kind:'delete',time:now()});
      publish(control);
    }
    function reconcile(control, kind) {
      var change = options.readNativeChange ? options.readNativeChange(control,model,view) :
        control === source ? (function () {
          var d = difference(model.text,source.value);
          return {from:d.from,to:d.to,text:d.text,selection:sourceSelection(source)};
        })() : nativeBlockChange(model,view);
      if (!change) return;
      if (!Number.isInteger(change.from) || !Number.isInteger(change.to) || typeof change.text !== 'string') {
        throw new RangeError('ambiguous native input');
      }
      if (model.text.length - (change.to-change.from) + change.text.length > MAX_SOURCE) {
        throw new RangeError('native input too large');
      }
      model.apply({from:change.from,to:change.to,insert:change.text,
        selection:change.selection,kind:kind || 'input',time:now()});
      publish(control);
    }
    function beforeInput(event) {
      if (failed) { event.preventDefault(); return; }
      if (composing || event.isComposing) return;
      var control = event.target, type = event.inputType || '';
      if (type === 'historyUndo' || type === 'historyRedo') {
        if (event.cancelable) {
          event.preventDefault();
          try { history(control,type === 'historyRedo'); } catch (_) { fail(control); }
        }
        else pendingHistory = type;
        return;
      }
      if (!event.cancelable) return;
      try {
        if (type === 'insertText' && typeof event.data === 'string') {
          event.preventDefault(); replace(control,event.data,'typing');
        } else if (type === 'insertParagraph' || type === 'insertLineBreak') {
          event.preventDefault(); replace(control,'\n','command');
        } else if (type === 'deleteContentBackward' || type === 'deleteContentForward') {
          event.preventDefault(); deletion(control,type === 'deleteContentBackward');
        }
      } catch (_) { fail(control); }
    }
    function onInput(event) {
      if (failed || composing || event.isComposing) return;
      var control = event.target;
      try {
        if (pendingHistory) {
          var redo = pendingHistory === 'historyRedo'; pendingHistory = null;
          history(control,redo);
        } else reconcile(control);
      } catch (_) { fail(control); }
    }
    function onKey(event) {
      if (failed || composing || event.isComposing) return;
      var key = String(event.key || '').toLowerCase();
      plainNext = key === 'v' && !!event.shiftKey && !!(event.ctrlKey || event.metaKey);
      if ((event.ctrlKey || event.metaKey) && (key === 'z' || (event.ctrlKey && key === 'y'))) {
        event.preventDefault();
        try { history(event.target,key === 'y' || !!event.shiftKey); }
        catch (_) { fail(event.target); }
      }
    }
    function onPaste(event) {
      if (failed || composing || !event.clipboardData) {
        event.preventDefault();
        if (!failed && !composing) fail(event.target);
        return;
      }
      var control = event.target, plain, html;
      try {
        plain = event.clipboardData.getData('text/plain') || '';
        html = event.clipboardData.getData('text/html') || '';
      } catch (_) { event.preventDefault(); fail(control); return; }
      var text = plain;
      if (!plainNext && !event.shiftKey && html && typeof options.convertHtml === 'function') {
        try {
          var converted = options.convertHtml(html);
          if (typeof converted === 'string' && converted.length <= MAX_SOURCE) text = converted;
        } catch (_) { text = plain; }
      }
      plainNext = false;
      event.preventDefault();
      if (!text && html) { fail(control, html); return; }
      try { replace(control,text,'paste'); }
      catch (_) { fail(control,text); }
    }
    function onCompositionStart(event) {
      if (failed || composing) return;
      try { capture(event.target); view.freeze(); composing = true; }
      catch (_) { fail(event.target); }
    }
    function onCompositionEnd(event) {
      if (failed || !composing) return;
      var control = event.target;
      try { view.unfreeze(); composing = false; reconcile(control,'composition'); }
      catch (_) { fail(control); }
    }
    for (var control of source ? [active,source] : [active]) {
      listen(control,'beforeinput',beforeInput);
      listen(control,'input',onInput);
      listen(control,'keydown',onKey);
      listen(control,'paste',onPaste);
      listen(control,'compositionstart',onCompositionStart);
      listen(control,'compositionend',onCompositionEnd);
    }
    if (source) source.value = model.text;
    return {get failed() {return failed;}, detach:function () {
      for (var off of listeners) off(); listeners.length = 0;
    }};
  }
  return {create:create};
});
