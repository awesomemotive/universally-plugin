import { test, expect, type Page } from '@playwright/test';
import path from 'node:path';

// Mirrors playwright.config.ts / global-setup.ts: `baseURL` is a test-scoped
// option and is therefore not injectable into afterAll, so rebuild it here.
const BASE_URL = `http://127.0.0.1:${process.env.WP_PORT ?? '12345'}`;
const AUTH_STATE = path.join(__dirname, 'auth.json');

const PANEL_PAGE = '/wp-admin/admin.php?page=universally_settings';
const PERMALINK_PAGE = '/wp-admin/options-permalink.php';

const NOTICE = '[data-notice-id="plain_permalinks"]';

const PRETTY_STRUCTURE = '/%postname%/';

/**
 * Submit the permalink settings form with the given structure.
 * Passing '' selects the "Plain" option. Falls back to the custom-structure
 * radio + text input when WP does not offer a preset radio for the value.
 */
async function setPermalinkStructure(page: Page, structure: string): Promise<void> {
  await page.goto(PERMALINK_PAGE, { waitUntil: 'domcontentloaded' });

  const preset = page.locator(`input[name="selection"][value="${structure}"]`);
  if (await preset.count()) {
    await preset.check();
  } else {
    await page.check('input[name="selection"][value="custom"]');
    await page.fill('#permalink_structure', structure);
  }

  await Promise.all([
    page.waitForURL(/options-permalink\.php/, { timeout: 30_000 }),
    page.click('#submit'),
  ]);
}

test.describe.serial('Plain permalinks notice', () => {
  test('no notice with pretty permalinks', async ({ page }) => {
    await page.goto(PANEL_PAGE);

    // React must have mounted before we can trust the absence of the notice.
    await expect(page.locator('.wp-panel__tab.is-active')).toHaveCount(1, { timeout: 15_000 });
    await expect(page.locator(NOTICE), 'notice should be absent with pretty permalinks').toHaveCount(0);
  });

  test('notice appears with plain permalinks', async ({ page }) => {
    await setPermalinkStructure(page, '');

    await page.goto(PANEL_PAGE);

    const notice = page.locator(NOTICE);
    await expect(notice, 'notice should render with plain permalinks').toBeVisible({ timeout: 15_000 });
    await expect(notice).toContainText('Pretty permalinks are required');

    const action = notice.locator('.wp-panel__notice-card-action');
    await expect(action).toBeVisible();
    await expect(action).toHaveAttribute('href', /options-permalink\.php$/);
  });

  // Restore pretty permalinks in a context of its own so later suites (and reruns
  // of this one) start from the default structure even if a test above failed.
  test.afterAll(async ({ browser }) => {
    const context = await browser.newContext({ baseURL: BASE_URL, storageState: AUTH_STATE });
    const page = await context.newPage();
    try {
      await setPermalinkStructure(page, PRETTY_STRUCTURE);
    } finally {
      await context.close();
    }
  });
});
