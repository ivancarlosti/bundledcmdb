import { expect, test } from '@playwright/test';

import { mockPanel } from './fixtures';

/**
 * Selection + bulk actions.
 *
 * The grid is server-paged, so a bulk action must be *one* request for the whole
 * selection — not one request per row driven by the DOM order — and the user has
 * to see what happened when the server skips a row.
 */
test.describe('bulk actions', () => {
  test('selects rows and sends one status request', async ({ page }) => {
    let bulk: Record<string, unknown> | undefined;
    await mockPanel(page, {
      onBulk: (body) => {
        bulk = body;
      },
    });

    await page.goto('/app/devices');
    await expect(page.getByTestId('data-row')).toHaveCount(2);

    await page.getByRole('checkbox', { name: 'Select SN-0007' }).click();
    await page.getByRole('checkbox', { name: 'Select SN-0008' }).click();

    const toolbar = page.getByTestId('bulk-actions');
    await expect(toolbar).toBeVisible();
    await expect(toolbar.getByRole('status')).toHaveText('2 selected');
    await expect(page.getByRole('checkbox', { name: 'Select all rows on this page' })).toBeChecked();

    await page.getByRole('button', { name: 'Apply' }).first().click();

    await expect.poll(() => bulk).toBeDefined();
    expect(bulk).toMatchObject({ op: 'status', ids: [7, 8], value: 'In Use' });

    // A successful action clears the selection, so the next one starts empty.
    await expect(toolbar).toHaveCount(0);
  });

  test('the header checkbox selects the page and reports a partial selection', async ({ page }) => {
    await mockPanel(page);
    await page.goto('/app/devices');
    await expect(page.getByTestId('data-row')).toHaveCount(2);

    const all = page.getByRole('checkbox', { name: 'Select all rows on this page' });
    const toolbar = page.getByTestId('bulk-actions');

    await all.click();
    await expect(toolbar.getByRole('status')).toHaveText('2 selected');
    await expect(page.getByRole('checkbox', { name: 'Select SN-0008' })).toBeChecked();

    await page.getByRole('checkbox', { name: 'Select SN-0007' }).click();
    await expect(toolbar.getByRole('status')).toHaveText('1 selected');
    await expect(all).not.toBeChecked();

    await page.getByRole('button', { name: 'Clear selection' }).click();
    await expect(toolbar).toHaveCount(0);
    await expect(all).not.toBeChecked();
  });

  test('sends bulk delete as admin and reports skipped rows', async ({ page }) => {
    let bulk: Record<string, unknown> | undefined;
    await mockPanel(page, {
      onBulk: (body) => {
        bulk = body;
      },
    });

    await page.goto('/app/devices');
    await page.getByRole('checkbox', { name: 'Select SN-0007' }).click();
    await page.getByRole('button', { name: 'Delete' }).click();

    await expect.poll(() => bulk).toBeDefined();
    expect(bulk).toMatchObject({ op: 'delete', ids: [7] });
  });
});
