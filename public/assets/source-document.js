/* Exact source strings and bounded edit history. Initialize from textarea.value:
 * browser form parsing and transport may normalize newlines before this layer. */
'use strict';
(function (root, factory) {
  var api = factory();
  if (typeof module !== 'undefined' && module.exports) module.exports = api;
  if (root.Kip) root.Kip.sourceDocument = api;
})(typeof window !== 'undefined' ? window : globalThis, function () {
  function difference(before, after) {
    var start = 0, a = before.length, b = after.length;
    while (start < a && start < b && before[start] === after[start]) start++;
    while (a > start && b > start && before[a - 1] === after[b - 1]) { a--; b--; }
    // Expand a shared surrogate boundary so a replacement keeps whole pairs.
    if (start > 0 && /[\uD800-\uDBFF]/.test(before[start - 1])) start--;
    if (a < before.length && /[\uDC00-\uDFFF]/.test(before[a])) { a++; b++; }
    return { start: start, end: a, insert: after.slice(start, b) };
  }

  function create(initial, initialSelection) {
    var text = String(initial), selection;
    var undo = [], redo = [];
    var selectionEpoch = 0;
    function copy(s) { return { anchor: s.anchor, focus: s.focus }; }
    function validate(s, length) {
      if (!s || !Number.isInteger(s.anchor) || !Number.isInteger(s.focus) ||
          Math.min(s.anchor, s.focus) < 0 || Math.max(s.anchor, s.focus) > length) throw RangeError('selection');
    }
    function setSelection(s) { validate(s, text.length); selection = copy(s); }
    function select(s) {
      validate(s, text.length);
      if (selection && (selection.anchor !== s.anchor || selection.focus !== s.focus)) selectionEpoch++;
      selection = copy(s);
    }
    select(initialSelection || { anchor: 0, focus: 0 });
    function replace(start, end, insert) { text = text.slice(0, start) + insert + text.slice(end); }
    function prune() {
      var units = undo.reduce(function (n, item) { return n + item.removed.length + item.insert.length; }, 0);
      // Keep one oversized operation so even a large paste can be undone.
      while (undo.length > 100 || (units > 4000000 && undo.length > 1)) {
        var first = undo.shift(); units -= first.removed.length + first.insert.length;
      }
    }
    return {
      get text() { return text; },
      get selection() { return copy(selection); },
      select: select,
      apply: function (start, end, insert, next, kind, now) {
        if (!Number.isInteger(start) || !Number.isInteger(end) || start < 0 || end < start ||
            end > text.length || typeof insert !== 'string') throw RangeError('edit');
        // Validate before touching source, selection, or either history stack.
        validate(next, text.length - (end - start) + insert.length);
        if (text.slice(start, end) === insert) { select(next); return false; }
        var item = { start: start, removed: text.slice(start, end), insert: insert,
          before: copy(selection), after: copy(next), kind: kind, at: now, selectionEpoch: selectionEpoch };
        var last = undo[undo.length - 1];
        var join = kind === 'typing' && last && last.kind === 'typing' &&
          item.removed === '' && last.removed === '' && last.start + last.insert.length === start &&
          now >= last.at && now - last.at <= 750 && selection.anchor === start && selection.focus === start &&
          last.selectionEpoch === selectionEpoch;
        replace(start, end, insert); setSelection(next); redo.length = 0;
        if (join) { last.insert += insert; last.after = copy(next); last.at = now; }
        else undo.push(item);
        prune(); return true;
      },
      undo: function () {
        var item = undo.pop(); if (!item) return false;
        replace(item.start, item.start + item.insert.length, item.removed);
        setSelection(item.before); selectionEpoch++; redo.push(item); return true;
      },
      redo: function () {
        var item = redo.pop(); if (!item) return false;
        replace(item.start, item.start + item.removed.length, item.insert);
        setSelection(item.after); selectionEpoch++; undo.push(item); return true;
      }
    };
  }
  return { create: create, difference: difference };
});
