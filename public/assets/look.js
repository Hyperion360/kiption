/* Kiption enhancement layer, look module. On the colors and layout screen the
 * two preview panes follow the form as it changes: the module posts the
 * fields to the preview address and paints the answer onto the panes. It sets
 * style properties, data attributes, and text only. With scripting off the
 * Preview button posts the form and the server paints the panes instead. */
'use strict';
(function (Kip) {
  if (!Kip) { return; }
  Kip.init(function () {
    var form = document.querySelector('form[data-js-module="look"]');
    if (!form) { return; }
    var url = form.getAttribute('data-preview-url');
    var panes = Kip.$$('[data-look-pane]');
    var button = form.querySelector('[data-look-preview-button]');
    var timer = 0;
    var seq = 0;
    var box = null;

    if (!url || panes.length === 0) { return; }
    if (button) { button.hidden = true; }
    document.documentElement.classList.add('look-live');

    function complain(errors) {
      if (!box) {
        box = document.createElement('p');
        box.className = 'look-live-error';
        box.setAttribute('role', 'alert');
        form.insertBefore(box, form.querySelector('button[type="submit"]'));
      }
      box.textContent = Object.keys(errors).map(function (k) { return errors[k]; }).join(' ');
    }

    function paint(body) {
      if (box) { box.textContent = ''; }
      panes.forEach(function (pane) {
        var vars = body.vars[pane.getAttribute('data-look-pane')] || {};
        Object.keys(vars).forEach(function (name) { pane.style.setProperty(name, vars[name]); });
        Object.keys(body.presets).forEach(function (group) { pane.setAttribute('data-' + group, body.presets[group]); });
      });
    }

    function refresh() {
      var mine = ++seq;
      var data = new FormData(form);
      data.set('fragment', '1');
      fetch(url, { method: 'POST', body: data, credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
        .then(function (r) { return r.json(); })
        .then(function (body) {
          if (mine !== seq) { return; }
          if (body && body.ok) { paint(body); } else if (body && body.errors) { complain(body.errors); }
        })
        .catch(function () { /* the saved page still works; the preview just stays as it was */ });
    }

    form.addEventListener('input', function (e) {
      var hex = e.target.type === 'color' ? e.target.parentNode.querySelector('.look-hex') : null;
      if (hex) { hex.textContent = e.target.value; }
      clearTimeout(timer);
      timer = setTimeout(refresh, 150);
    });
  });
})(window.Kip);
