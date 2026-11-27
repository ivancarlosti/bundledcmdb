# Assets and Systems tabs

## Context

The panel only inventories **devices** (`assets` + the `/devices` screens). Two
kinds of inventory have nowhere to live:

* **Assets** — physical things that are not computers: a television in the meeting
  room, a second monitor, a desk. They are shared, so one row often belongs to
  several people at once, and a company-owned item (the TV) belongs to nobody in
  particular and is simply "common usage".
* **Systems** — software and services the company runs: Acrobat Pro seats,
  Google as the identity provider, an antivirus console. Same assignment
  problem (a licence covers N people), same "common usage" case (the IdP is not
  assigned to anyone).

The device table cannot absorb them: `assets` is keyed by `SN`/`UUID`, carries an
agent block, files, custom fields and a soft-delete/trash lifecycle, and its
`UserEmail` column holds exactly one person.

## Goal

Two new tabs directly after **Devices** — **Assets** (`/assets`) and **Systems**
(`/systems`) — with list, create, edit, view and delete, multi-user assignment
and a **common usage** flag that disables individual assignment.

Out of scope for this iteration (deliberately, so nothing is implied):
attachments, export, bulk actions, trash/restore, saved views per tab,
`/api/v1` (Bearer) endpoints and custom fields.

## Steps

### 1. Storage — one catalogue, one join table

`kind` distinguishes the two tabs, so both share one model, one handler set and
one SPA feature folder. Kind-specific columns are simply never offered to the
other kind (`inventory_column_defs()` declares which kinds own a column).

```sql
CREATE TABLE inventory_items (
    id INT NOT NULL AUTO_INCREMENT,
    kind VARCHAR(16) NOT NULL,              -- 'asset' | 'system'
    name VARCHAR(191) NOT NULL,
    brand VARCHAR(191) NULL,                -- manufacturer ("Dell") / vendor ("Adobe")
    category VARCHAR(64) NULL,              -- free text: Monitor, Furniture, Software…
    model VARCHAR(191) NULL,                -- assets
    version VARCHAR(64) NULL,               -- systems
    serial VARCHAR(191) NULL,               -- assets
    location VARCHAR(191) NULL,             -- assets
    status VARCHAR(64) NOT NULL DEFAULT 'In Use',
    purchase_date DATE NULL,
    warranty_end DATE NULL,                 -- assets
    license_expiry DATE NULL,               -- systems
    common_usage TINYINT(1) NOT NULL DEFAULT 0,
    notes TEXT NULL,
    company VARCHAR(191) NULL,
    created_at DATETIME NOT NULL,
    created_by VARCHAR(191) NULL,
    updated_at DATETIME NOT NULL,
    updated_by VARCHAR(191) NULL,
    PRIMARY KEY (id),
    KEY idx_inventory_kind_company (kind, company),
    KEY idx_inventory_kind_status (kind, status),
    KEY idx_inventory_kind_name (kind, name),
    KEY idx_inventory_kind_updated (kind, updated_at)
);

CREATE TABLE inventory_item_users (
    item_id INT NOT NULL,
    email VARCHAR(191) NOT NULL,
    assigned_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    assigned_by VARCHAR(191) NULL,
    PRIMARY KEY (item_id, email),
    KEY idx_inventory_item_users_email (email)
);
```

`status` reuses `asset_status_values()`, so `status_*` translations and
`StatusPill` keep working and the vocabulary stays one list.

Assignment invariant: `common_usage = 1` deletes the join rows in the same
transaction, and a payload that sends `users` together with `common_usage: true`
is rejected (`users => common_usage`). The rule lives in the model, not in the
form.

### 2. Migration

`lib/migrate.php`: `migration_0025_inventory_items` registered as
`2026_11_26_0025_inventory_items` (idempotent `CREATE TABLE IF NOT EXISTS` +
`migrate_note()`). `sql/schema.sql` gets the same DDL **and** the ledger row —
`migrate_dba_sql_drift()` fails the schema check otherwise. `sql/dev-seed.sql`
gets a few rows (TV as common usage, a monitor with two users, a desk; Acrobat
Pro with users, Google IdP as common usage, VS Code).

### 3. Model — `lib/inventory.php`

```
inventory_kinds()                         ['asset', 'system']
inventory_column_defs()                   column => label, type, max, kinds[]
inventory_columns(string $kind)           ordered column list for one kind
inventory_status_values()                 shared with asset_status_values()
inventory_normalize_input(array)          trim, '' => null, unknown keys reported
inventory_validate(array, kind, partial)  field => error code
inventory_list(PDO, kind, filters)        rows with users[] + total
inventory_get(PDO, id, kind, company)     row with users[]
inventory_create/update/delete(...)       transactional, audited
inventory_assignments_sync(PDO, id, emails, actor)
inventory_audit(...)                      asset_event_record() with entity = kind
```

Validation: `name` required ≤191; `brand`/`model`/`serial`/`location` ≤191;
`category`/`version` ≤64; `status` in `inventory_status_values()`; dates
`YYYY-MM-DD` or empty; `users` must be addresses from the item's company roster
(`unknown_user`); `company` is immutable after create and forced for a
company-bound account.

### 4. Panel API — `lib/panel/inventory.php` + routes

| Method + path | Capability |
| --- | --- |
| `GET /panel-api/v1/assets`, `/systems` | `assets.view` / `systems.view` |
| `POST /panel-api/v1/assets`, `/systems` | `.create` |
| `GET /panel-api/v1/assets/{id}`, `/systems/{id}` | `.view` |
| `PATCH /panel-api/v1/assets/{id}`, `/systems/{id}` | `.update` |
| `DELETE /panel-api/v1/assets/{id}`, `/systems/{id}` | `.delete` |

List query: `q`, `status`, `category`, `common_usage`, `company`, `sort`,
`dir`, `page`, `per_page`; response `{rows,total,page,per_page,pages}` with
`meta.sort/dir/filters`. Unknown sort → `400 invalid_sort`; unknown filter value
→ `400 invalid_filter`. Writes are CSRF-required, honour `expected_updated_at`
(`409 stale_record`), and answer `201` with the created row. Company scoping
copies `panel_devices_where()`: a bound account never widens the scope, an
unbound one may filter or see everything.

### 5. Audit + permissions

* `ASSET_EVENT_ENTITIES` += `asset`, `system`; every write records
  `entity`/`entity_id`/`entity_label`/`company`, assignment changes appear as a
  `users` diff.
* `lib/permissions.php`: `assets.view/create/update/delete` and `systems.*` with
  `group` `assets` / `systems`; defaults `view = user+`,
  `create/update = manager+`, `delete = admin+`. Defaults live in the code, so no
  data migration is needed.
* `GET /users` (the assignment picker's directory) accepts any of
  `devices.update`, `assets.update`, `systems.update`.
* `user_delete()` also removes that e-mail's assignment rows.

### 6. SPA — `ui/src/features/inventory/*`

`api.ts`, `useInventory.ts`, `inventoryState.ts` (URL-bound filters/sort/page),
`columns.tsx` (per-kind columns, `StatusPill`, users/common-usage cell),
`InventoryGridPage.tsx`, `InventoryFormPage.tsx`, `InventoryDetailPage.tsx`,
`UserMultiSelect.tsx` (+ `inventoryState.test.ts`).

Routes: `/assets`, `/assets/new`, `/assets/$itemId`, `/assets/$itemId/edit` and
the same four under `/systems`. `AppShell` shows both links right after Devices,
gated by `assets.view` / `systems.view`.

The form disables and clears the assignment picker when **Common usage** is
checked, and says why; the grid shows "Common usage" in the users column instead
of a name list.

### 7. i18n

`ui_*` (tabs, form labels/hints, errors, confirmations), `col_item_*` (headers),
`perm_group_assets`/`perm_group_systems` + `perm_cap_*`, `logs_entity_asset`.
`ui_`/`col_` are translated in **all seven** catalogues: the vitest coverage test
fails a locale that misses one, and `bin/check-i18n.php` fails a key nothing
references.

### 8. Docs and checks

`docs/inventory.md` (what an asset vs a system is, the common-usage rule,
capabilities), `docs/frontend.md` (feature row + endpoints), `docs/api.md`
(note: panel-only), `README.md` (feature bullet + link).

Verification: `php -l` on every touched file, `php bin/check-schema.php`,
`php bin/check-i18n.php`, `php bin/migrate.php` on the dev database,
`php bin/test-panel-api.php`, `cd ui && npm run typecheck && npm run lint &&
npm test && npm run build`.

## Risks

* **Name collision.** "Asset" already means *device* here (`assets` table,
  `asset_id`, `asset_events`). All new identifiers are `inventory_*`; only the
  tab label and the audit entity say "asset".
* **Tenant leaks.** Every read/write carries `company = ?` for a bound account;
  cross-company ids answer `404`, never `403`.
* **Common-usage drift.** Enforced server-side inside the write transaction, so a
  direct API call cannot leave a shared item assigned to people.
* **Stale roster rows.** Deleting a user removes their assignments; an unknown
  e-mail in a payload is a validation error, never a silent insert.
* **i18n gates.** A missing `ui_`/`col_` translation fails `npm test`; a key with
  no call site fails `check-i18n.php`. Both run before the change is called done.
* **DBA-managed installs.** `sql/schema.sql` carries the DDL *and* the ledger row.
* **No trash.** Inventory deletes are hard deletes in this iteration; the audit
  row keeps the label of what was removed.

