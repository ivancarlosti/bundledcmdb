<?php
declare(strict_types=1);

/**
 * lib/inventory.php — the Assets and Systems catalogues.
 *
 * "Asset" is an overloaded word in this codebase: `assets` is the *device* table
 * and `asset_id`/`asset_events` are device concepts. This module therefore stores
 * everything in `inventory_items`, where `kind` says which tab a row belongs to:
 *
 *   asset   a physical thing that is not a computer (television, monitor, desk)
 *   system  software or a service the company runs (Acrobat Pro, identity provider)
 *
 * Both tabs behave the same way — a list with search/status/sort/paging, a form,
 * and an assignment of **any number of people** through `inventory_item_users`.
 * `common_usage` marks an item the whole company shares (the meeting-room TV, the
 * identity provider); such an item is assigned to nobody, and
 * `inventory_assignments_sync()` enforces that inside the write transaction rather
 * than trusting the form.
 *
 * Kind-specific columns are declared in `inventory_column_defs()`: the form, the
 * grid, the sort whitelist and the validation all read that one table, so
 * `serial`/`location`/`model` never appear on a system and `version` never on an
 * asset.
 */

/** Kinds in tab order. */
function inventory_kinds(): array
{
    return ['asset', 'system'];
}

function inventory_kind_valid(string $kind): bool
{
    return in_array($kind, inventory_kinds(), true);
}

/** Capability/route prefix for a kind: `assets` or `systems`. */
function inventory_kind_prefix(string $kind): string
{
    return $kind === 'asset' ? 'assets' : 'systems';
}

/** Plural tab label key (`ui_assets` / `ui_systems`). */
function inventory_kind_label_key(string $kind): string
{
    return $kind === 'asset' ? 'ui_assets' : 'ui_systems';
}

/** Singular noun used in messages and the audit label (`asset` / `system`). */
function inventory_kind_noun(string $kind): string
{
    return $kind === 'asset' ? 'asset' : 'system';
}

/**
 * Writable catalogue columns.
 *
 * `max` is the character ceiling of the column (null for TEXT), `type` drives the
 * form control, the validation branch and the cell renderer, and `kinds` decides
 * which tab offers the field at all. Labels are English on purpose: the SPA
 * renders `col_item_*` translations and falls back to these.
 *
 * @return array<string, array{label: string, type: string, max: ?int, kinds: list<string>}>
 */
function inventory_column_defs(): array
{
    $both = ['asset', 'system'];

    return [
        'name'            => ['label' => 'Name',           'type' => 'text',   'max' => 191, 'kinds' => $both],
        'brand'           => ['label' => 'Brand',          'type' => 'text',   'max' => 191, 'kinds' => $both],
        'category'        => ['label' => 'Category',       'type' => 'category', 'max' => 64, 'kinds' => $both],
        'model'           => ['label' => 'Model',          'type' => 'text',   'max' => 191, 'kinds' => ['asset']],
        'version'         => ['label' => 'Version',        'type' => 'text',   'max' => 64,  'kinds' => ['system']],
        'serial'          => ['label' => 'Serial number',  'type' => 'text',   'max' => 191, 'kinds' => ['asset']],
        'location'        => ['label' => 'Location',       'type' => 'text',   'max' => 191, 'kinds' => ['asset']],
        'status'          => ['label' => 'Status',         'type' => 'status', 'max' => 64,  'kinds' => $both],
        'purchase_date'   => ['label' => 'Purchase date',  'type' => 'date',   'max' => null, 'kinds' => $both],
        'warranty_end'    => ['label' => 'Warranty end',   'type' => 'date',   'max' => null, 'kinds' => ['asset']],
        'license_expiry'  => ['label' => 'Licence expiry', 'type' => 'date',   'max' => null, 'kinds' => ['system']],
        'common_usage'    => ['label' => 'Common usage',   'type' => 'bool',   'max' => null, 'kinds' => $both],
        'notes'           => ['label' => 'Notes',          'type' => 'text',   'max' => null, 'kinds' => $both],
        'company'         => ['label' => 'Company',        'type' => 'text',   'max' => 191, 'kinds' => $both],
    ];
}

/**
 * Column keys a kind offers, in form order.
 *
 * @return list<string>
 */
function inventory_columns(string $kind): array
{
    $keys = [];
    foreach (inventory_column_defs() as $key => $def) {
        if (in_array($kind, $def['kinds'], true)) {
            $keys[] = $key;
        }
    }
    return $keys;
}

/** Status vocabulary — the device list's, so `status_*` and `StatusPill` are reused. */
function inventory_status_values(): array
{
    return asset_status_values();
}

/**
 * The category vocabulary of one kind.
 *
 * `inventory_items.category` stores the *name* (like `assets.source` stores a
 * slug), so the registry only controls which names a form may submit and a
 * rename has to cascade — see `inventory_category_rename()`. A database without
 * migration `2026_11_26_0026` has no table: that means "cannot verify" and every
 * category is accepted, so an unmigrated installation keeps working.
 *
 * @return list<array{id: int, kind: string, name: string, sort_order: int}>
 */
function inventory_categories(PDO $pdo, string $kind, bool $force = false): array
{
    static $cache = [];
    if (isset($cache[$kind]) && !$force) {
        return $cache[$kind];
    }
    if (!db_table_exists($pdo, 'inventory_categories')) {
        return $cache[$kind] = [];
    }
    $stmt = $pdo->prepare(
        'SELECT id, kind, name, sort_order FROM inventory_categories WHERE kind = ? ORDER BY sort_order, name'
    );
    $stmt->execute([$kind]);

    $rows = [];
    foreach ($stmt->fetchAll() ?: [] as $row) {
        $rows[] = [
            'id'         => (int) $row['id'],
            'kind'       => (string) $row['kind'],
            'name'       => (string) $row['name'],
            'sort_order' => (int) $row['sort_order'],
        ];
    }

    return $cache[$kind] = $rows;
}

/**
 * Category names the form offers and the validator accepts.
 *
 * @return list<string>
 */
function inventory_category_names(PDO $pdo, string $kind, bool $force = false): array
{
    return array_map(static fn (array $row): string => $row['name'], inventory_categories($pdo, $kind, $force));
}

/** One registry row by id (null when the table or the row is missing). */
function inventory_category_get(PDO $pdo, int $id): ?array
{
    if (!db_table_exists($pdo, 'inventory_categories')) {
        return null;
    }
    $stmt = $pdo->prepare('SELECT id, kind, name, sort_order FROM inventory_categories WHERE id = ?');
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    if (!is_array($row)) {
        return null;
    }

    return [
        'id'         => (int) $row['id'],
        'kind'       => (string) $row['kind'],
        'name'       => (string) $row['name'],
        'sort_order' => (int) $row['sort_order'],
    ];
}

/** Items of that kind still pointing at the name (the delete guard's number). */
function inventory_category_usage(PDO $pdo, string $kind, string $name): int
{
    if (!db_table_exists($pdo, 'inventory_items')) {
        return 0;
    }
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM inventory_items WHERE kind = ? AND category = ?');
    $stmt->execute([$kind, $name]);

    return (int) $stmt->fetchColumn();
}

/** Append a category to the end of its kind's list. Returns the new id. */
function inventory_category_create(PDO $pdo, string $kind, string $name, ?string $actor = null): int
{
    $last = $pdo->prepare('SELECT COALESCE(MAX(sort_order), 0) FROM inventory_categories WHERE kind = ?');
    $last->execute([$kind]);
    $sortOrder = (int) $last->fetchColumn() + 10;

    $stmt = $pdo->prepare(
        'INSERT INTO inventory_categories (kind, name, sort_order, created_at, created_by) VALUES (?, ?, ?, ?, ?)'
    );
    $stmt->execute([$kind, $name, $sortOrder, gmdate('Y-m-d H:i:s'), $actor]);
    $id = (int) $pdo->lastInsertId();
    inventory_categories($pdo, $kind, true);

    return $id;
}

/**
 * Rename a registry row and carry every item with it.
 *
 * The registry names the category, so a registry-only rename would make every
 * stored item invalid. Both writes share one transaction: a half-applied rename
 * would leave a name no form accepts.
 *
 * @param array{id: int, kind: string, name: string} $row
 * @return int items moved to the new name
 */
function inventory_category_rename(PDO $pdo, array $row, string $name, ?string $actor = null): int
{
    $kind = (string) $row['kind'];
    $previous = (string) $row['name'];
    if ($name === $previous) {
        return 0;
    }

    $moved = 0;
    $pdo->beginTransaction();
    try {
        if (db_table_exists($pdo, 'inventory_items')) {
            // `updated_at` moves with the name: the item genuinely changed.
            $update = $pdo->prepare(
                'UPDATE inventory_items SET category = ?, updated_at = ?, updated_by = ?
                  WHERE kind = ? AND category = ?'
            );
            $update->execute([$name, gmdate('Y-m-d H:i:s'), $actor, $kind, $previous]);
            $moved = $update->rowCount();
        }
        $pdo->prepare('UPDATE inventory_categories SET name = ? WHERE id = ?')->execute([$name, (int) $row['id']]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    inventory_categories($pdo, $kind, true);

    return $moved;
}

/**
 * Delete a registry row, optionally draining its items to another category.
 *
 * Callers check the two refusals (`in_use` without a target, `last_category`)
 * before calling, because both are policy; the model only moves rows.
 *
 * @param array{id: int, kind: string, name: string} $row
 * @return int items reassigned
 */
function inventory_category_delete(PDO $pdo, array $row, ?string $reassignTo = null, ?string $actor = null): int
{
    $kind = (string) $row['kind'];
    $name = (string) $row['name'];

    $moved = 0;
    $pdo->beginTransaction();
    try {
        if ($reassignTo !== null && db_table_exists($pdo, 'inventory_items')) {
            $update = $pdo->prepare(
                'UPDATE inventory_items SET category = ?, updated_at = ?, updated_by = ?
                  WHERE kind = ? AND category = ?'
            );
            $update->execute([$reassignTo, gmdate('Y-m-d H:i:s'), $actor, $kind, $name]);
            $moved = $update->rowCount();
        }
        $pdo->prepare('DELETE FROM inventory_categories WHERE id = ?')->execute([(int) $row['id']]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    inventory_categories($pdo, $kind, true);

    return $moved;
}

/**
 * Wire representation of one inventory row.
 *
 * Keys are the column names themselves (already snake_case), so the SPA needs no
 * column mapping table for this feature. `users` is the assignment list, always
 * a list (empty for common usage), and `common_usage` is a real boolean.
 *
 * @param array<string, mixed> $row
 * @param list<string> $users
 */
function inventory_payload(array $row, array $users = []): array
{
    $out = [
        'id'   => (int) ($row['id'] ?? 0),
        'kind' => (string) ($row['kind'] ?? ''),
    ];

    foreach (inventory_column_defs() as $key => $def) {
        $value = $row[$key] ?? null;
        $out[$key] = match ($def['type']) {
            'bool'  => (int) $value === 1,
            'date'  => $value === null || $value === '' ? null : substr((string) $value, 0, 10),
            default => $value === null || $value === '' ? null : (string) $value,
        };
    }

    $out['users']      = array_values($users);
    $out['created_at'] = asset_iso_datetime(isset($row['created_at']) ? (string) $row['created_at'] : null);
    $out['updated_at'] = asset_iso_datetime(isset($row['updated_at']) ? (string) $row['updated_at'] : null);
    $out['created_by'] = $row['created_by'] ?? null;
    $out['updated_by'] = $row['updated_by'] ?? null;

    return $out;
}


/** `YYYY-MM-DD` and a real calendar day, or false. */
function inventory_date_valid(string $value): bool
{
    if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $parts) !== 1) {
        return false;
    }
    return checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1]);
}

/**
 * Normalise a request body into catalogue columns.
 *
 * Unknown keys and columns of the *other* kind are reported instead of ignored:
 * silently dropping `version` from an asset payload would look like a save that
 * worked. `users` is pulled out separately because it is not a column, and
 * `expected_updated_at` is the optimistic-lock value the handler consumes.
 *
 * @return array{data: array<string, mixed>, users: ?list<string>, users_provided: bool, errors: array<string, string>}
 */
function inventory_normalize_input(array $body, string $kind): array
{
    $defs = inventory_column_defs();
    $allowed = inventory_columns($kind);
    $data = [];
    $errors = [];

    foreach ($body as $key => $value) {
        $key = (string) $key;
        if (in_array($key, ['users', 'expected_updated_at', 'id', 'kind'], true)) {
            continue;
        }
        if (!isset($defs[$key])) {
            $errors[$key] = 'unknown_field';
            continue;
        }
        if (!in_array($key, $allowed, true)) {
            $errors[$key] = 'not_applicable';
            continue;
        }

        if ($defs[$key]['type'] === 'bool') {
            $parsed = is_bool($value)
                ? $value
                : filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            if ($parsed === null) {
                $errors[$key] = 'invalid_boolean';
                continue;
            }
            $data[$key] = $parsed;
            continue;
        }

        if ($value === null) {
            $data[$key] = null;
            continue;
        }
        if (!is_scalar($value)) {
            $errors[$key] = 'invalid_value';
            continue;
        }
        $text = trim((string) $value);
        $data[$key] = $text === '' ? null : $text;
    }

    $users = null;
    $usersProvided = array_key_exists('users', $body);
    if ($usersProvided) {
        if (!is_array($body['users'])) {
            $errors['users'] = 'invalid_users';
        } else {
            $unique = [];
            foreach ($body['users'] as $email) {
                if (!is_string($email)) {
                    $errors['users'] = 'invalid_users';
                    continue;
                }
                $email = mb_strtolower(trim($email));
                if ($email === '') {
                    continue;
                }
                if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                    $errors['users'] = 'invalid_email';
                    continue;
                }
                $unique[$email] = true;
            }
            $users = array_keys($unique);
        }
    }

    return ['data' => $data, 'users' => $users, 'users_provided' => $usersProvided, 'errors' => $errors];
}

/**
 * Validation of a normalised payload.
 *
 * @param array{partial?: bool, company?: ?string, users?: ?list<string>, users_provided?: bool} $options
 * @return array<string, string> field => error code
 */
function inventory_validate(PDO $pdo, string $kind, array $data, array $options = []): array
{
    $partial = !empty($options['partial']);
    $company = $options['company'] ?? null;
    $users = is_array($options['users'] ?? null) ? $options['users'] : [];
    $usersProvided = !empty($options['users_provided']);
    $defs = inventory_column_defs();
    $errors = [];

    foreach ($data as $key => $value) {
        $def = $defs[$key] ?? null;
        if ($def === null || $key === 'company') {
            continue;
        }
        if ($def['type'] === 'category') {
            // A controlled list, so the two rules are absolute: the value always
            // has to be named (required on create, never clearable on update) and
            // it always has to exist in that kind's vocabulary. A database
            // without the registry table cannot be verified, so anything passes.
            if ($value === null) {
                $errors[$key] = 'required';
                continue;
            }
            if ($def['max'] !== null && mb_strlen((string) $value) > $def['max']) {
                $errors[$key] = 'too_long';
                continue;
            }
            if (
                db_table_exists($pdo, 'inventory_categories')
                && !in_array((string) $value, inventory_category_names($pdo, $kind), true)
            ) {
                $errors[$key] = 'invalid_option';
            }
            continue;
        }
        if ($value === null) {
            // The name is the one field that can never be cleared: it is the label
            // every list, audit row and delete dialog shows.
            if ($key === 'name') {
                $errors[$key] = 'required';
            }
            continue;
        }
        if ($def['max'] !== null && mb_strlen((string) $value) > $def['max']) {
            $errors[$key] = 'too_long';
            continue;
        }
        if ($def['type'] === 'status' && !in_array((string) $value, inventory_status_values(), true)) {
            $errors[$key] = 'invalid_option';
            continue;
        }
        if ($def['type'] === 'date' && !inventory_date_valid((string) $value)) {
            $errors[$key] = 'invalid_date';
        }
    }

    if (!$partial && !array_key_exists('name', $data)) {
        $errors['name'] = 'required';
    }
    if (!$partial && !array_key_exists('category', $data)) {
        $errors['category'] = 'required';
    }

    if ($usersProvided) {
        $commonUsage = ($data['common_usage'] ?? null) === true;
        if ($commonUsage && $users !== []) {
            // Common usage means "the whole company", so naming individuals is a
            // contradiction, not something to silently discard.
            $errors['users'] = 'common_usage_conflict';
        } elseif ($users !== [] && $company !== null && $company !== '') {
            foreach ($users as $email) {
                if (!inventory_user_exists($pdo, $email, $company)) {
                    $errors['users'] = 'unknown_user';
                    break;
                }
            }
        }
    }

    return $errors;
}

/**
 * True when the e-mail belongs to the company roster.
 *
 * A database without the `users` table (unit fixtures, a half-installed copy) is
 * treated as "cannot verify" and allowed through: refusing every assignment would
 * make the feature unusable there, and the roster is not a security boundary —
 * the item's company scope already is.
 */
function inventory_user_exists(PDO $pdo, string $email, string $company): bool
{
    if (!db_table_exists($pdo, 'users')) {
        return true;
    }
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM users WHERE LOWER(email) = ? AND company = ?');
    $stmt->execute([mb_strtolower($email), $company]);

    return (int) $stmt->fetchColumn() > 0;
}


/**
 * Assigned e-mails of one item, sorted.
 *
 * @return list<string>
 */
function inventory_user_emails(PDO $pdo, int $id): array
{
    if (!db_table_exists($pdo, 'inventory_item_users')) {
        return [];
    }
    $stmt = $pdo->prepare('SELECT email FROM inventory_item_users WHERE item_id = ? ORDER BY email');
    $stmt->execute([$id]);

    return array_values(array_map(
        static fn (array $row): string => (string) $row['email'],
        $stmt->fetchAll() ?: []
    ));
}

/**
 * Assignment lists for a page of items in one query.
 *
 * @param list<int> $ids
 * @return array<int, list<string>>
 */
function inventory_users_map(PDO $pdo, array $ids): array
{
    $ids = array_values(array_filter(array_map('intval', $ids)));
    if ($ids === [] || !db_table_exists($pdo, 'inventory_item_users')) {
        return [];
    }

    $map = [];
    foreach (array_chunk($ids, 500) as $chunk) {
        $placeholders = implode(',', array_fill(0, count($chunk), '?'));
        $stmt = $pdo->prepare(
            "SELECT item_id, email FROM inventory_item_users
              WHERE item_id IN ({$placeholders})
              ORDER BY item_id, email"
        );
        $stmt->execute($chunk);
        foreach ($stmt->fetchAll() ?: [] as $row) {
            $map[(int) $row['item_id']][] = (string) $row['email'];
        }
    }
    ksort($map);

    return $map;
}

/**
 * Replace the assignment set of one item.
 *
 * `$commonUsage` wins: the rows are dropped and `$emails` is ignored, so no code
 * path can leave a shared item pointing at individuals. Callers pass the flag of
 * the row they are about to write.
 *
 * @param list<string> $emails
 * @return array{before: list<string>, after: list<string>}
 */
function inventory_assignments_sync(PDO $pdo, int $id, array $emails, string $actor, bool $commonUsage): array
{
    $before = inventory_user_emails($pdo, $id);
    $after = $commonUsage ? [] : array_values(array_unique(array_map('strval', $emails)));
    sort($after);

    if ($before === $after) {
        return ['before' => $before, 'after' => $after];
    }

    $pdo->prepare('DELETE FROM inventory_item_users WHERE item_id = ?')->execute([$id]);
    if ($after !== []) {
        $stmt = $pdo->prepare(
            'INSERT IGNORE INTO inventory_item_users (item_id, email, assigned_at, assigned_by) VALUES (?, ?, ?, ?)'
        );
        $now = gmdate('Y-m-d H:i:s');
        foreach ($after as $email) {
            $stmt->execute([$id, $email, $now, $actor]);
        }
    }

    return ['before' => $before, 'after' => $after];
}


/**
 * Keys `sort` accepts for either kind.
 *
 * @return list<string>
 */
function inventory_sort_columns(): array
{
    $keys = array_keys(inventory_column_defs());
    $keys[] = 'created_at';
    $keys[] = 'updated_at';

    return array_values(array_unique($keys));
}

/**
 * WHERE clause of the list, honouring the caller's company scope.
 *
 * @param array{email: string, company: string, role: string} $user
 * @param array<string, mixed> $filters
 * @return array{sql: string, bind: list<mixed>}
 */
function inventory_where(string $kind, array $user, array $filters): array
{
    $where = ['`kind` = ?'];
    $bind = [$kind];

    // Same rule as the device grid: a company-bound account can never widen the
    // scope, only an unrestricted (superadmin) one may pick a company.
    $company = (string) ($user['company'] ?? '') !== ''
        ? (string) $user['company']
        : (string) ($filters['company'] ?? '');
    if ($company !== '') {
        $where[] = '`company` = ?';
        $bind[] = $company;
    }

    if ((string) ($filters['status'] ?? '') !== '') {
        $where[] = '`status` = ?';
        $bind[] = (string) $filters['status'];
    }

    if ((string) ($filters['category'] ?? '') !== '') {
        $where[] = '`category` = ?';
        $bind[] = (string) $filters['category'];
    }

    if (($filters['common_usage'] ?? null) !== null) {
        $where[] = '`common_usage` = ?';
        $bind[] = !empty($filters['common_usage']) ? 1 : 0;
    }

    $search = trim((string) ($filters['q'] ?? ''));
    if ($search !== '') {
        $like = '%' . $search . '%';
        $any = [];
        foreach (inventory_columns($kind) as $column) {
            // Free text only: a date or a boolean cannot be "searched", and the
            // company column would let a superadmin find rows by tenant name.
            $def = inventory_column_defs()[$column];
            if ($column === 'company' || !in_array($def['type'], ['text', 'status', 'category'], true)) {
                continue;
            }
            $any[] = '`' . $column . '` LIKE ?';
            $bind[] = $like;
        }
        if ($any !== []) {
            $where[] = '(' . implode(' OR ', $any) . ')';
        }
    }

    return ['sql' => implode(' AND ', $where), 'bind' => $bind];
}

/**
 * One page of items, already shaped for the API.
 *
 * The requested page is clamped to the page count, so deleting the last row of the
 * last page answers with rows instead of an empty table.
 *
 * @param array{email: string, company: string, role: string} $user
 * @param array<string, mixed> $filters page/per_page already normalised
 * @return array{rows: list<array>, total: int, page: int, pages: int}
 */
function inventory_list(PDO $pdo, string $kind, array $user, array $filters, string $orderSql): array
{
    $filter = inventory_where($kind, $user, $filters);

    $count = $pdo->prepare('SELECT COUNT(*) FROM inventory_items WHERE ' . $filter['sql']);
    $count->execute($filter['bind']);
    $total = (int) $count->fetchColumn();

    $perPage = max(1, (int) ($filters['per_page'] ?? 25));
    $pages = max(1, (int) ceil($total / $perPage));
    $page = min(max(1, (int) ($filters['page'] ?? 1)), $pages);
    $offset = ($page - 1) * $perPage;

    $stmt = $pdo->prepare(
        'SELECT * FROM inventory_items WHERE ' . $filter['sql']
        . ' ORDER BY ' . $orderSql
        . ' LIMIT ' . $perPage . ' OFFSET ' . $offset
    );
    $stmt->execute($filter['bind']);
    $rows = $stmt->fetchAll() ?: [];

    $assigned = inventory_users_map($pdo, array_map(static fn (array $row): int => (int) $row['id'], $rows));

    $payload = [];
    foreach ($rows as $row) {
        $id = (int) $row['id'];
        $payload[] = inventory_payload($row, $assigned[$id] ?? []);
    }

    return ['rows' => $payload, 'total' => $total, 'page' => $page, 'pages' => $pages];
}

/**
 * One raw row, scoped to a company when the caller is bound to one.
 *
 * @return array<string, mixed>|null
 */
function inventory_get(PDO $pdo, int $id, string $kind, ?string $company): ?array
{
    $sql = 'SELECT * FROM inventory_items WHERE `id` = ? AND `kind` = ?';
    $bind = [$id, $kind];
    if ($company !== null && $company !== '') {
        $sql .= ' AND `company` = ?';
        $bind[] = $company;
    }

    $stmt = $pdo->prepare($sql . ' LIMIT 1');
    $stmt->execute($bind);
    $row = $stmt->fetch();

    return $row === false ? null : $row;
}

/**
 * Append one audit row for an inventory write.
 *
 * `$assetId` is null on purpose: these rows are not devices, so the device
 * history query must never return them. The entity/label/company triple is what
 * the Logs screen and the detail page read.
 *
 * @param array<string, array{0: ?string, 1: ?string}> $changes
 */
function inventory_audit(
    PDO $pdo,
    string $kind,
    int $id,
    ?string $label,
    ?string $company,
    string $action,
    string $actor,
    array $changes = []
): void {
    asset_event_record($pdo, null, $action, $actor, $changes, null, [
        'entity'       => $kind,
        'entity_id'    => (string) $id,
        'entity_label' => $label,
        'company'      => $company,
    ]);
}


/**
 * Row values for a write, restricted to the columns the payload actually sent.
 *
 * Missing columns are the caller's business: create fills them with their column
 * default, update keeps the stored value (a PATCH must not blank a field it never
 * mentioned).
 *
 * @param array<string, mixed> $data normalised payload
 * @return array<string, mixed>
 */
function inventory_row_values(string $kind, array $data): array
{
    $defs = inventory_column_defs();
    $row = [];
    foreach (inventory_columns($kind) as $column) {
        if ($column === 'company' || !array_key_exists($column, $data)) {
            continue;
        }
        $value = $data[$column];
        $row[$column] = $defs[$column]['type'] === 'bool' ? (!empty($value) ? 1 : 0) : $value;
    }

    return $row;
}

/**
 * Content-only difference between two rows, for the audit trail.
 *
 * Bookkeeping columns (`id`, timestamps, actor) are excluded here rather than
 * relying on `ASSET_EVENT_IGNORED_COLUMNS`, whose names are the legacy
 * PascalCase device columns.
 *
 * @param array<string, mixed> $before
 * @param array<string, mixed> $after
 * @return array<string, array{0: ?string, 1: ?string}>
 */
function inventory_diff(string $kind, array $before, array $after): array
{
    $columns = array_values(array_diff(inventory_columns($kind), ['company']));
    $keys = array_flip($columns);

    return asset_event_diff(array_intersect_key($before, $keys), array_intersect_key($after, $keys));
}

/**
 * Insert one item and its assignments. The caller owns the transaction.
 *
 * @param array<string, mixed> $data normalised payload
 * @param list<string> $users
 * @return array{id: int, changes: array<string, array{0: ?string, 1: ?string}>}
 */
function inventory_create(PDO $pdo, string $kind, array $data, string $company, array $users, string $actor): array
{
    $row = inventory_row_values($kind, $data);

    // Columns the payload omitted take the column default, so the created row is
    // complete before it is written (and before it is diffed for the audit log).
    $defs = inventory_column_defs();
    foreach (inventory_columns($kind) as $column) {
        if ($column === 'company' || array_key_exists($column, $row)) {
            continue;
        }
        $row[$column] = $defs[$column]['type'] === 'bool' ? 0 : null;
    }
    $row['status'] ??= inventory_status_values()[0];

    $now = gmdate('Y-m-d H:i:s');
    $row['kind'] = $kind;
    $row['company'] = $company;
    $row['created_at'] = $now;
    $row['created_by'] = $actor;
    $row['updated_at'] = $now;
    $row['updated_by'] = $actor;

    $columns = array_keys($row);
    $sql = 'INSERT INTO inventory_items (`' . implode('`, `', $columns) . '`)'
        . ' VALUES (' . implode(', ', array_fill(0, count($columns), '?')) . ')';
    $pdo->prepare($sql)->execute(array_values($row));
    $id = (int) $pdo->lastInsertId();

    inventory_assignments_sync($pdo, $id, $users, $actor, (int) $row['common_usage'] === 1);

    return ['id' => $id, 'changes' => inventory_diff($kind, [], $row)];
}

/**
 * Update one item (partial) and its assignments.
 *
 * `$users` is only applied when `$usersProvided` is true, so a PATCH that renames
 * an item does not silently clear its roster. Common usage always clears it.
 *
 * @param array<string, mixed> $current raw row
 * @param array<string, mixed> $data normalised payload
 * @param list<string> $users
 * @return array{row: array<string, mixed>, changes: array<string, array{0: ?string, 1: ?string}>, assignments: array{before: list<string>, after: list<string>}}
 */
function inventory_update(
    PDO $pdo,
    string $kind,
    array $current,
    array $data,
    array $users,
    bool $usersProvided,
    string $actor
): array {
    $provided = inventory_row_values($kind, $data);
    // A column the payload did not mention keeps its stored value.
    $values = [];
    foreach (inventory_columns($kind) as $column) {
        if ($column === 'company') {
            continue;
        }
        $values[$column] = array_key_exists($column, $provided) ? $provided[$column] : ($current[$column] ?? null);
    }

    $assignments = inventory_assignments_sync(
        $pdo,
        (int) $current['id'],
        $usersProvided ? $users : inventory_user_emails($pdo, (int) $current['id']),
        $actor,
        (int) ($values['common_usage'] ?? 0) === 1
    );

    $now = gmdate('Y-m-d H:i:s');
    $set = [];
    $bind = [];
    // Only the columns the payload carried are written back; `updated_at` always
    // moves so the optimistic lock keeps working.
    foreach (array_keys($provided) as $column) {
        $set[] = '`' . $column . '` = ?';
        $bind[] = $provided[$column];
    }
    $set[] = '`updated_at` = ?';
    $bind[] = $now;
    $set[] = '`updated_by` = ?';
    $bind[] = $actor;
    $bind[] = (int) $current['id'];

    $pdo->prepare('UPDATE inventory_items SET ' . implode(', ', $set) . ' WHERE `id` = ?')->execute($bind);

    $after = $values;
    $after['updated_at'] = $now;
    $after['updated_by'] = $actor;

    return [
        'row'         => $after,
        'changes'     => inventory_diff($kind, $current, $after),
        'assignments' => $assignments,
    ];
}

/** Hard delete of one item and its assignment rows. The caller owns the transaction. */
function inventory_delete(PDO $pdo, int $id): void
{
    if (db_table_exists($pdo, 'inventory_item_users')) {
        $pdo->prepare('DELETE FROM inventory_item_users WHERE item_id = ?')->execute([$id]);
    }
    $pdo->prepare('DELETE FROM inventory_items WHERE id = ?')->execute([$id]);
}

