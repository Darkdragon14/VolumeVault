<?php

namespace App\Actions\Backup;

use App\Models\BackupDestination;
use App\Models\BackupJob;
use App\Models\DockerLabelBackupSetting;
use App\Models\DockerVolume;
use App\Models\NotificationChannel;
use Illuminate\Support\Facades\DB;

class WithDockerLabelMutationLocks
{
    public function handle(
        array $destinationIds,
        callable $callback,
        array $volumeNames = [],
        array $notificationChannelIds = [],
        array $explicitJobIds = [],
    ): mixed {
        return $this->handleScoped(null, $destinationIds, $callback, $volumeNames, $notificationChannelIds, $explicitJobIds);
    }

    public function handleForJobs(
        array $managedJobIds,
        array $destinationIds,
        callable $callback,
        array $volumeNames = [],
        array $notificationChannelIds = [],
        array $explicitJobIds = [],
    ): mixed {
        return $this->handleScoped($managedJobIds, $destinationIds, $callback, $volumeNames, $notificationChannelIds, $explicitJobIds);
    }

    private function handleScoped(
        ?array $managedJobIds,
        array $destinationIds,
        callable $callback,
        array $volumeNames,
        array $notificationChannelIds,
        array $explicitJobIds,
    ): mixed {
        return DB::transaction(function () use ($managedJobIds, $destinationIds, $callback, $volumeNames, $notificationChannelIds, $explicitJobIds): mixed {
            // Every caller acquires mutable label references in this order.
            $destinations = BackupDestination::query()
                ->whereKey(collect($destinationIds)->map(fn ($id): int => (int) $id)->filter()->unique()->sort()->values()->all())
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');
            $settings = DockerLabelBackupSetting::query()->whereKey(1)->lockForUpdate()->firstOrFail();
            $volumes = DockerVolume::query()
                ->whereIn('name', collect($volumeNames)->filter()->unique()->sort()->values()->all())
                ->orderBy('name')
                ->lockForUpdate()
                ->get()
                ->keyBy('name');
            $managedIds = $managedJobIds === null
                ? null
                : collect($managedJobIds)->map(fn ($id): int => (int) $id)->filter()->unique()->sort()->values()->all();
            $explicitIds = collect($explicitJobIds)->map(fn ($id): int => (int) $id)->filter()->unique()->sort()->values()->all();
            $lockedJobs = BackupJob::query()
                ->where(function ($query) use ($managedIds, $explicitIds): void {
                    $query->where(function ($query) use ($managedIds): void {
                        $query->where('configuration_source', BackupJob::CONFIGURATION_SOURCE_DOCKER_LABEL)
                            ->when($managedIds !== null, fn ($query) => $query->whereKey($managedIds));
                    })->when($explicitIds !== [], fn ($query) => $query->orWhereIn($query->getModel()->getQualifiedKeyName(), $explicitIds));
                })
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');
            $jobs = $lockedJobs->filter(fn (BackupJob $job): bool => $job->isDockerLabelManaged());
            $explicitJobs = $lockedJobs->filter(fn (BackupJob $job): bool => in_array($job->getKey(), $explicitIds, true));
            $notificationChannels = NotificationChannel::query()
                ->whereKey(collect($notificationChannelIds)->map(fn ($id): int => (int) $id)->filter()->unique()->sort()->values()->all())
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            return $callback($destinations, $settings, $jobs, $volumes, $notificationChannels, $explicitJobs);
        }, attempts: 3);
    }
}
