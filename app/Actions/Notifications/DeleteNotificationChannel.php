<?php

namespace App\Actions\Notifications;

use App\Actions\Backup\RetryDockerLabelMutation;
use App\Actions\Backup\WithDockerLabelMutationLocks;
use App\Models\BackupJob;
use App\Models\NotificationChannel;

class DeleteNotificationChannel
{
    public function __construct(
        private readonly WithDockerLabelMutationLocks $withLocks,
        private readonly MutateNotificationChannel $mutateNotificationChannel,
    ) {}

    public function handle(NotificationChannel $channel): void
    {
        for ($attempt = 0; $attempt < 3; $attempt++) {
            $manualJobIds = $this->manualJobIds($channel);

            try {
                $this->withLocks->handle([], function ($destinations, $settings, $jobs, $volumes, $channels) use ($channel, $manualJobIds): void {
                    $channel = $channels->get($channel->id);

                    if (! $channel) {
                        return;
                    }

                    if ($this->manualJobIds($channel) !== $manualJobIds) {
                        throw new RetryDockerLabelMutation('Notification channel attachments changed before they were locked.');
                    }

                    $this->mutateNotificationChannel->assertMutable($channel);

                    if ($settings) {
                        $defaults = $settings->resolvedDefaults();
                        $defaults['notification_channel_ids'] = $this->withoutChannel($defaults['notification_channel_ids'] ?? [], $channel->id);
                        $settings->update(['defaults' => $defaults]);
                    }

                    $jobs
                        ->filter(fn ($job): bool => ($job->pending_label_reconciliation['action'] ?? null) === 'apply')
                        ->each(function (BackupJob $job) use ($channel): void {
                            $pending = $job->pending_label_reconciliation;

                            if (collect($pending['expected_notification_channels'] ?? [])->contains(
                                fn (array $expected): bool => (int) ($expected['id'] ?? 0) === $channel->id,
                            )) {
                                return;
                            }

                            $pending['notification_channel_ids'] = $this->withoutChannel($pending['notification_channel_ids'] ?? [], $channel->id);
                            $job->update(['pending_label_reconciliation' => $pending]);
                        });

                    $channel->deleteQuietly();
                }, notificationChannelIds: [$channel->id], explicitJobIds: $manualJobIds);

                return;
            } catch (RetryDockerLabelMutation) {
                continue;
            }
        }

        throw new RetryDockerLabelMutation('Notification channel attachments kept changing concurrently.');
    }

    /** @return list<int> */
    private function manualJobIds(NotificationChannel $channel): array
    {
        return $channel->backupJobs()
            ->where('configuration_source', BackupJob::CONFIGURATION_SOURCE_MANUAL)
            ->orderBy('backup_jobs.id')
            ->pluck('backup_jobs.id')
            ->map(fn ($id): int => (int) $id)
            ->all();
    }

    private function withoutChannel(array $ids, int $channelId): array
    {
        return collect($ids)->reject(fn ($id): bool => (int) $id === $channelId)->values()->all();
    }

}
