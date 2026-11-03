# Settings, sources and custom fields

Everything on **Settings** (admin/superadmin only) is stored in the database,
so an installation can move away from `.env` without code changes.

## Precedence (DB → ENV → default)

Every field shows a badge telling which tier answered:

* **DB** — a row exists in `app_settings`.
* **ENV** — the environment variable (`APP_DEFAULT_LOCALE`, `APP_TIMEZONE`;
  kept for backwards compatibility).
* **DEFAULT** — built-in default from `lib/settings.php`.

"Hits" on the DB tier have a *Reset to env/default* button that deletes the row.
Placeholder values shipped in `docker/.env.example` (anything starting with
`YOUR`) are treated as "not configured".

Object storage is the one exception to the table above: `s3.*` has **no** ENV
tier, so the values only ever live in the database (the secret encrypted). An
installation that used the old `S3_*` variables must enter them once under
Settings → Storage after upgrading.

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

### Attachment storage
The Storage tab has two cards: **Attachment storage** (`storage.*`) and **S3
connection** (`s3.*`). This card's **Driver** is `s3` (default) or `local`
(`lib/storage.php`). `local` keeps the attachments under `var/storage`, created
`0700` on first upload; nothing else changes, because the driver is selected at
upload time and the database row keeps the same object key.

The directory is **not** a setting. Both compose files bind-mount it
(`${BCMDB_UPLOADS_DIR:-../uploads}` → `/var/www/html/var/storage`, so the default
is `<checkout>/uploads`), and an editable path could point PHP-FPM outside the
mount, where the files would die with the container. `BCMDB_STORAGE_PATH`
overrides it for the test suite and non-Docker installs. Migration
`0013_retire_storage_local_path` drops the old row from `app_settings`; an
install that had set one must move its files to `var/storage`.

Boolean settings render first in each card with their explicit **Enable …**
label. Downloads go through
`GET /panel-api/v1/devices/{id}/files/{fileId}/download`, which streams the file
for `local` and redirects to a presigned GET for `s3`, so the bucket never has to
be public and the local directory never lives inside the document root. Use
`local` for a laptop, a lab install or a zip deployment without object storage;
`s3` for anything with more than one app container.

### Upload size
`PANEL_MAX_UPLOAD_BYTES` (`lib/panel/device_writes.php`) is the ceiling for both
drivers and is published to the SPA as `meta.limits.maxUploadBytes`: **100 MiB**.
The image raises nginx (`client_max_body_size 101m`) and PHP
(`upload_max_filesize = 100M`, `post_max_size = 101M`) above it, because PHP
silently turns an oversized body into an empty `$_FILES` — which the API now
reports as `422 file=too_large` instead of `file=required`.

### S3 connection
Region, bucket, endpoint, access key, secret key, path-style URLs, presigned URL
lifetime and key prefix. The secret is encrypted with AES-256-GCM using
`APP_ENCRYPTION_KEY` and is never echoed back (masked, "clear" checkbox).
**Test connection** performs a `HeadBucket` with the values in the form (or the
saved ones) before you commit them.

**Object key prefix** is a template, not a folder name. It ships as
`{{company}}-{{asset}}-` and expands per asset at upload time
(`storage_prefix_resolve()` in `lib/storage.php`):

| Placeholder | Value |
| --- | --- |
| `{{company}}` | company of the asset |
| `{{asset}}` | asset tag |
| `{{id}}` | asset id |
| `{{sn}}` | serial number |
| `{{hostname}}` | hostname |
| `{{source}}` | source slug |

Values are sanitised to `[A-Za-z0-9._-]`, so a company called `ACME / BR` cannot
invent a folder. A token with no value expands to nothing and the doubled
separator it leaves collapses (`{{company}}-{{asset}}-` on a tagless asset is
`ACME-`). An unknown token is refused on save (`422 invalid_placeholder`) rather
than being written into every key. The panel lists the tokens as chips under the
field and inserts them at the caret; the catalogue comes from
`settings_definitions()` (`s3.prefix.placeholders`), so the two cannot drift.

The join depends on the last character: a prefix ending in `-` or `_` runs into
the key (`ACME-NB-1-assets/42/x.pdf`), anything else is a folder
(`uploads/assets/42/x.pdf`). Expanding an empty template yields no prefix at all
(`assets/42/x.pdf`). Uploads are the only place the prefix is applied — the row
in `device_files.file_path` holds the final key, and downloads/deletes build the
client with `prefix => ''` — so **changing the prefix only affects new uploads**
and never strands a stored file. That also makes the default a behaviour change
for an instance that never saved a prefix: new keys start with the company and
asset tag instead of `uploads/`.

Any setting whose badge says **DB** has a **Reset to default** button
(`PATCH /settings` with `{"reset": [...]}`): it deletes the `app_settings` row so
the environment or built-in default answers again, and the form is re-seeded from
the response — the rest of the form is sent along, so edits in other cards are
not discarded. Secrets are the exception: they are wiped with `{"clear": [...]}`
and a reset on one answers `422 not_a_secret`.

Generate a key once per environment:

```bash
openssl rand -base64 32
# docker/.env (copy docker/.env.example) or your orchestrator secret:
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
Provenance values for `assets.source`. `api` and `manual` are seeded and
protected (`is_system`); custom ones can be added for other integrations and
deleted while unused. The default source gets `is_default=1` and a colour used by
the badges in the grid.

ESET is no longer seeded. Migration `0015` deletes an unreferenced `eset` row and
otherwise demotes it to a normal source; move its assets and API-key references
with **Move references** and then delete it. The move rewrites `assets.source`,
`api_keys.source_slug`, `api_keys.allowed_sources` and `assets.default_source`
or `asset_sources.is_default` in one transaction, and is audited as a source
update.

### Permissions
`/settings?tab=permissions` is the persisted role × capability and role × field
matrix (`role_capabilities`, `role_field_permissions`). It requires
`permissions.manage`, which only superadmin has by default.

* Capabilities gate every panel endpoint through `session_require_capability()`;
  the historical role lists are only the defaults.
* Field permissions control create/update. Read visibility keeps the existing
  company/role scope, so the matrix cannot hide data that the API still returns.
* Superadmin is immutable and always allowed, both in the matrix and in
  `session_require_capability()`; a row equal to the code default is deleted
  instead of stored, so a new capability inherits its intended default.
* Custom fields still keep `asset_field_defs.editable_roles` authoritative; the
  matrix and the Fields tab both update that column.

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

### Audit log retention
The Logs tab holds one setting: **`logs.retention_days`** (default 180, 7–3650).
It is the policy behind the audit trail (`asset_events`, `lib/logs.php`):

* the purge deletes rows older than the window in 5 000-row chunks, so a large
  table never locks;
* the panel has no scheduler, so `GET /session` triggers a check. A `log_purge`
  row records each run, and one run per 12 hours is enforced from it — a busy
  instance trims twice a day without any cron;
* `php bin/purge-logs.php` runs the same purge on demand
  (`--dry-run` counts, `--before=YYYY-MM-DD` overrides the window, `--status`
  shows retention, row count and the last purge);
* the Logs screen (`/app/logs`) lists the events and — when the role has
  `logs.purge` (superadmin by default) — offers a purge by date. Deleting history
  is instance-wide, so a company admin can read the log but not destroy it
  unless the matrix grants `logs.purge`.

Dates are inclusive in the configured timezone: "purge before 2026-06-01"
removes everything older than 2026-06-01 00:00 local time. Values written by a
settings write are recorded with secrets masked (`••••••`), never in clear.

### Maintenance
Schema status (applied/pending migrations), secret-encryption state, a *Run
migrations* button (with dry-run), and diagnostics (version, PHP, DB, timezone,
counts).

### Users (superadmin)
The `users` roster with one role per e-mail. The e-mail is the identity of the
row, so it is the path parameter of the write. The search box and company filter
work on the roster the endpoint already returned (no extra request while typing)
and a summary reports how many rows are shown. A superadmin cannot change their
own role (`409 self_role_change`): that would lock the instance out of user
management with no way back in from the panel.

## REST API (`/panel-api/v1/settings*`)

The SPA renders the same tabs against these endpoints; the handlers are thin
wrappers over `lib/settings.php`, so `/app/settings` and `bin/test-panel-api.php`
cannot disagree about validation, defaults or protection rules.

| Method + path | Capability | Notes |
|---|---|---|
| `GET /settings` | `settings.manage` | Settings grouped for the tabs + sources, locales, timezones and themes |
| `PATCH /settings` | `settings.manage` | `{ "values": {...}, "clear": [...], "reset": [...] }`; answers the refreshed payload + `meta.saved` |
| `GET /settings/sources` | `settings.sources` | Includes `usage` (assets per source) |
| `POST /settings/sources` | `settings.sources` | `{ slug, label, color, is_default }` |
| `POST /settings/sources/move` | `settings.sources` | `{ "from": "eset", "to": "manual" }`; moves assets + API-key refs/default |
| `PATCH /settings/sources/{slug}` | `settings.sources` | `slug` is immutable |
| `DELETE /settings/sources/{slug}` | `settings.sources` | `409 system_source` / `409 in_use` |
| `GET /settings/fields` | `settings.fields` | Definitions + `usage` + the type/role lists |
| `POST /settings/fields` | `settings.fields` | `{ key, label, type, options, default, validation, required, unique, searchable, in_grid, editable_roles, sort_order, active }` |
| `PATCH /settings/fields/{id}` | `settings.fields` | Same body; omitted keys keep their stored value |
| `DELETE /settings/fields/{id}` | `settings.fields` | Also drops the stored EAV values |
| `POST /settings/fields/reorder` | `settings.fields` | `{ "ids": [3, 1, 2] }` — literal path, registered before `{id}` |
| `GET /settings/permissions` | `permissions.manage` | Capability/field catalogue + `matrix` |
| `PUT /settings/permissions` | `permissions.manage` | `{ "capabilities": {role: {key: bool}}, "fields": {field: [roles]} }`; omitted sections untouched |
| `GET /settings/api-keys` | `settings.api_keys` | Keys (prefix only, never the hash) + sources |
| `POST /settings/api-keys` | `settings.api_keys` | The token is in `meta.token` **once** |
| `PATCH /settings/api-keys/{id}` | `settings.api_keys` | Scopes/label only, the token is untouched |
| `POST /settings/api-keys/{id}/rotate` | `settings.api_keys` | New token in `meta.token`; the old one dies immediately |
| `DELETE /settings/api-keys/{id}` | `settings.api_keys` | |
| `GET /settings/users` | `settings.users` | `email`, `company`, `role`, `is_self` |
| `PATCH /settings/users/{email}` | `settings.users` | `{ "role": "manager" }`, URL-encoded e-mail |
| `GET /settings/maintenance` | `settings.maintenance` | Migrations + diagnostics (no credentials) |
| `POST /settings/maintenance/migrate` | `settings.maintenance` | `{ "dry_run": true }` is the default; `meta.log` carries the steps |

Two rules are worth calling out because they protect working installations:

* **Secrets are write-only.** `GET` returns `value: ""` and `configured: true`, so
  the SPA never holds a stored secret. `PATCH` treats an empty string on a
  `secret` key as "unchanged"; wiping one is explicit (`{"clear": ["s3.secret_key"]}`).
* **`reset` is how a DB hit goes back to DEFAULT/ENV.** It deletes the row, audits
  old → new, and lists the key in `meta.saved`; `values` are applied first, so a
  reset always wins for its own key. Secrets are refused (`not_a_secret`) because
  `clear` is the operation that keeps the old value out of the audit trail.
* **Every entry carries `default` and `placeholders`.** The first is the built-in
  value the reset restores (never for a secret), the second is the `{{token}}`
  catalogue — only `s3.prefix` has one today.
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
`sql/schema.sql` once (it also records the migration versions). Because that
file records itself as applied, a table it forgets never reaches those
installations — `php bin/check-schema.php` fails when it and `lib/migrate.php`
disagree about a table or a version.

## Language support

Catalogues: `public/lang/<code>.php`. Resolution order is explicit choice →
Settings default → `Accept-Language` → `en-US`. Missing keys fall back to
`en-US`, then to the key itself. `ar-SA` switches the document to `dir="rtl"`.

`bin/check-i18n.php` validates the files, reports coverage per locale and fails on
unknown keys, on keys referenced from PHP/TSX that en-US lacks, and on en-US keys
that nothing references any more:

```bash
php bin/check-i18n.php
```

Current coverage: en-US 100 %, pt-BR 97 %, es-MX 88 %, fr-FR/zh-CN/hi-IN/ar-SA
56 % (the whole React panel — every `ui_*`, `col_*`, `status_*`, `theme_*` and
`role_*` string). The remaining `set_*` keys are the settings screens, which fall
back to en-US in those four locales; `ui/src/i18n/coverage.test.ts` keeps the
panel families complete so a new `col_*` key cannot ship untranslated.
