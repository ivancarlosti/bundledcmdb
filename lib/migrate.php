<?php
declare(strict_types=1);

/**
 * lib/migrate.php — idempotent schema migrations.
 *
 * MySQL 8 / MariaDB do not support `ADD COLUMN IF NOT EXISTS`, so every step is
 * guarded by an information_schema check instead. Applied versions are recorded
 * in `schema_migrations`, which lets `bin/migrate.php --dry-run` and the
 * Settings → Maintenance tab report what is still pending.
 *
 * DDL privileges are required. When the application user cannot ALTER TABLE the
 * panel shows a banner and a DBA can apply `sql/schema.sql` instead.
 */

/**
 * Static drift check between `migrations()` and `sql/schema.sql`.
 *
 * `sql/schema.sql` is the DBA path: an installation applies it once and it
 * records the versions as applied, so `bin/migrate.php` afterwards believes the
 * work is done and never comes back. A table that a later migration creates must
 * therefore also appear in that file — otherwise the feature protected by its
 * `db_table_exists()` guard disappears silently on those installs, which is
 * exactly what happened to `user_preferences`, `asset_events` and `saved_views`.
 *
 * Columns are checked the same way: every `migrate_add_column()` call must be
 * mirrored in `sql/legacy.sql` (fresh installs) or `sql/schema.sql` (DBA path),
 * otherwise a managed database keeps the pre-migration table and the migration
 * never comes back to add them.
 *
 * No database is touched; both inputs are read as text, so this can run in CI
 * and in `bin/test-panel-api.php` without a connection.
 *
 * @return list<string> human-readable problems; empty when the two agree.
 */
function migrate_dba_sql_drift(string $root): array
{
    $schemaPath = rtrim($root, '/') . '/sql/schema.sql';
    if (!is_file($schemaPath)) {
        return ['sql/schema.sql is missing.'];
    }

    $errors = [];
    $migrationSql = (string) file_get_contents(__DIR__ . '/migrate.php');
    $migrationTables = migrate_sql_tables($migrationSql);
    $schemaTables = migrate_sql_tables((string) file_get_contents($schemaPath));

    $missing = array_keys(array_diff_key($migrationTables, $schemaTables));
    sort($missing);
    if ($missing !== []) {
        $errors[] = 'sql/schema.sql is missing tables that lib/migrate.php creates: ' . implode(', ', $missing);
    }

    $schemaSql = (string) file_get_contents($schemaPath);
    $recorded = [];
    if (preg_match('/INSERT\s+IGNORE\s+INTO\s+schema_migrations\b[\s\S]*?(?:;|\z)/i', $schemaSql, $block)
        && preg_match_all("/'([^']+)'/", $block[0], $matches)) {
        foreach ($matches[1] as $version) {
            $recorded[$version] = true;
        }
    }

    $versions = array_keys(migrations());
    $notRecorded = array_values(array_diff($versions, array_keys($recorded)));
    if ($notRecorded !== []) {
        $errors[] = 'sql/schema.sql does not record these migrations as applied: ' . implode(', ', $notRecorded);
    }
    $unknown = array_values(array_diff(array_keys($recorded), $versions));
    if ($unknown !== []) {
        $errors[] = 'sql/schema.sql records versions that no longer exist: ' . implode(', ', $unknown);
    }

    // Columns, not just tables: a migration that adds a column to an existing
    // table (0014, 0017, 0018, …) is invisible to the checks above, so a
    // DBA-managed database silently kept the pre-migration `assets` table.
    $legacyPath = rtrim($root, '/') . '/sql/legacy.sql';
    $referenceSql = $schemaSql . "\n" . (is_file($legacyPath) ? (string) file_get_contents($legacyPath) : '');
    $missingColumns = [];
    foreach (migrate_sql_added_columns($migrationSql) as $table => $columns) {
        foreach (array_keys($columns) as $column) {
            if (preg_match('/\b' . preg_quote($column, '/') . '\b/i', $referenceSql) !== 1) {
                $missingColumns[] = $table . '.' . $column;
            }
        }
    }
    if ($missingColumns !== []) {
        sort($missingColumns);
        $errors[] = 'sql/legacy.sql and sql/schema.sql do not mention columns that lib/migrate.php adds: '
            . implode(', ', $missingColumns);
    }

    // Retired columns too: `sql/legacy.sql` is the pre-migration snapshot, so it
    // still declares a column a later migration drops. `sql/schema.sql` is the
    // final state and must drop it as well, or a DBA-managed database keeps a
    // column the code no longer reads (and `NOT NULL` without a default would
    // start rejecting inserts).
    $missingDrops = [];
    foreach (migrate_sql_dropped_columns($migrationSql) as $table => $columns) {
        foreach (array_keys($columns) as $column) {
            if (preg_match('/\b' . preg_quote($column, '/') . '\b/i', $schemaSql) !== 1) {
                $missingDrops[] = $table . '.' . $column;
            }
        }
    }
    if ($missingDrops !== []) {
        sort($missingDrops);
        $errors[] = 'sql/schema.sql does not drop columns that lib/migrate.php retires: '
            . implode(', ', $missingDrops);
    }

    return $errors;
}

/**
 * Columns added by `migrate_add_column()` calls.
 *
 * @return array<string, array<string, true>> table => [column => true]
 */
function migrate_sql_added_columns(string $sql): array
{
    $columns = [];
    $pattern = '/migrate_add_column\(\s*\$pdo\s*,\s*\'([A-Za-z0-9_]+)\'\s*,\s*\'([A-Za-z0-9_]+)\'/';
    if (preg_match_all($pattern, $sql, $matches, PREG_SET_ORDER)) {
        foreach ($matches as $match) {
            $columns[$match[1]][$match[2]] = true;
        }
    }
    return $columns;
}

/**
 * Columns retired by `migrate_drop_column()` calls.
 *
 * @return array<string, array<string, true>> table => [column => true]
 */
function migrate_sql_dropped_columns(string $sql): array
{
    $columns = [];
    $pattern = '/migrate_drop_column\(\s*\$pdo\s*,\s*\'([A-Za-z0-9_]+)\'\s*,\s*\'([A-Za-z0-9_]+)\'/';
    if (preg_match_all($pattern, $sql, $matches, PREG_SET_ORDER)) {
        foreach ($matches as $match) {
            $columns[$match[1]][$match[2]] = true;
        }
    }
    return $columns;
}

/**
 * Table names created by a chunk of SQL (`CREATE TABLE [IF NOT EXISTS] name`).
 *
 * @return array<string, true> lower-cased table name as key
 */
function migrate_sql_tables(string $sql): array
{
    $tables = [];
    if (preg_match_all('/CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?`?([A-Za-z0-9_]+)`?/i', $sql, $matches)) {
        foreach ($matches[1] as $table) {
            $tables[strtolower($table)] = true;
        }
    }
    return $tables;
}

/** @return array<string, array{description: string, run: callable-string}> */
function migrations(): array
{
    return [
        '2026_11_02_0001_settings' => [
            'description' => 'Create app_settings (DB-backed configuration)',
            'run' => 'migration_0001_settings',
        ],
        '2026_11_02_0002_sources' => [
            'description' => 'Create asset_sources and seed eset/api/manual',
            'run' => 'migration_0002_sources',
        ],
        '2026_11_02_0003_api_keys' => [
            'description' => 'Create api_keys (hashed tokens, scopes, allowlists)',
            'run' => 'migration_0003_api_keys',
        ],
        '2026_11_02_0004_assets_provenance' => [
            'description' => 'Add assets.source/source_ref/timestamps/deleted_at + indexes, backfill eset',
            'run' => 'migration_0004_assets_provenance',
        ],
        '2026_11_02_0005_asset_fields' => [
            'description' => 'Create asset_field_defs + asset_custom_values (dynamic fields)',
            'run' => 'migration_0005_asset_fields',
        ],
        '2026_11_02_0006_user_preferences' => [
            'description' => 'Create user_preferences (locale, theme, page size, grid layout)',
            'run' => 'migration_0006_user_preferences',
        ],
        '2026_11_02_0007_assets_indexes' => [
            'description' => 'Ensure every index the device list/export sorts on exists',
            'run' => 'migration_0007_assets_indexes',
        ],
        '2026_11_04_0008_retire_spa_flag' => [
            'description' => 'Drop the retired ui.spa_enabled setting (the legacy panel is gone)',
            'run' => 'migration_0008_retire_spa_flag',
        ],
        '2026_11_04_0009_rate_limits' => [
            'description' => 'Create rate_limits (fixed-window counters for panel writes)',
            'run' => 'migration_0009_rate_limits',
        ],
        '2026_11_04_0010_asset_events' => [
            'description' => 'Create asset_events (audit trail for device writes)',
            'run' => 'migration_0010_asset_events',
        ],
        '2026_11_04_0011_saved_views' => [
            'description' => 'Create saved_views (named grid presets per user)',
            'run' => 'migration_0011_saved_views',
        ],
        '2026_11_05_0012_audit_entities' => [
            'description' => 'Add asset_events company/entity columns + indexes, backfill device rows',
            'run' => 'migration_0012_audit_entities',
        ],
        '2026_11_06_0013_retire_storage_local_path' => [
            'description' => 'Drop the storage.local_path setting (the directory is fixed at var/storage)',
            'run' => 'migration_0013_retire_storage_local_path',
        ],
        '2026_11_07_0014_asset_deletion_reason' => [
            'description' => 'Add assets.deleted_reason/duplicate_of_id + index (why a device was deleted)',
            'run' => 'migration_0014_asset_deletion_reason',
        ],
        '2026_11_03_0015_permissions' => [
            'description' => 'Add role capability/field matrix and retire the built-in ESET source',
            'run' => 'migration_0015_permissions',
        ],
        '2026_11_08_0016_api_key_users_scope' => [
            'description' => 'Add api_keys.can_users (manage the user roster over the API)',
            'run' => 'migration_0016_api_key_users_scope',
        ],
        '2026_11_04_0017_asset_hardware_disks' => [
            'description' => 'Add assets GPUs/RAMGB/LoggedUsername/DeviceModel + structured PhysicalDisks and its roll-ups',
            'run' => 'migration_0017_asset_hardware_disks',
        ],
        '2026_11_05_0018_asset_agent_inventory' => [
            'description' => 'Add the inventory-agent block (Agent* columns) without touching the legacy columns',
            'run' => 'migration_0018_asset_agent_inventory',
        ],
        '2026_11_05_0019_retire_agent_only_columns' => [
            'description' => 'Retire assets DeviceModel/GPUs/RAMGB/DisksTotalGB/DisksFreeGB/DisksUsedGB (agent block + PhysicalDisks own those facts)',
            'run' => 'migration_0019_retire_agent_only_columns',
        ],
        '2026_11_05_0020_retire_logged_username' => [
            'description' => 'Retire assets LoggedUsername (the inventory agent reports it as AgentLoggedUser)',
            'run' => 'migration_0020_retire_logged_username',
        ],
    ];
}

function migrate_ledger_exists(PDO $pdo): bool
{
    return db_table_exists($pdo, 'schema_migrations');
}

function migrate_ensure_ledger(PDO $pdo): void
{
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS schema_migrations (
            version VARCHAR(64) NOT NULL,
            applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (version)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );
}

/** @return list<string> */
function migrate_applied(PDO $pdo): array
{
    if (!migrate_ledger_exists($pdo)) {
        return [];
    }
    $rows = $pdo->query('SELECT version FROM schema_migrations ORDER BY version')->fetchAll();
    return array_map(static fn (array $r): string => (string) $r['version'], $rows);
}
function migrate_note(string $message): void
{
    $GLOBALS['bcmdb_migrate_log'] ??= [];
    $GLOBALS['bcmdb_migrate_log'][] = $message;
}

/** @return list<string> */
function migrate_log_take(): array
{
    $log = $GLOBALS['bcmdb_migrate_log'] ?? [];
    $GLOBALS['bcmdb_migrate_log'] = [];
    return $log;
}

function migrate_index_exists(PDO $pdo, string $table, string $index): bool
{
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.STATISTICS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?'
    );
    $stmt->execute([$table, $index]);
    return ((int) $stmt->fetchColumn()) > 0;
}


/** Add a column only when it is missing; returns true when DDL was executed. */
function migrate_add_column(PDO $pdo, string $table, string $column, string $definition): bool
{
    if (!db_table_exists($pdo, $table) || db_column_exists($pdo, $table, $column)) {
        return false;
    }
    $pdo->exec(sprintf('ALTER TABLE `%s` ADD COLUMN `%s` %s', $table, $column, $definition));
    migrate_note("ALTER TABLE {$table} ADD COLUMN {$column}");
    return true;
}

/** Add an index only when it is missing. */
function migrate_add_index(PDO $pdo, string $table, string $index, string $definition): bool
{
    if (!db_table_exists($pdo, $table) || migrate_index_exists($pdo, $table, $index)) {
        return false;
    }
    $pdo->exec(sprintf('ALTER TABLE `%s` ADD INDEX `%s` (%s)', $table, $index, $definition));
    migrate_note("ALTER TABLE {$table} ADD INDEX {$index}");
    return true;
}

/** Drop a column only when it is still there; returns true when DDL was executed. */
function migrate_drop_column(PDO $pdo, string $table, string $column): bool
{
    if (!db_table_exists($pdo, $table) || !db_column_exists($pdo, $table, $column)) {
        return false;
    }
    $pdo->exec(sprintf('ALTER TABLE `%s` DROP COLUMN `%s`', $table, $column));
    migrate_note("ALTER TABLE {$table} DROP COLUMN {$column}");
    return true;
}

/**
 * Exact type of assets.Id, used for asset_custom_values.asset_id so joins need
 * no casts (the legacy schema may use INT or BIGINT).
 */
function migrate_assets_id_type(PDO $pdo): string
{
    $type = db_column_definition($pdo, 'assets', 'Id');
    if ($type === null || preg_match('/^[a-zA-Z]+(\(\d+\))?( unsigned)?$/i', $type) !== 1) {
        return 'INT';
    }
    return $type;
}

/**
 * Run all pending migrations.
 *
 * @return array{applied: list<string>, skipped: list<string>, dry_run: bool, log: list<string>}
 */
function migrate_run(PDO $pdo, bool $dryRun = false): array
{
    $ledgerExisted = migrate_ledger_exists($pdo);
    if (!$dryRun && !$ledgerExisted) {
        migrate_ensure_ledger($pdo);
    }
    $applied = $ledgerExisted ? migrate_applied($pdo) : [];

    $result = ['applied' => [], 'skipped' => [], 'dry_run' => $dryRun, 'log' => []];
    $GLOBALS['bcmdb_migrate_log'] = [];

    foreach (migrations() as $version => $migration) {
        if (in_array($version, $applied, true)) {
            $result['skipped'][] = $version;
            continue;
        }
        $result['applied'][] = $version;
        if ($dryRun) {
            migrate_note('would run ' . $version . ' — ' . $migration['description']);
            continue;
        }
        migrate_note('running ' . $version . ' — ' . $migration['description']);
        ($migration['run'])($pdo);
        $stmt = $pdo->prepare('INSERT IGNORE INTO schema_migrations (version) VALUES (?)');
        $stmt->execute([$version]);
        migrate_note('applied ' . $version);
    }

    $result['log'] = migrate_log_take();
    return $result;
}

/**
 * Schema state used by the banner and the Maintenance tab.
 *
 * @return array{ready: bool, ledger: bool, applied: list<string>, pending: list<string>}
 */
function migrate_status(?PDO $pdo = null): array
{
    $pdo ??= db_try();
    if ($pdo === null) {
        return ['ready' => false, 'ledger' => false, 'applied' => [], 'pending' => array_keys(migrations())];
    }
    $ledger = migrate_ledger_exists($pdo);
    $applied = $ledger ? migrate_applied($pdo) : [];
    $pending = array_values(array_diff(array_keys(migrations()), $applied));
    $ready = $ledger
        && $pending === []
        && db_table_exists($pdo, 'app_settings')
        && db_column_exists($pdo, 'assets', 'source');

    return ['ready' => $ready, 'ledger' => $ledger, 'applied' => $applied, 'pending' => $pending];
}

// --- Migration steps ----------------------------------------------------

function migration_0001_settings(PDO $pdo): void
{
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS app_settings (
            skey VARCHAR(64) NOT NULL,
            svalue LONGTEXT NULL,
            is_secret TINYINT(1) NOT NULL DEFAULT 0,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            updated_by VARCHAR(191) NULL,
            PRIMARY KEY (skey)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    migrate_note('CREATE TABLE app_settings');
}

function migration_0002_sources(PDO $pdo): void
{
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS asset_sources (
            id INT NOT NULL AUTO_INCREMENT,
            slug VARCHAR(32) NOT NULL,
            label VARCHAR(64) NOT NULL,
            color VARCHAR(16) NULL,
            is_system TINYINT(1) NOT NULL DEFAULT 0,
            is_default TINYINT(1) NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_source_slug (slug)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    migrate_note('CREATE TABLE asset_sources');

    $seed = [
        ['eset', 'ESET', '#2f855a', 1, 0],
        ['api', 'API', '#2b6cb0', 1, 0],
        ['manual', 'Manual', '#6b46c1', 1, 1],
    ];
    $stmt = $pdo->prepare(
        'INSERT IGNORE INTO asset_sources (slug, label, color, is_system, is_default) VALUES (?, ?, ?, ?, ?)'
    );
    foreach ($seed as $row) {
        $stmt->execute($row);
    }
    migrate_note('seed asset_sources: eset, api, manual');
}

function migration_0003_api_keys(PDO $pdo): void
{
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS api_keys (
            id INT NOT NULL AUTO_INCREMENT,
            label VARCHAR(64) NOT NULL,
            prefix VARCHAR(16) NOT NULL,
            key_hash CHAR(64) NOT NULL,
            company VARCHAR(191) NULL,
            source_slug VARCHAR(32) NULL,
            allowed_sources VARCHAR(255) NULL,
            ip_allowlist TEXT NULL,
            can_read TINYINT(1) NOT NULL DEFAULT 1,
            can_write TINYINT(1) NOT NULL DEFAULT 1,
            can_delete TINYINT(1) NOT NULL DEFAULT 0,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            created_by VARCHAR(191) NULL,
            rotated_at DATETIME NULL,
            last_used_at DATETIME NULL,
            last_used_ip VARCHAR(45) NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_key_hash (key_hash),
            KEY idx_prefix (prefix)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    migrate_note('CREATE TABLE api_keys');
}



function migration_0004_assets_provenance(PDO $pdo): void
{
    migrate_add_column($pdo, 'assets', 'source', "VARCHAR(32) NOT NULL DEFAULT 'eset'");
    migrate_add_column($pdo, 'assets', 'source_ref', 'VARCHAR(191) NULL');
    migrate_add_column($pdo, 'assets', 'created_at', 'DATETIME NULL');
    migrate_add_column($pdo, 'assets', 'updated_at', 'DATETIME NULL');
    migrate_add_column($pdo, 'assets', 'created_by', 'VARCHAR(191) NULL');
    migrate_add_column($pdo, 'assets', 'updated_by', 'VARCHAR(191) NULL');
    migrate_add_column($pdo, 'assets', 'deleted_at', 'DATETIME NULL');

    if (db_table_exists($pdo, 'assets') && db_column_exists($pdo, 'assets', 'source')) {
        $pdo->exec("UPDATE assets SET source = 'eset' WHERE source IS NULL OR source = ''");
        migrate_note('backfill assets.source = eset');
    }

    // Indexes only for columns that really exist in the legacy table.
    if (db_column_exists($pdo, 'assets', 'company') && db_column_exists($pdo, 'assets', 'source')) {
        migrate_add_index($pdo, 'assets', 'idx_assets_company_source', '`company`, `source`');
    }
    if (db_column_exists($pdo, 'assets', 'UUID')) {
        migrate_add_index($pdo, 'assets', 'idx_assets_uuid', '`UUID`');
    }
    if (db_column_exists($pdo, 'assets', 'SN')) {
        migrate_add_index($pdo, 'assets', 'idx_assets_sn', '`SN`');
    }
    if (db_column_exists($pdo, 'assets', 'updated_at')) {
        migrate_add_index($pdo, 'assets', 'idx_assets_updated', '`updated_at`');
    }
    if (db_column_exists($pdo, 'assets', 'deleted_at')) {
        migrate_add_index($pdo, 'assets', 'idx_assets_deleted', '`deleted_at`');
    }
}

function migration_0005_asset_fields(PDO $pdo): void
{
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS asset_field_defs (
            id INT NOT NULL AUTO_INCREMENT,
            field_key VARCHAR(64) NOT NULL,
            label VARCHAR(128) NOT NULL,
            label_i18n TEXT NULL,
            data_type VARCHAR(16) NOT NULL DEFAULT 'text',
            options_json TEXT NULL,
            default_value VARCHAR(255) NULL,
            validation_json TEXT NULL,
            is_required TINYINT(1) NOT NULL DEFAULT 0,
            is_unique TINYINT(1) NOT NULL DEFAULT 0,
            is_searchable TINYINT(1) NOT NULL DEFAULT 1,
            show_in_grid TINYINT(1) NOT NULL DEFAULT 0,
            editable_roles VARCHAR(64) NOT NULL DEFAULT 'admin,superadmin',
            sort_order INT NOT NULL DEFAULT 0,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME NULL,
            updated_at DATETIME NULL,
            created_by VARCHAR(191) NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_field_key (field_key),
            KEY idx_field_sort (sort_order)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    migrate_note('CREATE TABLE asset_field_defs');

    $idType = migrate_assets_id_type($pdo);
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS asset_custom_values (
            asset_id {$idType} NOT NULL,
            field_id INT NOT NULL,
            value LONGTEXT NULL,
            updated_at DATETIME NULL,
            updated_by VARCHAR(191) NULL,
            PRIMARY KEY (asset_id, field_id),
            KEY idx_field_value (field_id, value(64))
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    migrate_note("CREATE TABLE asset_custom_values (asset_id {$idType})");
}

function migration_0006_user_preferences(PDO $pdo): void
{
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS user_preferences (
            email VARCHAR(191) NOT NULL,
            locale VARCHAR(16) NULL,
            theme VARCHAR(16) NOT NULL DEFAULT 'system',
            per_page SMALLINT NOT NULL DEFAULT 25,
            grid_state LONGTEXT NULL,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (email)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    migrate_note('CREATE TABLE user_preferences');
}

/**
 * Repair step: the index block of 0004 only runs on installations that applied
 * 0004 *after* it was written. A database that migrated earlier keeps the
 * `schema_migrations` row and never learns about `idx_assets_updated`,
 * `idx_assets_deleted` or `idx_assets_company_source`, which is exactly what
 * `bin/bench-devices.php` (phase 2, EXPLAIN at 100k rows) surfaced: the default
 * `updated_at desc` sort degraded to a filesort over the whole table.
 *
 * Idempotent by construction — every step is guarded by `migrate_index_exists()`.
 */
function migration_0007_assets_indexes(PDO $pdo): void
{
    if (!db_table_exists($pdo, 'assets')) {
        return;
    }

    $indexes = [
        'idx_assets_company_source' => ['`company`', '`source`'],
        'idx_assets_uuid'           => ['`UUID`'],
        'idx_assets_sn'             => ['`SN`'],
        'idx_assets_updated'        => ['`updated_at`'],
        'idx_assets_deleted'        => ['`deleted_at`'],
        // `ORDER BY source` / `ORDER BY Status` cannot use the composite and the
        // low-cardinality index still lets InnoDB walk in order and stop at the
        // page (179 ms → 0.3 ms at 100k rows in bin/bench-devices.php).
        'idx_assets_source'         => ['`source`'],
        'idx_assets_status'         => ['`Status`'],
    ];
    foreach ($indexes as $name => $columns) {
        $present = true;
        foreach ($columns as $column) {
            $present = $present && db_column_exists($pdo, 'assets', trim($column, '`'));
        }
        if ($present) {
            migrate_add_index($pdo, 'assets', $name, implode(', ', $columns));
        }
    }
}

/**
 * 11.0.0 removed the legacy PHP panel, so `ui.spa_enabled` no longer selects
 * anything: `/` always goes to `/app/`. Deleting the stored row keeps
 * `settings_all()` output (and the Settings → General screen, which is generated
 * from `settings_definitions()`) free of an option that would silently do
 * nothing. The environment tier never carried it.
 */
function migration_0008_retire_spa_flag(PDO $pdo): void
{
    if (!db_table_exists($pdo, 'app_settings')) {
        return;
    }
    $stmt = $pdo->prepare('DELETE FROM app_settings WHERE skey = ?');
    $stmt->execute(['ui.spa_enabled']);
    if ($stmt->rowCount() > 0) {
        migrate_note('DELETE app_settings ui.spa_enabled');
    }
}


/**
 * Fixed-window counters for `lib/rate_limit.php`.
 *
 * One row per bucket (`panel:<email>`): the window start and the hit count. The
 * upsert in `rate_limit_hit()` makes the update atomic, so concurrent writes from
 * different PHP-FPM workers cannot each believe they are the first one.
 * Rows are tiny and self-expiring (the next window overwrites them), so no
 * cleanup job is needed.
 */
function migration_0009_rate_limits(PDO $pdo): void
{
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS rate_limits (
            bucket VARCHAR(191) NOT NULL,
            window_start INT UNSIGNED NOT NULL,
            hits INT UNSIGNED NOT NULL DEFAULT 0,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (bucket)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );
    migrate_note('CREATE TABLE rate_limits');
}

/**
 * Audit trail (`lib/asset_events.php`).
 *
 * `asset_id` is intentionally not a foreign key: the history has to outlive a
 * hard delete, which is exactly the moment somebody wants to know who did it.
 * `changes` holds `{field: [old, new]}` as JSON so a row can describe a partial
 * update without one column per field.
 */
function migration_0010_asset_events(PDO $pdo): void
{
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS asset_events (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            asset_id INT NULL,
            action VARCHAR(32) NOT NULL,
            actor VARCHAR(191) NOT NULL,
            changes LONGTEXT NULL,
            note VARCHAR(255) NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            KEY idx_asset_events_asset (asset_id, id),
            KEY idx_asset_events_created (created_at),
            KEY idx_asset_events_actor (actor)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );
    migrate_note('CREATE TABLE asset_events');
}

/**
 * Named grid presets (`lib/saved_views.php`).
 *
 * `(email, name)` is unique: "save as …" with an existing name overwrites that
 * view (the gesture users expect from a toolbar) instead of piling up duplicates
 * with the same label.
 */
function migration_0011_saved_views(PDO $pdo): void
{
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS saved_views (
            id INT NOT NULL AUTO_INCREMENT,
            email VARCHAR(191) NOT NULL,
            name VARCHAR(64) NOT NULL,
            state LONGTEXT NOT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_saved_view (email, name)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );
    migrate_note('CREATE TABLE saved_views');
}

/**
 * Turn `asset_events` into the audit log behind the Logs screen.
 *
 * The table started as a per-device history (`0010`), where the company was
 * implied by the asset row. An audit screen has to filter by company without a
 * join, and has to describe rows that are not devices at all (settings, sources,
 * keys, roles), so the entity and company become columns.
 *
 * Backfill runs only on the run that adds the columns; afterwards the values are
 * written by `asset_event_record()`.
 */
function migration_0012_audit_entities(PDO $pdo): void
{
    if (!db_table_exists($pdo, 'asset_events')) {
        // 0010 has not run: nothing to extend yet. Keeping the step a no-op here
        // is safer than creating a half-table that 0010 would then have to fix.
        return;
    }

    migrate_add_column($pdo, 'asset_events', 'company', 'VARCHAR(191) NULL');
    $added = migrate_add_column($pdo, 'asset_events', 'entity', "VARCHAR(32) NOT NULL DEFAULT 'device'");
    migrate_add_column($pdo, 'asset_events', 'entity_id', 'VARCHAR(64) NULL');
    migrate_add_column($pdo, 'asset_events', 'entity_label', 'VARCHAR(191) NULL');

    migrate_add_index($pdo, 'asset_events', 'idx_asset_events_company', '`company`, `id`');
    migrate_add_index($pdo, 'asset_events', 'idx_asset_events_entity', '`entity`, `id`');
    // `logs_purge_due()` looks up the newest `log_purge` row on every session
    // bootstrap, so that lookup has to be an index seek, not a table scan.
    migrate_add_index($pdo, 'asset_events', 'idx_asset_events_action', '`action`, `id`');

    if (!$added) {
        return;
    }

    $pdo->exec('UPDATE asset_events SET entity_id = CAST(asset_id AS CHAR) WHERE asset_id IS NOT NULL AND entity_id IS NULL');
    migrate_note('backfill asset_events.entity_id');

    // Device rows are the only historical rows: their company and label live on
    // the asset. A hard-deleted device has no row left, and those events keep a
    // NULL company — they were already global in everything but name.
    if (db_table_exists($pdo, 'assets') && db_column_exists($pdo, 'assets', 'SN')
        && db_column_exists($pdo, 'assets', 'company')) {
        $pdo->exec(
            'UPDATE asset_events e JOIN assets a ON a.Id = e.asset_id
             SET e.company = a.company, e.entity_label = a.SN
             WHERE e.asset_id IS NOT NULL'
        );
        migrate_note('backfill asset_events.company/entity_label from assets');
    }
}


/**
 * `storage.local_path` was a free-text path the panel could not keep honest: the
 * Docker stacks bind-mount `var/storage`, so any other path meant the files were
 * written to the container filesystem and lost on the next `up`. The directory
 * is now fixed (`lib/storage.php`, with `BCMDB_STORAGE_PATH` as the escape hatch
 * for tests and non-Docker installs), so the stored row only misleads the UI.
 */
function migration_0013_retire_storage_local_path(PDO $pdo): void
{
    if (!db_table_exists($pdo, 'app_settings')) {
        return;
    }
    $stmt = $pdo->prepare('DELETE FROM app_settings WHERE skey = ?');
    $stmt->execute(['storage.local_path']);
    if ($stmt->rowCount() > 0) {
        migrate_note('DELETE app_settings storage.local_path');
    }
}

/**
 * Why a device was deleted, and which asset it duplicates.
 *
 * The trash view already keeps the row, but "deleted" alone cannot answer the
 * question an auditor asks next: was it a duplicate (and of what) or a record
 * that should never have existed? `deleted_reason` holds one of
 * `asset_deletion_reasons()`; `duplicate_of_id` points at the asset that was
 * kept. Both are NULL for rows deleted before this migration and for callers
 * that do not send a reason, so nothing has to be backfilled.
 *
 * `duplicate_of_id` uses the exact type of `assets.Id` (INT or BIGINT in the
 * legacy schema) so no join needs a cast.
 */
function migration_0014_asset_deletion_reason(PDO $pdo): void
{
    if (!db_table_exists($pdo, 'assets')) {
        return;
    }

    migrate_add_column($pdo, 'assets', 'deleted_reason', 'VARCHAR(32) NULL');
    migrate_add_column($pdo, 'assets', 'duplicate_of_id', migrate_assets_id_type($pdo) . ' NULL');
    // The trash view groups by reason; the index keeps that a range scan.
    migrate_add_index($pdo, 'assets', 'idx_assets_deleted_reason', '`deleted_reason`');
}

/**
 * Persisted role permissions and retirement of the built-in ESET source.
 *
 * The matrix is empty by default: `lib/permissions.php` holds the defaults, and
 * only deviations are stored here. ESET is deleted only when nothing references
 * it; otherwise it is demoted to a normal source that an admin can remove after
 * moving its assets (`source_move()`).
 */
function migration_0015_permissions(PDO $pdo): void
{
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS role_capabilities (
            role VARCHAR(32) NOT NULL,
            capability VARCHAR(64) NOT NULL,
            is_allowed TINYINT(1) NOT NULL DEFAULT 0,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_by VARCHAR(191) NULL,
            PRIMARY KEY (role, capability)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS role_field_permissions (
            role VARCHAR(32) NOT NULL,
            field_key VARCHAR(64) NOT NULL,
            can_edit TINYINT(1) NOT NULL DEFAULT 0,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_by VARCHAR(191) NULL,
            PRIMARY KEY (role, field_key)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    migrate_note('CREATE TABLE role_capabilities, role_field_permissions');

    if (db_table_exists($pdo, 'assets') && db_column_exists($pdo, 'assets', 'source')) {
        $pdo->exec("ALTER TABLE assets ALTER COLUMN source SET DEFAULT 'manual'");
        migrate_note('assets.source default = manual');
    }

    if (db_table_exists($pdo, 'app_settings')) {
        $stmt = $pdo->prepare(
            "UPDATE app_settings SET svalue = 'manual' WHERE skey = 'assets.default_source' AND svalue = 'eset'"
        );
        $stmt->execute();
        if ($stmt->rowCount() > 0) {
            migrate_note('assets.default_source = manual');
        }
    }

    if (!db_table_exists($pdo, 'asset_sources')) {
        return;
    }

    $eset = $pdo->query("SELECT is_default FROM asset_sources WHERE slug = 'eset'")->fetch();
    if ($eset === false) {
        return;
    }

    if ((int) $eset['is_default'] === 1) {
        $pdo->exec("UPDATE asset_sources SET is_default = 0 WHERE slug = 'eset'");
    }
    $defaultCount = (int) $pdo->query('SELECT COUNT(*) FROM asset_sources WHERE is_default = 1')->fetchColumn();
    if ($defaultCount === 0) {
        $pdo->exec("UPDATE asset_sources SET is_default = 1 WHERE slug = 'manual'");
        migrate_note('default source = manual');
    }

    $references = 0;
    if (db_table_exists($pdo, 'assets')) {
        $references += (int) $pdo->query("SELECT COUNT(*) FROM assets WHERE `source` = 'eset'")->fetchColumn();
    }
    if (db_table_exists($pdo, 'api_keys')) {
        $references += (int) $pdo->query(
            "SELECT COUNT(*) FROM api_keys WHERE source_slug = 'eset' OR FIND_IN_SET('eset', allowed_sources)"
        )->fetchColumn();
    }

    if ($references === 0) {
        $pdo->exec("DELETE FROM asset_sources WHERE slug = 'eset'");
        migrate_note('DELETE built-in source eset');
    } else {
        $pdo->exec("UPDATE asset_sources SET is_system = 0 WHERE slug = 'eset'");
        migrate_note('demote source eset (still referenced)');
    }
}

/**
 * Scope for the user roster endpoints.
 *
 * Defaults to 0 on purpose: the existing keys (ESET sync, Intune, CI) must not
 * be able to create accounts, which would be a privilege escalation out of
 * `can_write`.
 */
function migration_0016_api_key_users_scope(PDO $pdo): void
{
    migrate_add_column($pdo, 'api_keys', 'can_users', 'TINYINT(1) NOT NULL DEFAULT 0');
}

/**
 * Hardware and identity fields the inventory agents report, plus the structured
 * disk inventory.
 *
 * `PhysicalDisks` is the canonical JSON array (`lib/disks.php`); the three
 * `Disks*GB` columns were roll-ups recomputed on every write so the grid could
 * sort and filter on free space without parsing JSON, and `HDs`/`HDsTypes`/
 * `HDsSpacesGB` briefly stayed in sync for the clients that already read them.
 * Both behaviours were dropped again: the roll-ups in 0019, the summarising when
 * the three text columns became manual (they are no longer derived from the
 * JSON at all, so a report cannot overwrite what a human typed).
 *
 * GPUs and RAMGB are free text on purpose: `RAMGB VARCHAR` keeps whatever the
 * agent reports ("16", "16 GB", "32 (2x16)") instead of forcing a unit.
 *
 * Superseded in part by 0019: `GPUs`, `RAMGB`, `DeviceModel` and the three
 * `Disks*GB` roll-ups are dropped again, because the `Agent*` block and
 * `PhysicalDisks` became the only write paths for those facts. `LoggedUsername`
 * is dropped by 0020 for the same reason: the agent reports it as
 * `AgentLoggedUser`.
 */
function migration_0017_asset_hardware_disks(PDO $pdo): void
{
    migrate_add_column($pdo, 'assets', 'GPUs', 'VARCHAR(255) NULL');
    migrate_add_column($pdo, 'assets', 'RAMGB', 'VARCHAR(255) NULL');
    migrate_add_column($pdo, 'assets', 'LoggedUsername', 'VARCHAR(191) NULL');
    migrate_add_column($pdo, 'assets', 'DeviceModel', 'VARCHAR(191) NULL');
    migrate_add_column($pdo, 'assets', 'PhysicalDisks', 'LONGTEXT NULL');
    migrate_add_column($pdo, 'assets', 'DisksTotalGB', 'DECIMAL(12,2) NULL');
    migrate_add_column($pdo, 'assets', 'DisksFreeGB', 'DECIMAL(12,2) NULL');
    migrate_add_column($pdo, 'assets', 'DisksUsedGB', 'DECIMAL(12,2) NULL');
}

/**
 * The inventory-agent block.
 *
 * Every key the agent reports gets its own column instead of overwriting the
 * matching legacy one (`Hostname`, `OS`, `Manufacturer`, …): an agent write can
 * never replace a hand-maintained value and both sources stay comparable.
 * The block is declared once in `asset_agent_columns()` (`lib/assets.php`); this
 * migration only creates the storage.
 *
 * `AgentSecureBoot` is VARCHAR like `BYOD` ('true'/'false') so the panel keeps
 * one boolean convention, and `AgentAllLoggedUsers` is TEXT because the agent
 * reports a list.
 */
function migration_0018_asset_agent_inventory(PDO $pdo): void
{
    $definitions = [
        'AgentHostname'       => 'VARCHAR(255) NULL',
        'AgentOS'             => 'VARCHAR(191) NULL',
        'AgentOSBuild'        => 'VARCHAR(64) NULL',
        'AgentBIOS'           => 'VARCHAR(191) NULL',
        'AgentSecureBoot'     => 'VARCHAR(16) NULL',
        'AgentManufacturer'   => 'VARCHAR(191) NULL',
        'AgentModel'          => 'VARCHAR(191) NULL',
        'AgentCPU'            => 'VARCHAR(255) NULL',
        'AgentGPU'            => 'VARCHAR(255) NULL',
        'AgentRAMGB'          => 'VARCHAR(255) NULL',
        'AgentIP'             => 'VARCHAR(64) NULL',
        'AgentLoggedUser'     => 'VARCHAR(191) NULL',
        'AgentAllLoggedUsers' => 'TEXT NULL',
        'AgentExecutionUser'  => 'VARCHAR(191) NULL',
        'AgentAntivirus'      => 'VARCHAR(255) NULL',
    ];
    foreach ($definitions as $column => $definition) {
        migrate_add_column($pdo, 'assets', $column, $definition);
    }
}

/**
 * Retire the six columns an agent now owns.
 *
 * `DeviceModel`, `GPUs` and `RAMGB` duplicated what the inventory agent writes to
 * `AgentModel`/`AgentGPU`/`AgentRAMGB`, and the three `Disks*GB` roll-ups were
 * only a sorting shortcut over `PhysicalDisks` (the grid, the export and the API
 * read the JSON). Keeping both copies meant two writable paths for one fact, so
 * the legacy columns are dropped instead of deprecated.
 *
 * The values are *not* thrown away silently: whatever sits in the legacy column
 * is copied into the agent block first, but only where the agent has not
 * reported yet — a real report is fresher than a hand-typed value.
 *
 * This migration is destructive on purpose; take a dump before upgrading.
 * `sql/legacy.sql` keeps the columns (it is the pre-migration snapshot a fresh
 * bundle install starts from), so a new database creates them here and drops
 * them one migration later.
 */
function migration_0019_retire_agent_only_columns(PDO $pdo): void
{
    $backfill = [
        'AgentModel' => 'DeviceModel',
        'AgentGPU'   => 'GPUs',
        'AgentRAMGB' => 'RAMGB',
    ];
    foreach ($backfill as $target => $source) {
        if (!db_column_exists($pdo, 'assets', $target) || !db_column_exists($pdo, 'assets', $source)) {
            continue;
        }
        $stmt = $pdo->prepare(
            "UPDATE assets SET `{$target}` = `{$source}`
              WHERE (`{$target}` IS NULL OR `{$target}` = '')
                AND `{$source}` IS NOT NULL AND `{$source}` <> ''"
        );
        $stmt->execute();
        if ($stmt->rowCount() > 0) {
            migrate_note("assets.{$source} copied to assets.{$target} ({$stmt->rowCount()} rows)");
        }
    }

    // Written out one by one (not a loop) so `migrate_sql_dropped_columns()`
    // finds them and bin/check-schema.php can demand them in sql/schema.sql.
    migrate_drop_column($pdo, 'assets', 'DeviceModel');
    migrate_drop_column($pdo, 'assets', 'GPUs');
    migrate_drop_column($pdo, 'assets', 'RAMGB');
    migrate_drop_column($pdo, 'assets', 'DisksTotalGB');
    migrate_drop_column($pdo, 'assets', 'DisksFreeGB');
    migrate_drop_column($pdo, 'assets', 'DisksUsedGB');
}

/**
 * Retire the hand-typed `assets.LoggedUsername`.
 *
 * `0017` added it as a manual "logged-in user"; `0018` then gave the inventory
 * agent `AgentLoggedUser`, so the panel carried two fields for one fact and two
 * writable paths for it (managers typed the legacy column, admins edit the agent
 * one) that could disagree with each other.
 *
 * No backfill on purpose: a hand-typed value is stale by definition, and the next
 * report overwrites `AgentLoggedUser` anyway, so copying it would only show a
 * value the agent is about to replace. This migration is destructive on purpose;
 * take a dump before upgrading.
 *
 * `sql/legacy.sql` keeps the column (it is the pre-migration snapshot a fresh
 * bundle install starts from), so a new database creates it in 0017 and drops it
 * here.
 */
function migration_0020_retire_logged_username(PDO $pdo): void
{
    migrate_drop_column($pdo, 'assets', 'LoggedUsername');
}
