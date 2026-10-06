<?php

declare(strict_types=1);

namespace App\Config;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Authenticated encryption (AES-256-GCM) of secret settings stored in the
 * database, with the dedicated APP_SETTINGS_KEY from .env: 32 random bytes,
 * Base64-encoded (generate with `openssl rand -base64 32`). The key is only
 * needed once a secret setting is stored; without stored secrets the
 * application runs without it.
 *
 * Stored format: enc:v1:<key id>:<base64(nonce | tag | ciphertext)>, where the
 * key id is the first 8 hex characters of SHA-256(key). Each encryption uses a
 * fresh random 96-bit nonce. Key rotation (not built yet) must decrypt every
 * stored secret with the old key and re-encrypt it with the new key before the
 * new key is deployed: changing APP_SETTINGS_KEY by itself makes the stored
 * secrets unreadable.
 */
final class SettingsCipher
{
    private const PREFIX = 'enc:v1:';
    private const NONCE_BYTES = 12;
    private const TAG_BYTES = 16;

    public function __construct(#[Autowire('%env(default::APP_SETTINGS_KEY)%')] private readonly ?string $encodedKey)
    {
    }

    /** Why the key cannot be used, or null when it is usable. */
    public function problem(): ?string
    {
        try {
            $this->key();
            return null;
        } catch (SettingsCipherException $e) {
            return $e->getMessage();
        }
    }

    public static function isEncrypted(string $stored): bool
    {
        return str_starts_with($stored, self::PREFIX);
    }

    /** @throws SettingsCipherException when APP_SETTINGS_KEY is unusable */
    public function encrypt(string $plaintext): string
    {
        $key = $this->key();
        $nonce = random_bytes(self::NONCE_BYTES);
        $tag = '';
        $ciphertext = openssl_encrypt($plaintext, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $nonce, $tag, '', self::TAG_BYTES);
        if ($ciphertext === false) {
            throw new SettingsCipherException('A setting could not be encrypted.');
        }
        return self::PREFIX . self::keyId($key) . ':' . base64_encode($nonce . $tag . $ciphertext);
    }

    /** @throws SettingsCipherException for an unusable key, another key's data, or damaged or tampered data */
    public function decrypt(string $stored): string
    {
        $key = $this->key();
        $parts = explode(':', $stored, 4);
        if (count($parts) !== 4 || $parts[0] . ':' . $parts[1] . ':' !== self::PREFIX) {
            throw new SettingsCipherException('The stored setting is not in a supported encrypted format.');
        }
        if (!hash_equals(self::keyId($key), $parts[2])) {
            throw new SettingsCipherException('The stored setting was encrypted with a different APP_SETTINGS_KEY.');
        }
        $raw = base64_decode($parts[3], true);
        if ($raw === false || strlen($raw) < self::NONCE_BYTES + self::TAG_BYTES) {
            throw new SettingsCipherException('The stored setting is damaged.');
        }
        $plaintext = openssl_decrypt(
            substr($raw, self::NONCE_BYTES + self::TAG_BYTES),
            'aes-256-gcm',
            $key,
            OPENSSL_RAW_DATA,
            substr($raw, 0, self::NONCE_BYTES),
            substr($raw, self::NONCE_BYTES, self::TAG_BYTES)
        );
        if ($plaintext === false) {
            throw new SettingsCipherException('The stored setting failed authentication (damaged, or encrypted with another key).');
        }
        return $plaintext;
    }

    private function key(): string
    {
        $encoded = trim((string) $this->encodedKey);
        if ($encoded === '') {
            throw new SettingsCipherException('APP_SETTINGS_KEY is not set. Add 32 random bytes, Base64-encoded, to .env (openssl rand -base64 32).');
        }
        $key = base64_decode($encoded, true);
        if ($key === false || strlen($key) !== 32) {
            throw new SettingsCipherException('APP_SETTINGS_KEY must be 32 random bytes, Base64-encoded (openssl rand -base64 32).');
        }
        return $key;
    }

    private static function keyId(string $key): string
    {
        return substr(hash('sha256', $key), 0, 8);
    }
}
