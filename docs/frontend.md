# Front end (React SPA)

> **Status: migration in progress.** The plan, phases and acceptance criteria
> live in [`plans/2026-11-02-spa-migration.md`](../plans/2026-11-02-spa-migration.md).
> Until phase 9 the legacy PHP pages in `public/` remain the default UI; the SPA
> is served from `/app/` and can be enabled with the `ui.spa_enabled` setting.

## How the pieces fit

| Path | Responsibility |
|---|---|
| `ui/` | React 19 + TypeScript + Vite single-page app (source) |
| `public/app/` | Build output copied here by `npm run build` (git-ignored; built inside the Docker `ui` stage and shipped in the image and the release zip) |
| `public/auth.php` | Keycloak login/callback/logout (server-side; the SPA never sees a token) |
| `public/panel_api.php` | JSON API for the SPA at `/panel-api/v1/*` (session cookie + `X-CSRF-Token`) |
| `public/api.php` | Public Bearer-token API at `/api/v1/*` — unchanged, see [api.md](api.md) |
| `lib/session_auth.php` | Session bootstrap, role guards, CSRF helpers |
| `lib/panel/*` | Route handlers for the panel API (thin: validation + `lib/assets.php`) |
| `public/lang/*.php` | Translation catalogs (source of truth, served to the SPA by `GET /panel-api/v1/i18n`) |
| `ui/src/app/*` | `SessionGate` (boot/failure/login/app), `AppShell`, router |
| `ui/src/features/grid/*` | Grid state machine, URL binding, TanStack Table renderer |
| `ui/src/features/devices/*` | Device list/detail screens, column model built from `/meta` |

The SPA is same-origin with the API, so there is no CORS layer and the session
cookie works with `SameSite=Lax`.

## Local development

```bash
# 1. backend + database + (optional) minio
docker compose -f docker/docker-compose.dev.yml up -d --build
docker compose -f docker/docker-compose.dev.yml exec app php bin/migrate.php

# 2. SPA dev server (proxies /panel-api and /auth to the container)
cd ui
npm ci
npm run dev            # http://localhost:5173/app/
```

`vite.config.ts` proxies `/panel-api` and `/auth` to `http://localhost:5600`, so
cookies are set for the dev-server origin and authentication behaves like
production.

### Logging in without Keycloak

The dev compose file points Keycloak at `sso.example.com`, so the real flow
cannot complete locally. Set `APP_DEV_LOGIN=1` on the container (non-production
only) and use:

```
http://localhost:5600/auth/dev-login?email=admin@example.com
```

The endpoint only exists when `APP_DEV_LOGIN=1` **and** the app is not running
in production, and it only accepts e-mails that already exist in `users`. The dev
server (`npm run dev`) also renders a "Development sign-in" form on the login
screen; the production build hides it, because the button would only bounce off
the server-side guard.

## Panel API used by the SPA today

| Method + path | Purpose |
|---|---|
| `GET /panel-api/v1/session` | identity, CSRF token, locale/theme/page size, capabilities, grid state |
| `PATCH /panel-api/v1/session/preferences` | theme, locale, page size, grid layout |
| `POST /panel-api/v1/session/logout` | ends the session, returns the URL to navigate to |
| `GET /panel-api/v1/meta` | columns (incl. `api_key`, sortable/editable), statuses, sources, custom fields, page sizes |
| `GET /panel-api/v1/i18n` | merged catalogue for the effective (or `?locale=`) locale |
| `GET /panel-api/v1/devices` | list with filters, server-side sort, paging |
| `GET /panel-api/v1/devices/export` | CSV with the same filters/sort |
| `GET /panel-api/v1/devices/{id}` | one device + custom values + attachments |
| `POST /panel-api/v1/devices` | create (manager+), `201` + the created row |
| `POST /panel-api/v1/devices/bulk` | one operation (`status`, `byod`, `delete`, `restore`) on up to 200 ids, per-row results |
| `PATCH /panel-api/v1/devices/{id}` | partial update (manager+); `expected_updated_at` → `409 stale_record` |
| `PUT /panel-api/v1/devices/{id}` | full replace (manager+, parity with the Bearer API) |
| `DELETE /panel-api/v1/devices/{id}` | soft delete (admin+); `?hard=1` is superadmin-only |
| `POST /panel-api/v1/devices/{id}/restore` | undo a soft delete (admin+, idempotent) |
| `POST /panel-api/v1/devices/{id}/files` | multipart upload, server-side to S3 (manager+) |
| `DELETE /panel-api/v1/devices/{id}/files/{fileId}` | remove object + row (admin+) |
| `GET /panel-api/v1/settings` | every general/asset/API/storage setting, grouped, with locales/timezones and a `source` (`db`/`env`/`default`) per key |
| `PATCH /panel-api/v1/settings` | `{"values": {"general.per_page": 50}}`; `422 validation_failed` carries a code per key |
| `GET/POST /panel-api/v1/settings/sources` | source list (admin+) / create (`slug`, `label`) |
| `PATCH/DELETE /panel-api/v1/settings/sources/{slug}` | rename / delete (`409 system_source`, `409 in_use` when assets still reference it) |
| `GET/POST /panel-api/v1/settings/fields` | custom-field definitions + the type catalogue / create |
| `POST /panel-api/v1/settings/fields/reorder` | `{"ids": [3, 1, 2]}` → new display order |
| `PATCH/DELETE /panel-api/v1/settings/fields/{id}` | edit label/flags / delete (drops the stored values) |
| `GET/POST /panel-api/v1/settings/api-keys` | key list / create; the plaintext token is returned **once** in `meta.token` |
| `PATCH/DELETE /panel-api/v1/settings/api-keys/{id}` | edit label/scopes/allow-list / revoke |
| `POST /panel-api/v1/settings/api-keys/{id}/rotate` | new secret, same row; the old token stops working |
| `GET /panel-api/v1/settings/users` | roster with roles and `is_self` (superadmin only) |
| `PATCH /panel-api/v1/settings/users/{email}` | change a role; changing your own is `409 self_role_change` |
| `GET /panel-api/v1/settings/maintenance` | migration status, PHP/DB/encryption diagnostics, cron hints |
| `POST /panel-api/v1/settings/maintenance/migrate` | runs the migration runner; `{"dry_run": true}` is the default |

Writes go through the same helpers as `public/api.php` (`asset_validate()`,
`asset_create/update/soft_delete/restore/hard_delete()`,
`custom_values_save()`), so the two APIs cannot disagree about what a valid
device is. Role rules come from the server: `/meta.writeColumns` lists the keys
the signed-in role may send, and the API rejects everything else with `422`.

The settings handlers (`lib/panel/settings.php`) are thin wrappers over the same
model helpers the legacy pages at `/settings` use (`settings_set()`,
`source_create/update/delete()`, `field_*`, `api_key_*`, `migrate_run()`), so the
two front ends cannot drift. Secrets (keys flagged `secret`, i.e.
`s3.secret_key`, and the API key hashes) never leave the server: they come back
as an empty `value` plus `configured: true`, sending an empty string back means
"unchanged", and a deliberate wipe is `{"clear": ["s3.secret_key"]}`.

### Bulk actions

Selecting rows adds a checkbox column and a toolbar. The selection is a list of
ids held by `DeviceGridPage` (it survives paging, and is cleared by "Clear
selection", by "Clear filters", and once an action succeeded), and it is sent as
**one** `POST /devices/bulk` request:

```json
{"ids": [7, 8], "op": "status", "value": "In Stock"}
{"ids": [7, 8], "op": "delete"}
{"ids": [7, 8], "op": "restore"}
```

The server writes each row through the same single-row helpers in its own
transaction, so a stale, already-deleted or out-of-company row is *skipped* (with
a `code` and `message`) instead of aborting the whole action; the grid reports
`ui_bulk_partial` ("2 updated, 1 skipped") plus the first reason. `status`/`byod`
need manager+, `delete`/`restore` admin+, `hard` superadmin. The shared value is
validated once (`422 value: invalid_status`), and more than 200 ids is `422
ids: too_many`.

## Scripts

| Command | Purpose |
|---|---|
| `npm run dev` | Vite dev server with HMR and API proxy |
| `npm run build` | Type-check + production build into `public/app/` |
| `npm run typecheck` | `tsc -b --noEmit` |
| `npm run lint` | ESLint (flat config) |
| `npm run format` | Prettier check (use `--write` to fix) |
| `npm run test` | Vitest (jsdom) |
| `npm run e2e` | Playwright (`vite preview` on :4173, panel API stubbed per test) |

PHP side (the API itself):

```bash
php bin/check-i18n.php          # catalog coverage + unknown keys
php bin/test-panel-api.php      # integration tests: role matrix, validation,
                                # optimistic locking, delete/restore, isolation
```

`bin/test-panel-api.php` drives the real handlers against the configured
database and hard-deletes everything it creates, so it is safe to run against a
development database. It refuses to run with `APP_ENV=production`.

CI: `.github/workflows/ui.yml` runs the SPA gates (`typecheck`, `lint`, `vitest`,
`build`, then Playwright against `vite preview`) for changes under `ui/`,
`public/lang/` and `lib/panel/`, and uploads the built `public/app` bundle. The
release ZIP (`.github/workflows/build.yml`) builds the SPA before zipping, so a
zip deployment does not need Node.

## Conventions

* **Server owns the truth.** Sorting, filtering, paging and CSV export run in
  SQL; the grid never sorts a full dataset in the browser.
* **All network access goes through `src/api/client.ts`** — it adds the CSRF
  header, maps error envelopes to typed errors and handles `401` by clearing the
  session query.
* **URL is the state.** `?sort=&dir=&page=&per_page=&q=&source=&status=&company=&byod=&trashed=`
  is written with `history.replaceState` on every change, so a reload, a shared
  link and back/forward all reproduce the view (see `features/grid/gridSearch.ts`).
* **Column layout is server-side.** The `Columns` picker writes `grid_state`
  (`order`, `visible`) through `PATCH /session/preferences`; `applyColumnLayout()`
  merges it with what `/meta` publishes, so a stored layout can never hide a
  column the server stopped sending or lose a new one. Reordering is done with
  `Move up` / `Move down` buttons, which keeps the picker keyboard-only.
* **Writes are optimistic-locked.** `PATCH` carries `expected_updated_at`; a
  `409 stale_record` leaves the form untouched, shows a "reload before saving"
  alert and refreshes the cached row instead of overwriting someone else's edit.
  Inline grid edits (Status, BYOD) use the same contract.
* **Forms are generated from `/meta`.** `writeColumns.create|update` decide which
  inputs exist for the current role — a manager's form has no `cypher_id` field,
  and the API would reject it anyway.
* **Sorting cycle:** unsorted → `asc` → `desc` → back to the server default
  (`updated_at DESC`, which leaves `sort` out of the URL). Every sort or filter
  change resets the page. `/meta` says which columns are sortable and what their
  `api_key` is; the server rejects an unknown `sort` with `400 invalid_sort`
  instead of silently falling back.
* **No hard-coded strings** in components — every label comes from `t()` with a
  key that exists in all seven catalogs (the `check-i18n` gate enforces it).
* **Logical CSS only** (`ms-`, `me-`, `ps-`, `pe-`, `start-`, `end-`) so `ar-SA`
  RTL works without a second stylesheet.
* **One theme mechanism**: `data-theme` on `<html>`; Tailwind's `dark:` variant
  is bound to that attribute in `styles/tokens.css`.
* **Accessibility is not optional**: real table semantics, `aria-sort`,
  keyboard-operable column picker (checkbox + move buttons), an `aria-live`
  result summary, visible focus, AA contrast in both themes.
* **Failures are visible.** A render error is caught by `app/ErrorBoundary.tsx`
  (message + retry + a way back) instead of blanking the mount point, and every
  mutation reports its outcome through a toast.

## How translations reach the SPA

The SPA ships **no** translation bundle. On boot (and on a language switch) it
calls `GET /panel-api/v1/i18n`, which returns the requested locale merged over
`en-US`, plus the writing direction. `src/i18n/index.ts` registers that as an
i18next resource bundle and sets `lang`/`dir` on `<html>`.

## Adding a UI string

1. Add the key to `public/lang/en-US.php`.
2. Add the same key (translated, or empty to fall back to en-US) to the other
   `public/lang/*.php` catalogs; `php bin/check-i18n.php` reports the coverage
   per locale and fails on unknown keys.
3. Use it from a component with `t('the_key')`.

No build step and no generated JSON: the catalog is fetched at runtime, so a
missing translation can never be caused by a stale export.
