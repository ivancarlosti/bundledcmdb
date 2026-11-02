#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * bin/migrate.php — apply (or inspect) the schema migrations.
 *
 * Usage:
 *   php bin/migrate.php             apply every pending migration
 *   php bin/migrate.php --dry-run   list what would run, change nothing
 *   php bin/migrate.php --status    show applied/pending versions
 *   php bin/migrate.php --help
 *
 * Exit codes: 0 success / up to date, 1 error / still pending, 2 bad usage.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}

require_once __DIR__ . '/../lib/bootstrap.php';

$args = array_slice($argv, 1);
$dryRun = in_array('--dry-run', $args, true);
$statusOnly = in_array('--status', $args, true) || in_array('status', $args, true);
$help = in_array('--help', $args, true) || in_array('-h', $args, true);

if ($help) {
    echo <<<TXT
    BundledCMDB migration tool

      php bin/migrate.php             apply all pending migrations
      php bin/migrate.php --dry-run   list pending migrations, change nothing
      php bin/migrate.php --status    show schema state
      php bin/migrate.php --help      this help

    Connection: DB_SERVER/DB_NAME/DB_USERNAME/DB_PASSWORD (see config.php)

    TXT;
    exit(0);
}

try {
    $pdo = db();
} catch (Throwable $e) {
    fwrite(STDERR, 'Cannot connect to the database: ' . db_error_message($e) . PHP_EOL);
    exit(1);
}

if ($statusOnly) {
    $status = migrate_status($pdo);
    echo 'Schema ready : ' . ($status['ready'] ? 'yes' : 'no') . PHP_EOL;
    echo 'Ledger table : ' . ($status['ledger'] ? 'schema_migrations' : 'missing') . PHP_EOL;
    echo 'Applied      : ' . ($status['applied'] === [] ? '(none)' : implode(', ', $status['applied'])) . PHP_EOL;
    echo 'Pending      : ' . ($status['pending'] === [] ? '(none)' : implode(', ', $status['pending'])) . PHP_EOL;
    exit($status['ready'] ? 0 : 1);
}

try {
    $result = migrate_run($pdo, $dryRun);
} catch (Throwable $e) {
    fwrite(STDERR, 'Migration failed: ' . db_error_message($e) . PHP_EOL);
    foreach (migrate_log_take() as $line) {
        fwrite(STDERR, '  ' . $line . PHP_EOL);
    }
    exit(1);
}

foreach ($result['log'] as $line) {
    echo $line . PHP_EOL;
}

if ($dryRun) {
    echo PHP_EOL . 'Dry run: ' . count($result['applied']) . ' migration(s) pending, nothing changed.' . PHP_EOL;
    exit(0);
}

echo PHP_EOL . 'Applied: ' . count($result['applied']) . ', already present: ' . count($result['skipped']) . PHP_EOL;

$status = migrate_status($pdo);
if (!$status['ready']) {
    fwrite(STDERR, 'Schema still not ready. Pending: ' . implode(', ', $status['pending']) . PHP_EOL);
    exit(1);
}

echo 'Schema is up to date.' . PHP_EOL;
exit(0);
