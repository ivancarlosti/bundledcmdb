#!/usr/bin/env node
/**
 * Capture the README screenshots from a running stack.
 *
 * Usage (dev stack up, see docs/frontend.md):
 *
 *   docker cp public/app/. bcmdb-bundle-app:/var/www/html/public/app/   # fresh bundle
 *   node ui/scripts/screenshots.mjs [baseUrl]
 *
 * Default base URL: http://localhost:5600 (docker/docker-compose-bundle.yml). The
 * script signs in through the development login (`APP_DEV_LOGIN=1`), so it never
 * needs Keycloak; every screen is captured with the same demo data as
 * `sql/dev-seed.sql`. It writes into docs/screenshots/ and prints the file
 * names, which is what README.md references.
 *
 * The stack must run with `APP_DEFAULT_LOCALE=en-US`: the sign-in screen is
 * captured before the locale switcher exists, and the waits below match the
 * English labels (`Sign in with SSO`, `Devices`, `New device`, `Audit log`).
 *
 * The twelve files it produces:
 *
 *   01-login.png               anonymous /
 *   02-dashboard-light.png     devices grid, light, en-US
 *   03-dashboard-dark.png      devices grid, dark, en-US
 *   04-dashboard-es-MX.png     devices grid, light, es-MX
 *   05-asset-detail.png        devices/:id, light, en-US
 *   06-settings-users.png      settings?tab=users, superadmin
 *   07-audit-log.png           /logs (filters + purge panel)
 *   08-device-form.png         /devices/new
 *   09-settings-columns.png    settings?tab=columns (grid layout)
 *   10-settings-permissions.png settings?tab=permissions (capability matrix)
 *   11-dashboard-ar-SA.png     devices grid, light, ar-SA (dir="rtl")
 *   12-dashboard-mobile.png    devices grid at 360x740
 *
 * Everything after 06 assumes the dev-login user is a `superadmin`, otherwise
 * the settings tabs fall back to the first tab the role may open.
 */
import { mkdir } from 'node:fs/promises';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

import { chromium } from 'playwright';

const BASE = (process.argv[2] ?? process.env.SCREENSHOTS_BASE ?? 'http://localhost:5600').replace(/\/$/, '');
const OUT = join(dirname(fileURLToPath(import.meta.url)), '..', '..', 'docs', 'screenshots');
const ADMIN = process.env.SCREENSHOTS_USER ?? 'admin@example.com';
const VIEWPORT = { width: 1440, height: 900 };
// 12-…: the width where the grid is at its narrowest useful layout; the height
// is a Pixel-class phone, so the capture shows the real first screenful.
const MOBILE_VIEWPORT = { width: 360, height: 740 };

const log = (message) => process.stdout.write(`${message}\n`);

async function settle(page) {
  // The grid and the settings tabs fetch after mount; waiting for the network to
  // go quiet is the only stable signal across both screens.
  await page.waitForLoadState('networkidle');
  await page.waitForTimeout(250);
}

async function capture(page, name) {
  const path = join(OUT, name);
  await page.screenshot({ path, fullPage: false });
  log(`wrote docs/screenshots/${name}`);
}

/**
 * Open a deep link and wait for the screen's own `<h1>`, then for the fetches it
 * fires on mount. `networkidle` alone can resolve before the lazy chunk mounts
 * the heading, which would capture a blank card.
 */
async function open(page, path, heading) {
  await page.goto(`${BASE}${path}`);
  await page.getByRole('heading', { name: heading, level: 1 }).waitFor();
  await settle(page);
}

async function main() {
  await mkdir(OUT, { recursive: true });

  const browser = await chromium.launch();
  const context = await browser.newContext({ viewport: VIEWPORT, locale: 'en-US' });
  const page = await context.newPage();

  // 1. Sign-in screen (anonymous).
  await page.goto(`${BASE}/`);
  await page.getByRole('link', { name: 'Sign in with SSO' }).waitFor();
  await capture(page, '01-login.png');

  // 2. Signed in through the dev login: the devices grid.
  await page.goto(`${BASE}/auth/dev-login?email=${encodeURIComponent(ADMIN)}&redirect=%2Fapp%2F`);
  await page.locator('table tbody tr').first().waitFor();
  await settle(page);

  const theme = page.getByTestId('theme-switcher');
  const locale = page.getByTestId('locale-switcher');

  await theme.selectOption('light');
  await page.locator('html[data-theme="light"]').waitFor();
  await settle(page);
  await capture(page, '02-dashboard-light.png');

  await theme.selectOption('dark');
  await page.locator('html[data-theme="dark"]').waitFor();
  await settle(page);
  await capture(page, '03-dashboard-dark.png');

  // 4. Spanish, back on the light theme so the text stays readable in the README.
  //    The catalogue loads asynchronously after the PATCH, so waiting for
  //    `html[lang]` alone would capture the previous language's strings.
  await theme.selectOption('light');
  await locale.selectOption('es-MX');
  await page.getByRole('link', { name: 'Dispositivos', exact: true }).waitFor();
  await settle(page);
  await capture(page, '04-dashboard-es-MX.png');

  await locale.selectOption('en-US');
  await page.getByRole('link', { name: 'Devices', exact: true }).waitFor();
  await settle(page);

  // 5. Device detail: rows navigate with Enter (same path the keyboard spec
  //    uses), which keeps the capture free of a cell click that would start
  //    inline editing instead.
  const firstRow = page.getByTestId('data-row').first();
  await firstRow.focus();
  await page.keyboard.press('Enter');
  await page.waitForURL(/\/app\/devices\/\d+/);
  await settle(page);
  await capture(page, '05-asset-detail.png');

  // 6. Users tab (superadmin only) — the settings screen that replaced
  //    manage_permissions.php.
  await page.goto(`${BASE}/app/settings?tab=users`);
  await page.getByRole('table').first().waitFor();
  await settle(page);
  await capture(page, '06-settings-users.png');

  // 7. Audit log: its own screen (action/entity/date filters, purge panel), not
  //    a device view, so the README needs a picture of it next to the grid.
  await open(page, '/app/logs', 'Audit log');
  await capture(page, '07-audit-log.png');

  // 8. Create form: the dynamic-field tabs and the flat column list are what a
  //    reader checks before deploying, so capture the empty form, not an edit.
  await open(page, '/app/devices/new', 'New device');
  await capture(page, '08-device-form.png');

  // 9. Settings → Columns: the per-role grid layout (grid.columns), which is why
  //    the tab exists instead of the generic settings form. The list is a `<ul>`
  //    of checkboxes, not a table, so wait for its test id.
  await open(page, '/app/settings?tab=columns', 'Settings');
  await page.getByTestId('columns-list').waitFor();
  await settle(page);
  await capture(page, '09-settings-columns.png');

  // 10. Settings → Permissions: the role × capability matrix.
  await open(page, '/app/settings?tab=permissions', 'Settings');
  await page.getByRole('table').first().waitFor();
  await settle(page);
  await capture(page, '10-settings-permissions.png');

  // 11. RTL: the same grid in ar-SA. Every spacing utility is logical (`ms-*`,
  //    `text-start`), so this is the capture that proves `dir="rtl"` flips the
  //    layout instead of only the text.
  await page.goto(`${BASE}/app/devices`);
  await page.locator('table tbody tr').first().waitFor();
  await locale.selectOption('ar-SA');
  await page.locator('html[dir="rtl"]').waitFor();
  await settle(page);
  await capture(page, '11-dashboard-ar-SA.png');

  // 12. Phone width. The grid is page-flow (no inner scroll box), so a 360px
  //    capture is the honest view of what a phone user gets; switching back to
  //    en-US first leaves the stored preference in the README's language.
  await locale.selectOption('en-US');
  await page.locator('html[dir="ltr"]').waitFor();
  await page.setViewportSize(MOBILE_VIEWPORT);
  await settle(page);
  await capture(page, '12-dashboard-mobile.png');
  await page.setViewportSize(VIEWPORT);

  await browser.close();
  log('done');
}

main().catch((error) => {
  process.stderr.write(`${error.stack ?? error}\n`);
  process.exit(1);
});
