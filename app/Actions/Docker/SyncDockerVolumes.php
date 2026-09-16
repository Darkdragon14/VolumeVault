<?php

namespace App\Actions\Docker;

use App\Actions\Backup\MarkMissingVolumeJobs;
use App\Actions\Backup\ReconcileDockerLabelBackupJobs;
use App\Actions\Backup\WithDockerLabelMutationLocks;
use App\Models\BackupJob;
use App\Models\DockerVolume;
use Illuminate\Support\Facades\File;
use RuntimeException;

class SyncDockerVolumes
{
    public const LOCK_FILENAME = 'sync-docker-volumes.lock';

    public function __construct(
        private readonly ListDockerVolumes $listDockerVolumes,
        private readonly MarkMissingVolumeJobs $markMissingVolumeJobs,
        private readonly ReconcileDockerLabelBackupJobs $reconcileDockerLabelBackupJobs,
        private readonly WithDockerLabelMutationLocks $withLocks,
    ) {}

    public function handle(): array
    {
        $lockPath = storage_path('framework/'.self::LOCK_FILENAME);
        File::ensureDirectoryExists(dirname($lockPath));
        $lock = fopen($lockPath, 'c');

        if ($lock === false) {
            throw new RuntimeException('Unable to open the Docker volume synchronization lock.');
        }

        if (! flock($lock, LOCK_EX | LOCK_NB)) {
            fclose($lock);
            throw new RuntimeException('Docker volume synchronization is already in progress.');
        }

        try {
            return $this->sync();
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function sync(): array
    {
        $seenAt = now();
        $volumes = $this->listDockerVolumes->handle();
        $names = collect($volumes)->pluck('name')->filter()->values();

        foreach ($volumes as $volume) {
            DockerVolume::updateOrCreate(
                ['name' => $volume['name']],
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

        $candidateNames = DockerVolume::query()
            ->when($names->isNotEmpty(), fn ($query) => $query->whereNotIn('name', $names->all()))
            ->pluck('name')
            ->all();
        $cleanup = $this->withLocks->handle([], function () use ($names, $candidateNames): array {
            $jobVolumeNames = BackupJob::query()
                ->where('source_type', BackupJob::SOURCE_TYPE_DOCKER_VOLUME)
                ->whereNotNull('volume_name')
                ->pluck('volume_name')
                ->filter()
                ->unique()
                ->values();
            $missingNames = $jobVolumeNames->diff($names)->values();

            foreach ($missingNames as $missingName) {
                DockerVolume::firstOrCreate(['name' => $missingName], ['exists' => false]);
            }

            $markedMissing = DockerVolume::query()
                ->whereIn('name', $missingNames->all())
                ->where('exists', true)
                ->update(['exists' => false]);
            $referencedVolumes = BackupJob::query()
                ->select('volume_name')
                ->where('source_type', BackupJob::SOURCE_TYPE_DOCKER_VOLUME)
                ->whereNotNull('volume_name');
            $removed = DockerVolume::query()
                ->whereIn('name', $candidateNames)
                ->whereNotIn('name', $referencedVolumes)
                ->delete();

            return compact('markedMissing', 'missingNames', 'removed');
        }, volumeNames: $candidateNames);
        $missingNames = $cleanup['missingNames'];
        $markedMissing = $cleanup['markedMissing'];
        $removed = $cleanup['removed'];
        $labelBackups = $this->reconcileDockerLabelBackupJobs->handle();
        $affectedJobs = $this->markMissingVolumeJobs->handle($missingNames->all());

        return [
            'found' => $names->count(),
            'marked_missing' => $markedMissing,
            'removed' => $removed,
            'affected_jobs' => $affectedJobs,
            'label_backups' => $labelBackups,
        ];
    }
}
