<?php

namespace App\Services\Agents;

use App\Models\DockerHost;
use App\Services\BackupSources\HostPathPolicy;
use App\Services\Docker\LocalDockerExecution;
use Illuminate\Validation\ValidationException;

class AgentExecution
{
    public function summary(DockerHost $host, bool $includePaths = false): array
    {
        $data = [
            ...$host->only(['id', 'uuid', 'name', 'driver', 'agent_protocol_version', 'agent_capabilities', 'agent_registered_at', 'agent_revoked_at', 'maintenance_requested_at']),
            'status' => $host->agentStatus(),
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
        if (! $host || $host->driver !== DockerHost::DRIVER_AGENT || $host->agent_revoked_at !== null
            || $host->agent_registered_at === null || app(AgentCompatibility::class)->status($host) !== 'compatible'
            || ! in_array($capability, $host->agent_capabilities ?? [], true)) {
            throw ValidationException::withMessages(['docker_host_id' => 'Select a registered agent supporting this operation.']);
        }
    }
}
