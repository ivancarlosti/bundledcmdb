<?php
declare(strict_types=1);

/**
 * lib/companies.php — the company (tenant) registry.
 *
 * Tenancy itself is still the `company` string on `users`, `assets`, `api_keys`
 * and `asset_events`; this table only records which company names exist, so the
 * panel can create a tenant before its first user or device and can refuse to
 * delete one that is still referenced.
 *
 * There are deliberately no foreign keys. The REST API and the legacy writers
 * keep storing free-form company strings (`user_create()`,
 * `asset_normalize_input()`), so a registry row is a name, not a parent record;
 * `asset_companies()` in `lib/assets.php` remains the *derived* list used by the
 * device form and gains nothing from this table.
 */

/** True when the registry exists (migration 0023 / sql/schema.sql). */
function companies_ready(PDO $pdo): bool
{
    return db_table_exists($pdo, 'companies');
}

/**
 * Registered spelling of a company name, matched case-insensitively.
 *
 * `companies.name` uses `utf8mb4_unicode_ci`, so the comparison in SQL is the
 * same one the unique primary key enforces. Returns '' when the name is not
 * registered (or the registry does not exist yet).
 */
function company_canonical(PDO $pdo, string $name): string
{
    $name = trim($name);
    if ($name === '' || !companies_ready($pdo)) {
        return '';
    }
    $stmt = $pdo->prepare('SELECT name FROM companies WHERE name = ? LIMIT 1');
    $stmt->execute([$name]);
    $found = $stmt->fetchColumn();

    return $found === false ? '' : (string) $found;
}

/**
 * Registered names, alphabetically.
 *
 * @return list<string>
 */
function company_names(PDO $pdo): array
{
    if (!companies_ready($pdo)) {
        return [];
    }
    $rows = $pdo->query('SELECT name FROM companies ORDER BY name')->fetchAll();

    return array_map(static fn (array $row): string => (string) $row['name'], $rows);
}

/**
 * Rows that still point at a company, so a delete can explain itself and a
 * listing can show usage without a join.
 *
 * @return array{users: int, assets: int, api_keys: int}
 */
function company_usage(PDO $pdo, string $name): array
{
    $usage = ['users' => 0, 'assets' => 0, 'api_keys' => 0];
    foreach (['users' => 'users', 'assets' => 'assets', 'api_keys' => 'api_keys'] as $key => $table) {
        if (!db_table_exists($pdo, $table)) {
            continue;
        }
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM ' . $table . ' WHERE company = ?');
        $stmt->execute([$name]);
        $usage[$key] = (int) $stmt->fetchColumn();
    }

    return $usage;
}

/**
 * Registry listing for Settings → Companies.
 *
 * @return list<array{name: string, users: int, assets: int, api_keys: int, total: int}>
 */
function company_rows(PDO $pdo): array
{
    $out = [];
    foreach (company_names($pdo) as $name) {
        $usage = company_usage($pdo, $name);
        $out[] = [
            'name'     => $name,
            'users'    => $usage['users'],
            'assets'   => $usage['assets'],
            'api_keys' => $usage['api_keys'],
            'total'    => $usage['users'] + $usage['assets'] + $usage['api_keys'],
        ];
    }

    return $out;
}

/**
 * Validation code for a new company name, or `null` when it may be created.
 *
 * New names avoid `/`, `\`, `{` and `}` because `s3.prefix` expands
 * `{{company}}` into an object key: a slash would silently create an extra
 * "folder" and a brace would break the template. Existing rows are not touched
 * — this only guards the create form.
 */
function company_name_error(PDO $pdo, string $name): ?string
{
    if ($name === '') {
        return 'required';
    }
    if (mb_strlen($name) > 191) {
        return 'too_long';
    }
    if ($name === '.' || $name === '..' || preg_match('~[/\\\\{}<>%?#\x00-\x1F\x7F]~u', $name) === 1) {
        return 'invalid_name';
    }
    if (company_canonical($pdo, $name) !== '') {
        return 'duplicate';
    }

    return null;
}

/**
 * Insert one registry row and audit it.
 *
 * @return array{name: string}
 * @throws ApiError
 */
function company_create(PDO $pdo, string $name, string $actor): array
{
    $name = trim($name);
    $error = company_name_error($pdo, $name);
    if ($error !== null) {
        throw new ApiError('validation_failed', 'The company could not be created.', 422, ['name' => $error]);
    }

    $stmt = $pdo->prepare('INSERT INTO companies (name, created_at, created_by) VALUES (?, ?, ?)');
    $stmt->execute([$name, app_now_utc(), $actor]);

    asset_event_record($pdo, null, 'create', $actor, ['name' => [null, $name]], null, [
        'entity'       => 'company',
        'entity_id'    => $name,
        'entity_label' => $name,
        'company'      => $name,
    ]);

    return ['name' => $name];
}

/**
 * Delete one registry row. Refused while users, assets or API keys still point
 * at the name, so a tenant cannot become an orphaned string.
 *
 * @return array{ok: bool, error?: string, name?: string, usage?: array{users: int, assets: int, api_keys: int}}
 */
function company_delete(PDO $pdo, string $name, string $actor): array
{
    $canonical = company_canonical($pdo, $name);
    if ($canonical === '') {
        return ['ok' => false, 'error' => 'not_found'];
    }

    $usage = company_usage($pdo, $canonical);
    if (array_sum($usage) > 0) {
        return ['ok' => false, 'error' => 'in_use', 'usage' => $usage];
    }

    $pdo->prepare('DELETE FROM companies WHERE name = ?')->execute([$canonical]);

    asset_event_record($pdo, null, 'delete', $actor, ['name' => [$canonical, null]], null, [
        'entity'       => 'company',
        'entity_id'    => $canonical,
        'entity_label' => $canonical,
        'company'      => null,
    ]);

    return ['ok' => true, 'name' => $canonical];
}
