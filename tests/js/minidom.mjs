/* A tiny HTML reader for the paste tests, so they run without a DOM library.
 * It builds the same node shape the converter reads (nodeType, tagName,
 * childNodes, style, getAttribute). It handles well-formed fixture markup
 * only; it is a test helper, not a browser. */
const VOID = new Set(['META', 'BR', 'HR', 'IMG', 'LINK', 'INPUT']);
const NAMED = { amp: '&', lt: '<', gt: '>', quot: '"', apos: "'", nbsp: ' ', ldquo: '“', rdquo: '”', lsquo: '‘', rsquo: '’' };

function decode(s) {
  return s.replace(/&(#x[0-9a-f]+|#\d+|[a-z]+);/gi, (m, e) => {
    if (e[0] === '#') { return String.fromCodePoint(e[1].toLowerCase() === 'x' ? parseInt(e.slice(2), 16) : parseInt(e.slice(1), 10)); }
    return NAMED[e.toLowerCase()] ?? m;
  });
}

function styleOf(css) {
  const style = {};
  for (const part of css.split(';')) {
    const i = part.indexOf(':');
    if (i < 0) { continue; }
    const key = part.slice(0, i).trim().replace(/-([a-z])/g, (m, c) => c.toUpperCase());
    style[key] = part.slice(i + 1).trim();
  }
  return style;
}

function element(tag, attrs) {
  return { nodeType: 1, tagName: tag, childNodes: [], style: styleOf(attrs.style || ''), getAttribute: (k) => attrs[k] ?? null };
}

export function parseBody(html) {
  const body = element('BODY', {});
  const stack = [body];
  const re = /<!--[\s\S]*?-->|<!doctype[^>]*>|<\/([a-z0-9]+)\s*>|<([a-z0-9]+)((?:\s+[^\s=>\/]+(?:\s*=\s*(?:"[^"]*"|'[^']*'|[^\s>]+))?)*)\s*\/?>|([^<]+)/gi;
  let m;
  while ((m = re.exec(html)) !== null) {
    const top = stack[stack.length - 1];
    if (m[4] !== undefined) { top.childNodes.push({ nodeType: 3, nodeValue: decode(m[4]) }); continue; }
    if (m[1] !== undefined) {
      const name = m[1].toUpperCase();
      if (name === 'HTML' || name === 'BODY') { continue; }
      for (let i = stack.length - 1; i > 0; i--) { if (stack[i].tagName === name) { stack.length = i; break; } }
      continue;
    }
    if (m[2] === undefined) { continue; }
    const name = m[2].toUpperCase();
    if (name === 'HTML' || name === 'BODY') { continue; }
    const attrs = {};
    for (const a of m[3].matchAll(/([^\s=]+)(?:\s*=\s*(?:"([^"]*)"|'([^']*)'|([^\s>]+)))?/g)) {
      attrs[a[1].toLowerCase()] = decode(a[2] ?? a[3] ?? a[4] ?? '');
    }
    const el = element(name, attrs);
    top.childNodes.push(el);
    if (!VOID.has(name)) { stack.push(el); }
  }
  return body;
}

/* A parser object shaped like the browser's DOMParser. */
export class MiniDOMParser {
  parseFromString(html) { return { body: parseBody(html) }; }
}
