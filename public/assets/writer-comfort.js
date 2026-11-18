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
    var destination = Math.min(maximum, Math.max(minimum, state.viewport * 0.45));
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
    function current() { return box.kipWriter ? box.kipWriter.activeControl : box; }
    function text(el) { return el.tagName === 'TEXTAREA' ? el.value : el.textContent; }
    function pause() { paused = true; if (frame) { env.cancelAnimationFrame(frame); frame = 0; } }
    function button(label, toggle) {
      var node = doc.createElement('button'); node.type = 'button'; node.textContent = label; node.setAttribute('aria-pressed', 'false');
      on(node, 'click', function () { node.setAttribute('aria-pressed', String(toggle())); }); bar.append(node);
    }
    button(labels.focus, function () { return form.classList.toggle('writer-focus'); });
    button(labels.typewriter, function () { enabled = !enabled; pause(); return enabled; });
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
      }
      return textareaCaret(box);
    }
    function measure() {
      frame = 0; if (!enabled || composition || paused) return;
      var point = caret(current()), viewport = env.visualViewport;
      var offset = viewport ? viewport.offsetTop : 0, height = viewport ? viewport.height : env.innerHeight;
      var sticky = form.querySelector('.writer-actions');
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
    return { bar: bar, destroy: function () {
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
  function schedule(form, doc) {
    var field = form.querySelector('[data-writer-schedule]');
    if (!field) return;
    var note = form.querySelector('[data-utc-note]'); if (note) note.remove();
    var local = doc.createElement('input'); local.type = 'datetime-local'; local.value = utcToLocal(field.value);
    var owner = field.closest('label'); local.setAttribute('aria-label', owner ? owner.textContent.trim() : 'Schedule');
    // The named field stays a datetime-local so the unsaved-changes check still sees it; it just holds the UTC value out of sight.
    field.hidden = true; field.setAttribute('aria-hidden', 'true'); field.tabIndex = -1; field.after(local);
    local.addEventListener('input', function () {
      field.value = localToUtc(local.value); field.dispatchEvent(new root.Event('input', { bubbles: true }));
    });
  }
  // A background post, so the theme changes in place and the chapter form is never submitted or reloaded.
  function theme(form, doc, env) {
    var body = form.querySelector('[data-writer-panel-body]'), token = form.elements.namedItem('_token');
    if (!body || !body.dataset.writerThemeLabels || !token) return;
    var labels = JSON.parse(body.dataset.writerThemeLabels), root_ = doc.documentElement;
    var row = doc.createElement('div'), title = doc.createElement('span'), buttons = [];
    row.className = 'writer-theme'; title.textContent = labels.title; row.appendChild(title);
    function mark(value) { buttons.forEach(function (b) { b.setAttribute('aria-pressed', String(b.value === value)); }); }
    ['paper', 'sepia', 'night', 'auto'].forEach(function (value) {
      var b = doc.createElement('button'); b.type = 'button'; b.value = value; b.textContent = labels[value];
      b.addEventListener('click', function () {
        if (value === 'auto') root_.removeAttribute('data-theme'); else root_.setAttribute('data-theme', value);
        mark(value);
        var data = new env.FormData(); data.set('theme', value); data.set('_token', token.value); data.set('return_to', env.location.pathname);
        env.fetch('/reader/settings', { method: 'POST', body: data, credentials: 'same-origin', redirect: 'manual' }).catch(function () {});
      });
      buttons.push(b); row.appendChild(b);
    });
    mark(root_.getAttribute('data-theme') || 'auto');
    var help = body.querySelector('.writer-help'); if (help) body.insertBefore(row, help); else body.appendChild(row);
  }
  // Ctrl or Cmd plus / flips between the formatted page and the source.
  function shortcut(form, doc) {
    var bar = form.querySelector('.writer-actions');
    // Epigraph builds its own Source toggle later and wires its notices to it, so that one stays put.
    // A twin in the header's top right mirrors its label and presses it.
    var twin = null;
    function seat() {
      var mode = form.querySelector('.epigraph-mode');
      if (!mode || !bar) return !!mode;
      if (twin) return true;
      twin = doc.createElement('button'); twin.type = 'button'; twin.className = 'writer-source';
      twin.setAttribute('aria-keyshortcuts', 'Control+/ Meta+/'); twin.title = 'Ctrl or Cmd + /';
      var sync = function () { twin.textContent = mode.textContent; };
      sync();
      twin.addEventListener('click', function () { mode.click(); });
      bar.insertBefore(twin, bar.querySelector('.writer-panel') || null);
      if (root.MutationObserver) new root.MutationObserver(sync).observe(mode, { childList: true, characterData: true, subtree: true });
      form.classList.add('writer-has-twin');
      return true;
    }
    if (!seat() && root.MutationObserver) {
      var watch = new root.MutationObserver(function () { if (seat()) watch.disconnect(); });
      watch.observe(form, { childList: true, subtree: true });
    }
    doc.addEventListener('keydown', function (e) {
      if (e.key !== '/' || !(e.ctrlKey || e.metaKey) || e.altKey) return;
      var toggle = form.querySelector('.epigraph-mode');
      if (!toggle) return;
      e.preventDefault(); toggle.click();
    });
  }
  function init(doc) {
    doc.querySelectorAll('form[data-js-module~="writer-comfort"]').forEach(function (form) {
      if (form.kipComfort) return;
      try { form.kipComfort = attach(form); } catch (_) { /* Native editing remains available. */ }
      // The page gives the controls a quiet home in its footer; without one they stay above the text.
      var slot = form.kipComfort && form.kipComfort.bar && form.querySelector('[data-writer-tools]');
      if (slot && slot !== form.kipComfort.bar) slot.appendChild(form.kipComfort.bar);
      [schedule, theme, shortcut].forEach(function (step) {
        try { step(form, doc, doc.defaultView || root); } catch (_) { /* Each extra is optional. */ }
      });
    });
  }
  return { scrollTarget: scrollTarget, attach: attach, init: init, utcToLocal: utcToLocal, localToUtc: localToUtc };
});
