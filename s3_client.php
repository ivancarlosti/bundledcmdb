<?php
declare(strict_types=1);

/**
 * s3_client.php — S3 wrapper.
 *
 * Configuration now comes from Settings → Storage (DB), falling back to the
 * S3_* environment variables and finally to the built-in defaults, so an
 * instance can be moved from .env to the panel without touching code.
 *
 * Constructor overrides are supported, which is what the "Test connection"
 * button uses to validate values before saving them.
 */

require_once __DIR__ . '/lib/bootstrap.php';

class S3Client
{
    private Aws\S3\S3Client $s3;
    private string $bucket;
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
        $this->prefix = trim((string) ($overrides['prefix'] ?? settings_get('s3.prefix', 'uploads')), '/');
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

    /** True when bucket + credentials are present. */
    public function isConfigured(): bool
    {
        return $this->bucket !== ''
            && (string) $this->s3->getCredentials()->getAccessKeyId() !== ''
            && (string) $this->s3->getCredentials()->getSecretKey() !== '';
    }

    public function bucket(): string
    {
        return $this->bucket;
    }

    /** Prefix an object key with the configured prefix (no double prefixing). */
    public function objectKey(string $key): string
    {
        $key = ltrim($key, '/');
        if ($this->prefix === '' || str_starts_with($key, $this->prefix . '/')) {
            return $key;
        }
        return $this->prefix . '/' . $key;
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
        } catch (Aws\Exception\AwsException $e) {
            return [
                'success' => false,
                'code' => $e->getStatusCode() ?? 0,
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
        } catch (Aws\Exception\AwsException $e) {
            error_log($e->getMessage());
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
        } catch (Aws\Exception\AwsException $e) {
            error_log($e->getMessage());
            return '';
        }
    }
}

