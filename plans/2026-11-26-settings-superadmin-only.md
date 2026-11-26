# Settings is superadmin-only

## Context

`/settings` used to be delegable: `settings.manage`, `settings.sources`,
`settings.fields`, `settings.api_keys` and `settings.maintenance` defaulted to
`admin` + `superadmin`, and `lib/panel/settings.php` gated each area on its own
capability (`settings.manage` for general/storage/columns, `settings.sources`,
`settings.fields`, `settings.api_keys`, `settings.maintenance`,
`permissions.manage` for the matrix, `settings.users` for the roster).

That surface covers installation-wide configuration, storage credentials,
API-key issuance, the grid layout, schema migrations and the role matrix
itself — an admin could escalate by opening the permissions tab or by issuing a
superadmin-equivalent API key. The owner wants the whole screen reserved for
`superadmin`.

## Goal

* Only `superadmin` can open `/settings`, including its URL, navigation entry
  and every `/panel-api/v1/settings*` endpoint.
* The role matrix cannot grant a setting capability back to another role, and a
  stale `role_capabilities` row cannot reopen the screen.
* Admins keep the rest of their role: devices, logs, the `can_admin` flag.
* Docs, API tests and E2E fixtures describe the new policy.

## Steps

1. **Capabilities** (`lib/permissions.php`) — flag `settings.*` and
   `permissions.manage` as `superadmin_only`, keep `default_roles` at
   `['superadmin']`, refuse them in `permission_capability_allowed()` before the
   `role_capabilities` lookup, publish the flag in the matrix payload and skip
   those capabilities in `permission_matrix_normalize()`.
2. **Migration** (`lib/migrate.php`) — `0022_settings_superadmin_only` deletes
   non-superadmin rows for `settings.%` / `permissions.manage` so the database
   matches the code.
3. **Handlers** (`lib/panel/settings.php`) — collapse the
   `(bool $superadminOnly, ?string $capability)` guard to a single capability
   argument; `session_require_capability()` now resolves it to superadmin, so a
   direct call is refused even without the SPA.
4. **SPA** — `AppShell` shows the Settings link on `can_superadmin`;
   `SettingsPage` refuses the render without it; `PermissionsTab` disables the
   locked rows so the matrix explains why they cannot be granted.
5. **Tests** — `bin/test-panel-api.php` asserts admin *and* manager are refused
   on every settings area, a stored grant stays inert, and the matrix refuses to
   re-delegate; E2E uses a superadmin session for the screen and asserts the
   admin sees no link and no screen.
6. **Docs** — README, `docs/settings.md`, `docs/frontend.md` and the file header.

## Risks

* **Superadmin lockout.** Every deployment needs at least one `superadmin` row.
  The roster is only editable from `/settings`, so an installation whose only
  superadmin is demoted has no in-app recovery; the role is documented as the
  recovery role and `migration_0022` does not touch it.
* **Stale bookmarks.** An admin following `/app/settings` now gets the
  `ui_err_forbidden` alert instead of a redirect; the link is hidden, so this is
  an error state rather than a workflow.
* **Deleted overrides.** Rows removed by `0022` are gone; delegating a settings
  capability later (by flipping `superadmin_only` off) starts from the code
  defaults instead of resurrecting a historical choice.
