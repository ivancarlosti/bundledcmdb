<?php
declare(strict_types=1);

/**
 * lib/asset_fields.php — dynamic ("custom") asset fields (EAV).
 *
 * Definitions live in asset_field_defs, values in asset_custom_values keyed by
 * (asset_id, field_id). Keeping values out of `assets` means ESET full-row
 * writes can never wipe them, and per-field search/sort stays indexable.
 */

/** Supported data types: key => [label, input widget]. */
function field_types(): array
{
    return [
        'text'        => ['label' => 'Text', 'input' => 'text'],
        'textarea'    => ['label' => 'Long text', 'input' => 'textarea'],
        'integer'     => ['label' => 'Integer', 'input' => 'number'],
        'decimal'     => ['label' => 'Decimal', 'input' => 'number'],
        'date'        => ['label' => 'Date', 'input' => 'date'],
        'datetime'    => ['label' => 'Date and time', 'input' => 'datetime-local'],
        'bool'        => ['label' => 'Yes / No', 'input' => 'select'],
        'select'      => ['label' => 'Single choice', 'input' => 'select'],
        'multiselect' => ['label' => 'Multiple choice', 'input' => 'select'],
        'email'       => ['label' => 'E-mail', 'input' => 'email'],
        'url'         => ['label' => 'URL', 'input' => 'url'],
        'ip'          => ['label' => 'IP address', 'input' => 'text'],
    ];
}

function field_type_exists(string $type): bool
{
    return isset(field_types()[$type]);
}

/**
 * All field definitions ordered for display.
 *
 * @return list<array>
 */
function field_defs(PDO $pdo, bool $activeOnly = true, bool $force = false): array
{
    static $cache = [];
    $cacheKey = $activeOnly ? 'active' : 'all';
    if (isset($cache[$cacheKey]) && !$force) {
        return $cache[$cacheKey];
    }
    if (!db_table_exists($pdo, 'asset_field_defs')) {
        return $cache[$cacheKey] = [];
    }
    $sql = 'SELECT * FROM asset_field_defs' . ($activeOnly ? ' WHERE is_active = 1' : '')
        . ' ORDER BY sort_order ASC, label ASC';
    return $cache[$cacheKey] = $pdo->query($sql)->fetchAll();
}

/** @return array<string, array> field_key => definition */
function field_defs_by_key(PDO $pdo, bool $activeOnly = true, bool $force = false): array
{
    $map = [];
    foreach (field_defs($pdo, $activeOnly, $force) as $def) {
        $map[(string) $def['field_key']] = $def;
    }
    return $map;
}

function field_def(PDO $pdo, string $key, bool $includeInactive = true): ?array
{
    $defs = field_defs_by_key($pdo, !$includeInactive);
    if (isset($defs[$key])) {
        return $defs[$key];
    }
    if (!$includeInactive) {
        return null;
    }
    $stmt = $pdo->prepare('SELECT * FROM asset_field_defs WHERE field_key = ?');
    $stmt->execute([$key]);
    $row = $stmt->fetch();
    return $row === false ? null : $row;
}

function field_def_by_id(PDO $pdo, int $id): ?array
{
    $stmt = $pdo->prepare('SELECT * FROM asset_field_defs WHERE id = ?');
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    return $row === false ? null : $row;
}

/** "Storage location" -> "storage_location". */
function field_slugify(string $value): string
{
    $ascii = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
    $value = strtolower($ascii === false ? $value : $ascii);
    $value = preg_replace('/[^a-z0-9]+/', '_', $value) ?? '';
    $value = trim(substr($value, 0, 64), '_');
    return $value === '' ? 'field' : $value;
}

/**
 * Options are stored as a JSON list of plain strings.
 *
 * @return list<string>
 */
function field_options(array $def): array
{
    $decoded = json_decode((string) ($def['options_json'] ?? ''), true);
    if (!is_array($decoded)) {
        return [];
    }
    $options = [];
    foreach ($decoded as $option) {
        if (is_string($option) && trim($option) !== '') {
            $options[] = trim($option);
        }
    }
    return $options;
}

/** Accepts an array of strings or a newline/comma separated string. */
function field_options_normalize(mixed $raw): array
{
    if (is_array($raw)) {
        $items = $raw;
    } else {
        $items = preg_split('/[\r\n,]+/', (string) $raw) ?: [];
    }
    $out = [];
    foreach ($items as $item) {
        $item = trim((string) $item);
        if ($item !== '' && !in_array($item, $out, true)) {
            $out[] = $item;
        }
    }
    return $out;
}

/** For definitions that need options, ensure they exist. */
function field_needs_options(string $type): bool
{
    return in_array($type, ['select', 'multiselect'], true);
}

/** Additional validation rules (min/max/maxlength/pattern). */
function field_rules(array $def): array
{
    $decoded = json_decode((string) ($def['validation_json'] ?? ''), true);
    return is_array($decoded) ? $decoded : [];
}

/** Localised label: label_i18n[locale] → language prefix → default label. */
function field_label(array $def, string $locale): string
{
    $decoded = json_decode((string) ($def['label_i18n'] ?? ''), true);
    if (is_array($decoded)) {
        if (!empty($decoded[$locale])) {
            return (string) $decoded[$locale];
        }
        $language = strtolower(substr($locale, 0, 2));
        foreach ($decoded as $code => $label) {
            if (is_string($label) && $label !== '' && strtolower(substr((string) $code, 0, 2)) === $language) {
                return $label;
            }
        }
    }
    return (string) ($def['label'] ?? $def['field_key'] ?? '');
}

/** Roles allowed to edit a field. */
function field_editable_roles(array $def): array
{
    $roles = array_map('trim', explode(',', (string) ($def['editable_roles'] ?? '')));
    return array_values(array_filter($roles, static fn (string $r): bool => $r !== ''));
}

function field_editable_by(array $def, string $role): bool
{
    return in_array($role, field_editable_roles($def), true);
}

/**
 * Validate a definition payload.
 *
 * @return array<string, string> field => error code
 */
function field_validate(PDO $pdo, array $data, ?int $existingId = null): array
{
    $errors = [];
    $key = (string) ($data['field_key'] ?? '');
    $label = trim((string) ($data['label'] ?? ''));
    $type = (string) ($data['data_type'] ?? 'text');

    if (preg_match('/^[a-z][a-z0-9_]{1,63}$/', $key) !== 1) {
        $errors['field_key'] = 'invalid_key';
    } else {
        $stmt = $pdo->prepare('SELECT id FROM asset_field_defs WHERE field_key = ?');
        $stmt->execute([$key]);
        $found = $stmt->fetchColumn();
        if ($found !== false && (int) $found !== (int) $existingId) {
            $errors['field_key'] = 'duplicate';
        }
    }
    if ($label === '' || mb_strlen($label) > 128) {
        $errors['label'] = $label === '' ? 'required' : 'too_long';
    }
    if (!field_type_exists($type)) {
        $errors['data_type'] = 'invalid_type';
    }
    if (field_needs_options($type) && field_options($data) === []) {
        $errors['options_json'] = 'options_required';
    }
    $validRoles = ['user', 'manager', 'admin', 'superadmin'];
    foreach (array_map('trim', explode(',', (string) ($data['editable_roles'] ?? ''))) as $role) {
        if (!in_array($role, $validRoles, true)) {
            $errors['editable_roles'] = 'invalid_role';
            break;
        }
    }

    return $errors;
}

/**
 * Normalise a submitted definition (form or JSON) into column values.
 */
function field_normalize_payload(array $input): array
{
    $label = trim((string) ($input['label'] ?? ''));
    $key = trim((string) ($input['field_key'] ?? ''));
    if ($key === '') {
        $key = field_slugify($label);
    }

    $options = field_options_normalize($input['options_json'] ?? ($input['options'] ?? []));

    $rules = [];
    $rawRules = $input['validation_json'] ?? ($input['validation'] ?? []);
    if (is_string($rawRules)) {
        $decoded = json_decode($rawRules, true);
        $rawRules = is_array($decoded) ? $decoded : [];
    }
    if (is_array($rawRules)) {
        foreach (['min', 'max', 'maxlength', 'pattern'] as $ruleKey) {
            $value = trim((string) ($rawRules[$ruleKey] ?? ''));
            if ($value === '') {
                continue;
            }
            $rules[$ruleKey] = in_array($ruleKey, ['min', 'max', 'maxlength'], true) ? (float) $value : $value;
        }
    }

    $labelI18n = [];
    $rawI18n = $input['label_i18n'] ?? [];
    if (is_string($rawI18n)) {
        $decoded = json_decode($rawI18n, true);
        $rawI18n = is_array($decoded) ? $decoded : [];
    }
    if (is_array($rawI18n)) {
        foreach ($rawI18n as $code => $translated) {
            $translated = trim((string) $translated);
            if ($translated !== '') {
                $labelI18n[(string) $code] = $translated;
            }
        }
    }

    $roles = $input['editable_roles'] ?? 'admin,superadmin';
    if (is_array($roles)) {
        $roles = implode(',', array_map('trim', $roles));
    }
    $roles = implode(',', array_values(array_filter(array_map('trim', explode(',', (string) $roles)))));

    return [
        'field_key'      => $key,
        'label'          => $label,
        'label_i18n'     => $labelI18n === [] ? null : json_encode($labelI18n, JSON_UNESCAPED_UNICODE),
        'data_type'      => (string) ($input['data_type'] ?? 'text'),
        'options_json'   => $options === [] ? null : json_encode(array_values($options), JSON_UNESCAPED_UNICODE),
        'default_value'  => trim((string) ($input['default_value'] ?? '')) ?: null,
        'validation_json' => $rules === [] ? null : json_encode($rules, JSON_UNESCAPED_UNICODE),
        'is_required'    => !empty($input['is_required']) ? 1 : 0,
        'is_unique'      => !empty($input['is_unique']) ? 1 : 0,
        'is_searchable'  => !empty($input['is_searchable']) ? 1 : 0,
        'show_in_grid'   => !empty($input['show_in_grid']) ? 1 : 0,
        'editable_roles' => $roles === '' ? 'admin,superadmin' : $roles,
        'sort_order'     => (int) ($input['sort_order'] ?? 0),
        'is_active'      => array_key_exists('is_active', $input) ? (!empty($input['is_active']) ? 1 : 0) : 1,
    ];
}

function field_create(PDO $pdo, array $data, string $actor): int
{
    $payload = field_normalize_payload($data);
    $payload['created_at'] = gmdate('Y-m-d H:i:s');
    $payload['updated_at'] = gmdate('Y-m-d H:i:s');
    $payload['created_by'] = $actor;

    $columns = array_keys($payload);
    $sql = 'INSERT INTO asset_field_defs (`' . implode('`,`', $columns) . '`) VALUES ('
        . implode(',', array_fill(0, count($columns), '?')) . ')';
    $pdo->prepare($sql)->execute(array_values($payload));
    $id = (int) $pdo->lastInsertId();
    field_defs($pdo, true, true);
    field_defs($pdo, false, true);

    return $id;
}

function field_update(PDO $pdo, int $id, array $data, string $actor): bool
{
    $existing = field_def_by_id($pdo, $id);
    if ($existing === null) {
        return false;
    }
    $payload = field_normalize_payload($data);
    // The key is immutable once assets reference it.
    $payload['field_key'] = (string) $existing['field_key'];
    $payload['updated_at'] = gmdate('Y-m-d H:i:s');

    $set = [];
    $params = [];
    foreach ($payload as $column => $value) {
        $set[] = "`{$column}` = ?";
        $params[] = $value;
    }
    $params[] = $id;
    $pdo->prepare('UPDATE asset_field_defs SET ' . implode(', ', $set) . ' WHERE id = ?')->execute($params);
    field_defs($pdo, true, true);
    field_defs($pdo, false, true);

    return true;
}

function field_set_active(PDO $pdo, int $id, bool $active, string $actor): bool
{
    $stmt = $pdo->prepare('UPDATE asset_field_defs SET is_active = ?, updated_at = ? WHERE id = ?');
    $stmt->execute([$active ? 1 : 0, gmdate('Y-m-d H:i:s'), $id]);
    field_defs($pdo, true, true);
    field_defs($pdo, false, true);

    return $stmt->rowCount() > 0;
}

/** Hard delete a definition and all values stored for it. */
function field_delete(PDO $pdo, int $id): bool
{
    $pdo->prepare('DELETE FROM asset_custom_values WHERE field_id = ?')->execute([$id]);
    $stmt = $pdo->prepare('DELETE FROM asset_field_defs WHERE id = ?');
    $stmt->execute([$id]);
    field_defs($pdo, true, true);
    field_defs($pdo, false, true);

    return $stmt->rowCount() > 0;
}

/** @param list<int> $orderedIds */
function field_reorder(PDO $pdo, array $orderedIds): void
{
    $stmt = $pdo->prepare('UPDATE asset_field_defs SET sort_order = ? WHERE id = ?');
    foreach (array_values($orderedIds) as $index => $id) {
        $stmt->execute([$index + 1, (int) $id]);
    }
    field_defs($pdo, true, true);
    field_defs($pdo, false, true);
}

// --- Values -------------------------------------------------------------

/**
 * Validate/cast one submitted value against its definition.
 *
 * @return array{ok: bool, value: mixed, error: ?string}
 */
function field_parse_value(array $def, mixed $raw): array
{
    $type = (string) $def['data_type'];
    $rules = field_rules($def);
    $options = field_options($def);

    $isEmpty = $raw === null
        || (is_string($raw) && trim($raw) === '')
        || (is_array($raw) && $raw === []);
    if ($isEmpty) {
        return !empty($def['is_required'])
            ? ['ok' => false, 'value' => null, 'error' => 'required']
            : ['ok' => true, 'value' => null, 'error' => null];
    }

    $fail = static fn (string $error): array => ['ok' => false, 'value' => null, 'error' => $error];
    $value = is_array($raw) ? $raw : trim((string) $raw);

    switch ($type) {
        case 'text':
        case 'textarea':
            $text = is_array($value) ? implode(', ', $value) : (string) $value;
            $max = (int) ($rules['maxlength'] ?? ($type === 'textarea' ? 5000 : 255));
            if ($max > 0 && mb_strlen($text) > $max) {
                return $fail('too_long');
            }
            return ['ok' => true, 'value' => $text, 'error' => null];

        case 'integer':
            if (preg_match('/^-?\d+$/', (string) $value) !== 1) {
                return $fail('invalid_integer');
            }
            $int = (int) $value;
            if ((isset($rules['min']) && $int < $rules['min']) || (isset($rules['max']) && $int > $rules['max'])) {
                return $fail('out_of_range');
            }
            return ['ok' => true, 'value' => $int, 'error' => null];

        case 'decimal':
            if (!is_numeric((string) $value)) {
                return $fail('invalid_number');
            }
            $number = (float) $value;
            if ((isset($rules['min']) && $number < $rules['min']) || (isset($rules['max']) && $number > $rules['max'])) {
                return $fail('out_of_range');
            }
            return ['ok' => true, 'value' => $number, 'error' => null];

        case 'date':
            $date = asset_date_normalize($value);
            return $date === null ? $fail('invalid_date') : ['ok' => true, 'value' => $date, 'error' => null];

        case 'datetime':
            $datetime = asset_datetime_normalize($value);
            return $datetime === null ? $fail('invalid_datetime') : ['ok' => true, 'value' => $datetime, 'error' => null];

        case 'bool':
            return ['ok' => true, 'value' => asset_bool_parse($value), 'error' => null];

        case 'select':
            $text = (string) $value;
            return in_array($text, $options, true)
                ? ['ok' => true, 'value' => $text, 'error' => null]
                : $fail('invalid_option');

        case 'multiselect':
            $items = is_array($value) ? $value : array_map('trim', explode(',', (string) $value));
            $clean = [];
            foreach ($items as $item) {
                $item = trim((string) $item);
                if ($item === '') {
                    continue;
                }
                if (!in_array($item, $options, true)) {
                    return $fail('invalid_option');
                }
                if (!in_array($item, $clean, true)) {
                    $clean[] = $item;
                }
            }
            return $clean === [] ? $fail('required') : ['ok' => true, 'value' => $clean, 'error' => null];

        case 'email':
            return filter_var($value, FILTER_VALIDATE_EMAIL) !== false
                ? ['ok' => true, 'value' => (string) $value, 'error' => null]
                : $fail('invalid_email');

        case 'url':
            return filter_var($value, FILTER_VALIDATE_URL) !== false
                ? ['ok' => true, 'value' => (string) $value, 'error' => null]
                : $fail('invalid_url');

        case 'ip':
            return filter_var($value, FILTER_VALIDATE_IP) !== false
                ? ['ok' => true, 'value' => (string) $value, 'error' => null]
                : $fail('invalid_ip');

        default:
            $text = is_array($value) ? implode(', ', $value) : (string) $value;
            return ['ok' => true, 'value' => $text, 'error' => null];
    }
}

function field_encode_value(mixed $value): string
{
    return (string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

function field_decode_value(?string $stored): mixed
{
    if ($stored === null || $stored === '') {
        return null;
    }
    $decoded = json_decode($stored, true);
    return json_last_error() === JSON_ERROR_NONE ? $decoded : $stored;
}

/** Render a stored/typed value for tables, CSV and the API debug view. */
function field_display_value(array $def, mixed $value): string
{
    if ($value === null || $value === '') {
        return '';
    }
    switch ((string) $def['data_type']) {
        case 'bool':
            $label = function_exists('lang') ? lang(asset_bool_parse($value) ? 'true_label' : 'false_label') : null;
            return $label ?? (asset_bool_parse($value) ? 'true' : 'false');
        case 'multiselect':
            return is_array($value) ? implode(', ', array_map('strval', $value)) : (string) $value;
        case 'datetime':
            return function_exists('app_date') ? app_date((string) $value) : (string) $value;
        default:
            return is_array($value) ? implode(', ', array_map('strval', $value)) : (string) $value;
    }
}

/** Starting value for a new asset, derived from the definition default. */
function field_default_typed(array $def): mixed
{
    $default = trim((string) ($def['default_value'] ?? ''));
    if ($default === '') {
        return null;
    }
    $parsed = field_parse_value($def, $default);
    return $parsed['ok'] ? $parsed['value'] : null;
}

// --- Values storage -----------------------------------------------------

/**
 * Load custom values for many assets at once (grid, export, API list).
 *
 * @param list<int> $assetIds
 * @return array<int, array<string, mixed>> assetId => [field_key => typed value]
 */
function custom_values_load(PDO $pdo, array $assetIds, ?array $defs = null): array
{
    $assetIds = array_values(array_unique(array_map('intval', $assetIds)));
    if ($assetIds === [] || !db_table_exists($pdo, 'asset_custom_values')) {
        return [];
    }
    $defs ??= field_defs($pdo);
    $byId = [];
    foreach ($defs as $def) {
        $byId[(int) $def['id']] = $def;
    }

    $result = [];
    foreach (array_chunk($assetIds, 500) as $chunk) {
        $placeholders = implode(',', array_fill(0, count($chunk), '?'));
        $stmt = $pdo->prepare("SELECT asset_id, field_id, value FROM asset_custom_values WHERE asset_id IN ({$placeholders})");
        $stmt->execute($chunk);
        foreach ($stmt->fetchAll() as $row) {
            $def = $byId[(int) $row['field_id']] ?? null;
            if ($def === null) {
                continue;
            }
            $result[(int) $row['asset_id']][(string) $def['field_key']] = field_decode_value($row['value']);
        }
    }
    return $result;
}

/** @return array<string, mixed> field_key => typed value */
function custom_values_for_asset(PDO $pdo, int $assetId, ?array $defs = null): array
{
    return custom_values_load($pdo, [$assetId], $defs)[$assetId] ?? [];
}

/**
 * Save custom values for one asset.
 *
 * Only fields present in $input are touched unless $replace is true, in which
 * case omitted fields are cleared. Pass a role-filtered $defs list so a caller
 * cannot wipe fields it is not allowed to edit.
 *
 * @return array<string, string> field_key => error code
 */
function custom_values_save(
    PDO $pdo,
    int $assetId,
    array $input,
    ?array $defs = null,
    string $actor = '',
    bool $replace = false
): array {
    if (!db_table_exists($pdo, 'asset_custom_values')) {
        return $input === [] ? [] : ['custom' => 'unavailable'];
    }
    $defs ??= field_defs_by_key($pdo);
    $errors = [];

    foreach (array_keys($input) as $key) {
        if (!isset($defs[$key])) {
            $errors[(string) $key] = 'unknown_field';
        }
    }

    $deleteStmt = $pdo->prepare('DELETE FROM asset_custom_values WHERE asset_id = ? AND field_id = ?');
    $upsertStmt = $pdo->prepare(
        'INSERT INTO asset_custom_values (asset_id, field_id, value, updated_at, updated_by)
         VALUES (?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE value = VALUES(value), updated_at = VALUES(updated_at), updated_by = VALUES(updated_by)'
    );
    $now = gmdate('Y-m-d H:i:s');

    foreach ($defs as $key => $def) {
        $provided = array_key_exists($key, $input);
        if (!$provided && !$replace) {
            continue;
        }
        if (!$provided) {
            $deleteStmt->execute([$assetId, (int) $def['id']]);
            continue;
        }

        $parsed = field_parse_value($def, $input[$key]);
        if (!$parsed['ok']) {
            $errors[(string) $key] = (string) $parsed['error'];
            continue;
        }
        if ($parsed['value'] === null) {
            $deleteStmt->execute([$assetId, (int) $def['id']]);
            continue;
        }

        $encoded = field_encode_value($parsed['value']);
        if (!empty($def['is_unique'])) {
            $stmt = $pdo->prepare(
                'SELECT asset_id FROM asset_custom_values WHERE field_id = ? AND value = ? AND asset_id <> ? LIMIT 1'
            );
            $stmt->execute([(int) $def['id'], $encoded, $assetId]);
            if ($stmt->fetchColumn() !== false) {
                $errors[(string) $key] = 'duplicate';
                continue;
            }
        }

        $upsertStmt->execute([$assetId, (int) $def['id'], $encoded, $now, $actor]);
    }

    return $errors;
}

function custom_values_delete(PDO $pdo, int $assetId): void
{
    if (db_table_exists($pdo, 'asset_custom_values')) {
        $pdo->prepare('DELETE FROM asset_custom_values WHERE asset_id = ?')->execute([$assetId]);
    }
}

/** Extract the `custom[...]` map from a POST/JSON payload. */
function custom_input_from(array $source): array
{
    $custom = $source['custom'] ?? [];
    return is_array($custom) ? $custom : [];
}

// --- Form rendering -----------------------------------------------------

/**
 * HTML input for one custom field. Keeps asset.php and asset_new.php in sync.
 */
function field_render_input(array $def, mixed $value, string $name, bool $disabled = false): string
{
    $type = (string) $def['data_type'];
    $options = field_options($def);
    $required = !empty($def['is_required']) ? ' required' : '';
    $disabledAttr = $disabled ? ' disabled' : '';
    $nameAttr = e($name);
    $yes = e(function_exists('lang') ? lang('true_label') : 'Yes');
    $no = e(function_exists('lang') ? lang('false_label') : 'No');

    switch ($type) {
        case 'textarea':
            return '<textarea name="' . $nameAttr . '" rows="3" style="width:100%"' . $required . $disabledAttr . '>'
                . e($value) . '</textarea>';

        case 'bool':
            $selected = $value === null || $value === '' ? '' : (asset_bool_parse($value) ? '1' : '0');
            $html = '<select name="' . $nameAttr . '"' . $required . $disabledAttr . '>';
            $html .= '<option value=""' . ($selected === '' ? ' selected' : '') . '>—</option>';
            $html .= '<option value="1"' . ($selected === '1' ? ' selected' : '') . '>' . $yes . '</option>';
            $html .= '<option value="0"' . ($selected === '0' ? ' selected' : '') . '>' . $no . '</option>';
            return $html . '</select>';

        case 'select':
            $html = '<select name="' . $nameAttr . '"' . $required . $disabledAttr . '>';
            $html .= '<option value="">—</option>';
            foreach ($options as $option) {
                $selected = (string) $value === $option ? ' selected' : '';
                $html .= '<option value="' . e($option) . '"' . $selected . '>' . e($option) . '</option>';
            }
            return $html . '</select>';

        case 'multiselect':
            $values = is_array($value) ? array_map('strval', $value) : [];
            $size = max(3, min(8, count($options)));
            $html = '<select name="' . $nameAttr . '[]" multiple size="' . $size . '"' . $required . $disabledAttr . '>';
            foreach ($options as $option) {
                $selected = in_array($option, $values, true) ? ' selected' : '';
                $html .= '<option value="' . e($option) . '"' . $selected . '>' . e($option) . '</option>';
            }
            return $html . '</select>';

        case 'date':
            return '<input type="date" name="' . $nameAttr . '" value="' . e(substr((string) $value, 0, 10)) . '"'
                . $required . $disabledAttr . '>';

        case 'datetime':
            $local = $value === null || $value === '' ? '' : substr(str_replace(' ', 'T', (string) $value), 0, 16);
            return '<input type="datetime-local" name="' . $nameAttr . '" value="' . e($local) . '"'
                . $required . $disabledAttr . '>';

        case 'integer':
        case 'decimal':
            $step = $type === 'integer' ? ' step="1"' : ' step="any"';
            return '<input type="number" name="' . $nameAttr . '" value="' . e($value) . '"' . $step
                . $required . $disabledAttr . '>';

        case 'email':
        case 'url':
        case 'ip':
            $inputType = $type === 'ip' ? 'text' : $type;
            return '<input type="' . $inputType . '" name="' . $nameAttr . '" value="' . e($value) . '"'
                . $required . $disabledAttr . '>';

        default:
            return '<input type="text" name="' . $nameAttr . '" value="' . e($value) . '"'
                . $required . $disabledAttr . '>';
    }
}


