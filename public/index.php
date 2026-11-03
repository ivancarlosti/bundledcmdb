<?php
// index.php — the entry point for `/`.
//
// Since 11.0.0 this file renders nothing: the React panel at `/app/` owns the
// login screen, the language/theme controls and every error state. Two things
// have to stay on `/` anyway:
//
//   * `?action=logout` — old bookmarks and the Keycloak post-logout landing;
//   * `?code=…` — the authorization-code callback for Keycloak clients whose
//     redirect URI was registered as `/index.php` before `/auth/callback`
//     existed. New clients should use `/auth/callback` (public/auth.php).
//
// Everything else is a single redirect to `/app/`.
session_start();
require_once __DIR__ . '/../lib/bootstrap.php';
require_once '../auth_keycloak.php';
require_once 'lang.php';
lang_init();
app_boot();

/** Where the browser belongs once it is (or is not) signed in. */
const PANEL_HOME = '/app/';

$keycloak = new KeycloakAuth();

// Logout: drop the session, then let Keycloak end its own SSO session too.
if (isset($_GET['action']) && $_GET['action'] === 'logout') {
    session_destroy();
    header('Location: ' . $keycloak->getLogoutUrl());
    exit();
}

// Legacy SSO callback. Success sets the session and continues to /app/; a
// failure is reported to the SPA (which owns the error state) instead of
// rendering a PHP page.
if (isset($_GET['code'])) {
    $error = 'token_failed';
    $tokenData = $keycloak->getToken((string) $_GET['code']);

    if ($tokenData && isset($tokenData['access_token'])) {
        $userInfo = $keycloak->getUserInfo($tokenData['access_token']);

        if ($userInfo && isset($userInfo['email'])) {
            $email = (string) $userInfo['email'];
            try {
                $pdo = new PDO(
                    'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
                    DB_USER,
                    DB_PASS,
                    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
                );
            } catch (PDOException $e) {
                error_log('index.php: database unreachable during SSO callback: ' . $e->getMessage());
                header('Location: ' . PANEL_HOME . '?auth_error=server_error');
                exit();
            }

            $user = $keycloak->verifyUser($email, $pdo);
            if ($user) {
                $_SESSION['user_email'] = $email;
                $_SESSION['company'] = $user['company'] ?? '';
                $_SESSION['role'] = $user['role'] ?? 'user';
                header('Location: ' . PANEL_HOME);
                exit();
            }
            $error = 'not_authorized';
        } else {
            $error = 'userinfo_failed';
        }
    }

    header('Location: ' . PANEL_HOME . '?auth_error=' . $error);
    exit();
}

header('Location: ' . PANEL_HOME);
exit();

