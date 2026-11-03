import { defineConfig, devices } from '@playwright/test';

/**
 * End-to-end suite. Runs against `vite preview` (the real production bundle) on
 * the documented port; the panel API is expected to be reachable through the
 * same origin (start the dev stack first, see docs/frontend.md).
 *
 * Install browsers once with: npx playwright install --with-deps chromium
 */
export default defineConfig({
  testDir: './e2e',
  timeout: 30_000,
  expect: { timeout: 5_000 },
  fullyParallel: true,
  retries: process.env.CI ? 1 : 0,
  // `github` turns a failure into an annotation on the commit/PR, which is the
  // only way to read it without an authenticated job log.
  reporter: process.env.CI ? [['github'], ['html', { open: 'never' }]] : 'list',
  use: {
    baseURL: 'http://localhost:4173',
    trace: 'on-first-retry',
  },
  projects: [{ name: 'chromium', use: { ...devices['Desktop Chrome'] } }],
  webServer: {
    command: 'npm run preview -- --port 4173 --strictPort',
    url: 'http://localhost:4173/app/',
    reuseExistingServer: !process.env.CI,
    timeout: 60_000,
    // Vite's own output is discarded by default, which hides a proxy error or a
    // port clash behind "webServer exited early".
    stdout: 'pipe',
    stderr: 'pipe',
  },
});
