# Export every asset column in the CSV and Excel downloads

## Context

`GET /panel-api/v1/devices/export?format=csv|xlsx` was built as "the grid, but
without paging": `panel_devices_export_dataset()` (`lib/panel/devices.php`)
filtered `panel_columns_payload()` by `in_grid` and the custom fields by
`show_in_grid`. On a default installation that is 12 of the 52 columns `/meta`
publishes, so the download quietly dropped `Id`, `UUID`, `OSVersion`, `Mobile`,
`LastSeen`, `CypherID`, `CypherKey`, `Notes`, `CPUs`, the disk/network/MAC/ESET
text columns, both IP columns, `company`, `source_ref`, the audit columns
(`created_*`, `updated_*`, `deleted_at`), the whole inventory-agent block and
every custom field an admin had removed from the screen to keep it readable.

The export is meant to be the "everything about this asset" download, so column
visibility — a screen-only preference, and an installation-wide one at that —
must not decide what the file contains.

Secondary defect found while reading the code: `Id` is excluded from
`grid_layout_ids()`, so `grid_layout_apply_order()` pushed it to the end of the
list; and `PhysicalDisks` (`disks_decode()` → array) was written as an empty
cell ("no flat representation"), which would have turned it into a permanently
blank column once every column is exported.

## Goal

1. CSV and XLSX exports write **every** column `/meta` publishes for an asset,
   in the admin's layout order with `Id` first, plus **every active** custom
   field — regardless of `in_grid` / `show_in_grid` (the per-user picker was
   already ignored) — **except** `CypherKey` (the sync credential) and `Notes`
   (free-form text that tends to hold the same kind of secret), which never leave
   the panel in a file.
2. Filters and sort keep following the grid exactly: only the column set
   changes, never the row set.
3. `physical_disks` is exported losslessly as JSON instead of an empty cell.
4. Trash metadata (`deleted_reason`, `duplicate_of_id`) stays out of the export:
   those are not `/meta` columns, and adding them would need new `col_*` keys in
   all seven language catalogues for a fact that only exists on `?trashed=true`.

## Steps

1. **Plan** — this file.
2. **`lib/panel/devices.php`**
   - Add `panel_devices_export_excluded_keys()` (`CypherKey`, `Notes`: the
     credential and the free-text field that tends to hold secrets — dropped for
     *every* role and layout, so a spreadsheet cannot leak them),
     `panel_devices_export_columns()` (takes the full list, filters the excluded
     keys, hoists `Id` first) and `panel_devices_export_header()` (translates the
     column labels and the custom field labels once), so the header and the rows
     are built from the same ordered list and CSV/XLSX cannot drift. All three
     are pure: they take the lists as arguments and need no database, which is
     what makes them testable in the no-DB block.
   - `panel_devices_export_dataset()`: `$columns =
     panel_devices_export_columns(panel_columns_payload($user['role']))` and
     `$customFields = field_defs($pdo)` (all active fields, no
     `show_in_grid` filter), header through the helper.
   - `panel_devices_export_rows()`: `$gridFields` → `$customFields`, drop the
     duplicate `$defs` parameter (`custom_values_load()` takes the same list),
     and JSON-encode array values (`JSON_UNESCAPED_UNICODE |
     JSON_UNESCAPED_SLASHES`; empty list stays an empty cell). `fputcsv()` and
     `xlsx_xml_text()` already escape the quotes.
   - Docblocks (file header, `panel_devices_export_dataset()`,
     `panel_devices_export()`) say "every column except the credential/notes,
     grid visibility ignored" instead of "visible columns".
3. **`bin/test-panel-api.php`**
   - No-DB checks: `panel_devices_export_columns()` keeps the grid-hidden
     columns, drops `CypherKey`/`Notes` and puts `Id` first;
     `panel_devices_export_excluded_keys()` is the single name list;
     `panel_devices_export_header()` emits a label for an `in_grid = false`
     column.
   - DB block (in the settings/fields section, where a field is already flipped
     to `in_grid = false`): the export header starts with `Id`, is exactly
     `published − 2 + custom fields` wide (wider than the grid), contains
     `Agent GPU` and the grid-hidden custom field's label, contains neither
     `Cypher key` nor `Notes`, and every generated row is as wide as the header.
4. **Docs** — `docs/settings.md` (visibility bullet: grid + picker skip hidden
   columns, the export does not, except `CypherKey`/`Notes`), `docs/frontend.md`
   (the export endpoint row) and `README.md` ("every column except
   `CypherKey`/`Notes`").

## Risks

* **Exposure — resolved.** The first cut of the change exported *everything*,
  including `CypherKey` and `Notes`, to every holder of `devices.export` (granted
  to `user` by default). Those two are now dropped for every role and layout by
  `panel_devices_export_excluded_keys()`, so the download no longer carries the
  sync credential or the free-text notes. Every remaining column was already
  reachable through `GET /devices` for the roles that can export, so the export
  no longer widens the surface.
* **The exclusion list is code, not a setting.** Adding a third sensitive field
  means editing `panel_devices_export_excluded_keys()`; there is no admin toggle
  (deliberate: a toggle is one click away from leaking a credential again).
* **Wider sheets.** 50+ columns per row (plus custom fields). Neither
  `fputcsv()` nor `xlsx_build()` caps the width, but the file is a wide table.
* **Inactive fields stay out.** `field_defs()` defaults to `is_active = 1`, so a
  retired field does not reappear in the export. That is deliberate: an inactive
  definition has no place in the column catalogue any more.
* **Structured JSON in a cell.** `physical_disks` is now a JSON blob inside one
  CSV/Excel cell. It is lossless, but a spreadsheet user cannot sort or filter on
  its parts — the `HDs`/`HDsTypes`/`HDsSpacesGB` columns remain the flat ones.

## Validation

The suite needs `mbstring` + `pdo_mysql`, which the host PHP lacks, so it ran in
a container built from this checkout against a **throwaway** MariaDB
(`sql/legacy.sql` → `bin/migrate.php` → `sql/dev-seed.sql`); the local dev stack
was left untouched.

* `php bin/test-panel-api.php` — **539 passed, 0 failed** (12 export assertions:
  4 DB-free, 8 DB-backed).
* End-to-end dump of the real dataset on seeded data (`superadmin`, all
  companies): **50** header columns (`52 − CypherKey − Notes`), `Id` first,
  `Physical disks` as JSON (`friendly_name` found in the cell), `Cypher ID` and
  `Agent GPU` still present, 50 fields on every CSV line, and none of the seeded
  secrets (`KEY-1`, `Dev sample from ESET`, `Second tenant`) anywhere in the
  file. `.xlsx` header reaches its last cell (no width truncation), CSV BOM
  intact.
* Every `/meta` column key has a `panel_device_payload_keys()` mapping, so no
  column exports as a silently empty cell.
* `php bin/check-i18n.php` and `php bin/check-schema.php` pass (no `col_*` or
  schema change; excluding a column needs no new labels).
* Org pre-flight: no Dependabot PRs, 0 Dependabot alerts, 0 code-scanning
  alerts; `.dockerignore` still lists `docs/`, `plans/`, `docker/`, `.github/`
  and `.gitignore` still lists none of them.
* A virgin database (no rows in `companies`) fails two company assertions
  (`company: required`) because the suite derives `$companyA` from
  `asset_companies()`; seeding `sql/dev-seed.sql` clears them. Nothing in the
  export path is involved.
