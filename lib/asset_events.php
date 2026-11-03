<?php
declare(strict_types=1);

/**
 * lib/asset_events.php — the audit trail for device writes.
 *
 * Every write in the panel goes through `lib/panel/device_writes.php`, so this is
 * the one place that has to record what happened: who, what, when, and which
 * fields moved. `public/api.php` writes go through the same model helpers, so the
 * Bearer API is covered by `asset_event_record()` calls in `lib/assets.php`'s
 * callers as well (see the `panel_devices_*` handlers).
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
 * Append one event. Never throws: an audit write must not be able to fail a
 * device write that already succeeded (a missing table on an un-migrated
 * database is the realistic case, not corruption).
 *
 * @param array<string, array{0: ?string, 1: ?string}> $changes
 */
function asset_event_record(
    PDO $pdo,
    ?int $assetId,
    string $action,
    string $actor,
    array $changes = [],
    ?string $note = null
): void {
    if (!in_array($action, ASSET_EVENT_ACTIONS, true) || !asset_events_ready($pdo)) {
        return;
    }

    try {
        $stmt = $pdo->prepare(
            'INSERT INTO asset_events (asset_id, action, actor, changes, note, created_at)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $assetId,
            $action,
            trim($actor),
            $changes === [] ? null : json_encode($changes, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            $note === null || $note === '' ? null : $note,
            gmdate('Y-m-d H:i:s'),
        ]);
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

    $events = [];
    foreach ($stmt->fetchAll() ?: [] as $row) {
        $decoded = json_decode((string) ($row['changes'] ?? ''), true);
        $events[] = [
            'id'         => (int) $row['id'],
            'asset_id'   => $row['asset_id'] === null ? null : (int) $row['asset_id'],
            'action'     => (string) $row['action'],
            'actor'      => (string) $row['actor'],
            'changes'    => is_array($decoded) ? $decoded : [],
            'note'       => $row['note'] === null ? null : (string) $row['note'],
            // Stored in UTC, published as ISO-8601 like every other timestamp.
            'created_at' => asset_iso_datetime((string) $row['created_at']) ?? '',
        ];
    }
    return $events;
}
