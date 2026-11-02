# Settings, sources and custom fields

Everything on **Settings** (admin/superadmin only) is stored in the database,
so an installation can move away from `.env` without code changes.

## Precedence (DB → ENV → default)

Every field shows a badge telling which tier answered:

* **DB** — a row exists in `app_settings`.
* **ENV** — the environment variable (kept for backwards compatibility).
* **DEFAULT** — built-in default from `lib/settings.php`.

"Hits" on the DB tier have a *Reset to env/default* button that deletes the row.
Placeholder values such as `YOURS3BUCKET` are treated as "not configured".

Database credentials (`DB_*`), `APP_ENCRYPTION_KEY` and Keycloak stay
environment-only: they are needed *before* the settings table can be read.

## Tabs

### General
* Default language — combo box with the 7 supported locales (`en-US`, `pt-BR`,
  `es-MX`, `fr-FR`, `zh-CN`, `hi-IN`, `ar-SA`). Empty = follow `Accept-Language`.
* Timezone — applied to the panel and CSV exports; the API always returns UTC
  ISO-8601.
* Default theme (`system`, `light`, `dark`) and rows per page.
* Assets group: default source for panel-created assets and whether a serial
  number is mandatory.

### Storage (S3)
Region, bucket, endpoint, access key, secret key, path-style URLs, presigned URL
lifetime and key prefix. The secret is encrypted with AES-256-GCM using
`APP_ENCRYPTION_KEY` and is never echoed back (masked, "clear" checkbox).
**Test connection** performs a `HeadBucket` with the values in the form (or the
saved ones) before you commit them.

Generate a key once per environment:

```bash
openssl rand -base64 32
# docker/.env or your orchestrator secret:
APP_ENCRYPTION_KEY=...
```

Without the key the panel still works but secrets are stored with a `plain:`
prefix — the Storage tab and Maintenance tab show a warning.

### API
`api.enabled`, global IP allowlist (IPv4/IPv6/CIDR, one per line), whether to
trust `X-Forwarded-For`, and the maximum page size. Below the settings are the
API keys:

* create/edit: label, company scope, default source, allowed sources, IP
  allowlist, read/write/delete scopes;
* rotate (new token, old one invalid immediately), enable/disable, delete;
* the new token is displayed exactly once, with a copy hint.

See [api.md](api.md) for the endpoints.

### Sources
Provenance values for `assets.source`. `eset`, `api` and `manual` are seeded and
protected (`is_system`); custom ones can be added for other integrations and
deleted while unused. The default source gets `is_default=1` and a colour used by
the badges in the grid.

### Custom fields
Dynamic per-asset fields (EAV):

* key (immutable), label, optional per-locale labels;
* 12 data types; options for `select`/`multiselect`;
* default value, min/max/maxlength/pattern rules, required, unique, searchable,
  show-in-grid, editable roles, sort order, active flag.

Values live in `asset_custom_values` (one row per asset+field, JSON encoded) with
an index on `(field_id, value(64))`. That keeps ESET full-row rewrites from
destroying panel data and keeps filtering/sorting cheap.

Deactivating a field keeps its values; deleting it removes them.

### Maintenance
Schema status (applied/pending migrations), secret-encryption state, a *Run
migrations* button (with dry-run), and diagnostics (version, PHP, DB, timezone,
counts).

## Migrations

`bin/migrate.php` is idempotent and also callable from the panel:

```bash
php bin/migrate.php --status
php bin/migrate.php --dry-run
php bin/migrate.php
```

Applied versions are recorded in `schema_migrations`; every step is guarded by
`information_schema` checks because MySQL 8 has no `ADD COLUMN IF NOT EXISTS`.
For databases where the application user cannot run DDL, a DBA can apply
`docker/schema.sql` once (it also records the migration versions).

## Language support

Catalogues: `public/lang/<code>.php`. Resolution order is explicit choice →
Settings default → `Accept-Language` → `en-US`. Missing keys fall back to
`en-US`, then to the key itself. `ar-SA` switches the document to `dir="rtl"`.

`bin/check-i18n.php` validates the files, reports coverage per locale and fails
on unknown keys or missing en-US keys used in code:

```bash
php bin/check-i18n.php
```

Current coverage: en-US 100 %, pt-BR 95 %, es-MX 83 %, fr-FR/zh-CN/hi-IN/ar-SA
core UI (~20 %) with English fallback for the rest — extend the catalogues as
needed.
