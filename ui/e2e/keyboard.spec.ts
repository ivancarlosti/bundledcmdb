import { expect, test } from '@playwright/test';

import { mockPanel } from './fixtures';

/**
 * Keyboard-only walkthrough (phase 4 acceptance).
 *
 * Everything the grid does with the mouse has a keyboard path: sorting is a real
 * `<button>`, rows are focusable and activate on Enter, and the selection
 * checkboxes are native inputs so Space toggles them.
 */
test.describe('keyboard', () => {
  test('sorts with Enter on the header button', async ({ page }) => {
    await mockPanel(page);
    await page.goto('/app/devices');
    await expect(page.getByTestId('data-row')).toHaveCount(2);

    const header = page.getByRole('button', { name: /Serial number/ });
    await header.focus();
    await page.keyboard.press('Enter');

    await expect.poll(() => page.url()).toContain('sort=SN&dir=asc');
    await expect(header).toBeFocused();
  });

  test('opens a row with Enter and comes back', async ({ page }) => {
    await mockPanel(page);
    await page.goto('/app/devices');
    await expect(page.getByTestId('data-row')).toHaveCount(2);

    await page.getByTestId('data-row').first().focus();
    await page.keyboard.press('Enter');

    await expect.poll(() => page.url()).toContain('/app/devices/7');

    await page.goBack();
    await expect(page.getByTestId('data-row').first()).toBeVisible();
  });

  test('toggles a row checkbox with Space', async ({ page }) => {
    await mockPanel(page);
    await page.goto('/app/devices');

    const checkbox = page.getByRole('checkbox', { name: 'Select SN-0007' });
    await checkbox.focus();
    await page.keyboard.press('Space');

    await expect(checkbox).toBeChecked();
    await expect(page.getByTestId('bulk-actions').getByRole('status')).toHaveText('1 selected');
  });
});
