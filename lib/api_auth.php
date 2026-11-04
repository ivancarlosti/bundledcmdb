<?php
declare(strict_types=1);

/**
 * lib/api_auth.php — API key lifecycle and request authentication.
 *
 * Tokens look like `bcmdb_<prefix>_<secret>`. Only a SHA-256 hash is stored, so
 * a database leak does not expose usable tokens; the token itself is shown once
 * on creation/rotation.
 */

/** @return array{token: string, prefix: string, hash: string} */
function api_token_generate(): array
{
    $prefix = bin2hex(random_bytes(4));
    $secret = bin2hex(random_bytes(24));
    $token = 'bcmdb_' . $prefix . '_' . $secret;

    return ['token' => $token, 'prefix' => $prefix, 'hash' => hash('sha256', $token)];
}

function api_token_looks_valid(string $token): bool
{
    return preg_match('/^bcmdb_[a-z0-9]{4,16}_[A-Za-z0-9]{16,64}$/', $token) === 1;
}

/** Row without the hash (safe for HTML/JSON output). */
function api_key_public(array $row): array
{
    unset($row['key_hash']);
    return $row;
}

/** @return list<array> */
function api_keys_all(PDO $pdo): array
{
    return $pdo->query('SELECT * FROM api_keys ORDER BY created_at DESC, id DESC')->fetchAll();
}

function api_key_get(PDO $pdo, int $id): ?array
{
    $stmt = $pdo->prepare('SELECT * FROM api_keys WHERE id = ?');
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    return $row === false ? null : $row;
}

/** Normalise a submitted key payload. */
function api_key_normalize_payload(array $input): array
{
    $sources = $input['allowed_sources'] ?? '';
    if (is_array($sources)) {
        $sources = implode(',', array_map('trim', $sources));
    }
    $sources = implode(',', array_values(array_filter(array_map('trim', explode(',', (string) $sources)))));

    return [
        'label'           => trim((string) ($input['label'] ?? '')),
        'company'         => trim((string) ($input['company'] ?? '')) ?: null,
        'source_slug'     => trim((string) ($input['source_slug'] ?? '')) ?: null,
        'allowed_sources' => $sources === '' ? null : $sources,
        'ip_allowlist'    => trim((string) ($input['ip_allowlist'] ?? '')) ?: null,
        'can_read'        => !empty($input['can_read']) ? 1 : 0,
        'can_write'       => !empty($input['can_write']) ? 1 : 0,
        'can_delete'      => !empty($input['can_delete']) ? 1 : 0,
        'can_users'       => !empty($input['can_users']) ? 1 : 0,
        'is_active'       => array_key_exists('is_active', $input) ? (!empty($input['is_active']) ? 1 : 0) : 1,
    ];
}

/**
 * @return array<string, string> field => error code
 */
function api_key_validate(PDO $pdo, array $payload): array
{
    $errors = [];
    if ($payload['label'] === '' || mb_strlen((string) $payload['label']) > 64) {
        $errors['label'] = $payload['label'] === '' ? 'required' : 'too_long';
    }
    if ($payload['company'] !== null && mb_strlen((string) $payload['company']) > 191) {
        $errors['company'] = 'too_long';
    }
    if ($payload['source_slug'] !== null && !source_exists($pdo, (string) $payload['source_slug'])) {
        $errors['source_slug'] = 'unknown_source';
    }
    foreach (array_filter(explode(',', (string) ($payload['allowed_sources'] ?? ''))) as $slug) {
        if (!source_exists($pdo, $slug)) {
            $errors['allowed_sources'] = 'unknown_source';
            break;
        }
    }
    if ($payload['allowed_sources'] !== null && $payload['source_slug'] !== null
        && !in_array($payload['source_slug'], explode(',', (string) $payload['allowed_sources']), true)) {
        $errors['allowed_sources'] = 'default_not_allowed';
    }
    if ($payload['ip_allowlist'] !== null && !ip_allowlist_validate((string) $payload['ip_allowlist'])['ok']) {
        $errors['ip_allowlist'] = 'invalid_ip';
    }
    if (!$payload['can_read'] && !$payload['can_write'] && !$payload['can_delete'] && !$payload['can_users']) {
        $errors['can_read'] = 'no_scope';
    }
    return $errors;
}

/**
 * Create a key and return the plaintext token exactly once.
 *
 * @return array{id: int, token: string, key: array}
 */
function api_key_create(PDO $pdo, array $payload, ?string $actor = null): array
{
    $generated = api_token_generate();
    $stmt = $pdo->prepare(
        'INSERT INTO api_keys (label, prefix, key_hash, company, source_slug, allowed_sources, ip_allowlist,
                               can_read, can_write, can_delete, can_users, is_active, created_by)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $stmt->execute([
        $payload['label'], $generated['prefix'], $generated['hash'], $payload['company'],
        $payload['source_slug'], $payload['allowed_sources'], $payload['ip_allowlist'],
        $payload['can_read'], $payload['can_write'], $payload['can_delete'], $payload['can_users'],
        $payload['is_active'], $actor,
    ]);
    $id = (int) $pdo->lastInsertId();

    return ['id' => $id, 'token' => $generated['token'], 'key' => api_key_get($pdo, $id) ?? []];
}

function api_key_update(PDO $pdo, int $id, array $payload): bool
{
    $stmt = $pdo->prepare(
        'UPDATE api_keys SET label = ?, company = ?, source_slug = ?, allowed_sources = ?, ip_allowlist = ?,
                             can_read = ?, can_write = ?, can_delete = ?, can_users = ?, is_active = ?
         WHERE id = ?'
    );
    $stmt->execute([
        $payload['label'], $payload['company'], $payload['source_slug'], $payload['allowed_sources'],
        $payload['ip_allowlist'], $payload['can_read'], $payload['can_write'], $payload['can_delete'],
        $payload['can_users'], $payload['is_active'], $id,
    ]);
    return true;
}

/** Replace the token of an existing key; returns the new plaintext token. */
function api_key_rotate(PDO $pdo, int $id): ?string
{
    if (api_key_get($pdo, $id) === null) {
        return null;
    }
    $generated = api_token_generate();
    $stmt = $pdo->prepare('UPDATE api_keys SET prefix = ?, key_hash = ?, rotated_at = ?, is_active = 1 WHERE id = ?');
    $stmt->execute([$generated['prefix'], $generated['hash'], gmdate('Y-m-d H:i:s'), $id]);

    return $generated['token'];
}
function api_key_delete(PDO $pdo, int $id): bool
{
    $stmt = $pdo->prepare('DELETE FROM api_keys WHERE id = ?');
    $stmt->execute([$id]);
    return $stmt->rowCount() > 0;
}

// --- Request authentication --------------------------------------------

/** Extract the bearer token from headers ($_SERVER or getallheaders() style). */
function api_request_token(?array $server = null): ?string
{
    $server ??= $_SERVER;
    $header = (string) ($server['HTTP_AUTHORIZATION'] ?? $server['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
    if ($header !== '' && preg_match('/^Bearer\s+(\S+)$/i', $header, $match) === 1) {
        return $match[1];
    }
    if (!empty($server['HTTP_X_API_KEY'])) {
        return trim((string) $server['HTTP_X_API_KEY']);
    }
    if (!empty($server['Authorization'])) {
        $header = (string) $server['Authorization'];
        if (preg_match('/^Bearer\s+(\S+)$/i', $header, $match) === 1) {
            return $match[1];
        }
    }
    return null;
}

/**
 * Authenticate the current request.
 *
 * @return array{key: array, company: ?string, sources: list<string>, default_source: ?string, token: string}
 * @throws ApiError
 */
function api_authenticate(PDO $pdo, string $ip): array
{
    $token = api_request_token();
    if ($token === null || $token === '') {
        throw new ApiError('unauthorized', 'Missing API token. Send Authorization: Bearer <token>.', 401);
    }
    if (!api_token_looks_valid($token)) {
        throw new ApiError('unauthorized', 'Malformed API token.', 401);
    }

    $hash = hash('sha256', $token);
    $stmt = $pdo->prepare('SELECT * FROM api_keys WHERE key_hash = ? LIMIT 1');
    $stmt->execute([$hash]);
    $key = $stmt->fetch();

    if ($key === false || !hash_equals((string) $key['key_hash'], $hash) || (int) $key['is_active'] !== 1) {
        throw new ApiError('unauthorized', 'Invalid or disabled API token.', 401);
    }

    $allowlist = trim((string) ($key['ip_allowlist'] ?? ''));
    if ($allowlist === '') {
        $allowlist = (string) settings_get('api.ip_allowlist', '');
    }
    if (!ip_in_allowlist($ip, $allowlist)) {
        throw new ApiError('ip_not_allowed', 'This client IP is not allowed to use the API.', 403);
    }

    $sources = [];
    if (!empty($key['allowed_sources'])) {
        $sources = array_values(array_filter(array_map('trim', explode(',', (string) $key['allowed_sources']))));
    }

    // Throttled "last used" bookkeeping (at most once a minute per key).
    $last = (string) ($key['last_used_at'] ?? '');
    if ($last === '' || strtotime($last . ' UTC') < time() - 60) {
        $pdo->prepare('UPDATE api_keys SET last_used_at = ?, last_used_ip = ? WHERE id = ?')
            ->execute([gmdate('Y-m-d H:i:s'), $ip, (int) $key['id']]);
    }

    return [
        'key'            => $key,
        'company'        => trim((string) ($key['company'] ?? '')) ?: null,
        'sources'        => $sources,
        'default_source' => trim((string) ($key['source_slug'] ?? '')) ?: null,
        'token'          => $token,
    ];
}

/**
 * Ensure the key carries the required scope.
 *
 * @throws ApiError
 */
function api_require_scope(array $auth, string $scope): void
{
    $column = match ($scope) {
        'read' => 'can_read',
        'write' => 'can_write',
        'delete' => 'can_delete',
        'users' => 'can_users',
        default => null,
    };
    if ($column === null) {
        throw new ApiError('internal_error', 'Unknown scope: ' . $scope, 500);
    }
    if ((int) ($auth['key'][$column] ?? 0) !== 1) {
        throw new ApiError('forbidden', 'This API key cannot perform ' . $scope . ' operations.', 403);
    }
}

/** True when the key may write the given source slug. */
function api_key_allows_source(array $auth, string $source): bool
{
    return $auth['sources'] === [] || in_array($source, $auth['sources'], true);
}
