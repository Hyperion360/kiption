/* Kiption enhancement layer, smart punctuation. Curly quotes, an em dash
 * from an inline hyphen pair, and an ellipsis, typed as you go (D6: `--`
 * becomes an em dash; a hyphen line is a scene break and never converts).
 * Each conversion replaces the keystroke at keydown through the shared
 * insert helper, so the browser's own undo removes the whole conversion in
 * one step (the recorded D2 warning: curly punctuation in saved text is
 * hard to reverse, so the one-step undo is the mitigation). Guards, read
 * from subset.js so the rules exist once: never inside a link target,
 * never on an escaped marker, never on a hyphen line. IME composition is
 * suppressed by the editor module before this runs. */
'use strict';
(function (root, factory) {
  var api = factory(root);
  if (typeof module !== 'undefined' && module.exports) { module.exports = api; }
  if (root.Kip) { root.Kip.smart = api; }
})(typeof window !== 'undefined' ? window : globalThis, function (root) {
  var subset = (function () {
    if (typeof module !== 'undefined' && module.exports) { return require('./subset.js'); }
    return root && root.Kip && root.Kip.subset ? root.Kip.subset : null;
  })();

  /** The line this caret sits on, up to the caret. */
  function lineBefore(text, caret) {
    var start = text.lastIndexOf('\n', caret - 1) + 1;
    return text.slice(start, caret);
  }

  /** True when the marker position is escaped (a backslash right before
   *  the character one step back) or sits in a link's address. */
  function guarded(text, caret) {
    if (caret >= 2 && text.charAt(caret - 2) === '\\') { return true; }
    return subset ? subset.linkTargetAt(text, caret - 1) : false;
  }

  /** The replacement for a typed quote: opens after whitespace, an opener
   *  bracket or the start of the text; inside a word it is an apostrophe;
   *  otherwise it closes. Double and single quotes follow one rule set. */
  function curlyFor(text, caret, key) {
    var before = caret === 0 ? '' : text.charAt(caret - 1);
    var after = caret >= text.length ? '' : text.charAt(caret);
    var opens = before === '' || /[\s(\[{\u2014-]/.test(before);
    var inWord = /[\p{L}\p{N}]/u.test(before) && /[\p{L}\p{N}]/u.test(after);
    if (inWord) { return key === '"' ? '”' : '’'; }
    if (opens) { return key === '"' ? '“' : '‘'; }
    return key === '"' ? '”' : '’';
  }

  /** The conversion a keystroke makes, or null when the keystroke stays
   *  natural. @return null | {from, to, text} the range before the caret to
   *  swap for text. */
  function forKey(box, key) {
    var text = box.value;
    var caret = box.selectionStart;
    if (caret !== box.selectionEnd || caret === 0) {
      if (caret !== box.selectionEnd || key === '-' || key === '.') { return null; }
      if (key !== '"' && key !== "'") { return null; }
    }
    if (key === '"' || key === "'") {
      if (guarded(text, caret)) { return null; }
      return { from: caret, to: caret, text: curlyFor(text, caret, key) };
    }
    if (key === '-') {
      if (caret < 2 || text.charAt(caret - 1) !== '-') { return null; }
      if (guarded(text, caret)) { return null; }
      /* the scene-break guard: a line whose only earlier content is hyphens
       * or whitespace never converts, at any width */
      var line = lineBefore(text, caret);
      if (!/[^\s-]/.test(line)) { return null; }
      return { from: caret - 1, to: caret, text: '\u2014' };
    }
    if (key === '.') {
      if (caret < 2 || text.charAt(caret - 1) !== '.' || text.charAt(caret - 2) !== '.') { return null; }
      if (caret >= 3 && text.charAt(caret - 3) === '.') { return null; } /* four dots are literal */
      if (guarded(text, caret)) { return null; }
      return { from: caret - 2, to: caret, text: '\u2026' };
    }
    return null;
  }

  /** The editor module's keydown hook: consume the keystroke when a
   *  conversion applies, through the shared insert helper (one undo step).
   *  Returns true when the keystroke was replaced. */
  function apply(box, key, doc) {
    var insert = (function () {
      if (typeof module !== 'undefined' && module.exports) { return require('./text-edit.js').insertText; }
      return root && root.Kip && root.Kip.textInsert ? root.Kip.textInsert : null;
    })();
    var conv = forKey(box, key);
    if (conv === null) { return false; }
    box.setSelectionRange(conv.from, conv.to);
    if (insert) { insert(box, conv.text, doc); } else { box.setRangeText(conv.text, conv.from, conv.to, 'end'); }
    return true;
  }

  return { forKey: forKey, apply: apply };
});
