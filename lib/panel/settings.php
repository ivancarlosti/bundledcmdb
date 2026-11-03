<?php
declare(strict_types=1);

/**
 * lib/panel/settings.php — administration endpoints for `/panel-api/v1/settings*`.
 *
 *   GET    /settings                       definitions + current values   (admin+)
 *   PATCH  /settings                       validate and store a subset   (admin+)
 *   GET    /settings/sources               provenance sources            (admin+)
 *   POST   /settings/sources                                             (admin+)
 *   PATCH  /settings/sources/{slug}                                      (admin+)
 *   DELETE /settings/sources/{slug}        refused while in use          (admin+)
 *   GET    /settings/fields                custom-field definitions      (admin+)
 *   POST   /settings/fields                                              (admin+)
 *   POST   /settings/fields/reorder        drag-and-drop order           (admin+)
 *   PATCH  /settings/fields/{id}                                         (admin+)
 *   DELETE /settings/fields/{id}           drops stored values too       (admin+)
 *   GET    /settings/api-keys              keys without their hash       (admin+)
 *   POST   /settings/api-keys              token returned once           (admin+)
 *   POST   /settings/api-keys/{id}/rotate  token returned once           (admin+)
 *   PATCH  /settings/api-keys/{id}                                       (admin+)
 *   DELETE /settings/api-keys/{id}                                       (admin+)
 *   GET    /settings/users                 roles per e-mail              (superadmin)
 *   PATCH  /settings/users/{email}         change one role               (superadmin)
 *   GET    /settings/maintenance           schema state + diagnostics    (admin+)
 *   POST   /settings/maintenance/migrate   run (or dry-run) migrations   (admin+)
 *
 * The handlers are thin on purpose: every rule (validation, protection of system
 * sources, token hashing, migration ledger) already lives in the model helpers
 * (`lib/settings.php`, `lib/sources.php`, `lib/api_auth.php`, `lib/migrate.php`),
 * so the REST API in `public/api.php` and this panel cannot drift. What this file
 * adds is the JSON shape, the role gate and the `ApiError` mapping
 * (`validation_failed` + `errors` per field, like the device writes).
 */

/** Roles allowed to change settings; user management stays superadmin-only. */
function panel_settings_user(bool $superadminOnly = false): array
{
    return $superadminOnly
        ? session_require_role(['superadmin'])
        : session_require_role(['admin', 'superadmin']);
}

/** Guard for a mutating handler: role first, then the CSRF header. */
function panel_settings_writer(bool $superadminOnly = false): array
{
    $user = panel_settings_user($superadminOnly);
    session_csrf_require();
    return $user;
}

/** Positive integer path parameter. */
function panel_settings_id(array $params, string $name = 'id'): int
{
    $id = (int) ($params[$name] ?? 0);
    if ($id <= 0) {
        throw new ApiError('invalid_id', 'Expected a positive numeric ' . $name . '.', 400);
    }
    return $id;
}

/** Decoded JSON body as an array (empty body => []). */
function panel_settings_body(): array
{
    return request_json_body();
}

/**
 * Options for a `select` setting: `['dark' => 'Dark']` => `[{value, label}]`.
 *
 * @param array<string, string> $options
 * @return list<array{value: string, label: string}>
 */
function panel_settings_options(array $options): array
{
    $out = [];
    foreach ($options as $value => $label) {
        $out[] = ['value' => (string) $value, 'label' => (string) $label];
    }
    return $out;
}

/**
 * One setting as the SPA renders it.
 *
 * Secrets never leave the server: `value` stays empty and `configured` says
 * whether something is stored (DB or env), which is what the form needs to show
 * "configured — leave blank to keep it".
 */
function panel_settings_entry(string $key, array $entry): array
{
    $def = $entry['def'];
    $isSecret = !empty($def['secret']);

    return [
        'key'        => $key,
        'group'      => (string) ($def['group'] ?? 'general'),
        'label'      => (string) ($def['label'] ?? $key),
        'label_key'  => 'setting_' . str_replace('.', '_', $key),
        'type'       => (string) ($def['type'] ?? 'text'),
        'options'    => panel_settings_options((array) ($def['options'] ?? [])),
        'min'        => $def['min'] ?? null,
        'max'        => $def['max'] ?? null,
        'source'     => (string) $entry['source'],
        'secret'     => $isSecret,
        'configured' => $isSecret ? settings_secret_configured($key) : null,
        'value'      => $isSecret ? '' : (string) ($entry['value'] ?? ''),
    ];
}

/** Full `GET /settings` payload (also returned after a write). */
function panel_settings_payload(): array
{
    $groups = [];
    $labels = [
        'general' => 'set_group_general',
        'assets'  => 'set_group_assets',
        'api'     => 'set_group_api',
        'storage' => 'set_group_storage',
        's3'      => 'set_group_s3',
        'logs'    => 'set_group_logs',
    ];

    foreach (settings_all(true) as $key => $entry) {
        $group = (string) ($entry['def']['group'] ?? 'general');
        if (!isset($groups[$group])) {
            $groups[$group] = [
                'id'        => $group,
                'label_key' => $labels[$group] ?? 'set_group_general',
                'settings'  => [],
            ];
        }
        $groups[$group]['settings'][] = panel_settings_entry((string) $key, $entry);
    }

    return [
        'groups'    => array_values($groups),
        'sources'   => panel_settings_source_rows(db()),
        'locales'   => panel_locales_payload(),
        'timezones' => DateTimeZone::listIdentifiers(),
        'themes'    => user_preferences_themes(),
    ];
}

/** GET /settings */
function panel_settings_show(array $params = []): array
{
    panel_settings_user();
    return ['data' => panel_settings_payload()];
}

/**
 * Validate and store one setting.
 *
 * @return string|null error code, null when stored
 */
function panel_settings_store(string $key, mixed $raw, string $actor): ?string
{
    $defs = settings_definitions();
    $def = $defs[$key] ?? null;
    if ($def === null) {
        return 'unknown_setting';
    }
    $type = (string) ($def['type'] ?? 'text');

    if ($type === 'bool') {
        settings_set($key, filter_var((string) $raw, FILTER_VALIDATE_BOOLEAN) ? '1' : '0', $actor);
        return null;
    }
    if ($type === 'locale') {
        $locale = trim((string) $raw);
        if ($locale !== '' && !in_array($locale, locales_supported(), true)) {
            return 'invalid_locale';
        }
        settings_set($key, $locale, $actor);
        return null;
    }
    if ($type === 'source') {
        $slug = trim((string) $raw);
        if ($slug !== '' && !source_exists(db(), $slug)) {
            return 'unknown_source';
        }
        settings_set($key, $slug, $actor);
        return null;
    }
    if ($key === 'api.ip_allowlist' && !ip_allowlist_validate((string) $raw)['ok']) {
        return 'invalid_ip';
    }

    $normalized = settings_normalize($key, $raw);
    if (!$normalized['ok']) {
        return (string) $normalized['error'];
    }
    settings_set($key, $normalized['value'], $actor);

    return null;
}

/**
 * Value written to the audit trail instead of a stored secret.
 *
 * The Logs screen must be able to say "the S3 secret was changed" without ever
 * exposing it (and without leaking it into a table that is purged less often
 * than the secret is rotated).
 */
const PANEL_AUDIT_SECRET = '••••••';

/**
 * Append one settings/user-management event.
 *
 * Every settings screen writes global rows, so `company` stays null unless the
 * row it describes belongs to a tenant (an API key can). A company-bound admin
 * sees the null-company rows, which is exactly right: global configuration is
 * part of what happens to their instance.
 *
 * @param array<string, array{0: ?string, 1: ?string}> $changes
 */
function panel_settings_audit(
    PDO $pdo,
    array $user,
    string $action,
    string $entity,
    string $entityId,
    string $label,
    array $changes = [],
    ?string $note = null,
    ?string $company = null
): void {
    asset_event_record(
        $pdo,
        null,
        $action,
        (string) ($user['email'] ?? 'system'),
        $changes,
        $note,
        [
            'entity'       => $entity,
            'entity_id'    => $entityId,
            'entity_label' => $label,
            'company'      => $company,
        ]
    );
}

/**
 * PATCH /settings
 *
 * Body: `{ "values": { "<key>": <value> }, "clear": ["<secret key>"] }`.
 * A secret is only written when a non-empty value is sent, so an untouched form
 * field can never wipe a stored secret.
 */
function panel_settings_update(array $params = []): array
{
    $user = panel_settings_writer();
    $body = panel_settings_body();

    $values = $body['values'] ?? null;
    if (!is_array($values)) {
        throw new ApiError('validation_failed', 'Expected a `values` object.', 422, ['values' => 'required']);
    }
    $clear = array_values(array_filter((array) ($body['clear'] ?? []), 'is_string'));

    $errors = [];
    $saved = [];
    $audit = [];

    foreach ($clear as $key) {
        $def = settings_definitions()[$key] ?? null;
        if ($def === null || empty($def['secret'])) {
            $errors[$key] = 'not_a_secret';
            continue;
        }
        $audit[] = ['key' => $key, 'old' => PANEL_AUDIT_SECRET, 'new' => null];
        settings_set($key, '', $user['email']);
        $saved[] = $key;
    }

    foreach ($values as $key => $raw) {
        $key = (string) $key;
        $def = settings_definitions()[$key] ?? null;
        if ($def === null) {
            $errors[$key] = 'unknown_setting';
            continue;
        }
        if (!empty($def['secret'])) {
            $secret = trim((string) $raw);
            if ($secret === '') {
                // Unchanged: the form never receives the stored value.
                continue;
            }
            $audit[] = ['key' => $key, 'old' => PANEL_AUDIT_SECRET, 'new' => PANEL_AUDIT_SECRET];
            settings_set($key, $secret, $user['email']);
            $saved[] = $key;
            continue;
        }
        $before = settings_get($key);
        $error = panel_settings_store($key, $raw, $user['email']);
        if ($error !== null) {
            $errors[$key] = $error;
            continue;
        }
        $audit[] = ['key' => $key, 'old' => $before, 'new' => settings_get($key)];
        $saved[] = $key;
    }

    if ($errors !== []) {
        throw new ApiError('validation_failed', 'Some settings were rejected.', 422, $errors);
    }

    $pdo = db();
    $defs = settings_definitions();
    foreach ($audit as $entry) {
        $key = (string) $entry['key'];
        $old = $entry['old'] === null ? null : (string) $entry['old'];
        $new = $entry['new'] === null ? null : (string) $entry['new'];
        if ($old === $new) {
            // The form re-submits every field; only real changes are audited.
            continue;
        }
        panel_settings_audit(
            $pdo,
            $user,
            'update',
            'setting',
            $key,
            (string) ($defs[$key]['label'] ?? $key),
            [$key => [$old, $new]],
            null,
            null
        );
    }

    return ['data' => panel_settings_payload(), 'meta' => ['saved' => array_values(array_unique($saved))]];
}

// --- Sources (provenance) ------------------------------------------------

/**
 * Sources plus how many assets use each one, so the UI can explain a refused
 * delete instead of only showing "in use".
 *
 * @return list<array>
 */
function panel_settings_source_rows(PDO $pdo): array
{
    $usage = [];
    if (db_table_exists($pdo, 'assets')) {
        foreach ($pdo->query('SELECT `source`, COUNT(*) AS total FROM assets GROUP BY `source`')->fetchAll() as $row) {
            $usage[(string) $row['source']] = (int) $row['total'];
        }
    }

    $out = [];
    foreach (sources_all($pdo) as $row) {
        $slug = (string) $row['slug'];
        $out[] = [
            'slug'       => $slug,
            'label'      => (string) $row['label'],
            'color'      => $row['color'] === null ? null : (string) $row['color'],
            'is_system'  => (int) $row['is_system'] === 1,
            'is_default' => (int) $row['is_default'] === 1,
            'usage'      => $usage[$slug] ?? 0,
        ];
    }

    return $out;
}

function panel_settings_sources_payload(PDO $pdo): array
{
    return ['sources' => panel_settings_source_rows($pdo)];
}

/** GET /settings/sources */
function panel_settings_sources_index(array $params = []): array
{
    panel_settings_user();
    return ['data' => panel_settings_sources_payload(db())];
}

/** POST /settings/sources */
function panel_settings_sources_store(array $params = []): array
{
    $user = panel_settings_writer();
    $pdo = db();
    $body = panel_settings_body();

    $slug = trim((string) ($body['slug'] ?? ''));
    if ($slug === '') {
        // The legacy form asks for the slug; the SPA derives it from the label.
        $slug = source_slugify((string) ($body['label'] ?? ''));
    }
    $payload = [
        'slug'       => $slug,
        'label'      => (string) ($body['label'] ?? ''),
        'color'      => (string) ($body['color'] ?? ''),
        'is_default' => !empty($body['is_default']),
    ];

    $errors = source_validate($pdo, $payload);
    if ($errors !== []) {
        throw new ApiError('validation_failed', 'The source could not be saved.', 422, $errors);
    }

    source_create($pdo, $payload);

    panel_settings_audit($pdo, $user, 'create', 'source', $slug, (string) $payload['label'], [
        'slug'  => [null, $slug],
        'label' => [null, (string) $payload['label']],
        'color' => [null, (string) $payload['color']],
    ]);

    return [
        'data'   => panel_settings_sources_payload($pdo),
        'status' => 201,
        'meta'   => ['slug' => $slug],
    ];
}

/** PATCH /settings/sources/{slug} */
function panel_settings_sources_update(array $params = []): array
{
    $user = panel_settings_writer();
    $pdo = db();
    $slug = (string) ($params['slug'] ?? '');
    $source = source_get($pdo, $slug);
    if ($source === null) {
        throw new ApiError('not_found', 'No source named ' . $slug . '.', 404);
    }

    $body = panel_settings_body();
    $merged = [
        'slug'       => $slug,
        'label'      => array_key_exists('label', $body) ? (string) $body['label'] : (string) $source['label'],
        'color'      => array_key_exists('color', $body) ? (string) $body['color'] : (string) ($source['color'] ?? ''),
        'is_default' => array_key_exists('is_default', $body) ? !empty($body['is_default']) : (int) $source['is_default'] === 1,
    ];

    // The slug is the identity: `source_update()` only ever changes label/colour
    // and can move the default flag, never clear it.
    $errors = source_validate($pdo, $merged, $slug);
    if ($errors !== []) {
        throw new ApiError('validation_failed', 'The source could not be saved.', 422, $errors);
    }

    source_update($pdo, $slug, $merged);

    $changes = asset_event_diff(
        ['label' => (string) $source['label'], 'color' => (string) ($source['color'] ?? ''), 'is_default' => (string) (int) $source['is_default']],
        ['label' => (string) $merged['label'], 'color' => (string) $merged['color'], 'is_default' => $merged['is_default'] ? '1' : '0']
    );
    if ($changes !== []) {
        panel_settings_audit($pdo, $user, 'update', 'source', $slug, (string) $merged['label'], $changes);
    }

    return ['data' => panel_settings_sources_payload($pdo)];
}

/** DELETE /settings/sources/{slug} */
function panel_settings_sources_delete(array $params = []): array
{
    $user = panel_settings_writer();
    $pdo = db();
    $slug = (string) ($params['slug'] ?? '');
    $source = source_get($pdo, $slug);
    $result = source_delete($pdo, $slug);

    if (!$result['ok']) {
        $code = (string) $result['error'];
        $status = $code === 'not_found' ? 404 : 409;
        throw new ApiError($code, 'The source "' . $slug . '" cannot be deleted.', $status, ['slug' => $code]);
    }

    panel_settings_audit(
        $pdo,
        $user,
        'delete',
        'source',
        $slug,
        $source === null ? $slug : (string) $source['label'],
        ['slug' => [$slug, null]]
    );

    return ['data' => panel_settings_sources_payload($pdo), 'meta' => ['deleted' => $slug]];
}

// --- Custom-field definitions -------------------------------------------

/**
 * One definition in JSON form, plus how many asset values reference it (so the
 * UI can warn before the destructive delete).
 *
 * @return list<array>
 */
function panel_settings_field_rows(PDO $pdo): array
{
    $usage = [];
    if (db_table_exists($pdo, 'asset_custom_values')) {
        foreach ($pdo->query('SELECT field_id, COUNT(*) AS total FROM asset_custom_values GROUP BY field_id')->fetchAll() as $row) {
            $usage[(int) $row['field_id']] = (int) $row['total'];
        }
    }

    $out = [];
    foreach (field_defs($pdo, false) as $def) {
        $id = (int) $def['id'];
        $labelI18n = json_decode((string) ($def['label_i18n'] ?? ''), true);
        $out[] = [
            'id'             => $id,
            'key'            => (string) $def['field_key'],
            'label'          => (string) $def['label'],
            'label_i18n'     => is_array($labelI18n) ? $labelI18n : [],
            'type'           => (string) $def['data_type'],
            'options'        => field_options($def),
            'default'        => (string) ($def['default_value'] ?? ''),
            'validation'     => field_rules($def),
            'required'       => (int) ($def['is_required'] ?? 0) === 1,
            'unique'         => (int) ($def['is_unique'] ?? 0) === 1,
            'searchable'     => (int) ($def['is_searchable'] ?? 0) === 1,
            'in_grid'        => (int) ($def['show_in_grid'] ?? 0) === 1,
            'editable_roles' => field_editable_roles($def),
            'sort_order'     => (int) ($def['sort_order'] ?? 0),
            'active'         => (int) ($def['is_active'] ?? 0) === 1,
            'usage'          => $usage[$id] ?? 0,
        ];
    }

    return $out;
}

function panel_settings_fields_payload(PDO $pdo): array
{
    $types = [];
    foreach (field_types() as $value => $meta) {
        $types[] = [
            'value' => (string) $value,
            'label' => (string) ($meta['label'] ?? $value),
            'input' => (string) ($meta['input'] ?? 'text'),
        ];
    }

    return [
        'fields' => panel_settings_field_rows($pdo),
        'types'  => $types,
        'roles'  => array_keys(SESSION_ROLES),
    ];
}

/** Definition row => the input array `field_normalize_payload()` expects. */
function panel_settings_field_input(array $row): array
{
    return [
        'field_key'      => (string) ($row['field_key'] ?? ''),
        'label'          => (string) ($row['label'] ?? ''),
        'label_i18n'     => (array) (json_decode((string) ($row['label_i18n'] ?? ''), true) ?: []),
        'data_type'      => (string) ($row['data_type'] ?? 'text'),
        'options'        => field_options($row),
        'default_value'  => (string) ($row['default_value'] ?? ''),
        'validation'     => field_rules($row),
        'is_required'    => (int) ($row['is_required'] ?? 0) === 1,
        'is_unique'      => (int) ($row['is_unique'] ?? 0) === 1,
        'is_searchable'  => (int) ($row['is_searchable'] ?? 0) === 1,
        'show_in_grid'   => (int) ($row['show_in_grid'] ?? 0) === 1,
        'editable_roles' => field_editable_roles($row),
        'sort_order'     => (int) ($row['sort_order'] ?? 0),
        'is_active'      => (int) ($row['is_active'] ?? 1) === 1,
    ];
}

/**
 * Overlay a JSON body on that input shape.
 *
 * Only keys the model understands are copied, so a newer SPA sending extra
 * fields cannot break an older server.
 *
 * @param array<string, mixed> $input
 * @param array<string, mixed> $body
 * @return array<string, mixed>
 */
function panel_settings_field_apply(array $input, array $body): array
{
    $map = [
        'key'            => 'field_key',
        'label'          => 'label',
        'label_i18n'     => 'label_i18n',
        'type'           => 'data_type',
        'options'        => 'options',
        'default'        => 'default_value',
        'validation'     => 'validation',
        'required'       => 'is_required',
        'unique'         => 'is_unique',
        'searchable'     => 'is_searchable',
        'in_grid'        => 'show_in_grid',
        'editable_roles' => 'editable_roles',
        'sort_order'     => 'sort_order',
        'active'         => 'is_active',
    ];
    foreach ($body as $key => $value) {
        $target = $map[(string) $key] ?? null;
        if ($target !== null) {
            $input[$target] = $value;
        }
    }

    return $input;
}

/** GET /settings/fields */
function panel_settings_fields_index(array $params = []): array
{
    panel_settings_user();
    return ['data' => panel_settings_fields_payload(db())];
}

/** POST /settings/fields */
function panel_settings_fields_store(array $params = []): array
{
    $user = panel_settings_writer();
    $pdo = db();
    $input = panel_settings_field_apply(panel_settings_field_input([]), panel_settings_body());
    $payload = field_normalize_payload($input);

    $errors = field_validate($pdo, $payload);
    if ($errors !== []) {
        throw new ApiError('validation_failed', 'The field could not be saved.', 422, $errors);
    }

    $id = field_create($pdo, $input, (string) session_user_email());

    panel_settings_audit($pdo, $user, 'create', 'field', (string) $id, (string) $input['label'], [
        'field_key' => [null, (string) $input['field_key']],
        'label'     => [null, (string) $input['label']],
        'data_type' => [null, (string) $input['data_type']],
    ]);

    return ['data' => panel_settings_fields_payload($pdo), 'status' => 201, 'meta' => ['id' => $id]];
}

/** PATCH /settings/fields/{id} */
function panel_settings_fields_update(array $params = []): array
{
    $user = panel_settings_writer();
    $pdo = db();
    $id = panel_settings_id($params);
    $existing = field_def_by_id($pdo, $id);
    if ($existing === null) {
        throw new ApiError('not_found', 'No custom field with id ' . $id . '.', 404);
    }

    $input = panel_settings_field_apply(panel_settings_field_input($existing), panel_settings_body());
    $payload = field_normalize_payload($input);

    $errors = field_validate($pdo, $payload, $id);
    if ($errors !== []) {
        throw new ApiError('validation_failed', 'The field could not be saved.', 422, $errors);
    }

    field_update($pdo, $id, $input, (string) session_user_email());

    // Only scalars: `options`/`label_i18n`/`validation` are arrays and would
    // either bloat the audit row or trip the string cast inside the diff.
    $audited = ['field_key', 'label', 'data_type', 'default_value', 'editable_roles'];
    $before = [];
    $after = [];
    foreach ($audited as $key) {
        $before[$key] = (string) ($existing[$key] ?? '');
        $after[$key] = (string) ($input[$key] ?? '');
    }
    $changes = asset_event_diff($before, $after);
    if ($changes !== []) {
        panel_settings_audit($pdo, $user, 'update', 'field', (string) $id, (string) $input['label'], $changes);
    }

    return ['data' => panel_settings_fields_payload($pdo), 'meta' => ['id' => $id]];
}

/** DELETE /settings/fields/{id} — also drops every stored value. */
function panel_settings_fields_delete(array $params = []): array
{
    $user = panel_settings_writer();
    $pdo = db();
    $id = panel_settings_id($params);
    $existing = field_def_by_id($pdo, $id);
    if ($existing === null) {
        throw new ApiError('not_found', 'No custom field with id ' . $id . '.', 404);
    }

    field_delete($pdo, $id);

    panel_settings_audit(
        $pdo,
        $user,
        'delete',
        'field',
        (string) $id,
        (string) ($existing['label'] ?? $id),
        ['field_key' => [(string) ($existing['field_key'] ?? ''), null]]
    );

    return ['data' => panel_settings_fields_payload($pdo), 'meta' => ['deleted' => $id]];
}

/** POST /settings/fields/reorder — body `{"ids": [3, 1, 2]}`. */
function panel_settings_fields_reorder(array $params = []): array
{
    panel_settings_writer();
    $pdo = db();
    $ids = panel_settings_body()['ids'] ?? null;
    if (!is_array($ids) || $ids === []) {
        throw new ApiError('validation_failed', 'Expected a non-empty `ids` array.', 422, ['ids' => 'required']);
    }

    $known = [];
    foreach (field_defs($pdo, false, true) as $def) {
        $known[(int) $def['id']] = true;
    }
    foreach ($ids as $id) {
        if (!isset($known[(int) $id])) {
            throw new ApiError('validation_failed', 'Unknown field ids in the new order.', 422, ['ids' => 'not_found']);
        }
    }

    field_reorder($pdo, array_map('intval', $ids));

    return ['data' => panel_settings_fields_payload($pdo)];
}

// --- API keys ------------------------------------------------------------

/**
 * Key row => JSON. The hash never leaves the server; the prefix is enough to
 * tell two keys apart in the UI.
 *
 * @param array<string, mixed> $row
 */
function panel_settings_key_row(array $row): array
{
    $public = api_key_public($row);

    return [
        'id'              => (int) $public['id'],
        'label'           => (string) $public['label'],
        'prefix'          => (string) $public['prefix'],
        'company'         => $public['company'] === null ? null : (string) $public['company'],
        'source_slug'     => $public['source_slug'] === null ? null : (string) $public['source_slug'],
        'allowed_sources' => array_values(array_filter(explode(',', (string) ($public['allowed_sources'] ?? '')))),
        'ip_allowlist'    => (string) ($public['ip_allowlist'] ?? ''),
        'can_read'        => (int) $public['can_read'] === 1,
        'can_write'       => (int) $public['can_write'] === 1,
        'can_delete'      => (int) $public['can_delete'] === 1,
        'is_active'       => (int) $public['is_active'] === 1,
        'created_at'      => $public['created_at'] === null ? null : (string) $public['created_at'],
        'created_by'      => $public['created_by'] === null ? null : (string) $public['created_by'],
        'rotated_at'      => $public['rotated_at'] === null ? null : (string) $public['rotated_at'],
        'last_used_at'    => $public['last_used_at'] === null ? null : (string) $public['last_used_at'],
        'last_used_ip'    => $public['last_used_ip'] === null ? null : (string) $public['last_used_ip'],
    ];
}

function panel_settings_keys_payload(PDO $pdo): array
{
    return [
        'keys'    => array_map('panel_settings_key_row', api_keys_all($pdo)),
        'sources' => panel_settings_source_rows($pdo),
    ];
}

/**
 * Input array for `api_key_normalize_payload()` from a stored row.
 *
 * @param array<string, mixed> $row
 * @return array<string, mixed>
 */
function panel_settings_key_input(array $row): array
{
    return [
        'label'           => (string) ($row['label'] ?? ''),
        'company'         => (string) ($row['company'] ?? ''),
        'source_slug'     => (string) ($row['source_slug'] ?? ''),
        'allowed_sources' => (string) ($row['allowed_sources'] ?? ''),
        'ip_allowlist'    => (string) ($row['ip_allowlist'] ?? ''),
        'can_read'        => (int) ($row['can_read'] ?? 1) === 1,
        'can_write'       => (int) ($row['can_write'] ?? 1) === 1,
        'can_delete'      => (int) ($row['can_delete'] ?? 0) === 1,
        'is_active'       => (int) ($row['is_active'] ?? 1) === 1,
    ];
}

/** GET /settings/api-keys */
function panel_settings_keys_index(array $params = []): array
{
    panel_settings_user();
    return ['data' => panel_settings_keys_payload(db())];
}

/** POST /settings/api-keys — the token is returned exactly once. */
function panel_settings_keys_store(array $params = []): array
{
    $user = panel_settings_writer();
    $pdo = db();
    $payload = api_key_normalize_payload(panel_settings_body());

    $errors = api_key_validate($pdo, $payload);
    if ($errors !== []) {
        throw new ApiError('validation_failed', 'The API key could not be created.', 422, $errors);
    }

    $created = api_key_create($pdo, $payload, $user['email']);

    panel_settings_audit(
        $pdo,
        $user,
        'create',
        'api_key',
        (string) $created['id'],
        (string) $payload['label'],
        [
            'label'   => [null, (string) $payload['label']],
            'company' => [null, (string) ($payload['company'] ?? '')],
            'can_read'  => [null, (string) $payload['can_read']],
            'can_write' => [null, (string) $payload['can_write']],
            'can_delete' => [null, (string) $payload['can_delete']],
        ],
        null,
        $payload['company'] === null ? null : (string) $payload['company']
    );

    return [
        'data'   => panel_settings_keys_payload($pdo),
        'status' => 201,
        'meta'   => ['id' => $created['id'], 'token' => $created['token']],
    ];
}

/** PATCH /settings/api-keys/{id} — scopes only, the token is untouched. */
function panel_settings_keys_update(array $params = []): array
{
    $user = panel_settings_writer();
    $pdo = db();
    $id = panel_settings_id($params);
    $existing = api_key_get($pdo, $id);
    if ($existing === null) {
        throw new ApiError('not_found', 'No API key with id ' . $id . '.', 404);
    }

    $input = panel_settings_key_input($existing);
    foreach (panel_settings_body() as $key => $value) {
        if (array_key_exists((string) $key, $input)) {
            $input[(string) $key] = $value;
        }
    }
    $payload = api_key_normalize_payload($input);

    $errors = api_key_validate($pdo, $payload);
    if ($errors !== []) {
        throw new ApiError('validation_failed', 'The API key could not be saved.', 422, $errors);
    }

    api_key_update($pdo, $id, $payload);

    $audited = ['label', 'company', 'source_slug', 'allowed_sources', 'ip_allowlist', 'can_read', 'can_write', 'can_delete', 'is_active'];
    $before = [];
    $after = [];
    foreach ($audited as $key) {
        $before[$key] = (string) ($existing[$key] ?? '');
        $after[$key] = (string) ($payload[$key] ?? '');
    }
    $changes = asset_event_diff($before, $after);
    if ($changes !== []) {
        panel_settings_audit(
            $pdo,
            $user,
            'update',
            'api_key',
            (string) $id,
            (string) $payload['label'],
            $changes,
            null,
            $payload['company'] === null ? null : (string) $payload['company']
        );
    }

    return ['data' => panel_settings_keys_payload($pdo), 'meta' => ['id' => $id]];
}

/** POST /settings/api-keys/{id}/rotate — new token, same scopes. */
function panel_settings_keys_rotate(array $params = []): array
{
    $user = panel_settings_writer();
    $pdo = db();
    $id = panel_settings_id($params);
    $existing = api_key_get($pdo, $id);
    $token = api_key_rotate($pdo, $id);
    if ($token === null) {
        throw new ApiError('not_found', 'No API key with id ' . $id . '.', 404);
    }

    panel_settings_audit(
        $pdo,
        $user,
        'key_rotate',
        'api_key',
        (string) $id,
        (string) ($existing['label'] ?? $id),
        [],
        'Token rotated',
        $existing === null || $existing['company'] === null ? null : (string) $existing['company']
    );

    return ['data' => panel_settings_keys_payload($pdo), 'meta' => ['id' => $id, 'token' => $token]];
}

/** DELETE /settings/api-keys/{id} */
function panel_settings_keys_delete(array $params = []): array
{
    $user = panel_settings_writer();
    $pdo = db();
    $id = panel_settings_id($params);
    $existing = api_key_get($pdo, $id);
    if (!api_key_delete($pdo, $id)) {
        throw new ApiError('not_found', 'No API key with id ' . $id . '.', 404);
    }

    panel_settings_audit(
        $pdo,
        $user,
        'delete',
        'api_key',
        (string) $id,
        (string) ($existing['label'] ?? $id),
        ['label' => [(string) ($existing['label'] ?? ''), null]],
        null,
        $existing === null || $existing['company'] === null ? null : (string) $existing['company']
    );

    return ['data' => panel_settings_keys_payload($pdo), 'meta' => ['deleted' => $id]];
}

// --- User roles (superadmin) ---------------------------------------------

/**
 * `users` has no surrogate key in the legacy schema, so the e-mail is the
 * identity of a row — and therefore the path parameter.
 *
 * @return list<array{email: string, company: string, role: string, is_self: bool}>
 */
function panel_settings_user_rows(PDO $pdo): array
{
    if (!db_table_exists($pdo, 'users')) {
        return [];
    }
    $self = (string) session_user_email();
    $rows = [];
    foreach ($pdo->query('SELECT email, company, role FROM users')->fetchAll() as $row) {
        $email = (string) $row['email'];
        $rows[] = [
            'email'   => $email,
            'company' => (string) ($row['company'] ?? ''),
            'role'    => session_role_normalize($row['role'] ?? null),
            'is_self' => strcasecmp($email, $self) === 0,
        ];
    }
    usort($rows, static function (array $a, array $b): int {
        $rank = session_role_rank($b['role']) <=> session_role_rank($a['role']);
        return $rank !== 0 ? $rank : strcasecmp($a['email'], $b['email']);
    });

    return $rows;
}

function panel_settings_users_payload(PDO $pdo): array
{
    return [
        'users' => panel_settings_user_rows($pdo),
        'roles' => array_keys(SESSION_ROLES),
    ];
}

/** GET /settings/users */
function panel_settings_users_index(array $params = []): array
{
    panel_settings_user(true);
    return ['data' => panel_settings_users_payload(db())];
}

/** PATCH /settings/users/{email} — body `{"role": "manager"}`. */
function panel_settings_users_update(array $params = []): array
{
    $user = panel_settings_writer(true);
    $pdo = db();
    $email = trim(rawurldecode((string) ($params['email'] ?? '')));
    if ($email === '') {
        throw new ApiError('invalid_email', 'Expected an e-mail in the path.', 400);
    }

    $stmt = $pdo->prepare('SELECT email, company, role FROM users WHERE email = ?');
    $stmt->execute([$email]);
    $row = $stmt->fetch();
    if ($row === false) {
        throw new ApiError('not_found', 'No user with e-mail ' . $email . '.', 404);
    }

    $role = strtolower(trim((string) (panel_settings_body()['role'] ?? '')));
    if (!isset(SESSION_ROLES[$role])) {
        throw new ApiError('validation_failed', 'Unknown role.', 422, ['role' => 'invalid_option']);
    }

    $self = (string) session_user_email();
    if (strcasecmp($email, $self) === 0) {
        // A superadmin removing their own rights would lock the instance out of
        // user management with no way back in from the panel.
        throw new ApiError('self_role_change', 'You cannot change your own role.', 409, ['role' => 'self_role_change']);
    }

    $previousRole = session_role_normalize((string) ($row['role'] ?? ''));
    $pdo->prepare('UPDATE users SET role = ? WHERE email = ?')->execute([$role, $email]);

    panel_settings_audit(
        $pdo,
        $user,
        'role_change',
        'user',
        $email,
        $email,
        $previousRole === $role ? [] : ['role' => [$previousRole, $role]],
        null,
        (string) ($row['company'] ?? '') === '' ? null : (string) $row['company']
    );

    return ['data' => panel_settings_users_payload($pdo), 'meta' => ['email' => $email, 'role' => $role]];
}

// --- Maintenance ---------------------------------------------------------

/**
 * Schema state plus the diagnostics the legacy Maintenance tab shows. No
 * credential is included: only the database *name* and the server version.
 */
function panel_settings_maintenance_payload(): array
{
    $pdo = db_try();
    $status = migrate_status($pdo);

    $counts = [];
    if ($pdo !== null) {
        foreach (['api_keys' => 'api_keys', 'sources' => 'asset_sources', 'fields' => 'asset_field_defs'] as $label => $table) {
            if (!db_table_exists($pdo, $table)) {
                $counts[$label] = 0;
                continue;
            }
            $counts[$label] = (int) $pdo->query(sprintf('SELECT COUNT(*) FROM `%s`', $table))->fetchColumn();
        }
    }

    return [
        'migrations'  => $status,
        'diagnostics' => [
            'app_version'    => defined('APP_VERSION') ? (string) APP_VERSION : '',
            'app_env'        => app_env(),
            'php_version'    => PHP_VERSION,
            'database'       => defined('DB_NAME') ? (string) DB_NAME : '',
            'db_version'     => $pdo === null ? '' : (string) $pdo->getAttribute(PDO::ATTR_SERVER_VERSION),
            'timezone'       => app_timezone(),
            'now'            => app_date(gmdate('Y-m-d H:i:s')),
            'encryption'     => crypto_enabled(),
            'counts'         => $counts,
        ],
    ];
}

/** GET /settings/maintenance */
function panel_settings_maintenance_show(array $params = []): array
{
    panel_settings_user();
    return ['data' => panel_settings_maintenance_payload()];
}

/**
 * POST /settings/maintenance/migrate — body `{"dry_run": true}`.
 *
 * The dry run is the default: a schema change has to be asked for explicitly.
 */
function panel_settings_maintenance_migrate(array $params = []): array
{
    $user = panel_settings_writer();
    $pdo = db();
    $dryRun = !empty(panel_settings_body()['dry_run']);

    $run = migrate_run($pdo, $dryRun);
    $payload = panel_settings_maintenance_payload();

    if (!$dryRun && $run['applied'] !== []) {
        panel_settings_audit(
            $pdo,
            $user,
            'update',
            'migration',
            'schema',
            'schema',
            [],
            'Applied: ' . implode(', ', $run['applied'])
        );
    }

    return [
        'data' => $payload,
        'meta' => [
            'dry_run' => $run['dry_run'],
            'applied' => $run['applied'],
            'skipped' => $run['skipped'],
            'log'     => $run['log'],
            'ready'   => $payload['migrations']['ready'],
        ],
    ];
}

