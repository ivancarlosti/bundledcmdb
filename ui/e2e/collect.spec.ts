import { expect, test } from '@playwright/test';

import { COLLECT_STATUS, COLLECT_TOKEN, mockPanel, SESSION } from './fixtures';

/**
 * Experimental "Collect data" screen.
 *
 * The interesting behaviour is not the download (a Blob is hard to assert and
 * says nothing about the server contract) but the three states the screen has to
 * get right: the feature flag hides the tab, an active link shows its expiry and
 * can be revoked, and generating a new link asks first because it cancels the old
 * one.
 */
test.describe('collect data', () => {
  test('hides the tab when the feature is off', async ({ page }) => {
    await mockPanel(page, { session: { features: { ...SESSION.features, collect: false } } });

    await page.goto('/app/devices');
    await expect(page.getByRole('link', { name: 'Devices' })).toBeVisible();
    await expect(page.getByRole('link', { name: 'Collect data' })).toHaveCount(0);

    // A bookmark still lands on an explanation, not on a failing request.
    await page.goto('/app/collect');
    await expect(page.getByText('Data collection is disabled on this installation.')).toBeVisible();
  });

  test('shows the tab and generates a file', async ({ page }) => {
    await mockPanel(page);

    await page.goto('/app/devices');
    await page.getByRole('link', { name: 'Collect data' }).click();

    await expect(page.getByRole('heading', { name: 'Collect data' })).toBeVisible();
    await expect(page.getByText('You have no active download.')).toBeVisible();
    await expect(page.getByText(/Server: https:\/\/cmdb.example.com\/collect/)).toBeVisible();

    await page.getByRole('button', { name: 'Generate download' }).click();

    await expect(page.getByText('The file was saved to your downloads folder.').first()).toBeVisible();
  });

  test('shows the active link and revokes it after a confirmation', async ({ page }) => {
    await mockPanel(page, {
      collect: { ...COLLECT_STATUS, token: COLLECT_TOKEN },
    });
    const dialogs: string[] = [];
    page.on('dialog', (dialog) => {
      dialogs.push(dialog.message());
      void dialog.accept();
    });

    await page.goto('/app/collect');

    await expect(page.getByText('collect-qa.bat')).toBeVisible();
    await expect(page.getByText('The file has not been run yet.')).toBeVisible();
    await expect(page.getByText(/One link per user, valid for 120 minutes/)).toBeVisible();

    await page.getByRole('button', { name: 'Revoke link' }).click();

    await expect.poll(() => dialogs.length).toBeGreaterThan(0);
    expect(dialogs[0]).toContain('Revoke the link?');
    await expect(page.getByText('The link was revoked.').first()).toBeVisible();
  });

  test('asks before replacing an active link', async ({ page }) => {
    await mockPanel(page, {
      collect: { ...COLLECT_STATUS, token: COLLECT_TOKEN },
    });
    const dialogs: string[] = [];
    page.on('dialog', (dialog) => {
      dialogs.push(dialog.message());
      void dialog.accept();
    });

    await page.goto('/app/collect');
    await page.getByRole('button', { name: 'Generate a new file' }).click();

    await expect.poll(() => dialogs.length).toBeGreaterThan(0);
    expect(dialogs[0]).toContain('A new file replaces the previous link');
  });
});
