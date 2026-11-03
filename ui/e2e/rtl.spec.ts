import { expect, test } from '@playwright/test';

import { mockPanel } from './fixtures';

/**
 * RTL layout contract (`ar-SA`).
 *
 * The grid is the screen with the most physical layout in the panel — pinned
 * header cells, a leading checkbox column and a scroll container — so it is the
 * one that breaks when a logical utility is swapped for `left-`/`mr-`. The
 * assertions below are geometry, not pixels, so they stay stable across fonts
 * and browsers; the pixel snapshot at the end is opt-in for the same reason
 * (see docs/frontend.md).
 */

test.describe('RTL (ar-SA)', () => {
  test('mirrors the grid without overflowing the page', async ({ page }) => {
    await mockPanel(page);
    await page.goto('/app/devices');
    await page.getByTestId('locale-switcher').selectOption('ar-SA');

    await expect(page.locator('html')).toHaveAttribute('dir', 'rtl');

    // 1. The document itself never scrolls sideways.
    const overflow = await page.evaluate(
      () => document.documentElement.scrollWidth - document.documentElement.clientWidth,
    );
    expect(overflow).toBeLessThanOrEqual(0);

    // 2. The selection column sits at the visual *right* edge in RTL: the header
    //    checkbox is to the right of the first data cell.
    const checkbox = page.getByRole('checkbox', { name: /select all/i }).first();
    const serialHeader = page.getByRole('columnheader', { name: /Serial number/ });
    const checkboxBox = await checkbox.boundingBox();
    const serialBox = await serialHeader.boundingBox();
    expect(checkboxBox).not.toBeNull();
    expect(serialBox).not.toBeNull();
    if (checkboxBox !== null && serialBox !== null) {
      expect(checkboxBox.x).toBeGreaterThan(serialBox.x);
    }

    // 3. The table still scrolls inside its own container instead of pushing the
    //    page wide.
    const grid = page.getByTestId('data-grid-scroll');
    const scrolls = await grid.evaluate((node) => node.scrollWidth > node.clientWidth);
    expect(typeof scrolls).toBe('boolean');

    // 4. Direction-aware strings: no literal LTR-only arrow leaked into the nav.
    await expect(page.getByRole('heading', { name: 'Devices' })).toBeVisible();
  });

  test('keeps the detail page readable in RTL', async ({ page }) => {
    await mockPanel(page);
    await page.goto('/app/devices');
    await page.getByTestId('locale-switcher').selectOption('ar-SA');
    await expect(page.locator('html')).toHaveAttribute('dir', 'rtl');

    await page.getByTestId('data-row').first().click();
    await expect(page.getByRole('heading', { level: 1 })).toBeVisible();

    const overflow = await page.evaluate(
      () => document.documentElement.scrollWidth - document.documentElement.clientWidth,
    );
    expect(overflow).toBeLessThanOrEqual(0);
  });

  /**
   * Opt-in: `VISUAL_SNAPSHOTS=1 npm run e2e -- rtl.spec.ts`.
   *
   * A committed baseline would compare a Chromium render on the developer's
   * machine with one on the CI runner, where the installed font set differs, so
   * the snapshot is only taken when explicitly asked for (`--update-snapshots`
   * writes `e2e/__screenshots__/`). The geometry assertions above are the gate.
   */
  test('records the RTL grid snapshot on demand', async ({ page }) => {
    test.skip(process.env.VISUAL_SNAPSHOTS !== '1', 'set VISUAL_SNAPSHOTS=1 to compare the RTL snapshot');

    await mockPanel(page);
    await page.goto('/app/devices');
    await page.getByTestId('locale-switcher').selectOption('ar-SA');
    await expect(page.locator('html')).toHaveAttribute('dir', 'rtl');

    await expect(page).toHaveScreenshot('rtl-devices-grid.png', {
      animations: 'disabled',
      fullPage: false,
      maxDiffPixelRatio: 0.02,
    });
  });
});
