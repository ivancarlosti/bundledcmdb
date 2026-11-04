<?php
declare(strict_types=1);

/**
 * lib/users.php — the `users` roster (email, company, role).
 *
 * Login needs a Keycloak account *and* a row here (`auth_keycloak.php::
 * verifyUser()`); this file only manages the authorisation row. It never touches
 * Keycloak, so an account created through the API cannot sign in until the
 * Keycloak user exists — credentials stay owned by the identity provider.
 *
 * The legacy table has no surrogate key, so `email` is the identity: it is the
 * path parameter of the REST endpoints, exactly like
 * `PATCH /panel-api/v1/settings/users/{email}`.
 *
 * Every write is audited through `asset_event_record()` with `entity = user`,
 * so the Logs screen shows roster changes next to device changes.
 */

/** Roles a roster row may carry, weakest first. */
function user_roles(): array
{
    return array_keys(SESSION_ROLES);
}

/** True when the legacy roster table exists (sql/legacy.sql + schema.sql). */
function users_ready(PDO $pdo): bool
{
    return db_table_exists($pdo, 'users');
}

/**
 * Canonical e-mail: the panel compares case-insensitively, so stored values are
 * lower-cased to keep the unique index honest.
 */
function user_email_normalize(?string $email): string
{
    return strtolower(trim((string) $email));
}

/** @param array<string, mixed> $row */
function user_payload(array $row): array
{
    return [
        'email'   => (string) ($row['email'] ?? ''),
        'company' => (string) ($row['company'] ?? ''),
        'role'    => session_role_normalize($row['role'] ?? null),
    ];
}

/** @return array{email: string, company: string, role: string}|null */
function user_find(PDO $pdo, string $email, ?string $company = null): ?array
{
    $email = user_email_normalize($email);
    if ($email === '' || !users_ready($pdo)) {
        return null;
    }

    $sql = 'SELECT email, company, role FROM users WHERE email = ?';
    $bind = [$email];
    if ($company !== null) {
        $sql .= ' AND company = ?';
        $bind[] = $company;
    }
    $stmt = $pdo->prepare($sql . ' LIMIT 1');
    $stmt->execute($bind);
    $row = $stmt->fetch();

    return $row === false ? null : user_payload($row);
}

/**
 * Paged roster listing.
 *
 * @param array{company?: string, role?: string, search?: string, page?: int, per_page?: int} $filters
 * @return array{rows: list<array{email: string, company: string, role: string}>, total: int, page: int, per_page: int, pages: int}
 */
function user_list(PDO $pdo, array $filters = []): array
{
    $perPage = (int) ($filters['per_page'] ?? 50);
    $perPage = max(1, min(1000, $perPage));
    $page = max(1, (int) ($filters['page'] ?? 1));

    if (!users_ready($pdo)) {
        return ['rows' => [], 'total' => 0, 'page' => 1, 'per_page' => $perPage, 'pages' => 0];
    }

    $where = [];
    $bind = [];
    $company = trim((string) ($filters['company'] ?? ''));
    if ($company !== '') {
        $where[] = 'company = ?';
        $bind[] = $company;
    }
    $role = strtolower(trim((string) ($filters['role'] ?? '')));
    if ($role !== '') {
        $where[] = 'role = ?';
        $bind[] = $role;
    }
    $search = trim((string) ($filters['search'] ?? ''));
    if ($search !== '') {
        $where[] = '(email LIKE ? OR company LIKE ?)';
        $bind[] = '%' . $search . '%';
        $bind[] = '%' . $search . '%';
    }
    $sql = $where === [] ? '' : ' WHERE ' . implode(' AND ', $where);

    $countStmt = $pdo->prepare('SELECT COUNT(*) FROM users' . $sql);
    $countStmt->execute($bind);
    $total = (int) $countStmt->fetchColumn();

    $pages = $total === 0 ? 0 : (int) ceil($total / $perPage);
    if ($pages > 0 && $page > $pages) {
        $page = $pages;
    }

    $stmt = $pdo->prepare(
        'SELECT email, company, role FROM users' . $sql . ' ORDER BY email LIMIT ' . $perPage
        . ' OFFSET ' . (($page - 1) * $perPage)
    );
    $stmt->execute($bind);

    $rows = [];
    foreach ($stmt->fetchAll() ?: [] as $row) {
        $rows[] = user_payload($row);
    }

    return ['rows' => $rows, 'total' => $total, 'page' => $page, 'per_page' => $perPage, 'pages' => $pages];
}

/**
 * Normalise a submitted roster payload.
 *
 * @return array{data: array<string, string>, unknown: list<string>}
 */
function user_normalize_input(array $input): array
{
    $data = [];
    $unknown = [];
    foreach ($input as $key => $value) {
        $key = (string) $key;
        if (!in_array($key, ['email', 'company', 'role'], true)) {
            $unknown[] = $key;
            continue;
        }
        $data[$key] = is_scalar($value) ? trim((string) $value) : '';
    }
    if (isset($data['email'])) {
        $data['email'] = user_email_normalize($data['email']);
    }
    if (isset($data['role'])) {
        $data['role'] = strtolower($data['role']);
    }

    return ['data' => $data, 'unknown' => $unknown];
}

/**
 * Validate a roster payload. `$partial` = PATCH semantics (only sent keys).
 *
 * `$keyCompany` is the tenant of the API key: a company-bound key may never
 * grant `superadmin`, which would otherwise be a one-request escape from its own
 * tenant.
 *
 * @param list<string> $unknown
 * @return array<string, string> field => error code
 */
function user_validate(array $data, bool $partial = false, ?string $keyCompany = null, array $unknown = []): array
{
    $errors = [];
    foreach ($unknown as $key) {
        $errors[(string) $key] = 'unknown_field';
    }

    $email = user_email_normalize($data['email'] ?? '');
    if (!$partial || array_key_exists('email', $data)) {
        if ($email === '') {
            $errors['email'] = 'required';
        } elseif (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $errors['email'] = 'invalid_email';
        } elseif (mb_strlen($email) > 191) {
            $errors['email'] = 'too_long';
        }
    }

    if (array_key_exists('role', $data)) {
        $role = strtolower(trim((string) $data['role']));
        if ($role === '') {
            $errors['role'] = $partial ? 'invalid_option' : 'required';
        } elseif (!in_array($role, user_roles(), true)) {
            $errors['role'] = 'invalid_option';
        } elseif ($keyCompany !== null && $role === 'superadmin') {
            $errors['role'] = 'role_not_allowed';
        }
    } elseif (!$partial) {
        $errors['role'] = 'required';
    }

    if (array_key_exists('company', $data)) {
        if (mb_strlen((string) $data['company']) > 191) {
            $errors['company'] = 'too_long';
        }
    } elseif (!$partial && $keyCompany === null) {
        // An empty company means "sees every tenant"; a global key must say so
        // explicitly instead of creating an unbound account by omission.
        $errors['company'] = 'required';
    }

    return $errors;
}

/** Audit one roster write (`asset_events`, entity `user`). */
function user_audit(
    PDO $pdo,
    string $action,
    string $actor,
    string $email,
    array $changes = [],
    ?string $company = null,
    ?string $note = null
): void {
    asset_event_record($pdo, null, $action, $actor, $changes, $note, [
        'entity'       => 'user',
        'entity_id'    => $email,
        'entity_label' => $email,
        'company'      => $company,
    ]);
}

/** Number of rows that still hold `superadmin`. */
function user_superadmin_count(PDO $pdo): int
{
    if (!users_ready($pdo)) {
        return 0;
    }
    return (int) $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'superadmin'")->fetchColumn();
}

/**
 * Refuse to delete or demote the last superadmin.
 *
 * Without this an API key could leave the instance with nobody able to reach
 * Settings → Users, the same lockout the panel guard prevents for self-edits.
 *
 * @param array{role?: string} $row
 * @throws ApiError
 */
function user_guard_last_superadmin(PDO $pdo, array $row, ?string $nextRole = null): void
{
    if (session_role_normalize($row['role'] ?? null) !== 'superadmin') {
        return;
    }
    if ($nextRole !== null && session_role_normalize($nextRole) === 'superadmin') {
        return;
    }
    if (user_superadmin_count($pdo) > 1) {
        return;
    }
    throw new ApiError(
        'last_superadmin',
        'This is the last superadmin; the row cannot be deleted or demoted.',
        409,
        ['role' => 'last_superadmin']
    );
}

/**
 * Insert one roster row.
 *
 * @param array{email: string, company?: string, role: string} $data
 * @return array{email: string, company: string, role: string}
 * @throws ApiError
 */
function user_create(PDO $pdo, array $data, string $actor): array
{
    $email = user_email_normalize($data['email'] ?? '');
    $company = trim((string) ($data['company'] ?? ''));
    $role = session_role_normalize($data['role'] ?? null);

    $stmt = $pdo->prepare('INSERT INTO users (email, company, role) VALUES (?, ?, ?)');
    $stmt->execute([$email, $company, $role]);

    $row = user_find($pdo, $email) ?? ['email' => $email, 'company' => $company, 'role' => $role];
    user_audit($pdo, 'create', $actor, $email, [
        'email'   => [null, $email],
        'company' => [null, $company],
        'role'    => [null, $role],
    ], $company === '' ? null : $company);

    return $row;
}

/**
 * Update the fields present in `$data` and return the fresh row.
 *
 * @return array{email: string, company: string, role: string}
 * @throws ApiError
 */
function user_update(PDO $pdo, string $email, array $data, string $actor): array
{
    $existing = user_find($pdo, $email);
    if ($existing === null) {
        throw new ApiError('not_found', 'No user with e-mail ' . $email . '.', 404);
    }

    $after = $existing;
    foreach (['company', 'role'] as $field) {
        if (array_key_exists($field, $data)) {
            $after[$field] = $field === 'role'
                ? session_role_normalize($data[$field])
                : trim((string) $data[$field]);
        }
    }

    $changes = asset_event_diff(
        ['company' => $existing['company'], 'role' => $existing['role']],
        ['company' => $after['company'], 'role' => $after['role']]
    );
    if ($changes === []) {
        return $existing;
    }

    if ($after['role'] !== $existing['role']) {
        user_guard_last_superadmin($pdo, $existing, $after['role']);
    }

    $pdo->prepare('UPDATE users SET company = ?, role = ? WHERE email = ?')
        ->execute([$after['company'], $after['role'], $existing['email']]);

    user_audit(
        $pdo,
        $after['role'] !== $existing['role'] ? 'role_change' : 'update',
        $actor,
        $existing['email'],
        $changes,
        $after['company'] === '' ? null : $after['company']
    );

    return user_find($pdo, $existing['email']) ?? $after;
}

/**
 * Create or update one row (the primitive an IdP/HR sync needs).
 *
 * @return array{user: array{email: string, company: string, role: string}, created: bool}
 * @throws ApiError
 */
function user_upsert(PDO $pdo, array $data, string $actor): array
{
    $email = user_email_normalize($data['email'] ?? '');
    $existing = user_find($pdo, $email);
    if ($existing === null) {
        return ['user' => user_create($pdo, $data, $actor), 'created' => true];
    }

    return ['user' => user_update($pdo, $existing['email'], $data, $actor), 'created' => false];
}

/**
 * Remove one roster row. The Keycloak account is left untouched, so the same
 * address can be re-admitted later with `POST /v1/users`.
 *
 * @return array{email: string, role: string, deleted: bool}
 * @throws ApiError
 */
function user_delete(PDO $pdo, string $email, string $actor): array
{
    $existing = user_find($pdo, $email);
    if ($existing === null) {
        throw new ApiError('not_found', 'No user with e-mail ' . $email . '.', 404);
    }

    user_guard_last_superadmin($pdo, $existing);
    $pdo->prepare('DELETE FROM users WHERE email = ?')->execute([$existing['email']]);

    user_audit($pdo, 'delete', $actor, $existing['email'], [], $existing['company'] === '' ? null : $existing['company']);

    return ['email' => $existing['email'], 'role' => $existing['role'], 'deleted' => true];
}

