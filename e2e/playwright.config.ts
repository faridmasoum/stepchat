import { defineConfig, devices } from '@playwright/test';

export default defineConfig({
  testDir: './tests',
  fullyParallel: false,
  workers: 1,
  retries: 0,
  timeout: 30_000,
  reporter: process.env.CI ? [['list'], ['html', { open: 'never' }]] : 'list',
  use: { baseURL: 'http://127.0.0.1:8123', trace: 'retain-on-failure' },
  projects: [{ name: 'chromium', use: { ...devices['Desktop Chrome'] } }],
  webServer: {
    command: 'php -S 127.0.0.1:8123',
    cwd: '..',
    url: 'http://127.0.0.1:8123/tests/Fixtures/e2e/index.html',
    reuseExistingServer: !process.env.CI,
    timeout: 15_000,
  },
});