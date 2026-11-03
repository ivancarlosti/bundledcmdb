#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * bin/check-schema.php — keeps `sql/schema.sql` in step with the migrations.
 *
 * A DBA-managed installation applies `sql/schema.sql` once and never needs
 * `bin/migrate.php` again: the file records the versions as applied. Anything a
 * later migration adds but the file forgets is therefore gone for good on those
 * installs (that is how `user_preferences`, `asset_events` and `saved_views`
 * went missing, taking Settings → Preferences, the History card and saved grid
 * views with them because each feature is guarded by `db_table_exists()`).
 *
 * The comparison itself lives in `migrate_dba_sql_drift()` so
 * `bin/test-panel-api.php` enforces the same invariant; this script is the
 * standalone/CI entry point.
 *
 * Exit code 0 = OK, 1 = drift found.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}

require_once __DIR__ . '/../lib/bootstrap.php';

$root = dirname(__DIR__);
$errors = migrate_dba_sql_drift($root);

$migrationTables = migrate_sql_tables((string) file_get_contents(__DIR__ . '/../lib/migrate.php'));
$schemaTables = migrate_sql_tables((string) file_get_contents(__DIR__ . '/../sql/schema.sql'));
printf(
    "migrations: %d tables, sql/schema.sql: %d tables, ledger: %d versions\n",
    count($migrationTables),
    count($schemaTables),
    count(migrations())
);

if ($errors !== []) {
    foreach ($errors as $error) {
        fwrite(STDERR, 'ERROR ' . $error . PHP_EOL);
    }
    exit(1);
}

echo 'schema check passed.' . PHP_EOL;
exit(0);
