<?php
declare(strict_types=1);

/**
 * public/api.php — REST API front controller.
 *
 * Mounted as /api/ by nginx (see Dockerfile) and also reachable directly as
 * api.php?path=/v1/devices for deployments without a rewrite.
 *
 * Every response is JSON:
 *   {"ok":true,"data":…}                      success
 *   {"ok":false,"error":{"code","message",…}} failure
 */

require_once __DIR__ . '/../lib/bootstrap.php';

// API responses must stay valid JSON even if PHP emits a notice.
ini_set('display_errors', '0');
error_reporting(E_ALL);

header('X-Content-Type-Options: nosniff');

/** Normalised request path ("/v1/devices/12"). */
function api_path(): string
{
    $path = (string) ($_SERVER['PATH_INFO'] ?? '');
    if ($path === '' && isset($_GET['path'])) {
        $path = (string) $_GET['path'];
    }
    if ($path === '') {
        $uri = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
        if (preg_match('#/api(?:\.php)?(/.*)$#', $uri, $match) === 1) {
            $path = $match[1];
        }
    }
    $path = '/' . trim($path, '/');
    // Tolerate proxies that forward the full URI (/api/v1/...) as PATH_INFO.
    if ($path === '/api' || str_starts_with($path, '/api/')) {
        $path = substr($path, 4);
    }
    $path = '/' . trim((string) $path, '/');
    return $path === '/' ? '/' : rtrim($path, '/');
}

/** @return list<string> */
function api_segments(string $path): array
{
    return array_values(array_filter(explode('/', trim($path, '/')), static fn (string $p): bool => $p !== ''));
}

/** Load custom values and build the API representation of one asset. */
function api_device_payload(PDO $pdo, array $row, bool $withFiles = false): array
{
    if (!isset($row['Id'])) {
        throw new ApiError('internal_error', 'Asset could not be reloaded after writing.', 500);
    }
    $assetId = (int) $row['Id'];
    $custom = custom_values_for_asset($pdo, $assetId);
    $payload = asset_api_payload($row, $custom);

    if ($withFiles) {
        $payload['files'] = array_map(static fn (array $file): array => [
            'id'        => (int) $file['id'],
            'name'      => $file['file_name'] ?? null,
            'path'      => $file['file_path'] ?? null,
            'mime_type' => $file['mime_type'] ?? null,
            'size'      => isset($file['size']) ? (int) $file['size'] : null,
            'uploaded_at' => asset_iso_datetime($file['uploaded_at'] ?? null),
        ], asset_files($pdo, $assetId));
    }

    return $payload;
}

/**
 * Resolve the tenant for a write.
 *
 * @throws ApiError
 */
function api_resolve_company(array $auth, array $data, bool $required = true): ?string
{
    $payloadCompany = trim((string) ($data['company'] ?? ''));
    if ($auth['company'] !== null) {
        if ($payloadCompany !== '' && $payloadCompany !== $auth['company']) {
            throw new ApiError('forbidden', 'This API key is restricted to company "' . $auth['company'] . '".', 403);
        }
        return $auth['company'];
    }
    if ($payloadCompany === '' && $required) {
        throw new ApiError('validation_failed', 'The "company" field is required for this key.', 422, ['company' => 'required']);
    }
    return $payloadCompany === '' ? null : $payloadCompany;
}

/**
 * Source stamped on writes: payload → key default → global default.
 *
 * @throws ApiError
 */
function api_resolve_source(array $auth, array $data): string
{
    $payloadSource = trim((string) ($data['source'] ?? ''));
    if ($payloadSource !== '' && !source_exists(db(), $payloadSource)) {
        throw new ApiError('validation_failed', 'Unknown source "' . $payloadSource . '".', 422, ['source' => 'unknown_source']);
    }
    if ($payloadSource !== '' && !api_key_allows_source($auth, $payloadSource)) {
        throw new ApiError('forbidden', 'This API key may not write source "' . $payloadSource . '".', 403);
    }

    $source = $payloadSource !== ''
        ? $payloadSource
        : ($auth['default_source'] ?? source_default_slug(db()));

    if (!api_key_allows_source($auth, $source)) {
        throw new ApiError('forbidden', 'This API key may not write source "' . $source . '".', 403);
    }
    return $source;
}

/**
 * Validate custom values without writing them.
 *
 * @return array<string, string> field_key => error code
 */
function api_validate_custom(PDO $pdo, array $payload): array
{
    $defs = field_defs_by_key($pdo);
    $errors = [];
    foreach (custom_input_from($payload) as $key => $value) {
        if (!isset($defs[$key])) {
            $errors['custom.' . $key] = 'unknown_field';
            continue;
        }
        $parsed = field_parse_value($defs[$key], $value);
        if (!$parsed['ok']) {
            $errors['custom.' . $key] = (string) $parsed['error'];
        }
    }
    return $errors;
}

/** Merge the errors of two validations, prefixing custom ones. */
function api_merge_errors(array $base, array $custom): array
{
    foreach ($custom as $key => $code) {
        $base[str_starts_with((string) $key, 'custom.') ? (string) $key : 'custom.' . $key] = $code;
    }
    return $base;
}

/**
 * Create or upsert one device from an API payload.
 *
 * @return array{device: array, created: bool}
 * @throws ApiError
 */
function api_write_device(PDO $pdo, array $auth, array $payload, string $mode = 'upsert'): array
{
    $actor = 'api:' . (string) ($auth['key']['label'] ?? 'key');
    $mapped = asset_api_map_input($payload);
    $data = $mapped['data'];

    $errors = [];
    foreach ($mapped['unknown'] as $unknown) {
        $errors[(string) $unknown] = 'unknown_field';
    }

    $company = api_resolve_company($auth, $data, true);
    $source = api_resolve_source($auth, $data);
    $sourceRef = trim((string) ($data['source_ref'] ?? '')) ?: null;

    unset($data['source'], $data['source_ref']);
    $data = asset_normalize_input($data);
    $data['company'] = $company;

    $existing = null;
    if ($mode === 'upsert') {
        if (!empty($data['UUID'])) {
            $existing = asset_find_by($pdo, ['UUID' => $data['UUID']], $company);
        }
        if ($existing === null && !empty($data['SN'])) {
            $existing = asset_find_by($pdo, ['SN' => $data['SN']], $company);
        }
    }

    $partial = $existing !== null;
    $errors = api_merge_errors(
        array_merge($errors, asset_validate($data, $partial)),
        api_validate_custom($pdo, $payload)
    );
    if ($errors !== []) {
        throw new ApiError('validation_failed', 'Payload validation failed.', 422, $errors);
    }

    if ($existing === null) {
        $duplicate = asset_find_duplicate($pdo, $data, null, $company);
        if ($duplicate !== null) {
            throw new ApiError('conflict', 'An asset with the same ' . $duplicate['field'] . ' already exists.', 409, [
                $duplicate['field']  => 'duplicate',
                'existing_device_id' => (string) $duplicate['id'],
            ]);
        }
    }

    $replaceCustom = ($_GET['custom_mode'] ?? '') === 'replace';
    $pdo->beginTransaction();
    try {
        if ($existing !== null) {
            $id = (int) $existing['Id'];
            asset_update($pdo, $id, $data, $actor, $company);
            $created = false;
        } else {
            $id = asset_create($pdo, $data, $actor, $source, $sourceRef);
            $created = true;
        }

        $customErrors = custom_values_save($pdo, $id, custom_input_from($payload), null, $actor, $replaceCustom);
        if ($customErrors !== []) {
            $pdo->rollBack();
            throw new ApiError('validation_failed', 'Custom field validation failed.', 422, api_merge_errors([], $customErrors));
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    $row = asset_get($pdo, $id, $company, true) ?? [];
    return ['device' => api_device_payload($pdo, $row), 'created' => $created];
}

/**
 * PATCH-style partial update.
 *
 * @throws ApiError
 */
function api_patch_device(PDO $pdo, array $auth, int $id, array $payload): array
{
    $actor = 'api:' . (string) ($auth['key']['label'] ?? 'key');
    $row = asset_get($pdo, $id, $auth['company']);
    if ($row === null) {
        throw new ApiError('not_found', 'Device not found.', 404);
    }
    $company = (string) $row['company'];

    $mapped = asset_api_map_input($payload);
    $data = $mapped['data'];
    $errors = [];
    foreach ($mapped['unknown'] as $unknown) {
        $errors[(string) $unknown] = 'unknown_field';
    }
    unset($data['company']); // moving assets between tenants is not allowed here

    if (isset($data['source']) && trim((string) $data['source']) !== '') {
        if (!source_exists($pdo, (string) $data['source'])) {
            $errors['source'] = 'unknown_source';
        } elseif (!api_key_allows_source($auth, (string) $data['source'])) {
            throw new ApiError('forbidden', 'This API key may not use source "' . $data['source'] . '".', 403);
        }
    }

    $data = asset_normalize_input($data);
    $errors = api_merge_errors(
        array_merge($errors, asset_validate($data, true)),
        api_validate_custom($pdo, $payload)
    );
    if ($errors !== []) {
        throw new ApiError('validation_failed', 'Payload validation failed.', 422, $errors);
    }

    $source = trim((string) ($data['source'] ?? ''));
    $sourceRef = array_key_exists('source_ref', $data) ? $data['source_ref'] : null;
    unset($data['source'], $data['source_ref']);

    $replaceCustom = ($_GET['custom_mode'] ?? '') === 'replace';
    $pdo->beginTransaction();
    try {
        $changes = $data;
        if ($source !== '') {
            $changes['source'] = $source;
        }
        if ($sourceRef !== null) {
            $changes['source_ref'] = $sourceRef;
        }
        asset_update($pdo, $id, $changes, $actor, $company);

        $customErrors = custom_values_save($pdo, $id, custom_input_from($payload), null, $actor, $replaceCustom);
        if ($customErrors !== []) {
            $pdo->rollBack();
            throw new ApiError('validation_failed', 'Custom field validation failed.', 422, api_merge_errors([], $customErrors));
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    $row = asset_get($pdo, $id, $company, true) ?? [];
    return ['device' => api_device_payload($pdo, $row)];
}

/**
 * Delete (soft by default, hard with ?hard=1).
 *
 * @throws ApiError
 */
function api_delete_device(PDO $pdo, array $auth, int $id): array
{
    $actor = 'api:' . (string) ($auth['key']['label'] ?? 'key');
    if (asset_get($pdo, $id, $auth['company']) === null) {
        throw new ApiError('not_found', 'Device not found.', 404);
    }
    $hard = ($_GET['hard'] ?? '') === '1';
    if ($hard) {
        asset_hard_delete($pdo, $id, $auth['company']);
    } else {
        asset_soft_delete($pdo, $id, $actor, $auth['company']);
    }

    return ['id' => $id, 'deleted' => true, 'hard' => $hard];
}

/**
 * Paged list with the filters documented in docs/api.md.
 *
 * @throws ApiError
 */
function api_list_devices(PDO $pdo, array $auth): array
{
    $company = $auth['company'];
    if ($company === null) {
        $company = trim((string) ($_GET['company'] ?? ''));
    }

    $uuid = trim((string) ($_GET['uuid'] ?? ''));
    $sn = trim((string) ($_GET['sn'] ?? ''));
    if ($uuid !== '' || $sn !== '') {
        $row = asset_find_by($pdo, ['UUID' => $uuid, 'SN' => $sn], $company);
        if ($row === null) {
            throw new ApiError('not_found', 'Device not found.', 404);
        }
        return ['devices' => [api_device_payload($pdo, $row)], 'total' => 1, 'page' => 1, 'pages' => 1];
    }

    $maxPageSize = max(1, settings_int('api.max_page_size'));
    $perPage = (int) ($_GET['per_page'] ?? 25);
    $perPage = max(1, min($maxPageSize, $perPage));

    $result = asset_list($pdo, [
        'company'         => $company ?? '',
        'source'          => (string) ($_GET['source'] ?? ''),
        'search'          => (string) ($_GET['search'] ?? ''),
        'search_field'    => (string) ($_GET['field'] ?? ''),
        'updated_since'   => asset_datetime_normalize($_GET['updated_since'] ?? '') ?? '',
        'include_deleted' => isset($_GET['include_deleted']) && $_GET['include_deleted'] === '1',
        'sort'            => (string) ($_GET['sort'] ?? ''),
        'dir'             => (string) ($_GET['dir'] ?? 'asc'),
        'page'            => (int) ($_GET['page'] ?? 1),
        'per_page'        => $perPage,
    ]);

    $devices = [];
    foreach ($result['rows'] as $row) {
        $devices[] = api_device_payload($pdo, $row);
    }

    return [
        'devices'  => $devices,
        'total'    => $result['total'],
        'page'     => $result['page'],
        'per_page' => $result['per_page'],
        'pages'    => $result['pages'],
    ];
}

/**
 * Bulk upsert with per-item results (max 500 per request).
 *
 * @throws ApiError
 */
function api_bulk_devices(PDO $pdo, array $auth, array $body): array
{
    $items = $body['devices'] ?? $body;
    if (!is_array($items) || $items === [] || !array_is_list($items)) {
        throw new ApiError('validation_failed', 'Body must be a non-empty array of devices (or {"devices": [...]}).', 422, [
            'devices' => 'invalid',
        ]);
    }
    if (count($items) > 500) {
        throw new ApiError('validation_failed', 'A bulk request accepts at most 500 devices.', 422, ['devices' => 'too_many']);
    }

    $results = [];
    $created = 0;
    $updated = 0;
    $failed = 0;

    foreach ($items as $index => $item) {
        if (!is_array($item)) {
            $failed++;
            $results[] = ['index' => $index, 'ok' => false, 'error' => ['code' => 'validation_failed', 'message' => 'Each item must be an object.']];
            continue;
        }
        try {
            $outcome = api_write_device($pdo, $auth, $item, 'upsert');
            $created += $outcome['created'] ? 1 : 0;
            $updated += $outcome['created'] ? 0 : 1;
            $results[] = ['index' => $index, 'ok' => true, 'created' => $outcome['created'], 'device_id' => $outcome['device']['id']];
        } catch (ApiError $e) {
            $failed++;
            $results[] = [
                'index' => $index,
                'ok'    => false,
                'error' => ['code' => $e->errorCode, 'message' => $e->getMessage(), 'errors' => $e->errors],
            ];
        }
    }

    return [
        'results' => $results,
        'summary' => ['total' => count($items), 'created' => $created, 'updated' => $updated, 'failed' => $failed],
    ];
}

/** @throws ApiError */
function api_method_not_allowed(array $allowed): never
{
    header('Allow: ' . implode(', ', $allowed));
    throw new ApiError('method_not_allowed', 'Method not allowed. Allowed: ' . implode(', ', $allowed), 405);
}

/** @throws ApiError */
function api_require_method(array $allowed): string
{
    $method = request_method();
    if (!in_array($method, $allowed, true)) {
        api_method_not_allowed($allowed);
    }
    return $method;
}

// --- Router -------------------------------------------------------------

try {
    $pdo = db();

    if (!app_schema_ready()) {
        throw new ApiError('schema_not_ready', 'Database schema is not migrated. Run bin/migrate.php.', 503);
    }
    if (!settings_bool('api.enabled')) {
        throw new ApiError('api_disabled', 'The API is disabled. Enable it in Settings → API.', 503);
    }

    $method = request_method();
    if ($method === 'OPTIONS') {
        header('Allow: GET, POST, PATCH, PUT, DELETE, OPTIONS');
        http_response_code(204);
        exit;
    }

    $auth = api_authenticate($pdo, client_ip());
    $segments = api_segments(api_path());
    $resource = ($segments[0] ?? '') === 'v1' ? ($segments[1] ?? '') : null;
    if ($resource === null || $resource === '') {
        throw new ApiError('not_found', 'Unknown API path. Use /api/v1/...', 404);
    }

    if ($resource === 'health') {
        api_require_method(['GET']);
        $status = migrate_status($pdo);
        json_ok([
            'status'         => 'ok',
            'version'        => APP_VERSION,
            'schema_ready'   => $status['ready'],
            'pending'        => $status['pending'],
            'server_time'    => gmdate('Y-m-d\TH:i:s\Z'),
            'timezone'       => app_timezone(),
            'key_label'      => $auth['key']['label'] ?? null,
            'key_company'    => $auth['company'],
            'key_sources'    => $auth['sources'],
            'default_source' => $auth['default_source'] ?? source_default_slug($pdo),
        ]);
    }

    if ($resource === 'sources') {
        api_require_method(['GET']);
        api_require_scope($auth, 'read');
        $sources = [];
        foreach (sources_all($pdo) as $source) {
            $sources[] = [
                'slug'       => $source['slug'],
                'label'      => $source['label'],
                'color'      => $source['color'],
                'is_default' => (bool) $source['is_default'],
                'is_system'  => (bool) $source['is_system'],
            ];
        }
        json_ok(['sources' => $sources]);
    }

    if ($resource === 'fields') {
        api_require_method(['GET']);
        api_require_scope($auth, 'read');
        $fields = [];
        foreach (field_defs($pdo, false) as $def) {
            $i18n = json_decode((string) ($def['label_i18n'] ?? ''), true);
            $fields[] = [
                'key'            => $def['field_key'],
                'label'          => $def['label'],
                'label_i18n'     => is_array($i18n) && $i18n !== [] ? $i18n : (object) [],
                'type'           => $def['data_type'],
                'options'        => field_options($def),
                'validation'     => (object) field_rules($def),
                'required'       => (bool) $def['is_required'],
                'unique'         => (bool) $def['is_unique'],
                'searchable'     => (bool) $def['is_searchable'],
                'show_in_grid'   => (bool) $def['show_in_grid'],
                'editable_roles' => field_editable_roles($def),
                'default'        => field_default_typed($def),
                'active'         => (bool) $def['is_active'],
            ];
        }
        json_ok(['fields' => $fields, 'types' => array_keys(field_types())]);
    }

    if ($resource !== 'devices') {
        throw new ApiError('not_found', 'Unknown API resource: ' . $resource, 404);
    }

    $idPart = $segments[2] ?? '';
    $sub = $segments[3] ?? '';

    if ($idPart === '') {
        if ($method === 'GET') {
            api_require_scope($auth, 'read');
            json_ok(api_list_devices($pdo, $auth));
        }
        if ($method === 'POST') {
            api_require_scope($auth, 'write');
            $outcome = api_write_device($pdo, $auth, request_json_body(), 'create');
            json_ok($outcome['device'], 201);
        }
        api_method_not_allowed(['GET', 'POST']);
    }

    if ($idPart === 'upsert') {
        if (!in_array($method, ['POST', 'PUT'], true)) {
            api_method_not_allowed(['POST', 'PUT']);
        }
        api_require_scope($auth, 'write');
        $outcome = api_write_device($pdo, $auth, request_json_body(), 'upsert');
        json_ok($outcome['device'], $outcome['created'] ? 201 : 200, ['created' => $outcome['created']]);
    }

    if ($idPart === 'bulk') {
        if ($method !== 'POST') {
            api_method_not_allowed(['POST']);
        }
        api_require_scope($auth, 'write');
        json_ok(api_bulk_devices($pdo, $auth, request_json_body()));
    }

    if (!ctype_digit($idPart)) {
        throw new ApiError('not_found', 'Unknown devices endpoint.', 404);
    }
    $id = (int) $idPart;

    if ($sub === 'files') {
        api_require_method(['GET']);
        api_require_scope($auth, 'read');
        if (asset_get($pdo, $id, $auth['company']) === null) {
            throw new ApiError('not_found', 'Device not found.', 404);
        }
        $files = [];
        foreach (asset_files($pdo, $id) as $file) {
            $files[] = [
                'id'          => (int) $file['id'],
                'name'        => $file['file_name'] ?? null,
                'path'        => $file['file_path'] ?? null,
                'mime_type'   => $file['mime_type'] ?? null,
                'size'        => isset($file['size']) ? (int) $file['size'] : null,
                'uploaded_at' => asset_iso_datetime($file['uploaded_at'] ?? null),
            ];
        }
        json_ok(['device_id' => $id, 'files' => $files]);
    }

    if ($sub !== '') {
        throw new ApiError('not_found', 'Unknown devices endpoint.', 404);
    }

    if ($method === 'GET') {
        api_require_scope($auth, 'read');
        $row = asset_get($pdo, $id, $auth['company']);
        if ($row === null) {
            throw new ApiError('not_found', 'Device not found.', 404);
        }
        $with = (string) ($_GET['with'] ?? '');
        json_ok(api_device_payload($pdo, $row, str_contains($with, 'files')));
    }
    if (in_array($method, ['PATCH', 'PUT'], true)) {
        api_require_scope($auth, 'write');
        $outcome = api_patch_device($pdo, $auth, $id, request_json_body());
        json_ok($outcome['device']);
    }
    if ($method === 'DELETE') {
        api_require_scope($auth, 'delete');
        json_ok(api_delete_device($pdo, $auth, $id));
    }
    api_method_not_allowed(['GET', 'PATCH', 'PUT', 'DELETE']);
} catch (ApiError $e) {
    if ($e->status === 401) {
        header('WWW-Authenticate: Bearer realm="bundledcmdb"');
    }
    json_fail($e->errorCode, $e->getMessage(), $e->status, $e->errors);
} catch (Throwable $e) {
    error_log('[bundledcmdb api] ' . get_class($e) . ': ' . $e->getMessage());
    json_fail('internal_error', 'Unexpected server error. Check the application log.', 500);
}


