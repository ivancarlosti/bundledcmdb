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

  test('offers the company directory in the e-mail dropdown', async ({ page }) => {
    let posted: Record<string, unknown> | undefined;
    await mockPanel(page, {
      onCreate: (body) => {
        posted = body;
      },
    });

    await page.goto('/app/devices/new');
    await page.getByRole('tab', { name: 'Assignment' }).click();

    const email = page.locator('#field-user_email');
    await expect(email).toBeVisible();
    await expect(email.locator('option[value="manager@example.com"]')).toHaveCount(1);
    await email.selectOption('manager@example.com');

    await page.getByRole('tab', { name: 'Identity' }).click();
    await page.locator('#field-sn').fill('SN-E2E-2');
    await page.getByRole('button', { name: 'Create asset' }).click();

    await expect.poll(() => posted).toBeDefined();
    expect(posted).toMatchObject({ user_email: 'manager@example.com' });
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
 * The detail page is the "show me everything" view.
 *
 * The grid clips a cell at 26 characters (one row per device: a long note would
 * blow the row height). The detail page used to reuse that `GridCell` unchanged,
 * so long values — serials, MAC lists, notes — were dots on the one screen whose
 * whole purpose is reading them.
 */
test.describe('device detail layout', () => {
  test('renders a long field value in full', async ({ page }) => {
    const hostname = 'acme-laptop-07.asset-name-that-the-grid-would-clip.example.internal';
    await mockPanel(page, { detail: { hostname } });

    await page.goto('/app/devices/7');

    // Scoped to the field list: the header repeats the hostname next to the company.
    const value = page.locator('dl').getByText(hostname, { exact: true });
    await expect(value).toBeVisible();
    await expect(value).toHaveClass(/break-words/);
    await expect(value).not.toHaveClass(/max-w-\[26ch\]/);
  });
});

/**
 * Section layout: one list, two screens.
 *
 * The disk-space field used to land in the catch-all *More* tab because the panel
 * payload and the tab map spelled its key differently (`hds_space_gb` vs
 * `hds_spaces_gb`), and the view was a single flat list of every column. Both
 * screens read `deviceSections.ts` now, so the field is a Hardware field and the
 * agent report sits under its own heading inside the Hardware card.
 */
test.describe('device sections', () => {
  test('keeps the disk-space field in the Hardware tab of the form', async ({ page }) => {
    await mockPanel(page);

    await page.goto('/app/devices/7/edit');
    await page.getByRole('tab', { name: 'Hardware' }).click();

    await expect(page.locator('#field-hds_spaces_gb')).toBeVisible();
    // Nothing falls through to the catch-all, so it is not rendered at all: the
    // tab is called *Custom* now, and "More" is gone.
    await expect(page.getByRole('tab', { name: 'More' })).toHaveCount(0);
    await expect(page.getByRole('tab', { name: 'Custom' })).toHaveCount(0);
  });

  test('shows the agent report under Additional hardware info', async ({ page }) => {
    await mockPanel(page);

    await page.goto('/app/devices/7');

    const hardware = page.getByTestId('device-section-hardware');
    const agent = hardware.getByTestId('device-group-agent');
    await expect(agent).toBeVisible();
    await expect(agent).toContainText('Additional hardware info');
    await expect(agent).toContainText('Windows 11 Pro build 22631');
    await expect(agent).toContainText('Yes');
    // The structured disk inventory stays inside the same card.
    await expect(hardware.getByTestId('device-storage')).toBeVisible();
  });

  test('lays the view out as section cards', async ({ page }) => {
    await mockPanel(page);

    await page.goto('/app/devices/7');

    await expect(page.getByTestId('device-section-identity')).toBeVisible();
    await expect(page.getByTestId('device-section-assignment')).toBeVisible();
    await expect(page.getByTestId('device-section-record')).toBeVisible();
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

/**
 * Saved views.
 *
 * A view is the grid state the URL already carries plus the column layout, so
 * saving one must send exactly those keys and applying it must put the filters
 * back into the URL (not into a hidden parallel state).
 */
test.describe('saved views', () => {
  test('saves the current filters and applies them again from the menu', async ({ page }) => {
    let saved: { name: string; state: Record<string, unknown> } | undefined;
    await mockPanel(page, {
      onSaveView: (view) => {
        saved = view;
      },
    });

    await page.goto('/app/devices?source=eset&sort=SN&dir=asc');
    await expect(page.getByRole('columnheader', { name: /Serial number/ })).toBeVisible();

    await page.getByTestId('views-menu').click();
    await page.getByLabel('View name').fill('ESET by serial');
    await page.getByRole('button', { name: 'Save view' }).click();

    await expect.poll(() => saved?.name).toBe('ESET by serial');
    expect(saved?.state).toMatchObject({ source: 'eset', sort: 'SN', dir: 'asc' });

    // The stored view appears in the list and re-applies its filters.
    await page.goto('/app/devices?status=In%20Use');
    await page.getByTestId('views-menu').click();
    await page.getByRole('button', { name: 'ESET by serial', exact: true }).click();
    await expect.poll(() => page.url()).toContain('source=eset');
    await expect.poll(() => page.url()).toContain('sort=SN');
  });

  test('deletes a saved view without touching the grid', async ({ page }) => {
    await mockPanel(page);

    await page.goto('/app/devices');
    await page.getByTestId('views-menu').click();
    await page.getByLabel('View name').fill('Temporary');
    await page.getByRole('button', { name: 'Save view' }).click();

    await expect(page.getByRole('button', { name: 'Temporary', exact: true })).toBeVisible();
    await page.getByRole('button', { name: 'Delete Temporary' }).click();

    await expect(page.getByRole('button', { name: 'Temporary', exact: true })).toHaveCount(0);
    await expect(page.getByText('No saved views yet. Filter the grid, then name it below.')).toBeVisible();
  });
});

/**
 * Attachment upload (plan §14.9).
 *
 * With the S3 driver the browser PUTs straight to the bucket and only registers
 * the object, which is the path that reports real progress; with `local` storage
 * (or a bucket that refuses CORS) the server puts the object and the client uses
 * multipart instead. Both end in the same `device_files` row.
 */
test.describe('attachment upload', () => {
  test('uploads straight to the bucket when the server offers a presigned PUT', async ({ page }) => {
    let registered: Record<string, unknown> | undefined;
    let presigned: Record<string, unknown> | undefined;
    await mockPanel(page, {
      session: { ...SESSION, features: { ...SESSION.features, attachments: true } },
      presign: {
        mode: 's3',
        url: '/fake-upload?X-Amz-Signature=abc',
        key: 'uploads/assets/7/x_note.txt',
        headers: { 'Content-Type': 'text/plain' },
        expires_in: 300,
      },
      onFileUpload: (body) => {
        registered = body;
      },
    });
    page.on('request', (request) => {
      if (request.url().includes('/files/presign')) {
        presigned = request.postDataJSON() as Record<string, unknown>;
      }
    });

    await page.goto('/app/devices/7');
    await page.getByLabel('Upload New File').setInputFiles({
      name: 'note.txt',
      mimeType: 'text/plain',
      buffer: Buffer.from('hello attachment'),
    });

    await expect.poll(() => registered).toBeDefined();
    expect(presigned).toMatchObject({ name: 'note.txt', size: 16 });
    // The registration carries the key the server signed, not a client-made one.
    expect(registered).toMatchObject({ key: 'uploads/assets/7/x_note.txt', name: 'note.txt' });
  });

  test('falls back to a multipart upload when storage is local', async ({ page }) => {
    await mockPanel(page, {
      session: { ...SESSION, features: { ...SESSION.features, attachments: true } },
    });

    await page.goto('/app/devices/7');
    await page.getByLabel('Upload New File').setInputFiles({
      name: 'note.txt',
      mimeType: 'text/plain',
      buffer: Buffer.from('hello attachment'),
    });

    // `mode: 'server'` (the fixture default) means the browser never talks to a
    // bucket: the request goes to the panel API as multipart.
    await expect(page.getByText('invoice.pdf')).toBeVisible();
  });

  test('uploads through the panel when the browser cannot reach the bucket', async ({ page }) => {
    // What a real MinIO setup does when `s3.endpoint` is `http://minio:9000`: the
    // name resolves inside the Docker network only, so the browser's PUT dies on
    // DNS (or on CORS). The object may still have landed — a blocked response, not
    // a blocked request — so the key is registered first and the bytes are only
    // re-sent through PHP-FPM when the server says the object is missing.
    const bodies: Record<string, unknown>[] = [];
    await mockPanel(page, {
      session: { ...SESSION, features: { ...SESSION.features, attachments: true } },
      presign: {
        mode: 's3',
        url: '/fake-upload?X-Amz-Signature=abc',
        key: 'uploads/assets/7/x_note.txt',
        headers: { 'Content-Type': 'text/plain' },
        expires_in: 300,
      },
      bucketUnreachable: true,
      registerDirectFailed: true,
      onFileUpload: (body) => {
        bodies.push(body);
      },
    });

    await page.goto('/app/devices/7');
    await page.getByLabel('Upload New File').setInputFiles({
      name: 'note.txt',
      mimeType: 'text/plain',
      buffer: Buffer.from('hello attachment'),
    });

    await expect.poll(() => bodies.length).toBe(2);
    // First the registration of the signed key, then the multipart upload.
    expect(bodies[0]).toMatchObject({ key: 'uploads/assets/7/x_note.txt' });
    expect(bodies[1]).not.toHaveProperty('key');
    await expect(page.getByText('invoice.pdf')).toBeVisible();
  });

  test('reports the server reason when no path can upload', async ({ page }) => {
    await mockPanel(page, {
      session: { ...SESSION, features: { ...SESSION.features, attachments: true } },
      presign: {
        mode: 's3',
        url: '/fake-upload?X-Amz-Signature=abc',
        key: 'uploads/assets/7/x_note.txt',
        headers: { 'Content-Type': 'text/plain' },
        expires_in: 300,
      },
      bucketUnreachable: true,
      registerDirectFailed: true,
      multipartFailed: true,
    });

    await page.goto('/app/devices/7');
    await page.getByLabel('Upload New File').setInputFiles({
      name: 'huge.pdf',
      mimeType: 'application/pdf',
      buffer: Buffer.from('%PDF-1.7'),
    });

    // The title stays the generic `upload_failed`; the description is the reason
    // the server gave. `messageOf()` used to drop everything that was not a
    // `PanelApiError`, which is how the bare "Could not upload the file." happened.
    await expect(page.getByText('Could not upload the file.')).toBeVisible();
    await expect(page.getByText('The file is too large.')).toBeVisible();
  });
});
