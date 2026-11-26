#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * bin/test-collector.php — integration tests for the experimental "Collect data"
 * feature (`lib/collect.php`, `lib/collect_script.php`, `lib/panel/collect.php`).
 *
 * Runs against the configured development database and removes everything it
 * creates: devices are hard-deleted, roster rows and tokens are deleted by
 * e-mail, rate-limit buckets are dropped and the `collect.enabled` setting is put
 * back the way it was found.
 *
 * What it covers:
 *   - token lifecycle: prefix/length, only the hash is stored, one active token
 *     per user, issuing revokes the previous one, expiry, revocation;
 *   - authorisation gates: bad token, expired, revoked, feature off, unknown
 *     roster row, unassigned company, capability denied;
 *   - payload rules: the field allow-list refuses `company`/`user_email`/
 *     `source`/`status`, accepts the agent spellings, normalises booleans;
 *   - the write path: create -> update (same row), ownership refused for another
 *     person, soft-deleted serial refused, cross-tenant duplicate allowed,
 *     `source = collector`, audit actor `collector:<email>`;
 *   - throttling: the per-token limit answers 429;
 *   - the download: a CRLF batch whose body is the PowerShell script, ASCII-only,
 *     endpoint and token embedded, marker line last in the launcher;
 *   - the panel handlers: GET status, POST file (CSRF required), DELETE revoke.
 *
 * Usage: php bin/test-collector.php    (exit 0 pass, 1 failure, 2 wrong env)
 */

require_once __DIR__ . '/../lib/bootstrap.php';
require_once __DIR__ . '/../lib/panel/session.php';
require_once __DIR__ . '/../lib/panel/collect.php';
require_once __DIR__ . '/../lib/collect_script.php';

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

/** @param array<string, string> $fields expected `errors` subset */
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

/** Remove everything this run created. Registered as a shutdown hook too. */
function collect_test_cleanup(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    $pdo = db_try();
    if ($pdo === null) {
        return;
    }

    foreach ((array) ($GLOBALS['test_collect_devices'] ?? []) as $id) {
        try {
            asset_hard_delete($pdo, (int) $id);
        } catch (Throwable $e) {
            fwrite(STDERR, "device cleanup failed (#{$id}): " . $e->getMessage() . "\n");
        }
    }
    foreach ((array) ($GLOBALS['test_collect_emails'] ?? []) as $email) {
        try {
            $pdo->prepare('DELETE FROM collector_tokens WHERE email = ?')->execute([(string) $email]);
            $pdo->prepare('DELETE FROM users WHERE email = ?')->execute([(string) $email]);
            if (asset_events_ready($pdo)) {
                $pdo->prepare('DELETE FROM asset_events WHERE actor = ?')->execute(['collector:' . (string) $email]);
            }
        } catch (Throwable $e) {
            fwrite(STDERR, 'cleanup failed for ' . $email . ': ' . $e->getMessage() . "\n");
        }
    }
    foreach ((array) ($GLOBALS['test_collect_buckets'] ?? []) as $bucket) {
        try {
            rate_limit_reset($pdo, (string) $bucket);
        } catch (Throwable $e) {
            fwrite(STDERR, 'rate limit cleanup failed: ' . $e->getMessage() . "\n");
        }
    }
    if (!empty($GLOBALS['test_collect_settings_touched'])) {
        try {
            if (!empty($GLOBALS['test_collect_enabled_row'])) {
                settings_set('collect.enabled', (string) ($GLOBALS['test_collect_enabled_value'] ?? '0'), 'bin/test-collector.php');
            } else {
                settings_forget('collect.enabled');
            }
        } catch (Throwable $e) {
            fwrite(STDERR, 'settings cleanup failed: ' . $e->getMessage() . "\n");
        }
    }
}

register_shutdown_function('collect_test_cleanup');

$pdo = db();
if (!db_table_exists($pdo, 'collector_tokens')) {
    fwrite(STDERR, "collector_tokens is missing. Run php bin/migrate.php.\n");
    exit(2);
}
if (!db_table_exists($pdo, 'users')) {
    fwrite(STDERR, "The `users` table is missing. Apply sql/legacy.sql first.\n");
    exit(2);
}

$actor = 'bin/test-collector.php';
$suffix = bin2hex(random_bytes(4));
$email = 'bin-test-collect-' . $suffix . '@example.invalid';
$otherEmail = 'bin-test-collect-other-' . $suffix . '@example.invalid';
$companyA = 'BinTest Collect A ' . $suffix;
$companyB = 'BinTest Collect B ' . $suffix;

$GLOBALS['test_collect_emails'] = [$email, $otherEmail];
$GLOBALS['test_collect_devices'] = [];
$GLOBALS['test_collect_buckets'] = [];

$enabledRow = settings_db_rows()['collect.enabled'] ?? null;
$GLOBALS['test_collect_enabled_row'] = $enabledRow !== null;
$GLOBALS['test_collect_enabled_value'] = $enabledRow['value'] ?? null;
$GLOBALS['test_collect_settings_touched'] = true;
settings_set('collect.enabled', '1', $actor);

user_upsert($pdo, ['email' => $email, 'company' => $companyA, 'role' => 'user'], $actor);
user_upsert($pdo, ['email' => $otherEmail, 'company' => $companyA, 'role' => 'user'], $actor);

$user = ['email' => $email, 'company' => $companyA, 'role' => 'user'];
$other = ['email' => $otherEmail, 'company' => $companyA, 'role' => 'user'];

fwrite(STDOUT, 'Collect data tests (db=' . DB_NAME . ", user={$email})\n");

// --- token lifecycle -------------------------------------------------------
$issuedA = collect_issue_token($pdo, $user, 'collect-test.bat');
$tokenA = $issuedA['token'];
$rowA = collect_token_by_hash($pdo, collect_token_hash($tokenA));

check(str_starts_with($tokenA, COLLECT_TOKEN_PREFIX), 'token: carries the recognisable prefix');
check(strlen($tokenA) === strlen(COLLECT_TOKEN_PREFIX) + COLLECT_TOKEN_BYTES * 2, 'token: 24 random bytes', (string) strlen($tokenA));
check($rowA !== null, 'token: the row is stored');
check(($rowA['token_hash'] ?? '') === collect_token_hash($tokenA), 'token: only the SHA-256 is stored');
check(($rowA['token_hash'] ?? '') !== $tokenA, 'token: the raw value is never stored');
check(($rowA['company'] ?? '') === $companyA, 'token: the company is denormalised for support');
check(($rowA['revoked_at'] ?? null) === null, 'token: a new token is not revoked');

$ttlMinutes = collect_ttl_minutes();
$expiresTs = strtotime((string) $rowA['expires_at'] . ' UTC');
$expectedTs = time() + $ttlMinutes * 60;
check(abs($expiresTs - $expectedTs) <= 120, 'token: expiry follows collect.token_ttl_minutes', (string) ($expiresTs - $expectedTs));

$active = collect_active_token($pdo, $email);
check($active !== null && (int) $active['id'] === (int) $rowA['id'], 'token: the active lookup finds it');
check(collect_active_token($pdo, strtoupper($email)) !== null, 'token: the e-mail lookup is case-insensitive');

$issuedB = collect_issue_token($pdo, $user, 'collect-test-2.bat');
$tokenB = $issuedB['token'];
$rowAAfter = collect_token_by_hash($pdo, collect_token_hash($tokenA));
check(($rowAAfter['revoked_at'] ?? null) !== null, 'token: a second download revokes the first');
check(collect_active_token($pdo, $email)['id'] === $issuedB['row']['id'], 'token: only one active token per user');
check_error('token: the revoked link is refused', fn () => collect_authorize($pdo, $tokenA), 'token_revoked', 401);

// Expire the current one, then issue a fresh token for the write tests.
$pdo->prepare('UPDATE collector_tokens SET expires_at = ? WHERE id = ?')
    ->execute([gmdate('Y-m-d H:i:s', time() - 60), (int) $issuedB['row']['id']]);
check_error('token: an expired link is refused', fn () => collect_authorize($pdo, $tokenB), 'token_expired', 401);

$issued = collect_issue_token($pdo, $user, collect_file_name($email));
$token = $issued['token'];
$auth = collect_authorize($pdo, $token);
check($auth['user']['email'] === $email && $auth['user']['company'] === $companyA, 'token: authorisation resolves the roster row');
check($auth['user']['role'] === 'user', 'token: the current role travels with the authorisation');

// --- authorisation gates ---------------------------------------------------
check_error('gate: a random string is not a token', fn () => collect_authorize($pdo, 'nope'), 'invalid_token', 401);
check_error('gate: an unknown token is refused', fn () => collect_authorize($pdo, COLLECT_TOKEN_PREFIX . str_repeat('a', 48)), 'invalid_token', 401);

$ghostEmail = 'bin-test-collect-ghost-' . $suffix . '@example.invalid';
$GLOBALS['test_collect_emails'][] = $ghostEmail;
$ghost = collect_issue_token($pdo, ['email' => $ghostEmail, 'company' => $companyA, 'role' => 'user'], 'ghost.bat');
check_error('gate: a removed roster row is refused', fn () => collect_authorize($pdo, $ghost['token']), 'user_not_found', 401);
$pdo->prepare('DELETE FROM collector_tokens WHERE id = ?')->execute([(int) $ghost['row']['id']]);

$noCompanyEmail = 'bin-test-collect-nocompany-' . $suffix . '@example.invalid';
$GLOBALS['test_collect_emails'][] = $noCompanyEmail;
$pdo->prepare('INSERT INTO users (email, company, role) VALUES (?, ?, ?)')
    ->execute([$noCompanyEmail, '', 'user']);
$noCompany = collect_issue_token($pdo, ['email' => $noCompanyEmail, 'company' => '', 'role' => 'user'], 'nocompany.bat');
check_error('gate: an account without a company is refused', fn () => collect_authorize($pdo, $noCompany['token']), 'company_unassigned', 403);
$pdo->prepare('DELETE FROM collector_tokens WHERE id = ?')->execute([(int) $noCompany['row']['id']]);

settings_set('collect.enabled', '0', $actor);
check_error('gate: the feature flag is enforced', fn () => collect_authorize($pdo, $token), 'collect_disabled', 403);
settings_set('collect.enabled', '1', $actor);

check(permission_capability_allowed('user', 'devices.collect'), 'capability: a plain user may collect by default');
check(permission_capability_allowed('manager', 'devices.collect'), 'capability: a manager may collect by default');
check(!permission_capability_allowed('user', 'devices.nope'), 'capability: an unknown key is denied');

// --- payload rules ---------------------------------------------------------
$mapped = collect_map_payload([
    'sn'          => '  ',
    'hostname'    => 'COLLECT-TEST',
    'company'     => 'Evil Corp',
    'user_email'  => 'somebody@example.invalid',
    'source'      => 'api',
    'status'      => 'In Use',
    'asset'       => 'ACME-1',
    'notes'       => 'x',
    'nickname'    => 'x',
    'secureBoot'  => 'TRUE',
    'ramGB'       => 16,
]);
check(($mapped['errors']['company'] ?? '') === 'forbidden_field', 'payload: company is server-controlled');
check(($mapped['errors']['UserEmail'] ?? '') === 'forbidden_field', 'payload: the owner is server-controlled');
check(($mapped['errors']['source'] ?? '') === 'forbidden_field', 'payload: source is server-controlled');
check(($mapped['errors']['Status'] ?? '') === 'forbidden_field', 'payload: status stays with the panel');
check(($mapped['errors']['Asset'] ?? '') === 'forbidden_field', 'payload: the asset tag stays with the panel');
check(($mapped['errors']['Notes'] ?? '') === 'forbidden_field', 'payload: notes stay with the panel');
check(($mapped['errors']['nickname'] ?? '') === 'forbidden_field', 'payload: unknown keys are named');
check(!array_key_exists('company', $mapped['data']), 'payload: the forbidden keys never reach the model');
check(($mapped['data']['Hostname'] ?? '') === 'COLLECT-TEST', 'payload: hostname maps to the legacy column');
check(($mapped['data']['AgentSecureBoot'] ?? '') === 'true', 'payload: the agent spelling is normalised to a boolean');
check(($mapped['data']['AgentRAMGB'] ?? '') === '16', 'payload: the agent spelling reaches the agent block');
check(array_key_exists('SN', $mapped['data']) && $mapped['data']['SN'] === null, 'payload: a blank serial is normalised to null');

$invalidDisks = collect_map_payload(['physical_disks' => ['not-a-list' => true]]);
check(($invalidDisks['errors']['PhysicalDisks'] ?? '') === 'invalid_disks', 'payload: a broken disk list is reported');

// --- write path ------------------------------------------------------------
$sn = 'COLLECT-' . strtoupper($suffix);
$uuid = 'UUID-' . strtoupper($suffix);
$hostname = 'collect-' . $suffix;

$basePayload = [
    'sn'                  => $sn,
    'uuid'                => $uuid,
    'hostname'            => $hostname,
    'os'                  => 'Microsoft Windows 11 Pro',
    'os_version'          => '10.0.22631',
    'manufacturer'        => 'Dell Inc.',
    'cpus'                => 'Intel Core i7-1365U',
    'primary_local_ip'    => '10.0.0.5',
    'network_adapters'    => 'Intel Ethernet',
    'mac_addresses'       => 'AA-BB-CC-DD-EE-FF',
    'physical_disks'      => [[
        'number'        => 0,
        'friendly_name' => 'WD Green 480GB',
        'media_type'    => 'SSD',
        'bus_type'      => 'NVMe',
        'size_gb'       => 476.94,
        'free_gb'       => 210.5,
        'volumes'       => [
            ['drive' => 'C:', 'label' => 'Windows', 'total_gb' => 476.94, 'free_gb' => 210.5],
        ],
    ]],
    'agent_hostname'      => $hostname,
    'agent_model'         => 'Latitude 5440',
    'agent_ram_gb'        => '16',
    'agent_secure_boot'   => 'true',
    'agent_logged_user'   => 'ACME\\jdoe',
];

$created = collect_apply($pdo, $auth, $basePayload, '203.0.113.9');
$GLOBALS['test_collect_devices'][] = (int) $created['id'];
check($created['created'] === true, 'write: the first report creates the device');
$row = asset_get($pdo, (int) $created['id'], $companyA, true) ?? [];
check(($row['UserEmail'] ?? '') === $email, 'write: the device is assigned to the collecting user');
check(($row['company'] ?? '') === $companyA, 'write: the tenant comes from the roster, not the payload');
check(($row['source'] ?? '') === COLLECT_SOURCE, 'write: a new device is stamped with the collector source');
check(($row['SN'] ?? '') === $sn && ($row['Hostname'] ?? '') === $hostname, 'write: identity columns land');
check(($row['AgentModel'] ?? '') === 'Latitude 5440', 'write: the agent block lands');
check(str_starts_with((string) ($row['PhysicalDisks'] ?? ''), '['), 'write: the disk inventory is stored as JSON');
check((string) ($row['LastSeen'] ?? '') !== '', 'write: LastSeen is stamped');

$tokenRow = collect_token_by_hash($pdo, collect_token_hash($token));
check((int) $tokenRow['uses'] === 1, 'write: the token counts the use');
check(($tokenRow['last_used_at'] ?? null) !== null && ($tokenRow['last_used_ip'] ?? '') === '203.0.113.9', 'write: the address is recorded');

$secondPayload = $basePayload;
$secondPayload['hostname'] = $hostname . '-new';
$second = collect_apply($pdo, $auth, $secondPayload, '203.0.113.9');
check($second['created'] === false && (int) $second['id'] === (int) $created['id'], 'write: a second report updates the same row');
$row2 = asset_get($pdo, (int) $created['id'], $companyA, true) ?? [];
check(($row2['Hostname'] ?? '') === $hostname . '-new', 'write: the new hostname is stored');
check(($row2['AgentModel'] ?? '') === 'Latitude 5440', 'write: a repeated report keeps the agent data');

// A sparse report (a second run after a partial failure) must not clear values.
collect_apply($pdo, $auth, ['sn' => $sn, 'hostname' => $hostname . '-new'], '203.0.113.9');
$row3 = asset_get($pdo, (int) $created['id'], $companyA, true) ?? [];
check(($row3['AgentModel'] ?? '') === 'Latitude 5440', 'write: a sparse report never clears a maintained field');

// Ownership: somebody else's device is refused instead of re-assigned.
$otherSn = 'COLLECT-OTHER-' . strtoupper($suffix);
$otherId = asset_create($pdo, [
    'SN'        => $otherSn,
    'Hostname'  => 'other-' . $suffix,
    'UserEmail' => $otherEmail,
    'company'   => $companyA,
], $actor, 'manual');
$GLOBALS['test_collect_devices'][] = $otherId;
check_error(
    'write: a device owned by somebody else is refused',
    fn () => collect_apply($pdo, $auth, ['sn' => $otherSn, 'hostname' => 'x'], '203.0.113.9'),
    'device_assigned_to_other_user',
    409
);

// Soft-deleted serial: refused, never silently resurrected.
$deletedSn = 'COLLECT-DEL-' . strtoupper($suffix);
$deletedId = asset_create($pdo, ['SN' => $deletedSn, 'Hostname' => 'del-' . $suffix, 'company' => $companyA], $actor, 'manual');
$GLOBALS['test_collect_devices'][] = $deletedId;
asset_soft_delete($pdo, $deletedId, $actor, $companyA, 'created_by_mistake');
check_error(
    'write: a soft-deleted serial is refused',
    fn () => collect_apply($pdo, $auth, ['sn' => $deletedSn, 'hostname' => 'x'], '203.0.113.9'),
    'serial_soft_deleted',
    409
);

// Cross-tenant duplicates are allowed: the lookup is scoped to the company.
$foreignSn = 'COLLECT-FOREIGN-' . strtoupper($suffix);
$foreignId = asset_create($pdo, ['SN' => $foreignSn, 'Hostname' => 'foreign-' . $suffix, 'company' => $companyB], $actor, 'manual');
$GLOBALS['test_collect_devices'][] = $foreignId;
$crossTenant = collect_apply($pdo, $auth, ['sn' => $foreignSn, 'hostname' => 'mine-' . $suffix], '203.0.113.9');
$GLOBALS['test_collect_devices'][] = (int) $crossTenant['id'];
check($crossTenant['created'] === true, 'write: the same serial in another tenant does not block the report');
check((int) $crossTenant['id'] !== $foreignId, 'write: a new row was created for this tenant');
check(($crossTenant['device']['company'] ?? '') === $companyA, 'write: the new row belongs to the collector tenant');

// A device that reports no serial while the instance requires one.
if (settings_bool('assets.require_sn')) {
    check_error(
        'write: a missing serial is named explicitly',
        fn () => collect_apply($pdo, $auth, ['hostname' => 'no-serial-' . $suffix], '203.0.113.9'),
        'serial_missing',
        422,
        ['SN' => 'required']
    );
} else {
    fwrite(STDOUT, "  skip serial_missing (assets.require_sn is off)\n");
}

if (asset_events_ready($pdo)) {
    $stmt = $pdo->prepare('SELECT action, note FROM asset_events WHERE asset_id = ? AND actor = ? ORDER BY id');
    $stmt->execute([(int) $created['id'], 'collector:' . $email]);
    $events = $stmt->fetchAll();
    $actions = array_column($events, 'action');
    check(in_array('create', $actions, true) && in_array('update', $actions, true), 'audit: create and update are recorded');
    check(($events[0]['note'] ?? '') === 'collector', 'audit: the note names the collector');
} else {
    fwrite(STDOUT, "  skip audit (asset_events not migrated)\n");
}

// --- throttling ------------------------------------------------------------
$tokenBucket = 'collect:token:' . substr((string) $tokenRow['token_hash'], 0, 32);
$ipBucket = 'collect:ip:198.51.100.7';
$GLOBALS['test_collect_buckets'][] = $tokenBucket;
$GLOBALS['test_collect_buckets'][] = $ipBucket;
rate_limit_reset($pdo, $tokenBucket);
rate_limit_reset($pdo, $ipBucket);

$limit = collect_rate_limit();
for ($i = 0; $i < $limit; $i++) {
    collect_rate_limit_token($pdo, $auth);
}
check_error('throttle: the per-token limit answers 429', fn () => collect_rate_limit_token($pdo, $auth), 'rate_limited', 429);

for ($i = 0; $i < $limit * 6; $i++) {
    collect_rate_limit_ip($pdo, '198.51.100.7');
}
check_error(
    'throttle: the per-address limit answers 429',
    fn () => collect_rate_limit_ip($pdo, '198.51.100.7'),
    'rate_limited',
    429
);

// --- the download ----------------------------------------------------------
$endpoint = 'https://cmdb.example.com/collect';
$bat = collect_script_bat($endpoint, $token);
$batLines = explode("\r\n", $bat);
$markerIndex = array_search(COLLECT_SCRIPT_MARKER, $batLines, true);
$exitIndex = null;
foreach ($batLines as $index => $line) {
    if (str_starts_with($line, 'exit /b %RC%')) {
        $exitIndex = $index;
        break;
    }
}

check(str_starts_with($bat, '@echo off'), 'script: the batch starts with @echo off');
check(str_contains($bat, 'set "BCMDB_SELF=%~f0"'), 'script: the launcher resolves its own path');
check(str_contains($bat, 'ExecutionPolicy Bypass'), 'script: the launcher bypasses the execution policy for the run');
check($markerIndex !== false && $exitIndex !== null && $exitIndex < $markerIndex, 'script: cmd exits before the embedded body');
$markerLines = array_keys($batLines, COLLECT_SCRIPT_MARKER, true);
check($markerLines === [$markerIndex], 'script: the separator line appears exactly once');
check(($batLines[$markerIndex + 1] ?? '') === '# BundledCMDB data collector.', 'script: the body starts right after the marker');
check(str_contains($bat, $endpoint), 'script: the endpoint is embedded');
check(str_contains($bat, $token), 'script: the token is embedded');
check(!str_contains($bat, '__ENDPOINT__') && !str_contains($bat, '__TOKEN__') && !str_contains($bat, '__VERSION__'), 'script: every placeholder is replaced');
check(preg_match('/[^\x09\x0A\x0D\x20-\x7E]/', $bat) !== 1, 'script: the file is pure ASCII');
check(str_contains($bat, "\r\n"), 'script: the batch uses CRLF line endings');
check(str_contains($bat, 'X-Collector-Token'), 'script: the token travels in the collector header');

$ps1 = collect_script_ps1($endpoint, $token);
check(str_contains($ps1, $endpoint) && str_contains($ps1, $token), 'script: the .ps1 variant carries the same values');
check(preg_match('/[^\x09\x0A\x0D\x20-\x7E]/', $ps1) !== 1, 'script: the .ps1 variant is pure ASCII');
check(str_contains($ps1, '$payload = [ordered]@{'), 'script: the .ps1 variant contains the inventory payload');
check(!str_contains($ps1, COLLECT_SCRIPT_MARKER), 'script: the plain .ps1 has no batch marker');

check(collect_file_name('Ivan.Silva@Example.invalid') === 'collect-ivan-silva.bat', 'script: the file name is derived from the e-mail');
check(collect_file_name('@invalid') === 'collect-device.bat', 'script: a nameless mailbox gets a fallback name');

// --- panel handlers --------------------------------------------------------
// The session has to be started *before* the fake identity is written: a later
// `session_start()` would replace `$_SESSION` with the (empty) stored session and
// the CSRF check would fail with 419.
session_boot();
$csrf = csrf_token();
$_SERVER['HTTP_X_CSRF_TOKEN'] = $csrf;
$_SESSION[SESSION_EMAIL] = $email;
$_SESSION[SESSION_COMPANY] = $companyA;
$_SESSION[SESSION_ROLE] = 'user';

$status = panel_collect_show();
check(($status['data']['enabled'] ?? false) === true, 'panel: the status reports the feature');
check(($status['data']['can_collect'] ?? false) === true, 'panel: the status reports the capability');
check(($status['data']['token']['uses'] ?? 0) >= 3, 'panel: the active token travels without the raw value');
check(!str_contains((string) json_encode($status['data']), COLLECT_TOKEN_PREFIX), 'panel: the status never contains the raw token');

body(['format' => 'ps1']);
$stored = panel_collect_store();
$file = $stored['data']['file'] ?? [];
check(($stored['status'] ?? 0) === 201, 'panel: generating a file answers 201');
check(($file['name'] ?? '') === preg_replace('/\.bat$/', '.ps1', collect_file_name($email)), 'panel: the .ps1 keeps the user name', (string) ($file['name'] ?? ''));
check(str_contains((string) ($file['content'] ?? ''), collect_script_endpoint()), 'panel: the file embeds this installation endpoint');
check(!str_contains((string) json_encode($stored['data']['token'] ?? []), COLLECT_TOKEN_PREFIX), 'panel: the response metadata stays token-free');

preg_match('/\$token = \'([^\']+)\'/', (string) ($file['content'] ?? ''), $matches);
$issuedFromFile = (string) ($matches[1] ?? '');
check($issuedFromFile !== '' && collect_token_by_hash($pdo, collect_token_hash($issuedFromFile)) !== null, 'panel: the token inside the file is the stored one');
check(collect_active_token($pdo, $email)['id'] === $stored['data']['token']['id'], 'panel: generating replaces the previous token');

body(['format' => 'exe']);
check_error('panel: an unknown format is refused', fn () => panel_collect_store(), 'validation_failed', 422, ['format' => 'invalid_option']);

$_SERVER['HTTP_X_CSRF_TOKEN'] = 'not-the-token';
check_error('panel: a write without the CSRF header is refused', fn () => panel_collect_store(), 'invalid_csrf', 419);
check_error('panel: revoking without the CSRF header is refused', fn () => panel_collect_revoke(), 'invalid_csrf', 419);
$_SERVER['HTTP_X_CSRF_TOKEN'] = $csrf;

settings_set('collect.enabled', '0', $actor);
check_error('panel: a disabled feature cannot issue a file', fn () => panel_collect_store(), 'collect_disabled', 403);
settings_set('collect.enabled', '1', $actor);

$revoked = panel_collect_revoke();
check(($revoked['data']['revoked'] ?? 0) >= 1, 'panel: revoking ends the active link');
check(collect_active_token($pdo, $email) === null, 'panel: no active link is left behind');
check_error('panel: the revoked file no longer authorises', fn () => collect_authorize($pdo, $issuedFromFile), 'token_revoked', 401);

unset($GLOBALS['APP_TEST_JSON_BODY']);

// --- cleanup ---------------------------------------------------------------
collect_test_cleanup();

$failures = (int) $GLOBALS['failures'];
$passes = (int) $GLOBALS['passes'];
fwrite(STDOUT, "\n{$passes} passed, {$failures} failed\n");
exit($failures === 0 ? 0 : 1);




