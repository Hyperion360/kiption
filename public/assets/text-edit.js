/* Kiption enhancement layer, text editing. ONE helper for changing a text
 * box: execCommand('insertText') first so the browser's own undo removes the
 * whole edit in one step, setRangeText as the fallback where that API is
 * missing, then a bubbling input event so every listener (counter, draft,
 * paste) hears the change. The toolbar, smart punctuation and the paste
 * module all edit through here. Node loads it through module.exports; the
 * browser gets Kip.textInsert and Kip.textWrap and Kip.textPrefix. */
'use strict';
(function (root, factory) {
  var api = factory();
  if (typeof module !== 'undefined' && module.exports) { module.exports = api; }
  if (root.Kip) { root.Kip.textInsert = api.insertText; root.Kip.textWrap = api.wrapSelection; root.Kip.textPrefix = api.prefixLines; }
})(typeof window !== 'undefined' ? window : globalThis, function () {
  var fireInput = function (box) {
    var e = typeof Event === 'function' ? new Event('input', { bubbles: true }) : { type: 'input', bubbles: true };
    if (box.dispatchEvent) { box.dispatchEvent(e); }
  };

  /** Replace the box's current selection with text through the undo-honest
   *  path. $doc is injectable so tests can watch execCommand. */
  function insertText(box, text, doc) {
    var d = doc || (typeof document !== 'undefined' ? document : null);
    var ok = false;
    if (d && typeof d.execCommand === 'function') {
      if (typeof box.focus === 'function') { try { box.focus(); } catch (err) { /* a detached box still edits */ } }
      try { ok = d.execCommand('insertText', false, text); } catch (err) { ok = false; }
    }
    if (!ok) {
      box.setRangeText(text, box.selectionStart, box.selectionEnd, 'end');
    }
    fireInput(box);
  }

  /** Wrap the selection in a marker pair; a second call over the same pair
   *  unwraps. An empty selection places the caret between the markers. A
   *  trailing-whitespace tail moves outside the markers. */
  function wrapSelection(box, before, after, doc) {
    var v = box.value;
    var s = box.selectionStart;
    var e = box.selectionEnd;
    if (s === e) {
      insertText(box, before + after, doc);
      box.setSelectionRange(s + before.length, s + before.length);
      return;
    }
    if (v.slice(s - before.length, s) === before && v.slice(e, e + after.length) === after) {
      /* Already wrapped: removing the opening marker shifts the inner selection. */
      var inner0 = v.slice(s, e);
      box.setSelectionRange(s - before.length, e + after.length);
      insertText(box, inner0, doc);
      box.setSelectionRange(s - before.length, s - before.length + inner0.length);
      return;
    }
    var sel = v.slice(s, e);
    var lead = ( /^\s*/.exec(sel) || [''] )[0];
    var core = sel.slice(lead.length).replace(/\s+$/, '');
    var trail = sel.slice(lead.length + core.length);
    box.setSelectionRange(s + lead.length, s + lead.length + core.length);
    insertText(box, before + core + after, doc);
    box.setSelectionRange(s + lead.length + before.length, s + lead.length + before.length + core.length);
  }

  /** Prefix every line of the selection's block with $marker (the quote
   *  command); empty lines stay empty so blanks remain blanks. */
  function prefixLines(box, marker, doc) {
    var v = box.value;
    var s = box.selectionStart;
    var e = box.selectionEnd;
    var start = v.lastIndexOf('\n', s - 1) + 1;
    if (s === 0) { start = 0; }
    var nl = v.indexOf('\n', e);
    var end = nl === -1 ? v.length : nl;
    var block = v.slice(start, end);
    var done = block.split('\n').map(function (line) {
      return line.trim() === '' ? line : marker + line;
    }).join('\n');
    box.setSelectionRange(start, end);
    insertText(box, done, doc);
    box.setSelectionRange(start, start + done.length);
  }

  return { insertText: insertText, wrapSelection: wrapSelection, prefixLines: prefixLines };
});
