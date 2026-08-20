<?php

namespace App\Actions\Docker;

use App\Services\Docker\DockerProcess;
use App\Services\Docker\DockerProcessResult;
use RuntimeException;

/**
 * Empty a Docker volume before an in-place restore.
 *
 * A backup archive is extracted with `tar -xzf`, which only adds/overwrites
 * entries — files present in the volume but absent from the archive would
 * survive. To give an in-place restore true "the volume matches the backup"
 * semantics, the volume contents are deleted first via a throwaway container
 * mounting the volume (the host can't reach a named volume directly).
 */
class ClearDockerVolume
{
    public function __construct(private readonly DockerProcess $dockerProcess) {}

    /**
     * @param  string|null  $containerName  Name to give the throwaway clear
     *                                      container. Pass it when the caller records the name as the run's
     *                                      docker_container_id, so stale-run reconciliation can confirm the clear is
     *                                      still alive on a large volume instead of failing the restore (and
     *                                      restarting stopped containers) mid-delete.
     * @param  callable|null  $heartbeat  Periodic worker lease renewal while the clear container runs.
     */
    public function handle(string $volumeName, ?string $containerName = null, ?callable $heartbeat = null): void
    {
        $command = array_merge([
            'docker',
            'run',
            '--rm',
        ], $containerName ? ['--name', $containerName] : [], [
            '-v',
            $volumeName.':/target',
            '--entrypoint',
            'find',
            RunBackupContainer::IMAGE,
            '/target',
            '-mindepth',
            '1',
            '-delete',
        ]);

        // No timeout (0): clearing is destructive and runs just before extraction.
        // A fixed cap could fire mid-delete on a large volume, leaving it partially
        // wiped AND aborting before the restore extracts — the worst outcome. A
        // hung delete is instead caught by stale-run reconciliation, like the
        // restore/backup containers which also run uncapped.
        $execute = fn (): DockerProcessResult => $this->dockerProcess->run($command, 0);
        $result = $heartbeat === null
            ? $execute()
            : $this->dockerProcess->whileMonitoring($heartbeat, $execute);

        if (! $result->successful()) {
            throw new RuntimeException($result->combinedOutput() ?: "Unable to clear Docker volume {$volumeName}.");
        }
    }
}
