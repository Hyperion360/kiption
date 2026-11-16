'use strict';
(function (root, factory) {
  var api = factory(root);
  if (typeof module !== 'undefined' && module.exports) module.exports = api;
  if (root.Kip) { root.Kip.richEditor = api; root.Kip.init(function () { api.init(root.document); }); }
})(typeof window !== 'undefined' ? window : globalThis, function (root) {
  function attach(box, options) {
    var K = options.Kip || root.Kip, doc = box.ownerDocument, win = doc.defaultView, form = box.form;
    if (!form) throw Error('chapter form required');
    var labels = options.labels, candidate = options.candidate || 'a', failed = false;
    // Probe the candidate before adding controls or hiding the native form.
    K.richActive.create(doc, candidate, labels, box);
    var model = K.sourceDocument.create(box.value, K.richActive.textSelection(box));
    var region = null, active = null, rendered = [], composing = false, syncing = false, formatted = false, pendingHistory = null;
    var originalRequired = box.required, cleanup = [], activeOff = function () {};
    var host = doc.createElement('div'), surface = doc.createElement('div'), controls = doc.createElement('div'), status = doc.createElement('p');
    host.className = 'writer-rich'; surface.className = 'writer-surface'; controls.className = 'writer-modes';
    controls.setAttribute('role', 'group'); controls.setAttribute('aria-label', labels.mode);
    status.setAttribute('role', 'status'); status.setAttribute('aria-live', 'polite');
    function button(label, fn) { var b = doc.createElement('button'); b.type = 'button'; b.textContent = label; b.addEventListener('click', fn); controls.appendChild(b); return b; }
    var sourceButton = button(labels.source, showSource), formattedButton = button(labels.formatted, showFormatted);
    var selectionButton = button(labels.editSelection, function () { if(captureProjectionSelection())showSource(true); });
    host.append(controls, status, surface);
    var label = box.closest('label'); (label || box).insertAdjacentElement('afterend', host);
    function on(target, name, fn, capture) { target.addEventListener(name, fn, capture); cleanup.push(function () { target.removeEventListener(name, fn, capture); }); }
    function syncSource() {
      box.value = model.text; K.richActive.setTextSelection(box, model.selection);
    }
    function publish(kind, inserted) {
      syncing = true; syncSource();
      var inputType=kind==='history'?'historyUndo':inserted?'insertReplacementText':'deleteContentBackward';
      box.dispatchEvent(new win.InputEvent('input', { bubbles: true, inputType: inputType }));
      if (formatted && active) box.dispatchEvent(new win.CustomEvent('writer-commit', {
        bubbles: true, detail: { control: active.element, inserted: inserted, kind: kind }
      }));
      syncing = false;
    }
    function modeState() {
      box.hidden = formatted; surface.hidden = !formatted; selectionButton.hidden = !formatted;
      sourceButton.setAttribute('aria-pressed', String(!formatted)); formattedButton.setAttribute('aria-pressed', String(formatted));
    }
    function selectSource(selection) {
      model.select(selection);
      var submitted = K.richActive.textSelection(box);
      if (submitted.anchor !== selection.anchor || submitted.focus !== selection.focus) K.richActive.setTextSelection(box, selection);
    }
    function capture() {
      if (composing) return;
      if (!formatted) model.select(K.richActive.textSelection(box));
      else if (active && doc.activeElement === active.element) {
        var dom = doc.getSelection();
        if (dom && (!active.element.contains(dom.anchorNode) || !active.element.contains(dom.focusNode)) && captureProjectionSelection()) return;
        var s = active.selection(); selectSource({ anchor: region.start + s.anchor, focus: region.start + s.focus });
      }
    }
    function localSelection() { var s = model.selection; return { anchor: s.anchor - region.start, focus: s.focus - region.start }; }
    function repaint() {
      if (formatted && active) active.set(model.text.slice(region.start, region.end), localSelection(), true);
      else { box.value = model.text; box.focus(); K.richActive.setTextSelection(box, model.selection); }
    }
    function apply(start, end, text, selection, kind) {
      var change = model.apply(start, end, text, selection, kind, Date.now());
      if (formatted) {
        var oldEnd=region.end,delta=text.length-(end-start);
        region.end+=delta;rendered.forEach(function(part){if(part.start>=oldEnd)part.shift(delta);});
      }
      if (change) publish(kind, text.length > 0); else syncSource();
      if(formatted&&model.text.length>2000000){formatted=false;modeState();box.focus();K.richActive.setTextSelection(box,model.selection);status.textContent=labels.tooLarge;}
      return change;
    }
    function reconcile(kind) {
      if (syncing) return;
      var value = formatted ? active.value() : box.value;
      var before = formatted ? model.text.slice(region.start, region.end) : model.text;
      var start = formatted ? region.start : 0, selection = formatted ? active.selection() : K.richActive.textSelection(box);
      var difference = K.sourceDocument.difference(before, value);
      apply(start + difference.start, start + difference.end, difference.insert,
        { anchor: start + selection.anchor, focus: start + selection.focus }, kind);
      if (formatted) active.resize();
    }
    function flush() {
      if (composing) { composing = false; try { reconcile('composition'); } catch (_) { fallback(); } }
    }
    function fallback() {
      var emergency = doc.createElement('textarea'); emergency.readOnly = true; emergency.setAttribute('aria-label', labels.unconverted);
      emergency.value = active ? active.element.innerText : box.value; host.appendChild(emergency);
      failed = true; composing = false; host.dataset.candidateFailed = '1';
      showSource(true); formattedButton.disabled = true; status.textContent = labels.inputFallback;
    }
    function history(redo) {
      flush();
      if(redo)model.redo();else model.undo();
      publish('history',false); if (formatted) render(); else repaint();
    }
    function command(id) {
      if (composing) return; capture();
      var sel = model.selection;
      var proxy = { value: model.text, selectionStart: Math.min(sel.anchor, sel.focus), selectionEnd: Math.max(sel.anchor, sel.focus),
        setSelectionRange: function (a, b) { this.selectionStart = a; this.selectionEnd = b; },
        setRangeText: function (text, a, b) { this.value = this.value.slice(0, a) + text + this.value.slice(b); this.selectionStart = this.selectionEnd = a + text.length; },
        dispatchEvent: function () {}, focus: function () {} };
      K.editorRun(id, proxy, { execCommand: function () { return false; } });
      var d = K.sourceDocument.difference(model.text, proxy.value);
      apply(d.start, d.end, d.insert, { anchor: proxy.selectionStart, focus: proxy.selectionEnd }, 'command');
      if (formatted) render(); else repaint();
    }
    function replaceSelection(text, kind) {
      capture(); var s = model.selection, a = Math.min(s.anchor, s.focus), b = Math.max(s.anchor, s.focus);
      var inside = formatted && a >= region.start && b <= region.end;
      apply(a, b, text, { anchor: a + text.length, focus: a + text.length }, kind);
      if (formatted && !inside) render(); else repaint();
    }
    function beforeInput(e) {
      if (syncing || composing || e.isComposing) return;
      capture();
      if (/^history(Undo|Redo)$/.test(e.inputType)) {
        if (e.cancelable) { e.preventDefault(); history(e.inputType === 'historyRedo'); }
        else pendingHistory = e.inputType;
      } else if (formatted && candidate === 'b' && e.cancelable && ['insertParagraph', 'insertLineBreak'].includes(e.inputType)) {
        e.preventDefault(); replaceSelection('\n', 'typing');
      }
    }
    function input(e) {
      if (syncing) return;
      e.stopPropagation();
      if (pendingHistory) { var redo = pendingHistory === 'historyRedo'; pendingHistory = null; history(redo); return; }
      try {
        if (composing || e.isComposing) {
          // Update submission text while keeping partial IME changes out of history.
          if (formatted) box.value = model.text.slice(0, region.start) + active.value() + model.text.slice(region.end);
          return;
        }
        reconcile(e.inputType === 'insertText' ? 'typing' : 'input');
      } catch (_) { fallback(); }
    }
    function key(e) {
      if (syncing || composing || e.isComposing) return;
      if (e.key === 'Escape' && formatted) { e.preventDefault(); sourceButton.focus(); return; }
      if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'z') { e.preventDefault(); e.stopImmediatePropagation(); history(e.shiftKey); return; }
      if (e.ctrlKey && e.key.toLowerCase() === 'y') { e.preventDefault(); e.stopImmediatePropagation(); history(true); return; }
      var id = K.editorHandleKey({composing:false}, e.key, e.ctrlKey, e.metaKey);
      if (id) { e.preventDefault(); e.stopImmediatePropagation(); command(id); return; }
      if (!e.ctrlKey && !e.metaKey && !e.altKey && K.smart) {
        capture(); var s = model.selection;
        var edit = K.smart.forKey({value:model.text, selectionStart:Math.min(s.anchor,s.focus), selectionEnd:Math.max(s.anchor,s.focus)}, e.key);
        if (edit) { e.preventDefault(); e.stopImmediatePropagation(); apply(edit.from, edit.to, edit.text, {anchor:edit.from+edit.text.length,focus:edit.from+edit.text.length}, 'smart'); if(formatted)render();else repaint(); }
      }
      if (formatted && !e.ctrlKey && !e.metaKey && ['ArrowLeft','ArrowRight','ArrowUp','ArrowDown'].includes(e.key)) {
        capture();
        var selection = model.selection;
        var vertical = e.key === 'ArrowUp' || e.key === 'ArrowDown';
        if (vertical || selection.focus === region.start || selection.focus === region.end) expandForNavigation(vertical);
      }
    }
    function expandForNavigation(vertical) {
      // Extend the raw context before the browser moves the caret. The browser
      // owns visual bidi direction, Shift selection, and grapheme boundaries.
      var before = rendered.filter(function (part) { return part.end <= region.start; }).pop();
      var after = rendered.find(function (part) { return part.start >= region.end; });
      if (!before && !after) return;
      // Vertical movement can leave a visual line at any column. Keep complete
      // context until repartitioning so repeated movement through short/blank
      // lines does not reset the browser's preferred column by rewriting input.
      if (vertical) { region.start = 0; region.end = model.text.length; }
      else {
        if (before) region.start = before.start;
        if (after) region.end = after.end;
      }
      rendered = rendered.filter(function (part) {
        if (part.start >= region.start && part.end <= region.end) { part.element.remove(); return false; }
        return true;
      });
      repaint();
    }
    var plainNext = false;
    function paste(e) {
      if (!e.clipboardData) return;
      var text = e.clipboardData.getData('text/plain'), html = e.clipboardData.getData('text/html');
      if (!plainNext && html) text = K.paste.htmlToMarkdown(html) || text;
      plainNext = false; e.preventDefault(); e.stopImmediatePropagation(); replaceSelection(text, 'paste');
    }
    function wire(control) {
      var off = [];
      function listen(type,fn,capture){control.addEventListener(type,fn,capture);off.push(function(){control.removeEventListener(type,fn,capture);});}
      listen('beforeinput', beforeInput); listen('input', input);
      listen('keydown', function (e) { plainNext = e.shiftKey && (e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'v'; key(e); }, true);
      listen('paste', paste, true);
      listen('drop', function (e) { e.preventDefault(); if (e.dataTransfer) replaceSelection(e.dataTransfer.getData('text/plain'), 'drop'); });
      listen('compositionstart', function () { capture(); composing = true;  });
      listen('compositionend', flush);
      listen('select', function () {
        // A hidden source-selection update is a mirror, not a new selection in
        // the active textarea. Its asynchronous select event must not undo a
        // range the author just accepted in the inactive projection.
        if (control !== box || !formatted) capture();
      });
      listen('keyup', capture);
      listen('blur', function () {
        if (control === (formatted && active ? active.element : box)) capture();
      });
      listen('pointerup', capture);
      return function(){off.forEach(function(remove){remove();});};
    }
    function render() {
      flush(); if (failed) return;
      if(model.text.length>2000000){formatted=false;modeState();box.value=model.text;box.focus();K.richActive.setTextSelection(box,model.selection);status.textContent=labels.tooLarge;return;}
       var selection = model.selection, lo = Math.min(selection.anchor, selection.focus), hi = Math.max(selection.anchor, selection.focus);
      var all = K.richProjection.spans(model.text, K.subset);
      var first = all.findIndex(function (span) { return lo >= span.start && (lo < span.end || span.end === model.text.length); });
      var last = all.findIndex(function (span) { return hi >= span.start && (hi < span.end || span.end === model.text.length); });
      if (first < 0) first = all.length - 1; if (last < first) last = first;
      region = { start: all[first].start, end: all[last].end };
      activeOff(); active = K.richActive.create(doc, candidate, labels, box); activeOff = wire(active.element);
      if (first === last) active.element.dataset.writerBlock = all[first].type;
      surface.replaceChildren(); rendered = [];
      all.forEach(function (span, i) {
        if (i === first) surface.appendChild(active.element);
        if (i >= first && i <= last) return;
        var part = K.richProjection.renderSpan(doc, model.text, span, K.subset, labels);
        rendered.push(part); surface.appendChild(part.element);
      });
      active.set(model.text.slice(region.start, region.end), localSelection(), true);
    }
    function projectPoint(node, offset) {
      if (active && (node === active.element || active.element.contains(node))) {
        if (candidate === 'a') return region.start + active.selection().focus;
        return region.start + K.richActive.offset(active.element, node, offset);
      }
      for (var i = 0; i < rendered.length; i++) { var p = rendered[i].point(node, offset); if (p !== null) return p; }
      return null;
    }
    function captureProjectionSelection() {
      var s = doc.getSelection(); if (!s) return false;
      var anchor = projectPoint(s.anchorNode, s.anchorOffset), focus = projectPoint(s.focusNode, s.focusOffset);
      if (anchor === null || focus === null) return false;
      selectSource({anchor:anchor,focus:focus}); return true;
    }
    function showSource(preserveSelection) { flush(); if(preserveSelection!==true)capture(); formatted = false; modeState(); box.value = model.text; box.focus(); K.richActive.setTextSelection(box,model.selection); status.textContent = labels.source; }
    function showFormatted() {
      if (composing || failed) return;
      capture(); if (model.text.length > 2000000) { status.textContent = labels.tooLarge; return; }
      formatted = true; modeState();
      try { render(); if(formatted)status.textContent = labels.formatted; }
      catch (_) { formatted = false; modeState(); status.textContent = labels.unavailable; box.focus(); }
    }
    var pointerAnchor = null;
    on(surface, 'pointerdown', function (e) {
      capture(); pointerAnchor = e.shiftKey ? model.selection.anchor : null;
    });
    on(surface, 'pointerup', function (e) {
      if (active && active.element.contains(e.target)) return;
      var part = rendered.find(function (entry) { return entry.element.contains(e.target); });
      var mapped = captureProjectionSelection(), selected = model.selection;
      // Rules and blank blocks may receive a click without a DOM text caret.
      if (part && (!mapped || (selected.anchor === selected.focus && (selected.focus < part.start || selected.focus > part.end)))) {
        var point = part.point(e.target, 0);
        model.select({anchor:point, focus:point}); mapped = true;
      }
      if (!mapped) return;
      if (e.shiftKey && pointerAnchor !== null) {
        model.select({anchor:pointerAnchor, focus:model.selection.focus}); pointerAnchor = null; render(); return;
      }
      // A drag stays selected until the explicit source action.
      if (model.selection.anchor === model.selection.focus) render();
    });
    on(box, 'invalid', function () { if (box.hidden) { showSource(); box.focus(); status.textContent = form.dataset.contentRequired; } });
    on(form, 'submit', function (e) {
      var previouslyFailed = failed; flush(); box.value = model.text;
      if (failed && !previouslyFailed) { e.preventDefault(); e.stopImmediatePropagation(); }
    }, true);
    on(controls, 'pointerdown', function () { capture(); });
    cleanup.push(wire(box)); modeState();
    var api = { model:model, capture:capture, get activeControl(){return formatted&&active?active.element:box;}, showSource:showSource, showFormatted:showFormatted, command:command,
      replace:function (text, kind) { flush(); model.apply(0,model.text.length,text,{anchor:text.length,focus:text.length},kind,Date.now()); publish(kind,true); if(formatted)render(); },
      destroy:function () { flush(); formatted=false; modeState(); box.required=originalRequired; activeOff(); cleanup.forEach(function(off){off();}); host.remove(); delete box.kipWriter; } };
    box.kipWriter = api; return api;
  }
  function init(doc) {
    var K=root.Kip;
    if (!K || !K.sourceDocument || !K.richProjection || !K.richActive || !K.paste || !K.editorRun) return;
    doc.querySelectorAll('textarea[data-js-module~="rich-editor"]').forEach(function(box){
      if(box.kipWriter)return;
      try{attach(box,{candidate:'a',labels:JSON.parse(box.dataset.writerLabels),Kip:K});}catch(_){box.hidden=false;}
    });
  }
  return { attach:attach, init:init };
});
