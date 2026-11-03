import { expect, test } from '@playwright/test';

import { mockPanel, mockSettings } from './fixtures';

/**
 * Settings screens (phase 7).
 *
 * The three behaviours that only exist in the SPA and are easy to regress:
 *
 *  - a save must not resend an untouched secret (the API sends `""` for one);
 *  - a `422` with per-key `errors` is rendered next to the input, not as one
 *    opaque banner;
 *  - the tab list follows `capabilities` (`can_admin` opens the screen, the user
 *    roster stays `can_superadmin`).
 */
test.describe('settings', () => {
  test('saves the storage tab without resending the stored secret', async ({ page }) => {
    const saved: Record<string, unknown>[] = [];
    await mockPanel(page);
    await mockSettings(page, { onSave: (body) => saved.push(body) });

    await page.goto('/app/settings?tab=storage');

    const bucket = page.getByLabel('Bucket');
    await expect(bucket).toHaveValue('cmdb');

    // The secret is stored (`configured: true`) and the input starts blank.
    const secret = page.getByLabel('Secret access key');
    await expect(secret).toHaveValue('');

    await bucket.fill('cmdb-eu');
    await page.getByRole('button', { name: 'Save Changes' }).click();

    await expect.poll(() => saved.length).toBe(1);
    expect(saved[0]?.values).toMatchObject({ 's3.bucket': 'cmdb-eu' });
    // Blank means unchanged: the key is absent, not present-and-empty.
    expect((saved[0]?.values as Record<string, unknown>)['s3.secret_key']).toBeUndefined();
    expect(saved[0]?.clear).toBeUndefined();
  });

  test('renders per-key validation errors from a 422', async ({ page }) => {
    await mockPanel(page);
    await mockSettings(page, { invalid: { 's3.bucket': 'required' } });

    await page.goto('/app/settings?tab=storage');
    await page.getByLabel('Bucket').fill('');
    await page.getByRole('button', { name: 'Save Changes' }).click();

    // The catalogue stub maps `required` to "Required.".
    await expect(page.getByText('Required.')).toBeVisible();
  });

  test('shows the settings link to an admin and hides the user roster', async ({ page }) => {
    await mockPanel(page);
    await mockSettings(page);

    await page.goto('/app/devices');
    await expect(page.getByRole('link', { name: 'Settings' })).toBeVisible();

    await page.getByRole('link', { name: 'Settings' }).click();
    await expect(page.getByRole('heading', { name: 'Settings' })).toBeVisible();

    const tabs = page.getByRole('tab');
    await expect(tabs).toHaveCount(6);
    await expect(page.getByRole('tab', { name: 'Users' })).toHaveCount(0);

    // Source usage is shown, and a system source cannot be deleted.
    await page.getByRole('tab', { name: 'Sources' }).click();
    await expect(page.getByRole('cell', { name: /Manual/ })).toBeVisible();
    await expect(page.getByRole('button', { name: 'Delete' }).first()).toBeDisabled();
  });

  test('opens the user roster for a superadmin and disables the own row', async ({ page }) => {
    await mockPanel(page, {
      session: {
        user: { email: 'qa@example.com', company: '', role: 'superadmin' },
        capabilities: { role: 'superadmin', rank: 4, can_write: true, can_admin: true, can_superadmin: true },
      },
    });
    await mockSettings(page);

    await page.goto('/app/settings?tab=users');

    await expect(page.getByRole('tab', { name: 'Users' })).toHaveAttribute('aria-selected', 'true');
    const own = page.getByRole('row', { name: /qa@example.com/ }).getByRole('combobox');
    await expect(own).toBeDisabled();

    const other = page.getByRole('row', { name: /ops@example.com/ }).getByRole('combobox');
    await expect(other).toBeEnabled();
    await expect(other).toHaveValue('manager');
  });

  test('keeps the caller out of the screen without can_admin', async ({ page }) => {
    await mockPanel(page, {
      session: {
        user: { email: 'qa@example.com', company: '', role: 'manager' },
        capabilities: { role: 'manager', rank: 2, can_write: true, can_admin: false, can_superadmin: false },
      },
    });
    await mockSettings(page);

    await page.goto('/app/settings');

    await expect(page.getByRole('alert')).toBeVisible();
    await expect(page.getByRole('link', { name: 'Settings' })).toHaveCount(0);
  });
});
