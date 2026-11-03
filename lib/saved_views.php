<?php
declare(strict_types=1);

/**
 * lib/saved_views.php — named grid presets ("My laptops", "ESET, in repair").
 *
 * A saved view is nothing more than the grid state the URL already carries
 * (`q`, `source`, `status`, `company`, `byod`, `trashed`, `sort`, `dir`) plus the
 * column layout from `user_preferences.grid_state`. Storing that pair, keyed by
 * e-mail, is what lets the SPA restore a view in one click without inventing a
 * second state machine.
 *
 * Validation is a whitelist on purpose: the point of `saved_view_state()` is that
 * a client cannot smuggle a `per_page` of 100000 or an arbitrary `sort` into the
 * stored JSON, exactly like `gridSearch.ts` refuses to build such a URL.
 */

/** Keys a saved view may carry, and their type. */
const SAVED_VIEW_STRINGS = ['q', 'source', 'status', 'company', 'sort'];
const SAVED_VIEW_BOOLEANS = ['trashed'];
const SAVED_VIEW_LISTS = ['order', 'visible'];

/** Name limits (the column and the unique index are 64/191 chars). */
const SAVED_VIEW_NAME_MAX = 64;

/**
 * Normalise the state payload.
 *
 * Unknown keys are dropped (never an error: the SPA may be newer than the
 * server), empty strings are dropped so two equivalent views compare equal, and
 * the column layout keeps its order.
 *
 * @param array<string, mixed> $raw
 * @return array<string, mixed>
 */
function saved_view_state(array $raw): array
{
    $state = [];

    foreach (SAVED_VIEW_STRINGS as $key) {
        $value = $raw[$key] ?? null;
        if (!is_scalar($value)) {
            continue;
        }
        $value = trim((string) $value);
        if ($value !== '') {
            $state[$key] = $value;
        }
    }

    foreach (SAVED_VIEW_BOOLEANS as $key) {
        if (!empty($raw[$key])) {
            $state[$key] = true;
        }
    }

    foreach (SAVED_VIEW_LISTS as $key) {
        $value = $raw[$key] ?? null;
        if (!is_array($value)) {
            continue;
        }
        $list = [];
        foreach ($value as $item) {
            if (is_string($item) && trim($item) !== '') {
                $list[] = trim($item);
            }
        }
        if ($list !== []) {
            $state[$key] = array_values(array_unique($list));
        }
    }

    // `sort` without a direction is ambiguous; the grid's default is `asc`.
    if (isset($state['sort']) && !in_array($raw['dir'] ?? null, ['asc', 'desc'], true)) {
        $state['dir'] = 'asc';
    } elseif (in_array($raw['dir'] ?? null, ['asc', 'desc'], true) && isset($state['sort'])) {
        $state['dir'] = $raw['dir'];
    }

    return $state;
}

/** Trimmed, length-checked view name. Throws a `422` code via the caller. */
function saved_view_name(mixed $raw): ?string
{
    if (!is_string($raw)) {
        return null;
    }
    $name = trim(preg_replace('/\s+/', ' ', $raw) ?? '');
    if ($name === '' || mb_strlen($name) > SAVED_VIEW_NAME_MAX) {
        return null;
    }
    return $name;
}

/** @return list<array{id: int, name: string, state: array, created_at: ?string, updated_at: ?string}> */
function saved_views_for(PDO $pdo, string $email): array
{
    if (!db_table_exists($pdo, 'saved_views')) {
        return [];
    }
    $stmt = $pdo->prepare('SELECT id, name, state, created_at, updated_at FROM saved_views WHERE email = ? ORDER BY name ASC');
    $stmt->execute([strtolower(trim($email))]);

    $views = [];
    foreach ($stmt->fetchAll() ?: [] as $row) {
        $decoded = json_decode((string) $row['state'], true);
        $views[] = [
            'id'         => (int) $row['id'],
            'name'       => (string) $row['name'],
            'state'      => is_array($decoded) ? $decoded : [],
            'created_at' => asset_iso_datetime((string) $row['created_at']),
            'updated_at' => asset_iso_datetime((string) $row['updated_at']),
        ];
    }
    return $views;
}

/** One view of one user (404s are the caller's job). */
function saved_view_find(PDO $pdo, string $email, int $id): ?array
{
    foreach (saved_views_for($pdo, $email) as $view) {
        if ($view['id'] === $id) {
            return $view;
        }
    }
    return null;
}

/**
 * Create or rename-in-place a view.
 *
 * `INSERT ... ON DUPLICATE KEY UPDATE` on `(email, name)`: saving the same name
 * twice is the natural "overwrite this view" gesture in the toolbar, and it keeps
 * the id stable so an open tab keeps pointing at the same row.
 */
function saved_view_save(PDO $pdo, string $email, string $name, array $state, ?int $id = null): int
{
    $email = strtolower(trim($email));
    $now = gmdate('Y-m-d H:i:s');
    $json = json_encode($state, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}';

    if ($id !== null) {
        $stmt = $pdo->prepare('UPDATE saved_views SET name = ?, state = ?, updated_at = ? WHERE id = ? AND email = ?');
        $stmt->execute([$name, $json, $now, $id, $email]);
        if ($stmt->rowCount() === 0 && saved_view_find($pdo, $email, $id) === null) {
            return 0;
        }
        return $id;
    }

    $stmt = $pdo->prepare(
        'INSERT INTO saved_views (email, name, state, created_at, updated_at) VALUES (?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE state = VALUES(state), updated_at = VALUES(updated_at)'
    );
    $stmt->execute([$email, $name, $json, $now, $now]);

    $find = $pdo->prepare('SELECT id FROM saved_views WHERE email = ? AND name = ?');
    $find->execute([$email, $name]);
    $found = $find->fetchColumn();
    return $found === false ? 0 : (int) $found;
}

function saved_view_delete(PDO $pdo, string $email, int $id): bool
{
    $stmt = $pdo->prepare('DELETE FROM saved_views WHERE id = ? AND email = ?');
    $stmt->execute([$id, strtolower(trim($email))]);
    return $stmt->rowCount() > 0;
}
