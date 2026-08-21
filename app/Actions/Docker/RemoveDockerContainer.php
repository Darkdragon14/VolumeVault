<?php

namespace App\Actions\Docker;

use App\Services\Docker\DockerProcess;
use RuntimeException;

class RemoveDockerContainer
{
    public function __construct(private readonly DockerProcess $dockerProcess) {}

    public function handle(string $containerReference): void
    {
        $result = $this->dockerProcess->run(['docker', 'rm', '--force', $containerReference], 60);

        if ($result->successful()) {
            return;
        }

        $error = strtolower($result->combinedOutput());

        if (str_contains($error, 'no such container') || str_contains($error, 'no such object')) {
            return;
        }

        throw new RuntimeException($result->combinedOutput() ?: "Unable to remove container {$containerReference}.");
    }
}
