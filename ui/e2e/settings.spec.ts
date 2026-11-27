import { expect, test } from '@playwright/test';

import { SETTINGS_USERS, mockPanel, mockSettings } from './fixtures';

/**
 * Settings screens (phase 7).
 *
 * `/settings` is superadmin-only except for Users: `settings.*` and
 * `permissions.manage` are flagged `superadmin_only` in `lib/permissions.php`,
 * while the delegable `users.manage` opens only the roster. `mockPanel` fills
 * the capability map from `ROLE_PERMISSIONS.<role>`.
 *
 * The behaviours that only exist in the SPA and are easy to regress:
 *
 *  - a save must not resend an untouched secret (the API sends `""` for one);
 *  - a `422` with per-key `errors` is rendered next to the input, not as one
 *    opaque banner;
 *  - cards follow the server groups (Storage is split into attachment and S3)
 *    and the user roster filters on the client;
 *  - the navigation link and the screen follow `users.manage` as well as
 *    `can_superadmin`, and an admin sees exactly one tab.
 */
const SUPERADMIN = {
  user: { email: 'qa@example.com', company: '', role: 'superadmin' },
  capabilities: { role: 'superadmin', rank: 4, can_write: true, can_admin: true, can_superadmin: true },
};

test.describe('settings', () => {
  test('saves the storage tab without resending the stored secret', async ({ page }) => {
    const saved: Record<string, unknown>[] = [];
    await mockPanel(page, { session: SUPERADMIN });
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
    await mockPanel(page, { session: SUPERADMIN });
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
    await mockPanel(page, { session: SUPERADMIN });
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
    await mockPanel(page, { session: SUPERADMIN });
    await mockSettings(page, { invalid: { 's3.bucket': 'required' } });

    await page.goto('/app/settings?tab=storage');
    await page.getByLabel('Bucket').fill('');
    await page.getByRole('button', { name: 'Save Changes' }).click();

    // The catalogue stub maps `required` to "Required.".
    await expect(page.getByText('Required.')).toBeVisible();
  });

  test('inserts a prefix placeholder at the caret', async ({ page }) => {
    await mockPanel(page, { session: SUPERADMIN });
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
    await mockPanel(page, { session: SUPERADMIN });
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

  test('hides the settings link and screen from a manager', async ({ page }) => {
    await mockPanel(page, {
      session: {
        user: { email: 'qa@example.com', company: '', role: 'manager' },
        capabilities: { role: 'manager', rank: 2, can_write: true, can_admin: true, can_superadmin: false },
      },
    });
    await mockSettings(page);

    await page.goto('/app/devices');
    await expect(page.getByRole('link', { name: 'Settings' })).toHaveCount(0);

    // A bookmark still renders the forbidden state instead of the tab list.
    await page.goto('/app/settings');
    await expect(page.getByRole('alert')).toBeVisible();
    await expect(page.getByRole('tab')).toHaveCount(0);
  });

  test('gives an admin the Users tab and nothing else', async ({ page }) => {
    await mockPanel(page);
    await mockSettings(page, {
      users: {
        ...SETTINGS_USERS,
        users: [
          { email: 'boss@example.com', company: 'Acme', role: 'admin', is_self: true, manageable: false },
          { email: 'ops@example.com', company: 'Acme', role: 'manager', is_self: false, manageable: true },
        ],
        roles: ['user', 'manager'],
        companies: ['Acme'],
        scope: { company: 'Acme', all_companies: false },
      },
    });

    await page.goto('/app/devices');
    await page.getByRole('link', { name: 'Settings' }).click();

    // `users.manage` is the only settings-area capability an admin holds.
    await expect(page.getByRole('heading', { name: 'Settings' })).toBeVisible();
    await expect(page.getByRole('tab')).toHaveCount(1);
    await expect(page.getByRole('tab', { name: 'Users' })).toHaveAttribute('aria-selected', 'true');
    await expect(page.getByText('You can manage the users of Acme.')).toBeVisible();
    await expect(page.getByRole('tab', { name: 'General' })).toHaveCount(0);
  });

  test('opens every tab for a superadmin', async ({ page }) => {
    await mockPanel(page, { session: SUPERADMIN });
    await mockSettings(page);

    await page.goto('/app/devices');
    await page.getByRole('link', { name: 'Settings' }).click();
    await expect(page.getByRole('heading', { name: 'Settings' })).toBeVisible();

    // General, Columns, Storage, Sources, Categories, Companies, API, Fields,
    // Logs, Collect data, Maintenance, Permissions, Users: the whole surface
    // belongs to the recovery role.
    await expect(page.getByRole('tab')).toHaveCount(13);
    await expect(page.getByRole('tab', { name: 'Companies' })).toBeVisible();
    await expect(page.getByRole('tab', { name: 'Permissions' })).toBeVisible();
    await expect(page.getByRole('tab', { name: 'Users' })).toBeVisible();

    // Source usage is shown, and a system source cannot be deleted.
    await page.getByRole('tab', { name: 'Sources' }).click();
    await expect(page.getByRole('cell', { name: /Manual/ })).toBeVisible();
    await expect(page.getByRole('button', { name: 'Delete' }).first()).toBeDisabled();
  });

  /**
   * The category tab is the only settings screen whose rows are grouped instead
   * of listed flat, and the only one where a row can be undeletable: one item is
   * always required to carry a category, so the last row of a tab has to stay.
   */
  test('groups the category registry per tab and keeps the rename kind fixed', async ({ page }) => {
    const patches: { url: string; body: unknown }[] = [];
    await mockPanel(page, { session: SUPERADMIN });
    await mockSettings(page);

    // A single System row proves the delete guard without needing an item.
    const categories = [
      { id: 1, kind: 'asset', name: 'Laptop', usage: 2 },
      { id: 2, kind: 'asset', name: 'Monitor', usage: 0 },
      { id: 3, kind: 'system', name: 'Licence', usage: 0 },
    ];
    await page.route('**/panel-api/v1/settings/categories**', (route) => {
      if (route.request().method() === 'PATCH') {
        patches.push({ url: route.request().url(), body: route.request().postDataJSON() });
      }
      return route.fulfill({ json: { ok: true, data: { categories, kinds: ['asset', 'system'] } } });
    });

    await page.goto('/app/settings?tab=categories');

    await expect(page.getByRole('tab', { name: 'Categories' })).toHaveAttribute('aria-selected', 'true');
    await expect(page.getByRole('heading', { name: 'Assets' })).toBeVisible();
    await expect(page.getByRole('heading', { name: 'Systems' })).toBeVisible();

    const laptop = page.getByRole('row', { name: /^Laptop/ });
    await expect(laptop.getByText('2 item(s)')).toBeVisible();
    await expect(laptop.getByRole('button', { name: 'Delete' })).toBeEnabled();

    // The only System category cannot be deleted, and the title says why.
    const licence = page.getByRole('row', { name: /^Licence/ });
    await expect(licence.getByRole('button', { name: 'Delete' })).toBeDisabled();
    await expect(licence.getByRole('button', { name: 'Delete' })).toHaveAttribute(
      'title',
      'The last category of this tab cannot be deleted: every item needs one.',
    );

    // Rename: the kind is part of `UNIQUE(kind, name)` and owns the items, so it
    // is shown but locked; only the name is editable.
    await laptop.getByRole('button', { name: 'Edit' }).click();
    await expect(page.getByLabel('Applies to')).toBeDisabled();
    await expect(page.getByLabel('Applies to')).toHaveValue('asset');

    const name = page.getByLabel('Category name');
    await expect(name).toHaveValue('Laptop');
    await name.fill('Laptop EU');
    await page.getByRole('button', { name: 'Save Changes' }).click();

    await expect.poll(() => patches.length).toBe(1);
    expect(patches[0]?.url).toMatch(/\/settings\/categories\/1$/);
    expect(patches[0]?.body).toMatchObject({ name: 'Laptop EU' });
  });

  test('asks for the receiving category before deleting a used one', async ({ page }) => {
    const deletes: string[] = [];
    await mockPanel(page, { session: SUPERADMIN });
    await mockSettings(page);

    const categories = [
      { id: 1, kind: 'asset', name: 'Laptop', usage: 2 },
      { id: 2, kind: 'asset', name: 'Monitor', usage: 0 },
      { id: 3, kind: 'system', name: 'Licence', usage: 1 },
    ];
    await page.route('**/panel-api/v1/settings/categories**', (route) => {
      if (route.request().method() === 'DELETE') {
        deletes.push(route.request().url());
      }
      return route.fulfill({ json: { ok: true, data: { categories, kinds: ['asset', 'system'] } } });
    });

    await page.goto('/app/settings?tab=categories');
    await page
      .getByRole('row', { name: /^Laptop/ })
      .getByRole('button', { name: 'Delete' })
      .click();

    // A used row opens the reassign panel instead of a confirm that the server
    // would answer with `409 in_use`.
    await expect(page.getByRole('heading', { name: 'Delete "Laptop" and move its items' })).toBeVisible();

    // Only siblings of the same kind are offered, and the first one is
    // preselected so the panel is never submitted empty.
    const target = page.getByLabel('Move its items to');
    await expect(target).toHaveValue('Monitor');
    await expect(target.getByRole('option')).toHaveCount(1);

    const panel = page.locator('form').filter({ has: page.getByRole('heading', { name: /move its items/ }) });
    await panel.getByRole('button', { name: 'Delete' }).click();

    await expect.poll(() => deletes.length).toBe(1);
    expect(deletes[0]).toContain('/settings/categories/1?reassign_to=Monitor');
    await expect(panel).toHaveCount(0);
  });

  test('opens the user roster for a superadmin and disables the own row', async ({ page }) => {
    await mockPanel(page, { session: SUPERADMIN });
    await mockSettings(page);

    await page.goto('/app/settings?tab=users');

    await expect(page.getByRole('tab', { name: 'Users' })).toHaveAttribute('aria-selected', 'true');
    // The own row is rendered as text (the server refuses a self-edit)…
    const own = page.getByRole('row', { name: /qa@example.com/ });
    await expect(own.getByRole('combobox')).toHaveCount(0);
    await expect(own.getByText('Superadmin')).toBeVisible();

    // …while a row inside the role ceiling keeps its select.
    const other = page.getByRole('row', { name: /ops@example.com/ }).getByRole('combobox');
    await expect(other).toBeEnabled();
    await expect(other).toHaveValue('manager');
  });

  test('keeps a manager with can_admin out of the screen', async ({ page }) => {
    await mockPanel(page, {
      session: {
        user: { email: 'qa@example.com', company: '', role: 'manager' },
        capabilities: { role: 'manager', rank: 2, can_write: true, can_admin: true, can_superadmin: false },
      },
    });
    await mockSettings(page);

    await page.goto('/app/settings');

    await expect(page.getByRole('alert')).toBeVisible();
    await expect(page.getByRole('link', { name: 'Settings' })).toHaveCount(0);
  });
});
