/* Kiption enhancement layer, confirm module. A form that acts in one click
 * and cannot be taken back (delete a menu link, remove a queued story,
 * reject a member, revoke an invite code) carries its question in
 * data-confirm. The module asks it before the form posts. It listens on the
 * button click, not on submit: the core's double-submission guard disables
 * the button at submit, so a cancelled submit would leave it dead. With
 * scripting off the form posts as before. */
'use strict';
(function (Kip) {
  if (!Kip) { return; }
  Kip.init(function () {
    document.addEventListener('click', function (e) {
      var b = e.target instanceof Element ? e.target.closest('button, input[type="submit"]') : null;
      var form = b && b.form;
      var text = form && form.getAttribute('data-confirm');
      if (text && !window.confirm(text)) { e.preventDefault(); }
    });
  });
})(window.Kip);
