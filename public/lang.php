<?php
declare(strict_types=1);

/**
 * public/lang.php — locale resolution, catalogs and UI helpers.
 *
 * Catalogs live in public/lang/<code>.php and return a flat key=>string array.
 * Locales use canonical BCP-47 codes; legacy codes used by the old panel
 * (pt_BR, es_MX, en_US, h-CN) are accepted and mapped.
 *
 * Resolution order: explicit choice (session) → Settings default → browser
 * Accept-Language → en-US. Missing keys fall back to en-US, then to the key.
 */

// Locale list/normalisation live in lib/ so the panel API can use them without
// starting a session or loading a catalogue.
require_once __DIR__ . '/../lib/locales.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/** @return list<string> canonical locale codes, English first */
function lang_supported(): array
{
    return locales_supported();
}
/** Normalise any accepted code to a canonical supported one (or null). */
function lang_normalize_code(?string $code): ?string
{
    return locale_normalize($code);
}

/** Native names + writing direction. */
function lang_meta(): array
{
    return locales_meta();
}
/** Default UI locale (Settings → General), canonicalised. */
function lang_default(): string
{
    return locale_default();
}

/** Currently active locale. */
function lang_code(): string
{
    return lang_normalize_code($_SESSION['lang'] ?? null) ?? lang_default();
}
/** Fill the session locale: ?lang= → session → default → browser. */
function lang_init(): void
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    if (isset($_GET['lang'])) {
        $requested = lang_normalize_code((string) $_GET['lang']);
        if ($requested !== null) {
            $_SESSION['lang'] = $requested;
            $_SESSION['lang_explicit'] = true;

            $query = $_GET;
            unset($query['lang']);
            $path = strtok((string) ($_SERVER['REQUEST_URI'] ?? ''), '?');
            $url = $path !== false ? $path : '/';
            if ($query !== []) {
                $url .= '?' . http_build_query($query);
            }
            header('Location: ' . $url);
            exit;
        }
    }

    $stored = lang_normalize_code($_SESSION['lang'] ?? null);
    if ($stored !== null) {
        $_SESSION['lang'] = $stored;
        return;
    }

    // First visit: Settings default, then Accept-Language, then the default.
    $locale = lang_default();
    $header = (string) ($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '');
    if ($header !== '') {
        foreach (explode(',', $header) as $candidate) {
            $candidate = trim(explode(';', $candidate)[0]);
            $match = lang_normalize_code($candidate);
            if ($match !== null) {
                $locale = $match;
                break;
            }
        }
    }
    $_SESSION['lang'] = $locale;
}

/** @return array<string, string> catalog for one locale (cached) */
function lang_catalog(string $code): array
{
    return locale_catalog($code);
}

/** Translate a key: active locale → en-US → key. */
function lang(string $key): string
{
    return locale_translate($key, lang_code());
}

/** Translation coverage of a locale against en-US (bin/check-i18n.php). */
function lang_coverage(string $code): array
{
    $base = array_keys(lang_catalog('en-US'));
    $catalog = lang_catalog($code);
    $translated = 0;
    $missing = [];
    foreach ($base as $key) {
        if (isset($catalog[$key]) && $catalog[$key] !== '') {
            $translated++;
        } else {
            $missing[] = $key;
        }
    }

    return [
        'total'      => count($base),
        'translated' => $translated,
        'percent'    => $base === [] ? 100 : (int) round($translated * 100 / count($base)),
        'missing'    => $missing,
        'unknown'    => array_values(array_diff(array_keys($catalog), $base)),
    ];
}
