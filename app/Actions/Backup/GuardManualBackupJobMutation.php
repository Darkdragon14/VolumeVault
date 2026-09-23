<?php

namespace App\Actions\Backup;

use App\Models\BackupGroupRun;
use App\Models\BackupJob;
use App\Models\DockerHost;
use App\Services\Agents\AgentExecution;
use App\Services\Agents\HostWorkAdmission;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class GuardManualBackupJobMutation
{
    public function __construct(
        private readonly WithDockerLabelMutationLocks $withLocks,
        private readonly WithBackupGroupMutationLocks $withGroupLocks,
    ) {}

    public function handle(
        array $destinationIds,
        array $volumeNames,
        int $destinationId,
        ?string $volumeName,
        array $notificationChannelIds,
        callable $callback,
        array $backupGroupIds = [],
        array $explicitJobIds = [],
        int $dockerHostId = DockerHost::LOCAL_ID,
    ): mixed {
        return $this->withGroupLocks->handle($backupGroupIds, function ($groups) use ($destinationIds, $destinationId, $volumeName, $notificationChannelIds, $callback, $volumeNames, $explicitJobIds, $dockerHostId): mixed {
            return $this->withLocks->handleOnHost(
                $destinationIds,
                function ($destinations, $settings, $managedJobs, $volumes, $channels, $explicitJobs) use ($destinationId, $volumeName, $notificationChannelIds, $callback, $groups, $dockerHostId): mixed {
                    app(AgentExecution::class)->validateHost($dockerHostId, 'backup-v1');
                    app(HostWorkAdmission::class)->assertAccepting($dockerHostId);
                    $destination = $destinations->get($destinationId);
                    if ($destination?->isHostBound() && (int) $destination->docker_host_id !== $dockerHostId) {
                        throw ValidationException::withMessages(['backup_destination_id' => 'The destination belongs to another Docker host.']);
                    }
                    if (! $destinations->get($destinationId)?->is_active) {
                        throw ValidationException::withMessages([
                            'backup_destination_id' => 'The selected backup destination no longer exists or is inactive.',
                        ]);
                    }

                    if ($volumeName !== null && ! $volumes->get($volumeName)?->isAvailable()) {
                        throw ValidationException::withMessages([
                            'volume_name' => 'The selected Docker volume no longer exists.',
                        ]);
                    }

                    if ($volumeName !== null && $managedJobs->contains(fn (BackupJob $job): bool => $job->reservesDockerVolume($volumeName))) {
                        throw ValidationException::withMessages([
                            'volume_name' => 'This volume is already configured by Docker labels.',
                        ]);
                    }

                    if (! $this->hasAllNotificationChannels($channels, $notificationChannelIds)) {
                        throw ValidationException::withMessages([
                            'notification_channel_ids' => 'A selected notification channel no longer exists.',
                        ]);
                    }

                    return $callback($destinations, $settings, $managedJobs, $volumes, $channels, $groups, $explicitJobs);
                },
                $volumeNames,
                $notificationChannelIds,
                $explicitJobIds,
                $dockerHostId,
            );
        });
    }

    public function isReadOnly(BackupJob $job): bool
    {
        return $job->isDockerLabelManaged();
    }

    public function guardGroupMembershipChange(?BackupJob $job, ?int $requestedGroupId, bool $createsGroup, Collection $lockedGroups): void
    {
        $currentGroupId = $job?->backup_job_group_id !== null ? (int) $job->backup_job_group_id : null;

        if (! $createsGroup && $currentGroupId === $requestedGroupId) {
            return;
        }

        if ($job?->hasRunInProgress()) {
            throw ValidationException::withMessages([
                'backup_job_group_id' => 'This job has a backup or restore run in progress; wait for it to finish before changing its group membership.',
            ]);
        }

        $existingGroupIds = collect([$currentGroupId, $requestedGroupId])
            ->filter(fn (?int $groupId): bool => $groupId !== null && $lockedGroups->has($groupId))
            ->values();

        if ($existingGroupIds->isEmpty()) {
            return;
        }

        if (BackupGroupRun::query()
            ->whereIn('backup_job_group_id', $existingGroupIds)
            ->whereIn('status', [BackupGroupRun::STATUS_QUEUED, BackupGroupRun::STATUS_RUNNING])
            ->exists()) {
            throw ValidationException::withMessages([
                'backup_job_group_id' => 'A backup group run is queued or running; wait for it to finish before changing group membership.',
            ]);
        }
    }

    private function hasAllNotificationChannels(Collection $channels, array $notificationChannelIds): bool
    {
        return $channels->keys()->map(fn ($id): int => (int) $id)->sort()->values()->all()
            === collect($notificationChannelIds)->map(fn ($id): int => (int) $id)->sort()->values()->all();
    }
}
