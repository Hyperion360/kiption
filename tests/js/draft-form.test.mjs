import assert from 'node:assert/strict';
import { createRequire } from 'node:module';
import test from 'node:test';
import vm from 'node:vm';
import { readFileSync } from 'node:fs';
const require = createRequire(import.meta.url);
const storeApi = require('../../public/assets/draft-store.js');
const api = require('../../public/assets/draft-form.js');
const names = ['title', 'content', 'notes_before', 'notes_after', 'publish_at'];
const labels = Object.fromEntries(['retry','compare','archive','recovery','enable','disable','restore','replaceUnsaved','useDraft','discard','copy','recoveryUnavailable','recoveryProtected','recoveryOff','recoveryTab','recoveryOffer','recoveryConflict','recoveryDiscarded','recoveryRestored','recoveryWritten','recoveryClearFailed','recoveryRemoveFailed','recoveryProtectedRetained','copyFallback','copied'].map(k => [k,k]));
class Element extends EventTarget {
  constructor(tag = 'div') { super(); this.tagName = tag.toUpperCase(); this.children = []; this.dataset = {}; this.attributes = {}; this.value = ''; this.defaultValue = ''; this._text = ''; }
  set textContent(text) { this._text = text; this.children = []; }
  get textContent() { return this._text + this.children.map(c => c.textContent).join(''); }
  append(...items) { items.forEach(x => this.appendChild(x)); }
  appendChild(item) { this.children.push(item); item.parent = this; return item; }
  replaceChildren(...items) { this._text = ''; this.children = []; this.append(...items); }
  setAttribute(k,v) { this.attributes[k] = v; }
  remove() { if (this.parent) this.parent.children = this.parent.children.filter(x => x !== this); }
  focus() { this.focused = true; }
  select() { this.selected = true; }
}
class Form extends Element { constructor() { super('form'); this.method = 'post'; } }
function memory() {
  const map = new Map(), reads = [], writes = [];
  return { reads,writes,get length() { return map.size; },key: i => [...map.keys()][i],getItem(k) { reads.push(k); return map.get(k) ?? null; },setItem(k,v) { writes.push(k); map.set(k,v); },removeItem(k) { map.delete(k); } };
}
function fixture({ consent = false, content = 'Archive', record, recordBase = 'a'.repeat(64), protectedText = false, before, store = memory(), fetch } = {}) {
  const doc = new EventTarget(); doc.createElement = tag => new Element(tag); doc.createTextNode = text => Object.assign(new Element('#text'), { textContent: text });
  const form = new Form(); form.ownerDocument = doc; form.action = 'https://archive.test/chapter/update/story/1';
  form.elements = names.map(name => Object.assign(new Element(name === 'content' ? 'textarea' : 'input'), { name, value: name === 'content' ? content : '', defaultValue: name === 'content' ? content : '' }));
  form.elements.push(Object.assign(new Element('input'), { name:'base_revision',value:'a'.repeat(64),defaultValue:'a'.repeat(64) }),Object.assign(new Element('input'), { name:'access_password',type:'password',value:'' }));
  form.elements.namedItem = name => form.elements.find(x => x.name === name) || null;
  form.dataset = { writerOwner:'5',writerChapter:'chapter:42',writerBase:'a'.repeat(64),writerProtected:protectedText?'1':'0',writerServer:JSON.stringify(api.values(form)),writerLabels:JSON.stringify(labels),writerCheckUrl:'/chapter/edit/story/1',writerRejected:'0' };
  const win = new EventTarget(), timers = new Map(); let tick = 0;
  Object.assign(win, { Event,HTMLFormElement:Form,location:{ href:'https://archive.test/chapter/edit/story/1',origin:'https://archive.test' },navigator:{},setTimeout: fn => { timers.set(++tick, fn); return tick; },clearTimeout: id => timers.delete(id) });
  win.DOMParser = class { parseFromString() { return { querySelector: selector => selector.includes('/auth/attempt') ? (form.loginPage ? {} : null) : (form.remote === null ? null : form.remote ?? form) }; } };
  const key = storeApi.key('5','chapter:42');
  if (consent) storeApi.setEnabled(store,'5',true);
  if (record !== undefined) storeApi.write(store,key,recordBase,storeApi.fields({ content:record }),1000);
  before?.({ form,store,win }); store.reads.length = 0; store.writes.length = 0;
  const attached = api.attach(form,{ window:win,store,api:storeApi,now:()=>1001,fetch });
  const field = name => form.elements.namedItem(name);
  const input = (name = 'content', value) => { if (value !== undefined) field(name).value = value; const e = new Event('input'); Object.defineProperty(e,'target',{ value:field(name) }); form.dispatchEvent(e); };
  const click = name => { const b = attached.host.children[1].children.find(x => x.textContent === labels[name]); assert.ok(b, 'button '+name); b.dispatchEvent(new Event('click')); };
  const flush = () => { const pending = [...timers.values()]; timers.clear(); pending.forEach(fn => fn()); };
  return { form,doc,win,store,key,attached,field,input,click,flush,timers,status:()=>attached.host.children[0].textContent };
}
test('consent off never reads or writes prose and has no timer', () => {
  const f=fixture({record:'Recovery'}); assert.deepEqual(f.store.reads,[storeApi.key('5','enabled')]); f.input('content','Typed'); f.flush();
  assert.deepEqual(f.store.writes,[]); assert.equal(f.timers.size,0); assert.equal(f.status(),'recoveryOff');
  f.click('enable'); assert.ok(f.status().startsWith('recoveryOffer')); assert.equal(f.field('content').value,'Typed');
  f.click('restore'); assert.equal(f.field('content').value,'Typed'); f.click('replaceUnsaved'); assert.equal(f.field('content').value,'Recovery'); f.attached.destroy();
});
test('typing before delayed attachment and rejected input require explicit replacement', () => {
  for (const rejected of [false,true]) {
    const f=fixture({consent:true,record:'Recovery',before:({form})=>{ if(rejected)form.dataset.writerRejected='1';else form.elements.namedItem('content').value='Early typing'; }});
    const current=f.field('content').value; f.click('restore'); assert.equal(f.field('content').value,current); f.click('replaceUnsaved'); assert.equal(f.field('content').value,'Recovery'); f.attached.destroy();
  }
});
test('enabled recovery debounces five fields and never removes on submit or aborted save', () => {
  const f=fixture({consent:true}); f.input('content','Work'); f.input('notes_before','Note'); assert.equal(f.timers.size,1); assert.equal(f.store.getItem(f.key),null);
  f.flush(); assert.equal(storeApi.read(f.store,f.key,1001).fields.content,'Work');
  f.input('content','More'); f.form.dispatchEvent(new Event('submit')); assert.equal(storeApi.read(f.store,f.key,1001).fields.content,'More'); assert.equal(f.timers.size,0);
  f.input('content','After aborted submit'); f.flush(); assert.equal(storeApi.read(f.store,f.key,1001).fields.content,'After aborted submit'); f.attached.destroy();
});
test('pending recovery cannot be overwritten before an explicit decision', () => {
  const f=fixture({consent:true,record:'Recovery'}); f.input('content','Typed'); f.flush(); f.form.dispatchEvent(new Event('submit'));
  assert.equal(storeApi.read(f.store,f.key,1001).fields.content,'Recovery'); f.click('discard'); assert.equal(f.store.getItem(f.key),null);
  f.form.dispatchEvent(new Event('submit')); assert.equal(f.store.getItem(f.key),null); f.input('content','After discard'); f.flush(); assert.equal(storeApi.read(f.store,f.key,1001).fields.content,'After discard'); f.attached.destroy();
});
test('exact server acknowledgment alone removes a matching copy, including after saved GET', () => {
  const f=fixture({consent:true,record:'Archive'}); assert.equal(f.store.getItem(f.key),null); assert.equal(f.status(),'recoveryTab'); f.attached.destroy();
  const different=fixture({consent:true,record:'Not saved',before:({form})=>form.dataset.writerSaved='1'}); assert.ok(different.store.getItem(different.key)); different.attached.destroy();
});
test('a conflicting copy adopts only the compared server revision after explicit choice', () => {
  const f=fixture({consent:true,record:'Recovery',recordBase:'b'.repeat(64),before:({form})=>form.elements.namedItem('base_revision').value='c'.repeat(64)});
  assert.ok(f.status().startsWith('recoveryConflict')); assert.ok(f.attached.host.children[2].textContent.includes(labels.archive), 'archive pane has a visible label'); assert.ok(f.attached.host.children[2].textContent.includes(labels.recovery), 'recovery pane has a visible label'); f.click('useDraft'); assert.equal(f.field('content').value,'Recovery'); assert.equal(f.field('base_revision').value,'a'.repeat(64)); f.flush();
  assert.equal(storeApi.read(f.store,f.key,1001).base,'a'.repeat(64)); f.attached.destroy();
});
test('protected and early-password fields never read or persist prose', () => {
  for(const type of ['saved','filled','touched']) {
    const f=fixture({consent:true,record:'Recovery',protectedText:type==='saved',before:({form})=>{if(type==='filled')form.elements.namedItem('access_password').value='secret';if(type==='touched')form.kipPasswordTouched=true;}});
    assert.ok(!f.store.reads.includes(f.key)); assert.equal(f.store.getItem(f.key),null); f.input('content','Protected'); f.flush(); assert.equal(f.store.getItem(f.key),null); assert.equal(f.status(),'recoveryProtected'); f.attached.destroy();
  }
});
test('new password input immediately clears recovery and cancels pending work', () => {
  const f=fixture({consent:true}); f.input('content','Work'); f.flush(); f.input('content','More'); f.input('access_password','secret'); assert.equal(f.timers.size,0); assert.equal(f.store.getItem(f.key),null);
  f.input('access_password',''); f.input('content','Still protected'); f.flush(); assert.equal(f.store.getItem(f.key),null); f.attached.destroy();
});
test('failed discard retains comparison and copy fallback; protected removal reports failure', () => {
  const f=fixture({consent:true,record:'Recovery'}); f.store.removeItem=()=>{throw Error('denied');}; f.click('discard'); assert.ok(f.status().startsWith('recoveryRemoveFailed'));
  f.click('copy'); assert.equal(f.status(),'copyFallback'); assert.equal(f.attached.host.children[2].children[2].children[0].selected,true);
  f.input('access_password','secret'); assert.equal(f.status(),'recoveryProtectedRetained'); f.attached.destroy();
});
test('copy handles successful and denied clipboard access', async () => {
  const f=fixture({consent:true,record:'Recovery'}); let copied; f.win.navigator.clipboard={writeText:async text=>{copied=text;}};f.click('copy');await Promise.resolve();assert.equal(copied,'Recovery');assert.equal(f.status(),'copied');
  f.win.navigator.clipboard.writeText=()=>Promise.reject(Error('denied'));f.click('copy');await Promise.resolve();assert.equal(f.status(),'copyFallback');f.attached.destroy();
});
test('turning recovery off clears only this owner and keeps current text', () => {
  const f=fixture({consent:true}); f.store.setItem('theme','dark');f.input('content','Work');f.flush();f.click('disable');assert.equal(f.field('content').value,'Work');assert.equal(f.store.getItem(f.key),null);assert.equal(f.store.getItem('theme'),'dark');assert.equal(f.status(),'recoveryOff');f.attached.destroy();
});
test('quota and consent failures keep editing and Save available', () => {
  const f=fixture();f.store.setItem=()=>{throw Error('quota');};f.click('enable');assert.equal(f.status(),'recoveryUnavailable');f.field('content').value='Work';f.form.dispatchEvent(new Event('submit'));assert.equal(f.field('content').value,'Work');f.attached.destroy();
  const enabled=fixture({consent:true});enabled.store.setItem=()=>{throw Error('quota');};enabled.input('content','Work');enabled.flush();assert.equal(enabled.status(),'recoveryUnavailable');enabled.attached.destroy();
});
test('account change clears only other writer namespaces before offers', () => {
  const store=memory();store.setItem('theme','dark');storeApi.write(store,storeApi.key('6','chapter:42'),'new',storeApi.fields({content:'Other account'}),1000);
  const f=fixture({store});assert.equal(store.getItem(storeApi.key('6','chapter:42')),null);assert.equal(store.getItem('theme'),'dark');f.attached.destroy();
});
test('logout cancels writes and clears every writer key in the tab', () => {
  const f=fixture({consent:true});f.store.setItem('theme','dark');f.input('content','Work');const logout=new Form();logout.action='https://archive.test/auth/logout';const e=new Event('submit');Object.defineProperty(e,'target',{value:logout});f.doc.dispatchEvent(e);f.flush();assert.equal(f.store.getItem(f.key),null);assert.equal(storeApi.enabled(f.store,'5'),false);assert.equal(f.store.getItem('theme'),'dark');f.input('content','After logout');f.flush();assert.equal(f.store.getItem(f.key),null);f.attached.destroy();
});
test('bfcache waits for authorized identity and then uses the refreshed server version', async () => {
  let resolve;const f=fixture({consent:true,fetch:()=>new Promise(r=>resolve=r)});f.input('content','Pending');const check=f.attached.revalidate();f.flush();assert.equal(f.store.getItem(f.key),null);assert.equal(f.status(),'recoveryUnavailable');
  f.form.remote={dataset:{...f.form.dataset,writerBase:'b'.repeat(64),writerServer:JSON.stringify(storeApi.fields({content:'New archive'}))}};
  resolve({ok:true,url:'https://archive.test/chapter/edit/story/1',text:async()=>'<form>'});await check;f.input('content','After revalidation');f.flush();assert.equal(storeApi.read(f.store,f.key,1001).base,'a'.repeat(64),'ordinary hidden CAS token stays stale');f.attached.destroy();
});
test('unverified revalidation failures retain every record and allow copy or discard without restoration', async () => {
  for (const mode of ['offline','server','missing','malformed','origin','path','login-error']) {
    const f=fixture({consent:true,record:'Recovery',fetch:async()=>{
      if(mode==='offline')throw TypeError('Failed to fetch');
      return {ok:mode!=='server',status:mode==='server'?503:200,url:mode==='origin'?'https://elsewhere.test/chapter/edit/story/1':mode==='path'?'https://archive.test/unexpected':mode==='login-error'?'https://archive.test/auth/login':'https://archive.test/chapter/edit/story/1',text:async()=>'<form>'};
    }});
    const other=storeApi.key('5','chapter:43');storeApi.write(f.store,other,'new',storeApi.fields({content:'Other chapter'}),1000);
    if(mode==='missing')f.form.remote=null;
    if(mode==='malformed')f.form.remote={dataset:{...f.form.dataset,writerServer:'{bad'}};
    await f.attached.revalidate();
    const retained=storeApi.read(f.store,f.key,1001);assert.ok(retained,mode+' retained its copy');assert.equal(retained.fields.content,'Recovery',mode);
    assert.equal(storeApi.read(f.store,other,1001).fields.content,'Other chapter',mode);
    assert.equal(storeApi.enabled(f.store,'5'),true,mode);
    f.input('content','Unsaved page');f.flush();assert.equal(storeApi.read(f.store,f.key,1001).fields.content,'Recovery',mode);
    const buttons=f.attached.host.children[1].children.map(x=>x.textContent);
    assert.ok(buttons.includes(labels.retry),mode);assert.ok(!buttons.some(x=>[labels.restore,labels.replaceUnsaved,labels.useDraft].includes(x)),mode);
    f.click('copy');assert.equal(f.status(),'copyFallback');f.click('discard');assert.equal(f.store.getItem(f.key),null);assert.ok(f.store.getItem(other));f.attached.destroy();
  }
});
test('recovery retry resumes only after a valid matching authorized form', async () => {
  let offline=true;const f=fixture({consent:true,record:'Recovery',fetch:async()=>{if(offline)throw Error('offline');return {ok:true,url:'https://archive.test/chapter/edit/story/1',text:async()=>'<form>'};}});
  await f.attached.revalidate();offline=false;f.click('retry');await new Promise(resolve=>setImmediate(resolve));
  assert.ok(f.status().startsWith('recoveryOffer'));f.click('restore');assert.equal(f.field('content').value,'Recovery');f.attached.destroy();
});
test('restored pages defer account and protection cleanup until identity is verified', async () => {
  for (const protectedText of [false,true]) {
    let offline=true;
    const other=storeApi.key('6','chapter:42');
    const f=fixture({consent:true,record:'Recovery',protectedText,fetch:async()=>{
      if(offline)throw Error('offline');
      return {ok:true,url:'https://archive.test/chapter/edit/story/1',text:async()=>'<form>'};
    },before:({store,win})=>{
      win.performance={getEntriesByType:()=>[{type:'back_forward'}]};
      storeApi.write(store,other,'new',storeApi.fields({content:'Other account'}),1000);
    }});
    assert.ok(!f.store.reads.includes(f.key),'unverified history never reads prose');
    assert.ok(f.store.getItem(f.key),'history keeps the current copy');
    assert.ok(f.store.getItem(other),'history keeps the other account copy');
    await new Promise(resolve=>setImmediate(resolve));
    f.input('content','Unverified text');f.flush();
    assert.equal(storeApi.read(f.store,f.key,1001).fields.content,'Recovery');
    assert.ok(f.store.getItem(other));
    assert.deepEqual(f.attached.host.children[1].children.map(x=>x.textContent),[labels.retry]);
    offline=false;await f.attached.revalidate();
    assert.equal(f.store.getItem(other),null,'verified identity clears other accounts');
    if(protectedText)assert.equal(f.store.getItem(f.key),null,'verified protection clears this copy');
    else assert.ok(f.store.getItem(f.key));
    f.attached.destroy();
  }
});
test('affirmative same-origin login and changed-owner forms clean only justified writer scopes', async () => {
  for(const mode of ['login','owner']) {
    const f=fixture({consent:true,record:'Old account',fetch:async()=>({ok:true,url:mode==='login'?'https://archive.test/auth/login':'https://archive.test/chapter/edit/story/1',text:async()=>'<form>'})});
    f.store.setItem('theme','dark');const newOwner=storeApi.key('6','chapter:42');storeApi.write(f.store,newOwner,'new',storeApi.fields({content:'New account'}),1000);
    if(mode==='login')f.form.loginPage=true;
    else f.form.remote={dataset:{...f.form.dataset,writerOwner:'6'}};
    await f.attached.revalidate();assert.equal(f.store.getItem(f.key),null);assert.equal(f.store.getItem('theme'),'dark');
    if(mode==='owner')assert.ok(f.store.getItem(newOwner));else assert.equal(f.store.getItem(newOwner),null);
    f.input('content','Must not persist');f.flush();assert.equal(f.store.getItem(f.key),null);f.attached.destroy();
  }
});
test('cleanup failures are visible and stop all recovery writes', () => {
  const store=memory();store.setItem(storeApi.key('6','enabled'),'1');store.removeItem=()=>{throw Error('denied');};const f=fixture({store});assert.equal(f.status(),'recoveryClearFailed');f.input('content','Work');f.flush();assert.equal(f.store.getItem(f.key),null);f.attached.destroy();
  assert.equal(api.cleanup(null),false);assert.equal(api.equal({content:'a'},{content:'b'}),false);
});

test('a prose read failure reports unavailability without deleting the inaccessible copy', () => {
  const store=memory(),k=storeApi.key('5','chapter:42');storeApi.setEnabled(store,'5',true);storeApi.write(store,k,'new',storeApi.fields({content:'Keep'}),1000);const get=store.getItem.bind(store);
  store.getItem=name=>{if(name===k)throw Error('unavailable');return get(name);};
  const f=fixture({store});assert.equal(f.status(),'recoveryUnavailable');assert.ok(get(k));f.input('content','Work');f.flush();assert.equal(JSON.parse(get(k)).fields.content,'Keep');f.attached.destroy();
});
test('failed exact-copy cleanup retains the copy and failed disabling remains truthful', () => {
  const f=fixture({consent:true,record:'Archive',before:({store})=>store.removeItem=()=>{throw Error('denied');}});
  assert.ok(f.status().startsWith('recoveryRemoveFailed'));f.click('disable');assert.equal(f.status(),'recoveryClearFailed');f.input('content','Work');f.flush();assert.ok(f.store.getItem(f.key));f.attached.destroy();
});

test('login cleanup and logout on another page clear writer keys without a chapter controller', () => {
  for (const login of [true, false]) {
    const store=memory(),doc=new EventTarget();store.setItem('theme','dark');storeApi.setEnabled(store,'5',true);storeApi.write(store,storeApi.key('5','chapter:42'),'new',storeApi.fields({content:'Work'}),1000);
    doc.querySelector=()=>login?{}:null;doc.querySelectorAll=()=>[];
    const root={document:doc,sessionStorage:store,HTMLFormElement:Form,location:{href:'https://archive.test/account'},Kip:{draftStore:storeApi,init:fn=>fn()}};
    vm.runInNewContext(readFileSync(new URL('../../public/assets/draft-form.js',import.meta.url),'utf8'),{window:root,URL},{filename:new URL('../../public/assets/draft-form.js',import.meta.url).pathname});
    root.Kip.draftForm.init(doc);
    if(!login){const logout=new Form();logout.action='/auth/logout';const event=new Event('submit');Object.defineProperty(event,'target',{value:logout});doc.dispatchEvent(event);}
    assert.equal(storeApi.enabled(store,'5'),false);assert.equal(store.getItem(storeApi.key('5','chapter:42')),null);assert.equal(store.getItem('theme'),'dark');
  }
});
test('a blocked sessionStorage getter leaves initialization and native forms usable', () => {
  const doc=new EventTarget();doc.querySelector=()=>({});doc.querySelectorAll=()=>[];
  const root={document:doc,get sessionStorage(){throw Error('denied');},HTMLFormElement:Form,location:{href:'https://archive.test/auth/login'},Kip:{init:fn=>fn()}};
  assert.doesNotThrow(()=>vm.runInNewContext(readFileSync(new URL('../../public/assets/draft-form.js',import.meta.url),'utf8'),{window:root,URL}));
});

test('synchronous clipboard failures use the selectable text fallback', () => {
  const f=fixture({consent:true,record:'Recovery'});f.win.navigator.clipboard={writeText(){throw Error('denied');}};
  assert.doesNotThrow(()=>f.click('copy'));assert.equal(f.status(),'copyFallback');f.attached.destroy();
});

test('already-consented early typing and rejected drafts queue their first recovery copy', () => {
  for (const rejected of [false,true]) {
    const f=fixture({consent:true,before:({form})=>{form.elements.namedItem('content').value='Earlier work';if(rejected)form.dataset.writerRejected='1';}});
    assert.equal(f.timers.size,1);f.flush();assert.equal(storeApi.read(f.store,f.key,1001).fields.content,'Earlier work');f.attached.destroy();
  }
});

test('a story editor names its own fields in data-writer-fields', () => {
  const form = new Form();
  form.elements = ['chapter_title', 'content', 'title'].map(name => Object.assign(new Element('input'), { name, value: name + '!' }));
  form.elements.namedItem = name => form.elements.find(x => x.name === name) || null;
  assert.deepEqual(api.values(form, ['chapter_title', 'content']), { chapter_title: 'chapter_title!', content: 'content!' });
  assert.deepEqual(Object.keys(api.values(form)), names, 'no names given means the chapter five');
  assert.equal(api.equal({ chapter_title: 'a' }, { chapter_title: 'a' }, ['chapter_title']), true);
  assert.equal(api.equal({ chapter_title: 'a' }, { chapter_title: 'b' }, ['chapter_title']), false);
});

test('data-writer-fields chooses which fields recovery keeps, and a malformed list falls back to the chapter five', () => {
  const run = list => {
    const f = fixture({ consent: true, before: ({ form }) => { form.dataset.writerFields = list; } });
    f.input('content', 'Work'); f.input('title', 'Named'); f.flush();
    const rec = JSON.parse(f.store.getItem(f.key));
    f.attached.destroy();
    return Object.keys(rec.fields);
  };
  assert.deepEqual(run(JSON.stringify(['content', 'notes_after'])), ['content', 'notes_after']);
  for (const bad of ['{nope', '[]', '[1,2]', '"x"']) assert.deepEqual(run(bad), names, bad);
});

test('the offer banner shows a title and detail, orders Restore then Copy then Discard, and keeps the result visible', () => {
  Object.assign(labels, { recoveryOffer: 'A recovery copy is available', recoveryOfferDetail: 'Kept at {time} with {n} words.', recoveryOfferDetailOne: 'Kept at {time} with 1 word.', recoveryOfferDetailSame: 'Kept at {time}, differs.' });
  try {
    const f = fixture({ consent: true, record: 'Archive plus three more' });
    const status = f.attached.host.children[0];
    assert.equal(status.children[0].tagName, 'STRONG');
    assert.equal(status.children[0].textContent, 'A recovery copy is available');
    assert.match(status.children[1].textContent, /with 3 words\./);
    assert.deepEqual(f.attached.host.children[1].children.map(x => x.textContent).filter(x => [labels.restore, labels.copy, labels.discard].includes(x)), [labels.restore, labels.copy, labels.discard]);
    assert.equal(f.attached.host.dataset.offer, '1');
    f.click('discard');
    assert.equal(f.attached.host.dataset.offer, '');
    assert.equal(f.attached.host.dataset.notice, '1', 'the discard result stays on screen');
    f.input('content', 'Typed after');
    assert.equal(f.attached.host.dataset.notice, '', 'typing clears the result');
    f.attached.destroy();
    const one = fixture({ consent: true, record: 'Archive one' });
    assert.match(one.attached.host.children[0].children[1].textContent, /with 1 word\./);
    one.click('restore');
    assert.equal(one.attached.host.dataset.notice, '1', 'the restore result stays on screen');
    one.attached.destroy();
    const same = fixture({ consent: true, record: 'Different' });
    assert.match(same.attached.host.children[0].children[1].textContent, /differs\./);
    same.attached.destroy();
  } finally {
    ['recoveryOfferDetail', 'recoveryOfferDetailOne', 'recoveryOfferDetailSame'].forEach(k => delete labels[k]); labels.recoveryOffer = 'recoveryOffer';
  }
});
