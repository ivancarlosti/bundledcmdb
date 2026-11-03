<?php
declare(strict_types=1);

/**
 * lib/http.php — request/response helpers shared by the panel and the API:
 * escaping, JSON envelopes, CSRF, flash messages, client IP + allowlists.
 */

/**
 * API error carrying the machine-readable code, HTTP status and per-field
 * validation errors used to build the JSON error envelope.
 */
class ApiError extends RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $status = 400,
        public readonly array $errors = []
    ) {
        parent::__construct($message);
    }
}

/** HTML-escape for output. */
function e(mixed $value): string
{
    return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function json_respond(array $payload, int $status = 200): never
{
    if (!headers_sent()) {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: no-store');
    }
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
    exit;
}

function json_ok(mixed $data, int $status = 200, array $meta = []): never
{
    $payload = ['ok' => true, 'data' => $data];
    if ($meta !== []) {
        $payload['meta'] = $meta;
    }
    json_respond($payload, $status);
}

function json_fail(string $code, string $message, int $status = 400, array $errors = []): never
{
    $error = ['code' => $code, 'message' => $message];
    if ($errors !== []) {
        $error['errors'] = $errors;
    }
    json_respond(['ok' => false, 'error' => $error], $status);
}

/**
 * Decode a JSON request body; throws ApiError('invalid_json') on garbage.
 *
 * `$GLOBALS['APP_TEST_JSON_BODY']` (a JSON string) overrides `php://input` so
 * `bin/test-panel-api.php` can drive the real handlers from the CLI without a
 * web server. Nothing else ever sets it.
 */
function request_json_body(): array
{
    $raw = $GLOBALS['APP_TEST_JSON_BODY'] ?? null;
    if (!is_string($raw)) {
        $raw = file_get_contents('php://input');
    }
    if ($raw === false || trim($raw) === '') {
        return [];
    }
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        throw new ApiError('invalid_json', 'Request body must be a JSON object or array.', 400);
    }
    return $data;
}

function request_method(): string
{
    return strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
}

function redirect(string $url): never
{
    header('Location: ' . $url);
    exit;
}

// --- CSRF ---------------------------------------------------------------

function csrf_token(): string
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}
function csrf_verify(?string $token): bool
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    $expected = $_SESSION['csrf_token'] ?? '';
    return is_string($token) && $expected !== '' && hash_equals($expected, $token);
}

// --- Client IP / allowlists --------------------------------------------

function client_ip(): string
{
    $remote = (string) ($_SERVER['REMOTE_ADDR'] ?? '');

    if (function_exists('settings_bool') && settings_bool('api.trust_proxy')) {
        $forwarded = (string) ($_SERVER['HTTP_X_FORWARDED_FOR'] ?? '');
        foreach (explode(',', $forwarded) as $candidate) {
            $candidate = trim($candidate);
            if ($candidate !== '' && filter_var($candidate, FILTER_VALIDATE_IP) !== false) {
                return $candidate;
            }
        }
        $real = trim((string) ($_SERVER['HTTP_X_REAL_IP'] ?? ''));
        if ($real !== '' && filter_var($real, FILTER_VALIDATE_IP) !== false) {
            return $real;
        }
    }

    return $remote;
}

/**
 * Match one IP against one allowlist entry (plain IP or CIDR, v4 or v6).
 */
function ip_matches_rule(string $ip, string $rule): bool
{
    $rule = trim($rule);
    if ($rule === '') {
        return false;
    }

    if (str_contains($rule, '/')) {
        [$subnet, $bits] = array_pad(explode('/', $rule, 2), 2, '');
        if (preg_match('/^\d+$/', $bits) !== 1) {
            return false;
        }
        $binIp = @inet_pton($ip);
        $binSubnet = @inet_pton($subnet);
        if ($binIp === false || $binSubnet === false || strlen($binIp) !== strlen($binSubnet)) {
            return false;
        }
        $bits = (int) $bits;
        $maxBits = strlen($binIp) * 8;
        if ($bits < 0 || $bits > $maxBits) {
            return false;
        }
        $wholeBytes = intdiv($bits, 8);
        $restBits = $bits % 8;
        if ($wholeBytes > 0 && substr($binIp, 0, $wholeBytes) !== substr($binSubnet, 0, $wholeBytes)) {
            return false;
        }
        if ($restBits === 0) {
            return true;
        }
        $mask = (0xFF << (8 - $restBits)) & 0xFF;
        return (ord($binIp[$wholeBytes]) & $mask) === (ord($binSubnet[$wholeBytes]) & $mask);
    }

    $binIp = @inet_pton($ip);
    $binRule = @inet_pton($rule);
    return $binIp !== false && $binRule !== false && $binIp === $binRule;
}

/**
 * True when the IP matches any entry of the allowlist. An empty allowlist
 * means "no restriction".
 */
function ip_in_allowlist(string $ip, string $allowlist): bool
{
    if (trim($allowlist) === '') {
        return true;
    }
    foreach (preg_split('/[\s,;]+/', $allowlist, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $rule) {
        if (ip_matches_rule($ip, $rule)) {
            return true;
        }
    }
    return false;
}

/**
 * Validate an allowlist string: every entry must be a valid IP or CIDR.
 *
 * @return array{ok: bool, invalid: list<string>}
 */
function ip_allowlist_validate(string $allowlist): array
{
    $invalid = [];
    foreach (preg_split('/[\s,;]+/', trim($allowlist), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $rule) {
        $probe = str_contains($rule, '/') ? explode('/', $rule, 2)[0] : $rule;
        if (filter_var($probe, FILTER_VALIDATE_IP) === false) {
            $invalid[] = $rule;
            continue;
        }
        if (str_contains($rule, '/')) {
            $bits = explode('/', $rule, 2)[1] ?? '';
            $max = str_contains($probe, ':') ? 128 : 32;
            if (preg_match('/^\d+$/', $bits) !== 1 || (int) $bits > $max) {
                $invalid[] = $rule;
            }
        }
    }
    return ['ok' => $invalid === [], 'invalid' => $invalid];
}
