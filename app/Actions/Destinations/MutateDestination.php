<?php

namespace App\Actions\Destinations;

use App\Actions\Backup\WithDockerLabelMutationLocks;
use App\Models\BackupDestination;
use App\Models\BackupJob;
use App\Models\DockerLabelBackupSetting;

class MutateDestination
{
    public function __construct(
        private readonly WithDockerLabelMutationLocks $withLocks,
        private readonly NormalizeDestinationData $normalizeDestinationData,
    ) {}

    /** @param array<string, mixed> $data */
    public function create(array $data): BackupDestination
    {
        return $this->withLocks->handleForJobs([], [], fn (): BackupDestination => BackupDestination::query()->create(
            $this->normalizeDestinationData->handle($data),
        ));
    }

    public function update(BackupDestination $destination, array $data): void
    {
        $this->withLocks->handleForJobs([], [$destination->id], function ($destinations, ?DockerLabelBackupSetting $settings) use ($destination, $data): void {
            $locked = $destinations->get($destination->id);

            $this->ensureNoRunNeedsConfiguration($locked);

            if (! $locked) {
                return;
            }

            $normalizedData = $this->normalizeDestinationData->handle($data, $locked);

            $this->ensureCanDeactivate($locked, (bool) ($normalizedData['is_active'] ?? false), $settings);
            $locked->update($normalizedData);
        });
    }

    public function setActive(BackupDestination $destination, bool $isActive): void
    {
        $this->withLocks->handleForJobs([], [$destination->id], function ($destinations, ?DockerLabelBackupSetting $settings) use ($destination, $isActive): void {
            $locked = $destinations->get($destination->id);

            if (! $locked) {
                return;
            }

            $this->ensureNoRunNeedsConfiguration($locked);
            $this->ensureCanDeactivate($locked, $isActive, $settings);
            $locked->forceFill(['is_active' => $isActive])->save();
        });
    }

    public function delete(BackupDestination $destination): void
    {
        $this->withLocks->handleAcrossHosts([$destination->id], function ($destinations, ?DockerLabelBackupSetting $settings, $managedJobs) use ($destination): void {
            $locked = $destinations->get($destination->id);
            $message = match (true) {
                $this->isEnabledDefault($destination, $settings) => 'This destination is the active default for Docker label backups. Change or disable that setting before deleting it.',
                $destination->jobs()->where('configuration_source', BackupJob::CONFIGURATION_SOURCE_DOCKER_LABEL)->exists() => 'This destination is used by Docker label managed jobs. Disable or reconfigure label backups before deleting it.',
                $locked?->hasRunInProgress(includeAllFinalizations: true) => 'A backup or restore using this destination is in progress. Wait for it to finish before deleting it.',
                default => null,
            };

            if ($message !== null) {
                throw new DestinationMutationBlocked($message);
            }

            $locked?->delete();
        });
    }

    private function ensureCanDeactivate(BackupDestination $destination, bool $isActive, ?DockerLabelBackupSetting $settings): void
    {
        if (! $isActive && $this->isEnabledDefault($destination, $settings)) {
            throw new DestinationMutationBlocked('This destination is the active default for Docker label backups. Change or disable that setting first.');
        }
    }

    private function ensureNoRunNeedsConfiguration(?BackupDestination $destination): void
    {
        if ($destination?->hasConfigurationInUse()) {
            throw new DestinationMutationBlocked('A backup or restore still needs this destination configuration. Wait for it to finish before changing it.');
        }
    }

    private function isEnabledDefault(BackupDestination $destination, ?DockerLabelBackupSetting $settings): bool
    {
        return DockerLabelBackupSetting::usesEnabledDestination($destination);
    }
}
