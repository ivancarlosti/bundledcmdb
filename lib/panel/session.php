<?php
declare(strict_types=1);

/**
 * lib/panel/session.php — `/panel-api/v1/session*` handlers.
 *
 * The SPA bootstraps from `GET /session`: identity, CSRF token, resolved locale,
 * theme, page size, persisted grid layout and the capability flags it uses to
 * hide actions. Everything the browser needs to render is here so the shell does
 * not need a second round-trip.
 */

/** Locale list with native names and direction (for the language selector). */
function panel_locales_payload(): array
{
    $out = [];
    foreach (locales_supported() as $code) {
        $meta = locales_meta()[$code] ?? [];
        $out[] = [
            'code'   => $code,
            'native' => $meta['native'] ?? $code,
            'label'  => $meta['label'] ?? strtoupper(substr($code, 0, 2)),
            'dir'    => $meta['dir'] ?? 'ltr',
        ];
    }
    return $out;
}

/** True when the active storage driver (s3 or local) can accept uploads. */
function panel_storage_configured(): bool
{
    return storage_configured();
}

/**
 * Capability flags plus the full permission map.
 *
 * The three boolean flags are kept for existing SPA builds; new checks should
 * use the explicit keys in `permissions`. They are derived from the matrix so a
 * revoked capability hides the matching action.
 */
function panel_capabilities(string $role): array
{
    $rank = session_role_rank($role);
    $permissions = permission_capabilities_for_role($role);

    return [
        'role'           => session_role_normalize($role),
        'rank'           => $rank,
        'can_write'      => $permissions['devices.create'] || $permissions['devices.update'],
        'can_admin'      => $permissions['settings.manage'] || $permissions['devices.delete'],
        'can_superadmin' => session_role_normalize($role) === 'superadmin',
        'permissions'    => $permissions,
    ];
}

/**
 * Build the session payload shared by `GET /session` and `PATCH /preferences`.
 */
function panel_session_payload(array $user): array
{
    $prefs = user_preferences_get($user['email']);
    $locale = $prefs['locale'] ?? locale_default();

    $features = [];
    try {
        $features = [
            'api'         => settings_bool('api.enabled'),
            'attachments' => panel_storage_configured(),
            'devLogin'    => app_dev_login_enabled(),
        ];
    } catch (Throwable) {
        $features = ['api' => false, 'attachments' => false, 'devLogin' => app_dev_login_enabled()];
    }

    $maxPageSize = 200;
    try {
        $maxPageSize = (int) settings_get('api.max_page_size', 200);
    } catch (Throwable) {
        // keep the default
    }

    return [
        'user'        => $user,
        'csrfToken'   => csrf_token(),
        'locale'      => $locale,
        'dir'         => locale_dir($locale),
        'theme'       => $prefs['theme'],
        'perPage'     => $prefs['per_page'],
        'gridState'   => $prefs['grid_state'],
        'locales'     => panel_locales_payload(),
        'themes'      => user_preferences_themes(),
        'pageSizes'   => user_preferences_page_sizes(),
        'maxPageSize' => $maxPageSize > 0 ? $maxPageSize : 200,
        'capabilities' => panel_capabilities($user['role']),
        'features'    => $features,
        'serverTime'  => app_now_utc(),
    ];
}

/** GET /session */
function panel_session_show(array $params = []): array
{
    $payload = ['data' => panel_session_payload(session_require())];

    // The panel has no scheduler, so audit retention rides along with the
    // session bootstrap: `logs_purge_due()` is a no-op unless the last purge is
    // older than LOGS_PURGE_INTERVAL (lib/logs.php).
    $pdo = db_try();
    if ($pdo !== null) {
        logs_purge_due($pdo);
    }

    return $payload;
}

/**
 * PATCH /session/preferences
 *
 * Body: any subset of `locale`, `theme`, `per_page`, `grid_state`.
 * Unknown keys are ignored on purpose (older SPA builds keep working).
 */
function panel_session_update_preferences(array $params = []): array
{
    $user = session_require();
    session_csrf_require();

    $body = request_json_body();
    $current = user_preferences_get($user['email']);
    $next = user_preferences_apply($body, $current);
    user_preferences_save($next, $user['email']);

    return ['data' => panel_session_payload($user)];
}

/**
 * GET /views — the signed-in user's saved grid presets.
 *
 * Per user, never shared: a view is "my filter", and the panel has no notion of
 * a team library. Company scoping still applies to the rows the view selects,
 * because the grid's own filters do that.
 */
function panel_views_index(array $params = []): array
{
    $user = session_require();
    $views = saved_views_for(db(), $user['email']);
    return ['data' => ['rows' => $views, 'total' => count($views)]];
}

/** POST /views — create, or overwrite the view with the same name. */
function panel_views_store(array $params = []): array
{
    $user = session_require();
    session_csrf_require();
    $body = request_json_body();

    $name = saved_view_name($body['name'] ?? null);
    if ($name === null) {
        throw new ApiError('validation_failed', 'A name is required.', 422, [
            'name' => 'required',
        ]);
    }

    $pdo = db();
    $id = saved_view_save($pdo, $user['email'], $name, saved_view_state((array) ($body['state'] ?? [])));
    if ($id === 0) {
        throw new ApiError('server_error', 'The view could not be stored.', 500);
    }

    return ['data' => saved_view_find($pdo, $user['email'], $id), 'status' => 201];
}

/** PATCH /views/{id} — rename and/or replace the state. */
function panel_views_update(array $params = []): array
{
    $user = session_require();
    session_csrf_require();
    $pdo = db();

    $id = (int) ($params['id'] ?? 0);
    $existing = saved_view_find($pdo, $user['email'], $id);
    if ($existing === null) {
        throw new ApiError('not_found', 'View not found.', 404);
    }

    $body = request_json_body();
    $name = array_key_exists('name', $body) ? saved_view_name($body['name']) : $existing['name'];
    if ($name === null) {
        throw new ApiError('validation_failed', 'A name is required.', 422, ['name' => 'required']);
    }
    $state = array_key_exists('state', $body)
        ? saved_view_state((array) $body['state'])
        : (array) $existing['state'];

    saved_view_save($pdo, $user['email'], $name, $state, $id);
    return ['data' => saved_view_find($pdo, $user['email'], $id)];
}

/** DELETE /views/{id} */
function panel_views_delete(array $params = []): array
{
    $user = session_require();
    session_csrf_require();

    $id = (int) ($params['id'] ?? 0);
    if (!saved_view_delete(db(), $user['email'], $id)) {
        throw new ApiError('not_found', 'View not found.', 404);
    }

    return ['data' => ['id' => $id, 'deleted' => true]];
}

/**
 * POST /session/logout
 *
 * Returns the URL the browser should navigate to. `local` skips Keycloak
 * end-session (used when Keycloak is not reachable, e.g. dev login).
 */
function panel_session_logout(array $params = []): array
{
    session_require();
    session_csrf_require();

    $body = request_json_body();
    $local = filter_var($body['local'] ?? false, FILTER_VALIDATE_BOOLEAN);

    session_logout();

    // Without a reachable Keycloak the browser must stay on the SPA login screen
    // instead of bouncing to an end-session URL that cannot resolve.
    $logoutUrl = (!$local && auth_keycloak_usable()) ? auth_end_session_url() : '/app/';

    return ['data' => ['logoutUrl' => $logoutUrl]];
}
