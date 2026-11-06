<?php
declare(strict_types=1);

/**
 * lib/panel/routes.php — front controller for `/panel-api/v1/*`.
 *
 * Deliberately tiny: a method+pattern table, one JSON envelope (the same
 * `json_ok`/`json_fail` helpers used by `public/api.php`) and no dependency
 * beyond lib/bootstrap.php. Handlers live in lib/panel/*.php and return:
 *
 *   ['data' => mixed, 'meta' => array, 'status' => int]   // status/meta optional
 *
 * Anything they throw is mapped to the documented error envelope, so handlers
 * only deal with their own validation problems (ApiError).
 */

/**
 * Route table. Patterns use `{name}` placeholders that match one path segment.
 *
 * @return list<array{0: string, 1: string, 2: string}>
 */
function panel_routes(): array
{
    return [
        ['GET',   '/session',             'panel_session_show'],
        ['PATCH', '/session/preferences', 'panel_session_update_preferences'],
        ['POST',  '/session/logout',      'panel_session_logout'],
        ['GET',   '/meta',                'panel_meta_show'],
        ['GET',   '/i18n',                'panel_i18n_show'],
        ['GET',   '/views',               'panel_views_index'],
        ['POST',  '/views',               'panel_views_store'],
        ['PATCH', '/views/{id}',          'panel_views_update'],
        ['DELETE', '/views/{id}',         'panel_views_delete'],
        // Literal routes before `{id}` so /devices/export is not read as an id.
        ['GET',   '/devices',             'panel_devices_index'],
        ['GET',   '/devices/export',      'panel_devices_export'],
        ['POST',  '/devices',             'panel_devices_create'],
        ['POST',  '/devices/bulk',        'panel_devices_bulk'],
        ['POST',  '/devices/{id}/restore', 'panel_devices_restore'],
        ['GET',   '/devices/{id}/files',  'panel_devices_files_index'],
        ['GET',   '/devices/{id}/files/{fileId}/download', 'panel_devices_files_download'],
        ['GET',   '/devices/{id}/events', 'panel_devices_events'],
        ['POST',  '/devices/{id}/files/presign', 'panel_devices_files_presign'],
        ['POST',  '/devices/{id}/files',  'panel_devices_files_store'],
        ['DELETE', '/devices/{id}/files/{fileId}', 'panel_devices_files_delete'],
        ['GET',   '/devices/{id}',        'panel_devices_show'],
        ['PATCH', '/devices/{id}',        'panel_devices_update'],
        ['PUT',   '/devices/{id}',        'panel_devices_replace'],
        ['DELETE', '/devices/{id}',       'panel_devices_delete'],
        // Settings: literal paths before the `{slug}` / `{id}` ones so
        // /settings/fields/reorder is not read as a field id.
        ['GET',   '/settings',            'panel_settings_show'],
        ['PATCH', '/settings',            'panel_settings_update'],
        ['GET',   '/settings/sources',    'panel_settings_sources_index'],
        ['POST',  '/settings/sources',    'panel_settings_sources_store'],
        ['POST',  '/settings/sources/move', 'panel_settings_sources_move'],
        ['PATCH', '/settings/sources/{slug}', 'panel_settings_sources_update'],
        ['DELETE', '/settings/sources/{slug}', 'panel_settings_sources_delete'],
        ['GET',   '/settings/fields',     'panel_settings_fields_index'],
        ['POST',  '/settings/fields',     'panel_settings_fields_store'],
        ['POST',  '/settings/fields/reorder', 'panel_settings_fields_reorder'],
        ['PATCH', '/settings/fields/{id}', 'panel_settings_fields_update'],
        ['DELETE', '/settings/fields/{id}', 'panel_settings_fields_delete'],
        ['GET',   '/settings/api-keys',   'panel_settings_keys_index'],
        ['POST',  '/settings/api-keys',   'panel_settings_keys_store'],
        ['POST',  '/settings/api-keys/{id}/rotate', 'panel_settings_keys_rotate'],
        ['PATCH', '/settings/api-keys/{id}', 'panel_settings_keys_update'],
        ['DELETE', '/settings/api-keys/{id}', 'panel_settings_keys_delete'],
        ['GET',   '/settings/permissions', 'panel_settings_permissions_index'],
        ['PUT',   '/settings/permissions', 'panel_settings_permissions_update'],
        ['GET',   '/settings/users',      'panel_settings_users_index'],
        ['PATCH', '/settings/users/{email}', 'panel_settings_users_update'],
        ['GET',   '/settings/maintenance', 'panel_settings_maintenance_show'],
        ['POST',  '/settings/maintenance/migrate', 'panel_settings_maintenance_migrate'],
        ['GET',   '/settings/columns',    'panel_settings_columns_index'],
        ['PUT',   '/settings/columns',    'panel_settings_columns_update'],
        ['DELETE', '/settings/columns',   'panel_settings_columns_delete'],
        // Audit log. `/logs/purge` is a literal, so it is listed before nothing
        // that could swallow it (there is no `{id}` on this resource).
        ['GET',   '/logs',                'panel_logs_index'],
        ['POST',  '/logs/purge',          'panel_logs_purge'],
        // Directory for the device form's "user email" dropdown.
        ['GET',   '/users',               'panel_users_index'],
    ];
}

/** Path relative to the mount point, without the `/vN` version segment. */
function panel_request_path(): string
{
    $uriPath = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
    if ($uriPath === '') {
        $uriPath = (string) ($_SERVER['PATH_INFO'] ?? '/');
    }

    $marker = '/panel-api';
    $position = strpos($uriPath, $marker);
    if ($position !== false) {
        $uriPath = substr($uriPath, $position + strlen($marker));
    }
    $uriPath = (string) preg_replace('#^/v\d+(?=/|$)#', '', $uriPath);
    $uriPath = '/' . ltrim($uriPath, '/');
    $uriPath = rtrim($uriPath, '/');

    return $uriPath === '' ? '/' : $uriPath;
}

/**
 * Match a route pattern against a path.
 *
 * @return array<string, string>|null captured placeholders
 */
function panel_match(string $pattern, string $path): ?array
{
    // `{name}` -> one path segment. The name may mix case (`{fileId}`), which the
    // first version of this helper did not allow: those routes matched nothing
    // at all and the SPA saw a 404 for a file it could list.
    $regex = '#^' . preg_replace('#\\\{([A-Za-z_][A-Za-z0-9_]*)\\\}#', '(?P<$1>[^/]+)', preg_quote($pattern, '#')) . '$#';
    if (preg_match($regex, $path, $matches) !== 1) {
        return null;
    }
    $params = [];
    foreach ($matches as $name => $value) {
        if (is_string($name)) {
            $params[$name] = $value;
        }
    }
    return $params;
}

/**
 * Run the current request and emit the JSON envelope. Never returns.
 */
function panel_dispatch(): never
{
    $method = request_method();
    $path = panel_request_path();
    $allowed = [];

    foreach (panel_routes() as $route) {
        [$routeMethod, $pattern, $handler] = $route;
        $params = panel_match($pattern, $path);
        if ($params === null) {
            continue;
        }
        $allowed[] = $routeMethod;
        if ($routeMethod !== $method) {
            continue;
        }
        panel_invoke($handler, $params);
    }

    if ($allowed !== []) {
        header('Allow: ' . implode(', ', array_unique($allowed)));
        json_fail('method_not_allowed', $method . ' is not allowed for this resource.', 405);
    }

    json_fail('not_found', 'No panel API route matches ' . $path . '.', 404);
}

/**
 * Map a PDO failure onto the documented error envelope.
 *
 * Only a real connection failure is an outage. Everything else (a value the
 * server rejected, a constraint, a deadlock) is a database error and must not be
 * reported as "the database is unreachable": the panel used to answer that for
 * every `PDOException`, which pointed operators at the network while the actual
 * cause was, for example, SQLSTATE 22007 on `assets.Mobile`.
 *
 * @return array{code: string, message: string, status: int}
 */
function panel_pdo_failure(PDOException $e): array
{
    $sqlState = (string) ($e->errorInfo[0] ?? '');
    if ($sqlState === '') {
        $sqlState = (string) $e->getCode();
    }
    $driverCode = (int) ($e->errorInfo[1] ?? 0);
    // 1040/1044/1045/1129/1130: server refused the account or the host;
    // 2002/2003/2005/2006/2013: no connection, DNS or "server has gone away".
    $connectionCodes = [1040, 1044, 1045, 1129, 1130, 2002, 2003, 2005, 2006, 2013];
    if (str_starts_with($sqlState, '08') || in_array($driverCode, $connectionCodes, true)) {
        return [
            'code' => 'database_unavailable',
            'message' => 'The database is unreachable right now.',
            'status' => 503,
        ];
    }

    return [
        'code' => 'database_error',
        'message' => 'The change was rejected by the database'
            . ($sqlState !== '' ? ' (SQLSTATE ' . $sqlState . ')' : '')
            . '. Check the application log for details.',
        'status' => 500,
    ];
}

/**
 * Call one handler and render its result (or its exception) as JSON.
 *
 * @param array<string, string> $params
 */
function panel_invoke(string $handler, array $params = []): never
{
    try {
        // Write protection before the handler runs, so a rejected call costs one
        // upsert and nothing else (`rate_limited` 429 + `Retry-After`).
        rate_limit_require_panel(request_method());
        $result = $handler($params);
        $data = is_array($result) && array_key_exists('data', $result) ? $result['data'] : $result;
        $meta = is_array($result) ? (array) ($result['meta'] ?? []) : [];
        $status = is_array($result) ? (int) ($result['status'] ?? 200) : 200;
        json_ok($data, $status, $meta);
    } catch (ApiError $e) {
        json_fail($e->errorCode, $e->getMessage(), $e->status, $e->errors);
    } catch (PDOException $e) {
        error_log('panel-api database error: ' . db_error_message($e));
        $failure = panel_pdo_failure($e);
        json_fail($failure['code'], $failure['message'], $failure['status']);
    } catch (Throwable $e) {
        error_log('panel-api error: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
        $message = app_env() === 'production' ? 'Unexpected server error.' : $e->getMessage();
        json_fail('server_error', $message, 500);
    }
}

