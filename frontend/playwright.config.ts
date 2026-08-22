import { defineConfig, devices } from '@playwright/test'

/**
 * End-to-end tests run against the REAL compose stack — the same image production uses.
 *
 * An e2e suite pointed at a mocked backend tests the mock. These exist to catch what unit and
 * functional tests structurally cannot: cookie behaviour in a real browser, the SPA and API
 * sharing an origin, and a permission change taking effect without a re-login.
 */
export default defineConfig({
  testDir: './e2e',
  fullyParallel: false, // Shared database; parallel workers would race on fixture state.
  forbidOnly: !!process.env.CI,
  retries: process.env.CI ? 1 : 0,
  workers: 1,
  reporter: process.env.CI ? [['html', { open: 'never' }], ['list']] : 'list',

  use: {
    baseURL: process.env.E2E_BASE_URL ?? 'http://localhost:8080',
    // Traces on first retry only: always-on costs time, never-on makes a CI-only failure
    // undiagnosable.
    trace: 'on-first-retry',
    screenshot: 'only-on-failure',
  },

  projects: [{ name: 'chromium', use: { ...devices['Desktop Chrome'] } }],
})
