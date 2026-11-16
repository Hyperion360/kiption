import http from 'node:http';
import { readFile } from 'node:fs/promises';
import { fileURLToPath } from 'node:url';
import path from 'node:path';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const files = new Map([
  ['/tests/manual/inline-editor-comparison.html', 'text/html; charset=utf-8'],
  ['/tests/manual/inline-editor-dom.js', 'text/javascript; charset=utf-8'],
  ['/public/assets/inline-editor/source.js', 'text/javascript; charset=utf-8'],
  ['/public/assets/inline-editor/syntax.js', 'text/javascript; charset=utf-8'],
  ['/public/assets/inline-editor/projection.js', 'text/javascript; charset=utf-8'],
  ['/public/assets/inline-editor/editor.css', 'text/css; charset=utf-8']
]);

export function createServer() {
  return http.createServer(async (request, response) => {
    if (request.method !== 'GET') {
      response.writeHead(405, { Allow: 'GET' }); response.end(); return;
    }
    const pathname = (request.url || '').split('?', 1)[0];
    const type = files.get(pathname);
    if (!type) { response.writeHead(404); response.end(); return; }
    try {
      const contents = await readFile(path.join(root, pathname));
      response.writeHead(200, { 'Content-Type': type, 'Cache-Control': 'no-store', 'X-Content-Type-Options': 'nosniff' });
      response.end(contents);
    } catch {
      response.writeHead(404); response.end();
    }
  });
}

if (process.argv[1] && path.resolve(process.argv[1]) === fileURLToPath(import.meta.url)) {
  const port = Number(process.argv[2] || 8210);
  if (!Number.isInteger(port) || port < 1 || port > 65535) throw new RangeError('port');
  createServer().listen(port, '127.0.0.1', () => {
    process.stdout.write(`Inline editor fixture: http://127.0.0.1:${port}/tests/manual/inline-editor-comparison.html?run=1\n`);
  });
}
