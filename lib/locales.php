<?php
declare(strict_types=1);

/**
 * lib/locales.php — canonical locale list, display metadata and normalisation.
 *
 * Framework-free and session-free on purpose: the panel API (preferences,
 * `/meta`) and CLI tools need the same list as the legacy pages, without
 * including `public/lang.php` (which starts a session and loads catalogues).
 *
 * `public/lang.php` keeps its historical function names (`lang_supported()`,
 * `lang_meta()`, `lang_normalize_code()`) as thin wrappers over these helpers.
 */

/** @return list<string> canonical locale codes, English first */
function locales_supported(): array
{
    return ['en-US', 'pt-BR', 'es-MX', 'fr-FR', 'zh-CN', 'hi-IN', 'ar-SA'];
}

/** @return array<string, string> legacy or alternate code => canonical code */
function locales_aliases(): array
{
    return [
        'en' => 'en-US', 'en_US' => 'en-US', 'en-us' => 'en-US',
        'pt' => 'pt-BR', 'pt_BR' => 'pt-BR', 'pt-br' => 'pt-BR',
        'es' => 'es-MX', 'es_MX' => 'es-MX', 'es-mx' => 'es-MX',
        'fr' => 'fr-FR', 'fr_FR' => 'fr-FR', 'fr-fr' => 'fr-FR',
        'zh' => 'zh-CN', 'zh_CN' => 'zh-CN', 'h-CN' => 'zh-CN', 'zh-cn' => 'zh-CN',
        'hi' => 'hi-IN', 'hi_IN' => 'hi-IN', 'hi-in' => 'hi-IN',
        'ar' => 'ar-SA', 'ar_SA' => 'ar-SA', 'ar-sa' => 'ar-SA',
    ];
}

/** Native names + writing direction. */
function locales_meta(): array
{
    return [
        'en-US' => ['native' => 'English (US)', 'label' => 'EN', 'dir' => 'ltr'],
        'pt-BR' => ['native' => 'Português (Brasil)', 'label' => 'PT', 'dir' => 'ltr'],
        'es-MX' => ['native' => 'Español (México)', 'label' => 'ES', 'dir' => 'ltr'],
        'fr-FR' => ['native' => 'Français (France)', 'label' => 'FR', 'dir' => 'ltr'],
        'zh-CN' => ['native' => '中文（简体）', 'label' => 'ZH', 'dir' => 'ltr'],
        'hi-IN' => ['native' => 'हिन्दी (भारत)', 'label' => 'HI', 'dir' => 'ltr'],
        'ar-SA' => ['native' => 'العربية (السعودية)', 'label' => 'AR', 'dir' => 'rtl'],
    ];
}

/** Normalise any accepted code to a canonical supported one (or null). */
function locale_normalize(?string $code): ?string
{
    $code = trim((string) $code);
    if ($code === '') {
        return null;
    }
    if (in_array($code, locales_supported(), true)) {
        return $code;
    }
    $aliases = locales_aliases();
    if (isset($aliases[$code])) {
        return $aliases[$code];
    }
    if (isset($aliases[strtolower($code)])) {
        return $aliases[strtolower($code)];
    }
    $prefix = strtolower(substr($code, 0, 2));
    foreach (locales_supported() as $supported) {
        if (strtolower(substr($supported, 0, 2)) === $prefix) {
            return $supported;
        }
    }
    return null;
}

/** Writing direction of a locale (`ltr` unless the locale says otherwise). */
function locale_dir(?string $code): string
{
    $code = locale_normalize($code);
    return $code === null ? 'ltr' : (locales_meta()[$code]['dir'] ?? 'ltr');
}

/** Native display name, falling back to the raw code. */
function locale_native_name(string $code): string
{
    return locales_meta()[$code]['native'] ?? $code;
}

/**
 * Default UI locale (Settings → General), canonicalised to a supported code.
 * Falls back to `APP_DEFAULT_LOCALE`, then `en-US`.
 */
function locale_default(): string
{
    $configured = function_exists('app_default_locale') ? app_default_locale() : 'en-US';
    return locale_normalize($configured) ?? 'en-US';
}
