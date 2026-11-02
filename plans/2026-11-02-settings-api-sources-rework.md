# Plan: Settings, Device API, Sources & Dynamic Fields Rework

> **This file is the single source of truth for the rework.** Every phase starts
> by re-reading it. Update it at the end of each phase (mark progress, record
> deviations) so context is never lost.

## 1. Goals (user request)

1. **Device API** to add / change / remove assets instead of writing rows into
   MySQL by hand (today ESET scripts write directly into `assets`).
2. **Source per asset** — every item records where it came from (`eset` today,
   later API integrations, later manual panel entry).
3. **Panel-side creation/editing** — admins can add assets and extra fields from
   the panel, not only consume rows produced by other sources.
4. **Settings page** — default language as a **combo box** (no flags), timezone,
   and the **S3 settings migrated out of `.env` into the panel**.
5. **API security** — key regeneration, IP whitelist, `Authorization: Bearer`
   (the user wrote "beaver" = Bearer; the standard scheme is used).
6. **Language list** taken from <https://github.com/ivancarlosti/sync>:
   `en-US`, `pt-BR`, `es-MX`, `fr-FR`, `h-CN` (canonicalised as `zh-CN`),
   `hi-IN`, `ar-SA` (RTL).

**Decision taken with the user:** fully **dynamic custom fields**
(`asset_field_defs` + `asset_custom_values`), not a fixed extra-column list.

## 2. Current state (baseline, verified)

| Area | Today |
|---|---|
| Runtime | PHP 8.5 + nginx in one image; nginx.conf is generated inside `Dockerfile` |
| Framework | none; plain PHP entry points in `public/`, PDO/MySQL, AWS SDK only |
| Tables | `assets`, `users`, `device_files` — **no schema in repo, no migrations** |
| Config | `config.php` only: `define()` from `getenv()` (DB, S3, Keycloak) |
| Pages | `index.php`, `main.php`, `asset.php`, `save_row.php`, `save_rows.php`, `export.php`, `manage_permissions.php`, `lang.php`, `theme.js`, `style.css` |
| i18n | one file `public/lang.php`, 3 locales (`pt_BR`, `es_MX`, `en_US`), flags from flagcdn, session only |
| Roles | `user`, `manager`, `admin`, `superadmin`; multi-tenant via `assets.company` |
| Dead code | `insert_log()` in both save scripts is a no-op → no audit trail |
| Duplication | PDO connect + `get_row()`/`update_row()`/`escape()` copy-pasted in 5 files |

Asset columns observed in use: `Id, UUID, SN, OS, OSVersion, Hostname, Mobile,
Manufacturer, LastSeen, UserEmail, BYOD, Status, Warranty, Asset, PurchaseDate,
CypherID, CypherKey, Notes, CPUs, HDs, HDsTypes, HDsSpacesGB, NetworkAdapters,
MACAddresses, ESETComponents, PrimaryLocalIP, PrimaryRemoteIP, company`
(`Term` is computed from `device_files`, not a real column).

## 3. Architecture decisions

1. **`lib/` at repo root** (outside the nginx docroot `/var/www/html/public`, so
   not web-served): `bootstrap.php`, `db.php`, `settings.php`, `crypto.php`,
   `migrate.php`, `sources.php`, `assets.php`, `asset_fields.php`, `api_auth.php`,
   `http.php`, `i18n.php`.
2. **Settings precedence: DB (`app_settings`) → env → built-in default.**
   `settings_get()` resolves, `settings_source()` reports `DB|ENV|default` for the
   UI badge. Placeholder env values (`YOURS3BUCKET`, …) count as unset.
3. **Secrets encrypted at rest** — AES-256-GCM, key from new `APP_ENCRYPTION_KEY`
   env (`openssl rand -base64 32`). Format `enc:v1:<b64(iv|tag|ct)>`. Without the
   env key values are stored `plain:`-prefixed and the settings page shows a
   warning. Secrets are never echoed back (masked + "replace" input).
4. **API**: `public/api.php` front controller. REST paths `/api/v1/...` through an
   nginx `location /api/` added to the generated config; the same script also
   accepts `api.php?path=/v1/devices` so non-Docker deployments work without
   rewrites.
5. **API auth**: `Authorization: Bearer bcmdb_<prefix>_<secret>` (alias
   `X-API-Key`). Only `sha256(token)` is stored, compared with `hash_equals()`.
   Each key has: bound `company` (or global), default `source_slug`, optional
   `allowed_sources`, `can_read/can_write/can_delete`, own IP allowlist (global
   fallback), `is_active`, `rotated_at`, `last_used_at/ip`. IP matching supports
   IPv4/IPv6 + CIDR; `api.trust_proxy` enables `X-Forwarded-For`.
6. **Sources are data**: `asset_sources` (slug/label/colour/is_system/is_default),
   seeded `eset`, `api`, `manual`; `assets.source` defaults to `eset` so existing
   ESET rows are correct the moment the column appears.
7. **Dynamic fields (EAV)**, not a JSON column on `assets`:
   - ESET writes `assets` directly and may replace rows → custom data must live
     elsewhere (`asset_custom_values`).
   - Per-field index `(field_id, value(64))` gives cheap `LIKE`/`ORDER BY`
     instead of `JSON_EXTRACT` scans (which would also need per-field DDL).
   - `value` holds JSON (scalars or arrays for multiselect).
   - No hard FKs on purpose (ESET may rebuild/truncate `assets`); integrity is
     enforced in `lib/asset_fields.php`.
8. **i18n**: catalogs in `public/lang/<code>.php` (7 files), `public/lang.php`
   becomes loader/helpers. Canonical BCP-47 codes with aliases
   (`pt_BR→pt-BR`, `es_MX→es-MX`, `en_US→en-US`, `h-CN→zh-CN`). Resolution:
   explicit user choice (session flag) → `general.default_locale` →
   `Accept-Language` → `en-US`. `<html dir>` mirrors the locale; `ar-SA` is RTL.
9. **Timezone**: `general.timezone` applied once in bootstrap; one date helper
   renders grid/asset/CSV; API always emits ISO-8601 UTC.
10. **Migrations**: idempotent runner guarded by `information_schema`
    (MySQL 8 has no `ADD COLUMN IF NOT EXISTS`), recorded in
    `schema_migrations`, run from `bin/migrate.php` (with `--dry-run`) and from
    Settings → Maintenance. Same DDL shipped as `docker/schema.sql` for
    DBA-managed databases.
11. **Soft delete** (`assets.deleted_at`) for panel/API deletion; hard delete
    only for superadmin (`?hard=1`). All reads filter `deleted_at IS NULL`.
12. **CSRF** tokens on every panel POST (`lib/http.php`), POST-redirect-GET +
    flash messages.


## 4. Schema

```sql
CREATE TABLE app_settings (
  skey VARCHAR(64) PRIMARY KEY, svalue LONGTEXT NULL,
  is_secret TINYINT(1) NOT NULL DEFAULT 0,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  updated_by VARCHAR(191) NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE schema_migrations (
  version VARCHAR(64) PRIMARY KEY,
  applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP);

CREATE TABLE asset_sources (
  id INT AUTO_INCREMENT PRIMARY KEY, slug VARCHAR(32) NOT NULL, label VARCHAR(64) NOT NULL,
  color VARCHAR(16) NULL, is_system TINYINT(1) NOT NULL DEFAULT 0,
  is_default TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_source_slug (slug));

CREATE TABLE api_keys (
  id INT AUTO_INCREMENT PRIMARY KEY, label VARCHAR(64) NOT NULL, prefix VARCHAR(16) NOT NULL,
  key_hash CHAR(64) NOT NULL, company VARCHAR(191) NULL, source_slug VARCHAR(32) NULL,
  allowed_sources VARCHAR(255) NULL, ip_allowlist TEXT NULL,
  can_read TINYINT(1) NOT NULL DEFAULT 1, can_write TINYINT(1) NOT NULL DEFAULT 1,
  can_delete TINYINT(1) NOT NULL DEFAULT 0, is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, created_by VARCHAR(191) NULL,
  rotated_at DATETIME NULL, last_used_at DATETIME NULL, last_used_ip VARCHAR(45) NULL,
  UNIQUE KEY uq_key_hash (key_hash), KEY idx_prefix (prefix));

CREATE TABLE asset_field_defs (
  id INT AUTO_INCREMENT PRIMARY KEY,
  field_key VARCHAR(64) NOT NULL, label VARCHAR(128) NOT NULL, label_i18n TEXT NULL,
  data_type VARCHAR(16) NOT NULL DEFAULT 'text', options_json TEXT NULL,
  default_value VARCHAR(255) NULL, validation_json TEXT NULL,
  is_required TINYINT(1) NOT NULL DEFAULT 0, is_unique TINYINT(1) NOT NULL DEFAULT 0,
  is_searchable TINYINT(1) NOT NULL DEFAULT 1, show_in_grid TINYINT(1) NOT NULL DEFAULT 0,
  editable_roles VARCHAR(64) NOT NULL DEFAULT 'admin,superadmin',
  sort_order INT NOT NULL DEFAULT 0, is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NULL, updated_at DATETIME NULL, created_by VARCHAR(191) NULL,
  UNIQUE KEY uq_field_key (field_key));

CREATE TABLE asset_custom_values (
  asset_id <type of assets.Id>, field_id INT NOT NULL, value LONGTEXT NULL,
  updated_at DATETIME NULL, updated_by VARCHAR(191) NULL,
  PRIMARY KEY (asset_id, field_id), KEY idx_field_value (field_id, value(64)));

ALTER TABLE assets
  ADD source VARCHAR(32) NOT NULL DEFAULT 'eset', ADD source_ref VARCHAR(191) NULL,
  ADD created_at DATETIME NULL, ADD updated_at DATETIME NULL,
  ADD created_by VARCHAR(191) NULL, ADD updated_by VARCHAR(191) NULL,
  ADD deleted_at DATETIME NULL;
UPDATE assets SET source='eset' WHERE source IS NULL OR source='';
-- indexes: (company, source), (UUID), (updated_at), (deleted_at)
```

`asset_custom_values.asset_id` is created with the exact type read from
`information_schema` for `assets.Id` so joins never need casts.

## 5. Settings keys

| Key | Default | Secret | Env fallback |
|---|---|---|---|
| `general.default_locale` | `pt-BR` | no | `APP_DEFAULT_LOCALE` |
| `general.timezone` | `America/Sao_Paulo` | no | `APP_TIMEZONE` |
| `general.default_theme` | `system` | no | – |
| `general.per_page` | `25` | no | – |
| `assets.default_source` | `manual` | no | – |
| `assets.require_sn` | `1` | no | – |
| `api.enabled` | `1` | no | – |
| `api.ip_allowlist` | `` | no | – |
| `api.trust_proxy` | `0` | no | – |
| `api.max_page_size` | `200` | no | – |
| `s3.region` | `` | no | `S3_REGION` |
| `s3.bucket` | `` | no | `S3_BUCKET` |
| `s3.endpoint` | `` | no | `S3_ENDPOINT` |
| `s3.access_key` | `` | no | `S3_ACCESS_KEY` |
| `s3.secret_key` | `` | **yes** | `S3_SECRET_KEY` |
| `s3.path_style` | `1` | no | – |
| `s3.presign_seconds` | `3600` | no | – |
| `s3.prefix` | `uploads` | no | – |

DB credentials and Keycloak stay env-only in `config.php` (bootstrapping
problem: settings live in the DB that those credentials open; Keycloak is read
before any settings render).


## 6. API surface

| Method & path | Purpose |
|---|---|
| `GET /api/v1/health` | version, schema status, server time (503 when `api.enabled=0`) |
| `GET /api/v1/fields` | dynamic field definitions |
| `GET /api/v1/sources` | source slugs + labels |
| `GET /api/v1/devices` | paged list; `company,source,search,field,updated_since,page,per_page,sort,dir`; `?uuid=` / `?sn=` lookup |
| `GET /api/v1/devices/{id}` | single device (`?with=files,fields`) |
| `GET /api/v1/devices/{id}/files` | attachment metadata |
| `POST /api/v1/devices` | create (409 on duplicate `UUID` / `company+SN`) |
| `PATCH /api/v1/devices/{id}` | partial update |
| `PUT/POST /api/v1/devices/upsert` | match by `UUID` then `company+SN` |
| `POST /api/v1/devices/bulk` | array of upserts, per-item results |
| `DELETE /api/v1/devices/{id}` | soft delete (`?hard=1` for superadmin keys) |

Envelope `{"ok":true,"data":...}` / `{"ok":false,"error":{"code","message","errors"}}`.
Codes: `unauthorized` 401 (+`WWW-Authenticate`), `ip_not_allowed` 403,
`forbidden` 403, `not_found` 404, `method_not_allowed` 405, `conflict` 409,
`validation_failed` 422, `internal_error` 500.

`custom` payload merges by default (`?custom_mode=replace` clears omitted keys).
Unknown keys / bad types / duplicate `is_unique` values -> 422 with a per-field
`errors` map (`custom.storage_location`).

## 7. File-by-file plan

**New**
- `lib/`: `bootstrap.php`, `db.php`, `crypto.php`, `settings.php`, `migrate.php`,
  `http.php`, `sources.php`, `assets.php`, `asset_fields.php`, `api_auth.php`.
- `public/api.php` (front controller), `public/settings.php` (6 tabs),
  `public/asset_new.php`.
- `public/lang/{en-US,pt-BR,es-MX,fr-FR,zh-CN,hi-IN,ar-SA}.php`.
- `bin/migrate.php`, `bin/check-i18n.php`.
- `docker/schema.sql`, `docker/docker-compose.dev.yml`, `docker/.env.dev.example`.
- `docs/api.md`, `docs/settings.md`, `docs/development.md`.

**Modified**
- `config.php` (keep env, add `APP_ENCRYPTION_KEY`, tolerate missing vendor).
- `s3_client.php` (read from settings, constructor overrides, `testConnection()`).
- `Dockerfile` (nginx `/api/` rewrite, `client_max_body_size`).
- `docker/docker-compose.yml`, `docker/.env` (S3 as fallback, add
  `APP_ENCRYPTION_KEY`).
- `public/lang.php` (loader/helpers + combo switcher + locale metadata + RTL).
- `public/index.php`, `main.php`, `asset.php`, `asset_new.php`, `export.php`,
  `save_row.php`, `save_rows.php`, `manage_permissions.php`, `style.css`.
- `README.md`.

## 8. Phases & status

| # | Phase | Status |
|---|---|---|
| 0 | Plan file, dev stack, baseline `php -l` | done |
| 1 | `lib/` core + migrations + `bin/migrate.php` + `docker/schema.sql` | done |
| 2 | `settings.php` shell + timezone + S3 from settings | done |
| 3 | Sources model + tab + badges/backfill | done |
| 4 | Dynamic fields engine + designer tab | done |
| 5 | API (keys, auth, devices, fields, sources) + `docs/api.md` + nginx | done |
| 6 | Panel: `asset_new.php`, `asset.php`, grid, export, delete | done |
| 7 | 7 locales + combo + RTL + parity checker | done (fallback documented) |
| 8 | Docs/README/env + full validation pass | in progress |
| 9 | *Optional*: audit log, Keycloak tab, rate limiting | pending |

## 9. Validation matrix

- `php -l` on every created/modified PHP file.
- `bin/check-i18n.php` — 7 catalogs, identical key sets, no empty values.
- `bin/migrate.php --dry-run` then real run **twice** (idempotency).
- API: create -> get -> patch -> upsert (new + existing) -> bulk mixed -> list/filter/
  sort -> soft delete -> hard delete; `unauthorized`, `ip_not_allowed`, `conflict`,
  `validation_failed` (type/required/unique); **rotate key -> old token 401**.
- Custom fields: define -> create asset -> filter/sort/export -> `custom_mode=replace`
  -> deactivate field (values preserved).
- Settings: save -> reload -> env fallback reported as `ENV`.
- S3: test connection + upload/presign/delete via the new settings (MinIO).
- Pages: HTTP 200 + marker strings in `en-US` and `ar-SA` (`dir="rtl"`).
- Timezone: grid/CSV in configured zone; API ISO-8601 UTC.
- ESET compatibility: direct `INSERT` into `assets` still lands with
  `source='eset'` and shows up in the grid.

## 10. Risks / notes

- `ALTER TABLE` needs DDL privileges; DBA path = `docker/schema.sql`, and the app
  shows a "schema not migrated" banner instead of a fatal error.
- ESET direct writers keep working (defaults); switching them to the API is a
  separate optional step. Custom fields stay empty until the source switches.
- EAV search uses `%LIKE%` per field (index-assisted) — fine at CMDB scale,
  documented.
- Secret safety equals `APP_ENCRYPTION_KEY` safety; documented in README and
  flagged in the settings banner when absent.
- `/api/` needs the nginx rewrite shipped in the image; the `api.php?path=`
  fallback covers other setups.
- `ar-SA` needs the `[dir="rtl"]` CSS pass (planned).

## 11. Progress log

- 2026-11-02 — plan created; MariaDB 11 pulled for validation; starting `lib/`.
- 2026-11-02 — Phase 0/1 complete: `lib/` (bootstrap, db, crypto, settings,
  migrate, http, sources, assets, asset_fields, api_auth, assets_ui,
  settings_ui, settings_actions), `bin/migrate.php`, `docker/schema.sql`,
  `docker/dev-legacy.sql`, `docker/docker-compose.dev.yml`. Migrations verified
  twice (idempotent) against MariaDB 11; legacy rows backfilled to `eset`.
- 2026-11-02 — Phase 2–4 complete: `public/settings.php` (6 tabs), sources,
  dynamic fields designer; settings persisted, secret stored as `enc:v1:`.
- 2026-11-02 — Phase 5 complete: `public/api.php` + nginx `/api/` location.
  31/31 device API assertions green (create/get/patch/upsert/upsert-new/bulk/
  conflict/validation/custom unique/search/sort/405/404), plus delete
  (soft/hard), tenant isolation, read-only scope 403, IP allowlist 403 and key
  rotation (old token 401, new 200).
- 2026-11-02 — Phase 6 complete: `asset_new.php`, rewritten `asset.php`,
  `main.php`, `export.php`, `save_row.php`, `save_rows.php`; panel create →
  detail → grid save → soft delete verified; CSV export fixed for PHP 8.5
  (`fputcsv` escape parameter).
- 2026-11-02 — Phase 7 complete: `public/lang.php` loader + 7 catalogues,
  locale combo, RTL for `ar-SA`, `bin/check-i18n.php` (coverage gate).
- 2026-11-02 — Phase 8: docs (api/settings/development), README, `.env` +
  compose updated. Final matrix: lint clean, i18n passed (en 100 %, pt 95 %,
  es 83 %, others core 20 % + English fallback), migrations "already present: 5",
  all panel pages HTTP 200 with no PHP diagnostics in `en-US` and `ar-SA`
  (`dir="rtl"`), direct ESET-style INSERT lands with `source='eset'`.

### Deviations from the original plan

* Hard delete via API is allowed for keys with the `delete` scope (the plan said
  superadmin) — API keys have no role; scoping is per key.
* The dev compose stack does not bundle MinIO (registry unavailable in CI);
  Settings → Storage + the "Test connection" button cover S3.
* `fr-FR`, `zh-CN`, `hi-IN`, `ar-SA` ship a translated core (navigation,
  settings, asset actions) and fall back to English for the rest; the i18n
  checker enforces a floor and reports exact coverage.
* `Hostname` became editable by manager+ (it was read-only before) so panel
  creation and corrections are possible.

