import { test, expect, type APIRequestContext, type APIResponse } from '@playwright/test';

const COOKIE = 'universally_lang';

function setCookieHeaders(res: APIResponse): string[] {
  return res.headersArray()
    .filter((h) => h.name.toLowerCase() === 'set-cookie')
    .map((h) => h.value);
}

function langSetCookie(res: APIResponse): string | undefined {
  return setCookieHeaders(res).find((v) => v.startsWith(`${COOKIE}=`));
}

async function hasLangCookie(ctx: APIRequestContext): Promise<boolean> {
  const state = await ctx.storageState();
  return state.cookies.some((c) => c.name === COOKIE);
}

test.describe('sticky language cookie — default (remember on)', () => {
  test('visiting /pt/ sets the 30-day cookie', async ({ request }) => {
    const res = await request.get('/pt/', { maxRedirects: 0 });
    expect(res.status()).toBe(200);
    const cookie = langSetCookie(res);
    expect(cookie, 'Set-Cookie for universally_lang').toBeDefined();
    expect(cookie).toMatch(/^universally_lang=pt;/);
    expect(await hasLangCookie(request)).toBe(true);
  });

  test('unprefixed GET with the cookie 302s to /pt/', async ({ request }) => {
    await request.get('/pt/');
    const res = await request.get('/', { maxRedirects: 0 });
    expect(res.status()).toBe(302);
    expect(res.headers()['location']).toMatch(/\/pt\/$/);
  });

  test('?universally_switch=source clears the cookie and redirects clean', async ({ request }) => {
    await request.get('/pt/');
    const res = await request.get('/?universally_switch=source', { maxRedirects: 0 });
    expect(res.status()).toBe(302);
    expect(res.headers()['location']).toMatch(/\/$/);
    expect(res.headers()['location']).not.toContain('universally_switch');
    expect(await hasLangCookie(request)).toBe(false);
  });
});

const OFF = { 'X-Universally-Test-Remember': '0' };
const ON = { 'X-Universally-Test-Remember': '1' };
const FILTER_OFF = { 'X-Universally-Test-Filter': 'off' };

test.describe('sticky language cookie — remember off', () => {
  test('visiting /pt/ sets no cookie', async ({ request }) => {
    const res = await request.get('/pt/', { headers: OFF, maxRedirects: 0 });
    expect(res.status()).toBe(200);
    expect(langSetCookie(res)).toBeUndefined();
    expect(await hasLangCookie(request)).toBe(false);
  });

  test('unprefixed GET is not redirected', async ({ request }) => {
    const res = await request.get('/', { headers: OFF, maxRedirects: 0 });
    expect(res.status()).toBe(200);
  });

  test('a leftover cookie is cleared instead of redirecting', async ({ request }) => {
    // Cookie planted while the feature was on (the duplicator.com scenario).
    await request.get('/pt/', { headers: ON });
    expect(await hasLangCookie(request)).toBe(true);

    const res = await request.get('/', { headers: OFF, maxRedirects: 0 });
    expect(res.status()).toBe(200);
    const cleared = langSetCookie(res);
    expect(cleared, 'expiring Set-Cookie').toBeDefined();
    // PHP's setcookie() rewrites an empty-string value to "deleted" in the
    // Set-Cookie header regardless of the value passed in — this is how the
    // pre-existing clearLanguageCookie() has always cleared the cookie.
    expect(cleared).toMatch(/^universally_lang=deleted;/);
    expect(await hasLangCookie(request)).toBe(false);
  });

  test('?universally_switch=source still works as a fallback', async ({ request }) => {
    const res = await request.get('/?universally_switch=source', { headers: OFF, maxRedirects: 0 });
    expect(res.status()).toBe(302);
    expect(res.headers()['location']).not.toContain('universally_switch');
  });

  test('explicit on setting keeps the redirect', async ({ request }) => {
    await request.get('/pt/', { headers: ON });
    const res = await request.get('/', { headers: ON, maxRedirects: 0 });
    expect(res.status()).toBe(302);
  });
});

test.describe('sticky language cookie — universally_remember_language filter', () => {
  test('filter returning false overrides the (default on) setting', async ({ request }) => {
    await request.get('/pt/');
    expect(await hasLangCookie(request)).toBe(true);

    const res = await request.get('/', { headers: FILTER_OFF, maxRedirects: 0 });
    expect(res.status()).toBe(200);
    expect(await hasLangCookie(request)).toBe(false);
  });
});
