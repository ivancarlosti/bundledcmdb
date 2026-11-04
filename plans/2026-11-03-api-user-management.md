# API user management (roster CRUD + upsert)

## Context

`public/api.php` exposes only `health`, `sources`, `fields` and `devices`. The
`users` table (identity = `email`, plus `company` and `role`) has no public write
path: rows are inserted by hand in SQL, and the only write endpoint is the
session-only `PATCH /panel-api/v1/settings/users/{email}` (superadmin + CSRF).

Login requires a Keycloak account *and* a matching `users` row
(`auth_keycloak.php::verifyUser()`). This change therefore manages
**authorisation rows only** — Keycloak account lifecycle stays in Keycloak, the
API never provisions credentials.

API keys carry `can_read` / `can_write` / `can_delete`. Reusing `can_write`
would let an ESET/Intune sync key mint itself a `superadmin` row, so user
management gets its own scope, off by default.

## Goal

1. Create/read/update/delete/upsert for the `users` roster over `/api/v1`,
   guarded by a new `can_users` key scope.
2. `DELETE /api/v1/devices?uuid=|sn=` so n8n needs no lookup request first.
3. Documentation: endpoints, scope, and copy-paste n8n payload examples for the
   user and asset operations.

## Steps

1. Migration `2026_11_08_0016_api_key_users_scope`: `api_keys.can_users
   TINYINT(1) NOT NULL DEFAULT 0`; mirrored in `sql/schema.sql` (DDL + ledger
   row) so DBA installs get it too.
2. `lib/api_auth.php`: `can_users` in normalize/create/update/validate and the
   `api_require_scope()` map (`users` => `can_users`).
3. `lib/panel/settings.php` + SPA (`api.ts`, `settingsForm.ts`,
   `ApiKeysTab.tsx`, tests) expose the new checkbox; 2 i18n keys per locale.
4. New `lib/users.php`: roles, find/list, normalize/validate, create, update,
   upsert, delete, last-superadmin guard; audited through `asset_event_record()`
   with `entity = user`.
5. `public/api.php`: `/v1/users` (GET list, GET one, POST, POST|PUT upsert,
   PATCH, DELETE) and `DELETE /v1/devices?uuid=|sn=`.
6. `lib/session_auth.php::session_require()` re-reads the `users` row so a
   delete or role change takes effect without waiting for the session to expire.
7. Tests: new `bin/test-api-users.php`, extended `bin/test-panel-api.php`
   assertions; gates `bin/check-schema.php`, `bin/check-i18n.php`,
   `bin/check-ui.sh`.
8. Docs: `docs/api.md` (Users section, scope table, n8n recipes),
   `docs/settings.md`, `README.md`.

## Risks

- **Privilege escalation** — mitigated by a separate scope defaulting to `0`,
  company binding, `superadmin` refused for company-bound keys and the
  last-superadmin guard. A global key with `can_users` is effectively an
  instance admin: documented as such.
- **Session revocation** costs one indexed query per panel request; skipped when
  the `users` table is absent, so legacy behaviour is preserved.
- **DBA installs** only receive the column through `sql/schema.sql`; the schema
  gate fails the build when the two drift.
- Deleting a roster row does not touch `assets.UserEmail` (plain text) nor the
  user's `user_preferences` / `saved_views` rows. Documented, not cleaned.
- No Keycloak side effects by design: an API-created row without a Keycloak
  account simply cannot sign in.
