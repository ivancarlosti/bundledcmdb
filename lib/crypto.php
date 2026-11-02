<?php
declare(strict_types=1);

/**
 * lib/crypto.php — encryption for secrets stored in app_settings (S3 secret
 * key, and any future credential).
 *
 * AES-256-GCM with a key taken from the APP_ENCRYPTION_KEY environment
 * variable (base64, hex or 32 raw bytes).
 *
 * Stored formats:
 *   enc:v1:<base64(iv | tag | ciphertext)>   key available
 *   plain:<value>                            no key configured (degraded)
 *
 * When no key is configured the settings page shows a warning banner; values
 * are still readable so an instance never bricks itself by upgrading.
 */

const CRYPTO_ENC_PREFIX   = 'enc:v1:';
const CRYPTO_PLAIN_PREFIX = 'plain:';

/**
 * Raw 32-byte key, or null when APP_ENCRYPTION_KEY is missing/invalid.
 */
function crypto_key(): ?string
{
    static $cached = false;
    if ($cached !== false) {
        return $cached;
    }

    $raw = defined('APP_ENCRYPTION_KEY') ? trim((string) APP_ENCRYPTION_KEY) : '';
    if ($raw === '') {
        return $cached = null;
    }
    if (preg_match('/^[0-9a-fA-F]{64}$/', $raw) === 1) {
        return $cached = (string) hex2bin($raw);
    }
    $decoded = base64_decode($raw, true);
    if ($decoded !== false && strlen($decoded) === 32) {
        return $cached = $decoded;
    }
    if (strlen($raw) === 32) {
        return $cached = $raw;
    }

    return $cached = null;
}

function crypto_enabled(): bool
{
    return crypto_key() !== null;
}

function crypto_is_encrypted(string $value): bool
{
    return str_starts_with($value, CRYPTO_ENC_PREFIX);
}

/**
 * Encrypt a secret for storage. Falls back to `plain:` without a key.
 */
function crypto_encrypt(string $plain): string
{
    $key = crypto_key();
    if ($key === null) {
        return CRYPTO_PLAIN_PREFIX . $plain;
    }

    $iv  = random_bytes(12);
    $tag = '';
    $ct  = openssl_encrypt($plain, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
    if ($ct === false) {
        throw new RuntimeException('Encryption failed');
    }

    return CRYPTO_ENC_PREFIX . base64_encode($iv . $tag . $ct);
}

/**
 * Decrypt a stored value. Returns null when the value is encrypted but the key
 * is missing or the payload is corrupt.
 */
function crypto_decrypt(string $stored): ?string
{
    if (str_starts_with($stored, CRYPTO_PLAIN_PREFIX)) {
        return substr($stored, strlen(CRYPTO_PLAIN_PREFIX));
    }
    if (!crypto_is_encrypted($stored)) {
        return $stored; // legacy plaintext row
    }

    $key = crypto_key();
    if ($key === null) {
        return null;
    }
    $payload = base64_decode(substr($stored, strlen(CRYPTO_ENC_PREFIX)), true);
    if ($payload === false || strlen($payload) < 29) {
        return null;
    }
    $iv  = substr($payload, 0, 12);
    $tag = substr($payload, 12, 16);
    $ct  = substr($payload, 28);
    $pt  = openssl_decrypt($ct, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);

    return $pt === false ? null : $pt;
}

/**
 * Human-readable mask for a secret ("••••••••" / "not set").
 */
function crypto_mask(?string $value): string
{
    if ($value === null || $value === '') {
        return '—';
    }
    $len = mb_strlen($value);
    return str_repeat('•', 8) . ($len > 4 ? ' (' . $len . ')' : '');
}
