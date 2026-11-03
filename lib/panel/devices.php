<?php
declare(strict_types=1);

/**
 * lib/panel/devices.php — device (asset) endpoints for the panel API.
 *
 *   GET  /panel-api/v1/devices          list + filter + sort + paging
 *   GET  /panel-api/v1/devices/{id}     one device (+ custom values + files)
 *   GET  /panel-api/v1/devices/{id}/files
 *   GET  /panel-api/v1/devices/export   CSV with the same filters/sort
 *
 * All reads go through the same rules as `asset_list()` (lib/assets.php) but
 * fix the two regressions from `2279453`: the default order is
 * `updated_at DESC, Id DESC` instead of `Id ASC`, and **every** visible column
 * is sortable — including `source`, `updated_at`, custom fields and the virtual
 * `files` column (COUNT over `device_files`). An unknown sort column is a 400,
 * never a silent fallback.
 */

/** Per-page ceiling when `api.max_page_size` is unusable. */
const PANEL_MAX_PER_PAGE = 200;

/** Columns any role may sort by, resolved against the live schema. */
function panel_sortable_columns(PDO $pdo): array
{
    $columns = [];
    foreach (asset_column_defs() as $column => $def) {
        if (db_column_exists($pdo, 'assets', $column)) {
            $columns[] = $column;
        }
    }
    // Virtual / always-present sort keys.
    $columns[] = 'files';
    if (db_column_exists($pdo, 'assets', 'updated_at')) {
        $columns[] = 'updated_at';
    }
    return array_values(array_unique($columns));
}

/**
 * Validate and normalise the query string of `GET /devices`.
 *
 * @param array<string, mixed> $query
 * @return array{q: string, source: string, status: string, company: string,
 *               byod: ?bool, trashed: bool, sort: string, dir: string,
 *               page: int, per_page: int}
 */
function panel_devices_params(array $query): array
{
    $page = filter_var($query['page'] ?? 1, FILTER_VALIDATE_INT);
    $page = ($page === false || $page < 1) ? 1 : (int) $page;

    $defaultPerPage = 25;
    $maxPerPage = PANEL_MAX_PER_PAGE;
    try {
        $defaultPerPage = max(5, (int) settings_get('general.per_page', 25));
        $maxPerPage = max(5, (int) settings_get('api.max_page_size', PANEL_MAX_PER_PAGE));
    } catch (Throwable) {
        // keep the built-in defaults
    }
    $perPage = filter_var($query['per_page'] ?? $defaultPerPage, FILTER_VALIDATE_INT);
    $perPage = $perPage === false ? $defaultPerPage : (int) $perPage;
    $perPage = max(5, min($maxPerPage, $perPage));

    $status = trim((string) ($query['status'] ?? ''));
    if ($status !== '' && !in_array($status, asset_status_values(), true)) {
        throw new ApiError('invalid_filter', 'Unknown status filter.', 400, ['status' => 'invalid_option']);
    }

    $byod = null;
    $rawByod = $query['byod'] ?? null;
    if ($rawByod !== null && $rawByod !== '') {
        $parsed = filter_var($rawByod, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        if ($parsed === null) {
            throw new ApiError('invalid_filter', 'byod must be true or false.', 400, ['byod' => 'invalid_boolean']);
        }
        $byod = $parsed;
    }

    $dir = strtolower(trim((string) ($query['dir'] ?? '')));
    if (!in_array($dir, ['asc', 'desc'], true)) {
        $dir = 'desc';
    }

    return [
        'q'        => trim((string) ($query['q'] ?? '')),
        'source'   => trim((string) ($query['source'] ?? '')),
        'status'   => $status,
        'company'  => trim((string) ($query['company'] ?? '')),
        'byod'     => $byod,
        'trashed'  => filter_var($query['trashed'] ?? false, FILTER_VALIDATE_BOOLEAN),
        'sort'     => trim((string) ($query['sort'] ?? '')),
        'dir'      => $dir,
        'page'     => $page,
        'per_page' => $perPage,
    ];
}

/**
 * Resolve the ORDER BY clause.
 *
 * @return array{sql: string, sort: string, dir: string}
 *
 * @throws ApiError('invalid_sort', …, 400) for an unknown column
 */
function panel_devices_order_by(PDO $pdo, string $sort, string $dir): array
{
    $direction = $dir === 'asc' ? 'ASC' : 'DESC';
    $tieBreaker = 'a.`Id` ' . $direction;

    if ($sort === '') {
        if (db_column_exists($pdo, 'assets', 'updated_at')) {
            return ['sql' => 'a.`updated_at` ' . $direction . ', ' . $tieBreaker, 'sort' => 'updated_at', 'dir' => $dir];
        }
        return ['sql' => 'a.`Id` ' . $direction, 'sort' => 'Id', 'dir' => $dir];
    }

    if ($sort === 'files') {
        return [
            'sql'  => '(SELECT COUNT(*) FROM device_files df'
                . " WHERE df.device_id = a.`Id` AND df.device_table = 'assets') " . $direction
                . ', ' . $tieBreaker,
            'sort' => 'files',
            'dir'  => $dir,
        ];
    }

    if (str_starts_with($sort, 'custom:')) {
        $fieldKey = substr($sort, 7);
        $defs = field_defs_by_key($pdo);
        if ($fieldKey === '' || !isset($defs[$fieldKey])) {
            throw new ApiError('invalid_sort', 'Unknown custom field in sort.', 400, ['sort' => 'unknown_field']);
        }
        return [
            'sql'  => '(SELECT sv.value FROM asset_custom_values sv'
                . ' WHERE sv.asset_id = a.`Id` AND sv.field_id = ' . (int) $defs[$fieldKey]['id'] . ') '
                . $direction . ', ' . $tieBreaker,
            'sort' => 'custom:' . $fieldKey,
            'dir'  => $dir,
        ];
    }

    if (!in_array($sort, panel_sortable_columns($pdo), true)) {
        throw new ApiError('invalid_sort', 'Unknown sort column: ' . $sort . '.', 400, ['sort' => 'unknown_column']);
    }

    return ['sql' => 'a.`' . $sort . '` ' . $direction . ', ' . $tieBreaker, 'sort' => $sort, 'dir' => $dir];
}

/**
 * WHERE clause for the list/export query.
 *
 * @param array{email: string, company: string, role: string} $user
 * @param array<string, mixed> $params result of panel_devices_params()
 * @return array{sql: string, bind: list<mixed>}
 */
function panel_devices_where(PDO $pdo, array $user, array $params): array
{
    $where = ['1 = 1'];
    $bind = [];

    // A company-bound account can never widen the scope; only an unrestricted
    // account (company = '') may pick a company or see all of them.
    $company = $user['company'] !== '' ? $user['company'] : (string) $params['company'];
    if ($company !== '') {
        $where[] = 'a.`company` = ?';
        $bind[] = $company;
    }

    if (db_column_exists($pdo, 'assets', 'deleted_at')) {
        $where[] = $params['trashed'] ? 'a.`deleted_at` IS NOT NULL' : 'a.`deleted_at` IS NULL';
    }

    if ($params['source'] !== '' && db_column_exists($pdo, 'assets', 'source')) {
        $where[] = 'a.`source` = ?';
        $bind[] = $params['source'];
    }

    if ($params['status'] !== '') {
        $where[] = 'a.`Status` = ?';
        $bind[] = $params['status'];
    }

    if ($params['byod'] !== null && db_column_exists($pdo, 'assets', 'BYOD')) {
        // `BYOD` is a VARCHAR: `asset_bool_normalize()` writes 'true'/'false', but
        // installations imported from the legacy grid also hold '1'/'0' and
        // 'yes'/'no'. Comparing against an integer (`BYOD = 1`) casts the column
        // to a number, so 'true' becomes 0 and the "BYOD only" filter silently
        // returned nothing — found by bin/bench-devices.php (phase 2).
        $spellings = $params['byod']
            ? ['1', 'true', 'yes', 'on']
            : ['0', 'false', 'no', 'off'];
        $where[] = 'LOWER(TRIM(a.`BYOD`)) IN ('
            . implode(',', array_fill(0, count($spellings), '?')) . ')';
        foreach ($spellings as $spelling) {
            $bind[] = $spelling;
        }
    }

    $search = (string) $params['q'];
    if ($search !== '') {
        $like = '%' . $search . '%';
        $any = [];
        foreach (asset_column_defs() as $column => $def) {
            if (empty($def['api']) || in_array($def['type'], ['datetime', 'date', 'int'], true)) {
                continue;
            }
            if (!db_column_exists($pdo, 'assets', $column)) {
                continue;
            }
            $any[] = 'a.`' . $column . '` LIKE ?';
            $bind[] = $like;
        }
        $any[] = 'EXISTS (SELECT 1 FROM asset_custom_values v'
            . ' JOIN asset_field_defs f ON f.id = v.field_id'
            . ' WHERE v.asset_id = a.`Id` AND f.is_searchable = 1 AND v.value LIKE ?)';
        $bind[] = $like;

        if ($any !== []) {
            $where[] = '(' . implode(' OR ', $any) . ')';
        }
    }

    return ['sql' => implode(' AND ', $where), 'bind' => $bind];
}

/**
 * Wire representation of one device row (snake_case, like `api.php`).
 *
 * @param array<string, mixed> $row    raw `assets` row
 * @param array<string, mixed> $custom field_key => typed value
 */
function panel_device_payload(array $row, array $custom = [], int $filesCount = 0): array
{
    return [
        'id'                => (int) ($row['Id'] ?? 0),
        'uuid'              => $row['UUID'] ?? null,
        'sn'                => $row['SN'] ?? null,
        'os'                => $row['OS'] ?? null,
        'os_version'        => $row['OSVersion'] ?? null,
        'hostname'          => $row['Hostname'] ?? null,
        'mobile'            => asset_bool_parse($row['Mobile'] ?? null),
        'manufacturer'      => $row['Manufacturer'] ?? null,
        'last_seen'         => asset_iso_datetime(isset($row['LastSeen']) ? (string) $row['LastSeen'] : null),
        'user_email'        => $row['UserEmail'] ?? null,
        'byod'              => asset_bool_parse($row['BYOD'] ?? null),
        'status'            => $row['Status'] ?? null,
        'warranty'          => $row['Warranty'] ?? null,
        'asset'             => $row['Asset'] ?? null,
        'purchase_date'     => $row['PurchaseDate'] ?? null,
        'cypher_id'         => $row['CypherID'] ?? null,
        'cypher_key'        => $row['CypherKey'] ?? null,
        'notes'             => $row['Notes'] ?? null,
        'cpus'              => $row['CPUs'] ?? null,
        'hds'               => $row['HDs'] ?? null,
        'hds_types'         => $row['HDsTypes'] ?? null,
        'hds_space_gb'      => $row['HDsSpacesGB'] ?? null,
        'network_adapters'  => $row['NetworkAdapters'] ?? null,
        'mac_addresses'     => $row['MACAddresses'] ?? null,
        'eset_components'   => $row['ESETComponents'] ?? null,
        'primary_local_ip'  => $row['PrimaryLocalIP'] ?? null,
        'primary_remote_ip' => $row['PrimaryRemoteIP'] ?? null,
        'company'           => $row['company'] ?? null,
        'source'            => $row['source'] ?? null,
        'source_ref'        => $row['source_ref'] ?? null,
        'created_at'        => asset_iso_datetime(isset($row['created_at']) ? (string) $row['created_at'] : null),
        'updated_at'        => asset_iso_datetime(isset($row['updated_at']) ? (string) $row['updated_at'] : null),
        'created_by'        => $row['created_by'] ?? null,
        'updated_by'        => $row['updated_by'] ?? null,
        'deleted_at'        => asset_iso_datetime(isset($row['deleted_at']) ? (string) $row['deleted_at'] : null),
        'files_count'       => $filesCount,
        'custom'            => $custom === [] ? new stdClass() : $custom,
    ];
}

/**
 * Attachment count per asset for a page of rows (single query).
 *
 * @param list<int> $ids
 * @return array<int, int>
 */
function panel_devices_files_counts(PDO $pdo, array $ids): array
{
    $ids = array_values(array_filter(array_map('intval', $ids)));
    if ($ids === [] || !db_table_exists($pdo, 'device_files')) {
        return [];
    }
    $counts = [];
    foreach (array_chunk($ids, 500) as $chunk) {
        $placeholders = implode(',', array_fill(0, count($chunk), '?'));
        $stmt = $pdo->prepare(
            "SELECT device_id, COUNT(*) AS c FROM device_files
              WHERE device_table = 'assets' AND device_id IN ({$placeholders})
              GROUP BY device_id"
        );
        $stmt->execute($chunk);
        foreach ($stmt->fetchAll() as $row) {
            $counts[(int) $row['device_id']] = (int) $row['c'];
        }
    }
    return $counts;
}


/**
 * One page of raw rows plus the total for the current filters.
 *
 * @return array{rows: list<array>, total: int}
 */
function panel_devices_fetch(PDO $pdo, array $user, array $params, array $order, int $page, int $perPage): array
{
    $filter = panel_devices_where($pdo, $user, $params);

    $countStmt = $pdo->prepare('SELECT COUNT(*) FROM assets a WHERE ' . $filter['sql']);
    $countStmt->execute($filter['bind']);
    $total = (int) $countStmt->fetchColumn();

    $offset = max(0, ($page - 1) * $perPage);
    $sql = 'SELECT a.* FROM assets a WHERE ' . $filter['sql']
        . ' ORDER BY ' . $order['sql']
        . ' LIMIT ' . max(1, $perPage) . ' OFFSET ' . $offset;

    $stmt = $pdo->prepare($sql);
    $stmt->execute($filter['bind']);

    return ['rows' => $stmt->fetchAll(), 'total' => $total];
}

/** Positive integer id from a route placeholder. */
function panel_device_id(array $params): int
{
    $raw = (string) ($params['id'] ?? '');
    if (preg_match('/^\d+$/', $raw) !== 1 || (int) $raw < 1) {
        throw new ApiError('not_found', 'Device not found.', 404);
    }
    return (int) $raw;
}

/** Attachment metadata as exposed to the SPA. */
function panel_file_payload(array $row): array
{
    return [
        'id'          => (int) ($row['id'] ?? 0),
        'name'        => (string) ($row['file_name'] ?? ''),
        'path'        => (string) ($row['file_path'] ?? ''),
        'mime'        => $row['mime_type'] ?? null,
        'size'        => isset($row['size']) ? (int) $row['size'] : null,
        'uploaded_at' => asset_iso_datetime(isset($row['uploaded_at']) ? (string) $row['uploaded_at'] : null),
    ];
}

/** GET /devices */
function panel_devices_index(array $params = []): array
{
    $user = session_require();
    $pdo = db();

    $filters = panel_devices_params($_GET);
    $order = panel_devices_order_by($pdo, $filters['sort'], $filters['dir']);

    $fetch = panel_devices_fetch($pdo, $user, $filters, $order, $filters['page'], $filters['per_page']);
    $total = $fetch['total'];
    $pages = max(1, (int) ceil($total / max(1, $filters['per_page'])));
    $page = min($filters['page'], $pages);
    if ($page !== $filters['page']) {
        $fetch = panel_devices_fetch($pdo, $user, $filters, $order, $page, $filters['per_page']);
    }

    $ids = array_map(static fn (array $row): int => (int) $row['Id'], $fetch['rows']);
    $counts = panel_devices_files_counts($pdo, $ids);
    $custom = custom_values_load($pdo, $ids, field_defs($pdo));

    $rows = [];
    foreach ($fetch['rows'] as $row) {
        $id = (int) $row['Id'];
        $rows[] = panel_device_payload($row, $custom[$id] ?? [], $counts[$id] ?? 0);
    }

    return [
        'data' => [
            'rows'     => $rows,
            'total'    => $total,
            'page'     => $page,
            'per_page' => $filters['per_page'],
            'pages'    => $pages,
        ],
        'meta' => [
            'sort'        => $order['sort'],
            'dir'         => $order['dir'],
            'filters'     => [
                'q'       => $filters['q'],
                'source'  => $filters['source'],
                'status'  => $filters['status'],
                // Echo the effective scope, not the requested one: a company-bound
                // account cannot widen it, so reporting the request would lie.
                'company' => $user['company'] !== '' ? $user['company'] : $filters['company'],
                'byod'    => $filters['byod'],
                'trashed' => $filters['trashed'],
            ],
            'generated_at' => app_now_utc(),
        ],
    ];
}

/** GET /devices/{id} */
function panel_devices_show(array $params = []): array
{
    $user = session_require();
    $pdo = db();
    $id = panel_device_id($params);

    $company = $user['company'] !== '' ? $user['company'] : null;
    $row = asset_get($pdo, $id, $company, true);
    if ($row === null) {
        throw new ApiError('not_found', 'Device not found.', 404);
    }

    $defs = field_defs($pdo);
    $custom = custom_values_for_asset($pdo, $id, $defs);
    $files = array_map('panel_file_payload', asset_files($pdo, $id));

    $payload = panel_device_payload($row, $custom, count($files));
    $payload['files'] = $files;

    return ['data' => $payload];
}

/** GET /devices/{id}/files */
function panel_devices_files_index(array $params = []): array
{
    $user = session_require();
    $pdo = db();
    $id = panel_device_id($params);

    $company = $user['company'] !== '' ? $user['company'] : null;
    if (asset_get($pdo, $id, $company, true) === null) {
        throw new ApiError('not_found', 'Device not found.', 404);
    }

    $files = array_map('panel_file_payload', asset_files($pdo, $id));
    return ['data' => ['rows' => $files, 'total' => count($files)]];
}

/**
 * GET /devices/{id}/events — the audit trail of one device.
 *
 * Readable by every role that can read the device itself (the company scope is
 * the same check as `GET /devices/{id}`), newest first, capped by `?limit=`.
 * The field names in `changes` are the schema columns: the SPA maps them to the
 * grid's `col_*` labels, so nothing has to be translated server-side.
 */
function panel_devices_events(array $params = []): array
{
    $user = session_require();
    $pdo = db();
    $id = panel_device_id($params);

    $company = $user['company'] !== '' ? $user['company'] : null;
    if (asset_get($pdo, $id, $company, true) === null) {
        throw new ApiError('not_found', 'Device not found.', 404);
    }

    $limit = (int) ($_GET['limit'] ?? 50);
    $events = asset_events_for_asset($pdo, $id, $limit);

    return ['data' => ['rows' => $events, 'total' => count($events)]];
}


/**
 * Column name => key inside `panel_device_payload()` (used by the CSV export).
 *
 * @return array<string, string>
 */
function panel_device_payload_keys(): array
{
    return [
        'Id'              => 'id',
        'UUID'            => 'uuid',
        'SN'              => 'sn',
        'OS'              => 'os',
        'OSVersion'       => 'os_version',
        'Hostname'        => 'hostname',
        'Mobile'          => 'mobile',
        'Manufacturer'    => 'manufacturer',
        'LastSeen'        => 'last_seen',
        'UserEmail'       => 'user_email',
        'BYOD'            => 'byod',
        'Status'          => 'status',
        'Warranty'        => 'warranty',
        'Asset'           => 'asset',
        'PurchaseDate'    => 'purchase_date',
        'CypherID'        => 'cypher_id',
        'CypherKey'       => 'cypher_key',
        'Notes'           => 'notes',
        'CPUs'            => 'cpus',
        'HDs'             => 'hds',
        'HDsTypes'        => 'hds_types',
        'HDsSpacesGB'     => 'hds_space_gb',
        'NetworkAdapters' => 'network_adapters',
        'MACAddresses'    => 'mac_addresses',
        'ESETComponents'  => 'eset_components',
        'PrimaryLocalIP'  => 'primary_local_ip',
        'PrimaryRemoteIP' => 'primary_remote_ip',
        'company'         => 'company',
        'source'          => 'source',
        'source_ref'      => 'source_ref',
        'created_at'      => 'created_at',
        'updated_at'      => 'updated_at',
        'created_by'      => 'created_by',
        'updated_by'      => 'updated_by',
        'deleted_at'      => 'deleted_at',
        'files'           => 'files_count',
    ];
}

/**
 * GET /devices/export — streams a CSV with the same filters, sort and visible
 * columns as `GET /devices`. Never returns: the response is written directly to
 * `php://output` (with the Excel-friendly BOM and `;` separator used by the
 * legacy export) so large result sets are not buffered in memory.
 */
function panel_devices_export(array $params = []): never
{
    $user = session_require();
    $pdo = db();

    $filters = panel_devices_params($_GET);
    $order = panel_devices_order_by($pdo, $filters['sort'], $filters['dir']);
    $prefs = user_preferences_get($user['email']);
    $locale = $prefs['locale'] ?? locale_default();

    $columns = [];
    foreach (panel_columns_payload($user['role']) as $column) {
        if (!empty($column['in_grid'])) {
            $columns[] = $column;
        }
    }
    $defs = field_defs($pdo);
    $gridFields = array_values(array_filter(
        $defs,
        static fn (array $def): bool => (int) ($def['show_in_grid'] ?? 0) === 1
    ));

    $company = $user['company'] !== '' ? $user['company'] : 'all';
    $slug = preg_replace('/[^A-Za-z0-9_-]+/', '_', $company) ?: 'all';
    $filename = 'assets-' . $slug . '-' . gmdate('Ymd-His') . '.csv';

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');

    $out = fopen('php://output', 'wb');
    if ($out === false) {
        throw new ApiError('server_error', 'Could not open the output stream.', 500);
    }
    fwrite($out, "\xEF\xBB\xBF");

    $header = [];
    foreach ($columns as $column) {
        $header[] = locale_translate((string) $column['label_key'], $locale);
    }
    foreach ($gridFields as $def) {
        $header[] = field_label($def, $locale);
    }
    fputcsv($out, $header, ';', '"', '');

    $statusKeys = [];
    foreach (panel_status_payload() as $status) {
        $statusKeys[$status['value']] = $status['label_key'];
    }
    $payloadKeys = panel_device_payload_keys();
    $yes = locale_translate('yes', $locale);
    $no = locale_translate('no', $locale);

    $perPage = 500;
    $page = 1;
    do {
        $fetch = panel_devices_fetch($pdo, $user, $filters, $order, $page, $perPage);
        $ids = array_map(static fn (array $row): int => (int) $row['Id'], $fetch['rows']);
        $counts = panel_devices_files_counts($pdo, $ids);
        $custom = custom_values_load($pdo, $ids, $defs);

        foreach ($fetch['rows'] as $row) {
            $id = (int) $row['Id'];
            $payload = panel_device_payload($row, $custom[$id] ?? [], $counts[$id] ?? 0);

            $line = [];
            foreach ($columns as $column) {
                $key = $payloadKeys[(string) $column['key']] ?? null;
                $value = $key === null ? '' : ($payload[$key] ?? '');

                if ($column['key'] === 'Status' && is_string($value) && $value !== '') {
                    $value = locale_translate($statusKeys[$value] ?? $value, $locale);
                }
                if (is_bool($value)) {
                    $value = $value ? $yes : $no;
                }
                $line[] = $value === null ? '' : (string) $value;
            }
            foreach ($gridFields as $def) {
                $line[] = field_display_value($def, $custom[$id][(string) $def['field_key']] ?? null);
            }
            fputcsv($out, $line, ';', '"', '');
        }

        $pages = max(1, (int) ceil($fetch['total'] / $perPage));
        $page++;
    } while ($page <= $pages);

    fclose($out);
    exit;
}

