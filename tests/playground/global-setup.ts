import { chromium, type FullConfig } from '@playwright/test';
import path from 'node:path';

export default async function globalSetup(_config: FullConfig): Promise<void> {
  // Match WP's siteurl in Playground (127.0.0.1, not localhost) to avoid CORS.
  const baseURL = `http://127.0.0.1:${process.env.WP_PORT ?? '12345'}`;
  const browser = await chromium.launch();
  const context = await browser.newContext({ baseURL });
  const page = await context.newPage();

  // Playground CLI auto-logs in the admin user via blueprint.json (login: true).
  await page.goto('/wp-admin/', { waitUntil: 'domcontentloaded' });

  if (/wp-login\.php/.test(page.url())) {
    // Fallback: not auto-logged-in. Submit credentials manually.
    await page.fill('#user_login', 'admin');
    await page.fill('#user_pass', 'password');
    await Promise.all([
      page.waitForURL('**/wp-admin/**', { timeout: 30_000 }),
      page.click('#wp-submit'),
    ]);
  }

  // Sanity check: we should be inside wp-admin now.
  if (!/\/wp-admin\//.test(page.url())) {
    throw new Error(`globalSetup: not authenticated; final URL = ${page.url()}`);
  }

  await context.storageState({ path: path.join(__dirname, 'auth.json') });
  await browser.close();
}
