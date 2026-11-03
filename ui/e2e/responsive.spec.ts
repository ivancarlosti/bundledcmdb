/// <reference lib="dom" />
// `page.evaluate` callbacks run in the browser, which the node-flavoured
// tsconfig for `e2e/` does not include; the reference scopes DOM types here.
import { expect, test } from '@playwright/test';

import { mockPanel } from './fixtures';

/**
 * 360 px audit (phase 5 acceptance: "the grid is usable on a 360 px viewport").
 *
 * The rule is that the *page* never scrolls sideways — the toolbar wraps and the
 * table scrolls inside its own container — because a page-level horizontal
 * scrollbar is what made the legacy grid unusable on a phone.
 */
test.use({ viewport: { width: 360, height: 740 } });

async function pageOverflow(page: import('@playwright/test').Page): Promise<number> {
  return page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
}

test.describe('360 px viewport', () => {
  test('the grid fits without a page-level horizontal scrollbar', async ({ page }) => {
    await mockPanel(page);
    await page.goto('/app/devices');
    await expect(page.getByTestId('data-row')).toHaveCount(2);

    expect(await pageOverflow(page)).toBeLessThanOrEqual(1);

    // The primary action and the filters are still on screen and usable.
    await expect(page.getByRole('link', { name: 'New device' })).toBeVisible();
    await expect(page.getByRole('searchbox')).toBeVisible();
    await expect(page.getByRole('button', { name: 'Next' })).toBeVisible();

    // Wide tables scroll inside their own container instead of the page.
    const overflowX = await page.evaluate(() => {
      const table = document.querySelector('table');
      const box = table?.parentElement;
      return box === null || box === undefined ? 'missing' : getComputedStyle(box).overflowX;
    });
    expect(overflowX).toBe('auto');
  });

  test('the detail page fits without a page-level horizontal scrollbar', async ({ page }) => {
    await mockPanel(page);
    await page.goto('/app/devices/7');
    await expect(page.getByRole('heading', { name: 'SN-0007' })).toBeVisible();

    expect(await pageOverflow(page)).toBeLessThanOrEqual(1);
    await expect(page.getByRole('link', { name: 'Back to list' }).first()).toBeVisible();
  });
});
