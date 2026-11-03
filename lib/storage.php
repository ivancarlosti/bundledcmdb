<?php
declare(strict_types=1);

/**
 * lib/storage.php — attachment storage behind one interface (`s3` or `local`).
 *
 * Why two drivers: the Docker image and the zip release are usable without S3
 * (a laptop, a lab box, the `docker-compose-bundle.yml` stack), and asking an
 * administrator to stand up MinIO just to attach a PDF is a bad first run. The
 * driver is a setting (`storage.driver`), the caller never cares which one is
 * active, and `local` keeps everything under `var/storage` — git-ignored, and a
 * bind mount in both Docker stacks (`${BCMDB_UPLOADS_DIR:-../uploads}`), so the
 * attachments outlive the container.
 *
 * The object key is what the database stores in `device_files.file_path`, so the
 * two drivers share the same rows: switching from `local` to `s3` later means
 * copying the files, not rewriting the schema.
 */

/** Active driver: `s3` or `local` (anything unknown falls back to `s3`). */
function storage_driver(): string
{
    try {
        $driver = strtolower(trim((string) settings_get('storage.driver', 's3')));
    } catch (Throwable) {
        $driver = 's3';
    }
    return $driver === 'local' ? 'local' : 's3';
}

/**
 * Absolute directory used by the local driver.
 *
 * Deliberately not a setting: `docker/docker-compose.yml` and
 * `docker/docker-compose-bundle.yml` bind-mount this path
 * (`${BCMDB_UPLOADS_DIR:-../uploads}` → `/var/www/html/var/storage`), so an
 * editable path could point PHP-FPM outside the mount and the attachments would
 * disappear with the next container. `BCMDB_STORAGE_PATH` is the escape hatch
 * for the test suite and for non-Docker deployments.
 */
function storage_local_dir(): string
{
    $override = trim((string) getenv('BCMDB_STORAGE_PATH'));
    if ($override !== '') {
        return rtrim($override, '/');
    }

    return dirname(__DIR__) . '/var/storage';
}

/** Is the active driver ready to accept an upload? */
function storage_configured(): bool
{
    if (storage_driver() === 'local') {
        $dir = storage_local_dir();
        return is_dir($dir) ? is_writable($dir) : is_writable(dirname($dir));
    }

    // S3 talks to the bucket, so a bucket plus a secret is the minimum.
    try {
        return (string) settings_get('s3.bucket', '') !== '' && settings_secret_configured('s3.secret_key');
    } catch (Throwable) {
        return false;
    }
}

/** Keep a key inside the storage root (no `..`, no absolute paths). */
function storage_safe_key(string $key): string
{
    $key = str_replace('\\', '/', trim($key));
    $parts = [];
    foreach (explode('/', $key) as $segment) {
        $segment = trim($segment);
        if ($segment === '' || $segment === '.' || $segment === '..') {
            continue;
        }
        $parts[] = preg_replace('/[^A-Za-z0-9._-]+/', '_', $segment) ?? '';
    }
    return implode('/', $parts);
}

/** Create the local root on demand (0700: the files are customer data). */
function storage_local_ensure_dir(): bool
{
    $dir = storage_local_dir();
    if (is_dir($dir)) {
        return true;
    }
    return @mkdir($dir, 0700, true) || is_dir($dir);
}

/**
 * Tokens the object-key prefix understands, straight from the setting
 * definition, so the panel and the resolver can never disagree.
 *
 * @return array<string, string> token => English label
 */
function storage_prefix_tokens(): array
{
    try {
        $tokens = settings_definitions()['s3.prefix']['placeholders'] ?? [];
    } catch (Throwable) {
        return [];
    }
    return is_array($tokens) ? $tokens : [];
}

/**
 * One placeholder value: safe for a key segment, or `''` when nothing is left.
 *
 * A company called `ACME / BR` must not invent a folder, and `..` must not
 * survive — `local` writes below `var/storage` and S3 clients treat `/` as a
 * namespace, so both matter.
 */
function storage_prefix_segment(string $value): string
{
    $value = (string) preg_replace('/[^A-Za-z0-9._-]+/', '_', trim($value));
    return trim($value, '._-');
}

/**
 * Expand the `{{token}}` placeholders in `s3.prefix` for one asset.
 *
 * Uploads are the only place the prefix is applied: the key stored in
 * `device_files.file_path` is already fully qualified, and reads/deletes use it
 * verbatim (`new S3Client(['prefix' => ''])`), so changing the prefix later can
 * never strand an object.
 *
 * Rules, in order:
 *  - a known token becomes the asset value passed through
 *    `storage_prefix_segment()`; a token that resolves to nothing expands to
 *    nothing (`{{company}}-{{asset}}-` on a tagless asset reads `ACME--` →
 *    `ACME-`, which `S3Client::objectKey()` still glues straight onto the key);
 *  - runs of the same separator collapse (`a--b` → `a-b`) and leading
 *    separators are trimmed, because a prefix never starts with `-`, `_` or `/`;
 *  - a *literal* trailing `-`/`_` survives, because it is what asks
 *    `S3Client::objectKey()` to run into the key instead of creating a folder;
 *  - unknown tokens stay literal (a typo is visible instead of swallowed); the
 *    settings form rejects them on save (`storage_prefix_unknown_token()`).
 */
function storage_prefix_resolve(array $asset = []): string
{
    try {
        $template = trim((string) settings_get('s3.prefix', ''));
    } catch (Throwable) {
        return '';
    }
    if ($template === '') {
        return '';
    }

    $catalog = storage_prefix_tokens();
    $values = [
        'company'  => (string) ($asset['company'] ?? ''),
        'asset'    => (string) ($asset['Asset'] ?? ''),
        'id'       => (string) ($asset['Id'] ?? ''),
        'sn'       => (string) ($asset['SN'] ?? ''),
        'hostname' => (string) ($asset['Hostname'] ?? ''),
        'source'   => (string) ($asset['source'] ?? ''),
    ];

    // Literal and token segments alternate: [literal, token, literal, …].
    $parts = preg_split('/\{\{\s*([A-Za-z_]+)\s*\}\}/', $template, -1, PREG_SPLIT_DELIM_CAPTURE);
    if (!is_array($parts)) {
        return '';
    }

    $out = '';
    $count = count($parts);
    for ($i = 0; $i < $count; $i++) {
        if ($i % 2 === 0) {
            $out .= $parts[$i];
            continue;
        }
        $token = strtolower((string) $parts[$i]);
        if (!array_key_exists($token, $catalog) || !array_key_exists($token, $values)) {
            $out .= '{{' . $token . '}}';
            continue;
        }
        $out .= storage_prefix_segment($values[$token]);
    }

    // A prefix is a path fragment: a doubled separator is an artefact of an
    // empty token (`ACME--` → `ACME-`), it never starts with a separator, and a
    // folder prefix may not end with one. The trailing `-`/`_` stays.
    $out = (string) preg_replace('#([-_/])\1+#', '$1', $out);
    $out = ltrim($out, '-_/');
    return rtrim($out, '/');
}

/** First unknown `{{token}}` in a template, or null. Used to reject a typo on save. */
function storage_prefix_unknown_token(string $template): ?string
{
    $catalog = storage_prefix_tokens();
    if (preg_match_all('/\{\{\s*([A-Za-z_]+)\s*\}\}/', $template, $matches) === false) {
        return null;
    }
    foreach ($matches[1] as $token) {
        if (!array_key_exists(strtolower((string) $token), $catalog)) {
            return (string) $token;
        }
    }
    return null;
}

/**
 * Store a file.
 *
 * `$asset` feeds the `{{token}}` placeholders of `s3.prefix`; the local driver
 * ignores the prefix, so both drivers keep storing `assets/<id>/<name>` rows.
 *
 * @param array<string, mixed> $asset Asset row the key belongs to
 * @return array{success: bool, message: string, key: string}
 */
function storage_put(string $tmpPath, string $key, string $mime = 'application/octet-stream', array $asset = []): array
{
    $key = storage_safe_key($key);
    if ($key === '') {
        return ['success' => false, 'message' => 'Invalid object key.', 'key' => ''];
    }

    if (storage_driver() === 'local') {
        if (!storage_local_ensure_dir()) {
            return ['success' => false, 'message' => 'The storage directory is not writable.', 'key' => $key];
        }
        $target = storage_local_dir() . '/' . $key;
        $parent = dirname($target);
        if (!is_dir($parent) && !@mkdir($parent, 0700, true) && !is_dir($parent)) {
            return ['success' => false, 'message' => 'The storage directory is not writable.', 'key' => $key];
        }
        if (!@copy($tmpPath, $target)) {
            return ['success' => false, 'message' => 'The file could not be stored.', 'key' => $key];
        }
        @chmod($target, 0600);
        return ['success' => true, 'message' => 'OK', 'key' => $key];
    }

    require_once __DIR__ . '/../s3_client.php';
    // The prefix is expanded here and nowhere else: this is the moment the asset
    // the placeholders refer to is known.
    $s3 = new S3Client(['prefix' => storage_prefix_resolve($asset)]);
    if (!$s3->isConfigured()) {
        return ['success' => false, 'message' => 'Object storage is not configured.', 'key' => $key];
    }
    $result = $s3->uploadFile($tmpPath, $key, $mime);
    if (empty($result['success'])) {
        return ['success' => false, 'message' => (string) ($result['message'] ?? 'The file could not be stored.'), 'key' => $key];
    }
    // The database row keeps the same key for both drivers.
    return ['success' => true, 'message' => 'OK', 'key' => $s3->objectKey($key)];
}

/** Remove an object. Missing files are not an error (the row still has to go). */
function storage_delete(string $key): bool
{
    $key = storage_safe_key($key);
    if ($key === '') {
        return false;
    }

    if (storage_driver() === 'local') {
        // Accept a row written by the S3 driver too (it carries the key prefix).
        $path = storage_local_dir() . '/' . $key;
        return !is_file($path) || @unlink($path);
    }

    require_once __DIR__ . '/../s3_client.php';
    // No prefix: the row already carries the fully-qualified key, so expanding
    // the template again would target the wrong object after a prefix change.
    $s3 = new S3Client(['prefix' => '']);
    if (!$s3->isConfigured()) {
        return false;
    }
    return $s3->deleteFile($key);
}

/**
 * Local path of a stored object, or null when it lives in S3.
 *
 * Used by the download route: `local` streams the file, `s3` redirects to a
 * presigned GET so PHP never proxies a large object.
 */
function storage_local_path(string $key): ?string
{
    if (storage_driver() !== 'local') {
        return null;
    }
    $key = storage_safe_key($key);
    if ($key === '') {
        return null;
    }
    $path = storage_local_dir() . '/' . $key;
    return is_file($path) ? $path : null;
}

/** Presigned GET for the S3 driver (null when local storage is active). */
function storage_presigned_url(string $key): ?string
{
    if (storage_driver() === 'local') {
        return null;
    }
    require_once __DIR__ . '/../s3_client.php';
    // Same reason as `storage_delete()`: the key is already fully qualified.
    $s3 = new S3Client(['prefix' => '']);
    if (!$s3->isConfigured()) {
        return null;
    }
    $url = $s3->getPresignedUrl($key);
    return $url === '' ? null : $url;
}
