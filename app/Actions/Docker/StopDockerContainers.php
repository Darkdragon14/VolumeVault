<?php

namespace App\Actions\Docker;

use App\Services\Docker\DockerProcess;
use RuntimeException;

class StopDockerContainers
{
    public function __construct(private readonly DockerProcess $dockerProcess) {}

    /**
     * @param  callable|null  $afterEach  Invoked after each container is stopped.
     *                                    Lets a caller refresh a liveness signal (e.g. a restore's heartbeat) so a
     *                                    long multi-container stop is not mistaken for a dead worker — each
     *                                    `docker stop` is capped at 120s, so the signal advances at least that often.
     */
    public function handle(array $containerIds, ?callable $afterEach = null): void
    {
        foreach ($containerIds as $containerId) {
            if (! filled($containerId)) {
                continue;
            }

            $result = $this->dockerProcess->run(['docker', 'stop', $containerId], 120);

            if (! $result->successful()) {
                throw new RuntimeException($result->combinedOutput() ?: "Unable to stop container {$containerId}.");
            }

            if ($afterEach) {
                $afterEach($containerId);
            }
        }
    }
}
