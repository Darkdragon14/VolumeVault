<?php

namespace App\Jobs;

use App\Actions\Docker\SyncDockerVolumes;
use App\Models\AgentCommand;
use App\Models\Host;
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
        Host::query()->active()->orderBy('id')->each(function (Host $host) use ($syncDockerVolumes): void {
            if ($host->type === Host::TYPE_LOCAL) {
                $syncDockerVolumes->handle($host);

                return;
            }

            $hasPendingSync = AgentCommand::query()
                ->where('host_id', $host->id)
                ->where('type', AgentCommand::TYPE_SYNC_VOLUMES)
                ->whereIn('status', [AgentCommand::STATUS_PENDING, AgentCommand::STATUS_LEASED])
                ->exists();

            if ($hasPendingSync) {
                return;
            }

            AgentCommand::create([
                'host_id' => $host->id,
                'type' => AgentCommand::TYPE_SYNC_VOLUMES,
                'status' => AgentCommand::STATUS_PENDING,
                'payload' => [],
            ]);
        });
    }
}
