'use strict';
(async function () {
  var actions = await (await fetch('/tests/fixtures/editor-actions.json')).json();
  var labels = {text:'Chapter Text', mode:'Editing Mode', source:'Source', formatted:'Formatted', editSelection:'Edit Selection in Source', scene:'Scene Break', tooLarge:'Use Source for this large chapter. Your text is still editable.', unavailable:'Formatted mode is unavailable. You can continue in Source.', inputFallback:'The last input needs review. Its text is shown below; Source retains the previous version.', unconverted:'Unconverted Input'};
  var select = document.getElementById('fixture'), editors = [];
  actions.forEach(function(action,i){var option=document.createElement('option');option.value=i;option.textContent=action.name;select.append(option);});
  function button(host, label, action) {var b=document.createElement('button');b.type='button';b.textContent=label;b.addEventListener('click',action);host.append(b);return b;}
  ['a','b'].forEach(function(candidate) {
    var form=document.createElement('form'),heading=document.createElement('h2'),label=document.createElement('label'),box=document.createElement('textarea'),snapshot=document.createElement('pre'),status=document.createElement('p');
    heading.textContent=candidate==='a'?'Candidate A: Native Textarea':'Candidate B: Plaintext Contenteditable';
    label.append(document.createTextNode('Chapter Text'),box);box.name='content';box.required=true;box.rows=8;
    box.dataset.jsModule='paste editor';box.dataset.editorLabels=JSON.stringify({bold:'Bold',italic:'Italic',link:'Add Link',quote:'Quote',break:'Scene Break'});
    form.dataset.contentRequired='Enter chapter text before saving.';form.append(heading,label);document.getElementById('candidates').append(form);
    Kip.paste.init(form);Kip.editorInit(document);
    var writer;
    try {writer=Kip.richEditor.attach(box,{candidate:candidate,labels:labels});}
    catch (_) {status.textContent=labels.unavailable;form.append(status);return;}
    function history(redo){writer.activeControl.dispatchEvent(new KeyboardEvent('keydown',{key:'z',ctrlKey:true,shiftKey:redo,bubbles:true,cancelable:true}));update();}
    button(form,'Undo',()=>history(false));button(form,'Redo',()=>history(true));
    var save=button(form,'Check Native Save',function(){});save.type='submit';status.setAttribute('role','status');
    var detail=document.createElement('details'),summary=document.createElement('summary');summary.textContent='Exact Source';detail.append(summary,snapshot);form.append(status,detail);
    function update(){snapshot.textContent=JSON.stringify(box.value);}
    form.addEventListener('input',update);form.addEventListener('submit',function(e){e.preventDefault();status.textContent='Draft checked. Nothing was saved or sent.';update();});
    editors.push({writer:writer,box:box,update:update});
  });
  function load(){var action=actions[select.value];editors.forEach(function(item){item.writer.showSource();item.writer.replace(action.source,'fixture');Kip.richActive.setTextSelection(item.box,action.selection);item.writer.showFormatted();item.update();});}
  document.getElementById('load-fixture').addEventListener('click',load);load();
  document.getElementById('run-tests').addEventListener('click',async function(){
    var result=await runEditorDOMTests(Kip,actions),body=document.getElementById('results');body.replaceChildren();
    result.tests.forEach(function(test){var row=document.createElement('tr'),name=document.createElement('td'),outcome=document.createElement('td');name.textContent=test.name;outcome.textContent=test.pass?'Pass':'Fail: '+test.error;row.append(name,outcome);body.append(row);});
    var passed=result.tests.filter(function(test){return test.pass;}).length;
    document.getElementById('dom-summary').textContent=passed+' Pass, '+(result.tests.length-passed)+' Fail';
    document.getElementById('dom-failures').textContent=result.tests.filter(function(test){return !test.pass;}).map(function(test){return test.name+': '+test.error;}).join('\n');
    window.editorDOMResult=result;
  });
  var probe=document.getElementById('paste-probe'),pasteResult=document.getElementById('paste-result');
  probe.addEventListener('paste',function(e){
    var expected=e.clipboardData && e.clipboardData.getData('text/plain');
    setTimeout(function(){
      var pass=false;
      try {pass=expected!==null && Kip.richActive.readText(probe)===expected && !probe.querySelector('b,strong,i,em,a,img,style,script');}catch(_){}
      pasteResult.textContent=pass?'Pass: The browser pasted plain text.':'Fail: Candidate B is unavailable in this browser.';
      pasteResult.dataset.pass=String(pass);
      if(!pass && editors[1]) {editors[1].writer.destroy();editors[1].box.hidden=false;}
    },0);
  });
  window.editorComparison={editors:editors,actions:actions};
})();
