# Front end (React SPA)

> **Status: the SPA is the only UI since 11.0.0.** `/` redirects to `/app/`, and
> the legacy PHP pages (`main.php`, `settings.php`, `manage_permissions.php`,
> `asset*.php`, `save_row*.php`, `export.php`, `style.css`, `theme.js`) no longer
> exist — nginx answers 404 for anything that is not one of the four entry
> points. 10.8.0 shipped the default plus the `ui.spa_enabled = 0` rollback;
> 11.0.0 removed both. To go back now, redeploy the 10.x image.
> See [`plans/2026-11-02-spa-migration.md`](../plans/2026-11-02-spa-migration.md).

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
| `ui/src/features/settings/*` | `/app/settings` screens (one tab per settings group, sources, custom fields, API keys, users, maintenance) |

The SPA is same-origin with the API, so there is no CORS layer and the session
cookie works with `SameSite=Lax`.

## Cutover (phase 9)

`/` and `/index.php` are entry points, not pages: a browser without a session
lands on the SPA's own login screen, and a browser with one lands on the grid.

| URL | Effect |
|---|---|
| `/`, `/index.php` | `302` to `/app/`; `/index.php` also keeps the legacy `?code=` SSO callback and `?action=logout` |
| `/app/*` | The SPA (history fallback to `/app/index.html`) |
| `/auth/login`, `/auth/callback`, `/auth/logout`, `/auth/dev-login` | SPA authentication (`public/auth.php`) |
| `/panel-api/v1/*`, `/api/v1/*` | Panel and public APIs |
| anything else ending in `.php` | `404` — only the entry points above execute PHP |

The legacy pages (`main.php`, `settings.php`, `manage_permissions.php`,
`asset*.php`, `save_row*.php`, `export.php`, `style.css`, `theme.js`) and their
helpers (`lang_selector()`, `csrf_field()`, `lib/*_ui.php`, …) were deleted in
11.0.0, together with the `ui.spa_enabled` switch (migration
`0008_retire_spa_flag` deletes the stored row). There is no in-app rollback any
more: the rollback is redeploying the previous 10.x image, which still contains
both UIs.

A failed legacy SSO callback is not rendered as a PHP page: `/index.php`
redirects to `/app/?auth_error=<code>` (`not_authorized`, `userinfo_failed`,
`token_failed`, `server_error`) and `LoginScreen` shows the localised message —
covered by `e2e/smoke.spec.ts`.

## Local development

```bash
# 1. backend + bundled database
cp docker/.env.example docker/.env   # once; APP_ENCRYPTION_KEY + Keycloak values
docker compose -f docker/docker-compose-bundle.yml up -d --build
docker compose -f docker/docker-compose-bundle.yml exec app php bin/migrate.php

# 2. SPA dev server (proxies /panel-api and /auth to the container)
cd ui
npm ci
npm run dev            # http://localhost:5173/app/
```

`vite.config.ts` proxies `/panel-api` and `/auth` to `http://localhost:5600`, so
cookies are set for the dev-server origin and authentication behaves like
production.

### Logging in without Keycloak

The bundle compose file points Keycloak at `sso.example.com`, so the real flow
cannot complete locally. Put `APP_ENV=development` and `APP_DEV_LOGIN=1` in
`docker/.env` (the compose defaults are production-safe and leave it off) and
use:

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
| `GET /panel-api/v1/meta` | columns (incl. `api_key`, sortable/editable, `in_grid` from the admin layout), `gridLayout` (locked + order), statuses, sources, custom fields, page sizes |
| `GET /panel-api/v1/i18n` | merged catalogue for the effective (or `?locale=`) locale |
| `GET /panel-api/v1/views` | the signed-in user's saved grid presets (filters + sort + column layout) |
| `POST /panel-api/v1/views` | save a view; the same name overwrites it (`201`, same id) |
| `PATCH/DELETE /panel-api/v1/views/{id}` | rename/replace the state / delete (404 outside your own views) |
| `GET /panel-api/v1/devices` | list with filters, server-side sort, paging |
| `GET /panel-api/v1/devices/export` | CSV (`?format=csv`, default) or native `.xlsx` (`?format=xlsx`) with the same filters/sort |
| `GET /panel-api/v1/devices/{id}` | one device + custom values + attachments |
| `POST /panel-api/v1/devices` | create (manager+), `201` + the created row |
| `POST /panel-api/v1/devices/bulk` | one operation (`status`, `byod`, `delete`, `restore`) on up to 200 ids, per-row results; delete takes an optional `reason` |
| `PATCH /panel-api/v1/devices/{id}` | partial update (manager+); `expected_updated_at` → `409 stale_record` |
| `PUT /panel-api/v1/devices/{id}` | full replace (manager+, parity with the Bearer API) |
| `DELETE /panel-api/v1/devices/{id}` | soft delete (admin+) with `?reason=duplicated` or `?reason=created_by_mistake`, plus `?duplicate_of=<id>` for a duplicate; `?hard=1` is superadmin-only and takes no reason |
| `POST /panel-api/v1/devices/{id}/restore` | undo a soft delete (admin+, idempotent) |
| `GET /panel-api/v1/devices/{id}/events` | audit trail (who changed what, `[old, new]` per column), newest first |
| `POST /panel-api/v1/devices/{id}/files/presign` | `{mode: "s3", url, key, headers}` for a direct PUT, or `{mode: "server"}` when the driver is `local` |
| `POST /panel-api/v1/devices/{id}/files` | multipart upload through `lib/storage.php`, **or** (JSON body) the registration of a direct upload (`key` must live under `assets/<id>/` and exist) |
| `GET /panel-api/v1/devices/{id}/files/{fileId}/download` | `302` to a presigned S3 URL, or the file streamed for `storage.driver = local` |
| `DELETE /panel-api/v1/devices/{id}/files/{fileId}` | remove object + row (admin+) |
| `GET /panel-api/v1/settings` | every general/asset/API/storage setting, grouped, with locales/timezones and a `source` (`db`/`env`/`default`) per key |
| `PATCH /panel-api/v1/settings` | `{"values": {"general.per_page": 50}}`; `422 validation_failed` carries a code per key |
| `GET/POST /panel-api/v1/settings/sources` | source list (admin+) / create (`slug`, `label`) |
| `PATCH/DELETE /panel-api/v1/settings/sources/{slug}` | rename / delete (`409 system_source`, `409 in_use` when assets still reference it) |
| `GET/POST /panel-api/v1/settings/fields` | custom-field definitions + the type catalogue / create |
| `POST /panel-api/v1/settings/fields/reorder` | `{"ids": [3, 1, 2]}` → new display order |
| `PATCH/DELETE /panel-api/v1/settings/fields/{id}` | edit label/flags / delete (drops the stored values) |
| `GET/POST /panel-api/v1/settings/api-keys` | key list / create; the plaintext token is returned **once** in `meta.token` |
| `PATCH/DELETE /panel-api/v1/settings/api-keys/{id}` | edit label/scopes (read, write, delete, users)/allow-list / revoke |
| `POST /panel-api/v1/settings/api-keys/{id}/rotate` | new secret, same row; the old token stops working |
| `GET /panel-api/v1/settings/users` | roster with roles and `is_self` (superadmin only) |
| `PATCH /panel-api/v1/settings/users/{email}` | change a role; changing your own is `409 self_role_change` |
| `GET /panel-api/v1/settings/maintenance` | migration status, PHP/DB/encryption diagnostics, cron hints |
| `POST /panel-api/v1/settings/maintenance/migrate` | runs the migration runner; `{"dry_run": true}` is the default |
| `GET /panel-api/v1/logs` | audit rows (admin+), filters `q`/`action`/`entity`/`actor`/`company`/`from`/`to`/`page`/`per_page`; `meta` carries the filter options, retention and last purge |
| `POST /panel-api/v1/logs/purge` | superadmin: `{"before": "YYYY-MM-DD", "dry_run": true}` deletes (or counts) everything older than that day |
| `GET /panel-api/v1/users` | directory for the device form's company-filtered e-mail dropdown (manager+; a company-bound caller always sees its own company) |

Every mutation (`POST`/`PATCH`/`PUT`/`DELETE`) is counted against
`api.panel_rate_limit` (default 120 per user per minute, fixed window in
`lib/rate_limit.php`, one upsert per call). Over the limit the envelope is
`429 rate_limited` with a `Retry-After` header; reads are never counted.

Writes go through the same helpers as `public/api.php` (`asset_validate()`,
`asset_create/update/soft_delete/restore/hard_delete()`,
`custom_values_save()`), so the two APIs cannot disagree about what a valid
device is. Role rules come from the server: `/meta.writeColumns` lists the keys
the signed-in role may send, and the API rejects everything else with `422`.

**Deleting asks why.** The row survives in the trash, but "deleted" cannot answer
the question an auditor asks next, so a soft delete stores one of
`asset_deletion_reasons()` in `assets.deleted_reason` (`duplicated`,
`created_by_mistake`) and, for a duplicate, the kept asset in
`assets.duplicate_of_id`. The SPA requires the reason for a single delete
(`DeleteReasonFields.tsx`, with `DuplicatePicker.tsx` searching `/devices?q=` and
excluding the row being deleted) and leaves it optional in the bulk toolbar; the
API validates whatever it is given (`422 invalid_reason`, `duplicate_of`:
`not_found`, `self`, `deleted`, `reason_not_duplicated`), records it as the
`[old, new]` diff of the `delete` audit event, exposes it in
`GET /devices/{id}` (plus a resolved `duplicate_of` reference) and clears both
columns on restore. A hard delete nulls the references pointing at the row it
drops. Options for the picker come from `/meta.deleteReasons`, so a new reason is
one array entry in `lib/assets.php` plus one translation key per locale.

**CSRF has a retry.** `session_login()` rotates `csrf_token` with the session id,
so a tab that was open before the login keeps a stale copy — the request had the
header, the server just disagreed, and the user saw "Invalid or missing CSRF
token." until a reload. `src/api/client.ts` now fetches `GET /session` when a
write has no token at all and, on `419 invalid_csrf`, refreshes once and replays
the request (a second failure is thrown, never looped). `client.test.ts` pins all
three paths down.

The settings handlers (`lib/panel/settings.php`) are thin wrappers over the model
helpers (`settings_set()`, `source_create/update/delete()`, `field_*`,
`api_key_*`, `migrate_run()`), which are the same functions `bin/migrate.php` and
the Bearer API use — no rule lives in two places. Secrets (keys flagged `secret`,
i.e. `s3.secret_key`, and the API key hashes) never leave the server: they come
back as an empty `value` plus `configured: true`, sending an empty string back
means "unchanged", and a deliberate wipe is `{"clear": ["s3.secret_key"]}`.

## Settings screens

`/app/settings` is one screen with tabs, and the tab lives in the URL
(`?tab=general|storage|sources|api|fields|maintenance|users`) so it can be
bookmarked and survives a reload. The link in the header and the `users` tab are
gated by the capabilities `GET /session` publishes (`can_admin` opens the screen,
`can_superadmin` adds the roster) — and the server enforces the same matrix, so a
hidden tab is never a security boundary on its own.

The five behaviours worth knowing when you touch this screen:

* **Cards are server-side groups.** `general`, `assets`, `api`, `storage` and
  `s3` are rendered as separate cards with their own `set_group_*` heading;
  Storage shows **Attachment storage** and **S3 connection** as two cards in one
  tab. `sortSettings()` puts every boolean first while keeping the server order
  inside each block, and each checkbox uses the setting's explicit **Enable …**
  label instead of a generic "Yes".
* **Secrets blank means unchanged.** `settingsFormFrom()` starts every `secret`
  input empty and `buildSettingsPatch()` only includes it when it has text;
  wiping a stored secret is an explicit `clear` entry, never an empty value.
  `settingsForm.test.ts` pins that down.
* **Errors land on the input.** A `422 validation_failed` carries a code per
  setting key / field name; the form maps it through `settingsErrorKey()` to a
  `set_err_*` string (unknown codes are shown verbatim) instead of a single
  banner.
* **Writes seed the cache.** Every settings mutation answers the refreshed list
  and the hook writes it into the query cache, so nothing re-fetches and the
  stale value cannot flash back (the same rule as the device writes).
* **The user roster filters on the client.** `GET /settings/users` already
  returns the full list; the search box matches e-mail, company and role, the
  company `<select>` includes a "No company" option, and the summary line stays
  in sync through `aria-live`.

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

### Saved views

`SavedViewsMenu` sits in the grid toolbar (a keyboard-operable `<details>`, the
same pattern as the column picker). It lists the signed-in user's presets from
`GET /panel-api/v1/views` and saves the current state — filters, sort,
direction and the column `order`/`visible` — under a name. The same name
overwrites the row (`POST` answers `201` with the same id), which is the gesture
a toolbar should have; deleting asks for confirmation first. Views belong to one
e-mail: somebody else's id is `404`, not `403`, so the id space leaks nothing.

Applying a view does not re-run the query by itself: it hands the stored state
back to the grid, which writes it into the URL like any other filter change, so
the result is shareable and survives a reload.

### History and attachments

The detail page has two cards under the form:

* **History** — `GET /devices/{id}/events`, newest first: `ui_ev_<action>`, the
  actor, the timestamp and the `{column: [old, new]}` pairs from
  `asset_events.changes`. Saving the whole form therefore lists only the columns
  that actually moved (`asset_event_diff()` ignores `updated_at`/`updated_by`).
  An empty history is a normal state, not an error.
* **Attachments** — list, download, remove and upload. Upload asks
  `POST /devices/{id}/files/presign` first: with `storage.driver = s3` the file
  goes **straight to the bucket** through an `XMLHttpRequest` PUT — that is what
  makes the percentage next to `ui_uploading` possible, since `fetch` cannot
  report upload progress — and the panel API is called once afterwards to
  register the key. With the `local` driver the server answers
  `{mode: "server"}` and the same control posts the multipart form, so the screen
  does not care which driver is configured.
  A failed PUT is not fatal: the browser may be unable to resolve `s3.endpoint`
  (`http://minio:9000` only exists inside the Docker network) or the bucket may
  lack a CORS rule for the panel origin. The SPA then tries to register the key —
  the object often did land — and only falls back to the multipart upload when
  that registration is refused, so a bucket reachable solely from the server
  still works. The toast carries the transport message instead of a bare
  "Could not upload the file." (`messageOf()` in `useDeviceMutations.ts`).
  `meta.limits.maxUploadBytes` (100 MiB) is the ceiling for both drivers.

## Scripts

| Command | Purpose |
|---|---|
| `npm run dev` | Vite dev server with HMR and API proxy |
| `npm run build` | Type-check + production build into `public/app/` |
| `npm run typecheck` | `tsc -b --noEmit` |
| `npm run lint` | ESLint (flat config) |
| `npm run format` | Prettier check (`.prettierrc.json`: single quotes, 120 columns; `--write` fixes) |
| `npm run test` | Vitest (jsdom) |
| `npm run e2e` | Playwright (`vite preview` on :4173, panel API stubbed per test) |

PHP side (the API itself):

```bash
php bin/check-i18n.php          # catalog coverage, unknown keys and dead keys
php bin/check-schema.php        # sql/schema.sql ↔ lib/migrate.php (no database)
php bin/test-panel-api.php      # integration tests: role matrix, validation,
                                # optimistic locking, delete/restore, isolation
php bin/bench-devices.php       # EXPLAIN + timings on a throwaway 100k fixture
                                # (see docs/api.md "Measured cost at 100k rows")
```

`bin/test-panel-api.php` drives the real handlers against the configured
database and hard-deletes everything it creates, so it is safe to run against a
development database. It refuses to run with `APP_ENV=production`. The route
table and `panel_request_path()` are asserted before the database check, so a
broken route ordering still fails the gate on a machine without MySQL.

SPA gates run from the repo root, without GitHub Actions:
`./bin/check-ui.sh` installs (`npm ci`), then runs `typecheck`, `lint`, `format`,
`test` and `build`; `--e2e` also runs Playwright against `vite preview` and
`--no-install` skips the install. The build writes `public/app/`, which the
release ZIP (`.github/workflows/build.yml`) and the Dockerfile produce
themselves, so a zip or image deployment does not need Node.

The Playwright suite is **hermetic**: every `/panel-api/v1/*` call is stubbed per
test (`e2e/fixtures.ts` — `mockPanel()` for a signed-in role, `mockAnonymous()`
for the 401 shell), because the runner has no PHP backend and an unstubbed
request would come back as a 502 from the preview proxy. A download started by
an `<a href>` is the one exception — `page.route()`/`context.route()` do not see
it, so the CSV test asserts the URL instead of the body. Under CI the reporter
is `github`, which turns a failure into an annotation on the commit (readable
without an authenticated job log) and `webServer.stdout/stderr` are piped so
Vite's own errors show up in the job output.

### Screenshots

`node ui/scripts/screenshots.mjs [baseUrl]` re-captures every image in
`docs/screenshots/` (default `http://localhost:5600`). It is *not* part of the
gates: it drives a real stack — the bundle compose file with
`APP_ENV=development`, `APP_DEV_LOGIN=1`, `APP_DEFAULT_LOCALE=en-US` (the script's
waits match English labels) and `sql/dev-seed.sql` loaded — signs in through the
development login and writes the twelve PNGs `README.md` references, so a UI
change is refreshed with one command instead of hand-cropped pictures. Run
`npx playwright install chromium` once if the browser is missing.

## Conventions

* **Server owns the truth.** Sorting, filtering, paging and the CSV/Excel export
  run in SQL; the grid never sorts a full dataset in the browser.
* **The grid is page-flow.** `DataGrid` contains only the horizontal axis
  (`overflow-x-auto`), so a wide table never scrolls the page sideways while a
  long one is read by scrolling the page itself — no scroll box inside a scroll
  page. Only paging brings the grid back into view (`scroll-mt-24` clears the
  sticky header), because a new page otherwise starts where the old one ended.
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
  `/meta.columns` itself is already the *installation* layout (the `grid.columns`
  setting behind Settings → Columns): `in_grid` decides what exists and the array
  order is the default order. `effectiveLayout()` drops the per-user layout when
  `/meta.gridLayout.locked` is true, so a locked layout wins for order and
  visibility and the picker renders disabled.
* **Writes are optimistic-locked.** `PATCH` carries `expected_updated_at`; a
  `409 stale_record` leaves the form untouched, shows a "reload before saving"
  alert and refreshes the cached row instead of overwriting someone else's edit.
  Inline grid edits (Status and boolean columns) use the same contract; both
  render as a `<select>` so Yes/No is explicit and needs no keyboard-only
  checkbox handling.
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
