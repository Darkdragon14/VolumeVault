<?php

namespace App\Services\Agents;

use App\Models\DockerHost;
use App\Services\BackupSources\HostPathPolicy;
use App\Services\Docker\LocalDockerExecution;
use App\Support\DeploymentMode;
use Illuminate\Validation\ValidationException;

class AgentExecution
{
    public function summary(DockerHost $host, bool $includePaths = false): array
    {
        $data = [
            ...$host->only(['id', 'uuid', 'name', 'driver', 'agent_protocol_version', 'agent_capabilities', 'agent_registered_at', 'agent_revoked_at', 'maintenance_requested_at']),
            'status' => $host->agentStatus(),
            'is_local' => $host->isLocal(),
            'maintenance_requested' => $host->maintenance_requested_at !== null,
        ];
        if ($includePaths) {
            $data['host_path_allowlist'] = $host->isLocal() ? app(HostPathPolicy::class)->allowedPrefixes() : ($host->agent_host_path_allowlist ?? []);
        }

        return $data;
    }

    public function validateHost(int $id, string $capability): void
    {
        if ($id === DockerHost::LOCAL_ID) {
            LocalDockerExecution::validate();

            return;
        }
        $host = DockerHost::find($id);
        if (! $this->supportsHost($host, $capability)) {
            throw ValidationException::withMessages(['docker_host_id' => 'Select a registered agent supporting this operation.']);
        }
    }

    public function supportsHost(?DockerHost $host, string $capability): bool
    {
        if ($host?->isLocal()) {
            return DeploymentMode::localExecutionEnabled();
        }

        return $host !== null && $host->driver === DockerHost::DRIVER_AGENT
            && $host->agent_revoked_at === null && $host->agent_registered_at !== null
            && app(AgentCompatibility::class)->status($host) === 'compatible'
            && in_array($capability, $host->agent_capabilities ?? [], true);
    }
}
