import { defineConfig, devices } from '@playwright/test';

export default defineConfig({
  testDir: './tests',
  timeout: 60000,
  projects: [
    {
      name: 'chromium',
      use: { ...devices['Pixel 5'], channel: undefined, browserName: 'chromium' },
    },
  ],
});
