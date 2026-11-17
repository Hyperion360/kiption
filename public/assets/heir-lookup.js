/* Kiption enhancement layer, heir-lookup module. On the delete-a-member
 * card, typing the pen name of the member who takes over the stories shows
 * who it is ("Found wrenfield, an admin."), or that no one matches, as soon
 * as the typing pauses. The server words every answer and applies the same
 * rules the delete itself does; this file only asks and shows it. Without
 * scripting the field is a plain input and the delete does its own check.
 * The loader appends this file only when the page carries
 * [data-js-module~="heir-lookup"]. */
'use strict';
(function () {
  if (!window.Kip || !window.fetch) { return; }
  window.Kip.init(function () {
    Array.prototype.forEach.call(document.querySelectorAll('[data-js-module~="heir-lookup"]'), function (card) {
      var input = card.querySelector('input[name="penname"]');
      var status = card.querySelector('[data-heir-status]');
      var base = card.getAttribute('data-heir-url');
      if (!input || !status || !base) { return; }
      var timer, serial = 0;
      function show(state, message) {
        status.textContent = message;
        if (state) { status.setAttribute('data-state', state); } else { status.removeAttribute('data-state'); }
      }
      function ask() {
        var name = String(input.value).trim();
        var mine = ++serial;
        if (name === '') { show('', ''); return; }
        window.fetch(base + '&penname=' + encodeURIComponent(name), { headers: { Accept: 'application/json' }, credentials: 'same-origin' })
          .then(function (res) { return res.ok ? res.json() : null; })
          .then(function (data) {
            if (mine !== serial) { return; }
            if (data && typeof data.message === 'string') { show(data.state === 'found' ? 'found' : 'problem', data.message); } else { show('', ''); }
          })
          .catch(function () { if (mine === serial) { show('', ''); } });
      }
      input.addEventListener('input', function () { clearTimeout(timer); serial += 1; timer = setTimeout(ask, 250); });
    });
  });
})();
