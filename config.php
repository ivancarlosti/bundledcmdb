<?php

// --- MariaDB Settings ---
require_once __DIR__ . '/vendor/autoload.php';

define('DB_HOST', getenv('DB_SERVER') ?: '127.0.0.1');
define('DB_NAME', getenv('DB_NAME') ?: 'YOURDBNAME');
define('DB_USER', getenv('DB_USERNAME') ?: 'YOURDBUSERNAME');
define('DB_PASS', getenv('DB_PASSWORD') ?: 'YOURDBPASSWORD');

// --- S3 Settings ---
define('S3_REGION', getenv('S3_REGION') ?: 'YOURS3REGION');
define('S3_BUCKET', getenv('S3_BUCKET') ?: 'YOURS3BUCKET');
define('S3_ACCESS_KEY', getenv('S3_ACCESS_KEY') ?: 'YOURS3ACCESSKEY');
define('S3_SECRET_KEY', getenv('S3_SECRET_KEY') ?: 'YOURS3SECRETKEY');
define('S3_ENDPOINT', getenv('S3_ENDPOINT') ?: 'https://s3.YOURS3REGION.amazonaws.com'); // Change if using MinIO or other provider

// --- Keycloak Settings ---
define('KEYCLOAK_BASE_URL', getenv('KEYCLOAK_BASE_URL') ?: 'https://sso.example.com'); // Replace with your Keycloak URL
define('KEYCLOAK_REALM', getenv('KEYCLOAK_REALM') ?: 'YOURSSORealm'); // Replace with your Realm
define('KEYCLOAK_CLIENT_ID', getenv('KEYCLOAK_CLIENT_ID') ?: 'bundledcmdb'); // Replace with your Client ID
define('KEYCLOAK_CLIENT_SECRET', getenv('KEYCLOAK_CLIENT_SECRET') ?: 'YOURKEYCLOAKCLIENTSECRET'); // Replace with your Client Secret
define('KEYCLOAK_REDIRECT_URI', getenv('KEYCLOAK_REDIRECT_URI') ?: 'https://bundledcmdb.example.com/index.php'); // Adjust based on deployment
