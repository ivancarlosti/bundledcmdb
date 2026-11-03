<?php
declare(strict_types=1);

/**
 * lib/permissions.php — effective role permissions.
 *
 * Roles still rank users for the few operations that must stay rank-based
 * (superadmin is immutable), but every panel action is authorised through this
 * table. The table starts with the historical role lists as defaults; admins can
 * grant or revoke individual capabilities without a schema change.
 *
 * Field permissions only control editing. Read visibility remains the existing
 * company/role scope, so nobody can hide a column with the matrix and still
 * receive its value from the API.
 */

/** Known roles, weakest first. */
function permission_roles(): array
{
    return array_keys(SESSION_ROLES);
}

/**
 * Capability definitions and their defaults.
 *
 * `label_key` is resolved through `public/lang/*.php`; the key name is also the
 * stable API identifier used by the matrix UI.
 *
 * @return array<string, array{group: string, label_key: string, default_roles: list<string>}>
 */
function permission_capability_defs(): array
{
    return [
        'devices.view'         => ['group' => 'devices',  'label_key' => 'perm_cap_devices_view',         'default_roles' => ['user', 'manager', 'admin', 'superadmin']],
        'devices.create'       => ['group' => 'devices',  'label_key' => 'perm_cap_devices_create',       'default_roles' => ['manager', 'admin', 'superadmin']],
        'devices.update'       => ['group' => 'devices',  'label_key' => 'perm_cap_devices_update',       'default_roles' => ['manager', 'admin', 'superadmin']],
        'devices.delete'       => ['group' => 'devices',  'label_key' => 'perm_cap_devices_delete',       'default_roles' => ['admin', 'superadmin']],
        'devices.restore'      => ['group' => 'devices',  'label_key' => 'perm_cap_devices_restore',      'default_roles' => ['admin', 'superadmin']],
        'devices.purge'        => ['group' => 'devices',  'label_key' => 'perm_cap_devices_purge',        'default_roles' => ['superadmin']],
        'devices.bulk'         => ['group' => 'devices',  'label_key' => 'perm_cap_devices_bulk',         'default_roles' => ['manager', 'admin', 'superadmin']],
        'devices.export'       => ['group' => 'devices',  'label_key' => 'perm_cap_devices_export',       'default_roles' => ['user', 'manager', 'admin', 'superadmin']],
        'devices.events'       => ['group' => 'devices',  'label_key' => 'perm_cap_devices_events',       'default_roles' => ['user', 'manager', 'admin', 'superadmin']],
        'devices.files'        => ['group' => 'devices',  'label_key' => 'perm_cap_devices_files',        'default_roles' => ['user', 'manager', 'admin', 'superadmin']],
        'devices.files.upload' => ['group' => 'devices',  'label_key' => 'perm_cap_devices_files_upload', 'default_roles' => ['manager', 'admin', 'superadmin']],
        'devices.files.delete' => ['group' => 'devices',  'label_key' => 'perm_cap_devices_files_delete', 'default_roles' => ['admin', 'superadmin']],
        'logs.view'            => ['group' => 'logs',      'label_key' => 'perm_cap_logs_view',            'default_roles' => ['admin', 'superadmin']],
        'logs.purge'           => ['group' => 'logs',      'label_key' => 'perm_cap_logs_purge',           'default_roles' => ['superadmin']],
        'settings.manage'      => ['group' => 'settings',  'label_key' => 'perm_cap_settings_manage',      'default_roles' => ['admin', 'superadmin']],
        'settings.sources'     => ['group' => 'settings',  'label_key' => 'perm_cap_settings_sources',     'default_roles' => ['admin', 'superadmin']],
        'settings.fields'      => ['group' => 'settings',  'label_key' => 'perm_cap_settings_fields',      'default_roles' => ['admin', 'superadmin']],
        'settings.api_keys'    => ['group' => 'settings',  'label_key' => 'perm_cap_settings_api_keys',    'default_roles' => ['admin', 'superadmin']],
        'settings.maintenance' => ['group' => 'settings',  'label_key' => 'perm_cap_settings_maintenance', 'default_roles' => ['admin', 'superadmin']],
        'settings.users'       => ['group' => 'settings',  'label_key' => 'perm_cap_settings_users',       'default_roles' => ['superadmin']],
        'permissions.manage'   => ['group' => 'settings',  'label_key' => 'perm_cap_permissions_manage',   'default_roles' => ['superadmin']],
    ];
}

function permission_capability_default(string $role, string $capability): bool
{
    $role = session_role_normalize($role);
    $def = permission_capability_defs()[$capability] ?? null;
    return $def !== null && in_array($role, $def['default_roles'], true);
}

/**
 * Explicit capability overrides, keyed by `role\0capability`.
 *
 * The cache is per request; `$force` is used after a matrix save so the response
 * reflects the rows just written.
 *
 * @return array<string, array{role: string, capability: string, is_allowed: int}>
 */
function permission_capability_overrides(PDO $pdo, bool $force = false): array
{
    static $rows = null;
    if ($rows !== null && !$force) {
        return $rows;
    }
    $rows = [];
    if (!db_table_exists($pdo, 'role_capabilities')) {
        return $rows;
    }
    foreach ($pdo->query('SELECT role, capability, is_allowed FROM role_capabilities')->fetchAll() as $row) {
        $role = session_role_normalize((string) $row['role']);
        $rows[$role . "\0" . (string) $row['capability']] = [
            'role'       => $role,
            'capability' => (string) $row['capability'],
            'is_allowed' => (int) $row['is_allowed'],
        ];
    }
    return $rows;
}

function permission_capability_allowed(string $role, string $capability, ?PDO $pdo = null): bool
{
    $role = session_role_normalize($role);
    if ($role === 'superadmin') {
        return true;
    }
    if (!isset(permission_capability_defs()[$capability])) {
        return false;
    }

    $pdo ??= db_try();
    if ($pdo !== null) {
        $rows = permission_capability_overrides($pdo);
        $key = $role . "\0" . $capability;
        if (isset($rows[$key])) {
            return (int) $rows[$key]['is_allowed'] === 1;
        }
    }

    return permission_capability_default($role, $capability);
}

/**
 * Effective capabilities for a role, including keys that are currently false.
 *
 * @return array<string, bool>
 */
function permission_capabilities_for_role(string $role, ?PDO $pdo = null): array
{
    $out = [];
    foreach (permission_capability_defs() as $capability => $def) {
        $out[$capability] = permission_capability_allowed($role, $capability, $pdo);
    }
    return $out;
}

/**
 * Field keys exposed by the matrix: editable built-in columns plus custom fields.
 *
 * `UUID` is deliberately excluded: it is an identity aid kept admin-only by the
 * write path (`panel_device_writable_columns()`), not a field to delegate.
 *
 * @return list<string>
 */
function permission_field_keys(PDO $pdo): array
{
    $keys = [];
    foreach (asset_form_columns() as $column) {
        if ($column !== 'UUID' && isset(asset_column_defs()[$column])) {
            $keys[] = $column;
        }
    }
    foreach (['source', 'source_ref'] as $column) {
        if (isset(asset_column_defs()[$column])) {
            $keys[] = $column;
        }
    }
    foreach (field_defs($pdo, false) as $def) {
        $keys[] = 'custom:' . (string) $def['field_key'];
    }
    return array_values(array_unique($keys));
}

/** Default edit permission for a field, matching the pre-matrix role lists. */
function permission_field_default_editable(string $role, string $field, ?PDO $pdo = null): bool
{
    $role = session_role_normalize($role);
    if ($role === 'superadmin') {
        return true;
    }
    if (str_starts_with($field, 'custom:')) {
        $pdo ??= db_try();
        if ($pdo === null) {
            return false;
        }
        $key = substr($field, strlen('custom:'));
        $def = field_defs_by_key($pdo, false)[$key] ?? null;
        return $def !== null && field_editable_by($def, $role);
    }

    if (!in_array($role, ['manager', 'admin', 'superadmin'], true)) {
        return false;
    }
    $columns = array_merge(
        asset_create_columns($role),
        asset_grid_editable_columns($role),
        ['source', 'source_ref']
    );
    return in_array($field, $columns, true);
}

/**
 * Explicit matrix override for a built-in field, or null when none is stored.
 *
 * @return array<string, bool> keyed by `role\0field`; use `permission_field_override()`
 */
function permission_field_overrides(PDO $pdo, bool $force = false): array
{
    static $rows = null;
    if ($rows !== null && !$force) {
        return $rows;
    }
    $rows = [];
    if (!db_table_exists($pdo, 'role_field_permissions')) {
        return $rows;
    }
    foreach ($pdo->query('SELECT role, field_key, can_edit FROM role_field_permissions')->fetchAll() as $row) {
        $key = session_role_normalize((string) $row['role']) . "\0" . (string) $row['field_key'];
        $rows[$key] = (int) $row['can_edit'] === 1;
    }
    return $rows;
}

function permission_field_override(PDO $pdo, string $role, string $field): ?bool
{
    $rows = permission_field_overrides($pdo);
    $key = session_role_normalize($role) . "\0" . $field;
    return array_key_exists($key, $rows) ? $rows[$key] : null;
}

function permission_field_editable(string $role, string $field, ?PDO $pdo = null): bool
{
    $role = session_role_normalize($role);
    if ($role === 'superadmin') {
        return true;
    }

    if (str_starts_with($field, 'custom:')) {
        $pdo ??= db_try();
        if ($pdo === null) {
            return false;
        }
        $key = substr($field, strlen('custom:'));
        $def = field_defs_by_key($pdo, false)[$key] ?? null;
        return $def !== null && field_editable_by($def, $role);
    }

    $pdo ??= db_try();
    if ($pdo !== null) {
        $override = permission_field_override($pdo, $role, $field);
        if ($override !== null) {
            return $override;
        }
    }
    return permission_field_default_editable($role, $field, $pdo);
}

/**
 * A field row as the matrix UI renders it.
 *
 * @return array{key: string, label: string, custom: bool, editable_roles: list<string>}
 */
function permission_field_row(PDO $pdo, string $field): array
{
    $custom = str_starts_with($field, 'custom:');
    $label = $field;
    if ($custom) {
        $key = substr($field, strlen('custom:'));
        $def = field_defs_by_key($pdo, false)[$key] ?? null;
        $label = $def === null ? $key : (string) $def['label'];
    } else {
        $def = asset_column_defs()[$field] ?? null;
        $label = $def === null ? $field : (string) ($def['label'] ?? $field);
    }

    $roles = [];
    foreach (permission_roles() as $role) {
        if (permission_field_editable($role, $field, $pdo)) {
            $roles[] = $role;
        }
    }

    return ['key' => $field, 'label' => $label, 'custom' => $custom, 'editable_roles' => $roles];
}

/** Full matrix payload shared by GET and PUT. */
function permission_matrix_payload(PDO $pdo): array
{
    $capabilities = [];
    $matrixCaps = [];
    foreach (permission_capability_defs() as $key => $def) {
        $capabilities[] = [
            'key'           => $key,
            'group'         => $def['group'],
            'label_key'     => $def['label_key'],
            'default_roles' => $def['default_roles'],
        ];
        foreach (permission_roles() as $role) {
            $matrixCaps[$role][$key] = permission_capability_allowed($role, $key, $pdo);
        }
    }

    $fields = [];
    $matrixFields = [];
    foreach (permission_field_keys($pdo) as $field) {
        $row = permission_field_row($pdo, $field);
        $fields[] = $row;
        $matrixFields[$field] = $row['editable_roles'];
    }

    return [
        'roles'        => permission_roles(),
        'capabilities' => $capabilities,
        'fields'       => $fields,
        'matrix'       => [
            'capabilities' => $matrixCaps,
            'fields'       => $matrixFields,
        ],
    ];
}

/**
 * Validate a matrix payload without writing it.
 *
 * @return array{capabilities: array<string, array<string, bool>>, fields: array<string, list<string>>, errors: array<string, string>}
 */
function permission_matrix_normalize(PDO $pdo, array $payload): array
{
    $errors = [];
    $capabilities = [];
    $caps = $payload['capabilities'] ?? [];
    if (!is_array($caps)) {
        $errors['capabilities'] = 'invalid';
        $caps = [];
    }
    foreach ($caps as $role => $values) {
        $rawRole = strtolower(trim((string) $role));
        if (!in_array($rawRole, permission_roles(), true)) {
            $errors['capabilities'] = 'invalid_role';
            continue;
        }
        if (!is_array($values)) {
            $errors['capabilities'] = 'invalid';
            continue;
        }
        foreach ($values as $capability => $value) {
            $capability = (string) $capability;
            if (!isset(permission_capability_defs()[$capability])) {
                $errors['capabilities'] = 'unknown_capability';
                continue;
            }
            if (!is_bool($value)) {
                $errors['capabilities'] = 'invalid_value';
                continue;
            }
            $capabilities[$rawRole][$capability] = $value;
        }
    }

    $fields = [];
    $fieldInput = $payload['fields'] ?? [];
    if (!is_array($fieldInput)) {
        $errors['fields'] = 'invalid';
        $fieldInput = [];
    }
    $knownFields = permission_field_keys($pdo);
    foreach ($fieldInput as $field => $roles) {
        $field = (string) $field;
        if (!in_array($field, $knownFields, true)) {
            $errors['fields'] = 'unknown_field';
            continue;
        }
        if (!is_array($roles)) {
            $errors['fields'] = 'invalid';
            continue;
        }
        $normalized = [];
        foreach ($roles as $role) {
            $rawRole = strtolower(trim((string) $role));
            if (!in_array($rawRole, permission_roles(), true)) {
                $errors['fields'] = 'invalid_role';
                continue 2;
            }
            $normalized[] = $rawRole;
        }
        // Superadmin is immutable; every row keeps it checked.
        $normalized[] = 'superadmin';
        $fields[$field] = array_values(array_unique($normalized));
    }

    return ['capabilities' => $capabilities, 'fields' => $fields, 'errors' => $errors];
}

/**
 * Persist the matrix. Rows equal to the code defaults are deleted, so adding a
 * capability later inherits its default instead of a stale pinned value.
 *
 * @return array{changed: array<string, array{0: ?string, 1: ?string}>, errors: array<string, string>}
 */
function permission_matrix_save(PDO $pdo, array $payload, string $actor): array
{
    $normalized = permission_matrix_normalize($pdo, $payload);
    if ($normalized['errors'] !== []) {
        return ['changed' => [], 'errors' => $normalized['errors']];
    }

    $changed = [];
    $pdo->beginTransaction();
    try {
        $upsertCap = $pdo->prepare(
            'INSERT INTO role_capabilities (role, capability, is_allowed, updated_at, updated_by)
             VALUES (?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE is_allowed = VALUES(is_allowed), updated_at = VALUES(updated_at), updated_by = VALUES(updated_by)'
        );
        $deleteCap = $pdo->prepare('DELETE FROM role_capabilities WHERE role = ? AND capability = ?');

        foreach ($normalized['capabilities'] as $role => $values) {
            if ($role === 'superadmin') {
                continue;
            }
            foreach ($values as $capability => $allowed) {
                $before = permission_capability_allowed($role, $capability, $pdo);
                if ($before === $allowed) {
                    continue;
                }
                if ($allowed === permission_capability_default($role, $capability)) {
                    $deleteCap->execute([$role, $capability]);
                } else {
                    $upsertCap->execute([$role, $capability, $allowed ? 1 : 0, gmdate('Y-m-d H:i:s'), $actor]);
                }
                $changed[$role . '.' . $capability] = [$before ? '1' : '0', $allowed ? '1' : '0'];
            }
        }

        $upsertField = $pdo->prepare(
            'INSERT INTO role_field_permissions (role, field_key, can_edit, updated_at, updated_by)
             VALUES (?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE can_edit = VALUES(can_edit), updated_at = VALUES(updated_at), updated_by = VALUES(updated_by)'
        );
        $deleteField = $pdo->prepare('DELETE FROM role_field_permissions WHERE role = ? AND field_key = ?');

        foreach ($normalized['fields'] as $field => $roles) {
            if (str_starts_with($field, 'custom:')) {
                $key = substr($field, strlen('custom:'));
                $def = field_defs_by_key($pdo, false)[$key] ?? null;
                if ($def === null) {
                    continue;
                }
                $beforeRoles = field_editable_roles($def);
                $afterRoles = array_values(array_intersect(permission_roles(), $roles));
                if ($beforeRoles !== $afterRoles) {
                    field_set_editable_roles($pdo, $key, $afterRoles, $actor);
                    $changed['custom.' . $key . '.editable_roles'] = [implode(',', $beforeRoles), implode(',', $afterRoles)];
                }
                continue;
            }
            foreach (permission_roles() as $role) {
                if ($role === 'superadmin') {
                    continue;
                }
                $before = permission_field_editable($role, $field, $pdo);
                $after = in_array($role, $roles, true);
                if ($before === $after) {
                    continue;
                }
                if ($after === permission_field_default_editable($role, $field, $pdo)) {
                    $deleteField->execute([$role, $field]);
                } else {
                    $upsertField->execute([$role, $field, $after ? 1 : 0, gmdate('Y-m-d H:i:s'), $actor]);
                }
                $changed[$role . '.' . $field] = [$before ? '1' : '0', $after ? '1' : '0'];
            }
        }

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    // The request renders the matrix again after PUT, so drop the per-request
    // caches that were read while comparing.
    permission_capability_overrides($pdo, true);
    permission_field_overrides($pdo, true);
    field_defs($pdo, true, true);
    field_defs($pdo, false, true);

    return ['changed' => $changed, 'errors' => []];
}

/**
 * Require a capability on the current session.
 *
 * This is the only authorization entry point for panel handlers; it keeps the
 * 401/403 envelope identical to `session_require_role()`.
 */
function session_require_capability(string $capability): array
{
    $user = session_require();
    if (!permission_capability_allowed($user['role'], $capability)) {
        throw new ApiError('forbidden', 'Your role cannot perform this action.', 403);
    }
    return $user;
}
