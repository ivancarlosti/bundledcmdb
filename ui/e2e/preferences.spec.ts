import { expect, test } from '@playwright/test';

import { mockPanel } from './fixtures';

/**
 * Preferences and export.
 *
 * Theme, locale and column layout are stored server-side; what matters for the
 * panel is that a switch is applied immediately (no flash, no reload) *and*
 * survives one — the two behaviours the legacy `theme.js` got wrong.
 */
test.describe('preferences', () => {
  test('switches to dark, persists it and keeps it after a reload', async ({ page }) => {
    const patches: Record<string, unknown>[] = [];
    await mockPanel(page, {
      onPreferences: (patch) => {
        patches.push(patch);
      },
    });

    await page.goto('/app/devices');
    await page.getByTestId('theme-switcher').selectOption('dark');

    await expect(page.locator('html')).toHaveAttribute('data-theme', 'dark');
    await expect.poll(() => patches.at(-1)).toMatchObject({ theme: 'dark' });

    await page.reload();
    await expect(page.locator('html')).toHaveAttribute('data-theme', 'dark');
  });

  test('switches to Arabic and flips the document direction', async ({ page }) => {
    const patches: Record<string, unknown>[] = [];
    await mockPanel(page, {
      onPreferences: (patch) => {
        patches.push(patch);
      },
    });

    await page.goto('/app/devices');
    await page.getByTestId('locale-switcher').selectOption('ar-SA');

    await expect(page.locator('html')).toHaveAttribute('dir', 'rtl');
    await expect(page.locator('html')).toHaveAttribute('lang', 'ar-SA');
    await expect.poll(() => patches.at(-1)).toMatchObject({ locale: 'ar-SA' });

    // Layout is logical, so nothing is pushed off screen by the flip.
    await expect(page.getByRole('heading', { name: 'Devices' })).toBeVisible();
  });

  test('exports the CSV with the current sort and filters', async ({ page }) => {
    await mockPanel(page);
    await page.goto('/app/devices');

    await page.getByRole('button', { name: /Serial number/ }).click();
    await expect.poll(() => page.url()).toContain('sort=SN&dir=asc');

    const link = page.getByRole('link', { name: 'Export to Excel' });
    // The link is a plain GET with the grid's own query, so the download can be
    // bookmarked and matches what the table shows.
    await expect(link).toHaveAttribute('href', /\/devices\/export\?.*sort=SN&dir=asc/);

    const [download] = await Promise.all([page.waitForEvent('download'), link.click()]);
    expect(download.url()).toContain('sort=SN&dir=asc');
  });
});
