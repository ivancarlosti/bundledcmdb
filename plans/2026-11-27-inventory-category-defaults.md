# Inventory category defaults and inventory vocabulary translations

## Context

Migration `2026_11_26_0026_inventory_categories` seeded a placeholder vocabulary
(`Display`, `Peripheral`, `Mobile`, `Network`, `Furniture`, `Other` for assets;
`Software`, `Service`, `Subscription`, `Platform`, `Other` for systems). Two
problems followed:

- a real catalogue has printers, servers and storage on the asset side, and
  operating systems, applications, identity providers, databases, security
  tooling and development tools on the systems side; picking `Other` for all of
  them makes the category filter useless;
- the required field is only usable if the shipped list covers the obvious
  answers, and `0026` is immutable (some installations already ran it).

While reviewing the screens the vocabulary feeds, the same audit found that the
inventory, audit-log, permission-matrix and category settings vocabulary was
still falling back to en-US in every locale except pt-BR (partially) and es-MX,
and that the device form's submit button said “Create asset” — the device tab is
not the asset tab.

## Goal

1. Ship the intended defaults: 8 asset names (`Display`, `Peripheral`,
   `Printer`, `Network`, `Server`, `Storage`, `Furniture`, `Other`) and 11 system
   names (`Operating system`, `Application`, `Software`, `Service`,
   `Subscription`, `Platform`, `Identity provider`, `Database`, `Security`,
   `Development tool`, `Other`), with `Other` last on both kinds.
2. Do it without deleting data: the new migration is additive (an existing name
   is never duplicated, `Mobile` stays because items may point at it) and only
   re-orders the shipped names, because `sort_order` is presentation.
3. Make `sql/schema.sql` (DBA import) seed the exact same final list and record
   the new version, so a fresh import and a migrated database agree.
4. Finish the translations for everything the inventory and access-control
   screens read — including the per-kind “Create asset/system” submit label —
   and gate those families in the unit suite so they cannot silently fall back.

## Steps

1. `lib/migrate.php`: add `2026_11_27_0027_inventory_category_defaults`
   (`INSERT IGNORE` per name + canonical `sort_order`, guarded by
   `db_table_exists`), register it behind `0026`.
2. `sql/schema.sql`: replace the seed block with the final 8 + 11 rows, add the
   version to the ledger.
3. `sql/dev-seed.sql`: drop the now-shipped `Identity provider` / `Developer tool`
   rows, keep the one-off `Monitor`, and point the VS Code demo row at the
   shipped `Development tool`.
4. Labels: `ui_create_device` for the device form (replacing the misleading
   `create_asset`) and `inventoryCreateKey()` → `ui_create_asset` /
   `ui_create_system` for the inventory form (replacing the generic `ui_create`);
   update the e2e fixtures/specs that clicked the old label.
5. Catalogues: add the 39 keys missing from pt-BR, the 66 missing from es-MX and
   the 105 missing from each community locale (settings errors, categories,
   theme labels, audit log, permission matrix, assets/systems capabilities).
6. Gates: raise `I18N_MIN_COVERAGE` to 100/100/70 % and add a
   `coverage.test.ts` case for the inventory and access-control families.
7. Docs: defaults table and upgrade behaviour in `docs/inventory.md`, defaults in
   `docs/settings.md`, coverage numbers, and the stricter gate in
   `docs/frontend.md`.

## Risks

- **A re-ordered vocabulary surprises an installation that sorted it by hand.**
  Only rows whose name is in the shipped list are re-numbered; admin-created
  names keep their `sort_order`, and `sort_order` has no semantic meaning.
- **`Mobile` diverges between fresh and upgraded databases.** A fresh install
  gets the 8 names, an upgraded one also keeps `Mobile`. Deleting it would break
  items that reference it, so the divergence is documented instead.
- **A renamed default comes back.** `0027` re-inserts any shipped name the admin
  renamed away; the restored row is an extra option, not data loss.
- **Community-locale translations are machine-assisted.** They cover the screens
  the gate enforces; native-speaker review is still wanted for tone, and the
  settings screens that remain in en-US are unchanged.
