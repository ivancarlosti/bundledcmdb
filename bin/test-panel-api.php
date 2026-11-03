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

app_boot();

if (app_env() === 'production') {
    fwrite(STDERR, "Refusing to run the write tests with APP_ENV=production.\n");
    exit(2);
}

$pdo = db_try();
if ($pdo === null) {
    fwrite(STDOUT, "SKIP: no database reachable — panel API write tests not run.\n");
    exit(0);
}

ini_set('session.cache_limiter', '');
session_boot();

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

    // --- attachments -------------------------------------------------------
    if (!panel_storage_configured()) {
        as_role('manager', $companyA);
        check_error('files: storage not configured → 503', fn () => panel_devices_files_store(['id' => (string) $id]), 'storage_not_configured', 503);
    }
    as_role('user', $companyA);
    check_error('files: user cannot upload → 403', fn () => panel_devices_files_store(['id' => (string) $id]), 'forbidden', 403);

    // --- hard delete -------------------------------------------------------
    as_role('superadmin', '');
    $_GET = ['hard' => '1'];
    $hardDeleted = panel_devices_delete(['id' => (string) $id]);
    check($hardDeleted['data']['hard'] === true, 'delete: superadmin hard deletes');
    $_GET = [];
    check(asset_get($pdo, $id, null, true) === null, 'delete: row is gone');
    $createdIds = array_values(array_diff($createdIds, [$id]));
} catch (Throwable $e) {
    check(false, 'unexpected exception', get_class($e) . ': ' . $e->getMessage());
} finally {
    clear_body();
    $_GET = [];
    as_role('superadmin', '');
    foreach ($createdIds as $createdId) {
        try {
            asset_hard_delete($pdo, (int) $createdId);
        } catch (Throwable $e) {
            fwrite(STDERR, "cleanup failed for #{$createdId}: " . $e->getMessage() . "\n");
        }
    }
}

$failures = (int) $GLOBALS['failures'];
$passes = (int) $GLOBALS['passes'];
fwrite(STDOUT, "\n{$passes} passed, {$failures} failed\n");
exit($failures === 0 ? 0 : 1);

