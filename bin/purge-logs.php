#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * bin/purge-logs.php — apply the audit retention policy from the command line.
 *
 * The panel trims `asset_events` on its own (`lib/logs.php` runs the same purge
 * from `GET /session`, at most once per 12 h), so this script is the explicit
 * path: a container that is idle for days, a one-off cleanup, or a dry run that
 * shows what the configured retention would remove.
 *
 * Usage:
 *   php bin/purge-logs.php                 purge using `logs.retention_days`
 *   php bin/purge-logs.php --dry-run       count only, change nothing
 *   php bin/purge-logs.php --before=2026-06-01   purge older than that day
 *   php bin/purge-logs.php --status        show retention, last purge, row count
 *   php bin/purge-logs.php --help
 *
 * Exit codes: 0 success, 1 error, 2 bad usage.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}

require_once __DIR__ . '/../lib/bootstrap.php';

$args = array_slice($argv, 1);
$dryRun = in_array('--dry-run', $args, true);
$statusOnly = in_array('--status', $args, true);
$help = in_array('--help', $args, true) || in_array('-h', $args, true);

$before = null;
foreach ($args as $arg) {
    if (str_starts_with($arg, '--before=')) {
        $before = substr($arg, strlen('--before='));
    }
}

if ($help) {
    echo <<<TXT
    BundledCMDB audit-log retention

      php bin/purge-logs.php                      purge using logs.retention_days
      php bin/purge-logs.php --dry-run            count what would be removed
      php bin/purge-logs.php --before=YYYY-MM-DD  purge everything older than that day
      php bin/purge-logs.php --status             show retention, last purge and row count
      php bin/purge-logs.php --help               this help

    The date is inclusive in the configured timezone (APP_TIMEZONE / general.timezone).
    Exit codes: 0 success, 1 error, 2 bad usage.

    TXT;
    exit(0);
}

try {
    $pdo = db();
} catch (Throwable $e) {
    fwrite(STDERR, 'Cannot connect to the database: ' . db_error_message($e) . PHP_EOL);
    exit(1);
}

$days = logs_retention_days();
$last = logs_last_purge($pdo);
$total = 0;
if (asset_events_ready($pdo)) {
    $total = (int) $pdo->query('SELECT COUNT(*) FROM asset_events')->fetchColumn();
}

if ($statusOnly) {
    $next = 'now';
    if ($last !== null) {
        $at = strtotime($last['created_at']);
        $next = $at === false ? 'unknown' : gmdate('Y-m-d H:i', $at + LOGS_PURGE_INTERVAL) . ' UTC';
    }
    echo 'Retention   : ' . ($days > 0 ? $days . ' days' : 'disabled (keep everything)') . PHP_EOL;
    echo 'Events      : ' . $total . PHP_EOL;
    echo 'Last purge  : ' . ($last === null ? '(never)' : $last['created_at'] . ' by ' . $last['actor']) . PHP_EOL;
    echo 'Next due    : ' . ($days > 0 ? $next : 'never (retention disabled)') . PHP_EOL;
    exit(0);
}

$cutoff = null;
if ($before !== null && $before !== '') {
    $cutoff = logs_purge_date($before);
    if ($cutoff === null) {
        fwrite(STDERR, 'Invalid --before date (expected YYYY-MM-DD).' . PHP_EOL);
        exit(2);
    }
} else {
    if ($days <= 0) {
        echo 'Retention is disabled (logs.retention_days = 0); nothing to purge.' . PHP_EOL;
        exit(0);
    }
    $cutoff = logs_cutoff(null, $days);
}

$result = logs_purge_before($pdo, $cutoff, 'cli:' . (getenv('USER') ?: 'purge-logs'), $dryRun);

echo ($dryRun ? 'Would remove ' : 'Removed ')
    . $result['deleted']
    . ' event(s) older than ' . $result['cutoff'] . ' UTC' . PHP_EOL;

if (!$dryRun) {
    echo 'Retention   : ' . ($days > 0 ? $days . ' days' : 'disabled') . PHP_EOL;
    echo 'Events left : ' . ($total - $result['deleted']) . PHP_EOL;
}

exit(0);
