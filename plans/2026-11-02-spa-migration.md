# Plan: SPA Migration (React 19 + TypeScript + Vite)

> **This file is the single source of truth for the migration.** Every phase
> starts by re-reading it. Update it at the end of each phase (mark progress,
> record deviations in §17) so context is never lost.
>
> Status: **in progress** — phases 0–8 done. Phase 2's last item (EXPLAIN +
> 100k-row timing) is closed by `bin/bench-devices.php`, which found and fixed
> three real problems (missing indexes, a repair migration, and the broken `byod`
> filter). Phase 9 step 1 is shipped: `ui.spa_enabled` defaults to `1`, `/`
> redirects to `/app/`, and rolling the setting back renders a notice instead of
> a half-working shell. What is left is the breaking release that deletes the
> legacy PHP pages (§9).

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
| POST | `/devices/bulk` | manager+ / admin+ | one op (`status`, `byod`, `delete`, `restore`) for ≤200 ids, per-row `updated`/`skipped` |
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
* Status values are stored in English and rendered through the status catalogue
  (`status_*` keys, exported as `status.*` JSON), so filters/search keep using DB
  values.
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
# /api/ block unchanged
# Only the entry points run PHP: / (index.php), /api, /panel-api, /auth.
# Every other *.php request is a 404 — a stray file must never be served as source.
location ~ \.php$ { return 404; }
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
* [x] `EXPLAIN` + 100k-row fixture timing (§10) — `bin/bench-devices.php`
  seeds a throwaway fixture through the production
  `panel_devices_where()`/`panel_devices_order_by()` helpers and prints the plan
  plus wall time per access pattern (2026-11-02, MariaDB 11.8, 90k live rows):
  default/`SN`/`source`/`Status` sorts 0.3 ms page and ~12 ms count; `files` and
  `custom:` sorts ~155 ms (filesort over a computed expression); `q=` ~300+400 ms
  (`LIKE '%…%'` on ~20 columns); last page 176 ms. The run found two real bugs,
  both fixed: `idx_assets_source`/`idx_assets_status` were missing (and
  installations that had already applied `0004` never got `idx_assets_updated`
  either → migration `0007_assets_indexes` repairs them), and the `byod=true`
  filter matched nothing because a `VARCHAR` column was compared to integer `1`.
* Done when: sort by `SN` asc/desc, `files` desc (2 attachments sorts first),
  paging (`total 8 → pages 2`), company isolation (Acme manager cannot read
  Globex, Globex admin cannot read Acme), CSV with `Anexos`/`Não` in pt-BR — all
  verified against the dev stack on 2026-11-02.

### Phase 3 — devices write API (3–4 d) ✅ done

* [x] `POST /devices` (manager+), `PATCH` (+`expected_updated_at`,
  `409 stale_record`), `PUT` (full replace), `DELETE` (soft by default, `?hard=1`
  superadmin-only), `POST /devices/{id}/restore` (idempotent).
* [x] `lib/panel/device_writes.php`: all writes go through `asset_validate()` +
  `asset_create/update/soft_delete/restore/hard_delete()` +
  `custom_values_save()`, so both APIs validate identically; unknown/forbidden
  keys become `422` with snake_case field names (`panel_device_error_keys()`).
* [x] Role matrix from `panel_device_writable_columns()` (create columns ∪ grid
  inline columns ∪ `source`/`source_ref`, `UUID` for admin+); company-bound
  accounts can never widen or move the scope, unrestricted ones must name a
  company on create.
* [x] Attachments: `POST /devices/{id}/files` (multipart → server-side S3 put,
  `503 storage_not_configured` when the bucket/key is missing) and
  `DELETE /devices/{id}/files/{fileId}` (object best effort + row).
* [x] `/meta` publishes `writeColumns` (create/update) and `limits`
  (`requireSn`, `maxUploadBytes`) so the SPA renders the right form.
* [x] `bin/test-panel-api.php`: 68 assertions covering the role matrix, validation
  (unknown/forbidden field, required SN, unknown source, invalid status, duplicate
  SN), optimistic locking, company isolation, soft delete → hidden → `trashed=1`
  → restore → hard delete, CSRF `419`, and the read-sort contract.
  plus bulk actions; verified in the dev image on 2026-11-02 (`68 passed, 0 failed`).

### Phase 4 — SPA shell + design system (4–5 d) 🚧 scaffold done

* [x] Vite scaffold with the pinned stack (§13), `base: '/app/'`,
  `build.outDir: '../public/app'`, dev proxy for `/panel-api`, `/auth`, `/api`;
  committed lockfile.
* [x] `index.html` with `viewport` + pre-paint theme bootstrap (no flash).
* [x] `styles/tokens.css`: Tailwind v4 CSS-first config, `@custom-variant dark`
  bound to `[data-theme="dark"]`, light/dark token sets, reduced-motion and
  focus-visible base rules.
* [x] `lib/theme.ts` (single theme mechanism, unit-tested), `api/client.ts`
  (envelope unwrapping, CSRF header, typed `PanelApiError`), `api/types.ts`,
  `features/auth/useSession.ts` + scaffold `App.tsx` that boots from `/session`
  and shows the sign-in card on `401`.
* [x] ESLint flat config with the logical-utilities rule (bans `ml-/mr-/pl-/pr-/
  left-/right-*`), Vitest + Testing Library, Playwright config + smoke spec.
* [x] Router (TanStack Router, `basepath: '/app'`) with `/`, `/devices` and
  `/devices/$deviceId`; `SessionGate` (boot → failure → login → app), `AppShell`
  (sticky header, nav, theme/locale switchers, sign-out), sonner toasts,
  `SessionProvider` so screens read the cached session.
* [x] `GET /panel-api/v1/i18n` + `src/i18n/index.ts`: the SPA loads the merged
  catalogue at runtime (PHP catalogs stay the only source of truth) and applies
  `lang`/`dir` on `<html>`.
* [x] `/settings/*` routes — **not started**, moved to phase 7; the error boundary
  (`app/ErrorBoundary.tsx`) and the keyboard walkthrough landed instead.
* [x] Error boundary: a render error shows a message, "Try again" and a way back
  instead of blanking the mount point.
* [x] Multi-stage Dockerfile (`vendor` → `ui` on `node:24-alpine` → runtime) and
  nginx `/app/` locations (immutable assets, `no-store` shell, history fallback) —
  landed with phase 4 so the scaffold could be verified end-to-end.
* [x] Release ZIP (`build.yml`) builds the SPA before `zip -r`
  (`npm ci --prefix ui && npm run build --prefix ui`, then `public/app` is
  included and `ui/node_modules`/`ui/dist`/test output excluded).
* Done when: light/dark/system switch with no flash and no reload; all 7 locales
  switch instantly incl. `ar-SA` RTL; shell passes a keyboard-only walkthrough.

### Phase 5 — data grid (6–8 d)

* [x] Server-driven sort/filter/page through TanStack Table v8 (`manualSorting`),
  URL as state (`?sort=&dir=&page=&per_page=&q=&source=&status=&company=&byod=&trashed=`),
  sort cycle asc → desc → default with the page reset on every change, sortable
  headers with `aria-sort`, attachments column, CSV export with the same query,
  debounced search, paging + page-size control, loading skeleton, empty and error
  states, keyboard-activatable rows.
* [x] Tests: 16 unit tests for the state machine (`gridSearch`) + 12 for the column
  layout (`columnLayout`) + 4 component tests for the table + 4 Playwright specs
  (sort cycle/URL persistence, filters/paging, create form payload, column layout
  persistence) against the real bundle.
* [x] Column picker persisted in `grid_state` (`order`, `visible`) with checkbox +
  `Move up`/`Move down` buttons (keyboard-only; pointer drag is deferred).
* [x] Inline edit with optimistic locking: Status (`select`) and boolean columns
  (`checkbox`) PATCH the row with `expected_updated_at`; a `409` shows
  "changed by someone else" and refreshes the page data.
* [x] `aria-live` result summary (`ui_showing_range`) next to the table.
* [x] Selection + bulk actions: a leading checkbox column (header checkbox with
  the `indeterminate` half-state), a toolbar that sends **one**
  `POST /devices/bulk` for the whole selection (`status`, `byod`, `delete`,
  `restore`; manager+/admin+/superadmin) and reports per-row results
  (`ui_bulk_partial` = "2 updated, 1 skipped"). `features/grid/selection.ts` is a
  pure reducer with 13 unit tests; `e2e/bulk.spec.ts` covers the payload and the
  clearing rules.
* [x] 360 px audit: `e2e/responsive.spec.ts` asserts the *page* never scrolls
  sideways (grid and detail) and that the table scrolls inside its own container;
  the live stack reports `overflow = 0 px` and the toolbar wraps.
* [x] Keyboard walkthrough (`e2e/keyboard.spec.ts`): sort with Enter on the
  header button, activate a row with Enter, toggle a row checkbox with Space.
* Done when: each §2.1 regression has a test (see §10) and the grid is usable on
  a 360 px viewport.

### Phase 6 — device detail, create/edit, attachments (4–5 d) ✅ core done

* [x] Read-only detail page (`/app/devices/:id`): every published column, custom
  values, attachment list (name/size/date), back to the grid.
* [x] Create/edit form (`/app/devices/new`, `/app/devices/:id/edit`) generated
  from `/meta.writeColumns` (role matrix stays on the server), with per-field
  `422` errors, required/duplicate custom-field handling, and a `409`
  "reload before saving" state.
* [x] Delete (soft) / restore / hard delete (superadmin) from the detail page,
  gated by `capabilities`; attachments upload + remove (server-side S3 put).
* [~] Deferred by design: tabbed edit drawer, `react-hook-form` + `zod` client
  schemas, upload progress. The controlled form covers the same fields (they come
  from `/meta.writeColumns`, so nothing is missing), `deviceForm.ts` owns the
  payload/normalisation rules and is unit-tested, and the 422/409 paths are
  covered by e2e. Both libraries are installed and pinned, so the drawer is a
  contained change; the trigger to do it is the field count growing (or the need
  for per-file upload state, which `fetch` cannot report — that needs XHR).

### Phase 7 — settings, sources, fields, API keys, users (5–6 d) ✅ done

* [x] Server contract for the whole surface: `lib/panel/settings.php` exposes
  `GET/PATCH /settings`, `sources` CRUD, `fields` CRUD + `reorder`, `api-keys`
  CRUD + `rotate`, `users` (superadmin) and `maintenance` (status + migrate, dry
  run by default). Every handler wraps the same model helper the legacy page
  uses (`settings_set()`, `source_*`, `field_*`, `api_key_*`, `migrate_run()`).
* [x] Parity rules encoded server-side: secrets write-only (empty `value` +
  `configured`, `clear` to wipe), `422 validation_failed` with a code per key,
  system sources undeletable (`409 system_source`), tokens returned once, own
  role immutable (`409 self_role_change`).
* [x] `bin/test-panel-api.php` covers the role gates (manager `403`, users
  superadmin-only), validation/storage, sources, fields + reorder, keys
  (create/rotate/delete, hash never serialised) and maintenance.
* [x] SPA screens: `/app/settings` with one tab per group (`?tab=general`,
  `storage`, `sources`, `api` + keys, `fields`, `maintenance`, `users`), the
  header link and the `users` tab gated on `can_admin`/`can_superadmin`.
  `features/settings/settingsForm.ts` keeps the two risky rules unit-tested
  (blank secret = unchanged, `clear` to wipe) and `e2e/settings.spec.ts` covers a
  save, a per-key `422`, the roster gating and the `can_admin` guard.

### Phase 8 — tests, CI, docs (3–4 d) 🚧 mostly done

* [x] Vitest: `gridSearch` (16), `columnLayout` (12), `selection` (13),
  `deviceForm` (8), `DataGrid` component (4), `theme` (5) — 58 tests.
* [x] Playwright (20 specs): sort/filter/paging + URL persistence, inline edit +
  `409` conflict, column layout persistence, bulk actions, dark mode, RTL
  switch, CSV download, keyboard a11y, 360 px layout, create payload, shell
  smoke.
* [x] `.github/workflows/ui.yml`: `npm ci` → typecheck → lint → vitest → build
  (+ `public/app` artifact) and a Playwright job against `vite preview` with the
  stubbed panel API; `build.yml` builds the SPA before zipping.
* [x] Vitest for `api/client.ts` (envelope → `PanelApiError`, CSRF header,
  multipart body, `buildQuery`/`apiUrl`) and an i18n coverage assertion that
  reads `public/lang/*.php` and fails on a literal used by the SPA that no
  catalogue defines (dynamic families asserted separately).
* [x] RTL geometry spec (`e2e/rtl.spec.ts`): no page-level overflow, the
  selection column on the visual right, the grid scrolling inside its own
  container; the pixel snapshot is opt-in (`VISUAL_SNAPSHOTS=1`) because a
  baseline would compare different font sets across machines.
* [x] `docs/frontend.md` finalised (panel API table, bulk actions, gates);
  `docs/api.md` cross-links the panel API; `docs/development.md` documents
  `npm run dev` + proxy.

### Phase 9 — cutover (2–3 d) ✅ done

* [x] `ui.spa_enabled` default `1` → `/` redirects to `/app/`; legacy pages still
  reachable by direct URL for one release (10.8.0). `public/index.php` picked the
  target from `settings_spa_enabled()`, anonymous visitors went to the SPA's own
  login screen, and `/app/` rendered a localised "turned off" notice
  (`features.spa`) instead of a half-working shell when an administrator rolled
  back.
* [x] 11.0.0 (breaking `feat!:`) deleted `main.php`, `asset.php`, `asset_new.php`,
  `export.php`, `save_row.php`, `save_rows.php`, `settings.php`,
  `manage_permissions.php`, `style.css`, `theme.js` and everything that only
  existed for them: `lang_selector()`/`lang_flag_buttons()`/
  `theme_toggle_button()`/`lang_status_options()`/`lang_status_label()`,
  `csrf_field()`/`csrf_require()`/`flash_set()`/`flash_take()`,
  `lib/assets_ui.php`, `lib/settings_ui.php`, `lib/settings_actions.php`, the
  dead model helpers (`source_label()`, `source_badge()`, `field_def()`,
  `field_set_active()`, `custom_values_delete()`, `field_render_input()`,
  `asset_grid_columns()`, `asset_column_exists()`, `settings_schema_ready()`,
  `user_preferences_ready()`, `api_key_set_active()`, `crypto_mask()`,
  `locale_native_name()`, `db_schema_cache_flush()`, `migrate_pending()`), the
  `ui.spa_enabled` setting (`0008_retire_spa_flag` deletes the stored row) and the
  `features.spa` flag. nginx now runs PHP for the four entry points only —
  `/`, `/api`, `/panel-api`, `/auth` — and answers `404` to every other `*.php`
  request, so a stray file can never be served as source.
* [x] `public/index.php` is a pure entry point: `/` → `/app/`, plus the two things
  that must stay on `/` (`?action=logout` and the legacy `?code=` SSO callback,
  which now reports failures as `/app/?auth_error=<code>` and lets the SPA render
  the localised message — `e2e/smoke.spec.ts`).
* [x] `docs/screenshots` refreshed from the real image with
  `node ui/scripts/screenshots.mjs` (dev login through the dev stack, six screens,
  including the Users tab that replaced `manage_permissions.php`).

**Total: ~28–37 focus days.**


## 10. Validation matrix

| Layer | Command | Gate |
|---|---|---|
| PHP syntax | `php -l <file>` on every changed PHP file | every commit |
| PHP behaviour | `php bin/migrate.php --dry-run`, `php bin/check-i18n.php` | every commit touching `lib/`, `bin/`, `public/lang/` |
| Panel API (manual) | `curl -i -c /tmp/c -b /tmp/c …` (§10.1) | phase 1+ |
| Panel API (auto) | `php bin/test-panel-api.php` (integration, dev DB, self-cleaning) | phase 2+ |
| Device list perf | `php bin/bench-devices.php --rows=100000` (EXPLAIN + timings, self-cleaning) | before each release |
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
3. ~~**Local storage driver** for attachments (`lib/storage.php` with `s3` +
   `local`)~~ — done: `storage.driver` (`s3`/`local`) + `storage.local_path`
   (default `var/storage`, `0700`), one interface for put/delete/read, and a new
   `GET /devices/{id}/files/{fileId}/download` that streams for `local` and
   redirects to a presigned GET for `s3`. Covered end to end (upload, bytes,
   `0600`, download key, delete) by `bin/test-panel-api.php`.
4. ~~**Audit trail** (`asset_events`: who changed what, when)~~ — done:
   migration `0010_asset_events`, `lib/asset_events.php` (append-only, `asset_id`
   is not a foreign key so the trail outlives a hard delete), recorded by every
   panel write **and** by `public/api.php` (`api:<key label>` as the actor),
   exposed as `GET /panel-api/v1/devices/{id}/events` and rendered as the
   History card on the device page (field labels come from `meta.columns`).
5. **Saved views** (named filter+sort+column presets) — `grid_state` leaves room.
6. **Keyset pagination** for very deep pages if `OFFSET` shows up in profiling —
   measured at 100k rows: the last page costs 176 ms against 0.3 ms for page 1
   (`bin/bench-devices.php`, "deep page" row). Not user-visible yet (nobody pages
   to 3600), but it is the number to act on if it is.
7. ~~**Rate limiting** on panel mutations (`rate_limited` reserved in §4.1)~~ —
   done: `lib/rate_limit.php` + migration `0009_rate_limits`, a fixed-window
   counter per user (`api.panel_rate_limit`, default 120/min) enforced in
   `panel_invoke()` before the handler runs, answering `429 rate_limited` with
   `Retry-After`. Reads are exempt. A table rather than APCu/Redis because the
   image ships neither and PHP-FPM spreads a user's requests over workers.
8. ~~`col_*` translations for `fr-FR`, `zh-CN`, `hi-IN`, `ar-SA`~~ — done: the
   whole panel family (`ui_*`, `col_*`, `status_*`, `theme_*`, `role_*`) plus the
   shell strings are translated in all seven catalogues (47 % coverage for the
   four community locales, floor raised to 45 % in `bin/check-i18n.php` and
   enforced per locale by `ui/src/i18n/coverage.test.ts`). The legacy `set_*` /
   `perm_*` keys of the PHP pages still fall back to en-US there.
9. **Presigned PUT upload** (`POST /devices/{id}/files/presign`) if uploads ever
    need to bypass PHP-FPM; today the server puts the object itself (§16, phase 3).
10. **Pointer drag-and-drop** for the column picker (buttons already cover the
    keyboard path; `@dnd-kit` is pinned).
11. ~~**Prettier**~~ — done: `.prettierrc.json` (single quotes, 120 columns) and
    `.prettierignore` pin the house style, the tree is reformatted in one
    no-behaviour-change commit, and `npm run format` is now a CI gate.
12. ~~**`api/client.ts` + i18n coverage unit tests** and an RTL screenshot
    snapshot~~ — done: `src/api/client.test.ts`, `src/i18n/coverage.test.ts`
    (including the per-locale panel-family gate) and the opt-in
    `VISUAL_SNAPSHOTS=1` RTL snapshot in `e2e/rtl.spec.ts` all shipped.

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
| 2026-11-02 | 4 | Playwright config + smoke spec added with the scaffold (plan had e2e in phase 8) | the two cheapest assertions (viewport meta, single theme mechanism) guard the exact §2.1 regressions; browsers are not installed in CI yet |
| 2026-11-02 | 4 | ESLint flat config also warns on `console.*` and bans physical inline-axis utilities | encoded so reviewers stop repeating it; scoped to `src/**` and disabled for tests |
| 2026-11-02 | 4 | Multi-stage Dockerfile + nginx `/app/` locations landed in phase 4 (plan had them in §8) | without them the scaffold cannot be verified against the real image; the runtime stage is unchanged apart from one `COPY --from=ui` |
| 2026-11-02 | 4 | `GET /panel-api/v1/i18n` added (session-protected) instead of generated `src/i18n/locales/*.json` | the catalogs stay in `public/lang/*.php` with one importer (`lib/locales.php`); no second generated artefact to keep in sync, and adding a language stays a server-only change. `bin/i18n-export.php` is no longer needed |
| 2026-11-02 | 4 | Grid state lives in the URL via `history.replaceState` (`features/grid/useGridState.ts`), not TanStack Router search params | keeps a keystroke from pushing a history entry per character and keeps the pure state machine unit-testable; the router still owns `/devices/:id` deep links, and `popstate` re-reads the query |
| 2026-11-02 | 4 | `/meta` columns publish `api_key` (`panel_device_payload_keys()`) | the wire payload is snake_case (`sn`, `primary_local_ip`, `files_count`) while the schema is legacy PascalCase; the first integration run rendered empty cells because the SPA guessed the mapping. Now the server publishes it and the CSV export uses the same table |
| 2026-11-02 | 4 | `nextSort` returns to the server default (empty `sort`) on the third click instead of cycling to "unsorted" | there is no client-side ordering to fall back to; the default is `updated_at DESC` and is echoed in `/devices` `meta.sort` |
| 2026-11-02 | 4 | Physical inline-axis utilities stay banned, so the ESLint rule needed a documented exception for `_`-prefixed unused args | `nextSort(state, column, defaults)` keeps the defaults parameter for symmetry with the other helpers |
| 2026-11-02 | fix | `curl_close()` removed from `auth_keycloak.php` (PHP 8.5 deprecation) and `display_errors` forced off in `public/auth.php` | the notice was printed *before* the `Location:` header on the OIDC callback, which broke the Keycloak login with "Deprecated: Function curl_close() is deprecated…". Handles are objects freed at scope end since PHP 8.0, and a browser-facing redirect endpoint must never emit diagnostics |
| 2026-11-02 | 3 | Attachments are uploaded as multipart `POST /devices/{id}/files` (server-side S3 put) instead of presign + browser-to-bucket | `S3Client` only presigns GET URLs, and the legacy page already uploaded server-side; this reuses the working path and keeps credentials out of JavaScript. A presigned PUT can be added behind the same route later |
| 2026-11-02 | 3 | `expected_updated_at` stays optional on `PATCH` | scripts and the Bearer API keep the simple contract while the SPA always sends it; documented in `docs/frontend.md` |
| 2026-11-02 | 3 | Moving a device between companies is rejected (`company: immutable`) | the read paths scope every query by company, so allowing a move from one form would silently expose the row to the other tenant |
| 2026-11-02 | 3 | `bin/test-panel-api.php` drives the real handlers through a `$GLOBALS['APP_TEST_JSON_BODY']` seam in `request_json_body()` | gives CLI coverage of the real write path (role matrix, locking, cascade) without a web server or a second copy of the logic; only that script sets the global |
| 2026-11-02 | 4 | `/meta.writeColumns` (`create`/`update`) added instead of reusing `columns[].editable` | `editable` describes grid cells (7 role-filtered columns); a form needs create-vs-update plus every form column, and the role matrix must stay server-side |
| 2026-11-02 | 4 | The error boundary renders untranslated English | the failure may be the i18n catalogue itself, so a hard-coded fallback is the only message guaranteed to paint |
| 2026-11-02 | 5 | Column reordering uses `Move up`/`Move down` buttons, not pointer drag | keyboard-first, screen-reader friendly and directly testable; `@dnd-kit` stays pinned for the selection/bulk work |
| 2026-11-02 | 5 | Inline editing is limited to Status and boolean columns | the other editable columns (dates, e-mail, notes) need per-type editors; the edit form already covers them, and the grid keeps the two fields that are actually toggled in bulk |
| 2026-11-02 | 5 | `POST /devices/bulk` added to the panel API (not in §4) | a selection has to be one request with per-row results, not 25 fire-and-forget `PATCH`es; the route is panel-only (session + CSRF) so the Bearer API keeps its own `/v1/bulk` contract untouched |
| 2026-11-02 | 5 | Bulk actions are per-row, not one transaction | a stale or already-deleted row must not roll back the rows that did save; the response reports `updated` + `skipped[{id, code, message}]` and the grid shows "N updated, M skipped" |
| 2026-11-02 | 5 | The bulk value is validated once, before the loop (`status`, `byod`) | 200 rows would produce 200 identical `422`s; an invalid `value` or `ids` list fails the whole request instead |
| 2026-11-02 | 5 | `DataGrid` ignores row clicks that land on `input`/`select`/`button`/`a`/`label` | with inline edit and selection inside the row, a click on the status `<select>` used to navigate to the detail page (found by the Playwright detail-page spec); interactive elements now win |
| 2026-11-02 | 5 | `ui_selected_count`/`ui_bulk_done` interpolate `{{total}}`/`{{done}}`, not `{{count}}` | i18next rewrites a `count` option to plural keys (`_one`/`_other`), and the PHP catalogues are flat; the neutral variable keeps one key per string |
| 2026-11-02 | 5 | e2e fixtures keep a mutable session and echo `PATCH /session/preferences` | theme, locale and column-layout tests need the stubbed server to remember what was saved, otherwise "survives a reload" cannot be asserted |
| 2026-11-02 | 5 | `ui.yml` runs the Playwright suite in its own job, after `npm run build` | `vite preview` serves the production bundle; a separate job keeps a flaky browser test from blocking the typecheck/lint/vitest signal |
| 2026-11-02 | 5 | Release ZIP excludes `ui/node_modules`, `ui/dist`, `ui/test-results`, `ui/playwright-report`, `ui/coverage` but includes `public/app` | zip deployments have no build step, while those directories are reproducible build inputs/outputs (and `node_modules` is not even tracked) |
| 2026-11-02 | 7 | Settings writes reuse `settings_normalize()` + `settings_set()` instead of a second validator | the legacy page and the SPA must agree on `min`/`max`/option lists; the handlers only add the JSON shape and `ApiError` mapping |
| 2026-11-02 | 7 | `PATCH /settings` treats an empty string on a `secret` key as "unchanged" and needs `{"clear": [...]}` to wipe it | an untouched password field posts `""`; without this a settings save would erase a working S3 secret |
| 2026-11-02 | 7 | `PATCH /settings/users/{email}` refuses to change the caller's own role (`409 self_role_change`) | a superadmin who demotes themself locks the roster for every other administrator; the legacy page has the same guard |
| 2026-11-02 | 7 | `POST /settings/maintenance/migrate` defaults to `dry_run: true` | running DDL from a button click should be an explicit choice; the response returns the same log for both modes |
| 2026-11-02 | 7 | `bin/test-panel-api.php` asserts the self-role guard by logging in *as* a roster row | the CLI session (`tester@example.com`) is not a `users` row, so the natural test path returned `404` instead of the guard |
| 2026-11-02 | 8 | The RTL pixel snapshot is opt-in (`VISUAL_SNAPSHOTS=1`); the committed gate is geometry | a committed baseline compares the local font set with the CI runner's, so it fails for reasons unrelated to layout |
| 2026-11-02 | 8 | `ui/tsconfig.test.json` added and `tsconfig.app.json` excludes `*.test.*` | `vite build` must not type-check test files against the browser-only project, while `tsc -b` still has to cover them |
| 2026-11-03 | 8 | `smoke.spec.ts` stubs `/session` through a new `mockAnonymous()` fixture, and the CI reporter is `github` with `webServer.stdout/stderr` piped | the shell test was the only spec that touched the real backend: on the runner the preview proxy answers 502, Chromium reports it as a console error and the gate goes red; the annotations are also the only way to read a failure without an authenticated job log |
| 2026-11-03 | 8 | The CSV export assertion stays on the download URL, not the body | `page.route()` and `context.route()` do not intercept a download navigation (verified against a dead proxy), so the request really leaves the browser |
| 2026-11-03 | 7 | Route-table assertions live in `bin/test-panel-api.php` and run before the `db_try()` check | the literal-before-`{placeholder}` rule is pure logic; tying it to a database would hide it on machines without MySQL (it reports 25 checks and exits 0 there) |
| 2026-11-03 | 7 | `/app/settings` is one route with `?tab=`, not seven nested routes | the legacy page already models the tabs as `?tab=`, and a bookmark/reload keeps the tab either way; `settingsForm.ts` stays testable without a router |
| 2026-11-03 | 7 | The custom-field body uses the model's short keys (`key`, `type`, `default`, `required`, …) | `panel_settings_field_apply()` only copies the names it knows, so database column names would be accepted and silently ignored |
| 2026-11-03 | 7 | New catalogue keys (`set_tab_users`, `set_user_*`, `set_err_self_role`) in en-US, pt-BR and es-MX | the settings screens need a Users tab and the self-role hint; the other four locales fall back to en-US and stay above their coverage floor |
| 2026-11-04 | 9 | `ui.spa_enabled` flipped to `1` and `/` redirects, but the legacy pages stay on disk for one release | the plan's own rollout (D7): shipping the default and deleting the files in the same release would remove the rollback at the moment it is most likely to be needed |
| 2026-11-04 | 9 | The SPA renders a "turned off" notice from `features.spa` instead of `/app/` returning 404 | nginx serves `<base>/app/` from disk, so PHP cannot gate it; the session payload already carries the flag and the notice keeps the rollback one click away from `main.php` |
| 2026-11-04 | 9 | Anonymous visitors on `/` are redirected to `/app/`, not shown the legacy login form | the SPA owns login, the SSO bounce and the error states; keeping two login pages would let the two diverge again (§2.1) |
| 2026-11-04 | 2 | Migration `0007_assets_indexes` added as a repair step | `0004` is recorded as applied on installations that migrated before its index block existed, so `idx_assets_updated`/`idx_assets_deleted`/`idx_assets_company_source` were missing there; the EXPLAIN run measured a filesort over the whole table |
| 2026-11-04 | 2 | `idx_assets_source` and `idx_assets_status` added | `ORDER BY source` / `ORDER BY Status` cannot use `(company, source)` for an unrestricted account; 179 ms → 0.3 ms page time at 100k rows |
| 2026-11-04 | 2 | The `byod` filter compares normalised strings (`LOWER(TRIM(BYOD)) IN ('1','true','yes','on')`) instead of binding `1`/`0` | `BYOD` is a `VARCHAR`: MariaDB cast `'true'` to `0`, so `byod=true` silently matched nothing. Both legacy (`1`/`0`, `yes`/`no`) and current (`true`/`false`) spellings now match |
| 2026-11-04 | 2 | `files`/`custom:` sorts and free-text search stay linear and are documented as such | both sort on a computed expression (filesort over the whole scope, ~155 ms at 100k) and `q=` is a `LIKE '%…%'` over ~20 columns (~300+400 ms); the UI narrows with filters first, and keyset pagination/FULLTEXT stay in §14 |
| 2026-11-04 | 8 | `fr-FR`/`zh-CN`/`hi-IN`/`ar-SA` floors raised 15 % → 45 % and a per-locale family gate added to `coverage.test.ts` | the panel families are what a user actually reads; a percentage floor alone let a single new `col_*` key ship untranslated |
| 2026-11-04 | 6 | The tabbed drawer / `react-hook-form` + `zod` rewrite stays deferred (marked `[~]`) | the field list comes from `/meta.writeColumns`, so the controlled form has no blind spots, and `deviceForm.ts` is unit-tested; rewriting the only device-write screen in the same release that makes it the default UI would trade known-good behaviour for style |
| 2026-11-04 | 9 | The legacy pages are **not** deleted in this release | the rollback I just shipped links at `/main.php`, `bin/bootstrap.php` still points its maintenance banner at `settings.php?tab=maintenance`, and the plan (D7) stages the deletion as a separate breaking release with the `docs/screenshots` refresh |
| 2026-11-04 | 9 | 11.0.0 deletes the legacy panel **and** the `ui.spa_enabled` switch instead of keeping the flag as a no-op | a setting that no longer selects anything is worse than no setting: it would still be rendered in Settings → General and silently do nothing. `0008_retire_spa_flag` deletes the stored row, so an installation that had it at `0` upgrades to a working panel rather than a dead notice |
| 2026-11-04 | 9 | `public/index.php` keeps the `?code=` SSO callback (and `?action=logout`) after the pages are gone | Keycloak clients registered before `/auth/callback` existed point their redirect URI at `/index.php`; deleting the file would break their sign-in with a 404 after a successful login. The callback now redirects failures to `/app/?auth_error=<code>` so the SPA keeps owning the message |
| 2026-11-04 | 9 | nginx answers `404` for every `*.php` outside the four entry points instead of keeping the generic `location ~ \.php$` FastCGI block | with the pages deleted the generic block had nothing left to serve, and it would happily execute any file dropped into `public/` |
| 2026-11-04 | 9 | Dead helpers removed in the same commit (24 functions, 3 `lib/*_ui`/`*_actions` files) | they only existed for the deleted pages (`csrf_field()`, `flash_set()`, `field_render_input()`, `crypto_mask()`, …); leaving them behind makes the next reader believe there is a second UI to keep in sync |
| 2026-11-04 | 9 | `docs/screenshots` are generated by `ui/scripts/screenshots.mjs` against the dev stack, not by hand | the refresh has to be repeatable: the script signs in through the development login, drives the real theme/locale switchers and writes all six files, so the next UI change can re-run one command |
| 2026-11-04 | 4 | `loadLocale()` caches the whole payload (not a "loaded" flag) and `SessionGate` re-applies it on every locale change | switching *back* to a cached locale never re-ran the query (`staleTime: Infinity`), so the previous language stayed on screen until a reload, and `dir` could not be restored for RTL because `i18n.dir()` does not know `ar-SA` is RTL. The screenshot run caught it; `src/i18n/loadLocale.test.ts` and `e2e/preferences.spec.ts` now pin it |
| 2026-11-04 | 4 | The audit trail is a MariaDB table (`asset_events`), not a log file, and `asset_id` is not a foreign key | the question "who changed this device" is asked *after* a hard delete; a FK (or a log line nobody greps) would lose exactly that answer. A table also lets the detail page show it without shipping the log off the box |
| 2026-11-04 | 4 | `public/api.php` records events too (`api:<key label>`) | the plan's assumption was that "all writes already funnel through the panel API"; they do not — ESET-style integrations use the Bearer API. Recording only the panel would make the trail silently partial |
| 2026-11-04 | 7 | Rate limiting is a fixed window in a table, not a sliding window in APCu/Redis | neither extension exists in the image and PHP-FPM spreads requests over workers, so an in-process counter would allow N × workers writes. One upsert per mutation is cheaper than the alternative (a new service) |
| 2026-11-04 | 7 | The limit covers panel mutations only; `public/api.php` keeps its per-key limits on `api_keys` | the grid reloads `/devices` and `/session` constantly and a page load must never be throttled; integrations already have an explicit quota per key |
| 2026-11-04 | 3 | Attachments go through `lib/storage.php` (`storage.driver` = `s3`/`local`) and the download is a panel route, not a public bucket URL | the plan asked for the driver; the download route fell out of it: `local` files live outside `public/` and `s3` buckets stay private, so `GET /devices/{id}/files/{fileId}/download` is the only way to read one — and it is the same session/role check as the rest of the panel |
| 2026-11-04 | 2 | Fixed `panel_match()`: placeholder names may mix case | the helper only expanded `[a-z_]+`, so `{fileId}` never became a capture group and both attachment routes (`DELETE /devices/{id}/files/{fileId}` included) matched nothing — a file listed by the panel could not be removed over HTTP. The unit test drove the handlers directly, which is why it stayed hidden; the routing table now has explicit `{fileId}` assertions |
