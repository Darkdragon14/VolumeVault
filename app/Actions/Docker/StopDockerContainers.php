<?php

namespace App\Actions\Docker;

use App\Services\Docker\DockerProcess;
use RuntimeException;

class StopDockerContainers
{
    public function __construct(private readonly DockerProcess $dockerProcess) {}

    public function handle(array $containerIds): void
    {
        foreach ($containerIds as $containerId) {
            if (! filled($containerId)) {
                continue;
            }

            $result = $this->dockerProcess->run(['docker', 'stop', $containerId], 120);

            if (! $result->successful()) {
                throw new RuntimeException($result->combinedOutput() ?: "Unable to stop container {$containerId}.");
            }
        }
    }
}
