'use strict';
(function (root, factory) {
  var api = factory(root);
  if (typeof module !== 'undefined' && module.exports) module.exports = api;
  if (root.Kip) { root.Kip.draftForm = api; root.Kip.init(function () { api.init(root.document); }); }
})(typeof window !== 'undefined' ? window : globalThis, function (root) {
  var PREFIX = 'kiption:writer:v1:';
  var DEFAULT_NAMES = ['title', 'content', 'notes_before', 'notes_after', 'publish_at'];
  // The new-story page posts more fields than a chapter, and names them in data-writer-fields.
  function fieldNames(form) {
    try { var list = JSON.parse(form.dataset.writerFields || 'null'); if (Array.isArray(list) && list.length && list.every(function (n) { return typeof n === 'string'; })) return list; } catch (_) { /* Fall back to the chapter's five. */ }
    return DEFAULT_NAMES;
  }
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
  function isLogout(event, win) {
    if (!(event.target instanceof win.HTMLFormElement)) return false;
    try {
      var submitter = event.submitter, form = event.target;
      var action = new URL((submitter && submitter.getAttribute('formaction')) || form.action, win.location.href);
      var method = (submitter && submitter.getAttribute('formmethod')) || form.method;
      return action.origin === new URL(win.location.href).origin && action.pathname === '/auth/logout' && method.toLowerCase() === 'post';
    } catch (_) { return false; }
  }
  function values(form, names) {
    names = names || DEFAULT_NAMES;
    var out = {};
    names.forEach(function (name) { var el = form.elements.namedItem(name); out[name] = el ? el.value : ''; });
    return out;
  }
  function equal(a, b, names) { return (names || DEFAULT_NAMES).every(function (name) { return a[name] === b[name]; }); }
  function attach(form, env) {
    env = Object.assign({ window: root, store: storage(), now: Date.now,
      fetch: root.fetch ? root.fetch.bind(root) : null, api: root.Kip && root.Kip.draftStore }, env || {});
    var doc = form.ownerDocument, store = env.store, api = env.api;
    var names = fieldNames(form);
    var owner = form.dataset.writerOwner, id = form.dataset.writerChapter, key = api.key(owner, id);
    var server = JSON.parse(form.dataset.writerServer), currentBase = form.dataset.writerBase;
    var labels = JSON.parse(form.dataset.writerLabels), initial = {}, pending = null, timer = null;
    var live = true, ready = true, checking = false, enabled = false, armed = false, restoreReplace = false, clearFailed = false, cleanupFailed = false;
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
    host.className = 'writer-recovery'; host.append(status, actions, compare);
    // The page may give recovery a slot of its own (the editor's banner spot); otherwise it joins the options panel or the form.
    ((form.querySelector && (form.querySelector('[data-writer-recovery-slot]') || form.querySelector('[data-writer-panel-body]'))) || form).appendChild(host);
    function button(label, fn, act) { var el = doc.createElement('button'); el.type = 'button'; el.textContent = label; if (act) el.dataset.act = act; el.addEventListener('click', fn); actions.appendChild(el); return el; }
    function on(target, type, fn, opts) { target.addEventListener(type, fn, opts); listeners.push(function () { target.removeEventListener(type, fn, opts); }); }
    function stop() { env.window.clearTimeout(timer); timer = null; checking = false; generation++; }
    // Restoring or discarding closes the offer, so its one-line result stays on screen (data-notice) until the next change.
    function say(name) { if (status.textContent !== labels[name]) status.textContent = labels[name]; if (name === 'recoveryRestored' || name === 'recoveryDiscarded') host.dataset.notice = '1'; }
    function erase(){var ok=api.remove(store,key);clearFailed=!ok;return ok;}
    // The page styles these: a banner only while a copy is on offer, and the More menu reads the switch.
    function mark() {
      var locked = cleanupFailed || !live || !store || !ready || protectedText;
      host.dataset.recovery = locked ? 'locked' : enabled ? 'on' : 'off';
      host.dataset.offer = !locked && enabled && pending ? '1' : '';
    }
    function show() { paint(); mark(); }
    function paint() {
      actions.replaceChildren(); compare.hidden = true; host.dataset.notice = '';
      if(cleanupFailed){say('recoveryClearFailed');return;}
      if (!live || !store) { say(clearFailed?'recoveryClearFailed':'recoveryUnavailable'); return; }
      if (!ready) {
        say(clearFailed ? 'recoveryClearFailed' : 'recoveryUnavailable');
        if (pending && !protectedText) offer(false, false);
        if (!checking) button(labels.retry, revalidate);
        return;
      }
      if (protectedText) { say(clearFailed?'recoveryProtectedRetained':'recoveryProtected'); return; }
      if (!enabled) { say('recoveryOff'); button(labels.enable, enable); return; }
      button(labels.disable, disable, 'disable');
      if (!pending) { say('recoveryTab'); return; }
      var conflict = pending.base !== currentBase;
      say(clearFailed?'recoveryRemoveFailed':conflict ? 'recoveryConflict' : 'recoveryOffer');
      offer(true, conflict);
    }
    function words(text) { var m = String(text).trim().match(/\S+/g); return m ? m.length : 0; }
    function when(at) {
      var d = new Date(at), now = new Date(env.now());
      var sameDay = d.getFullYear() === now.getFullYear() && d.getMonth() === now.getMonth() && d.getDate() === now.getDate();
      return sameDay ? d.toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' }) : d.toLocaleString([], { month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit' });
    }
    // The banner's own wording: a title, then when the copy was kept and how much of it the page lacks.
    function offerText() {
      var extra = Math.max(0, words(pending.fields.content) - words(server.content));
      var text = extra === 0 ? labels.recoveryOfferDetailSame : extra === 1 ? labels.recoveryOfferDetailOne : labels.recoveryOfferDetail;
      return String(text || '').replace('{time}', when(pending.at)).replace('{n}', String(extra));
    }
    function offer(allowRestore, conflict) {
      if (allowRestore && !conflict && labels.recoveryOfferDetail) {
        var title = doc.createElement('strong'), detail = doc.createElement('span');
        title.textContent = labels.recoveryOffer; detail.textContent = offerText();
        status.textContent = ''; status.append(title, detail);
      } else {
        status.appendChild(doc.createTextNode(' ' + new Date(pending.at).toLocaleString()));
      }
      archive.value = server.content; draft.value = pending.fields.content;
      compare.hidden = false; compare.open = !allowRestore || conflict;
      if (allowRestore) button(restoreReplace ? labels.replaceUnsaved : conflict ? labels.useDraft : labels.restore, restore, 'restore');
      button(labels.copy, function () {
        var fallback = function () { compare.open = true; draft.focus(); draft.select(); say('copyFallback'); };
        try {
          if (!env.window.navigator.clipboard) { fallback(); return; }
          env.window.navigator.clipboard.writeText(draft.value).then(function () { say('copied'); }, fallback);
        } catch (_) { fallback(); }
      }, 'copy');
      button(labels.discard, function () { stop(); if(!erase()){show();return;} pending = null; armed = false; restoreReplace = false; show(); say('recoveryDiscarded'); }, 'discard');
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
      }, key, env.now(), names);
      if (readFailed || (clearFailed && !pending)) { ready = false; show(); return; }
      if (pending && equal(pending.fields, server, names) && erase()) pending = null;
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
      if ((form.dataset.writerRejected === '1' || !equal(values(form, names), initial, names)) && !restoreReplace) { restoreReplace = true; show(); return; }
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
      if (!api.write(store, key, base ? base.value : 'new', values(form, names), env.now(), names)) say('recoveryUnavailable');
      else say('recoveryWritten');
    }
    function input(e) {
      if (e.target && e.target.name === 'access_password' && e.target.value !== '') {
        protectedText = true; stop(); if (store) erase(); pending = null; show(); return;
      }
      host.dataset.notice = '';
      if (!live || !ready || !enabled || protectedText || pending) return;
      armed = true; env.window.clearTimeout(timer); timer = env.window.setTimeout(write, 750);
    }
    function resume() {
      if (enabled && (form.dataset.writerRejected === '1' || !equal(values(form, names), initial, names))) input({});
    }
    function endSession(nextOwner) {
      stop(); live = false; ready = false; enabled = false; armed = false; pending = null;
      if (!cleanup(store, nextOwner)) clearFailed = true;
      show();
    }
    async function revalidate() {
      if (!live) return;
      stop(); ready = false; checking = true; show(); var ticket = generation;
      try {
        if (!env.fetch) throw Error('no fetch');
        var url = new URL(form.dataset.writerCheckUrl, env.window.location.href);
        if (url.origin !== env.window.location.origin) throw Error('origin');
        var response = await env.fetch(url.href, { credentials: 'same-origin', cache: 'no-store', redirect: 'follow' });
        var finalUrl = new URL(response.url);
        if (!response.ok || finalUrl.origin !== url.origin) throw Error('response');
        var html = new env.window.DOMParser().parseFromString(await response.text(), 'text/html');
        if (ticket !== generation || !live) return;
        if (finalUrl.pathname === '/auth/login' && html.querySelector('[data-js-module~="draft-cleanup"] form[action="/auth/attempt"]')) {
          endSession(); return;
        }
        if (finalUrl.pathname !== url.pathname) throw Error('path');
        var found = html.querySelector('form[data-writer-owner]');
        if (!found || !/^[1-9][0-9]*$/.test(found.dataset.writerOwner) || found.dataset.writerChapter !== id || !/^(new|[a-f0-9]{64})$/.test(found.dataset.writerBase)) throw Error('identity');
        var latest = JSON.parse(found.dataset.writerServer);
        if (!latest || Object.keys(latest).length !== names.length || !names.every(function (name) { return typeof latest[name] === 'string'; })) throw Error('fields');
        if (found.dataset.writerOwner !== owner) { endSession(found.dataset.writerOwner); return; }
        cleanupFailed = !!store && !cleanup(store, owner);
        if (cleanupFailed) { ready = false; checking = false; show(); return; }
        currentBase = found.dataset.writerBase; server = latest;
        protectedText = protectedText || found.dataset.writerProtected === '1'; ready = true; checking = false;
        if (protectedText) { erase(); pending = null; show(); return; }
        enabled = api.enabled(store, owner); inspect(); resume();
      } catch (_) {
        if (ticket !== generation || !live) return;
        // An unavailable check is not evidence of logout. Keep known copies
        // accessible, but suspend restoration and writes until a retry succeeds.
        ready = false; checking = false; show();
      }
    }
    if(!restoredPage&&store&&!cleanup(store, owner)){cleanupFailed=true;ready=false;}
    enabled = !!store && api.enabled(store, owner);
    if (protectedText) { if (store && ready) erase(); }
    else if (enabled) inspect();
    show();
    on(form, 'input', input); on(form, 'change', input);
    on(form, 'submit', function () { stop(); write(); if (!ready) show(); });
    on(env.window, 'pagehide', function () { stop(); write(); });
    on(env.window, 'pageshow', function (e) { if (e.persisted) revalidate(); });
    on(doc, 'submit', function (e) {
      if (isLogout(e, env.window)) endSession();
    }, true);
    if (restoredPage) revalidate(); else resume();
    return { host: host, enable: enable, disable: disable, revalidate: revalidate, destroy: function () { stop(); live = false; listeners.forEach(function (off) { off(); }); host.remove(); } };
  }
  function init(doc) {
    if (doc.querySelector('[data-js-module~="draft-cleanup"]')) cleanup(storage());
    if (!doc.kipDraftCleanup) {
      doc.kipDraftCleanup = true;
      doc.addEventListener('submit', function (event) {
        if (isLogout(event, root)) cleanup(storage());
      }, true);
    }
    if (!root.Kip || !root.Kip.draftStore || root.Kip.writerLogout) return;
    doc.querySelectorAll('form[data-js-module~="draft-form"]').forEach(function (form) {
      if (form.kipDraft) return;
      try { form.kipDraft = attach(form); } catch (_) { /* Native form remains usable. */ }
    });
  }
  return { attach: attach, cleanup: cleanup, values: values, equal: equal, init: init };
});
