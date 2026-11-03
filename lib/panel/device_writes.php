<?php
declare(strict_types=1);

/**
 * lib/panel/device_writes.php — device mutations for the panel API.
 *
 *   POST   /panel-api/v1/devices                     create            (manager+)
 *   POST   /panel-api/v1/devices/bulk                bulk status/byod/delete/restore
 *                                                    (manager+ / admin+ / superadmin)
 *   PATCH  /panel-api/v1/devices/{id}                partial update    (manager+)
 *   PUT    /panel-api/v1/devices/{id}                full replace      (manager+)
 *   DELETE /panel-api/v1/devices/{id}                soft/hard delete  (admin+, hard = superadmin)
 *   POST   /panel-api/v1/devices/{id}/restore        undo soft delete  (admin+)
 *   POST   /panel-api/v1/devices/{id}/files          upload            (manager+)
 *   DELETE /panel-api/v1/devices/{id}/files/{fileId}                   (admin+)
 *
 * Every write goes through the same model helpers as `public/api.php` and the
 * legacy pages (`asset_validate()`, `asset_create/update/soft_delete/restore/
 * hard_delete()`, `custom_values_save()`), so the two APIs can never disagree
 * about what a valid device is.
 *
 * Optimistic locking: a body may carry `expected_updated_at` (the ISO timestamp
 * the client rendered). When it is present and no longer matches, the request
 * fails with `409 stale_record` instead of overwriting a newer edit.
 */

/** Uploads accepted through the panel API (nginx/PHP limits are lower in practice). */
const PANEL_MAX_UPLOAD_BYTES = 26214400; // 25 MiB

/**
 * Schema columns the given role may write.
 *
 * @return list<string>
 */
function panel_device_writable_columns(string $role): array
{
    $writable = array_merge(
        asset_create_columns($role),
        asset_grid_editable_columns($role),
        // Provenance is writable by managers+ (see `api.php`), never by `user`.
        ['source', 'source_ref']
    );
    if (session_role_rank($role) >= session_role_rank('admin')) {
        $writable[] = 'UUID';
    }

    $known = array_keys(asset_column_defs());
    return array_values(array_unique(array_filter(
        $writable,
        static fn (string $column): bool => in_array($column, $known, true)
    )));
}

/**
 * Wire key (snake_case) => schema column, for every column a client may write.
 *
 * Derived from the payload map published by `/meta`, so the SPA never has to
 * translate between the two spellings.
 *
 * @return array<string, string>
 */
function panel_device_input_map(string $role): array
{
    $writable = panel_device_writable_columns($role);
    $map = [];
    foreach (panel_device_payload_keys() as $column => $key) {
        if (in_array($column, $writable, true)) {
            $map[$key] = $column;
        }
    }
    return $map;
}

/**
 * Translate schema column error keys to the wire keys the SPA renders.
 *
 * @param array<string, string> $errors
 * @return array<string, string>
 */
function panel_device_error_keys(array $errors): array
{
    $out = [];
    foreach ($errors as $field => $code) {
        $field = (string) $field;
        if (str_starts_with($field, 'custom.')) {
            $out[$field] = (string) $code;
            continue;
        }
        $out[panel_payload_key($field)] = (string) $code;
    }
    return $out;
}


/**
 * Map a JSON body onto schema columns, rejecting unknown and forbidden keys.
 *
 * @param array<string, mixed> $body
 * @param array{email: string, company: string, role: string} $user
 * @return array{data: array<string, mixed>, custom: array<string, mixed>,
 *               defs: array<string, array>, errors: array<string, string>}
 */
function panel_device_input(PDO $pdo, array $user, array $body): array
{
    $map = panel_device_input_map($user['role']);
    $editableFields = [];
    foreach (field_defs($pdo) as $def) {
        if (field_editable_by($def, $user['role'])) {
            $editableFields[(string) $def['field_key']] = $def;
        }
    }

    $errors = [];
    $data = [];
    $reserved = [
        'id', 'files_count', 'files', 'custom', 'expected_updated_at', 'company',
        'created_at', 'created_by', 'updated_at', 'updated_by', 'deleted_at',
    ];

    foreach ($body as $key => $value) {
        $key = (string) $key;
        if (in_array($key, $reserved, true)) {
            continue;
        }
        if (!isset($map[$key])) {
            $errors[$key] = 'unknown_field';
            continue;
        }
        $data[$map[$key]] = $value;
    }

    $custom = [];
    $rawCustom = $body['custom'] ?? [];
    if (is_array($rawCustom)) {
        $knownFields = field_defs_by_key($pdo);
        foreach ($rawCustom as $key => $value) {
            $key = (string) $key;
            if (!isset($knownFields[$key])) {
                $errors['custom.' . $key] = 'unknown_field';
                continue;
            }
            if (!isset($editableFields[$key])) {
                $errors['custom.' . $key] = 'forbidden';
                continue;
            }
            $custom[$key] = $value;
        }
    } elseif ($rawCustom !== null && $rawCustom !== []) {
        $errors['custom'] = 'invalid_object';
    }

    return ['data' => $data, 'custom' => $custom, 'defs' => $editableFields, 'errors' => $errors];
}

/**
 * Company a write targets.
 *
 * A company-bound account always writes inside its own company and can never
 * move a row to another one; an unrestricted account (company `''`) must name
 * the company when creating.
 *
 * @param array{email: string, company: string, role: string} $user
 * @param array<string, mixed> $body
 * @param array<string, mixed>|null $existing
 *
 * @throws ApiError('validation_failed', …, 422)
 */
function panel_device_write_company(array $user, array $body, ?array $existing): string
{
    $requested = trim((string) ($body['company'] ?? ''));

    if ($existing !== null) {
        $current = trim((string) ($existing['company'] ?? ''));
        if ($requested !== '' && $requested !== $current) {
            throw new ApiError('validation_failed', 'A device cannot be moved to another company.', 422, [
                'company' => 'immutable',
            ]);
        }
        return $current;
    }

    if ($user['company'] !== '') {
        if ($requested !== '' && $requested !== $user['company']) {
            throw new ApiError('validation_failed', 'You can only create devices in your own company.', 422, [
                'company' => 'forbidden',
            ]);
        }
        return $user['company'];
    }

    if ($requested === '') {
        throw new ApiError('validation_failed', 'A company is required for this account.', 422, [
            'company' => 'required',
        ]);
    }
    return $requested;
}

/**
 * Enforce optimistic locking.
 *
 * @param array<string, mixed> $row
 * @param mixed $expected value of `expected_updated_at` in the request body
 *
 * @throws ApiError('stale_record', …, 409) when the row moved on
 */
function panel_device_check_version(array $row, mixed $expected): void
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
            'This device was modified by someone else. Reload it and try again.',
            409,
            ['expected_updated_at' => 'stale', 'current_updated_at' => (string) $current]
        );
    }
}

/**
 * Load a device for a write, honouring the company scope.
 *
 * @param array{email: string, company: string, role: string} $user
 * @return array<string, mixed>
 *
 * @throws ApiError('not_found', …, 404)
 */
function panel_device_for_write(PDO $pdo, array $user, int $id): array
{
    $row = asset_get($pdo, $id, $user['company'] !== '' ? $user['company'] : null, true);
    if ($row === null) {
        throw new ApiError('not_found', 'Device not found.', 404);
    }
    return $row;
}

/**
 * Full representation after a write (row + custom values + files).
 *
 * @param array<string, mixed> $row
 */
function panel_device_write_payload(PDO $pdo, array $row): array
{
    $id = (int) $row['Id'];
    $custom = custom_values_for_asset($pdo, $id, field_defs($pdo));
    $files = array_map('panel_file_payload', asset_files($pdo, $id));

    $payload = panel_device_payload($row, $custom, count($files));
    $payload['files'] = $files;

    return $payload;
}

/**
 * Validate `source` / `source_ref` and split them out of the column payload.
 *
 * @param array<string, mixed> $data schema columns
 * @return array{source: string, source_ref: ?string, data: array<string, mixed>}
 *
 * @throws ApiError('validation_failed', …, 422)
 */
function panel_device_provenance(PDO $pdo, array $data): array
{
    $source = trim((string) ($data['source'] ?? ''));
    $sourceRef = trim((string) ($data['source_ref'] ?? ''));
    unset($data['source'], $data['source_ref']);

    if ($source !== '' && !source_exists($pdo, $source)) {
        throw new ApiError('validation_failed', 'Unknown source.', 422, ['source' => 'unknown_source']);
    }

    return [
        'source'     => $source,
        'source_ref' => $sourceRef === '' ? null : $sourceRef,
        'data'       => $data,
    ];
}

/** POST /devices */
function panel_devices_create(array $params = []): array
{
    $user = session_require_role(['manager', 'admin', 'superadmin']);
    session_csrf_require();
    $pdo = db();

    $body = request_json_body();
    $input = panel_device_input($pdo, $user, $body);
    if ($input['errors'] !== []) {
        throw new ApiError('validation_failed', 'Payload validation failed.', 422, $input['errors']);
    }

    $company = panel_device_write_company($user, $body, null);
    $provenance = panel_device_provenance($pdo, $input['data']);
    $data = asset_normalize_input($provenance['data']);
    $data['company'] = $company;

    $errors = asset_validate($data, false);
    if ($errors !== []) {
        throw new ApiError('validation_failed', 'Payload validation failed.', 422, panel_device_error_keys($errors));
    }

    $duplicate = asset_find_duplicate($pdo, $data, null, $company);
    if ($duplicate !== null) {
        throw new ApiError('conflict', 'An asset with the same ' . $duplicate['field'] . ' already exists.', 409, [
            panel_payload_key($duplicate['field']) => 'duplicate',
            'existing_device_id'                   => (string) $duplicate['id'],
        ]);
    }

    $actor = $user['email'];
    $pdo->beginTransaction();
    try {
        $id = asset_create($pdo, $data, $actor, $provenance['source'], $provenance['source_ref']);
        $customErrors = custom_values_save($pdo, $id, $input['custom'], $input['defs'], $actor, false);
        if ($customErrors !== []) {
            $pdo->rollBack();
            throw new ApiError(
                'validation_failed',
                'Custom field validation failed.',
                422,
                panel_device_error_keys($customErrors)
            );
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    asset_event_record($pdo, $id, 'create', $actor, asset_event_diff([], $data), null);

    $row = asset_get($pdo, $id, $company, true);
    if ($row === null) {
        throw new ApiError('server_error', 'The device could not be reloaded after it was created.', 500);
    }

    return [
        'data'   => panel_device_write_payload($pdo, $row),
        'status' => 201,
        'meta'   => ['created' => true],
    ];
}


/**
 * Shared body of PATCH (partial) and PUT (replace).
 *
 * @param array{email: string, company: string, role: string} $user
 * @param array<string, mixed> $row
 * @param array<string, mixed> $body
 */
function panel_devices_save(PDO $pdo, array $user, array $row, array $body, bool $replace): array
{
    $id = (int) $row['Id'];
    if (!empty($row['deleted_at'])) {
        throw new ApiError('conflict', 'This device is deleted. Restore it before editing.', 409, [
            'deleted_at' => 'trashed',
        ]);
    }

    panel_device_check_version($row, $body['expected_updated_at'] ?? null);
    $company = panel_device_write_company($user, $body, $row);

    $input = panel_device_input($pdo, $user, $body);
    if ($input['errors'] !== []) {
        throw new ApiError('validation_failed', 'Payload validation failed.', 422, $input['errors']);
    }

    $provenance = panel_device_provenance($pdo, $input['data']);
    $data = asset_normalize_input($provenance['data']);

    if ($replace) {
        // A real replace: every writable column is written, omitted ones are
        // cleared. `source` keeps its value unless the body sends one.
        $complete = [];
        foreach (panel_device_writable_columns($user['role']) as $column) {
            if (in_array($column, ['source', 'source_ref'], true)) {
                continue;
            }
            $complete[$column] = $data[$column] ?? null;
        }
        $data = $complete;
    }

    $errors = asset_validate($data, !$replace);
    if ($errors !== []) {
        throw new ApiError('validation_failed', 'Payload validation failed.', 422, panel_device_error_keys($errors));
    }

    $duplicate = asset_find_duplicate($pdo, $data, $id, $company);
    if ($duplicate !== null) {
        throw new ApiError('conflict', 'An asset with the same ' . $duplicate['field'] . ' already exists.', 409, [
            panel_payload_key($duplicate['field']) => 'duplicate',
            'existing_device_id'                   => (string) $duplicate['id'],
        ]);
    }

    $changes = $data;
    if ($provenance['source'] !== '') {
        $changes['source'] = $provenance['source'];
    }
    if ($provenance['source_ref'] !== null) {
        $changes['source_ref'] = $provenance['source_ref'];
    }

    if ($changes === [] && $input['custom'] === []) {
        return panel_device_write_payload($pdo, $row);
    }

    $actor = $user['email'];
    $pdo->beginTransaction();
    try {
        asset_update($pdo, $id, $changes, $actor, $company);
        $customErrors = custom_values_save($pdo, $id, $input['custom'], $input['defs'], $actor, $replace);
        if ($customErrors !== []) {
            $pdo->rollBack();
            throw new ApiError(
                'validation_failed',
                'Custom field validation failed.',
                422,
                panel_device_error_keys($customErrors)
            );
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    $fresh = asset_get($pdo, $id, $company, true);
    if ($fresh === null) {
        throw new ApiError('not_found', 'Device not found.', 404);
    }

    // Only what actually moved, so an idempotent save leaves no audit noise.
    asset_event_record($pdo, $id, 'update', $actor, asset_event_diff($row, $fresh), null);

    return panel_device_write_payload($pdo, $fresh);
}

/** PATCH /devices/{id} */
function panel_devices_update(array $params = []): array
{
    $user = session_require_role(['manager', 'admin', 'superadmin']);
    session_csrf_require();
    $pdo = db();

    $row = panel_device_for_write($pdo, $user, panel_device_id($params));

    return ['data' => panel_devices_save($pdo, $user, $row, request_json_body(), false)];
}

/** PUT /devices/{id} — full replace (parity with the Bearer API). */
function panel_devices_replace(array $params = []): array
{
    $user = session_require_role(['manager', 'admin', 'superadmin']);
    session_csrf_require();
    $pdo = db();

    $row = panel_device_for_write($pdo, $user, panel_device_id($params));

    return ['data' => panel_devices_save($pdo, $user, $row, request_json_body(), true)];
}


/** DELETE /devices/{id} */
function panel_devices_delete(array $params = []): array
{
    $user = session_require_role(['admin', 'superadmin']);
    session_csrf_require();
    $pdo = db();

    $hard = filter_var($_GET['hard'] ?? false, FILTER_VALIDATE_BOOLEAN);
    if ($hard && session_role_rank($user['role']) < session_role_rank('superadmin')) {
        throw new ApiError('forbidden', 'Only a superadmin can delete a device permanently.', 403);
    }

    $id = panel_device_id($params);
    $row = panel_device_for_write($pdo, $user, $id);
    $company = $user['company'] !== '' ? $user['company'] : null;

    if (!$hard && !empty($row['deleted_at'])) {
        throw new ApiError('conflict', 'This device is already deleted.', 409, ['deleted_at' => 'trashed']);
    }

    if ($hard) {
        asset_hard_delete($pdo, $id, $company);
        asset_event_record($pdo, $id, 'purge', $user['email']);
    } else {
        asset_soft_delete($pdo, $id, $user['email'], $company);
        asset_event_record($pdo, $id, 'delete', $user['email']);
    }

    return ['data' => ['id' => $id, 'deleted' => true, 'hard' => $hard]];
}

/** POST /devices/{id}/restore — idempotent: restoring a live row is a no-op. */
function panel_devices_restore(array $params = []): array
{
    $user = session_require_role(['admin', 'superadmin']);
    session_csrf_require();
    $pdo = db();

    $id = panel_device_id($params);
    $row = panel_device_for_write($pdo, $user, $id);
    $company = $user['company'] !== '' ? $user['company'] : null;

    $restored = false;
    if (!empty($row['deleted_at'])) {
        $restored = asset_restore($pdo, $id, $user['email'], $company);
        if ($restored) {
            asset_event_record($pdo, $id, 'restore', $user['email']);
        }
    }

    $fresh = asset_get($pdo, $id, $company, true);
    if ($fresh === null) {
        throw new ApiError('not_found', 'Device not found.', 404);
    }

    return ['data' => panel_device_write_payload($pdo, $fresh), 'meta' => ['restored' => $restored]];
}


/** Rows one bulk request may touch (the SPA never sends more than a page). */
const PANEL_BULK_MAX_IDS = 200;

/**
 * Validate the `ids` list of a bulk request.
 *
 * @return list<int> unique ids, in the order they were sent
 *
 * @throws ApiError('validation_failed', …, 422)
 */
function panel_bulk_ids(mixed $raw): array
{
    if (!is_array($raw) || $raw === []) {
        throw new ApiError('validation_failed', 'Send a non-empty ids array.', 422, ['ids' => 'required']);
    }

    $ids = [];
    foreach ($raw as $value) {
        $valid = is_int($value) || (is_string($value) && ctype_digit($value));
        if (!$valid || (int) $value <= 0) {
            throw new ApiError('validation_failed', 'ids must be a list of device ids.', 422, ['ids' => 'invalid']);
        }
        // Keyed assignment dedupes while keeping the original order.
        $ids[(int) $value] = (int) $value;
    }

    $ids = array_values($ids);
    if (count($ids) > PANEL_BULK_MAX_IDS) {
        throw new ApiError(
            'validation_failed',
            'A bulk request may touch at most ' . PANEL_BULK_MAX_IDS . ' devices.',
            422,
            ['ids' => 'too_many']
        );
    }

    return $ids;
}

/**
 * POST /devices/bulk — apply one operation to several devices.
 *
 *   {"ids": [1, 2], "op": "status", "value": "In Use"}   manager+
 *   {"ids": [1, 2], "op": "byod",   "value": true}       manager+
 *   {"ids": [1, 2], "op": "delete", "hard": false}       admin+
 *   {"ids": [1, 2], "op": "restore"}                     admin+
 *
 * Each row goes through the same single-row helpers in its own transaction, so
 * a stale, deleted or out-of-scope row cannot roll back the others. The answer
 * says exactly what changed and why the rest did not (`skipped`), which the grid
 * shows as "N updated, M skipped" instead of failing a 25-row action silently.
 *
 * The shared value is validated once: every row would fail identically and 200
 * copies of one error is not a useful response.
 */
function panel_devices_bulk(array $params = []): array
{
    $user = session_require_role(['manager', 'admin', 'superadmin']);
    session_csrf_require();
    $pdo = db();

    $body = request_json_body();
    $op = trim((string) ($body['op'] ?? ''));
    if (!in_array($op, ['status', 'byod', 'delete', 'restore'], true)) {
        throw new ApiError('validation_failed', 'Unknown bulk operation.', 422, ['op' => 'invalid']);
    }

    $rank = session_role_rank($user['role']);
    if ($op === 'delete' || $op === 'restore') {
        if ($rank < session_role_rank('admin')) {
            throw new ApiError('forbidden', 'Only an admin can delete or restore devices.', 403);
        }
    }

    $hard = $op === 'delete' && filter_var($body['hard'] ?? false, FILTER_VALIDATE_BOOLEAN);
    if ($hard && $rank < session_role_rank('superadmin')) {
        throw new ApiError('forbidden', 'Only a superadmin can delete devices permanently.', 403);
    }

    $ids = panel_bulk_ids($body['ids'] ?? null);

    $value = null;
    if ($op === 'status') {
        $value = (string) ($body['value'] ?? '');
        if (!in_array($value, asset_status_values(), true)) {
            throw new ApiError('validation_failed', 'Unknown status.', 422, ['value' => 'invalid_status']);
        }
    } elseif ($op === 'byod') {
        $raw = $body['value'] ?? null;
        if (!is_bool($raw) && !in_array($raw, [0, 1, '0', '1', 'true', 'false'], true)) {
            throw new ApiError('validation_failed', 'BYOD must be true or false.', 422, ['value' => 'invalid_boolean']);
        }
        $value = asset_bool_parse($raw);
    }

    $company = $user['company'] !== '' ? $user['company'] : null;
    $changed = [];
    $skipped = [];

    foreach ($ids as $id) {
        try {
            $row = panel_device_for_write($pdo, $user, $id);

            if ($op === 'delete') {
                if (!empty($row['deleted_at'])) {
                    $skipped[] = ['id' => $id, 'code' => 'already_deleted', 'message' => 'This device is already deleted.'];
                    continue;
                }
                if ($hard) {
                    asset_hard_delete($pdo, $id, $company);
                } else {
                    asset_soft_delete($pdo, $id, $user['email'], $company);
                }
            } elseif ($op === 'restore') {
                if (empty($row['deleted_at'])) {
                    $skipped[] = ['id' => $id, 'code' => 'unchanged', 'message' => 'This device is not deleted.'];
                    continue;
                }
                asset_restore($pdo, $id, $user['email'], $company);
            } else {
                // Reuses PATCH: same validation, same company scope, same
                // "deleted rows are read-only" rule.
                panel_devices_save($pdo, $user, $row, [$op => $value], false);
            }

            $changed[] = $id;
        } catch (ApiError $e) {
            $skipped[] = [
                'id'      => $id,
                'code'    => $e->errorCode,
                'message' => $e->getMessage(),
                'errors'  => $e->errors,
            ];
        }
    }

    return [
        'data' => ['op' => $op, 'updated' => $changed, 'skipped' => $skipped],
        'meta' => ['changed' => count($changed), 'skipped' => count($skipped)],
    ];
}


/**
 * POST /devices/{id}/files — multipart upload, server-side to S3.
 *
 * The browser uploads to the panel (the session cookie and the CSRF token are
 * already there) and the server puts the object, so AWS credentials and signing
 * logic never reach JavaScript. `S3Client` only signs GET URLs, so a
 * direct-to-bucket upload is intentionally not offered.
 */
function panel_devices_files_store(array $params = []): array
{
    $user = session_require_role(['manager', 'admin', 'superadmin']);
    session_csrf_require();

    $id = panel_device_id($params);
    $pdo = db();
    panel_device_for_write($pdo, $user, $id);

    if (!panel_storage_configured()) {
        throw new ApiError('storage_not_configured', 'Object storage is not configured.', 503);
    }

    $file = $_FILES['file'] ?? $_FILES['new_file'] ?? null;
    if (!is_array($file) || (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        throw new ApiError('validation_failed', 'A file is required.', 422, ['file' => 'required']);
    }

    $size = (int) ($file['size'] ?? 0);
    if ($size <= 0) {
        throw new ApiError('validation_failed', 'The uploaded file is empty.', 422, ['file' => 'empty']);
    }
    if ($size > PANEL_MAX_UPLOAD_BYTES) {
        throw new ApiError('validation_failed', 'The file is too large.', 422, ['file' => 'too_large']);
    }

    $name = trim((string) ($file['name'] ?? ''));
    if ($name === '') {
        throw new ApiError('validation_failed', 'The file has no name.', 422, ['file' => 'invalid_name']);
    }

    $mime = trim((string) ($file['type'] ?? ''));
    $safeName = (string) preg_replace('/[^A-Za-z0-9._-]+/', '_', $name);

    require_once __DIR__ . '/../../s3_client.php';
    $s3 = new S3Client();
    $key = 'assets/' . $id . '/' . uniqid('', true) . '_' . ($safeName === '' ? 'file' : $safeName);
    $result = $s3->uploadFile(
        (string) $file['tmp_name'],
        $key,
        $mime === '' ? 'application/octet-stream' : $mime
    );
    if (empty($result['success'])) {
        throw new ApiError('storage_error', (string) ($result['message'] ?? 'The file could not be stored.'), 502);
    }

    $pdo->prepare(
        'INSERT INTO device_files (device_id, device_table, file_path, file_name, mime_type, size)
              VALUES (?, ?, ?, ?, ?, ?)'
    )->execute([$id, 'assets', $s3->objectKey($key), $name, $mime === '' ? null : $mime, $size]);

    $fileId = (int) $pdo->lastInsertId();
    asset_event_record($pdo, $id, 'file_add', $user['email'], ['file' => [null, $name]]);
    $stmt = $pdo->prepare("SELECT * FROM device_files WHERE id = ? AND device_table = 'assets'");
    $stmt->execute([$fileId]);
    $row = $stmt->fetch();

    return ['data' => panel_file_payload(is_array($row) ? $row : []), 'status' => 201, 'meta' => ['created' => true]];
}

/** DELETE /devices/{id}/files/{fileId} */
function panel_devices_files_delete(array $params = []): array
{
    $user = session_require_role(['admin', 'superadmin']);
    session_csrf_require();
    $pdo = db();

    $id = panel_device_id($params);
    panel_device_for_write($pdo, $user, $id);

    $rawFileId = (string) ($params['fileId'] ?? '');
    if (preg_match('/^\d+$/', $rawFileId) !== 1) {
        throw new ApiError('not_found', 'File not found.', 404);
    }
    $fileId = (int) $rawFileId;

    $stmt = $pdo->prepare("SELECT * FROM device_files WHERE id = ? AND device_id = ? AND device_table = 'assets'");
    $stmt->execute([$fileId, $id]);
    $file = $stmt->fetch();
    if ($file === false) {
        throw new ApiError('not_found', 'File not found.', 404);
    }

    try {
        require_once __DIR__ . '/../../s3_client.php';
        $s3 = new S3Client();
        if ($s3->isConfigured()) {
            $s3->deleteFile((string) $file['file_path']);
        }
    } catch (Throwable $e) {
        // The row still has to go: a missing object must not make the panel
        // impossible to clean up.
        error_log('[panel file delete] ' . $e->getMessage());
    }

    $pdo->prepare('DELETE FROM device_files WHERE id = ?')->execute([$fileId]);
    asset_event_record($pdo, $id, 'file_remove', $user['email'], ['file' => [(string) $file['file_name'], null]]);

    return ['data' => ['id' => $fileId, 'deleted' => true]];
}

