<?php
declare(strict_types=1);

/**
 * lib/assets_ui.php — HTML inputs shared by asset.php and asset_new.php.
 */

/** Render the input for one built-in asset column. */
function asset_field_input(string $column, mixed $value, bool $disabled = false): string
{
    $def = asset_column_defs()[$column] ?? ['type' => 'text'];
    $name = 'row[' . $column . ']';
    $nameAttr = e($name);
    $disabledAttr = $disabled ? ' disabled' : '';
    $value = (string) ($value ?? '');

    switch ($def['type']) {
        case 'status':
            $html = '<select name="' . $nameAttr . '"' . $disabledAttr . '>';
            $html .= '<option value="">—</option>';
            foreach (lang_status_options() as $dbValue => $label) {
                $selected = $value === $dbValue ? ' selected' : '';
                $html .= '<option value="' . e($dbValue) . '"' . $selected . '>' . e($label) . '</option>';
            }
            return $html . '</select>';

        case 'bool':
            $selected = $value === '' ? '' : (asset_bool_parse($value) ? 'true' : 'false');
            $html = '<select name="' . $nameAttr . '"' . $disabledAttr . '>';
            $html .= '<option value=""' . ($selected === '' ? ' selected' : '') . '>—</option>';
            $html .= '<option value="true"' . ($selected === 'true' ? ' selected' : '') . '>'
                . e(lang('true_label')) . '</option>';
            $html .= '<option value="false"' . ($selected === 'false' ? ' selected' : '') . '>'
                . e(lang('false_label')) . '</option>';
            return $html . '</select>';

        case 'date':
            return '<input type="date" name="' . $nameAttr . '" value="' . e(substr($value, 0, 10)) . '"' . $disabledAttr . '>';

        case 'datetime':
            $local = $value === '' ? '' : substr(str_replace(' ', 'T', $value), 0, 16);
            return '<input type="datetime-local" name="' . $nameAttr . '" value="' . e($local) . '"' . $disabledAttr . '>';

        case 'textarea':
            return '<textarea name="' . $nameAttr . '" rows="4" style="width:100%"' . $disabledAttr . '>'
                . e($value) . '</textarea>';

        case 'email':
            return '<input type="email" name="' . $nameAttr . '" value="' . e($value) . '"' . $disabledAttr . '>';

        default:
            return '<input type="text" name="' . $nameAttr . '" value="' . e($value) . '"' . $disabledAttr . '>';
    }
}

/**
 * Custom-field block for the asset forms.
 *
 * @param array<string, mixed> $values field_key => typed value
 * @param array<string, string> $errors field_key => error code
 */
function asset_custom_fields_html(PDO $pdo, string $role, array $values = [], array $errors = []): string
{
    $defs = field_defs($pdo);
    if ($defs === []) {
        return '<tr><td colspan="2" class="muted">' . e(lang('set_field_none')) . '</td></tr>';
    }

    $html = '';
    foreach ($defs as $def) {
        if (!field_editable_by($def, $role)) {
            continue;
        }
        $key = (string) $def['field_key'];
        $value = $values[$key] ?? field_default_typed($def);
        $html .= '<tr>';
        $html .= '<th>' . e(field_label($def, lang_code())) . (!empty($def['is_required']) ? ' *' : '') . '</th>';
        $html .= '<td>' . field_render_input($def, $value, 'custom[' . $key . ']');
        if (isset($errors[$key])) {
            $html .= '<div class="field-error">' . e(settings_error_message((string) $errors[$key])) . '</div>';
        }
        $html .= '</td></tr>';
    }
    return $html;
}

/** Read the custom values already stored for an asset (for form prefill). */
function asset_custom_values_for_form(PDO $pdo, int $assetId, string $role): array
{
    $values = custom_values_for_asset($pdo, $assetId);
    $filtered = [];
    foreach (field_defs($pdo) as $def) {
        if (field_editable_by($def, $role) && array_key_exists((string) $def['field_key'], $values)) {
            $filtered[(string) $def['field_key']] = $values[(string) $def['field_key']];
        }
    }
    return $filtered;
}
