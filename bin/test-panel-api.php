#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * bin/test-panel-api.php — integration tests for the panel API.
 *
 * Drives the real handlers (`lib/panel/*.php`) against the configured database
 * and cleans up after itself: every device it creates is hard-deleted, so it can
 * run against a development database as often as needed.
 *
 * What it covers (the phase 3 acceptance list):
 *   - routing: the literal paths that could be read as a `{placeholder}`
 *     (`/settings/fields/reorder`, `/settings/api-keys/{id}/rotate`, …) — these
 *     run without a database, so a broken route table still fails the gate;
 *   - role matrix: user/manager/admin/superadmin for create, update, delete,
 *     restore, hard delete and attachment upload;
 *   - validation: unknown fields, forbidden fields, required SN, unknown source
 *     and custom-field errors, all mapped back to snake_case keys;
 *   - optimistic locking: `expected_updated_at` → 409 stale_record;
 *   - company isolation: a manager cannot see or write another company's device;
 *   - soft delete → hidden from the list → restore → hard delete;
 *   - CSRF: a mutation without the header is 419;
 *   - read API: unknown sort is 400, `sort=SN` is echoed in `meta`.
 *
 * Usage: php bin/test-panel-api.php        (exit 0 pass/skip, 1 failure, 2 wrong env)
 */

require_once __DIR__ . '/../lib/bootstrap.php';
require_once __DIR__ . '/../lib/panel/routes.php';
require_once __DIR__ . '/../lib/panel/session.php';
require_once __DIR__ . '/../lib/panel/meta.php';
require_once __DIR__ . '/../lib/panel/devices.php';
require_once __DIR__ . '/../lib/panel/device_writes.php';
require_once __DIR__ . '/../lib/panel/settings.php';

app_boot();

if (app_env() === 'production') {
    fwrite(STDERR, "Refusing to run the write tests with APP_ENV=production.\n");
    exit(2);
}

$GLOBALS['passes'] = 0;
$GLOBALS['failures'] = 0;

function check(bool $ok, string $label, string $detail = ''): void
{
    if ($ok) {
        $GLOBALS['passes']++;
        fwrite(STDOUT, "  ok   {$label}\n");
        return;
    }
    $GLOBALS['failures']++;
    fwrite(STDOUT, "  FAIL {$label}" . ($detail === '' ? '' : "  ({$detail})") . "\n");
}

/**
 * The handler a request would reach, or null when no route matches.
 *
 * Mirrors `panel_dispatch()`: the table is walked in order and the first
 * pattern+method hit wins, so a placeholder registered before a literal path
 * silently swallows it.
 */
function route_for(string $method, string $path): ?string
{
    foreach (panel_routes() as [$routeMethod, $pattern, $handler]) {
        if ($routeMethod === $method && panel_match($pattern, $path) !== null) {
            return $handler;
        }
    }
    return null;
}

// --- routing (no database needed) -------------------------------------------
// Every literal segment that could also be read as a `{placeholder}` is pinned
// here: `/settings/fields/reorder` must not be routed as field id "reorder",
// and `/settings/api-keys/{id}/rotate` must not fall through to `{id}`.
$requestUri = $_SERVER['REQUEST_URI'] ?? '/';
foreach ([
    ['GET',   '/settings',                          'panel_settings_show'],
    ['PATCH', '/settings',                          'panel_settings_update'],
    ['GET',   '/settings/sources',                  'panel_settings_sources_index'],
    ['POST',  '/settings/sources',                  'panel_settings_sources_store'],
    ['PATCH', '/settings/sources/intune',           'panel_settings_sources_update'],
    ['GET',   '/settings/fields',                   'panel_settings_fields_index'],
    ['POST',  '/settings/fields/reorder',           'panel_settings_fields_reorder'],
    ['PATCH', '/settings/fields/12',                'panel_settings_fields_update'],
    ['DELETE', '/settings/fields/12',               'panel_settings_fields_delete'],
    ['POST',  '/settings/api-keys',                 'panel_settings_keys_store'],
    ['POST',  '/settings/api-keys/9/rotate',        'panel_settings_keys_rotate'],
    ['PATCH', '/settings/api-keys/9',               'panel_settings_keys_update'],
    ['GET',   '/settings/users',                    'panel_settings_users_index'],
    ['PATCH', '/settings/users/a%40example.com',    'panel_settings_users_update'],
    ['GET',   '/settings/maintenance',              'panel_settings_maintenance_show'],
    ['POST',  '/settings/maintenance/migrate',      'panel_settings_maintenance_migrate'],
    // Pre-existing literals, kept here so the ordering rule has one home.
    ['GET',   '/devices/export',                    'panel_devices_export'],
    ['POST',  '/devices/bulk',                      'panel_devices_bulk'],
    ['PATCH', '/devices/7',                         'panel_devices_update'],
] as [$method, $path, $expected]) {
    $actual = route_for($method, $path);
    check($actual === $expected, "routing: {$method} {$path}", (string) $actual);
}

check(route_for('GET', '/settings/nope') === null, 'routing: unknown settings path is 404');
check(route_for('DELETE', '/settings') === null, 'routing: DELETE /settings falls through to 405');
check(
    panel_match('/settings/users/{email}', '/settings/users/a%40example.com') === ['email' => 'a%40example.com'],
    'routing: placeholders keep the encoded segment (the handler decodes it)'
);
// Mixed-case placeholder names (`{fileId}`) used to expand to nothing, so the
// attachment routes matched no path at all — a listed file could not be removed.
check(
    panel_match('/devices/{id}/files/{fileId}', '/devices/7/files/3') === ['id' => '7', 'fileId' => '3'],
    'routing: mixed-case placeholders are captured'
);
check(
    panel_match('/devices/{id}/files/{fileId}/download', '/devices/7/files/3/download')
        === ['id' => '7', 'fileId' => '3'],
    'routing: the download route is reachable'
);
check(
    route_for('DELETE', '/devices/7/files/3') === 'panel_devices_files_delete',
    'routing: DELETE /devices/{id}/files/{fileId} reaches the handler'
);
check(
    route_for('GET', '/devices/7/files/3/download') === 'panel_devices_files_download',
    'routing: GET .../download reaches the handler'
);

$_SERVER['REQUEST_URI'] = '/panel-api/v1/settings/fields/reorder';
check(panel_request_path() === '/settings/fields/reorder', 'routing: path drops the mount point and version');
$_SERVER['REQUEST_URI'] = '/panel-api/v1/settings/';
check(panel_request_path() === '/settings', 'routing: trailing slash is normalised');
$_SERVER['REQUEST_URI'] = '/panel-api/v1/';
check(panel_request_path() === '/', 'routing: bare version path is /');
// Leave the CLI server superglobal as it was found.
$_SERVER['REQUEST_URI'] = $requestUri ?? '/';

// --- saved views (no database needed) --------------------------------------
$viewState = saved_view_state([
    'q' => '  lenovo  ',
    'sort' => 'SN',
    'dir' => 'desc',
    'trashed' => true,
    'order' => ['SN', 'Status', 'SN'],
    'visible' => ['SN'],
    'per_page' => 100000,
    'nope' => 'x',
]);
check($viewState['q'] === 'lenovo', 'views: strings are trimmed');
check($viewState['dir'] === 'desc', 'views: the direction is kept next to the sort');
check($viewState['trashed'] === true, 'views: booleans survive');
check($viewState['order'] === ['SN', 'Status'], 'views: the column order is de-duplicated');
check(!isset($viewState['per_page']) && !isset($viewState['nope']), 'views: unknown keys are dropped');
check(
    saved_view_state(['sort' => 'SN'])['dir'] === 'asc',
    'views: a sort without a direction defaults to ascending'
);
check(saved_view_state(['q' => '   ']) === [], 'views: blanks are dropped');
check(saved_view_name('  My   laptops ') === 'My laptops', 'views: names are collapsed');
check(saved_view_name('') === null && saved_view_name(str_repeat('x', 65)) === null, 'views: names are length-checked');

// --- rate limit windows (no database needed) --------------------------------
check(rate_limit_window(1_700_000_000, 60) === 1_700_000_000 - (1_700_000_000 % 60), 'rate limit: window start is the containing minute');
check(rate_limit_window(1_700_000_000, 3600) % 3600 === 0, 'rate limit: window start respects wider windows');
check(rate_limit_retry_after(1_700_000_030, 1_700_000_000, 60) === 30, 'rate limit: retry-after counts down inside the window');
check(rate_limit_retry_after(1_700_000_000, 1_700_000_000, 60) === 60, 'rate limit: retry-after is the full window at its start');
check(rate_limit_retry_after(1_700_000_999, 1_700_000_000, 60) === 1, 'rate limit: retry-after never drops to 0');

// --- audit trail diff (no database needed) ---------------------------------
$diff = asset_event_diff(['SN' => 'A', 'Status' => 'In Use'], ['SN' => 'B', 'Status' => 'In Use']);
check($diff === ['SN' => ['A', 'B']], 'events: only the changed column is recorded', json_encode($diff));
check(
    asset_event_diff(['SN' => 'A', 'CypherID' => null], ['SN' => 'A', 'CypherID' => '']) === [],
    'events: null and empty string are the same value'
);
check(
    asset_event_diff(['SN' => 'A'], ['SN' => 'A', 'updated_at' => '2026-11-04 10:00:00', 'updated_by' => 'x']) === [],
    'events: bookkeeping columns never count as a change'
);
check(asset_event_diff([], ['SN' => 'A']) === ['SN' => [null, 'A']], 'events: a create reports every set column');

// --- release stamping + DBA schema (no database needed) --------------------
// `manifest.json` is the file semantic-release bumps. config.php used to fall
// back to a literal that nobody updated, so 11.0.2 reported itself as 1.1.0;
// the constant must now be the environment stamp (the Dockerfile's `ARG
// VERSION`) or, without one, exactly manifest.json.
$manifestPath = dirname(__DIR__) . '/manifest.json';
$manifestVersion = (string) (json_decode((string) file_get_contents($manifestPath), true)['version'] ?? '');
check($manifestVersion !== '', 'release: manifest.json carries a version');
$envVersion = (string) (getenv('APP_VERSION') ?: '');
check(APP_VERSION !== '', 'release: APP_VERSION is never empty');
check(
    $envVersion !== '' ? APP_VERSION === $envVersion : APP_VERSION === $manifestVersion,
    'release: APP_VERSION is the env stamp, else manifest.json (' . APP_VERSION . ')'
);

// `docker/schema.sql` is the DBA path: it records itself as applied, so a table
// it forgets never reaches those installations (user_preferences, asset_events
// and saved_views were all missing). `bin/check-schema.php` prints the detail;
// running the same helper here keeps the suite honest.
$schemaDrift = migrate_dba_sql_drift(dirname(__DIR__));
check($schemaDrift === [], 'schema: docker/schema.sql matches lib/migrate.php', implode('; ', $schemaDrift));

$pdo = db_try();
if ($pdo === null) {
    $passes = (int) $GLOBALS['passes'];
    $failures = (int) $GLOBALS['failures'];
    fwrite(STDOUT, "\nSKIP: no database reachable — write tests not run (routing only).\n");
    fwrite(STDOUT, "{$passes} passed, {$failures} failed\n");
    exit($failures === 0 ? 0 : 1);
}

ini_set('session.cache_limiter', '');
session_boot();

/**
 * @param array<string, string> $fields expected `errors` subset
 */
function check_error(string $label, callable $fn, string $code, int $status, array $fields = []): void
{
    try {
        $fn();
        check(false, $label, 'no error was thrown');
        return;
    } catch (ApiError $e) {
        $ok = $e->errorCode === $code && $e->status === $status;
        foreach ($fields as $field => $expected) {
            $ok = $ok && (($e->errors[$field] ?? null) === $expected);
        }
        check($ok, $label, $e->errorCode . '/' . $e->status . ' ' . json_encode($e->errors));
    } catch (Throwable $e) {
        check(false, $label, get_class($e) . ': ' . $e->getMessage());
    }
}

/** Set the fake JSON body the handlers read (`lib/http.php` seam). */
function body(array $payload): void
{
    $GLOBALS['APP_TEST_JSON_BODY'] = json_encode($payload, JSON_UNESCAPED_UNICODE);
}

function clear_body(): void
{
    unset($GLOBALS['APP_TEST_JSON_BODY']);
}

function as_role(string $role, string $company, string $email = 'tester@example.com'): void
{
    $_SESSION[SESSION_EMAIL] = $email;
    $_SESSION[SESSION_COMPANY] = $company;
    $_SESSION[SESSION_ROLE] = $role;
}

$csrf = bin2hex(random_bytes(16));
$_SESSION['csrf_token'] = $csrf;
$_SERVER['HTTP_X_CSRF_TOKEN'] = $csrf;

$createdIds = [];
$companies = asset_companies($pdo);
$companyA = (string) ($companies[0] ?? '');
$companyB = (string) ($companies[1] ?? '');

fwrite(STDOUT, "Panel API integration tests (db=" . DB_NAME . ", companies="
    . json_encode($companies) . ")\n");

$customDef = null;
foreach (field_defs($pdo) as $def) {
    if (field_editable_by($def, 'manager') && (string) $def['data_type'] === 'text') {
        $customDef = $def;
        break;
    }
}

$statusValues = asset_status_values();
$statusA = (string) ($statusValues[0] ?? 'In Use');
$statusB = (string) ($statusValues[1] ?? $statusA);
$sn = 'TEST-' . strtoupper(bin2hex(random_bytes(4)));
$writeCompany = $companyA !== '' ? $companyA : 'TEST-CO';

// Ids of the settings rows created below, so the cleanup also runs when an
// assertion throws halfway through.
$testSource = null;
$fieldId = 0;
$keyId = 0;
// Previous tier of the setting the storage test overwrites.
$restoreSetting = null;

try {
    // --- /meta role matrix -------------------------------------------------
    as_role('manager', $companyA);
    $metaManager = panel_meta_show()['data'];
    check(in_array('sn', $metaManager['writeColumns']['create'], true), 'meta: manager may create sn');
    check(
        !in_array('cypher_id', $metaManager['writeColumns']['create'], true),
        'meta: manager may not create cypher_id'
    );
    check(
        is_bool($metaManager['limits']['requireSn']),
        'meta: limits.requireSn is published'
    );

    as_role('admin', $companyA);
    $metaAdmin = panel_meta_show()['data'];
    check(in_array('cypher_id', $metaAdmin['writeColumns']['create'], true), 'meta: admin may create cypher_id');
    check(
        in_array('cypher_id', $metaAdmin['writeColumns']['update'], true),
        'meta: admin may update cypher_id'
    );
    check(
        !in_array('cypher_id', $metaManager['writeColumns']['update'], true),
        'meta: manager may not update cypher_id'
    );

    // --- session: capability and feature flags ----------------------------
    $sessionFeatures = panel_session_payload(session_require())['features'];
    check(
        !array_key_exists('spa', $sessionFeatures),
        'session: features.spa is gone now that the legacy panel is deleted'
    );
    check(
        !isset(settings_definitions()['ui.spa_enabled']),
        'settings: the retired ui.spa_enabled option is no longer defined'
    );
    check(is_bool($sessionFeatures['attachments']), 'session: features.attachments is a bool');
    check(
        ($sessionFeatures['devLogin'] ?? null) === app_dev_login_enabled(),
        'session: features.devLogin mirrors the environment'
    );

    // --- create ------------------------------------------------------------
    $createBody = [
        'sn'        => $sn,
        'hostname'  => 'TEST-HOST',
        'status'    => $statusA,
        'byod'      => true,
        'notes'     => 'created by bin/test-panel-api.php',
    ];
    if ($companyA === '') {
        $createBody['company'] = $writeCompany;
    }
    if ($customDef !== null) {
        $createBody['custom'] = [(string) $customDef['field_key'] => 'test-value'];
    }

    as_role('manager', $companyA);
    body($createBody);
    $created = panel_devices_create();
    $id = (int) $created['data']['id'];
    $createdIds[] = $id;
    check(($created['status'] ?? 0) === 201, 'create: 201', (string) json_encode($created['status'] ?? null));
    check($created['data']['sn'] === $sn, 'create: sn stored');
    check($created['data']['hostname'] === 'TEST-HOST', 'create: hostname stored');
    check($created['data']['byod'] === true, 'create: byod is a bool');
    check($created['data']['company'] === $writeCompany, 'create: company scoped to the caller');
    check((string) $created['data']['source'] !== '', 'create: a source was assigned');
    if ($customDef !== null) {
        $key = (string) $customDef['field_key'];
        check(
            ($created['data']['custom'][$key] ?? null) === 'test-value',
            'create: custom value stored'
        );
    }

    body(['sn' => $sn . '-X', 'bogus' => 1]);
    check_error('create: unknown field → 422', fn () => panel_devices_create(), 'validation_failed', 422, [
        'bogus' => 'unknown_field',
    ]);

    body(['sn' => $sn . '-X', 'cypher_id' => 'C-1']);
    check_error('create: manager cannot write cypher_id', fn () => panel_devices_create(), 'validation_failed', 422, [
        'cypher_id' => 'unknown_field',
    ]);

    if ($metaManager['limits']['requireSn']) {
        body(['hostname' => 'NO-SN']);
        check_error('create: sn required → 422', fn () => panel_devices_create(), 'validation_failed', 422, [
            'sn' => 'required',
        ]);
    }

    body(['sn' => $sn, 'hostname' => 'DUPLICATE'] + ($companyA === '' ? ['company' => $writeCompany] : []));
    check_error('create: duplicate sn → 409', fn () => panel_devices_create(), 'conflict', 409, [
        'sn' => 'duplicate',
    ]);

    body(['sn' => $sn . '-SRC', 'source' => 'no-such-source'] + ($companyA === '' ? ['company' => $writeCompany] : []));
    check_error('create: unknown source → 422', fn () => panel_devices_create(), 'validation_failed', 422, [
        'source' => 'unknown_source',
    ]);

    as_role('user', $companyA);
    body(['sn' => $sn . '-U']);
    check_error('create: user role → 403', fn () => panel_devices_create(), 'forbidden', 403);

    $csrfHeader = (string) ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    as_role('manager', $companyA);
    $_SERVER['HTTP_X_CSRF_TOKEN'] = 'not-the-token';
    body(['sn' => $sn . '-CSRF']);
    check_error('create: CSRF required → 419', fn () => panel_devices_create(), 'invalid_csrf', 419);
    $_SERVER['HTTP_X_CSRF_TOKEN'] = $csrfHeader;


    // --- PATCH (optimistic locking) ---------------------------------------
    $row = asset_get($pdo, $id, null, true);
    $expected = asset_iso_datetime(isset($row['updated_at']) ? (string) $row['updated_at'] : null);

    as_role('manager', $companyA);
    body(['status' => $statusB, 'expected_updated_at' => '2000-01-01T00:00:00Z']);
    check_error('patch: stale expected_updated_at → 409', fn () => panel_devices_update(['id' => (string) $id]), 'stale_record', 409, [
        'expected_updated_at' => 'stale',
    ]);

    body(['status' => $statusB, 'expected_updated_at' => $expected]);
    $patched = panel_devices_update(['id' => (string) $id]);
    check($patched['data']['status'] === $statusB, 'patch: status updated');
    check(
        asset_iso_datetime((string) $patched['data']['updated_at']) !== null,
        'patch: updated_at is an ISO timestamp'
    );

    body(['bogus' => 'x']);
    check_error('patch: unknown field → 422', fn () => panel_devices_update(['id' => (string) $id]), 'validation_failed', 422, [
        'bogus' => 'unknown_field',
    ]);

    body(['status' => 'Not A Status']);
    check_error('patch: invalid status → 422', fn () => panel_devices_update(['id' => (string) $id]), 'validation_failed', 422, [
        'status' => 'invalid_option',
    ]);

    if ($companyB !== '' && $companyB !== $companyA) {
        body(['company' => $companyB]);
        check_error('patch: company is immutable → 422', fn () => panel_devices_update(['id' => (string) $id]), 'validation_failed', 422, [
            'company' => 'immutable',
        ]);
    }

    if ($customDef !== null) {
        body(['custom' => [(string) $customDef['field_key'] => '']]);
        $cleared = panel_devices_update(['id' => (string) $id]);
        check(
            !array_key_exists((string) $customDef['field_key'], (array) $cleared['data']['custom']),
            'patch: empty custom value clears the row'
        );
    }

    // --- PUT (full replace) -----------------------------------------------
    body(['sn' => $sn, 'hostname' => 'REPLACED'] + ($companyA === '' ? ['company' => $writeCompany] : []));
    $replaced = panel_devices_replace(['id' => (string) $id]);
    check($replaced['data']['hostname'] === 'REPLACED', 'replace: hostname written');
    check($replaced['data']['status'] === null, 'replace: omitted columns are cleared');
    check($replaced['data']['notes'] === null, 'replace: notes cleared');

    // --- read scope --------------------------------------------------------
    as_role('manager', $companyA);
    $_GET = ['q' => $sn];
    $listed = panel_devices_index()['data'];
    check(in_array($id, array_column($listed['rows'], 'id'), true), 'list: the creator sees the device');
    $_GET = [];

    $_GET = ['sort' => 'DROP TABLE assets'];
    check_error('list: unknown sort → 400', fn () => panel_devices_index(), 'invalid_sort', 400, [
        'sort' => 'unknown_column',
    ]);
    $_GET = ['sort' => 'SN', 'dir' => 'asc'];
    $sorted = panel_devices_index();
    check(($sorted['meta']['sort'] ?? '') === 'SN', 'list: meta.sort echoes the column');
    $_GET = [];

    // --- byod filter -------------------------------------------------------
    // Regression: `BYOD` is a VARCHAR holding 'true'/'false'. Binding integer
    // 1/0 made MariaDB cast the column to a number ('true' → 0), so
    // `byod=true` matched nothing. Found by bin/bench-devices.php (phase 2).
    as_role('manager', $companyA);
    body(['byod' => true]);
    panel_devices_update(['id' => (string) $id]);

    $nonByodBody = ['sn' => $sn . '-N', 'hostname' => 'NON-BYOD', 'byod' => false];
    if ($companyA === '') {
        $nonByodBody['company'] = $writeCompany;
    }
    body($nonByodBody);
    $nonByodId = (int) panel_devices_create()['data']['id'];
    $createdIds[] = $nonByodId;

    $_GET = ['q' => $sn, 'byod' => 'true'];
    $byodTrue = array_column(panel_devices_index()['data']['rows'], 'id');
    check(in_array($id, $byodTrue, true), 'filter: byod=true returns the BYOD device');
    check(!in_array($nonByodId, $byodTrue, true), 'filter: byod=true skips the other device');

    $_GET = ['q' => $sn, 'byod' => 'false'];
    $byodFalse = array_column(panel_devices_index()['data']['rows'], 'id');
    check(in_array($nonByodId, $byodFalse, true), 'filter: byod=false returns the other device');
    check(!in_array($id, $byodFalse, true), 'filter: byod=false skips the BYOD device');
    $_GET = [];

    // --- company isolation -------------------------------------------------
    $foreignId = null;
    if ($companyA !== '' && $companyB !== '' && $companyA !== $companyB) {
        as_role('superadmin', '');
        body(['sn' => $sn . '-B', 'company' => $companyB, 'hostname' => 'OTHER-COMPANY']);
        $foreign = panel_devices_create();
        $foreignId = (int) $foreign['data']['id'];
        $createdIds[] = $foreignId;

        as_role('manager', $companyA);
        body(['status' => $statusA]);
        check_error('isolation: manager of A cannot patch B', fn () => panel_devices_update(['id' => (string) $foreignId]), 'not_found', 404);

        $_GET = ['q' => $sn . '-B'];
        $foreignList = panel_devices_index()['data'];
        check(
            !in_array($foreignId, array_column($foreignList['rows'], 'id'), true),
            'isolation: manager of A cannot list B'
        );
        $_GET = [];

        as_role('superadmin', '');
        body(['sn' => $sn . '-NOCO']);
        check_error('create: unrestricted account needs a company', fn () => panel_devices_create(), 'validation_failed', 422, [
            'company' => 'required',
        ]);
    }


    // --- delete / restore --------------------------------------------------
    as_role('manager', $companyA);
    check_error('delete: manager → 403', fn () => panel_devices_delete(['id' => (string) $id]), 'forbidden', 403);

    body([]);
    as_role('admin', $companyA);
    $deleted = panel_devices_delete(['id' => (string) $id]);
    check($deleted['data']['hard'] === false, 'delete: soft by default');
    $trashed = asset_get($pdo, $id, null, true);
    check($trashed !== null && $trashed['deleted_at'] !== null, 'delete: deleted_at set');

    as_role('manager', $companyA);
    $_GET = ['q' => $sn];
    $hidden = panel_devices_index()['data'];
    check(!in_array($id, array_column($hidden['rows'], 'id'), true), 'list: soft-deleted rows are hidden');
    $_GET = ['q' => $sn, 'trashed' => '1'];
    $onlyTrashed = panel_devices_index()['data'];
    check(in_array($id, array_column($onlyTrashed['rows'], 'id'), true), 'list: trashed=1 finds it');
    $_GET = [];

    as_role('admin', $companyA);
    body(['status' => $statusA]);
    check_error('patch: a deleted device is read-only', fn () => panel_devices_update(['id' => (string) $id]), 'conflict', 409, [
        'deleted_at' => 'trashed',
    ]);

    $_GET = [];
    $restored = panel_devices_restore(['id' => (string) $id]);
    check(($restored['meta']['restored'] ?? false) === true, 'restore: row restored');
    check($restored['data']['deleted_at'] === null, 'restore: deleted_at cleared');

    $again = panel_devices_restore(['id' => (string) $id]);
    check(($again['meta']['restored'] ?? true) === false, 'restore: idempotent');

    $_GET = ['hard' => '1'];
    check_error('delete: admin cannot hard delete → 403', fn () => panel_devices_delete(['id' => (string) $id]), 'forbidden', 403);
    $_GET = [];

    // --- audit trail -------------------------------------------------------
    // Every write above went through `asset_event_record()`, so the history has
    // to show create -> update -> delete -> restore in reverse order.
    $_GET = ['limit' => '50'];
    $events = panel_devices_events(['id' => (string) $id])['data'];
    $_GET = [];
    $actions = array_map(static fn (array $event): string => $event['action'], $events['rows']);
    check($events['total'] > 0, 'events: the device has history');
    check(in_array('create', $actions, true), 'events: create is recorded');
    check(in_array('update', $actions, true), 'events: update is recorded');
    check(in_array('delete', $actions, true), 'events: soft delete is recorded');
    check(in_array('restore', $actions, true), 'events: restore is recorded');
    check(
        $actions[0] === 'restore',
        'events: newest first (restore is the last thing that happened)'
    );
    // The PATCH that moved status A -> B; the later PUT (a replace) has its own
    // event, so the search looks for the change, not for the first update row.
    $updateEvent = null;
    foreach ($events['rows'] as $event) {
        if (($event['changes']['Status'] ?? null) === [$statusA, $statusB]) {
            $updateEvent = $event;
            break;
        }
    }
    check($updateEvent !== null, 'events: the update carries the changed column');
    if ($updateEvent !== null) {
        check($updateEvent['action'] === 'update', 'events: the change is recorded as an update');
        check($updateEvent['actor'] === 'tester@example.com', 'events: the actor is recorded');
    }
    if ($companyB !== '') {
        as_role('admin', $companyB);
        check_error(
            'events: another company cannot read the history → 404',
            fn () => panel_devices_events(['id' => (string) $id]),
            'not_found',
            404
        );
    }
    as_role('user', $companyA);
    check(
        panel_devices_events(['id' => (string) $id])['data']['total'] > 0,
        'events: a read-only user sees the history too'
    );
    as_role('admin', $companyA);

    // --- attachments -------------------------------------------------------
    if (!panel_storage_configured()) {
        as_role('manager', $companyA);
        check_error('files: storage not configured → 503', fn () => panel_devices_files_store(['id' => (string) $id]), 'storage_not_configured', 503);
    }
    as_role('user', $companyA);
    check_error('files: user cannot upload → 403', fn () => panel_devices_files_store(['id' => (string) $id]), 'forbidden', 403);

    // --- local storage driver (plan §14.3) --------------------------------
    // The whole upload/list/delete path with `storage.driver = local`, so the
    // driver is exercised against the real handlers and not just the helpers.
    $localDir = sys_get_temp_dir() . '/bcmdb-storage-' . bin2hex(random_bytes(4));
    $driverBefore = settings_all(true)['storage.driver'] ?? null;
    $pathBefore = settings_all(true)['storage.local_path'] ?? null;
    try {
        settings_set('storage.driver', 'local', 'tester@example.com');
        settings_set('storage.local_path', $localDir, 'tester@example.com');
        check(storage_driver() === 'local', 'storage: the driver switch is honoured');
        check(panel_storage_configured() === true, 'storage: local storage counts as configured');

        $tmp = (string) tempnam(sys_get_temp_dir(), 'bcmdb-up');
        file_put_contents($tmp, 'attachment body');
        $_FILES['file'] = [
            'name'     => 'note.txt',
            'type'     => 'text/plain',
            'tmp_name' => $tmp,
            'error'    => UPLOAD_ERR_OK,
            'size'     => strlen('attachment body'),
        ];
        as_role('manager', $companyA);
        $uploaded = panel_devices_files_store(['id' => (string) $id]);
        unset($_FILES['file']);
        $uploadedId = (int) ($uploaded['data']['id'] ?? 0);
        $key = (string) ($uploaded['data']['path'] ?? '');
        check($uploadedId > 0, 'storage: local upload creates the row');
        check(str_starts_with($key, 'assets/' . $id . '/'), 'storage: the key keeps the device prefix', $key);
        $onDisk = storage_local_path($key);
        check($onDisk !== null && is_file($onDisk), 'storage: the object is on disk', (string) $onDisk);
        check($onDisk !== null && file_get_contents($onDisk) === 'attachment body', 'storage: the bytes round-trip');
        check(
            $onDisk !== null && (fileperms($onDisk) & 0777) === 0600,
            'storage: the file is created 0600'
        );

        $events = panel_devices_events(['id' => (string) $id])['data']['rows'];
        check(
            in_array('file_add', array_map(static fn (array $event): string => $event['action'], $events), true),
            'storage: the upload is in the audit trail'
        );

        as_role('admin', $companyA);
        $removed = panel_devices_files_delete(['id' => (string) $id, 'fileId' => (string) $uploadedId]);
        check(($removed['data']['deleted'] ?? false) === true, 'storage: the row is deleted');
        check($onDisk === null || !is_file($onDisk), 'storage: the object is deleted with the row');

        check(storage_safe_key('../../etc/passwd') === 'etc/passwd', 'storage: keys cannot escape the root');

        // Presigned upload: with `local` active the answer is the fallback mode the
        // SPA switches on, and validation still runs first.
        body(['name' => 'note.txt', 'mime' => 'text/plain', 'size' => 16]);
        $_SERVER['CONTENT_TYPE'] = 'application/json';
        $plan = panel_devices_files_presign(['id' => (string) $id]);
        check(($plan['data']['mode'] ?? '') === 'server', 'storage: local storage asks the client to upload through PHP');

        $errors = [];
        try {
            body(['name' => '', 'size' => 0]);
            panel_devices_files_presign(['id' => (string) $id]);
        } catch (ApiError $e) {
            $errors = $e->errors;
        }
        check(($errors['name'] ?? '') === 'required' && ($errors['size'] ?? '') === 'required', 'storage: presign validates the file metadata');

        // Registration of a direct upload refuses keys outside this device.
        body(['key' => 'assets/999/other.txt', 'name' => 'other.txt', 'mime' => 'text/plain', 'size' => 10]);
        check_error(
            'storage: registering an object of another device is 422',
            fn () => panel_devices_files_store(['id' => (string) $id]),
            'validation_failed',
            422,
            ['key' => 'invalid_key']
        );
        unset($_SERVER['CONTENT_TYPE']);
    } finally {
        foreach (['storage.driver' => $driverBefore, 'storage.local_path' => $pathBefore] as $key_ => $previous) {
            if ($previous === null) {
                continue;
            }
            if (($previous['source'] ?? '') === 'db') {
                settings_set($key_, (string) $previous['value'], 'tester@example.com');
            } else {
                settings_forget($key_);
            }
        }
        if (is_dir($localDir)) {
            $items = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($localDir, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ($items as $item) {
                $item->isDir() ? @rmdir((string) $item->getPathname()) : @unlink((string) $item->getPathname());
            }
            @rmdir($localDir);
        }
    }

    // --- bulk --------------------------------------------------------------
    // Bulk actions are the grid's "select some rows" path: one request, one
    // operation, per-row results even when some rows are stale or out of scope.
    as_role('manager', $companyA);
    $secondBody = $createBody;
    $secondBody['sn'] = $sn . '-2';
    $secondBody['hostname'] = 'TEST-HOST-2';
    body($secondBody);
    $second = panel_devices_create();
    $secondId = (int) $second['data']['id'];
    $createdIds[] = $secondId;

    as_role('manager', $companyA);
    body(['ids' => [$id, $secondId], 'op' => 'status', 'value' => $statusB]);
    $bulkStatus = panel_devices_bulk();
    check(($bulkStatus['meta']['changed'] ?? 0) === 2, 'bulk: status applies to every id');
    $freshRow = asset_get($pdo, $id, null, true);
    check(($freshRow['Status'] ?? null) === $statusB, 'bulk: status written to the row');

    body(['ids' => [$id], 'op' => 'byod', 'value' => false]);
    panel_devices_bulk();
    $freshRow = asset_get($pdo, $id, null, true);
    check(asset_bool_parse($freshRow['BYOD'] ?? null) === false, 'bulk: byod written to the row');

    check_error('bulk: unknown op → 422', function (): void {
        body(['ids' => [1], 'op' => 'explode']);
        panel_devices_bulk();
    }, 'validation_failed', 422, ['op' => 'invalid']);

    check_error('bulk: unknown status → 422', function (): void {
        body(['ids' => [1], 'op' => 'status', 'value' => 'Not A Status']);
        panel_devices_bulk();
    }, 'validation_failed', 422, ['value' => 'invalid_status']);

    check_error('bulk: empty ids → 422', function (): void {
        body(['ids' => [], 'op' => 'status', 'value' => 'In Use']);
        panel_devices_bulk();
    }, 'validation_failed', 422, ['ids' => 'required']);

    check_error('bulk: non-numeric ids → 422', function (): void {
        body(['ids' => ['abc'], 'op' => 'status', 'value' => 'In Use']);
        panel_devices_bulk();
    }, 'validation_failed', 422, ['ids' => 'invalid']);

    check_error('bulk: too many ids → 422', function (): void {
        body(['ids' => range(1, PANEL_BULK_MAX_IDS + 1), 'op' => 'status', 'value' => 'In Use']);
        panel_devices_bulk();
    }, 'validation_failed', 422, ['ids' => 'too_many']);

    check_error('bulk: manager cannot delete → 403', function (): void {
        body(['ids' => [1], 'op' => 'delete']);
        panel_devices_bulk();
    }, 'forbidden', 403);

    as_role('manager', $companyA);
    $_SERVER['HTTP_X_CSRF_TOKEN'] = 'not-the-token';
    body(['ids' => [$id], 'op' => 'status', 'value' => 'In Use']);
    check_error('bulk: CSRF required → 419', fn () => panel_devices_bulk(), 'invalid_csrf', 419);
    $_SERVER['HTTP_X_CSRF_TOKEN'] = $csrfHeader;

    as_role('admin', $companyA);
    body(['ids' => [$id, $secondId], 'op' => 'delete']);
    $bulkDeleted = panel_devices_bulk();
    check(($bulkDeleted['meta']['changed'] ?? 0) === 2, 'bulk: admin soft deletes every id');
    $trashedRow = asset_get($pdo, $id, null, true);
    check($trashedRow !== null && $trashedRow['deleted_at'] !== null, 'bulk: delete sets deleted_at');

    body(['ids' => [$id, $secondId], 'op' => 'delete']);
    $bulkAgain = panel_devices_bulk();
    check(($bulkAgain['meta']['skipped'] ?? 0) === 2, 'bulk: deleting twice is reported as skipped');
    check(($bulkAgain['data']['skipped'][0]['code'] ?? '') === 'already_deleted', 'bulk: skip code is already_deleted');

    as_role('manager', $companyA);
    body(['ids' => [$id], 'op' => 'status', 'value' => $statusA]);
    $bulkTrashed = panel_devices_bulk();
    check(($bulkTrashed['data']['skipped'][0]['code'] ?? '') === 'conflict', 'bulk: deleted rows are skipped, not updated');

    as_role('admin', $companyA);
    body(['ids' => [$id, $secondId], 'op' => 'restore']);
    $bulkRestored = panel_devices_bulk();
    check(($bulkRestored['meta']['changed'] ?? 0) === 2, 'bulk: restore every id');

    body(['ids' => [$id], 'op' => 'restore']);
    $bulkNoop = panel_devices_bulk();
    check(($bulkNoop['data']['skipped'][0]['code'] ?? '') === 'unchanged', 'bulk: restoring a live row is skipped');

    if ($companyB !== '') {
        as_role('manager', $companyB, 'other-company@example.com');
        body(['ids' => [$id], 'op' => 'status', 'value' => $statusB]);
        $bulkForeign = panel_devices_bulk();
        check(($bulkForeign['meta']['changed'] ?? -1) === 0, 'bulk: other company rows are not changed');
        check(($bulkForeign['data']['skipped'][0]['code'] ?? '') === 'not_found', 'bulk: other company rows skip as not_found');
    }

    // --- hard delete -------------------------------------------------------
    as_role('superadmin', '');
    $_GET = ['hard' => '1'];
    $hardDeleted = panel_devices_delete(['id' => (string) $id]);
    check($hardDeleted['data']['hard'] === true, 'delete: superadmin hard deletes');
    $_GET = [];
    check(asset_get($pdo, $id, null, true) === null, 'delete: row is gone');
    $createdIds = array_values(array_diff($createdIds, [$id]));

    // --- settings: role gate ----------------------------------------------
    as_role('manager', $companyA);
    check_error('settings: manager cannot read → 403', fn () => panel_settings_show(), 'forbidden', 403);
    check_error('settings: manager cannot write → 403', function (): void {
        body(['values' => ['general.per_page' => 30]]);
        panel_settings_update();
    }, 'forbidden', 403);
    check_error('settings: manager cannot list users → 403', fn () => panel_settings_users_index(), 'forbidden', 403);

    as_role('admin', $companyA);
    $settingsPayload = panel_settings_show()['data'] ?? [];
    check(($settingsPayload['groups'] ?? []) !== [], 'settings: admin reads the groups');
    check(($settingsPayload['locales'] ?? []) !== [], 'settings: locales are included');
    check(($settingsPayload['timezones'] ?? []) !== [], 'settings: timezones are included');

    $secretEntry = null;
    foreach ($settingsPayload['groups'] as $group) {
        foreach ($group['settings'] as $entry) {
            if (!empty($entry['secret'])) {
                $secretEntry = $entry;
                break 2;
            }
        }
    }
    check($secretEntry !== null && $secretEntry['value'] === '', 'settings: secret values are never returned');
    check($secretEntry !== null && array_key_exists('configured', $secretEntry), 'settings: secrets report "configured"');
    check($secretEntry !== null && !array_key_exists('hash', $secretEntry), 'settings: no hash/secret metadata leaks');

    // --- settings: validation and storage ---------------------------------
    $pageSizeKey = 'general.per_page';
    $pageSizeOriginal = settings_get($pageSizeKey);
    $pageSizeSource = settings_source($pageSizeKey);
    $restoreSetting = ['key' => $pageSizeKey, 'value' => $pageSizeOriginal, 'source' => $pageSizeSource];

    check_error('settings: unknown key → 422', function (): void {
        body(['values' => ['nope.nothing' => 'x']]);
        panel_settings_update();
    }, 'validation_failed', 422, ['nope.nothing' => 'unknown_setting']);

    check_error('settings: out-of-range int → 422', function (): void {
        body(['values' => ['general.per_page' => 9999]]);
        panel_settings_update();
    }, 'validation_failed', 422, ['general.per_page' => 'out_of_range']);

    check_error('settings: unknown locale → 422', function (): void {
        body(['values' => ['general.default_locale' => 'xx-XX']]);
        panel_settings_update();
    }, 'validation_failed', 422, ['general.default_locale' => 'invalid_locale']);

    check_error('settings: write without CSRF → 419', function (): void {
        $header = (string) ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
        $_SERVER['HTTP_X_CSRF_TOKEN'] = 'not-the-token';
        try {
            body(['values' => ['general.per_page' => 30]]);
            panel_settings_update();
        } finally {
            $_SERVER['HTTP_X_CSRF_TOKEN'] = $header;
        }
    }, 'invalid_csrf', 419);

    body(['values' => ['general.per_page' => 30]]);
    $savedSettings = panel_settings_update();
    check(($savedSettings['meta']['saved'] ?? []) === ['general.per_page'], 'settings: valid value is saved');
    check((int) settings_get('general.per_page') === 30, 'settings: value reaches the settings tier');
    $storedEntry = null;
    foreach ($savedSettings['data']['groups'] as $group) {
        foreach ($group['settings'] as $entry) {
            if ($entry['key'] === $pageSizeKey) {
                $storedEntry = $entry;
            }
        }
    }
    check($storedEntry !== null && $storedEntry['source'] === 'db', 'settings: source badge switches to db');

    // --- settings: sources ------------------------------------------------
    $testSource = 'panel_api_test';
    if (source_get($pdo, $testSource) !== null) {
        source_delete($pdo, $testSource);
    }

    body(['label' => 'Panel API test', 'slug' => $testSource, 'color' => '#123456']);
    $createdSource = panel_settings_sources_store();
    check(($createdSource['status'] ?? 0) === 201, 'sources: create returns 201');
    check(source_get($pdo, $testSource) !== null, 'sources: row exists after create');

    check_error('sources: duplicate slug → 422', function () use ($testSource): void {
        body(['label' => 'Again', 'slug' => $testSource]);
        panel_settings_sources_store();
    }, 'validation_failed', 422);

    body(['label' => 'Panel API test renamed']);
    $renamedSource = panel_settings_sources_update(['slug' => $testSource]);
    $renamedRow = source_get($pdo, $testSource);
    check(($renamedRow['label'] ?? '') === 'Panel API test renamed', 'sources: label is updated');
    check(($renamedSource['data']['sources'] ?? []) !== [], 'sources: update returns the full list');

    check_error('sources: system source cannot be deleted → 409', function (): void {
        panel_settings_sources_delete(['slug' => 'eset']);
    }, 'system_source', 409);

    $inUseSlug = null;
    foreach (panel_settings_source_rows($pdo) as $row) {
        if ($row['usage'] > 0 && !$row['is_system']) {
            $inUseSlug = $row['slug'];
            break;
        }
    }
    if ($inUseSlug !== null) {
        check_error('sources: in-use source cannot be deleted → 409', function () use ($inUseSlug): void {
            panel_settings_sources_delete(['slug' => $inUseSlug]);
        }, 'in_use', 409);
    }

    $deletedSource = panel_settings_sources_delete(['slug' => $testSource]);
    check(($deletedSource['meta']['deleted'] ?? '') === $testSource, 'sources: delete reports the slug');
    check(source_get($pdo, $testSource) === null, 'sources: row is gone');
    $testSource = null;

    check_error('sources: unknown slug → 404', function (): void {
        panel_settings_sources_delete(['slug' => 'no_such_source']);
    }, 'not_found', 404);

    // --- settings: custom fields ------------------------------------------
    $fieldLabel = 'Panel API test field';
    $fieldId = 0;
    body(['label' => $fieldLabel, 'type' => 'text', 'in_grid' => true, 'editable_roles' => ['admin']]);
    $createdField = panel_settings_fields_store();
    $fieldId = (int) ($createdField['meta']['id'] ?? 0);
    check(($createdField['status'] ?? 0) === 201 && $fieldId > 0, 'fields: create returns the new id');

    check_error('fields: duplicate key → 422', function () use ($fieldLabel): void {
        body(['label' => $fieldLabel, 'type' => 'text']);
        panel_settings_fields_store();
    }, 'validation_failed', 422);

    check_error('fields: unknown type → 422', function (): void {
        body(['label' => 'Bad type field', 'type' => 'wat']);
        panel_settings_fields_store();
    }, 'validation_failed', 422, ['data_type' => 'invalid_type']);

    body(['label' => 'Panel API test field (edited)', 'in_grid' => false]);
    $updatedField = panel_settings_fields_update(['id' => (string) $fieldId]);
    $updatedDef = field_def_by_id($pdo, $fieldId);
    check(($updatedDef['label'] ?? '') === 'Panel API test field (edited)', 'fields: label is updated');
    check((int) ($updatedDef['show_in_grid'] ?? 1) === 0, 'fields: flags are updated');
    check(($updatedField['data']['types'] ?? []) !== [], 'fields: type catalogue is included');

    $orderedIds = array_map(static fn (array $def): int => (int) $def['id'], field_defs($pdo, false, true));
    body(['ids' => array_reverse($orderedIds)]);
    $reordered = panel_settings_fields_reorder();
    check(($reordered['data']['fields'][0]['id'] ?? 0) === (int) end($orderedIds), 'fields: reorder moves the last field first');
    body(['ids' => $orderedIds]);
    panel_settings_fields_reorder();

    check_error('fields: unknown id in reorder → 422', function (): void {
        body(['ids' => [999999]]);
        panel_settings_fields_reorder();
    }, 'validation_failed', 422, ['ids' => 'not_found']);

    check_error('fields: unknown id on PATCH → 404', function (): void {
        body(['label' => 'Ghost']);
        panel_settings_fields_update(['id' => '999999']);
    }, 'not_found', 404);

    $deletedField = panel_settings_fields_delete(['id' => (string) $fieldId]);
    check(($deletedField['meta']['deleted'] ?? 0) === $fieldId, 'fields: delete reports the id');
    check(field_def_by_id($pdo, $fieldId) === null, 'fields: definition is gone');
    $fieldId = 0;

    // --- settings: API keys -----------------------------------------------
    $keyId = 0;
    body([
        'label'           => 'Panel API test key',
        'company'         => $companyA,
        'allowed_sources' => ['eset'],
        'can_read'        => true,
        'can_write'       => false,
    ]);
    $createdKey = panel_settings_keys_store();
    $keyId = (int) ($createdKey['meta']['id'] ?? 0);
    $token = (string) ($createdKey['meta']['token'] ?? '');
    check(($createdKey['status'] ?? 0) === 201 && $keyId > 0, 'keys: create returns 201 + id');
    check(str_starts_with($token, 'bcmdb_'), 'keys: the plaintext token is returned once');

    $keyRows = $createdKey['data']['keys'] ?? [];
    $listedKey = null;
    foreach ($keyRows as $row) {
        if ($row['id'] === $keyId) {
            $listedKey = $row;
        }
    }
    check($listedKey !== null, 'keys: the new key is listed');
    check($listedKey !== null && !array_key_exists('key_hash', $listedKey), 'keys: the hash is never serialised');
    check($listedKey !== null && $listedKey['allowed_sources'] === ['eset'], 'keys: allowed sources come back as a list');

    check_error('keys: no scope → 422', function () use ($companyA): void {
        body(['label' => 'No scope', 'company' => $companyA]);
        panel_settings_keys_store();
    }, 'validation_failed', 422, ['can_read' => 'no_scope']);

    check_error('keys: unknown source → 422', function () use ($companyA): void {
        body(['label' => 'Bad source', 'company' => $companyA, 'source_slug' => 'nope']);
        panel_settings_keys_store();
    }, 'validation_failed', 422, ['source_slug' => 'unknown_source']);

    body(['label' => 'Panel API test key (edited)', 'can_write' => true]);
    $updatedKey = panel_settings_keys_update(['id' => (string) $keyId]);
    $freshKey = api_key_get($pdo, $keyId);
    check(($freshKey['label'] ?? '') === 'Panel API test key (edited)', 'keys: label is updated');
    check((int) ($freshKey['can_write'] ?? 0) === 1, 'keys: scopes are updated');
    check((int) ($freshKey['can_read'] ?? 0) === 1, 'keys: unspecified scopes are preserved');
    check(!array_key_exists('token', $updatedKey['meta'] ?? []), 'keys: PATCH never re-reveals the token');

    $rotated = panel_settings_keys_rotate(['id' => (string) $keyId]);
    $rotatedToken = (string) ($rotated['meta']['token'] ?? '');
    check($rotatedToken !== '' && $rotatedToken !== $token, 'keys: rotate issues a different token');
    check(hash_equals((string) api_key_get($pdo, $keyId)['key_hash'], hash('sha256', $rotatedToken)), 'keys: rotate stores the new hash');

    $deletedKey = panel_settings_keys_delete(['id' => (string) $keyId]);
    check(($deletedKey['meta']['deleted'] ?? 0) === $keyId, 'keys: delete reports the id');
    check(api_key_get($pdo, $keyId) === null, 'keys: row is gone');
    $keyId = 0;

    check_error('keys: delete twice → 404', function (): void {
        panel_settings_keys_delete(['id' => '999999']);
    }, 'not_found', 404);

    // --- settings: users (superadmin only) --------------------------------
    as_role('superadmin', '');
    $usersPayload = panel_settings_users_index()['data'] ?? [];
    check(($usersPayload['users'] ?? []) !== [], 'users: superadmin lists the roster');
    check(($usersPayload['roles'] ?? []) === array_keys(SESSION_ROLES), 'users: the role catalogue is included');

    $otherEmail = null;
    foreach ($usersPayload['users'] as $row) {
        if (!$row['is_self']) {
            $otherEmail = (string) $row['email'];
            break;
        }
    }

    // Sign in *as* a real roster entry to exercise the lockout guard.
    if ($otherEmail !== null) {
        as_role('superadmin', '', $otherEmail);
        check_error('users: changing your own role → 409', function () use ($otherEmail): void {
            body(['role' => 'admin']);
            panel_settings_users_update(['email' => rawurlencode($otherEmail)]);
        }, 'self_role_change', 409);
        as_role('superadmin', '');
    }

    check_error('users: unknown e-mail → 404', function (): void {
        body(['role' => 'admin']);
        panel_settings_users_update(['email' => 'nobody%40example.invalid']);
    }, 'not_found', 404);

    if ($otherEmail !== null) {
        $otherBefore = (string) $pdo->query('SELECT role FROM users WHERE email = ' . $pdo->quote($otherEmail))->fetchColumn();
        check_error('users: unknown role → 422', function () use ($otherEmail): void {
            body(['role' => 'emperor']);
            panel_settings_users_update(['email' => rawurlencode($otherEmail)]);
        }, 'validation_failed', 422, ['role' => 'invalid_option']);

        body(['role' => 'manager']);
        $promoted = panel_settings_users_update(['email' => rawurlencode($otherEmail)]);
        check(($promoted['meta']['role'] ?? '') === 'manager', 'users: role is written');
        check((string) $pdo->query('SELECT role FROM users WHERE email = ' . $pdo->quote($otherEmail))->fetchColumn() === 'manager', 'users: the row holds the new role');
        $pdo->prepare('UPDATE users SET role = ? WHERE email = ?')->execute([$otherBefore, $otherEmail]);
    }

    // --- settings: maintenance --------------------------------------------
    as_role('admin', $companyA);
    $maintenance = panel_settings_maintenance_show()['data'] ?? [];
    check(array_key_exists('ready', $maintenance['migrations'] ?? []), 'maintenance: migration status is included');
    check(($maintenance['diagnostics']['php_version'] ?? '') === PHP_VERSION, 'maintenance: diagnostics include PHP');
    check(is_bool($maintenance['diagnostics']['encryption'] ?? null), 'maintenance: encryption flag is a bool');
    check(($maintenance['diagnostics']['app_version'] ?? '') === APP_VERSION, 'maintenance: diagnostics report the running version');

    body(['dry_run' => true]);
    $dryRun = panel_settings_maintenance_migrate();
    check(($dryRun['meta']['dry_run'] ?? false) === true, 'maintenance: dry run is the default shape');
    check(is_array($dryRun['meta']['log'] ?? null), 'maintenance: the migration log is returned');

    // --- saved views (CRUD, per user) --------------------------------------
    $viewId = 0;
    try {
        as_role('manager', $companyA, 'views@example.com');
        check(panel_views_index()['data']['total'] === 0, 'views: a new user starts with none');

        body(['name' => 'My laptops', 'state' => ['q' => 'lenovo', 'sort' => 'SN', 'dir' => 'asc']]);
        $view = panel_views_store();
        $viewId = (int) ($view['data']['id'] ?? 0);
        check($viewId > 0, 'views: create returns the row');
        check($view['data']['state']['q'] === 'lenovo', 'views: the state round-trips');

        // Same name again = overwrite, same id.
        body(['name' => 'My laptops', 'state' => ['q' => 'dell']]);
        $overwritten = panel_views_store();
        check((int) $overwritten['data']['id'] === $viewId, 'views: the same name overwrites in place');
        check($overwritten['data']['state']['q'] === 'dell', 'views: the overwrite replaces the state');
        check(panel_views_index()['data']['total'] === 1, 'views: no duplicate row was created');

        body(['name' => '  ']);
        check_error('views: a blank name is 422', fn () => panel_views_store(), 'validation_failed', 422, [
            'name' => 'required',
        ]);

        body(['name' => 'ESET in repair', 'state' => ['source' => 'eset', 'status' => $statusB]]);
        $second = panel_views_store();
        $secondId = (int) ($second['data']['id'] ?? 0);
        check(panel_views_index()['data']['total'] === 2, 'views: both views are listed');

        body(['name' => 'Renamed']);
        $renamed = panel_views_update(['id' => (string) $secondId]);
        check($renamed['data']['name'] === 'Renamed', 'views: rename works');
        check($renamed['data']['state']['source'] === 'eset', 'views: a rename keeps the state');

        $deleted = panel_views_delete(['id' => (string) $secondId]);
        check(($deleted['data']['deleted'] ?? false) === true, 'views: delete works');
        check_error('views: deleting twice is 404', fn () => panel_views_delete(['id' => (string) $secondId]), 'not_found', 404);

        as_role('manager', $companyA, 'other-viewer@example.com');
        check(panel_views_index()['data']['total'] === 0, 'views: another user does not see them');
        check_error(
            'views: editing somebody else’s view is 404',
            fn () => panel_views_update(['id' => (string) $viewId]),
            'not_found',
            404
        );
    } finally {
        // Views belong to an e-mail nobody else uses, so a leftover row is inert;
        // still delete it so a re-run starts from zero.
        try {
            $pdo->prepare('DELETE FROM saved_views WHERE email IN (?, ?)')->execute(['views@example.com', 'other-viewer@example.com']);
        } catch (Throwable $e) {
            fwrite(STDERR, 'views cleanup failed: ' . $e->getMessage() . "\n");
        }
        as_role('superadmin', '');
    }

    // --- rate limiting on panel writes -------------------------------------
    // `rate_limit_require_panel()` runs before every mutation, so the setting
    // only has to be lowered for a dedicated user to observe the 429. The bucket
    // is keyed by e-mail, which is why this uses its own identity.
    $limitKey = 'api.panel_rate_limit';
    $previousLimit = settings_all(true)[$limitKey] ?? null;
    try {
        settings_set($limitKey, '2', 'tester@example.com');
        as_role('manager', $companyA, 'ratelimit@example.com');
        // `panel_invoke()` runs the guard before the handler, so the test does the
        // same: a bare `panel_devices_create()` would bypass the counter.
        $mutate = static function (callable $handler): array {
            rate_limit_require_panel('POST');
            return $handler();
        };
        body(['sn' => 'TEST-RL-' . strtoupper(bin2hex(random_bytes(3))), 'hostname' => 'RL']);
        // Two accepted writes...
        $rlFirst = $mutate(static fn (): array => panel_devices_create());
        $createdIds[] = (int) $rlFirst['data']['id'];
        body(['sn' => 'TEST-RL-' . strtoupper(bin2hex(random_bytes(3))), 'hostname' => 'RL']);
        $rlSecond = $mutate(static fn (): array => panel_devices_create());
        $createdIds[] = (int) $rlSecond['data']['id'];
        check(true, 'rate limit: the first writes below the limit are accepted');

        // ...the third within the same window is 429 rate_limited.
        body(['sn' => 'TEST-RL-' . strtoupper(bin2hex(random_bytes(3))), 'hostname' => 'RL']);
        check_error('rate limit: the next write is 429 rate_limited', static function (): void {
            rate_limit_require_panel('POST');
        }, 'rate_limited', 429);
        check(
            rate_limit_hit($pdo, 'panel:ratelimit@example.com', 2)['allowed'] === false,
            'rate limit: the bucket is over its limit inside the window'
        );
        check(
            rate_limit_hit($pdo, 'panel:ratelimit@example.com', 2, 60, time() + 60)['allowed'] === true,
            'rate limit: the counter resets in the next window'
        );
        // Reads are never counted: the grid reloads `/devices` constantly.
        rate_limit_require_panel('GET');
        check(true, 'rate limit: reads are exempt');
    } finally {
        rate_limit_reset($pdo, 'panel:ratelimit@example.com');
        if ($previousLimit !== null) {
            if (($previousLimit['source'] ?? '') === 'db') {
                settings_set($limitKey, (string) $previousLimit['value'], 'tester@example.com');
            } else {
                settings_forget($limitKey);
            }
        }
        as_role('superadmin', '');
    }



} catch (Throwable $e) {
    check(false, 'unexpected exception', get_class($e) . ': ' . $e->getMessage());
} finally {
    clear_body();
    $_GET = [];
    as_role('superadmin', '');
    if ($restoreSetting !== null) {
        // Put the previous value back on its previous tier, so the database keeps
        // the same effective configuration even when an assertion above threw.
        // (Only `updated_at`/`updated_by` of that one row change.)
        try {
            if ($restoreSetting['source'] === 'db') {
                settings_set($restoreSetting['key'], (string) $restoreSetting['value'], 'tester@example.com');
            } else {
                settings_forget($restoreSetting['key']);
            }
        } catch (Throwable $e) {
            fwrite(STDERR, "setting restore failed ({$restoreSetting['key']}): " . $e->getMessage() . "\n");
        }
    }
    if ($testSource !== null) {
        try {
            source_delete($pdo, $testSource);
        } catch (Throwable $e) {
            fwrite(STDERR, "source cleanup failed ({$testSource}): " . $e->getMessage() . "\n");
        }
    }
    if ($fieldId > 0) {
        try {
            field_delete($pdo, $fieldId);
        } catch (Throwable $e) {
            fwrite(STDERR, "field cleanup failed (#{$fieldId}): " . $e->getMessage() . "\n");
        }
    }
    if ($keyId > 0) {
        try {
            api_key_delete($pdo, $keyId);
        } catch (Throwable $e) {
            fwrite(STDERR, "api key cleanup failed (#{$keyId}): " . $e->getMessage() . "\n");
        }
    }
    foreach ($createdIds as $createdId) {
        try {
            asset_hard_delete($pdo, (int) $createdId);
        } catch (Throwable $e) {
            fwrite(STDERR, "cleanup failed for #{$createdId}: " . $e->getMessage() . "\n");
        }
    }
    // The audit trail is append-only by design, so the test removes the events it
    // wrote (only the identities it uses are affected).
    if (asset_events_ready($pdo)) {
        try {
            $pdo->exec("DELETE FROM asset_events WHERE actor IN ('tester@example.com', 'ratelimit@example.com')");
        } catch (Throwable $e) {
            fwrite(STDERR, 'events cleanup failed: ' . $e->getMessage() . "\n");
        }
    }
}

$failures = (int) $GLOBALS['failures'];
$passes = (int) $GLOBALS['passes'];
fwrite(STDOUT, "\n{$passes} passed, {$failures} failed\n");
exit($failures === 0 ? 0 : 1);

