import { chromium, type FullConfig } from '@playwright/test';
import path from 'node:path';

export default async function globalSetup(_config: FullConfig): Promise<void> {
  const baseURL = `http://127.0.0.1:${process.env.WP_PORT ?? '8881'}`;
  const browser = await chromium.launch();
  const context = await browser.newContext({ baseURL });
  const page = await context.newPage();
  await page.goto('/wp-login.php');
  await page.fill('#user_login', 'admin');
  await page.fill('#user_pass', 'password');
  await Promise.all([
    page.waitForURL('**/wp-admin/**', { timeout: 30_000 }),
    page.click('#wp-submit'),
  ]);
  await context.storageState({ path: path.join(__dirname, 'auth.json') });
  await browser.close();
}
