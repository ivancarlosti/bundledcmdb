<?php
declare(strict_types=1);

/**
 * lib/bootstrap.php — single include for every entry point (web pages, API,
 * CLI scripts).
 *
 * Order matters: config → db → crypto → settings, then the models that build on
 * them. Nothing here starts a session or emits output.
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/crypto.php';
require_once __DIR__ . '/settings.php';
require_once __DIR__ . '/http.php';
require_once __DIR__ . '/locales.php';
require_once __DIR__ . '/session_auth.php';
require_once __DIR__ . '/user_preferences.php';
require_once __DIR__ . '/migrate.php';
require_once __DIR__ . '/sources.php';
require_once __DIR__ . '/rate_limit.php';
require_once __DIR__ . '/storage.php';
require_once __DIR__ . '/saved_views.php';
require_once __DIR__ . '/asset_fields.php';
require_once __DIR__ . '/asset_events.php';
require_once __DIR__ . '/logs.php';
require_once __DIR__ . '/disks.php';
require_once __DIR__ . '/assets.php';
require_once __DIR__ . '/grid_layout.php';
require_once __DIR__ . '/permissions.php';
require_once __DIR__ . '/users.php';
require_once __DIR__ . '/api_users.php';
require_once __DIR__ . '/api_auth.php';

/**
 * Configured timezone, validated; falls back to the env value then UTC.
 */
function app_timezone(): string
{
    static $timezone = null;
    if ($timezone !== null) {
        return $timezone;
    }

    $candidate = '';
    try {
        $candidate = (string) settings_get('general.timezone', '');
    } catch (Throwable) {
        $candidate = '';
    }
    if ($candidate === '' && defined('APP_TIMEZONE')) {
        $candidate = trim((string) APP_TIMEZONE);
    }
    if ($candidate === '' || !in_array($candidate, DateTimeZone::listIdentifiers(), true)) {
        $candidate = 'UTC';
    }

    return $timezone = $candidate;
}

/** Apply the configured timezone for the current request/process. */
function app_boot(): void
{
    date_default_timezone_set(app_timezone());
}

/** Default UI locale (Settings → General), canonical BCP-47 code. */
function app_default_locale(): string
{
    try {
        $locale = (string) settings_get('general.default_locale', '');
    } catch (Throwable) {
        $locale = '';
    }
    if ($locale === '' && defined('APP_DEFAULT_LOCALE')) {
        $locale = trim((string) APP_DEFAULT_LOCALE);
    }
    return $locale === '' ? 'pt-BR' : $locale;
}

/** Current UTC timestamp in MySQL DATETIME format. */
function app_now_utc(): string
{
    return gmdate('Y-m-d H:i:s');
}

/**
 * Render a stored (UTC) timestamp in the configured timezone.
 */
function app_date(?string $value, string $format = 'Y-m-d H:i'): string
{
    $value = trim((string) $value);
    if ($value === '' || $value === '0000-00-00 00:00:00') {
        return '';
    }
    try {
        $dt = new DateTimeImmutable($value, new DateTimeZone('UTC'));
        return $dt->setTimezone(new DateTimeZone(app_timezone()))->format($format);
    } catch (Throwable) {
        return $value;
    }
}

/** True when all migrations have been applied (banner / API health). */
function app_schema_ready(): bool
{
    static $ready = null;
    if ($ready !== null) {
        return $ready;
    }
    return $ready = migrate_status()['ready'];
}