<?php

namespace App\Actions\Docker;

use App\Services\Docker\DockerProcess;
use RuntimeException;

class StartDockerContainers
{
    public function __construct(private readonly DockerProcess $dockerProcess) {}

    /**
     * Start the given containers. $afterEach, when provided, is invoked after each
     * successful `docker start`; a group member run uses it to refresh its group
     * run heartbeat, so a slow multi-container restart (docker start is 120s each,
     * sequential) never lets the live group run be reconciled as stale.
     */
    public function handle(array $containerIds, ?callable $afterEach = null): void
    {
        foreach ($containerIds as $containerId) {
            if (! filled($containerId)) {
                continue;
            }

            $result = $this->dockerProcess->run(['docker', 'start', $containerId], 120);

            if (! $result->successful()) {
                throw new RuntimeException($result->combinedOutput() ?: "Unable to start container {$containerId}.");
            }

            if ($afterEach !== null) {
                $afterEach();
            }
        }
    }
}
