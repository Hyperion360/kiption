/* Kiption enhancement layer, Epigraph loader. Mounts the vendored Epigraph
 * editor (epigraph.js) over a chapter textarea marked epigraph-boot. The
 * textarea stays the form field that is submitted; if the editor is missing
 * or fails to start, the textarea is left visible and the page works as it
 * does with scripting off. */
'use strict';
(function (root, factory) {
  var api = factory();
  if (typeof module !== 'undefined' && module.exports) { module.exports = api; }
  if (root.document && typeof root.addEventListener === 'function') {
    var run = function () { api.init(root.document, root); };
    if (root.document.readyState === 'loading') { root.document.addEventListener('DOMContentLoaded', run); } else { run(); }
  }
})(typeof window !== 'undefined' ? window : globalThis, function () {
  function init(doc, win) {
    var editor = win.EpigraphAuthor;
    if (!editor || typeof editor.mount !== 'function') { return 0; }
    var mounted = 0;
    Array.prototype.forEach.call(doc.querySelectorAll('textarea[data-js-module~="epigraph-boot"]'), function (box) {
      try {
        /* Images are on only when the server says so: it renders the upload
         * address and token onto the textarea when the site allows them. */
        var uploadUrl = box.getAttribute ? box.getAttribute('data-epigraph-upload-url') : null;
        var token = box.getAttribute ? box.getAttribute('data-epigraph-csrf') : null;
        var images = !!(uploadUrl && token);
        var options = {
          imagesAllowed: images,
          /* The word count and draft modules listen for input on the textarea. */
          onChange: function () { box.dispatchEvent(new win.Event('input', { bubbles: true })); }
        };
        if (images) { options.imageLibrary = { uploadUrl: uploadUrl, csrfToken: token }; }
        editor.mount(box, options);
        mounted += 1;
      } catch (e) {
        box.hidden = false;
      }
    });
    return mounted;
  }
  return { init: init };
});
