# Device fields, structured disks and admin column layout

## Context

`lib/assets.php::asset_column_defs()` is the single source of truth for a device
column: the grid, the sort whitelist (`panel_sortable_columns()`), the "any"
search, the CSV/XLSX export, the permissions matrix (`permission_field_keys()`)
and the audit diff all derive from it. Only three things are not data-driven and
have to be edited by hand: the `/meta` label keys (`lib/panel/meta.php`), the
panel payload (`lib/panel/devices.php` + `panel_device_payload_keys()`) and the
SPA types/form-tab map.

Today the grid offers exactly the columns flagged `grid` in that table, and the
*only* way to hide or reorder one is the per-user layout in
`user_preferences.grid_state`. There is no installation-wide default and no way
for an admin to expose a column the code ships as "off" (CPUs, GPUs, RAM).

## Goal

1. Four new device fields — GPUs, installed RAM, logged-in username and device
   model — readable **and** writable through the REST API, with the panel form
   gated by role.
2. Replace the flat disk text (`HDs`/`HDsTypes`/`HDsSpacesGB`) as the primary
   source with a structured per-disk payload (`physical_disks`) fed by the
   inventory agent, while the three legacy columns keep working as derived
   summaries.
3. A **Settings → Columns** tab where an admin chooses which columns the grid
   offers, in which order, and optionally locks the layout so a per-user layout
   cannot override it.

## Steps

### 1. Migration `2026_11_04_0017_asset_hardware_disks`

`assets` gains: `GPUs VARCHAR(255)`, `RAMGB VARCHAR(255)`,
`LoggedUsername VARCHAR(191)`, `DeviceModel VARCHAR(191)`,
`PhysicalDisks LONGTEXT`, `DisksTotalGB DECIMAL(12,2)`,
`DisksFreeGB DECIMAL(12,2)`, `DisksUsedGB DECIMAL(12,2)` (all `NULL`).
Mirrored in `sql/legacy.sql` (fresh installs) and recorded in the
`sql/schema.sql` ledger, or `bin/check-schema.php` fails.

### 2. Structured disks (`lib/disks.php`)

`PhysicalDisks` holds one canonical JSON array; `disks_normalize()` validates
and bounds it (≤32 disks, ≤64 volumes each, text ≤191 chars, numbers ≥0 and
rounded to 2 decimals), accepting camelCase and snake_case spellings on input
and always answering snake_case. `used_gb` is optional: when the agent does not
send it the server derives `size_gb - free_gb` (same for
`volumes[].used_gb`) — the disk inventory never has to compute it.

Writing `physical_disks` also recomputes `HDs`, `HDsTypes`, `HDsSpacesGB`,
`DisksTotalGB`, `DisksFreeGB` and `DisksUsedGB` (`disks_summary()`), so the old
ESET-style text columns, the export and the sortable roll-ups stay in sync.
The roll-ups are `api => false`: only the normalizer writes them. Omitting the
key on `PUT`/`PATCH` leaves the disks untouched; `null`/`[]` clears them.

### 3. Admin column layout (`lib/grid_layout.php`)

One `app_settings` row `grid.columns` = `{"order": [...], "enabled": [...],
"locked": false}`. `panel_columns_payload()`/`panel_fields_payload()` compute
`in_grid` from `enabled` and publish the columns in `order`, so the grid, the
column picker and the export inherit the admin decision with no new SPA
concept. `locked` is published in `/meta` as `gridLayout.locked`; when it is on
the SPA ignores `session.gridState` (order **and** visibility) and disables the
picker. New endpoints: `GET/PUT/DELETE /panel-api/v1/settings/columns`
(capability `settings.manage`, audited like the other settings).

### 4. Surface

- `lib/assets.php`: defs, `asset_form_columns()`, `asset_create_columns()`,
  `asset_validate()` lengths, `asset_api_field_map()`, `asset_api_payload()`,
  and an optional by-ref error bag on `asset_normalize_input()` for
  `invalid_disks`/`invalid_number`.
- `lib/asset_events.php`: `PhysicalDisks` joins the ignored columns — history
  shows the readable summaries instead of a raw JSON blob.
- `lib/panel/*`: payload keys, export array guard, label keys, meta
  `gridLayout`, the three settings endpoints and their routes.
- UI: `api.ts` types, `columns.tsx` (`decimal` cell), `deviceSchema.ts` tabs,
  `DeviceGridPage` (locked layout), `DeviceDetailPage` (per-disk storage
  block), `SettingsPage` + new `ColumnsTab.tsx`, `useSettings.ts`,
  `settings/api.ts`, `e2e/fixtures.ts`.
- i18n: 23 new keys in the 8 catalogs (`bin/check-i18n.php` enforces en-US
  completeness and the per-locale floors).
- Docs: `docs/api.md` (fields + `physical_disks` contract and precedence),
  `docs/settings.md` (Columns tab), `docs/development.md` (checklist for adding
  a built-in column).
- Tests: `bin/test-panel-api.php` (four new keys, disk roundtrip, 422 cases,
  settings/columns roundtrip, `/meta` order + lock, export columns) and
  `ui/src/features/grid/columnLayout.test.ts`.

## Risks

1. **Deploy order.** The "any" search concatenates every `api` column, so the
   new code must not serve traffic before the migration ran. Run
   `bin/migrate.php` (or Settings → Maintenance) first.
2. **Summary columns are agent-owned.** `HDs`/`HDsTypes`/`HDsSpacesGB` were
   already read-only in the panel; they stay API-writable for the legacy push
   clients but any `physical_disks` write rebuilds them, so the two can never
   disagree. Documented in `docs/api.md`.
3. **Two switches for custom fields.** `FieldsTab.show_in_grid` (availability)
   and the new Columns tab (default visibility/order) both touch custom-field
   grid presence. Unifying them is a deliberate follow-up.
4. **JSON is validated in PHP only.** A manual `UPDATE` can store invalid JSON;
   `disks_decode()` returns `[]` in that case, so the UI degrades instead of
   crashing.
