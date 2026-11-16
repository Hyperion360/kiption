'use strict';
(function () {
  var tests = [], result = document.getElementById('results'), summary = document.getElementById('summary');
  function check(name, fn) { tests.push({name:name, fn:fn}); }
  function equal(actual, expected, label) {
    if (actual !== expected) throw Error(label + ': expected ' + JSON.stringify(expected) + ', got ' + JSON.stringify(actual));
  }
  function fixture(source) {
    var model = KipInline.source.createSource(source);
    var view = KipInline.projection.create(document, model);
    var host = document.getElementById('editor');
    host.replaceChildren(view.element);
    return {model:model, view:view};
  }
  check('every offset round-trips through marked text', function () {
    var f = fixture('**Words**');
    var delimiters = f.view.element.querySelectorAll('.ki-syntax');
    equal(delimiters.length, 2, 'delimiter count');
    if (!delimiters[0].hidden || !delimiters[1].hidden) throw Error('inactive syntax exposed');
    for (var n = 0; n <= f.model.text.length; n++) {
      var point = f.view.toDom(n, 'after');
      equal(f.view.fromDom(point.node, point.offset, 'after'), n, 'source offset ' + n);
    }
    if (delimiters[0].hidden || delimiters[1].hidden) throw Error('syntax not revealed for caret');
    equal(f.model.text, '**Words**', 'source');
    equal(f.model.revision, 0, 'revision');
    if (!f.view.element.querySelector('.ki-strong')) throw Error('active word has no strong style');
  });
  check('empty and consecutive lines have distinct newline endpoints', function () {
    var f = fixture('a\n\nb');
    for (var n = 0; n <= f.model.text.length; n++) {
      var point = f.view.toDom(n, n === 2 ? 'before' : 'after');
      equal(f.view.fromDom(point.node, point.offset, n === 2 ? 'before' : 'after'), n, 'newline offset ' + n);
    }
    equal(f.view.element.querySelectorAll('[data-line]').length, 3, 'line count');
    equal(f.view.fromDom(f.view.element, 1, 'before'), 1, 'first line end');
    equal(f.view.fromDom(f.view.element, 1, 'after'), 2, 'second line start');
    equal(f.view.fromDom(f.view.element, 2, 'before'), 2, 'empty line end');
    equal(f.view.fromDom(f.view.element, 2, 'after'), 3, 'third line start');
  });
  check('both affinities map every source offset', function () {
    var f = fixture('**Words**\n\n[label](https://e.test/)');
    for (var n = 0; n <= f.model.text.length; n++) {
      for (var affinity of ['before','after']) {
        var point = f.view.toDom(n, affinity);
        equal(f.view.fromDom(point.node, point.offset, affinity), n, affinity + ' offset ' + n);
      }
    }
  });
  check('directed pairs through a styled span preserve both endpoints', function () {
    var f = fixture('**Words**');
    for (var anchor = 0; anchor <= f.model.text.length; anchor++) {
      for (var focus = 0; focus <= f.model.text.length; focus++) {
        f.view.restoreSelection({anchor:anchor,focus:focus});
        var actual = f.view.readSelection();
        equal(actual.anchor, anchor, 'anchor ' + anchor + ':' + focus);
        equal(actual.focus, focus, 'focus ' + anchor + ':' + focus);
        equal(document.getSelection().isCollapsed, anchor === focus, 'native collapsed state ' + anchor + ':' + focus);
      }
    }
  });
  check('literal escaped delimiters and inactive HTTPS target map exactly', function () {
    var f = fixture('\\*literal* [label](https://e.test/)');
    for (var n = 0; n <= f.model.text.length; n++) {
      var point = f.view.toDom(n, 'after');
      equal(f.view.fromDom(point.node, point.offset, 'after'), n, 'source offset ' + n);
    }
    equal(f.view.element.querySelectorAll('a,img').length, 0, 'no live external elements');
  });
  check('quote and scene blocks preserve source and display distinct structure', function () {
    var f = fixture('> quoted\n\n---');
    var lines = f.view.element.querySelectorAll('[data-line]');
    equal(lines[0].dataset.block, 'quote', 'quote block');
    equal(lines[2].dataset.block, 'scene', 'scene block');
    equal(f.model.text, '> quoted\n\n---', 'source');
    equal(f.model.revision, 0, 'revision');
  });
  check('Unicode and literal syntax preserve all offsets', function () {
    var f = fixture('👩🏽‍💻 café\n# Heading\n[label](http://e.test/)');
    for (var n = 0; n <= f.model.text.length; n++) {
      var point = f.view.toDom(n, 'after');
      equal(f.view.fromDom(point.node, point.offset, 'after'), n, 'UTF-16 offset ' + n);
    }
    equal(f.model.revision, 0, 'revision');
  });
  check('Unicode caret restoration is exact at every UTF-16 boundary', function () {
    var f = fixture('👩🏽‍💻 café');
    for (var n = 0; n <= f.model.text.length; n++) {
      f.view.restoreSelection({anchor:n,focus:n});
      var actual = f.view.readSelection();
      equal(actual.anchor, n, 'caret anchor ' + n);
      equal(actual.focus, n, 'caret focus ' + n);
      equal(document.getSelection().isCollapsed, true, 'native Unicode caret ' + n);
    }
  });
  check('Safari mixed forward endpoint shape preserves source range', function () {
    var source = 'Alpha\n\nBeta', f = fixture(source), expected = {anchor:4,focus:source.length-1};
    f.view.restoreSelection(expected);
    equal(f.view.readSelection().anchor, expected.anchor, 'forward anchor');
    equal(f.view.readSelection().focus, expected.focus, 'forward focus');
    equal(f.model.text, source, 'source');
    equal(f.model.undo(), false, 'selection has no history');
  });
  check('Safari mixed forward Unicode and structural endpoint shape preserves range', function () {
    var source = 'A👩🏽‍💻\nZ\n\nBeta', f = fixture(source);
    var expected = {anchor:source.indexOf('Z')+1,focus:source.length-1};
    f.view.restoreSelection(expected);
    equal(f.view.readSelection().anchor, expected.anchor, 'Unicode forward anchor');
    equal(f.view.readSelection().focus, expected.focus, 'Unicode forward focus');
    equal(f.model.text, source, 'source');
    equal(f.model.undo(), false, 'selection has no history');
  });
  check('Safari mixed backward Unicode and structural endpoint shape preserves range', function () {
    var source = 'A👩🏽‍💻\nZ\n\nBeta', f = fixture(source);
    var expected = {anchor:source.length-1,focus:source.indexOf('Z')+1};
    f.view.restoreSelection(expected);
    equal(f.view.readSelection().anchor, expected.anchor, 'Unicode backward anchor');
    equal(f.view.readSelection().focus, expected.focus, 'Unicode backward focus');
    equal(f.model.text, source, 'source');
    equal(f.model.undo(), false, 'selection has no history');
  });
  check('exact prior Safari mixed endpoint pairs retain source and direction', function () {
    var cases = [
      {source:'Alpha\n\nBeta',anchor:4,focus:5},
      {source:'A👩🏽‍💻\nZ\n\nBeta',anchor:10,focus:10},
      {source:'A👩🏽‍💻\nZ\n\nBeta',anchor:10,focus:5}
    ];
    for (var item of cases) {
      var f = fixture(item.source);
      f.view.restoreSelection({anchor:item.anchor,focus:item.focus});
      var actual = f.view.readSelection();
      equal(actual.anchor, item.anchor, 'historical anchor ' + item.anchor + ':' + item.focus);
      equal(actual.focus, item.focus, 'historical focus ' + item.anchor + ':' + item.focus);
      equal(document.getSelection().isCollapsed, item.anchor === item.focus, 'native collapsed state');
      equal(f.model.text, item.source, 'historical source identity');
      equal(f.model.undo(), false, 'selection has no history');
    }
  });
  check('directed cross-line selection survives restore and capture', function () {
    var f = fixture('**one**\n\ntwo');
    for (var pair of [[1, 12], [12, 1]]) {
      f.view.restoreSelection({anchor:pair[0],focus:pair[1]});
      var actual = f.view.readSelection();
      equal(actual.anchor, pair[0], 'anchor');
      equal(actual.focus, pair[1], 'focus');
    }
  });
  check('mixed text and empty-line endpoints preserve direction', function () {
    var f = fixture('a\n\nb');
    for (var pair of [[0,4],[4,0],[1,2],[2,1],[2,3],[3,2]]) {
      f.view.restoreSelection({anchor:pair[0],focus:pair[1]});
      var actual = f.view.readSelection();
      equal(actual.anchor, pair[0], 'anchor ' + pair.join(':'));
      equal(actual.focus, pair[1], 'focus ' + pair.join(':'));
    }
  });
  check('ten renders preserve source, revision, and unaffected line nodes', function () {
    var f = fixture('**Words**\nplain');
    var second = f.view.element.querySelectorAll('[data-line]')[1];
    for (var n = 0; n < 10; n++) {
      f.view.render();
      f.view.restoreSelection({anchor:3,focus:12});
      var selection = f.view.readSelection();
      equal(selection.anchor, 3, 'render selection anchor');
      equal(selection.focus, 12, 'render selection focus');
    }
    equal(f.model.text, '**Words**\nplain', 'source');
    equal(f.model.revision, 0, 'revision');
    equal(f.model.undo(), false, 'history empty');
    if (f.view.element.querySelectorAll('[data-line]')[1] !== second) throw Error('unchanged line replaced');
  });
  check('outside DOM endpoint fails closed', function () {
    var f = fixture('hello');
    var failed = false;
    try { f.view.fromDom(document.body, 0, 'after'); } catch (_) { failed = true; }
    if (!failed) throw Error('outside endpoint accepted');
  });
  check('untrusted source is inert text', function () {
    var f = fixture('<img src="https://e.test/x" onerror="alert(1)">');
    equal(f.view.element.querySelectorAll('img,script,iframe').length, 0, 'unsafe nodes');
    equal(f.view.element.textContent, f.model.text, 'literal source');
  });
  check('changed line does not replace unaffected line', function () {
    var f = fixture('**Words**\nplain');
    var second = f.view.element.querySelectorAll('[data-line]')[1];
    f.view.restoreSelection({anchor:4,focus:4});
    f.model.apply({from:2,to:7,insert:'Other',selection:{anchor:7,focus:7},kind:'command',time:1});
    f.view.render();
    if (f.view.element.querySelectorAll('[data-line]')[1] !== second) throw Error('unaffected line replaced');
    equal(f.model.text, '**Other**\nplain', 'source');
    equal(f.view.readSelection().anchor, 7, 'active caret restored');
  });
  check('unchanged line mapping shifts without replacing its DOM', function () {
    var f = fixture('a\nb');
    var second = f.view.element.querySelectorAll('[data-line]')[1];
    f.model.apply({from:0,to:0,insert:'x',selection:{anchor:1,focus:1},kind:'typing',time:1});
    f.view.render();
    if (f.view.element.querySelectorAll('[data-line]')[1] !== second) throw Error('unchanged line replaced');
    var point = f.view.toDom(3, 'after');
    equal(f.view.fromDom(point.node, point.offset, 'after'), 3, 'shifted line offset');
  });
  check('line insertion and deletion keep total source mapping', function () {
    var f = fixture('first\nlast');
    f.model.apply({from:6,to:6,insert:'middle\n',selection:{anchor:13,focus:13},kind:'command',time:1});
    f.view.render();
    equal(f.view.element.querySelectorAll('[data-line]').length, 3, 'inserted line');
    for (var n = 0; n <= f.model.text.length; n++) {
      var point = f.view.toDom(n, 'after');
      equal(f.view.fromDom(point.node, point.offset, 'after'), n, 'insert offset ' + n);
    }
    f.model.undo(); f.view.render();
    equal(f.view.element.querySelectorAll('[data-line]').length, 2, 'removed line');
    equal(f.model.text, 'first\nlast', 'restored source');
  });
  check('a composing projection is never replaced', function () {
    var f = fixture('text');
    var active = f.view.element.querySelector('[data-line]');
    f.view.freeze();
    f.model.apply({from:0,to:0,insert:'x',selection:{anchor:1,focus:1},kind:'composition',time:1});
    var refused = false;
    try { f.view.render(); } catch (error) { refused = error.message === 'composition active'; }
    if (!refused) throw Error('changed source rendered during composition');
    if (f.view.element.querySelector('[data-line]') !== active) throw Error('composing node replaced');
    f.view.unfreeze(); f.view.render();
    equal(f.view.element.textContent, 'xtext', 'committed text');
  });
  function inputEvent(type, inputType, data, cancelable) {
    return new InputEvent(type, {bubbles:true,cancelable:cancelable !== false,inputType:inputType,data:data});
  }
  check('beforeinput typing, Enter, and undo share source history', function () {
    var f = fixture('ab'), input = KipInline.input.create(f.model,f.view);
    f.view.restoreSelection({anchor:0,focus:0});
    var typed = inputEvent('beforeinput','insertText','x');
    f.view.element.dispatchEvent(typed);
    equal(typed.defaultPrevented,true,'typing prevented');
    equal(f.model.text,'xab','typed source');
    f.view.element.dispatchEvent(inputEvent('beforeinput','insertParagraph',null));
    equal(f.model.text,'x\nab','newline source');
    f.view.element.dispatchEvent(inputEvent('beforeinput','historyUndo',null));
    equal(f.model.text,'xab','undo newline');
    f.view.element.dispatchEvent(inputEvent('beforeinput','historyUndo',null));
    equal(f.model.text,'ab','undo typing');
    f.view.element.dispatchEvent(inputEvent('beforeinput','historyRedo',null));
    equal(f.model.text,'xab','redo typing');
    input.detach();
  });
  check('backspace deletes one emoji grapheme in the editable projection', function () {
    var emoji = '👩🏽‍💻', f = fixture(emoji + 'a');
    var input = KipInline.input.create(f.model,f.view);
    f.view.restoreSelection({anchor:emoji.length,focus:emoji.length});
    f.view.element.dispatchEvent(inputEvent('beforeinput','deleteContentBackward',null));
    equal(f.model.text,'a','emoji removed as one grapheme');
    equal(f.model.selection.anchor,0,'caret after removal');
    input.detach();
  });
  check('source and live controls share browser-menu undo history', function () {
    var f = fixture('ab'), source = document.createElement('textarea');
    document.getElementById('editor').appendChild(source);
    var input = KipInline.input.create(f.model,f.view,{source:source});
    f.view.restoreSelection({anchor:0,focus:0});
    f.view.element.dispatchEvent(inputEvent('beforeinput','insertText','x'));
    source.setSelectionRange(1,1);
    source.dispatchEvent(inputEvent('beforeinput','insertText','y'));
    equal(f.model.text,'xyab','shared source');
    source.dispatchEvent(inputEvent('beforeinput','historyUndo',null));
    equal(f.model.text,'ab','source-mode undo of adjacent typing');
    f.view.element.dispatchEvent(inputEvent('beforeinput','historyRedo',null));
    equal(f.model.text,'xyab','live-mode redo');
    input.detach();
  });
  check('HTML paste conversion and plain paste keep exact source', function () {
    var f = fixture(''), input = KipInline.input.create(f.model,f.view,{convertHtml:function(){return '**Bold**';}});
    f.view.restoreSelection({anchor:0,focus:0});
    function paste(html,plain,shift) {
      var event = new Event('paste',{bubbles:true,cancelable:true});
      Object.defineProperty(event,'clipboardData',{value:{getData:function(type){return type==='text/html'?html:plain;}}});
      Object.defineProperty(event,'shiftKey',{value:shift});
      f.view.element.dispatchEvent(event);
      equal(event.defaultPrevented,true,'paste prevented');
    }
    paste('<b>Bold</b>','Bold',false);
    equal(f.model.text,'**Bold**','converted source');
    paste('<script>bad</script>',' plain',true);
    equal(f.model.text,'**Bold** plain','plain source');
    equal(f.view.element.querySelectorAll('script,img').length,0,'inert DOM');
    input.detach();
  });
  check('noncancelable native mutation reconciles one line and fails closed on structure', function () {
    var f = fixture('ab'), failures = [], input = KipInline.input.create(f.model,f.view,{onFailure:function(text){failures.push(text);}});
    f.view.restoreSelection({anchor:1,focus:1});
    var node = f.view.element.querySelector('.ki-token').firstChild;
    node.data = 'axb'; document.getSelection().collapse(node,2);
    f.view.element.dispatchEvent(inputEvent('input','insertText','x',false));
    equal(f.model.text,'axb','reconciled source');
    var line = f.view.element.querySelector('.ki-line');
    line.appendChild(document.createElement('em'));
    f.view.element.dispatchEvent(inputEvent('input','insertText','y',false));
    equal(input.failed,true,'ambiguous DOM failed');
    equal(f.model.text,'axb','source retained');
    equal(failures.length,1,'visible fallback callback');
    input.detach();
  });
  check('root-level native text fails closed without losing its visible copy', function () {
    var f = fixture('ab'), retained = [], input = KipInline.input.create(f.model,f.view,{onFailure:function(text){retained.push(text);}});
    f.view.element.appendChild(document.createTextNode('unexpected'));
    f.view.element.dispatchEvent(inputEvent('input','insertText','unexpected',false));
    equal(input.failed,true,'root mutation blocked');
    equal(f.model.text,'ab','authoritative source unchanged');
    equal(retained[0],'abunexpected','unconverted text retained');
    input.detach();
  });
  check('oversized plain paste is retained for fallback', function () {
    var f = fixture(''), retained = [], input = KipInline.input.create(f.model,f.view,{onFailure:function(text){retained.push(text);}});
    f.view.restoreSelection({anchor:0,focus:0});
    var pasted = 'x'.repeat(2000001), event = new Event('paste',{bubbles:true,cancelable:true});
    Object.defineProperty(event,'clipboardData',{value:{getData:function(type){return type==='text/plain'?pasted:'';}}});
    f.view.element.dispatchEvent(event);
    equal(event.defaultPrevented,true,'native paste blocked');
    equal(input.failed,true,'oversized paste blocked');
    equal(f.model.text,'','source unchanged');
    equal(retained[0].length,pasted.length,'paste retained');
    input.detach();
  });
  check('noncancelable input inside styled text retains Markdown delimiters', function () {
    var f = fixture('**Words**'), input = KipInline.input.create(f.model,f.view);
    f.view.restoreSelection({anchor:4,focus:4});
    var node = f.view.element.querySelector('.ki-strong').firstChild;
    node.data = 'Woxrds'; document.getSelection().collapse(node,3);
    f.view.element.dispatchEvent(inputEvent('input','insertText','x',false));
    equal(f.model.text,'**Woxrds**','source with delimiters');
    equal(f.model.selection.anchor,5,'caret in styled word');
    equal(input.failed,false,'reconciliation succeeded');
    input.detach();
  });
  check('composition freezes then commits once with one undo', function () {
    var f = fixture('ab'), input = KipInline.input.create(f.model,f.view);
    f.view.restoreSelection({anchor:0,focus:2});
    f.view.element.dispatchEvent(new CompositionEvent('compositionstart',{bubbles:true}));
    var node = f.view.element.querySelector('.ki-token').firstChild;
    node.data = '日本'; document.getSelection().collapse(node,2);
    f.view.element.dispatchEvent(inputEvent('input','insertCompositionText','日本',false));
    equal(f.model.text,'ab','composition held');
    f.view.element.dispatchEvent(new CompositionEvent('compositionend',{bubbles:true,data:'日本'}));
    equal(f.model.text,'日本','composition source');
    f.view.element.dispatchEvent(inputEvent('beforeinput','historyUndo',null));
    equal(f.model.text,'ab','one undo');
    input.detach();
  });

  check('package attaches to native form and ten mode toggles preserve source', function () {
    var box = document.getElementById('chapter'), original = '**Words**';
    box.value = original;
    var handle = KipInline.editor.attach(box);
    if (!handle || !box.hidden) throw Error('live editor did not attach');
    var host = box.nextElementSibling;
    if (!host.querySelector('.ki-strong')) throw Error('inline strong missing');
    for (var n = 0; n < 10; n++) {
      host.querySelector('[data-editor-control="source"]').click();
      equal(box.hidden,false,'source visible');
      equal(box.value,original,'source unchanged');
      host.querySelector('[data-editor-control="live"]').click();
      equal(box.hidden,true,'live visible');
    }
    equal(handle.detach(),true,'detach');
    equal(box.hidden,false,'native source restored');
  });
  check('package mirrors source into the native form before submit', function () {
    var box = document.getElementById('chapter'), form = box.form;
    box.value = 'ab';
    var handle = KipInline.editor.attach(box), host = box.nextElementSibling;
    var live = host.querySelector('.ki-document');
    var point = live.querySelector('.ki-token').firstChild;
    document.getSelection().collapse(point,0);
    live.dispatchEvent(inputEvent('beforeinput','insertText','x'));
    var submit = new Event('submit',{bubbles:true,cancelable:true});
    var blockedByEditor = null;
    form.addEventListener('submit',function (event) {
      blockedByEditor = event.defaultPrevented;
      event.preventDefault(); // The fixture has no save endpoint.
    },{once:true});
    form.dispatchEvent(submit);
    equal(blockedByEditor,false,'valid submit allowed');
    equal(box.value,'xab','exact form value');
    equal(handle.detach(),true,'detach');
  });
  check('package failure retains uncertain text and blocks stale Save', function () {
    var box = document.getElementById('chapter'), form = box.form;
    box.value = 'ab';
    var handle = KipInline.editor.attach(box), host = box.nextElementSibling;
    host.querySelector('.ki-document').appendChild(document.createTextNode('uncertain'));
    host.querySelector('.ki-document').dispatchEvent(inputEvent('input','insertText','uncertain',false));
    equal(handle.mode,'source','source fallback');
    equal(box.hidden,false,'source visible');
    equal(host.querySelector('[data-editor-control="copy"]').value,'abuncertain','copy retained');
    var blocked = new Event('submit',{bubbles:true,cancelable:true});
    var staleBlockedByEditor = null;
    form.addEventListener('submit',function (event) {
      staleBlockedByEditor = event.defaultPrevented;
      event.preventDefault();
    },{once:true});
    form.dispatchEvent(blocked);
    equal(staleBlockedByEditor,true,'stale Save blocked');
    equal(handle.detach(),false,'cannot silently detach unresolved input');
    box.value = 'author corrected';
    box.dispatchEvent(new Event('input',{bubbles:true}));
    var saved = new Event('submit',{bubbles:true,cancelable:true});
    var correctedBlockedByEditor = null;
    form.addEventListener('submit',function (event) {
      correctedBlockedByEditor = event.defaultPrevented;
      event.preventDefault();
    },{once:true});
    form.dispatchEvent(saved);
    equal(correctedBlockedByEditor,false,'corrected source can save');
    equal(box.value,'author corrected','corrected source retained');
    equal(handle.detach(),true,'detach after correction');
  });
  check('source-input failure stays blocked until a later distinct edit', function () {
    var box = document.getElementById('chapter'), form = box.form;
    box.value = 'ab';
    var handle = KipInline.editor.attach(box);
    box.nextElementSibling.querySelector('[data-editor-control="source"]').click();
    box.value = 'x'.repeat(2000001);
    box.dispatchEvent(new Event('input',{bubbles:true}));
    var copy = box.nextElementSibling.querySelector('[data-editor-control="copy"]');
    equal(copy.value.length,2000001,'uncertain oversized source retained');
    equal(box.value,'ab','authoritative source restored');
    var blockedByEditor = null;
    form.addEventListener('submit',function (event) {
      blockedByEditor = event.defaultPrevented; event.preventDefault();
    },{once:true});
    form.dispatchEvent(new Event('submit',{bubbles:true,cancelable:true}));
    equal(blockedByEditor,true,'same input event cannot unlock Save');
    box.value = 'author corrected';
    box.dispatchEvent(new Event('input',{bubbles:true}));
    equal(handle.detach(),true,'author correction permits detach');
  });
  check('package leaves oversize and unavailable native source usable', function () {
    var box = document.getElementById('chapter');
    box.value = 'x'.repeat(2000001);
    equal(KipInline.editor.attach(box),null,'oversize fallback');
    equal(box.hidden,false,'native textarea visible');
    box.value = '**Words**';
    var prior = KipInline.projection.create;
    KipInline.projection.create = function () { throw Error('selection unavailable'); };
    try { equal(KipInline.editor.attach(box),null,'capability fallback'); }
    finally { KipInline.projection.create = prior; }
    equal(box.hidden,false,'native textarea remains visible');
  });
  check('package repeated attach and detach does not duplicate controls', function () {
    var box = document.getElementById('chapter');
    box.value = 'plain';
    var first = KipInline.editor.attach(box);
    equal(KipInline.editor.attach(box),first,'idempotent attach');
    equal(document.querySelectorAll('.ki-editor').length,1,'one host');
    equal(first.detach(),true,'first detach');
    var second = KipInline.editor.attach(box);
    if (!second || second === first) throw Error('new attach missing');
    equal(document.querySelectorAll('.ki-editor').length,1,'one new host');
    equal(second.detach(),true,'second detach');
  });
  check('native required validation reveals the source textarea', function () {
    var box = document.getElementById('chapter');
    box.value = '';
    var handle = KipInline.editor.attach(box);
    equal(box.hidden,true,'live mode starts visible');
    equal(box.form.reportValidity(),false,'required validation fails');
    equal(handle.mode,'source','source shown for invalid field');
    equal(box.hidden,false,'native field visible');
    equal(handle.detach(),true,'detach after validation');
  });

  if (!new URLSearchParams(location.search).has('run')) {
    fixture('**Words** remain styled while this line is active.\nA second *paragraph* stays readable.');
    return;
  }
  var pass = 0, fail = 0;
  tests.forEach(function (test) {
    var item = document.createElement('li');
    try { test.fn(); item.textContent = 'Pass: ' + test.name; pass++; }
    catch (error) { item.textContent = 'Fail: ' + test.name + ': ' + error.message; fail++; }
    result.appendChild(item);
  });
  summary.textContent = pass + ' Pass, ' + fail + ' Fail';
  summary.dataset.pass = String(pass); summary.dataset.fail = String(fail);
})();
