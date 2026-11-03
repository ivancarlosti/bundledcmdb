<?php
declare(strict_types=1);

/**
 * lib/asset_events.php — the audit trail.
 *
 * Every write in the panel goes through `lib/panel/device_writes.php`, so this is
 * the one place that has to record what happened: who, what, when, and which
 * fields moved. `public/api.php` writes go through the same model helpers, so the
 * Bearer API is covered by `asset_event_record()` calls in `lib/assets.php`'s
 * callers as well (see the `panel_devices_*` handlers).
 *
 * Since migration `2026_11_05_0012_audit_entities` the table is also the audit
 * log behind the Logs screen: `entity`/`entity_id`/`entity_label` describe *what*
 * was touched (`device`, `setting`, `source`, `api_key`, …) and `company` makes
 * the rows scoped like everything else. Device rows keep `asset_id` populated and
 * the device History card reads them through `asset_events_for_asset()`.
 *
 * The table is deliberately append-only and denormalised: `asset_id` is not a
 * foreign key, so the history of a hard-deleted device survives the delete (which
 * is exactly when somebody asks for it).
 */

/** Actions worth recording. Kept short: they are stored in a VARCHAR(32). */
const ASSET_EVENT_ACTIONS = [
    'create',
    'update',
    'delete',
    'restore',
    'purge',
    'file_add',
    'file_remove',
    'role_change',
    'key_rotate',
    'log_purge',
];

/** Entity types the Logs screen can filter on (a VARCHAR(32) in the table). */
const ASSET_EVENT_ENTITIES = [
    'device',
    'setting',
    'source',
    'field',
    'api_key',
    'user',
    'migration',
    'logs',
];

/** Columns that never count as a "change" (bookkeeping, not content). */
const ASSET_EVENT_IGNORED_COLUMNS = ['Id', 'updated_at', 'updated_by', 'created_at', 'created_by'];

/**
 * Field-level difference between two device rows.
 *
 * Only keys present in `$after` are considered, values are compared as strings
 * (`null` and `''` are the same thing here) and bookkeeping columns are skipped,
 * so an update that only touched `updated_at` produces an empty diff.
 *
 * @param array<string, mixed> $before
 * @param array<string, mixed> $after
 * @return array<string, array{0: ?string, 1: ?string}> field => [old, new]
 */
function asset_event_diff(array $before, array $after): array
{
    $changes = [];
    foreach ($after as $column => $newValue) {
        if (in_array((string) $column, ASSET_EVENT_IGNORED_COLUMNS, true)) {
            continue;
        }

        $old = $before[$column] ?? null;
        $oldText = $old === null ? null : (string) $old;
        $newText = $newValue === null ? null : (string) $newValue;

        if ($oldText === $newText || ($oldText === '' && $newText === null) || ($oldText === null && $newText === '')) {
            continue;
        }

        $changes[(string) $column] = [$oldText, $newText];
    }
    return $changes;
}

/** True when the audit table exists (0010 applied). */
function asset_events_ready(PDO $pdo): bool
{
    static $ready = null;
    if ($ready !== null) {
        return $ready;
    }
    return $ready = db_table_exists($pdo, 'asset_events');
}

/**
 * True when the entity columns from `0012_audit_entities` are present.
 *
 * Cached per request: `asset_event_record()` is called on every write and an
 * information_schema probe there would be pure overhead. An installation that
 * has not run the migration keeps auditing device rows through the old column
 * set instead of failing every write.
 */
function asset_events_extended(PDO $pdo): bool
{
    static $extended = null;
    if ($extended !== null) {
        return $extended;
    }
    return $extended = asset_events_ready($pdo) && db_column_exists($pdo, 'asset_events', 'entity');
}

/**
 * Audit metadata for a device event.
 *
 * The company comes from the row itself (or the explicit argument when the row
 * is already gone), not from the signed-in user: a superadmin writing into
 * another tenant must not file the event under "global". `entity_label` is the
 * serial number — falling back to the UUID — denormalised because the Logs
 * screen still has to name a device that was hard-deleted five minutes ago.
 *
 * @param array<string, mixed> $row
 * @return array{company: ?string, entity: string, entity_id: ?string, entity_label: ?string}
 */
function asset_event_device_meta(array $row, ?int $id = null, ?string $company = null): array
{
    $id ??= isset($row['Id']) ? (int) $row['Id'] : null;

    $label = trim((string) ($row['SN'] ?? ''));
    if ($label === '') {
        $label = trim((string) ($row['UUID'] ?? ''));
    }
    $company ??= (string) ($row['company'] ?? '');
    $company = trim($company);

    return [
        'company'      => $company === '' ? null : $company,
        'entity'       => 'device',
        'entity_id'    => $id === null ? null : (string) $id,
        'entity_label' => $label === '' ? null : $label,
    ];
}

/**
 * Append one event. Never throws: an audit write must not be able to fail a
 * device write that already succeeded (a missing table on an un-migrated
 * database is the realistic case, not corruption).
 *
 * @param array<string, array{0: ?string, 1: ?string}> $changes
 * @param array{company?: ?string, entity?: ?string, entity_id?: ?string, entity_label?: ?string} $meta
 */
function asset_event_record(
    PDO $pdo,
    ?int $assetId,
    string $action,
    string $actor,
    array $changes = [],
    ?string $note = null,
    array $meta = []
): void {
    if (!in_array($action, ASSET_EVENT_ACTIONS, true) || !asset_events_ready($pdo)) {
        return;
    }

    $columns = ['asset_id', 'action', 'actor', 'changes', 'note', 'created_at'];
    $values = [
        $assetId,
        $action,
        trim($actor),
        $changes === [] ? null : json_encode($changes, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        $note === null || $note === '' ? null : $note,
        gmdate('Y-m-d H:i:s'),
    ];

    if (asset_events_extended($pdo)) {
        // Device rows default to the device entity; everything else says what it
        // is. `entity_label` is denormalised on purpose: the Logs screen still
        // has to name a row that may have been deleted since.
        $entity = trim((string) ($meta['entity'] ?? ''));
        if ($entity === '') {
            $entity = $assetId !== null ? 'device' : 'system';
        }
        $entityId = trim((string) ($meta['entity_id'] ?? ''));
        if ($entityId === '' && $assetId !== null) {
            $entityId = (string) $assetId;
        }
        $company = trim((string) ($meta['company'] ?? ''));
        $label = trim((string) ($meta['entity_label'] ?? ''));

        // The columns are VARCHAR(n) characters; a byte-cut would split a
        // multi-byte serial number and make the insert fail at the table charset.
        $cut = static function (string $value, int $max): string {
            if ($value === '') {
                return '';
            }
            return function_exists('mb_substr') ? mb_substr($value, 0, $max) : substr($value, 0, $max);
        };

        $columns[] = 'company';
        $columns[] = 'entity';
        $columns[] = 'entity_id';
        $columns[] = 'entity_label';
        $values[] = $company === '' ? null : $cut($company, 191);
        $values[] = $cut($entity, 32);
        $values[] = $entityId === '' ? null : $cut($entityId, 64);
        $values[] = $label === '' ? null : $cut($label, 191);
    }

    try {
        $stmt = $pdo->prepare(
            'INSERT INTO asset_events (' . implode(', ', $columns) . ')
             VALUES (' . implode(', ', array_fill(0, count($columns), '?')) . ')'
        );
        $stmt->execute($values);
    } catch (Throwable $e) {
        error_log('asset_event_record failed (' . $action . '): ' . $e->getMessage());
    }
}

/**
 * Events for one device, newest first.
 *
 * @return list<array{id: int, asset_id: ?int, action: string, actor: string,
 *                    changes: array<string, array{0: ?string, 1: ?string}>, note: ?string,
 *                    created_at: string}>
 */
function asset_events_for_asset(PDO $pdo, int $assetId, int $limit = 50): array
{
    if (!asset_events_ready($pdo)) {
        return [];
    }

    $limit = max(1, min(200, $limit));
    $stmt = $pdo->prepare(
        'SELECT id, asset_id, action, actor, changes, note, created_at
         FROM asset_events WHERE asset_id = ? ORDER BY id DESC LIMIT ' . $limit
    );
    $stmt->execute([$assetId]);

    return asset_events_decode_rows($stmt->fetchAll() ?: []);
}

/**
 * Normalise raw rows into the JSON shape the API publishes.
 *
 * `changes` is stored as JSON and `created_at` as a UTC datetime, so neither may
 * reach the SPA untouched. Rows from `asset_events_for_asset()` carry no entity
 * columns; `asset_events_page()` rows do, and they are passed through when
 * present.
 *
 * @param list<array<string, mixed>> $rows
 * @return list<array<string, mixed>>
 */
function asset_events_decode_rows(array $rows): array
{
    $events = [];
    foreach ($rows as $row) {
        $decoded = json_decode((string) ($row['changes'] ?? ''), true);
        $event = [
            'id'         => (int) $row['id'],
            'asset_id'   => ($row['asset_id'] ?? null) === null ? null : (int) $row['asset_id'],
            'action'     => (string) $row['action'],
            'actor'      => (string) $row['actor'],
            'changes'    => is_array($decoded) ? $decoded : [],
            'note'       => ($row['note'] ?? null) === null ? null : (string) $row['note'],
            // Stored in UTC, published as ISO-8601 like every other timestamp.
            'created_at' => asset_iso_datetime((string) $row['created_at']) ?? '',
        ];
        foreach (['company', 'entity', 'entity_id', 'entity_label'] as $extra) {
            if (array_key_exists($extra, $row)) {
                $event[$extra] = $row[$extra] === null ? null : (string) $row[$extra];
            }
        }
        $events[] = $event;
    }
    return $events;
}

/**
 * One page of audit rows for the Logs screen.
 *
 * Company scoping mirrors the device list: an account bound to a company sees
 * its own rows (plus the `company IS NULL` rows that describe global settings
 * changes), an unbound superadmin sees everything and may narrow with
 * `company`.
 *
 * @param array{q?: string, action?: string, entity?: string, actor?: string,
 *              from?: ?string, to?: ?string, company?: ?string,
 *              company_or_global?: ?string, page?: int, per_page?: int} $filters
 * @return array{rows: list<array<string, mixed>>, total: int, page: int, per_page: int, pages: int}
 */
function asset_events_page(PDO $pdo, array $filters): array
{
    if (!asset_events_ready($pdo)) {
        return ['rows' => [], 'total' => 0, 'page' => 1, 'per_page' => 50, 'pages' => 1];
    }

    $extended = asset_events_extended($pdo);
    $select = $extended
        ? 'id, asset_id, company, entity, entity_id, entity_label, action, actor, changes, note, created_at'
        : "id, asset_id, NULL AS company, 'device' AS entity, CAST(asset_id AS CHAR) AS entity_id,
           NULL AS entity_label, action, actor, changes, note, created_at";

    $where = [];
    $bind = [];

    if ($extended) {
        $scope = trim((string) ($filters['company_or_global'] ?? ''));
        $company = trim((string) ($filters['company'] ?? ''));
        if ($scope !== '') {
            $where[] = '(company = ? OR company IS NULL)';
            $bind[] = $scope;
        } elseif ($company !== '') {
            $where[] = 'company = ?';
            $bind[] = $company;
        }

        $entity = trim((string) ($filters['entity'] ?? ''));
        if ($entity !== '') {
            $where[] = 'entity = ?';
            $bind[] = $entity;
        }
    }

    $action = trim((string) ($filters['action'] ?? ''));
    if ($action !== '') {
        $where[] = 'action = ?';
        $bind[] = $action;
    }

    $actor = trim((string) ($filters['actor'] ?? ''));
    if ($actor !== '') {
        $where[] = 'actor = ?';
        $bind[] = $actor;
    }

    $from = trim((string) ($filters['from'] ?? ''));
    if ($from !== '') {
        $where[] = 'created_at >= ?';
        $bind[] = $from;
    }
    $to = trim((string) ($filters['to'] ?? ''));
    if ($to !== '') {
        // `to` is the exclusive next-midnight boundary: the handler converts the
        // inclusive date the user picked into that value.
        $where[] = 'created_at < ?';
        $bind[] = $to;
    }

    $q = trim((string) ($filters['q'] ?? ''));
    if ($q !== '') {
        $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q) . '%';
        $searchable = $extended ? ['actor', 'entity_label', 'entity_id', 'note'] : ['actor', 'note'];
        $parts = [];
        foreach ($searchable as $column) {
            $parts[] = $column . ' LIKE ?';
            $bind[] = $like;
        }
        $where[] = '(' . implode(' OR ', $parts) . ')';
    }

    $sql = ' FROM asset_events' . ($where === [] ? '' : ' WHERE ' . implode(' AND ', $where));

    $count = $pdo->prepare('SELECT COUNT(*)' . $sql);
    $count->execute($bind);
    $total = (int) $count->fetchColumn();

    $perPage = max(1, min(200, (int) ($filters['per_page'] ?? 50)));
    $pages = max(1, (int) ceil($total / $perPage));
    $page = max(1, min($pages, (int) ($filters['page'] ?? 1)));
    $offset = ($page - 1) * $perPage;

    $stmt = $pdo->prepare('SELECT ' . $select . $sql . ' ORDER BY id DESC LIMIT ' . $perPage . ' OFFSET ' . $offset);
    $stmt->execute($bind);

    return [
        'rows'     => asset_events_decode_rows($stmt->fetchAll() ?: []),
        'total'    => $total,
        'page'     => $page,
        'per_page' => $perPage,
        'pages'    => $pages,
    ];
}

