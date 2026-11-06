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
require_once __DIR__ . '/../lib/panel/logs.php';
require_once __DIR__ . '/../lib/panel/users.php';
require_once __DIR__ . '/../lib/xlsx.php';

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
    ['POST',  '/settings/sources/move',             'panel_settings_sources_move'],
    ['PATCH', '/settings/sources/intune',           'panel_settings_sources_update'],
    ['GET',   '/settings/fields',                   'panel_settings_fields_index'],
    ['POST',  '/settings/fields/reorder',           'panel_settings_fields_reorder'],
    ['PATCH', '/settings/fields/12',                'panel_settings_fields_update'],
    ['DELETE', '/settings/fields/12',               'panel_settings_fields_delete'],
    ['POST',  '/settings/api-keys',                 'panel_settings_keys_store'],
    ['POST',  '/settings/api-keys/9/rotate',        'panel_settings_keys_rotate'],
    ['PATCH', '/settings/api-keys/9',               'panel_settings_keys_update'],
    ['GET',   '/settings/permissions',              'panel_settings_permissions_index'],
    ['PUT',   '/settings/permissions',              'panel_settings_permissions_update'],
    ['GET',   '/settings/users',                    'panel_settings_users_index'],
    ['PATCH', '/settings/users/a%40example.com',    'panel_settings_users_update'],
    ['GET',   '/settings/maintenance',              'panel_settings_maintenance_show'],
    ['POST',  '/settings/maintenance/migrate',      'panel_settings_maintenance_migrate'],
    ['GET',   '/logs',                              'panel_logs_index'],
    ['POST',  '/logs/purge',                        'panel_logs_purge'],
    ['GET',   '/users',                             'panel_users_index'],
    // Pre-existing literals, kept here so the ordering rule has one home.
    ['GET',   '/devices/export',                    'panel_devices_export'],
    ['POST',  '/devices/bulk',                      'panel_devices_bulk'],
    ['PATCH', '/devices/7',                         'panel_devices_update'],
] as [$method, $path, $expected]) {
    $actual = route_for($method, $path);
    check($actual === $expected, "routing: {$method} {$path}", (string) $actual);
}

check(route_for('GET', '/settings/nope') === null, 'routing: unknown settings path is 404');

// The Bearer API accepts `source_slug` as an alias for the device `source`
// column (the same slug the API-key screen stores).
$mappedSource = asset_api_map_input(['source_slug' => 'api']);
check(
    ($mappedSource['data']['source'] ?? null) === 'api' && $mappedSource['unknown'] === [],
    'api: source_slug maps to the source column'
);
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
check(
    asset_event_diff(['PhysicalDisks' => '[]'], ['PhysicalDisks' => '[{"number":0}]']) === [],
    'events: the raw disk JSON stays out of the history',
    json_encode(asset_event_diff(['PhysicalDisks' => '[]'], ['PhysicalDisks' => '[{"number":0}]']))
);

// --- physical disks (no database needed) -----------------------------------
// The agent payload is forgiving on input (camelCase, `size`/`free`, no
// `used_gb`) and canonical on output: snake_case keys, sorted by slot, with
// `used_gb` derived as `size_gb - free_gb`.
$parsedDisks = disks_normalize([
    [
        'index'        => 1,
        'friendlyName' => 'WD Green M.2',
        'mediaType'    => 'SSD',
        'busType'      => 'NVMe',
        'sizeGb'       => 476.94,
        'freeGb'       => 210.5,
        'volumes'      => [['drive' => 'C:', 'label' => 'Windows', 'totalGb' => 476.94, 'freeGb' => 210.5]],
    ],
    [
        'number' => 0,
        'name'   => 'Seagate Expansion',
        'type'   => 'HDD',
        'bus'    => 'USB',
        'size'   => 1863.02,
        'free'   => 1740.25,
        'used'   => 122.78,
    ],
]);
check($parsedDisks['ok'] === true && $parsedDisks['error'] === null, 'disks: a mixed payload validates');
check(
    array_keys($parsedDisks['disks'][0] ?? []) === ['number', 'friendly_name', 'media_type', 'bus_type', 'size_gb', 'free_gb', 'used_gb', 'volumes'],
    'disks: the output shape is snake_case',
    json_encode(array_keys($parsedDisks['disks'][0] ?? []))
);
check((int) ($parsedDisks['disks'][0]['number'] ?? -1) === 0, 'disks: the list is sorted by slot number');
check(
    ($parsedDisks['disks'][1]['friendly_name'] ?? null) === 'WD Green M.2',
    'disks: camelCase members land on their canonical key'
);
check(
    ($parsedDisks['disks'][1]['used_gb'] ?? null) === 266.44,
    'disks: used_gb is derived from size_gb - free_gb',
    json_encode($parsedDisks['disks'][1]['used_gb'] ?? null)
);
check(($parsedDisks['disks'][1]['volumes'][0]['used_gb'] ?? null) === 266.44, 'disks: volume used_gb is derived too');
check(($parsedDisks['disks'][0]['used_gb'] ?? null) === 122.78, 'disks: a supplied used_gb is kept as sent');

// The legacy text columns are manual fields: a disk payload may only touch the
// JSON, otherwise an agent report would overwrite what a human typed.
$diskOnly = array_keys(asset_normalize_input(['PhysicalDisks' => $parsedDisks['disks']]));
check(
    $diskOnly === ['PhysicalDisks'],
    'disks: a payload writes nothing but PhysicalDisks',
    json_encode($diskOnly)
);

check(disks_normalize(null)['disks'] === [], 'disks: null clears the inventory');
check(disks_normalize([['sizeGb' => 'lots']])['error'] === 'invalid_number', 'disks: a non-numeric size is invalid_number');
check(disks_normalize([['sizeGb' => -5]])['error'] === 'invalid_number', 'disks: a negative size is invalid_number');
check(disks_normalize('{"number":0}')['error'] === 'invalid_disks', 'disks: a JSON object is not a list');
check(disks_normalize([['volumes' => 'C:']])['error'] === 'invalid_disks', 'disks: volumes must be a list');
check(
    disks_normalize(array_fill(0, DISKS_MAX_DISKS + 1, ['number' => 0]))['error'] === 'too_many_disks',
    'disks: the payload is bounded'
);
check(disks_decode('not json') === [], 'disks: invalid stored JSON degrades to "no data"');
check(disks_decode('[{"number":0},"x"]') === [['number' => 0]], 'disks: non-object entries are dropped');
check(disks_encode([]) === null, 'disks: an empty list is stored as NULL');

$diskWrites = [];
$diskWritten = asset_normalize_input(['PhysicalDisks' => [['number' => 0, 'sizeGb' => 100, 'freeGb' => 40]]], $diskWrites);
check($diskWrites === [], 'disks: writing a payload reports no error', json_encode($diskWrites));
check(
    !isset($diskWritten['HDs']) && !isset($diskWritten['HDsTypes']) && !isset($diskWritten['HDsSpacesGB']),
    'disks: the manual text columns are never derived from the JSON',
    json_encode(array_keys($diskWritten))
);
check(
    !isset($diskWritten['DisksTotalGB']) && !isset($diskWritten['DisksUsedGB']),
    'disks: the retired roll-up columns are never written',
    json_encode(array_keys($diskWritten))
);
$diskWrites = [];
asset_normalize_input(['PhysicalDisks' => [['sizeGb' => 'x']]], $diskWrites);
check($diskWrites === ['PhysicalDisks' => 'invalid_number'], 'disks: a bad payload surfaces as a field error', json_encode($diskWrites));

$diskMapped = asset_api_map_input([
    'physical_disks' => [],
]);
check($diskMapped['unknown'] === [], 'disks: the new API keys are known', json_encode($diskMapped['unknown']));
check(
    ($diskMapped['data']['PhysicalDisks'] ?? null) === [],
    'disks: the API keys map onto their columns',
    json_encode($diskMapped['data'])
);

// 0020 retired the hand-typed logged-in user: the agent block owns that fact.
$retiredIdentity = asset_api_map_input(['logged_username' => 'jdoe']);
check(
    $retiredIdentity['unknown'] === ['logged_username'],
    'identity: logged_username is retired',
    json_encode($retiredIdentity['unknown'])
);

$retiredMapped = asset_api_map_input([
    'device_model' => 'Dell Latitude 5440',
    'gpus'         => 'NVIDIA RTX 4060',
    'ram_gb'       => '32',
]);
check(
    $retiredMapped['unknown'] === ['device_model', 'gpus', 'ram_gb'],
    'disks: the retired API keys are unknown',
    json_encode($retiredMapped['unknown'])
);

$diskApiRow = asset_api_payload([
    'PhysicalDisks' => '[{"number":0}]',
]);
check($diskApiRow['physical_disks'] === [['number' => 0]], 'disks: the API returns the decoded list');
check(
    !array_key_exists('logged_username', $diskApiRow),
    'identity: logged_username is gone from the API payload'
);
check(
    !array_key_exists('device_model', $diskApiRow) && !array_key_exists('gpus', $diskApiRow)
    && !array_key_exists('ram_gb', $diskApiRow) && !array_key_exists('disks_total_gb', $diskApiRow)
    && !array_key_exists('disks_free_gb', $diskApiRow) && !array_key_exists('disks_used_gb', $diskApiRow),
    'disks: the retired fields are gone from the API payload'
);

// --- inventory agent (no database needed) ----------------------------------
// The agent reports its own copy of the identity and hardware facts. Its payload
// keys (`os`, `hostname`, `model`, …) reach the `Agent*` block through
// `asset_agent_field_map()`, while the very same keys on the public endpoints keep
// meaning the legacy columns — that separation is the whole point of the block.
$agentPayload = [
    'hostname'       => 'pc-01',
    'os'             => 'Windows 11 Pro',
    'build'          => '22631.4169',
    'bios'           => 'Dell 1.14.2',
    'secureBoot'     => true,
    'manufacturer'   => 'Dell Inc.',
    'model'          => 'Latitude 5440',
    'cpu'            => 'Intel Core i7-1365U',
    'gpu'            => 'Intel Iris Xe Graphics',
    'ramGB'          => '16',
    'ip'             => '10.0.0.57',
    'loggedUser'     => 'ACME\\jdoe',
    'allLoggedUsers' => ['ACME\\jdoe', 'ACME\\asmith'],
    'executionUser'  => 'SYSTEM',
    'antivirus'      => 'ESET Endpoint Security 11.1',
    'sn'             => 'AGENT-1',
    'physicalDisks'  => [['number' => 0, 'sizeGb' => 476.94, 'freeGb' => 210.5]],
];

$agentMapped = asset_api_map_input($agentPayload, asset_agent_field_map());
check($agentMapped['unknown'] === [], 'agent: every payload key is claimed', json_encode($agentMapped['unknown']));
check(
    !isset($agentMapped['data']['OS']) && !isset($agentMapped['data']['Hostname'])
    && !isset($agentMapped['data']['Manufacturer']) && !isset($agentMapped['data']['CPUs']),
    'agent: `os`/`hostname`/`manufacturer`/`cpu` never touch the legacy columns',
    json_encode(array_keys($agentMapped['data']))
);
check(
    ($agentMapped['data']['AgentOS'] ?? null) === 'Windows 11 Pro'
    && ($agentMapped['data']['AgentModel'] ?? null) === 'Latitude 5440'
    && ($agentMapped['data']['SN'] ?? null) === 'AGENT-1',
    'agent: the payload keys map onto the Agent* block',
    json_encode($agentMapped['data'])
);

$agentErrors = [];
$agentData = asset_normalize_input($agentMapped['data'], $agentErrors);
check($agentErrors === [], 'agent: the report normalises without errors', json_encode($agentErrors));
check(($agentData['AgentSecureBoot'] ?? null) === 'true', 'agent: secureBoot is stored as a string boolean', json_encode($agentData['AgentSecureBoot'] ?? null));
check(
    ($agentData['AgentAllLoggedUsers'] ?? null) === 'ACME\\jdoe, ACME\\asmith',
    'agent: the user list is joined with ", "',
    (string) ($agentData['AgentAllLoggedUsers'] ?? '')
);
check(
    !isset($agentData['HDs']) && !isset($agentData['HDsTypes']) && !isset($agentData['HDsSpacesGB']),
    'agent: physicalDisks no longer feeds the disk text columns',
    json_encode(array_keys($agentData))
);
check(asset_validate($agentData, true) === [], 'agent: a complete report validates', json_encode(asset_validate($agentData, true)));

check(
    asset_normalize_input(['AgentSecureBoot' => 'yes'])['AgentSecureBoot'] === 'true'
    && asset_normalize_input(['AgentSecureBoot' => false])['AgentSecureBoot'] === 'false'
    && asset_normalize_input(['AgentSecureBoot' => ''])['AgentSecureBoot'] === 'false',
    'agent: secureBoot normalises "yes"/false/"" like every other boolean'
);
check(
    (asset_validate(['AgentOS' => str_repeat('x', 192)], true)['AgentOS'] ?? null) === 'too_long'
    && !isset(asset_validate(['AgentAllLoggedUsers' => str_repeat('x', 5000)], true)['AgentAllLoggedUsers']),
    'agent: the length caps come from asset_agent_columns() (TEXT is uncapped)'
);

// The public map is unchanged: an agent spelling sent to `/v1/devices` still means
// the legacy column, and the block is written through its `agent_*` keys there.
$publicMapped = asset_api_map_input(['os' => 'Windows 11 Pro', 'hostname' => 'pc-01', 'agent_os' => 'Ubuntu 24.04']);
check(
    ($publicMapped['data']['OS'] ?? null) === 'Windows 11 Pro'
    && ($publicMapped['data']['Hostname'] ?? null) === 'pc-01'
    && ($publicMapped['data']['AgentOS'] ?? null) === 'Ubuntu 24.04',
    'agent: the public endpoints keep the legacy meaning of `os`/`hostname`',
    json_encode($publicMapped['data'])
);

$agentRow = asset_api_payload([
    'AgentHostname'   => 'pc-01',
    'AgentOS'         => 'Windows 11 Pro',
    'AgentSecureBoot' => 'true',
    'OS'              => 'Ubuntu 24.04',
]);
check(
    $agentRow['agent_hostname'] === 'pc-01' && $agentRow['agent_os'] === 'Windows 11 Pro'
    && $agentRow['agent_secure_boot'] === true && $agentRow['os'] === 'Ubuntu 24.04',
    'agent: the API payload publishes the block next to the legacy columns',
    json_encode(array_intersect_key($agentRow, array_flip(['agent_hostname', 'agent_os', 'agent_secure_boot', 'os'])))
);
check(
    panel_device_payload(['AgentOS' => 'Windows 11 Pro'])['agent_os'] === 'Windows 11 Pro',
    'agent: the panel payload uses the same keys as the REST payload'
);
check(
    array_keys(asset_agent_columns()) === array_keys(array_filter(
        asset_column_defs(),
        static fn (string $column): bool => str_starts_with($column, 'Agent'),
        ARRAY_FILTER_USE_KEY
    )),
    'agent: the column definitions are derived from asset_agent_columns()'
);
check(
    asset_column_defs()['AgentOS']['form_roles'] === ['admin', 'superadmin']
    && asset_column_defs()['AgentOS']['grid'] === false,
    'agent: admins edit the block, the grid stays opt-in'
);

// One spelling for the disk-space column, in the payload and in the export map.
check(
    array_key_exists('hds_spaces_gb', panel_device_payload(['HDsSpacesGB' => '476.94']))
    && panel_device_payload_keys()['HDsSpacesGB'] === 'hds_spaces_gb',
    'panel: HDsSpacesGB is published as hds_spaces_gb everywhere'
);

// --- boolean storage (no database needed) ----------------------------------
// `Mobile`/`BYOD` are `VARCHAR(16)` ('true'/'false') in the schema, but databases
// created from an older release kept them as `tinyint(1)`. Writing the normalised
// string 'false' into a numeric column is SQLSTATE 22007 under strict mode, which
// the panel reported as "the database is unreachable" while every device save
// failed. `asset_bool_storage_kind()` keeps both layouts writable.
check(
    asset_bool_storage_kind('tinyint(1)') === 'int'
    && asset_bool_storage_kind('int(11)') === 'int'
    && asset_bool_storage_kind('BIGINT(20) UNSIGNED') === 'int'
    && asset_bool_storage_kind('bit(1)') === 'int',
    'bool storage: numeric columns are recognised',
    asset_bool_storage_kind('tinyint(1)')
);
check(
    asset_bool_storage_kind('varchar(16)') === 'text'
    && asset_bool_storage_kind('text') === 'text'
    && asset_bool_storage_kind('') === 'text'
    && asset_bool_storage_kind(null) === 'text',
    'bool storage: text columns keep true/false'
);
check(
    asset_bool_sql_value('int', false) === 0
    && asset_bool_sql_value('int', 'false') === 0
    && asset_bool_sql_value('int', true) === 1
    && asset_bool_sql_value('int', 'yes') === 1,
    'bool storage: a numeric column gets 1/0'
);
check(
    asset_bool_sql_value('int', null) === null && asset_bool_sql_value('text', null) === null,
    'bool storage: NULL stays NULL (a PUT clears omitted columns)'
);
check(
    asset_bool_sql_value('text', false) === 'false'
    && asset_bool_sql_value('text', true) === 'true'
    && asset_bool_sql_value('text', 'on') === 'true',
    'bool storage: a text column keeps true/false'
);

// --- database failures (no database needed) --------------------------------
// A rejected value is not an outage: only a lost connection may answer
// `database_unavailable`, otherwise the SPA tells the user to wait for a database
// that is perfectly healthy (that is what hid this bug for so long).
$rejectedValue = new PDOException("SQLSTATE[22007]: 1366 Incorrect integer value: 'false' for column `assets`.`Mobile`");
$rejectedValue->errorInfo = ['22007', 1366, "Incorrect integer value: 'false' for column `assets`.`Mobile`"];
$valueFailure = db_failure_map($rejectedValue);
check(
    $valueFailure['code'] === 'database_error' && $valueFailure['status'] === 500,
    'database failures: a rejected value is a database_error, not an outage',
    $valueFailure['code']
);
check(
    str_contains($valueFailure['message'], '22007'),
    'database failures: the message names the SQLSTATE',
    $valueFailure['message']
);

$lostConnection = new PDOException('SQLSTATE[HY000]: 2006 MySQL server has gone away');
$lostConnection->errorInfo = ['HY000', 2006, 'MySQL server has gone away'];
$connectionFailure = db_failure_map($lostConnection);
check(
    $connectionFailure['code'] === 'database_unavailable' && $connectionFailure['status'] === 503,
    'database failures: a lost connection is still an outage',
    $connectionFailure['code']
);

$sqlStateOnly = new PDOException('SQLSTATE[08S01]: Communication link failure');
$sqlStateOnly->errorInfo = ['08S01', 0, 'Communication link failure'];
check(
    db_failure_map($sqlStateOnly)['code'] === 'database_unavailable',
    'database failures: SQLSTATE class 08 is an outage'
);

// --- grid layout (no database needed) --------------------------------------
$layoutDefaults = grid_layout_defaults(null);
check(in_array('SN', $layoutDefaults['enabled'], true), 'grid: the code default follows the `grid` flag');
check(!in_array('CPUs', $layoutDefaults['enabled'], true), 'grid: a hidden column is off by default');
check(in_array('files', $layoutDefaults['enabled'], true), 'grid: the virtual files column is on by default');
check($layoutDefaults['locked'] === false, 'grid: unlocked by default');
check(!in_array('Id', grid_layout_ids(null), true), 'grid: the internal id column is never offered');

$layoutSaved = grid_layout_normalize(['order' => ['CPUs', 'SN'], 'enabled' => ['CPUs', 'SN'], 'locked' => true], null);
check($layoutSaved['locked'] === true, 'grid: the lock flag is normalised');
check($layoutSaved['enabled'] === ['CPUs', 'SN'], 'grid: visibility keeps the requested ids', json_encode($layoutSaved['enabled']));
check($layoutSaved['order'][0] === 'CPUs' && $layoutSaved['order'][1] === 'SN', 'grid: the requested order wins');
check(in_array('Status', $layoutSaved['order'], true), 'grid: ids the request omits keep their default slot');
check(
    grid_layout_normalize(['order' => ['Nope'], 'enabled' => ['Nope']], null)['enabled'] === [],
    'grid: unknown ids are dropped'
);

$layoutOrdered = grid_layout_apply_order(
    [['key' => 'SN'], ['key' => 'Status'], ['key' => 'CPUs'], ['key' => 'custom:x']],
    ['order' => ['CPUs', 'SN']]
);
check(
    array_column($layoutOrdered, 'key') === ['CPUs', 'SN', 'Status', 'custom:x'],
    'grid: applying the order keeps the untouched columns at the end',
    json_encode(array_column($layoutOrdered, 'key'))
);


// --- audit retention (no database needed) -----------------------------------
$fixed = new DateTimeImmutable('2026-11-05 12:00:00', new DateTimeZone('UTC'));
check(LOGS_RETENTION_DEFAULT === 180, 'logs: the built-in default is 180 days');
$retention = logs_retention_days();
check($retention >= 0 && $retention <= 3650, 'logs: retention is clamped to a sane range', (string) $retention);
check(
    logs_cutoff($fixed, 180)->format('Y-m-d') === '2026-05-09',
    'logs: the cutoff is the retention window before now',
    logs_cutoff($fixed, 180)->format('Y-m-d')
);
check(logs_cutoff($fixed, 0)->format('Y-m-d') === '2026-11-05', 'logs: a zero-day retention keeps everything');

$purgeDate = logs_purge_date('2026-06-01');
check($purgeDate instanceof DateTimeImmutable, 'logs: an inclusive purge date parses');
check(
    $purgeDate !== null && $purgeDate->getTimezone()->getName() === 'UTC',
    'logs: the purge boundary is converted to UTC'
);
check(logs_purge_date('2026-02-30') === null, 'logs: impossible dates are rejected');
check(logs_purge_date('06/01/2026') === null, 'logs: only YYYY-MM-DD is accepted');

$from = panel_logs_boundary('2026-11-05', 0, 'from');
$to = panel_logs_boundary('2026-11-05', 1, 'to');
check($from !== null && $to !== null && $from < $to, 'logs: the `to` boundary is the next midnight', (string) $from);
check(panel_logs_boundary('', 0, 'from') === null, 'logs: an empty date is not a filter');
$threw = false;
try {
    panel_logs_boundary('2026-13-01', 0, 'from');
} catch (ApiError $e) {
    $threw = $e->status === 400;
}
check($threw, 'logs: an invalid filter date is a 400');

// --- xlsx writer (no database needed) ---------------------------------------
check(xlsx_column(1) === 'A' && xlsx_column(26) === 'Z' && xlsx_column(27) === 'AA', 'xlsx: column letters');
check(
    xlsx_xml_text("A & B <c> \x01") === 'A &amp; B &lt;c&gt; ',
    'xlsx: text is escaped and control characters are dropped',
    xlsx_xml_text("A & B <c> \x01")
);

$sheet = implode('', iterator_to_array(xlsx_sheet_chunks(['SN', 'Hostname'], [['A1', 'pc-1'], ['A2', 'pc-2']])));
check(str_contains($sheet, 'r="A1"') && str_contains($sheet, 'r="B3"'), 'xlsx: cells are addressed by row and column');
check(str_contains($sheet, 'ySplit="1"'), 'xlsx: the header row is frozen');
check(substr_count($sheet, '<row ') === 3, 'xlsx: header plus every data row');

$workbook = xlsx_build(['SN', 'Hostname'], [['A1', 'pc-1']], 'Acme: 2026/27');
check(str_starts_with($workbook, "PK\x03\x04"), 'xlsx: the package is a zip archive');
foreach (['[Content_Types].xml', 'xl/workbook.xml', 'xl/worksheets/sheet1.xml', 'xl/styles.xml'] as $part) {
    check(str_contains($workbook, $part), 'xlsx: contains ' . $part);
}
check(xlsx_sheet_name('Acme: 2026/27') === 'Acme- 2026-27', 'xlsx: forbidden sheet-name characters are replaced');


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

// `sql/schema.sql` is the DBA path: it records itself as applied, so a table
// it forgets never reaches those installations (user_preferences, asset_events
// and saved_views were all missing). `bin/check-schema.php` prints the detail;
// running the same helper here keeps the suite honest.
$schemaDrift = migrate_dba_sql_drift(dirname(__DIR__));
check($schemaDrift === [], 'schema: sql/schema.sql matches lib/migrate.php', implode('; ', $schemaDrift));

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

/**
 * Fake a signed-in browser session.
 *
 * `session_require()` re-reads the roster row on every request (that is how an
 * API delete revokes a session), so the fake identity needs a matching `users`
 * row the way a real login does. Rows created here are removed in the cleanup
 * block at the end of the run.
 */
function as_role(string $role, string $company, string $email = 'tester@example.com'): void
{
    $pdo = db_try();
    if ($pdo !== null && db_table_exists($pdo, 'users')) {
        $stmt = $pdo->prepare('SELECT email FROM users WHERE email = ?');
        $stmt->execute([$email]);
        if ($stmt->fetch() === false) {
            $pdo->prepare('INSERT INTO users (email, company, role) VALUES (?, ?, ?)')
                ->execute([$email, $company, session_role_normalize($role)]);
            $GLOBALS['test_roster_emails'][] = $email;
        } elseif (in_array($email, (array) ($GLOBALS['test_roster_emails'] ?? []), true)) {
            // Keep the fake row in step with the role the test is impersonating.
            $pdo->prepare('UPDATE users SET company = ?, role = ? WHERE email = ?')
                ->execute([$company, session_role_normalize($role), $email]);
        }
    }

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
    $replacedRow = asset_get($pdo, $id, null, true);
    check(
        is_array($replacedRow) && array_key_exists('BYOD', $replacedRow) && $replacedRow['BYOD'] === null,
        'replace: an omitted boolean is cleared to NULL, not false',
        var_export($replacedRow['BYOD'] ?? 'missing', true)
    );

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

    // --- hardware fields: role gate ---------------------------------------
    // `GPUs`, `RAMGB`, `DeviceModel` and the three `Disks*GB` roll-ups were
    // retired by migration 0019 — the `Agent*` block and `PhysicalDisks` own
    // those facts now — and `LoggedUsername` by 0020, because the agent reports
    // the logged-in user as `AgentLoggedUser`. Their old wire keys must fail
    // loudly instead of being accepted by one code path and dropped by another.
    as_role('admin', $companyA);
    body([
        'cpus' => 'Intel Core i7-1365U',
    ]);
    $hardware = panel_devices_update(['id' => (string) $id])['data'];
    check($hardware['cpus'] === 'Intel Core i7-1365U', 'hardware: admin writes cpus');
    check(
        !array_key_exists('logged_username', $hardware),
        'hardware: logged_username is gone from the panel payload'
    );

    foreach ([
        'device_model', 'gpus', 'ram_gb', 'disks_total_gb', 'disks_free_gb', 'disks_used_gb',
        'logged_username',
    ] as $retired) {
        body([$retired => '1']);
        check_error(
            'hardware: ' . $retired . ' is retired → 422',
            fn () => panel_devices_update(['id' => (string) $id]),
            'validation_failed',
            422,
            [$retired => 'unknown_field']
        );
    }

    as_role('manager', $companyA);
    body(['hostname' => 'manager-renamed']);
    check(
        panel_devices_update(['id' => (string) $id])['data']['hostname'] === 'manager-renamed',
        'hardware: manager may still edit the identity fields'
    );
    body(['cpus' => 'x']);
    check_error('hardware: manager cannot write cpus → 422', fn () => panel_devices_update(['id' => (string) $id]), 'validation_failed', 422, [
        'cpus' => 'unknown_field',
    ]);
    body(['physical_disks' => []]);
    check_error('hardware: the panel never writes the disk JSON', fn () => panel_devices_update(['id' => (string) $id]), 'validation_failed', 422, [
        'physical_disks' => 'unknown_field',
    ]);

    // --- inventory agent: panel round trip and role gate -------------------
    $hostnameBefore = panel_devices_show(['id' => (string) $id])['data']['hostname'];
    as_role('admin', $companyA);
    body([
        'agent_hostname'    => 'pc-01',
        'agent_os'          => 'Windows 11 Pro',
        'agent_os_build'    => '22631.4169',
        'agent_secure_boot' => true,
    ]);
    $agentPanel = panel_devices_update(['id' => (string) $id])['data'];
    check($agentPanel['agent_os'] === 'Windows 11 Pro', 'agent: admin writes agent_os');
    check($agentPanel['agent_os_build'] === '22631.4169', 'agent: admin writes agent_os_build');
    check($agentPanel['agent_secure_boot'] === true, 'agent: secureBoot round-trips as a boolean');
    check(
        $agentPanel['agent_hostname'] === 'pc-01' && $agentPanel['hostname'] === $hostnameBefore,
        'agent: the block does not overwrite the legacy hostname',
        json_encode([$agentPanel['agent_hostname'], $agentPanel['hostname'], $hostnameBefore])
    );

    as_role('manager', $companyA);
    body(['agent_os' => 'Ubuntu 24.04']);
    check_error('agent: manager cannot write the block → 422', fn () => panel_devices_update(['id' => (string) $id]), 'validation_failed', 422, [
        'agent_os' => 'unknown_field',
    ]);
    $agentRead = panel_devices_show(['id' => (string) $id])['data'];
    check(
        $agentRead['agent_os'] === 'Windows 11 Pro',
        'agent: the refused write changed nothing',
        (string) $agentRead['agent_os']
    );

    // --- disks: the API write path ----------------------------------------
    // `physical_disks` is API-only, so it goes through the same helpers
    // `public/api.php` uses and is then read back through the panel payload.
    $diskErrors = [];
    $diskData = asset_normalize_input([
        'PhysicalDisks' => [[
            'number'       => 0,
            'friendlyName' => 'WD Green M.2',
            'mediaType'    => 'SSD',
            'busType'      => 'NVMe',
            'sizeGb'       => 476.94,
            'freeGb'       => 210.5,
            'volumes'      => [['drive' => 'C:', 'label' => 'Windows', 'totalGb' => 476.94, 'freeGb' => 210.5]],
        ]],
    ], $diskErrors);
    check($diskErrors === [], 'disks: the API payload validates', json_encode($diskErrors));
    // The manual hardware text is independent of the agent-fed JSON: write it
    // first, report disks, and it must still be there afterwards.
    asset_update(
        $pdo,
        $id,
        ['HDs' => 'Manual disk', 'HDsTypes' => 'SSD SATA', 'HDsSpacesGB' => '256'],
        'tester@example.com',
        $companyA === '' ? null : $companyA
    );
    asset_update($pdo, $id, $diskData, 'tester@example.com', $companyA === '' ? null : $companyA);

    as_role('manager', $companyA);
    $diskRow = panel_devices_show(['id' => (string) $id])['data'];
    check(count($diskRow['physical_disks']) === 1, 'disks: the JSON is stored and decoded');
    check(($diskRow['physical_disks'][0]['used_gb'] ?? null) === 266.44, 'disks: used_gb survives the round trip');
    check($diskRow['hds'] === 'Manual disk', 'disks: a report leaves the manual HDs alone', (string) $diskRow['hds']);
    check($diskRow['hds_types'] === 'SSD SATA', 'disks: a report leaves the manual HDsTypes alone', (string) $diskRow['hds_types']);
    check($diskRow['hds_spaces_gb'] === '256', 'disks: a report leaves the manual HDsSpacesGB alone', (string) $diskRow['hds_spaces_gb']);
    check(
        !array_key_exists('disks_total_gb', $diskRow) && !array_key_exists('disks_free_gb', $diskRow),
        'disks: the retired roll-ups are not published by the panel',
        json_encode(array_keys($diskRow))
    );

    $_GET = ['q' => 'WD Green'];
    check(
        in_array($id, array_column(panel_devices_index()['data']['rows'], 'id'), true),
        'disks: the "any" search reaches the disk JSON'
    );
    $_GET = ['q' => 'no-such-disk-name'];
    check(
        !in_array($id, array_column(panel_devices_index()['data']['rows'], 'id'), true),
        'disks: a miss stays a miss'
    );
    $_GET = ['sort' => 'DisksFreeGB', 'dir' => 'asc'];
    check_error('disks: the retired free-space roll-up is not sortable → 400', fn () => panel_devices_index(), 'invalid_sort', 400, [
        'sort' => 'unknown_column',
    ]);
    $_GET = [];

    // Clearing the inventory only empties the JSON: the manual text columns are a
    // different fact and stay where the admin put them.
    asset_update($pdo, $id, asset_normalize_input(['PhysicalDisks' => null]), 'tester@example.com', $companyA === '' ? null : $companyA);
    $clearedDisks = panel_devices_show(['id' => (string) $id])['data'];
    check(
        $clearedDisks['physical_disks'] === [] && $clearedDisks['hds'] === 'Manual disk',
        'disks: clearing the JSON keeps the manual text columns',
        json_encode([$clearedDisks['physical_disks'], $clearedDisks['hds']])
    );

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

    // --- deletion reason ----------------------------------------------------
    // "Deleted" alone does not answer why an asset went away, so the reason and
    // the kept duplicate are columns (0014) and validated before the write.
    $metaPayload = panel_meta_show()['data'];
    check(
        array_column($metaPayload['deleteReasons'] ?? [], 'value') === asset_deletion_reasons(),
        'meta: deleteReasons matches asset_deletion_reasons()'
    );

    // The asset that was kept: the duplicate reference has to point at a live row
    // of the same company, so the test owns the row it points at.
    $duplicateBody = $createBody;
    $duplicateBody['sn'] = $sn . '-DUP';
    $duplicateBody['hostname'] = 'TEST-HOST-DUP';
    body($duplicateBody);
    $duplicate = panel_devices_create();
    $duplicateId = (int) $duplicate['data']['id'];
    $createdIds[] = $duplicateId;

    as_role('admin', $companyA);
    $_GET = ['reason' => 'because', 'duplicate_of' => (string) $duplicateId];
    check_error(
        'delete: unknown reason → 422',
        fn () => panel_devices_delete(['id' => (string) $id]),
        'validation_failed',
        422,
        ['reason' => 'invalid_reason']
    );

    $_GET = ['reason' => 'created_by_mistake', 'duplicate_of' => (string) $duplicateId];
    check_error(
        'delete: duplicate_of needs the duplicated reason → 422',
        fn () => panel_devices_delete(['id' => (string) $id]),
        'validation_failed',
        422,
        ['duplicate_of' => 'reason_not_duplicated']
    );

    $_GET = ['reason' => 'duplicated', 'duplicate_of' => (string) $id];
    check_error(
        'delete: a device cannot duplicate itself → 422',
        fn () => panel_devices_delete(['id' => (string) $id]),
        'validation_failed',
        422,
        ['duplicate_of' => 'self']
    );

    $_GET = ['reason' => 'duplicated', 'duplicate_of' => '999999'];
    check_error(
        'delete: unknown duplicate_of → 422',
        fn () => panel_devices_delete(['id' => (string) $id]),
        'validation_failed',
        422,
        ['duplicate_of' => 'not_found']
    );

    $_GET = ['reason' => 'duplicated', 'duplicate_of' => (string) $duplicateId];
    $reasonDeleted = panel_devices_delete(['id' => (string) $id]);
    check(($reasonDeleted['data']['deleted_reason'] ?? '') === 'duplicated', 'delete: reason is echoed');
    check(($reasonDeleted['data']['duplicate_of_id'] ?? 0) === $duplicateId, 'delete: duplicate_of is echoed');

    $trashedWithReason = asset_get($pdo, $id, null, true);
    check(($trashedWithReason['deleted_reason'] ?? '') === 'duplicated', 'delete: reason is stored');
    check((int) ($trashedWithReason['duplicate_of_id'] ?? 0) === $duplicateId, 'delete: duplicate_of is stored');

    $_GET = [];
    $shown = panel_devices_show(['id' => (string) $id])['data'];
    check(($shown['deleted_reason'] ?? '') === 'duplicated', 'show: the reason is exposed');
    check((int) ($shown['duplicate_of']['id'] ?? 0) === $duplicateId, 'show: duplicate_of resolves to the kept device');

    $_GET = ['limit' => '5'];
    $reasonEvents = panel_devices_events(['id' => (string) $id])['data'];
    $_GET = [];
    check(
        ($reasonEvents['rows'][0]['changes']['deleted_reason'] ?? null) === [null, 'duplicated'],
        'events: the delete records why it happened'
    );

    $restoredAgain = panel_devices_restore(['id' => (string) $id]);
    // `?? 'kept'` would treat an existing NULL as missing, so the keys are asserted
    // explicitly: the payload has to carry the cleared values, not omit them.
    check(
        array_key_exists('deleted_reason', $restoredAgain['data']) && $restoredAgain['data']['deleted_reason'] === null,
        'restore: reason is cleared'
    );
    check(
        array_key_exists('duplicate_of_id', $restoredAgain['data']) && $restoredAgain['data']['duplicate_of_id'] === null,
        'restore: duplicate_of is cleared'
    );

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

    // --- S3 client configuration (no bucket, no network) -------------------
    // `isConfigured()` used to ask the SDK, but `AwsClient::getCredentials()`
    // returns the credential *provider's* promise (aws-sdk-php 3.4xx), so
    // `getAccessKeyId()` on it was a fatal error that turned every S3 upload,
    // delete and download link into a 500. Constructor overrides keep this
    // check free of database rows and of any bucket round-trip.
    if (class_exists(Aws\S3\S3Client::class)) {
        require_once __DIR__ . '/../s3_client.php';
        $s3Empty = new S3Client([
            'region' => 'us-east-1', 'endpoint' => '', 'bucket' => '',
            'access_key' => '', 'secret_key' => '', 'path_style' => true,
            'prefix' => 'uploads', 'presign_seconds' => 60,
        ]);
        check($s3Empty->isConfigured() === false, 'storage: a bucket without a key pair is not configured');

        $s3Ready = new S3Client([
            'region' => 'us-east-1', 'endpoint' => '', 'bucket' => 'cmdb',
            'access_key' => 'AKIAEXAMPLE', 'secret_key' => 'secret',
            'path_style' => true, 'prefix' => 'uploads', 'presign_seconds' => 60,
        ]);
        check($s3Ready->isConfigured() === true, 'storage: a bucket plus an explicit key pair is configured');

        // The join rule decides the folder layout of every upload: a prefix that
        // ends in `-`/`_` runs into the key (`acme-1-assets/42/x.pdf`), anything
        // else is a folder (`uploads/assets/42/x.pdf`), and an already-qualified
        // key is never prefixed twice.
        $dashPrefix = new S3Client([
            'region' => 'us-east-1', 'endpoint' => '', 'bucket' => 'cmdb',
            'access_key' => 'AKIAEXAMPLE', 'secret_key' => 'secret',
            'path_style' => true, 'prefix' => 'acme-1-', 'presign_seconds' => 60,
        ]);
        check(
            $dashPrefix->objectKey('assets/42/x.pdf') === 'acme-1-assets/42/x.pdf',
            'storage: a prefix ending in - runs into the key',
            $dashPrefix->objectKey('assets/42/x.pdf')
        );
        check(
            $dashPrefix->objectKey('acme-1-assets/42/x.pdf') === 'acme-1-assets/42/x.pdf',
            'storage: an already-prefixed key is not prefixed twice'
        );
        $folderPrefix = new S3Client([
            'region' => 'us-east-1', 'endpoint' => '', 'bucket' => 'cmdb',
            'access_key' => 'AKIAEXAMPLE', 'secret_key' => 'secret',
            'path_style' => true, 'prefix' => 'uploads', 'presign_seconds' => 60,
        ]);
        check(
            $folderPrefix->objectKey('assets/42/x.pdf') === 'uploads/assets/42/x.pdf',
            'storage: any other prefix becomes a folder',
            $folderPrefix->objectKey('assets/42/x.pdf')
        );
        $noPrefix = new S3Client([
            'region' => 'us-east-1', 'endpoint' => '', 'bucket' => 'cmdb',
            'access_key' => 'AKIAEXAMPLE', 'secret_key' => 'secret',
            'path_style' => true, 'prefix' => '', 'presign_seconds' => 60,
        ]);
        check(
            $noPrefix->objectKey('acme-1-assets/42/x.pdf') === 'acme-1-assets/42/x.pdf',
            'storage: reads and deletes use the stored key verbatim'
        );
    } else {
        fwrite(STDOUT, "  skip storage: S3 client configuration (AWS SDK is not installed)\n");
    }

    // --- local storage driver (plan §14.3) --------------------------------
    // The whole upload/list/delete path with `storage.driver = local`, so the
    // driver is exercised against the real handlers and not just the helpers.
    $localDir = sys_get_temp_dir() . '/bcmdb-storage-' . bin2hex(random_bytes(4));
    $driverBefore = settings_all(true)['storage.driver'] ?? null;
    $pathBefore = (string) getenv('BCMDB_STORAGE_PATH');
    if ($pathBefore === '') {
        check(
            storage_local_dir() === dirname(__DIR__) . '/var/storage',
            'storage: the local directory defaults to <app>/var/storage',
            storage_local_dir()
        );
    }
    try {
        settings_set('storage.driver', 'local', 'tester@example.com');
        // The directory is a fixed path now — the compose stacks bind-mount it —
        // so `BCMDB_STORAGE_PATH` is the documented escape hatch that keeps this
        // test out of the real attachment tree.
        putenv('BCMDB_STORAGE_PATH=' . $localDir);
        check(
            storage_local_dir() === $localDir,
            'storage: BCMDB_STORAGE_PATH overrides the local directory',
            storage_local_dir()
        );
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

        // …but the key the presign step handed out carries the object-key prefix
        // (`ACME-1-assets/42/…`), so the segment boundary is what counts.
        body(['key' => 'ACME-1-assets/' . $id . '/note.txt', 'name' => 'note.txt', 'mime' => 'text/plain', 'size' => 10]);
        $registered = panel_devices_files_store(['id' => (string) $id]);
        check(
            ($registered['data']['path'] ?? '') === 'ACME-1-assets/' . $id . '/note.txt',
            'storage: a prefixed key of this device registers',
            (string) ($registered['data']['path'] ?? '')
        );
        panel_devices_files_delete(['id' => (string) $id, 'fileId' => (string) ($registered['data']['id'] ?? 0)]);

        // A prefix only widens the check by a separator, never by a substring.
        foreach (['xassets/' . $id . '/note.txt', 'uploads/assets/999/note.txt'] as $badKey) {
            body(['key' => $badKey, 'name' => 'note.txt', 'mime' => 'text/plain', 'size' => 10]);
            check_error(
                'storage: ' . $badKey . ' is rejected',
                fn () => panel_devices_files_store(['id' => (string) $id]),
                'validation_failed',
                422,
                ['key' => 'invalid_key']
            );
        }
        unset($_SERVER['CONTENT_TYPE']);
    } finally {
        putenv($pathBefore === '' ? 'BCMDB_STORAGE_PATH' : 'BCMDB_STORAGE_PATH=' . $pathBefore);
        if ($driverBefore !== null) {
            if (($driverBefore['source'] ?? '') === 'db') {
                settings_set('storage.driver', (string) $driverBefore['value'], 'tester@example.com');
            } else {
                settings_forget('storage.driver');
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

    // --- boolean columns stored as tinyint ---------------------------------
    // The installations created from an older release keep `Mobile`/`BYOD` as
    // `tinyint(1)`. Writing the normalised string 'false' there is SQLSTATE 22007
    // under strict mode, and the panel answered "the database is unreachable",
    // which is why admins and managers could not save a device at all.
    // `asset_filter_columns()` now binds 1/0 for a numeric column; the ALTERs are
    // reverted at the end so the suite leaves the schema as it found it.
    // The rows are rewritten first: strict mode refuses to truncate 'true' into a
    // TINYINT, and the values are semantically identical either way.
    $rewriteBoolColumns = static function (PDO $pdo, bool $numeric): void {
        $on = $numeric ? '1' : 'true';
        $off = $numeric ? '0' : 'false';
        foreach (['Mobile', 'BYOD'] as $column) {
            $stmt = $pdo->prepare(
                "UPDATE assets SET `{$column}` = CASE"
                . " WHEN LOWER(TRIM(`{$column}`)) IN ('1', 'true', 'yes', 'on') THEN ?"
                . " WHEN LOWER(TRIM(`{$column}`)) IN ('0', 'false', 'no', 'off') THEN ?"
                . " ELSE `{$column}` END"
                . " WHERE `{$column}` IS NOT NULL"
            );
            $stmt->execute([$on, $off]);
        }
    };

    $tinyintReady = true;
    try {
        $rewriteBoolColumns($pdo, true);
        $pdo->exec('ALTER TABLE assets MODIFY `Mobile` TINYINT(1) NULL, MODIFY `BYOD` TINYINT(1) NULL');
    } catch (PDOException $e) {
        $tinyintReady = false;
        $rewriteBoolColumns($pdo, false);
        fwrite(STDOUT, '  skip bool storage: the database user cannot ALTER TABLE (' . $e->getCode() . ")\n");
    }
    if ($tinyintReady) {
        asset_bool_storage_flush();
        try {
            as_role('manager', $companyA);
            $tinyintBody = $createBody;
            $tinyintBody['sn'] = $sn . '-TINYINT';
            $tinyintBody['mobile'] = false;
            $tinyintBody['byod'] = true;
            body($tinyintBody);
            $tinyintCreated = panel_devices_create();
            $tinyintId = (int) $tinyintCreated['data']['id'];
            $createdIds[] = $tinyintId;
            check(
                ($tinyintCreated['data']['mobile'] ?? null) === false && ($tinyintCreated['data']['byod'] ?? null) === true,
                'bool storage: create saves booleans into tinyint columns',
                json_encode(['mobile' => $tinyintCreated['data']['mobile'] ?? null, 'byod' => $tinyintCreated['data']['byod'] ?? null])
            );

            body(['hostname' => 'TEST-HOST-TINYINT', 'mobile' => false]);
            $tinyintSaved = panel_devices_update(['id' => (string) $tinyintId]);
            check(
                ($tinyintSaved['data']['hostname'] ?? null) === 'TEST-HOST-TINYINT',
                'bool storage: saving a device on a tinyint column succeeds'
            );
            $tinyintRow = asset_get($pdo, $tinyintId, null, true);
            check(
                (string) ($tinyintRow['Mobile'] ?? '') === '0',
                'bool storage: mobile stored as 0 in the tinyint column',
                (string) ($tinyintRow['Mobile'] ?? 'null')
            );

            body(['ids' => [$tinyintId], 'op' => 'byod', 'value' => true]);
            panel_devices_bulk();
            $tinyintRow = asset_get($pdo, $tinyintId, null, true);
            check(
                (string) ($tinyintRow['BYOD'] ?? '') === '1',
                'bool storage: a bulk byod writes 1 into the tinyint column',
                (string) ($tinyintRow['BYOD'] ?? 'null')
            );
        } finally {
            $pdo->exec('ALTER TABLE assets MODIFY `Mobile` VARCHAR(16) NULL, MODIFY `BYOD` VARCHAR(16) NULL');
            $rewriteBoolColumns($pdo, false);
            asset_bool_storage_flush();
        }
        check(
            (string) db_column_definition($pdo, 'assets', 'Mobile') === 'varchar(16)',
            'bool storage: the suite restores the declared column type',
            (string) db_column_definition($pdo, 'assets', 'Mobile')
        );
    }

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

    // The bulk reason is optional (a mixed batch has no single answer) but the
    // value that is sent is validated and stored on every row it deletes.
    as_role('admin', $companyA);
    check_error('bulk: unknown reason → 422', function (): void {
        body(['ids' => [1], 'op' => 'delete', 'reason' => 'because']);
        panel_devices_bulk();
    }, 'validation_failed', 422, ['reason' => 'invalid_reason']);

    body(['ids' => [$secondId], 'op' => 'delete', 'reason' => 'created_by_mistake']);
    $bulkReason = panel_devices_bulk();
    check(($bulkReason['data']['reason'] ?? '') === 'created_by_mistake', 'bulk: the reason is echoed');
    $bulkReasonRow = asset_get($pdo, $secondId, null, true);
    check(
        ($bulkReasonRow['deleted_reason'] ?? '') === 'created_by_mistake',
        'bulk: the reason is stored on the row'
    );

    body(['ids' => [$secondId], 'op' => 'restore']);
    panel_devices_bulk();
    $bulkRestoredRow = asset_get($pdo, $secondId, null, true);
    check(
        is_array($bulkRestoredRow) && $bulkRestoredRow['deleted_reason'] === null,
        'bulk: restore clears the reason'
    );

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
    $settingsGroupIds = array_column($settingsPayload['groups'], 'id');
    check(
        $settingsGroupIds === ['general', 'assets', 'logs', 'api', 'storage', 's3'],
        'settings: cards have one group each (general, assets, logs, api, storage, s3)'
    );
    $settingsGroupLabels = array_column($settingsPayload['groups'], 'label_key', 'id');
    check(
        ($settingsGroupLabels['assets'] ?? '') === 'set_group_assets'
        && ($settingsGroupLabels['s3'] ?? '') === 'set_group_s3',
        'settings: cards use distinct headings'
    );
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

    // --- settings: object-key prefix placeholders and reset ----------------
    // `s3.prefix` is the one setting whose value is a template: the tokens are
    // rejected on save, expanded at upload time, and the entry publishes both the
    // catalogue and the default the "Reset to default" button restores.
    $prefixBefore = settings_all(true)['s3.prefix'] ?? null;
    try {
        check_error('settings: an unknown placeholder → 422', function (): void {
            body(['values' => ['s3.prefix' => '{{nope}}-']]);
            panel_settings_update();
        }, 'validation_failed', 422, ['s3.prefix' => 'invalid_placeholder']);

        check_error('settings: resetting a secret → 422', function (): void {
            body(['values' => [], 'reset' => ['s3.secret_key']]);
            panel_settings_update();
        }, 'validation_failed', 422, ['s3.secret_key' => 'not_a_secret']);

        body(['values' => ['s3.prefix' => '{{company}}-{{asset}}-']]);
        $savedPrefix = panel_settings_update();
        check(
            ($savedPrefix['meta']['saved'] ?? []) === ['s3.prefix'],
            'settings: a template built from known placeholders is saved'
        );
        check(
            storage_prefix_resolve(['company' => 'ACME', 'Asset' => 'NB-1']) === 'ACME-NB-1-',
            'settings: the prefix expands from the asset row',
            storage_prefix_resolve(['company' => 'ACME', 'Asset' => 'NB-1'])
        );
        check(
            storage_prefix_resolve(['company' => 'ACME']) === 'ACME-',
            'settings: a missing placeholder still leaves the dash that joins the key',
            storage_prefix_resolve(['company' => 'ACME'])
        );
        check(storage_prefix_resolve([]) === '', 'settings: an all-empty template resolves to no prefix');

        $prefixEntry = null;
        foreach ($savedPrefix['data']['groups'] as $group) {
            foreach ($group['settings'] as $entry) {
                if ($entry['key'] === 's3.prefix') {
                    $prefixEntry = $entry;
                }
            }
        }
        check(
            $prefixEntry !== null && ($prefixEntry['default'] ?? null) === '{{company}}-{{asset}}-',
            'settings: the entry publishes its default'
        );
        check(
            $prefixEntry !== null && array_key_exists('company', (array) ($prefixEntry['placeholders'] ?? [])),
            'settings: the entry publishes the placeholder catalogue'
        );
        check(
            $prefixEntry !== null && $prefixEntry['source'] === 'db',
            'settings: the saved prefix is a db hit'
        );

        // `reset` drops the row: the value falls back to the built-in default and
        // the badge flips back, which is what the SPA button reads.
        body(['values' => [], 'reset' => ['s3.prefix']]);
        $resetPrefix = panel_settings_update();
        check(
            ($resetPrefix['meta']['saved'] ?? []) === ['s3.prefix'],
            'settings: reset reports the key as saved'
        );
        $resetEntry = null;
        foreach ($resetPrefix['data']['groups'] as $group) {
            foreach ($group['settings'] as $entry) {
                if ($entry['key'] === 's3.prefix') {
                    $resetEntry = $entry;
                }
            }
        }
        check(
            $resetEntry !== null && $resetEntry['source'] === 'default',
            'settings: reset switches the source badge back to default'
        );
        check(
            $resetEntry !== null && $resetEntry['value'] === '{{company}}-{{asset}}-',
            'settings: reset restores the built-in default',
            (string) ($resetEntry['value'] ?? '')
        );
    } finally {
        if ($prefixBefore !== null && ($prefixBefore['source'] ?? '') === 'db') {
            settings_set('s3.prefix', (string) $prefixBefore['value'], 'tester@example.com');
        } else {
            settings_forget('s3.prefix');
        }
    }

    // --- settings: grid columns -------------------------------------------
    as_role('admin', $companyA);
    $layoutBefore = (string) settings_get(GRID_LAYOUT_SETTING, '');
    $columnsPayload = panel_settings_columns_index()['data'];
    $columnIds = array_column($columnsPayload['columns'], 'id');
    check($columnIds !== [], 'columns: the catalogue is published');
    check(
        in_array('SN', $columnIds, true) && in_array('files', $columnIds, true),
        'columns: built-in and virtual columns are offered'
    );
    check(in_array('CPUs', $columnIds, true), 'columns: a column shipped "off" can be enabled');
    check(!in_array('Id', $columnIds, true), 'columns: the internal id stays out');
    check($columnsPayload['layout']['locked'] === false, 'columns: the default layout is unlocked');
    check(
        !in_array('ui', array_column(panel_settings_show()['data']['groups'], 'id'), true),
        'columns: the hidden layout setting has no card of its own'
    );

    check_error('columns: unknown column → 422', function (): void {
        body(['enabled' => ['SN', 'Nope']]);
        panel_settings_columns_update();
    }, 'validation_failed', 422, ['enabled' => 'unknown_column']);

    body(['order' => ['CPUs', 'SN'], 'enabled' => ['CPUs', 'SN'], 'locked' => true]);
    $savedColumns = panel_settings_columns_update()['data'];
    check($savedColumns['layout']['enabled'] === ['CPUs', 'SN'], 'columns: visibility is saved', json_encode($savedColumns['layout']['enabled']));
    check($savedColumns['layout']['order'][0] === 'CPUs', 'columns: the order is saved');
    check($savedColumns['layout']['locked'] === true, 'columns: the lock is saved');

    $lockedMeta = panel_meta_show()['data'];
    check($lockedMeta['gridLayout']['locked'] === true, 'columns: /meta publishes the lock');
    check(array_column($lockedMeta['columns'], 'key')[0] === 'CPUs', 'columns: /meta follows the admin order');
    $inGrid = [];
    foreach ($lockedMeta['columns'] as $column) {
        $inGrid[(string) $column['key']] = (bool) $column['in_grid'];
    }
    check(($inGrid['SN'] ?? false) === true, 'columns: an enabled column stays in the grid');
    check(($inGrid['Status'] ?? true) === false, 'columns: a disabled column leaves the grid');

    $resetLayout = panel_settings_columns_delete()['data'];
    $codeDefaults = grid_layout_defaults($pdo);
    check($resetLayout['layout']['locked'] === false, 'columns: reset unlocks');
    check($resetLayout['layout']['order'] === $codeDefaults['order'], 'columns: reset restores the code order');
    check($resetLayout['layout']['enabled'] === $codeDefaults['enabled'], 'columns: reset restores the code visibility');
    if ($layoutBefore !== '') {
        // Leave the installation exactly as it was found.
        settings_set(GRID_LAYOUT_SETTING, $layoutBefore, 'tester@example.com');
    }

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
        panel_settings_sources_delete(['slug' => 'api']);
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

    // --- settings: move references between sources -------------------------
    $moveFrom = 'panel_api_move_from';
    $moveTo = 'panel_api_move_to';
    $moveKeyId = 0;
    $moveAssetId = 0;
    foreach ([$moveFrom, $moveTo] as $slug) {
        if (source_get($pdo, $slug) !== null) {
            source_delete($pdo, $slug);
        }
        source_create($pdo, ['slug' => $slug, 'label' => $slug, 'color' => null, 'is_default' => false]);
    }
    try {
        $pdo->prepare('INSERT INTO assets (SN, company, `source`) VALUES (?, ?, ?)')
            ->execute(['MOVE-' . bin2hex(random_bytes(4)), $companyA, $moveFrom]);
        $moveAssetId = (int) $pdo->lastInsertId();

        body([
            'label'           => 'Move test key',
            'company'         => $companyA,
            'source_slug'     => $moveFrom,
            'allowed_sources' => [$moveFrom],
            'can_read'        => true,
            'can_write'       => true,
        ]);
        $moveKey = panel_settings_keys_store();
        $moveKeyId = (int) ($moveKey['meta']['id'] ?? 0);
        check($moveKeyId > 0, 'sources: move fixture key was created');

        body(['from' => $moveFrom, 'to' => $moveTo]);
        $moved = panel_settings_sources_move();
        check(($moved['meta']['from'] ?? '') === $moveFrom, 'sources: move reports the source');
        check(($moved['meta']['to'] ?? '') === $moveTo, 'sources: move reports the target');
        check((int) ($moved['meta']['assets'] ?? 0) >= 1, 'sources: move reports the assets');
        check((int) ($moved['meta']['api_keys'] ?? 0) >= 1, 'sources: move rewrites API-key references');

        $movedAsset = asset_get($pdo, $moveAssetId, $companyA, true);
        check(($movedAsset['source'] ?? '') === $moveTo, 'sources: move rewrites assets.source');

        $afterMove = api_key_get($pdo, $moveKeyId);
        check(($afterMove['source_slug'] ?? '') === $moveTo, 'sources: move rewrites the key default');
        check(($afterMove['allowed_sources'] ?? '') === $moveTo, 'sources: move rewrites the key allowlist');

        check_error('sources: moving to itself → 422', function () use ($moveTo): void {
            body(['from' => $moveTo, 'to' => $moveTo]);
            panel_settings_sources_move();
        }, 'same_source', 422);
    } finally {
        if ($moveKeyId > 0) {
            api_key_delete($pdo, $moveKeyId);
        }
        if ($moveAssetId > 0) {
            $pdo->prepare('DELETE FROM assets WHERE Id = ?')->execute([$moveAssetId]);
        }
        foreach ([$moveFrom, $moveTo] as $slug) {
            $pdo->prepare('DELETE FROM asset_sources WHERE slug = ?')->execute([$slug]);
        }
        sources_all($pdo, true);
    }

    // --- settings: permission matrix ---------------------------------------
    as_role('superadmin', '');
    $permissions = panel_settings_permissions_index()['data'] ?? [];
    check(($permissions['roles'] ?? []) === array_keys(SESSION_ROLES), 'permissions: role catalogue is included');
    check(($permissions['capabilities'] ?? []) !== [], 'permissions: capability catalogue is included');
    check(($permissions['fields'] ?? []) !== [], 'permissions: editable fields are included');
    $superMatrix = $permissions['matrix']['capabilities']['superadmin'] ?? [];
    check(
        $superMatrix !== [] && !in_array(false, $superMatrix, true),
        'permissions: superadmin cannot be revoked'
    );

    body(['capabilities' => ['manager' => ['devices.delete' => true]]]);
    $granted = panel_settings_permissions_update();
    check(
        ($granted['data']['matrix']['capabilities']['manager']['devices.delete'] ?? false) === true,
        'permissions: a capability can be granted'
    );
    check(permission_capability_allowed('manager', 'devices.delete', $pdo), 'permissions: grant is effective');

    body(['capabilities' => ['manager' => ['devices.delete' => false]]]);
    $revoked = panel_settings_permissions_update();
    check(
        ($revoked['data']['matrix']['capabilities']['manager']['devices.delete'] ?? true) === false,
        'permissions: a capability can be revoked'
    );
    check(!permission_capability_allowed('manager', 'devices.delete', $pdo), 'permissions: revoke is effective');

    $notesBefore = $permissions['matrix']['fields']['Notes'] ?? [];
    check($notesBefore !== [], 'permissions: built-in field is listed');
    $notesAfter = array_values(array_filter($notesBefore, static fn (string $role): bool => $role !== 'manager'));
    body(['fields' => ['Notes' => $notesAfter]]);
    $fieldSaved = panel_settings_permissions_update();
    check(
        !in_array('manager', $fieldSaved['data']['matrix']['fields']['Notes'] ?? [], true),
        'permissions: a field can be restricted'
    );
    check(!permission_field_editable('manager', 'Notes', $pdo), 'permissions: field restriction is effective');

    body(['fields' => ['Notes' => $notesBefore]]);
    panel_settings_permissions_update();
    check(permission_field_editable('manager', 'Notes', $pdo), 'permissions: field restriction is restored');

    as_role('admin', '');
    check_error('permissions: an admin cannot edit the matrix → 403', function (): void {
        panel_settings_permissions_index();
    }, 'forbidden', 403);
    as_role('superadmin', '');

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
        'allowed_sources' => ['api'],
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
    check($listedKey !== null && $listedKey['allowed_sources'] === ['api'], 'keys: allowed sources come back as a list');

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
    foreach (array_unique((array) ($GLOBALS['test_roster_emails'] ?? [])) as $testEmail) {
        try {
            $pdo->prepare('DELETE FROM users WHERE email = ?')->execute([(string) $testEmail]);
        } catch (Throwable $e) {
            fwrite(STDERR, 'roster cleanup failed for ' . $testEmail . ': ' . $e->getMessage() . "\n");
        }
    }
}

$failures = (int) $GLOBALS['failures'];
$passes = (int) $GLOBALS['passes'];
fwrite(STDOUT, "\n{$passes} passed, {$failures} failed\n");
exit($failures === 0 ? 0 : 1);

