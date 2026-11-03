import { expect, test } from '@playwright/test';

import { mockPanel } from './fixtures';

const SUPERADMIN = {
  role: 'superadmin',
  rank: 4,
  can_write: true,
  can_admin: true,
  can_superadmin: true,
};

/**
 * Audit log screen.
 *
 * Two things are worth pinning: the row renders the change the API returns
 * (`field: old → new`, not a JSON blob) and the purge card exists only for a
 * superadmin — deleting history is instance-wide, so an admin must not see a
 * button that would answer 403.
 */
test.describe('audit log', () => {
  test('lists the events with actor, entity and the changed field', async ({ page }) => {
    await mockPanel(page);

    await page.goto('/app/logs');

    await expect(page.getByRole('heading', { name: 'Audit log' })).toBeVisible();
    await expect(page.getByText('admin@example.com')).toBeVisible();
    await expect(page.getByText('Status: In Use → In Stock')).toBeVisible();
    await expect(page.getByText('SN-0007')).toBeVisible();
    await expect(page.getByText(/Keeping 180 days of history/)).toBeVisible();
    await expect(page.getByText(/The log has never been trimmed/)).toBeVisible();
  });

  test('is reachable from the shell for an admin', async ({ page }) => {
    await mockPanel(page);

    await page.goto('/app/devices');
    await page.getByRole('link', { name: 'Audit log' }).click();

    await expect(page.getByRole('heading', { name: 'Audit log' })).toBeVisible();
  });

  test('filters by action and keeps the selected value', async ({ page }) => {
    await mockPanel(page);

    await page.goto('/app/logs');
    await page.locator('#logs-action').selectOption('update');

    await expect(page.locator('#logs-action')).toHaveValue('update');
    await expect(page.getByText('Status: In Use → In Stock')).toBeVisible();
  });

  test('offers the purge card to a superadmin, after a numbered confirmation', async ({ page }) => {
    await mockPanel(page, { session: { capabilities: SUPERADMIN } });
    const dialogs: string[] = [];
    page.on('dialog', (dialog) => {
      dialogs.push(dialog.message());
      void dialog.accept();
    });

    await page.goto('/app/logs');
    await expect(page.getByRole('heading', { name: 'Purge history' })).toBeVisible();

    await page.locator('#logs-purge-before').fill('2026-01-01');
    await page.getByRole('button', { name: 'Delete older events' }).click();

    await expect.poll(() => dialogs.length).toBeGreaterThan(0);
    expect(dialogs[0]).toContain('2 event(s) older than 2026-01-01');
    await expect(page.getByText('2 event(s) deleted.').first()).toBeVisible();
  });

  test('hides the purge card from a plain admin', async ({ page }) => {
    await mockPanel(page);

    await page.goto('/app/logs');

    await expect(page.getByRole('heading', { name: 'Audit log' })).toBeVisible();
    await expect(page.getByRole('heading', { name: 'Purge history' })).toHaveCount(0);
  });
});
