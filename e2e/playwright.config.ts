import { defineConfig, devices } from '@playwright/test';

// The app runs in its own container (docker-compose.e2e.yml), so we do NOT use
// Playwright's `webServer`. BASE_URL points at it (http://app:8000 in docker,
// http://localhost:8000 when running against a host instance).
export default defineConfig({
  testDir: './tests',
  // Sequential: tests share one app/DB and drive Stripe one checkout at a time.
  fullyParallel: false,
  workers: 1,
  // Driving Stripe's hosted page is inherently a bit flaky — retry once locally.
  retries: process.env.CI ? 2 : 1,
  timeout: 120_000,
  expect: { timeout: 15_000 },
  reporter: [['list'], ['html', { open: 'never' }]],
  use: {
    baseURL: process.env.BASE_URL || 'http://localhost:8000',
    trace: 'on-first-retry',
    video: 'on-first-retry',
    screenshot: 'only-on-failure',
  },
  projects: [
    { name: 'chromium', use: { ...devices['Desktop Chrome'] } },
  ],
});
