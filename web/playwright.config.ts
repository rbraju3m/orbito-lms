import { defineConfig, devices } from '@playwright/test';

/**
 * End-to-end tests run against a real built SPA and a real API.
 * Phase 2 covers the shell; the critical-flow suite listed in
 * docs/FRONTEND_ARCHITECTURE.md §10 grows with each phase.
 */
export default defineConfig({
  testDir: './e2e',
  fullyParallel: true,
  forbidOnly: !!process.env['CI'],
  retries: process.env['CI'] ? 2 : 0,
  workers: process.env['CI'] ? 1 : undefined,
  reporter: process.env['CI'] ? [['github'], ['html', { open: 'never' }]] : [['list']],

  use: {
    baseURL: process.env['E2E_BASE_URL'] ?? 'http://127.0.0.1:4173',
    trace: 'on-first-retry',
    screenshot: 'only-on-failure',
  },

  projects: [
    { name: 'chromium', use: { ...devices['Desktop Chrome'] } },
    // The player and the builder must work on a phone; a desktop-only e2e
    // suite would let mobile regressions ship.
    { name: 'mobile', use: { ...devices['Pixel 7'] } },
  ],

  webServer: {
    command: 'npm run preview -- --port 4173 --strictPort',
    url: 'http://127.0.0.1:4173',
    reuseExistingServer: !process.env['CI'],
    timeout: 60_000,
  },
});
