/* Native chapter form adapter for the source-preserving inline editor. */
'use strict';
(function (root, factory) {
  var api = factory(root);
  if (typeof module !== 'undefined' && module.exports) module.exports = api;
  if (root.document) {
    root.KipInline = root.KipInline || {};
    root.KipInline.editor = api;
  }
})(typeof window !== 'undefined' ? window : globalThis, function (root) {
  var MAX_SOURCE = 2000000, attached = new WeakMap();

  function selection(control) {
    return control.selectionDirection === 'backward' ?
      {anchor:control.selectionEnd,focus:control.selectionStart} :
      {anchor:control.selectionStart,focus:control.selectionEnd};
  }
  function setSelection(control, range) {
    control.setSelectionRange(Math.min(range.anchor,range.focus),
      Math.max(range.anchor,range.focus),range.anchor > range.focus ? 'backward' : 'forward');
  }
  function attach(box, options) {
    if (!box || !box.form || !box.ownerDocument || typeof box.value !== 'string') {
      throw new TypeError('chapter textarea and form required');
    }
    if (attached.has(box)) return attached.get(box);
    if (box.value.length > MAX_SOURCE) return null;
    options = options || {};
    var modules = options.modules || root.KipInline;
    if (!modules || !modules.source || !modules.projection || !modules.input || !modules.syntax) return null;
    var doc = box.ownerDocument, form = box.form, model, view, controller;
    try {
      model = modules.source.createSource(box.value,selection(box));
      view = modules.projection.create(doc,model,{syntax:modules.syntax});
    } catch (_) { return null; }

    var host = doc.createElement('div'), controls = doc.createElement('div');
    var sourceButton = doc.createElement('button'), liveButton = doc.createElement('button');
    var status = doc.createElement('p'), copy = null, cleanup = [];
    var mode = 'live', blocked = false, blockedSourceValue = null, sourceOnly = false, detached = false;
    host.className = 'ki-editor';
    controls.className = 'ki-editor-modes';
    controls.setAttribute('role','group');
    controls.setAttribute('aria-label','Editor Mode');
    sourceButton.type = liveButton.type = 'button';
    sourceButton.textContent = 'Source'; liveButton.textContent = 'Formatted';
    sourceButton.setAttribute('data-editor-control','source');
    liveButton.setAttribute('data-editor-control','live');
    status.setAttribute('role','status');
    status.setAttribute('aria-live','polite');
    controls.append(sourceButton,liveButton);
    host.append(controls,status,view.element);
    function listen(target,type,fn,capture) {
      target.addEventListener(type,fn,!!capture);
      cleanup.push(function () { target.removeEventListener(type,fn,!!capture); });
    }
    function updateModes() {
      box.hidden = mode === 'live';
      view.element.hidden = mode !== 'live';
      sourceButton.setAttribute('aria-pressed',String(mode === 'source'));
      liveButton.setAttribute('aria-pressed',String(mode === 'live'));
    }
    function showSource() {
      if (!blocked && !sourceOnly && box.value !== model.text) {
        blockUnsignaledSource(); return false;
      }
      if (mode === 'source') return true;
      try { model.select(view.readSelection()); }
      catch (_) {
        // WebKit can clear the DOM selection when focus leaves the editable
        // region. Reuse the last model selection only if no native edit is
        // waiting to be reconciled.
        try {
          if (!controller.domMatchesSource()) throw new RangeError('unconverted DOM');
        } catch (_) { fail(view.element.textContent || ''); return false; }
      }
      box.value = model.text;
      mode = 'source'; updateModes();
      setSelection(box,model.selection);
      box.focus();
      return true;
    }
    function showLive() {
      if (blocked || sourceOnly) return false;
      if (box.value !== model.text) { blockUnsignaledSource(); return false; }
      if (mode === 'live') return true;
      if (box.value.length > MAX_SOURCE) {
        status.textContent = 'Continue editing in Source mode.';
        return false;
      }
      try { model.select(selection(box)); view.render(); view.restoreSelection(model.selection); }
      catch (_) { fail(box.value); return false; }
      mode = 'live'; updateModes(); view.element.focus();
      return true;
    }
    function fail(uncertain, preserveSource) {
      if (blocked) return;
      blocked = true;
      if (controller) controller.detach();
      if (!preserveSource) box.value = model.text;
      blockedSourceValue = box.value;
      mode = 'source'; updateModes();
      liveButton.disabled = true;
      copy = doc.createElement('textarea');
      copy.readOnly = true;
      copy.value = String(uncertain);
      copy.setAttribute('aria-label','Unconverted Input Copy');
      copy.setAttribute('data-editor-control','copy');
      host.append(copy);
      status.textContent = preserveSource ?
        'Source text changed outside the editor. Review and edit it before saving.' :
        'Input could not be converted. Copy the text below into Source mode, then edit it before saving.';
      box.focus();
    }
    function blockUnsignaledSource() { fail(box.value,true); }
    try {
      controller = modules.input.create(model,view,{source:box,
        convertHtml:options.convertHtml,onFailure:fail});
      if (!controller || typeof controller.detach !== 'function') throw new TypeError('input controller');
    } catch (_) { if (controller && controller.detach) controller.detach(); return null; }
    listen(sourceButton,'click',showSource);
    listen(liveButton,'click',showLive);
    // WebKit may clear the native Selection as focus leaves contenteditable.
    // Retain the last mapped caret before that happens so Source mode can
    // restore it without guessing from an escaped DOM endpoint.
    if (doc.addEventListener && doc.removeEventListener) listen(doc,'selectionchange',function () {
      if (mode !== 'live' || blocked || sourceOnly) return;
      var nativeSelection = doc.getSelection && doc.getSelection();
      if (!nativeSelection || !view.element.contains(nativeSelection.anchorNode) ||
          !view.element.contains(nativeSelection.focusNode)) return;
      try { model.select(view.readSelection()); } catch (_) { /* Unsafe selection is handled on the next action. */ }
    });
    listen(box,'invalid',function () { showSource(); });
    listen(box,'input',function () {
      if (!blocked || box.value === blockedSourceValue) return;
      blocked = false; sourceOnly = true;
      if (copy) { copy.remove(); copy = null; }
      status.textContent = 'Source editing restored. Save uses the visible source text.';
    },true);
    listen(form,'submit',function (event) {
      if (!blocked && !sourceOnly && box.value !== model.text) {
        event.preventDefault(); blockUnsignaledSource(); return;
      }
      if (blocked) {
        event.preventDefault();
        status.textContent = 'Edit the source text before saving.';
        return;
      }
      if (!sourceOnly) box.value = model.text;
    });
    try {
      box.insertAdjacentElement('afterend',host);
      updateModes();
      view.restoreSelection(model.selection);
    }
    catch (_) {
      controller.detach();
      for (var off of cleanup) off();
      host.remove(); box.hidden = false;
      return null;
    }
    var handle = {
      get mode() { return mode; },
      showSource:showSource,
      showLive:showLive,
      detach:function () {
        if (detached) return true;
        if (blocked) return false;
        if (!sourceOnly && box.value !== model.text) {
          blockUnsignaledSource(); return false;
        }
        if (!sourceOnly) box.value = model.text;
        controller.detach();
        for (var off of cleanup) off();
        host.remove(); box.hidden = false;
        detached = true; attached.delete(box);
        return true;
      }
    };
    attached.set(box,handle);
    return handle;
  }
  return {attach:attach};
});
