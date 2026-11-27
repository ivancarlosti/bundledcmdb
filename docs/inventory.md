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
| `category` | ✓ | ✓ | free text (`Monitor`, `Furniture`, `Software`, …) |
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

## Storage

Both tabs live in one table pair (migration `0025_inventory_items`):

```
inventory_items       id, kind ('asset'|'system'), name, brand, category, model,
                      version, serial, location, status, purchase_date,
                      warranty_end, license_expiry, common_usage, notes, company,
                      created_at/by, updated_at/by
inventory_item_users  item_id, email, assigned_at, assigned_by   (PK item_id+email)
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
