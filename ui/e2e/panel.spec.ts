import { expect, test } from '@playwright/test';

import { mockPanel } from './fixtures';

/**
 * Regression suite for the grid behaviour that 2279453 broke.
 *
 * Every assertion is about what the *server* is asked for and what the URL says,
 * because that is where the old grid failed: it reordered the rows it had
 * loaded, so sorting stopped at the page boundary and a reload lost it.
 */
test.describe('device grid', () => {
  test('sorts on the server and keeps the order in the URL', async ({ page }) => {
    const requested: string[] = [];
    await mockPanel(page, { onDevices: (url) => requested.push(url) });

    await page.goto('/app/devices');
    await expect(page.getByRole('heading', { name: 'Devices' })).toBeVisible();
    await expect(page.getByTestId('data-row')).toHaveCount(2);
    await expect(page.getByRole('columnheader', { name: /Updated at/ })).toHaveAttribute('aria-sort', 'none');

    const serialHeader = page.getByRole('button', { name: /Serial number/ });

    // 1st click: ascending.
    await serialHeader.click();
    await expect.poll(() => page.url()).toContain('sort=SN&dir=asc');
    await expect.poll(() => requested.at(-1) ?? '').toContain('sort=SN&dir=asc');
    await expect(page.getByRole('columnheader', { name: /Serial number/ })).toHaveAttribute(
      'aria-sort',
      'ascending',
    );

    // 2nd click: descending.
    await serialHeader.click();
    await expect.poll(() => page.url()).toContain('sort=SN&dir=desc');
    await expect.poll(() => requested.at(-1) ?? '').toContain('dir=desc');
    await expect(page.getByRole('columnheader', { name: /Serial number/ })).toHaveAttribute(
      'aria-sort',
      'descending',
    );

    // 3rd click: back to the server default, which leaves no sort in the URL
    // (the default query is the one already cached from the first load).
    await serialHeader.click();
    await expect.poll(() => page.url()).not.toContain('sort=');
    expect(requested[0]).toContain('sort=updated_at&dir=desc');

    // A reload of a sorted view asks for the same order again.
    await serialHeader.click();
    await expect.poll(() => page.url()).toContain('sort=SN&dir=asc');
    await page.reload();
    await expect(page.getByRole('columnheader', { name: /Serial number/ })).toHaveAttribute(
      'aria-sort',
      'ascending',
    );
  });

  test('writes filters and paging to the URL and to the request', async ({ page }) => {
    const requested: string[] = [];
    await mockPanel(page, { onDevices: (url) => requested.push(url), pages: 3, total: 60 });

    await page.goto('/app/devices');
    await expect(page.getByTestId('data-row')).toHaveCount(2);

    await page.getByRole('searchbox').fill('dell');
    await expect.poll(() => page.url()).toContain('q=dell');
    await expect.poll(() => requested.at(-1) ?? '').toContain('q=dell');

    await page.getByRole('button', { name: 'Next' }).click();
    await expect.poll(() => page.url()).toContain('page=2');
    await expect.poll(() => requested.at(-1) ?? '').toContain('page=2');

    // Clearing the filters returns to page 1 (no empty page 3).
    await page.getByRole('button', { name: 'Clear filters' }).click();
    await expect.poll(() => page.url()).not.toContain('q=dell');
    await expect.poll(() => page.url()).not.toContain('page=2');
  });

  test('opens the detail page and comes back', async ({ page }) => {
    await mockPanel(page);

    await page.goto('/app/devices');
    await page.getByTestId('data-row').first().click();

    await expect.poll(() => page.url()).toContain('/app/devices/7');
    await expect(page.getByRole('heading', { name: 'SN-0007' })).toBeVisible();
    await expect(page.getByText('invoice.pdf')).toBeVisible();

    await page.getByRole('link', { name: 'Back to list' }).first().click();
    await expect.poll(() => page.url()).toContain('/app/devices');
  });
});
