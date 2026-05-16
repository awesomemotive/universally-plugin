import { test, expect, type Page, type ConsoleMessage } from '@playwright/test';
import path from 'node:path';

const ADMIN_USER = 'admin';
const ADMIN_PASS = 'password';
const SLUG = 'universally-language-translation-multilingual-tool';
const PANEL_PAGE = '/wp-admin/admin.php?page=universally_settings';

const TABS = ['general_tab', 'language_switcher_tab', 'styling_tab'] as const;

const FATAL_NEEDLES = [
  'There has been a critical error',
  'Fatal error',
  'Parse error',
  'Uncaught Error',
  'Uncaught TypeError',
] as const;

function attachConsoleGuard(page: Page): string[] {
  const errors: string[] = [];
  page.on('console', (msg: ConsoleMessage) => {
    if (msg.type() === 'error') {
      errors.push(msg.text());
    }
  });
  page.on('pageerror', (err) => {
    errors.push(String(err));
  });
  return errors;
}

async function expectNoFatalCopy(page: Page): Promise<void> {
  const body = await page.content();
  for (const needle of FATAL_NEEDLES) {
    expect(body, `page contained "${needle}"`).not.toContain(needle);
  }
}

async function login(page: Page): Promise<void> {
  await page.goto('/wp-login.php');
  await page.fill('#user_login', ADMIN_USER);
  await page.fill('#user_pass', ADMIN_PASS);
  await Promise.all([
    page.waitForURL('**/wp-admin/**', { timeout: 30_000 }),
    page.click('#wp-submit'),
  ]);
}

test.describe('Universally smoke', () => {
  test.beforeAll(async ({ browser }) => {
    const ctx = await browser.newContext();
    const page = await ctx.newPage();
    await login(page);
    await ctx.storageState({ path: path.join(__dirname, 'auth.json') });
    await ctx.close();
  });

  test.use({ storageState: path.join(__dirname, 'auth.json') });

  test('plugin is active and shows no activation error', async ({ page }) => {
    const consoleErrors = attachConsoleGuard(page);
    await page.goto('/wp-admin/plugins.php');

    const row = page.locator(`tr[data-slug="${SLUG}"]`);
    await expect(row, 'plugin row should exist in plugins list').toBeVisible();
    await expect(row, 'plugin row should be marked active').toHaveClass(/(^|\s)active(\s|$)/);

    // WP renders "Plugin could not be activated because it triggered a fatal error" inside .notice-error.
    const errorNotices = page.locator('.notice-error');
    await expect(errorNotices, 'no error notices should be present').toHaveCount(0);

    await expectNoFatalCopy(page);
    expect(consoleErrors, 'no console errors on plugins page').toEqual([]);
  });

  for (const tabId of TABS) {
    test(`settings tab hydrates — ${tabId}`, async ({ page }) => {
      const consoleErrors = attachConsoleGuard(page);
      await page.goto(`${PANEL_PAGE}#${tabId}`);

      // React panel mounts; the tab button for the requested hash must become active.
      const activeTab = page.locator(`.wp-panel__tab.is-active`);
      await expect(activeTab, 'an active tab should be highlighted').toHaveCount(1, { timeout: 15_000 });

      // Tab content container should match the requested hash.
      const tabContent = page.locator(`.wp-panel__tab-content [data-tab="${tabId}"]`);
      await expect(tabContent, `[data-tab="${tabId}"] container should render`).toBeVisible({ timeout: 15_000 });

      // Schema-driven fields render under the tab — proves settings.php wasn't dropped.
      const fieldCount = await tabContent.locator('.wp-panel__field, .wp-panel__section').count();
      expect(fieldCount, `tab "${tabId}" should render at least one field/section`).toBeGreaterThan(0);

      await expectNoFatalCopy(page);
      // Onboarding state changes / API-key validation can log harmless 4xx fetches; we only
      // fail on true console "error" messages, which this guard already captures.
      expect(consoleErrors, `no console errors on ${tabId}`).toEqual([]);
    });
  }

  test('REST namespace is registered', async ({ request }) => {
    const res = await request.get('/wp-json/universally/v1');
    // 200 if the namespace responds with its route index, 404 only if registration failed.
    expect(res.status(), 'universally/v1 namespace should be discoverable').toBeLessThan(400);
  });

  test('front-end home renders without a fatal error', async ({ page }) => {
    const consoleErrors = attachConsoleGuard(page);
    const res = await page.goto('/');
    expect(res?.status(), 'front-end should not 5xx').toBeLessThan(500);

    // Even a 200 can carry a WSOD-style fatal in the body; check copy too.
    await expectNoFatalCopy(page);

    // Sanity: something resembling a real page rendered.
    await expect(page.locator('html')).toBeVisible();

    expect(consoleErrors, 'no console errors on front-end').toEqual([]);
  });
});
