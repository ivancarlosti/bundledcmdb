# Plan: Screenshots & README Documentation

## Overview

Add real UI screenshots to the repository so the README shows what Bundled CMDB
looks like before a reader deploys it. Images live in `docs/screenshots/` and
are referenced from `README.md` with relative links (GitHub renders them in the
repository view, the release page and the Pages/docs contexts).

The screenshots were captured from the PHP 8.5 image produced by
[`2026-10-23-php-8.5-upgrade.md`](2026-10-23-php-8.5-upgrade.md), against a
throwaway MariaDB container seeded with demo data — no production data, no real
user e-mails, no customer names.

## Screenshot inventory

| File | Page | State |
|------|------|-------|
| `docs/screenshots/01-login.png` | `public/index.php` | Light theme, pt_BR |
| `docs/screenshots/02-dashboard-light.png` | `public/main.php` | Light theme, pt_BR, SuperAdmin |
| `docs/screenshots/03-dashboard-dark.png` | `public/main.php` | Dark theme, pt_BR, SuperAdmin |
| `docs/screenshots/04-dashboard-es-MX.png` | `public/main.php` | Light theme, es_MX |
| `docs/screenshots/05-asset-detail.png` | `public/asset.php?id=1` | Light theme, pt_BR |
| `docs/screenshots/06-manage-permissions.png` | `public/manage_permissions.php` | Light theme, pt_BR |

Format: PNG rendered by headless Chrome at a 1440 px viewport width with
`deviceScaleFactor: 1`:

| File | Size | Notes |
|------|------|-------|
| `01-login.png` | 1440×320 | top crop of the full-window login page |
| `02-dashboard-light.png` | 1440×981 | full page, includes the save bar and pagination |
| `03-dashboard-dark.png` | 1440×981 | full page |
| `04-dashboard-es-MX.png` | 1440×981 | full page |
| `05-asset-detail.png` | 1440×1792 | full page, includes the attachments card |
| `06-manage-permissions.png` | 1440×900 | viewport |

All files are below 200 KB. PNGs are committed exactly as Chrome encodes them
(lossless, no re-compression) so the images stay crisp on HiDPI displays.

## How the screenshots are produced

1. **Database** — start a disposable `mariadb:11.4` container with a random
   name and host port, then create the app schema:
   - `assets` (`Id`, `UUID`, `SN`, `OS`, `OSVersion`, `Hostname`, `Mobile`,
     `Manufacturer`, `Term`, `LastSeen`, `UserEmail`, `BYOD`, `Status`,
     `Warranty`, `Asset`, `PurchaseDate`, `CypherID`, `CypherKey`, `Notes`,
     `CPUs`, `HDs`, `HDsTypes`, `HDsSpacesGB`, `NetworkAdapters`,
     `MACAddresses`, `ESETComponents`, `PrimaryLocalIP`, `PrimaryRemoteIP`,
     `company`)
   - `users` (`email`, `company`, `role`)
   - `device_files` (`id`, `device_id`, `device_table`, `file_path`,
     `file_name`, `mime_type`, `size`)
2. **Demo data** — a handful of laptops/desktops with realistic serial numbers,
   OS versions, statuses, warranty/purchase dates, two companies and three
   roles (superadmin / admin / user), plus a couple of `device_files` rows so
   the `Term` column shows file counts.
3. **Application** — run the built image (`bundledcmdb:php85`) with the DB and
   dummy S3/Keycloak environment variables. A `dev-login.php` shim is mounted
   read-only from `/tmp` (never committed) that seeds `$_SESSION` and redirects
   to the page under test, so Keycloak SSO is bypassed.
4. **Capture** — headless Chrome via `puppeteer-core` (installed in `/tmp`,
   not in the repo): log in through the shim, then for each shot set
   `?lang=…`, toggle the theme through `localStorage['cmdb-theme']`, wait for
   fonts/flags, and call `page.screenshot()`.
5. **Cleanup** — remove containers, temp files and the shim; commit only the
   PNGs.

## README integration

The section is inserted **before** the `<!-- footer -->` marker:

```markdown
## 📸 Screenshots

| Asset dashboard (light) | Asset dashboard (dark) |
|---|---|
| ![Asset dashboard in light theme](docs/screenshots/02-dashboard-light.png) | ![Asset dashboard in dark theme](docs/screenshots/03-dashboard-dark.png) |

### Login with Keycloak SSO
![Login screen with Keycloak SSO button](docs/screenshots/01-login.png)

### Asset detail and file attachments
![Asset detail page with editable fields and attachments](docs/screenshots/05-asset-detail.png)

### Manage permissions
![SuperAdmin permission management page](docs/screenshots/06-manage-permissions.png)
```

This placement matters: `.github/workflows/update-readme.yml` rewrites the
buttons block and everything from `<!-- footer -->` onward on a daily schedule.
Anything above the footer marker survives that job, anything below it does not.

Each image gets descriptive alt text (accessibility + GitHub's broken-image
fallback) and a short caption describing the page and its state.

## Captured output

- All six PNGs were generated on 2026-10-23 from the `bundledcmdb:php85` image
  (PHP 8.5.12) against a MariaDB 11.4.13 container, with no page or console
  errors reported by Chrome.
- Widths are 1440 px; file sizes range from 16 KB to 188 KB.
- The temporary `dev-login.php` shim, the seed SQL and the Puppeteer script were
  deleted after the capture; only the PNGs are committed.
- Dashboards use `fullPage` so the save bar and pagination stay visible; the
  login page is cropped to its top 320 px because the rest of that page is
  empty background.

## Packaging notes

- `docs/` and `plans/` are added to `.dockerignore` so they never end up in the
  runtime image.
- `.github/workflows/build.yml` is intentionally **not** touched in this change,
  so the release ZIP may still contain `docs/` and `plans/`. Excluding them from
  the ZIP is a separate, optional follow-up.
- Screenshots are documentation-only assets: they are not loaded by the app at
  runtime, so no PHP/CSS changes are required.

## Verification

1. `file docs/screenshots/*.png` reports the expected dimensions.
2. `du -h docs/screenshots/*.png` stays within the ~300 KB budget per file.
3. `grep -o 'docs/screenshots/[^)]*' README.md | sort -u` matches the files
   present on disk (no broken links).
4. Render `README.md` locally (e.g. `npx markdown-it README.md > /tmp/out.html`
   or GitHub preview) and confirm both tables and figures display.
