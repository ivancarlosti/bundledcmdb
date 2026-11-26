# Collect data (experimental)

A colleague with a laptop should not have to type a serial number and nine
hardware facts into the device form. The collector is the opposite flow: the user
downloads one small file from `/app/collect`, double-clicks it, and the machine
lands in the inventory — already assigned to their own e-mail and their own
company.

> **Experimental.** The feature ships **off**. It is a public endpoint protected
> only by a short-lived token, so read this page before enabling it.

## For the user

1. Open `/app/collect` (navigation: **Collect data**).
2. Press **Generate download** — the file is saved as
   `collect-<your-mailbox>.bat` (or `.ps1`, see below).
3. Copy it to the computer that should be registered and double-click it.
4. A console window reports what it did and stays open until a key is pressed.

No administrator rights are needed: everything it reads is readable by a normal
user (WMI/CIM, the registry key that holds the Secure Boot flag, the Storage
module for disks). The window prints a sentence per failure — "the link expired",
"this device is assigned to someone else" — instead of an HTTP status.

The link lives for `collect.token_ttl_minutes` (default **2 hours**). Generating a
new file revokes the previous one, so there is exactly one live download per user.
**Revoke link** ends it immediately.

## What is collected

| Group | Fields |
|---|---|
| Identity | serial (`Win32_BIOS`), SMBIOS UUID, hostname, OS caption + version |
| Hardware | manufacturer, model, CPU, GPU, RAM (GB), OEM BIOS version, Secure Boot |
| Network | IPv4 addresses, adapter names, MAC addresses |
| Disks | physical disks with media/bus type, size, free space and their volumes |
| Session | logged-in user (console session, else `explorer.exe` owners), execution user |
| Security | antivirus products registered with the Security Center |

It never reads documents, browsing history, installed applications or the
contents of any file.

The same facts are written twice on purpose: the legacy columns (`Hostname`, `OS`,
`Manufacturer`, `CPUs`, `PrimaryLocalIP`) and the inventory-agent block
(`agent_*`), exactly like `POST /api/v1/devices/inventory`. A re-run updates both,
and a field the report cannot read is not blanked (`collect_drop_empty()`).

## The two file types

| File | Use |
|---|---|
| `.bat` | The default. A tiny launcher that copies the PowerShell body out of itself, runs it with `-ExecutionPolicy Bypass`, deletes the temporary copy and pauses at the end. |
| `.ps1` | The same body without the launcher, for administrators who deploy scripts (GPO, Intune) and do not want a `pause`. |

Both are ASCII-only and carry the endpoint, the token and
`X-Collector-Version: 1.0.0`.

## Security model

* **Only the hash is stored.** `collector_tokens.token_hash` holds
  `sha256(token)`; the raw value exists in the downloaded file and in the panel
  response that generated it, nothing else. It is never written to a log line.
* **Short window, one file.** The token expires after the configured TTL and is
  revoked the moment the same user downloads again.
* **The token alone is not enough.** Every request re-reads the `users` row: the
  e-mail must still be on the roster, still have a company (`company_unassigned`
  otherwise) and the role it has **now** must still hold `devices.collect`
  (`role_not_allowed`). A fired employee's file stops working even before it
  expires.
* **The server owns the assignment.** `company`, `user_email`, `source` and
  `status` are refused as payload fields (`forbidden_field`), so a crafted request
  cannot hand the machine to somebody else, move it to another tenant, or rewrite
  an asset tag.
* **Ownership rules.** An existing device matched by UUID or serial is updated
  only when `UserEmail` is empty or the caller's own address; otherwise the answer
  is `409 device_assigned_to_other_user`. A soft-deleted serial answers
  `409 serial_soft_deleted` instead of resurrecting the row. Cross-tenant
  duplicates are allowed — the lookup is scoped to the caller's company.
* **Throttling.** Per token and per address, counted before the token lookup
  (`collect.rate_limit`, default 10/min per token; ×6 per address).
* **Audit.** Writes land in `asset_events` as `create`/`update` by
  `collector:<email>` with the note `collector`, so the Logs screen shows where the
  change came from. New devices carry `source = collector` (seeded by migration
  `0024`); an existing device keeps its original source.

## Turning it on

1. `php bin/migrate.php` (or apply `sql/schema.sql`) — migration
   `2026_11_26_0024_collector_tokens` creates the table and seeds the `collector`
   source.
2. **Settings → Collect data → Enable the data collector**.
3. Check **Settings → Permissions** if a role should not collect
   (`devices.collect` is granted to every role by default).
4. The nginx config in the image already routes `/collect` to
   `public/collect.php`. A reverse proxy in front of the container must pass that
   path through unchanged.

## Troubleshooting

| Symptom in the console window | Cause |
|---|---|
| `The collector link expired` | past the TTL — generate a new file |
| `This file was replaced by a newer download` | the user generated a second file |
| `Data collection is switched off on this server` | `collect.enabled` is off |
| `Your account is no longer on the authorised list` | the roster row was deleted |
| `Your account has no company` | the roster row has an empty company |
| `Your role may not collect data` | `devices.collect` was revoked |
| `This device is assigned to another person` | the serial already belongs to somebody else in the company |
| `This device was deleted from the inventory` | restore it in the trash view, then collect again |
| `did not report a serial number` | `assets.require_sn` is on and the machine has no serial (common in VMs) |
| `The server could not be reached` | no network/VPN, or the reverse proxy does not route `/collect` |

`Settings → Collect data` is also where to look when nothing happens: the panel
shows the active link's expiry, how many times the file ran, and the address it
came from (`last_used_ip`), which is usually enough to tell "never ran" from "ran
somewhere else".

## Tests

```bash
php bin/test-collector.php          # token lifecycle, gates, write path, script, panel handlers
npm --prefix ui run test            # SPA units, including the i18n coverage gate
npm --prefix ui run e2e -- collect  # the screen: flag, generate, revoke, confirm
```
