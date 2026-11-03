<?php
declare(strict_types=1);

/**
 * lib/storage.php — attachment storage behind one interface (`s3` or `local`).
 *
 * Why two drivers: the Docker image and the zip release are usable without S3
 * (a laptop, a lab box, the `docker-compose.dev.yml` stack), and asking an
 * administrator to stand up MinIO just to attach a PDF is a bad first run. The
 * driver is a setting (`storage.driver`), the caller never cares which one is
 * active, and `local` keeps everything under `var/storage` (git-ignored) unless
 * `storage.local_path` says otherwise.
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

/** Absolute directory used by the local driver. */
function storage_local_dir(): string
{
    $configured = '';
    try {
        $configured = trim((string) settings_get('storage.local_path', ''));
    } catch (Throwable) {
        $configured = '';
    }
    if ($configured === '') {
        $configured = dirname(__DIR__) . '/var/storage';
    }
    return rtrim($configured, '/');
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
 * Store a file.
 *
 * @return array{success: bool, message: string, key: string}
 */
function storage_put(string $tmpPath, string $key, string $mime = 'application/octet-stream'): array
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
    $s3 = new S3Client();
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
    $s3 = new S3Client();
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
    $s3 = new S3Client();
    if (!$s3->isConfigured()) {
        return null;
    }
    $url = $s3->getPresignedUrl($key);
    return $url === '' ? null : $url;
}
