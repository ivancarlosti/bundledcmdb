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

/** True when object storage can accept uploads (bucket + secret configured). */
function panel_storage_configured(): bool
{
    try {
        return (string) settings_get('s3.bucket', '') !== '' && settings_secret_configured('s3.secret_key');
    } catch (Throwable) {
        return false;
    }
}

/** Capability flags derived from the role (mirrors lib/assets.php role lists). */
function panel_capabilities(string $role): array
{
    $rank = session_role_rank($role);
    return [
        'role'          => session_role_normalize($role),
        'rank'          => $rank,
        'can_write'     => $rank >= session_role_rank('manager'),
        'can_admin'     => $rank >= session_role_rank('admin'),
        'can_superadmin' => $rank >= session_role_rank('superadmin'),
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
            'spa'         => settings_spa_enabled(),
            'api'         => settings_bool('api.enabled'),
            'attachments' => panel_storage_configured(),
            'devLogin'    => app_dev_login_enabled(),
        ];
    } catch (Throwable) {
        $features = ['spa' => false, 'api' => false, 'attachments' => false, 'devLogin' => app_dev_login_enabled()];
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
    return ['data' => panel_session_payload(session_require())];
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
