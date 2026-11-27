# Assets and Systems

Two tabs that sit right after **Devices** in the panel. They inventory the things a
CMDB usually forgets because they are not computers:

* **Assets** (`/app/assets`) — physical, non-IT objects: the meeting-room
  television, a second monitor, a desk, a projector.
* **Systems** (`/app/systems`) — software and services: Acrobat Pro seats, the
  identity provider, an antivirus console.

Both work the same way: a searchable, sortable, paged list; a form; and an
assignment of **any number of people**. What separates them from a device is that
one item can belong to several people at once — and that an item can belong to
nobody because the whole company shares it.

## Common usage

`common_usage` marks an item the whole company shares (the TV in room 2, the SSO
provider). Such an item is assigned to nobody:

* the form disables and clears the assignment picker;
* the server rejects a payload that sends both `common_usage: true` and a
  non-empty `users` list (`422 validation_failed`, `users =>
  common_usage_conflict`);
* turning the flag on deletes the assignment rows in the same transaction, so a
  direct API call cannot leave a shared item pointing at people.

Turning it back off leaves the item unassigned until somebody is named.

## Fields

| Field | Assets | Systems | Notes |
| --- | --- | --- | --- |
| `name` | ✓ | ✓ | required, ≤191 |
| `brand` | ✓ | ✓ | manufacturer ("Dell") or vendor ("Adobe") |
| `category` | ✓ | ✓ | **required** on create, one of the names of this kind in the `inventory_categories` registry (see [Categories](#categories)) |
| `model` | ✓ | — | ≤191 |
| `version` | — | ✓ | ≤64 |
| `serial` | ✓ | — | ≤191 |
| `location` | ✓ | — | ≤191 |
| `status` | ✓ | ✓ | the device vocabulary (`In Use`, `In Stock`, `In Repair`, `Decommissioned`, `Lost or Stolen`), so `status_*` and the status pill are reused |
| `purchase_date` | ✓ | ✓ | `YYYY-MM-DD` |
| `warranty_end` | ✓ | — | `YYYY-MM-DD` |
| `license_expiry` | — | ✓ | `YYYY-MM-DD` |
| `common_usage` | ✓ | ✓ | see above |
| `notes` | ✓ | ✓ | free text, line breaks preserved |
| `company` | ✓ | ✓ | tenant scope; immutable after create |
| `users` | ✓ | ✓ | assignment list (e-mail addresses from the company roster) |

A field of the other kind is rejected with `not_applicable`, never silently
dropped: sending `version` for an asset is a client bug.

## Categories

`category` is a controlled vocabulary per kind, so the same word cannot mean two
things in one tab and the list does not drift into `Monitor` / `monitor` /
`Monitors`. The names live in `inventory_categories` (migration
`2026_11_26_0026`, `sql/schema.sql` seeds the same rows) and a superadmin edits
them in **Settings → Categories** — an admin never sees the tab.

```
inventory_categories  id, kind ('asset'|'system'), name, sort_order, created_at/by
                      UNIQUE (kind, name)
```

The rules that follow from that table:

* **The name is stored, not an id.** `inventory_items.category` keeps the text
  (the same choice as `assets.source`), so a list row or an audit entry is
  readable without a join. The registry therefore only decides which names a form
  may submit, and a rename has to cascade.
* **`kind` is fixed per row.** The Systems form never offers an asset name and
  vice versa (`422 errors.category = "invalid_option"`), so "move it to the other
  tab" is a delete + create, not an edit.
* **Required on create** (`422 errors.category = "required"`) and never clearable
  on update: an uncategorised item would drop out of every filter.
* **A rename cascades** — the registry row and the items move in one transaction
  (`meta.items` says how many), so no item is left pointing at a name no form
  accepts any more.
* **A delete needs a home for the items.** Deleting a category that still has
  items answers `409 in_use` with the count; the caller names the receiver
  (`{ "reassign_to": "Other" }`, or `?reassign_to=Other` on a plain `DELETE`,
  because a proxy is free to drop a DELETE body). A target from the other kind is
  `422 invalid_target`. The last category of a kind is refused outright
  (`409 last_category`): with no vocabulary left the required field could never be
  satisfied again.
* **An unmigrated database still works.** Without the table the vocabulary cannot
  be verified, so every name is accepted and Settings shows an empty list.

The forms read the vocabulary from `GET /panel-api/v1/meta`
(`inventoryCategories: [{kind, name}]`), the grid's category `<select>` builds
its options from the same array, and a stored value that is not in it (legacy
data, a name deleted by hand) is kept as the current choice instead of being
silently replaced.

## Storage

Both tabs live in one table pair (migration `0025_inventory_items`), plus the
category registry added by migration `0026_inventory_categories`:

```
inventory_items       id, kind ('asset'|'system'), name, brand, category, model,
                      version, serial, location, status, purchase_date,
                      warranty_end, license_expiry, common_usage, notes, company,
                      created_at/by, updated_at/by
inventory_item_users  item_id, email, assigned_at, assigned_by   (PK item_id+email)
inventory_categories  id, kind, name, sort_order, created_at/by  (UNIQUE kind+name)
```

`kind` is what makes the two lists disjoint, and it is part of every query, so an
asset id can never resolve through `/systems`. Assignment rows are denormalised
e-mail strings (no foreign key, like the rest of the schema); deleting a roster
row also deletes its assignments.

`assets` keeps meaning **device** in this codebase (`asset_id`, `asset_events`,
`asset_column_defs()`), which is why nothing here is named `assets_*`.

## Permissions

| Capability | Default roles |
| --- | --- |
| `assets.view`, `systems.view` | user, manager, admin, superadmin |
| `assets.create`, `assets.update`, `systems.create`, `systems.update` | manager, admin, superadmin |
| `assets.delete`, `systems.delete` | admin, superadmin |

The tabs appear in the navigation only when the matching `view` capability is
granted; a hidden tab still answers `403` if reached directly. The assignment
picker reads `GET /panel-api/v1/users`, which any of `devices.update`,
`assets.update` or `systems.update` opens.

The category vocabulary behind the required `category` field is its own
superadmin area (`settings.categories`, see
[settings.md](settings.md#inventory-categories-superadmin) and
[Categories](#categories)); managers write items but never the list of allowed
names.

## Audit

Every write appends a row to `asset_events` with `entity` `asset` or `system`,
`entity_id`, `entity_label` (the name, kept after a delete) and the item's
company. Assignment changes appear as a single `users` diff (`old → new`,
comma-joined). Device history queries filter on `entity = 'device'`, so the two
histories never mix, and the Logs screen can filter on the two new entities.

## Not in this iteration

Attachments, CSV/Excel export, bulk actions, a trash/restore lifecycle, saved
views per tab, custom fields and `/api/v1` (Bearer) endpoints. Inventory deletes
are hard deletes; the audit row keeps the label of what was removed.
