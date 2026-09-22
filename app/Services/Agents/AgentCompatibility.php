<?php

namespace App\Services\Agents;

use App\Models\DockerHost;

class AgentCompatibility
{
    public const PROTOCOL_VERSION = 1;

    public const CAPABILITIES = ['inventory-v1', 'maintenance-v1', 'backup-v1', 'restore-v1', 'docker-labels-v1', 'destination-v1', 'archive-relay-v1'];

    public function status(DockerHost $host): string
    {
        if ($host->agent_protocol_version === null || $host->agent_capabilities === null) {
            return 'unknown';
        }

        return $host->agent_protocol_version === self::PROTOCOL_VERSION
            && in_array('inventory-v1', $host->agent_capabilities, true) ? 'compatible' : 'incompatible';
    }

    public function targetVersion(): ?string
    {
        $version = (string) config('app.version');

        return preg_match('/\Av?\d+\.\d+\.\d+(?:-[0-9A-Za-z.-]+)?\z/', $version) ? $version : null;
    }

    public function updateStatus(?string $installed): string
    {
        $target = $this->targetVersion();
        if ($target === null || $installed === null || ! preg_match('/\Av?\d+\.\d+\.\d+(?:-[0-9A-Za-z.-]+)?\z/', $installed)) {
            return 'unknown';
        }

        return match (version_compare(ltrim($installed, 'v'), ltrim($target, 'v'))) {
            -1 => 'available', 0 => 'current', 1 => 'ahead',
        };
    }
}
