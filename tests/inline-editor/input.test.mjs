import assert from 'node:assert/strict';
import test from 'node:test';
import source from '../../public/assets/inline-editor/source.js';
import input from '../../public/assets/inline-editor/input.js';

function control() {
  const listeners = new Map();
  return {
    value: '', selectionStart: 0, selectionEnd: 0, selectionDirection: 'forward',
    addEventListener(type, fn) { const list = listeners.get(type) || []; list.push(fn); listeners.set(type, list); },
    removeEventListener(type, fn) { listeners.set(type, (listeners.get(type) || []).filter(item => item !== fn)); },
    setSelectionRange(start, end, direction) { this.selectionStart = start; this.selectionEnd = end; this.selectionDirection = direction; },
    fire(type, details = {}) {
      const event = { type, target: this, cancelable: true, defaultPrevented: false,
        preventDefault() { if (this.cancelable) this.defaultPrevented = true; }, ...details };
      for (const fn of listeners.get(type) || []) fn(event);
      return event;
    }
  };
}

function fixture(initial = 'ab', options = {}) {
  const model = source.createSource(initial, options.selection || {anchor:0,focus:0});
  const live = control(), text = control(), failures = [];
  let frozen = 0, rendered = 0;
  const view = {
    element: live,
    readSelection() { return model.selection; },
    restoreSelection() {},
    render() { rendered++; },
    freeze() { frozen++; },
    unfreeze() { frozen--; }
  };
  const controller = input.create(model, view, {
    source: text, convertHtml: options.convertHtml,
    readNativeChange: options.readNativeChange,
    onFailure: value => failures.push(value), now: () => options.now || 1000
  });
  return {model, live, text, view, controller, failures, get frozen() {return frozen;}, get rendered() {return rendered;}};
}

test('cancellable typing, deletion, and Enter update source and directed selection', () => {
  const f = fixture('ab');
  assert.equal(f.live.fire('beforeinput', {inputType:'insertText',data:'x'}).defaultPrevented, true);
  assert.equal(f.model.text, 'xab');
  assert.deepEqual(f.model.selection, {anchor:1,focus:1});
  f.live.fire('beforeinput', {inputType:'deleteContentBackward'});
  assert.equal(f.model.text, 'ab');
  f.live.fire('beforeinput', {inputType:'insertParagraph'});
  assert.equal(f.model.text, '\nab');
  assert.deepEqual(f.model.selection, {anchor:1,focus:1});
});

test('backspace removes one visible grapheme instead of a partial emoji', () => {
  const emoji = '👩🏽‍💻';
  const f = fixture(emoji + 'a', {selection:{anchor:emoji.length,focus:emoji.length}});
  f.live.fire('beforeinput', {inputType:'deleteContentBackward'});
  assert.equal(f.model.text, 'a');
  assert.deepEqual(f.model.selection, {anchor:0,focus:0});
  const g = fixture('a' + emoji, {selection:{anchor:1,focus:1}});
  g.live.fire('beforeinput', {inputType:'deleteContentForward'});
  assert.equal(g.model.text, 'a');
});

test('source and live controls share one undo journal and do not merge a selection jump', () => {
  const f = fixture('ab');
  f.live.fire('beforeinput', {inputType:'insertText',data:'x'});
  f.model.select({anchor:3,focus:3});
  f.model.select({anchor:1,focus:1});
  f.text.setSelectionRange(1,1,'forward');
  f.text.fire('beforeinput', {inputType:'insertText',data:'y'});
  assert.equal(f.model.text, 'xyab');
  f.live.fire('keydown', {key:'z',metaKey:true});
  assert.equal(f.model.text, 'xab');
  f.text.fire('beforeinput', {inputType:'historyUndo'});
  assert.equal(f.model.text, 'ab');
  f.text.fire('beforeinput', {inputType:'historyRedo'});
  assert.equal(f.model.text, 'xab');
  assert.equal(f.text.value, 'xab');
});

test('composition keeps projection frozen and commits one undo item', () => {
  let native = {from:0,to:2,text:'日本',selection:{anchor:2,focus:2}};
  const f = fixture('ab', {readNativeChange:() => native});
  f.live.fire('compositionstart');
  assert.equal(f.frozen, 1);
  f.live.fire('input', {inputType:'insertCompositionText',isComposing:true});
  assert.equal(f.model.text, 'ab');
  f.live.fire('compositionend');
  assert.equal(f.frozen, 0);
  assert.equal(f.model.text, '日本');
  f.live.fire('beforeinput', {inputType:'historyUndo'});
  assert.equal(f.model.text, 'ab');
});

test('noncancelable native input reconciles one block and rejects ambiguous DOM', () => {
  let change = {from:0,to:2,text:'cab',selection:{anchor:1,focus:1}};
  const f = fixture('ab', {readNativeChange:() => change});
  f.live.fire('input', {inputType:'insertText'});
  assert.equal(f.model.text, 'cab');
  assert.deepEqual(f.model.selection, {anchor:1,focus:1});
  change = {};
  f.live.fire('input', {inputType:'insertText'});
  assert.equal(f.controller.failed, true);
  assert.equal(f.failures.length, 1);
  assert.equal(f.model.text, 'cab');
});

test('default source textarea reconciliation preserves a backward selection', () => {
  const f = fixture('abc');
  f.text.value = 'aXYc';
  f.text.setSelectionRange(1,3,'backward');
  f.text.fire('input', {inputType:'insertReplacementText'});
  assert.equal(f.model.text, 'aXYc');
  assert.deepEqual(f.model.selection, {anchor:3,focus:1});
});

test('default native block reconciliation reads one changed line and rejects an unsafe node', () => {
  const f = fixture('a\nb');
  const textNode = {nodeType:3,nodeName:'#text',data:'bx'};
  const span = {nodeName:'SPAN',classList:{contains:value => value === 'ki-token'},childNodes:[textNode]};
  const first = {nodeName:'DIV',classList:{contains:value => value === 'ki-line'},childNodes:[span],textContent:'a'};
  const second = {nodeName:'DIV',classList:{contains:value => value === 'ki-line'},childNodes:[span],textContent:'bx',contains:node => node === textNode};
  f.live.children = [first,second];
  f.live.ownerDocument = {
    getSelection:() => ({anchorNode:textNode,anchorOffset:2,focusNode:textNode,focusOffset:2}),
    createRange:() => ({setStart(){},setEnd(node,offset){this.offset=offset;},toString(){return 'bx'.slice(0,this.offset);}})
  };
  f.live.fire('input', {inputType:'insertText'});
  assert.equal(f.model.text, 'a\nbx');
  assert.deepEqual(f.model.selection, {anchor:4,focus:4});
  second.childNodes = [{nodeName:'IMG'}]; second.textContent = 'by';
  f.live.fire('input', {inputType:'insertText'});
  assert.equal(f.controller.failed, true);
  assert.equal(f.model.text, 'a\nbx');
});

test('noncancelable browser-menu undo and redo keep one model history', () => {
  const f = fixture('a');
  f.live.fire('beforeinput', {inputType:'insertText',data:'x'});
  f.live.fire('beforeinput', {inputType:'historyUndo',cancelable:false});
  f.live.fire('input', {inputType:'historyUndo'});
  assert.equal(f.model.text, 'a');
  f.live.fire('beforeinput', {inputType:'historyRedo',cancelable:false});
  f.live.fire('input', {inputType:'historyRedo'});
  assert.equal(f.model.text, 'xa');
});

test('history projection failure is visible and blocks further input', () => {
  const f = fixture('a');
  f.live.fire('beforeinput', {inputType:'insertText',data:'x'});
  f.view.render = () => { throw Error('projection failed'); };
  assert.doesNotThrow(() => f.live.fire('beforeinput', {inputType:'historyUndo'}));
  assert.equal(f.controller.failed, true);
  assert.equal(f.failures.length, 1);
  const afterFailure = f.live.fire('beforeinput', {inputType:'insertText',data:'z'});
  assert.equal(afterFailure.defaultPrevented, true);
  assert.equal(f.model.text, 'a');
});

test('HTML paste uses converter; Shift-paste and conversion failures retain plain text', () => {
  const f = fixture('', {convertHtml: html => html === '<b>x</b>' ? '**x**' : 'z'.repeat(2000001)});
  const clip = (html, plain) => ({getData: kind => kind === 'text/html' ? html : plain});
  f.live.fire('paste', {clipboardData:clip('<b>x</b>','x')});
  assert.equal(f.model.text, '**x**');
  f.live.fire('paste', {clipboardData:clip('<b>y</b>','y'),shiftKey:true});
  assert.equal(f.model.text, '**x**y');
  f.live.fire('paste', {clipboardData:clip('<b>z</b>','z')});
  assert.equal(f.model.text, '**x**yz');
  const g = fixture('', {convertHtml:() => {throw Error('bad HTML');}});
  g.live.fire('paste', {clipboardData:clip('<script>x</script>','safe')});
  assert.equal(g.model.text, 'safe');
  const h = fixture('', {convertHtml:() => {throw Error('bad HTML');}});
  h.live.fire('paste', {clipboardData:clip('<b>only HTML</b>','')});
  assert.equal(h.controller.failed, true);
  assert.equal(h.failures[0], '<b>only HTML</b>');
  assert.equal(h.model.text, '');
  const denied = fixture('untouched');
  assert.doesNotThrow(() => denied.live.fire('paste', {clipboardData:{getData(){throw Error('clipboard denied');}}}));
  assert.equal(denied.controller.failed, true);
  assert.equal(denied.model.text, 'untouched');
  const absent = fixture('untouched');
  const missing = absent.live.fire('paste');
  assert.equal(missing.defaultPrevented, true);
  assert.equal(absent.controller.failed, true);
  assert.equal(absent.model.text, 'untouched');
});

test('detach removes event handling', () => {
  const f = fixture('ab');
  f.controller.detach();
  f.live.fire('beforeinput', {inputType:'insertText',data:'x'});
  assert.equal(f.model.text, 'ab');
});
