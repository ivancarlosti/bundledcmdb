<?php
declare(strict_types=1);

/**
 * lib/api_users.php — HTTP layer of `GET|POST|PATCH|DELETE /v1/users`.
 *
 * Kept out of the `public/api.php` front controller so the write path can be
 * driven from `bin/test-api-users.php`: a front controller answers with
 * `json_respond()` (which exits), so a request-level test could only ever make
 * one call per process.
 *
 * The roster itself lives in `lib/users.php`; this file only turns a Bearer key
 * into a tenant, validates the payload and maps model results to responses.
 */

/** Actor recorded in the audit trail for a Bearer key. */
function api_actor(array $auth): string
{
    return 'api:' . (string) ($auth['key']['label'] ?? 'key');
}

/**
 * Company of a roster write.
 *
 * A company-bound key always writes its own tenant (asking for another one is a
 * 403, never a silent override); a global key writes whatever it sent, and
 * `user_validate()` already refused an omitted company.
 *
 * @throws ApiError
 */
function api_user_company(array $auth, array $data): string
{
    $requested = trim((string) ($data['company'] ?? ''));
    if ($auth['company'] !== null) {
        if ($requested !== '' && $requested !== $auth['company']) {
            throw new ApiError('forbidden', 'This API key is restricted to company "' . $auth['company'] . '".', 403);
        }
        return (string) $auth['company'];
    }
    return $requested;
}

/**
 * POST /v1/users — one row, 201. An e-mail that already has a row is a 409 so a
 * retried sync never silently rewrites a role.
 *
 * @throws ApiError
 */
function api_create_user(PDO $pdo, array $auth, array $payload): array
{
    $normalized = user_normalize_input($payload);
    $data = $normalized['data'];
    $errors = user_validate($data, false, $auth['company'], $normalized['unknown']);
    if ($errors !== []) {
        throw new ApiError('validation_failed', 'The user could not be created.', 422, $errors);
    }
    if (user_find($pdo, $data['email']) !== null) {
        throw new ApiError('conflict', 'A user with this e-mail already exists.', 409, ['email' => 'duplicate']);
    }
    $data['company'] = api_user_company($auth, $data);

    return user_create($pdo, $data, api_actor($auth));
}

/**
 * POST|PUT /v1/users/upsert — create or update in one call (the primitive an
 * IdP/HR sync needs). `created` says which happened.
 *
 * @return array{user: array, created: bool}
 * @throws ApiError
 */
function api_upsert_user(PDO $pdo, array $auth, array $payload): array
{
    $normalized = user_normalize_input($payload);
    $data = $normalized['data'];
    $errors = user_validate($data, false, $auth['company'], $normalized['unknown']);
    if ($errors !== []) {
        throw new ApiError('validation_failed', 'The user could not be saved.', 422, $errors);
    }
    $data['company'] = api_user_company($auth, $data);
    $existing = user_find($pdo, $data['email']);
    if ($existing !== null && $auth['company'] !== null && $existing['company'] !== $auth['company']) {
        // The e-mail is unique across tenants, so an upsert from a bound key that
        // resolves another tenant's row would move it into its own company.
        throw new ApiError('forbidden', 'This API key is restricted to company "' . $auth['company'] . '".', 403);
    }

    return user_upsert($pdo, $data, api_actor($auth));
}

/**
 * PATCH /v1/users/{email} — `company` and/or `role`.
 *
 * The e-mail is the identity, so it is immutable: sending a different one is a
 * 422 instead of a rename the table cannot express.
 *
 * @throws ApiError
 */
function api_patch_user(PDO $pdo, array $auth, string $email, array $payload): array
{
    $existing = user_find($pdo, $email, $auth['company']);
    if ($existing === null) {
        throw new ApiError('not_found', 'No user with e-mail ' . $email . '.', 404);
    }

    $normalized = user_normalize_input($payload);
    $data = $normalized['data'];
    $errors = user_validate($data, true, $auth['company'], $normalized['unknown']);
    if (array_key_exists('email', $data)
        && user_email_normalize($data['email']) !== user_email_normalize($existing['email'])) {
        $errors['email'] = 'immutable';
    }
    unset($data['email']);
    if ($errors !== []) {
        throw new ApiError('validation_failed', 'The user could not be saved.', 422, $errors);
    }
    if (array_key_exists('company', $data)) {
        $data['company'] = api_user_company($auth, $data);
    }

    return user_update($pdo, $existing['email'], $data, api_actor($auth));
}

/**
 * DELETE /v1/users/{email}.
 *
 * @throws ApiError
 */
function api_delete_user(PDO $pdo, array $auth, string $email): array
{
    $existing = user_find($pdo, $email, $auth['company']);
    if ($existing === null) {
        throw new ApiError('not_found', 'No user with e-mail ' . $email . '.', 404);
    }

    return user_delete($pdo, $existing['email'], api_actor($auth));
}

/**
 * Paged roster listing; a company-bound key only ever sees its own tenant.
 *
 * @throws ApiError
 */
function api_list_users(PDO $pdo, array $auth): array
{
    $company = $auth['company'] ?? trim((string) ($_GET['company'] ?? ''));
    $maxPageSize = max(1, settings_int('api.max_page_size'));
    $perPage = max(1, min($maxPageSize, (int) ($_GET['per_page'] ?? 50)));

    $result = user_list($pdo, [
        'company'  => $company,
        'role'     => (string) ($_GET['role'] ?? ''),
        'search'   => (string) ($_GET['search'] ?? ''),
        'page'     => (int) ($_GET['page'] ?? 1),
        'per_page' => $perPage,
    ]);

    return [
        'users'    => $result['rows'],
        'total'    => $result['total'],
        'page'     => $result['page'],
        'per_page' => $result['per_page'],
        'pages'    => $result['pages'],
    ];
}
