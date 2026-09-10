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
