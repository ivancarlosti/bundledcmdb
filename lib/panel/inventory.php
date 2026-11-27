<?php
declare(strict_types=1);

/**
 * lib/panel/inventory.php — `/panel-api/v1/assets` and `/panel-api/v1/systems`.
 *
 * The two tabs are one resource with a different `kind` (lib/inventory.php), so the
 * handlers exist once and the ten routes are thin wrappers. Everything else mirrors
 * the device grid: capability gate, company scoping, CSRF on writes, optimistic
 * locking through `expected_updated_at`, and the `{rows,total,page,per_page,pages}`
 * list envelope with a `meta` block that echoes the sort and the effective scope.
 */

/** Capability key for a kind: `assets.view`, `systems.delete`, … */
function panel_inventory_capability(string $kind, string $action): string
{
    return inventory_kind_prefix($kind) . '.' . $action;
}

/**
 * Normalise the query string of the list endpoint.
 *
 * @return array{q: string, status: string, category: string, common_usage: ?bool,
 *               company: string, sort: string, dir: string, page: int, per_page: int}
 */
function panel_inventory_params(): array
{
    $page = filter_var($_GET['page'] ?? 1, FILTER_VALIDATE_INT);
    $page = ($page === false || $page < 1) ? 1 : (int) $page;

    $defaultPerPage = 25;
    $maxPerPage = PANEL_MAX_PER_PAGE;
    try {
        $defaultPerPage = max(5, (int) settings_get('general.per_page', 25));
        $maxPerPage = max(5, (int) settings_get('api.max_page_size', PANEL_MAX_PER_PAGE));
    } catch (Throwable) {
        // keep the built-in defaults
    }
    $perPage = filter_var($_GET['per_page'] ?? $defaultPerPage, FILTER_VALIDATE_INT);
    $perPage = $perPage === false ? $defaultPerPage : (int) $perPage;
    $perPage = max(5, min($maxPerPage, $perPage));

    $status = trim((string) ($_GET['status'] ?? ''));
    if ($status !== '' && !in_array($status, inventory_status_values(), true)) {
        throw new ApiError('invalid_filter', 'Unknown status filter.', 400, ['status' => 'invalid_option']);
    }

    $commonUsage = null;
    $rawCommon = $_GET['common_usage'] ?? null;
    if ($rawCommon !== null && $rawCommon !== '') {
        $parsed = filter_var($rawCommon, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        if ($parsed === null) {
            throw new ApiError('invalid_filter', 'common_usage must be true or false.', 400, [
                'common_usage' => 'invalid_boolean',
            ]);
        }
        $commonUsage = $parsed;
    }

    $dir = strtolower(trim((string) ($_GET['dir'] ?? '')));
    if (!in_array($dir, ['asc', 'desc'], true)) {
        $dir = 'desc';
    }

    return [
        'q'            => trim((string) ($_GET['q'] ?? '')),
        'status'       => $status,
        'category'     => trim((string) ($_GET['category'] ?? '')),
        'common_usage' => $commonUsage,
        'company'      => trim((string) ($_GET['company'] ?? '')),
        'sort'         => trim((string) ($_GET['sort'] ?? '')),
        'dir'          => $dir,
        'page'         => $page,
        'per_page'     => $perPage,
    ];
}

/**
 * ORDER BY clause, resolved against the catalogue columns.
 *
 * @param array<string, mixed> $filters
 * @return array{sql: string, sort: string, dir: string}
 *
 * @throws ApiError('invalid_sort', …, 400) for an unknown column
 */
function panel_inventory_order(array $filters): array
{
    $dir = (string) ($filters['dir'] ?? 'desc') === 'asc' ? 'asc' : 'desc';
    $direction = $dir === 'asc' ? 'ASC' : 'DESC';
    $sort = (string) ($filters['sort'] ?? '');
    if ($sort === '') {
        $sort = 'updated_at';
    }
    if (!in_array($sort, inventory_sort_columns(), true)) {
        throw new ApiError('invalid_sort', 'Unknown sort column: ' . $sort . '.', 400, ['sort' => 'unknown_column']);
    }

    return ['sql' => '`' . $sort . '` ' . $direction . ', `id` ' . $direction, 'sort' => $sort, 'dir' => $dir];
}

/**
 * Company of a write, mirroring `panel_device_write_company()`.
 *
 * A bound account can only write inside its own company, an unrestricted one must
 * name a company on create, and an existing row can never move tenants.
 *
 * @param array{email: string, company: string, role: string} $user
 * @param array<string, mixed> $body
 * @param array<string, mixed>|null $existing
 *
 * @throws ApiError('validation_failed', …, 422)
 */
function panel_inventory_write_company(array $user, array $body, ?array $existing): string
{
    $requested = trim((string) ($body['company'] ?? ''));

    if ($existing !== null) {
        $current = trim((string) ($existing['company'] ?? ''));
        if ($requested !== '' && $requested !== $current) {
            throw new ApiError('validation_failed', 'An item cannot be moved to another company.', 422, [
                'company' => 'immutable',
            ]);
        }
        return $current;
    }

    $bound = trim((string) ($user['company'] ?? ''));
    if ($bound !== '') {
        if ($requested !== '' && $requested !== $bound) {
            throw new ApiError('validation_failed', 'You can only create items in your own company.', 422, [
                'company' => 'forbidden',
            ]);
        }
        return $bound;
    }

    if ($requested === '') {
        throw new ApiError('validation_failed', 'A company is required for this account.', 422, [
            'company' => 'required',
        ]);
    }

    return $requested;
}

/** Positive integer id from the route placeholder. */
function panel_inventory_id(array $params, string $kind): int
{
    $raw = (string) ($params['id'] ?? '');
    if (preg_match('/^\d+$/', $raw) !== 1 || (int) $raw < 1) {
        throw new ApiError('not_found', ucfirst(inventory_kind_noun($kind)) . ' not found.', 404);
    }

    return (int) $raw;
}

/**
 * Load one item for a write, honouring the caller's company scope.
 *
 * @param array{email: string, company: string, role: string} $user
 * @return array<string, mixed>
 */
function panel_inventory_for_write(PDO $pdo, array $user, int $id, string $kind): array
{
    $bound = trim((string) ($user['company'] ?? ''));
    $row = inventory_get($pdo, $id, $kind, $bound === '' ? null : $bound);
    if ($row === null) {
        throw new ApiError('not_found', ucfirst(inventory_kind_noun($kind)) . ' not found.', 404);
    }

    return $row;
}

/**
 * Optimistic locking, same contract as the device endpoints.
 *
 * @param array<string, mixed> $row
 *
 * @throws ApiError('stale_record', …, 409) when the row moved on
 */
function panel_inventory_check_version(array $row, mixed $expected): void
{
    if ($expected === null || $expected === '') {
        return;
    }
    $parsed = is_string($expected) ? asset_iso_datetime($expected) : null;
    if ($parsed === null) {
        throw new ApiError('validation_failed', 'expected_updated_at must be an ISO-8601 timestamp.', 422, [
            'expected_updated_at' => 'invalid_datetime',
        ]);
    }

    $current = asset_iso_datetime(isset($row['updated_at']) ? (string) $row['updated_at'] : null);
    if ($current !== $parsed) {
        throw new ApiError(
            'stale_record',
            'This item was modified by someone else. Reload it and try again.',
            409,
            ['expected_updated_at' => 'stale', 'current_updated_at' => (string) $current]
        );
    }
}


/** GET /assets | /systems */
function panel_inventory_index(string $kind): array
{
    $user = session_require_capability(panel_inventory_capability($kind, 'view'));
    $pdo = db();

    $filters = panel_inventory_params();
    $order = panel_inventory_order($filters);
    $result = inventory_list($pdo, $kind, $user, $filters, $order['sql']);

    return [
        'data' => [
            'rows'     => $result['rows'],
            'total'    => $result['total'],
            'page'     => $result['page'],
            'per_page' => $filters['per_page'],
            'pages'    => $result['pages'],
        ],
        'meta' => [
            'kind'         => $kind,
            'sort'         => $order['sort'],
            'dir'          => $order['dir'],
            'filters'      => [
                'q'            => $filters['q'],
                'status'       => $filters['status'],
                'category'     => $filters['category'],
                'common_usage' => $filters['common_usage'],
                // The effective scope, not the requested one: a bound account
                // cannot widen it, so echoing the request would lie.
                'company' => trim((string) ($user['company'] ?? '')) !== ''
                    ? (string) $user['company']
                    : $filters['company'],
            ],
            'generated_at' => app_now_utc(),
        ],
    ];
}

/** GET /assets/{id} | /systems/{id} */
function panel_inventory_show(string $kind, array $params): array
{
    $user = session_require_capability(panel_inventory_capability($kind, 'view'));
    $pdo = db();

    $id = panel_inventory_id($params, $kind);
    $row = panel_inventory_for_write($pdo, $user, $id, $kind);

    return ['data' => inventory_payload($row, inventory_user_emails($pdo, $id))];
}

/** POST /assets | /systems */
function panel_inventory_store(string $kind): array
{
    $user = session_require_capability(panel_inventory_capability($kind, 'create'));
    session_csrf_require();
    $pdo = db();

    $body = request_json_body();
    $company = panel_inventory_write_company($user, $body, null);
    $input = inventory_normalize_input($body, $kind);

    $errors = $input['errors'] + inventory_validate($pdo, $kind, $input['data'], [
        'partial'        => false,
        'company'        => $company,
        'users'          => $input['users'],
        'users_provided' => $input['users_provided'],
    ]);
    if ($errors !== []) {
        throw new ApiError('validation_failed', 'Payload validation failed.', 422, $errors);
    }

    $actor = (string) $user['email'];
    $pdo->beginTransaction();
    try {
        $created = inventory_create($pdo, $kind, $input['data'], $company, $input['users'] ?? [], $actor);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    inventory_audit(
        $pdo,
        $kind,
        $created['id'],
        isset($input['data']['name']) ? (string) $input['data']['name'] : null,
        $company === '' ? null : $company,
        'create',
        $actor,
        $created['changes']
    );

    $row = inventory_get($pdo, $created['id'], $kind, $company === '' ? null : $company);
    if ($row === null) {
        throw new ApiError('server_error', 'The item could not be reloaded after it was created.', 500);
    }

    return [
        'data'   => inventory_payload($row, inventory_user_emails($pdo, $created['id'])),
        'status' => 201,
        'meta'   => ['created' => true],
    ];
}


/** PATCH /assets/{id} | /systems/{id} */
function panel_inventory_update(string $kind, array $params): array
{
    $user = session_require_capability(panel_inventory_capability($kind, 'update'));
    session_csrf_require();
    $pdo = db();

    $id = panel_inventory_id($params, $kind);
    $row = panel_inventory_for_write($pdo, $user, $id, $kind);

    $body = request_json_body();
    panel_inventory_check_version($row, $body['expected_updated_at'] ?? null);

    $company = panel_inventory_write_company($user, $body, $row);
    $input = inventory_normalize_input($body, $kind);

    $errors = $input['errors'] + inventory_validate($pdo, $kind, $input['data'], [
        'partial'        => true,
        'company'        => $company,
        'users'          => $input['users'],
        'users_provided' => $input['users_provided'],
    ]);
    if ($errors !== []) {
        throw new ApiError('validation_failed', 'Payload validation failed.', 422, $errors);
    }

    $actor = (string) $user['email'];
    $pdo->beginTransaction();
    try {
        $saved = inventory_update(
            $pdo,
            $kind,
            $row,
            $input['data'],
            $input['users'] ?? [],
            $input['users_provided'],
            $actor
        );
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    $changes = $saved['changes'];
    if ($saved['assignments']['before'] !== $saved['assignments']['after']) {
        // One diff line, not one per person: the Logs screen renders values as
        // text, so a comma-joined list is what an admin can actually read.
        $changes['users'] = [
            $saved['assignments']['before'] === [] ? null : implode(', ', $saved['assignments']['before']),
            $saved['assignments']['after'] === [] ? null : implode(', ', $saved['assignments']['after']),
        ];
    }
    if ($changes !== []) {
        inventory_audit(
            $pdo,
            $kind,
            $id,
            isset($saved['row']['name']) ? (string) $saved['row']['name'] : null,
            $company === '' ? null : $company,
            'update',
            $actor,
            $changes
        );
    }

    $fresh = inventory_get($pdo, $id, $kind, $company === '' ? null : $company);
    if ($fresh === null) {
        throw new ApiError('server_error', 'The item could not be reloaded after it was saved.', 500);
    }

    return ['data' => inventory_payload($fresh, inventory_user_emails($pdo, $id))];
}

/** DELETE /assets/{id} | /systems/{id} */
function panel_inventory_destroy(string $kind, array $params): array
{
    $user = session_require_capability(panel_inventory_capability($kind, 'delete'));
    session_csrf_require();
    $pdo = db();

    $id = panel_inventory_id($params, $kind);
    $row = panel_inventory_for_write($pdo, $user, $id, $kind);
    $company = trim((string) ($row['company'] ?? ''));
    $label = isset($row['name']) ? (string) $row['name'] : null;

    $pdo->beginTransaction();
    try {
        inventory_delete($pdo, $id);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    // The row is gone, so the audit line keeps the label that identified it.
    inventory_audit(
        $pdo,
        $kind,
        $id,
        $label,
        $company === '' ? null : $company,
        'delete',
        (string) $user['email'],
        ['name' => [$label, null]]
    );

    return ['data' => ['id' => $id, 'kind' => $kind, 'deleted' => true]];
}

// --- Route wrappers -----------------------------------------------------
//
// One pair per tab so `panel_routes()` stays a flat table of named handlers; each
// one is a single call to the generic implementation above.

function panel_assets_index(array $params = []): array
{
    return panel_inventory_index('asset');
}

function panel_assets_show(array $params = []): array
{
    return panel_inventory_show('asset', $params);
}

function panel_assets_create(array $params = []): array
{
    return panel_inventory_store('asset');
}

function panel_assets_update(array $params = []): array
{
    return panel_inventory_update('asset', $params);
}

function panel_assets_delete(array $params = []): array
{
    return panel_inventory_destroy('asset', $params);
}

function panel_systems_index(array $params = []): array
{
    return panel_inventory_index('system');
}

function panel_systems_show(array $params = []): array
{
    return panel_inventory_show('system', $params);
}

function panel_systems_create(array $params = []): array
{
    return panel_inventory_store('system');
}

function panel_systems_update(array $params = []): array
{
    return panel_inventory_update('system', $params);
}

function panel_systems_delete(array $params = []): array
{
    return panel_inventory_destroy('system', $params);
}

