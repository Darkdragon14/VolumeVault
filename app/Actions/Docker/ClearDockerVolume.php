<?php

namespace App\Actions\Docker;

use App\Services\Docker\DockerProcess;
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

    public function handle(string $volumeName): void
    {
        $command = [
            'docker',
            'run',
            '--rm',
            '-v',
            $volumeName.':/target',
            '--entrypoint',
            'find',
            RunBackupContainer::IMAGE,
            '/target',
            '-mindepth',
            '1',
            '-delete',
        ];

        $result = $this->dockerProcess->run($command, 120);

        if (! $result->successful()) {
            throw new RuntimeException($result->combinedOutput() ?: "Unable to clear Docker volume {$volumeName}.");
        }
    }
}
