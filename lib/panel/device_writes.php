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

/**
 * Uploads accepted through the panel API, for both drivers: the local one copies
 * a temp file, the S3 one signs a PUT of the same size. The image raises nginx
 * (`client_max_body_size 101m`) and PHP (`post_max_size = 101M`) above this so
 * the multipart envelope always fits.
 */
const PANEL_MAX_UPLOAD_BYTES = 104857600; // 100 MiB

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
    // UUID is an identity aid, not a delegated field.
    if (session_role_rank($role) >= session_role_rank('admin')) {
        $writable[] = 'UUID';
    }

    $known = array_keys(asset_column_defs());
    return array_values(array_unique(array_filter(
        $writable,
        static fn (string $column): bool => in_array($column, $known, true)
            && ($column === 'UUID' || permission_field_editable($role, $column))
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
        if (permission_field_editable($user['role'], 'custom:' . (string) $def['field_key'], $pdo)) {
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
    $user = session_require_capability('devices.create');
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

    asset_event_record($pdo, $id, 'create', $actor, asset_event_diff([], $data), null, asset_event_device_meta($data, $id));

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
    asset_event_record($pdo, $id, 'update', $actor, asset_event_diff($row, $fresh), null, asset_event_device_meta($fresh, $id));

    return panel_device_write_payload($pdo, $fresh);
}

/** PATCH /devices/{id} */
function panel_devices_update(array $params = []): array
{
    $user = session_require_capability('devices.update');
    session_csrf_require();
    $pdo = db();

    $row = panel_device_for_write($pdo, $user, panel_device_id($params));

    return ['data' => panel_devices_save($pdo, $user, $row, request_json_body(), false)];
}

/** PUT /devices/{id} — full replace (parity with the Bearer API). */
function panel_devices_replace(array $params = []): array
{
    $user = session_require_capability('devices.update');
    session_csrf_require();
    $pdo = db();

    $row = panel_device_for_write($pdo, $user, panel_device_id($params));

    return ['data' => panel_devices_save($pdo, $user, $row, request_json_body(), true)];
}


/**
 * Validate the optional "why was this deleted" inputs.
 *
 * Both are optional — the Bearer API and older SPA builds delete without them —
 * but a value that is sent has to be usable: an unknown reason or a dangling
 * duplicate reference would be recorded as a fact that nobody can act on.
 *
 * @return array{reason: ?string, duplicate_of: ?int}
 *
 * @throws ApiError('validation_failed', …, 422)
 */
function panel_delete_reason_input(mixed $reasonRaw, mixed $duplicateRaw): array
{
    $reason = null;
    if (is_string($reasonRaw) && trim($reasonRaw) !== '') {
        $reason = trim($reasonRaw);
        if (!in_array($reason, asset_deletion_reasons(), true)) {
            throw new ApiError(
                'validation_failed',
                'Unknown deletion reason.',
                422,
                ['reason' => 'invalid_reason']
            );
        }
    }

    $duplicateOf = null;
    if (is_int($duplicateRaw) || (is_string($duplicateRaw) && trim($duplicateRaw) !== '')) {
        $raw = trim((string) $duplicateRaw);
        if (ctype_digit($raw) === false || (int) $raw < 1) {
            throw new ApiError(
                'validation_failed',
                'duplicate_of must be a device id.',
                422,
                ['duplicate_of' => 'invalid']
            );
        }
        $duplicateOf = (int) $raw;
        if ($reason !== 'duplicated') {
            throw new ApiError(
                'validation_failed',
                'duplicate_of only applies to the "duplicated" reason.',
                422,
                ['duplicate_of' => 'reason_not_duplicated']
            );
        }
    }

    return ['reason' => $reason, 'duplicate_of' => $duplicateOf];
}

/**
 * The duplicate reference must be another, still-live device the caller can see.
 *
 * @throws ApiError('validation_failed', …, 422)
 */
function panel_delete_validate_duplicate(PDO $pdo, array $user, int $id, int $duplicateOf): void
{
    if ($duplicateOf === $id) {
        throw new ApiError('validation_failed', 'A device cannot duplicate itself.', 422, ['duplicate_of' => 'self']);
    }
    $company = $user['company'] !== '' ? $user['company'] : null;
    $target = asset_get($pdo, $duplicateOf, $company, true);
    if ($target === null) {
        throw new ApiError('validation_failed', 'The duplicate reference was not found.', 422, ['duplicate_of' => 'not_found']);
    }
    if (!empty($target['deleted_at'])) {
        throw new ApiError(
            'validation_failed',
            'The duplicate reference is itself deleted.',
            422,
            ['duplicate_of' => 'deleted']
        );
    }
}

/**
 * Audit diff for a soft delete: the reason and the kept asset are new values.
 *
 * The Logs screen already renders `field: old → new`, so recording them as a
 * change keeps the history readable without a delete-specific renderer.
 *
 * @return array<string, array{0: ?string, 1: ?string}>
 */
function panel_delete_event_changes(?string $reason, ?int $duplicateOf): array
{
    $changes = [];
    if ($reason !== null) {
        $changes['deleted_reason'] = [null, $reason];
    }
    if ($duplicateOf !== null) {
        $changes['duplicate_of_id'] = [null, (string) $duplicateOf];
    }
    return $changes;
}

/** DELETE /devices/{id} */
function panel_devices_delete(array $params = []): array
{
    $hard = filter_var($_GET['hard'] ?? false, FILTER_VALIDATE_BOOLEAN);
    $user = session_require_capability($hard ? 'devices.purge' : 'devices.delete');
    session_csrf_require();
    $pdo = db();

    $id = panel_device_id($params);
    $row = panel_device_for_write($pdo, $user, $id);
    $company = $user['company'] !== '' ? $user['company'] : null;

    if (!$hard && !empty($row['deleted_at'])) {
        throw new ApiError('conflict', 'This device is already deleted.', 409, ['deleted_at' => 'trashed']);
    }

    // A hard delete removes the row the reason would describe, so the query
    // string is only honoured for the soft path (and ignored, not rejected, so
    // the two callers can share one URL shape).
    $reason = null;
    $duplicateOf = null;
    if (!$hard) {
        $input = panel_delete_reason_input($_GET['reason'] ?? null, $_GET['duplicate_of'] ?? null);
        $reason = $input['reason'];
        $duplicateOf = $input['duplicate_of'];
        if ($duplicateOf !== null) {
            panel_delete_validate_duplicate($pdo, $user, $id, $duplicateOf);
        }
    }

    if ($hard) {
        asset_hard_delete($pdo, $id, $company);
        asset_event_record($pdo, $id, 'purge', $user['email'], [], null, asset_event_device_meta($row, $id));
    } else {
        asset_soft_delete($pdo, $id, $user['email'], $company, $reason, $duplicateOf);
        asset_event_record(
            $pdo,
            $id,
            'delete',
            $user['email'],
            panel_delete_event_changes($reason, $duplicateOf),
            null,
            asset_event_device_meta($row, $id)
        );
    }

    return [
        'data' => [
            'id'              => $id,
            'deleted'         => true,
            'hard'            => $hard,
            'deleted_reason'  => $reason,
            'duplicate_of_id' => $duplicateOf,
        ],
    ];
}

/** POST /devices/{id}/restore — idempotent: restoring a live row is a no-op. */
function panel_devices_restore(array $params = []): array
{
    $user = session_require_capability('devices.restore');
    session_csrf_require();
    $pdo = db();

    $id = panel_device_id($params);
    $row = panel_device_for_write($pdo, $user, $id);
    $company = $user['company'] !== '' ? $user['company'] : null;

    $restored = false;
    if (!empty($row['deleted_at'])) {
        $restored = asset_restore($pdo, $id, $user['email'], $company);
        if ($restored) {
            asset_event_record($pdo, $id, 'restore', $user['email'], [], null, asset_event_device_meta($row, $id));
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
 *   {"ids": [1, 2], "op": "delete", "reason": "duplicated"}  admin+
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
    $user = session_require_capability('devices.bulk');
    session_csrf_require();
    $pdo = db();

    $body = request_json_body();
    $op = trim((string) ($body['op'] ?? ''));
    if (!in_array($op, ['status', 'byod', 'delete', 'restore'], true)) {
        throw new ApiError('validation_failed', 'Unknown bulk operation.', 422, ['op' => 'invalid']);
    }

    $hard = $op === 'delete' && filter_var($body['hard'] ?? false, FILTER_VALIDATE_BOOLEAN);
    if ($op === 'delete' && !permission_capability_allowed($user['role'], $hard ? 'devices.purge' : 'devices.delete')) {
        throw new ApiError('forbidden', 'Your role cannot delete devices.', 403);
    }
    if ($op === 'restore' && !permission_capability_allowed($user['role'], 'devices.restore')) {
        throw new ApiError('forbidden', 'Your role cannot restore devices.', 403);
    }

    $ids = panel_bulk_ids($body['ids'] ?? null);

    // One shared reason for the whole batch: the SPA offers it next to the bulk
    // action and it stays optional there, so a one-click delete still works.
    // Duplicate references are single-row only — a list has no single kept asset.
    $reason = null;
    if ($op === 'delete') {
        $reason = panel_delete_reason_input($body['reason'] ?? null, null)['reason'];
    }

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
                    asset_event_record($pdo, $id, 'purge', $user['email'], [], null, asset_event_device_meta($row, $id));
                } else {
                    asset_soft_delete($pdo, $id, $user['email'], $company, $reason);
                    asset_event_record(
                        $pdo,
                        $id,
                        'delete',
                        $user['email'],
                        panel_delete_event_changes($reason, null),
                        null,
                        asset_event_device_meta($row, $id)
                    );
                }
            } elseif ($op === 'restore') {
                if (empty($row['deleted_at'])) {
                    $skipped[] = ['id' => $id, 'code' => 'unchanged', 'message' => 'This device is not deleted.'];
                    continue;
                }
                asset_restore($pdo, $id, $user['email'], $company);
                asset_event_record($pdo, $id, 'restore', $user['email'], [], null, asset_event_device_meta($row, $id));
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
        'data' => ['op' => $op, 'updated' => $changed, 'skipped' => $skipped, 'reason' => $reason],
        'meta' => ['changed' => count($changed), 'skipped' => count($skipped)],
    ];
}


/**
 * POST /devices/{id}/files — multipart upload, server-side to S3 or to disk.
 *
 * The browser uploads to the panel (the session cookie and the CSRF token are
 * already there) and the server puts the object, so AWS credentials and signing
 * logic never reach JavaScript. This is the path used with `storage.driver =
 * local` and the fallback the SPA takes when the presigned PUT to the bucket
 * fails (CORS, or an `s3.endpoint` the browser cannot resolve).
 */
function panel_devices_files_store(array $params = []): array
{
    $user = session_require_capability('devices.files.upload');
    session_csrf_require();

    $id = panel_device_id($params);
    $pdo = db();
    $device = panel_device_for_write($pdo, $user, $id);

    if (!panel_storage_configured()) {
        throw new ApiError('storage_not_configured', 'Object storage is not configured.', 503);
    }

    // A JSON body means "I already uploaded this object with the presigned URL"
    // (`POST /devices/{id}/files/presign`); multipart is the server-side path.
    if (request_content_type() === 'application/json') {
        return panel_devices_files_register($pdo, $user, $id, $device);
    }

    // PHP discards a body over `post_max_size` before reading it, so `$_FILES`
    // arrives empty and the per-file error never exists. Without this check the
    // user would be told "A file is required." for an oversized attachment.
    if ($_FILES === [] && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
        throw new ApiError('validation_failed', 'The file is too large.', 422, ['file' => 'too_large']);
    }

    $file = $_FILES['file'] ?? $_FILES['new_file'] ?? null;
    $uploadError = is_array($file) ? (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) : UPLOAD_ERR_NO_FILE;
    if ($uploadError === UPLOAD_ERR_INI_SIZE || $uploadError === UPLOAD_ERR_FORM_SIZE) {
        throw new ApiError('validation_failed', 'The file is too large.', 422, ['file' => 'too_large']);
    }
    if (!is_array($file) || $uploadError !== UPLOAD_ERR_OK) {
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

    $key = 'assets/' . $id . '/' . uniqid('', true) . '_' . ($safeName === '' ? 'file' : $safeName);
    // `$device` is what the `{{company}}`/`{{asset}}`/… placeholders of
    // `s3.prefix` read; the returned key is the fully-qualified one the row keeps.
    $stored = storage_put((string) $file['tmp_name'], $key, $mime === '' ? 'application/octet-stream' : $mime, $device);
    if (empty($stored['success'])) {
        throw new ApiError('storage_error', (string) ($stored['message'] ?? 'The file could not be stored.'), 502);
    }

    $pdo->prepare(
        'INSERT INTO device_files (device_id, device_table, file_path, file_name, mime_type, size)
              VALUES (?, ?, ?, ?, ?, ?)'
    )->execute([$id, 'assets', $stored['key'], $name, $mime === '' ? null : $mime, $size]);

    $fileId = (int) $pdo->lastInsertId();
    asset_event_record($pdo, $id, 'file_add', $user['email'], ['file' => [null, $name]], null, asset_event_device_meta($device, $id));
    $stmt = $pdo->prepare("SELECT * FROM device_files WHERE id = ? AND device_table = 'assets'");
    $stmt->execute([$fileId]);
    $row = $stmt->fetch();

    return ['data' => panel_file_payload(is_array($row) ? $row : []), 'status' => 201, 'meta' => ['created' => true]];
}

/**
 * POST /devices/{id}/files/presign — a URL the browser can PUT to directly.
 *
 * Answers `{mode: "s3", ...}` when the S3 driver is active and `{mode: "server"}`
 * otherwise, so the SPA has one call to make and no guessing: `local` storage (or
 * an S3 bucket without CORS) falls back to the multipart endpoint, which is also
 * the only path that works through a proxy that rejects large bodies.
 */
function panel_devices_files_presign(array $params = []): array
{
    $user = session_require_capability('devices.files.upload');
    session_csrf_require();

    $id = panel_device_id($params);
    $pdo = db();
    $device = panel_device_for_write($pdo, $user, $id);

    if (!panel_storage_configured()) {
        throw new ApiError('storage_not_configured', 'Object storage is not configured.', 503);
    }

    $body = request_json_body();
    $name = trim((string) ($body['name'] ?? ''));
    $mime = trim((string) ($body['mime'] ?? ''));
    $size = (int) ($body['size'] ?? 0);

    $errors = [];
    if ($name === '') {
        $errors['name'] = 'required';
    }
    if ($size <= 0) {
        $errors['size'] = 'required';
    } elseif ($size > PANEL_MAX_UPLOAD_BYTES) {
        $errors['size'] = 'too_large';
    }
    if ($errors !== []) {
        throw new ApiError('validation_failed', 'A name and a size within the limit are required.', 422, $errors);
    }

    if (storage_driver() !== 's3') {
        return ['data' => ['mode' => 'server']];
    }

    $safeName = (string) preg_replace('/[^A-Za-z0-9._-]+/', '_', $name);
    $key = 'assets/' . $id . '/' . uniqid('', true) . '_' . ($safeName === '' ? 'file' : $safeName);
    $contentType = $mime === '' ? 'application/octet-stream' : $mime;

    require_once __DIR__ . '/../../s3_client.php';
    // Same resolution the multipart path does (`storage_put()`), so the signed
    // URL and the `key` returned to the SPA describe the same object.
    $s3 = new S3Client(['prefix' => storage_prefix_resolve($device)]);
    $signed = $s3->getPresignedPutUrl($key, $contentType);
    if ($signed === null) {
        // A signature failure must not strand the upload: the client falls back.
        return ['data' => ['mode' => 'server']];
    }

    return ['data' => [
        'mode'       => 's3',
        'url'        => $signed['url'],
        'key'        => $s3->objectKey($key),
        'headers'    => $signed['headers'],
        'expires_in' => $signed['expires_in'],
    ]];
}

/**
 * Register an object the browser uploaded with the presigned PUT.
 *
 * Two checks keep this from being an "attach anything in the bucket" endpoint:
 * the key has to live in this device's `assets/<id>/` folder (the object-key
 * prefix may be glued in front of it), and the object has to exist.
 */
function panel_devices_files_register(PDO $pdo, array $user, int $id, array $device = []): array
{
    if ($device === []) {
        $device = asset_get($pdo, $id, $user['company'] !== '' ? $user['company'] : null, true) ?? [];
    }
    $body = request_json_body();
    $key = trim((string) ($body['key'] ?? ''));
    $name = trim((string) ($body['name'] ?? ''));
    $mime = trim((string) ($body['mime'] ?? ''));
    $size = (int) ($body['size'] ?? 0);

    $errors = [];
    if ($key === '') {
        $errors['key'] = 'required';
    }
    if ($name === '') {
        $errors['name'] = 'required';
    }
    if ($size <= 0 || $size > PANEL_MAX_UPLOAD_BYTES) {
        $errors['size'] = $size <= 0 ? 'required' : 'too_large';
    }
    if ($errors === [] && preg_match('#(?:^|[-_/])assets/' . $id . '/#', $key) !== 1) {
        // The prefix may or may not be glued with a separator
        // (`acme-001-assets/42/…`, `uploads/assets/42/…`, `assets/42/…`), so the
        // segment boundary is what counts — never `assets/<other id>/`.
        $errors['key'] = 'invalid_key';
    }
    if ($errors !== []) {
        throw new ApiError('validation_failed', 'The uploaded object could not be registered.', 422, $errors);
    }

    if (storage_driver() === 's3') {
        require_once __DIR__ . '/../../s3_client.php';
        // No prefix: `presign` already returned the fully-qualified key.
        $s3 = new S3Client(['prefix' => '']);
        if (!$s3->objectExists(preg_replace('#^' . preg_quote($s3->bucket(), '#') . '/#', '', $key) ?? $key)) {
            throw new ApiError('validation_failed', 'The uploaded object was not found.', 422, ['key' => 'missing']);
        }
    }

    $pdo->prepare(
        'INSERT INTO device_files (device_id, device_table, file_path, file_name, mime_type, size)
              VALUES (?, ?, ?, ?, ?, ?)'
    )->execute([$id, 'assets', $key, $name, $mime === '' ? null : $mime, $size]);

    $fileId = (int) $pdo->lastInsertId();
    asset_event_record($pdo, $id, 'file_add', $user['email'], ['file' => [null, $name]], null, asset_event_device_meta($device, $id));

    $stmt = $pdo->prepare("SELECT * FROM device_files WHERE id = ? AND device_table = 'assets'");
    $stmt->execute([$fileId]);
    $row = $stmt->fetch();

    return ['data' => panel_file_payload(is_array($row) ? $row : []), 'status' => 201, 'meta' => ['created' => true]];
}

/** DELETE /devices/{id}/files/{fileId} */
function panel_devices_files_delete(array $params = []): array
{
    $user = session_require_capability('devices.files.delete');
    session_csrf_require();
    $pdo = db();

    $id = panel_device_id($params);
    $device = panel_device_for_write($pdo, $user, $id);

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
        storage_delete((string) $file['file_path']);
    } catch (Throwable $e) {
        // The row still has to go: a missing object must not make the panel
        // impossible to clean up.
        error_log('[panel file delete] ' . $e->getMessage());
    }

    $pdo->prepare('DELETE FROM device_files WHERE id = ?')->execute([$fileId]);
    asset_event_record($pdo, $id, 'file_remove', $user['email'], ['file' => [(string) $file['file_name'], null]], null, asset_event_device_meta($device, $id));

    return ['data' => ['id' => $fileId, 'deleted' => true]];
}

