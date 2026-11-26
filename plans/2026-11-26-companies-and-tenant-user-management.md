# Companies and tenant-scoped user management

## Context

Commit `3bd46bb` made the whole Settings screen superadmin-only and flagged every
`settings.*` capability `superadmin_only` (`plans/2026-11-26-settings-superadmin-only.md`).
The installation now needs a company (tenant) registry and delegated user
management:

- a SuperAdmin creates and deletes companies;
- a SuperAdmin manages users in any company, or company-less users;
- an Admin manages only the users of their own company;
- a user without a company is a SuperAdmin by definition.

The existing tenancy is the `company` string on `users`, `assets`, `api_keys`
and `asset_events`; `asset_companies()` only derives names from asset rows, so a
tenant with no assets cannot exist there. The registry is therefore a new
`companies` table, without foreign keys: the REST API and legacy writers keep
writing arbitrary company strings.

## Goal

1. Add a `companies` registry (create/delete, usage-aware delete guard).
2. Replace `settings.users` with a delegable `users.manage` capability
   (defaults: admin + superadmin) and add superadmin-only `settings.companies`.
3. Keep `/settings` reachable for admins, but show them only the Users tab.
4. Enforce company scoping in SQL and PHP on every user list/update/delete.
5. Keep `/v1/users` (REST) unchanged; document the panel-only invariant.
6. Cover the new rules in `bin/test-panel-api.php` and the Settings E2E suite.

## Steps

1. Create the plan file (this document), check `.dockerignore`/`.gitignore`.
2. Migration `2026_11_26_0023_companies` + `sql/schema.sql` + `sql/dev-seed.sql`
   (+ `idx_users_company`).
3. `lib/companies.php` (registry helpers, audit) and `ASSET_EVENT_ENTITIES`.
4. `lib/permissions.php`: `users.manage`, `settings.companies`; drop
   `settings.users` and its stale `role_capabilities` rows.
5. Panel routes/handlers in `lib/panel/settings.php` + `lib/panel/routes.php`.
6. UI: `api.ts`, `useSettings.ts`, `UsersTab.tsx`, new `CompaniesTab.tsx`,
   `SettingsPage.tsx`, `AppShell.tsx`, `settingsForm.ts`.
7. i18n keys in all seven catalogs.
8. Tests: `bin/test-panel-api.php`, `ui/e2e/fixtures.ts`,
   `ui/e2e/settings.spec.ts`; docs: `docs/settings.md`, `docs/frontend.md`,
   `docs/api.md`, `README.md`.
9. Run schema/i18n/API/UI checks and the GitHub alert review after push.

## Risks

- **Settings guarantee regression** — admins reach `/settings`; the screen gate
  becomes "any allowed tab", every tab keeps its capability, and E2E asserts an
  admin sees exactly the Users tab.
- **Privilege escalation** — admin role ceiling (`user`/`manager`), rank check on
  existing rows, self-delete/self-role blocks, `last_superadmin` guard.
- **Cross-tenant leaks** — SQL predicate `company = ?` for non-superadmins; a
  non-superadmin with an empty company is refused (no company = superadmin).
- **Company casing drift** — creation canonicalises to the registered spelling;
  backfill order is users then assets.
- **Deleting a tenant with data** — `409 in_use` with users/assets/api_keys
  counts; no rename in this iteration.
- **DBA installs** — `schema.sql` carries the table *and* the backfill, since the
  migration will not run when the table already exists.
- **i18n floors** — every new key goes into all seven catalogs; `perm_*` is a
  dynamic family, everything else must be referenced literally.
