<?php

namespace App\Actions\Docker;

use App\Services\Docker\DockerProcess;
use RuntimeException;

class RemoveDockerVolume
{
    public function __construct(private readonly DockerProcess $dockerProcess) {}

    public function handle(string $volumeName): void
    {
        $result = $this->dockerProcess->run(['docker', 'volume', 'rm', $volumeName], 60);

        if (! $result->successful()) {
            throw new RuntimeException($result->combinedOutput() ?: "Unable to remove Docker volume {$volumeName}.");
        }
    }
}
