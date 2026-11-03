import { expect, test } from '@playwright/test';

import { mockPanel } from './fixtures';

/**
 * Deleting a device now asks *why*.
 *
 * The trash keeps the row, but "deleted" alone cannot answer the question an
 * auditor or a colleague asks next, so the reason and (for a duplicate) the asset
 * that was kept have to reach the API — and the API rejects a reason that does not
 * accompany a matching `duplicate_of`.
 */
test.describe('delete with a reason', () => {
  test('refuses to delete without a reason and says what is missing', async ({ page }) => {
    const deletes: string[] = [];
    await mockPanel(page, { onDelete: (url) => deletes.push(url) });

    await page.goto('/app/devices/7');
    await page.getByRole('button', { name: 'Delete' }).click();

    const panel = page.getByTestId('delete-reason');
    await expect(panel).toBeVisible();
    await panel.getByRole('button', { name: 'Delete' }).click();

    await expect(panel.getByRole('alert')).toHaveText('Choose a reason before deleting.');
    expect(deletes).toHaveLength(0);
  });

  test('sends the reason and the device that was kept', async ({ page }) => {
    const deletes: string[] = [];
    await mockPanel(page, { onDelete: (url) => deletes.push(url) });

    await page.goto('/app/devices/7');
    await page.getByRole('button', { name: 'Delete' }).click();

    const panel = page.getByTestId('delete-reason');
    await panel.getByRole('combobox').selectOption('duplicated');

    // The picker searches `/devices?q=` and drops the device being deleted, so the
    // only candidate is the row the fixture list returns besides it.
    await panel.getByRole('searchbox').fill('SN-0008');
    await panel.getByRole('button', { name: /SN-0008/ }).click();

    await panel.getByRole('button', { name: 'Delete' }).click();

    await expect.poll(() => deletes.length).toBe(1);
    const url = new URL(deletes[0] as string);
    expect(url.pathname).toContain('/panel-api/v1/devices/7');
    expect(url.searchParams.get('reason')).toBe('duplicated');
    expect(url.searchParams.get('duplicate_of')).toBe('8');
  });

  test('shows a stored reason and links the kept device in the trash', async ({ page }) => {
    await mockPanel(page, {
      detail: {
        deleted_at: '2026-11-01T10:00:00Z',
        deleted_reason: 'duplicated',
        duplicate_of_id: 8,
        duplicate_of: { id: 8, sn: 'SN-0008', hostname: 'acme-desktop-08', deleted_at: null },
      },
    });

    await page.goto('/app/devices/7');

    const trash = page.getByRole('alert').first();
    await expect(trash).toContainText('Deleted because: Duplicated');
    await expect(trash.getByRole('link', { name: /SN-0008/ })).toHaveAttribute('href', /\/app\/devices\/8$/);
  });

  test('sends the bulk reason only when one is chosen', async ({ page }) => {
    const bodies: Record<string, unknown>[] = [];
    await mockPanel(page, { onBulk: (body) => bodies.push(body) });

    await page.goto('/app/devices');
    await page.getByRole('checkbox', { name: 'Select SN-0007' }).click();

    const toolbar = page.getByTestId('bulk-actions');
    await toolbar.getByTestId('bulk-delete-reason').selectOption('created_by_mistake');
    await toolbar.getByRole('button', { name: 'Delete' }).click();

    await expect.poll(() => bodies.length).toBe(1);
    expect(bodies[0]).toMatchObject({ op: 'delete', ids: [7], reason: 'created_by_mistake' });
  });
});
