<?php

namespace App\Actions\Docker;

use App\Actions\Backup\MarkMissingVolumeJobs;
use App\Models\AgentCommand;
use App\Models\BackupJob;
use App\Models\DockerVolume;
use App\Models\Host;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class SyncDockerVolumes
{
    public function __construct(
        private readonly ListDockerVolumes $listDockerVolumes,
        private readonly MarkMissingVolumeJobs $markMissingVolumeJobs,
    ) {}

    public function handle(?Host $host = null): array
    {
        $host ??= Host::localHost();

        if ($host->type !== Host::TYPE_LOCAL) {
            throw new RuntimeException('Only the local host can be synced through the local Docker worker.');
        }

        return Cache::lock('volumevault:docker-volume-sync:'.$host->id, 300)->block(5, function () use ($host): array {
            $volumes = $this->listDockerVolumes->handle();

            return DB::transaction(fn (): array => $this->applyVolumeList($host, $volumes), 3);
        });
    }

    public function queueAgentSync(Host $host): bool
    {
        return Cache::lock('volumevault:agent-sync:'.$host->id, 10)->block(5, function () use ($host): bool {
            return DB::transaction(function () use ($host): bool {
                $currentHost = Host::query()->lockForUpdate()->findOrFail($host->id);

                if ($currentHost->type !== Host::TYPE_AGENT) {
                    throw new RuntimeException('Only agent hosts can queue a remote Docker volume sync.');
                }

                if (! $currentHost->is_active) {
                    throw new RuntimeException('Inactive agent hosts cannot queue a remote Docker volume sync.');
                }

                $hasPendingSync = AgentCommand::query()
                    ->where('host_id', $currentHost->id)
                    ->where('type', AgentCommand::TYPE_SYNC_VOLUMES)
                    ->whereIn('status', [AgentCommand::STATUS_PENDING, AgentCommand::STATUS_LEASED])
                    ->exists();

                if ($hasPendingSync) {
                    return false;
                }

                AgentCommand::create([
                    'host_id' => $currentHost->id,
                    'type' => AgentCommand::TYPE_SYNC_VOLUMES,
                    'status' => AgentCommand::STATUS_PENDING,
                    'payload' => [],
                ]);

                return true;
            }, 3);
        });
    }

    public function applyVolumeList(Host $host, array $volumes): array
    {
        $seenAt = now();
        $names = collect($volumes)->pluck('name')->filter()->values();

        foreach ($volumes as $volume) {
            DockerVolume::updateOrCreate(
                ['host_id' => $host->id, 'name' => $volume['name']],
                [
                    'driver' => $volume['driver'] ?? null,
                    'mountpoint' => $volume['mountpoint'] ?? null,
                    'labels' => $volume['labels'] ?? [],
                    'options' => $volume['options'] ?? [],
                    'exists' => true,
                    'last_seen_at' => $seenAt,
                ]
            );
        }

        $missingQuery = DockerVolume::query()
            ->where('host_id', $host->id)
            ->where('exists', true);

        if ($names->isNotEmpty()) {
            $missingQuery->whereNotIn('name', $names->all());
        }

        $jobVolumeNames = BackupJob::query()
            ->where('host_id', $host->id)
            ->where('source_type', BackupJob::SOURCE_TYPE_DOCKER_VOLUME)
            ->whereNotNull('volume_name')
            ->select('volume_name');
        $missingNames = (clone $missingQuery)->whereIn('name', clone $jobVolumeNames)->pluck('name');
        $orphanedMissingNames = (clone $missingQuery)->whereNotIn('name', clone $jobVolumeNames)->pluck('name');

        $markedMissing = DockerVolume::query()
            ->where('host_id', $host->id)
            ->whereIn('name', $missingNames)
            ->update(['exists' => false]);
        $removed = DockerVolume::query()
            ->where('host_id', $host->id)
            ->whereIn('name', $orphanedMissingNames)
            ->delete();
        $removed += DockerVolume::query()
            ->where('host_id', $host->id)
            ->where('exists', false)
            ->whereNotIn('name', clone $jobVolumeNames)
            ->delete();
        $affectedJobs = $this->markMissingVolumeJobs->handle($missingNames->all(), $host);

        return [
            'found' => $names->count(),
            'marked_missing' => $markedMissing,
            'removed' => $removed,
            'affected_jobs' => $affectedJobs,
        ];
    }
}
