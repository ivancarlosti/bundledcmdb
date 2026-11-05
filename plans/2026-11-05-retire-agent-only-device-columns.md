# Retire the agent-only device columns and nest Assignment inside Identity

## Context

`0017` added `DeviceModel`, `GPUs`, `RAMGB`, `DisksTotalGB`, `DisksFreeGB` and
`DisksUsedGB` to `assets`, and `0018` added the inventory-agent block
(`AgentModel`, `AgentGPU`, `AgentRAMGB`, …) plus `PhysicalDisks`. Two releases
later the six `0017` columns are duplicates:

* `DeviceModel` / `GPUs` / `RAMGB` hold the same facts the agent writes to
  `AgentModel` / `AgentGPU` / `AgentRAMGB`, but only admins can write the agent
  block while managers could type the legacy ones. Two writable paths for one
  fact means the panel shows two answers and the audit trail splits the blame.
* `DisksTotalGB` / `DisksFreeGB` / `DisksUsedGB` are sums over `PhysicalDisks`.
  They existed so the grid could sort on free space; they are a second copy of
  numbers that are already published, and they go stale whenever a client writes
  the JSON directly.

The device form also carried *Assignment* as its own top-level tab even though it
describes the same card (which machine, whose machine) as *Identity*.

## Goal

1. **Full removal** of the six columns: schema, code, API, panel payload, `/meta`
   labels, i18n catalogues, SPA types, fixtures, tests and docs. Values are not
   thrown away — the three hardware columns are backfilled into the agent block
   first, and the disk numbers were always derived.
2. **Assignment becomes a sub-block of Identity** on both the edit form and the
   detail page, reusing the existing `ui_form_tab_assignment` label.

## Steps

1. **Schema** — `lib/migrate.php`: add `migrate_drop_column()` (guarded by
   `db_table_exists()`/`db_column_exists()`) and its drift counterpart
   `migrate_sql_dropped_columns()`, so `migrate_dba_sql_drift()` also fails when
   a dropped column is still declared in `sql/schema.sql`. Register
   `2026_11_05_0019_retire_agent_only_columns` and write it as backfill-then-drop.
   Mirror it in `sql/schema.sql` (UPDATEs, six `DROP COLUMN`s, ledger entry).
   `sql/legacy.sql` stays untouched: it is the pre-migration snapshot and MySQL 8
   has no `DROP COLUMN IF EXISTS`.
2. **Seed** — `sql/dev-seed.sql` runs after `migrate`, so its `INSERT` must stop
   naming the six columns; the demo hardware values move to `AgentModel` /
   `AgentGPU` / `AgentRAMGB`.
3. **Server** — `lib/assets.php` (column defs, form/create lists, length caps,
   field map, payload), `lib/disks.php` (`disks_summary()` returns only
   `HDs`/`HDsTypes`/`HDsSpacesGB`), `lib/panel/devices.php` (payload + export
   keys), `lib/panel/meta.php` (`col_*` labels), `lib/asset_events.php` docblock.
4. **SPA** — drop the six keys from `DeviceRow`, `deviceSections.ts`,
   `PermissionsTab.tsx` and the e2e fixtures; `StorageSection` sums
   `physical_disks` for the footer total; keep the `decimal` renderer for custom
   fields.
5. **Assignment nesting** — `deviceSections.ts` turns the `assignment` section
   into a child of `identity` with `headingKey: 'ui_form_tab_assignment'`;
   `deviceSections.test.ts` and `ui/e2e/devices.spec.ts` assert the group lives
   inside `device-section-identity` / `device-group-assignment` and that no
   *Assignment* tab exists.
6. **i18n** — delete the six `col_*` keys from all seven catalogues. The family is
   dynamic, so `bin/check-i18n.php` cannot detect a leftover; this is hand work.
7. **Tests** — `bin/test-panel-api.php`: the disk block loses the roll-up
   expectations, the API/payload checks assert the keys are *absent*, the panel
   write tests assert `422 unknown_field` for all six (admin and manager), and
   `?sort=DisksFreeGB` becomes a `400 invalid_sort` guard.
8. **Docs** — `docs/api.md` (payload table, `hds*`-only derived table, 0019
   breaking-changes section), `docs/settings.md` (hidden-column examples),
   `docs/development.md` (drop helper, drift check, `already present: 19`).

## Risks

* **Data loss.** The migration drops columns. Mitigated by the backfill (only
  where `Agent*` is empty, so a real report wins) and by `sql/schema.sql` keeping
  the same order for DBA-managed databases. Anything else in those columns is
  gone on purpose; take a dump before upgrading.
* **A fresh install differs from an upgrade.** `sql/legacy.sql` still declares the
  columns, so a bundle install creates and then drops them. Covered by
  `check-schema.php` and by running `bin/migrate.php` against a database loaded
  from `legacy.sql` alone.
* **Stale build artefact.** `public/app/assets/index-*.js` is committed and still
  contains the six keys; forgetting `./bin/check-ui.sh` ships a bundle that
  renders columns `/meta` no longer publishes.
* **Saved layouts.** A stored `grid.columns` layout may name `DeviceModel` /
  `DisksFreeGB`; the grid ignores unknown keys, so the worst case is a column
  that quietly disappears from that user's view.
* **Integrations.** ESET-style scripts posting `gpus`/`ram_gb`/`device_model` now
  get `422 unknown_field` instead of a silent no-op — that is the point, but it
  has to be in the changelog (`docs/api.md` → Breaking changes).
