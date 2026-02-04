<?php
// s3_client.php

require_once 'config.php';

class S3Client
{
    private $s3;
    private $bucket;

    public function __construct()
    {
        $this->bucket = S3_BUCKET;

        $this->s3 = new Aws\S3\S3Client([
            'version' => 'latest',
            'region' => S3_REGION,
            'endpoint' => S3_ENDPOINT,
            'use_path_style_endpoint' => true,
            'credentials' => [
                'key' => S3_ACCESS_KEY,
                'secret' => S3_SECRET_KEY,
            ],
        ]);
    }

    public function uploadFile($filePath, $key, $contentType = 'application/octet-stream')
    {
        try {
            $result = $this->s3->putObject([
                'Bucket' => $this->bucket,
                'Key' => ltrim($key, '/'),
                'SourceFile' => $filePath,
                'ContentType' => $contentType,
            ]);
            return ['success' => true, 'code' => 200, 'message' => 'OK', 'url' => $result['ObjectURL']];
        } catch (Aws\Exception\AwsException $e) {
            return [
                'success' => false,
                'code' => $e->getStatusCode(),
                'message' => $e->getMessage()
            ];
        }
    }

    public function deleteFile($key)
    {
        try {
            $this->s3->deleteObject([
                'Bucket' => $this->bucket,
                'Key' => ltrim($key, '/'),
            ]);
            return true;
        } catch (Aws\Exception\AwsException $e) {
            error_log($e->getMessage());
            return false;
        }
    }

    public function getPresignedUrl($key, $expiresIn = 3600)
    {
        try {
            $cmd = $this->s3->getCommand('GetObject', [
                'Bucket' => $this->bucket,
                'Key' => ltrim($key, '/'),
            ]);
            $request = $this->s3->createPresignedRequest($cmd, "+{$expiresIn} seconds");
            return (string) $request->getUri();
        } catch (Aws\Exception\AwsException $e) {
            error_log($e->getMessage());
            return '';
        }
    }
}
