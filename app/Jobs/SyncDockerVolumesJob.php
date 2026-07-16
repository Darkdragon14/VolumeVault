<?php

namespace App\Jobs;

use App\Actions\Docker\SyncDockerVolumes;
use App\Models\Host;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Throwable;

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
        Host::query()->active()->orderBy('id')->each(function (Host $host) use ($syncDockerVolumes): void {
            try {
                if ($host->type === Host::TYPE_LOCAL) {
                    $syncDockerVolumes->handle($host);
                    $host->forceFill(['last_error' => null])->save();

                    return;
                }

                $syncDockerVolumes->queueAgentSync($host);
            } catch (Throwable $exception) {
                $host->forceFill([
                    'last_error' => str($exception->getMessage())->limit(1000)->toString(),
                ])->save();
            }
        });
    }
}
