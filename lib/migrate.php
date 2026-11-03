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
 * panel shows a banner and a DBA can apply `docker/schema.sql` instead.
 */

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
