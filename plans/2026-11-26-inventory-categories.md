# Inventory categories (registry-backed vocabulary)

## Context

`inventory_items.category` is a free-text `VARCHAR(64)` column offered by the
Assets and Systems forms (`lib/inventory.php`, `inventory_column_defs()`). Two
problems follow from that:

- the same thing is spelled three ways ("Monitor", "monitor", "MONITORS"), so the
  grid's `category` filter and the reports built on it fragment;
- the column is optional, although the catalogue is meant to be browsable by
  category — an item with no category is invisible to the only filter that makes
  a mixed inventory list readable.

`asset_sources` already shows the shape this codebase uses for a small
admin-managed vocabulary: rows in a table, superadmin-only Settings tab with
add/rename/delete, usage counts so a delete is explained before it is attempted.
Categories need the same, but **per kind** — an asset is a `Monitor` while a
system is a `Subscription`, and one shared list would offer nonsense options.

The device form already renders its company field as a `<Select>` from
`/meta.companies`; the inventory form still uses a free-text company input, and
its category input is a text box.

## Goal

1. A `inventory_categories` registry: one row per `(kind, name)`, seeded with
   defaults per kind, listed/added/renamed/deleted-with-reassign from
   Settings → Categories (superadmin, `settings.categories`).
2. `category` becomes required on create and non-clearable on update, validated
   against the registry (`invalid_option` otherwise). `inventory_items.category`
   keeps storing the *name* — no foreign key, matching the rest of the schema.
3. The Assets/Systems form renders `category` and `company` as dropdowns
   (`/meta.inventoryCategories`, `/meta.companies`) and the grid gains a
   category filter.
4. Existing databases keep working: an installation without the table is
   "cannot verify" and lets any category through; the migration backfills the
   distinct values already stored and rewrites empty ones to `Other`.

## Steps

1. **Migration** `2026_11_26_0026_inventory_categories` (`lib/migrate.php`):
   create the table (`UNIQUE(kind, name)`), seed defaults per kind (including
   `Other`), insert the distinct `(kind, category)` values already stored, then
   rewrite `NULL`/`''` to `Other`. Mirror in `sql/schema.sql` and add the version
   to its `schema_migrations` INSERT block (the drift test compares both).
2. **Model** (`lib/inventory.php`): `inventory_categories()`,
   `inventory_category_names()`, `inventory_category_get()`,
   `inventory_category_create()`, `inventory_category_rename()` (cascades to
   `inventory_items`), `inventory_category_delete()`, `inventory_category_usage()`.
   Flip the `category` definition to `type => 'category'` and add the
   required/invalid-option branch to `inventory_validate()`.
3. **Audit + capabilities**: `category` in `ASSET_EVENT_ENTITIES`,
   `settings.categories` in `permission_capability_defs()` (superadmin-only),
   `logs_entity_category` in the Logs label map.
4. **Endpoints** (`lib/panel/settings.php`, `lib/panel/routes.php`):
   `GET/POST /settings/categories`, `PATCH /settings/categories/{id}` (rename +
   cascade), `DELETE /settings/categories/{id}` (409 `in_use`,
   `last_category`; 422 `invalid_target`; `reassign_to` in the body or as
   `?reassign_to=`).
5. **`/meta`** (`lib/panel/meta.php`): publish `inventoryCategories` as
   `{kind, name}[]` plus the `MetaPayload` type on the SPA.
6. **UI**: `settings/api.ts` + `useSettings.ts` (queries/mutations invalidating
   `/meta` and `['inventory']`), `SettingsPage.tsx` tab, new `CategoriesTab.tsx`
   (grouped per kind, add/rename/delete-with-reassign); `InventoryFormPage.tsx`
   category and company `Select`s; `InventoryGridPage.tsx` category filter.
7. **i18n** (`public/lang/en-US.php`): `set_category_*`, `set_tab_categories`,
   `perm_cap_settings_categories`, `logs_entity_category`, `ui_select_category`.
8. **Tests**: `bin/test-panel-api.php` (routing, 403 matrix, CRUD, duplicate,
   required, `invalid_option`, rename cascade, `in_use`, reassign,
   `last_category` — exercised inside a transaction that is always rolled back,
   so the seeded vocabulary survives the run — and self-cleanup) plus `ui/e2e`
   fixtures and two `settings.spec.ts` cases (grouped registry with a locked
   rename kind; reassign-then-delete of a used category).
9. **Docs**: `docs/inventory.md`, `docs/settings.md`, `docs/frontend.md`.

## Risks

- **Backfill rewrites data.** Empty categories become `Other` and the migration
  is not reversible. Mitigation: `Other` is seeded on both kinds, the count is
  reported in the migration note, and the write is a single `UPDATE` restricted
  to `NULL`/`''`.
- **Rename cascade touches many rows.** `inventory_category_rename()` runs the
  registry update and the `inventory_items` cascade in one transaction, so a
  failure cannot leave items pointing at a name that no longer exists.
- **Delete can strand items.** Refused with `409 in_use` unless `reassign_to`
  names another category of the same kind (`422 invalid_target`); deleting the
  last category of a kind is refused (`409 last_category`) so the required
  create cannot be satisfied afterwards.
- **Unmigrated database.** Every helper checks `db_table_exists()`: no table
  means "cannot verify" and the write is allowed, exactly like
  `inventory_user_exists()`. The Settings tab then shows an empty list instead of
  erroring.
- **Old SPA / new API.** `category` is now required on create. Any other client
  posting without it gets `422 {category: required}` — visible, not silently
  stored as `NULL`.
