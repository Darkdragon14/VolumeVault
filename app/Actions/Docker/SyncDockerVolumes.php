<?php

namespace App\Actions\Docker;

use App\Actions\Backup\MarkMissingVolumeJobs;
use App\Models\BackupJob;
use App\Models\DockerVolume;
use App\Models\Host;

class SyncDockerVolumes
{
    public function __construct(
        private readonly ListDockerVolumes $listDockerVolumes,
        private readonly MarkMissingVolumeJobs $markMissingVolumeJobs,
    ) {}

    public function handle(): array
    {
        $localHost = Host::localHost();
        $seenAt = now();
        $volumes = $this->listDockerVolumes->handle();
        $names = collect($volumes)->pluck('name')->filter()->values();

        foreach ($volumes as $volume) {
            DockerVolume::updateOrCreate(
                ['host_id' => $localHost->id, 'name' => $volume['name']],
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
            ->where('host_id', $localHost->id)
            ->where('exists', true);

        if ($names->isNotEmpty()) {
            $missingQuery->whereNotIn('name', $names->all());
        }

        $jobVolumeNames = BackupJob::query()
            ->where('source_type', BackupJob::SOURCE_TYPE_DOCKER_VOLUME)
            ->whereNotNull('volume_name')
            ->select('volume_name');
        $missingNames = (clone $missingQuery)->whereIn('name', $jobVolumeNames)->pluck('name');
        $orphanedMissingNames = (clone $missingQuery)->whereNotIn('name', clone $jobVolumeNames)->pluck('name');

        $markedMissing = DockerVolume::whereIn('name', $missingNames)->update(['exists' => false]);
        $removed = DockerVolume::whereIn('name', $orphanedMissingNames)->delete();
        $removed += DockerVolume::query()
            ->where('exists', false)
            ->whereNotIn('name', clone $jobVolumeNames)
            ->delete();
        $removed += DockerVolume::query()
            ->where('host_id', $localHost->id)
            ->where('exists', false)
            ->whereNotIn('name', clone $jobVolumeNames)
            ->delete();
        $affectedJobs = $this->markMissingVolumeJobs->handle($missingNames->all(), $localHost);

        return [
            'found' => $names->count(),
            'marked_missing' => $markedMissing,
            'removed' => $removed,
            'affected_jobs' => $affectedJobs,
        ];
    }
}
