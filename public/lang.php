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

/** DB values for Status (English in the database, translated for display). */
define('STATUS_DB_VALUES', [
    'In Use',
    'In Stock',
    'In Repair',
    'Decommissioned',
    'Lost or Stolen',
]);

/** @return list<string> canonical locale codes, English first */
function lang_supported(): array
{
    return locales_supported();
}

/** @return array<string, string> legacy or alternate code => canonical code */
function lang_aliases(): array
{
    return locales_aliases();
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

function lang_native_name(string $code): string
{
    return locale_native_name($code);
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

/** Writing direction of the active locale. */
function lang_dir(?string $code = null): string
{
    $meta = lang_meta();
    return $meta[$code ?? lang_code()]['dir'] ?? 'ltr';
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
            $url = $path !== false ? $path : 'main.php';
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
    static $cache = [];
    if (isset($cache[$code])) {
        return $cache[$code];
    }
    $file = __DIR__ . '/lang/' . $code . '.php';
    if (!is_file($file)) {
        return $cache[$code] = [];
    }
    $catalog = require $file;
    return $cache[$code] = is_array($catalog) ? $catalog : [];
}

/** Translate a key: active locale → en-US → key. */
function lang(string $key): string
{
    $catalog = lang_catalog(lang_code());
    if (isset($catalog[$key]) && $catalog[$key] !== '') {
        return (string) $catalog[$key];
    }
    $fallback = lang_catalog('en-US');
    return isset($fallback[$key]) && $fallback[$key] !== '' ? (string) $fallback[$key] : $key;
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

/** [db value => translated label] for Status dropdowns. */
function lang_status_options(): array
{
    $keys = ['status_in_use', 'status_stock', 'status_repair', 'status_decomm', 'status_lost'];
    $result = [];
    foreach (asset_status_values() as $index => $value) {
        $result[$value] = lang($keys[$index] ?? 'status_in_use');
    }
    return $result;
}

function lang_status_label(?string $value): string
{
    $value = (string) $value;
    if ($value === '') {
        return '';
    }
    return lang_status_options()[$value] ?? $value;
}

/** Locale combo box (replaces the flags). Preserves the current query string. */
function lang_selector(string $currentPage = ''): string
{
    $page = $currentPage !== '' ? $currentPage : basename((string) ($_SERVER['SCRIPT_NAME'] ?? 'main.php'));
    $hidden = '';
    foreach ($_GET as $name => $value) {
        if ($name === 'lang' || is_array($value)) {
            continue;
        }
        $hidden .= '<input type="hidden" name="' . e((string) $name) . '" value="' . e((string) $value) . '">';
    }

    $html = '<form method="get" action="' . e($page) . '" class="lang-selector">' . $hidden;
    $html .= '<label for="langSelect" class="sr-only">' . e(lang('language')) . '</label>';
    $html .= '<select id="langSelect" name="lang" onchange="this.form.submit()">';
    foreach (lang_supported() as $code) {
        $selected = $code === lang_code() ? ' selected' : '';
        $html .= '<option value="' . e($code) . '"' . $selected . '>' . e(lang_native_name($code)) . '</option>';
    }
    $html .= '</select><noscript><button type="submit">' . e(lang('apply')) . '</button></noscript></form>';

    return $html;
}

/** Backwards-compatible alias: the old templates called this "flags". */
function lang_flag_buttons(string $current_page = ''): string
{
    return lang_selector($current_page);
}

function theme_toggle_button(): string
{
    $html = '<div class="theme-toggle">';
    $html .= '<button type="button" id="themeToggleBtn" title="' . e(lang('theme_system')) . '" onclick="cycleTheme()">';
    $html .= '<span id="themeIcon">⏻</span></button></div>';
    return $html;
}

