#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * bin/check-i18n.php — validates the locale catalogs.
 *
 *  1. every locale file loads and returns an array;
 *  2. every key referenced from code exists in en-US — `lang('key')` in PHP and
 *     `t('key')`, `i18nKey="key"` or `label_key: 'key'` in the SPA/Playwright;
 *  3. a locale never defines a key that en-US does not have (typo guard);
 *  4. no en-US key is left that nothing references (dead key). Deleting a screen
 *     has to delete its strings: after the legacy PHP pages went away 70 keys
 *     stayed behind, translated by hand in five locales, for screens nobody could
 *     reach any more;
 *  5. reports translation coverage and enforces a per-locale floor.
 *
 * The SPA is scanned as well because it is now the only consumer of these
 * catalogues: `lib/` and `public/` barely call `lang()` any more, so a PHP-only
 * scan reports "0 referenced" and protects nothing.
 *
 * Exit code 0 = OK, 1 = problems found.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}

require_once __DIR__ . '/../lib/bootstrap.php';
require_once __DIR__ . '/../public/lang.php';

/** Minimum share of en-US keys each locale must translate. */
const I18N_MIN_COVERAGE = [
    'en-US' => 100,
    'pt-BR' => 90,
    'es-MX' => 75,
    // The four community locales ship the whole React panel (every `ui_*`,
    // `col_*`, `status_*`, `theme_*` and `role_*` key) and fall back to en-US for
    // the legacy `set_*`/`perm_*` screens of the PHP pages.
    'fr-FR' => 45,
    'zh-CN' => 45,
    'hi-IN' => 45,
    'ar-SA' => 45,
];

/**
 * Key families the code builds at runtime, so there is no literal to find:
 *
 *   col_*         lib/panel/meta.php            `'col_' . $cleaned`
 *   setting_*     lib/panel/settings.php        `'setting_' . str_replace('.', '_', $key)`
 *   set_theme_*   SettingsGroupPanel.tsx        `set_theme_${option.value}`
 *   set_source_*  SettingsGroupPanel.tsx        `set_source_${entry.source}`
 *   role_*        AppShell/UsersTab/FieldsTab   `role_${role}`
 *   ui_ev_*       DeviceDetailPage.tsx          `ui_ev_${event.action}`
 *   ui_form_tab_* DeviceFormPage.tsx            `ui_form_tab_${tab.id}`
 */
const I18N_DYNAMIC_FAMILIES = [
    'col_',
    'setting_',
    'set_theme_',
    'set_source_',
    'role_',
    'ui_ev_',
    'ui_form_tab_',
];

/**
 * Source with comments removed: a doc block that writes `t('key')` is an
 * example, not a call site.
 */
function i18n_without_comments(string $source): string
{
    $source = (string) preg_replace('#/\*.*?\*/#s', ' ', $source);
    // `(?<!:)` keeps `https://` inside a string literal intact.
    return (string) preg_replace('#(?<!:)//[^\n]*#', ' ', $source);
}

/** @return list<string> absolute paths of the sources that reference keys */
function i18n_sources(string $root): array
{
    $files = [];
    foreach ([['public', ['php']], ['lib', ['php']], ['ui/src', ['ts', 'tsx']], ['ui/e2e', ['ts', 'tsx']]] as [$dir, $extensions]) {
        if (!is_dir($root . '/' . $dir)) {
            continue;
        }
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(
            $root . '/' . $dir,
            FilesystemIterator::SKIP_DOTS
        ));
        foreach ($iterator as $file) {
            $path = str_replace('\\', '/', $file->getPathname());
            if (!in_array($file->getExtension(), $extensions, true) || str_contains($path, '/public/lang/')) {
                continue;
            }
            $files[] = $path;
        }
    }
    return $files;
}

$root = dirname(__DIR__);
$errors = [];

$en = lang_catalog('en-US');
if ($en === []) {
    $errors[] = 'public/lang/en-US.php is empty or missing.';
}

// `$references` is the strict set: an explicit call site, so the key must exist.
// `$mentions` is the loose set (any quoted token): it only keeps a key from being
// reported dead, which is how tables like `col_*` in lib/panel/meta.php and
// `theme_*` in ThemeSwitcher.tsx stay alive.
$references = [];
$mentions = [];
$callSites = [
    '/\blang\(\s*([\'"])([a-zA-Z0-9_.]+)\1/',          // PHP
    '/\bt\(\s*([\'"])([a-zA-Z0-9_.]+)\1/',             // React
    '/i18nKey=([\'"])([a-zA-Z0-9_.]+)\1/',             // React (antd style props)
    '/label_key\s*:\s*([\'"])([a-zA-Z0-9_.]+)\1/',     // server payloads / tab tables
];

foreach (i18n_sources($root) as $path) {
    $source = i18n_without_comments((string) file_get_contents($path));
    foreach ($callSites as $pattern) {
        if (preg_match_all($pattern, $source, $matches)) {
            foreach ($matches[2] as $key) {
                $references[$key] = true;
            }
        }
    }
    if (preg_match_all('/[\'"]([A-Za-z0-9_.]+)[\'"]/', $source, $matches)) {
        foreach ($matches[1] as $key) {
            $mentions[$key] = true;
        }
    }
}

$missingInBase = array_values(array_diff(array_keys($references), array_keys($en)));
sort($missingInBase);
if ($missingInBase !== []) {
    $errors[] = 'Keys referenced in code but missing from en-US.php: ' . implode(', ', $missingInBase);
}

$dead = [];
foreach (array_keys($en) as $key) {
    if (isset($references[$key]) || isset($mentions[$key])) {
        continue;
    }
    foreach (I18N_DYNAMIC_FAMILIES as $family) {
        if (str_starts_with($key, $family)) {
            continue 2;
        }
    }
    $dead[] = $key;
}
sort($dead);
if ($dead !== []) {
    $errors[] = 'en-US.php has keys nothing references any more (delete them, or add a dynamic family): '
        . implode(', ', $dead);
}

printf("en-US: %d keys, %d referenced, %d dynamic families\n\n", count($en), count($references), count(I18N_DYNAMIC_FAMILIES));
printf("%-8s %9s %9s %9s %9s\n", 'locale', 'keys', 'translated', 'coverage', 'min');


foreach (lang_supported() as $code) {
    $catalog = lang_catalog($code);
    if ($catalog === []) {
        $errors[] = $code . ': catalog missing or invalid.';
        continue;
    }
    $stats = lang_coverage($code);
    printf(
        "%-8s %9d %9d %8d%% %8d%%\n",
        $code,
        count($catalog),
        $stats['translated'],
        $stats['percent'],
        I18N_MIN_COVERAGE[$code] ?? 0
    );

    if ($stats['unknown'] !== []) {
        $errors[] = $code . ': unknown keys (not in en-US): ' . implode(', ', array_slice($stats['unknown'], 0, 10));
    }
    if ($stats['percent'] < (I18N_MIN_COVERAGE[$code] ?? 0)) {
        $errors[] = $code . ': coverage ' . $stats['percent'] . '% is below the required '
            . (I18N_MIN_COVERAGE[$code] ?? 0) . '%.';
    }
}

echo "\n";
if ($errors !== []) {
    foreach ($errors as $error) {
        fwrite(STDERR, 'ERROR ' . $error . PHP_EOL);
    }
    exit(1);
}

echo 'i18n check passed.' . PHP_EOL;
exit(0);
