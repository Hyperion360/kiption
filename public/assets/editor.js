/* Kiption enhancement layer, editor commands. The commands table with two
 * halves: the pure key mapping and command runs the tests drive, and the
 * DOM wiring (toolbar, shortcuts, the IME guard) that init() builds on the
 * chapter form. The textarea adapter edits through text-edit.js so every
 * command undoes in one step. The rich-view adapter (slice 6) plugs into the
 * same table. With JavaScript off there is no toolbar and no shortcuts: the
 * plain textarea posts as always. */
'use strict';
(function (root, factory) {
  var api = factory(root);
  if (typeof module !== 'undefined' && module.exports) { module.exports = api; }
  if (root.Kip) {
    root.Kip.editorHandleKey = api.handleKey;
    root.Kip.editorRun = api.run;
    root.Kip.editorInit = api.init;
    if (root.Kip.init) { root.Kip.init(api.init); }
  }
})(typeof window !== 'undefined' ? window : globalThis, function (root) {
  var textEdit = (function () {
    if (typeof module !== 'undefined' && module.exports) { return require('./text-edit.js'); }
    return root && root.Kip ? { insertText: root.Kip.textInsert, wrapSelection: root.Kip.textWrap, prefixLines: root.Kip.textPrefix } : null;
  })();
  /* smart punctuation rides the same keydown path in the browser; the Node
   * tests drive it directly, so it stays null here */
  var smart = (typeof module !== 'undefined' && module.exports)
    ? null
    : (root && root.Kip && root.Kip.smart ? root.Kip.smart : null);

  var MARKERS = { bold: ['**', '**'], italic: ['*', '*'] };

  /** The key map, pure so the tests can drive it: a modifier plus B, I or K
   *  names the command; an active IME composition suppresses everything
   *  (the keyboard is mid-word, the keystrokes are not commands). */
  function handleKey(state, key, ctrl, meta) {
    if (state.composing) { return null; }
    if (!(ctrl || meta)) { return null; }
    var k = String(key).toLowerCase();
    if (k === 'b') { return 'bold'; }
    if (k === 'i') { return 'italic'; }
    if (k === 'k') { return 'link'; }
    return null;
  }

  /** Run one command against a text box. Multi-line bold or italic wraps
   *  each non-empty line separately: the renderer cannot bold across a
   *  newline, so an honest per-line wrap is what renders back. */
  function run(id, box, doc) {
    var v = box.value;
    var s = box.selectionStart;
    var e = box.selectionEnd;
    if (id === 'quote') { textEdit.prefixLines(box, '> ', doc); return; }
    if (id === 'break') {
      var lead = s > 0 && v.charAt(s - 1) !== '\n' ? '\n\n' : (s > 0 ? '\n' : '');
      textEdit.insertText(box, lead + '---', doc);
      return;
    }
    if (id === 'link') {
      var label = v.slice(s, e);
      textEdit.insertText(box, '[' + label + '](https://)', doc);
      /* the caret lands on the address of a non-empty label, on the label of an empty one */
      var addr = s + 1 + label.length + 2;
      box.setSelectionRange(label === '' ? s + 1 : addr, label === '' ? s + 1 : addr + 8);
      return;
    }
    var pair = MARKERS[id];
    if (!pair) { return; }
    if (v.slice(s, e).indexOf('\n') > -1) {
      var start = v.lastIndexOf('\n', s - 1) + 1;
      if (s === 0) { start = 0; }
      var nl = v.indexOf('\n', e);
      var end = nl === -1 ? v.length : nl;
      var block = v.slice(start, end);
      var done = block.split('\n').map(function (line) {
        return line.trim() === '' ? line : pair[0] + line + pair[1];
      }).join('\n');
      box.setSelectionRange(start, end);
      textEdit.insertText(box, done, doc);
      box.setSelectionRange(start, start + done.length);
      return;
    }
    textEdit.wrapSelection(box, pair[0], pair[1], doc);
  }

  /** The DOM wiring: the JS-built toolbar (real buttons, labelled), the
   *  shortcuts on the box, and the composition window that guards them.
   *  Labels ride the textarea's data-editor-labels JSON, rendered server
   *  side from the language keys, so nothing here hardcodes copy. */
  function init(doc) {
    doc = doc || document;
    var boxes = doc.querySelectorAll('textarea[data-js-module~="editor"]');
    Array.prototype.forEach.call(boxes, function (box) {
      if (box.dataset.editorDone) { return; }
      box.dataset.editorDone = '1';
      var labels = {};
      try { labels = JSON.parse(box.dataset.editorLabels || '{}') || {}; } catch (err) { labels = {}; }

      var state = { composing: false };
      box.addEventListener('compositionstart', function () { state.composing = true; });
      box.addEventListener('compositionend', function () { state.composing = false; });
      box.addEventListener('keydown', function (e) {
        if (box.kipWriter || state.composing || e.isComposing) { return; }
        /* smart punctuation consumes the keystroke first (a plain quote,
         * hyphen or dot, no modifiers); the shortcuts read the modifiers */
        if (smart && !e.ctrlKey && !e.metaKey && !e.altKey && smart.apply(box, e.key)) {
          e.preventDefault();
          return;
        }
        var id = handleKey(state, e.key, e.ctrlKey, e.metaKey);
        if (id === null) { return; }
        e.preventDefault();
        run(id, box);
      });

      var host = box.closest('label') || box;
      var bar = doc.createElement('div');
      bar.className = 'editor-toolbar';
      bar.setAttribute('role', 'toolbar');
      var defs = [['bold', 'b'], ['italic', 'i'], ['link', 'k'], ['quote', ''], ['break', '']];
      defs.forEach(function (def) {
        if (!labels[def[0]]) { return; }
        var b = doc.createElement('button');
        b.type = 'button';
        b.className = 'btn-quiet editor-btn';
        b.setAttribute('aria-label', labels[def[0]]);
        b.textContent = labels[def[0]];
        b.addEventListener('pointerdown', function () { if (box.kipWriter) box.kipWriter.capture(); });
        b.addEventListener('click', function (ev) {
          ev.preventDefault();
          if (box.kipWriter) box.kipWriter.command(def[0]);
          else { run(def[0], box); box.focus(); }
        });
        bar.appendChild(b);
      });
      if (bar.firstChild) { host.parentNode.insertBefore(bar, host); }
    });
  }

  return { handleKey: handleKey, run: run, init: init };
});
