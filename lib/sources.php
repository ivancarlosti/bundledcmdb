<?php
declare(strict_types=1);

/**
 * lib/sources.php — asset provenance ("source") model.
 *
 * Sources are rows, not constants: the API and Manual sources are seeded, and
 * admins can add more (ESET, Intune, Jamf, …) from Settings → Sources.
 * `assets.source` stores the slug, so seeded slugs must never change.
 */

/** @return list<array> all sources ordered by label */
function sources_all(PDO $pdo, bool $force = false): array
{
    static $cache = null;
    if ($cache !== null && !$force) {
        return $cache;
    }
    if (!db_table_exists($pdo, 'asset_sources')) {
        return $cache = [];
    }
    return $cache = $pdo->query('SELECT * FROM asset_sources ORDER BY is_default DESC, label ASC')->fetchAll();
}

/** @return array<string, array> slug => row */
function sources_map(PDO $pdo, bool $force = false): array
{
    $map = [];
    foreach (sources_all($pdo, $force) as $row) {
        $map[(string) $row['slug']] = $row;
    }
    return $map;
}

function source_get(PDO $pdo, string $slug): ?array
{
    return sources_map($pdo)[$slug] ?? null;
}

function source_exists(PDO $pdo, string $slug): bool
{
    return isset(sources_map($pdo)[$slug]);
}

/** Slug of the default source (is_default row, then the setting, then "manual"). */
function source_default_slug(PDO $pdo): string
{
    foreach (sources_all($pdo) as $row) {
        if ((int) $row['is_default'] === 1) {
            return (string) $row['slug'];
        }
    }
    $configured = (string) settings_get('assets.default_source', 'manual');
    return source_exists($pdo, $configured) ? $configured : 'manual';
}
/** Normalise "Intune (Graph)" -> "intune_graph". */
function source_slugify(string $value): string
{
    $value = trim($value);
    if ($value === '') {
        return '';
    }
    $ascii = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
    $value = strtolower($ascii === false ? $value : $ascii);
    $value = preg_replace('/[^a-z0-9]+/', '_', $value) ?? '';
    return trim(substr($value, 0, 32), '_');
}

/** Only hex colours are accepted so they can be safely inlined in a style attribute. */
function source_color_normalize(?string $color): ?string
{
    $color = trim((string) $color);
    return preg_match('/^#[0-9a-fA-F]{3,8}$/', $color) === 1 ? $color : null;
}

/**
 * Validate a source payload.
 *
 * @return array<string, string> field => error code
 */
function source_validate(PDO $pdo, array $data, ?string $existingSlug = null): array
{
    $errors = [];
    $slug = (string) ($data['slug'] ?? '');
    $label = trim((string) ($data['label'] ?? ''));

    if ($existingSlug === null) {
        if (preg_match('/^[a-z][a-z0-9_]{1,31}$/', $slug) !== 1) {
            $errors['slug'] = 'invalid_slug';
        } elseif (source_exists($pdo, $slug)) {
            $errors['slug'] = 'duplicate';
        }
    }
    if ($label === '' || mb_strlen($label) > 64) {
        $errors['label'] = $label === '' ? 'required' : 'too_long';
    }
    $color = trim((string) ($data['color'] ?? ''));
    if ($color !== '' && source_color_normalize($color) === null) {
        $errors['color'] = 'invalid_color';
    }

    return $errors;
}

function source_create(PDO $pdo, array $data, ?string $actor = null): int
{
    $isDefault = !empty($data['is_default']);
    if ($isDefault) {
        $pdo->exec('UPDATE asset_sources SET is_default = 0');
    }
    $stmt = $pdo->prepare(
        'INSERT INTO asset_sources (slug, label, color, is_system, is_default) VALUES (?, ?, ?, 0, ?)'
    );
    $stmt->execute([
        (string) $data['slug'],
        trim((string) $data['label']),
        source_color_normalize($data['color'] ?? null),
        $isDefault ? 1 : 0,
    ]);
    $id = (int) $pdo->lastInsertId();
    sources_all($pdo, true);

    return $id;
}

function source_update(PDO $pdo, string $slug, array $data): bool
{
    $source = source_get($pdo, $slug);
    if ($source === null) {
        return false;
    }
    $isDefault = !empty($data['is_default']);
    if ($isDefault) {
        $pdo->exec('UPDATE asset_sources SET is_default = 0');
    }
    $stmt = $pdo->prepare('UPDATE asset_sources SET label = ?, color = ?, is_default = ? WHERE slug = ?');
    $stmt->execute([
        trim((string) $data['label']),
        source_color_normalize($data['color'] ?? null),
        $isDefault ? 1 : ((int) $source['is_default']),
        $slug,
    ]);
    sources_all($pdo, true);

    return true;
}

/**
 * Delete a source. System sources and sources still in use are protected.
 *
 * @return array{ok: bool, error: ?string}
 */
function source_delete(PDO $pdo, string $slug): array
{
    $source = source_get($pdo, $slug);
    if ($source === null) {
        return ['ok' => false, 'error' => 'not_found'];
    }
    if ((int) $source['is_system'] === 1) {
        return ['ok' => false, 'error' => 'system_source'];
    }
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM assets WHERE `source` = ?');
    $stmt->execute([$slug]);
    if ((int) $stmt->fetchColumn() > 0) {
        return ['ok' => false, 'error' => 'in_use'];
    }
    $pdo->prepare('DELETE FROM asset_sources WHERE slug = ?')->execute([$slug]);
    sources_all($pdo, true);

    return ['ok' => true, 'error' => null];
}

/**
 * Move every reference from one source to another.
 *
 * This is the safe predecessor to deleting an old source: assets, API-key
 * defaults and allowlists, and the configured default all follow the move. The
 * source row itself is kept so the caller can decide whether to delete it.
 *
 * @return array{ok: bool, error: ?string, assets: int, api_keys: int, default_changed: bool}
 */
function source_move(PDO $pdo, string $from, string $to, string $actor): array
{
    $result = ['ok' => false, 'error' => null, 'assets' => 0, 'api_keys' => 0, 'default_changed' => false];
    if ($from === $to) {
        $result['error'] = 'same_source';
        return $result;
    }
    if (!source_exists($pdo, $from)) {
        $result['error'] = 'source_not_found';
        return $result;
    }
    if (!source_exists($pdo, $to)) {
        $result['error'] = 'target_not_found';
        return $result;
    }

    $pdo->beginTransaction();
    try {
        $setUpdated = db_column_exists($pdo, 'assets', 'updated_at');
        $sql = 'UPDATE assets SET `source` = ?' . ($setUpdated ? ', `updated_at` = ?' : '') . ' WHERE `source` = ?';
        $params = [$to];
        if ($setUpdated) {
            $params[] = gmdate('Y-m-d H:i:s');
        }
        $params[] = $from;
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $result['assets'] = $stmt->rowCount();

        if (db_table_exists($pdo, 'api_keys')) {
            $stmt = $pdo->prepare('UPDATE api_keys SET source_slug = ? WHERE source_slug = ?');
            $stmt->execute([$to, $from]);
            $result['api_keys'] += $stmt->rowCount();

            $keys = $pdo->prepare('SELECT id, allowed_sources FROM api_keys WHERE FIND_IN_SET(?, allowed_sources)');
            $keys->execute([$from]);
            $update = $pdo->prepare('UPDATE api_keys SET allowed_sources = ? WHERE id = ?');
            foreach ($keys->fetchAll() as $row) {
                $list = array_map('trim', explode(',', (string) $row['allowed_sources']));
                $list = array_values(array_unique(array_filter(array_map(
                    static fn (string $slug): string => $slug === $from ? $to : $slug,
                    $list
                ))));
                $update->execute([implode(',', $list), (int) $row['id']]);
                $result['api_keys']++;
            }
        }

        $fromSource = source_get($pdo, $from);
        if ($fromSource !== null && (int) $fromSource['is_default'] === 1) {
            $pdo->exec('UPDATE asset_sources SET is_default = 0');
            $pdo->prepare('UPDATE asset_sources SET is_default = 1 WHERE slug = ?')->execute([$to]);
            $result['default_changed'] = true;
        }
        if ((string) settings_get('assets.default_source', 'manual') === $from) {
            settings_set('assets.default_source', $to, $actor);
            $result['default_changed'] = true;
        }

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    sources_all($pdo, true);
    $result['ok'] = true;
    return $result;
}