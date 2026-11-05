# Device inventory agent fields + view/form layout rework

## Context

The inventory agent posts a payload whose keys overlap the columns the panel
already had (`hostname`, `os`, `manufacturer`, `model`, `cpu`, `gpu`, `ip`,
`loggedUser`) and add facts that had no home at all (`build`, `bios`,
`secureBoot`, `antivirus`, `allLoggedUsers`, `executionUser`). The payload also
carries a structured `physicalDisks[]` inventory with nested `volumes[]`.

Two problems were reported from the device screens:

1. On the edit form only the disk-space field showed up and it sat under the
   **More** tab instead of **Hardware**.
   Cause: the panel payload spelled the key `hds_space_gb` while the SPA tab map
   expected `hds_spaces_gb` (`lib/panel/devices.php` vs
   `ui/src/features/devices/deviceSchema.ts`), so the field fell through to the
   catch-all tab. The same column had two spellings in two APIs — `/api/v1`
   documented `hds_spaces_gb`.
2. The new hardware fields were missing from the form and the detail page had
   everything in one flat list, which made a wide device unreadable.

The user's decision for this change: **duplicate, do not merge**. Every agent
payload key gets its own column in a separate "inventory agent" block; the
legacy columns are never written by the agent, and no agent value overwrites a
hand-maintained one.

## Goal

* Store every inventory-agent payload key in its own `Agent*` column.
* Keep `/api/v1/devices` semantics unchanged; give the agent its own entry point
  so the same wire key (`os`, `hostname`) cannot mean two different columns.
* Show the agent block on the device view and edit screens under the hardware
  section, and give the detail page a two-column layout.
* Rename the **More** tab to **Custom**.
* Make the "a column is declared in six parallel lists" class of bug impossible.

## Steps

1. **Schema** — `migration_0018_asset_agent_inventory` adds `AgentHostname`,
   `AgentOS`, `AgentOSBuild`, `AgentBIOS`, `AgentSecureBoot`, `AgentManufacturer`,
   `AgentModel`, `AgentCPU`, `AgentGPU`, `AgentRAMGB`, `AgentIP`,
   `AgentLoggedUser`, `AgentAllLoggedUsers`, `AgentExecutionUser`,
   `AgentAntivirus`. Mirrored in `sql/legacy.sql` (fresh installs), added as
   `ALTER TABLE` in `sql/schema.sql` and recorded in its ledger.
   `migrate_dba_sql_drift()` now also compares the columns each
   `migrate_add_column()` call adds with `sql/legacy.sql` + `sql/schema.sql`.
2. **One declarative table** — `asset_agent_columns()` in `lib/assets.php` is the
   single source for the column name, agent payload key, API key, type, length cap
   and label. `asset_column_defs()`, `asset_form_columns()`,
   `asset_api_field_map()`, `asset_api_payload()`, `asset_validate()` and the
   panel payload/export/label maps are derived from it.
3. **Agent entry point** — `POST|PUT /api/v1/devices/inventory` maps the payload's
   own spelling onto the `Agent*` block (`asset_agent_field_map()`); the public
   `/v1/devices*` endpoints keep mapping `os`/`hostname`/… onto the legacy
   columns. Writes need the existing `write` scope; nothing else changes.
4. **Panel API** — the 15 `agent_*` keys join the device payload, so the SPA reads
   and writes them like any other column. The payload key for
   `HDsSpacesGB` becomes `hds_spaces_gb` everywhere (the fix for problem 1).
5. **SPA** — `deviceSections.ts` holds the section layout once; `formTabs()`
   (edit form) and the new `detailSections()` (view) both read it, so the two
   screens cannot disagree again. Hardware keeps the disk fields and gains the
   agent block under an "Additional hardware info" heading; **More** becomes
   **Custom**; the detail page renders two columns of section cards.
6. **i18n** — 15 `col_agent_*` labels, `ui_agent_inventory`, `ui_form_tab_custom`
   in all seven catalogues.
7. **Tests/gates** — `deviceSchema.test.ts`, `deviceSections.test.ts`, `ui/e2e/*`,
   `bin/test-panel-api.php` (agent payload round-trip, `os` must not touch `OS`),
   `bin/check-schema.php`, `bin/check-i18n.php`, `bin/check-ui.sh`.

### Repairs the change needed along the way

* `ui/e2e/fixtures.ts` had no `capabilities.permissions`, so every e2e test died
  on `session.capabilities.permissions['logs.view']` (the shell reads the map the
  server publishes in `lib/panel/session.php`). The fixture now ships the per-role
  maps from `permission_capability_defs()` and `mockPanel()` fills the map in when
  a spec overrides the role.
* `ui/e2e/settings.spec.ts` expected 7 settings tabs; an admin sees 8 (General,
  Columns, Storage, Sources, API, Fields, Logs, Maintenance). Fixed there.

## Risks

* `/api/v1/devices/inventory` is a new, additive route; the existing device
  endpoints keep their field map, so current integrations are unaffected.
* The panel key `hds_space_gb` is renamed to `hds_spaces_gb`: panel-internal only,
  every consumer (SPA, fixtures, tests) is updated in the same change.
* 15 new columns widen `assets`; `AgentAllLoggedUsers` is an unindexed `TEXT`
  and is therefore part of the `any` `LIKE` search (same class as `Notes`).
* The agent block is admin/superadmin writable (like `GPUs`/`RAMGB`); managers
  see it read-only, so existing role expectations do not change.
