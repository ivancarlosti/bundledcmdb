import { expect, test } from '@playwright/test';

import { mockPanel, ROWS } from './fixtures';

/**
 * Inline editing from the grid.
 *
 * The grid sends the row's `updated_at` as `expected_updated_at`, so a
 * concurrent edit becomes a visible `409` instead of a lost update — the value
 * must never silently snap back to the old one.
 */
test.describe('inline edit', () => {
  test('reports a stale record when somebody else saved first', async ({ page }) => {
    await mockPanel(page);

    // Registered after the fixtures' routes, so it wins for /devices/7.
    await page.route('**/panel-api/v1/devices/7', (route) => {
      if (route.request().method() === 'PATCH') {
        return route.fulfill({
          status: 409,
          json: {
            ok: false,
            error: {
              code: 'stale_record',
              message: 'This device was modified by someone else.',
              errors: { expected_updated_at: 'stale' },
            },
          },
        });
      }
      return route.fulfill({ json: { ok: true, data: { ...ROWS[0], files: [] }, meta: {} } });
    });

    await page.goto('/app/devices');
    const status = page.getByRole('combobox', { name: 'Status' }).first();
    await expect(status).toHaveValue('In Use');

    await status.selectOption('In Stock');

    await expect(
      page.getByText('This device was changed by someone else. Reload before saving.'),
    ).toBeVisible();
  });

  test('patches the row with expected_updated_at', async ({ page }) => {
    let patched: Record<string, unknown> | undefined;
    await mockPanel(page);

    await page.route('**/panel-api/v1/devices/7', (route) => {
      if (route.request().method() === 'PATCH') {
        patched = route.request().postDataJSON() as Record<string, unknown>;
        return route.fulfill({
          json: { ok: true, data: { ...ROWS[0], status: 'In Stock', files: [] }, meta: {} },
        });
      }
      return route.fulfill({ json: { ok: true, data: { ...ROWS[0], files: [] }, meta: {} } });
    });

    await page.goto('/app/devices');
    await page.getByRole('combobox', { name: 'Status' }).first().selectOption('In Stock');

    await expect.poll(() => patched).toMatchObject({
      status: 'In Stock',
      expected_updated_at: ROWS[0].updated_at,
    });
  });
});
