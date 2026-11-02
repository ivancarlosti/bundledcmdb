<?php
declare(strict_types=1);

/**
 * lib/settings_ui.php — rendering helpers for public/settings.php.
 *
 * Kept separate so the page controller stays readable; language lookups fall
 * back to the English label from settings_definitions().
 */

/** Badge showing which tier answered a setting (DB / ENV / default). */
function settings_source_badge(string $source): string
{
    $labels = [
        'db'      => ['DB', 'badge-db'],
        'env'     => ['ENV', 'badge-env'],
        'default' => ['DEFAULT', 'badge-default'],
    ];
    [$text, $class] = $labels[$source] ?? $labels['default'];
    return '<span class="source-tag ' . $class . '" title="' . e(lang('set_source_' . $source)) . '">'
        . e($text) . '</span>';
}

/** Localised label for a setting, falling back to the definition label. */
function setting_label(string $key, array $def): string
{
    $langKey = 'setting_' . str_replace('.', '_', $key);
    $translated = lang($langKey);
    return $translated === $langKey ? (string) ($def['label'] ?? $key) : $translated;
}

/** Human message for a validation error code. */
function settings_error_message(string $code): string
{
    $langKey = 'set_err_' . $code;
    $translated = lang($langKey);
    return $translated === $langKey ? $code : $translated;
}

/**
 * Render one setting row (input + source badge + reset control).
 *
 * @param array{value: ?string, source: string, def: array} $entry
 * @param list<array> $sources asset sources for the "source" input type
 */
function settings_field_row(string $key, array $entry, array $sources = []): string
{
    $def = $entry['def'];
    $value = (string) ($entry['value'] ?? '');
    $type = (string) $def['type'];
    $id = 'set_' . str_replace('.', '_', $key);
    $name = 'setting[' . $key . ']';

    $html = '<div class="form-row">';
    $html .= '<label for="' . e($id) . '">' . e(setting_label($key, $def)) . '</label>';
    $html .= '<div class="form-control">';

    switch ($type) {
        case 'bool':
            $checked = filter_var($value, FILTER_VALIDATE_BOOLEAN) ? ' checked' : '';
            $html .= '<input type="hidden" name="' . e($name) . '" value="0">';
            $html .= '<input type="checkbox" id="' . e($id) . '" name="' . e($name) . '" value="1"' . $checked . '>';
            break;

        case 'int':
            $html .= '<input type="number" id="' . e($id) . '" name="' . e($name) . '" value="' . e($value) . '"'
                . (isset($def['min']) ? ' min="' . (int) $def['min'] . '"' : '')
                . (isset($def['max']) ? ' max="' . (int) $def['max'] . '"' : '') . '>';
            break;

        case 'timezone':
            $html .= '<select id="' . e($id) . '" name="' . e($name) . '">';
            $html .= '<option value="">— UTC —</option>';
            foreach (DateTimeZone::listIdentifiers() as $tz) {
                $selected = $value === $tz ? ' selected' : '';
                $html .= '<option value="' . e($tz) . '"' . $selected . '>' . e($tz) . '</option>';
            }
            $html .= '</select>';
            break;

        case 'locale':
            $html .= '<select id="' . e($id) . '" name="' . e($name) . '">';
            $html .= '<option value="">— ' . e(lang('set_follow_browser')) . ' —</option>';
            foreach (lang_supported() as $code) {
                $selected = $value === $code ? ' selected' : '';
                $html .= '<option value="' . e($code) . '"' . $selected . '>' . e(lang_native_name($code)) . '</option>';
            }
            $html .= '</select>';
            break;

        case 'select':
            $html .= '<select id="' . e($id) . '" name="' . e($name) . '">';
            foreach (($def['options'] ?? []) as $optionValue => $optionLabel) {
                $selected = $value === (string) $optionValue ? ' selected' : '';
                $themeKey = 'set_theme_' . $optionValue;
                $label = lang($themeKey) === $themeKey ? (string) $optionLabel : lang($themeKey);
                $html .= '<option value="' . e($optionValue) . '"' . $selected . '>' . e($label) . '</option>';
            }
            $html .= '</select>';
            break;

        case 'source':
            $html .= '<select id="' . e($id) . '" name="' . e($name) . '">';
            foreach ($sources as $source) {
                $selected = $value === (string) $source['slug'] ? ' selected' : '';
                $html .= '<option value="' . e($source['slug']) . '"' . $selected . '>'
                    . e($source['label']) . '</option>';
            }
            $html .= '</select>';
            break;

        case 'secret':
            $configured = $value !== '';
            $html .= '<input type="password" id="' . e($id) . '" name="' . e($name) . '" value="" autocomplete="new-password" placeholder="'
                . e($configured ? lang('set_secret_keep') : lang('set_secret_empty')) . '">';
            if ($configured) {
                $html .= ' <label class="inline-check"><input type="checkbox" name="clear[' . e($key) . ']" value="1"> '
                    . e(lang('set_secret_clear')) . '</label>';
            }
            break;

        case 'textarea':
            $html .= '<textarea id="' . e($id) . '" name="' . e($name) . '" rows="3" placeholder="'
                . e(lang('set_allowlist_hint')) . '">' . e($value) . '</textarea>';
            break;

        default:
            $html .= '<input type="text" id="' . e($id) . '" name="' . e($name) . '" value="' . e($value) . '">';
    }

    $html .= '<div class="form-meta">' . settings_source_badge($entry['source']);
    if ($entry['source'] === 'db') {
        $html .= ' <button type="submit" class="link-button" name="action" value="reset_setting" '
            . 'formnovalidate onclick="return confirm(\'' . e(lang('set_reset_confirm')) . '\')">'
            . e(lang('set_reset')) . '</button>'
            . '<input type="hidden" name="reset_key" value="' . e($key) . '">';
    }
    $html .= '</div></div></div>';

    return $html;
}

