<?php

namespace App\Support;

class SshHostKey
{
    public static function matches(string $pinned, string $presented): bool
    {
        $presentedFingerprint = self::fingerprint($presented);

        if ($presentedFingerprint === '') {
            return false;
        }

        if (preg_match('/^sha256:/i', trim($pinned))) {
            return hash_equals($presentedFingerprint, self::normalizeFingerprint($pinned));
        }

        $pinnedFingerprint = self::fingerprint($pinned);

        return $pinnedFingerprint !== '' && hash_equals($presentedFingerprint, $pinnedFingerprint);
    }

    public static function fingerprint(string $key): string
    {
        if (preg_match('/^sha256:/i', trim($key))) {
            return self::normalizeFingerprint($key);
        }

        $blob = self::blob($key);

        return $blob === '' ? '' : 'SHA256:'.rtrim(base64_encode(hash('sha256', $blob, true)), '=');
    }

    private static function normalizeFingerprint(string $fingerprint): string
    {
        $digest = rtrim(trim(substr(trim($fingerprint), strlen('SHA256:'))), '=');

        return $digest === '' ? '' : 'SHA256:'.$digest;
    }

    private static function blob(string $key): string
    {
        $parts = preg_split('/\s+/', trim($key)) ?: [];
        $declaredAlgorithm = null;

        if (count($parts) === 1) {
            $encoded = $parts[0] ?? '';
        } elseif (preg_match('/^(?:ssh-|ecdsa-|sk-|rsa-sha2-)/', $parts[0] ?? '')) {
            $declaredAlgorithm = $parts[0];
            $encoded = $parts[1] ?? '';
        } else {
            return '';
        }

        $decoded = base64_decode($encoded, true);

        if ($decoded === false || strlen($decoded) < 5) {
            return '';
        }

        $length = unpack('Nlength', substr($decoded, 0, 4))['length'] ?? 0;
        $encodedAlgorithm = substr($decoded, 4, $length);

        if (
            $length < 1
            || 4 + $length > strlen($decoded)
            || preg_match('/^(?:ssh-|ecdsa-|sk-)/', $encodedAlgorithm) !== 1
            || ($declaredAlgorithm !== null && ! hash_equals(self::encodedAlgorithmFor($declaredAlgorithm), $encodedAlgorithm))
        ) {
            return '';
        }

        return $decoded;
    }

    private static function encodedAlgorithmFor(string $declaredAlgorithm): string
    {
        return in_array($declaredAlgorithm, ['rsa-sha2-256', 'rsa-sha2-512'], true)
            ? 'ssh-rsa'
            : $declaredAlgorithm;
    }
}
