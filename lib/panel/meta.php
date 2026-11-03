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
    return [
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
        'BYOD'            => 'col_byod',
        'Status'          => 'col_status',
        'Warranty'        => 'col_warranty',
        'Asset'           => 'col_asset',
        'PurchaseDate'    => 'col_purchase_date',
        'CypherID'        => 'col_cypher_id',
        'CypherKey'       => 'col_cypher_key',
        'Notes'           => 'col_notes',
        'CPUs'            => 'col_cpus',
        'HDs'             => 'col_hds',
        'HDsTypes'        => 'col_hds_types',
        'HDsSpacesGB'     => 'col_hds_spaces_gb',
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
 */
function panel_columns_payload(string $role): array
{
    $editable = asset_grid_editable_columns($role);
    // Sortable even when hidden: the grid may sort by them without showing them.
    $alwaysSortable = ['source', 'updated_at'];
    $out = [];

    foreach (asset_column_defs() as $column => $def) {
        $out[] = [
            'key'       => $column,
            'api_key'   => panel_payload_key($column),
            'label_key' => panel_column_label_key($column),
            'label'     => (string) ($def['label'] ?? $column),
            'type'      => (string) ($def['type'] ?? 'text'),
            'in_grid'   => (bool) ($def['grid'] ?? false),
            'sortable'  => (bool) ($def['grid'] ?? false) || in_array($column, $alwaysSortable, true),
            'editable'  => in_array($column, $editable, true),
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
        'in_grid'   => true,
        'sortable'  => true,
        'editable'  => false,
        'virtual'   => true,
    ];

    return $out;
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
    $creatable = asset_create_columns($role);
    $form = [];
    $create = [];
    foreach (asset_form_columns() as $column) {
        $key = array_search($column, $map, true);
        if ($key === false) {
            continue;
        }
        $form[] = (string) $key;
        if (in_array($column, $creatable, true)) {
            $create[] = (string) $key;
        }
    }
    if (isset($map['source'])) {
        $create[] = 'source';
    }

    return ['create' => $create, 'update' => $form];
}

/** Custom field definitions (grid-visible ones become extra columns). */
function panel_fields_payload(?PDO $pdo): array
{
    if ($pdo === null) {
        return [];
    }
    $out = [];
    foreach (field_defs($pdo) as $def) {
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
            'in_grid'        => (int) $def['show_in_grid'] === 1,
            'editable_roles' => array_values(array_filter(array_map(
                'trim',
                explode(',', (string) ($def['editable_roles'] ?? ''))
            ))),
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

    return ['data' => [
        'columns'       => panel_columns_payload($user['role']),
        'statuses'      => panel_status_payload(),
        'deleteReasons' => panel_delete_reason_payload(),
        'sources'       => $sources,
        'fields'        => panel_fields_payload($pdo),
        'companies'     => $companies,
        'locales'       => panel_locales_payload(),
        'themes'        => user_preferences_themes(),
        'pageSizes'     => user_preferences_page_sizes(),
        'capabilities'  => panel_capabilities($user['role']),
        'defaultSort'   => ['sort' => 'updated_at', 'dir' => 'desc'],
        'virtualSorts'  => ['files'],
        'writeColumns'  => panel_write_columns_payload($user['role']),
        'limits'        => [
            'requireSn'      => $requireSn,
            'maxUploadBytes' => defined('PANEL_MAX_UPLOAD_BYTES') ? PANEL_MAX_UPLOAD_BYTES : 104857600,
        ],
        'schemaReady'   => app_schema_ready(),
    ]];
}

