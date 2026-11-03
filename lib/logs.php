<?php
declare(strict_types=1);

/**
 * lib/logs.php — retention and manual purge for the audit log (`asset_events`).
 *
 * The panel has no scheduler, so retention cannot depend on cron: the purge is
 * triggered from two places that are guaranteed to happen,
 *
 *   1. `panel_session_show()` — any authenticated request may start one, and a
 *      "last purge" check (`asset_events` action `log_purge`) keeps it to one
 *      run per `LOGS_PURGE_INTERVAL`. Two workers racing is harmless: each
 *      `DELETE … LIMIT` is atomic and neither can remove a row twice.
 *   2. `bin/purge-logs.php` — the explicit path for operators (and for a
 *      container that may be idle for days).
 *
 * The cutoff is an absolute UTC instant derived from an *inclusive* local date,
 * so "purge before 2026-06-01" removes everything older than 2026-06-01 00:00 in
 * the configured timezone — the interpretation the date picker implies.
 */

/** Minimum time between two automatic purges (12 h: safe for twice-daily use). */
const LOGS_PURGE_INTERVAL = 43200;

/** Rows removed per DELETE statement; keeps a big purge from locking the table. */
const LOGS_PURGE_BATCH = 5000;

/** Retention used when the setting is missing or unreadable. */
const LOGS_RETENTION_DEFAULT = 180;

/**
 * Configured retention in days.
 *
 * A value of 0 (only reachable through the CLI, the UI enforces a minimum)
 * means "keep everything" and disables both the automatic and the manual purge.
 */
function logs_retention_days(): int
{
    $days = LOGS_RETENTION_DEFAULT;
    try {
        $days = (int) settings_get('logs.retention_days', (string) LOGS_RETENTION_DEFAULT);
    } catch (Throwable) {
        // No database (or an un-migrated settings table): keep the default so a
        // broken configuration can never wipe the audit trail.
    }
    return max(0, min(3650, $days));
}

/**
 * UTC instant `$days` before now.
 *
 * @param DateTimeImmutable|null $now reference point (tests pass a fixed clock)
 */
function logs_cutoff(?DateTimeImmutable $now = null, ?int $days = null): DateTimeImmutable
{
    $now ??= new DateTimeImmutable('now', new DateTimeZone('UTC'));
    $days ??= logs_retention_days();
    return $now->setTimezone(new DateTimeZone('UTC'))->sub(new DateInterval('P' . max(0, $days) . 'D'));
}

/**
 * Parse the inclusive local date a purge form submits.
 *
 * Returns the UTC instant at which that day starts, i.e. the exclusive upper
 * boundary of the rows to delete.
 */
function logs_purge_date(string $value): ?DateTimeImmutable
{
    $value = trim($value);
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
        return null;
    }
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value, new DateTimeZone(app_timezone()));
    if ($date === false || $date->format('Y-m-d') !== $value) {
        return null;
    }
    return $date->setTimezone(new DateTimeZone('UTC'));
}


/**
 * Delete audit rows older than `$cutoff`, in chunks.
 *
 * A dry run only counts, so the Logs screen can confirm the blast radius before
 * anything is removed. A real run always appends a `log_purge` row — including
 * when nothing matched, because that row is what tells `logs_purge_due()` the
 * daily check already happened.
 *
 * @return array{deleted: int, cutoff: string, dry_run: bool}
 */
function logs_purge_before(PDO $pdo, DateTimeImmutable $cutoff, string $actor, bool $dryRun = false): array
{
    $cutoffUtc = $cutoff->setTimezone(new DateTimeZone('UTC'));
    $boundary = $cutoffUtc->format('Y-m-d H:i:s');

    if (!asset_events_ready($pdo)) {
        return ['deleted' => 0, 'cutoff' => $boundary, 'dry_run' => $dryRun];
    }

    if ($dryRun) {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM asset_events WHERE created_at < ?');
        $stmt->execute([$boundary]);
        return ['deleted' => (int) $stmt->fetchColumn(), 'cutoff' => $boundary, 'dry_run' => true];
    }

    $deleted = 0;
    $stmt = $pdo->prepare('DELETE FROM asset_events WHERE created_at < ? LIMIT ' . LOGS_PURGE_BATCH);
    do {
        $stmt->execute([$boundary]);
        $batch = $stmt->rowCount();
        $deleted += $batch;
    } while ($batch >= LOGS_PURGE_BATCH);

    asset_event_record(
        $pdo,
        null,
        'log_purge',
        $actor,
        [],
        'Removed ' . $deleted . ' event(s) older than ' . $cutoffUtc->format('Y-m-d H:i:s') . ' UTC',
        ['entity' => 'logs', 'entity_label' => 'audit log']
    );

    return ['deleted' => $deleted, 'cutoff' => $boundary, 'dry_run' => false];
}

/**
 * The most recent purge, or null when the log has never been trimmed.
 *
 * @return array{created_at: string, actor: string, note: ?string}|null
 */
function logs_last_purge(PDO $pdo): ?array
{
    if (!asset_events_ready($pdo)) {
        return null;
    }
    $stmt = $pdo->query("SELECT actor, note, created_at FROM asset_events WHERE action = 'log_purge' ORDER BY id DESC LIMIT 1");
    $row = $stmt === false ? false : $stmt->fetch();
    if ($row === false) {
        return null;
    }
    return [
        'created_at' => asset_iso_datetime((string) $row['created_at']) ?? '',
        'actor'      => (string) $row['actor'],
        'note'       => $row['note'] === null ? null : (string) $row['note'],
    ];
}

/**
 * Run the automatic purge when one is due. At most once per request.
 *
 * Never throws: this is called from page loads, and a retention problem must not
 * turn into a login loop.
 *
 * @return array{deleted: int, cutoff: string, dry_run: bool}|null null when skipped
 */
function logs_purge_due(PDO $pdo, string $actor = 'system'): ?array
{
    static $checked = false;
    if ($checked) {
        return null;
    }
    $checked = true;

    try {
        $days = logs_retention_days();
        if ($days <= 0 || !asset_events_ready($pdo)) {
            return null;
        }

        $last = logs_last_purge($pdo);
        if ($last !== null) {
            $at = strtotime($last['created_at']);
            if ($at !== false && (time() - $at) < LOGS_PURGE_INTERVAL) {
                return null;
            }
        }

        return logs_purge_before($pdo, logs_cutoff(null, $days), $actor);
    } catch (Throwable $e) {
        error_log('[logs] automatic purge skipped: ' . $e->getMessage());
        return null;
    }
}
