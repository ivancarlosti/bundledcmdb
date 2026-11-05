# BundledCMDB REST API (v1)

The API is meant for source systems (ESET scripts, Intune/Jamf syncs, CI jobs)
that need to create, update or remove devices without touching MySQL directly,
and — since `can_users` — for the IdP/HR sync that maintains the `users` roster
that decides who may sign in to the panel.

* Base URL: `https://<host>/api/v1` (nginx `location ~ ^/api(/.*)?$` →
  `public/api.php`)
* Fallback without rewrites: `https://<host>/api.php?path=/v1/devices`
* Content type: `application/json` for requests and responses
* Auth: `Authorization: Bearer bcmdb_<prefix>_<secret>` (alias `X-API-Key`)

## Keys

Create keys in **Settings → API → Create API key**. Each key can be limited to

| Setting | Meaning |
|---|---|
| Company | key may only touch this tenant (empty = global) |
| Default source | `source` stamped on writes when the payload omits it |
| Allowed sources | whitelist; empty = every source |
| IP allowlist | IPs/CIDRs allowed to use this key (empty = global `api.ip_allowlist`) |
| Scopes | `read`, `write`, `delete`, `users` |

`users` is deliberately separate from `write`: a key that syncs devices (ESET,
Intune) must not be able to create accounts, and it is off by default. See
[Users](#users-the-roster) for what the scope grants.

The plaintext token is displayed **once**, right after creation/rotation. Only
`sha256(token)` is stored. Rotating a key invalidates the old token immediately.

## Envelope

```json
{"ok": true, "data": {}, "meta": {}}
{"ok": false, "error": {"code": "validation_failed", "message": "...", "errors": {"SN": "duplicate"}}}
```

| Code | HTTP | When |
|---|---|---|
| `unauthorized` | 401 | missing/invalid/disabled token |
| `ip_not_allowed` | 403 | client IP outside the allowlist |
| `forbidden` | 403 | scope/company/source not allowed for this key |
| `not_found` | 404 | unknown device/resource |
| `method_not_allowed` | 405 | wrong HTTP verb (see `Allow` header) |
| `conflict` | 409 | duplicate `UUID`/`SN` in the same company, or an e-mail that already has a roster row |
| `last_superadmin` | 409 | deleting/demoting the only `superadmin` row |
| `validation_failed` | 422 | unknown field, bad type, duplicate unique custom value |
| `invalid_json` | 400 | body is not valid JSON |
| `schema_not_ready` | 503 | migrations not applied |
| `api_disabled` | 503 | API turned off in Settings |
| `internal_error` | 500 | unexpected error (details in the app log) |

## Endpoints

### `GET /v1/health`
Version, schema state, server time (UTC), timezone and the calling key's scope.

### `GET /v1/sources`
`[{"slug":"api","label":"API","color":"#2b6cb0","is_default":false}]`

`eset` is no longer seeded. It is only returned on an existing installation
while an asset or API key still references it (the settings migration demotes it
instead of deleting it).

### `GET /v1/fields`
Dynamic field definitions (key, label, `label_i18n`, type, options, validation,
flags, `editable_roles`, default, `active`).

### `GET /v1/devices`
Query parameters:

| Param | Description |
|---|---|
| `company` | tenant (ignored/forced when the key is bound) |
| `source` | filter by source slug |
| `search` + `field` | `field` = column name, `custom:<key>` or empty (any field) |
| `uuid`, `sn` | direct lookup (returns at most one device) |
| `updated_since` | ISO-8601 or `YYYY-MM-DD HH:MM:SS` (UTC) |
| `page`, `per_page` | pagination (`api.max_page_size`, default 200) |
| `sort`, `dir` | column or `custom:<key>`, `asc|desc` |
| `include_deleted=1` | include soft-deleted rows |

Response: `{"devices":[],"total":n,"page":1,"pages":1}`

#### Measured cost at 100k rows

`bin/bench-devices.php` seeds a throwaway 100k-row fixture (deleted afterwards),
runs the SQL that `lib/panel/devices.php` builds through `EXPLAIN`, and prints
the wall time per access pattern. Measured on MariaDB 11.8, page size 25, 90k
live rows:

| Access pattern | count | page | Plan |
|---|---|---|---|
| default (`updated_at desc`) | 11 ms | 0.3 ms | `idx_assets_updated` |
| `sort=SN` asc/desc | 11 ms | 0.3 ms | `idx_assets_sn` |
| `sort=source`, `sort=Status` | 13 ms | 0.3 ms | `idx_assets_source` / `idx_assets_status` |
| `sort=files`, `sort=custom:<key>` | 12 ms | ~155 ms | filesort over the whole scope (computed expression) |
| `q=<term>` (`LIKE '%…%'` on ~20 columns) | 300 ms | 400 ms | index scan + filter |
| last page (`OFFSET 90000`) | 12 ms | 176 ms | `idx_assets_updated`, offset scan |

Two findings from that run are fixed in this release: `idx_assets_source`,
`idx_assets_status` were missing (179 ms → 0.3 ms for those sorts, migration
`0007_assets_indexes` also repairs installations that had already applied
`0004`), and `byod=true` matched nothing because a `VARCHAR` column was compared
against integer `1`.

Sorting by a computed value (`files`, `custom:<key>`) and free-text search are
inherently linear: combine them with `source`/`status`/`company` filters, which
is what the panel's UI does, instead of paging through 90k rows.

### `GET /v1/devices/{id}`
`?with=files` adds attachment metadata.

### `GET /v1/devices/{id}/files`
Attachment metadata (id, name, path, mime, size, uploaded_at).

### `POST /v1/devices`
Creates a device. Required: `company` (unless the key is bound) and `SN`
(unless `assets.require_sn` is disabled). `UUID` is optional but recommended.

```bash
curl -X POST https://cmdb.example.com/api/v1/devices \
  -H "Authorization: Bearer $TOKEN" -H 'Content-Type: application/json' \
  -d '{
        "company": "Acme",
        "uuid": "1f3a...",
        "sn": "SN-1234",
        "hostname": "acme-laptop-01",
        "os": "Windows 11 Pro",
        "status": "In Use",
        "user_email": "user@example.com",
        "last_seen": "2026-11-02T10:00:00Z",
        "custom": {"storage_location": "DC1", "asset_tag": "ACME-0001"}
      }'
```

Returns `201` with the stored device (including `source` and `custom`).

### `PATCH /v1/devices/{id}` (and `PUT`)
Partial update of built-in columns and custom values. Only the fields present in
the body change. `custom_mode=replace` clears custom fields that are omitted.

### `POST /v1/devices/upsert` (and `PUT`)
Matches by `UUID` first, then by `company`+`SN`; creates when nothing matches.
`meta.created` tells which branch ran (`201` on create, `200` on update).

### `POST /v1/devices/inventory` (and `PUT`)
The inventory-agent entry point. It behaves exactly like `/v1/devices/upsert`
(match by `UUID` then `company`+`SN`, `meta.created` tells which branch ran,
`write` scope, custom values accepted) with one difference: the payload's own
keys target the **agent block** instead of the legacy columns, so `os`,
`hostname`, `manufacturer` or `model` land in `AgentOS`, `AgentHostname`,
`AgentManufacturer` and `AgentModel`. A report therefore never overwrites a
hand-maintained value. See [Inventory agent](#inventory-agent).

```bash
curl -X POST https://cmdb.example.com/api/v1/devices/inventory \
  -H "Authorization: Bearer $TOKEN" -H 'Content-Type: application/json' \
  -d '{
        "company": "Acme",
        "sn": "SN-1234",
        "hostname": "acme-laptop-01",
        "os": "Windows 11 Pro",
        "build": "22631.4169",
        "bios": "Dell 1.14.2",
        "secureBoot": true,
        "manufacturer": "Dell Inc.",
        "model": "Latitude 5440",
        "cpu": "Intel Core i7-1365U",
        "gpu": "Intel Iris Xe Graphics",
        "ramGB": "16",
        "ip": "10.0.0.57",
        "loggedUser": "ACME\\jdoe",
        "allLoggedUsers": ["ACME\\jdoe", "ACME\\asmith"],
        "executionUser": "SYSTEM",
        "antivirus": "ESET Endpoint Security 11.1",
        "physicalDisks": [{"number": 0, "sizeGb": 476.94, "freeGb": 210.5}]
      }'
```

### `POST /v1/devices/bulk`
Body: `{"devices":[{},{ }]}` (or a bare array). Up to 500 items per request.
Each item is processed independently:

```json
{"results":[{"index":0,"ok":true,"created":true,"device_id":42},
            {"index":1,"ok":false,"error":{"code":"validation_failed","errors":{"status":"invalid_option"}}}],
 "summary":{"total":2,"created":1,"updated":0,"failed":1}}
```

### `DELETE /v1/devices/{id}`
Soft delete by default (`deleted_at`), hidden from lists. `?hard=1` removes the
row plus its custom values and attachment metadata (files stay in S3).

### `DELETE /v1/devices?uuid=<uuid>` or `?sn=<serial>`
Same operation, addressed by identity: one request instead of
`GET`-then-`DELETE /{id}`. Needs the `delete` scope and accepts the same
`?hard=1`. A missing/blank selector is `422` (`errors.uuid = "required"`).

A trashed row also carries `deleted_reason` (`duplicated` / `created_by_mistake`)
and `duplicate_of_id` **when the web panel deleted it** — the panel asks why and
stores the kept asset, this API does not take a reason. Both are `null` for rows
deleted here or before migration `0014`, and a restore clears them.

> The web panel does **not** use this API. It talks to its own session-authenticated
> `/panel-api/v1` (same envelope, CSRF header instead of a Bearer token), which
> shares the model layer with the endpoints above. Its route table, including the
> panel-only `POST /panel-api/v1/devices/bulk` used by the grid's bulk actions, is
> documented in [frontend.md](frontend.md#panel-api-used-by-the-spa-today).
>
> Every write — here and in the panel — appends a row to `asset_events`
> (`lib/asset_events.php`) with the actor (`api:<key label>` for Bearer calls),
> the action and the `[old, new]` value of each changed column. The panel reads it
> through `GET /panel-api/v1/devices/{id}/events` and shows it on the device page;
> the public API does not expose the trail.

## Users (the roster)

`users` is the authorisation roster: a Keycloak account can only sign in when its
e-mail also has a row here (`role` = `user|manager|admin|superadmin`, `company` =
tenant). These endpoints manage **that row only** — no Keycloak user is created,
updated or deleted, so credentials and MFA stay owned by the identity provider.
An account created here can sign in only after the Keycloak user exists; deleting
the row revokes the session on the next request.

All six routes require the `users` scope (`read`/`write` are neither enough nor
needed). `email` is the identity — it is the path parameter and cannot be
renamed (`422 errors.email = "immutable"`); delete and re-create instead.

| Method | Path | Body / query | Result |
|---|---|---|---|
| `GET` | `/v1/users` | `company`, `role`, `search`, `page`, `per_page` | `{"users":[…],"total":n,"page":1,"pages":1}`; `meta.roles` lists the catalogue |
| `GET` | `/v1/users/{email}` | – | one row, `404` when unknown |
| `POST` | `/v1/users` | `{"email","company","role"}` | `201` + row; `409` when the e-mail already exists |
| `POST`/`PUT` | `/v1/users/upsert` | same as create | `201`/`200` + row, `meta.created` |
| `PATCH`/`PUT` | `/v1/users/{email}` | `{"company","role"}` (partial) | `200` + row |
| `DELETE` | `/v1/users/{email}` | – | `{"email","role","deleted":true}` |

Rules enforced by the server:

* `email` is lower-cased, must be a valid address and ≤ 191 characters.
* `role` must be one of `user`, `manager`, `admin`, `superadmin`.
* `company` is required on create/upsert for a **global** key: an empty company is
  an unbound account that sees every tenant, so it has to be explicit. A
  **company-bound** key always writes its own company (`403` when the body names
  another one) and may never grant `superadmin` (`422 errors.role =
  "role_not_allowed"`).
* The last `superadmin` row cannot be demoted or deleted (`409 last_superadmin`).
* An unknown body key is `422 errors.<key> = "unknown_field"`.

Audit: every write appends to `asset_events` with `entity = user`,
`entity_id = <email>` and `actor = api:<key label>`, so roster changes appear in
Settings → Logs next to device changes.

> A global key with `users` is effectively an instance administrator: it can
> create a `superadmin`. Treat it like the Keycloak admin credentials.
>
> Deleting a row leaves the Keycloak account, the user's `user_preferences` /
> `saved_views` rows and the free-text `assets.UserEmail` values untouched.

## Recipes (n8n / curl)

n8n's **HTTP Request** node maps 1:1 onto the calls below: method + URL, *Body
Content Type: JSON*, and a header `Authorization: Bearer <token>` (an n8n
credential of type *Header Auth* works too).

### Upsert a device from a host inventory

```bash
curl -X POST https://cmdb.example.com/api/v1/devices/upsert \
  -H "Authorization: Bearer $TOKEN" -H 'Content-Type: application/json' \
  -d '{"company":"Acme","sn":"SN-1234","hostname":"acme-laptop-01",
       "user_email":"user@example.com","last_seen":"2026-11-03T10:00:00Z"}'
```

### Delete a device by serial number

```bash
curl -X DELETE "https://cmdb.example.com/api/v1/devices?sn=SN-1234" \
  -H "Authorization: Bearer $TOKEN"
```

### Maintain the roster from the IdP

```bash
# create or update one row (safe to retry)
curl -X POST https://cmdb.example.com/api/v1/users/upsert \
  -H "Authorization: Bearer $TOKEN" -H 'Content-Type: application/json' \
  -d '{"email":"jane@acme.com","company":"Acme","role":"manager"}'

# off-boarding: the next request of that session is rejected
curl -X DELETE https://cmdb.example.com/api/v1/users/jane%40acme.com \
  -H "Authorization: Bearer $TOKEN"
```

Send the e-mail percent-encoded in the path (`@` → `%40`); a literal `@` is
accepted as well.

### List the users of one tenant

```bash
curl "https://cmdb.example.com/api/v1/users?company=Acme&role=manager&per_page=100" \
  -H "Authorization: Bearer $TOKEN"
```

## Payload fields

Both `snake_case` API names and raw database column names are accepted
(case-insensitive). Unknown keys are rejected with `422`.

| API | Column |
|---|---|
| `uuid`, `sn` (alias `serial_number`), `hostname`, `os`, `os_version`, `manufacturer` | same |
| `mobile`, `byod` | booleans (`true/false`, `1/0`, `yes/no`) |
| `last_seen` | ISO-8601 or `Y-m-d H:i:s` (stored UTC) |
| `user_email`, `status`, `warranty`, `asset`, `purchase_date` | same |
| `cypher_id`, `cypher_key`, `notes`, `cpus`, `hds`, `hds_types` | same |
| `hds_spaces_gb`, `network_adapters`, `mac_addresses`, `eset_components` | same |
| `physical_disks`, `disks` (alias) | `assets.PhysicalDisks` (structured, see below) |
| `primary_local_ip`, `primary_remote_ip` | same |
| `company`, `source`, `source_ref` | same |
| `source_slug` | alias for `source` (same slug shown on Settings → API keys) |

`device_model`, `gpus`, `ram_gb`, `disks_total_gb`, `disks_free_gb` and
`disks_used_gb` are **retired** (migration 0019) and now answer `422
unknown_field`: the model, GPU and RAM facts belong to the inventory-agent block
(`agent_model`, `agent_gpu`, `agent_ram_gb`) and the disk numbers to
`physical_disks`. `logged_username` is retired the same way (migration 0020): the
agent reports the logged-in user as `agent_logged_user`, and `user_email` stays
the owner field. See [Breaking changes](#breaking-changes).

`source` is resolved as: payload → key default → `assets.default_source`
setting; it must exist and be allowed for the key. `source_slug` is accepted as
an input alias on device writes, so a payload built from the key configuration
can use the same name for both.

## Custom fields

`custom` is an object keyed by `field_key`:

```json
"custom": {"storage_location": "DC2", "warranty_months": 36, "tags": ["a","b"]}
```

* types: `text`, `textarea`, `integer`, `decimal`, `date`, `datetime`, `bool`,
  `select`, `multiselect`, `email`, `url`, `ip`
* `select`/`multiselect` values must be one of the configured options
* `is_unique` fields reject duplicates with `errors["custom.<key>"]="duplicate"`
* default merge behaviour keeps untouched values; `?custom_mode=replace` clears
  omitted ones

## Physical disks (`physical_disks`)

`physical_disks` is the canonical disk inventory: one array of objects per
physical disk, each with its volumes. Keys are **forgiving on input** (camelCase,
snake_case or `PascalCase` all land on the same member; `size`/`free`,
`bus`, `type`, `mount`, `label` are accepted shortcuts) and **always snake_case
on output**.

```json
"physical_disks": [
  {
    "number": 0,
    "friendly_name": "WD Green M.2 2280 480GB",
    "media_type": "SSD",
    "bus_type": "NVMe",
    "size_gb": 476.94,
    "free_gb": 210.5,
    "used_gb": 266.44,
    "volumes": [
      {"drive": "C:", "label": "Windows", "total_gb": 476.94, "free_gb": 210.5, "used_gb": 266.44}
    ]
  }
]
```

Accepted members: `number` (`index`/`slot`/`id`), `friendly_name` (`name`/
`model`), `media_type` (`type`/`kind`), `bus_type` (`bus`/`interface`),
`size_gb` (`size`/`capacity`/`total`), `free_gb` (`free`/`available`),
`used_gb` (`used`/`occupied`) and `volumes` (`partitions`). Volume members:
`drive` (`letter`/`mount`/`path`), `label` (`name`), `total_gb`, `free_gb`,
`used_gb`.

* **`used_gb` is optional** — when it is omitted the server derives it as
  `size_gb - free_gb` (and `total_gb - free_gb` for volumes), so an agent never
  has to compute it. A supplied value is kept.
* Limits: ≤ 32 disks, ≤ 64 volumes per disk, text ≤ 191 characters, numbers ≥ 0
  and ≤ 10 000 000 (GB), rounded to two decimals. Breaches answer
  `422` with `errors.PhysicalDisks` = `invalid_disks`, `invalid_number`,
  `too_many_disks` or `too_many_volumes` (error keys are the column names, like
  `errors.SN`).
* `null` or `[]` clears the inventory; omitting the key on `PATCH` leaves it
  untouched (`PUT` clears it, like every other omitted column).

Writing it touches **only** the JSON. `hds`, `hds_types` and `hds_spaces_gb`
are independent manual text fields (the ESET-era columns a client may still
write), so an agent report never fills nor clears them and a value typed into the
panel survives every report. Read the inventory back as `physical_disks`; a client
that needs a total sums `size_gb`/`free_gb` over the list (the `disks_*_gb`
roll-ups were retired in 0019 for exactly that reason — one copy of the numbers).

## Inventory agent

`POST /v1/devices/inventory` (or `PUT`) stores a host report in its own column
block. The keys are the ones the agent already sends; every one of them gets a
dedicated `Agent*` column so a report **never** overwrites a hand-maintained
`Hostname`/`OS`/`Manufacturer`. The block is the *only* home of the model, GPU
and RAM facts since 0019 retired the legacy `DeviceModel`/`GPUs`/`RAMGB`
columns; the panel shows it under *Hardware → Additional hardware info*, together
with the structured disk inventory (*Storage*).

| Payload key | Column | Output key |
|---|---|---|
| `hostname` | `AgentHostname` | `agent_hostname` |
| `os` | `AgentOS` | `agent_os` |
| `build` | `AgentOSBuild` | `agent_os_build` |
| `bios` | `AgentBIOS` | `agent_bios` |
| `secureBoot` | `AgentSecureBoot` | `agent_secure_boot` (JSON boolean) |
| `manufacturer` | `AgentManufacturer` | `agent_manufacturer` |
| `model` | `AgentModel` | `agent_model` |
| `cpu` | `AgentCPU` | `agent_cpu` |
| `gpu` | `AgentGPU` | `agent_gpu` |
| `ramGB` | `AgentRAMGB` | `agent_ram_gb` |
| `ip` | `AgentIP` | `agent_ip` |
| `loggedUser` | `AgentLoggedUser` | `agent_logged_user` |
| `allLoggedUsers` | `AgentAllLoggedUsers` | `agent_all_logged_users` |
| `executionUser` | `AgentExecutionUser` | `agent_execution_user` |
| `antivirus` | `AgentAntivirus` | `agent_antivirus` |

* The keys are matched case-insensitively (`ramGB`, `ramgb` and `AgentRAMGB` are
  the same field), and `agent_*` names are accepted on **every** device endpoint
  — that is what the panel form posts.
* `secureBoot` accepts `true/false`, `1/0`, `yes/no`, `on/off` and is stored as
  `'true'`/`'false'` (like `byod`), then published as a JSON boolean.
* `allLoggedUsers` takes a list and is stored `, `-joined (`AgentAllLoggedUsers`
  is `TEXT`, so its length is not capped; the other columns stay within the
  widths below).
* `physicalDisks` is **not** duplicated: the structured disk inventory keeps
  `PhysicalDisks` (see the previous section) and is independent of the manual
  `hds`/`hds_types`/`hds_spaces_gb` text columns, which this endpoint has never
  accepted.
* Identity and plumbing keys keep their usual meaning on this endpoint too:
  `uuid`, `sn`, `last_seen`, `mobile`, `byod`, `status`, `company`, `source`,
  `source_ref` and `custom`.
* Length caps: `AgentHostname`/`AgentCPU`/`AgentGPU`/`AgentRAMGB`/`AgentAntivirus`
  255, `AgentOS`/`AgentBIOS`/`AgentManufacturer`/`AgentModel`/`AgentLoggedUser`/
  `AgentExecutionUser` 191, `AgentOSBuild` 64, `AgentIP` 64. Exceeding one
  answers `422` with `errors.AgentOS = "too_long"`.
* The block is `admin`/`superadmin` writable in the panel; managers get
  `422 unknown_field` and read it in the device view.
* The columns are off by default in the grid (Settings → Columns), so they do not
  widen every view; `/v1/devices` always returns them (`agent_*` keys).

## Breaking changes

### 0020 — `LoggedUsername`

Migration `2026_11_05_0020_retire_logged_username` **drops**
`assets.LoggedUsername`, the hand-typed "Logged-in user" the device form showed
under *Identity → Assignment*. The inventory agent already reports that fact as
`AgentLoggedUser`, so the column was a second writable path for the same value
(managers typed one, admins edited the other, and the two could disagree).

Nothing is copied: the agent refreshes `agent_logged_user` on its next report,
which makes a hand-typed value stale by definition.

| Retired field | Read it from |
|---|---|
| `logged_username` | `agent_logged_user` (inventory report) |
| — | `user_email` (the manager-editable "who holds the device") |

What this means for an integration:

* Sending `logged_username` (or the raw column name `LoggedUsername`) answers
  `422 validation_failed` with `errors.logged_username = unknown_field`. Use
  `agent_logged_user`, which only `admin`/`superadmin` may write, or `user_email`.
* Reading a device no longer returns `logged_username`; the JSON key is absent,
  not null. `agent_logged_user` and `agent_all_logged_users` are unchanged.
* `GET /v1/devices?sort=LoggedUsername` answers `400 invalid_sort`; sort on
  `AgentLoggedUser` instead.
* An existing value in that column is discarded by the drop — take a dump before
  upgrading if anything still reads it.

### 0019 — agent-only device columns

Migration `2026_11_05_0019_retire_agent_only_columns` **drops** six `assets`
columns. The values are copied into the agent block first (only where the agent
has not reported yet), then the columns are gone:

| Retired field | Read it from |
|---|---|
| `device_model` | `agent_model` |
| `gpus` | `agent_gpu` |
| `ram_gb` | `agent_ram_gb` |
| `disks_total_gb` | sum `size_gb` over `physical_disks` |
| `disks_free_gb` | sum `free_gb` over `physical_disks` |
| `disks_used_gb` | sum `used_gb` over `physical_disks` |

What this means for an integration:

* Sending any of the six keys now answers `422 validation_failed` with
  `errors.<column> = unknown_field` — a typo can no longer be mistaken for a
  successful write.
* Reading a device no longer returns them; the JSON keys are absent, not null.
* `GET /v1/devices?sort=DisksFreeGB` answers `400 invalid_sort`; sort on
  `AgentModel`/`AgentGPU`/`AgentRAMGB` instead.
* A `GridView`/saved view that mentioned them simply ignores the missing
  columns.
* Existing values are preserved: `AgentModel`/`AgentGPU`/`AgentRAMGB` were
  backfilled from the legacy columns during the migration, and the disk numbers
  live in `physical_disks`.

### The `hds*` text columns are manual (no migration)

`hds`, `hds_types` and `hds_spaces_gb` used to be recomputed from
`physical_disks` on every write. They are plain fields again:

* An inventory report touches only `agent_*` and `physical_disks`; text an admin
  typed survives every report.
* Writing `physical_disks` no longer changes those three keys in the response.
* Values stored before the change are kept as they are (frozen at the last
  derivation): nothing is backfilled and nothing is deleted.
* Read the disk inventory from `physical_disks`; the three columns are only what
  a client — or an admin in the panel — wrote.

## Notes for ESET-style integrations

1. Prefer `POST /v1/devices/upsert` with `uuid` (or `sn`) per host.
2. Send only the fields you own; `PATCH` never clears what you omit.
3. Custom values survive full-row rewrites because they live in
   `asset_custom_values`, not in `assets`.
4. Direct SQL writers keep working: `assets.source` defaults to `eset`.

