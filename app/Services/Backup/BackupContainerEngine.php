<?php

namespace App\Services\Backup;

use App\Services\Docker\DockerProcessResult;
use Closure;
use Throwable;

class BackupContainerEngine
{
    /**
     * Infrastructure is supplied by the caller so execution needs no Laravel bootstrapping.
     *
     * @param  Closure(array, int, array): DockerProcessResult  $run
     * @param  Closure(callable, callable): mixed  $monitor
     * @param  Closure(string): void  $removeContainer  Idempotent removal; throws on failure.
     */
    public function __construct(
        private readonly Closure $run,
        private readonly Closure $monitor,
        private readonly Closure $removeContainer,
    ) {}

    /**
     * Identity is published before issuing Docker commands. Cleanup status is
     * published after removal, even on execution failure, before caller-owned
     * secret cleanup. Observers must not throw from the cleanup callback.
     *
     * @param  callable(string): void  $containerIdentified
     * @param  callable(bool, ?Throwable): void  $cleanupFinished
     * @param  null|callable(): void  $heartbeat
     */
    public function handle(BackupContainerPlan $plan, callable $containerIdentified, callable $cleanupFinished, ?callable $heartbeat = null): DockerProcessResult
    {
        $creationIssued = false;

        try {
            $environment = array_merge($plan->environment, ['DOCKER_HOST' => $plan->dockerHost]);
            $command = $this->command($plan, $environment);
            $containerIdentified($plan->containerName);

            $execute = function () use ($plan, $command, $environment, &$creationIssued): DockerProcessResult {
                $creationIssued = true;

                if ($plan->copies === []) {
                    return ($this->run)($command, 0, $environment);
                }

                $result = ($this->run)($command, 300, $environment);

                if (! $result->successful()) {
                    return $result;
                }

                foreach ($plan->copies as $source => $destination) {
                    $result = ($this->run)(['docker', 'cp', $source, $plan->containerName.':'.$destination], 300, []);

                    if (! $result->successful()) {
                        return $result;
                    }
                }

                return ($this->run)(['docker', 'start', '--attach', $plan->containerName], 0, []);
            };

            return $heartbeat === null ? $execute() : ($this->monitor)($heartbeat, $execute);
        } finally {
            $cleanupError = null;

            if ($creationIssued) {
                try {
                    ($this->removeContainer)($plan->containerName);
                } catch (Throwable $exception) {
                    $cleanupError = $exception;
                }
            }

            $cleanupFinished($cleanupError === null, $cleanupError);
        }
    }

    /**
     * @param  array<string, string>  $environment
     * @return list<string>
     */
    private function command(BackupContainerPlan $plan, array $environment): array
    {
        $command = ['docker', $plan->copies === [] ? 'run' : 'create'];

        if ($plan->copies === []) {
            $command[] = '--rm';
        }

        array_push($command, '--name', $plan->containerName, '--entrypoint', '/usr/bin/backup');

        if (trim($plan->dockerNetwork) !== '') {
            array_push($command, '--network', trim($plan->dockerNetwork));
        }

        array_push($command, ...$plan->sourceMountArguments);

        if (str_starts_with($plan->dockerHost, 'unix://')) {
            $socketPath = substr($plan->dockerHost, strlen('unix://'));

            if ($socketPath !== '') {
                array_push($command, '-v', $socketPath.':'.$socketPath.':ro');
            }
        }

        foreach ($plan->mounts as $mount) {
            array_push($command, '-v', $mount);
        }

        foreach (array_keys($environment) as $key) {
            array_push($command, '--env', $key);
        }

        $command[] = $plan->image;

        return $command;
    }
}
