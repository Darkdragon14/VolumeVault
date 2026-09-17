<?php

namespace App\Jobs;

use App\Actions\Docker\SyncDockerVolumes;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SyncDockerVolumesJob implements ShouldQueue
{
    public const TIMEOUT_SECONDS = 300;

    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = self::TIMEOUT_SECONDS;

    public function handle(SyncDockerVolumes $syncDockerVolumes): void
    {
        $syncDockerVolumes->handle();
    }
}
