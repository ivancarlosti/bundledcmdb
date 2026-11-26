<?php
declare(strict_types=1);

/**
 * lib/panel/collect.php — `/panel-api/v1/collect*` handlers.
 *
 *   GET    /collect        feature state + the caller's active download
 *   POST   /collect/file   issue a token and return the script to save
 *   DELETE /collect/file   revoke the caller's active download
 *
 * The download is generated *here* and returned as text, not as a redirect to a
 * signed URL: the token has to reach a file on disk, and a second public
 * endpoint that hands out a token by session would be one more thing to secure.
 * The SPA turns the response into a `Blob` and saves it.
 *
 * Everything the caller can do is per-user: a token is always issued for the
 * signed-in e-mail, never for an e-mail from the request body, so this endpoint
 * cannot mint a credential for somebody else.
 */

// `COLLECT_SCRIPT_VERSION` and the renderers; harmless to include twice.
require_once __DIR__ . '/../collect_script.php';

/** Feature state and the caller's live token (never the raw value). */
function panel_collect_payload(array $user): array
{
    $pdo = db();
    $token = collect_active_token($pdo, $user['email']);

    return [
        'enabled'        => collect_enabled(),
        'ttl_minutes'    => collect_ttl_minutes(),
        'rate_limit'     => collect_rate_limit(),
        'script_version' => COLLECT_SCRIPT_VERSION,
        'endpoint'       => collect_script_endpoint(),
        'token'          => $token === null ? null : collect_token_payload($token),
        'can_collect'    => permission_capability_allowed($user['role'], 'devices.collect', $pdo),
    ];
}

/** GET /collect */
function panel_collect_show(array $params = []): array
{
    $user = session_require_capability('devices.collect');
    return ['data' => panel_collect_payload($user)];
}

/**
 * POST /collect/file
 *
 * Body: `{"format": "bat"|"ps1"}` (default `bat`). The response carries the
 * script text and the public metadata of the token it embeds; issuing revokes the
 * previous download of the same user (`collect_issue_token()`).
 */
function panel_collect_store(array $params = []): array
{
    $user = session_require_capability('devices.collect');
    session_csrf_require();

    if (!collect_enabled()) {
        throw new ApiError('collect_disabled', 'Data collection is disabled on this installation.', 403);
    }
    if (trim((string) $user['company']) === '') {
        throw new ApiError('company_unassigned', 'Your account has no company. Ask an administrator to assign one.', 403);
    }
    if (!collect_tokens_ready(db())) {
        throw new ApiError('collect_unavailable', 'The collector tables are missing. Run the migrations.', 503);
    }

    $body = request_json_body();
    $format = strtolower(trim((string) ($body['format'] ?? 'bat')));
    if ($format === '') {
        $format = 'bat';
    }
    if (!in_array($format, ['bat', 'ps1'], true)) {
        throw new ApiError('validation_failed', 'Unknown file format.', 422, ['format' => 'invalid_option']);
    }

    $fileName = collect_file_name($user['email']);
    if ($format === 'ps1') {
        $fileName = (string) preg_replace('/\.bat$/', '.ps1', $fileName);
    }

    $issued = collect_issue_token(db(), $user, $fileName);
    $endpoint = collect_script_endpoint();
    $content = $format === 'ps1'
        ? collect_script_ps1($endpoint, $issued['token'])
        : collect_script_bat($endpoint, $issued['token']);

    return [
        'data' => [
            'file' => [
                'name'       => $fileName,
                'format'     => $format,
                'content'    => $content,
                'expires_at' => asset_iso_datetime($issued['row']['expires_at'] ?? null),
            ],
            'token' => collect_token_payload($issued['row']),
        ],
        'status' => 201,
    ];
}

/** DELETE /collect/file — revoke whatever the caller currently holds. */
function panel_collect_revoke(array $params = []): array
{
    $user = session_require_capability('devices.collect');
    session_csrf_require();

    $revoked = collect_revoke(db(), $user['email']);
    return ['data' => ['revoked' => $revoked]];
}
