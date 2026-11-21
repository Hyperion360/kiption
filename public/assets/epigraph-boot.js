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
  /* The editor's mode button asks for Formatted mode and drops the answer.
   * A chapter that holds markup Formatted mode cannot show is "blocked", and
   * one whose markup it would rewrite (a "* * *" scene break becomes "---")
   * waits for "review". Either way the click did nothing visible, so say why
   * and, for a rewrite, let the writer choose. Nothing converts unasked. */
  function explainMode(doc, box, api) {
    if (!api || typeof api.mode !== 'function' || typeof api.review !== 'function') { return; }
    var wrap = box.nextElementSibling;
    var button = wrap && wrap.querySelector ? wrap.querySelector('.epigraph-mode') : null;
    if (!button) { return; }
    var labels = {};
    try { labels = JSON.parse(box.getAttribute('data-writer-labels') || '{}') || {}; } catch (e) { labels = {}; }
    var note = doc.createElement('div');
    note.className = 'epigraph-mode-note';
    note.setAttribute('role', 'status');
    note.hidden = true;
    button.after(note);
    var clear = function () { note.hidden = true; note.replaceChildren(); };
    var action = function (text, cls, run) {
      var b = doc.createElement('button');
      b.type = 'button';
      b.className = cls;
      b.textContent = text;
      b.addEventListener('click', function () { run(); clear(); });
      return b;
    };
    /* Registered after the editor's own handler, so the mode is settled. */
    var explain = function () {
      clear();
      if (api.mode() === 'rich') { return; }
      var text = doc.createElement('p');
      if (typeof api.blocker === 'function' && api.blocker()) {
        text.textContent = labels.modeBlocked || '';
        note.append(text);
      } else if (api.review()) {
        text.textContent = labels.modeReview || '';
        note.append(text,
          action(labels.convert || '', 'btn-primary', function () { api.acceptConversion(); if (typeof api.focus === 'function') { api.focus(); } }),
          action(labels.keepSource || '', 'btn-secondary', function () { api.cancelConversion(); if (typeof api.focus === 'function') { api.focus(); } }));
      } else {
        return;
      }
      note.hidden = false;
      /* A keyboard writer cannot see the note appear, so put focus on the first answer. */
      var first = note.querySelector('button');
      if (first && first.focus) { first.focus(); }
    };
    button.addEventListener('click', explain);
    /* The editor's own Ctrl or Cmd + / shortcut toggles without clicking the button, so it needs the same explanation. */
    if (wrap.addEventListener) {
      wrap.addEventListener('keydown', function (e) {
        if ((e.metaKey || e.ctrlKey) && !e.shiftKey && !e.altKey && e.key === '/') { explain(); }
      }, false);
    }
  }

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
        if (images) {
          options.imageLibrary = { uploadUrl: uploadUrl, csrfToken: token };
          /* The server's intake cap, before it shrinks the image; junk or zero leaves the editor's own default. */
          var maxBytes = parseInt(box.getAttribute('data-epigraph-max-bytes') || '', 10);
          if (maxBytes > 0) { options.imageMaxBytes = maxBytes; }
        } else {
          var offNote = box.getAttribute ? box.getAttribute('data-epigraph-images-off') : null;
          if (offNote) { options.imagesOffMessage = offNote; }
        }
        var api = editor.mount(box, options);
        mounted += 1;
        /* The page chrome (preview, selection count) drives the editor through its API. */
        box.kipEpigraph = api;
        explainMode(doc, box, api);
        if (typeof win.CustomEvent === 'function') { box.dispatchEvent(new win.CustomEvent('epigraph-ready', { bubbles: true })); }
      } catch (e) {
        box.hidden = false;
      }
    });
    return mounted;
  }
  return { init: init };
});
