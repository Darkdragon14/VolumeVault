<?php

namespace App\Services\Agents;

use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Str;
use RuntimeException;

class AgentOperationEnvelope
{
    /** Destination credentials never appear as plaintext fields in an API response. */
    public function seal(array $operation, string $agentCredential): array
    {
        return [
            'version' => 1,
            'ciphertext' => $this->cipher($agentCredential)->encryptString(json_encode($operation, JSON_THROW_ON_ERROR)),
        ];
    }

    public function open(array $envelope, string $agentCredential): array
    {
        try {
            if (($envelope['version'] ?? null) !== 1 || ! is_string($envelope['ciphertext'] ?? null)) {
                throw new RuntimeException;
            }
            $operation = json_decode($this->cipher($agentCredential)->decryptString($envelope['ciphertext']), true, flags: JSON_THROW_ON_ERROR);
            if (! is_array($operation)) {
                throw new RuntimeException;
            }

            return $operation;
        } catch (\Throwable) {
            throw new RuntimeException('Agent operation envelope could not be authenticated.');
        }
    }

    private function cipher(string $credential): Encrypter
    {
        [$host, $secret] = array_pad(explode('.', $credential, 2), 2, '');
        if (! Str::isUuid($host) || ! preg_match('/\A[a-f0-9]{64}\z/', $secret)) {
            throw new RuntimeException('Invalid agent credential.');
        }

        return new Encrypter(hash_hkdf('sha256', hex2bin($secret), 32, 'VolumeVault agent operations v1', $host), 'AES-256-CBC');
    }
}
