/* Kiption enhancement layer, confirm module. A form that acts in one click
 * and cannot be taken back (delete a menu link, remove a queued story,
 * reject a member, revoke an invite code) carries its question in
 * data-confirm. The module asks it before the form posts. It listens on the
 * button click, not on submit: the core's double-submission guard disables
 * the button at submit, so a cancelled submit would leave it dead. With
 * scripting off the form posts without the answer and the server asks
 * the same question on a page of its own. */
'use strict';
(function (Kip) {
  if (!Kip) { return; }
  Kip.init(function () {
    document.addEventListener('click', function (e) {
      var b = e.target instanceof Element ? e.target.closest('button, input[type="submit"]') : null;
      var form = b && b.form;
      var text = form && form.getAttribute('data-confirm');
      if (!text) { return; }
      if (!window.confirm(text)) { e.preventDefault(); return; }
      // Answered here, so the server does not ask again on its own page.
      if (!form.querySelector('input[name="confirmed"]')) {
        var done = document.createElement('input');
        done.type = 'hidden'; done.name = 'confirmed'; done.value = '1';
        form.appendChild(done);
      }
    });
  });
})(window.Kip);
