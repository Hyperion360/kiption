// Isolated static comparison server. No application routes or POST handlers.
import { createServer } from 'node:http';
import { readFile } from 'node:fs/promises';
const root = new URL('../../', import.meta.url);
const assets = new Set(['reader.css','subset.js','source-document.js','rich-projection.js','rich-active.js','text-edit.js','smart.js','paste.js','editor.js','rich-editor.js']);
const manual = new Set(['editor-comparison.html','editor-environment.js','editor-comparison.js','editor-dom.test.js']);
createServer(async (req,res) => {
  let path = new URL(req.url,'http://localhost').pathname;
  if(path === '/') path = '/tests/manual/editor-comparison.html';
  const allowed = path.startsWith('/assets/') && assets.has(path.slice(8)) || path.startsWith('/tests/manual/') && manual.has(path.slice(14)) || path === '/tests/fixtures/editor-actions.json';
  if(req.method !== 'GET' || !allowed){res.writeHead(404);res.end();return;}
  try { const data=await readFile(new URL(path.startsWith('/assets/')?'public'+path:path.slice(1),root));res.setHeader('Content-Type',path.endsWith('.js')?'text/javascript':path.endsWith('.css')?'text/css':path.endsWith('.json')?'application/json':'text/html');res.end(data); }
  catch {res.writeHead(404);res.end();}
}).listen(Number(process.argv[2] || 8197),'127.0.0.1',function(){console.log('Editor comparison: http://127.0.0.1:'+this.address().port);});
