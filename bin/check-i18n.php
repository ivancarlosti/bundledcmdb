#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * bin/check-i18n.php — validates the locale catalogs.
 *
 *  1. every locale file loads and returns an array;
 *  2. every literal lang('key') used in the code exists in en-US;
 *  3. a locale never defines a key that en-US does not have (typo guard);
 *  4. reports translation coverage and enforces a per-locale floor.
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

$errors = [];

$en = lang_catalog('en-US');
if ($en === []) {
    $errors[] = 'public/lang/en-US.php is empty or missing.';
}

// Literal keys referenced from the code base.
$roots = [dirname(__DIR__) . '/public', dirname(__DIR__) . '/lib'];
$used = [];
foreach ($roots as $root) {
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }
        $source = (string) file_get_contents($file->getPathname());
        if (preg_match_all('/\blang\(\s*([\'"])([a-zA-Z0-9_]+)\1\s*\)/', $source, $matches)) {
            foreach ($matches[2] as $key) {
                $used[$key] = true;
            }
        }
    }
}

$missingInBase = array_values(array_diff(array_keys($used), array_keys($en)));
sort($missingInBase);
if ($missingInBase !== []) {
    $errors[] = 'Keys used in code but missing from en-US.php: ' . implode(', ', $missingInBase);
}

printf("en-US: %d keys, %d referenced in code\n\n", count($en), count($used));
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
