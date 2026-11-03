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

> The `ui.spa_enabled` switch is gone: 11.0.0 deleted the legacy PHP panel, so
> `/` always redirects to `/app/`. Migration `0008_retire_spa_flag` removes the
> stored row and the option is no longer rendered.

### Storage
**Driver** — `s3` (default) or `local` (`lib/storage.php`). `local` keeps the
attachments under `var/storage` (git-ignored, created `0700` on first upload) or
under **Local storage path** when you set one; nothing else changes, because the
driver is selected at upload time and the database row keeps the same object key.
Downloads go through `GET /panel-api/v1/devices/{id}/files/{fileId}/download`,
which streams the file for `local` and redirects to a presigned GET for `s3`, so
the bucket never has to be public and the local directory never lives inside the
document root. Use `local` for a laptop, a lab install or a zip deployment
without object storage; `s3` for anything with more than one app container.

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
trust `X-Forwarded-For`, the maximum page size and **panel writes per minute per
user** (`api.panel_rate_limit`, default 120). The last one is the fixed-window
counter in `lib/rate_limit.php`: every panel mutation (`POST`/`PATCH`/`PUT`/
`DELETE`) costs one hit and the 121st inside the same minute answers
`429 rate_limited` with `Retry-After`. Reads are never counted — the grid fetches
`/devices` and `/session` on every navigation. Below the settings are the API
keys:

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

### Users (superadmin)
The `users` roster with one role per e-mail. The e-mail is the identity of the
row, so it is the path parameter of the write. A superadmin cannot change their
own role (`409 self_role_change`): that would lock the instance out of user
management with no way back in from the panel.

## REST API (`/panel-api/v1/settings*`)

The SPA renders the same tabs against these endpoints; the handlers are thin
wrappers over `lib/settings.php`, so `/app/settings` and `bin/test-panel-api.php`
cannot disagree about validation, defaults or protection rules.

| Method + path | Role | Notes |
|---|---|---|
| `GET /settings` | admin | Settings grouped for the tabs + sources, locales, timezones and themes |
| `PATCH /settings` | admin | `{ "values": {...}, "clear": [...] }`; answers the refreshed payload + `meta.saved` |
| `GET /settings/sources` | admin | Includes `usage` (assets per source) |
| `POST /settings/sources` | admin | `{ slug, label, color, is_default }` |
| `PATCH /settings/sources/{slug}` | admin | `slug` is immutable |
| `DELETE /settings/sources/{slug}` | admin | `409 system_source` / `409 in_use` |
| `GET /settings/fields` | admin | Definitions + `usage` + the type/role lists |
| `POST /settings/fields` | admin | `{ key, label, type, options, default, validation, required, unique, searchable, in_grid, editable_roles, sort_order, active }` |
| `PATCH /settings/fields/{id}` | admin | Same body; omitted keys keep their stored value |
| `DELETE /settings/fields/{id}` | admin | Also drops the stored EAV values |
| `POST /settings/fields/reorder` | admin | `{ "ids": [3, 1, 2] }` — literal path, registered before `{id}` |
| `GET /settings/api-keys` | admin | Keys (prefix only, never the hash) + sources |
| `POST /settings/api-keys` | admin | The token is in `meta.token` **once** |
| `PATCH /settings/api-keys/{id}` | admin | Scopes/label only, the token is untouched |
| `POST /settings/api-keys/{id}/rotate` | admin | New token in `meta.token`; the old one dies immediately |
| `DELETE /settings/api-keys/{id}` | admin | |
| `GET /settings/users` | superadmin | `email`, `company`, `role`, `is_self` |
| `PATCH /settings/users/{email}` | superadmin | `{ "role": "manager" }`, URL-encoded e-mail |
| `GET /settings/maintenance` | admin | Migrations + diagnostics (no credentials) |
| `POST /settings/maintenance/migrate` | admin | `{ "dry_run": true }` is the default; `meta.log` carries the steps |

Two rules are worth calling out because they protect working installations:

* **Secrets are write-only.** `GET` returns `value: ""` and `configured: true`, so
  the SPA never holds a stored secret. `PATCH` treats an empty string on a
  `secret` key as "unchanged"; wiping one is explicit (`{"clear": ["s3.secret_key"]}`).
* **Validation is per key.** A rejected write answers
  `422 { "error": { "code": "validation_failed", "errors": { "<key or field>": "<code>" } } }`,
  the same envelope the device writes use, and the SPA renders each code next to
  its input (`set_err_*` catalogue keys).

`bin/test-panel-api.php` covers the whole surface, including the route ordering
(`/settings/fields/reorder` versus `/settings/fields/{id}`) that keeps a literal
segment from being read as an id.

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

Current coverage: en-US 100 %, pt-BR 96 %, es-MX 87 %, fr-FR/zh-CN/hi-IN/ar-SA
47 % (the whole React panel — every `ui_*`, `col_*`, `status_*`, `theme_*` and
`role_*` string). The `set_*` family still falls back to en-US in those four
locales; `ui/src/i18n/coverage.test.ts` keeps the panel families complete so a
new `col_*` key cannot ship untranslated.
