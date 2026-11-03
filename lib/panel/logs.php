<?php
declare(strict_types=1);

/**
 * lib/panel/logs.php — `/panel-api/v1/logs*` handlers.
 *
 *   GET  /logs          audit rows (admin+)
 *   POST /logs/purge    delete older rows, or count them with `dry_run` (superadmin)
 *
 * Visibility follows the rest of the panel: the screens behind `admin` already
 * expose every company, so an account bound to a company is restricted to its
 * own rows *plus* the global (`company IS NULL`) ones — a company admin has to
 * see that somebody changed a global setting, but not what another tenant did.
 * Retention itself is an instance-wide policy, so only a superadmin may destroy
 * rows.
 */

/** Page size when the request does not ask for one. */
const PANEL_LOGS_PER_PAGE = 50;

/**
 * Turn one `from`/`to` query parameter into a UTC boundary.
 *
 * The date is inclusive in the user's timezone, so `to = 2026-11-05` becomes
 * `< 2026-11-06 00:00` in UTC — a range that behaves the way the date inputs
 * suggest, including across a DST change.
 *
 * @throws ApiError when the value is not a calendar date
 */
function panel_logs_boundary(string $value, int $addDays, string $field): ?string
{
    $value = trim($value);
    if ($value === '') {
        return null;
    }
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value, new DateTimeZone(app_timezone()));
    if ($date === false || $date->format('Y-m-d') !== $value) {
        throw new ApiError('invalid_filter', 'Expected a date as YYYY-MM-DD.', 400, [$field => 'invalid_date']);
    }
    if ($addDays !== 0) {
        $date = $date->modify('+' . $addDays . ' day');
    }
    return $date->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
}

/**
 * Validated filters for `GET /logs`.
 *
 * @param array<string, mixed> $query
 * @return array{q: string, action: string, entity: string, actor: string,
 *               from: ?string, to: ?string, company: ?string, page: int, per_page: int}
 * @throws ApiError
 */
function panel_logs_params(array $query): array
{
    // A query string can repeat a key (`?q[]=a`), which arrives as an array and
    // would warn on the string cast. Only scalars are filters.
    $text = static function (string $key) use ($query): string {
        $value = $query[$key] ?? '';
        return is_string($value) ? trim($value) : '';
    };

    $action = $text('action');
    if ($action !== '' && !in_array($action, ASSET_EVENT_ACTIONS, true)) {
        throw new ApiError('invalid_filter', 'Unknown action.', 400, ['action' => 'invalid_option']);
    }

    $entity = $text('entity');
    if ($entity !== '' && !in_array($entity, ASSET_EVENT_ENTITIES, true)) {
        throw new ApiError('invalid_filter', 'Unknown entity type.', 400, ['entity' => 'invalid_option']);
    }

    $max = 200;
    try {
        $max = max(1, (int) settings_get('api.max_page_size', 200));
    } catch (Throwable) {
        // keep the default
    }
    $perPage = (int) ($query['per_page'] ?? 0);
    $perPage = $perPage > 0 ? min($max, $perPage) : min($max, PANEL_LOGS_PER_PAGE);

    $company = $text('company');

    return [
        'q'        => mb_substr($text('q'), 0, 120),
        'action'   => $action,
        'entity'   => $entity,
        'actor'    => mb_substr($text('actor'), 0, 191),
        'from'     => panel_logs_boundary($text('from'), 0, 'from'),
        'to'       => panel_logs_boundary($text('to'), 1, 'to'),
        'company'  => $company === '' ? null : mb_substr($company, 0, 191),
        'page'     => max(1, (int) ($query['page'] ?? 1)),
        'per_page' => $perPage,
    ];
}

/**
 * Values the filter selects offer: the actions the code can write plus the
 * entities/companies actually present in the table, so an option list never
 * goes empty after a purge.
 *
 * @return array{actions: list<string>, entities: list<string>, companies: list<string>}
 */
function panel_logs_filter_options(PDO $pdo): array
{
    $entities = [];
    $companies = [];
    if (asset_events_extended($pdo)) {
        $rows = $pdo->query('SELECT DISTINCT entity FROM asset_events WHERE entity IS NOT NULL ORDER BY entity');
        foreach ($rows === false ? [] : $rows->fetchAll() as $row) {
            $entities[] = (string) $row['entity'];
        }
        $rows = $pdo->query('SELECT DISTINCT company FROM asset_events WHERE company IS NOT NULL ORDER BY company');
        foreach ($rows === false ? [] : $rows->fetchAll() as $row) {
            $companies[] = (string) $row['company'];
        }
    }

    return [
        'actions'   => ASSET_EVENT_ACTIONS,
        'entities'  => $entities === [] ? ASSET_EVENT_ENTITIES : $entities,
        'companies' => $companies,
    ];
}

/** GET /logs */
function panel_logs_index(array $params = []): array
{
    $user = session_require_role(['admin', 'superadmin']);
    $pdo = db();
    $filters = panel_logs_params($_GET);

    $bound = (string) ($user['company'] ?? '');
    if ($bound !== '') {
        // A company-bound admin cannot widen the scope with `?company=`.
        $filters['company_or_global'] = $bound;
        $filters['company'] = null;
    }

    $result = asset_events_page($pdo, $filters);

    $options = panel_logs_filter_options($pdo);
    if ($bound !== '') {
        // A tenant-bound admin must not learn the other tenants' names from the
        // filter options, even though the selector is hidden client-side.
        $options['companies'] = [];
    }

    return [
        'data' => [
            'rows'     => $result['rows'],
            'total'    => $result['total'],
            'page'     => $result['page'],
            'per_page' => $result['per_page'],
            'pages'    => $result['pages'],
        ],
        'meta' => [
            'filters'        => [
                'q'       => $filters['q'],
                'action'  => $filters['action'],
                'entity'  => $filters['entity'],
                'actor'   => $filters['actor'],
                'from'    => $filters['from'],
                'to'      => $filters['to'],
                'company' => $filters['company'],
            ],
            'scope'          => $bound !== '' ? $bound : 'all',
            'filter_options' => $options,
            'retention_days' => logs_retention_days(),
            'last_purge'     => logs_last_purge($pdo),
        ],
    ];
}

/**
 * POST /logs/purge
 *
 * Body: `{"before": "YYYY-MM-DD", "dry_run": true}`. The dry run counts the rows
 * the real call would remove, so the UI can ask for confirmation with a number
 * instead of a vague warning.
 */
function panel_logs_purge(array $params = []): array
{
    $user = session_require_role(['superadmin']);
    session_csrf_require();
    $pdo = db();

    $body = request_json_body();
    $raw = trim((string) ($body['before'] ?? ''));
    if ($raw === '') {
        throw new ApiError('validation_failed', 'A date is required.', 422, ['before' => 'required']);
    }

    $cutoff = logs_purge_date($raw);
    if ($cutoff === null) {
        throw new ApiError('validation_failed', 'Expected a date as YYYY-MM-DD.', 422, ['before' => 'invalid_date']);
    }

    $today = new DateTimeImmutable('today', new DateTimeZone(app_timezone()));
    if ($cutoff->setTimezone(new DateTimeZone(app_timezone())) > $today) {
        throw new ApiError('validation_failed', 'The date cannot be in the future.', 422, ['before' => 'future']);
    }

    $dryRun = !empty($body['dry_run']);
    $result = logs_purge_before($pdo, $cutoff, (string) $user['email'], $dryRun);

    return [
        'data' => [
            'deleted'        => $result['deleted'],
            'before'         => $raw,
            'cutoff'         => $result['cutoff'],
            'dry_run'        => $result['dry_run'],
            'last_purge'     => logs_last_purge($pdo),
            'retention_days' => logs_retention_days(),
        ],
    ];
}
