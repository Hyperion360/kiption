/* Kiption enhancement layer, paste module. Pasting rich text from Google
 * Docs or Word into a marked text box converts it to this site's markdown
 * and inserts it as text. Anything unexpected falls back to the browser's
 * own plain-text paste. Shift held means paste as plain text. The converter
 * walks a parsed tree and only ever builds a string; it never writes markup
 * back into the page. */
'use strict';
(function (root, factory) {
  var api = factory();
  if (typeof module !== 'undefined' && module.exports) { module.exports = api; }
  if (root.Kip) { root.Kip.pasteToMarkdown = api.convertNodeTree; root.Kip.pasteInit = api.init; }
})(typeof window !== 'undefined' ? window : globalThis, function () {
  var SKIP = { SCRIPT: 1, STYLE: 1, IFRAME: 1, OBJECT: 1, EMBED: 1, IMG: 1, HEAD: 1, META: 1, LINK: 1, NOSCRIPT: 1, TEMPLATE: 1, SVG: 1, TITLE: 1, DEL: 1 };
  var BLOCK = { P: 1, DIV: 1, H1: 1, H2: 1, H3: 1, H4: 1, H5: 1, H6: 1, UL: 1, OL: 1, LI: 1, BLOCKQUOTE: 1, TABLE: 1, TR: 1, TD: 1, TH: 1, BODY: 1, HR: 1, PRE: 1, SECTION: 1, ARTICLE: 1 };
  /* Pastes past these fall back to the browser's plain text paste: a huge
     document or a deeply nested one would freeze the page while it converts. */
  var MAX_HTML_LENGTH = 2000000;
  var MAX_DEPTH = 100;
  var HEADING = { H1: 1, H2: 1, H3: 1, H4: 1, H5: 1, H6: 1 };

  function tagOf(n) { return n.nodeType === 1 ? String(n.tagName || '').toUpperCase() : ''; }
  function styleOf(n, prop) { return String((n.style && n.style[prop]) || '').toLowerCase(); }
  function classOf(n) { return String((n.className !== undefined && n.className) || (n.getAttribute && n.getAttribute('class')) || ''); }
  /* Word's down-level-revealed markup arrives as namespaced tags (o:p,
     v:shape, st1:place): none of them survive the walk. */
  function skipTag(tag) { return !!SKIP[tag] || tag.indexOf(':') > -1; }

  /* Bold: an explicit font-weight wins (Docs wraps everything in a b tag that
     says font-weight:normal), else a bare b or strong means bold (Word), else
     the parent's state carries down. */
  function boldOf(n, inherited) {
    var w = styleOf(n, 'fontWeight');
    if (w !== '') { return w === 'bold' || w === 'bolder' || parseInt(w, 10) >= 600; }
    var t = tagOf(n);
    return (t === 'STRONG' || t === 'B') ? true : inherited;
  }
  function italicOf(n, inherited) {
    var s = styleOf(n, 'fontStyle');
    if (s !== '') { return s === 'italic' || s === 'oblique'; }
    var t = tagOf(n);
    return (t === 'EM' || t === 'I' || t === 'CITE') ? true : inherited;
  }

  /* A link target we are willing to keep: https only, with a Google redirect
     unwrapped to its real destination. Anything else yields ''. */
  function unwrapGoogleUrl(href) {
    var raw = String(href || '').trim();
    var u;
    try { u = new URL(raw); } catch (e) { return ''; }
    var target = raw;
    if (/(^|\.)google\.com$/i.test(u.hostname) && u.pathname === '/url') {
      target = u.searchParams.get('q') || '';
    }
    target = String(target).trim();
    if (!/^https:\/\//i.test(target) || /[\u0000-\u001f\u007f]/.test(target)) { return ''; }
    return target;
  }

  function normalizeMarkdown(s) { return String(s).replace(/\n{3,}/g, '\n\n').replace(/^\s+|\s+$/g, ''); }

  function wrap(text, mark) {
    var m = /^(\s*)([\s\S]*?)(\s*)$/.exec(text);
    if (m[2] === '') { return text; }
    return m[1] + mark + m[2] + mark + m[3];
  }

  function hasBlock(n) {
    var kids = n.childNodes || [];
    for (var i = 0; i < kids.length; i++) {
      var k = kids[i];
      if (k.nodeType === 1 && !skipTag(tagOf(k)) && (BLOCK[tagOf(k)] || hasBlock(k))) { return true; }
    }
    return false;
  }
  function isBlockish(n) { return n.nodeType === 1 && !skipTag(tagOf(n)) && (BLOCK[tagOf(n)] || hasBlock(n)); }

  /* A literal backslash, asterisk or underscore in pasted text gets a
     backslash so the site's markdown shows it as typed ("5 * 3" is not
     emphasis). Real emphasis markers are added after this, unescaped. */
  function escapeLiteral(t) { return t.replace(/([\\*_])/g, '\\$1'); }

  /* One inline node to a string of markdown. st carries inherited b and i. */
  function inline(n, st) {
    if (n.nodeType === 3) { return escapeLiteral(String(n.nodeValue || '').replace(/[\s ]+/g, ' ')); }
    if (n.nodeType !== 1) { return ''; }
    var tag = tagOf(n);
    if (skipTag(tag)) { return ''; }
    if (tag === 'BR') { return '\n'; }
    /* Word's bullet glyphs ride Symbol or Wingdings font spans: the glyph is
       the bullet, the list marker below replaces it */
    if (tag === 'SPAN' && /symbol|wingdings/.test(styleOf(n, 'fontFamily'))) { return ''; }
    /* a footnote anchor (a superscript number linking inside the page) is
       paper baggage: story text keeps the sentence, not the citation machinery */
    if ((tag === 'SUP' || tag === 'SMALL') && isFootnote(n)) { return ''; }
    var b = boldOf(n, st.b), i = italicOf(n, st.i);
    var inner = inlineRun(n.childNodes || [], { b: b, i: i });
    if (tag === 'A') {
      var url = unwrapGoogleUrl(n.getAttribute && n.getAttribute('href'));
      var label = inner.replace(/[\[\]]/g, '');
      if (url === '' || label.trim() === '') { return inner; }
      url = url.replace(/ /g, '%20').replace(/\(/g, '%28').replace(/\)/g, '%29');
      return '[' + label + '](' + url + ')';
    }
    var out = inner;
    var newB = b && !st.b, newI = i && !st.i;
    if (newB && newI) { out = wrap(out, '***'); }
    else if (newB) { out = wrap(out, '**'); }
    else if (newI) { out = wrap(out, '*'); }
    return out;
  }
  /* A superscript or small span whose links all point inside the page and
     whose text is bare numbers: a footnote anchor. */
  function isFootnote(n) {
    var text = tidy(inlineRun(n.childNodes || [], { b: false, i: false }));
    if (text === '' || !/^[\d\s,.]*$/.test(text)) { return false; }
    /* a real footnote anchor links inside the page; a superscript with no
       link is content (an exponent), so it keeps */
    var links = [];
    (function walk(node) {
      var kids = node.childNodes || [];
      for (var i = 0; i < kids.length; i++) {
        if (kids[i].nodeType !== 1) { continue; }
        if (tagOf(kids[i]) === 'A') { links.push(kids[i]); }
        walk(kids[i]);
      }
    })(n);
    if (links.length === 0) { return false; }
    for (var i = 0; i < links.length; i++) {
      var href = String((links[i].getAttribute && links[i].getAttribute('href')) || '');
      if (href.charAt(0) !== '#') { return false; }
    }
    return true;
  }

  function inlineRun(nodes, st) {
    var s = '';
    for (var k = 0; k < nodes.length; k++) { s += inline(nodes[k], st); }
    return s;
  }

  function tidy(s) {
    return s.replace(/[ \t]+/g, ' ').replace(/ ?\n ?/g, '\n').replace(/^\s+|\s+$/g, '');
  }
  function isSceneBreak(s) {
    s = s.replace(/\\([\\*_])/g, '$1');
    return /^(?:\*\s*){3,}$/.test(s) || /^(?:[-\u2013\u2014_=]\s*){3,}$/.test(s) || /^~+$/.test(s) || /^#$/.test(s);
  }

  /* One tidy paragraph of text as a markdown block ('' when empty); the one
     place a scene-break paragraph turns into ---. */
  function paragraph(t) {
    if (t === '') { return []; }
    return [isSceneBreak(t) ? '---' : t];
  }

  /* A list of sibling nodes to markdown blocks. Consecutive inline siblings
     form one paragraph; block siblings recurse. */
  function blocksOf(nodes, ctx) {
    var out = [], run = [];
    function flush() {
      if (!run.length) { return; }
      out = out.concat(paragraph(tidy(inlineRun(run, { b: ctx.b, i: false }))));
      run = [];
    }
    for (var k = 0; k < nodes.length; k++) {
      var n = nodes[k];
      if (n.nodeType === 3) { run.push(n); continue; }
      if (n.nodeType !== 1 || skipTag(tagOf(n))) { continue; }
      if (!isBlockish(n)) { run.push(n); continue; }
      flush();
      out = out.concat(blockOf(n, ctx));
    }
    flush();
    return out;
  }

  /* Word fake lists: paragraphs of class MsoList*, the bullet glyph in a
     Symbol or Wingdings span (dropped above), the number as literal text.
     A bullet paragraph gets a literal '- ' marker; the numbered kind keeps
     its own typed number, so the line reads exactly as Word printed it. */
  function isMsoList(n) { return /msolist/i.test(classOf(n)); }
  function msoListItem(n, ctx) {
    var kids = Array.prototype.slice.call(n.childNodes || []);
    var kept = [], dropped = false;
    for (var i = 0; i < kids.length; i++) {
      if (kids[i].nodeType === 1 && /symbol|wingdings/.test(styleOf(kids[i], 'fontFamily'))) { dropped = true; continue; }
      kept.push(kids[i]);
    }
    var text = tidy(inlineRun(kept, { b: ctx.b, i: false }));
    if (text === '') { return []; }
    return [dropped ? '- ' + text : text];
  }

  function blockOf(n, ctx) {
    var tag = tagOf(n);
    if (isMsoList(n)) { return msoListItem(n, ctx); }
    if (tag === 'HR') { return ['---']; }
    if (HEADING[tag]) {
      var h = tidy(inlineRun(n.childNodes || [], { b: true, i: false }));
      return h === '' ? [] : [wrap(h, '**')];
    }
    if (tag === 'UL' || tag === 'OL') {
      var items = [], num = 0, kids = n.childNodes || [];
      for (var k = 0; k < kids.length; k++) {
        var li = kids[k];
        if (tagOf(li) !== 'LI') { continue; }
        var own = [], nested = [], lk = li.childNodes || [];
        for (var j = 0; j < lk.length; j++) {
          (tagOf(lk[j]) === 'UL' || tagOf(lk[j]) === 'OL' ? nested : own).push(lk[j]);
        }
        var text = blocksOf(own, ctx).join(' ');
        num++;
        if (text !== '') { items.push((tag === 'OL' ? num + '. ' : '- ') + text); }
        items = items.concat(blocksOf(nested, ctx));
      }
      return items;
    }
    if (tag === 'BLOCKQUOTE') {
      var q = blocksOf(n.childNodes || [], ctx);
      return q.length ? [q.map(function (l) { return '> ' + l.replace(/\n/g, ' '); }).join('\n')] : [];
    }
    if (!hasBlock(n)) {
      /* A leaf paragraph: its own bold or italic styling wraps its text. */
      return paragraph(tidy(inline(n, { b: ctx.b, i: false })));
    }
    /* A wrapper of blocks. A Docs wrapper says font-weight:normal, which
       must not leak bold into its children; a bold-looking wrapper emits no
       markers of its own. */
    return blocksOf(n.childNodes || [], { b: boldOf(n, ctx.b) });
  }

  /* The converter: a parsed tree to a markdown string for this site. */
  function tooDeep(root, max) {
    var level = [root];
    for (var d = 0; level.length; d++) {
      if (d > max) { return true; }
      var next = [];
      for (var i = 0; i < level.length; i++) {
        var kids = level[i].childNodes || [];
        for (var k = 0; k < kids.length; k++) { if (kids[k].nodeType === 1) { next.push(kids[k]); } }
      }
      level = next;
    }
    return false;
  }

  function convertNodeTree(root) {
    if (!root || tooDeep(root, MAX_DEPTH)) { return ''; }
    return normalizeMarkdown(blocksOf([root], { b: false }).join('\n\n'));
  }

  /* Insert text at the caret. execCommand('insertText') goes through the
     browser's editing pipeline, so Ctrl/Cmd+Z undoes the paste; setRangeText
     edits the value directly and wipes the undo history (Firefox bug 1220696,
     and see MDN on HTMLInputElement.setRangeText and Document.execCommand).
     execCommand is deprecated but still the only undo-safe route, so it is
     tried first and setRangeText is the fallback when it is missing or
     refuses. Either way an input event tells listeners the value changed. */
  function insertText(box, text) {
    var done = false;
    try { done = document.execCommand('insertText', false, text); } catch (err) { done = false; }
    if (!done) {
      box.setRangeText(text, box.selectionStart, box.selectionEnd, 'end');
      box.dispatchEvent(new Event('input', { bubbles: true }));
    }
  }

  /* Reading the clipboard's html: parse it in an inert document (scripts
     never run) and convert. Any failure yields '' so the caller falls back. */
  function htmlToMarkdown(html, parser) {
    if (String(html).length > MAX_HTML_LENGTH) { return ''; }
    try {
      return convertNodeTree((parser || new DOMParser()).parseFromString(html, 'text/html').body);
    } catch (err) { return ''; }
  }

  /* Listener wiring for one text box. It knows nothing about markdown or
     undo; the two collaborators arrive as arguments, so a later editor can
     reuse it with its own converter or inserter. */
  function attach(box, toMarkdown, insert) {
    var plainNext = false;
    box.addEventListener('keydown', function (e) {
      plainNext = e.shiftKey && (e.ctrlKey || e.metaKey) && String(e.key).toLowerCase() === 'v';
    });
    box.addEventListener('paste', function (e) {
      var cd = e.clipboardData;
      if (!cd) { return; }
      var html = cd.getData('text/html');
      var plain = plainNext;
      plainNext = false;
      if (plain || !html) { return; } /* the browser's own paste does the right thing */
      var md = toMarkdown(html) || cd.getData('text/plain');
      if (md === '') { return; }
      e.preventDefault();
      insert(box, md);
    });
  }

  function init() {
    Array.prototype.forEach.call(document.querySelectorAll('textarea[data-js-module="paste"]'), function (box) {
      attach(box, htmlToMarkdown, insertText);
    });
  }
  if (typeof Kip !== 'undefined' && Kip.init) { Kip.init(init); }

  return { convertNodeTree: convertNodeTree, unwrapGoogleUrl: unwrapGoogleUrl, normalizeMarkdown: normalizeMarkdown, attach: attach, init: init,
    htmlToMarkdown: htmlToMarkdown, MAX_HTML_LENGTH: MAX_HTML_LENGTH, MAX_DEPTH: MAX_DEPTH };
});
