<?php
declare(strict_types=1);

/**
 * lib/session_auth.php — authenticated browser sessions for the panel.
 *
 * The legacy pages opened `session_start()` themselves and stored the Keycloak
 * identity in three loose keys. This file centralises that contract so the SPA,
 * the panel API (`public/panel_api.php`) and the legacy pages share one session:
 *
 *   $_SESSION['user_email'] / ['company'] / ['role']   ← unchanged keys
 *
 * Everything else (cookie flags, strict mode, CSRF header, role guards and the
 * login/logout cycle) lives here. Nothing in this file emits output, so it is
 * safe to include from CLI scripts.
 */

/** Session keys (the first three are the legacy contract). */
const SESSION_EMAIL = 'user_email';
const SESSION_COMPANY = 'company';
const SESSION_ROLE = 'role';

/** Pending OIDC state and the post-login destination. */
const SESSION_AUTH_STATE = 'auth_state';
const SESSION_AUTH_NONCE = 'auth_nonce';
const SESSION_AUTH_RETURN = 'auth_return_to';

/** Known roles, weakest first. Used by the role guards. */
const SESSION_ROLES = ['user' => 1, 'manager' => 2, 'admin' => 3, 'superadmin' => 4];

/** True when the current request arrived over TLS (directly or via a proxy). */
function session_is_https(): bool
{
    $https = $_SERVER['HTTPS'] ?? '';
    if ($https !== '' && strtolower((string) $https) !== 'off') {
        return true;
    }
    foreach (['HTTP_X_FORWARDED_PROTO', 'HTTP_X_FORWARDED_SCHEME'] as $header) {
        $value = strtolower(trim((string) ($_SERVER[$header] ?? '')));
        if ($value === 'https') {
            return true;
        }
    }
    return (string) ($_SERVER['SERVER_PORT'] ?? '') === '443';
}

/** True when a session is already open (legacy pages start it themselves). */
function session_active(): bool
{
    return session_status() === PHP_SESSION_ACTIVE;
}

/**
 * Open the session with the panel cookie policy. Idempotent: legacy pages that
 * already called `session_start()` keep their session untouched.
 */
function session_boot(): void
{
    if (session_active()) {
        return;
    }

    $secure = session_is_https();

    // Keeping the default session name means the session is shared with the
    // legacy pages during the transition (they call plain session_start()).
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.cookie_httponly', '1');
    ini_set('session.cookie_samesite', 'Lax');
    ini_set('session.cookie_secure', $secure ? '1' : '0');

    if (!headers_sent()) {
        session_set_cookie_params([
            'lifetime' => 0,
            'path'     => '/',
            'domain'   => '',
            'secure'   => $secure,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }
    session_start();
}

/** Canonical role, defaulting to the least privileged one. */
function session_role_normalize(?string $role): string
{
    $role = strtolower(trim((string) $role));
    return isset(SESSION_ROLES[$role]) ? $role : 'user';
}

/** Rank of a role (1..4). */
function session_role_rank(?string $role): int
{
    return SESSION_ROLES[session_role_normalize($role)] ?? 1;
}

/** Role of the signed-in user (or `user` when anonymous). */
function session_role(): string
{
    return session_role_normalize($_SESSION[SESSION_ROLE] ?? null);
}

/** Company of the signed-in user ('' when anonymous). */
function session_company(): string
{
    return (string) ($_SESSION[SESSION_COMPANY] ?? '');
}

/** E-mail of the signed-in user, or null. */
function session_user_email(): ?string
{
    $email = trim((string) ($_SESSION[SESSION_EMAIL] ?? ''));
    return $email === '' ? null : $email;
}

/**
 * Signed-in identity.
 *
 * @return array{email: string, company: string, role: string}|null
 */
function session_user(): ?array
{
    $email = session_user_email();
    if ($email === null) {
        return null;
    }
    return [
        'email'   => $email,
        'company' => session_company(),
        'role'    => session_role(),
    ];
}

/**
 * Require a signed-in user; throws `ApiError('unauthorized', …, 401)`.
 *
 * @return array{email: string, company: string, role: string}
 */
function session_require(): array
{
    $user = session_user();
    if ($user === null) {
        throw new ApiError('unauthorized', 'Authentication required.', 401);
    }
    return $user;
}

/**
 * Require one of the given roles; throws `ApiError('forbidden', …, 403)`.
 *
 * @param list<string> $roles
 * @return array{email: string, company: string, role: string}
 */
function session_require_role(array $roles): array
{
    $user = session_require();
    $allowed = array_map('session_role_normalize', $roles);
    if (!in_array($user['role'], $allowed, true)) {
        throw new ApiError('forbidden', 'Your role cannot perform this action.', 403);
    }
    return $user;
}

/**
 * Create the authenticated session: new session id (fixation), identity keys
 * and a fresh CSRF token. Called by `public/auth.php` only.
 */
function session_login(string $email, string $company, string $role): void
{
    session_boot();
    session_regenerate_id(true);

    $_SESSION[SESSION_EMAIL] = trim($email);
    $_SESSION[SESSION_COMPANY] = trim($company);
    $_SESSION[SESSION_ROLE] = session_role_normalize($role);

    unset($_SESSION[SESSION_AUTH_STATE], $_SESSION[SESSION_AUTH_NONCE], $_SESSION[SESSION_AUTH_RETURN]);

    // Rotate the CSRF token so a pre-login token can never be replayed.
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

/** Destroy the session and expire its cookie. */
function session_logout(): void
{
    if (session_status() === PHP_SESSION_NONE) {
        session_boot();
    }
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', [
            'expires'  => time() - 42000,
            'path'     => $params['path'] ?: '/',
            'domain'   => $params['domain'] ?: '',
            'secure'   => (bool) ($params['secure'] ?? false),
            'httponly' => (bool) ($params['httponly'] ?? true),
            'samesite' => $params['samesite'] ?? 'Lax',
        ]);
    }
    session_destroy();
}

/**
 * Require a valid CSRF token from the `X-CSRF-Token` header (or `_csrf` field).
 * Throws `ApiError('invalid_csrf', …, 419)`.
 */
function session_csrf_require(): void
{
    session_boot();
    $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($_POST['_csrf'] ?? null);
    if (csrf_verify(is_string($token) ? $token : null)) {
        return;
    }
    throw new ApiError('invalid_csrf', 'Invalid or missing CSRF token.', 419);
}

/** Whitelist a post-login redirect to a local path (no open redirects). */
function session_safe_return_path(?string $target, string $fallback = '/app/'): string
{
    $target = trim((string) $target);
    if ($target === '' || $target[0] !== '/' || str_starts_with($target, '//') || str_contains($target, '\\')) {
        return $fallback;
    }
    if (preg_match('#^/(auth|panel-api)(/|$)#', $target) === 1) {
        return $fallback;
    }
    return $target;
}

/** Deployment environment: `production` unless APP_ENV says otherwise. */
function app_env(): string
{
    $env = getenv('APP_ENV');
    if (!is_string($env) || trim($env) === '') {
        $env = defined('APP_ENV') ? (string) APP_ENV : 'production';
    }
    $env = strtolower(trim($env));
    return $env === '' ? 'production' : $env;
}

/**
 * Development login (`/auth/dev-login`) exists only when explicitly enabled
 * AND the deployment is not production.
 */
function app_dev_login_enabled(): bool
{
    if (app_env() === 'production') {
        return false;
    }
    $flag = getenv('APP_DEV_LOGIN');
    if (!is_string($flag) || trim($flag) === '') {
        $flag = defined('APP_DEV_LOGIN') ? (string) APP_DEV_LOGIN : '0';
    }
    return filter_var(trim($flag), FILTER_VALIDATE_BOOLEAN);
}

/** Origin of the current request (`https://host`), used to build redirect URIs. */
function app_base_url(): string
{
    $host = trim((string) ($_SERVER['HTTP_HOST'] ?? ''));
    if ($host === '' || preg_match('/^[A-Za-z0-9.\-]+(:\d{1,5})?$/', $host) !== 1) {
        $host = 'localhost';
    }
    return (session_is_https() ? 'https' : 'http') . '://' . $host;
}

/**
 * True when the Keycloak configuration is real: the shipped placeholder host
 * (`sso.example.com`) and `YOUR…` secrets count as "not configured", which is
 * what local and S3-less deployments look like.
 */
function auth_keycloak_usable(): bool
{
    $base = trim((string) (defined('KEYCLOAK_BASE_URL') ? KEYCLOAK_BASE_URL : ''));
    $secret = (string) (defined('KEYCLOAK_CLIENT_SECRET') ? KEYCLOAK_CLIENT_SECRET : '');
    if ($base === '' || str_starts_with($secret, 'YOUR')) {
        return false;
    }
    $host = strtolower((string) parse_url($base, PHP_URL_HOST));
    return $host !== '' && !str_contains($host, 'example.com');
}

/** Realm end-session URL (RP-initiated logout). */
function auth_end_session_url(?string $postLogoutRedirect = null): string
{
    $base = rtrim((string) (defined('KEYCLOAK_BASE_URL') ? KEYCLOAK_BASE_URL : ''), '/');
    $realm = (string) (defined('KEYCLOAK_REALM') ? KEYCLOAK_REALM : '');
    $clientId = (string) (defined('KEYCLOAK_CLIENT_ID') ? KEYCLOAK_CLIENT_ID : '');
    $params = ['client_id' => $clientId];
    if ($postLogoutRedirect !== null && $postLogoutRedirect !== '') {
        $params['post_logout_redirect_uri'] = $postLogoutRedirect;
    }
    return $base . '/realms/' . $realm . '/protocol/openid-connect/logout?' . http_build_query($params);
}


