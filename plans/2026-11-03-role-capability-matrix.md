# Role capability/field matrix, source move and ESET retirement

## Context

Authorization currently stops at `session_require_role()`. Device field editing is
spread across `asset_create_columns()`, `asset_grid_editable_columns()` and
`field_editable_by()`. Sources are DB-backed, but `eset` is still seeded as a
system source, and there is no way to move every asset from one source to
another. `source_slug` already maps to the `source` column, but that contract is
undocumented and untested.

## Goal

- Persist and enforce a role -> capability matrix and a role -> field-edit matrix.
- Expose both through `GET/PUT /settings/permissions` and a Settings tab.
- Feed the same effective permissions into the SPA session/meta flags.
- Add `POST /settings/sources/move` to reassign assets and API-key references.
- Stop seeding `eset` as a built-in source; migrate existing installations.
- Verify the API `source_slug` contract and document it.

Field permissions control editing (create/update) only. Read visibility remains
role/company scoped as before. Superadmin is always allowed.

## Steps

1. Add `role_capabilities` and `role_field_permissions` tables plus migration
   `2026_11_03_0015_permissions`; mirror the tables/default changes in
   `sql/schema.sql`.
2. Add `lib/permissions.php`: definitions, defaults, effective checks, matrix
   read/save, and `session_require_capability()`.
3. Replace `session_require_role()` call sites with capability checks; filter
   meta/write columns through the field matrix.
4. Add permissions endpoints and source move handler with audit logging.
5. Demote or delete `eset` during migration and reset the source default.
6. Add the React permissions tab, matrix hooks, source-move action and explicit
   capability checks in device/log screens.
7. Add catalog strings and the `perm_` dynamic i18n family.
8. Extend backend tests and docs; run schema, i18n, PHP tests and the UI gate.

## Risks

- Misconfigured permissions can lock roles out. Superadmin remains immutable and
  always allowed.
- Custom fields are edited both here and in Fields; both paths update
  `asset_field_defs.editable_roles` and invalidate caches.
- Moving a source must rewrite API-key defaults/allowlists or keys silently stop
  working; the move is transactional and audited.
- Deleting `eset` is only safe when no asset or API key references it; otherwise
  the migration demotes it to a normal deletable source.
