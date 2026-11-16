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
    on(form, 'compositionstart', function (e) { if (e.target === current()) { pause(); composition = { target: e.target, before: text(e.target) }; } });
    on(form, 'compositionend', function (e) {
      if (!composition || e.target !== composition.target) return;
      var changed = text(e.target) !== composition.before; composition = null; if (changed) schedule();
    });
    on(env, 'wheel', pause); on(env, 'touchmove', pause); on(env, 'pointerdown', pause);
    on(env, 'keydown', function (e) { if (['PageUp', 'PageDown', 'Home', 'End', 'ArrowUp', 'ArrowDown', 'ArrowLeft', 'ArrowRight'].includes(e.key)) pause(); });
    doc.body.appendChild(mirror); (box.closest('label') || box).insertAdjacentElement('beforebegin', bar);
    return { destroy: function () {
      pause(); listeners.forEach(function (off) { off(); }); form.classList.remove('writer-focus'); bar.remove(); mirror.remove();
    } };
  }
  function init(doc) {
    doc.querySelectorAll('form[data-js-module~="writer-comfort"]').forEach(function (form) {
      if (form.kipComfort) return;
      try { form.kipComfort = attach(form); } catch (_) { /* Native editing remains available. */ }
    });
  }
  return { scrollTarget: scrollTarget, attach: attach, init: init };
});
