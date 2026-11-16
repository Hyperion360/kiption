'use strict';
async function runDraftRecoveryDOMTests(K) {
  var results=[], disposers=[];
  function assert(value,message){if(!value)throw Error(message);}
  function equal(a,b,message){assert(JSON.stringify(a)===JSON.stringify(b),message+'; got '+JSON.stringify(a));}
  async function test(name,run){try{await run();results.push({name:name,pass:true});}catch(e){results.push({name:name,pass:false,error:e.message});}finally{while(disposers.length)disposers.pop()();}}
  var labels={retry:'Try Recovery Again',words:'{n} words',selection:'{n} selected words',unsaved:'You have unsaved changes.',saved:'Changes saved.',saving:'Saving…',
    text:'Chapter Text',mode:'Editing Mode',source:'Source',formatted:'Formatted',editSelection:'Edit Selection in Source',scene:'Scene Break',
    tooLarge:'Formatted mode is unavailable for this large chapter.',unavailable:'Formatted mode is unavailable.',inputFallback:'Input needs review in Source.',
    compare:'Compare Versions',archive:'Archive Version',recovery:'Recovery Copy',enable:'Enable Recovery in This Tab',disable:'Turn Off Recovery',
    restore:'Restore Draft',replaceUnsaved:'Replace Current Unsaved Text',useDraft:'Use Draft as New Version',discard:'Discard Draft',copy:'Copy Draft',
    recoveryUnavailable:'Recovery is unavailable. Keep this tab open until you save.',recoveryProtected:'Recovery is off for password-protected chapters.',
    recoveryOff:'Recovery is off for this tab.',recoveryTab:'Recovery copies stay in this tab.',recoveryOffer:'A recovery copy is available.',
    recoveryConflict:'The archive has a newer version.',recoveryDiscarded:'Recovery copy discarded.',recoveryRestored:'Draft restored. Save to keep these changes on the archive.',
    recoveryRemoveFailed:'The browser could not remove this recovery copy. It remains available below.',recoveryProtectedRetained:'Protected recovery is off, but existing recovery data could not be removed.',recoveryClearFailed:'Recovery is off on this page, but stored data could not be removed.',recoveryWritten:'Recovery copy saved in this tab.',copyFallback:'Select and copy the recovery text.',copied:'Recovery text copied.',focus:'Focus Mode',typewriter:'Typewriter Scrolling'};
  function fixture(source){
    var form=document.createElement('form');
    form.innerHTML='<input name="title" value="Title"><textarea name="content" required></textarea><textarea name="notes_before"></textarea><textarea name="notes_after"></textarea><input name="publish_at"><input name="base_revision" type="hidden"><input name="access_password" type="password"><input name="access_clear" type="checkbox" value="1"><button type="submit">Save</button><span data-writer-state></span><output data-writer-count></output><button data-writer-count-selection type="button">Count Selection</button><span data-writer-selection></span>';
    form.dataset.writerLabels=JSON.stringify(labels);form.dataset.writerOwner='5';form.dataset.writerChapter='chapter:42';form.dataset.writerBase='a'.repeat(64);
    form.dataset.writerCheckUrl='/chapter/edit/example/1';form.dataset.writerProtected='0';form.dataset.contentRequired='Enter chapter text before saving.';
    form.elements.content.defaultValue=source;form.elements.content.value=source;form.elements.base_revision.value='a'.repeat(64);form.dataset.writerServer=JSON.stringify(K.draftForm.values(form));
    form.addEventListener('submit',function(e){e.preventDefault();});document.body.appendChild(form);disposers.push(function(){form.remove();});return form;
  }
  function memory(){var map=new Map(),reads=[],writes=[];return{reads:reads,writes:writes,get length(){return map.size;},key:i=>Array.from(map.keys())[i],getItem:k=>{reads.push(k);return map.has(k)?map.get(k):null;},setItem:(k,v)=>{writes.push(k);map.set(k,v);},removeItem:k=>map.delete(k)};}
  function timed(){var task=null;return{Event:Event,HTMLFormElement:HTMLFormElement,DOMParser:DOMParser,location:location,navigator:navigator,
    setTimeout:fn=>{task=fn;return 1;},clearTimeout:()=>{task=null;},addEventListener:window.addEventListener.bind(window),removeEventListener:window.removeEventListener.bind(window),
    flush:()=>{var fn=task;task=null;if(fn)fn();}};}
  function click(host,label){var button=Array.from(host.querySelectorAll('button')).find(b=>b.textContent===label);assert(button,'missing '+label);button.click();}
  await test('recovery reads no prose before consent and never overwrites new typing silently',function(){
    var form=fixture('Archive'),store=memory(),clock=timed(),key=K.draftStore.key('5','chapter:42');
    K.draftStore.write(store,key,'a'.repeat(64),K.draftStore.fields({content:'Recovery'}),1000);store.reads.length=0;
    var adapter=K.draftForm.attach(form,{store:store,window:clock,api:K.draftStore,now:()=>1001});disposers.push(()=>adapter.destroy());
    assert(!store.reads.includes(key),'no prose read before consent');
    click(adapter.host,labels.enable);assert(store.reads.includes(key),'consent opens matching record');
    form.elements.content.value='Typed now';form.elements.content.dispatchEvent(new Event('input',{bubbles:true}));
    click(adapter.host,labels.restore);equal(form.elements.content.value,'Typed now','first action does not replace typing');
    click(adapter.host,labels.replaceUnsaved);equal(form.elements.content.value,'Recovery','explicit replacement');
    clock.flush();assert(!store.getItem(key).includes('access_password'),'no password stored');
    click(adapter.host,labels.disable);assert(!K.draftStore.enabled(store,'5'),'consent removed');equal(store.getItem(key),null,'draft removed');
  });
  await test('failed discard and protected cleanup report retained recovery data',function(){
    var form=fixture('Archive'),store=memory(),clock=timed(),key=K.draftStore.key('5','chapter:42');
    K.draftStore.setEnabled(store,'5',true);K.draftStore.write(store,key,'a'.repeat(64),K.draftStore.fields({content:'Recovery'}),1000);
    store.removeItem=function(){throw Error('removal denied');};
    var adapter=K.draftForm.attach(form,{store:store,window:clock,api:K.draftStore,now:()=>1001});disposers.push(()=>adapter.destroy());
    click(adapter.host,labels.discard);assert(adapter.host.textContent.includes(labels.recoveryRemoveFailed),'discard failure visible');
    assert(Array.from(adapter.host.querySelectorAll('button')).some(b=>b.textContent===labels.copy),'copy ability retained');assert(store.getItem(key)!==null,'copy still stored');
    form.elements.access_password.value='lock';form.elements.access_password.dispatchEvent(new Event('input',{bubbles:true}));
    assert(adapter.host.textContent.includes(labels.recoveryProtectedRetained),'protection cleanup failure visible');
    equal(form.elements.content.value,'Archive','original page content untouched');
  });
  await test('matching server-copy deletion failure does not hide the retained record',function(){
    var form=fixture('Archive'),store=memory(),key=K.draftStore.key('5','chapter:42');K.draftStore.setEnabled(store,'5',true);
    K.draftStore.write(store,key,'a'.repeat(64),K.draftForm.values(form),1000);store.removeItem=function(){throw Error('denied');};
    var adapter=K.draftForm.attach(form,{store:store,window:timed(),api:K.draftStore,now:()=>1001});disposers.push(()=>adapter.destroy());
    assert(adapter.host.textContent.includes(labels.recoveryRemoveFailed),'ack cleanup failure visible');
    assert(Array.from(adapter.host.querySelectorAll('button')).some(b=>b.textContent===labels.copy),'matching record remains reviewable');
  });
  await test('conflicting recovery adopts the shown server token only after explicit choice',function(){
    var form=fixture('Archive'),store=memory(),clock=timed(),key=K.draftStore.key('5','chapter:42');
    form.elements.base_revision.value='b'.repeat(64);K.draftStore.setEnabled(store,'5',true);
    K.draftStore.write(store,key,'c'.repeat(64),K.draftStore.fields({content:'Recovery'}),1000);
    var adapter=K.draftForm.attach(form,{store:store,window:clock,api:K.draftStore,now:()=>1001});disposers.push(()=>adapter.destroy());
    equal(form.elements.base_revision.value,'b'.repeat(64),'no automatic token update');
    click(adapter.host,labels.useDraft);equal(form.elements.base_revision.value,'a'.repeat(64),'explicit shown-token adoption');
    equal(form.elements.content.value,'Recovery','explicit content adoption');
  });
  await test('protected content is never read from storage and expired session prevents writes',async function(){
    var form=fixture('Protected'),store=memory(),clock=timed(),key=K.draftStore.key('5','chapter:42');
    K.draftStore.setEnabled(store,'5',true);K.draftStore.write(store,key,'a'.repeat(64),K.draftStore.fields({content:'old'}),1000);store.reads.length=0;
    form.dataset.writerProtected='1';var adapter=K.draftForm.attach(form,{store:store,window:clock,api:K.draftStore,now:()=>1001,fetch:async()=>({ok:false,status:401})});disposers.push(()=>adapter.destroy());
    assert(!store.reads.includes(key),'protected record not read');equal(store.getItem(key),null,'protected copy removed');
    await adapter.revalidate();form.elements.content.value='still here';form.elements.content.dispatchEvent(new Event('input',{bubbles:true}));clock.flush();
    equal(store.getItem(key),null,'revoked session cannot write');equal(form.elements.content.value,'still here','input retained');
  });
  await test('early typing and earlier password input remain protected when recovery attaches late',function(){
    var form=fixture('Archive'),store=memory(),clock=timed(),key=K.draftStore.key('5','chapter:42');
    K.draftStore.setEnabled(store,'5',true);K.draftStore.write(store,key,'a'.repeat(64),K.draftStore.fields({content:'Recovery'}),1000);
    form.elements.content.value='Early typing';var adapter=K.draftForm.attach(form,{store:store,window:clock,api:K.draftStore,now:()=>1001});disposers.push(()=>adapter.destroy());
    click(adapter.host,labels.restore);equal(form.elements.content.value,'Early typing','late module does not reset the baseline');
    click(adapter.host,labels.replaceUnsaved);equal(form.elements.content.value,'Recovery','explicit replacement still works');
    var protectedForm=fixture('Protected early input');protectedForm.kipPasswordTouched=true;store.reads.length=0;
    var blocked=K.draftForm.attach(protectedForm,{store:store,window:clock,api:K.draftStore,now:()=>1001});disposers.push(()=>blocked.destroy());
    assert(!store.reads.includes(key),'early password refuses prose read');equal(store.getItem(key),null,'earlier recovery cleared');
  });
  await test('native validation and submission retain recovery until matching server acknowledgment',function(){
    var form=fixture('Archive'),store=memory(),clock=timed(),key=K.draftStore.key('5','chapter:42');K.draftStore.setEnabled(store,'5',true);
    var adapter=K.draftForm.attach(form,{store:store,window:clock,api:K.draftStore,now:()=>1001});disposers.push(()=>adapter.destroy());
    form.elements.content.value='Work';form.elements.content.dispatchEvent(new Event('input',{bubbles:true}));clock.flush();
    var submits=0;form.addEventListener('submit',()=>submits++);form.elements.content.value='';form.requestSubmit();equal(submits,0,'required field remains native');
    form.elements.content.value='Submitted work';form.elements.content.dispatchEvent(new Event('input',{bubbles:true}));form.requestSubmit();equal(submits,1,'one valid native submit');
    equal(K.draftStore.read(store,key,1001).fields.content,'Submitted work','submit retains draft');
    var saved=fixture('Submitted work');saved.dataset.writerSaved='1';saved.elements.title.value='Title';saved.dataset.writerServer=JSON.stringify(K.draftForm.values(saved));
    var acknowledgment=K.draftForm.attach(saved,{store:store,window:timed(),api:K.draftStore,now:()=>1001});disposers.push(()=>acknowledgment.destroy());equal(store.getItem(key),null,'actual field match acknowledges copy');
  });
  await test('tab storage can survive a restored page but requires session revalidation',async function(){
    var form=fixture('Archive'),store=memory(),clock=timed(),key=K.draftStore.key('5','chapter:42');K.draftStore.setEnabled(store,'5',true);
    K.draftStore.write(store,key,'a'.repeat(64),K.draftStore.fields({content:'Recovery'}),1000);
    var resolve,fetch=()=>new Promise(r=>resolve=r),adapter=K.draftForm.attach(form,{store:store,window:clock,api:K.draftStore,now:()=>1001,fetch:fetch});disposers.push(()=>adapter.destroy());
    var pending=adapter.revalidate();form.elements.content.value='New local text';form.elements.content.dispatchEvent(new Event('input',{bubbles:true}));clock.flush();
    equal(K.draftStore.read(store,key,1001).fields.content,'Recovery','unverified tab cannot replace retained record');
    resolve({ok:true,url:new URL('/auth/login',location.href).href,text:async()=>'<section data-js-module="draft-cleanup"><form action="/auth/attempt"></form></section>'});await pending;equal(store.getItem(key),null,'affirmative sign-in page clears writer data');equal(form.elements.content.value,'New local text','expired session retains page text');
  });
  await test('copy failures expose a labeled selectable recovery pane',function(){
    var form=fixture('Archive'),store=memory(),clock=timed();clock.navigator={clipboard:{writeText:function(){throw Error('denied');}}};K.draftStore.setEnabled(store,'5',true);
    K.draftStore.write(store,K.draftStore.key('5','chapter:42'),'a'.repeat(64),K.draftStore.fields({content:'Copy this'}),1000);
    var adapter=K.draftForm.attach(form,{store:store,window:clock,api:K.draftStore,now:()=>1001});disposers.push(()=>adapter.destroy());click(adapter.host,labels.copy);
    var box=adapter.host.querySelector('textarea[aria-label="'+labels.recovery+'"]');assert(box.readOnly,'readonly pane');assert(box.closest('label').textContent.includes(labels.recovery),'visible pane label');equal(box.value.slice(box.selectionStart,box.selectionEnd),'Copy this','fallback selection');
  });
  await test('offline revalidation retains every copy and exposes safe copy/discard plus retry',async function(){
    var form=fixture('Archive'),store=memory(),clock=timed(),key=K.draftStore.key('5','chapter:42'),other=K.draftStore.key('5','chapter:43');
    K.draftStore.setEnabled(store,'5',true);K.draftStore.write(store,key,'a'.repeat(64),K.draftStore.fields({content:'Recovery'}),1000);K.draftStore.write(store,other,'new',K.draftStore.fields({content:'Other'}),1000);
    var fail=true,fetch=async()=>{if(fail)throw TypeError('Failed to fetch');return{ok:true,url:new URL(form.dataset.writerCheckUrl,location.href).href,text:async()=>form.outerHTML};};
    var adapter=K.draftForm.attach(form,{store:store,window:clock,api:K.draftStore,now:()=>1001,fetch:fetch});disposers.push(()=>adapter.destroy());
    await adapter.revalidate();assert(store.getItem(key)!==null&&store.getItem(other)!==null,'network failure retains both copies');assert(K.draftStore.enabled(store,'5'),'consent retained');
    form.elements.content.value='Current unsaved';form.elements.content.dispatchEvent(new Event('input',{bubbles:true}));clock.flush();equal(K.draftStore.read(store,key,1001).fields.content,'Recovery','writes suspended');
    assert(!Array.from(adapter.host.querySelectorAll('button')).some(b=>[labels.restore,labels.useDraft,labels.replaceUnsaved].includes(b.textContent)),'unsafe restore controls absent');
    assert(Array.from(adapter.host.querySelectorAll('button')).some(b=>b.textContent===labels.copy),'copy retained');
    fail=false;await adapter.revalidate();assert(Array.from(adapter.host.querySelectorAll('button')).some(b=>b.textContent===labels.restore),'successful retry restores recovery choice');
    click(adapter.host,labels.discard);equal(store.getItem(key),null,'discard removes matching copy only');assert(store.getItem(other)!==null,'other chapter retained');
  });
  return results;
}
