import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import vm from 'node:vm';
const code = readFileSync(new URL('../../public/assets/search.js', import.meta.url), 'utf8');
class Element {
  constructor(attrs = {}) { this.attrs = {...attrs}; this.events = {}; this.children = []; this.hidden = false; this.textContent = ''; this.innerHTML = ''; }
  setAttribute(k, v) { this.attrs[k] = String(v); }
  getAttribute(k) { return this.attrs[k] ?? null; }
  removeAttribute(k) { delete this.attrs[k]; }
  addEventListener(k, fn) { (this.events[k] ??= []).push(fn); }
  emit(k, extra = {}) {
    const e = {prevented: false, preventDefault() { this.prevented = true; }, ...extra};
    for (const fn of this.events[k] ?? []) fn(e);
    return e;
  }
  replaceChildren(...kids) { this.children = kids; }
  appendChild(el) { this.children.push(el); }
  contains(el) { return el === this || this.children.some(c => c.contains(el)); }
}
function harness(settings = {}) {
  let now = 0, next = 0;
  const timers = new Map(), requests = [], navigated = [];
  const input = new Element(); input.value = ''; input.name = 'q';
  const list = new Element(); list.id = 'search-suggestions';
  const status = new Element(), results = new Element(); results.innerHTML = 'initial';
  const count = new Element({'data-search-count': 'category:a', 'data-count-label': 'A'});
  const bare = new Element({'data-search-count': 'status:any'});
  const apply = new Element({'data-show-one': 'Show 1 Story', 'data-show-many': 'Show {count} Stories'}); apply.textContent = 'Apply Filters';
  const form = new Element({'data-search-loading': 'Searching', 'data-search-error': 'Use Search', 'data-search-suggestions-label': 'Suggested Stories'});
  form.action = 'https://archive.example/search'; form.fields = []; form.children = [input,list,status,count];
  form.querySelector = sel => ({'[name="q"]': input, '[data-search-suggestions]': list, '[data-search-status]': status, '[data-show-many]': apply})[sel] ?? null;
  form.querySelectorAll = sel => sel === '[data-search-count]' ? [count, bare, ...(language?.options ?? [])] : [];
  const language = settings.language ? new Element() : null;
  if (language) { language.options = language.children; language.value = 'zz'; }
  const originalQuery = form.querySelector;
  form.querySelector = sel => sel === 'select[name="language"]' ? language : originalQuery(sel);
  const document = {activeElement: input,
    querySelectorAll: sel => sel === 'form[data-js-module~="search"]' ? [form] : [],
    querySelector: sel => sel === '[data-search-results]' ? results : null,
    createElement: () => new Element()};
  function fetch(url, options) {
    return new Promise((resolve, reject) => requests.push({url,options,resolve,reject}));
  }
  const window = new Element(); Object.assign(window, {Kip: {init: fn => fn()}, fetch, AbortController});
  if (settings.unavailable) window.fetch = undefined;
  const context = {window,document,fetch,AbortController,URL,URLSearchParams,
    FormData: class { constructor(f) { this.rows = [['q', input.value], ...f.fields]; } [Symbol.iterator]() { return this.rows[Symbol.iterator](); } },
    location: {origin: 'https://archive.example', assign: href => navigated.push(href)},
    setTimeout: (fn, ms) => { const id = ++next; timers.set(id,{at:now+ms,fn}); return id; },
    clearTimeout: id => timers.delete(id)};
  vm.runInNewContext(code, context, {filename: new URL('../../public/assets/search.js', import.meta.url).pathname});
  function tick(ms) {
    now += ms;
    for (;;) {
      const ready = [...timers].filter(([,t]) => t.at <= now).sort((a,b) => a[1].at-b[1].at);
      if (!ready.length) break;
      const [id,t] = ready[0]; timers.delete(id); t.fn();
    }
  }
  async function flush() { for (let i=0;i<12;i++) await Promise.resolve(); }
  function type(value) { input.value=value; form.emit('input', {target: input}); }
  async function answer(index, body, ok=true, mime='application/json') {
    requests[index].resolve({ok,headers:{get:()=>mime},json:()=>Promise.resolve(body)});
    await flush();
  }
  function body(html='new', suggestions=[{label:'River',url:'/story/view/river'}]) {
    return {html,suggestions,counts:{'category:a':2,'status:any':7},total:7,message:'2 matching stories.'};
  }
  return {input,list,status,results,count,bare,apply,form,document,window,language,requests,navigated,tick,type,answer,body,flush};
}

test('rapid input sends one request after 250 ms and includes all active filters', () => {
  const h=harness(); h.form.fields=[['category[]','a'],['category[]','b'],['xtag[]','12'],['sort','updated']];
  h.type('ri'); h.tick(100); h.type('riv'); h.tick(100); h.type('river'); h.tick(249);
  assert.equal(h.requests.length,0); h.tick(1); assert.equal(h.requests.length,1);
  const url=new URL(h.requests[0].url);
  assert.deepEqual(url.searchParams.getAll('category[]'),['a','b']);
  assert.equal(url.searchParams.get('xtag[]'),'12'); assert.equal(url.searchParams.get('live'),'1');
});
test('old and aborted responses cannot replace a newer response', async () => {
  const h=harness(); h.type('river'); h.tick(250); h.type('winter'); h.tick(250);
  assert.equal(h.requests[0].options.signal.aborted,true);
  await h.answer(1,h.body('B')); await h.answer(0,h.body('A'));
  assert.equal(h.results.innerHTML,'B');
});
test('Escape invalidates a pending response and keeps suggestions closed', async () => {
  const h=harness(); h.type('river'); h.tick(250); h.input.emit('keydown',{key:'Escape'});
  await h.answer(0,h.body()); assert.equal(h.results.innerHTML,'initial'); assert.equal(h.list.hidden,true);
});
test('composition never requests partial words', () => {
  const h=harness(); h.input.emit('compositionstart'); h.type('пр'); h.tick(1000);
  assert.equal(h.requests.length,0); h.input.value='привет'; h.input.emit('compositionend'); h.tick(250);
  assert.equal(h.requests.length,1);
});
test('single character waits but clearing refreshes filtered results', () => {
  const h=harness(); h.type('r'); h.tick(1000); assert.equal(h.requests.length,0);
  h.type(''); h.tick(250); assert.equal(h.requests.length,1);
});
test('HTTP and network errors retain old content and the submit path', async () => {
  const h=harness(); h.type('river'); h.tick(250); await h.answer(0,{},false);
  assert.equal(h.results.innerHTML,'initial'); assert.equal(h.status.textContent,'Use Search');
  assert.equal(h.form.emit('submit').prevented,false);
  h.type('winter'); h.tick(250); h.requests[1].reject(new Error('offline')); await h.flush();
  assert.equal(h.results.innerHTML,'initial'); assert.equal(h.results.getAttribute('aria-busy'),null);
});
test('ordinary Enter submits; ArrowDown then Enter selects a same-origin suggestion', async () => {
  const h=harness(); assert.equal(h.input.emit('keydown',{key:'Enter'}).prevented,false);
  h.type('river'); h.tick(250); await h.answer(0,h.body());
  assert.equal(h.input.emit('keydown',{key:'ArrowDown'}).prevented,true);
  assert.equal(h.input.getAttribute('aria-activedescendant'),'search-suggestions-0');
  assert.equal(h.input.emit('keydown',{key:'Enter'}).prevented,true);
  assert.deepEqual(h.navigated,['https://archive.example/story/view/river']);
});
test('suggestion text cannot become markup and external URLs are rejected', async () => {
  const h=harness(); h.type('river'); h.tick(250);
  await h.answer(0,h.body('safe',[
    {label:'<img onerror=alert(1)>',url:'/story/view/safe'},
    {label:'Outside',url:'https://evil.example/story/view/a'},
    {label:'Script',url:'javascript:alert(1)'}]));
  assert.equal(h.list.children.length,1);
  assert.equal(h.list.children[0].textContent,'<img onerror=alert(1)>');
  assert.equal(h.list.children[0].innerHTML,'');
});
test('count refresh preserves focus and selected filter values', async () => {
  const h=harness(); h.form.fields=[['category[]','a']]; h.type('river'); h.tick(250);
  await h.answer(0,h.body());
  assert.equal(h.count.textContent,'A (2)'); assert.equal(h.document.activeElement,h.input);
  assert.deepEqual(h.form.fields,[['category[]','a']]);
});
test('new input discards stale options before ArrowDown can reopen them', async () => {
  const h=harness(); h.type('river'); h.tick(250); await h.answer(0,h.body());
  h.type('winter'); h.input.emit('keydown',{key:'ArrowDown'});
  assert.equal(h.list.hidden,true); assert.equal(h.list.children.length,0);
});
test('malformed response preserves old results', async () => {
  const h=harness(); h.type('river'); h.tick(250); await h.answer(0,{html:7,suggestions:[]});
  assert.equal(h.results.innerHTML,'initial'); assert.equal(h.status.textContent,'Use Search');
});

test('invalid suggestion rows are skipped without losing valid results or options', async () => {
  const h=harness(); h.type('river'); h.tick(250);
  await h.answer(0,h.body('fresh',[null,{label:'Missing URL'}, {label:'Broken',url:'http://['}, {label:'River',url:'/story/view/river'}]));
  assert.equal(h.results.innerHTML,'fresh'); assert.equal(h.list.children.length,1);
  assert.equal(h.list.children[0].textContent,'River'); assert.equal(h.status.textContent,'2 matching stories.');
});
test('Tab to another form control closes suggestions and clears the active descendant', async () => {
  const h=harness(); h.type('river'); h.tick(250); await h.answer(0,h.body());
  h.input.emit('keydown',{key:'ArrowDown'}); h.document.activeElement=h.count;
  h.input.emit('blur'); h.tick(0);
  assert.equal(h.list.hidden,true); assert.equal(h.input.getAttribute('aria-activedescendant'),null);
});
test('local tag-list filtering does not send a content query', () => {
  const h=harness(); h.input.value='river'; h.form.emit('input',{target:{name:''}}); h.tick(250);
  assert.equal(h.requests.length,0);
});
test('Escape closes the popup without clearing the query or leaving Searching behind', async () => {
  const h=harness(); h.type('river'); h.tick(250); await h.answer(0,h.body());
  assert.equal(h.input.emit('keydown',{key:'Escape'}).prevented,true);
  h.type('winter'); h.tick(250); h.input.emit('keydown',{key:'Escape'});
  assert.equal(h.status.textContent,''); assert.equal(h.input.value,'winter');
});
test('MIME and JSON parse errors retain results; later changes can recover', async () => {
  const h=harness(); h.type('river'); h.tick(250); await h.answer(0,h.body(),'true','text/html');
  assert.equal(h.results.innerHTML,'initial'); assert.equal(h.status.textContent,'Use Search');
  h.type('winter'); h.tick(250);
  h.requests[1].resolve({ok:true,headers:{get:()=> 'application/json'},json:()=>Promise.reject(new SyntaxError('JSON'))});
  await h.flush(); assert.equal(h.results.innerHTML,'initial');
  h.type('river'); h.tick(250); await h.answer(2,h.body('recovered',[]));
  assert.equal(h.results.innerHTML,'recovered'); assert.equal(h.list.hidden,true);
});
test('ArrowUp wraps, click selects, and IME Enter never selects', async () => {
  const h=harness(); h.type('river'); h.tick(250);
  await h.answer(0,h.body('new',[{label:'First',url:'/story/view/first'},{label:'Last',url:'/story/view/last'}]));
  h.input.emit('keydown',{key:'ArrowUp'}); assert.equal(h.input.getAttribute('aria-activedescendant'),'search-suggestions-1');
  h.input.emit('keydown',{key:'ArrowUp'}); assert.equal(h.input.getAttribute('aria-activedescendant'),'search-suggestions-0');
  assert.equal(h.input.emit('keydown',{key:'Enter',isComposing:true}).prevented,false);
  assert.equal(h.list.children[1].emit('mousedown').prevented,true);
  h.list.children[1].emit('click'); assert.deepEqual(h.navigated,['https://archive.example/story/view/last']);
});
test('language options grow without duplicate values or losing the selection', async () => {
  const h=harness({language:true}); h.type('river'); h.tick(250);
  const data=h.body(); data.counts={'language:fr':3,'category:a':-2};
  await h.answer(0,data); assert.equal(h.language.options.length,1);
  assert.equal(h.language.options[0].value,'fr'); assert.equal(h.language.options[0].textContent,'fr (3)');
  assert.equal(h.language.value,'zz'); assert.equal(h.count.textContent,'A (0)');
  h.type('winter'); h.tick(250); await h.answer(1,data); assert.equal(h.language.options.length,1);
});
test('changing filters, submitting, or leaving invalidates pending work', async () => {
  const h=harness(); h.type('river'); h.tick(250); h.form.fields=[['completed','0']];
  await h.answer(0,h.body('stale')); assert.equal(h.results.innerHTML,'initial');
  h.form.emit('change',{target:{name:'completed'}}); h.tick(250);
  assert.equal(new URL(h.requests[1].url).searchParams.get('completed'),'0');
  assert.equal(h.form.emit('submit').prevented,false); await h.answer(1,h.body('stale submit'));
  assert.equal(h.results.innerHTML,'initial');
  h.type('winter'); h.tick(250); h.window.emit('pagehide'); await h.answer(2,h.body('left'));
  assert.equal(h.results.innerHTML,'initial');
});
test('fetch unavailability leaves the native form and input unchanged', () => {
  const h=harness({unavailable:true}); h.type('river'); h.tick(500);
  assert.equal(h.requests.length,0); assert.equal(h.input.getAttribute('role'),null);
  assert.equal(h.form.emit('submit').prevented,false);
});
test('rail counts without a label show the bare number and the apply button names the total', async () => {
  const h=harness(); h.type('river'); h.tick(250);
  await h.answer(0,h.body());
  assert.equal(h.bare.textContent,'7');
  assert.equal(h.apply.textContent,'Show 7 Stories');
  h.type('rive'); h.tick(250);
  const d=h.body(); d.total=1; await h.answer(1,d);
  assert.equal(h.apply.textContent,'Show 1 Story');
});
