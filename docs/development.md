# Development guide

## Layout

```
config.php            env constants (DB, S3 fallbacks, Keycloak, APP_ENCRYPTION_KEY)
lib/                  shared PHP (no framework)
  bootstrap.php       single include for web/API/CLI
  db.php              PDO + information_schema helpers
  settings.php        app_settings with DB → ENV → default precedence
  crypto.php          AES-256-GCM for secrets
  migrate.php         idempotent migrations + ledger
  http.php            JSON envelope, ApiError, CSRF, flash, IP/CIDR matching
  sources.php         asset sources
  assets.php          asset model, validation, CRUD, list/search/sort
  asset_fields.php    dynamic fields (EAV) + value validation/rendering
  api_auth.php        API keys + request authentication
  session_auth.php    session bootstrap, role guards, CSRF
  panel/              panel API route handlers (devices, writes, settings, meta)
public/               web root (nginx document root)
  index.php           entry point for / (redirects to /app/, legacy SSO callback)
  api.php             REST front controller
  panel_api.php       panel API front controller (session cookie + CSRF)
  auth.php            Keycloak login/callback/logout for the SPA
  lang.php + lang/    i18n loader + catalogues
  app/                built SPA (npm run build)
bin/
  migrate.php         schema migrations (CLI)
  check-i18n.php      catalogue validation/coverage
docker/
  docker-compose.dev.yml  db + app for local work
  dev-legacy.sql          pre-migration schema + sample rows
  schema.sql              DDL for DBA-managed databases
docs/
  api.md, settings.md, development.md
```

## Local stack

```bash
docker compose -f docker/docker-compose.dev.yml up -d --build
docker compose -f docker/docker-compose.dev.yml exec app php bin/migrate.php
```

* panel: <http://localhost:5600/> (Keycloak is stubbed; to test authenticated
  pages create a session cookie as shown below)
* API: <http://localhost:5600/api/v1/health>
* MariaDB: `127.0.0.1:33061` (`cmdb`/`cmdb`, database `bundledcmdb`)
* sample data: `docker/dev-legacy.sql` (3 assets, 4 users, 2 tenants)

Creating a session for manual API tests:

```bash
docker compose -f docker/docker-compose.dev.yml exec -T -u www-data app php -r '
  session_id("testsess123"); session_start();
  $_SESSION["user_email"]="admin@example.com"; $_SESSION["role"]="superadmin";
  $_SESSION["company"]="Acme"; $_SESSION["lang"]="en-US"; session_write_close();'
curl -H 'Cookie: PHPSESSID=testsess123' http://localhost:5600/panel-api/v1/session
```

## CLI

```bash
php bin/migrate.php [--dry-run|--status]
php bin/check-i18n.php
```

`lib/bootstrap.php` requires `vendor/autoload.php` only when present, so these
scripts work without `composer install` (the S3 driver needs the AWS SDK; the
`local` driver in `lib/storage.php` does not, which is why it is the sane choice
for a laptop: Settings → Storage → Driver → *Local disk*).

## Validation checklist

1. `php -l` on every PHP file.
2. `php bin/migrate.php` twice → second run reports "already present: 5".
3. `php bin/check-i18n.php` → passed.
4. API smoke (`curl`):

```bash
TOKEN=... # Settings → API → Create key
B=http://localhost:5600/api/v1
curl -s -H "Authorization: Bearer $TOKEN" $B/health | jq
curl -s -H "Authorization: Bearer $TOKEN" "$B/devices?per_page=2" | jq '.data.total'
curl -s -X POST -H "Authorization: Bearer $TOKEN" -H 'Content-Type: application/json' \
     -d '{"company":"Acme","sn":"SN-DEV-1","hostname":"dev-1"}' $B/devices | jq
```

5. Panel: `/app/`, `/app/devices/1`, `/app/devices/1/edit` and every
   `/app/settings?tab=…` render without console errors, in `en-US` and `ar-SA`
   (`dir="rtl"`).
6. ESET compatibility: `INSERT` a row into `assets` without `source` and check
   that it appears with the `eset` badge.

## Conventions

* `declare(strict_types=1);` and typed signatures in `lib/` and new pages.
* Escape all output with `e()` (HTML) or `json_ok()` (API).
* Every panel mutation goes through the panel API and calls
  `session_csrf_require()`; never add a PHP page that writes on GET.
* Never trust `$_GET`/`$_POST` for column names: use `asset_column_defs()`,
  `field_defs()` and the whitelist in `asset_list()`.
* Timestamps are stored in UTC (`app_now_utc()`), rendered with `app_date()`.
* New settings must be declared in `settings_definitions()` (label + type +
  optional env fallback) to appear in the UI and in `settings_all()`.
