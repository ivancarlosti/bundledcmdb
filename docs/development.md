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
  assets_ui.php       shared form inputs
  asset_fields.php    dynamic fields (EAV) + value validation/rendering
  api_auth.php        API keys + request authentication
  settings_ui.php     settings page widgets
  settings_actions.php POST handlers for the settings page
public/               web root (nginx document root)
  api.php             REST front controller
  settings.php        settings UI
  main.php            asset grid
  asset.php           asset detail
  asset_new.php       create asset
  export.php          CSV export
  save_row.php        detail form handler
  save_rows.php       grid save / bulk delete
  lang.php + lang/    i18n loader + catalogues
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

Creating a session for manual page tests:

```bash
docker compose -f docker/docker-compose.dev.yml exec -T -u www-data app php -r '
  session_id("testsess123"); session_start();
  $_SESSION["user_email"]="admin@example.com"; $_SESSION["role"]="superadmin";
  $_SESSION["company"]="Acme"; $_SESSION["lang"]="en-US"; session_write_close();'
curl -H 'Cookie: PHPSESSID=testsess123' http://localhost:5600/main.php
```

## CLI

```bash
php bin/migrate.php [--dry-run|--status]
php bin/check-i18n.php
```

`lib/bootstrap.php` requires `vendor/autoload.php` only when present, so these
scripts work without `composer install` (S3 features need the AWS SDK).

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

5. Panel: `main.php`, `asset.php?id=1`, `asset_new.php`, every `settings.php?tab=…`
   return 200 without PHP notices, in `en-US` and `ar-SA` (`dir="rtl"`).
6. ESET compatibility: `INSERT` a row into `assets` without `source` and check
   that it appears with the `eset` badge.

## Conventions

* `declare(strict_types=1);` and typed signatures in `lib/` and new pages.
* Escape all output with `e()` (HTML) or `json_ok()` (API).
* All panel mutations are POST + CSRF (`csrf_field()` / `csrf_require()`).
* Never trust `$_GET`/`$_POST` for column names: use `asset_column_defs()`,
  `field_defs()` and the whitelist in `asset_list()`.
* Timestamps are stored in UTC (`app_now_utc()`), rendered with `app_date()`.
* New settings must be declared in `settings_definitions()` (label + type +
  optional env fallback) to appear in the UI and in `settings_all()`.
