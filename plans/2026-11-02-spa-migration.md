# Plan: SPA Migration (React 19 + TypeScript + Vite)

> **This file is the single source of truth for the migration.** Every phase
> starts by re-reading it. Update it at the end of each phase (mark progress,
> record deviations in §17) so context is never lost.
>
> Status: **in progress** — phase 0/1 (plan + session auth layer).

## 1. Goals

1. **Stop losing features on every refactor.** Commit `2279453` (device API +
   settings) silently removed/broke table sorting, column ordering, dark mode,
   the language selector and the toolbar layout. The new UI must make those
   behaviours explicit, tested and hard to regress.
2. **Move the UI to a real front-end**: React 19 + TypeScript + Vite SPA served
   from `/app/`, talking to a same-origin JSON API. PHP keeps doing what it is
   good at: auth, SQL, S3, validation — it stops rendering HTML tables.
3. **Keep every existing contract.** `public/api.php` (`/api/v1/*`, Bearer
   token, documented in `docs/api.md`) must not change. Legacy panel pages stay
   reachable until the SPA reaches parity, then are removed in a breaking
   release.
4. **Restore the lost UX** (§5 grid spec): sortable **every** column including
   `SN`, `source` and attachments; user-arrangeable columns persisted
   server-side; working light/dark/system theme; 7 locales + RTL; a toolbar that
   does not overlap page actions; pagination that keeps its sort/filter state.
5. **Server-side truth.** Sorting, filtering, paging and CSV export are computed
   by SQL, not in the browser, so 100k-row installations stay fast and the API
   stays the single implementation.

## 2. Current state (baseline, verified on `main` @ `27e0276`)

| Area | Today |
|---|---|
| Runtime | PHP 8.5 + nginx, single image; `nginx.conf` generated inside `Dockerfile` |
| Front end | Plain PHP pages in `public/` (`index.php`, `main.php`, `asset.php`, `asset_new.php`, `settings.php`, `manage_permissions.php`, `export.php`) + `style.css` (≈2.3k lines) + `theme.js` |
| Data layer | PDO/MySQL, `lib/assets.php` (CRUD, `asset_list()` with search/sort/paging), `lib/asset_fields.php` (EAV custom fields), `lib/sources.php`, `lib/settings.php`, `lib/migrate.php` |
| Public API | `public/api.php` → `/api/v1/*`, Bearer keys (`lib/api_auth.php`), documented in `docs/api.md` |
| Auth | Keycloak OIDC **server-side** in `index.php` + `auth_keycloak.php`; `$_SESSION['user_email'|'company'|'role']` |
| i18n | `public/lang.php` + `public/lang/<code>.php` (7 locales, `lang()` helper), `bin/check-i18n.php` gate |
| CI | `.github/workflows/build.yml` (docker build + zip release), semantic-release |
| Tests | none |

### 2.1 Regressions introduced by `2279453` (root causes)

| Symptom | Root cause |
|---|---|
| Toolbar overlaps the page actions | `.top-toolbar` is `position: absolute` and the page header is not offset for it |
| Grid always ordered by `Id ASC` | `exit;`-style default in the sort whitelist; `Id` is the fallback for every unknown column |
| `SN`, `source`, attachments cannot be sorted | those columns are absent from the sort whitelist / not real DB columns |
| Sorting/search resets when paging | the search `<form>` is `GET` but does not carry `sort`, `dir`, `page` |
| Inline `Status` / `BYOD` edits are discarded | inputs are named `row[Status]` while `save_row.php` reads `rows[<id>][Status]` |
| Dark mode does nothing | CSS keys off `body.dark`, `theme.js` sets `data-theme` on `<html>` |
| Column labels stay English | grid headers use the raw column name, never `lang()` |
| Mobile layout broken | no `<meta name="viewport">` |

These eight items are the **acceptance list** for the grid and shell work: each
gets a regression test (unit, e2e or both) so it cannot come back silently.

## 3. Target architecture

```
                        ┌──────────────────────────────────────────┐
  browser ── HTTPS ──▶  │ nginx                                    │
                        │  /app/*      → public/app (SPA bundle)   │
                        │  /auth/*     → public/auth.php           │
                        │  /panel-api/*→ public/panel_api.php      │
                        │  /api/*      → public/api.php   (Bearer) │
                        │  *.php       → legacy pages  (transition)│
                        └───────────────┬──────────────────────────┘
                                        │ PHP-FPM
                        ┌───────────────▼──────────────────────────┐
                        │ lib/  (shared, framework-free)           │
                        │  bootstrap · db · http · settings        │
                        │  assets · asset_fields · sources         │
                        │  session_auth (new) · panel/* (new)      │
                        └───────────────┬──────────────────────────┘
                     ┌──────────────────┴───────────────────┐
                     ▼                                      ▼
                 MariaDB                                  S3
```

### 3.1 Decisions (with rationale)

| # | Decision | Why |
|---|---|---|
| D1 | **SPA served from `/app/`**, same origin as the API | no CORS, cookies keep `SameSite=Lax`, cookies/CSRF work as today |
| D2 | **New `public/panel_api.php`** front controller at `/panel-api/v1/*` | `api.php` is Bearer-only and documented; mixing cookie sessions into it would break that contract |
| D3 | **Session cookie + `X-CSRF-Token`** for the panel API | Keycloak secrets must stay server-side (no SPA OIDC client), no token storage in JS |
| D4 | **Server-side sort/filter/page**; TanStack Table is headless | one implementation for UI + CSV export; big datasets |
| D5 | **`PATCH` + `expected_updated_at`** optimistic locking | two managers editing the same row must not silently overwrite each other |
| D6 | **i18n source of truth stays `public/lang/*.php`** | `bin/check-i18n.php` + existing translations survive; SPA gets JSON generated at build time |
| D7 | **Phased rollout behind `ui.spa_enabled`** (`0` → `1` → SPA default → legacy removal) | a bad release is one setting away from rollback |
| D8 | **No Node in the runtime image**; SPA built in a separate Docker stage | keeps the production image small |
| D9 | TanStack Table **v8** (not v9) for now, isolated behind `useDataGrid()` | v9 is a store-based rewrite; the grid is the riskiest screen, keep the dependency boring and swappable |

### 3.2 Repository layout after the migration

```
ui/                         # SPA (new)
  package.json  vite.config.ts  tsconfig*.json  eslint.config.js
  index.html
  src/
    main.tsx  App.tsx  router.tsx
    api/            client.ts (fetch wrapper, CSRF, error mapping)
                    types.ts  queryKeys.ts
    features/
      auth/         useSession.ts  LoginScreen.tsx
      devices/      DeviceGrid.tsx  useDevices.ts  columns.ts  DeviceDetail.tsx
                    DeviceForm.tsx  Attachments.tsx  filters.ts
      settings/     SettingsLayout.tsx  GeneralTab.tsx  SourcesTab.tsx
                    ApiKeysTab.tsx  FieldsTab.tsx  MaintenanceTab.tsx
    components/     ui/* (Radix + Tailwind primitives)  DataTable.tsx
                    ColumnPicker.tsx  ThemeToggle.tsx  LocaleSelect.tsx
    i18n/           index.ts  locales/*.json (generated)  status.ts
    lib/            theme.ts  format.ts  cn.ts
    styles/         tokens.css  app.css
    test/           setup.ts  *.test.tsx
  e2e/              smoke.spec.ts  grid.spec.ts  visual.spec.ts
public/
  app/                      # vite build output (git-ignored, shipped in image/zip)
  auth.php  panel_api.php   # new entry points
  (legacy pages, removed in phase 9)
lib/
  session_auth.php          # new
  panel/                    # new: routes.php, session.php, meta.php, devices.php,
                            #      fields.php, sources.php, settings.php, files.php, export.php
bin/
  i18n-export.php           # new: lang/*.php → ui/src/i18n/locales/*.json
```


## 4. Panel API surface (`/panel-api/v1`)

Envelope is identical to `api.php` (`json_ok`/`json_fail`): `{ok, data, meta}` /
`{ok:false, error:{code,message,errors?}}`. All routes answer `Cache-Control:
no-store`. Auth: session cookie; mutations additionally require `X-CSRF-Token`.

| Method | Path | Role | Notes |
|---|---|---|---|
| GET | `/session` | any | `{user, role, company, csrfToken, locale, dir, theme, perPage, features}` |
| PATCH | `/session/preferences` | any | locale, theme, per_page, grid state (validated, merged) |
| POST | `/session/logout` | any | destroys session, returns Keycloak end-session URL |
| GET | `/meta` | any | column defs (translated), status values, sources, custom fields, capabilities |
| GET | `/devices` | any | `q, source, company, status, byod, sort, dir, page, per_page, trashed, custom:<key>` |
| POST | `/devices` | manager+ | create |
| GET | `/devices/{id}` | any | full row + `files[]` + `custom{}` + `updated_at` |
| PATCH | `/devices/{id}` | manager+ | partial update, `expected_updated_at` → `409 stale_record` |
| PUT | `/devices/{id}` | manager+ | full replace (parity with `api.php`) |
| DELETE | `/devices/{id}` | admin+ | soft delete (`?hard=1` → superadmin) |
| POST | `/devices/{id}/restore` | admin+ | undo soft delete |
| GET | `/devices/{id}/files` | any | metadata list |
| POST | `/devices/{id}/files/presign` | manager+ | `{filename, content_type, size}` → S3 policy |
| POST | `/devices/{id}/files` | manager+ | register uploaded object (`key`, `etag`, `size`) |
| DELETE | `/devices/{id}/files/{fileId}` | admin+ | remove object + row |
| GET | `/devices/export` | any | CSV, translated headers, same filters as `/devices` |
| GET/POST/PATCH/DELETE | `/fields[/{id}]` | admin+ | custom field definitions |
| POST | `/fields/reorder` | admin+ | ordered ids |
| GET/POST/PATCH/DELETE | `/sources[/{slug}]` | admin+ | sources |
| GET | `/settings` | admin+ | grouped values, secrets masked |
| PATCH | `/settings` | admin+ | per-group update through `settings_normalize()` |
| GET | `/settings/maintenance` | admin+ | migration status |
| POST | `/settings/maintenance/migrate` | superadmin | run pending migrations |
| GET/POST/DELETE | `/api-keys[/{id}]` | admin+ | list (prefix only), create (plaintext once), revoke |
| POST | `/api-keys/{id}/rotate` | admin+ | |
| GET | `/users`, PATCH `/users/{id}` | superadmin | role/company management (`manage_permissions.php` parity) |

**Not re-implemented** (out of scope): `/v1/upsert` and `/v1/bulk` stay
Bearer-only on `api.php`; the SPA uses the panel routes.

### 4.1 Example — device list

```http
GET /panel-api/v1/devices?sort=files&dir=desc&page=2&per_page=25&source=eset&q=lenovo
Cookie: PHPSESSID=…
```

```json
{ "ok": true,
  "data": { "rows": [ { "id": 12, "sn": "SN-0001", "hostname": "NB-012",
                        "status": "In Use", "source": "eset", "byod": false,
                        "files_count": 3, "custom": { "cost_center": "CC-7" },
                        "updated_at": "2026-11-02T12:00:00Z" } ],
            "total": 137, "page": 2, "per_page": 25, "pages": 6 },
  "meta": { "sort": "files", "dir": "desc", "generated_at": "2026-11-02T12:00:03Z" } }
```

Errors: `unauthorized` 401, `forbidden` 403, `invalid_csrf` 419, `not_found`
404, `validation_failed` 422 (+`errors`), `stale_record` 409,
`storage_not_configured` 503, `rate_limited` 429.

### 4.2 Session / auth flow

```
1. GET  /app/                     → SPA shell (nginx, no PHP)
2. GET  /panel-api/v1/session     → 401 → SPA shows LoginScreen
3. GET  /auth/login?redirect=…    → 302 Keycloak (state = HMAC(redirect + nonce))
4. GET  /auth/callback?code&state → token → userinfo → users row
                                    → session_regenerate_id(true) → 302 /app/{redirect}
5. all mutations                  → X-CSRF-Token header (token from /session)
6. POST /panel-api/v1/session/logout → returns Keycloak end-session URL
```

* Cookie flags: `HttpOnly`, `SameSite=Lax`, `Secure` when the request is HTTPS
  (or `X-Forwarded-Proto: https`), `session.use_strict_mode=1`.
* `APP_DEV_LOGIN` (non-production only) enables `GET /auth/dev-login?email=…`
  so the SPA can be developed locally without a reachable Keycloak — the dev
  compose file points at `sso.example.com` today.
* Legacy `index.php`/`logout.php` keep working; `logout.php` is later rewritten
  as a thin alias of `/auth/logout`.


## 5. Device grid specification (the regression-proof part)

State lives in the URL (`?sort=&dir=&page=&per_page=&q=&source=…`) so links,
back/forward and reload keep the view. TanStack Router validates search params;
invalid values fall back to defaults instead of 500s.

| Concern | Behaviour |
|---|---|
| Sorting | Every visible column is sortable, including `SN`, `source`, `files` (virtual: `COUNT(device_files)`), `custom:<key>` (typed: text/date/number) and `updated_at`. Server whitelists the column; unknown → `invalid_sort` 400 (never silently `Id ASC`). Three-state header click: `asc → desc → none` (none = default `updated_at DESC, Id DESC`). |
| Default order | `updated_at DESC, Id DESC` — most recently touched first, not `Id ASC`. |
| Filtering | Text search (`q`) over SN/hostname/email/asset/UUID + searchable custom fields; `source`, `status`, `byod`, `company`, `trashed` are separate params; chips show active filters with `×`. |
| Pagination | `per_page` ∈ {10, 25, 50, 100} (user preference, default 25); page numbers with ellipsis; changing sort/filter resets to page 1 while keeping other params. |
| Column order | Drag-and-drop (`@dnd-kit`) + keyboard (grab, arrow keys, drop) in a `ColumnPicker` dialog, plus show/hide toggles. Persisted per user in `user_preferences.grid_state` and mirrored in the URL for shareable views. |
| Inline edit | `Status` (select), `BYOD` (switch), `Warranty`/`PurchaseDate` (date), `UserEmail`/`Asset` (text) — same role matrix as `asset_grid_editable_columns()`. Each cell PATCHes `{field, value, expected_updated_at}`; failure → toast + revert. |
| Optimistic UI | `useMutation` with `onMutate` snapshot/rollback; `409 stale_record` shows a diff-aware "load latest" action. |
| Attachments | `files` column shows a count badge; sorting by `files` is a real SQL sort. Detail drawer handles upload (presigned POST) / delete. |
| Selection | Checkbox column + bulk actions (delete/restore/export selection) for admin+, guarded by a confirm dialog. |
| Accessibility | Real `<table>` with `<th scope="col">`, `aria-sort`, focusable sort buttons, `aria-live` for result counts, keyboard row navigation, visible focus rings, `prefers-reduced-motion` respected. |
| Empty/loading/error | Skeleton rows while loading, distinct empty states ("no devices" vs "no matches for these filters"), retry on error, `429` backoff hint. |
| CSV export | `/devices/export` reuses the same filters; headers go through `lang()` so the file matches the UI language. |

Role matrix (must match `lib/assets.php`): `user` = read-only; `manager` = inline
edit of the manager columns + create/edit devices; `admin`/`superadmin` = plus
`CypherID`/`CypherKey`, delete, settings, fields, sources, API keys;
`superadmin` = plus user management, hard delete, migrations.

## 6. Design system, theme and layout

* **Tokens first**: `ui/src/styles/tokens.css` defines `--color-surface`,
  `--color-surface-2`, `--color-border`, `--color-text`, `--color-muted`,
  `--color-accent`, `--color-success|warning|danger`, radii, shadows, spacing in
  a `@theme` block (Tailwind v4 CSS-first config — no `tailwind.config.js`).
* **Theme**: `data-theme="light|dark"` on `<html>` + `data-theme-source=
  "system|user"`. Tailwind dark variant is bound to the attribute:
  `@custom-variant dark (&:where([data-theme="dark"], [data-theme="dark"] *));`
  `system` follows `prefers-color-scheme` live. Persisted in
  `user_preferences.theme` **and** `localStorage` (no flash: an inline script in
  `index.html` sets the attribute before first paint). `theme.js`'s `body.dark`
  mistake cannot repeat — there is exactly one mechanism.
* **Layout**: `AppShell` grid = `header` (sticky, in normal flow) + optional
  `aside` + `main`. Nothing is `position: absolute`; page actions live in the
  page header row with `flex-wrap`, so the toolbar can never overlap them. Sticky
  table header inside a scroll container (`max-height: 100%`).
* **Responsive**: `<meta name="viewport" content="width=device-width,
  initial-scale=1">`; grid collapses to cards below `md`; dialogs become sheets;
  touch targets ≥ 44px.
* **RTL**: `dir="rtl"` on `<html>` for `ar-SA`; only logical utilities (`ms-`,
  `me-`, `ps-`, `pe-`, `start-`, `end-`, `text-start`) — a lint rule bans
  `ml-/mr-/pl-/pr-/left-/right-*` in `ui/src/**` to keep RTL intact.
* **Components**: Radix primitives (dialog, select, dropdown, popover, tooltip,
  tabs, checkbox, switch) + `cva` variants; `sonner` for toasts; `lucide-react`
  icons. No bespoke CSS files beyond tokens + two `@layer components` helpers.
* **Both themes meet WCAG AA** (4.5:1 body text, 3:1 UI); checked in review via
  a token contrast test in `tokens.test.ts`.


## 7. i18n pipeline

```
public/lang/en-US.php … ar-SA.php      (source of truth, edited by translators)
        │  bin/i18n-export.php        (also runs as npm "prebuild")
        ▼
ui/src/i18n/locales/<code>.json        (generated; committed so CI can diff)
        │  i18next + react-i18next
        ▼
SPA strings, date/number formatting, Status labels, CSV headers
```

* Keys are the **existing** ones (`login_title`, `status_in_use`, …); new UI
  strings are added to all seven catalogs in the same commit — `bin/check-i18n.php`
  already fails the build on missing keys and stays the gate.
* `bin/i18n-export.php --check` (new flag) fails when `locales/*.json` is stale,
  wired into `ui.yml` so the JSON can never drift from the PHP catalogs.
* Locale resolution: `user_preferences.locale` → `settings.general.default_locale`
  → `Accept-Language` → `en-US`; `dir` comes from `lang_meta()`.
* Status values are stored in English and rendered through `lang_status_options()`
  (exported as `status.*` JSON), so filters/search keep using DB values.
* No hard-coded user-facing strings: `eslint-plugin-i18next`-style check
  (`no-literal-string` limited to `src/features/**` and `src/components/**`).

## 8. Build, packaging and CI

**Dockerfile** (three stages, Node never reaches runtime):

```dockerfile
FROM composer:2 AS vendor
# … composer install --no-dev (unchanged)

FROM node:24-alpine AS ui
WORKDIR /ui
COPY ui/package.json ui/package-lock.json ./
RUN --mount=type=cache,target=/root/.npm npm ci
COPY ui/ ./
COPY public/lang/ /public/lang/            # i18n export needs the PHP catalogs
COPY bin/i18n-export.php /bin/i18n-export.php
RUN npm run build                          # → /ui/dist

FROM php:8.5-fpm-alpine AS runtime
# … unchanged, plus:
COPY --from=ui /ui/dist/ /var/www/html/public/app/
```

**nginx** (generated in the Dockerfile, extended):

```nginx
location = /            { return 302 /app/; }
location /app/assets/   { try_files $uri =404; expires 1y; add_header Cache-Control "public, immutable"; }
location /app/          { try_files $uri /app/index.html; }
location = /app/index.html { add_header Cache-Control "no-store"; }
location ^~ /auth/      { include fastcgi_params; fastcgi_param SCRIPT_FILENAME $document_root/auth.php;
                          fastcgi_param PATH_INFO $uri; fastcgi_pass app:9000; }
location ^~ /panel-api/ { include fastcgi_params; fastcgi_param SCRIPT_FILENAME $document_root/panel_api.php;
                          fastcgi_param PATH_INFO $uri; fastcgi_pass app:9000; }
# /api/ block unchanged; legacy *.php locations unchanged until phase 9
add_header X-Content-Type-Options nosniff always;
add_header X-Frame-Options DENY always;
add_header Referrer-Policy strict-origin-when-cross-origin always;
```

**Release ZIP** (`build.yml`): build the SPA in the runner
(`npm ci --prefix ui && npm run build --prefix ui`) **before** `zip -r`, exclude
`ui/node_modules`, `ui/dist`, `ui/test-results`, but include `public/app` and the
`ui/` sources — zip deployments have no build step.

**Fallback**: `public/index.php` keeps serving the legacy UI when
`public/app/index.html` is missing (source checkout without a build) or when
`ui.spa_enabled = 0`.

**New workflow** `.github/workflows/ui.yml` (on `ui/**`, `public/lang/**`,
`lib/panel/**` changes): `npm ci` → `typecheck` → `lint` → `vitest --coverage` →
`build` (+ upload artifact) → `playwright test` against `vite preview` with a
mocked panel API. `build.yml`/semantic-release keep their behaviour; the
breaking `feat!:` removal of legacy pages is the only release that changes
`/` for end users.

**Git ignores**: `ui/node_modules/`, `ui/dist/`, `ui/test-results/`,
`ui/playwright-report/`, `ui/coverage/`, `public/app/`. `.dockerignore` must
**not** exclude `ui/` (it is needed by the build stage) but must exclude the
generated directories above.


## 9. Phases and acceptance criteria

Estimates are focus days for one developer.

### Phase 0 — this plan (0.5 d) ✅ in progress

* [x] Baseline verified, regressions root-caused (§2.1)
* [x] Endpoint inventory (§4), stack locked (§13)
* [x] `plans/2026-11-02-spa-migration.md` + `docs/frontend.md` + README pointer
* Done when: plan committed; no code changes yet.

### Phase 1 — session auth + preferences (2–3 d)

* [x] `lib/session_auth.php`: `session_boot()`, `session_user()`,
  `session_require()`, `session_require_role()`, `session_csrf_require()`,
  `session_login()`, `session_logout()`, `session_safe_return_path()`
* [x] `public/auth.php`: `/auth/login`, `/auth/callback` (with `state`),
  `/auth/logout`, `/auth/dev-login` (only when `APP_DEV_LOGIN=1` and
  `APP_ENV !== production`)
* [x] `public/panel_api.php` + `lib/panel/routes.php` (method+pattern router,
  JSON envelope, `401`/`403`/`404`/`405`/`419` mapping) + `lib/panel/session.php`
  + `lib/panel/meta.php`
* [x] Migration `2026_11_02_0006_user_preferences` (`email` PK, `locale`, `theme`,
  `per_page`, `grid_state` JSON, `updated_at`)
* [x] `lib/locales.php` (locale list/normalisation shared by the panel and the
  legacy `public/lang.php` wrappers)
* [x] nginx blocks for `/auth` and `/panel-api` in the Dockerfile
* [x] Legacy `index.php`/`logout.php` keep working unchanged
* Done when: the §10 commands pass; `api.php` unchanged; no session data leaks
  into `/api/v1/*`. **Verified 2026-11-02** against the dev stack: 401 → dev-login
  302 → `/session` 200 → PATCH 419 without CSRF / 200 with it → invalid values
  422 → `/meta` 200 → logout 200 + `/session` 401 → `/api/v1/devices` still 401 →
  `/auth/login` 503 with the placeholder Keycloak → `main.php`/`settings.php`
  still render (new setting visible and translated).

### Phase 2 — devices read API (3–4 d) ✅ code + smoke done

* [x] `GET /devices` with `q`, filters (`source`, `status`, `byod`, `company`,
  `trashed`), `sort` (incl. `files`, `custom:<key>`, `updated_at`), `dir`,
  `page`, `per_page`; `meta.sort/dir/filters` echo the **effective** scope.
* [x] `GET /devices/{id}` (row + `custom{}` + `files[]`), `GET /devices/{id}/files`.
* [x] `GET /devices/export`: CSV (BOM, `;`), translated headers, same
  filters/sort, streamed in 500-row pages.
* [x] Typed rows (`bool` for `BYOD`/`Mobile`, ISO-8601 UTC timestamps,
  `files_count`, `custom`), batched attachment counts and custom values.
* [x] Unknown sort column → `400 invalid_sort`; unknown status/boolean →
  `400 invalid_filter`; page past the end clamps to the last page.
* [ ] `EXPLAIN` + 100k-row fixture timing (§10) — needs a large dataset; the
  index set from migration 0004 (`updated_at`, `source`, `SN`) covers the
  documented sorts.
* Done when: sort by `SN` asc/desc, `files` desc (2 attachments sorts first),
  paging (`total 8 → pages 2`), company isolation (Acme manager cannot read
  Globex, Globex admin cannot read Acme), CSV with `Anexos`/`Não` in pt-BR — all
  verified against the dev stack on 2026-11-02.

### Phase 3 — devices write API (3–4 d)

* `POST`, `PATCH` (+`expected_updated_at`, `409 stale_record`), `PUT`,
  `DELETE` (soft/hard), `POST /restore`, attachments presign/register/delete.
* All writes go through `asset_create/update/soft_delete` +
  `field_validate`/`custom_values_save` so both APIs validate identically.
* Done when: role matrix enforced (user/manager/admin/superadmin) and covered by
  PHP integration tests (`bin/test-panel-api.php` or `tests/` w/ PHPUnit).

### Phase 4 — SPA shell + design system (4–5 d)

* Vite scaffold, router (`/`, `/login`, `/devices`, `/devices/:id`,
  `/settings/*`), `AppShell`, tokens, theme + locale switchers, toasts, error
  boundary, query client (retry/backoff, `401` → login screen).
* Done when: light/dark/system switch with no flash and no reload; all 7 locales
  switch instantly incl. `ar-SA` RTL; shell passes a keyboard-only walkthrough.

### Phase 5 — data grid (6–8 d)

* §5 in full: server-driven sort/filter/page, column picker (drag + keyboard),
  persisted preferences, inline edit with optimistic locking, selection, bulk
  actions, attachments column, CSV export, a11y (`aria-sort`, `aria-live`).
* Done when: each §2.1 regression has a test (see §10) and the grid is usable on
  a 360 px viewport.

### Phase 6 — device detail, create/edit, attachments (4–5 d)

* Detail drawer/page with tabs (Overview / Custom fields / Attachments /
  History), `react-hook-form` + `zod` schemas derived from `/meta` field defs,
  presigned upload with progress, delete/restore.

### Phase 7 — settings, sources, fields, API keys, users (5–6 d)

* Full parity with `settings.php`, `manage_permissions.php`: general, storage
  (secrets write-only, masked), sources, custom fields (reorder, per-locale
  labels, validation options), API keys (create-once secret, rotate, revoke),
  maintenance (status + migrate button), users (superadmin).

### Phase 8 — tests, CI, docs (3–4 d)

* Vitest: api client, `useDataGrid`, column persistence, theme, i18n coverage.
* Playwright: login, sort/filter/paging, inline edit + conflict, column reorder
  persistence, dark mode, RTL snapshot, CSV download, keyboard a11y.
* `docs/frontend.md` finalised, `docs/api.md` cross-links the panel API,
  `docs/development.md` documents `npm run dev` + proxy.

### Phase 9 — cutover (2–3 d)

* `ui.spa_enabled` default `1` → `/` redirects to `/app/`; legacy pages still
  reachable by direct URL for one release.
* Then a breaking `feat!:` release deletes `main.php`, `asset.php`,
  `asset_new.php`, `export.php`, `save_row.php`, `save_rows.php`,
  `settings.php`, `manage_permissions.php`, `style.css`, `theme.js`,
  `lang_flag_buttons`/`theme_toggle_button` helpers, and the nginx `*.php`
  locations, plus `docs/screenshots` refresh.

**Total: ~28–37 focus days.**


## 10. Validation matrix

| Layer | Command | Gate |
|---|---|---|
| PHP syntax | `php -l <file>` on every changed PHP file | every commit |
| PHP behaviour | `php bin/migrate.php --dry-run`, `php bin/check-i18n.php` | every commit touching `lib/`, `bin/`, `public/lang/` |
| Panel API (manual) | `curl -i -c /tmp/c -b /tmp/c …` (§10.1) | phase 1+ |
| Panel API (auto) | `php bin/test-panel-api.php` (integration, temp DB) | phase 2+ |
| SPA types | `npm --prefix ui run typecheck` | every UI commit |
| SPA lint | `npm --prefix ui run lint` | every UI commit |
| SPA unit | `npm --prefix ui run test` (`vitest run --coverage`) | every UI commit |
| SPA e2e | `npm --prefix ui run e2e` (`playwright test`) | phase 4+ |
| i18n sync | `npm --prefix ui run i18n -- --check` | every commit touching lang files |
| Build | `npm --prefix ui run build` (must emit `public/app/index.html`) | before release |
| Image | `docker build .` then `docker compose -f docker/docker-compose.dev.yml up -d` | phase 8+ |
| Legacy parity | legacy `main.php` still sorts/pages/filters | until phase 9 |

### 10.1 Regression tests for the eight §2.1 bugs

| Bug | Test |
|---|---|
| `SN` / `source` / `files` not sortable | `panel devices: sort=SN|source|files returns ordered rows` (PHP) + `grid.spec.ts` header click |
| default `Id ASC` | unit: no `sort` → `meta.sort === 'updated_at'`, `dir === 'desc'` |
| sort lost when paging/searching | `grid.spec.ts`: sort by hostname → type query → assert URL+order preserved |
| toolbar overlap | `visual.spec.ts` snapshot at 1280/768/360 px + `boundingBox` assertion (header bottom ≤ actions top) |
| inline edit discarded | `grid.spec.ts`: toggle BYOD → reload → value persisted |
| dark mode | `theme.spec.ts`: switch → `html[data-theme=dark]`, body background changes, no console errors |
| English column labels | `grid.spec.ts` with `pt-BR`: headers match `lang` catalog |
| no viewport meta | unit: `index.html` contains `name="viewport"` |

### 10.2 Manual smoke (phase 1, current)

```bash
docker compose -f docker/docker-compose.dev.yml up -d --build
docker compose -f docker/docker-compose.dev.yml exec app php bin/migrate.php
curl -i http://localhost:5600/panel-api/v1/session                 # 401 unauthorized
curl -i -c /tmp/c -b /tmp/c 'http://localhost:5600/auth/dev-login?email=admin@example.com'
curl -i -b /tmp/c http://localhost:5600/panel-api/v1/session        # 200 + csrfToken
curl -i -b /tmp/c -X POST http://localhost:5600/panel-api/v1/session/logout   # 419 (no CSRF)
curl -i -b /tmp/c -H "X-CSRF-Token: $TOKEN" -H 'Content-Type: application/json' \
     -d '{"theme":"dark"}' -X PATCH http://localhost:5600/panel-api/v1/session/preferences
curl -i http://localhost:5600/api/v1/devices                        # 401 (Bearer prod): unchanged
```

## 11. Risks and mitigations

| Risk | Impact | Mitigation |
|---|---|---|
| Grid regressions repeat | high | §2.1 list is the test matrix; server owns sort/filter; e2e asserts URL state |
| **v9** TanStack Table churn | medium | pinned v8, isolated behind `useDataGrid()` + `columns.ts` mapping |
| TypeScript 7 vs tooling | low | pinned `~5.9.3`; upgrade tracked when `typescript-eslint` widens its peer range |
| Panel API drifts from `api.php` | medium | both call the same `lib/assets.php` functions; shared contract test on the 6 common fields |
| Big installations slow | medium | server-side paging, `EXPLAIN` checks, keyset option for deep pages, indexes on `updated_at`, `source`, `SN` |
| Keycloak unavailable locally | medium | `APP_DEV_LOGIN` (non-production); dev compose documents `.env` overrides |
| Session cookie on HTTP | low | `Secure` only when HTTPS detected; docs warn that production must be TLS |
| S3 not configured | medium | `storage_not_configured` surfaces as a friendly panel message; local driver added in phase 6 (§14.3) |
| Release zip without a build | medium | `build.yml` builds the SPA before zipping; `index.php` falls back to legacy |
| i18n JSON drift | low | `i18n-export --check` in CI; `check-i18n.php` unchanged |
| Scope creep (saved views, audit) | medium | explicitly deferred to §14; phases are shippable on their own |

## 12. Rollout and rollback

1. Phases 1–3 ship **invisible** (new endpoints only) — zero user impact.
2. Phase 4–8 ship with `ui.spa_enabled=0`; the SPA is reachable at `/app/` for
   testers behind the setting.
3. `ui.spa_enabled=1` → `/` redirects to `/app/`; legacy pages remain for one
   release (rollback = flip the setting back in Settings → General or `.env`).
4. Breaking release removes legacy pages; rollback = redeploy the previous tag.

Every phase: conventional commit, `CHANGELOG` via semantic-release, no version
bumps by hand.


## 13. Stack lock (verified against npm on 2026-11-02)

| Package | Version | Note |
|---|---|---|
| `react`, `react-dom` | `19.3.1` | `@types/react`/`@types/react-dom` `19.3.0` |
| `typescript` | `~5.9.3` | latest is `7.0.2`, but `typescript-eslint@8.72.1` peers `typescript >=4.8.4 <6.1.0` |
| `vite` | `8.3.5` | engines `^20.19.0 \|\| >=22.12.0` (local Node 24, Docker `node:24-alpine`) |
| `@vitejs/plugin-react` | `6.2.1` | peer `vite ^8`; `oxc-transform-react`/`@rolldown/plugin-babel` are optional peers |
| `tailwindcss`, `@tailwindcss/vite` | `4.3.4` | peer `vite ^5.2 \|\| ^6 \|\| ^7 \|\| ^8`; CSS-first config |
| `@tanstack/react-query` | `5.105.3` | |
| `@tanstack/react-table` | `8.21.3` | v9 (`9.3.1`) is a store-based rewrite — see D9 |
| `@tanstack/react-router` | `1.170.46` | peers react/react-dom `>=18 \|\| >=19` |
| `i18next`, `react-i18next` | `26.7.1`, `17.1.11` | |
| `react-hook-form`, `zod` | `7.91.0`, `4.6.7` | |
| `@radix-ui/react-*` | `dialog 1.3.1`, `select 2.4.1`, `dropdown-menu 2.2.1`, `popover 1.3.1`, `tooltip 1.4.1`, `tabs 1.2.1`, `checkbox 1.4.1`, `switch 1.4.1`, `label 2.1.17`, `slot 1.5.0` | individual primitives, not the `radix-ui` umbrella |
| `sonner`, `lucide-react`, `clsx`, `tailwind-merge`, `class-variance-authority` | `2.0.8`, `1.66.0`, `2.1.1`, `3.7.0`, `0.7.1` | |
| `@dnd-kit/core`, `sortable`, `modifiers`, `utilities` | `6.3.1`, `10.0.0`, `9.0.0`, `3.2.2` | `sortable@10` peers `core ^6.3.0` ✔ |
| `vitest`, `@vitest/coverage-v8`, `jsdom` | `5.1.0`, `5.1.0`, `30.1.3` | |
| `@testing-library/react`, `dom`, `user-event`, `jest-dom` | `16.3.3`, `10.4.2`, `14.6.8`, `7.0.1` | |
| `@playwright/test` | `1.64.1` | |
| `eslint`, `typescript-eslint`, `prettier` | `10.14.0`, `8.72.1`, `3.9.10` | flat config, `typescript-eslint` supports eslint ^10 |
| `@types/node` | `26.6.6` | |

`ui/package-lock.json` is committed; `engines.node >=24`.

## 14. Deferred / open items

1. **TypeScript 7** once `typescript-eslint` accepts `>=6.1.0`.
2. **TanStack Table v9** review after phase 5 (contained in `useDataGrid()`).
3. **Local storage driver** for attachments (`lib/storage.php` with `s3` +
   `local`) so dev and zip deployments without S3 can upload.
4. **Audit trail** (`asset_events`: who changed what, when) — all writes already
   funnel through the panel API, so this is cheap to add.
5. **Saved views** (named filter+sort+column presets) — `grid_state` leaves room.
6. **Keyset pagination** for very deep pages if `OFFSET` shows up in profiling.
7. **Rate limiting** on panel mutations (`rate_limited` reserved in §4.1).
8. **`col_*` translations** for `fr-FR`, `zh-CN`, `hi-IN`, `ar-SA` (they fall
   back to en-US labels today; see §16, 2026-11-02 phase 2).

## 15. Context recovery protocol

A new session (human or agent) must be able to resume from this file alone:

1. Read §1–§4 (goals, baseline, architecture, API surface).
2. Read §9 and check the phase checkboxes; the first unchecked phase is the task.
3. Read §10 for the exact commands that must pass for that phase.
4. `git log --oneline -20` and compare with the phase list; any commit that is
   not described here means the plan is stale — update §16 before continuing.
5. Never code a phase whose predecessor is red. Never weaken a §10 gate to make
   a phase "done".
6. Record every deviation (scope, order, version, endpoint shape) in §16 the
   moment it happens, not at the end.
7. Files that are always safe to read first: `lib/bootstrap.php`, `lib/assets.php`,
   `lib/session_auth.php`, `public/panel_api.php`, `ui/src/api/client.ts`.

## 16. Deviations log (append-only)

| Date | Phase | Deviation | Why |
|---|---|---|---|
| 2026-11-02 | 0 | TanStack Table pinned to `8.21.3` instead of latest `9.3.1` | v9 is a store-based rewrite; the grid is the riskiest screen (D9) |
| 2026-11-02 | 0 | TypeScript pinned to `~5.9.3` instead of `7.0.2` | `typescript-eslint@8.72.1` peers `<6.1.0`; lint is a §10 gate |
| 2026-11-02 | 0 | Radix consumed as individual `@radix-ui/react-*` packages | avoids the `radix-ui` umbrella's coarser versioning |
| 2026-11-02 | 1 | nginx `/auth` + `/panel-api` blocks landed in phase 1 (plan had them in §8) | the endpoints cannot be smoke-tested otherwise; both are additive and do not touch `/api` |
| 2026-11-02 | 1 | Locale list moved to `lib/locales.php`; `public/lang.php` keeps `lang_supported()`/`lang_meta()`/`lang_normalize_code()` as wrappers | the panel API needs the list without starting a session or loading a catalogue; `bin/check-i18n.php` and `lib/settings_*` call sites are unchanged |
| 2026-11-02 | 1 | `ui.spa_enabled` added to the `general` settings group | `public/settings.php` renders tabs per group; a new `ui` group would need its own tab before there is anything else in it |
| 2026-11-02 | 1 | `settings_ui_spa_enabled` added to all seven catalogs now | the legacy Settings page renders the key immediately; leaving it English-only would re-create the §2.1 label bug |
| 2026-11-02 | 2 | `col_*` catalog keys added to `en-US`, `pt-BR`, `es-MX` only | those are the three complete catalogs; `fr-FR`/`zh-CN`/`hi-IN`/`ar-SA` (≈20 %) fall back to the en-US label through `locale_translate()` instead of showing raw keys. Remaining translations tracked in §14 |
| 2026-11-02 | 2 | `locale_catalog()`/`locale_translate()` added to `lib/locales.php`; `public/lang.php` delegates (`lang_catalog()`, `lang()`) | the CSV export runs inside the panel API and must translate headers without including a page file that starts a session |
| 2026-11-02 | 2 | `/meta` appends a synthetic `files` column (COUNT(device_files)) | `asset_column_defs()` has no `files` entry, so the virtual column would otherwise be invisible to the SPA even though the server sorts by it |

