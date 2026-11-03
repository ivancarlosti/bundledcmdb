<?php
declare(strict_types=1);

/**
 * lib/assets.php — asset (device) model: column metadata, validation, CRUD,
 * search/sort/paging and the API representation.
 *
 * `lib/panel/devices.php` (the device list, detail, exports and the write
 * handlers), the panel meta endpoint and `public/api.php` all use these helpers
 * instead of the copy-pasted SQL that used to live in each page.
 *
 * Column metadata keys:
 *   type        input/validation hint
 *   grid        available to the SPA grid / export by default
 *   api         writable through the REST API
 *   form_roles  roles allowed to edit it in the device form ([] = read-only)
 *   label       English fallback label
 */
function asset_column_defs(): array
{
    static $defs = null;
    if ($defs !== null) {
        return $defs;
    }

    $admins = ['admin', 'superadmin'];
    $managers = ['manager', 'admin', 'superadmin'];

    return $defs = [
        'Id'              => ['type' => 'int', 'grid' => false, 'api' => false, 'form_roles' => [], 'label' => 'Id'],
        'UUID'            => ['type' => 'text', 'grid' => false, 'api' => true, 'form_roles' => [], 'label' => 'UUID'],
        'SN'              => ['type' => 'text', 'grid' => true, 'api' => true, 'form_roles' => $managers, 'label' => 'Serial Number'],
        'OS'              => ['type' => 'text', 'grid' => true, 'api' => true, 'form_roles' => $managers, 'label' => 'Operating System'],
        'OSVersion'       => ['type' => 'text', 'grid' => false, 'api' => true, 'form_roles' => [], 'label' => 'OS Version'],
        'Hostname'        => ['type' => 'text', 'grid' => true, 'api' => true, 'form_roles' => $managers, 'label' => 'Hostname'],
        'Mobile'          => ['type' => 'bool', 'grid' => false, 'api' => true, 'form_roles' => [], 'label' => 'Mobile device'],
        'Manufacturer'    => ['type' => 'text', 'grid' => true, 'api' => true, 'form_roles' => [], 'label' => 'Manufacturer'],
        'LastSeen'        => ['type' => 'datetime', 'grid' => false, 'api' => true, 'form_roles' => [], 'label' => 'Last Seen'],
        'UserEmail'       => ['type' => 'email', 'grid' => true, 'api' => true, 'form_roles' => $managers, 'label' => 'User Email'],
        'BYOD'            => ['type' => 'bool', 'grid' => true, 'api' => true, 'form_roles' => $managers, 'label' => 'BYOD'],
        'Status'          => ['type' => 'status', 'grid' => true, 'api' => true, 'form_roles' => $managers, 'label' => 'Status'],
        'Warranty'        => ['type' => 'date', 'grid' => true, 'api' => true, 'form_roles' => $managers, 'label' => 'Warranty'],
        'Asset'           => ['type' => 'text', 'grid' => true, 'api' => true, 'form_roles' => $managers, 'label' => 'Asset'],
        'PurchaseDate'    => ['type' => 'date', 'grid' => true, 'api' => true, 'form_roles' => $managers, 'label' => 'Purchase Date'],
        'CypherID'        => ['type' => 'text', 'grid' => false, 'api' => true, 'form_roles' => $admins, 'label' => 'Cypher ID'],
        'CypherKey'       => ['type' => 'text', 'grid' => false, 'api' => true, 'form_roles' => $admins, 'label' => 'Cypher Key'],
        'Notes'           => ['type' => 'textarea', 'grid' => false, 'api' => true, 'form_roles' => $managers, 'label' => 'Notes'],
        'CPUs'            => ['type' => 'text', 'grid' => false, 'api' => true, 'form_roles' => [], 'label' => 'CPUs'],
        'HDs'             => ['type' => 'text', 'grid' => false, 'api' => true, 'form_roles' => [], 'label' => 'Disks'],
        'HDsTypes'        => ['type' => 'text', 'grid' => false, 'api' => true, 'form_roles' => [], 'label' => 'Disk Types'],
        'HDsSpacesGB'     => ['type' => 'text', 'grid' => false, 'api' => true, 'form_roles' => [], 'label' => 'Disk Space (GB)'],
        'NetworkAdapters' => ['type' => 'text', 'grid' => false, 'api' => true, 'form_roles' => [], 'label' => 'Network Adapters'],
        'MACAddresses'    => ['type' => 'text', 'grid' => false, 'api' => true, 'form_roles' => [], 'label' => 'MAC Addresses'],
        'ESETComponents'  => ['type' => 'text', 'grid' => false, 'api' => true, 'form_roles' => [], 'label' => 'ESET Components'],
        'PrimaryLocalIP'  => ['type' => 'text', 'grid' => false, 'api' => true, 'form_roles' => [], 'label' => 'Primary Local IP'],
        'PrimaryRemoteIP' => ['type' => 'text', 'grid' => false, 'api' => true, 'form_roles' => [], 'label' => 'Primary Remote IP'],
        'company'         => ['type' => 'text', 'grid' => false, 'api' => true, 'form_roles' => [], 'label' => 'Company'],
        'source'          => ['type' => 'text', 'grid' => true, 'api' => false, 'form_roles' => [], 'label' => 'Source'],
        'source_ref'      => ['type' => 'text', 'grid' => false, 'api' => false, 'form_roles' => [], 'label' => 'Source reference'],
        'created_at'      => ['type' => 'datetime', 'grid' => false, 'api' => false, 'form_roles' => [], 'label' => 'Created at'],
        'updated_at'      => ['type' => 'datetime', 'grid' => false, 'api' => false, 'form_roles' => [], 'label' => 'Updated at'],
        'created_by'      => ['type' => 'text', 'grid' => false, 'api' => false, 'form_roles' => [], 'label' => 'Created by'],
        'updated_by'      => ['type' => 'text', 'grid' => false, 'api' => false, 'form_roles' => [], 'label' => 'Updated by'],
        'deleted_at'      => ['type' => 'datetime', 'grid' => false, 'api' => false, 'form_roles' => [], 'label' => 'Deleted at'],
    ];
}
/** Columns editable inline on the grid for a role. */
function asset_grid_editable_columns(string $role): array
{
    if ($role === 'user') {
        return [];
    }
    $allowed = ['UserEmail', 'Status', 'Warranty', 'Asset', 'PurchaseDate', 'BYOD'];
    if (in_array($role, ['manager', 'admin', 'superadmin'], true)) {
        $allowed[] = 'Notes';
    }
    if (in_array($role, ['admin', 'superadmin'], true)) {
        $allowed[] = 'CypherID';
        $allowed[] = 'CypherKey';
    }
    return $allowed;
}

/** Columns the device form edits (create and PATCH), in order. */
function asset_form_columns(): array
{
    return [
        'SN', 'Hostname', 'Manufacturer', 'OS', 'OSVersion', 'Mobile', 'Asset',
        'Status', 'UserEmail', 'BYOD', 'Warranty', 'PurchaseDate', 'LastSeen',
        'UUID', 'CypherID', 'CypherKey', 'CPUs', 'HDs', 'HDsTypes', 'HDsSpacesGB',
        'NetworkAdapters', 'MACAddresses', 'ESETComponents', 'PrimaryLocalIP',
        'PrimaryRemoteIP', 'Notes',
    ];
}

/** Columns a role may fill in when creating an asset. */
function asset_create_columns(string $role): array
{
    if ($role === 'user') {
        return [];
    }
    $manager = ['SN', 'Hostname', 'Manufacturer', 'OS', 'OSVersion', 'Mobile', 'Asset',
        'Status', 'UserEmail', 'BYOD', 'Warranty', 'PurchaseDate', 'Notes'];
    if (!in_array($role, ['admin', 'superadmin'], true)) {
        return $manager;
    }
    return array_values(array_filter(
        asset_form_columns(),
        static fn (string $column): bool => $column !== 'UUID'
    ));
}
/** @return list<string> companies known to the installation */
function asset_companies(PDO $pdo): array
{
    $rows = $pdo->query('SELECT DISTINCT company FROM assets WHERE company IS NOT NULL ORDER BY company')->fetchAll();
    return array_map(static fn (array $r): string => (string) $r['company'], $rows);
}


/** DB values for the Status column (English on purpose, translated on display). */
function asset_status_values(): array
{
    return ['In Use', 'In Stock', 'In Repair', 'Decommissioned', 'Lost or Stolen'];
}

/**
 * Machine values for why a device was deleted (`assets.deleted_reason`).
 *
 * Stored as English like the status values; the panel shows them through the
 * `ui_delete_reason_*` translations, so the column is stable for reports.
 *
 * @return list<string>
 */
function asset_deletion_reasons(): array
{
    return ['duplicated', 'created_by_mistake'];
}


// --- Value normalisation / validation -----------------------------------

function asset_bool_normalize(mixed $value): string
{
    if (is_bool($value)) {
        return $value ? 'true' : 'false';
    }
    $value = strtolower(trim((string) $value));
    return in_array($value, ['1', 'true', 'yes', 'on'], true) ? 'true' : 'false';
}

function asset_bool_parse(mixed $value): bool
{
    return asset_bool_normalize($value) === 'true';
}

/** Accept ISO-8601 / "Y-m-d H:i:s" / "Y-m-d"; store "Y-m-d H:i:s" (UTC). */
function asset_datetime_normalize(mixed $value): ?string
{
    $value = trim((string) $value);
    if ($value === '') {
        return null;
    }
    try {
        $dt = new DateTimeImmutable($value, new DateTimeZone('UTC'));
    } catch (Throwable) {
        return null;
    }
    return $dt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
}

/** Store dates as "Y-m-d"; returns null for empty input. */
function asset_date_normalize(mixed $value): ?string
{
    $value = trim((string) $value);
    if ($value === '') {
        return null;
    }
    $dt = DateTimeImmutable::createFromFormat('!Y-m-d', substr($value, 0, 10));
    return $dt instanceof DateTimeImmutable ? $dt->format('Y-m-d') : null;
}

/**
 * Normalise the built-in columns of an input array.
 *
 * Only columns present in $data are touched (PATCH-friendly); unknown keys are
 * ignored here and reported by the caller.
 *
 * @return array<string, string|null>
 */
function asset_normalize_input(array $data): array
{
    $defs = asset_column_defs();
    $out = [];
    foreach ($data as $column => $value) {
        $def = $defs[$column] ?? null;
        if ($def === null || empty($def['api'])) {
            continue;
        }
        switch ($def['type']) {
            case 'bool':
                $out[$column] = asset_bool_normalize($value);
                break;
            case 'date':
                $out[$column] = asset_date_normalize($value);
                break;
            case 'datetime':
                $out[$column] = asset_datetime_normalize($value);
                break;
            default:
                $value = is_array($value) ? implode(', ', $value) : (string) $value;
                $value = trim($value);
                $out[$column] = $value === '' ? null : $value;
        }
    }
    return $out;
}

/**
 * Validate built-in columns. $partial = true for PATCH (only provided keys).
 *
 * @return array<string, string> field => error code
 */
function asset_validate(array $data, bool $partial = false): array
{
    $errors = [];

    if (!$partial && settings_bool('assets.require_sn') && trim((string) ($data['SN'] ?? '')) === '') {
        $errors['SN'] = 'required';
    }
    if (array_key_exists('SN', $data) && $data['SN'] !== null && mb_strlen((string) $data['SN']) > 191) {
        $errors['SN'] = 'too_long';
    }
    if (array_key_exists('UUID', $data) && $data['UUID'] !== null && mb_strlen((string) $data['UUID']) > 191) {
        $errors['UUID'] = 'too_long';
    }
    if (array_key_exists('UserEmail', $data) && $data['UserEmail'] !== null && $data['UserEmail'] !== ''
        && filter_var((string) $data['UserEmail'], FILTER_VALIDATE_EMAIL) === false) {
        $errors['UserEmail'] = 'invalid_email';
    }
    if (array_key_exists('Status', $data) && $data['Status'] !== null && $data['Status'] !== ''
        && !in_array((string) $data['Status'], asset_status_values(), true)) {
        $errors['Status'] = 'invalid_option';
    }
    foreach (['Warranty', 'PurchaseDate'] as $dateColumn) {
        if (array_key_exists($dateColumn, $data) && !empty($data[$dateColumn])
            && asset_date_normalize($data[$dateColumn]) === null) {
            $errors[$dateColumn] = 'invalid_date';
        }
    }
    if (array_key_exists('LastSeen', $data) && !empty($data['LastSeen'])
        && asset_datetime_normalize($data['LastSeen']) === null) {
        $errors['LastSeen'] = 'invalid_datetime';
    }
    foreach (['Hostname' => 255, 'OS' => 191, 'Manufacturer' => 191, 'Asset' => 191] as $column => $max) {
        if (array_key_exists($column, $data) && $data[$column] !== null && mb_strlen((string) $data[$column]) > $max) {
            $errors[$column] = 'too_long';
        }
    }

    return $errors;
}

// --- CRUD ---------------------------------------------------------------

/** Load one asset; returns null when not found (or not in the company). */
function asset_get(PDO $pdo, int $id, ?string $company = null, bool $includeDeleted = false): ?array
{
    $sql = 'SELECT * FROM assets WHERE `Id` = ?';
    $params = [$id];
    if ($company !== null && $company !== '') {
        $sql .= ' AND `company` = ?';
        $params[] = $company;
    }
    if (!$includeDeleted && db_column_exists($pdo, 'assets', 'deleted_at')) {
        $sql .= ' AND `deleted_at` IS NULL';
    }
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $row = $stmt->fetch();
    return $row === false ? null : $row;
}

/**
 * Find an asset by UUID and/or SN (used by upsert and duplicate checks).
 *
 * @param array{UUID?: string, SN?: string} $criteria
 */
function asset_find_by(PDO $pdo, array $criteria, ?string $company = null, bool $includeDeleted = false): ?array
{
    $where = [];
    $params = [];
    foreach (['UUID', 'SN'] as $column) {
        $value = trim((string) ($criteria[$column] ?? ''));
        if ($value !== '') {
            $where[] = "`{$column}` = ?";
            $params[] = $value;
        }
    }
    if ($where === []) {
        return null;
    }
    if ($company !== null && $company !== '') {
        $where[] = '`company` = ?';
        $params[] = $company;
    }
    if (!$includeDeleted && db_column_exists($pdo, 'assets', 'deleted_at')) {
        $where[] = '`deleted_at` IS NULL';
    }
    $stmt = $pdo->prepare('SELECT * FROM assets WHERE ' . implode(' AND ', $where) . ' LIMIT 1');
    $stmt->execute($params);
    $row = $stmt->fetch();
    return $row === false ? null : $row;
}

/** Keep only columns that exist and are known to the model. */
function asset_filter_columns(PDO $pdo, array $data): array
{
    $defs = asset_column_defs();
    $out = [];
    foreach ($data as $column => $value) {
        if ($column === 'Id' || !isset($defs[$column])) {
            continue;
        }
        if (!db_column_exists($pdo, 'assets', $column)) {
            continue;
        }
        $out[$column] = $value;
    }
    return $out;
}

/** Insert an asset and return its id. */
function asset_create(PDO $pdo, array $data, string $actor, string $source = '', ?string $sourceRef = null): int
{
    $data = asset_filter_columns($pdo, $data);
    $now = gmdate('Y-m-d H:i:s');
    $data['source'] = $source !== '' ? $source : (string) settings_get('assets.default_source', 'manual');
    if (db_column_exists($pdo, 'assets', 'source_ref')) {
        $data['source_ref'] = $sourceRef;
    }
    $data['created_at'] = $now;
    $data['updated_at'] = $now;
    $data['created_by'] = $actor;
    $data['updated_by'] = $actor;

    $columns = array_keys($data);
    $sql = 'INSERT INTO assets (`' . implode('`,`', $columns) . '`) VALUES ('
        . implode(',', array_fill(0, count($columns), '?')) . ')';
    $pdo->prepare($sql)->execute(array_values($data));
    return (int) $pdo->lastInsertId();
}

/** Update an asset; returns the number of changed rows. */
function asset_update(PDO $pdo, int $id, array $data, string $actor, ?string $company = null): int
{
    $data = asset_filter_columns($pdo, $data);
    $data['updated_at'] = gmdate('Y-m-d H:i:s');
    $data['updated_by'] = $actor;

    $set = [];
    $params = [];
    foreach ($data as $column => $value) {
        $set[] = "`{$column}` = ?";
        $params[] = $value;
    }
    $sql = 'UPDATE assets SET ' . implode(', ', $set) . ' WHERE `Id` = ?';
    $params[] = $id;
    if ($company !== null && $company !== '') {
        $sql .= ' AND `company` = ?';
        $params[] = $company;
    }
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->rowCount();
}

/**
 * Soft delete; falls back to a hard delete before the migration ran.
 *
 * `$reason` is one of `asset_deletion_reasons()` and `$duplicateOf` the asset
 * that was kept instead; both are optional (older callers and the Bearer API
 * delete without them) and both are skipped on an un-migrated database, where
 * the columns do not exist yet.
 */
function asset_soft_delete(
    PDO $pdo,
    int $id,
    string $actor,
    ?string $company = null,
    ?string $reason = null,
    ?int $duplicateOf = null
): bool {
    if (!db_column_exists($pdo, 'assets', 'deleted_at')) {
        return asset_hard_delete($pdo, $id, $company);
    }
    $now = gmdate('Y-m-d H:i:s');
    $set = ['`deleted_at` = ?', '`updated_at` = ?', '`updated_by` = ?'];
    $params = [$now, $now, $actor];
    if ($reason !== null && db_column_exists($pdo, 'assets', 'deleted_reason')) {
        $set[] = '`deleted_reason` = ?';
        $params[] = $reason;
    }
    if ($duplicateOf !== null && db_column_exists($pdo, 'assets', 'duplicate_of_id')) {
        $set[] = '`duplicate_of_id` = ?';
        $params[] = $duplicateOf;
    }
    $params[] = $id;

    $sql = 'UPDATE assets SET ' . implode(', ', $set) . ' WHERE `Id` = ?';
    if ($company !== null && $company !== '') {
        $sql .= ' AND `company` = ?';
        $params[] = $company;
    }
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->rowCount() > 0;
}

/** Restore a trashed asset; the deletion reason and the duplicate link go too. */
function asset_restore(PDO $pdo, int $id, string $actor, ?string $company = null): bool
{
    if (!db_column_exists($pdo, 'assets', 'deleted_at')) {
        return false;
    }
    $set = ['`deleted_at` = NULL', '`updated_at` = ?', '`updated_by` = ?'];
    $params = [gmdate('Y-m-d H:i:s'), $actor];
    if (db_column_exists($pdo, 'assets', 'deleted_reason')) {
        $set[] = '`deleted_reason` = NULL';
    }
    if (db_column_exists($pdo, 'assets', 'duplicate_of_id')) {
        $set[] = '`duplicate_of_id` = NULL';
    }
    $params[] = $id;

    $sql = 'UPDATE assets SET ' . implode(', ', $set) . ' WHERE `Id` = ?';
    if ($company !== null && $company !== '') {
        $sql .= ' AND `company` = ?';
        $params[] = $company;
    }
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->rowCount() > 0;
}

/** Permanent delete: custom values, attachment rows and the asset. */
function asset_hard_delete(PDO $pdo, int $id, ?string $company = null): bool
{
    $params = [$id];
    $companyClause = '';
    if ($company !== null && $company !== '') {
        $companyClause = ' AND `company` = ?';
        $params[] = $company;
    }

    if (db_table_exists($pdo, 'asset_custom_values')) {
        $pdo->prepare('DELETE FROM asset_custom_values WHERE asset_id = ?')->execute([$id]);
    }
    if (db_table_exists($pdo, 'device_files')) {
        $pdo->prepare("DELETE FROM device_files WHERE device_id = ? AND device_table = 'assets'")->execute([$id]);
    }
    if (db_column_exists($pdo, 'assets', 'duplicate_of_id')) {
        // The reference only makes sense while both rows exist. Other trashed
        // rows keep pointing nowhere rather than at a recycled AUTO_INCREMENT id,
        // and `updated_at` stays untouched: nothing about them changed.
        $pdo->prepare('UPDATE assets SET `duplicate_of_id` = NULL WHERE `duplicate_of_id` = ?')->execute([$id]);
    }
    $stmt = $pdo->prepare('DELETE FROM assets WHERE `Id` = ?' . $companyClause);
    $stmt->execute($params);
    return $stmt->rowCount() > 0;
}

/** Attachment metadata rows for one asset. */
function asset_files(PDO $pdo, int $id): array
{
    if (!db_table_exists($pdo, 'device_files')) {
        return [];
    }
    $stmt = $pdo->prepare("SELECT * FROM device_files WHERE device_id = ? AND device_table = 'assets'");
    $stmt->execute([$id]);
    return $stmt->fetchAll();
}

/** ISO-8601 UTC rendering used by the API. */
function asset_iso_datetime(?string $value): ?string
{
    $value = trim((string) $value);
    if ($value === '') {
        return null;
    }
    try {
        $dt = new DateTimeImmutable($value, new DateTimeZone('UTC'));
    } catch (Throwable) {
        return null;
    }
    return $dt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
}

/**
 * Duplicate detection: UUID first, then SN, both inside the same company.
 *
 * @return array{field: string, id: int}|null
 */
function asset_find_duplicate(PDO $pdo, array $data, ?int $excludeId, ?string $company): ?array
{
    foreach (['UUID', 'SN'] as $column) {
        $value = trim((string) ($data[$column] ?? ''));
        if ($value === '') {
            continue;
        }
        $found = asset_find_by($pdo, [$column => $value], $company);
        if ($found !== null && (int) $found['Id'] !== (int) $excludeId) {
            return ['field' => $column, 'id' => (int) $found['Id']];
        }
    }
    return null;
}

// --- List / search / sort / paging --------------------------------------

/**
 * Query assets with filtering, sorting and pagination.
 *
 * Options: company, source, search, search_field (builtin column, "any",
 * "custom:<key>"), owner_email, updated_since, include_deleted, sort
 * (builtin column or "custom:<key>"), dir, page, per_page.
 *
 * @return array{rows: list<array>, total: int, page: int, per_page: int, pages: int}
 */
function asset_list(PDO $pdo, array $opts = []): array
{
    $company = (string) ($opts['company'] ?? '');
    $source = trim((string) ($opts['source'] ?? ''));
    $search = trim((string) ($opts['search'] ?? ''));
    $searchField = trim((string) ($opts['search_field'] ?? ''));
    $owner = trim((string) ($opts['owner_email'] ?? ''));
    $updatedSince = trim((string) ($opts['updated_since'] ?? ''));
    $includeDeleted = !empty($opts['include_deleted']);

    $page = max(1, (int) ($opts['page'] ?? 1));
    $perPage = max(1, min(1000, (int) ($opts['per_page'] ?? 25)));

    $defs = asset_column_defs();
    $where = ['1 = 1'];
    $params = [];

    if ($company !== '') {
        $where[] = 'a.`company` = ?';
        $params[] = $company;
    }
    if (!$includeDeleted && db_column_exists($pdo, 'assets', 'deleted_at')) {
        $where[] = 'a.`deleted_at` IS NULL';
    }
    if ($source !== '' && db_column_exists($pdo, 'assets', 'source')) {
        $where[] = 'a.`source` = ?';
        $params[] = $source;
    }
    if ($owner !== '') {
        $where[] = 'a.`UserEmail` = ?';
        $params[] = $owner;
    }
    if ($updatedSince !== '' && db_column_exists($pdo, 'assets', 'updated_at')) {
        $where[] = 'a.`updated_at` >= ?';
        $params[] = $updatedSince;
    }

    // --- Search ---
    if ($search !== '') {
        $like = '%' . $search . '%';
        if (str_starts_with($searchField, 'custom:')) {
            $where[] = 'EXISTS (SELECT 1 FROM asset_custom_values v
                                  JOIN asset_field_defs f ON f.id = v.field_id
                                 WHERE v.asset_id = a.`Id` AND f.field_key = ? AND v.value LIKE ?)';
            $params[] = substr($searchField, 7);
            $params[] = $like;
        } elseif ($searchField !== '' && $searchField !== 'any' && isset($defs[$searchField])) {
            $where[] = 'a.`' . $searchField . '` LIKE ?';
            $params[] = $like;
        } else {
            $anyWhere = [];
            foreach ($defs as $column => $def) {
                if (!empty($def['api']) && !in_array($def['type'], ['datetime', 'date', 'int'], true)) {
                    $anyWhere[] = 'a.`' . $column . '` LIKE ?';
                    $params[] = $like;
                }
            }
            $anyWhere[] = 'EXISTS (SELECT 1 FROM asset_custom_values v
                                     JOIN asset_field_defs f ON f.id = v.field_id
                                    WHERE v.asset_id = a.`Id` AND f.is_searchable = 1 AND v.value LIKE ?)';
            $params[] = $like;
            $where[] = '(' . implode(' OR ', $anyWhere) . ')';
        }
    }

    // --- Sort ---
    $dir = strtolower((string) ($opts['dir'] ?? 'asc')) === 'desc' ? 'DESC' : 'ASC';
    $sort = trim((string) ($opts['sort'] ?? ''));
    $join = '';
    $orderBy = 'a.`Id` ' . $dir;
    if (str_starts_with($sort, 'custom:')) {
        $stmt = $pdo->prepare('SELECT id FROM asset_field_defs WHERE field_key = ?');
        $stmt->execute([substr($sort, 7)]);
        $fieldId = $stmt->fetchColumn();
        if ($fieldId !== false) {
            $join = ' LEFT JOIN asset_custom_values sv ON sv.asset_id = a.`Id` AND sv.field_id = ' . (int) $fieldId;
            $orderBy = 'sv.value ' . $dir;
        }
    } elseif ($sort !== '' && isset($defs[$sort]) && db_column_exists($pdo, 'assets', $sort)) {
        $orderBy = 'a.`' . $sort . '` ' . $dir;
    }

    $whereSql = implode(' AND ', $where);
    $countStmt = $pdo->prepare('SELECT COUNT(*) FROM assets a' . $join . ' WHERE ' . $whereSql);
    $countStmt->execute($params);
    $total = (int) $countStmt->fetchColumn();

    $offset = ($page - 1) * $perPage;
    $sql = 'SELECT a.* FROM assets a' . $join . ' WHERE ' . $whereSql
        . ' ORDER BY ' . $orderBy . ' LIMIT ' . $perPage . ' OFFSET ' . $offset;
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    return [
        'rows'     => $stmt->fetchAll(),
        'total'    => $total,
        'page'     => $page,
        'per_page' => $perPage,
        'pages'    => max(1, (int) ceil($total / $perPage)),
    ];
}

// --- API representation -------------------------------------------------

/**
 * Public (snake_case) field name => database column. Both spellings are
 * accepted on input, so ESET scripts can also post raw column names.
 *
 * @return array<string, string>
 */
function asset_api_field_map(): array
{
    return [
        'id'                => 'Id',
        'uuid'              => 'UUID',
        'sn'                => 'SN',
        'serial_number'     => 'SN',
        'os'                => 'OS',
        'os_version'        => 'OSVersion',
        'hostname'          => 'Hostname',
        'mobile'            => 'Mobile',
        'manufacturer'      => 'Manufacturer',
        'last_seen'         => 'LastSeen',
        'user_email'        => 'UserEmail',
        'byod'              => 'BYOD',
        'status'            => 'Status',
        'warranty'          => 'Warranty',
        'asset'             => 'Asset',
        'purchase_date'     => 'PurchaseDate',
        'cypher_id'         => 'CypherID',
        'cypher_key'        => 'CypherKey',
        'notes'             => 'Notes',
        'cpus'              => 'CPUs',
        'hds'               => 'HDs',
        'hds_types'         => 'HDsTypes',
        'hds_spaces_gb'     => 'HDsSpacesGB',
        'network_adapters'  => 'NetworkAdapters',
        'mac_addresses'     => 'MACAddresses',
        'eset_components'   => 'ESETComponents',
        'primary_local_ip'  => 'PrimaryLocalIP',
        'primary_remote_ip' => 'PrimaryRemoteIP',
        'company'           => 'company',
        'source'            => 'source',
        'source_slug'       => 'source',
        'source_ref'        => 'source_ref',
    ];
}

/**
 * Translate an API payload into DB-column keys.
 *
 * @return array{data: array<string, mixed>, unknown: list<string>}
 */
function asset_api_map_input(array $payload): array
{
    $map = [];
    foreach (asset_api_field_map() as $apiKey => $column) {
        $map[strtolower($apiKey)] = $column;
        $map[strtolower($column)] = $column;
    }

    $data = [];
    $unknown = [];
    foreach ($payload as $key => $value) {
        $normalized = strtolower((string) $key);
        if ($normalized === 'custom') {
            continue;
        }
        if (!isset($map[$normalized])) {
            $unknown[] = (string) $key;
            continue;
        }
        $column = $map[$normalized];
        if ($column === 'Id') {
            continue;
        }
        $data[$column] = $value;
    }

    return ['data' => $data, 'unknown' => $unknown];
}

/**
 * Serialise one asset row for API responses (ISO-8601 UTC timestamps, booleans
 * as JSON booleans, custom values under "custom").
 */
function asset_api_payload(array $row, array $custom = []): array
{
    return [
        'id'                => isset($row['Id']) ? (int) $row['Id'] : null,
        'uuid'              => $row['UUID'] ?? null,
        'sn'                => $row['SN'] ?? null,
        'hostname'          => $row['Hostname'] ?? null,
        'os'                => $row['OS'] ?? null,
        'os_version'        => $row['OSVersion'] ?? null,
        'mobile'            => asset_bool_parse($row['Mobile'] ?? false),
        'manufacturer'      => $row['Manufacturer'] ?? null,
        'last_seen'         => asset_iso_datetime($row['LastSeen'] ?? null),
        'user_email'        => $row['UserEmail'] ?? null,
        'byod'              => asset_bool_parse($row['BYOD'] ?? false),
        'status'            => $row['Status'] ?? null,
        'warranty'          => !empty($row['Warranty']) ? substr((string) $row['Warranty'], 0, 10) : null,
        'asset'             => $row['Asset'] ?? null,
        'purchase_date'     => !empty($row['PurchaseDate']) ? substr((string) $row['PurchaseDate'], 0, 10) : null,
        'cypher_id'         => $row['CypherID'] ?? null,
        'cypher_key'        => $row['CypherKey'] ?? null,
        'notes'             => $row['Notes'] ?? null,
        'cpus'              => $row['CPUs'] ?? null,
        'hds'               => $row['HDs'] ?? null,
        'hds_types'         => $row['HDsTypes'] ?? null,
        'hds_spaces_gb'     => $row['HDsSpacesGB'] ?? null,
        'network_adapters'  => $row['NetworkAdapters'] ?? null,
        'mac_addresses'     => $row['MACAddresses'] ?? null,
        'eset_components'   => $row['ESETComponents'] ?? null,
        'primary_local_ip'  => $row['PrimaryLocalIP'] ?? null,
        'primary_remote_ip' => $row['PrimaryRemoteIP'] ?? null,
        'company'           => $row['company'] ?? null,
        'source'            => $row['source'] ?? null,
        'source_ref'        => $row['source_ref'] ?? null,
        'created_at'        => asset_iso_datetime($row['created_at'] ?? null),
        'updated_at'        => asset_iso_datetime($row['updated_at'] ?? null),
        'created_by'        => $row['created_by'] ?? null,
        'updated_by'        => $row['updated_by'] ?? null,
        'deleted_at'        => asset_iso_datetime($row['deleted_at'] ?? null),
        'deleted_reason'    => $row['deleted_reason'] ?? null,
        'duplicate_of_id'   => isset($row['duplicate_of_id']) ? (int) $row['duplicate_of_id'] : null,
        'custom'            => (object) $custom,
    ];
}
