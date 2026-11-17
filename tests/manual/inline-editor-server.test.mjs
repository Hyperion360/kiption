import assert from 'node:assert/strict';
import { test } from 'node:test';

test('local inline-editor server serves only the fixture and package assets', async () => {
  const { createServer } = await import('./inline-editor-server.mjs');
  const server = createServer();
  await new Promise(resolve => server.listen(0, '127.0.0.1', resolve));
  const base = `http://127.0.0.1:${server.address().port}`;
  try {
    for (const path of [
      '/tests/manual/inline-editor-comparison.html',
      '/tests/manual/inline-editor-dom.js',
      '/public/assets/inline-editor/source.js',
      '/public/assets/inline-editor/syntax.js',
      '/public/assets/inline-editor/projection.js',
      '/public/assets/inline-editor/input.js',
      '/public/assets/inline-editor/editor.js',
      '/public/assets/inline-editor/editor.css'
    ]) {
      const response = await fetch(base + path);
      assert.equal(response.status, 200, path);
    }
    for (const path of ['/AGENTS.md', '/config.php', '/tests/MarkdownTest.php', '/%2e%2e/config.php', '/public/assets/inline-editor/../../config.php']) {
      const response = await fetch(base + path);
      assert.equal(response.status, 404, path);
    }
    assert.equal((await fetch(base + '/tests/manual/inline-editor-comparison.html', { method: 'POST' })).status, 405);
  } finally {
    await new Promise(resolve => server.close(resolve));
  }
});
