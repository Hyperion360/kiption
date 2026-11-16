/* Focused DOM contracts, run from the isolated local comparison. These synthetic
 * events do not establish native IME, browser-menu Undo, or device acceptance. */
async function runEditorDOMTests(K, actions) {
  var results = [], disposers = [];
  function equal(actual, expected, message) { if (JSON.stringify(actual) !== JSON.stringify(expected)) throw Error(message + ': ' + JSON.stringify(actual)); }
  function assert(value, message) { if (!value) throw Error(message); }
  async function test(name, run) {
    try { await run(); results.push({name: name, pass: true}); }
    catch (e) { results.push({name: name, pass: false, error: e.message}); }
    finally { while (disposers.length) disposers.pop()(); }
  }
  var labels = {text:'Chapter Text', mode:'Editing Mode', source:'Source', formatted:'Formatted', editSelection:'Edit Selection in Source', scene:'Scene Break', tooLarge:'Use Source for this large chapter.', unavailable:'Formatted mode is unavailable.', inputFallback:'Review the unconverted input.', unconverted:'Unconverted Input'};
  function fixture(text, candidate, options) {
    var form = document.createElement('form');
    form.innerHTML = '<label>Chapter Text<textarea name="content" required data-js-module="paste editor" data-editor-labels="{&quot;bold&quot;:&quot;Bold&quot;}"></textarea></label><button type="submit">Save</button>';
    form.dataset.contentRequired = 'Enter chapter text before saving.';
    document.body.append(form); var box = form.elements.content; box.value = text; box.setSelectionRange(0,0);
    box.spellcheck = false; box.lang = 'fr';box.style.font='17px serif';box.style.lineHeight='1.4';
    K.paste.init(form); K.editorInit(document);
    var posts = 0;
    disposers.push(function() { if (box.kipWriter) box.kipWriter.destroy(); form.remove(); });
    var editor = K.richEditor.attach(box, Object.assign({candidate:candidate, labels:labels, Kip:K}, options));
    form.addEventListener('submit', function(e) { if (!e.defaultPrevented) posts++; e.preventDefault(); });
    return {form:form, box:box, editor:editor, posts:function(){return posts;}};
  }
  function key(control, name, options) { control.dispatchEvent(new KeyboardEvent('keydown', Object.assign({key:name, bubbles:true, cancelable:true}, options))); }
  function transferEvent(type, data) {
    // Firefox does not retain clipboardData passed to ClipboardEvent's constructor.
    // This is an adapter fixture; native paste is checked separately in the probe.
    var e=new Event(type,{bubbles:true,cancelable:true});
    Object.defineProperty(e,type==='paste'?'clipboardData':'dataTransfer',{value:data});return e;
  }
  function input(f, text, selection, type, composing) {
    var control = f.editor.activeControl;
    if (control.tagName === 'TEXTAREA') {control.value = text; control.setSelectionRange(selection.anchor, selection.focus);}
    else {control.replaceChildren(document.createTextNode(text)); document.getSelection().setBaseAndExtent(control.firstChild, selection.anchor, control.firstChild, selection.focus);}
    control.dispatchEvent(new InputEvent('input', {bubbles:true, inputType:type || 'insertText', isComposing:!!composing}));
  }
  await test('projection preserves source, uses inert nodes, and exposes exact visible boundaries', function() {
    for (var raw of ['**bold** and _word_ [site](https://example.org/a_b)', '**outer _inner_**', '*outer **inner** outer*', '[**bold**](https://example.org)', '\\*literal*', '![image](https://example.org/x)', '<img src=x onerror=alert(1)>']) {
      var part = K.richProjection.renderSpan(document, raw, {type:'p', start:0, end:raw.length}, K.subset, labels);
      assert(!part.element.querySelector('a,img,script,iframe,[href],[src],[style]'), 'no active/resource elements');
      if (/outer|literal|image|\[\*\*/.test(raw)) equal(part.element.textContent, raw, 'ambiguous span stays wholly raw');
      else if (raw.startsWith('**bold')) equal(part.element.textContent, 'bold and word site', 'simple formatting');
      equal(part.point(document.body, 0), null, 'outside has no mapping');
      equal(part.point(part.element, 0), 0, 'outer boundary maps to source start');
      equal(part.point(part.element, part.element.childNodes.length), raw.length, 'outer boundary maps to source end');
      part.shift(3); equal(part.start, 3, 'offset shifts with earlier edits');
    }
    var mapped=K.richProjection.renderSpan(document,'**Bold**',{type:'p',start:0,end:8},K.subset,labels),strong=mapped.element.querySelector('strong');
    equal(mapped.point(strong,0),2,'label start maps after delimiter');equal(mapped.point(strong,1),6,'label end maps before delimiter');
    var quote = K.richProjection.renderSpan(document, '> One\n> Two', {type:'quote',start:0,end:11},K.subset,labels);
    equal(quote.element.textContent,'One\nTwo','quote prefixes display only');
    var scene = K.richProjection.renderSpan(document,'---',{type:'break',start:0,end:3},K.subset,labels);
    equal(scene.element.getAttribute('aria-label'),'Scene Break','scene label');
  });
  await test('plaintext walker and selection use identical structural newline boundaries', function() {
    var el = document.createElement('div'); document.body.append(el); disposers.push(()=>el.remove());
    for (var pair of [['<div><br></div>',''],['A<br>B','A\nB'],['<div>A</div><div>B</div>','A\nB'],['<div>A</div>B','A\nB'],['A<div>B</div>','A\nB'],['<div>A</div><div><br></div>','A\n'],['<span>日<span>本</span></span>','日本'],['A<br><br>','A\n\n']]) {el.innerHTML=pair[0]; equal(K.richActive.readText(el),pair[1],pair[0]);}
    el.innerHTML='<div>A</div><div>B</div>';
    equal(K.richActive.offset(el,el.lastChild.firstChild,0),2,'caret before second block includes newline');
    equal(K.richActive.offset(el,el,1),2,'parent boundary before second block includes newline');
    equal(K.richActive.offset(el,el.lastChild.firstChild,1),3,'caret after second block');
    el.innerHTML='<img>'; var failed=false; try {K.richActive.readText(el);}catch(_){failed=true;} assert(failed,'unsupported nodes rejected');
  });
  await test('plaintext empty-line refocus and selection fallback preserve structural boundaries', function() {
    var control=K.richActive.create(document,'b',labels);document.body.append(control.element);disposers.push(()=>control.element.remove());
    control.set('A\n',{anchor:2,focus:2},true);control.element.innerHTML='<div>A</div><div><br></div>';
    document.getSelection().setBaseAndExtent(control.element.lastChild,0,control.element.lastChild,0);equal(control.selection(),{anchor:2,focus:2},'blank last line offset');
    document.getElementById('fixture').focus();control.element.focus();equal(control.selection(),{anchor:2,focus:2},'blank last line restored');
    var selection=document.getSelection(),saved=selection.setBaseAndExtent;
    try {selection.setBaseAndExtent=undefined;control.set('backward',{anchor:7,focus:1},true);equal(control.selection(),{anchor:7,focus:1},'collapse and extend fallback');}
    finally {selection.setBaseAndExtent=saved;}
    var failed=false;try{K.richActive.create(document,'unknown',labels);}catch(_){failed=true;}assert(failed,'unknown adapter rejected');
  });
  for (var candidate of ['a','b']) {
    await test(candidate + ': action fixtures preserve exact source and backward selection', function() {
      for (var action of actions) {
        var f=fixture(action.source,candidate); K.richActive.setTextSelection(f.box,action.selection);
        for(var mode of action.actions) f.editor[mode==='source'?'showSource':'showFormatted']();
        equal(f.editor.model.text,action.expected,'source identity'); equal(f.editor.model.selection,action.selection,'selection identity');
        equal(f.editor.model.undo(),false,'toggles have no history');
        assert(!f.form.querySelector('.writer-modes').closest('label'),'controls outside content label');
      }
    });
    await test(candidate + ': active selection stays available to the existing selection counter', function() {
      var f=fixture('Alpha',candidate);f.editor.showFormatted();var c=f.editor.activeControl;
      if(candidate==='a')c.setSelectionRange(1,4,'backward');else document.getSelection().setBaseAndExtent(c.firstChild,4,c.firstChild,1);
      c.dispatchEvent(new PointerEvent('pointerup',{bubbles:true}));
      equal(K.richActive.textSelection(f.box),{anchor:4,focus:1},'original selection tracks raw active selection');
      equal(f.editor.model.undo(),false,'selection adds no transaction');
    });
    await test(candidate + ': active edits keep the control and send one source input', function() {
      var f=fixture('First\n\nSecond',candidate);f.editor.showFormatted();var active=f.editor.activeControl, events=0;
      f.box.addEventListener('input',()=>events++); input(f,'First\nNew',{anchor:9,focus:9});
      assert(f.editor.activeControl === active, 'active subtree retained'); equal(events,1,'one source input');
      equal(f.box.value,'First\nNew\n\nSecond','submission source current');
      equal(getComputedStyle(active).fontSize,getComputedStyle(f.box).fontSize,'font size inherited');equal(getComputedStyle(active).lineHeight,getComputedStyle(f.box).lineHeight,'line height inherited');
      equal(active.spellcheck,false,'spellcheck inherited');equal(active.lang,'fr','language inherited');
      var second=f.form.querySelector('.writer-block:last-child');var s=document.getSelection();s.setBaseAndExtent(second.firstChild,0,second.firstChild,0);
      second.dispatchEvent(new PointerEvent('pointerup',{bubbles:true}));
      equal(f.editor.model.selection,{anchor:11,focus:11},'shifted later paragraph boundary');
      key(f.editor.activeControl,'z',{ctrlKey:true});equal(f.box.value,'First\n\nSecond','one undo restores edit');
    });
    await test(candidate + ': toolbar, shortcuts, source edits, and history share a journal', function() {
      var f=fixture('Alpha\n\nBeta',candidate);f.box.setSelectionRange(0,5);f.editor.showFormatted();
      var bold=f.form.querySelector('.editor-btn');bold.dispatchEvent(new PointerEvent('pointerdown',{bubbles:true}));bold.focus();bold.click();
      equal(f.box.value,'**Alpha**\n\nBeta','toolbar selection retained');
      f.editor.showSource();key(f.box,'z',{ctrlKey:true});equal(f.box.value,'Alpha\n\nBeta','source undo formatted command');
      key(f.box,'y',{ctrlKey:true});equal(f.box.value,'**Alpha**\n\nBeta','redo command');
      f.editor.showFormatted();key(f.editor.activeControl,'z',{metaKey:true});equal(f.box.value,'Alpha\n\nBeta','formatted undo');
      f.editor.showSource();f.box.setSelectionRange(0,0);input(f,'QAlpha\n\nBeta',{anchor:1,focus:1});
      f.editor.showFormatted();key(f.editor.activeControl,'z',{ctrlKey:true});equal(f.box.value,'Alpha\n\nBeta','formatted undo source input');
      key(f.editor.activeControl,'Escape');equal(document.activeElement.textContent,labels.source,'Escape returns to mode control');
      var tab=new KeyboardEvent('keydown',{key:'Tab',cancelable:true});f.editor.activeControl.dispatchEvent(tab);assert(!tab.defaultPrevented,'Tab leaves editor');
    });
    await test(candidate + ': composition is one edit and native Save flushes the pending source', function() {
      var f=fixture('Start',candidate);f.box.setSelectionRange(5,5);f.editor.showFormatted();var active=f.editor.activeControl;
      active.dispatchEvent(new CompositionEvent('compositionstart',{bubbles:true}));input(f,'Start日',{anchor:6,focus:6},'insertCompositionText',true);
      equal(f.editor.model.text,'Start','no partial composition history');equal(f.box.value,'Start日','submission stays current');
      key(active,'b',{ctrlKey:true,isComposing:true});f.form.requestSubmit();equal(f.posts(),1,'native valid Save');equal(f.editor.model.text,'Start日','submit flush');
      active.dispatchEvent(new CompositionEvent('compositionend',{bubbles:true}));key(f.editor.activeControl,'z',{ctrlKey:true});equal(f.box.value,'Start','one undo for composition');
    });
    await test(candidate + ': empty native Save reveals and focuses the invalid source', function() {
      var f=fixture('',candidate);f.editor.showFormatted();f.form.requestSubmit();equal(f.posts(),0,'invalid form cannot submit');
      assert(!f.box.hidden&&document.activeElement===f.box,'source visible and focused');assert(f.box.validity.valueMissing,'native required retained');
      input(f,'Valid text',{anchor:10,focus:10});f.form.requestSubmit();equal(f.posts(),1,'valid form submits');
    });
    await test(candidate + ': paste and text drop use one safe transaction', function() {
      var f=fixture('End',candidate);f.box.setSelectionRange(0,0);f.editor.showFormatted();var dt=new DataTransfer();
      dt.setData('text/html','<b>Bold</b><img src="https://example.org/x">');dt.setData('text/plain','Bold');
      f.editor.activeControl.dispatchEvent(transferEvent('paste',dt));equal(f.box.value,'**Bold**End','existing inert converter');
      key(f.editor.activeControl,'z',{ctrlKey:true});equal(f.box.value,'End','paste one undo');
      key(f.editor.activeControl,'v',{ctrlKey:true,shiftKey:true});f.editor.activeControl.dispatchEvent(transferEvent('paste',dt));equal(f.box.value,'BoldEnd','shift paste plain');
      key(f.editor.activeControl,'z',{ctrlKey:true});f.editor.activeControl.dispatchEvent(transferEvent('drop',dt));equal(f.box.value,'BoldEnd','drop plain');
      key(f.editor.activeControl,'z',{ctrlKey:true});equal(f.box.value,'End','drop one undo');
    });
    await test(candidate + ': noncancelable native Undo reconciles once', function() {
      var f=fixture('Original',candidate);f.editor.showFormatted();input(f,'OriginalQ',{anchor:9,focus:9});var c=f.editor.activeControl;
      c.dispatchEvent(new InputEvent('beforeinput',{inputType:'historyUndo',cancelable:false}));input(f,'Original',{anchor:8,focus:8},'historyUndo');equal(f.box.value,'Original','one undo result');
      key(f.editor.activeControl,'y',{ctrlKey:true});equal(f.box.value,'OriginalQ','redo retains shared operation');
    });
    await test(candidate + ': backward cross-block selection opens exact source range without history', function() {
      var f=fixture('Active\n\n**One**\n\nTwo',candidate);f.editor.showFormatted();
      var blocks=f.form.querySelectorAll('.writer-p'),first=blocks[0].querySelector('strong').firstChild,last=blocks[1].firstChild;
      document.getSelection().setBaseAndExtent(last,3,first,0);
      var button=Array.from(f.form.querySelectorAll('button')).find(b=>b.textContent===labels.editSelection);button.click();
      equal(f.editor.model.selection,{anchor:20,focus:10},'explicit visible boundary range');equal(f.editor.model.undo(),false,'no selection history');
      equal(K.richActive.textSelection(f.box),{anchor:20,focus:10},'backward source selection');
    });
    await test(candidate + ': scene-break activation has a source boundary without a text caret', function() {
      var f=fixture('First\n\n---\n\nLast',candidate);f.editor.showFormatted();
      document.getSelection().removeAllRanges();f.form.querySelector('.writer-break hr').dispatchEvent(new PointerEvent('pointerup',{bubbles:true}));
      equal(f.editor.model.selection,{anchor:7,focus:7},'scene boundary');
      equal(f.editor.activeControl.tagName==='TEXTAREA'?f.editor.activeControl.value:K.richActive.readText(f.editor.activeControl),'---','scene source editable');
    });
    await test(candidate + ': Shift activation extends the raw region and keyboard boundaries preserve direction', function() {
      var f=fixture('One\n\nTwo',candidate);f.box.setSelectionRange(1,1);f.editor.showFormatted();
      var last=f.form.querySelector('.writer-block:last-child');
      last.dispatchEvent(new PointerEvent('pointerdown',{bubbles:true,shiftKey:true}));
      document.getSelection().setBaseAndExtent(last.firstChild,2,last.firstChild,2);
      last.dispatchEvent(new PointerEvent('pointerup',{bubbles:true,shiftKey:true}));
      equal(f.editor.model.selection,{anchor:1,focus:7},'shift range retains anchor');
      equal(f.editor.activeControl.tagName==='TEXTAREA'?f.editor.activeControl.value:K.richActive.readText(f.editor.activeControl),'One\n\nTwo','whole selected raw region');
      f.editor.showSource();f.box.setSelectionRange(0,0);f.editor.showFormatted();key(f.editor.activeControl,'ArrowRight',{ctrlKey:false});
      f.editor.showSource();f.box.setSelectionRange(0,0);f.editor.showFormatted();
      if(candidate==='a')f.editor.activeControl.setSelectionRange(3,3);else document.getSelection().setBaseAndExtent(f.editor.activeControl.firstChild,3,f.editor.activeControl.firstChild,3);
      key(f.editor.activeControl,'ArrowRight',{shiftKey:true});
      equal(f.editor.model.selection,{anchor:3,focus:4},'shift boundary extends without collapse');
    });
    await test(candidate + ': two-instance refocus preserves the last intentional caret', async function() {
      var one=fixture('First paragraph',candidate),two=fixture('Second paragraph',candidate);one.box.setSelectionRange(6,6);one.editor.showFormatted();two.editor.showFormatted();
      one.editor.activeControl.focus();await Promise.resolve();equal(one.editor.model.selection,{anchor:6,focus:6},'saved source caret');
      var actual=candidate==='a'?K.richActive.textSelection(one.editor.activeControl):{anchor:K.richActive.offset(one.editor.activeControl,document.getSelection().anchorNode,document.getSelection().anchorOffset),focus:K.richActive.offset(one.editor.activeControl,document.getSelection().focusNode,document.getSelection().focusOffset)};
      equal(actual,{anchor:6,focus:6},'visible caret restored');
    });
  }
  await test('unsupported plaintext input preserves the unconverted text and disables formatted mode', function() {
    var f=fixture('Original','b');f.editor.showFormatted();f.editor.activeControl.innerHTML='<strong>Changed</strong>';
    f.editor.activeControl.dispatchEvent(new InputEvent('input',{bubbles:true}));assert(!f.box.hidden,'source fallback');equal(f.box.value,'Original','previous source preserved');
    equal(f.form.querySelector('[aria-label="Unconverted Input"]').value,'Changed','unconverted text available');f.editor.showFormatted();assert(!f.box.hidden,'failed candidate remains off');
  });
  await test('unsupported composing structure prevents a stale Save and leaves a source fallback', function() {
    var f=fixture('Original','b');f.editor.showFormatted();var c=f.editor.activeControl;
    c.dispatchEvent(new CompositionEvent('compositionstart',{bubbles:true}));c.innerHTML='<strong>Changed</strong>';
    f.form.requestSubmit();equal(f.posts(),0,'failed composition blocks this submit');assert(!f.box.hidden,'source fallback visible');
    equal(f.form.querySelector('[aria-label="Unconverted Input"]').value,'Changed','unconverted composition retained');
  });
  await test('projection failure leaves native source usable and no duplicate generated controls', function() {
    var broken=Object.assign({},K,{richProjection:{spans:function(){throw Error('projection unavailable');}}});
    var f=fixture('Source','a',{Kip:broken});f.editor.showFormatted();assert(!f.box.hidden,'source fallback');equal(f.box.value,'Source','source retained');
    f.editor.destroy();assert(!f.form.querySelector('.writer-rich'),'destroy removes controls');assert(f.box.required,'required retained');
  });
  await test('rich limit preserves source, native Save, and history',function(){
    var f=fixture('Short','a');f.editor.showFormatted();f.editor.replace('x'.repeat(2000001),'paste');assert(!f.box.hidden,'limit switches to source');equal(f.box.value.length,2000001,'large source retained');key(f.box,'z',{ctrlKey:true});equal(f.box.value,'Short','large edit undo');
  });
  return {pass:results.every(row=>row.pass), tests:results};
}
