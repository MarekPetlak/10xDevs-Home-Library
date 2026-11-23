// @ts-check
import { defineConfig, devices } from '@playwright/test';

/**
 * Local-only visual gate for the UI (change: ui-books-list). Not run in CI.
 *
 * The app runs in the "test" environment against its own database (TEST_TOKEN=e2e gives
 * `app_teste2e`), so loading fixtures can neither touch the development database (`app`)
 * nor leave data behind for PHPUnit (`app_test`). See e2e/support/fixtures.js.
 */
// 8081 was set by the existing visual suite; E2E_PORT overrides it when the port is taken on this machine.
const PORT = Number(process.env.E2E_PORT ?? 8081);

export default defineConfig({
  testDir: './e2e',
  // One shared PHP built-in server and one database: tests that load fixtures must not run in parallel.
  fullyParallel: false,
  workers: 1,
  forbidOnly: !!process.env.CI,
  retries: 0,
  reporter: [['list'], ['html', { open: 'never' }]],
  snapshotPathTemplate: '{testDir}/__screenshots__/{projectName}/{testFilePath}/{arg}{ext}',
  expect: {
    toHaveScreenshot: { maxDiffPixels: 100 },
  },
  use: {
    baseURL: `http://127.0.0.1:${PORT}`,
    locale: 'pl-PL',
    trace: 'on-first-retry',
    screenshot: 'only-on-failure',
  },

  projects: [
    // Behavioural E2E (tests/e2e): sign in once as the fixture reader, then every spec loads the saved session.
    { name: 'setup', testDir: './tests/e2e', testMatch: /.*\.setup\.js/ },
    {
      name: 'chromium',
      testDir: './tests/e2e',
      testIgnore: /.*\.setup\.js/,
      use: { ...devices['Desktop Chrome'], storageState: 'playwright/.auth/user.json' },
      dependencies: ['setup'],
    },
    // Visual suite (e2e/): tests sign in themselves and load their own fixtures; no shared session.
    // Both suites share one database and the visual fixtures purge it, so the visual projects run after 'chromium'.
    { name: 'desktop', use: { ...devices['Desktop Chrome'], viewport: { width: 1280, height: 900 } }, dependencies: ['chromium'] },
    // One mobile width (390 px), same engine, so a baseline difference is a layout difference.
    { name: 'mobile', use: { ...devices['Desktop Chrome'], viewport: { width: 390, height: 844 } }, dependencies: ['chromium'] },
  ],

  webServer: {
    command: `php -d variables_order=EGPCS -S 127.0.0.1:${PORT} -t public public/index.php`,
    url: `http://127.0.0.1:${PORT}/login`,
    reuseExistingServer: false,
    timeout: 60_000,
    env: { APP_ENV: 'test', TEST_TOKEN: 'e2e', APP_DEBUG: '1' },
  },
});
