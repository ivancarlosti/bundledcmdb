<?php
declare(strict_types=1);

/**
 * lib/panel/meta.php — `GET /panel-api/v1/meta`.
 *
 * Everything the SPA needs to render the grid *without* hard-coding column
 * names: column definitions (label + i18n key + type + sortable/editable),
 * Status values, sources, custom field definitions, page sizes, themes and the
 * default sort. The role decides which columns are editable.
 */

/**
 * Column => translation key. Mirrors `public/lang/*.php` (`col_*`) so the SPA can
 * show localised headers while the legacy grid keeps using the English labels
 * from `asset_column_defs()`.
 *
 * @return array<string, string>
 */
function panel_column_label_keys(): array
{
    $keys = [
        'Id'              => 'col_id',
        'UUID'            => 'col_uuid',
        'SN'              => 'col_sn',
        'OS'              => 'col_os',
        'OSVersion'       => 'col_os_version',
        'Hostname'        => 'col_hostname',
        'Mobile'          => 'col_mobile',
        'Manufacturer'    => 'col_manufacturer',
        'LastSeen'        => 'col_last_seen',
        'UserEmail'       => 'col_user_email',
        'LoggedUsername'  => 'col_logged_username',
        'DeviceModel'     => 'col_device_model',
        'BYOD'            => 'col_byod',
        'Status'          => 'col_status',
        'Warranty'        => 'col_warranty',
        'Asset'           => 'col_asset',
        'PurchaseDate'    => 'col_purchase_date',
        'CypherID'        => 'col_cypher_id',
        'CypherKey'       => 'col_cypher_key',
        'Notes'           => 'col_notes',
        'CPUs'            => 'col_cpus',
        'GPUs'            => 'col_gpus',
        'RAMGB'           => 'col_ram_gb',
        'HDs'             => 'col_hds',
        'HDsTypes'        => 'col_hds_types',
        'HDsSpacesGB'     => 'col_hds_spaces_gb',
        'PhysicalDisks'   => 'col_physical_disks',
        'DisksTotalGB'    => 'col_disks_total_gb',
        'DisksFreeGB'     => 'col_disks_free_gb',
        'DisksUsedGB'     => 'col_disks_used_gb',
        'NetworkAdapters' => 'col_network_adapters',
        'MACAddresses'    => 'col_mac_addresses',
        'ESETComponents'  => 'col_eset_components',
        'PrimaryLocalIP'  => 'col_primary_local_ip',
        'PrimaryRemoteIP' => 'col_primary_remote_ip',
        'company'         => 'col_company',
        'source'          => 'col_source',
        'source_ref'      => 'col_source_ref',
        'created_at'      => 'col_created_at',
        'updated_at'      => 'col_updated_at',
        'created_by'      => 'col_created_by',
        'updated_by'      => 'col_updated_by',
        'deleted_at'      => 'col_deleted_at',
        'files'           => 'col_files',
    ];

    // Inventory-agent block: `col_` + the wire key from `asset_agent_columns()`
    // (`AgentOSBuild` => `col_agent_os_build`). Deriving it keeps the catalogue
    // and the definitions from disagreeing about a name.
    foreach (asset_agent_columns() as $column => $meta) {
        $keys[$column] = 'col_' . $meta['api'];
    }

    return $keys;
}

/** Translation key for a column, with a snake_case fallback for unknown ones. */
function panel_column_label_key(string $column): string
{
    $keys = panel_column_label_keys();
    if (isset($keys[$column])) {
        return $keys[$column];
    }
    $snake = strtolower((string) preg_replace('/(?<!^)[A-Z]/', '_$0', $column));
    $clean = preg_replace('/[^a-z0-9_]/', '', $snake);
    return 'col_' . (($clean === null || $clean === '') ? 'unknown' : $clean);
}

/**
 * Key of the property that carries this column in `panel_device_payload()`.
 *
 * The wire format is snake_case (`sn`, `os`, `primary_local_ip`, …) while the
 * schema uses the legacy PascalCase names, so the mapping is published here
 * instead of being duplicated in the SPA. `panel_device_payload_keys()` (the
 * same table the CSV export uses) is the single source of truth; unknown names
 * fall back to their snake_case form.
 */
function panel_payload_key(string $column): string
{
    $map = panel_device_payload_keys();
    if (isset($map[$column])) {
        return $map[$column];
    }
    $snake = strtolower((string) preg_replace('/(?<!^)[A-Z]/', '_$0', $column));
    $clean = preg_replace('/[^a-z0-9_]/', '', $snake);

    return ($clean === null || $clean === '') ? '' : $clean;
}

/**
 * Status values with their translation keys (order matches asset_status_values()).
 *
 * @return list<array{value: string, label_key: string}>
 */
function panel_status_payload(): array
{
    $keys = ['status_in_use', 'status_stock', 'status_repair', 'status_decomm', 'status_lost'];
    $out = [];
    foreach (asset_status_values() as $index => $value) {
        $out[] = ['value' => (string) $value, 'label_key' => $keys[$index] ?? 'status_in_use'];
    }
    return $out;
}

/**
 * Why-a-device-was-deleted options, with their translation keys.
 *
 * Published next to `statuses` so the delete dialog and the trash view read the
 * same list the API validates against (order matches `asset_deletion_reasons()`).
 *
 * @return list<array{value: string, label_key: string}>
 */
function panel_delete_reason_payload(): array
{
    $keys = [
        'duplicated'         => 'ui_delete_reason_duplicated',
        'created_by_mistake' => 'ui_delete_reason_mistake',
    ];
    $out = [];
    foreach (asset_deletion_reasons() as $value) {
        $out[] = ['value' => $value, 'label_key' => $keys[$value] ?? 'ui_delete_reason_mistake'];
    }
    return $out;
}

/**
 * Column payload. `files` is virtual (COUNT(device_files)) and columns that are
 * not in `asset_column_defs()` (custom fields) are added by the SPA from
 * `/meta.fields`.
 *
 * `in_grid` comes from the installation layout (Settings → Columns), not from
 * the `grid` code default, and the list is returned in the admin's order: the
 * SPA then needs no extra concept — it already renders `meta.columns` filtered
 * by `in_grid`.
 */
function panel_columns_payload(string $role, ?PDO $pdo = null): array
{
    $pdo ??= db_try();
    // Sortable even when hidden: the grid may sort by them without showing them.
    $alwaysSortable = ['source', 'updated_at'];
    $layout = grid_layout_get($pdo);
    $out = [];

    foreach (asset_column_defs() as $column => $def) {
        $inGrid = grid_layout_enabled((string) $column, $layout);
        $out[] = [
            'key'       => $column,
            'api_key'   => panel_payload_key($column),
            'label_key' => panel_column_label_key($column),
            'label'     => (string) ($def['label'] ?? $column),
            'type'      => (string) ($def['type'] ?? 'text'),
            'in_grid'   => $inGrid,
            'sortable'  => $inGrid || in_array($column, $alwaysSortable, true),
            'editable'  => $column !== 'UUID' && permission_field_editable($role, $column, $pdo),
            'virtual'   => false,
        ];
    }

    // Virtual column: COUNT(device_files), sortable server-side.
    $out[] = [
        'key'       => 'files',
        'api_key'   => 'files_count',
        'label_key' => 'col_files',
        'label'     => 'Attachments',
        'type'      => 'int',
        'in_grid'   => grid_layout_enabled('files', $layout),
        'sortable'  => true,
        'editable'  => false,
        'virtual'   => true,
    ];

    return grid_layout_apply_order($out, $layout);
}

/**
 * Wire keys a role may write, in form order.
 *
 * `create` is the role-filtered subset of `asset_create_columns()` (plus
 * `source`), `update` is everything `PATCH` accepts from `asset_form_columns()`.
 * The SPA renders its form from these lists, so the role matrix stays a
 * server-side decision.
 *
 * @return array{create: list<string>, update: list<string>}
 */
function panel_write_columns_payload(string $role): array
{
    $map = panel_device_input_map($role);
    $form = [];
    $create = [];
    foreach (asset_form_columns() as $column) {
        $key = array_search($column, $map, true);
        if ($key !== false) {
            $form[] = (string) $key;
        }
    }
    foreach ($map as $key => $column) {
        // UUID is server-generated on create even when an admin may edit it.
        if ($column !== 'UUID') {
            $create[] = (string) $key;
        }
    }

    return ['create' => $create, 'update' => $form];
}

/** Custom field definitions (grid-visible ones become extra columns). */
function panel_fields_payload(?PDO $pdo, string $role = 'user'): array
{
    if ($pdo === null) {
        return [];
    }
    // The Columns tab owns the grid presence of custom fields too; the field's
    // own `show_in_grid` only feeds the default layout.
    $layout = grid_layout_get($pdo);
    $out = [];
    foreach (field_defs($pdo) as $def) {
        $fieldKey = 'custom:' . (string) $def['field_key'];
        $out[] = [
            'id'             => (int) $def['id'],
            'key'            => (string) $def['field_key'],
            'label'          => (string) $def['label'],
            'label_i18n'     => json_decode((string) ($def['label_i18n'] ?? ''), true) ?: null,
            'type'           => (string) $def['data_type'],
            'options'        => field_options($def),
            'required'       => (int) $def['is_required'] === 1,
            'unique'         => (int) $def['is_unique'] === 1,
            'searchable'     => (int) $def['is_searchable'] === 1,
            'in_grid'        => grid_layout_enabled($fieldKey, $layout),
            'editable_roles' => array_values(array_filter(
                permission_roles(),
                static fn (string $candidate): bool => permission_field_editable($candidate, $fieldKey, $pdo)
            )),
            'editable'       => permission_field_editable($role, $fieldKey, $pdo),
            'sort_order'     => (int) $def['sort_order'],
        ];
    }
    return $out;
}

/** GET /meta */
function panel_meta_show(array $params = []): array
{
    $user = session_require();
    $pdo = db_try();

    $sources = [];
    if ($pdo !== null) {
        foreach (sources_all($pdo) as $source) {
            $sources[] = [
                'slug'       => (string) $source['slug'],
                'label'      => (string) $source['label'],
                'color'      => $source['color'] ?? null,
                'is_system'  => (int) ($source['is_system'] ?? 0) === 1,
                'is_default' => (int) ($source['is_default'] ?? 0) === 1,
            ];
        }
    }

    // Only unrestricted (superadmin) accounts may switch company in the grid.
    $companies = [];
    if ($pdo !== null && $user['company'] === '') {
        $companies = asset_companies($pdo);
    }

    $requireSn = true;
    try {
        $requireSn = settings_bool('assets.require_sn');
    } catch (Throwable) {
        // keep the default
    }

    // Locked layout: the SPA stops applying the per-user column order/visibility
    // (order *and* visibility), because the admin decided the grid for everyone.
    $layout = grid_layout_get($pdo);

    return ['data' => [
        'columns'       => panel_columns_payload($user['role'], $pdo),
        'statuses'      => panel_status_payload(),
        'deleteReasons' => panel_delete_reason_payload(),
        'sources'       => $sources,
        'fields'        => panel_fields_payload($pdo, $user['role']),
        'companies'     => $companies,
        'locales'       => panel_locales_payload(),
        'themes'        => user_preferences_themes(),
        'pageSizes'     => user_preferences_page_sizes(),
        'capabilities'  => panel_capabilities($user['role']),
        'defaultSort'   => ['sort' => 'updated_at', 'dir' => 'desc'],
        'virtualSorts'  => ['files'],
        'gridLayout'    => [
            'locked'  => (bool) $layout['locked'],
            'enabled' => array_values((array) $layout['enabled']),
            'order'   => array_values((array) $layout['order']),
        ],
        'writeColumns'  => panel_write_columns_payload($user['role']),
        'limits'        => [
            'requireSn'      => $requireSn,
            'maxUploadBytes' => defined('PANEL_MAX_UPLOAD_BYTES') ? PANEL_MAX_UPLOAD_BYTES : 104857600,
        ],
        'schemaReady'   => app_schema_ready(),
    ]];
}

