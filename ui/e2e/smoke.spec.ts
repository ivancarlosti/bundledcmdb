import { expect, test } from '@playwright/test';

import { mockAnonymous } from './fixtures';

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
   * `public/index.php` (the legacy `/index.php?code=` SSO callback) reports a
   * failure by redirecting to `/app/?auth_error=<code>`; the login screen owns
   * the message, so a broken sign-in never lands on a PHP page.
   */
  test('renders the sign-in error the legacy SSO callback passes in', async ({ page }) => {
    await mockAnonymous(page);

    await page.goto('/app/?auth_error=not_authorized');

    const alert = page.getByRole('alert');
    await expect(alert).toContainText('Sign-in failed');
    await expect(alert).toContainText('not on the authorised list');
    // The SSO button stays reachable so the user can try again.
    await expect(page.getByRole('link', { name: 'Sign in with SSO' })).toBeVisible();
  });
});
