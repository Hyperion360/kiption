/* Kiption enhancement layer, prefs module. Instant text settings: every
 * control applies the moment it changes. The module mirrors the server
 * exactly - the same compact reader cookie string the Save POST mints, the
 * same data-* attributes the layout renders on <html>, the same
 * default-clearing economy (default-equal visitors keep full static-cache
 * hits) - so the next server render agrees with what the visitor already
 * sees. The Save POST stays the durable member write-through and the
 * noscript path; this file invents no server state and reads its wiring
 * from the data-js-pref markers the view rendered on the form's controls. */
'use strict';
(function (Kip) {
  if (!Kip) { return; }
  var DEFAULTS = { size: '19', typeface: 'serif', spacing: 'regular', paragraphs: 'indented', width: 'medium', mode: 'scroll' };
  var KEYS = ['size', 'typeface', 'spacing', 'paragraphs', 'width', 'mode'];
  var DEFAULT_STRING = KEYS.map(function (k) { return DEFAULTS[k]; }).join('-');

  /* The quick toggle's setter: syncs the checked swatch radio (so the text
     sheet opens agreeing with what is on screen), the root attribute, and
     the cookie. auto removes both attribute and cookie: absence IS auto,
     exactly like the server renders it. */
  Kip.setTheme = function (t) {
    var radio = Kip.$('[data-js="settings-form"] [data-js-pref="theme"][value="' + t + '"]');
    if (radio) { radio.checked = true; }
    if (t === 'auto' || t === '') {
      document.documentElement.removeAttribute('data-theme');
      Kip.cookie.set('theme', '');
    } else {
      document.documentElement.setAttribute('data-theme', t);
      Kip.cookie.set('theme', t);
    }
  };

  /* Read all seven controls, then apply: the six-segment reader cookie,
     both cookies deleted when everything is default, the data-size /
     typeface / spacing / paragraphs / width / mode and data-theme
     attributes set or removed on <html> exactly like the server, and the
     live px readout beside the slider. */
  Kip.applyPrefs = function () {
    var form = Kip.$('[data-js="settings-form"]');
    if (!form) { return; }
    var val = function (name, fallback) {
      var on = form.querySelector('[data-js-pref="' + name + '"]:checked, [data-js-pref-target="' + name + '"]');
      return on ? on.value : fallback;
    };
    var size = val('size', DEFAULTS.size), typeface = val('typeface', DEFAULTS.typeface),
        spacing = val('spacing', DEFAULTS.spacing), paragraphs = val('paragraphs', DEFAULTS.paragraphs),
        width = val('width', DEFAULTS.width), mode = val('mode', DEFAULTS.mode),
        theme = val('theme', 'auto');
    var values = { size: size, typeface: typeface, spacing: spacing, paragraphs: paragraphs, width: width, mode: mode };
    var parts = [];
    KEYS.forEach(function (k) { parts.push(values[k]); });
    Kip.cookie.set('reader', parts.join('-') === DEFAULT_STRING ? '' : parts.join('-'));
    Kip.cookie.set('theme', theme === 'auto' || theme === '' ? '' : theme);
    KEYS.forEach(function (k) {
      if (values[k] === DEFAULTS[k]) { document.documentElement.removeAttribute('data-' + k); }
      else { document.documentElement.setAttribute('data-' + k, values[k]); }
    });
    if (theme === 'auto' || theme === '') { document.documentElement.removeAttribute('data-theme'); }
    else { document.documentElement.setAttribute('data-theme', theme); }
    var out = form.querySelector('.size-readout');
    if (out) { out.textContent = size + ' px'; }
  };

  Kip.init(function () {
    if (!Kip.$('[data-js="settings-form"]')) { return; }
    /* Delegated at the document level and guarded by the form marker: the
       sheet markup may be re-laid-out by the browser, but any input or
       change bubbling out of the form means a pref moved. */
    var fromForm = function (e) {
      if (!e.target || e.target.nodeType !== 1 || !e.target.closest('[data-js="settings-form"]')) { return; }
      Kip.applyPrefs();
    };
    document.addEventListener('input', fromForm);
    document.addEventListener('change', fromForm);
  });
})(window.Kip);
