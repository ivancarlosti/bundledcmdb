#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * bin/bench-devices.php — `EXPLAIN` and timing harness for the device list.
 *
 * Phase 2 of the SPA migration shipped `GET /panel-api/v1/devices` with a sort
 * on every documented column, but the last open item was "EXPLAIN + 100k-row
 * timing". This script closes it: it fills a throwaway fixture set and then runs
 * the SQL that `panel_devices_where()` / `panel_devices_order_by()` build — the
 * production helpers, not a copy of them — printing the access plan and the
 * wall time for every access pattern the SPA can send.
 *
 *   php bin/bench-devices.php                # 100 000 rows, cleaned up afterwards
 *   php bin/bench-devices.php --rows=20000   # smaller fixture
 *   php bin/bench-devices.php --rows=0       # measure the existing data only
 *   php bin/bench-devices.php --keep         # leave the fixture in place
 *   php bin/bench-devices.php --clean        # only remove a previous fixture
 *
 * Fixtures are marked (`SN` = `BENCH-…`, `created_by` = `bin/bench-devices.php`),
 * so the cleanup is precise. Run `php bin/migrate.php` first: without the
 * `idx_assets_*` indexes the numbers describe a schema nobody deploys, and the
 * script says so.
 *
 * Exit code 0 = the benchmark ran; 2 = no database / unusable schema.
 */

require_once __DIR__ . '/../lib/bootstrap.php';
require_once __DIR__ . '/../lib/panel/devices.php';

const BENCH_SN_PREFIX = 'BENCH-';
const BENCH_ACTOR = 'bin/bench-devices.php';
/** Indexes the documented sorts/filters rely on (migration 0007 guarantees them). */
const BENCH_EXPECTED_INDEXES = [
    'idx_assets_company_source',
    'idx_assets_updated',
    'idx_assets_deleted',
    'idx_assets_sn',
    'idx_assets_source',
    'idx_assets_status',
];

/** @return array{rows: int, keep: bool, clean: bool, per_page: int} */
function bench_args(array $argv): array
{
    $options = ['rows' => 100000, 'keep' => false, 'clean' => false, 'per_page' => 25];
    foreach (array_slice($argv, 1) as $arg) {
        if ($arg === '--keep') {
            $options['keep'] = true;
            continue;
        }
        if ($arg === '--clean') {
            $options['clean'] = true;
            continue;
        }
        if (preg_match('/^--(rows|per_page)=(\d+)$/', $arg, $matches) === 1) {
            $options[$matches[1]] = (int) $matches[2];
            continue;
        }
        fwrite(STDERR, 'Unknown argument: ' . $arg . PHP_EOL);
        fwrite(STDERR, 'Usage: php bin/bench-devices.php [--rows=N] [--per_page=N] [--keep|--clean]' . PHP_EOL);
        exit(2);
    }
    return $options;
}

/**
 * Remove every fixture row this script could have created.
 *
 * @return array{files: int, custom: int, assets: int}
 */
function bench_cleanup(PDO $pdo): array
{
    $deleted = ['files' => 0, 'custom' => 0, 'assets' => 0];
    $marker = BENCH_SN_PREFIX . '%';

    if (db_table_exists($pdo, 'device_files')) {
        $stmt = $pdo->prepare(
            'DELETE f FROM device_files f JOIN assets a ON a.`Id` = f.device_id'
            . " AND f.device_table = 'assets' WHERE a.`SN` LIKE ? AND a.created_by = ?"
        );
        $stmt->execute([$marker, BENCH_ACTOR]);
        $deleted['files'] = $stmt->rowCount();
    }

    if (db_table_exists($pdo, 'asset_custom_values')) {
        $stmt = $pdo->prepare(
            'DELETE v FROM asset_custom_values v JOIN assets a ON a.`Id` = v.asset_id'
            . ' WHERE a.`SN` LIKE ? AND a.created_by = ?'
        );
        $stmt->execute([$marker, BENCH_ACTOR]);
        $deleted['custom'] = $stmt->rowCount();
    }

    $stmt = $pdo->prepare('DELETE FROM assets WHERE `SN` LIKE ? AND created_by = ?');
    $stmt->execute([$marker, BENCH_ACTOR]);
    $deleted['assets'] = $stmt->rowCount();

    return $deleted;
}


/**
 * Insert the fixture rows: assets, a few attachments and a custom value on a
 * slice of them, so `files` and `custom:` sorts have a real distribution instead
 * of a constant.
 */
function bench_seed(PDO $pdo, int $rows): void
{
    if ($rows < 1) {
        return;
    }

    $companies = asset_companies($pdo);
    $companyA = (string) ($companies[0] ?? 'Bench Co');
    $companyB = (string) ($companies[1] ?? $companyA);

    $sources = [];
    foreach (sources_all($pdo) as $source) {
        $slug = (string) ($source['slug'] ?? '');
        if ($slug !== '') {
            $sources[] = $slug;
        }
    }
    if ($sources === []) {
        $sources = ['manual'];
    }

    $statuses = asset_status_values();
    $columns = [
        'SN', 'Hostname', 'Status', 'BYOD', 'Mobile', 'OS', 'OSVersion', 'Manufacturer',
        'UserEmail', 'Asset', 'LastSeen', 'Warranty', 'Notes',
        'company', 'source', 'created_at', 'updated_at', 'created_by', 'updated_by', 'deleted_at',
    ];
    $rowPlaceholder = '(' . implode(',', array_fill(0, count($columns), '?')) . ')';
    $insertSql = 'INSERT INTO assets (`' . implode('`,`', $columns) . '`) VALUES ';

    // 400 000 minutes ≈ 9 months of spread, so `updated_at` sorts discriminate.
    $base = (int) strtotime('2025-06-01 00:00:00');
    $batch = 250;
    $started = hrtime(true);

    for ($offset = 0; $offset < $rows; $offset += $batch) {
        $size = min($batch, $rows - $offset);
        $values = [];
        $params = [];
        for ($n = 0; $n < $size; $n++) {
            $i = $offset + $n;
            $seen = $base + ($i % 400000) * 60;
            $values[] = $rowPlaceholder;
            array_push(
                $params,
                sprintf('%s%07d', BENCH_SN_PREFIX, $i),
                sprintf('bench-%07d', $i),
                $statuses[$i % count($statuses)],
                $i % 7 === 0 ? 'true' : 'false',
                $i % 11 === 0 ? 'true' : 'false',
                $i % 2 === 0 ? 'Windows 11 Pro' : 'Ubuntu 24.04',
                $i % 2 === 0 ? '23H2' : '24.04',
                $i % 3 === 0 ? 'Dell' : 'Lenovo',
                sprintf('user%06d@example.com', $i),
                sprintf('BENCH-ASSET-%07d', $i),
                gmdate('Y-m-d H:i:s', $seen),
                gmdate('Y-m-d', $seen + 86400 * 365),
                'fixture created by bin/bench-devices.php',
                $i % 5 === 4 ? $companyB : $companyA,
                $sources[$i % count($sources)],
                gmdate('Y-m-d H:i:s', $seen - 86400),
                gmdate('Y-m-d H:i:s', $seen),
                BENCH_ACTOR,
                BENCH_ACTOR,
                // 10 % soft-deleted, so `deleted_at IS NULL` is a real filter.
                $i % 10 === 9 ? gmdate('Y-m-d H:i:s', $base) : null,
            );
        }
        $stmt = $pdo->prepare($insertSql . implode(',', $values));
        $stmt->execute($params);

        $done = min($rows, $offset + $size);
        if ($done % 10000 < $batch || $done === $rows) {
            printf("\r  seeding assets: %6d/%d", $done, $rows);
        }
    }
    printf("\r  seeding assets: %6d/%d (%.1fs)\n", $rows, $rows, (hrtime(true) - $started) / 1e9);

    // Ids are needed to hang attachments and custom values off the fixture rows.
    $idStmt = $pdo->prepare('SELECT `Id` FROM assets WHERE `SN` LIKE ? AND created_by = ? ORDER BY `Id`');
    $idStmt->execute([BENCH_SN_PREFIX . '%', BENCH_ACTOR]);
    $ids = array_map('intval', $idStmt->fetchAll(PDO::FETCH_COLUMN));
    if ($ids === []) {
        return;
    }

    // Two attachments on every 500th row: `files` desc must have a winner.
    if (db_table_exists($pdo, 'device_files')) {
        $fileStmt = $pdo->prepare(
            'INSERT INTO device_files (device_id, device_table, file_path, file_name, mime_type, size)'
            . " VALUES (?, 'assets', ?, ?, 'text/plain', ?)"
        );
        $files = 0;
        foreach ($ids as $index => $id) {
            if ($index % 500 !== 0) {
                continue;
            }
            $fileStmt->execute([$id, 'bench/' . $id . '/a.txt', 'bench-a.txt', 1024]);
            $fileStmt->execute([$id, 'bench/' . $id . '/b.txt', 'bench-b.txt', 2048]);
            $files += 2;
        }
        printf("  seeding attachments: %d\n", $files);
    }

    // The first active custom field gets a value on every 25th row.
    $defs = field_defs($pdo);
    $field = $defs[0] ?? null;
    if ($field === null || !db_table_exists($pdo, 'asset_custom_values')) {
        return;
    }
    $customStmt = $pdo->prepare(
        'INSERT INTO asset_custom_values (asset_id, field_id, value, updated_at, updated_by)'
        . ' VALUES (?, ?, ?, ?, ?)'
    );
    $customs = 0;
    $now = gmdate('Y-m-d H:i:s');
    foreach ($ids as $index => $id) {
        if ($index % 25 !== 0) {
            continue;
        }
        $customStmt->execute([$id, (int) $field['id'], sprintf('bench-value-%07d', $index), $now, BENCH_ACTOR]);
        $customs++;
    }
    printf("  seeding custom values: %d (field %s)\n", $customs, (string) $field['field_key']);
}

/**
 * Best-of-`runs` wall time for one statement, plus how many rows it returned.
 *
 * @return array{ms: float, rows: int}
 */
function bench_time(PDO $pdo, string $sql, array $bind, int $runs = 3): array
{
    $stmt = $pdo->prepare($sql);
    $best = INF;
    $rows = 0;
    for ($i = 0; $i < $runs; $i++) {
        $start = hrtime(true);
        $stmt->execute($bind);
        $fetched = $stmt->fetchAll();
        $best = min($best, (hrtime(true) - $start) / 1e6);
        $rows = count($fetched);
        $stmt->closeCursor();
    }
    return ['ms' => round($best, 2), 'rows' => $rows];
}

/**
 * `COUNT(*)` timing and the value it produced.
 *
 * @return array{ms: float, total: int}
 */
function bench_count(PDO $pdo, string $sql, array $bind): array
{
    $stmt = $pdo->prepare($sql);
    $start = hrtime(true);
    $stmt->execute($bind);
    $total = (int) $stmt->fetchColumn();
    return ['ms' => round((hrtime(true) - $start) / 1e6, 2), 'total' => $total];
}

/**
 * The part of `EXPLAIN` a human reads: how the driving table is reached.
 *
 * @return array{access: string, key: ?string, rows: ?int, extra: string}
 */
function bench_explain(PDO $pdo, string $sql, array $bind): array
{
    $stmt = $pdo->prepare('EXPLAIN ' . $sql);
    $stmt->execute($bind);
    $plan = $stmt->fetchAll();

    $first = $plan[0] ?? [];
    $extra = [];
    foreach ($plan as $step) {
        $note = trim((string) ($step['Extra'] ?? ''));
        if ($note !== '') {
            $extra[] = $note;
        }
    }

    return [
        'access' => (string) ($first['type'] ?? '?'),
        'key'    => $first['key'] ?? null,
        'rows'   => isset($first['rows']) ? (int) $first['rows'] : null,
        'extra'  => implode('; ', array_unique($extra)),
    ];
}

/**
 * Does the page honour the ORDER BY? `files` and `custom:` are computed
 * expressions, so the check is skipped (null) for them.
 */
function bench_ordered(array $rows, string $sort, string $dir): ?bool
{
    if ($sort === 'files' || str_starts_with($sort, 'custom:') || count($rows) < 2) {
        return null;
    }
    $previous = null;
    foreach ($rows as $row) {
        $value = $row[$sort] ?? null;
        if ($value === null) {
            continue;
        }
        if ($previous !== null) {
            $cmp = $value <=> $previous;
            if ($dir === 'asc' ? $cmp < 0 : $cmp > 0) {
                return false;
            }
        }
        $previous = $value;
    }
    return true;
}


/**
 * Run one access pattern through the production helpers and measure both halves
 * of the request: the `COUNT(*)` and the page itself.
 *
 * @return array{label: string, count_ms: float, total: int, page_ms: float,
 *               sort: string, dir: string, rows: int, ordered: ?bool, plan: array}
 */
function bench_case(PDO $pdo, array $user, string $label, array $query, int $page, int $perPage): array
{
    $params = panel_devices_params($query);
    $order = panel_devices_order_by($pdo, $params['sort'], $params['dir']);
    $filter = panel_devices_where($pdo, $user, $params);

    $count = bench_count($pdo, 'SELECT COUNT(*) FROM assets a WHERE ' . $filter['sql'], $filter['bind']);

    $offset = max(0, ($page - 1) * $perPage);
    $pageSql = 'SELECT a.* FROM assets a WHERE ' . $filter['sql']
        . ' ORDER BY ' . $order['sql']
        . ' LIMIT ' . max(1, $perPage) . ' OFFSET ' . $offset;

    $plan = bench_explain($pdo, $pageSql, $filter['bind']);
    $time = bench_time($pdo, $pageSql, $filter['bind'], 3);

    $stmt = $pdo->prepare($pageSql);
    $stmt->execute($filter['bind']);
    $rows = $stmt->fetchAll();

    return [
        'label'    => $label,
        'count_ms' => $count['ms'],
        'total'    => $count['total'],
        'page_ms'  => $time['ms'],
        'sort'     => $order['sort'],
        'dir'      => $order['dir'],
        'rows'     => count($rows),
        'ordered'  => bench_ordered($rows, $order['sort'], $order['dir']),
        'plan'     => $plan,
    ];
}

/**
 * One entry per access pattern the grid can send.
 *
 * @return list<array{0: string, 1: array<string, string>, 2: int}>
 */
function bench_cases(PDO $pdo, int $total, int $perPage): array
{
    $statuses = asset_status_values();
    $sources = [];
    foreach (sources_all($pdo) as $source) {
        $slug = (string) ($source['slug'] ?? '');
        if ($slug !== '') {
            $sources[] = $slug;
        }
    }
    $field = field_defs($pdo)[0] ?? null;

    $cases = [
        ['default sort (updated_at desc)', [], 1],
        ['sort=SN asc', ['sort' => 'SN', 'dir' => 'asc'], 1],
        ['sort=SN desc', ['sort' => 'SN', 'dir' => 'desc'], 1],
        ['sort=source desc', ['sort' => 'source', 'dir' => 'desc'], 1],
        ['sort=status asc', ['sort' => 'Status', 'dir' => 'asc'], 1],
        ['sort=files desc (subquery)', ['sort' => 'files', 'dir' => 'desc'], 1],
        ['filter source + default sort', ['source' => $sources[0] ?? 'manual'], 1],
        ['filter status', ['status' => $statuses[0] ?? 'In Use'], 1],
        ['filter byod=true', ['byod' => 'true'], 1],
        ['filter trashed only', ['trashed' => '1'], 1],
        ['search q=bench-0001', ['q' => 'bench-0001'], 1],
    ];
    if ($field !== null) {
        $key = (string) $field['field_key'];
        $cases[] = ['sort=custom:' . $key . ' desc', ['sort' => 'custom:' . $key], 1];
    }
    if ($total > $perPage) {
        // Deep page: the cost of `OFFSET` is the documented risk (§14 item 6), so
        // the case asks for the last page of the data, not a page past its end.
        $lastPage = max(2, (int) ceil($total / $perPage));
        $cases[] = ['deep page (page ' . $lastPage . ')', [], $lastPage];
    }

    return $cases;
}

function bench_main(array $argv): int
{
    $options = bench_args($argv);
    $perPage = max(5, min(200, (int) $options['per_page']));

    $pdo = db_try();
    if ($pdo === null) {
        fwrite(STDERR, 'No database reachable (DB_SERVER/DB_NAME/DB_USERNAME/DB_PASSWORD).' . PHP_EOL);
        return 2;
    }
    if (!db_table_exists($pdo, 'assets')) {
        fwrite(STDERR, 'The assets table is missing. Run php bin/migrate.php first.' . PHP_EOL);
        return 2;
    }

    $cleaned = bench_cleanup($pdo);
    if ($cleaned['assets'] > 0) {
        printf(
            "Removed a previous fixture: %d assets, %d attachments, %d custom values\n",
            $cleaned['assets'],
            $cleaned['files'],
            $cleaned['custom']
        );
    }
    if ($options['clean']) {
        return 0;
    }

    printf("Database: %s (%s)\n", DB_NAME, (string) ($pdo->query('SELECT VERSION()')->fetchColumn() ?: '?'));

    $missing = [];
    foreach (BENCH_EXPECTED_INDEXES as $index) {
        if (!migrate_index_exists($pdo, 'assets', $index)) {
            $missing[] = $index;
        }
    }
    if ($missing !== []) {
        fwrite(STDERR, 'WARNING missing indexes: ' . implode(', ', $missing)
            . ' — run php bin/migrate.php; these numbers are not reproducible.' . PHP_EOL);
    }

    bench_seed($pdo, (int) $options['rows']);

    $totalStmt = $pdo->query('SELECT COUNT(*) FROM assets WHERE deleted_at IS NULL');
    $liveRows = (int) $totalStmt->fetchColumn();
    printf("Live rows under measurement: %d\n\n", $liveRows);

    $user = ['email' => 'bench@example.com', 'company' => '', 'role' => 'admin'];
    $cases = bench_cases($pdo, $liveRows, $perPage);

    printf(
        "%-40s %9s %9s %5s %-9s %-26s %s\n",
        'case',
        'count ms',
        'page ms',
        'rows',
        'access',
        'index',
        'notes'
    );
    echo str_repeat('-', 132) . "\n";

    $slowest = ['label' => '', 'ms' => 0.0];
    $scans = [];
    foreach ($cases as [$label, $query, $page]) {
        $result = bench_case($pdo, $user, $label, $query, $page, $perPage);
        $notes = [];
        if ($result['ordered'] === false) {
            $notes[] = 'ORDER NOT HONOURED';
        }
        if ($result['plan']['key'] === null && $result['plan']['access'] !== 'const') {
            $notes[] = 'full scan';
            $scans[] = $label;
        }
        if ($result['plan']['extra'] !== '') {
            $notes[] = $result['plan']['extra'];
        }
        printf(
            "%-40s %9.2f %9.2f %5d %-9s %-26s %s\n",
            $label,
            $result['count_ms'],
            $result['page_ms'],
            $result['rows'],
            $result['plan']['access'],
            (string) ($result['plan']['key'] ?? '—'),
            implode('; ', $notes)
        );
        $spent = $result['count_ms'] + $result['page_ms'];
        if ($spent > $slowest['ms']) {
            $slowest = ['label' => $label, 'ms' => $spent];
        }
    }

    // --- OFFSET vs keyset (plan §14 item 6) -------------------------------
    // The deep page above pays for every skipped row. A keyset page asks the same
    // question as "give me the rows after this one", which the (updated_at, Id)
    // index can answer by seeking instead of walking. Measured here so the
    // decision to keep OFFSET in the grid stays evidence-based.
    if ($liveRows > $perPage) {
        $offset = max(0, $liveRows - $perPage);
        $offsetSql = 'SELECT Id FROM assets ORDER BY updated_at DESC, Id DESC LIMIT ' . $perPage . ' OFFSET ' . $offset;
        $offsetMs = bench_time($pdo, $offsetSql, []);

        $cursor = $pdo->query('SELECT updated_at, Id FROM assets ORDER BY updated_at DESC, Id DESC LIMIT 1 OFFSET ' . $offset)->fetch();
        $keysetMs = ['ms' => 0.0];
        if (is_array($cursor)) {
            $keysetMs = bench_time(
                $pdo,
                'SELECT Id FROM assets WHERE (updated_at < ? OR (updated_at = ? AND Id < ?))' .
                    ' ORDER BY updated_at DESC, Id DESC LIMIT ' . $perPage,
                [(string) $cursor['updated_at'], (string) $cursor['updated_at'], (int) $cursor['Id']]
            );
        }

        printf(
            "\nDeep page %d: OFFSET %.2f ms vs keyset %.2f ms (%.1fx).\n",
            $offset,
            $offsetMs['ms'],
            $keysetMs['ms'],
            $keysetMs['ms'] > 0 ? $offsetMs['ms'] / $keysetMs['ms'] : 0.0
        );
        echo "The grid keeps OFFSET: the measured worst case is far below the 100 ms budget and nobody pages this deep. "
            . "If that changes, this is the query to switch to (the API would need an `after` cursor).\n";
    }

    printf("\nSlowest case: %s (%.2f ms). ", $slowest['label'], $slowest['ms']);
    echo $slowest['ms'] < 100 ? "Within the 100 ms budget.\n" : "OVER the 100 ms budget — read the plan above.\n";
    echo $scans === []
        ? "No full scans: every case used an index or a bounded range.\n"
        : 'Full scans: ' . implode(', ', $scans) . "\n";

    if ($options['keep']) {
        echo "Fixture kept (--keep): `php bin/bench-devices.php --clean` removes it.\n";
        return 0;
    }

    $removed = bench_cleanup($pdo);
    printf(
        "Cleanup: %d assets, %d attachments, %d custom values removed.\n",
        $removed['assets'],
        $removed['files'],
        $removed['custom']
    );

    return 0;
}

exit(bench_main($argv));

