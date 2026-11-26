# Collect data: a downloaded device collector (experimental)

## Context

Registering a laptop in the inventory means typing a serial number, an OS string,
a model, RAM, CPU, disks and a MAC address into the device form. The people who
own the machines never have those numbers, so the inventory fills with half-empty
rows — or stays empty — and an administrator ends up chasing serials by e-mail.

The installation already accepts machine reports over the Bearer API
(`POST /api/v1/devices/inventory`, documented in `docs/api.md`), but that path
needs an API key and a deployment tool. A non-technical user has neither.

A reference script (`eset_detailed_info.ps1`) already collects the facts and posts
them to a webhook; the missing pieces are a credential per user, ownership rules
on the server, and something a user can run by double-clicking.

## Goal

Let a signed-in user download a double-clickable, no-admin Windows file that
reports the machine it runs on and creates or updates **one** device:

* the credential is a token inside the file, valid for 2 hours by default and
  revoked when the user downloads again;
* the device is always assigned to the downloading user's e-mail, inside their
  company, regardless of what the payload says;
* the rules are enforced on the server and stated in the file's console output,
  so a failure is a sentence a user can repeat to their administrator;
* the feature ships **off** and is turned on per installation.

## Steps

1. **Schema** — migration `2026_11_26_0024_collector_tokens` creates
   `collector_tokens` (hash, e-mail, company, role, file name, expiry, revocation,
   use counters) and seeds the `collector` provenance source. Mirrored in
   `sql/schema.sql` so a DBA-managed database gets the same tables, and recorded in
   its `schema_migrations` block (`bin/check-schema.php` guards the drift).
2. **Model** (`lib/collect.php`) — token generation/hash/lookup, one active token
   per user, `collect_authorize()` for the gates (token → expiry → feature flag →
   roster → company → capability), a field allow-list, and `collect_apply()` for
   the create/update path with the ownership rules.
3. **Public endpoint** (`public/collect.php`) — `POST /collect`, JSON envelope,
   per-address throttle before the lookup and per-token throttle after it, no token
   or body in the logs. nginx location added to the Dockerfile.
4. **Script** (`lib/collect_script.ps1` + `lib/collect_script.php`) — one ASCII
   PowerShell body rendered with endpoint/token/version; the `.bat` variant embeds
   it behind a marker line and a `-ExecutionPolicy Bypass` launcher, the `.ps1`
   variant ships the body alone.
5. **Panel API** (`lib/panel/collect.php`) — `GET /collect`, `POST /collect/file`,
   `DELETE /collect/file`; per-user, CSRF on writes, the file content in the
   response so the SPA can save a `Blob`.
6. **Settings and permissions** — `collect.enabled` (default `0`),
   `collect.token_ttl_minutes` (120), `collect.rate_limit` (10) in a new Collect
   tab; `devices.collect` capability granted to every role by default; `collect`
   added to the `/session` feature flags.
7. **SPA** — `/app/collect` screen (state of the active link, generate, revoke,
   three-step explanation), navigation entry, `features.collect` gate.
8. **i18n** — 38 new keys translated in all 8 catalogues, because the coverage
   floors (45 % for `fr-FR`/`zh-CN`/`hi-IN`/`ar-SA`) would otherwise fail.
9. **Tests and docs** — `bin/test-collector.php` (model, gates, write path,
   script, panel handlers), `ui/e2e/collect.spec.ts` with fixtures, `docs/collect.md`,
   a section in `docs/api.md` and `docs/settings.md`.

## Risks

| Risk | Mitigation |
|---|---|
| The token leaks (forwarded e-mail, screenshot) | 2-hour TTL, one live file per user, the roster is re-checked on every request, a device can only be written for its owner |
| A crafted payload re-assigns or moves a device | allow-list plus server-side `company`/`user_email`/`source`/`status`; the refused keys are named in `errors` |
| A user "claims" a colleague's laptop | `409 device_assigned_to_other_user` when the matched device already has a different `UserEmail` |
| A deleted device silently comes back | `409 serial_soft_deleted`; an administrator restores it first |
| Antivirus/EDR blocks a downloaded `.bat` | the `.ps1` variant for managed deployment, and the file is plain ASCII text anyone can inspect |
| `cmd.exe` mangles the embedded script | ASCII-only body, CRLF line endings, a marker line copied out by PowerShell instead of being parsed by cmd, and a test that asserts all three |
| The feature becomes a public write surface nobody watches | off by default, per-token/per-address throttles, audit rows with `actor = collector:<email>`, `source = collector` |
| Per-item translation drift (8 catalogues) | all keys translated; `bin/check-i18n.php` enforces the floors |
