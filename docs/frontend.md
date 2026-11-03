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

Everything else (writes, settings, users, attachments upload) is still phase 3
and phase 6–7 work; until then the legacy pages remain available at `/`.

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

## Conventions

* **Server owns the truth.** Sorting, filtering, paging and CSV export run in
  SQL; the grid never sorts a full dataset in the browser.
* **All network access goes through `src/api/client.ts`** — it adds the CSRF
  header, maps error envelopes to typed errors and handles `401` by clearing the
  session query.
* **URL is the state.** `?sort=&dir=&page=&per_page=&q=&source=&status=&company=&byod=&trashed=`
  is written with `history.replaceState` on every change, so a reload, a shared
  link and back/forward all reproduce the view (see `features/grid/gridSearch.ts`).
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
  keyboard-operable drag-and-drop, visible focus, AA contrast in both themes.

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
