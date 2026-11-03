<?php
declare(strict_types=1);

/**
 * s3_client.php — S3 wrapper.
 *
 * Configuration comes from Settings → Storage (stored in `app_settings`, the
 * secret encrypted with APP_ENCRYPTION_KEY), so an instance can be moved away
 * from `.env` without touching code. There is no S3 environment tier.
 *
 * Constructor overrides are supported, which is what the "Test connection"
 * button uses to validate values before saving them.
 */

require_once __DIR__ . '/lib/bootstrap.php';

class S3Client
{
    private Aws\S3\S3Client $s3;
    private string $bucket;
    private string $accessKey;
    private string $secretKey;
    private string $prefix;
    private int $presignSeconds;

    /**
     * @param array{region?: string, bucket?: string, endpoint?: string, access_key?: string,
     *              secret_key?: string, path_style?: bool, prefix?: string, presign_seconds?: int} $overrides
     */
    public function __construct(array $overrides = [])
    {
        if (!class_exists(Aws\S3\S3Client::class)) {
            throw new RuntimeException('AWS SDK is not installed (run composer install).');
        }

        $region = trim((string) ($overrides['region'] ?? settings_get('s3.region', '')));
        $region = $region !== '' ? $region : 'us-east-1';
        $endpoint = trim((string) ($overrides['endpoint'] ?? settings_get('s3.endpoint', '')));
        $accessKey = trim((string) ($overrides['access_key'] ?? settings_get('s3.access_key', '')));
        $secretKey = trim((string) ($overrides['secret_key'] ?? settings_get('s3.secret_key', '')));
        $pathStyle = $overrides['path_style'] ?? settings_bool('s3.path_style');

        $this->bucket = trim((string) ($overrides['bucket'] ?? settings_get('s3.bucket', '')));
        $this->accessKey = $accessKey;
        $this->secretKey = $secretKey;
        $this->prefix = trim((string) ($overrides['prefix'] ?? settings_get('s3.prefix', '')), '/');
        $this->presignSeconds = max(60, (int) ($overrides['presign_seconds'] ?? settings_int('s3.presign_seconds')));

        $config = [
            'version' => 'latest',
            'region' => $region,
            'use_path_style_endpoint' => (bool) $pathStyle,
        ];
        if ($endpoint !== '') {
            $config['endpoint'] = $endpoint;
        }
        if ($accessKey !== '' && $secretKey !== '') {
            $config['credentials'] = ['key' => $accessKey, 'secret' => $secretKey];
        }

        $this->s3 = new Aws\S3\S3Client($config);
    }

    /**
     * True when bucket + credentials are present.
     *
     * Answered from the values this instance was built with, never from the
     * SDK: `AwsClient::getCredentials()` returns whatever the credential
     * *provider* yields, and in aws-sdk-php 3.4xx that is a `PromiseInterface`
     * (`GuzzleHttp\Promise\FulfilledPromise`), not a `CredentialsInterface`, so
     * calling `getAccessKeyId()` on it is a fatal error. It is also the exact
     * condition that decides whether `credentials` go into the SDK config in
     * the constructor, so the two can never disagree.
     */
    public function isConfigured(): bool
    {
        return $this->bucket !== ''
            && $this->accessKey !== ''
            && $this->secretKey !== '';
    }

    public function bucket(): string
    {
        return $this->bucket;
    }

    /**
     * Prefix an object key with the configured prefix (no double prefixing).
     *
     * A prefix that ends in `-`/`_` was written to run into the key, which is
     * what the shipped `{{company}}-{{asset}}-` template does
     * (`acme-nb-001-assets/42/x.pdf`); any other prefix is a folder
     * (`uploads/assets/42/x.pdf`). Only uploads pass a prefix — reads and deletes
     * build the client with `''` because the row stores the final key.
     */
    public function objectKey(string $key): string
    {
        $key = ltrim($key, '/');
        if ($this->prefix === '') {
            return $key;
        }
        $glue = preg_match('/[-_]$/', $this->prefix) === 1 ? '' : '/';
        if (str_starts_with($key, $this->prefix . $glue)) {
            return $key;
        }
        return $this->prefix . $glue . $key;
    }

    /**
     * @return array{ok: bool, message: string}
     */
    public function testConnection(): array
    {
        if (!$this->isConfigured()) {
            return ['ok' => false, 'message' => 'Bucket, access key and secret key are required.'];
        }
        try {
            $this->s3->headBucket(['Bucket' => $this->bucket]);
            return ['ok' => true, 'message' => 'Connected to bucket "' . $this->bucket . '".'];
        } catch (Throwable $e) {
            return ['ok' => false, 'message' => $e->getMessage()];
        }
    }

    public function uploadFile(string $filePath, string $key, string $contentType = 'application/octet-stream'): array
    {
        try {
            $result = $this->s3->putObject([
                'Bucket' => $this->bucket,
                'Key' => $this->objectKey($key),
                'SourceFile' => $filePath,
                'ContentType' => $contentType,
            ]);
            return ['success' => true, 'code' => 200, 'message' => 'OK', 'url' => $result['ObjectURL']];
        } catch (Throwable $e) {
            // `CredentialsException` extends RuntimeException (not AwsException),
            // so a signing/credential failure has to be caught here too: the
            // caller turns this into a 502 storage_error instead of a 500.
            return [
                'success' => false,
                'code' => $e instanceof Aws\Exception\AwsException ? ($e->getStatusCode() ?? 0) : 0,
                'message' => $e->getMessage(),
            ];
        }
    }

    public function deleteFile(string $key): bool
    {
        try {
            $this->s3->deleteObject([
                'Bucket' => $this->bucket,
                'Key' => $this->objectKey($key),
            ]);
            return true;
        } catch (Throwable $e) {
            error_log($e->getMessage());
            return false;
        }
    }

    /**
     * Presigned PUT for a browser upload (`POST /devices/{id}/files/presign`).
     *
     * The content type is part of the signature, so the client has to send the
     * same one it asked for; `expiresIn` stays short (the panel's presign
     * lifetime) because the URL is used once, immediately.
     *
     * @return array{url: string, headers: array<string, string>, expires_in: int}|null
     */
    public function getPresignedPutUrl(string $key, string $contentType, ?int $expiresIn = null): ?array
    {
        $expiresIn = max(60, $expiresIn ?? $this->presignSeconds);
        try {
            $cmd = $this->s3->getCommand('PutObject', [
                'Bucket' => $this->bucket,
                'Key' => $this->objectKey($key),
                'ContentType' => $contentType,
            ]);
            $request = $this->s3->createPresignedRequest($cmd, "+{$expiresIn} seconds");
            return [
                'url'        => (string) $request->getUri(),
                'headers'    => ['Content-Type' => $contentType],
                'expires_in' => $expiresIn,
            ];
        } catch (Throwable $e) {
            // A credentials failure is a RuntimeException, not an AwsException;
            // returning null keeps the documented `{mode: "server"}` fallback.
            error_log($e->getMessage());
            return null;
        }
    }

    /** True when the object exists (used to validate a direct upload). */
    public function objectExists(string $key): bool
    {
        try {
            $this->s3->headObject([
                'Bucket' => $this->bucket,
                'Key' => $this->objectKey($key),
            ]);
            return true;
        } catch (Throwable $e) {
            return false;
        }
    }

    public function getPresignedUrl(string $key, ?int $expiresIn = null): string
    {
        $expiresIn = $expiresIn ?? $this->presignSeconds;
        try {
            $cmd = $this->s3->getCommand('GetObject', [
                'Bucket' => $this->bucket,
                'Key' => $this->objectKey($key),
            ]);
            $request = $this->s3->createPresignedRequest($cmd, "+{$expiresIn} seconds");
            return (string) $request->getUri();
        } catch (Throwable $e) {
            error_log($e->getMessage());
            return '';
        }
    }
}

