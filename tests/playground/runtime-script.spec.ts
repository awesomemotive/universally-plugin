import { test, expect } from '@playwright/test';

// 64-char key: the plugin splits it into a 32-char public half (sent to the
// browser) and a 32-char private half (server-side only).
const API_KEY = 'a'.repeat(32) + 'b'.repeat(32);
const PUBLIC_KEY = 'a'.repeat(32);
const CONNECTED = { 'X-Universally-Test-Api-Key': API_KEY };
const EXPECTED_SRC = `https://scripts.universally.com/p/${PUBLIC_KEY}/s.js`;

// These tests deliberately use the `request` fixture rather than a browser
// page: the script URL points at the real production host, which 404s here,
// and a browser load would add a console error that smoke.spec.ts asserts on.

function headOf(html: string): string {
  const match = html.match(/<head[^>]*>([\s\S]*?)<\/head>/i);
  expect(match, 'page should have a <head>').not.toBeNull();
  return match ? match[1] : '';
}

function runtimeScriptTag(html: string): string | undefined {
  return html.match(/<script[^>]*\/s\.js[^>]*><\/script>/i)?.[0];
}

test.describe('Universally browser runtime script', () => {
  test('connected site loads the Universally runtime in the head', async ({ request }) => {
    const res = await request.get('/', { headers: CONNECTED });
    expect(res.status()).toBe(200);

    const head = headOf(await res.text());
    const tag = runtimeScriptTag(head);
    expect(tag, 'runtime <script> tag in <head>').toBeDefined();

    // src is the frozen path built from the public half of the key.
    expect(tag).toContain(`src="${EXPECTED_SRC}"`);
    // Non-blocking, and never a module: the file reads
    // document.currentScript.src, which is null for module scripts.
    expect(tag).toMatch(/\sasync(=|\s|>)/i);
    expect(tag).not.toContain('type="module"');
    // No cache-busting query string — the path is frozen by design.
    expect(tag).not.toContain('?ver=');
  });

  test('unconnected site prints no runtime tag', async ({ request }) => {
    const res = await request.get('/');
    expect(res.status()).toBe(200);
    expect(await res.text()).not.toContain('/s.js');
  });

  test('translated pages load it too', async ({ request }) => {
    const res = await request.get('/pt/', { headers: CONNECTED });
    expect(res.status()).toBe(200);

    const tag = runtimeScriptTag(headOf(await res.text()));
    expect(tag, 'runtime <script> tag in <head>').toBeDefined();
    expect(tag).toContain(`src="${EXPECTED_SRC}"`);
  });
});
