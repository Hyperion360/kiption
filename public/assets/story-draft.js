/* New Story: keep what the writer typed on this device so leaving the page is
 * never a trap. The browser's "Leave site?" prompt is turned off for this form
 * (data-writer-guard="off"); the title, summary, language and rating are saved
 * as they type and come back if the page is reopened. Cleared on submit. */
(function () {
  'use strict';
  var KEY = 'kip:new-story-draft', FIELDS = ['title', 'summary', 'notes', 'language', 'rating_id'];
  function store() { try { return window.localStorage; } catch (_) { return null; } }
  function init(form) {
    var ls = store();
    if (!ls || form.dataset.storyDraft === 'on') return;
    form.dataset.storyDraft = 'on';
    try {
      var saved = JSON.parse(ls.getItem(KEY) || 'null');
      if (saved && !form.elements.title.value) {
        FIELDS.forEach(function (n) { if (typeof saved[n] === 'string' && form.elements[n]) form.elements[n].value = saved[n]; });
      }
    } catch (_) {}
    form.addEventListener('input', function () {
      var out = {};
      FIELDS.forEach(function (n) { if (form.elements[n]) out[n] = form.elements[n].value; });
      try { ls.setItem(KEY, JSON.stringify(out)); } catch (_) {}
    });
    form.addEventListener('submit', function () { try { ls.removeItem(KEY); } catch (_) {} });
  }
  var form = document.getElementById('story-editor-form');
  if (form && form.getAttribute('data-writer-guard') === 'off') init(form);
}());
