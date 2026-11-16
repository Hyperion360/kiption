import assert from 'node:assert/strict';
import test from 'node:test';
import editor from '../../public/assets/inline-editor/editor.js';

class Element extends EventTarget {
  constructor(name, doc) {
    super();
    this.nodeName = name.toUpperCase();
    this.ownerDocument = doc;
    this.children = [];
    this.hidden = false;
    this.disabled = false;
    this.attributes = new Map();
    this.value = '';
    this.selectionStart = 0;
    this.selectionEnd = 0;
    this.selectionDirection = 'forward';
  }
  setAttribute(name, value) { this.attributes.set(name, String(value)); }
  getAttribute(name) { return this.attributes.get(name) ?? null; }
  append(...children) { for (const child of children) { child.parent = this; this.children.push(child); } }
  insertAdjacentElement(position, element) {
    assert.equal(position, 'afterend');
    this.inserted = element;
    element.parent = this;
  }
  remove() { this.removed = true; if (this.parent?.inserted === this) this.parent.inserted = null; }
  focus() { this.ownerDocument.activeElement = this; }
  click() { this.dispatchEvent(new Event('click', {cancelable:true})); }
  setSelectionRange(start, end, direction) {
    this.selectionStart = start; this.selectionEnd = end; this.selectionDirection = direction;
  }
}

function fixture(text = '**Words**', settings = {}) {
  const doc = {activeElement:null,createElement(name) { return new Element(name, this); }};
  const form = new Element('form', doc);
  const box = new Element('textarea', doc);
  box.value = text; box.form = form; box.required = true;
  let model, failed, inputDetached = 0;
  const modules = {
    source: {createSource(value, selection) {
      assert.equal(value, text);
      model = {text:value,selection,revision:0,select(next){this.selection=next;},
        apply(next){this.text=this.text.slice(0,next.from)+next.insert+this.text.slice(next.to);this.selection=next.selection;this.revision++;}};
      return model;
    }},
    syntax: {scan(){ return {}; }},
    projection: {create() {
      const element = new Element('div', doc);
      return {element,render(){},readSelection(){return model.selection;},restoreSelection(){},hideSyntax(){}};
    }},
    input: {create(_model, _view, options) {
      failed = options.onFailure;
      const failOnInput = () => options.onFailure('unconverted source input');
      if (settings.failOnSourceInput) box.addEventListener('input',failOnInput);
      return {detach(){
        inputDetached++;
        if (settings.failOnSourceInput) box.removeEventListener('input',failOnInput);
      }};
    }}
  };
  return {box,form,doc,modules,get model(){return model;},fail(text){failed(text);},get inputDetached(){return inputDetached;}};
}

function find(root, name) {
  if (root.getAttribute?.('data-editor-control') === name) return root;
  for (const child of root.children || []) {
    const match = find(child,name);
    if (match) return match;
  }
  return null;
}

test('attach keeps one native form source and mode switches leave revision unchanged', () => {
  const f = fixture();
  const handle = editor.attach(f.box,{modules:f.modules});
  assert.ok(handle);
  assert.equal(f.box.hidden,true);
  assert.equal(f.box.required,true);
  assert.equal(handle.mode,'live');
  const revision = f.model.revision;
  find(f.box.inserted,'source').click();
  assert.equal(handle.mode,'source');
  assert.equal(f.box.hidden,false);
  find(f.box.inserted,'live').click();
  assert.equal(handle.mode,'live');
  assert.equal(f.model.revision,revision);
  assert.equal(f.model.text,'**Words**');
});

test('form submit mirrors exact source and failure blocks stale Save until explicit source edit', () => {
  const f = fixture('abc');
  const handle = editor.attach(f.box,{modules:f.modules});
  f.model.apply({from:1,to:1,insert:'Z',selection:{anchor:2,focus:2}});
  let event = new Event('submit',{cancelable:true});
  f.form.dispatchEvent(event);
  assert.equal(event.defaultPrevented,false);
  assert.equal(f.box.value,'aZbc');
  f.fail('uncertain author input');
  assert.equal(handle.mode,'source');
  assert.equal(find(f.box.inserted,'copy').value,'uncertain author input');
  event = new Event('submit',{cancelable:true});
  f.form.dispatchEvent(event);
  assert.equal(event.defaultPrevented,true);
  f.box.value = 'author corrected source';
  f.box.dispatchEvent(new Event('input'));
  event = new Event('submit',{cancelable:true});
  f.form.dispatchEvent(event);
  assert.equal(event.defaultPrevented,false);
  assert.equal(f.box.value,'author corrected source');
});

test('a failing source input event cannot also clear the Save block', () => {
  const f = fixture('abc',{failOnSourceInput:true});
  const handle = editor.attach(f.box,{modules:f.modules});
  find(f.box.inserted,'source').click();
  f.box.value = 'unconverted source input';
  f.box.dispatchEvent(new Event('input'));
  let event = new Event('submit',{cancelable:true});
  f.form.dispatchEvent(event);
  assert.equal(event.defaultPrevented,true);
  assert.equal(find(f.box.inserted,'copy').value,'unconverted source input');
  f.box.value = 'author corrected input';
  f.box.dispatchEvent(new Event('input'));
  event = new Event('submit',{cancelable:true});
  f.form.dispatchEvent(event);
  assert.equal(event.defaultPrevented,false);
  assert.equal(handle.detach(),true);
});

test('oversize and missing capability leave native textarea untouched', () => {
  const large = fixture('x'.repeat(2000001));
  assert.equal(editor.attach(large.box,{modules:large.modules}),null);
  assert.equal(large.box.hidden,false);
  assert.equal(large.box.inserted,undefined);
  const missing = fixture();
  missing.modules.projection.create = () => { throw new Error('selection unavailable'); };
  assert.equal(editor.attach(missing.box,{modules:missing.modules}),null);
  assert.equal(missing.box.hidden,false);
  assert.equal(missing.box.inserted,undefined);
});

test('detach removes listeners and repeated attach creates one active surface', () => {
  const f = fixture();
  const first = editor.attach(f.box,{modules:f.modules});
  const oldHost = f.box.inserted;
  first.detach();
  assert.equal(f.box.hidden,false);
  assert.equal(f.inputDetached,1);
  assert.equal(oldHost.removed,true);
  const second = editor.attach(f.box,{modules:f.modules});
  assert.ok(second);
  assert.notEqual(f.box.inserted,oldHost);
  second.detach();
  assert.equal(f.inputDetached,2);
});
