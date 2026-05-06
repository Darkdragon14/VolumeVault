<?php

namespace App\Actions\Docker;

use App\Services\Docker\DockerProcess;
use RuntimeException;

class CreateDockerVolume
{
    public function __construct(private readonly DockerProcess $dockerProcess) {}

    public function handle(string $volumeName): void
    {
        $result = $this->dockerProcess->run(['docker', 'volume', 'create', $volumeName], 60);

        if (! $result->successful()) {
            throw new RuntimeException($result->combinedOutput() ?: "Unable to create Docker volume {$volumeName}.");
        }
    }
}
