<?php

// --- Autoloader (AWS SDK) ---
// Tolerated when missing so CLI tooling (bin/migrate.php, i18n checker) and
// deployments without S3 still boot.
$bcmdb_autoload = __DIR__ . '/vendor/autoload.php';
if (is_file($bcmdb_autoload)) {
    require_once $bcmdb_autoload;
}

// --- MariaDB Settings ---
// These stay environment-based on purpose: they are needed before any settings
// row can be read (the settings themselves live in this database).
define('DB_HOST', getenv('DB_SERVER') ?: '127.0.0.1');
define('DB_PORT', getenv('DB_PORT') ?: '');
define('DB_NAME', getenv('DB_NAME') ?: 'YOURDBNAME');
define('DB_USER', getenv('DB_USERNAME') ?: 'YOURDBUSERNAME');
define('DB_PASS', getenv('DB_PASSWORD') ?: 'YOURDBPASSWORD');

// --- Application ---
// Version: the environment wins (the Dockerfile stamps `ARG VERSION` as
// APP_VERSION, and docker-compose passes it through), then `manifest.json`,
// which semantic-release keeps at the released version and which ships with
// both the image and the release zip. A literal here would be a version that
// nobody bumps, so it is only the last resort for a tree without a manifest.
$bcmdb_manifest_version = '';
$bcmdb_manifest_path = __DIR__ . '/manifest.json';
if (is_file($bcmdb_manifest_path)) {
    $bcmdb_manifest_data = json_decode((string) file_get_contents($bcmdb_manifest_path), true);
    if (is_array($bcmdb_manifest_data) && is_string($bcmdb_manifest_data['version'] ?? null)) {
        $bcmdb_manifest_version = trim($bcmdb_manifest_data['version']);
    }
}
define('APP_VERSION', getenv('APP_VERSION') ?: ($bcmdb_manifest_version !== '' ? $bcmdb_manifest_version : '0.0.0'));

// Deployment environment: `production` disables the development login.
define('APP_ENV', getenv('APP_ENV') ?: 'production');
// Development-only session login (/auth/dev-login). Ignored in production.
define('APP_DEV_LOGIN', getenv('APP_DEV_LOGIN') ?: '0');
// 32-byte key (base64, hex or raw) used to encrypt secrets stored by the
// Settings page. Generate with: openssl rand -base64 32
define('APP_ENCRYPTION_KEY', getenv('APP_ENCRYPTION_KEY') ?: '');
// Environment fallbacks for the default locale / timezone; the Settings page
// overrides them (see lib/settings.php for the precedence rules).
define('APP_DEFAULT_LOCALE', getenv('APP_DEFAULT_LOCALE') ?: '');
define('APP_TIMEZONE', getenv('APP_TIMEZONE') ?: '');

// --- S3 Settings (environment fallbacks; editable in Settings → Storage) ---
define('S3_REGION', getenv('S3_REGION') ?: 'YOURS3REGION');
define('S3_BUCKET', getenv('S3_BUCKET') ?: 'YOURS3BUCKET');
define('S3_ACCESS_KEY', getenv('S3_ACCESS_KEY') ?: 'YOURS3ACCESSKEY');
define('S3_SECRET_KEY', getenv('S3_SECRET_KEY') ?: 'YOURS3SECRETKEY');
define('S3_ENDPOINT', getenv('S3_ENDPOINT') ?: 'https://s3.YOURS3REGION.amazonaws.com'); // Change if using MinIO or other provider

// --- Keycloak Settings ---
define('KEYCLOAK_BASE_URL', getenv('KEYCLOAK_BASE_URL') ?: 'https://sso.example.com'); // Replace with your Keycloak URL
define('KEYCLOAK_REALM', getenv('KEYCLOAK_REALM') ?: 'YOURSSORealm'); // Replace with your Realm
define('KEYCLOAK_CLIENT_ID', getenv('KEYCLOAK_CLIENT_ID') ?: 'bundledcmdb'); // Replace with your Client ID
define('KEYCLOAK_CLIENT_SECRET', getenv('KEYCLOAK_CLIENT_SECRET') ?: 'YOURKEYCLOAKCLIENTSECRET'); // Replace with your Client Secret
define('KEYCLOAK_REDIRECT_URI', getenv('KEYCLOAK_REDIRECT_URI') ?: 'https://bundledcmdb.example.com/index.php'); // Adjust based on deployment
// Callback used by the SPA login (/auth/callback). Empty = derive from the
// request (https://<host>/auth/callback); set it when the panel sits behind a
// proxy that rewrites the host, and register it in the Keycloak client.
define('KEYCLOAK_PANEL_REDIRECT_URI', getenv('KEYCLOAK_PANEL_REDIRECT_URI') ?: '');
