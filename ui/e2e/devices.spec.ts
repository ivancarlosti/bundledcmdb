import { expect, test } from '@playwright/test';

import { mockPanel, SESSION } from './fixtures';

/**
 * Write-path and layout suite.
 *
 * These cover the two things the old grid got wrong and the new SPA has to keep
 * doing: the payload it sends (`snake_case` keys, exactly what `/meta`
 * publishes) and the fact that a column layout survives a reload because it is
 * stored server-side, not in a local variable.
 */
test.describe('device writes', () => {
  test('creates a device and navigates to it', async ({ page }) => {
    let posted: Record<string, unknown> | undefined;
    await mockPanel(page, {
      onCreate: (body) => {
        posted = body;
      },
    });

    await page.goto('/app/devices');
    await page.getByRole('link', { name: 'New device' }).click();
    await expect(page.getByRole('heading', { name: 'New device' })).toBeVisible();

    await page.locator('#field-sn').fill('SN-E2E-1');
    await page.locator('#field-hostname').fill('e2e-host');
    await page.getByRole('button', { name: 'Create asset' }).click();

    await expect.poll(() => posted).toBeDefined();
    expect(posted).toMatchObject({ sn: 'SN-E2E-1', hostname: 'e2e-host' });
    await expect.poll(() => page.url()).toContain('/app/devices/99');
  });

  test('keeps the column layout in the preferences API', async ({ page }) => {
    let patched: { grid_state?: { visible?: string[]; order?: string[] } } | undefined;
    await mockPanel(page);

    await page.route('**/panel-api/v1/session/preferences', async (route) => {
      patched = route.request().postDataJSON() as typeof patched;
      return route.fulfill({
        json: { ok: true, data: { ...SESSION, gridState: patched?.grid_state ?? null } },
      });
    });

    await page.goto('/app/devices');
    await expect(page.getByRole('columnheader', { name: /Hostname/ })).toBeVisible();

    await page.getByText('Columns', { exact: true }).click();
    // `click` + retrying assertion: the preference is applied optimistically in a
    // microtask, so `uncheck()`'s immediate state check is too eager.
    await page.getByRole('checkbox', { name: 'Hostname' }).click();
    await expect(page.getByRole('checkbox', { name: 'Hostname' })).not.toBeChecked();

    await expect.poll(() => page.getByRole('columnheader', { name: /Hostname/ }).count()).toBe(0);
    await expect.poll(() => patched).toBeDefined();
    expect(patched?.grid_state?.visible).toBeInstanceOf(Array);
    expect(patched?.grid_state?.visible).not.toContain('Hostname');
  });
});

/**
 * Audit trail on the detail page.
 *
 * The history is the answer to "who changed this and to what?", so the columns
 * are labelled with the grid's own `col_*` translation and the field move is
 * rendered as `old → new` rather than a JSON blob.
 */
test.describe('device history', () => {
  test('lists the recorded changes with their field labels', async ({ page }) => {
    await mockPanel(page);

    await page.goto('/app/devices/7');

    const history = page.getByTestId('device-history');
    await expect(history).toBeVisible();
    // `:scope > li` so the per-field change lists inside each entry do not count.
    await expect(history.locator(':scope > li')).toHaveCount(2);
    await expect(history).toContainText('Updated');
    await expect(history).toContainText('In Use → In Stock');
    await expect(history).toContainText('Created');
    await expect(history).toContainText('admin@example.com');
  });
});

/**
 * Column reordering, both ways.
 *
 * The pointer path (`@dnd-kit` on the grip handle) is what the deferred item
 * asked for; the buttons stay because they are the keyboard baseline, and the
 * handle itself is a button so `KeyboardSensor` works off the same focus ring.
 */
test.describe('column picker', () => {
  test('reorders a column with the drag handle and persists it', async ({ page }) => {
    let patched: { grid_state?: { order?: string[] } } | undefined;
    await mockPanel(page);

    await page.route('**/panel-api/v1/session/preferences', async (route) => {
      patched = route.request().postDataJSON() as typeof patched;
      return route.fulfill({ json: { ok: true, data: { ...SESSION, gridState: null } } });
    });

    await page.goto('/app/devices');
    await expect(page.getByRole('columnheader', { name: /Hostname/ })).toBeVisible();

    await page.getByText('Columns', { exact: true }).click();
    const handle = page.getByRole('button', { name: 'Drag Hostname to reorder' });
    const target = page.getByTestId('column-row-SN');
    await expect(handle).toBeVisible();

    // Pointer drag: from the handle of `Hostname` onto the `SN` row above it.
    const from = await handle.boundingBox();
    const to = await target.boundingBox();
    if (from === null || to === null) {
      throw new Error('the picker rows have no geometry');
    }
    await page.mouse.move(from.x + from.width / 2, from.y + from.height / 2);
    await page.mouse.down();
    await page.mouse.move(to.x + to.width / 2, to.y + to.height / 2, { steps: 8 });
    await page.mouse.up();

    await expect.poll(() => patched?.grid_state?.order?.[0]).toBe('Hostname');
  });

  test('moves a column with the keyboard buttons', async ({ page }) => {
    await mockPanel(page);

    await page.goto('/app/devices');
    await page.getByText('Columns', { exact: true }).click();

    const firstHeader = page.getByRole('columnheader').nth(1);
    const before = await firstHeader.innerText();

    await page.getByRole('button', { name: `Move ${before} down` }).click();

    await expect.poll(async () => page.getByRole('columnheader').nth(1).innerText()).not.toBe(before);
  });
});
