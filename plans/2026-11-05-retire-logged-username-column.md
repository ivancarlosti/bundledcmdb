# Retire the manual `LoggedUsername` device column

## Context

`0017` added `assets.LoggedUsername` (`VARCHAR(191) NULL`) as a hand-typed
"Logged-in user" field. `0018` then gave the inventory agent its own block,
including `AgentLoggedUser`, which the report fills from its `loggedUser` key.
Since then the panel carries two fields for one fact and two writable paths:
managers can type `LoggedUsername` in *Identity → Assignment*, admins can edit
`AgentLoggedUser` in *Hardware → Agent inventory*, and the two can disagree
(`jdoe` vs `ACME\jdoe`) with nothing keeping them in sync.

`0019` already retired the same kind of duplicate (`DeviceModel`, `GPUs`,
`RAMGB` superseded by `AgentModel`, `AgentGPU`, `AgentRAMGB`). `LoggedUsername`
is the leftover it did not cover, so it follows the same route: full removal,
including the database column.

## Goal

Delete `assets.LoggedUsername` from schema, code, REST API, panel payload,
`/meta`, grid/export, SPA, i18n, fixtures, tests and docs. No backfill into the
agent block — the agent keeps filling `AgentLoggedUser` exactly as it does
today, and `user_email` remains the manager-editable "who holds it" field.

## Steps

1. **Migration** — `lib/migrate.php`: register
   `2026_11_05_0020_retire_logged_username` after `0019` and write
   `migration_0020_retire_logged_username()` as a single
   `migrate_drop_column($pdo, 'assets', 'LoggedUsername')` (helper added in
   0019, guarded by `db_table_exists()`/`db_column_exists()`). No backfill: a
   hand-typed value is stale by definition and the next agent report overwrites
   `AgentLoggedUser`.
2. **Schema** — `sql/schema.sql`: `ALTER TABLE assets DROP COLUMN LoggedUsername;`
   plus the ledger row, so a DBA-managed database ends up in the same state.
   `sql/legacy.sql` stays untouched (pre-migration snapshot; MySQL 8 has no
   `DROP COLUMN IF EXISTS`). `bin/check-schema.php` enforces this pairing.
3. **Seed** — `sql/dev-seed.sql`: drop `LoggedUsername` from the `INSERT` column
   list and remove the `'jdoe'` / `'asmith'` values (the demo rows already carry
   `AgentModel`/`AgentGPU`/`AgentRAMGB`, matching the 0019 pattern).
4. **Server** — remove the column from `lib/assets.php` (column def, form and
   create lists, length caps, `asset_api_field_map()`, `asset_api_payload()`),
   `lib/panel/devices.php` (`panel_device_payload()`,
   `panel_device_payload_keys()`), `lib/panel/meta.php` (`col_*` label). The
   permission matrix, sort whitelist, "any" search, grid and export derive from
   those lists, so they follow automatically.
5. **SPA** — drop `logged_username` from `DeviceRow` (`api.ts`), from the
   `assignment` section keys (`deviceSections.ts`), from `FIELD_LABELS`
   (`PermissionsTab.tsx`) and from both e2e fixtures. `ui/e2e/devices.spec.ts`
   needs no change (it asserts `user_email` in the Assignment group). Rebuild the
   served SPA with `./bin/check-ui.sh` (`public/app/` is gitignored build output,
   shipped inside the image).
6. **i18n** — delete `col_logged_username` from all seven catalogues (ar-SA,
   en-US, es-MX, fr-FR, hi-IN, pt-BR, zh-CN). The `col_*` family is dynamic, so
   `bin/check-i18n.php` cannot report a leftover; this is hand work.
7. **Tests** — `bin/test-panel-api.php`: the mapping/API assertions stop
   expecting `LoggedUsername`, and the panel write tests assert
   `logged_username` → `422 unknown_field` for admin and manager, like the
   retired 0019 keys. Agent-block assertions (`agent_logged_user`) stay.
8. **Docs** — `docs/api.md`: move `logged_username` from the payload table to the
   retired-keys paragraph and add a `### 0020 — LoggedUsername` breaking-change
   entry pointing at `agent_logged_user` (admin scope) and `user_email`.
   `docs/development.md`: `already present: 19` → `20` and mention 0020 in the
   retire checklist.
9. **Validate** — `php -l` on touched PHP, `php bin/check-schema.php`,
   `php bin/migrate.php --dry-run`, `php bin/migrate.php` twice
   (`already present: 20`), `php bin/check-i18n.php`, `php bin/test-panel-api.php`,
   `./bin/check-ui.sh --e2e`.

## Risks

* **Data loss.** The column is dropped and its values are not copied anywhere.
  That is the point (the agent is the only fresher source), but a client that
  posted `logged_username` and never sends reports loses what it typed. Take a
  dump before upgrading.
* **Breaking API change.** `logged_username` (and raw `LoggedUsername`) now
  answers `422 validation_failed` with `errors.logged_username = unknown_field`.
  Integrations move to `agent_logged_user` (admin scope) or `user_email`.
* **Role shift.** Managers lose a manager-editable "logged-in user". The
  Assignment group keeps `user_email`, which is the field meant for attributing a
  device to a person.
* **Fresh install vs upgrade.** `sql/legacy.sql` still declares the column, so a
  bundle install creates it in 0017 and drops it in 0020; covered by
  `bin/check-schema.php` and by running `bin/migrate.php` against a database
  loaded from `legacy.sql` alone.
* **Stale artefacts.** A saved `grid.columns` layout may still name the key (the
  grid ignores unknown columns) and a stored `role_field_permissions` row for
  `LoggedUsername` is inert, because the matrix iterates `asset_form_columns()`.
