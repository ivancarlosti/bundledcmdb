import { expect, test } from '@playwright/test';

/**
 * Scaffold smoke test — the full suite (grid, dark mode, RTL, CSV) lands in
 * phase 8. This one guards the three things that broke in 2279453 and are
 * cheapest to check on every run: the viewport meta, a single theme mechanism
 * and a shell that renders without console errors.
 */
test.describe('SPA shell', () => {
  test('boots with a viewport meta tag and a theme attribute', async ({ page }) => {
    const failures: string[] = [];
    page.on('console', (message) => {
      if (message.type() === 'error') {
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
    await page.route('**/panel-api/v1/session', (route) =>
      route.fulfill({
        status: 401,
        contentType: 'application/json',
        body: JSON.stringify({ ok: false, error: { code: 'unauthorized', message: 'Authentication required.' } }),
      }),
    );

    await page.goto('/app/');
    await expect(page.getByRole('link', { name: 'Sign in' })).toBeVisible();
  });
});
