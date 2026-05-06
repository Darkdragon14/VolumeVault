<?php

namespace App\Jobs;

use App\Actions\Docker\SyncDockerVolumes;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;

class SyncDockerVolumesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 120;

    public function middleware(): array
    {
        return [(new WithoutOverlapping('sync-docker-volumes'))->expireAfter(300)];
    }

    public function handle(SyncDockerVolumes $syncDockerVolumes): void
    {
        $syncDockerVolumes->handle();
    }
}
