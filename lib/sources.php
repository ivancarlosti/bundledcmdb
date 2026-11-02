<?php
declare(strict_types=1);

/**
 * lib/sources.php — asset provenance ("source") model.
 *
 * Sources are rows, not constants: ESET and the API are seeded, admins can add
 * more (Intune, Jamf, …) from Settings → Sources. `assets.source` stores the
 * slug, so the seeded slugs must never change.
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

function source_label(PDO $pdo, string $slug): string
{
    $source = source_get($pdo, $slug);
    return $source === null ? $slug : (string) $source['label'];
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

/** Inline badge HTML for the grid / asset page. */
function source_badge(PDO $pdo, ?string $slug): string
{
    $slug = (string) $slug;
    if ($slug === '') {
        return '<span class="source-badge source-badge-none">—</span>';
    }
    $source = source_get($pdo, $slug);
    $label = $source === null ? $slug : (string) $source['label'];
    $color = $source === null ? null : source_color_normalize($source['color'] ?? null);
    $style = $color === null ? '' : ' style="--source-color:' . e($color) . '"';
    return '<span class="source-badge"' . $style . '>' . e($label) . '</span>';
}
