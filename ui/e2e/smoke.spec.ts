import { expect, test } from '@playwright/test';

import { mockAnonymous, mockPanel, SESSION } from './fixtures';

/**
 * Scaffold smoke test — the full suite (grid, dark mode, RTL, CSV) lands in
 * phase 8. This one guards the three things that broke in 2279453 and are
 * cheapest to check on every run: the viewport meta, a single theme mechanism
 * and a shell that renders without console errors.
 */
test.describe('SPA shell', () => {
  test('boots with a viewport meta tag and a theme attribute', async ({ page }) => {
    // Every request is stubbed: CI has no PHP backend behind the preview proxy,
    // so anything left unstubbed would answer 502 and land in `failures`.
    await mockAnonymous(page);

    const failures: string[] = [];
    page.on('console', (message) => {
      // The 401 for the anonymous /session probe is the expected answer here;
      // Chromium reports every failed response as a console error.
      if (message.type() === 'error' && !message.text().includes('401')) {
        failures.push(message.text());
      }
    });
    page.on('pageerror', (error) => failures.push(error.message));

    await page.goto('/app/');

    await expect(page.locator('meta[name="viewport"]')).toHaveCount(1);
    await expect(page.locator('html')).toHaveAttribute('data-theme', /light|dark/);
    expect(failures).toEqual([]);
  });

  test('shows the sign-in card when the panel API answers 401', async ({ page }) => {
    await mockAnonymous(page);

    await page.goto('/app/');
    await expect(page.getByRole('link', { name: 'Sign in with SSO' })).toBeVisible();
  });

  /**
   * Phase 9 rollback: `ui.spa_enabled = 0` must never leave a half-working shell
   * on screen. The notice links at the legacy pages, which are still served.
   */
  test('renders the rollback notice when ui.spa_enabled is off', async ({ page }) => {
    await mockPanel(page, {
      session: { ...SESSION, features: { ...SESSION.features, spa: false } },
    });

    await page.goto('/app/settings');

    const notice = page.getByRole('alert');
    await expect(notice).toContainText('The React panel is turned off');
    await expect(notice.getByRole('link', { name: 'Open the classic panel' })).toHaveAttribute(
      'href',
      '/main.php',
    );
    // The router must not have mounted any screen behind the notice.
    await expect(page.getByRole('navigation')).toHaveCount(0);
  });
});
