import { test, expect, type APIRequestContext, type Playwright } from '@playwright/test';

const BASE = `http://127.0.0.1:${process.env.WP_PORT ?? '12345'}`;

// Playground CLI's auto-login (blueprint `login: true`) intercepts the first
// request from any client lacking this marker cookie and answers with a
// self-302. Every context here sends the marker so we observe real plugin
// behavior, as an anonymous (not logged-in) visitor.
const AUTO_LOGIN_MARKER = 'playground_auto_login_already_happened=1';

async function anonContext(playwright: Playwright, extraCookies = ''): Promise<APIRequestContext> {
  return playwright.request.newContext({
    baseURL: BASE,
    extraHTTPHeaders: {
      Cookie: extraCookies ? `${AUTO_LOGIN_MARKER}; ${extraCookies}` : AUTO_LOGIN_MARKER,
    },
  });
}

// Paths under /{lang}/ that must never serve English content with 200.
// Each must 301 to its unprefixed source URL (path + query preserved).
const REDIRECT_CASES = [
  { path: '/es/feed/', target: '/feed/' },
  { path: '/es/comments/feed/', target: '/comments/feed/' },
  { path: '/es/wp-sitemap.xml', target: '/wp-sitemap.xml' },
  { path: '/es/robots.txt', target: '/robots.txt' },
  { path: '/es/?feed=rss2', target: '/?feed=rss2' },
  { path: '/es/wp-json/', target: '/wp-json/' },
] as const;

test.describe('language-prefixed non-HTML endpoints', () => {
  for (const { path, target } of REDIRECT_CASES) {
    test(`301s ${path} → ${target}`, async ({ playwright }) => {
      const ctx = await anonContext(playwright);
      const res = await ctx.get(path, { maxRedirects: 0 });
      expect(res.status(), `${path} must not pass through`).toBe(301);
      const location = new URL(res.headers()['location'] ?? '', BASE);
      expect(location.pathname + location.search).toBe(target);
      await ctx.dispose();
    });
  }

  test('translated HTML page still returns 200 HTML (no redirect)', async ({ playwright }) => {
    const ctx = await anonContext(playwright);
    const res = await ctx.get('/es/', { maxRedirects: 0 });
    expect(res.status()).toBe(200);
    expect(res.headers()['content-type'] ?? '').toContain('text/html');
    await ctx.dispose();
  });

  test('cookie preference still redirects unprefixed HTML pages', async ({ playwright }) => {
    const ctx = await anonContext(playwright, 'universally_lang=es');
    const res = await ctx.get('/', { maxRedirects: 0 });
    expect(res.status()).toBe(302);
    const location = new URL(res.headers()['location'] ?? '', BASE);
    expect(location.pathname).toBe('/es/');
    await ctx.dispose();
  });

  test('cookie preference never redirects the unprefixed feed', async ({ playwright }) => {
    const ctx = await anonContext(playwright, 'universally_lang=es');
    const res = await ctx.get('/feed/', { maxRedirects: 0 });
    expect(res.status()).toBe(200);
    await ctx.dispose();
  });
});
