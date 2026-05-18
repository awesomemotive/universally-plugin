import { defineConfig, devices } from '@playwright/test';

const PORT = process.env.WP_PORT ?? '12345';

export default defineConfig({
  testDir: '.',
  fullyParallel: false,
  workers: 1,
  reporter: [['list']],
  outputDir: 'results',
  globalSetup: require.resolve('./global-setup'),
  use: {
    // Use 127.0.0.1 to match WP's siteurl in Playground. Loading via "localhost"
    // produces CORS errors on the front-end because WP serves absolute asset URLs
    // hardcoded to 127.0.0.1, and the browser treats those as a different origin.
    baseURL: `http://127.0.0.1:${PORT}`,
    storageState: 'auth.json',
    viewport: { width: 1440, height: 900 },
    locale: 'en-US',
    timezoneId: 'UTC',
    screenshot: 'off',
    video: 'off',
    trace: 'off',
  },
  projects: [
    {
      name: 'chromium',
      use: { ...devices['Desktop Chrome'] },
    },
  ],
});
