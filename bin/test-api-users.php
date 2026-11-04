#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * bin/test-api-users.php — integration tests for `GET|POST|PATCH|DELETE /v1/users`.
 *
 * Drives the HTTP layer (`lib/api_users.php`) and the roster model
 * (`lib/users.php`) against the configured database with real Bearer keys, then
 * removes everything it created: rows are deleted by e-mail and keys by id, so it
 * can run against a development database as often as needed.
 *
 * What it covers:
 *   - the dedicated `can_users` scope: normalisation default, 201/403/422 paths;
 *   - create: lower-cased e-mail, duplicate → 409, invalid e-mail / role /
 *     unknown field / missing company → 422;
 *   - company binding: a bound key writes its own tenant only and may never
 *     grant `superadmin`;
 *   - list/read isolation between tenants;
 *   - update: partial PATCH, immutable e-mail, role change, last-superadmin
 *     guard (asserted inside a rolled-back transaction);
 *   - upsert: `created` on the first call, update afterwards;
 *   - delete: idempotence error (404), audit row with `entity = user`;
 *   - session revocation: a deleted row ends the session on the next request.
 *
 * Usage: php bin/test-api-users.php    (exit 0 pass, 1 failure, 2 wrong env)
 */

require_once __DIR__ . '/../lib/bootstrap.php';

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

/**
 * The shape `api_authenticate()` returns; the handlers only read `key`/`company`.
 *
 * @return array{key: array, company: ?string, sources: list<string>, default_source: ?string, token: string}
 */
function test_auth(array $key, ?string $company): array
{
    return [
        'key'            => $key,
        'company'        => $company,
        'sources'        => [],
        'default_source' => null,
        'token'          => 'bin-test-token',
    ];
}

/**
 * Best-effort cleanup for everything this run created.
 *
 * Registered as a shutdown hook as well, so a fatal error (which skips the end of
 * the script) does not leave roster rows or API keys behind. Idempotent.
 */
function test_cleanup(): void
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
    foreach ((array) ($GLOBALS['test_user_emails'] ?? []) as $cleanupEmail) {
        try {
            $pdo->prepare('DELETE FROM users WHERE email = ?')->execute([(string) $cleanupEmail]);
        } catch (Throwable $e) {
            fwrite(STDERR, 'roster cleanup failed for ' . $cleanupEmail . ': ' . $e->getMessage() . "\n");
        }
    }
    foreach ((array) ($GLOBALS['test_key_ids'] ?? []) as $keyId) {
        try {
            api_key_delete($pdo, (int) $keyId);
        } catch (Throwable $e) {
            fwrite(STDERR, "api key cleanup failed (#{$keyId}): " . $e->getMessage() . "\n");
        }
    }
    if (asset_events_ready($pdo)) {
        foreach ((array) ($GLOBALS['test_event_actors'] ?? []) as $eventActor) {
            try {
                $pdo->prepare('DELETE FROM asset_events WHERE actor = ?')->execute([(string) $eventActor]);
            } catch (Throwable $e) {
                fwrite(STDERR, 'events cleanup failed: ' . $e->getMessage() . "\n");
            }
        }
    }
}

register_shutdown_function('test_cleanup');

$pdo = db();
if (!db_table_exists($pdo, 'users')) {
    fwrite(STDERR, "The `users` table is missing. Apply sql/legacy.sql first.\n");
    exit(2);
}
if (!db_column_exists($pdo, 'api_keys', 'can_users')) {
    fwrite(STDERR, "api_keys.can_users is missing. Run php bin/migrate.php.\n");
    exit(2);
}

$actor = 'bin/test-api-users.php';
$suffix = bin2hex(random_bytes(4));
$email = 'bin-test-users-' . $suffix . '@example.invalid';
$emailB = 'bin-test-users-b-' . $suffix . '@example.invalid';
$emailUp = 'bin-test-users-up-' . $suffix . '@example.invalid';
$emailLast = 'bin-test-users-last-' . $suffix . '@example.invalid';
$companyA = 'BinTest A ' . $suffix;
$companyB = 'BinTest B ' . $suffix;

$keyIds = [];
$globalKey = api_key_create($pdo, api_key_normalize_payload([
    'label'     => 'bin-test-users-global-' . $suffix,
    'can_read'  => true,
    'can_write' => true,
    'can_users' => true,
]), $actor);
$keyIds[] = (int) $globalKey['id'];

$readOnlyKey = api_key_create($pdo, api_key_normalize_payload([
    'label'     => 'bin-test-users-readonly-' . $suffix,
    'can_read'  => true,
    'can_write' => true,
]), $actor);
$keyIds[] = (int) $readOnlyKey['id'];

$boundKey = api_key_create($pdo, api_key_normalize_payload([
    'label'     => 'bin-test-users-bound-' . $suffix,
    'company'   => $companyA,
    'can_read'  => true,
    'can_users' => true,
]), $actor);
$keyIds[] = (int) $boundKey['id'];

$globalAuth = test_auth($globalKey['key'], null);
$readOnlyAuth = test_auth($readOnlyKey['key'], null);
$boundAuth = test_auth($boundKey['key'], $companyA);

// Everything the cleanup hook has to remove, collected up front so it also works
// when the script dies in the middle.
$GLOBALS['test_user_emails'] = [$email, $emailB, $emailUp, $emailLast];
$GLOBALS['test_key_ids'] = $keyIds;
$GLOBALS['test_event_actors'] = [
    'api:' . (string) $globalKey['key']['label'],
    'api:' . (string) $boundKey['key']['label'],
];

fwrite(STDOUT, "Users API tests (db=" . DB_NAME . ", actor={$actor})\n");

// --- scope ---------------------------------------------------------------
$normalizedDefault = api_key_normalize_payload(['label' => 'x', 'can_read' => true]);
check((int) $normalizedDefault['can_users'] === 0, 'scope: a key defaults to can_users = 0');
check((int) api_key_normalize_payload(['label' => 'x', 'can_users' => true])['can_users'] === 1, 'scope: the flag survives normalisation');

try {
    api_require_scope($globalAuth, 'users');
    check(true, 'scope: can_users passes the users scope');
} catch (Throwable $e) {
    check(false, 'scope: can_users passes the users scope', $e->getMessage());
}
check_error('scope: a key without can_users is refused', function () use ($readOnlyAuth): void {
    api_require_scope($readOnlyAuth, 'users');
}, 'forbidden', 403);

// --- create --------------------------------------------------------------
$created = api_create_user($pdo, $globalAuth, [
    'email'   => strtoupper($email),
    'company' => $companyA,
    'role'    => 'Manager',
]);
check($created['email'] === $email, 'create: the e-mail is lower-cased', (string) $created['email']);
check($created['company'] === $companyA, 'create: the company round-trips');
check($created['role'] === 'manager', 'create: the role is canonical', (string) $created['role']);

check_error('create: a duplicate e-mail is 409', function () use ($pdo, $globalAuth, $email, $companyA): void {
    api_create_user($pdo, $globalAuth, ['email' => $email, 'company' => $companyA, 'role' => 'user']);
}, 'conflict', 409, ['email' => 'duplicate']);

check_error('create: an invalid e-mail is 422', function () use ($pdo, $globalAuth, $companyA): void {
    api_create_user($pdo, $globalAuth, ['email' => 'not-an-email', 'company' => $companyA, 'role' => 'user']);
}, 'validation_failed', 422, ['email' => 'invalid_email']);

check_error('create: an unknown role is 422', function () use ($pdo, $globalAuth, $companyA): void {
    api_create_user($pdo, $globalAuth, ['email' => 'bin-test-bad-role@example.invalid', 'company' => $companyA, 'role' => 'emperor']);
}, 'validation_failed', 422, ['role' => 'invalid_option']);

check_error('create: an unknown body key is 422', function () use ($pdo, $globalAuth, $companyA): void {
    api_create_user($pdo, $globalAuth, [
        'email' => 'bin-test-unknown@example.invalid', 'company' => $companyA, 'role' => 'user', 'nickname' => 'x',
    ]);
}, 'validation_failed', 422, ['nickname' => 'unknown_field']);

check_error('create: a global key must send a company', function () use ($pdo, $globalAuth): void {
    api_create_user($pdo, $globalAuth, ['email' => 'bin-test-no-company@example.invalid', 'role' => 'user']);
}, 'validation_failed', 422, ['company' => 'required']);

// --- company binding -----------------------------------------------------
$boundCreated = api_create_user($pdo, $boundAuth, ['email' => $emailB, 'role' => 'user']);
check($boundCreated['company'] === $companyA, 'binding: a bound key stamps its own company');

check_error('binding: a bound key cannot write another tenant', function () use ($pdo, $boundAuth): void {
    api_create_user($pdo, $boundAuth, [
        'email' => 'bin-test-other-tenant@example.invalid', 'company' => 'Somewhere Else', 'role' => 'user',
    ]);
}, 'forbidden', 403);

check_error('binding: a bound key cannot grant superadmin', function () use ($pdo, $boundAuth): void {
    api_create_user($pdo, $boundAuth, ['email' => 'bin-test-escalate@example.invalid', 'role' => 'superadmin']);
}, 'validation_failed', 422, ['role' => 'role_not_allowed']);

// --- list / read ---------------------------------------------------------
$_GET = ['company' => $companyB, 'per_page' => '10'];
$boundList = api_list_users($pdo, $boundAuth);
$boundEmails = array_column($boundList['users'], 'email');
check($boundList['total'] >= 2, 'list: the bound key sees its own tenant', (string) $boundList['total']);
check(in_array($email, $boundEmails, true) && in_array($emailB, $boundEmails, true), 'list: every row of the tenant is returned');
check(array_unique(array_column($boundList['users'], 'company')) === [$companyA], 'list: the ?company filter is ignored for a bound key');

$_GET = ['search' => $emailB, 'per_page' => '10'];
$globalList = api_list_users($pdo, $globalAuth);
check($globalList['total'] === 1 && $globalList['users'][0]['email'] === $emailB, 'list: search narrows the roster');

$_GET = ['page' => '999', 'per_page' => '1'];
$paged = api_list_users($pdo, $globalAuth);
check($paged['per_page'] === 1 && $paged['page'] <= max(1, $paged['pages']), 'list: pagination is clamped to the last page');
$_GET = [];

check(user_find($pdo, $email, $companyB) === null, 'read: another tenant cannot resolve the e-mail');
check(user_find($pdo, strtoupper($email), $companyA) !== null, 'read: lookup is case-insensitive');

// --- update --------------------------------------------------------------
$patched = api_patch_user($pdo, $globalAuth, $email, ['role' => 'admin']);
check($patched['role'] === 'admin' && $patched['company'] === $companyA, 'patch: the role changes, the company stays');

check_error('patch: the e-mail is immutable', function () use ($pdo, $globalAuth, $email): void {
    api_patch_user($pdo, $globalAuth, $email, ['email' => 'bin-test-rename@example.invalid']);
}, 'validation_failed', 422, ['email' => 'immutable']);

check_error('patch: an unknown body key is 422', function () use ($pdo, $globalAuth, $email): void {
    api_patch_user($pdo, $globalAuth, $email, ['nickname' => 'x']);
}, 'validation_failed', 422, ['nickname' => 'unknown_field']);

check_error('patch: an unknown e-mail is 404', function () use ($pdo, $globalAuth): void {
    api_patch_user($pdo, $globalAuth, 'bin-test-nobody@example.invalid', ['role' => 'user']);
}, 'not_found', 404);

$boundPatch = api_patch_user($pdo, $boundAuth, $email, ['role' => 'user']);
check($boundPatch['role'] === 'user', 'patch: a bound key may edit its own tenant');

check_error('patch: a bound key cannot move a row to another tenant', function () use ($pdo, $boundAuth, $email, $companyB): void {
    api_patch_user($pdo, $boundAuth, $email, ['company' => $companyB]);
}, 'forbidden', 403);

// --- last-superadmin guard ------------------------------------------------
// The guard only fires when no other superadmin exists. Rather than touching the
// real rows, the check runs inside a transaction that is rolled back.
$lastUser = api_create_user($pdo, $globalAuth, ['email' => $emailLast, 'company' => $companyA, 'role' => 'superadmin']);
check($lastUser['role'] === 'superadmin', 'guard: the fixture superadmin exists');

$pdo->beginTransaction();
try {
    $pdo->exec("UPDATE users SET role = 'user' WHERE role = 'superadmin' AND email <> " . $pdo->quote($emailLast));
    check_error('guard: the last superadmin cannot be demoted', function () use ($pdo, $globalAuth, $emailLast): void {
        api_patch_user($pdo, $globalAuth, $emailLast, ['role' => 'admin']);
    }, 'last_superadmin', 409, ['role' => 'last_superadmin']);
    check_error('guard: the last superadmin cannot be deleted', function () use ($pdo, $globalAuth, $emailLast): void {
        api_delete_user($pdo, $globalAuth, $emailLast);
    }, 'last_superadmin', 409, ['role' => 'last_superadmin']);
} finally {
    $pdo->rollBack();
}
check(user_find($pdo, $emailLast, $companyA) !== null, 'guard: the rolled-back fixture is still there');

// --- upsert --------------------------------------------------------------
$upsertCreate = api_upsert_user($pdo, $globalAuth, ['email' => $emailUp, 'company' => $companyA, 'role' => 'user']);
check($upsertCreate['created'] === true && $upsertCreate['user']['role'] === 'user', 'upsert: the first call creates');

$upsertUpdate = api_upsert_user($pdo, $globalAuth, ['email' => $emailUp, 'company' => $companyA, 'role' => 'manager']);
check($upsertUpdate['created'] === false && $upsertUpdate['user']['role'] === 'manager', 'upsert: the second call updates');
check(user_find($pdo, $emailUp)['role'] === 'manager', 'upsert: the row holds the new role');

$otherRow = api_upsert_user($pdo, $globalAuth, ['email' => $emailB, 'company' => $companyB, 'role' => 'user']);
check($otherRow['user']['company'] === $companyB, 'upsert fixtures: a row in the other tenant');

check_error('upsert: a bound key cannot adopt another tenant row', function () use ($pdo, $boundAuth, $emailB, $companyB): void {
    api_upsert_user($pdo, $boundAuth, ['email' => $emailB, 'company' => $companyB, 'role' => 'user']);
}, 'forbidden', 403);

// --- delete --------------------------------------------------------------
$deleted = api_delete_user($pdo, $globalAuth, $emailB);
check(($deleted['deleted'] ?? false) === true && $deleted['email'] === $emailB, 'delete: the row is removed');
check(user_find($pdo, $emailB) === null, 'delete: the row is gone');

check_error('delete: a second delete is 404', function () use ($pdo, $globalAuth, $emailB): void {
    api_delete_user($pdo, $globalAuth, $emailB);
}, 'not_found', 404);

// --- audit trail ---------------------------------------------------------
if (asset_events_ready($pdo) && asset_events_extended($pdo)) {
    $stmt = $pdo->prepare('SELECT action, entity FROM asset_events WHERE entity_id = ? AND actor = ? ORDER BY id');
    $stmt->execute([$email, 'api:' . (string) $globalKey['key']['label']]);
    $events = $stmt->fetchAll();
    $actions = array_column($events, 'action');
    check(in_array('create', $actions, true), 'audit: the create is recorded');
    check(in_array('role_change', $actions, true), 'audit: the role change is recorded');
    check(array_unique(array_column($events, 'entity')) === ['user'], 'audit: the entity is user');
} else {
    fwrite(STDOUT, "  skip audit (asset_events not migrated)\n");
}

// --- session revocation --------------------------------------------------
$_SESSION = [
    SESSION_EMAIL   => $emailUp,
    SESSION_COMPANY => '',
    SESSION_ROLE    => 'superadmin',
];
$synced = session_require();
check($synced['role'] === 'manager' && $synced['company'] === $companyA, 'session: the roster row wins over a stale session', json_encode($synced));

api_delete_user($pdo, $globalAuth, $emailUp);
check_error('session: a deleted row revokes access', function () use ($emailUp, $companyA): void {
    session_roster_sync(['email' => $emailUp, 'company' => $companyA, 'role' => 'manager'], true);
}, 'unauthorized', 401);
check(!isset($_SESSION[SESSION_EMAIL]), 'session: the identity is cleared');

// --- cleanup -------------------------------------------------------------
test_cleanup();

$failures = (int) $GLOBALS['failures'];
$passes = (int) $GLOBALS['passes'];
fwrite(STDOUT, "\n{$passes} passed, {$failures} failed\n");
exit($failures === 0 ? 0 : 1);

