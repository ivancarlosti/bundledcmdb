<?php
declare(strict_types=1);

/**
 * lib/collect.php — the experimental "Collect data" feature.
 *
 * A signed-in user downloads a double-clickable Windows script that carries a
 * short-lived token; running it POSTs the machine's inventory to
 * `public/collect.php` and the device is created — or updated — under that
 * user's own e-mail. The feature exists because the alternative (asking a
 * non-technical colleague to type a serial number and nine hardware facts) never
 * produced a usable inventory.
 *
 * Security model, in one place:
 *
 *  - the token is a bearer credential with no scope beyond "write a device for
 *    the user who downloaded it". Only its SHA-256 is stored
 *    (`collector_tokens.token_hash`), the raw value lives in the script;
 *  - it expires (`collect.token_ttl_minutes`, default 2 h) and is revoked the
 *    moment the same user downloads a new one (`collect_issue_token()`), so a
 *    forwarded e-mail is the only way to leak it — and the window is short;
 *  - the token is *not* enough on its own: the e-mail must still be on the
 *    roster, must still have a company, and the role it has *now* must still
 *    hold `devices.collect`. A fired employee's download stops working;
 *  - the payload is restricted to a field allow-list (`collect_allowed_columns()`)
 *    and `company`/`user_email`/`source`/`status` are server-controlled, so a
 *    crafted request cannot assign the machine to somebody else, move it to
 *    another tenant, or rewrite an asset tag;
 *  - ownership: an existing device whose `UserEmail` belongs to another person
 *    is refused (`device_assigned_to_other_user`), and a soft-deleted serial is
 *    refused instead of silently resurrected (`serial_soft_deleted`).
 *
 * The model is deliberately separate from `lib/api_auth.php`: an API key is an
 * integration credential with scopes and sources, a collector token is a
 * one-user, one-purpose, two-hour link. Sharing the code would mean either
 * widening the key scopes or narrowing the collector; neither is desirable.
 */

/** Source slug stamped on devices created by the collector (seeded by 0024). */
const COLLECT_SOURCE = 'collector';

/** Prefix of the raw token, so it is recognisable in a log or a screenshot. */
const COLLECT_TOKEN_PREFIX = 'bcx_';

/** How many bytes of entropy one token carries (24 → 48 hex chars). */
const COLLECT_TOKEN_BYTES = 24;

/** True when the instance opted into the feature (Settings → Collect). */
function collect_enabled(): bool
{
    try {
        return settings_bool('collect.enabled');
    } catch (Throwable) {
        return false;
    }
}

/** Lifetime of one download in minutes, clamped to the setting's own bounds. */
function collect_ttl_minutes(): int
{
    $minutes = 120;
    try {
        $minutes = (int) settings_get('collect.token_ttl_minutes', 120);
    } catch (Throwable) {
        // keep the default
    }
    return max(5, min(10080, $minutes));
}

/** Requests per token per minute. */
function collect_rate_limit(): int
{
    $limit = 10;
    try {
        $limit = (int) settings_get('collect.rate_limit', 10);
    } catch (Throwable) {
        // keep the default
    }
    return max(1, min(600, $limit));
}

/** Cheap pre-check before touching the table (the panel hides the tab when off). */
function collect_tokens_ready(PDO $pdo): bool
{
    return db_table_exists($pdo, 'collector_tokens');
}

/** A fresh raw token. Never stored; it goes into the downloaded file only. */
function collect_token_generate(): string
{
    return COLLECT_TOKEN_PREFIX . bin2hex(random_bytes(COLLECT_TOKEN_BYTES));
}

/** What the database keeps: the SHA-256 of the raw token. */
function collect_token_hash(string $token): string
{
    return hash('sha256', $token);
}

/**
 * The token row a user currently holds, or null.
 *
 * "Active" is `revoked_at IS NULL AND expires_at > now`; the comparison happens
 * in PHP because the database session timezone is not guaranteed to be UTC.
 *
 * @return array<string, mixed>|null
 */
function collect_active_token(PDO $pdo, string $email): ?array
{
    if (!collect_tokens_ready($pdo)) {
        return null;
    }
    $stmt = $pdo->prepare(
        'SELECT * FROM collector_tokens
          WHERE email = ? AND revoked_at IS NULL AND expires_at > ?
          ORDER BY id DESC LIMIT 1'
    );
    $stmt->execute([user_email_normalize($email), app_now_utc()]);
    $row = $stmt->fetch();
    return $row === false ? null : $row;
}

/** Revoke every live token of one user; returns how many rows changed. */
function collect_revoke(PDO $pdo, string $email): int
{
    if (!collect_tokens_ready($pdo)) {
        return 0;
    }
    $stmt = $pdo->prepare(
        'UPDATE collector_tokens SET revoked_at = ?
          WHERE email = ? AND revoked_at IS NULL'
    );
    $stmt->execute([app_now_utc(), user_email_normalize($email)]);
    return $stmt->rowCount();
}

/**
 * Issue a new token for one user, revoking the previous one.
 *
 * One active download per user is deliberate: a second file would mean two
 * credentials with the same authority and no way for the user to tell which one
 * is which on their desktop.
 *
 * @param array{email: string, company: string, role: string} $user
 * @return array{token: string, row: array<string, mixed>}
 */
function collect_issue_token(PDO $pdo, array $user, string $fileName): array
{
    $email = user_email_normalize($user['email']);
    $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
    $expires = $now->modify('+' . collect_ttl_minutes() . ' minutes');

    $pdo->beginTransaction();
    try {
        collect_revoke($pdo, $email);
        $token = collect_token_generate();
        $stmt = $pdo->prepare(
            'INSERT INTO collector_tokens
                (token_hash, email, company, role, file_name, created_at, expires_at, uses)
             VALUES (?, ?, ?, ?, ?, ?, ?, 0)'
        );
        $stmt->execute([
            collect_token_hash($token),
            $email,
            $user['company'] === '' ? null : $user['company'],
            session_role_normalize($user['role']),
            mb_substr($fileName, 0, 191),
            $now->format('Y-m-d H:i:s'),
            $expires->format('Y-m-d H:i:s'),
        ]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    $row = collect_token_by_hash($pdo, collect_token_hash($token));
    return ['token' => $token, 'row' => $row ?? []];
}

/** One token row by its hash, revoked or not. */
function collect_token_by_hash(PDO $pdo, string $hash): ?array
{
    if (!collect_tokens_ready($pdo)) {
        return null;
    }
    $stmt = $pdo->prepare('SELECT * FROM collector_tokens WHERE token_hash = ? LIMIT 1');
    $stmt->execute([$hash]);
    $row = $stmt->fetch();
    return $row === false ? null : $row;
}

/** Public (non-secret) view of a token row, for the panel. */
function collect_token_payload(array $row): array
{
    return [
        'id'           => isset($row['id']) ? (int) $row['id'] : null,
        'file_name'    => (string) ($row['file_name'] ?? ''),
        'created_at'   => asset_iso_datetime($row['created_at'] ?? null),
        'expires_at'   => asset_iso_datetime($row['expires_at'] ?? null),
        'revoked_at'   => asset_iso_datetime($row['revoked_at'] ?? null),
        'last_used_at' => asset_iso_datetime($row['last_used_at'] ?? null),
        'last_used_ip' => $row['last_used_ip'] ?? null,
        'uses'         => isset($row['uses']) ? (int) $row['uses'] : 0,
    ];
}

/**
 * Resolve a raw token to the user it may write for, enforcing every gate.
 *
 * Runs in this order on purpose: token first (cheap, no user enumeration),
 * expiry, feature flag, roster, company, capability. Each failure has its own
 * code so the script can print something a non-technical user can act on.
 *
 * @return array{token: array<string, mixed>, user: array{email: string, company: string, role: string}}
 * @throws ApiError
 */
function collect_authorize(PDO $pdo, string $rawToken): array
{
    $rawToken = trim($rawToken);
    if ($rawToken === '' || !str_starts_with($rawToken, COLLECT_TOKEN_PREFIX)) {
        throw new ApiError('invalid_token', 'The collector link is not valid. Download a new one.', 401);
    }

    $row = collect_token_by_hash($pdo, collect_token_hash($rawToken));
    if ($row === null) {
        throw new ApiError('invalid_token', 'The collector link is not valid. Download a new one.', 401);
    }
    if (($row['revoked_at'] ?? null) !== null) {
        throw new ApiError('token_revoked', 'This collector link was replaced by a newer download. Use the latest file.', 401);
    }
    if ((string) ($row['expires_at'] ?? '') <= app_now_utc()) {
        throw new ApiError('token_expired', 'This collector link expired. Download a new one and run it again.', 401);
    }
    if (!collect_enabled()) {
        throw new ApiError('collect_disabled', 'Data collection is disabled on this installation.', 403);
    }

    $email = user_email_normalize((string) ($row['email'] ?? ''));
    $rosterRow = user_find($pdo, $email);
    if ($rosterRow === null) {
        throw new ApiError('user_not_found', 'Your account is no longer on the authorised list.', 401);
    }

    $company = trim((string) ($rosterRow['company'] ?? ''));
    if ($company === '') {
        throw new ApiError('company_unassigned', 'Your account has no company. Ask an administrator to assign one.', 403);
    }

    // The *current* role decides, not the one that was current at download time:
    // a demotion or a revoked capability has to bite before the link expires.
    $role = session_role_normalize((string) ($rosterRow['role'] ?? ''));
    if (!permission_capability_allowed($role, 'devices.collect', $pdo)) {
        throw new ApiError('role_not_allowed', 'Your role may not collect data. Ask an administrator.', 403);
    }

    return [
        'token' => $row,
        'user'  => ['email' => $email, 'company' => $company, 'role' => $role],
    ];
}

/**
 * Fields the collector may write.
 *
 * Identity and hardware facts plus the whole inventory-agent block. Everything
 * else — asset tag, status, notes, warranty, cypher keys, BYOD, the e-mail the
 * device is assigned to, the company and the source — stays with the panel.
 *
 * @return list<string>
 */
function collect_allowed_columns(): array
{
    $columns = [
        'UUID', 'SN', 'Hostname', 'OS', 'OSVersion', 'Manufacturer',
        'CPUs', 'HDs', 'HDsTypes', 'HDsSpacesGB', 'PhysicalDisks',
        'NetworkAdapters', 'MACAddresses', 'PrimaryLocalIP', 'PrimaryRemoteIP',
    ];
    foreach (array_keys(asset_agent_columns()) as $column) {
        $columns[] = $column;
    }
    return array_values(array_unique($columns));
}

/**
 * Field map for the collector payload.
 *
 * The script sends the `agent_*` keys of the public map for the agent block and
 * the plain keys for the legacy identity columns, so one map covers both and a
 * key can never mean two different columns depending on the endpoint.
 *
 * @return array<string, string>
 */
function collect_field_map(): array
{
    // `+` keeps the public keys on a collision, so `hostname`/`os`/
    // `manufacturer` keep meaning the legacy columns while the agent's own
    // spellings for the *other* facts (`build`, `secureBoot`, `ramGB`, …) are
    // accepted as aliases for their `Agent*` column.
    return asset_api_field_map() + [
        'physicaldisks'  => 'PhysicalDisks',
        'cpuname'        => 'CPUs',
        'build'          => 'AgentOSBuild',
        'bios'           => 'AgentBIOS',
        'secureboot'     => 'AgentSecureBoot',
        'model'          => 'AgentModel',
        'cpu'            => 'AgentCPU',
        'gpu'            => 'AgentGPU',
        'ramgb'          => 'AgentRAMGB',
        'ip'             => 'AgentIP',
        'loggeduser'     => 'AgentLoggedUser',
        'allloggedusers' => 'AgentAllLoggedUsers',
        'executionuser'  => 'AgentExecutionUser',
        'antivirus'      => 'AgentAntivirus',
    ];
}

/**
 * Map, filter and normalise one collector payload.
 *
 * @return array{data: array<string, mixed>, errors: array<string, string>}
 */
function collect_map_payload(array $payload): array
{
    $mapped = asset_api_map_input($payload, collect_field_map());
    $allowed = collect_allowed_columns();
    $errors = [];
    foreach ($mapped['unknown'] as $unknown) {
        $errors[(string) $unknown] = 'forbidden_field';
    }

    $data = [];
    foreach ($mapped['data'] as $column => $value) {
        if (!in_array($column, $allowed, true)) {
            $errors[$column] = 'forbidden_field';
            continue;
        }
        $data[$column] = $value;
    }

    $normalizeErrors = [];
    $data = asset_normalize_input($data, $normalizeErrors);
    $errors = array_merge($errors, $normalizeErrors);

    return ['data' => $data, 'errors' => $errors];
}

/**
 * Drop null/empty values, so a partial report can never wipe a maintained field.
 *
 * @param array<string, mixed> $data
 * @return array<string, mixed>
 */
function collect_drop_empty(array $data): array
{
    return array_filter($data, static fn (mixed $value): bool => $value !== null && $value !== '');
}

/** File name offered to the browser; ASCII only, so no header ever mangles it. */
function collect_file_name(string $email): string
{
    $slug = strtolower((string) preg_replace('/[^A-Za-z0-9]+/', '-', (string) strstr($email, '@', true)));
    $slug = trim($slug, '-');
    if ($slug === '') {
        $slug = 'device';
    }
    return 'collect-' . mb_substr($slug, 0, 40) . '.bat';
}

/**
 * Create or update the device for one authorised collector request.
 *
 * @param array{token: array<string, mixed>, user: array{email: string, company: string, role: string}} $auth
 * @return array{device: array, created: bool, id: int}
 * @throws ApiError
 */
function collect_apply(PDO $pdo, array $auth, array $payload, string $ip = ''): array
{
    $email = $auth['user']['email'];
    $company = $auth['user']['company'];
    // `assets.created_by` and `asset_events.actor` are VARCHAR(191): the longest
    // allowed e-mail plus the prefix would not fit, and strict mode would turn a
    // successful collect into a 500.
    $actor = mb_substr('collector:' . $email, 0, 191);

    $mapped = collect_map_payload($payload);
    $data = $mapped['data'];
    $errors = $mapped['errors'];

    $data['LastSeen'] = app_now_utc();
    // The assignment is the whole point of the feature and stays server-side: a
    // payload that sends `user_email` is reported as a forbidden field instead of
    // being honoured, so a crafted request cannot hand the machine to somebody.
    $data['UserEmail'] = $email;

    // Identity: matched inside the caller's company only, so the same serial in
    // another tenant is somebody else's device and is allowed to exist.
    $existing = null;
    $deleted = null;
    foreach (['UUID', 'SN'] as $column) {
        $value = trim((string) ($data[$column] ?? ''));
        if ($value === '') {
            continue;
        }
        $found = asset_find_by($pdo, [$column => $value], $company, true);
        if ($found === null) {
            continue;
        }
        if (($found['deleted_at'] ?? null) !== null) {
            $deleted = $found;
            break;
        }
        $existing = $found;
        break;
    }

    if ($deleted !== null) {
        throw new ApiError(
            'serial_soft_deleted',
            'This device was deleted from the inventory. Ask an administrator to restore it before collecting again.',
            409,
            ['existing_device_id' => (string) ($deleted['Id'] ?? '')]
        );
    }

    if ($existing !== null) {
        $owner = trim((string) ($existing['UserEmail'] ?? ''));
        if ($owner !== '' && strtolower($owner) !== $email) {
            throw new ApiError(
                'device_assigned_to_other_user',
                'This device is assigned to ' . $owner . '. Ask an administrator to change the owner.',
                409,
                ['existing_device_id' => (string) ($existing['Id'] ?? ''), 'owner' => $owner]
            );
        }
        // A partial report must never wipe a value a human maintained.
        $data = collect_drop_empty($data);
    }

    $partial = $existing !== null;
    $errors = array_merge($errors, asset_validate($data, $partial));
    if (($errors['SN'] ?? null) === 'required') {
        // A machine that reports no serial cannot become a device while the
        // instance requires one; say so instead of echoing a raw field map.
        throw new ApiError(
            'serial_missing',
            'This machine did not report a serial number and this installation requires one. Ask an administrator to fill it in.',
            422,
            ['SN' => 'required']
        );
    }
    if ($errors !== []) {
        throw new ApiError('validation_failed', 'The collected data could not be validated.', 422, $errors);
    }

    if ($existing === null) {
        $duplicate = asset_find_duplicate($pdo, $data, null, $company);
        if ($duplicate !== null) {
            throw new ApiError('conflict', 'A device with the same ' . $duplicate['field'] . ' already exists.', 409, [
                $duplicate['field']  => 'duplicate',
                'existing_device_id' => (string) $duplicate['id'],
            ]);
        }
    }

    $data['company'] = $company;

    $pdo->beginTransaction();
    try {
        if ($existing !== null) {
            $id = (int) $existing['Id'];
            asset_update($pdo, $id, $data, $actor, $company);
            $created = false;
        } else {
            $id = asset_create($pdo, $data, $actor, COLLECT_SOURCE);
            $created = true;
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    $row = asset_get($pdo, $id, $company, true) ?? [];

    // Same audit trail as every other writer (`lib/asset_events.php`); the note
    // names the collector so the Logs screen shows where the change came from.
    asset_event_record(
        $pdo,
        $id,
        $created ? 'create' : 'update',
        $actor,
        asset_event_diff($created ? [] : (array) $existing, $data),
        'collector',
        asset_event_device_meta($row, $id, $company)
    );

    collect_mark_used($pdo, (int) ($auth['token']['id'] ?? 0), $ip);

    return [
        'device'  => asset_api_payload($row, []),
        'created' => $created,
        'id'      => $id,
    ];
}

/** Record one successful use (and the address it came from). */
function collect_mark_used(PDO $pdo, int $tokenId, string $ip): void
{
    if ($tokenId <= 0 || !collect_tokens_ready($pdo)) {
        return;
    }
    $stmt = $pdo->prepare(
        'UPDATE collector_tokens
            SET uses = uses + 1, last_used_at = ?, last_used_ip = ?
          WHERE id = ?'
    );
    $stmt->execute([app_now_utc(), $ip === '' ? null : mb_substr($ip, 0, 45), $tokenId]);
}

/**
 * Per-address throttle, counted *before* the token is resolved.
 *
 * It protects the lookup itself: an unauthenticated client can otherwise make
 * the endpoint run one indexed query per attempt as fast as it can connect. The
 * cap is generous (six times the per-token limit) because a whole office behind
 * one NAT address collects at the same time.
 *
 * @throws ApiError `rate_limited` (429) with `Retry-After`
 */
function collect_rate_limit_ip(PDO $pdo, string $ip): void
{
    $result = rate_limit_hit($pdo, 'collect:ip:' . ($ip === '' ? 'unknown' : $ip), collect_rate_limit() * 6);
    if ($result['allowed']) {
        return;
    }
    if (!headers_sent()) {
        header('Retry-After: ' . $result['retry_after']);
    }
    throw new ApiError('rate_limited', 'Too many requests. Try again in ' . $result['retry_after'] . ' s.', 429);
}

/**
 * Per-token throttle, counted after authorisation.
 *
 * A token is reusable inside its window (a user may re-run the file after fixing
 * the network), but a stuck retry loop must not write devices in a circle.
 *
 * @throws ApiError `rate_limited` (429) with `Retry-After`
 */
function collect_rate_limit_token(PDO $pdo, array $auth): void
{
    $bucket = 'collect:token:' . substr((string) ($auth['token']['token_hash'] ?? ''), 0, 32);
    $result = rate_limit_hit($pdo, $bucket, collect_rate_limit());
    if ($result['allowed']) {
        return;
    }
    if (!headers_sent()) {
        header('Retry-After: ' . $result['retry_after']);
    }
    throw new ApiError('rate_limited', 'Too many requests. Try again in ' . $result['retry_after'] . ' s.', 429);
}
