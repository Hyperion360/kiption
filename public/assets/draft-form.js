'use strict';
(function (root, factory) {
  var api = factory(root);
  if (typeof module !== 'undefined' && module.exports) module.exports = api;
  if (root.Kip) { root.Kip.draftForm = api; root.Kip.init(function () { api.init(root.document); }); }
})(typeof window !== 'undefined' ? window : globalThis, function (root) {
  var PREFIX = 'kiption:writer:v1:';
  var names = ['title', 'content', 'notes_before', 'notes_after', 'publish_at'];
  function storage() { try { return root.sessionStorage; } catch (_) { return null; } }
  function cleanup(store, owner) {
    if (!store) return false;
    try {
      var allowed = owner === undefined ? null : PREFIX + encodeURIComponent(owner) + ':';
      for (var i = store.length - 1; i >= 0; i--) {
        var key = store.key(i);
        if (key && key.startsWith(PREFIX) && (!allowed || !key.startsWith(allowed))) store.removeItem(key);
      }
      return true;
    } catch (_) { return false; }
  }
  function values(form) {
    var out = {};
    names.forEach(function (name) { var el = form.elements.namedItem(name); out[name] = el ? el.value : ''; });
    return out;
  }
  function equal(a, b) { return names.every(function (name) { return a[name] === b[name]; }); }
  function attach(form, env) {
    env = Object.assign({ window: root, store: storage(), now: Date.now,
      fetch: root.fetch ? root.fetch.bind(root) : null, api: root.Kip && root.Kip.draftStore }, env || {});
    var doc = form.ownerDocument, store = env.store, api = env.api;
    var owner = form.dataset.writerOwner, id = form.dataset.writerChapter, key = api.key(owner, id);
    var server = JSON.parse(form.dataset.writerServer), currentBase = form.dataset.writerBase;
    var labels = JSON.parse(form.dataset.writerLabels), initial = {}, pending = null, timer = null;
    var live = true, ready = true, enabled = false, armed = false, restoreReplace = false, clearFailed = false, cleanupFailed = false;
    names.forEach(function (name) { var field = form.elements.namedItem(name); initial[name] = field ? field.defaultValue : ''; });
    var password = form.elements.namedItem('access_password');
    var protectedText = form.dataset.writerProtected === '1' || !!form.kipPasswordTouched || !!(password && password.value), listeners = [], generation = 0;
    var navigation = env.window.performance && env.window.performance.getEntriesByType('navigation')[0];
    var restoredPage = !!doc.wasDiscarded || !!(navigation && navigation.type === 'back_forward');
    if (restoredPage) ready = false;
    var host = doc.createElement('section'), status = doc.createElement('p'), actions = doc.createElement('div');
    var compare = doc.createElement('details'), heading = doc.createElement('summary');
    status.setAttribute('role', 'status'); status.setAttribute('aria-live', 'polite'); status.setAttribute('aria-atomic', 'true');
    heading.textContent = labels.compare; compare.appendChild(heading);
    var archive = doc.createElement('textarea'), draft = doc.createElement('textarea');
    [[archive, labels.archive], [draft, labels.recovery]].forEach(function (pair) {
      var label = doc.createElement('label'); label.textContent = pair[1];
      pair[0].readOnly = true; pair[0].setAttribute('aria-label', pair[1]); label.appendChild(pair[0]); compare.appendChild(label);
    });
    host.className = 'writer-recovery'; host.append(status, actions, compare); form.appendChild(host);
    function button(label, fn) { var el = doc.createElement('button'); el.type = 'button'; el.textContent = label; el.addEventListener('click', fn); actions.appendChild(el); return el; }
    function on(target, type, fn, opts) { target.addEventListener(type, fn, opts); listeners.push(function () { target.removeEventListener(type, fn, opts); }); }
    function stop() { env.window.clearTimeout(timer); timer = null; generation++; }
    function say(name) { if (status.textContent !== labels[name]) status.textContent = labels[name]; }
    function erase(){var ok=api.remove(store,key);clearFailed=!ok;return ok;}
    function show() {
      actions.replaceChildren(); compare.hidden = true;
      if(cleanupFailed){say('recoveryClearFailed');return;}
      if (!live || !ready || !store) { say(clearFailed?'recoveryClearFailed':'recoveryUnavailable'); return; }
      if (protectedText) { say(clearFailed?'recoveryProtectedRetained':'recoveryProtected'); return; }
      if (!enabled) { say('recoveryOff'); button(labels.enable, enable); return; }
      button(labels.disable, disable);
      if (!pending) { say('recoveryTab'); return; }
      var conflict = pending.base !== currentBase;
      say(clearFailed?'recoveryRemoveFailed':conflict ? 'recoveryConflict' : 'recoveryOffer');
      status.appendChild(doc.createTextNode(' ' + new Date(pending.at).toLocaleString()));
      archive.value = server.content; draft.value = pending.fields.content;
      compare.hidden = false; compare.open = conflict;
      button(restoreReplace ? labels.replaceUnsaved : conflict ? labels.useDraft : labels.restore, restore);
      button(labels.discard, function () { stop(); if(!erase()){show();return;} pending = null; armed = false; restoreReplace = false; show(); say('recoveryDiscarded'); });
      button(labels.copy, function () {
        var fallback = function () { compare.open = true; draft.focus(); draft.select(); say('copyFallback'); };
        try {
          if (!env.window.navigator.clipboard) { fallback(); return; }
          env.window.navigator.clipboard.writeText(draft.value).then(function () { say('copied'); }, fallback);
        } catch (_) { fallback(); }
      });
    }
    function inspect() {
      if (!enabled || protectedText || !ready || !live) return;
      var readFailed = false;
      pending = api.read({
        getItem: function (name) { try { return store.getItem(name); } catch (error) { readFailed = true; throw error; } },
        removeItem: function () {
          // An inaccessible record is not known to be malformed or obsolete.
          if (readFailed) return;
          if (!erase()) throw Error('remove failed');
        }
      }, key, env.now());
      if (readFailed || (clearFailed && !pending)) { ready = false; show(); return; }
      if (pending && equal(pending.fields, server) && erase()) pending = null;
      show();
    }
    function enable() {
      if (!live || !ready || protectedText || !store) return;
      enabled = api.setEnabled(store, owner, true);
      if (!enabled) { say('recoveryUnavailable'); return; }
      armed = true; inspect(); resume();
    }
    function disable() { stop(); enabled = false; armed = false; pending = null; var removed=api.setEnabled(store, owner, false); show(); if(!removed)say('recoveryClearFailed'); }
    function restore() {
      if (!pending || !live || !ready) return;
      if ((form.dataset.writerRejected === '1' || !equal(values(form), initial)) && !restoreReplace) { restoreReplace = true; show(); return; }
      var record = pending; pending = null; restoreReplace = false;
      names.forEach(function (name) {
        var el = form.elements.namedItem(name); if (!el) return;
        if (name === 'content' && el.kipWriter) el.kipWriter.replace(record.fields[name], 'restore');
        else el.value = record.fields[name];
      });
      // An explicit restore of the compared record adopts only the shown server revision.
      var base = form.elements.namedItem('base_revision'); if (base) base.value = currentBase;
      armed = true; form.dispatchEvent(new env.window.Event('input', { bubbles: true })); show(); say('recoveryRestored');
    }
    function write() {
      if (!live || !ready || !enabled || protectedText || pending || !armed) return;
      var base = form.elements.namedItem('base_revision');
      if (!api.write(store, key, base ? base.value : 'new', values(form), env.now())) say('recoveryUnavailable');
      else say('recoveryWritten');
    }
    function input(e) {
      if (e.target && e.target.name === 'access_password' && e.target.value !== '') {
        protectedText = true; stop(); if (store) erase(); pending = null; show(); return;
      }
      if (!live || !ready || !enabled || protectedText || pending) return;
      armed = true; env.window.clearTimeout(timer); timer = env.window.setTimeout(write, 750);
    }
    function resume() {
      if (enabled && (form.dataset.writerRejected === '1' || !equal(values(form), initial))) input({});
    }
    async function revalidate() {
      stop(); ready = false; pending = null; show(); var ticket = generation;
      try {
        if (!env.fetch) throw Error('no fetch');
        var url = new URL(form.dataset.writerCheckUrl, env.window.location.href);
        if (url.origin !== env.window.location.origin) throw Error('origin');
        var response = await env.fetch(url.href, { credentials: 'same-origin', cache: 'no-store', redirect: 'follow' });
        var finalUrl = new URL(response.url);
        if (!response.ok || finalUrl.origin !== url.origin || finalUrl.pathname !== url.pathname) throw Error('session');
        var html = new env.window.DOMParser().parseFromString(await response.text(), 'text/html');
        var found = html.querySelector('form[data-writer-owner]');
        if (!found || found.dataset.writerOwner !== owner || found.dataset.writerChapter !== id || !/^(new|[a-f0-9]{64})$/.test(found.dataset.writerBase)) throw Error('owner');
        if (ticket !== generation || !live) return;
        currentBase = found.dataset.writerBase; server = JSON.parse(found.dataset.writerServer);
        protectedText = protectedText || found.dataset.writerProtected === '1'; ready = true;
        if (protectedText) { erase(); show(); return; }
        enabled = api.enabled(store, owner); inspect(); resume();
      } catch (_) {
        if (ticket !== generation) return;
        live = false; ready = false; if(!cleanup(store))clearFailed=true; show();
      }
    }
    if(store&&!cleanup(store, owner)){cleanupFailed=true;ready=false;}
    enabled = !!store && api.enabled(store, owner);
    if (protectedText) { if (store) erase(); }
    else if (enabled) inspect();
    show();
    on(form, 'input', input); on(form, 'change', input);
    on(form, 'submit', function () { stop(); write(); });
    on(env.window, 'pagehide', function () { stop(); write(); });
    on(env.window, 'pageshow', function (e) { if (e.persisted) revalidate(); });
    on(doc, 'submit', function (e) {
      if (e.target instanceof env.window.HTMLFormElement && new URL(e.target.action).pathname === '/auth/logout') {
        stop(); live = false; enabled = false; if(!cleanup(store))clearFailed=true; show();
      }
    }, true);
    if (restoredPage) revalidate(); else resume();
    return { host: host, revalidate: revalidate, destroy: function () { stop(); live = false; listeners.forEach(function (off) { off(); }); host.remove(); } };
  }
  function init(doc) {
    if (doc.querySelector('[data-js-module~="draft-cleanup"]')) cleanup(storage());
    if (!doc.kipDraftCleanup) {
      doc.kipDraftCleanup = true;
      doc.addEventListener('submit', function (event) {
        if (event.target instanceof root.HTMLFormElement && new URL(event.target.action, root.location.href).pathname === '/auth/logout') cleanup(storage());
      }, true);
    }
    if (!root.Kip || !root.Kip.draftStore) return;
    doc.querySelectorAll('form[data-js-module~="draft-form"]').forEach(function (form) {
      if (form.kipDraft) return;
      try { form.kipDraft = attach(form); } catch (_) { /* Native form remains usable. */ }
    });
  }
  return { attach: attach, cleanup: cleanup, values: values, equal: equal, init: init };
});
