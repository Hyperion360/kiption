/* Kiption enhancement layer, saved module. The line that answers an admin
 * save rides in on ?saved=<code>. Once it is on screen the code is taken
 * out of the address, so a reload or a shared link does not repeat it.
 * With scripting off the line shows the same way. */
'use strict';
(function (Kip) {
  if (!Kip) { return; }
  Kip.init(function () {
    if (!document.querySelector('.saved-note') || !window.history || !window.history.replaceState) { return; }
    var url = new URL(window.location.href);
    ['saved', 'undo', 'undo_key'].forEach(function (k) { url.searchParams.delete(k); });
    window.history.replaceState(null, '', url.pathname + url.search + url.hash);
  });
})(window.Kip);
