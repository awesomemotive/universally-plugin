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
    baseURL: `http://localhost:${PORT}`,
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
