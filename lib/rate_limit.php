<?php
declare(strict_types=1);

/**
 * lib/rate_limit.php — fixed-window counters for panel writes.
 *
 * Why a table and not APCu/Redis: the image ships neither, and PHP-FPM spreads a
 * user's requests over several workers, so an in-process counter would let a
 * client through roughly N × workers times. A single row per bucket in MariaDB is
 * the smallest thing that is correct across workers and survives a restart.
 *
 * The window is deliberately coarse (fixed, not sliding): one upsert per write and
 * no background job. A client that lands exactly on a window boundary gets a
 * short burst of two windows, which is fine for the purpose — protecting the
 * database from a runaway client or a stuck retry loop, not metering traffic.
 */

/** Start of the fixed window that contains `$now`. Pure, so it is unit-testable. */
function rate_limit_window(int $now, int $windowSeconds): int
{
    $windowSeconds = max(1, $windowSeconds);
    return intdiv($now, $windowSeconds) * $windowSeconds;
}

/** Seconds until the window that contains `$now` rolls over (never 0). Pure. */
function rate_limit_retry_after(int $now, int $windowStart, int $windowSeconds): int
{
    $elapsed = max(0, $now - $windowStart);
    return max(1, max(1, $windowSeconds) - $elapsed);
}

/**
 * Count one hit against `$bucket` and report whether it is allowed.
 *
 * @return array{allowed: bool, hits: int, limit: int, window: int, retry_after: int}
 */
function rate_limit_hit(PDO $pdo, string $bucket, int $limit, int $windowSeconds = 60, ?int $now = null): array
{
    $limit = max(1, $limit);
    $windowSeconds = max(1, $windowSeconds);
    $now = $now ?? time();
    $windowStart = rate_limit_window($now, $windowSeconds);

    if (!db_table_exists($pdo, 'rate_limits')) {
        // A database that has not run 0009 yet must not lock anyone out.
        return [
            'allowed' => true, 'hits' => 0, 'limit' => $limit,
            'window' => $windowSeconds, 'retry_after' => 0,
        ];
    }

    $stmt = $pdo->prepare(
        'INSERT INTO rate_limits (bucket, window_start, hits) VALUES (?, ?, 1)
         ON DUPLICATE KEY UPDATE
            hits = IF(window_start = VALUES(window_start), hits + 1, 1),
            window_start = VALUES(window_start)'
    );
    $stmt->execute([$bucket, $windowStart]);

    $read = $pdo->prepare('SELECT window_start, hits FROM rate_limits WHERE bucket = ?');
    $read->execute([$bucket]);
    $row = $read->fetch() ?: ['window_start' => $windowStart, 'hits' => 0];
    $hits = (int) $row['hits'];
    $start = (int) $row['window_start'];

    return [
        'allowed'     => $hits <= $limit,
        'hits'        => $hits,
        'limit'       => $limit,
        'window'      => $windowSeconds,
        'retry_after' => $hits <= $limit ? 0 : rate_limit_retry_after($now, $start, $windowSeconds),
    ];
}

/** Forget every bucket (tests, and the maintenance "reset" action). */
function rate_limit_reset(PDO $pdo, ?string $bucket = null): void
{
    if (!db_table_exists($pdo, 'rate_limits')) {
        return;
    }
    if ($bucket === null) {
        $pdo->exec('DELETE FROM rate_limits');
        return;
    }
    $pdo->prepare('DELETE FROM rate_limits WHERE bucket = ?')->execute([$bucket]);
}

/**
 * Enforce the panel write limit for the signed-in user.
 *
 * Reads are never counted: the grid polls `/devices` and `/session` on every
 * navigation, and limiting those would break the panel long before it protected
 * anything. Anonymous requests are skipped too — the handlers answer `401`
 * themselves, and keying a bucket on nothing would let one client exhaust it for
 * everybody.
 *
 * @throws ApiError `rate_limited` (429) with `Retry-After`
 */
function rate_limit_require_panel(string $method): void
{
    if (in_array(strtoupper($method), ['GET', 'HEAD', 'OPTIONS'], true)) {
        return;
    }

    $email = session_user_email();
    if ($email === null) {
        return;
    }

    $limit = 120;
    try {
        $limit = (int) settings_get('api.panel_rate_limit', 120);
    } catch (Throwable) {
        // Settings table unreachable: keep the default instead of failing closed.
    }

    $result = rate_limit_hit(db(), 'panel:' . strtolower($email), $limit);
    if ($result['allowed']) {
        return;
    }

    header('Retry-After: ' . $result['retry_after']);
    throw new ApiError(
        'rate_limited',
        'Too many changes at once. Try again in ' . $result['retry_after'] . ' s.',
        429
    );
}
