/* Optional writing layout and caret scrolling. Preferences stay on this page. */
'use strict';
(function (root, factory) {
  var api = factory(root);
  if (typeof module !== 'undefined' && module.exports) module.exports = api;
  if (root.Kip) { root.Kip.writerComfort = api; root.Kip.init(function () { api.init(root.document); }); }
})(typeof window !== 'undefined' ? window : globalThis, function (root) {
  function scrollTarget(state) {
    if (!state.enabled || state.composing || (state.paused && !state.inserted)) return null;
    var minimum = state.minimum === undefined ? 0 : state.minimum;
    var maximum = state.maximum === undefined ? state.viewport : state.maximum;
    if (maximum < minimum) return null;
    var destination = Math.min(maximum, Math.max(minimum, state.viewport * 0.5));
    return Math.max(0, state.scroll + state.caret - destination);
  }
  function attach(form, env) {
    var doc = form.ownerDocument, box = form.elements.namedItem('content');
    var labels = JSON.parse(form.dataset.writerLabels || '{}');
    if (!box || typeof labels.focus !== 'string' || typeof labels.typewriter !== 'string') throw new Error('Missing writing controls');
    env = env || doc.defaultView || root;
    var enabled = false, paused = false, composition = null, frame = 0, listeners = [];
    var bar = doc.createElement('div'), mirror = doc.createElement('div');
    var prefix = doc.createTextNode(''), marker = doc.createElement('span'), suffix = doc.createTextNode('\u200b');
    bar.className = 'writer-comfort';
    mirror.className = 'writer-caret-mirror'; mirror.setAttribute('aria-hidden', 'true');
    marker.appendChild(suffix); mirror.append(prefix, marker);
    function on(target, type, fn) { target.addEventListener(type, fn); listeners.push(function () { target.removeEventListener(type, fn); }); }
    // Formatted mode edits a contenteditable beside the hidden field, so the caret lives there.
    function richSurface() {
      var a = box.kipEpigraph; if (!a || typeof a.mode !== 'function' || a.mode() !== 'rich') return null;
      var wrap = box.nextElementSibling; return wrap && wrap.querySelector ? wrap.querySelector('.ProseMirror') : null;
    }
    function current() { return box.kipWriter ? box.kipWriter.activeControl : (richSurface() || box); }
    function text(el) { return el.tagName === 'TEXTAREA' ? el.value : el.textContent; }
    function pause() { paused = true; if (frame) { env.cancelAnimationFrame(frame); frame = 0; } }
    function button(label, toggle) {
      var node = doc.createElement('button'); node.type = 'button'; node.textContent = label; node.setAttribute('aria-pressed', 'false');
      on(node, 'click', function () { node.setAttribute('aria-pressed', String(toggle())); }); bar.append(node);
    }
    button(labels.typewriter, function () { enabled = !enabled; pause(); return enabled; });
    button(labels.focus, function () { return form.classList.toggle('writer-focus'); });
    // Keyboard and Esc paths flip focus mode too; the button must follow.
    function setFocus(on) {
      form.classList[on ? 'add' : 'remove']('writer-focus');
      if (bar.children[1]) bar.children[1].setAttribute('aria-pressed', String(!!on));
    }
    function lineHeight(style) { return parseFloat(style.lineHeight) || (parseFloat(style.fontSize) || 16) * 1.2; }
    function textareaCaret(el) {
      var style = env.getComputedStyle(el), rect = el.getBoundingClientRect();
      ['boxSizing', 'fontFamily', 'fontSize', 'fontWeight', 'fontStyle', 'fontVariant', 'fontStretch', 'letterSpacing', 'lineHeight',
        'paddingTop', 'paddingRight', 'paddingBottom', 'paddingLeft', 'borderTopWidth', 'borderRightWidth', 'borderBottomWidth', 'borderLeftWidth',
        'borderTopStyle', 'borderRightStyle', 'borderBottomStyle', 'borderLeftStyle', 'wordSpacing', 'wordBreak', 'textIndent', 'tabSize', 'textTransform'].forEach(function (name) { mirror.style[name] = style[name]; });
      var width = el.clientWidth || rect.width;
      width += style.boxSizing === 'border-box'
        ? (parseFloat(style.borderLeftWidth) || 0) + (parseFloat(style.borderRightWidth) || 0)
        : -(parseFloat(style.paddingLeft) || 0) - (parseFloat(style.paddingRight) || 0);
      mirror.style.width = width + 'px'; mirror.style.whiteSpace = el.wrap === 'off' ? 'pre' : 'pre-wrap'; mirror.style.overflowWrap = style.overflowWrap;
      var offset = el.selectionDirection === 'backward' ? el.selectionStart : el.selectionEnd;
      prefix.data = el.value.slice(0, offset);
      // Keep the rest of the word so the caret fragment wraps with the full source.
      suffix.data = el.value.slice(offset) || '\u200b';
      var relative = marker.getBoundingClientRect().top - mirror.getBoundingClientRect().top - el.scrollTop;
      var height = lineHeight(style), top = parseFloat(style.borderTopWidth) || 0;
      // Scroll inside the textarea before moving the page. Native scrollTop clamps at zero.
      if (relative < top) { el.scrollTop = Math.max(0, el.scrollTop + relative - top); relative = Math.max(top, relative); }
      else if (relative > top + el.clientHeight - height) {
        var amount = relative - top - el.clientHeight + height; el.scrollTop += amount; relative -= amount;
      }
      return { y: rect.top + relative, height: height };
    }
    function caret(el) {
      if (el.tagName === 'TEXTAREA') return textareaCaret(el);
      var selection = doc.getSelection();
      if (selection && el.contains(selection.focusNode)) {
        var range = doc.createRange(); range.setStart(selection.focusNode, selection.focusOffset); range.collapse(true);
        var rect = range.getBoundingClientRect(); if (rect.height) return { y: rect.top, height: rect.height };
        // An empty line has no caret rectangle of its own: use the line's box.
        var line = selection.focusNode.nodeType === 1 ? selection.focusNode : selection.focusNode.parentElement;
        if (line && el !== box) { var lb = line.getBoundingClientRect(); return { y: lb.top, height: lineHeight(env.getComputedStyle(line)) }; }
      }
      return el === box || !richSurface() ? textareaCaret(box) : null;
    }
    function measure() {
      frame = 0; if (!enabled || composition || paused) return;
      var point = caret(current()), viewport = env.visualViewport; if (!point) return;
      var offset = viewport ? viewport.offsetTop : 0, height = viewport ? viewport.height : env.innerHeight;
      var sticky = form.querySelector('.ed-head');
      var minimum = Math.max(0, sticky ? sticky.getBoundingClientRect().bottom - offset : 0) + 16;
      var target = scrollTarget({ enabled: enabled, composing: false, paused: paused, inserted: true,
        caret: point.y - offset, viewport: height, scroll: env.scrollY, minimum: minimum, maximum: height - point.height - 8 });
      if (target !== null) env.scrollTo({ top: target, behavior: 'auto' });
    }
    function schedule() { if (!enabled || composition) return; paused = false; if (!frame) frame = env.requestAnimationFrame(measure); }
    on(form, 'input', function (e) {
      if (e.target !== current()) return;
      if (!e.isComposing && /^insert/.test(e.inputType || '')) schedule();
      else if (!e.isComposing) pause();
    });
    // Formatted edits synchronize the named source input, then identify the
    // committed active control without sending a second form input event.
    on(form, 'writer-commit', function (e) {
      if (e.target !== box || !box.kipWriter || !e.detail || e.detail.control !== current()) return;
      if (e.detail.inserted && e.detail.kind !== 'history') schedule(); else pause();
    });
    on(form, 'compositionstart', function (e) { if (e.target === current()) { pause(); composition = { target: e.target, before: text(e.target) }; } });
    on(form, 'compositionend', function (e) {
      if (!composition || e.target !== composition.target) return;
      var changed = text(e.target) !== composition.before; composition = null; if (changed) schedule();
    });
    on(env, 'wheel', pause); on(env, 'touchmove', pause); on(env, 'pointerdown', pause);
    on(env, 'keydown', function (e) { if (['PageUp', 'PageDown', 'Home', 'End', 'ArrowUp', 'ArrowDown', 'ArrowLeft', 'ArrowRight'].includes(e.key)) pause(); });
    doc.body.appendChild(mirror);
    (box.closest('label') || box).insertAdjacentElement('beforebegin', bar);
    return { bar: bar, setFocus: setFocus, isFocus: function () { return form.classList.contains('writer-focus'); }, destroy: function () {
      pause(); listeners.forEach(function (off) { off(); }); form.classList.remove('writer-focus'); bar.remove(); mirror.remove();
    } };
  }
  function pad(n) { return (n < 10 ? '0' : '') + n; }
  // The server keeps schedules in UTC. Writers see and pick their own time.
  function utcToLocal(utc) {
    var d = /^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/.test(utc || '') ? new Date(utc + ':00Z') : null;
    if (!d || isNaN(d.getTime())) return '';
    return d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate()) + 'T' + pad(d.getHours()) + ':' + pad(d.getMinutes());
  }
  function localToUtc(local) {
    var d = /^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/.test(local || '') ? new Date(local) : null;
    return !d || isNaN(d.getTime()) ? '' : d.toISOString().slice(0, 16);
  }
  /* The publish popover shows a date and a time; the named field carries one UTC value. */
  function composeSchedule(date, time) { return date && time ? localToUtc(date + 'T' + time) : ''; }
  function splitSchedule(utc) { var l = utcToLocal(utc); return l ? { date: l.slice(0, 10), time: l.slice(11, 16) } : { date: '', time: '' }; }
  function isPast(utc, now) { return utc === '' ? false : utc <= new Date(now === undefined ? Date.now() : now).toISOString().slice(0, 16); }
  /* Which access fields the form posts for the chosen audience. Everyone on a protected chapter asks the server to clear the password. */
  function accessFields(audience, protectedNow, password) {
    if (audience === 'password') return { clear: false, password: password };
    return { clear: !!protectedNow, password: '' };
  }
  /* Whether the writer may press the go button. */
  function canPublish(s) {
    if (s.story && (!s.storyTitle || !s.ratingChosen || s.bodyFilled === false)) return false;
    if (s.when === 'later') { if (!s.utc || isPast(s.utc, s.now)) return false; }
    if (s.audience === 'password' && !s.protectedNow && !s.password) return false;
    return true;
  }
  /* A draft only needs a rating. The story's title falls back to the chapter's on the server. */
  function canDraft(s) { return !s.story || !!s.ratingChosen; }
  /* The first popover row still missing an answer, or an empty string. */
  function firstMissing(s) {
    if (s.bodyFilled === false) return 'body';
    if (!s.storyTitle) return 'story';
    if (!s.ratingChosen) return 'rating';
    return '';
  }
  /* One-time copy of the old New Story page's local draft into an empty new-story form; the key is always removed. */
  function adoptOldDraft(form, ls, storyTitle, box) {
    try {
      var old = JSON.parse(ls.getItem('kip:new-story-draft') || 'null');
      if (old && storyTitle && !storyTitle.value && !(box && box.value)) {
        ['title', 'summary', 'notes', 'language'].forEach(function (n) { var el = form.elements.namedItem(n); if (el && typeof old[n] === 'string') el.value = old[n]; });
        var rate = old.rating_id ? form.querySelector('input[name="rating_id"][value="' + String(old.rating_id).replace(/[^0-9]/g, '') + '"]') : null;
        if (rate) rate.checked = true;
      }
      if (ls.getItem('kip:new-story-draft') !== null) ls.removeItem('kip:new-story-draft');
    } catch (_) { /* No storage, nothing to adopt. */ }
  }
  /* Esc closes the innermost thing first: link box, menu, dialog, panel, then Focus. */
  function escTarget(open) {
    var order = ['link', 'menu', 'dialog', 'panel', 'focus'];
    for (var i = 0; i < order.length; i++) if (open[order[i]]) return order[i];
    return null;
  }
  /* After Esc closes a panel, focus goes back to its toggle only if it was inside the panel (or nowhere). A writer typing in the page keeps the caret. */
  function refocusToggle(active, panelEl, body) {
    return !active || active === body || !!(panelEl && panelEl.contains && panelEl.contains(active));
  }
  function shortcutAction(e) {
    if (!(e.ctrlKey || e.metaKey) || e.altKey) return null;
    var key = String(e.key || '').toLowerCase();
    if (key === '/' && !e.shiftKey) return 'source';
    if (key === 'f' && e.shiftKey) return 'focus';
    return null;
  }
  var SETTINGS_KEY = 'kiption-editor-settings-v1';
  var DEFAULTS = { brk: 'squares', para: 'indented', face: 'serif', width: 'medium', size: 20, smart: true };
  var CHOICES = { brk: ['squares', 'asterism', 'asterisks'], para: ['indented', 'spaced'], face: ['serif', 'sans', 'mono'], width: ['narrow', 'medium', 'wide'] };
  function cleanSettings(raw) {
    var out = {}, src = raw && typeof raw === 'object' ? raw : {};
    Object.keys(CHOICES).forEach(function (k) { out[k] = CHOICES[k].indexOf(src[k]) >= 0 ? src[k] : DEFAULTS[k]; });
    var size = Math.round(Number(src.size));
    out.size = size >= 16 && size <= 26 ? size : DEFAULTS.size;
    out.smart = !(src.smart === false || src.smart === 'false');
    return out;
  }
  function loadSettings(storage) { try { return cleanSettings(JSON.parse(storage.getItem(SETTINGS_KEY))); } catch (_) { return cleanSettings(null); } }
  function saveSettings(storage, settings) { try { storage.setItem(SETTINGS_KEY, JSON.stringify(cleanSettings(settings))); return true; } catch (_) { return false; } }
  function fill(text, args) { return String(text).replace(/\{(\w+)\}/g, function (m, k) { return args[k] === undefined ? m : args[k]; }); }

  /* The editor page shell: panels, menus, the publish popover, preview and settings. */
  function editor(form, doc, env, comfort) {
    var ed = JSON.parse(form.dataset.edLabels || 'null'), writer = JSON.parse(form.dataset.writerLabels || '{}');
    if (!ed) return null;
    function $(sel) { return form.querySelector(sel); }
    function $$(sel) { return Array.prototype.slice.call(form.querySelectorAll(sel)); }
    var storyMode = form.dataset.edMode === 'new-story' || form.dataset.edMode === 'draft-story';
    var box = form.elements.namedItem('content'), title = form.elements.namedItem(form.dataset.edTitleField || 'title');
    var live = form.dataset.edState !== 'draft', token = form.elements.namedItem('_token');
    var protectedNow = form.dataset.writerProtected === '1';
    var field = $('[data-writer-schedule]'), clear = form.elements.namedItem('access_clear'), password = form.elements.namedItem('access_password');
    // In the story modes those two names belong to the story's own password in More Options, never to a chapter audience.
    if (storyMode) { clear = null; password = null; form.noValidate = true; }
    var storyTitle = storyMode ? $('[data-ed-story-title]') : null;
    // The old New Story page kept a copy of its fields on the device. Adopt it once into an empty form, then forget it.
    if (form.dataset.edMode === 'new-story') adoptOldDraft(form, env.localStorage, storyTitle, box);
    var pub = $('[data-ed-pub]'), scrim = $('[data-ed-scrim]'), menu = $('#ed-menu'), moreBtn = $('[data-ed-more]'), primary = $('[data-ed-primary]');
    var go = $('[data-ed-go]'), toast = $('[data-ed-toast]'), del = $('[data-ed-delete]');
    var contents = $('#ed-contents'), manuscript = $('#ed-manuscript');
    var settings = loadSettings((function () { try { return env.localStorage; } catch (_) { return null; } })() || { getItem: function () { return null; }, setItem: function () {} });
    var store = (function () { try { return env.localStorage; } catch (_) { return null; } })();
    var panelOrder = [], previewing = false, opener = null, whenMode = 'now', audience = protectedNow ? 'password' : 'everyone', whenTouched = false, toastTimer = 0;
    function api() { return box && box.kipEpigraph; }
    function note(name) { return $('[data-ed-note="' + name + '"]'); }

    // Settings: data attributes on the form, mirrored in the radio groups.
    function applySettings() {
      form.dataset.brk = settings.brk; form.dataset.para = settings.para; form.dataset.face = settings.face; form.dataset.width = settings.width; form.dataset.size = String(settings.size);
      $$('[data-setting]').forEach(function (b) { b.setAttribute('aria-checked', String(String(settings[b.dataset.setting]) === b.dataset.value)); });
      var label = $('[data-ed-size-label]'); if (label) label.textContent = fill(ed.size, { n: settings.size });
      var a = api(); if (a && a.setSmartPunctuation) a.setSmartPunctuation(settings.smart);
    }
    function setSetting(key, value) { settings[key] = value; settings = cleanSettings(settings); applySettings(); saveSettings(store || { setItem: function () {} }, settings); }
    $$('[data-setting]').forEach(function (b) { b.addEventListener('click', function () { setSetting(b.dataset.setting, b.dataset.value); }); });
    $$('[data-ed-size]').forEach(function (b) { b.addEventListener('click', function () { setSetting('size', settings.size + Number(b.dataset.edSize)); }); });
    applySettings();

    // Theme: swatches, the header toggle and the background post.
    var htmlEl = doc.documentElement;
    function currentTheme() { return htmlEl.getAttribute('data-theme') || 'auto'; }
    function paintTheme() {
      $$('[data-theme-value]').forEach(function (b) { b.setAttribute('aria-checked', String(b.dataset.themeValue === currentTheme())); });
      var name = ed.themeNames[currentTheme()] || ed.themeNames.auto, l = $('[data-ed-theme-label]'); if (l) l.textContent = name;
      var tt = $('[data-ed-theme-toggle]'); if (tt) tt.setAttribute('aria-label', ed.themeLabel + ': ' + name);
    }
    function setTheme(value) {
      if (value === 'auto') htmlEl.removeAttribute('data-theme'); else htmlEl.setAttribute('data-theme', value);
      if (env.Kip && env.Kip.cookie) env.Kip.cookie.set('theme', value === 'auto' ? '' : value);
      paintTheme();
      if (!token) return;
      var data = new env.FormData(); data.set('theme', value); data.set('_token', token.value); data.set('return_to', env.location.pathname);
      env.fetch('/reader/settings', { method: 'POST', body: data, credentials: 'same-origin', redirect: 'manual' }).catch(function () {});
    }
    $$('[data-theme-value]').forEach(function (b) { b.addEventListener('click', function () { setTheme(b.dataset.themeValue); }); });
    var themeToggle = $('[data-ed-theme-toggle]'); if (themeToggle) themeToggle.addEventListener('click', function () { var order = ['paper', 'sepia', 'night', 'auto']; setTheme(order[(order.indexOf(currentTheme()) + 1) % order.length]); });
    paintTheme();

    // Overlay bookkeeping: the scrim shows for dialogs, and for panels on phones.
    function narrow() { return !!(env.matchMedia && env.matchMedia('(max-width: 52rem)').matches); }
    function syncScrim() {
      var dialog = (pub && pub.hasAttribute('data-open')) || (del && !del.hidden);
      var panel = panelOrder.length > 0 && narrow();
      if (scrim) scrim.hidden = !(dialog || panel);
    }
    function say(text) {
      if (!toast) return; toast.textContent = text; toast.hidden = false;
      env.clearTimeout(toastTimer); toastTimer = env.setTimeout(function () { toast.hidden = true; }, 2600);
    }

    // Focus mode: only the paragraph holding the caret stays at full strength; the word count rides the top bar.
    (function focusDim() {
      var fw = $('[data-ed-focus-words]'), out = $('[data-writer-count]');
      if (fw && out) { var copy = function () { fw.textContent = out.textContent; }; copy(); if (root.MutationObserver) new root.MutationObserver(copy).observe(out, { childList: true, characterData: true, subtree: true }); }
      var lit = null;
      function mark() {
        if (!form.classList.contains('writer-focus')) { if (lit) lit.textContent = ''; return; }
        var pm = form.querySelector('.ProseMirror'); if (!pm) return;
        var sel = doc.getSelection && doc.getSelection(), n = sel && sel.anchorNode, cur = null;
        if (n && pm.contains(n)) { while (n && n.parentNode !== pm) n = n.parentNode; if (n && n.nodeType === 1) cur = n; }
        if (!cur) cur = pm.firstElementChild;
        // ProseMirror strips foreign classes from its nodes, so the lit block is named by position in a style rule.
        var idx = cur ? Array.prototype.indexOf.call(pm.children, cur) + 1 : 0;
        if (!lit) { lit = doc.createElement('style'); lit.setAttribute('data-ed-lit', ''); doc.head.appendChild(lit); }
        lit.textContent = idx ? '.ed.writer-focus .epigraph .ProseMirror > :nth-child(' + idx + ') { opacity: 1; }' : '';
      }
      doc.addEventListener('selectionchange', mark);
      if (root.MutationObserver) new root.MutationObserver(function () { mark(); }).observe(form, { attributes: true, attributeFilter: ['class'] });
    })();
    // Panels.
    function panel(name, open) {
      var el = name === 'contents' ? contents : manuscript, btn = $('[data-ed-panel="' + name + '"]');
      if (!el) return;
      // On a phone a panel slides over the header toggles, so only one can be open.
      if (open && env.matchMedia && env.matchMedia('(max-width: 52rem)').matches) { var other = name === 'contents' ? 'manuscript' : 'contents'; if (panelOrder.indexOf(other) >= 0) panel(other, false); }
      el.hidden = !open; if (btn) btn.setAttribute('aria-pressed', String(open));
      panelOrder = panelOrder.filter(function (n) { return n !== name; }); if (open) panelOrder.push(name);
      syncScrim();
    }
    $$('[data-ed-panel]').forEach(function (b) { b.addEventListener('click', function () { panel(b.dataset.edPanel, b.getAttribute('aria-pressed') !== 'true'); }); });
    $$('[data-ed-panel-close]').forEach(function (b) { b.addEventListener('click', function () { panel(b.dataset.edPanelClose, false); }); });
    // The design opens the chapter list beside the text on a wide screen.
    if (contents && env.innerWidth >= 1180) panel('contents', true);

    // The More menu.
    function menuOpen() { return !!menu && !menu.hidden; }
    function recoveryHost() { return form.kipDraft && form.kipDraft.host; }
    function paintRecovery() {
      var item = $('[data-ed-act="recovery"]'); if (!item) return;
      var host = recoveryHost(), mode = host ? host.dataset.recovery : 'locked', on = mode === 'on';
      item.setAttribute('aria-checked', String(on)); item.disabled = mode === 'locked';
      var s = $('[data-ed-rec-state]'); if (s) s.textContent = on ? ed.on : ed.off;
    }
    function openMenu(open) {
      if (!menu) return; menu.hidden = !open; moreBtn.setAttribute('aria-expanded', String(open));
      if (open) { paintRecovery(); var first = menu.querySelector('button:not([disabled]),a[href]'); if (first) first.focus(); } 
    }
    if (moreBtn) moreBtn.addEventListener('click', function () { openMenu(!menuOpen()); });
    doc.addEventListener('click', function (e) { if (menuOpen() && !(menu.contains(e.target) || moreBtn.contains(e.target))) openMenu(false); });
    if (menu) menu.addEventListener('keydown', function (e) {
      var items = Array.prototype.slice.call(menu.querySelectorAll('button:not([disabled]),a[href]')), i = items.indexOf(doc.activeElement);
      if (e.key === 'ArrowDown') { e.preventDefault(); items[(i + 1) % items.length].focus(); }
      else if (e.key === 'ArrowUp') { e.preventDefault(); items[(i - 1 + items.length) % items.length].focus(); }
      else if (e.key === 'Tab') openMenu(false);
    });

    // Author notes appear on demand.
    function labelNotes() {
      var before = (form.elements.namedItem('notes_before') || {}).value, after = (form.elements.namedItem('notes_after') || {}).value;
      $$('[data-ed-act="note-before"]').forEach(function (b) { b.textContent = before && before.trim() ? ed.editBefore : ed.addBefore; });
      $$('[data-ed-act="note-after"]').forEach(function (b) { b.textContent = after && after.trim() ? ed.editAfter : ed.addAfter; });
      var n = (before && before.trim() ? 1 : 0) + (after && after.trim() ? 1 : 0), v = $('[data-ed-notes-value]'), none = $('[data-ed-notes-none]');
      if (v) v.textContent = n ? fill(ed.notes, { n: n }) : ed.none;
    }
    function showNote(name, on) {
      var sec = note(name); if (!sec) return;
      if (on) sec.setAttribute('data-shown', ''); else { sec.removeAttribute('data-shown'); var t = sec.querySelector('textarea'); if (t && t.value) { t.value = ''; t.dispatchEvent(new env.Event('input', { bubbles: true })); } }
      if (on) { var ta = sec.querySelector('textarea'); if (ta) ta.focus(); }
      labelNotes();
    }
    $$('[data-ed-remove-note]').forEach(function (b) { b.addEventListener('click', function () { showNote(b.dataset.edRemoveNote, false); }); });
    form.addEventListener('input', function (e) { if (e.target && /^notes_/.test(e.target.name || '')) labelNotes(); });

    // Preview: Epigraph read-only, styled like the reader view.
    var eyebrow = $('[data-ed-eyebrow]'), previewBtn = $('[data-ed-preview]');
    function stateWord() { return previewing ? ed.statePreview : ({ draft: ed.stateDraft, published: ed.statePublished, scheduled: ed.stateScheduled })[form.dataset.edState]; }
    function paintEyebrow() { if (eyebrow) eyebrow.textContent = fill(ed.eyebrow, { n: form.dataset.edNumber, state: stateWord() }); }
    function setPreview(on) {
      previewing = on;
      form.classList.toggle('ed-previewing', on);
      if (previewBtn) { previewBtn.setAttribute('aria-pressed', String(on)); previewBtn.textContent = on ? ed.back : ed.preview; }
      var a = api(); if (a && a.setReadOnly) a.setReadOnly(on);
      if (title) title.readOnly = on;
      ['notes_before', 'notes_after'].forEach(function (n) { var t = form.elements.namedItem(n); if (t) t.readOnly = on; });
      paintEyebrow();
    }
    if (previewBtn) previewBtn.addEventListener('click', function () { setPreview(!previewing); });
    if (title) title.addEventListener('input', function () { $$('[data-ed-title-echo]').forEach(function (n) { n.textContent = title.value || n.getAttribute('data-ed-title-empty') || ''; }); });

    // Publish popover.
    var rows = $$('.ed-prow'), whenNow = $('#ed-when-now'), whenLater = $('#ed-when-later'), whoAll = $('#ed-who-all'), whoPw = $('#ed-who-pw');
    var date = $('#ed-date'), time = $('#ed-time'), pwWrap = $('[data-ed-pw-wrap]'), laterBox = $('[data-ed-later]'), past = $('[data-ed-past]');
    function rowHead(r) { return r.querySelector('.ed-prow-head'); }
    function expand(name, open) {
      rows.forEach(function (r) { var h = rowHead(r); if (!h) return; var is = r.dataset.edRow === name; h.setAttribute('aria-expanded', String(open && is)); });
    }
    rows.forEach(function (r) { var h = rowHead(r); if (h) h.addEventListener('click', function () { expand(r.dataset.edRow, h.getAttribute('aria-expanded') !== 'true'); }); });
    function setRadio(a, b, first) { if (a) a.setAttribute('aria-checked', String(first)); if (b) b.setAttribute('aria-checked', String(!first)); }
    function tz() { try { return Intl.DateTimeFormat().resolvedOptions().timeZone || ''; } catch (_) { return ''; } }
    function whenText(utc) {
      var d = new Date(utc + ':00Z');
      try { return d.toLocaleString(undefined, { month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit' }); } catch (_) { return utc; }
    }
    function chosenUtc() { return composeSchedule(date && date.value, time && time.value); }
    function storyState() {
      var picked = form.querySelector('input[name="rating_id"]:checked');
      return { story: storyMode, storyTitle: storyTitle ? storyTitle.value.trim() !== '' : true, ratingChosen: !!picked, bodyFilled: box ? box.value.trim() !== '' : true };
    }
    function setValue(name, text, empty) {
      var n = $('[data-ed-value="' + name + '"]'); if (n) n.textContent = text !== '' ? text : (empty || '');
    }
    function storyRows() {
      var t = storyTitle ? storyTitle.value.trim() : '';
      var n = $('[data-ed-value="story"]'); setValue('story', t, n && n.dataset.edEmpty);
      var picked = form.querySelector('input[name="rating_id"]:checked'), r = $('[data-ed-value="rating"]');
      setValue('rating', picked ? picked.dataset.label || '' : '', r && r.dataset.edEmpty);
      var lang = form.elements.namedItem('language');
      setValue('language', lang && lang.selectedIndex >= 0 ? lang.options[lang.selectedIndex].text : '', '');
      var names = Array.prototype.slice.call(form.querySelectorAll('input[name="categories[]"]:checked')).map(function (c) { return c.dataset.label || ''; });
      var c = $('[data-ed-value="categories"]'); setValue('categories', names.join(', '), c && c.dataset.edEmpty);
    }
    var needEl = $('[data-ed-need]');
    function needText(st) {
      if (!needEl) return '';
      var m = firstMissing(st);
      return m === 'body' ? needEl.dataset.needBody : m === 'story' ? needEl.dataset.needStory : m === 'rating' ? needEl.dataset.needRating : '';
    }
    // A browser check on a field inside a closed More Options row opens the row first, and the row says where to look.
    form.addEventListener('invalid', function (e) {
      var d = e.target && e.target.closest && e.target.closest('details.ed-moreopts'); if (!d) return;
      d.open = true; d.setAttribute('data-error', '');
      var v = d.querySelector('[data-ed-look]'); if (v) v.textContent = v.getAttribute('data-ed-look');
    }, true);
    function needNote(st, okay) { if (!needEl) return; var t = okay ? '' : needText(st); needEl.textContent = t; needEl.hidden = t === ''; }
    var ERROR_FIELDS = { story: 'ed-f-title', rating: 'ed-f-rating' };
    function showError(name) {
      var msg = $('[data-ed-error="' + name + '"]'), row = $('[data-ed-row="' + name + '"]'), field = doc.getElementById(ERROR_FIELDS[name] || '');
      if (!msg) return; msg.hidden = false; if (row) row.setAttribute('data-error', '');
      if (field) { field.setAttribute('aria-invalid', 'true'); if (name === 'story') field.setAttribute('aria-describedby', 'ed-hint-story ed-err-story'); }
    }
    function clearErrors(st) {
      [['story', st.storyTitle], ['rating', st.ratingChosen]].forEach(function (p) {
        if (!p[1]) return;
        var msg = $('[data-ed-error="' + p[0] + '"]'), row = $('[data-ed-row="' + p[0] + '"]'), field = doc.getElementById(ERROR_FIELDS[p[0]]);
        if (msg) msg.hidden = true;
        if (row) row.removeAttribute('data-error');
        if (field) { field.removeAttribute('aria-invalid'); if (p[0] === 'story') field.setAttribute('aria-describedby', 'ed-hint-story'); }
      });
    }
    function refresh() {
      setRadio(whenNow, whenLater, whenMode === 'now'); setRadio(whoAll, whoPw, audience === 'everyone');
      if (laterBox) laterBox.hidden = whenMode !== 'later';
      if (pwWrap) { if (audience === 'password') pwWrap.setAttribute('data-open', ''); else pwWrap.removeAttribute('data-open'); }
      var utc = whenMode === 'later' ? chosenUtc() : '', isPastNow = whenMode === 'later' && utc !== '' && isPast(utc);
      if (past) past.hidden = !isPastNow;
      var wv = $('[data-ed-when-value]'); if (wv) wv.textContent = whenMode === 'later' && utc ? whenText(utc) : ed.whenNow;
      var av = $('[data-ed-who-value]'); if (av) av.textContent = audience === 'password' ? ed.whoPw : ed.whoAll;
      var note_ = $('[data-ed-clear-note]'); if (note_) note_.hidden = !(protectedNow && audience === 'everyone');
      var st = storyMode ? storyState() : {};
      var okay = canPublish({ when: whenMode, utc: utc, audience: audience, protectedNow: protectedNow, password: password ? password.value : '', story: st.story, storyTitle: st.storyTitle, ratingChosen: st.ratingChosen, bodyFilled: st.bodyFilled });
      if (storyMode) { storyRows(); needNote(st, okay); clearErrors(st); }
      if (go) {
        // Story modes keep the button clickable so a press can say what is missing; it still looks and reads as unavailable.
        if (storyMode) go.setAttribute('aria-disabled', String(!okay)); else go.disabled = !okay;
        go.textContent = live ? ed.saveChanges : (whenMode === 'later' && utc ? fill(ed.scheduleFor, { when: whenText(utc) }) : ed.publishNow);
      }
      labelNotes();
    }
    // The named fields carry the chosen values, so a plain submit posts exactly what the popover shows.
    function commit() {
      if (field) {
        var utc = whenMode === 'later' ? chosenUtc() : '';
        if (whenMode === 'later') field.value = utc;
        else if (form.dataset.edState !== 'published' || whenTouched) field.value = '';
      }
      var a = accessFields(audience, protectedNow, password ? password.value : '');
      if (clear) clear.checked = a.clear;
      if (password && audience !== 'password') password.value = '';
    }
    if (whenNow) whenNow.addEventListener('click', function () { whenMode = 'now'; whenTouched = true; refresh(); });
    if (whenLater) whenLater.addEventListener('click', function () { whenMode = 'later'; whenTouched = true; if (date && !date.value) { var d = new Date(Date.now() + 86400000); date.value = d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate()); if (time && !time.value) time.value = '09:00'; } refresh(); });
    if (whoAll) whoAll.addEventListener('click', function () { audience = 'everyone'; refresh(); });
    if (whoPw) whoPw.addEventListener('click', function () { audience = 'password'; refresh(); if (password) password.focus(); });
    [date, time, password].forEach(function (el) { if (el) { el.addEventListener('input', refresh); el.addEventListener('change', refresh); } });
    var showBtn = $('[data-ed-pw-show]');
    if (showBtn && password) showBtn.addEventListener('click', function () { var hide = password.type === 'password'; password.type = hide ? 'text' : 'password'; showBtn.textContent = hide ? ed.hide : ed.show; });
    var tzNote = $('[data-ed-tz]'); if (tzNote) tzNote.textContent = fill(ed.tz, { tz: tz() });
    var draftBtn = $('[data-ed-draft]'); if (draftBtn && live) draftBtn.hidden = true;
    if (go) go.addEventListener('click', commit);
    // Story modes: a missing answer opens its row and focuses it, instead of a silent block.
    function reveal(rowName, fieldId) {
      if (!pub) return;
      if (!pub.hasAttribute('data-open')) openPub(rowName); else expand(rowName, true);
      var el = fieldId ? doc.getElementById(fieldId) : null; if (el && el.focus) el.focus();
      if (ERROR_FIELDS[rowName]) showError(rowName);
    }
    function missingGuard(e) {
      var m = firstMissing(storyState());
      if (!m) return;
      if (needEl) needEl.setAttribute('data-attempted', '');
      e.preventDefault(); e.stopImmediatePropagation();
      var pm = $('[data-ed-error="rating"]'); if (pm && pm.dataset.needPublish) pm.textContent = pm.dataset.needPublish;
      reveal(m, m === 'story' ? 'ed-f-title' : 'ed-f-rating');
    }
    if (storyMode && go) go.addEventListener('click', function (e) {
      if (go.getAttribute('aria-disabled') !== 'true') return;
      if (box && box.value.trim() === '') { e.preventDefault(); e.stopImmediatePropagation(); closePub(); var ed_ = api(), ce = form.querySelector('[contenteditable="true"]'); if (ce) ce.focus(); else if (ed_ && ed_.focus) ed_.focus(); else if (box.focus) box.focus(); say(needEl ? needEl.dataset.needBody : ''); return; }
      missingGuard(e);
    }, true);
    if (storyMode && draftBtn) draftBtn.addEventListener('click', function (e) {
      if (!canDraft(storyState())) {
        e.preventDefault(); e.stopImmediatePropagation(); reveal('rating', 'ed-f-rating');
        var rm = $('[data-ed-error="rating"]'); if (rm && rm.dataset.needDraft) rm.textContent = rm.dataset.needDraft;
      }
    }, true);
    if (storyMode) form.addEventListener('input', refresh);
    if (storyMode) form.addEventListener('change', refresh);
    if (draftBtn) draftBtn.addEventListener('click', function () { if (field && !live) { field.value = whenMode === 'later' ? chosenUtc() : ''; } });
    // Initial values.
    if (field && field.value) {
      var parts = splitSchedule(field.value);
      if (date) date.value = parts.date; if (time) time.value = parts.time;
      if (form.dataset.edState === 'scheduled' || form.dataset.writerRejected === '1') whenMode = 'later';
    }
    function dialogs() { return (pub && pub.hasAttribute('data-open')) || (del && !del.hidden); }
    function focusables(root_) { return Array.prototype.slice.call(root_.querySelectorAll('button:not([disabled]):not([hidden]), input:not([hidden]):not([disabled]), [href]')).filter(function (n) { return n.offsetParent !== null; }); }
    function openPub(row, step) {
      if (!pub) return; opener = menu && menu.contains(doc.activeElement) ? moreBtn : doc.activeElement; openMenu(false);
      var f = $('[data-ed-pub-form]'), d = $('[data-ed-pub-done]'), kick = $('[data-ed-pub-kicker]');
      if (kick && box) kick.textContent = kick.textContent.replace(/[\d,]+(?=\D*$)/, String(words(box.value)));
      f.hidden = step === 'done'; d.hidden = step !== 'done';
      pub.setAttribute('role', 'dialog'); pub.setAttribute('aria-modal', 'true');
      pub.setAttribute('data-open', ''); expand(row || '', !!row); refresh(); syncScrim();
      var first = storyMode && row ? doc.getElementById(ERROR_FIELDS[row] || '') : null;
      var target = step === 'done' ? d.querySelector('button') : (first || (storyMode ? focusables(pub)[0] : (go && !go.disabled ? go : focusables(pub)[0])));
      if (target) target.focus();
    }
    function closePub() { if (!pub) return; pub.removeAttribute('data-open'); syncScrim(); if (opener && opener.focus) opener.focus(); opener = null; }
    if (primary) primary.addEventListener('click', function (e) { e.preventDefault(); var q = storyMode ? storyState() : null; openPub(q && !q.storyTitle ? 'story' : q && !q.ratingChosen ? 'rating' : ''); });
    var cancel = $('[data-ed-cancel]'); if (cancel) cancel.addEventListener('click', closePub);
    var back = $('[data-ed-back]'); if (back) back.addEventListener('click', closePub);
    if (scrim) scrim.addEventListener('click', function () { if (del && !del.hidden) closeDel(); else if (pub && pub.hasAttribute('data-open')) closePub(); else { panelOrder.slice().forEach(function (n) { panel(n, false); }); } });
    var copy = $('[data-ed-copy]');
    if (copy) copy.addEventListener('click', function () {
      var url = (form.dataset.edLink ? env.location.origin + form.dataset.edLink : env.location.href);
      try { env.navigator.clipboard.writeText(url).then(function () { say(ed.copied); }, function () {}); } catch (_) { /* The link stays visible in the address bar. */ }
    });
    var doneState = form.dataset.edDone;
    if (doneState) {
      var dt = $('[data-ed-done-title]'), dx = $('[data-ed-done-text]');
      if (dt) dt.textContent = doneState === 'scheduled' ? ed.doneScheduled : ed.doneLive;
      if (dx) dx.textContent = doneState === 'scheduled' ? ed.doneScheduledText : ed.doneLiveText;
    }
    $$('[data-ed-act="fix-schedule"]').forEach(function (b) { b.addEventListener('click', function () { whenMode = 'later'; openPub('when'); }); });
    $$('[data-ed-dismiss]').forEach(function (b) { b.addEventListener('click', function () { var banner = b.closest('.ed-banner'); if (banner) banner.hidden = true; }); });

    // Delete confirmation.
    function openDel() { if (!del) return; opener = menu && menu.contains(doc.activeElement) ? moreBtn : doc.activeElement; openMenu(false); del.hidden = false; syncScrim(); var k = del.querySelector('[data-ed-keep]'); if (k) k.focus(); }
    function closeDel() { if (!del) return; del.hidden = true; syncScrim(); if (opener && opener.focus) opener.focus(); opener = null; }
    var keep = $('[data-ed-keep]'); if (keep) keep.addEventListener('click', closeDel);
    if (del) del.addEventListener('click', function (e) {
      if (e.target && e.target.getAttribute && e.target.getAttribute('form') === 'chapter-delete-form') {
        form.setAttribute('data-writer-guard', 'off'); form.dispatchEvent(new env.Event('change', { bubbles: true }));
      }
    });

    $$('[data-ed-combo]').forEach(function (k) { k.textContent = comboText(); });
    // The cheat sheet spells every combination for the platform in use: "Ctrl or Cmd + K" becomes "⌘ K" or "Ctrl K".
    $$('#ed-keys kbd').forEach(function (k) {
      var m = /^Ctrl or Cmd \+ (Shift \+ )?(.+)$/.exec(k.textContent.trim());
      if (m) k.textContent = comboKeys(!!m[1]) + ' ' + m[2];
    });
    // Menu actions.
    $$('[data-ed-act]').forEach(function (b) {
      var act = b.dataset.edAct;
      if (act === 'fix-schedule') return;
      b.addEventListener('click', function () {
        if (b.closest('#ed-menu') || act.indexOf('note-') === 0) openMenu(false);
        if (act === 'note-before' || act === 'note-after') { if (pub && pub.hasAttribute('data-open')) closePub(); showNote(act.slice(5), true); }
        else if (act === 'recovery') {
          var d = form.kipDraft, host = recoveryHost();
          if (d && host) { if (host.dataset.recovery === 'on') d.disable(); else d.enable(); paintRecovery(); }
        } else if (act === 'help') { panel('manuscript', true); var k = $('#ed-keys'); if (k && k.scrollIntoView) k.scrollIntoView({ block: 'start' }); }
        else if (act === 'source') { var m = form.querySelector('.epigraph-mode'); if (m) m.click(); }
        else if (act === 'delete') openDel();
        else if (act === 'site-menu') env.setTimeout(function () { var d = doc.querySelector('#menu .sheet-done'); if (d) d.focus(); }, 0);
      });
    });

    doc.addEventListener('keydown', function (e) { if (e.key === 'Escape' && env.location.hash === '#menu') env.location.hash = '#sheet-close'; });

    // Footer: the Source twin presses Epigraph's own toggle; the live count reads the selection.
    var count = $('[data-writer-count]'), total = 0;
    function words(text) { return root.Kip && root.Kip.wordCount ? root.Kip.wordCount(text) : 0; }
    function onSelection(text) {
      if (!count) return; total = words(box.value);
      count.textContent = text && text.trim() ? fill(ed.wordsOf, { n: words(text), m: total }) : fill(ed.words, { n: total });
    }
    var bound = false;
    function bind() {
      var a = api(); if (!a || bound) return; bound = true;
      if (a.onSelection) a.onSelection(onSelection);
      if (a.setSmartPunctuation) a.setSmartPunctuation(settings.smart);
      if (previewing && a.setReadOnly) a.setReadOnly(true);
    }
    box.addEventListener('epigraph-ready', bind); bind();

    // Status line: say Unpublished Changes on a live chapter, and keep the dot's word honest when the form is clean.
    var status = $('[data-writer-state]'), spoken = $('[data-ed-live]');
    function normalize() {
      if (!status) return; var text = status.textContent;
      var dirty = text === writer.unsaved, want = dirty ? (live ? ed.changes : ed.draft) : (text === '' ? (live ? ed.saved : ed.draft) : text);
      if (want !== text) status.textContent = want;
      if (spoken && (spoken.textContent === '' || spoken.textContent === writer.unsaved) && spoken.textContent !== (dirty ? writer.unsaved : '')) spoken.textContent = dirty ? writer.unsaved : ''; // Leave other announcements (copied, archive adopted) alone.
    }
    if (status && env.MutationObserver) new env.MutationObserver(normalize).observe(status, { childList: true, characterData: true, subtree: true });
    normalize();

    // Keys. Esc closes the innermost thing first.
    doc.addEventListener('keydown', function (e) {
      if (e.key === 'Escape') {
        var link = form.querySelector('.epigraph-link'), linkOpen = !!(link && !link.hidden && link.style.display !== 'none' && link.offsetParent !== null);
        var target = escTarget({ link: linkOpen, menu: menuOpen(), dialog: !!dialogs(), // Focus mode hides the panels, so Esc leaves focus first instead of closing something unseen.
        panel: panelOrder.length > 0 && !(comfort && comfort.isFocus()), focus: comfort && comfort.isFocus() });
        if (target === 'link' || target === null) return;
        e.preventDefault();
        if (target === 'menu') { openMenu(false); if (moreBtn) moreBtn.focus(); }
        else if (target === 'dialog') { if (del && !del.hidden) closeDel(); else closePub(); }
        else if (target === 'panel') { var last = panelOrder[panelOrder.length - 1], pe = last === 'contents' ? contents : manuscript, back = refocusToggle(doc.activeElement, pe, doc.body); panel(last, false); var tb = $('[data-ed-panel="' + last + '"]'); if (tb && back) tb.focus(); }
        else if (target === 'focus') comfort.setFocus(false);
        return;
      }
      if (e.key === 'Tab' && dialogs()) {
        var root_ = del && !del.hidden ? del : pub, list = focusables(root_);
        if (list.length) {
          var first = list[0], last_ = list[list.length - 1];
          if (e.shiftKey && doc.activeElement === first) { e.preventDefault(); last_.focus(); }
          else if (!e.shiftKey && doc.activeElement === last_) { e.preventDefault(); first.focus(); }
          else if (!root_.contains(doc.activeElement)) { e.preventDefault(); first.focus(); }
        }
        return;
      }
      var action = shortcutAction(e);
      if (action === 'focus' && comfort) { e.preventDefault(); comfort.setFocus(!comfort.isFocus()); }
      else if (action === 'source' && !previewing && !dialogs() && !e.defaultPrevented) { var mode = $('.epigraph-mode'); if (mode) { e.preventDefault(); mode.click(); } }
    });

    paintEyebrow(); refresh();
    if (storyMode && pub && form.dataset.edOpen) { openPub(form.dataset.edOpen === 'more' ? '' : form.dataset.edOpen); var more = $('.ed-moreopts'); if (more && form.dataset.edOpen === 'more') more.open = true; var ff = form.dataset.edFocus ? doc.getElementById(form.dataset.edFocus) : null; if (ff && ff.focus) ff.focus(); }
    if (storyMode) $$('[data-ed-error]').forEach(function (n) { if (!n.hidden) showError(n.dataset.edError); });
    // A new story starts in the chapter title. The editor builds itself a moment later and takes focus, so claim it back until the writer types.
    if (form.dataset.edMode === 'new-story' && title && !form.dataset.edFocus && !form.dataset.edOpen) {
      var typed = false, claim = function () { if (!typed && !(pub && pub.hasAttribute('data-open')) && doc.activeElement !== title) title.focus(); };
      ['keydown', 'pointerdown'].forEach(function (t) { form.addEventListener(t, function () { typed = true; }, true); });
      claim(); env.addEventListener('load', claim); [400, 1500, 3500].forEach(function (ms) { env.setTimeout(claim, ms); });
    }
    if (doneState && pub) openPub('', 'done');
    // A refused schedule opens the publish popover on the When row by itself; the banner button stays for a second try.
    else if (pub && $('[data-ed-act="fix-schedule"]')) { whenMode = 'later'; openPub('when'); }
    return { setPreview: setPreview, openPub: openPub };
  }

  // The Source button in the footer mirrors Epigraph's own toggle, which stays in the page for its notices.
  function sourceTwin(form, doc) {
    var slot = form.querySelector('[data-writer-tools]'), label = (JSON.parse(form.dataset.edLabels || '{}')).source || 'Source';
    var twin = null;
    function seat() {
      var mode = form.querySelector('.epigraph-mode');
      if (!mode || !slot) return !!mode;
      if (twin) return true;
      twin = doc.createElement('button'); twin.type = 'button'; twin.className = 'writer-source'; twin.textContent = label;
      twin.setAttribute('aria-keyshortcuts', 'Control+/ Meta+/'); twin.title = comboText(); twin.setAttribute('aria-pressed', 'false');
      var box = form.elements.namedItem('content');
      var head = null, more = form.querySelector('.ed-more');
      if (more) {
        // The owner keeps Source in the header, with its Ctrl or Cmd + / shortcut; phones keep the footer one.
        head = doc.createElement('button'); head.type = 'button'; head.className = 'ed-tool ed-tool-source js-only';
        head.setAttribute('aria-keyshortcuts', 'Control+/ Meta+/'); head.title = comboText(); head.setAttribute('aria-pressed', 'false');
        head.innerHTML = '<span class="ed-icon ed-icon-src" aria-hidden="true">&lt;/&gt;</span><span class="ed-tool-label"></span>';
        head.lastChild.textContent = label; more.parentNode.insertBefore(head, more);
      }
      function sync() { var a = box && box.kipEpigraph, on = String(!!(a && a.mode && a.mode() === 'source')); twin.setAttribute('aria-pressed', on); if (head) head.setAttribute('aria-pressed', on); }
      twin.addEventListener('click', function () { mode.click(); sync(); });
      if (head) head.addEventListener('click', function () { mode.click(); sync(); });
      slot.insertBefore(twin, slot.firstChild);
      if (root.MutationObserver) new root.MutationObserver(sync).observe(mode, { childList: true, characterData: true, subtree: true });
      sync(); form.classList.add('writer-has-twin');
      return true;
    }
    if (!seat() && root.MutationObserver) {
      var watch = new root.MutationObserver(function () { if (seat()) watch.disconnect(); });
      watch.observe(form, { childList: true, subtree: true });
    }
  }
  // The source shortcut as a full combination, by platform (never a bare "/").
  function comboKeys(shift) {
    var nav = root.navigator || {}, apple = /Mac|iPhone|iPad|iPod/i.test(String(nav.platform || nav.userAgent || ''));
    return apple ? '\u2318' + (shift ? ' \u21e7' : '') : 'Ctrl' + (shift ? ' Shift' : '');
  }
  function comboText() { return comboKeys(false) + ' /'; }
  function init(doc) {
    doc.querySelectorAll('form[data-js-module~="writer-comfort"]').forEach(function (form) {
      if (form.kipComfort) return;
      try { form.kipComfort = attach(form); } catch (_) { /* Native editing remains available. */ }
      // The page gives the controls a quiet home in its footer; without one they stay above the text.
      var slot = form.kipComfort && form.kipComfort.bar && form.querySelector('[data-writer-tools]');
      if (slot && slot !== form.kipComfort.bar) slot.appendChild(form.kipComfort.bar);
      try { sourceTwin(form, doc); } catch (_) { /* Source stays on Epigraph's own button. */ }
      try { form.kipEditor = editor(form, doc, doc.defaultView || root, form.kipComfort); } catch (_) { /* The plain form still works. */ }
    });
  }
  return { scrollTarget: scrollTarget, attach: attach, init: init, utcToLocal: utcToLocal, localToUtc: localToUtc,
    canDraft: canDraft, firstMissing: firstMissing, adoptOldDraft: adoptOldDraft, composeSchedule: composeSchedule, splitSchedule: splitSchedule, isPast: isPast, accessFields: accessFields, canPublish: canPublish,
    escTarget: escTarget, refocusToggle: refocusToggle, shortcutAction: shortcutAction, loadSettings: loadSettings, saveSettings: saveSettings, cleanSettings: cleanSettings, SETTINGS_KEY: SETTINGS_KEY };
});
