<?php
declare(strict_types=1);

/**
 * lib/grid_layout.php — installation-wide device-grid column layout.
 *
 * The `grid` flag in `asset_column_defs()` is the *code* default: which columns
 * the device grid offers and in which order. An admin can override that once for
 * the whole installation (Settings → Columns) and optionally lock it, in which
 * case a per-user layout (`user_preferences.grid_state`) is ignored.
 *
 * Stored in one `app_settings` row (`grid.columns`) as JSON:
 *
 *   {"order": ["SN", "Hostname", …], "enabled": ["SN", …], "locked": false}
 *
 * Ids are the column keys published by `/meta` (`SN`, `files`,
 * `custom:field_key`), so the SPA needs no extra concept: it already renders
 * `meta.columns` and hides whatever has `in_grid = false`.
 */

const GRID_LAYOUT_SETTING = 'grid.columns';
const GRID_LAYOUT_MAX = 120;

/**
 * Every id an admin may put in the grid, in code order.
 *
 * `Id` is excluded (internal), everything else — including the columns shipped
 * as `grid => false` and the custom fields — can be switched on.
 *
 * @return list<string>
 */
function grid_layout_ids(?PDO $pdo): array
{
    $ids = [];
    foreach (asset_column_defs() as $column => $def) {
        if ($column !== 'Id') {
            $ids[] = (string) $column;
        }
    }
    $ids[] = 'files';
    if ($pdo !== null && db_table_exists($pdo, 'asset_field_defs')) {
        foreach (field_defs($pdo) as $def) {
            $ids[] = 'custom:' . (string) $def['field_key'];
        }
    }
    return array_values(array_unique($ids));
}

/**
 * Code defaults: the columns flagged `grid` in `asset_column_defs()` are the ones
 * the grid shows, in code order.
 *
 * `order` is still the *complete* id list (hidden columns included), because it
 * is what Settings → Columns renders and where a newly enabled column is put;
 * `enabled` is what the device grid shows.
 *
 * @return array{order: list<string>, enabled: list<string>, locked: bool}
 */
function grid_layout_defaults(?PDO $pdo): array
{
    $defs = asset_column_defs();
    $ids = grid_layout_ids($pdo);
    $grid = [];
    foreach ($ids as $id) {
        if ($id === 'files') {
            $grid[] = $id;
            continue;
        }
        if (str_starts_with($id, 'custom:')) {
            foreach ($pdo !== null ? field_defs($pdo) : [] as $def) {
                if ('custom:' . (string) $def['field_key'] === $id && (int) $def['show_in_grid'] === 1) {
                    $grid[] = $id;
                }
            }
            continue;
        }
        if (!empty($defs[$id]['grid'])) {
            $grid[] = $id;
        }
    }

    $order = $grid;
    foreach ($ids as $id) {
        if (!in_array($id, $order, true)) {
            $order[] = $id;
        }
    }

    return ['order' => $order, 'enabled' => $grid, 'locked' => false];
}

/**
 * Normalise a layout (stored JSON, request body or partial patch).
 *
 * Unknown ids are dropped, duplicates removed, and `order` always contains every
 * enabled id (an id enabled but missing from the order is appended). A `locked`
 * layout keeps working even when the caller sends only that flag.
 *
 * @param array{order?: mixed, enabled?: mixed, locked?: mixed}|null $raw
 * @return array{order: list<string>, enabled: list<string>, locked: bool}
 */
function grid_layout_normalize(mixed $raw, ?PDO $pdo): array
{
    $defaults = grid_layout_defaults($pdo);
    if (is_string($raw)) {
        $decoded = json_decode(trim($raw), true);
        $raw = is_array($decoded) ? $decoded : null;
    }
    if (!is_array($raw)) {
        return $defaults;
    }

    $available = grid_layout_ids($pdo);
    $pick = static function (mixed $value) use ($available): array {
        if (!is_array($value)) {
            return [];
        }
        $list = [];
        foreach ($value as $id) {
            if (!is_string($id)) {
                continue;
            }
            if (in_array($id, $available, true) && !in_array($id, $list, true)) {
                $list[] = $id;
            }
            if (count($list) >= GRID_LAYOUT_MAX) {
                break;
            }
        }
        return $list;
    };

    $order = $pick($raw['order'] ?? null);
    $enabled = array_key_exists('enabled', $raw) ? $pick($raw['enabled']) : $defaults['enabled'];
    foreach ($enabled as $id) {
        if (!in_array($id, $order, true)) {
            $order[] = $id;
        }
    }
    // Keep the default order for ids the caller did not mention, so a partial
    // save never silently pushes newly shipped columns to the far right.
    foreach ($defaults['order'] as $id) {
        if (!in_array($id, $order, true)) {
            $order[] = $id;
        }
    }
    foreach ($available as $id) {
        if (!in_array($id, $order, true)) {
            $order[] = $id;
        }
    }

    return [
        'order'   => $order,
        'enabled' => array_values(array_filter($enabled, static fn (string $id): bool => in_array($id, $available, true))),
        'locked'  => filter_var($raw['locked'] ?? false, FILTER_VALIDATE_BOOLEAN),
    ];
}

/** Effective layout: the stored override, or the code defaults. */
function grid_layout_get(?PDO $pdo): array
{
    if ($pdo === null) {
        return grid_layout_defaults(null);
    }
    try {
        $stored = (string) settings_get(GRID_LAYOUT_SETTING, '');
    } catch (Throwable) {
        $stored = '';
    }
    if (trim($stored) === '') {
        return grid_layout_defaults($pdo);
    }
    return grid_layout_normalize($stored, $pdo);
}

/** True when the admin put this column in the grid (replaces `def['grid']`). */
function grid_layout_enabled(string $id, array $layout): bool
{
    return in_array($id, (array) ($layout['enabled'] ?? []), true);
}

/**
 * Re-order a `/meta` column list (or the export column set) by the layout.
 *
 * Stable: ids the layout does not mention keep their relative code order at the
 * end, so a column shipped by a later version still shows up.
 *
 * @param list<array<string, mixed>> $columns each with a `key`
 * @return list<array<string, mixed>>
 */
function grid_layout_apply_order(array $columns, array $layout): array
{
    $position = [];
    foreach ((array) ($layout['order'] ?? []) as $index => $id) {
        $position[(string) $id] = (int) $index;
    }

    $indexed = [];
    foreach ($columns as $index => $column) {
        $key = (string) ($column['key'] ?? '');
        $indexed[] = [
            'sort'    => $position[$key] ?? (count($position) + $index),
            'index'   => $index,
            'column'  => $column,
        ];
    }
    usort($indexed, static fn (array $a, array $b): int => [$a['sort'], $a['index']] <=> [$b['sort'], $b['index']]);

    return array_values(array_map(static fn (array $row): array => $row['column'], $indexed));
}

/**
 * Validate and store a layout.
 *
 * Returns the field errors in the same shape the other settings endpoints use
 * (`422 validation_failed` + `errors`), so the SPA can highlight the row.
 *
 * @param array<string, mixed> $body
 * @return array{ok: bool, errors: array<string, string>, layout: array}
 */
function grid_layout_save(array $body, string $actor, ?PDO $pdo = null): array
{
    $pdo ??= db_try();
    $current = grid_layout_get($pdo);
    $available = grid_layout_ids($pdo);

    $errors = [];
    foreach (['order', 'enabled'] as $field) {
        if (!array_key_exists($field, $body)) {
            continue;
        }
        if (!is_array($body[$field])) {
            $errors[$field] = 'invalid_list';
            continue;
        }
        foreach ($body[$field] as $id) {
            if (!is_string($id)) {
                $errors[$field] = 'invalid_column';
                break;
            }
            if (!in_array($id, $available, true)) {
                $errors[$field] = 'unknown_column';
                $errors['column'] = $id;
                break;
            }
        }
        if (count((array) $body[$field]) > GRID_LAYOUT_MAX) {
            $errors[$field] = 'too_many_columns';
        }
    }
    if ($errors !== []) {
        return ['ok' => false, 'errors' => $errors, 'layout' => $current];
    }

    $layout = grid_layout_normalize([
        'order'   => $body['order'] ?? $current['order'],
        'enabled' => $body['enabled'] ?? $current['enabled'],
        'locked'  => $body['locked'] ?? $current['locked'],
    ], $pdo);

    settings_set(GRID_LAYOUT_SETTING, json_encode($layout, JSON_UNESCAPED_UNICODE), $actor);

    return ['ok' => true, 'errors' => [], 'layout' => $layout];
}
