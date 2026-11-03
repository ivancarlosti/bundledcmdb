<?php
declare(strict_types=1);

/**
 * public/panel_api.php — session-authenticated JSON API for the SPA.
 *
 * Mount point: `/panel-api` (nginx maps `/panel-api/...` here with PATH_INFO).
 * The public, Bearer-only REST API stays in `public/api.php`; this file is the
 * panel's own API and is deliberately separate so that contract never changes.
 *
 * Requests carry the session cookie; mutations also need `X-CSRF-Token` (see
 * lib/session_auth.php). Responses use the same envelope as `api.php`:
 *
 *   { "ok": true,  "data": …, "meta": { … } }
 *   { "ok": false, "error": { "code": "…", "message": "…", "errors": { … } } }
 */

require_once __DIR__ . '/../lib/bootstrap.php';

app_boot();
session_boot();

header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('Cache-Control: no-store');

require_once __DIR__ . '/../lib/panel/routes.php';
require_once __DIR__ . '/../lib/panel/session.php';
require_once __DIR__ . '/../lib/panel/meta.php';
require_once __DIR__ . '/../lib/panel/i18n.php';
require_once __DIR__ . '/../lib/panel/devices.php';
require_once __DIR__ . '/../lib/panel/device_writes.php';
require_once __DIR__ . '/../lib/panel/settings.php';

panel_dispatch();
