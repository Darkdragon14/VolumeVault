<?php

namespace App\Services\InstallationSaves;

use Illuminate\Encryption\Encrypter;
use RuntimeException;

class SecureSaveCrypto
{
    public const FORMAT = 'volumevault-secure-save';

    public const VERSION = 1;

    private const CIPHER = 'aes-256-gcm';

    private const KDF_CONTEXT = 'volumevault secure installation save v1';

    public function encrypt(string $payload, string $appKey): string
    {
        $salt = random_bytes(32);
        $nonce = random_bytes(12);
        $tag = '';
        $ciphertext = openssl_encrypt(
            $payload,
            self::CIPHER,
            $this->deriveKey($appKey, $salt),
            OPENSSL_RAW_DATA,
            $nonce,
            $tag,
        );

        if ($ciphertext === false) {
            throw new RuntimeException('Unable to encrypt the installation save.');
        }

        $encoded = json_encode([
            'format' => self::FORMAT,
            'version' => self::VERSION,
            'cipher' => self::CIPHER,
            'kdf' => 'hkdf-sha256',
            'salt' => base64_encode($salt),
            'nonce' => base64_encode($nonce),
            'tag' => base64_encode($tag),
            'payload' => base64_encode($ciphertext),
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        return $encoded;
    }

    public function decrypt(string $save, string $appKey): string
    {
        $data = json_decode($save, true, flags: JSON_THROW_ON_ERROR);

        if (($data['format'] ?? null) !== self::FORMAT || ($data['version'] ?? null) !== self::VERSION) {
            throw new RuntimeException('Unsupported installation save format.');
        }

        if (($data['cipher'] ?? null) !== self::CIPHER || ($data['kdf'] ?? null) !== 'hkdf-sha256') {
            throw new RuntimeException('Unsupported installation save encryption.');
        }

        $salt = $this->decodeBase64($data['salt'] ?? '', 'salt');
        $nonce = $this->decodeBase64($data['nonce'] ?? '', 'nonce');
        $tag = $this->decodeBase64($data['tag'] ?? '', 'tag');
        $payload = $this->decodeBase64($data['payload'] ?? '', 'payload');

        $decrypted = openssl_decrypt(
            $payload,
            self::CIPHER,
            $this->deriveKey($appKey, $salt),
            OPENSSL_RAW_DATA,
            $nonce,
            $tag,
        );

        if ($decrypted === false) {
            throw new RuntimeException('Unable to decrypt the installation save. Check the previous APP_KEY.');
        }

        return $decrypted;
    }

    public function makeLaravelEncrypter(string $appKey): Encrypter
    {
        return new Encrypter($this->parseAppKey($appKey), config('app.cipher'));
    }

    public function parseAppKey(string $appKey): string
    {
        $appKey = trim($appKey);

        if ($appKey === '') {
            throw new RuntimeException('APP_KEY is required.');
        }

        if (str_starts_with($appKey, 'base64:')) {
            $decoded = base64_decode(substr($appKey, 7), true);

            if ($decoded === false) {
                throw new RuntimeException('APP_KEY is not valid base64.');
            }

            return $decoded;
        }

        return $appKey;
    }

    private function deriveKey(string $appKey, string $salt): string
    {
        $key = hash_hkdf('sha256', $this->parseAppKey($appKey), 32, self::KDF_CONTEXT, $salt);

        if ($key === false) {
            throw new RuntimeException('Unable to derive the installation save key.');
        }

        return $key;
    }

    private function decodeBase64(mixed $value, string $field): string
    {
        if (! is_string($value) || $value === '') {
            throw new RuntimeException('Invalid installation save '.$field.'.');
        }

        $decoded = base64_decode($value, true);

        if ($decoded === false) {
            throw new RuntimeException('Invalid installation save '.$field.'.');
        }

        return $decoded;
    }
}
