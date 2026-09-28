<?php

namespace App\Actions\Docker;

use App\Services\Docker\DockerProcess;
use RuntimeException;

class CreateDockerVolume
{
    public const RESTORE_OWNERSHIP_LABEL = 'io.volumevault.restore-ownership';

    public function __construct(private readonly DockerProcess $dockerProcess) {}

    public function handle(string $volumeName, ?string $ownershipToken = null): void
    {
        $command = ['docker', 'volume', 'create'];

        if ($ownershipToken !== null) {
            array_push($command, '--label', self::RESTORE_OWNERSHIP_LABEL.'='.$ownershipToken);
        }

        array_push($command, '--', $volumeName);
        $result = $this->dockerProcess->run($command, 60);

        if (! $result->successful()) {
            throw new RuntimeException($result->combinedOutput() ?: "Unable to create Docker volume {$volumeName}.");
        }
    }
}
