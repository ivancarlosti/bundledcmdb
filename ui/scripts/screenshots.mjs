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
 */
import { mkdir } from 'node:fs/promises';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

import { chromium } from 'playwright';

const BASE = (process.argv[2] ?? process.env.SCREENSHOTS_BASE ?? 'http://localhost:5600').replace(/\/$/, '');
const OUT = join(dirname(fileURLToPath(import.meta.url)), '..', '..', 'docs', 'screenshots');
const ADMIN = process.env.SCREENSHOTS_USER ?? 'admin@example.com';
const VIEWPORT = { width: 1440, height: 900 };

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

  await browser.close();
  log('done');
}

main().catch((error) => {
  process.stderr.write(`${error.stack ?? error}\n`);
  process.exit(1);
});
