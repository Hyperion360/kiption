/* Run with runWriterComfortDOMTests(Kip) on a page loading reader.css. */
async function runWriterComfortDOMTests(K) {
  var results = [], performanceResult = null, wrappedCaretResults = [];
  function assert(value, message) { if (!value) throw new Error(message); }
  function frame() { return new Promise(resolve => requestAnimationFrame(resolve)); }
  function fixture(options) {
    options = options || {};
    var form = document.createElement('form');
    form.innerHTML = '<div class="writer-actions"><button type="submit">Save</button></div><label>Title<input name="title"></label><label>Text<textarea name="content" rows="5" required></textarea></label>';
    form.dataset.writerLabels = JSON.stringify({ focus: 'Focus Mode', typewriter: 'Typewriter Scrolling' });
    form.style.cssText = 'width: min(500px, 100%); margin-block: 2rem'; document.body.appendChild(form);
    form.scrollIntoView();
    var box = form.elements.content, title = form.elements.title, pending = null, moves = [], queued = 0;
    box.value = 'First paragraph.\n\nEmoji 👩🏽‍💻 and é.'; box.setSelectionRange(box.value.length, box.value.length);
    var env = {
      addEventListener: window.addEventListener.bind(window), removeEventListener: window.removeEventListener.bind(window),
      getComputedStyle: window.getComputedStyle.bind(window), innerHeight: window.innerHeight, scrollY: 300,
      visualViewport: options.viewport || null, scrollTo: value => moves.push(value),
      requestAnimationFrame: fn => { queued++; pending = fn; return queued; }, cancelAnimationFrame: () => { pending = null; }
    };
    var adapter = K.writerComfort.attach(form, env), bar = form.querySelector('.writer-comfort');
    var mirrors = document.querySelectorAll('.writer-caret-mirror'), mirror = mirrors[mirrors.length - 1];
    function input(target, type, composing) { target.dispatchEvent(new InputEvent('input', { bubbles: true, inputType: type, isComposing: !!composing })); }
    function flush() { var fn = pending; pending = null; if (fn) fn(); }
    return { form, box, title, env, bar, mirror, moves, input, flush, queued: () => queued, pending: () => pending,
      enable: () => bar.children[1].click(), destroy: () => { adapter.destroy(); form.remove(); } };
  }
  async function test(name, fn) {
    var f = fixture();
    try { await fn(f); results.push({ name, pass: true }); }
    catch (error) { results.push({ name, pass: false, error: error.message }); }
    finally { f.destroy(); }
  }
  await test('controls have native semantics and do not alter text or its label', function (f) {
    var before = f.box.value; f.box.focus();
    assert(f.bar.children.length === 2, 'two controls');
    for (var button of f.bar.children) { assert(button.type === 'button', 'not a submit control'); assert(!button.closest('label'), 'outside Text label'); assert(button.getAttribute('aria-pressed') === 'false', 'initial pressed state'); }
    f.bar.children[0].click(); assert(f.form.classList.contains('writer-focus'), 'focus class enabled');
    assert(f.bar.children[0].getAttribute('aria-pressed') === 'true', 'pressed state changed');
    assert(f.form.querySelector('[type=submit]').getBoundingClientRect().height >= 44, 'Save touch target');
    assert(f.box.value === before && document.activeElement === f.box, 'text and focus unchanged by handler');
    f.bar.children[1].focus(); var focusOutline = getComputedStyle(f.bar.children[1]).outlineColor;
    f.bar.children[0].focus(); assert(getComputedStyle(f.bar.children[0]).outlineColor === focusOutline, 'pressed state preserves the keyboard focus ring');
    f.bar.children[0].click(); assert(!f.form.classList.contains('writer-focus'), 'focus disabled');
  });
  await test('only active insertions schedule one frame and keep the mirror nodes', function (f) {
    f.enable(); var prefix = f.mirror.firstChild, marker = f.mirror.lastChild;
    f.input(f.title, 'insertText'); f.input(f.box, 'deleteContentBackward'); assert(!f.pending(), 'unrelated/noninsert input ignored');
    f.input(f.box, 'insertText'); f.input(f.box, 'insertText'); assert(f.queued() === 1, 'one queued frame'); f.flush();
    assert(f.moves.length === 1 && f.moves[0].behavior === 'auto', 'one instant movement');
    assert(f.mirror.firstChild === prefix && f.mirror.lastChild === marker, 'mirror reused');
    assert(prefix.data === f.box.value, 'literal Unicode prefix retained');
  });
  await test('manual scrolling and selection cancel pending recentering', function (f) {
    f.enable();
    for (var event of [new WheelEvent('wheel'), new Event('touchmove'), new PointerEvent('pointerdown'), new KeyboardEvent('keydown', { key: 'PageDown' }), new KeyboardEvent('keydown', { key: 'ArrowLeft' })]) {
      f.input(f.box, 'insertText'); window.dispatchEvent(event); assert(!f.pending(), event.type + ' cancels frame'); f.flush();
    }
    assert(f.moves.length === 0, 'manual navigation does not move page');
    f.input(f.box, 'insertText'); f.flush(); assert(f.moves.length === 1, 'next insert resumes');
  });
  await test('only completed content composition resumes typewriter movement', function (f) {
    f.enable(); f.title.dispatchEvent(new CompositionEvent('compositionstart', { bubbles: true })); f.title.value = '日'; f.title.dispatchEvent(new CompositionEvent('compositionend', { bubbles: true, data: '日' }));
    assert(!f.pending(), 'title composition ignored');
    f.box.dispatchEvent(new CompositionEvent('compositionstart', { bubbles: true })); f.box.value += '日'; f.input(f.box, 'insertCompositionText', true); assert(!f.pending(), 'composition does not jump');
    f.box.dispatchEvent(new CompositionEvent('compositionend', { bubbles: true, data: '日' })); assert(f.pending(), 'committed content schedules'); f.flush();
    f.box.dispatchEvent(new CompositionEvent('compositionstart', { bubbles: true })); f.box.dispatchEvent(new CompositionEvent('compositionend', { bubbles: true, data: '' })); assert(!f.pending(), 'cancelled composition ignored');
  });
  await test('short visual viewport reserves space under sticky Save', function (f) {
    f.env.visualViewport = { offsetTop: 50, height: 250 };
    var rect = f.box.getBoundingClientRect(); f.box.getBoundingClientRect = () => ({ ...rect.toJSON(), top: 200, bottom: 200 + rect.height });
    f.form.querySelector('.writer-actions').getBoundingClientRect = () => ({ bottom: 120 });
    f.box.value = 'One line'; f.box.setSelectionRange(3, 3); f.enable(); f.input(f.box, 'insertText'); f.flush();
    var y = 200 + f.mirror.lastChild.getBoundingClientRect().top - f.mirror.getBoundingClientRect().top - f.box.scrollTop;
    var after = y - (f.moves[0].top - 300); assert(after >= 136 && after < 300, 'caret remains inside viewport below Save');
    f.env.visualViewport.height = 80; f.input(f.box, 'insertText'); f.flush(); assert(f.moves.length === 1, 'no forced movement when no safe area remains');
  });
  await test('long textarea scrolls internally and wrap width excludes scrollbars', function (f) {
    f.box.value = 'Wrapping words and emoji 👩🏽‍💻. '.repeat(1000); f.box.setSelectionRange(f.box.value.length, f.box.value.length); f.box.scrollTop = 0;
    var before = f.box.value; f.enable(); f.input(f.box, 'insertText'); f.flush();
    assert(f.box.scrollTop > 0, 'internal scrollbar moved'); assert(f.box.value === before, 'source untouched');
    var style = getComputedStyle(f.box), expected = f.box.clientWidth + parseFloat(style.borderLeftWidth) + parseFloat(style.borderRightWidth);
    assert(Math.abs(f.mirror.getBoundingClientRect().width - expected) < 2, 'mirror uses available wrap width');
  });
  await test('interior caret keeps the complete word wrapping in a short scrolled textarea', function (f) {
    var sentence = 'This paragraph contains characteristically different wrapping behavior.';
    f.box.style.cssText = 'width: 280px; height: 60px; font: 17px/27.2px serif; padding: 8px; box-sizing: border-box';
    f.env.visualViewport = { offsetTop: 50, height: 250 };
    f.form.querySelector('.writer-actions').getBoundingClientRect = () => ({ bottom: 120 });
    var boxRect = f.box.getBoundingClientRect(); f.box.getBoundingClientRect = () => ({ ...boxRect.toJSON(), top: 200 });
    f.enable();
    for (var lead of ['', 'Earlier line.\n'.repeat(20)]) {
      f.box.value = lead + sentence; var offset = lead.length + 25;
      f.box.setSelectionRange(offset, offset); f.box.scrollTop = 0; f.input(f.box, 'insertText'); f.flush();
      // A single full-source text node provides independent native range geometry.
      var reference = f.mirror.cloneNode(false), source = document.createTextNode(f.box.value);
      reference.append(source); document.body.append(reference);
      try {
        var range = document.createRange(); range.setStart(source, offset); range.collapse(true);
        var expected = range.getBoundingClientRect().top - reference.getBoundingClientRect().top;
        var actual = f.mirror.lastChild.getBoundingClientRect().top - f.mirror.getBoundingClientRect().top;
        assert(Math.abs(actual - expected) < 1, 'interior caret matches full-source range; error ' + (actual - expected));
        var after = 200 + expected - f.box.scrollTop - (f.moves[f.moves.length - 1].top - 300);
        assert(after >= 136 && after + 27.2 <= 300, 'actual caret clears sticky Save and fits visual viewport');
        if (lead) assert(f.box.scrollTop > 0, 'interior caret scrolls inside the textarea');
        assert(f.box.value === lead + sentence, 'source remains unchanged');
        wrappedCaretResults.push({ source_prefix_length: lead.length, caret_offset: offset, expected_top: expected, measured_top: actual, caret_after_scroll: after, internal_scroll: f.box.scrollTop });
      } finally { reference.remove(); }
    }
  });
  await test('native required validation and submit remain available', function (f) {
    var invalid = 0, submitted = 0; f.box.addEventListener('invalid', e => { invalid++; e.preventDefault(); }); f.form.addEventListener('submit', e => { submitted++; e.preventDefault(); });
    f.enable(); f.box.value = ''; f.form.requestSubmit(); assert(invalid === 1 && submitted === 0, 'empty text is refused natively');
    f.box.value = 'Saved text'; f.form.requestSubmit(); assert(submitted === 1, 'native submit runs');
  });
  await test('ten-thousand-word typing keeps one mirror and stays within frame budget', async function (f) {
    f.box.value = 'word '.repeat(10000); f.enable(); var mirror = f.mirror, prefix = mirror.firstChild, marker = mirror.lastChild, suffix = marker.firstChild, samples = [], longTasks = [];
    var measuredStart = Infinity;
    var observer = new PerformanceObserver(list => longTasks.push(...list.getEntries().filter(e => e.startTime >= measuredStart).map(e => e.duration))); observer.observe({ type: 'longtask' });
    for (var i = 0; i < 110; i++) {
      await frame(); var start = performance.now(); if (i === 10) measuredStart = start;
      var offset = i % 2 ? Math.floor(f.box.value.length / 2) : f.box.value.length;
      f.box.value = f.box.value.slice(0, offset) + 'x' + f.box.value.slice(offset); f.box.setSelectionRange(offset + 1, offset + 1); f.input(f.box, 'insertText');
      f.flush(); if (i >= 10) samples.push(performance.now() - start);
      assert(f.mirror === mirror && mirror.firstChild === prefix && mirror.lastChild === marker && marker.firstChild === suffix, 'same mirror and text nodes');
    }
    await frame(); observer.disconnect(); samples.sort((a, b) => a - b);
    performanceResult = { words: 10000, iterations: samples.length, insertion_positions: 'alternating middle and end', p95_ms: samples[94], max_ms: samples[99], long_tasks_over_50ms: longTasks.filter(n => n > 50).length, user_agent: navigator.userAgent };
    assert(samples[94] < 50 && samples[99] < 50, 'caret measurement below 50ms'); assert(performanceResult.long_tasks_over_50ms === 0, 'no typing long task');
  });
  return { passed: results.filter(r => r.pass).length, failed: results.filter(r => !r.pass).length, results, wrapped_carets: wrappedCaretResults, performance: performanceResult };
}
