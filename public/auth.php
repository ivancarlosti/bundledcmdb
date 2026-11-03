<?php
declare(strict_types=1);

/**
 * public/auth.php — browser authentication for the SPA.
 *
 *   GET /auth/login       → redirect to Keycloak (state + return path in session)
 *   GET /auth/callback    → exchange the code, resolve the user, start the session
 *   GET /auth/logout      → destroy the session, then Keycloak end-session
 *   GET /auth/dev-login   → only when APP_DEV_LOGIN=1 and APP_ENV≠production
 *
 * The legacy `index.php` keeps its own inline flow for now; both end up in the
 * same `$_SESSION['user_email'|'company'|'role']` contract (lib/session_auth.php).
 */

require_once __DIR__ . '/../lib/bootstrap.php';
require_once __DIR__ . '/../auth_keycloak.php';

app_boot();
session_boot();

// This endpoint answers browser navigations (302 to Keycloak / back to /app/).
// A stray notice or deprecation printed before the Location header would break
// the OIDC round-trip, so diagnostics go to the log, never into the response.
ini_set('display_errors', '0');

header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: strict-origin-when-cross-origin');

/** Action requested: PATH_INFO (`/auth/callback`), REQUEST_URI or `?action=`. */
function auth_action(): string
{
    $path = trim((string) ($_SERVER['PATH_INFO'] ?? ''), '/');
    if ($path === '') {
        $uriPath = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);
        if (preg_match('#/auth(/.*)?$#', $uriPath, $matches) === 1) {
            $path = trim((string) ($matches[1] ?? ''), '/');
        }
    }
    if ($path === '') {
        $path = trim((string) ($_GET['action'] ?? ''), '/');
    }
    if (str_starts_with($path, 'auth/')) {
        $path = substr($path, 5);
    }
    return $path === '' ? 'login' : $path;
}

/** Callback URI registered in Keycloak (env override wins). */
function auth_redirect_uri(): string
{
    $configured = defined('KEYCLOAK_PANEL_REDIRECT_URI') ? trim((string) KEYCLOAK_PANEL_REDIRECT_URI) : '';
    return $configured !== '' ? $configured : app_base_url() . '/auth/callback';
}

/** False while the placeholder host from config.php is still in use. */
function auth_keycloak_configured(): bool
{
    return auth_keycloak_usable();
}

/**
 * Human-readable failure page. The SPA shows its own errors; this is the last
 * step of a browser redirect and must never be a blank page.
 */
function auth_fail(string $code, string $message, int $status = 400): never
{
    http_response_code($status);
    header('Content-Type: text/html; charset=utf-8');
    $safeMessage = htmlspecialchars($message, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $safeCode = htmlspecialchars($code, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    echo '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<title>Sign-in error</title></head>'
        . '<body style="font-family:system-ui,sans-serif;margin:3rem auto;max-width:34rem">'
        . '<h1 style="font-size:1.25rem">Sign-in error</h1><p>' . $safeMessage . '</p>'
        . '<p style="color:#666"><code>' . $safeCode . '</code></p>'
        . '<p><a href="/app/">Back to the panel</a></p></body></html>';
    exit;
}

// --- Handlers -----------------------------------------------------------

function auth_handle_login(): never
{
    if (!auth_keycloak_configured()) {
        auth_fail(
            'keycloak_not_configured',
            'Keycloak is not configured for this deployment (KEYCLOAK_BASE_URL / KEYCLOAK_CLIENT_SECRET).',
            503
        );
    }

    $state = bin2hex(random_bytes(16));
    $_SESSION[SESSION_AUTH_STATE] = $state;
    $_SESSION[SESSION_AUTH_RETURN] = session_safe_return_path($_GET['redirect'] ?? null);

    $keycloak = new KeycloakAuth();
    redirect($keycloak->getLoginUrl(auth_redirect_uri(), ['state' => $state]));
}

function auth_handle_callback(): never
{
    $expected = (string) ($_SESSION[SESSION_AUTH_STATE] ?? '');
    $received = (string) ($_GET['state'] ?? '');
    $returnTo = session_safe_return_path($_SESSION[SESSION_AUTH_RETURN] ?? null);
    unset($_SESSION[SESSION_AUTH_STATE], $_SESSION[SESSION_AUTH_RETURN]);

    if ($expected === '' || $received === '' || !hash_equals($expected, $received)) {
        auth_fail('invalid_state', 'The sign-in request expired or was tampered with. Please try again.', 400);
    }
    if (isset($_GET['error'])) {
        $reason = (string) ($_GET['error_description'] ?? $_GET['error']);
        auth_fail('keycloak_error', 'Keycloak refused the sign-in: ' . $reason, 401);
    }

    $code = trim((string) ($_GET['code'] ?? ''));
    if ($code === '') {
        auth_fail('missing_code', 'The callback did not include an authorization code.', 400);
    }

    $keycloak = new KeycloakAuth();
    $token = $keycloak->getToken($code, auth_redirect_uri());
    if (!is_array($token) || !isset($token['access_token'])) {
        auth_fail('token_exchange_failed', 'Could not exchange the authorization code for a token.', 502);
    }

    $userInfo = $keycloak->getUserInfo((string) $token['access_token']);
    $email = strtolower(trim((string) ($userInfo['email'] ?? '')));
    if ($email === '') {
        auth_fail('missing_email', 'Keycloak did not return an e-mail address for this account.', 502);
    }

    $pdo = db_try();
    if ($pdo === null) {
        auth_fail('database_unavailable', 'The database is unreachable right now.', 503);
    }

    $user = $keycloak->verifyUser($email, $pdo);
    if (!is_array($user)) {
        auth_fail(
            'user_not_authorized',
            'This account is not authorized for Bundled CMDB. Ask an administrator to add ' . $email . '.',
            403
        );
    }

    session_login($email, (string) ($user['company'] ?? ''), (string) ($user['role'] ?? 'user'));
    redirect($returnTo);
}

function auth_handle_logout(): never
{
    $returnTo = session_safe_return_path($_GET['redirect'] ?? null);
    session_logout();

    // Without a reachable Keycloak (placeholder host or dev login) staying local
    // avoids bouncing the user to an end-session URL that cannot resolve.
    $localOnly = isset($_GET['local']) || app_dev_login_enabled() || !auth_keycloak_usable();
    redirect($localOnly ? $returnTo : auth_end_session_url());
}

function auth_handle_dev_login(): never
{
    if (!app_dev_login_enabled()) {
        auth_fail('dev_login_disabled', 'The development login is disabled (APP_DEV_LOGIN / APP_ENV).', 404);
    }

    $email = strtolower(trim((string) ($_GET['email'] ?? '')));
    if ($email === '') {
        auth_fail('missing_email', 'Pass ?email=<user@example.com> — the address must exist in the users table.', 400);
    }

    $pdo = db_try();
    if ($pdo === null) {
        auth_fail('database_unavailable', 'The database is unreachable right now.', 503);
    }

    $stmt = $pdo->prepare('SELECT email, company, role FROM users WHERE email = ?');
    $stmt->execute([$email]);
    $user = $stmt->fetch();
    if ($user === false) {
        auth_fail('user_not_authorized', 'No user row for ' . $email . '.', 404);
    }

    session_login($email, (string) ($user['company'] ?? ''), (string) ($user['role'] ?? 'user'));
    redirect(session_safe_return_path($_GET['redirect'] ?? null));
}

// --- Dispatch -----------------------------------------------------------

switch (auth_action()) {
    case 'login':
        auth_handle_login();
    case 'callback':
        auth_handle_callback();
    case 'logout':
        auth_handle_logout();
    case 'dev-login':
        auth_handle_dev_login();
    default:
        auth_fail('not_found', 'Unknown authentication action.', 404);
}

