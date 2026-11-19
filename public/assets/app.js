/* Kiption enhancement layer, core. Vanilla ES2017, no dependencies, loaded
 * with defer. Everything here is progressive enhancement: pages are fully
 * usable with scripting disabled (forms POST, sheets open via :target, the
 * pager links). Feature modules live in their own files (prefs.js,
 * position.js, keys.js, infinite.js, toggle.js) and load only when their
 * module marker is present in the markup; the loader below appends them with
 * async=false so dynamic classic scripts execute in dependency order. Wiring travels in
 * data-js-* attributes set by the views; this file contains no content. */
'use strict';
(function () {
  var scriptUrl = document.currentScript && document.currentScript.src || '/assets/app.js';
  var assetBase = scriptUrl.slice(0, scriptUrl.lastIndexOf('/') + 1);
  var Kip = {
    $: function (sel, root) { return (root || document).querySelector(sel); },
    $$: function (sel, root) { return Array.prototype.slice.call((root || document).querySelectorAll(sel)); },
    cookie: {
      set: function (name, value) {
        /* Secure on HTTPS, matching the server's App\Cookie, so a control
           move never strips the attribute the server set. */
        var secure = location.protocol === 'https:' ? '; secure' : '';
        /* An empty value DELETES (the server's default-clearing economy):
           a year-long empty cookie would still make every request miss the
           static cache. */
        if (value === '') {
          document.cookie = name + '=; path=/; max-age=0; samesite=lax' + secure;
          return;
        }
        document.cookie = name + '=' + encodeURIComponent(value) + '; path=/; max-age=31536000; samesite=lax' + secure;
      }
    },
    /* The one theme setter (header toggle and Text controls alike): the
       root attribute, the cookie, and the Text sheet's swatch radio when
       that sheet is on the page, so it opens agreeing with the screen.
       auto removes attribute and cookie: absence IS auto, exactly like the
       server renders it. */
    setTheme: function (t) {
      var auto = t === 'auto' || t === '';
      var radio = Kip.$('[data-js="settings-form"] [data-js-pref="theme"][value="' + (auto ? 'auto' : t) + '"]');
      if (radio) { radio.checked = true; }
      if (auto) { document.documentElement.removeAttribute('data-theme'); }
      else { document.documentElement.setAttribute('data-theme', t); }
      Kip.cookie.set('theme', auto ? '' : t);
    },
    /* Run a module init now if the DOM is ready, else on DOMContentLoaded
       (defer scripts run before that event, but dynamically appended ones
       may land after it). */
    init: function (fn) {
      if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', fn); }
      else { fn(); }
    }
  };
  window.Kip = Kip;
  document.documentElement.classList.add('js'); /* CSS reveals JS-only affordances */
  // Remember password edits while writer helpers load, even if the author
  // clears the field again. Only this boolean is retained, never its value.
  document.addEventListener('input', function (e) {
    var field = e.target, form = field.form;
    if (field.type === 'password' && form && (' ' + form.getAttribute('data-js-module') + ' ').indexOf(' writer-status ') > -1) {
      form.kipPasswordTouched = true;
    }
  });
  // Sign-out must clear tab recovery even while its lazy helpers are pending.
  document.addEventListener('submit', function (e) {
    var form = e.target;
    if (!(form instanceof HTMLFormElement)) return;
    var submitter = e.submitter;
    var action;
    try { action = new URL((submitter && submitter.getAttribute('formaction')) || form.action, location.href); }
    catch (_) { return; }
    var method = (submitter && submitter.getAttribute('formmethod')) || form.method;
    if (action.origin !== location.origin || action.pathname !== '/auth/logout' || method.toLowerCase() !== 'post') return;
    Kip.writerLogout = true;
    try {
      var store = window.sessionStorage;
      for (var i = store.length - 1; i >= 0; i--) {
        var key = store.key(i);
        if (key && key.indexOf('kiption:writer:v1:') === 0) {
          try { store.removeItem(key); } catch (_) { /* Native logout still proceeds. */ }
        }
      }
    } catch (_) { /* Storage access must never block native logout. */ }
  }, true);
  /* Double-submission guard, core rather than a module because it serves
   * every form on the site (stories, chapters, reviews, kudos): on submit
   * the clicked button disables until the POST navigates away, so a double
   * tap on a slow phone cannot post a review twice. Scoped to UNNAMED
   * submit buttons - a named button's value is part of the payload (the
   * reader panel's reset) and must keep submitting. HTML5 validation
   * failures never reach submit; a server-side re-render brings fresh DOM;
   * pageshow (back / bfcache) re-enables whatever the restore froze. */
  Kip.init(function () {
    document.addEventListener('submit', function (e) {
      var b = e.submitter;
      if (b instanceof HTMLButtonElement && !b.name && !b.disabled) {
        b.disabled = true;
        b.setAttribute('aria-busy', 'true');
        /* A POST that never navigates (user hits Stop, a stalled
           connection) must not strand the form: the timer re-enables after
           30 seconds, and pageshow covers the back/bfcache path. */
        setTimeout(function () { b.disabled = false; b.removeAttribute('aria-busy'); }, 30000);
      }
    }, true);
    window.addEventListener('pageshow', function (e) {
      if (e.persisted) {
        Kip.$$('[aria-busy="true"]').forEach(function (b) { b.disabled = false; b.removeAttribute('aria-busy'); });
      }
    });
  });
  /* [module, helpers to load before it] - the helpers load first, in
   * order, when the page asks for the module. A box can carry several
   * modules: data-js-module splits on spaces. */
  var loaded = Object.create(null);
  function load(name) {
    if (loaded[name]) return;
    loaded[name] = true;
    var script = document.createElement('script');
    script.src = assetBase + name + '.js';
    script.async = false;
    document.head.appendChild(script);
  }
  [['prefs'], ['position'], ['keys'], ['infinite'], ['toggle'], ['sitehead'], ['chips'], ['confirm'], ['adminnav'], ['saved'], ['listfilter'], ['queue-keys'], ['adminforms'], ['heir-lookup'], ['look'],
   ['search'], ['tagchips'], ['paste'], ['editor', ['subset', 'text-edit', 'smart']], ['writer-status', ['wordcount']], ['draft-form', ['draft-store']], ['writer-comfort'], ['ed-conflict'],
   ['rich-editor', ['subset', 'source-document', 'rich-projection', 'rich-active', 'text-edit', 'smart', 'paste', 'editor']], ['epigraph-boot', ['paste', 'epigraph']]].forEach(function (entry) {
    var m = entry[0];
    var wanted = function (el) { return (' ' + el.getAttribute('data-js-module') + ' ').indexOf(' ' + m + ' ') > -1; };
    if (Array.prototype.some.call(document.querySelectorAll('[data-js-module]'), wanted)) {
      (entry[1] || []).forEach(load);
      load(m);
    }
  });
  if (document.querySelector('[data-js-module~="draft-cleanup"], form[action="/auth/logout"]')) {
    load('draft-store');
    load('draft-form');
  }
})();
