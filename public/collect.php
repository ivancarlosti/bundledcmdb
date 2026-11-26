<?php
declare(strict_types=1);

/**
 * public/collect.php — the endpoint the downloaded collector posts to.
 *
 * Mounted as `/collect` by nginx (Dockerfile) and reachable as `collect.php`
 * without a rewrite. It is the only public write path that is not the Bearer
 * API, and it exists because the machine that reports the data has no API key:
 * the credential is the short-lived token inside the downloaded script
 * (`lib/collect.php`).
 *
 * Contract:
 *
 *   POST /collect
 *   X-Collector-Token: bcx_…          (required; also accepted in the JSON body
 *                                      as "token" for hand-run curl tests)
 *   X-Collector-Version: 1.0.0        (optional, for support)
 *   Content-Type: application/json
 *   { "sn": "…", "hostname": "…", "agent_*": …, "physical_disks": […] }
 *
 *   {"ok":true,"data":{"device":{…},"created":true}}     200 / 201
 *   {"ok":false,"error":{"code":"…","message":"…"}}      see the codes below
 *
 * Error codes, all documented in docs/api.md: `invalid_token`, `token_revoked`,
 * `token_expired`, `collect_disabled`, `user_not_found`, `company_unassigned`,
 * `role_not_allowed`, `device_assigned_to_other_user`, `serial_soft_deleted`,
 * `serial_missing`, `validation_failed`, `forbidden_field`, `conflict`,
 * `rate_limited`.
 *
 * Nothing is logged here: `error_log()` of a body that contains a bearer token
 * is how a token ends up in a log file.
 */

require_once __DIR__ . '/../lib/bootstrap.php';
require_once __DIR__ . '/../lib/collect.php';

ini_set('display_errors', '0');
error_reporting(E_ALL);

header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');
header('Referrer-Policy: no-referrer');

/**
 * The token from the header, or the JSON body (curl convenience).
 *
 * The header wins: a body key is what a browser could be tricked into sending.
 */
function collect_request_token(array $body): string
{
    $header = (string) ($_SERVER['HTTP_X_COLLECTOR_TOKEN'] ?? '');
    if (trim($header) !== '') {
        return trim($header);
    }
    return trim((string) ($body['token'] ?? ''));
}

/** Maximum body size accepted; a workstation report is a few kilobytes. */
const COLLECT_MAX_BODY_BYTES = 1048576;

try {
    if (request_method() !== 'POST') {
        header('Allow: POST');
        json_fail('method_not_allowed', 'POST is the only method this endpoint accepts.', 405);
    }

    $contentType = request_content_type();
    if ($contentType !== 'application/json' && $contentType !== '' && !str_ends_with($contentType, '+json')) {
        json_fail('unsupported_media_type', 'Send the payload as application/json.', 415);
    }

    $declared = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
    if ($declared > COLLECT_MAX_BODY_BYTES) {
        json_fail('payload_too_large', 'The payload is larger than 1 MiB.', 413);
    }

    $body = request_json_body();
    $token = collect_request_token($body);

    $pdo = db();
    if (!collect_tokens_ready($pdo)) {
        json_fail('collect_unavailable', 'Data collection is not installed on this server. Run the migrations.', 503);
    }

    $ip = client_ip();
    collect_rate_limit_ip($pdo, $ip);

    $auth = collect_authorize($pdo, $token);
    collect_rate_limit_token($pdo, $auth);

    // `token` is transport, never a device field: leaving it in the payload would
    // turn into a `forbidden_field` error.
    unset($body['token']);

    $outcome = collect_apply($pdo, $auth, $body, $ip);

    json_ok(
        [
            'device'  => $outcome['device'],
            'created' => $outcome['created'],
        ],
        $outcome['created'] ? 201 : 200
    );
} catch (ApiError $e) {
    json_fail($e->errorCode, $e->getMessage(), $e->status, $e->errors);
} catch (PDOException $e) {
    error_log('[bundledcmdb collect] database error: ' . db_error_message($e));
    $failure = db_failure_map($e);
    json_fail($failure['code'], $failure['message'], $failure['status']);
} catch (Throwable $e) {
    error_log('[bundledcmdb collect] ' . get_class($e) . ': ' . $e->getMessage());
    json_fail('server_error', 'Unexpected server error. Check the application log.', 500);
}
