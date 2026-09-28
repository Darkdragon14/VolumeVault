<?php

namespace App\Services\Agents;

use App\Models\ActivityLog;
use App\Models\DockerHost;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class AgentRegistry
{
    public function __construct(private readonly AgentTlsIdentity $tls) {}

    /** @return array{command: string, expires_at: string} */
    public function issueEnrollment(DockerHost $host): array
    {
        abort_unless(config('volumevault.agents.enabled'), 409, 'Agent transport is disabled.');
        abort_if($host->isLocal(), 422, 'The local host does not use an agent.');
        $ca = base64_encode($this->tls->caCertificate());
        $secret = bin2hex(random_bytes(32));
        $expiresAt = now()->addMinutes(15);

        DB::transaction(function () use ($host, $secret, $expiresAt): void {
            $locked = DockerHost::query()->lockForUpdate()->findOrFail($host->id);
            abort_unless($locked->driver === DockerHost::DRIVER_AGENT, 422);
            $locked->forceFill([
                'agent_enrollment_hash' => hash('sha256', $secret),
                'agent_enrollment_expires_at' => $expiresAt,
                'agent_token_hash' => null,
                'agent_instance_id' => null,
                'agent_registered_at' => null,
                'agent_revoked_at' => null,
                'last_seen_at' => null,
                'docker_status' => null,
                'agent_inventory_sequence' => 0,
                'agent_protocol_version' => null,
                'agent_capabilities' => null,
                'agent_maintenance_token' => null,
                'agent_active_operations' => null,
            ])->save();
            ActivityLog::record('agent_enrollment_issued', 'Agent enrollment issued.', $locked);
        }, attempts: 3);

        $arguments = [
            'docker', 'run', '-d', '--name', 'volumevault-agent-'.substr($host->uuid, 0, 8),
            '--restart', 'unless-stopped',
            '--no-healthcheck',
            '-v', '/var/run/docker.sock:/var/run/docker.sock',
            '-v', 'volumevault-agent-'.$host->uuid.':/app/storage',
            '-e', 'VOLUMEVAULT_ORCHESTRATOR_URL='.config('volumevault.agents.url'),
            '-e', 'VOLUMEVAULT_AGENT_ENROLLMENT_TOKEN='.$host->uuid.'.'.$secret,
            '-e', 'VOLUMEVAULT_AGENT_CA='.$ca,
            (string) config('volumevault.agents.image'), 'php', 'artisan', 'volumevault:agent',
        ];

        return [
            'command' => implode(' ', array_map(escapeshellarg(...), $arguments)),
            'expires_at' => $expiresAt->toIso8601String(),
        ];
    }

    /** @param array{instance_id: string, credential: string, version: string, protocol_version: int} $data */
    public function enroll(string $token, array $data): DockerHost
    {
        [$uuid, $secret] = $this->splitToken($token);

        return DB::transaction(function () use ($uuid, $secret, $data): DockerHost {
            $host = DockerHost::query()->where('uuid', $uuid)->lockForUpdate()->first();
            abort_unless($host?->driver === DockerHost::DRIVER_AGENT
                && $host->agent_revoked_at === null
                && hash_equals((string) $host->agent_enrollment_hash, hash('sha256', $secret)), 401, 'Invalid agent enrollment.');

            $credentialHash = hash('sha256', $data['credential']);
            if ($host->agent_registered_at !== null) {
                abort_unless($host->agent_instance_id === $data['instance_id']
                    && hash_equals((string) $host->agent_token_hash, $credentialHash), 401, 'Enrollment has already been used.');

                return $host;
            }

            abort_unless($host->agent_enrollment_expires_at?->isFuture(), 401, 'Agent enrollment expired.');
            $host->forceFill([
                'agent_token_hash' => $credentialHash,
                'agent_instance_id' => $data['instance_id'],
                'agent_registered_at' => now(),
                'agent_version' => $data['version'],
                'agent_protocol_version' => $data['protocol_version'],
                'last_seen_at' => now(),
            ])->save();
            ActivityLog::record('agent_registered', 'Agent registered.', $host);

            return $host;
        });
    }

    public function authenticate(string $token): DockerHost
    {
        [$uuid, $secret] = $this->splitToken($token);
        $host = DockerHost::where('uuid', $uuid)->first();
        $this->assertCredential($host, hash('sha256', $secret));

        return $host;
    }

    public function assertCredential(?DockerHost $host, string $credentialHash, ?string $instanceId = null): void
    {
        abort_unless($host?->driver === DockerHost::DRIVER_AGENT && $host->agent_registered_at !== null
            && $host->agent_revoked_at === null && $host->agent_token_hash !== null
            && hash_equals($host->agent_token_hash, $credentialHash)
            && ($instanceId === null || $host->agent_instance_id === $instanceId), 401, 'Invalid agent credentials.');
    }

    /** @param array{instance_id: string, version: string, docker_status: string} $data */
    public function heartbeat(DockerHost $host, array $data): ?string
    {
        return DB::transaction(function () use ($host, $data): ?string {
            $locked = DockerHost::query()->lockForUpdate()->find($host->id);
            $this->assertCredential($locked, $host->agent_token_hash, $data['instance_id']);
            $locked->forceFill([
                'last_seen_at' => now(), 'agent_version' => $data['version'], 'docker_status' => $data['docker_status'],
                'agent_protocol_version' => $data['protocol_version'],
                'agent_capabilities' => $data['capabilities'],
                'agent_maintenance_token' => $data['maintenance_token'] ?? null,
                'agent_active_operations' => $data['active_operations'] ?? null,
            ])->save();

            return $locked->maintenance_token;
        });
    }

    public function recordIncompatibleProtocol(DockerHost $host, mixed $protocol, mixed $version): void
    {
        DB::transaction(function () use ($host, $protocol, $version): void {
            $locked = DockerHost::query()->lockForUpdate()->find($host->id);
            $this->assertCredential($locked, $host->agent_token_hash, $host->agent_instance_id);
            $locked->forceFill([
                'agent_protocol_version' => is_int($protocol) && $protocol > 0 && $protocol <= 65535 ? $protocol : null,
                'agent_version' => is_string($version) && strlen($version) <= 100 ? $version : $locked->agent_version,
                'last_seen_at' => now(),
                'agent_maintenance_token' => null,
            ])->save();
        });
    }

    public function revoke(DockerHost $host): void
    {
        abort_if($host->isLocal(), 422, 'The local host does not use an agent.');
        DB::transaction(function () use ($host): void {
            $locked = DockerHost::query()->lockForUpdate()->findOrFail($host->id);
            $locked->forceFill([
                'agent_token_hash' => null, 'agent_enrollment_hash' => null,
                'agent_enrollment_expires_at' => null, 'agent_revoked_at' => now(),
            ])->save();
            ActivityLog::record('agent_revoked', 'Agent access revoked.', $locked);
        });
    }

    /** @return array{string, string} */
    private function splitToken(string $token): array
    {
        $parts = explode('.', $token);
        abort_unless(count($parts) === 2 && Str::isUuid($parts[0]) && preg_match('/\A[a-f0-9]{64}\z/', $parts[1]), 401, 'Invalid agent credentials.');

        return $parts;
    }
}
