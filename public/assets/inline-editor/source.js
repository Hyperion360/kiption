'use strict';
(function (root, factory) {
  var api = factory();
  if (typeof module !== 'undefined' && module.exports) module.exports = api;
  if (root.document) {
    root.KipInline = root.KipInline || {};
    root.KipInline.source = api;
  }
})(typeof window !== 'undefined' ? window : globalThis, function () {
  function createSource(initial, initialSelection) {
    if (typeof initial !== 'string') throw new RangeError('source');
    var text = initial, selection, revision = 0, selectionEpoch = 0;
    var undo = [], redo = [];
    function copy(value) { return { anchor: value.anchor, focus: value.focus }; }
    function validateSelection(value, length) {
      if (!value || !Number.isInteger(value.anchor) || !Number.isInteger(value.focus) ||
          Math.min(value.anchor, value.focus) < 0 || Math.max(value.anchor, value.focus) > length) {
        throw new RangeError('selection');
      }
    }
    function setSelection(value) { selection = copy(value); }
    function select(value) {
      validateSelection(value, text.length);
      if (selection && (selection.anchor !== value.anchor || selection.focus !== value.focus)) selectionEpoch++;
      setSelection(value);
    }
    select(initialSelection || { anchor: 0, focus: 0 });
    function replace(from, to, insert) { text = text.slice(0, from) + insert + text.slice(to); }
    function prune() {
      var units = undo.reduce(function (total, item) { return total + item.removed.length + item.insert.length; }, 0);
      while (undo.length > 100 || (units > 4000000 && undo.length > 1)) {
        var oldest = undo.shift();
        units -= oldest.removed.length + oldest.insert.length;
      }
    }
    return {
      get text() { return text; },
      get selection() { return copy(selection); },
      get revision() { return revision; },
      select: select,
      apply: function (change) {
        if (!change || !Number.isInteger(change.from) || !Number.isInteger(change.to) ||
            change.from < 0 || change.to < change.from || change.to > text.length ||
            typeof change.insert !== 'string' || typeof change.kind !== 'string' || !change.kind ||
            !Number.isFinite(change.time)) throw new RangeError('edit');
        validateSelection(change.selection, text.length - (change.to - change.from) + change.insert.length);
        if (text.slice(change.from, change.to) === change.insert) {
          select(change.selection);
          return false;
        }
        var item = { from: change.from, removed: text.slice(change.from, change.to),
          insert: change.insert, before: copy(selection), after: copy(change.selection),
          kind: change.kind, time: change.time, selectionEpoch: selectionEpoch };
        var last = undo[undo.length - 1];
        var join = item.kind === 'typing' && last && last.kind === 'typing' &&
          item.removed === '' && last.removed === '' && last.from + last.insert.length === item.from &&
          item.time >= last.time && item.time - last.time <= 750 &&
          selection.anchor === item.from && selection.focus === item.from &&
          last.selectionEpoch === selectionEpoch;
        replace(change.from, change.to, change.insert);
        setSelection(change.selection);
        redo.length = 0;
        if (join) {
          last.insert += change.insert;
          last.after = copy(change.selection);
          last.time = change.time;
        } else undo.push(item);
        prune();
        revision++;
        return true;
      },
      undo: function () {
        var item = undo.pop();
        if (!item) return false;
        replace(item.from, item.from + item.insert.length, item.removed);
        setSelection(item.before);
        selectionEpoch++;
        redo.push(item);
        revision++;
        return true;
      },
      redo: function () {
        var item = redo.pop();
        if (!item) return false;
        replace(item.from, item.from + item.removed.length, item.insert);
        setSelection(item.after);
        selectionEpoch++;
        undo.push(item);
        revision++;
        return true;
      }
    };
  }
  return { createSource: createSource };
});
