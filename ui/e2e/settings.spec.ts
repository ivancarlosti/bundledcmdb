import { expect, test } from '@playwright/test';

import { mockPanel, mockSettings } from './fixtures';

/**
 * Settings screens (phase 7).
 *
 * The behaviours that only exist in the SPA and are easy to regress:
 *
 *  - a save must not resend an untouched secret (the API sends `""` for one);
 *  - a `422` with per-key `errors` is rendered next to the input, not as one
 *    opaque banner;
 *  - cards follow the server groups (Storage is split into attachment and S3)
 *    and the user roster filters on the client;
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

  test('splits storage into two cards and starts each with its booleans', async ({ page }) => {
    await mockPanel(page);
    await mockSettings(page);

    await page.goto('/app/settings?tab=general');
    await expect(page.getByRole('heading', { name: 'General' })).toHaveCount(1);
    await expect(page.getByRole('heading', { name: 'Assets' })).toBeVisible();
    await expect(page.getByRole('checkbox', { name: 'Enable serial number requirement' })).toBeVisible();

    await page.getByRole('tab', { name: 'Storage' }).click();
    await expect(page.getByRole('heading', { name: 'Attachment storage' })).toBeVisible();
    await expect(page.getByRole('heading', { name: 'S3 connection' })).toBeVisible();

    const s3Card = page.locator('section').filter({ has: page.getByRole('heading', { name: 'S3 connection' }) });
    await expect(s3Card.getByRole('checkbox').first()).toHaveAccessibleName(
      'Enable path-style URLs (MinIO / self-hosted)',
    );
  });

  test('filters the user roster by search and company', async ({ page }) => {
    await mockPanel(page, {
      session: {
        user: { email: 'qa@example.com', company: '', role: 'superadmin' },
        capabilities: { role: 'superadmin', rank: 4, can_write: true, can_admin: true, can_superadmin: true },
      },
    });
    await mockSettings(page);

    await page.goto('/app/settings?tab=users');
    await expect(page.getByText('Showing 2 of 2 users')).toBeVisible();

    await page.getByLabel('Search users').fill('Acme');
    await expect(page.getByText('Showing 1 of 2 users')).toBeVisible();
    await expect(page.getByRole('row', { name: /ops@example.com/ })).toBeVisible();
    await expect(page.getByRole('row', { name: /qa@example.com/ })).toHaveCount(0);

    await page.getByLabel('Search users').fill('');
    await page.getByLabel('Company').selectOption('Acme');
    await expect(page.getByText('Showing 1 of 2 users')).toBeVisible();

    await page.getByLabel('Company').selectOption('__unassigned__');
    await expect(page.getByText('Showing 1 of 2 users')).toBeVisible();
    await expect(page.getByRole('row', { name: /qa@example.com/ })).toBeVisible();

    await page.getByLabel('Search users').fill('nobody');
    await expect(page.getByText('No users match the filters.')).toBeVisible();
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

  test('inserts a prefix placeholder at the caret', async ({ page }) => {
    await mockPanel(page);
    await mockSettings(page);

    await page.goto('/app/settings?tab=storage');

    const prefix = page.getByLabel('Object key prefix');
    await expect(prefix).toHaveValue('uploads');
    // The chips come from the entry's catalogue, so a new token needs no release.
    await expect(page.getByRole('button', { name: '{{company}}' })).toHaveAttribute('title', 'Company');

    // Insertion follows the caret instead of appending: with the caret after
    // "up" the token lands there, which is what editing a template expects.
    await prefix.press('Home');
    await prefix.press('ArrowRight');
    await prefix.press('ArrowRight');
    await page.getByRole('button', { name: '{{company}}' }).click();
    await expect(prefix).toHaveValue('up{{company}}loads');

    // A second chip at the tail keeps the first insertion intact.
    await prefix.press('End');
    await page.getByRole('button', { name: '{{asset}}' }).click();
    await expect(prefix).toHaveValue('up{{company}}loads{{asset}}');
  });

  test('resets a stored setting to its default without dropping the form', async ({ page }) => {
    const saved: Record<string, unknown>[] = [];
    await mockPanel(page);
    await mockSettings(page, { onSave: (body) => saved.push(body) });

    await page.goto('/app/settings?tab=storage');

    // Only a DB hit offers the button, and it lives in that setting's row, so the
    // label of the input it belongs to scopes it.
    await page.getByLabel('Object key prefix').locator('..').getByRole('button', { name: 'Reset to default' }).click();

    await expect.poll(() => saved.length).toBe(1);
    expect(saved[0]?.reset).toEqual(['s3.prefix']);
    // The rest of the form rides along: dropping the DB row re-seeds every field
    // from the answer, so a reset may not throw away an edit in another card.
    expect((saved[0]?.values as Record<string, unknown>)['s3.bucket']).toBe('cmdb');
  });

  test('shows the settings link to an admin and hides the user roster', async ({ page }) => {
    await mockPanel(page);
    await mockSettings(page);

    await page.goto('/app/devices');
    await expect(page.getByRole('link', { name: 'Settings' })).toBeVisible();

    await page.getByRole('link', { name: 'Settings' }).click();
    await expect(page.getByRole('heading', { name: 'Settings' })).toBeVisible();

    const tabs = page.getByRole('tab');
    // General, Columns, Storage, Sources, API, Fields, Logs, Maintenance: the
    // admin defaults of `lib/permissions.php`. Permissions and Users are
    // superadmin-only, so the roster tab is the one that must be missing.
    await expect(tabs).toHaveCount(8);
    await expect(page.getByRole('tab', { name: 'Logs' })).toBeVisible();
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
