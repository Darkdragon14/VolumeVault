<?php

namespace App\Actions\Docker;

use App\Services\Docker\DockerProcess;
use RuntimeException;

class StartDockerContainers
{
    public function __construct(private readonly DockerProcess $dockerProcess) {}

    public function handle(array $containerIds): void
    {
        foreach ($containerIds as $containerId) {
            if (! filled($containerId)) {
                continue;
            }

            $result = $this->dockerProcess->run(['docker', 'start', $containerId], 120);

            if (! $result->successful()) {
                throw new RuntimeException($result->combinedOutput() ?: "Unable to start container {$containerId}.");
            }
        }
    }
}
