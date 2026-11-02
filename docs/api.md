# BundledCMDB REST API (v1)

The API is meant for source systems (ESET scripts, Intune/Jamf syncs, CI jobs)
that need to create, update or remove devices without touching MySQL directly.

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
| Scopes | `read`, `write`, `delete` |

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
| `conflict` | 409 | duplicate `UUID`/`SN` in the same company |
| `validation_failed` | 422 | unknown field, bad type, duplicate unique custom value |
| `invalid_json` | 400 | body is not valid JSON |
| `schema_not_ready` | 503 | migrations not applied |
| `api_disabled` | 503 | API turned off in Settings |
| `internal_error` | 500 | unexpected error (details in the app log) |

## Endpoints

### `GET /v1/health`
Version, schema state, server time (UTC), timezone and the calling key's scope.

### `GET /v1/sources`
`[{"slug":"eset","label":"ESET","color":"#2f855a","is_default":false}]`

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
| `primary_local_ip`, `primary_remote_ip` | same |
| `company`, `source`, `source_ref` | same |

`source` is resolved as: payload → key default → `assets.default_source`
setting; it must exist and be allowed for the key.

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

## Notes for ESET-style integrations

1. Prefer `POST /v1/devices/upsert` with `uuid` (or `sn`) per host.
2. Send only the fields you own; `PATCH` never clears what you omit.
3. Custom values survive full-row rewrites because they live in
   `asset_custom_values`, not in `assets`.
4. Direct SQL writers keep working: `assets.source` defaults to `eset`.

