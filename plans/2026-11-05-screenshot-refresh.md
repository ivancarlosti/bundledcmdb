# Plan: Screenshot refresh (twelve images, repeatable)

## Context

`docs/screenshots/` still held the six images captured for the legacy PHP panel
(`plans/2026-10-23-readme-screenshots.md`: pt_BR strings, `manage_permissions.php`,
screens that no longer exist). The SPA replaced those pages
(`plans/2026-11-02-spa-migration.md`), the grid became page-flow (it no longer
scrolls inside a height-capped box), and screens the README never showed —
audit log, create form, grid columns, role capabilities, RTL, phone width — are
the ones a reader needs to judge the panel.

Two blockers showed up while re-capturing:

* `ui/scripts/screenshots.mjs` only wrote the original six files, so a manual
  capture would be lost on the next UI change.
* the audit log renders `asset_events`, which nothing seeded, so the Logs screen
  was an empty table on a demo stack.

## Goal

Twelve screenshots in `docs/screenshots/`, all produced by one command against a
dev stack seeded with `sql/dev-seed.sql`, all referenced from `README.md`.

| File | Screen |
|---|---|
| `01-login.png` | anonymous `/` |
| `02-dashboard-light.png` | devices grid, light, en-US |
| `03-dashboard-dark.png` | devices grid, dark, en-US |
| `04-dashboard-es-MX.png` | devices grid, light, es-MX |
| `05-asset-detail.png` | `/app/devices/:id`, light, en-US |
| `06-settings-users.png` | `settings?tab=users` |
| `07-audit-log.png` | `/app/logs` with real diffs |
| `08-device-form.png` | `/app/devices/new` |
| `09-settings-columns.png` | `settings?tab=columns` (grid layout) |
| `10-settings-permissions.png` | `settings?tab=permissions` (capability matrix) |
| `11-dashboard-ar-SA.png` | devices grid, ar-SA (`dir="rtl"`) |
| `12-dashboard-mobile.png` | devices grid at 360×740 |

## Steps

1. `ui/scripts/screenshots.mjs`:
   * header comment lists the twelve files and the superadmin assumption;
   * `MOBILE_VIEWPORT` (360×740) for the phone capture;
   * `open(page, path, heading)` waits for the screen's own `<h1>` before
     `networkidle`, so a lazy chunk cannot be captured half-mounted;
   * steps 7–12 added: audit log, create form, columns, permissions, ar-SA
     (waiting on `html[dir="rtl"]`), then back to en-US and the phone viewport,
     which leaves the stored preference in the README's language.
2. `sql/dev-seed.sql`: seven `asset_events` rows (device create/update/attachment,
   setting change, role change, API key rotation, log purge) using the real
   `entity`/`action` vocabulary from `lib/asset_events.php` and the DB column
   names in `changes`, so both the audit log and the device History card have
   content. Device rows link through `(SELECT Id FROM assets WHERE SN = …)`, so
   the seed stays independent of auto-increment values.
3. Re-capture everything from the bundle stack (`docker compose
   -f docker/docker-compose-bundle.yml up -d --build`, `php bin/migrate.php`,
   the seed, then `node ui/scripts/screenshots.mjs`) — the image builds the SPA
   from the working tree, so the captures show the current code. The stack runs
   with `APP_ENV=development`, `APP_DEV_LOGIN=1` and `APP_DEFAULT_LOCALE=en-US`
   (the script waits on English labels) via a throwaway `--env-file`, so no
   `docker/.env` is needed for a one-off capture.
4. `README.md`: the screenshot section grows to the new screens (two-column
   tables where a pair belongs together, the phone capture constrained with
   `<img width="340">`); `docs/frontend.md` documents the command and that it is
   not a gate; `docs/development.md` mentions the seeded audit rows.

## Risks

* **Writes to a demo database.** The audit rows are seed data, not something the
  script writes: the capture run is read-only, so pointing it at a stack with
  real data cannot record an edit.
* **Fragile waits.** Every new capture waits on structure (h1, `columns-list`,
  `html[dir]`, a table) rather than a timeout, so a re-render cannot be
  photographed mid-flight; the cost is a 30 s timeout if a screen is renamed.
* **RTL is only half translated.** `ar-SA` covers 51 % of the catalogue, so the
  Arabic capture mixes translated labels with English fallbacks (`Audit log`).
  It is captured on purpose: it shows `dir="rtl"` flipping the layout, not the
  coverage.
* **Data-scoped counts.** `admin@example.com` is an Acme superadmin, so the grid
  shows 2 of the 3 seeded devices (the Globex row is out of scope). A future
  capture from `other@example.com` would need its own file.
