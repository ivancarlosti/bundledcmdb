<?php
declare(strict_types=1);

/**
 * lib/settings.php — application settings with three-tier precedence.
 *
 *   database (app_settings)  →  environment variable  →  built-in default
 *
 * The settings page shows which tier answered (DB / ENV / default) so an
 * instance previously configured only through .env keeps working and it is
 * obvious what still comes from the environment.
 *
 * Secrets (S3 secret key, …) are stored encrypted, see lib/crypto.php.
 */

/**
 * Every known setting. `group` drives the Settings tabs, `type` drives both the
 * input widget and validation.
 */
function settings_definitions(): array
{
    static $defs = null;
    if ($defs !== null) {
        return $defs;
    }

    return $defs = [
        // --- General ---
        'general.default_locale' => [
            'group' => 'general', 'type' => 'locale', 'default' => 'pt-BR',
            'env' => 'APP_DEFAULT_LOCALE', 'label' => 'Default language',
        ],
        'general.timezone' => [
            'group' => 'general', 'type' => 'timezone', 'default' => 'America/Sao_Paulo',
            'env' => 'APP_TIMEZONE', 'label' => 'Time zone',
        ],
        'general.default_theme' => [
            'group' => 'general', 'type' => 'select', 'default' => 'system',
            'options' => ['system' => 'System', 'light' => 'Light', 'dark' => 'Dark'],
            'label' => 'Default theme',
        ],
        'general.per_page' => [
            'group' => 'general', 'type' => 'int', 'default' => '25', 'min' => 5, 'max' => 500,
            'label' => 'Rows per page',
        ],

        // --- Assets ---
        'assets.default_source' => [
            'group' => 'assets', 'type' => 'source', 'default' => 'manual',
            'label' => 'Default source for panel-created assets',
        ],
        'assets.require_sn' => [
            'group' => 'assets', 'type' => 'bool', 'default' => '1',
            'label' => 'Require serial number',
        ],

        // --- API ---
        'api.enabled' => [
            'group' => 'api', 'type' => 'bool', 'default' => '1',
            'label' => 'API enabled',
        ],
        'api.ip_allowlist' => [
            'group' => 'api', 'type' => 'textarea', 'default' => '',
            'label' => 'Global IP allowlist',
        ],
        'api.trust_proxy' => [
            'group' => 'api', 'type' => 'bool', 'default' => '0',
            'label' => 'Trust X-Forwarded-For',
        ],
        'api.max_page_size' => [
            'group' => 'api', 'type' => 'int', 'default' => '200', 'min' => 1, 'max' => 1000,
            'label' => 'Maximum page size',
        ],
        // Panel mutations per user per minute (`lib/rate_limit.php`). The public
        // Bearer API keeps its own per-key limits on api_keys.rate_limit.
        'api.panel_rate_limit' => [
            'group' => 'api', 'type' => 'int', 'default' => '120', 'min' => 1, 'max' => 10000,
            'label' => 'Panel writes per minute (per user)',
        ],

        // --- Storage (S3) ---
        's3.region' => [
            'group' => 'storage', 'type' => 'text', 'default' => '',
            'env' => 'S3_REGION', 'label' => 'Region',
        ],
        's3.bucket' => [
            'group' => 'storage', 'type' => 'text', 'default' => '',
            'env' => 'S3_BUCKET', 'label' => 'Bucket',
        ],
        's3.endpoint' => [
            'group' => 'storage', 'type' => 'text', 'default' => '',
            'env' => 'S3_ENDPOINT', 'label' => 'Endpoint',
        ],
        's3.access_key' => [
            'group' => 'storage', 'type' => 'text', 'default' => '',
            'env' => 'S3_ACCESS_KEY', 'label' => 'Access key ID',
        ],
        's3.secret_key' => [
            'group' => 'storage', 'type' => 'secret', 'default' => '', 'secret' => true,
            'env' => 'S3_SECRET_KEY', 'label' => 'Secret access key',
        ],
        's3.path_style' => [
            'group' => 'storage', 'type' => 'bool', 'default' => '1',
            'label' => 'Path-style URLs (MinIO / self-hosted)',
        ],
        's3.presign_seconds' => [
            'group' => 'storage', 'type' => 'int', 'default' => '3600', 'min' => 60, 'max' => 604800,
            'label' => 'Presigned URL lifetime (seconds)',
        ],
        's3.prefix' => [
            'group' => 'storage', 'type' => 'text', 'default' => 'uploads',
            'label' => 'Object key prefix',
        ],
    ];
}
/**
 * Placeholder values shipped in .env.example count as "not configured".
 */
function settings_env_value(string $key): ?string
{
    $defs = settings_definitions();
    $envName = $defs[$key]['env'] ?? null;
    if ($envName === null || !defined($envName)) {
        return null;
    }
    $value = trim((string) constant($envName));
    if ($value === '') {
        return null;
    }
    if (preg_match('/^YOUR/i', $value) === 1
        || str_contains($value, 'YOURS3')
        || str_contains($value, 'YOURKEYCLOAK')) {
        return null;
    }

    return $value;
}

/**
 * Raw rows from app_settings, secrets already decrypted.
 * Returns [] when the table does not exist yet (pre-migration).
 *
 * @return array<string, array{value: ?string, secret: bool, updated_at: ?string, updated_by: ?string}>
 */
function settings_db_rows(bool $force = false): array
{
    static $rows = null;
    if ($rows !== null && !$force) {
        return $rows;
    }

    $rows = [];
    $pdo = db_try();
    if ($pdo === null || !db_table_exists($pdo, 'app_settings')) {
        return $rows;
    }

    $stmt = $pdo->query('SELECT skey, svalue, is_secret, updated_at, updated_by FROM app_settings');
    foreach ($stmt->fetchAll() as $row) {
        $isSecret = (int) $row['is_secret'] === 1;
        $raw = (string) ($row['svalue'] ?? '');
        $rows[(string) $row['skey']] = [
            'value'      => $isSecret ? crypto_decrypt($raw) : $raw,
            'secret'     => $isSecret,
            'updated_at' => $row['updated_at'] ?? null,
            'updated_by' => $row['updated_by'] ?? null,
        ];
    }

    return $rows;
}

/**
 * Resolved value: DB row → env → definition default.
 */
function settings_get(string $key, mixed $default = null): mixed
{
    $defs = settings_definitions();
    if (!isset($defs[$key])) {
        return $default;
    }

    $rows = settings_db_rows();
    if (array_key_exists($key, $rows) && $rows[$key]['value'] !== null) {
        return $rows[$key]['value'];
    }
    $env = settings_env_value($key);
    if ($env !== null) {
        return $env;
    }
    if (array_key_exists('default', $defs[$key])) {
        return $defs[$key]['default'];
    }

    return $default;
}

/**
 * 'db' | 'env' | 'default' — shown as a badge next to each field.
 */
function settings_source(string $key): string
{
    $rows = settings_db_rows();
    if (array_key_exists($key, $rows) && $rows[$key]['value'] !== null) {
        return 'db';
    }
    return settings_env_value($key) !== null ? 'env' : 'default';
}

function settings_bool(string $key): bool
{
    $value = settings_get($key);
    if (is_bool($value)) {
        return $value;
    }
    return filter_var((string) $value, FILTER_VALIDATE_BOOLEAN);
}
function settings_int(string $key): int
{
    return (int) settings_get($key, 0);
}

/**
 * All settings resolved, including metadata for the UI.
 *
 * @return array<string, array{value: ?string, source: string, def: array}>
 */
function settings_all(bool $force = false): array
{
    if ($force) {
        settings_db_rows(true);
    }
    $out = [];
    foreach (settings_definitions() as $key => $def) {
        $out[$key] = [
            'value'  => settings_get($key),
            'source' => settings_source($key),
            'def'    => $def,
        ];
    }

    return $out;
}

/**
 * Persist one setting. Secrets are encrypted; passing '' clears the value so
 * the env/default tier takes over again.
 */
function settings_set(string $key, mixed $value, ?string $actor = null): void
{
    $defs = settings_definitions();
    if (!isset($defs[$key])) {
        throw new InvalidArgumentException('Unknown setting: ' . $key);
    }
    $pdo = db();
    $isSecret = !empty($defs[$key]['secret']);
    $value = $value === null ? '' : (string) $value;
    $stored = $isSecret ? ($value === '' ? '' : crypto_encrypt($value)) : $value;

    $stmt = $pdo->prepare(
        'INSERT INTO app_settings (skey, svalue, is_secret, updated_by)
         VALUES (?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE svalue = VALUES(svalue), is_secret = VALUES(is_secret),
                                 updated_by = VALUES(updated_by)'
    );
    $stmt->execute([$key, $stored, $isSecret ? 1 : 0, $actor]);

    settings_db_rows(true);
}

/**
 * Drop the DB override so the env/default tier answers again.
 */
function settings_forget(string $key): void
{
    if (!isset(settings_definitions()[$key])) {
        return;
    }
    $stmt = db()->prepare('DELETE FROM app_settings WHERE skey = ?');
    $stmt->execute([$key]);
    settings_db_rows(true);
}

/**
 * True when a secret has a usable value (DB, decrypted, or env).
 */
function settings_secret_configured(string $key): bool
{
    $value = settings_get($key);
    return is_string($value) && $value !== '';
}

/**
 * Validate/normalise a raw submitted value for a setting.
 *
 * @return array{ok: bool, value: ?string, error: ?string}
 */
function settings_normalize(string $key, mixed $raw): array
{
    $defs = settings_definitions();
    $def = $defs[$key] ?? null;
    if ($def === null) {
        return ['ok' => false, 'value' => null, 'error' => 'unknown_setting'];
    }
    $value = is_string($raw) ? trim($raw) : (string) $raw;

    switch ($def['type']) {
        case 'bool':
            return ['ok' => true, 'value' => filter_var($value, FILTER_VALIDATE_BOOLEAN) ? '1' : '0', 'error' => null];
        case 'int':
            if ($value === '' || preg_match('/^-?\d+$/', $value) !== 1) {
                return ['ok' => false, 'value' => null, 'error' => 'invalid_number'];
            }
            $int = (int) $value;
            if ((isset($def['min']) && $int < $def['min']) || (isset($def['max']) && $int > $def['max'])) {
                return ['ok' => false, 'value' => null, 'error' => 'out_of_range'];
            }
            return ['ok' => true, 'value' => (string) $int, 'error' => null];
        case 'timezone':
            if ($value === '' || !in_array($value, DateTimeZone::listIdentifiers(), true)) {
                return ['ok' => false, 'value' => null, 'error' => 'invalid_timezone'];
            }
            return ['ok' => true, 'value' => $value, 'error' => null];
        case 'select':
            $allowed = array_keys($def['options'] ?? []);
            if (!in_array($value, $allowed, true)) {
                return ['ok' => false, 'value' => null, 'error' => 'invalid_option'];
            }
            return ['ok' => true, 'value' => $value, 'error' => null];
        case 'locale':
            if ($value === '') {
                return ['ok' => true, 'value' => '', 'error' => null];
            }
            return [
                'ok'    => preg_match('/^[A-Za-z]{2,3}([_-][A-Za-z0-9]{2,8})*$/', $value) === 1,
                'value' => $value,
                'error' => 'invalid_locale',
            ];
        case 'textarea':
            return ['ok' => true, 'value' => str_replace("\r\n", "\n", $value), 'error' => null];
        default:
            return ['ok' => true, 'value' => $value, 'error' => null];
    }
}
