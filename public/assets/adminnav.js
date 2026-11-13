/* Kiption enhancement layer, adminnav module. The admin list of screens
 * (sidebar on wide screens, sheet on phones) gains a "Find a screen" box:
 * typing narrows the list to the screens whose name contains the text, and
 * Enter opens the first match. With scripting off the box stays hidden and
 * the full list is simply there. */
'use strict';
(function (Kip) {
  if (!Kip) { return; }
  Kip.init(function () {
    var box = document.querySelector('[data-js-module="adminnav"]');
    var nav = box && box.closest('nav');
    if (!nav) { return; }
    var none = nav.querySelector('.admin-nav-none');
    box.hidden = false;
    /* A long list scrolls inside the sidebar: start with the current screen in view. */
    var here = nav.querySelector('li a[aria-current="page"]');
    if (here && nav.scrollHeight > nav.clientHeight) { nav.scrollTop = Math.max(0, here.offsetTop - nav.clientHeight / 2); }
    function shown() {
      return Array.prototype.filter.call(nav.querySelectorAll('li'), function (li) { return !li.hidden; });
    }
    box.addEventListener('input', function () {
      var q = box.value.trim().toLowerCase();
      Array.prototype.forEach.call(nav.querySelectorAll('ul'), function (ul) {
        var any = false;
        Array.prototype.forEach.call(ul.children, function (li) {
          li.hidden = q !== '' && li.textContent.toLowerCase().indexOf(q) === -1;
          any = any || !li.hidden;
        });
        ul.hidden = !any;
        if (ul.previousElementSibling) { ul.previousElementSibling.hidden = !any; }
      });
      if (none) { none.hidden = shown().length > 0; }
    });
    box.addEventListener('keydown', function (e) {
      if (e.key !== 'Enter') { return; }
      var first = shown()[0];
      var link = box.value.trim() !== '' && first ? first.querySelector('a') : null;
      if (link) { e.preventDefault(); window.location.href = link.href; }
    });
  });
})(window.Kip);
