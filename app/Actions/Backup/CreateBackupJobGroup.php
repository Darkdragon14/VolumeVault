<?php

namespace App\Actions\Backup;

use App\Models\ActivityLog;
use App\Models\BackupJobGroup;
use App\Models\NotificationChannel;
use App\Services\Docker\LocalDockerExecution;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CreateBackupJobGroup
{
    /**
     * @param  array<string, mixed>  $attributes
     * @param  array<string, mixed>  $activityContext
     */
    public function handle(array $attributes, string $activityDescription, array $activityContext = []): BackupJobGroup
    {
        LocalDockerExecution::validate();

        return DB::transaction(function () use ($attributes, $activityDescription, $activityContext): BackupJobGroup {
            $channelIds = null;

            if (array_key_exists('notification_channel_ids', $attributes)) {
                $channelIds = collect($attributes['notification_channel_ids'] ?? [])
                    ->map(fn ($id): int => (int) $id)
                    ->unique()
                    ->sort()
                    ->values()
                    ->all();

                unset($attributes['notification_channel_ids']);
            }

            $group = BackupJobGroup::create($attributes);

            if ($channelIds !== null) {
                $lockedChannelIds = NotificationChannel::query()
                    ->whereKey($channelIds)
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->pluck('id')
                    ->all();

                if ($lockedChannelIds !== $channelIds) {
                    throw ValidationException::withMessages([
                        'notification_channel_ids' => 'One or more selected notification channels no longer exist.',
                    ]);
                }

                $group->notificationChannels()->sync($channelIds);
            }

            ActivityLog::record('backup_group_created', $activityDescription, $group, $activityContext);

            return $group;
        }, attempts: 3);
    }
}
