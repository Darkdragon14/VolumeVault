<?php

namespace App\Services\Docker;

use App\Models\BackupDestination;
use App\Models\DockerHost;
use App\Support\DeploymentMode;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class LocalDockerExecution
{
    public static function assertHost(int $dockerHostId): void
    {
        DeploymentMode::assertLocalExecution();

        if ($dockerHostId !== DockerHost::LOCAL_ID) {
            throw new RuntimeException('This Docker host requires an agent; local execution is not allowed.');
        }
    }

    public static function assertDestination(BackupDestination $destination): void
    {
        if ($destination->isHostBound()) {
            self::assertHost((int) ($destination->docker_host_id ?? DockerHost::LOCAL_ID));
        }
    }

    public static function validate(): void
    {
        if (! DeploymentMode::localExecutionEnabled()) {
            throw ValidationException::withMessages([
                'deployment_mode' => 'Local operations are disabled in orchestrator-only mode.',
            ]);
        }
    }
}
