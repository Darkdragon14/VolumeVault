<?php

namespace App\Actions\Docker;

use App\Services\Docker\DockerProcess;
use RuntimeException;

class InspectDockerVolume
{
    public function __construct(private readonly DockerProcess $dockerProcess) {}

    public function handle(string $volumeName): array
    {
        $result = $this->dockerProcess->run(['docker', 'volume', 'inspect', $volumeName], 60);

        if (! $result->successful()) {
            throw new RuntimeException($result->combinedOutput() ?: "Docker volume not found: {$volumeName}");
        }

        $payload = json_decode($result->output, true);
        $volume = $payload[0] ?? null;

        if (! is_array($volume)) {
            throw new RuntimeException("Unable to parse docker volume inspect output for {$volumeName}.");
        }

        return [
            'name' => $volume['Name'] ?? $volumeName,
            'driver' => $volume['Driver'] ?? null,
            'mountpoint' => $volume['Mountpoint'] ?? null,
            'labels' => $volume['Labels'] ?? [],
            'options' => $volume['Options'] ?? [],
        ];
    }
}
