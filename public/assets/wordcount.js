/* Kiption enhancement layer, word count. The same rule as
 * App\Markdown::wordCount in PHP: a word is a run of letters, digits and
 * marks, joined across an apostrophe (straight or curly) or hyphen. Link
 * syntax, scene breaks and emphasis markers do not count. The parity test
 * reads tests/fixtures/wordcount.json to keep the two in step. */
'use strict';
(function (root, factory) {
  var api = factory();
  if (typeof module !== 'undefined' && module.exports) { module.exports = api; }
  if (root.Kip) { root.Kip.wordCount = api.wordCount; }
})(typeof window !== 'undefined' ? window : globalThis, function () {
  var WORD = /[\p{L}\p{N}\p{M}]+(?:['\u2019-][\p{L}\p{N}\p{M}]+)*/gu;

  function wordCount(md) {
    var t = String(md).replace(/\r\n?/g, '\n');
    t = t.replace(/^\s*(?:-{3,}|\*{3,}|_{3,})\s*$/gm, ' ');
    t = t.replace(/!?\[([^\]]*)\]\((https?:\/\/[^\s)]+)\)/g, '$1');
    var m = t.match(WORD);
    return m ? m.length : 0;
  }

  return { wordCount: wordCount };
});
