<?php

namespace App\Actions\Backup;

use App\Models\BackupJob;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

class ApplyPendingDockerLabelReconciliation
{
    public function __construct(private readonly WithDockerLabelMutationLocks $withLocks) {}

    public function handle(BackupJob $job): bool
    {
        for ($attempt = 0; $attempt < 3; $attempt++) {
            $current = BackupJob::query()->find($job->id);

            if (! $current?->isDockerLabelManaged() || ! is_array($current->pending_label_reconciliation)) {
                return false;
            }

            $pending = $current->pending_label_reconciliation;
            $destinationIds = [$current->backup_destination_id, $pending['payload']['backup_destination_id'] ?? null];
            $volumeNames = [$current->volume_name, $pending['payload']['volume_name'] ?? null];
            $notificationChannelIds = $pending['notification_channel_ids'] ?? [];

            try {
                return $this->withLocks->handleForJobs(
                    [$job->id],
                    $destinationIds,
                    function ($destinations, $settings, $jobs, $volumes, $notificationChannels) use ($job, $destinationIds, $volumeNames, $notificationChannelIds): bool {
                        $lockedJob = $jobs->get($job->id);

                        $this->ensureReferencesWereLocked(
                            $lockedJob,
                            $destinationIds,
                            $volumeNames,
                            $notificationChannelIds,
                        );

                        return $this->handleLocked($lockedJob, $destinations, $volumes, $notificationChannels);
                    },
                    $volumeNames,
                    $notificationChannelIds,
                );
            } catch (RetryDockerLabelMutation) {
                continue;
            }
        }

        throw new RetryDockerLabelMutation('Pending Docker label references kept changing concurrently.');
    }

    public function handleLocked(
        ?BackupJob $job,
        Collection $destinations,
        Collection $volumes,
        Collection $notificationChannels,
    ): bool {
        if (! $job?->isDockerLabelManaged() || ! is_array($job->pending_label_reconciliation) || $job->hasRunInProgress()) {
            return false;
        }

        $pending = $job->pending_label_reconciliation;

        if (($pending['action'] ?? null) === 'disable') {
            $this->disable($job, (string) ($pending['message'] ?? 'Docker label definition is no longer active.'));

            return true;
        }

        if (($pending['action'] ?? null) !== 'apply' || ! is_array($pending['payload'] ?? null)) {
            return false;
        }

        $destinationId = (int) ($pending['payload']['backup_destination_id'] ?? 0);
        $volumeName = (string) ($pending['payload']['volume_name'] ?? '');
        $channelIds = collect($pending['notification_channel_ids'] ?? [])->map(fn ($id): int => (int) $id)->unique()->sort()->values();
        $jobNotificationChannels = $notificationChannels
            ->filter(fn ($channel): bool => $channelIds->contains((int) $channel->id))
            ->keyBy('id');
        $destinationValid = $destinations->get($destinationId)?->is_active === true;
        $volumeValid = $volumes->get($volumeName)?->isAvailable() === true;
        $existingChannelIds = $jobNotificationChannels->keys()->map(fn ($id): int => (int) $id)->sort()->values();
        $expectedDestination = $pending['expected_destination'] ?? null;
        $expectedChannels = collect($pending['expected_notification_channels'] ?? []);
        $destinationNameValid = ! is_array($expectedDestination)
            || ((int) ($expectedDestination['id'] ?? 0) === $destinationId
                && ($expectedDestination['name'] ?? null) === $destinations->get($destinationId)?->name);
        $expectedChannelIds = $expectedChannels->pluck('id')->map(fn ($id): int => (int) $id)->unique()->sort()->values();
        $channelNamesValid = $expectedChannels->every(function (array $expectedChannel) use ($jobNotificationChannels): bool {
            $channel = $jobNotificationChannels->get((int) ($expectedChannel['id'] ?? 0));

            return $channel && ($expectedChannel['name'] ?? null) === $channel->name;
        });

        if (! $destinationValid
            || ! $volumeValid
            || $existingChannelIds->all() !== $channelIds->all()
            || ! $destinationNameValid
            || ($expectedChannels->isNotEmpty() && $expectedChannelIds->all() !== $channelIds->all())
            || ! $channelNamesValid) {
            $message = 'A pending Docker label notification channel no longer exists.';

            if (! $destinationValid) {
                $message = 'Pending Docker label destination no longer exists or is inactive.';
            } elseif (! $volumeValid) {
                $message = 'Pending Docker label volume no longer exists or is unavailable.';
            } elseif (! $destinationNameValid) {
                $message = 'Pending Docker label destination no longer matches the configured name.';
            } elseif (! $channelNamesValid || ($expectedChannels->isNotEmpty() && $expectedChannelIds->all() !== $channelIds->all())) {
                $message = 'A pending Docker label notification channel no longer matches the configured name.';
            }

            $this->disable($job, $message);

            return true;
        }

        $wasPaused = $job->status === BackupJob::STATUS_PAUSED;
        $attributes = [
            ...$pending['payload'],
            'pending_label_reconciliation' => null,
            'label_reconciliation_error' => null,
        ];

        if ($wasPaused) {
            unset($attributes['status'], $attributes['pause_reason'], $attributes['next_run_at']);
        } else {
            $attributes['status'] = BackupJob::STATUS_ACTIVE;
            $attributes['pause_reason'] = null;
            $attributes['next_run_at'] = isset($pending['next_run_at'])
                ? CarbonImmutable::parse($pending['next_run_at'])
                : $job->next_run_at;
        }

        $job->update($attributes);
        $job->notificationChannels()->sync($channelIds->all());

        return true;
    }

    private function ensureReferencesWereLocked(
        ?BackupJob $job,
        array $requestedDestinationIds,
        array $requestedVolumeNames,
        array $requestedNotificationChannelIds,
    ): void {
        if (! $job?->isDockerLabelManaged() || ! is_array($job->pending_label_reconciliation)) {
            return;
        }

        $pending = $job->pending_label_reconciliation;

        if (($pending['action'] ?? null) !== 'apply' || ! is_array($pending['payload'] ?? null)) {
            return;
        }

        $destinationId = (int) ($pending['payload']['backup_destination_id'] ?? 0);
        $volumeName = (string) ($pending['payload']['volume_name'] ?? '');
        $channelIds = collect($pending['notification_channel_ids'] ?? [])->map(fn ($id): int => (int) $id)->unique()->sort()->values();
        $requestedDestinations = collect($requestedDestinationIds)->map(fn ($id): int => (int) $id)->filter()->unique();
        $requestedVolumes = collect($requestedVolumeNames)->filter()->unique();
        $requestedChannels = collect($requestedNotificationChannelIds)->map(fn ($id): int => (int) $id)->filter()->unique()->sort()->values();

        if (! $requestedDestinations->contains($destinationId)
            || ! $requestedVolumes->contains($volumeName)
            || $requestedChannels->all() !== $channelIds->all()) {
            throw new RetryDockerLabelMutation('Pending Docker label references changed before they were locked.');
        }
    }

    public function disable(BackupJob $job, string $message): void
    {
        $attributes = [
            'pending_label_reconciliation' => null,
            'label_reconciliation_error' => $message,
        ];

        if ($job->status !== BackupJob::STATUS_PAUSED) {
            $attributes['next_run_at'] = null;
            $attributes['status'] = BackupJob::STATUS_ERROR;
            $attributes['pause_reason'] = 'Docker label configuration is invalid or inactive.';
            $attributes['last_error'] = $message;
            $attributes['last_error_at'] = now();
        }

        $job->update($attributes);
    }
}
