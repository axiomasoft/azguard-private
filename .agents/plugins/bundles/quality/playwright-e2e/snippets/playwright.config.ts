// Source: anonymized production project
// playwright.config.ts — config E2E: webServer raises the application, projects-browsers,
// separate project "setup" for one-time login via storageState, data isolation via webServer.env.
import { defineConfig, devices } from '@playwright/test';

const BASE_URL = process.env.E2E_BASE_URL ?? 'http://127.0.0.1:8000';
const STORAGE_STATE = 'playwright/.auth/user.json';

export default defineConfig({
  testDir: './e2e',
  // B CI catching accidentally committed test.only and give retrays to the flakes.
  forbidOnly: !!process.env.CI,
  retries: process.env.CI ? 2 : 0,
  // One worker per CI, if E2E share a common database; locally - in parallel.
  workers: process.env.CI ? 1 : undefined,
  reporter: process.env.CI ? [['github'], ['html', { open: 'never' }]] : 'html',

  use: {
    baseURL: BASE_URL,
    // We hook anchor actions to data-testid, and not for classes/text.
    testIdAttribute: 'data-testid',
    // Artifacts only when dropped - diagnostics without bloat.
    trace: 'on-first-retry',
    screenshot: 'only-on-failure',
    video: 'retain-on-failure',
  },

  projects: [
    // 1) One-time login: goes through the form and saves the session in storageState.
    { name: 'setup', testMatch: /.*\.setup\.ts/ },

    // 2) Scripts for a logged-in user - start already with a session.
    {
      name: 'chromium',
      use: { ...devices['Desktop Chrome'], storageState: STORAGE_STATE },
      dependencies: ['setup'],
      // Flow without authorization (registration, public pages) are kept separately.
      testIgnore: /.*\.public\.spec\.ts/,
    },
    {
      name: 'chromium-public',
      use: { ...devices['Desktop Chrome'] }, // without storageState
      testMatch: /.*\.public\.spec\.ts/,
    },
    // Add. Enable browsers as needed:
    // { name: 'firefox', use: { ...devices['Desktop Firefox'], storageState: STORAGE_STATE }, dependencies: ['setup'] },
  ],

  // Raise the application before running; locally we reuse an already running server.
  webServer: {
    command: 'npm run serve:e2e',
    url: BASE_URL,
    reuseExistingServer: !process.env.CI,
    timeout: 120_000,
    // Isolation: the application writes to the test database and test media disk, not to the combat ones.
    env: {
      APP_ENV: 'testing',
      DB_DATABASE: process.env.E2E_DB_DATABASE ?? 'app_e2e',
      MEDIA_DISK: 'media-test',
    },
  },
});
