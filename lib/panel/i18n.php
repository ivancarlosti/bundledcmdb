<?php
declare(strict_types=1);

/**
 * lib/panel/i18n.php — `GET /panel-api/v1/i18n`.
 *
 * The SPA needs the same strings as the PHP pages, but loading
 * `public/lang.php` from a JSON endpoint would drag in a session and the
 * page-level helpers. `lib/locales.php` already reads the catalogs, so this
 * handler only adds the "merged with en-US" step:
 *
 *  - `public/lang/*.php` stays the single source of truth (no second catalogue
 *    to keep in sync, `bin/check-i18n.php` keeps guarding it);
 *  - every key is present in the response, so the SPA never needs a fallback
 *    chain and a missing translation is visible in one place only;
 *  - an explicit but unsupported `?locale=` is a 400, matching the "no silent
 *    fallback" rule used by the sorting endpoints.
 */

/**
 * Catalog for a locale, completed with en-US so all keys are always defined.
 *
 * @return array<string, string>
 */
function panel_i18n_messages(string $locale): array
{
    $messages = [];
    foreach (locale_catalog('en-US') as $key => $value) {
        if (is_string($key) && is_string($value)) {
            $messages[$key] = $value;
        }
    }
    if ($locale === 'en-US') {
        ksort($messages);
        return $messages;
    }
    foreach (locale_catalog($locale) as $key => $value) {
        if (is_string($key) && is_string($value) && $value !== '') {
            $messages[$key] = $value;
        }
    }
    ksort($messages);

    return $messages;
}

/**
 * GET /i18n
 *
 * Without `?locale=` the user's stored preference is used, so the SPA can load
 * its catalogue right after `GET /session` without passing anything.
 */
function panel_i18n_show(array $params = []): array
{
    $user = session_require();

    $requested = trim((string) ($_GET['locale'] ?? ''));
    if ($requested !== '') {
        $locale = locale_normalize($requested);
        if ($locale === null) {
            throw new ApiError('invalid_locale', 'Unsupported locale: ' . $requested . '.', 400, ['locale' => 'invalid_option']);
        }
    } else {
        $prefs = user_preferences_get($user['email']);
        $locale = locale_normalize($prefs['locale'] ?? null) ?? locale_default();
    }

    $messages = panel_i18n_messages($locale);

    return [
        'data' => [
            'locale'   => $locale,
            'dir'      => locale_dir($locale),
            'messages' => $messages === [] ? new stdClass() : $messages,
        ],
        'meta' => [
            'fallback' => 'en-US',
            'keys'     => count($messages),
        ],
    ];
}
