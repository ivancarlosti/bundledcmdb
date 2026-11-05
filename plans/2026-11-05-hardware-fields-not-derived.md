# Stop deriving the Hardware text fields from the disk inventory

## Context

The **Hardware** card of the device view holds two different kinds of fact:

| Group | Fields | Owner |
|---|---|---|
| Hardware (manual rows) | `cpus`, `hds`, `hds_types`, `hds_spaces_gb`, `network_adapters`, `mac_addresses`, `eset_components`, `primary_local_ip`, `primary_remote_ip` | a human in the panel, or a legacy ESET-style client |
| Additional hardware info | the 15 `agent_*` columns + `physical_disks` (rendered as *Storage*) | the inventory agent (`POST/PUT /v1/devices/inventory`) |

Since 0018 the disk payload also summarised itself into the three manual text
columns: `asset_normalize_input()` (`lib/assets.php:302-306`) called
`disks_summary()` (`lib/disks.php:277-309`) and wrote `HDs`, `HDsTypes` and
`HDsSpacesGB` on **every** `physical_disks` write. Consequences:

* an agent report silently overwrote text an admin had typed, so the two groups
  were not independent even though the UI presents them as such;
* the same fact existed twice (JSON + text), and the `hds*` columns could drift
  into values that no longer matched any client input;
* the audit diff was noisy: every report "changed" three fields.

Migration 0019 already retired the numeric roll-ups (`disks_*_gb`) for exactly
this reason. This plan retires the last derived copy.

## Goal

1. `hds`, `hds_types` and `hds_spaces_gb` are **plain manual fields**: only a
   client or an admin writes them, and no agent report touches them.
2. `physical_disks` stays agent-fed and is displayed under **Additional hardware
   info → Storage**, independent of the text columns.
3. The agent endpoint keeps rejecting the three keys (`422 unknown_field`).
4. Permissions do not change: admins/superadmin edit them, managers/users read
   only (Settings → Permissions can still widen that per column).
5. Docs, seed data and tests describe the new split, with no "derived" left.

## Steps

1. **Write path** — `lib/assets.php`: drop the `disks_summary()` loop from
   `asset_normalize_input()`; `case 'disks'` now stores the JSON only. Update the
   `PhysicalDisks` column definition comment and the `asset_agent_columns()`
   docblock.
2. **Dead code** — `lib/disks.php`: delete `disks_summary()` and its helper
   `disks_gb_text()` (single consumer each); rewrite the file header, which still
   promises the text columns are derived.
3. **Comments that would become lies** — `lib/asset_events.php`
   (`ASSET_EVENT_IGNORED_COLUMNS`) and `lib/migrate.php`
   (`migration_0017_asset_hardware_disks()`).
4. **Docs** — `docs/api.md`: replace the "writing it recomputes the derived text
   columns" table with "the payload touches only the JSON"; fix the agent section
   and the breaking-change bullet; note the change in *Breaking changes*.
5. **Seed** — `sql/dev-seed.sql`: drop the "summaries follow on the next write"
   note and seed `HDsTypes`/`HDsSpacesGB` for the three demo rows (they are
   manual now, so nothing else would fill them).
6. **Tests** — `bin/test-panel-api.php`:
   * unit: a payload normalises to `PhysicalDisks` and nothing else;
   * integration: write manual text, report disks, assert the text survived;
   * clearing the JSON leaves the text columns alone.
7. **SPA** — `ui/src/features/devices/deviceSections.ts`: move `physical_disks`
   from the Hardware rows into the agent block; `DeviceDetailPage.tsx`: render
   `<StorageSection>` inside that block instead of at the card's end;
   `api.ts`: correct the `hds*` docblocks. Extend `deviceSections.test.ts` and
   tighten the e2e locator in `ui/e2e/devices.spec.ts`.
8. **Validation** — `php bin/test-panel-api.php`, `php bin/check-schema.php`,
   `php bin/check-i18n.php`, `cd ui && npm run test`, `./bin/check-ui.sh`.

No schema change and no migration: the three columns already exist, and the
values stored so far are kept as they are (frozen at the last derivation). A
cleanup pass was deliberately not written — the old values are still the best
guess for pre-existing rows, and nulling them would delete data an admin may be
reading.

## Risks

* **Silent emptiness.** Installs that relied on the derivation now show blank
  `hds*` for agent-only devices until someone fills them. Mitigation: the docs
  and the plan spell it out, and *Storage* renders the real inventory; no
  migration deletes the frozen values.
* **Stale text.** Rows derived before this change keep values that may not match
  `physical_disks` any more. Accepted: they are manual fields now, and the panel
  shows the JSON right below them.
* **Legacy clients.** A client that wrote `hds_types` and relied on the next
  agent report to refresh it now sees the stale value. Intended behaviour, but
  must be in the changelog.
* **Two tests changed meaning.** The disk tests asserted "follows the JSON";
  they now assert the opposite, which is the point.
* **UI regrouping.** Moving *Storage* into the agent block touches one component
  and one e2e locator; `rows()` already filters `type === 'disks'`, so no
  `[object Object]` cell can appear.
