<?php

namespace App\Services\Agents;

use Illuminate\Support\Str;
use RuntimeException;

class AgentState
{
    private mixed $lock = null;

    /** @var array<string, mixed> */
    private array $data = [];

    private string $directory;

    public function open(): void
    {
        if (is_resource($this->lock)) {
            throw new RuntimeException('Agent state is already open.');
        }
        $this->directory = (string) config('volumevault.agents.client.state_directory', storage_path('app/agent'));
        if (is_link($this->directory) || (! is_dir($this->directory) && ! @mkdir($this->directory, 0700, true)) || ! @chmod($this->directory, 0700)) {
            throw new RuntimeException('Unable to secure agent state directory.');
        }
        foreach (['agent.lock', 'state.json', 'server-ca.pem'] as $file) {
            if (is_link($this->directory.'/'.$file)) {
                throw new RuntimeException('Unsafe agent state file.');
            }
        }
        $this->lock = @fopen($this->directory.'/agent.lock', 'c');
        if (! is_resource($this->lock) || ! @chmod($this->directory.'/agent.lock', 0600) || ! flock($this->lock, LOCK_EX | LOCK_NB)) {
            $this->close();
            throw new RuntimeException('Agent state is in use or inaccessible.');
        }
        try {
            $this->initialize();
        } catch (\Throwable) {
            $this->close();
            throw new RuntimeException('Agent configuration or persisted trust is invalid.');
        }
    }

    private function initialize(): void
    {
        $origin = (string) config('volumevault.agents.client.url');
        $parts = parse_url($origin);
        if (! is_array($parts) || ($parts['scheme'] ?? '') !== 'https' || empty($parts['host']) || ! filter_var($origin, FILTER_VALIDATE_URL) || array_diff(array_keys($parts), ['scheme', 'host', 'port', 'path']) !== [] || ! in_array($parts['path'] ?? '', ['', '/'], true) || preg_match('/[\s\\\\]/', $origin)) {
            throw new RuntimeException('Invalid origin.');
        }
        $origin = rtrim($origin, '/');
        $pem = base64_decode((string) config('volumevault.agents.client.ca_certificate'), true);
        $certificate = $pem === false ? false : @openssl_x509_read($pem);
        $parsed = $certificate === false ? false : openssl_x509_parse($certificate);
        if (! is_array($parsed) || ($parsed['validFrom_time_t'] ?? PHP_INT_MAX) > time() || ($parsed['validTo_time_t'] ?? 0) <= time() || ! str_contains($parsed['extensions']['basicConstraints'] ?? '', 'CA:TRUE') || (isset($parsed['extensions']['keyUsage']) && ! str_contains($parsed['extensions']['keyUsage'], 'Certificate Sign'))) {
            throw new RuntimeException('Invalid CA.');
        }
        openssl_x509_export($certificate, $canonicalPem);
        if (trim($pem) !== trim($canonicalPem)) {
            throw new RuntimeException('Expected a single public CA certificate.');
        }
        $fingerprint = hash('sha256', $canonicalPem);
        $token = (string) config('volumevault.agents.client.enrollment_token');
        $host = null;
        if ($token !== '') {
            $pieces = explode('.', $token);
            if (count($pieces) !== 2 || ! Str::isUuid($pieces[0]) || ! preg_match('/\A[0-9a-f]{64}\z/i', $pieces[1])) {
                throw new RuntimeException('Invalid enrollment token.');
            }
            $host = $pieces[0];
        }
        $path = $this->directory.'/state.json';
        if (file_exists($path)) {
            $this->data = json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
            if (($this->data['origin'] ?? null) !== $origin || ($this->data['ca_fingerprint'] ?? null) !== $fingerprint || ($host !== null && ($this->data['host_uuid'] ?? null) !== $host) || ! is_file($this->caPath()) || file_get_contents($this->caPath()) !== $canonicalPem) {
                throw new RuntimeException('Trust changed.');
            }
            if (! Str::isUuid($this->data['instance_id'] ?? '') || ! Str::isUuid($this->data['host_uuid'] ?? '') || ! preg_match('/\A[0-9a-f]{64}\z/', $this->data['credential'] ?? '') || ! is_int($this->data['sequence'] ?? null) || $this->data['sequence'] < 0 || ! is_bool($this->data['enrolled'] ?? null)) {
                throw new RuntimeException('Invalid state.');
            }
            if ($token !== '' && ! hash_equals($this->data['enrollment_fingerprint'], hash('sha256', $token))) {
                $this->newIdentity($token);
            }
            if (! @chmod($path, 0600) || ! @chmod($this->caPath(), 0600)) {
                throw new RuntimeException('Unable to secure state.');
            }
        } else {
            if ($host === null) {
                throw new RuntimeException('Enrollment required.');
            }
            if (file_exists($this->caPath()) && file_get_contents($this->caPath()) !== $canonicalPem) {
                throw new RuntimeException('Trust changed.');
            }
            $this->atomicWrite($this->caPath(), $canonicalPem);
            $this->data = ['origin' => $origin, 'ca_fingerprint' => $fingerprint, 'host_uuid' => $host];
            $this->newIdentity($token);
        }
    }

    private function newIdentity(string $token): void
    {
        $this->data = array_merge($this->data, [
            'instance_id' => (string) Str::uuid(), 'credential' => bin2hex(random_bytes(32)),
            'enrollment_token' => $token, 'enrollment_fingerprint' => hash('sha256', $token),
            'enrolled' => false, 'sequence' => 0,
        ]);
        $this->save();
    }

    /**
     * @return array{origin: string, ca_fingerprint: string, host_uuid: string, instance_id: string, credential: string, enrollment_fingerprint: string, enrollment_token?: string, enrolled: bool, sequence: int}
     */
    public function identity(): array
    {
        if (! is_resource($this->lock)) {
            throw new AgentStateException('Agent state is not open; restart required.');
        }

        return $this->data;
    }

    public function caPath(): string
    {
        return $this->directory.'/server-ca.pem';
    }

    public function markEnrolled(): void
    {
        $this->identity();
        $this->data['enrolled'] = true;
        unset($this->data['enrollment_token']);
        $this->save();
    }

    public function nextSequence(): int
    {
        $this->identity();
        if ($this->data['sequence'] >= PHP_INT_MAX) {
            throw new AgentStateException('Agent inventory sequence exhausted.');
        }
        $this->data['sequence']++;
        $this->save();

        return $this->data['sequence'];
    }

    private function save(): void
    {
        try {
            $this->atomicWrite($this->directory.'/state.json', json_encode($this->data, JSON_THROW_ON_ERROR));
        } catch (\Throwable) {
            $this->close();
            throw new AgentStateException('Unable to persist agent state; restart required.');
        }
    }

    private function atomicWrite(string $path, string $contents): void
    {
        $temporary = $path.'.'.bin2hex(random_bytes(8)).'.tmp';
        $oldMask = umask(0077);
        $stream = @fopen($temporary, 'x');
        umask($oldMask);
        try {
            if (! is_resource($stream) || ! @chmod($temporary, 0600) || fwrite($stream, $contents) !== strlen($contents) || ! fflush($stream) || ! fsync($stream) || ! @rename($temporary, $path)) {
                throw new RuntimeException('Unable to persist agent state.');
            }
            $directory = @fopen(dirname($path), 'r');
            try {
                if (! is_resource($directory) || ! fsync($directory)) {
                    throw new RuntimeException('Unable to persist agent state directory.');
                }
            } finally {
                if (is_resource($directory)) {
                    fclose($directory);
                }
            }
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
            if (file_exists($temporary)) {
                @unlink($temporary);
            }
        }
    }

    public function close(): void
    {
        if (is_resource($this->lock)) {
            flock($this->lock, LOCK_UN);
            fclose($this->lock);
        }
        $this->lock = null;
        $this->data = [];
    }

    public function __destruct()
    {
        $this->close();
    }
}
