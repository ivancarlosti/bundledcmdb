# Development guide

## Requirements

* PHP 8.5 with `pdo_mysql`, `mbstring` (the model helpers use it for length
  checks) and `json`; the Docker image already has all three.
* MariaDB 11 / MySQL 8 for the database and 100k-row benchmarks.
* Node 24 for the SPA (`ui/`): `npm ci && npm run dev`.

## Layout

```
config.php            env constants (DB, Keycloak, APP_ENCRYPTION_KEY)
manifest.json         released version (the APP_VERSION fallback)
lib/                  shared PHP (no framework)
  bootstrap.php       single include for web/API/CLI
  db.php              PDO + information_schema helpers
  settings.php        app_settings with DB → ENV → default precedence
  crypto.php          AES-256-GCM for secrets
  migrate.php         idempotent migrations, ledger, sql/schema.sql drift check
  http.php            JSON envelope, ApiError, CSRF, IP/CIDR matching
  locales.php         supported locales + coverage helpers
  session_auth.php    session bootstrap, role guards, CSRF
  user_preferences.php per-user locale/theme/page size/grid layout
  rate_limit.php      fixed-window counters for panel writes
  storage.php         attachment storage (local disk or S3)
  sources.php         asset sources
  assets.php          asset model, validation, CRUD, list/search/sort
  asset_fields.php    dynamic fields (EAV) + value validation/rendering
  asset_events.php    audit trail for device writes
  saved_views.php     named grid presets
  api_auth.php        API keys + request authentication
  panel/              panel API route handlers (devices, writes, settings, meta, session, i18n)
public/               web root (nginx document root)
  index.php           entry point for / (redirects to /app/)
  panel_api.php       panel API front controller (session cookie + CSRF)
  api.php             REST front controller (API keys)
  auth.php            Keycloak login/callback/logout for the SPA
  lang.php + lang/    i18n loader + catalogues
  app/                built SPA (npm run build)
ui/                   React panel (Vite + TanStack Query)
  src/                screens, grid, auth, i18n bridge
  e2e/                Playwright suite (stubs the panel API, no database)
bin/
  migrate.php         schema migrations (CLI)
  check-schema.php    sql/schema.sql ↔ lib/migrate.php parity
  check-i18n.php      catalogue validation, coverage and dead keys
  check-ui.sh         SPA gates (typecheck, lint, format, vitest, build, e2e)
  test-panel-api.php  panel API integration suite
  test-api-users.php  /api/v1/users integration suite (roster + can_users scope)
  bench-devices.php   100k-row query benchmarks
  docker-entrypoint.sh  container start (owns var/storage, then php-fpm + nginx)
docker/
  .env.example                template — copy it to docker/.env first
  docker-compose.yml          app + external database
  docker-compose-bundle.yml   app + bundled MariaDB (local work)
sql/
  legacy.sql                  pre-migration tables (bundle first boot)
  dev-seed.sql                demo rows for local work (manual)
  schema.sql                  DDL for DBA-managed databases
docs/
  api.md, frontend.md, settings.md, development.md
```

## Local stack

```bash
cp docker/.env.example docker/.env   # once; set APP_ENCRYPTION_KEY
docker compose -f docker/docker-compose-bundle.yml up -d --build
docker compose -f docker/docker-compose-bundle.yml exec app php bin/migrate.php
```

* panel: <http://localhost:5600/> (Keycloak is stubbed; to test authenticated
  pages create a session cookie as shown below)
* API: <http://localhost:5600/api/v1/health>
* MariaDB: `127.0.0.1:33061` (`cmdb`/`cmdb`, database `bundledcmdb`)
* schema: the database is created from `sql/legacy.sql` (tables only)
* sample data: `sql/dev-seed.sql` (3 assets, 4 users, 2 tenants), loaded by hand:

```bash
docker compose -f docker/docker-compose-bundle.yml exec -T db \
  mariadb -ucmdb -pcmdb bundledcmdb < sql/dev-seed.sql
```

* development login: `APP_ENV=development` and `APP_DEV_LOGIN=1` in
  `docker/.env` (the shipped default is production-safe and leaves it off).
* attachments: with Settings → Storage → Driver = `local` the files land in
  `/var/www/html/var/storage`, bind-mounted from `<checkout>/uploads`
  (`BCMDB_UPLOADS_DIR` in `docker/.env` moves it), so they survive
  `docker compose down`. `bin/docker-entrypoint.sh` chowns the directory before
  php-fpm starts, because Docker creates a missing host directory as root.
  The ceiling is 100 MiB for both drivers (`PANEL_MAX_UPLOAD_BYTES`), with
  nginx at `101m` and PHP at `upload_max_filesize=100M`/`post_max_size=101M`.

Creating a session for manual API tests:

```bash
docker compose -f docker/docker-compose-bundle.yml exec -T -u www-data app php -r '
  session_id("testsess123"); session_start();
  $_SESSION["user_email"]="admin@example.com"; $_SESSION["role"]="superadmin";
  $_SESSION["company"]="Acme"; $_SESSION["lang"]="en-US"; session_write_close();'
curl -H 'Cookie: PHPSESSID=testsess123' http://localhost:5600/panel-api/v1/session
```

## CLI

```bash
php bin/migrate.php [--dry-run|--status]
php bin/check-schema.php        # sql/schema.sql ↔ lib/migrate.php (no database)
php bin/check-i18n.php          # missing, unknown and dead catalogue keys
php bin/test-panel-api.php      # panel API integration suite (needs a non-production DB)
php bin/test-api-users.php      # /api/v1/users suite (needs a non-production DB)
./bin/check-ui.sh               # SPA gates (add --e2e for Playwright)
```

`lib/bootstrap.php` requires `vendor/autoload.php` only when present, so these
scripts work without `composer install` (the S3 driver needs the AWS SDK; the
`local` driver in `lib/storage.php` does not, which is why it is the sane choice
for a laptop: Settings → Storage → Driver → *Local disk*).

## Validation checklist

1. `php -l` on every PHP file.
2. `php bin/check-schema.php` → `schema check passed.` It fails when a migration
   creates a table that `sql/schema.sql` (the DBA path) does not, or when the
   ledger in it is not up to `migrations()`.
3. `php bin/migrate.php --dry-run` → 0 pending; a second real run reports
   `Applied: 0, already present: 11`.
4. `php bin/check-i18n.php` → passed (floors in the table at the top of the file).
5. `php bin/test-panel-api.php` → 0 failed (requires a non-production database;
   the suite also covers the role matrix, source move and `source_slug` alias).
6. `php bin/test-api-users.php` → 0 failed (user roster CRUD/upsert, the
   `can_users` scope, company binding and session revocation; it creates and
   removes its own rows and keys).
7. SPA: `./bin/check-ui.sh --e2e` (typecheck, lint, format, vitest, build, then
   Playwright against `vite preview`; `--no-install` skips `npm ci`).
8. API smoke (`curl`):

```bash
TOKEN=... # Settings → API → Create key
B=http://localhost:5600/api/v1
curl -s -H "Authorization: Bearer $TOKEN" $B/health | jq
curl -s -H "Authorization: Bearer $TOKEN" "$B/devices?per_page=2" | jq '.data.total'
curl -s -X POST -H "Authorization: Bearer $TOKEN" -H 'Content-Type: application/json' \
     -d '{"company":"Acme","sn":"SN-DEV-1","hostname":"dev-1"}' $B/devices | jq
# needs a key with the Users scope
curl -s -H "Authorization: Bearer $TOKEN" "$B/users?per_page=2" | jq '.data.total'
```

9. Panel: `/app/`, `/app/devices/1`, `/app/devices/1/edit` and every
   `/app/settings?tab=…` render without console errors, in `en-US` and `ar-SA`
   (`dir="rtl"`).
10. ESET compatibility: `INSERT` a row into `assets` without `source` and check
    that it appears with the `eset` badge.
11. Version: `curl -s $B/health | jq -r .version` matches `manifest.json` (or the
    `APP_VERSION` the image was built with). `config.php` falls back to
    `manifest.json`; a literal there is how the panel reported 1.1.0 on 11.0.2.

## Conventions

* `declare(strict_types=1);` and typed signatures in `lib/` and new pages.
* Escape all output with `e()` (HTML) or `json_ok()` (API).
* Every panel mutation goes through the panel API and calls
  `session_csrf_require()`; never add a PHP page that writes on GET.
* Never trust `$_GET`/`$_POST` for column names: use `asset_column_defs()`,
  `field_defs()` and the whitelist in `asset_list()`.
* Timestamps are stored in UTC (`app_now_utc()`), rendered with `app_date()`.
* `ui/package.json` pins `minimatch@^11` through `overrides`: eslint's config
  loader still asks for `^10`, and 10.x carries a DoS advisory (GHSA-g3hv-75wg-m249).
  Remove the override when `@eslint/config-array` widens its own range.
* New settings must be declared in `settings_definitions()` (label + type +
  optional env fallback) to appear in the UI and in `settings_all()`.
