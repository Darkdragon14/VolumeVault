<?php

namespace App\Actions\Runs;

use App\Models\BackupGroupRun;
use App\Models\BackupJob;
use App\Models\BackupJobGroup;
use App\Models\BackupRun;
use App\Models\NotificationChannel;
use App\Models\RestoreRun;
use App\Models\RunFinalization;
use Illuminate\Database\Eloquent\Collection;

class CreateRunFinalizations
{
    public function createMetadata(BackupRun $run): RunFinalization
    {
        return RunFinalization::query()->firstOrCreate([
            'deduplication_key' => "backup-run:{$run->id}:archive-metadata",
        ], [
            'backup_run_id' => $run->id,
            'type' => RunFinalization::TYPE_ARCHIVE_METADATA,
            'status' => RunFinalization::STATUS_PENDING,
            'available_at' => now(),
        ]);
    }

    /** @return list<int> */
    public function createBackupNotifications(BackupRun $run, BackupJob $lockedJob): array
    {
        if (! $lockedJob->notifications_enabled || $run->belongsToGroupRun() || $run->trigger === BackupRun::TRIGGER_PRE_RESTORE) {
            return [];
        }

        $channels = $lockedJob->notificationChannels()
            ->where('is_active', true)
            ->when($run->status === BackupRun::STATUS_SUCCESS, fn ($query) => $query->where('notification_level', NotificationChannel::LEVEL_INFO))
            ->orderBy('notification_channels.id')
            ->lockForUpdate()
            ->get();

        return $this->createNotificationRows('backup-run', $run->id, 'backup_run_id', $channels);
    }

    /** @return list<int> */
    public function createRestoreNotifications(RestoreRun $run, BackupJob $lockedJob): array
    {
        if ($lockedJob->isGroupMember()) {
            $group = BackupJobGroup::query()->lockForUpdate()->find($lockedJob->backup_job_group_id);
            $channels = $group?->notifications_enabled
                ? $group->notificationChannels()
                    ->where('is_active', true)
                    ->when($run->status === RestoreRun::STATUS_SUCCESS, fn ($query) => $query->where('notification_level', NotificationChannel::LEVEL_INFO))
                    ->orderBy('notification_channels.id')
                    ->lockForUpdate()
                    ->get()
                : new Collection;
        } else {
            $channels = $lockedJob->notifications_enabled
                ? $lockedJob->notificationChannels()
                    ->where('is_active', true)
                    ->when($run->status === RestoreRun::STATUS_SUCCESS, fn ($query) => $query->where('notification_level', NotificationChannel::LEVEL_INFO))
                    ->orderBy('notification_channels.id')
                    ->lockForUpdate()
                    ->get()
                : new Collection;
        }

        return $this->createNotificationRows('restore-run', $run->id, 'restore_run_id', $channels);
    }

    /** @return list<int> */
    public function createGroupNotifications(BackupGroupRun $run, BackupJobGroup $lockedGroup): array
    {
        if (! $lockedGroup->notifications_enabled) {
            return [];
        }

        $channels = $lockedGroup->notificationChannels()
            ->where('is_active', true)
            ->when($run->status === BackupGroupRun::STATUS_SUCCESS, fn ($query) => $query->where('notification_level', NotificationChannel::LEVEL_INFO))
            ->orderBy('notification_channels.id')
            ->lockForUpdate()
            ->get();

        return $this->createNotificationRows('backup-group-run', $run->id, 'backup_group_run_id', $channels);
    }

    /** @return list<int> */
    public function createGroupStartNotifications(BackupGroupRun $run, BackupJobGroup $lockedGroup): array
    {
        if (! $lockedGroup->notifications_enabled) {
            return [];
        }

        $channels = $lockedGroup->notificationChannels()->where('is_active', true)
            ->where('notification_level', NotificationChannel::LEVEL_INFO)->orderBy('notification_channels.id')->lockForUpdate()->get();

        return $this->createNotificationRows('backup-group-run', $run->id, 'backup_group_run_id', $channels, RunFinalization::TYPE_STARTED_NOTIFICATION);
    }

    /**
     * @param  Collection<int, NotificationChannel>  $channels
     * @return list<int>
     */
    private function createNotificationRows(string $ownerType, int $ownerId, string $ownerColumn, Collection $channels, string $type = RunFinalization::TYPE_FINISHED_NOTIFICATION): array
    {
        $event = $type === RunFinalization::TYPE_STARTED_NOTIFICATION ? 'started' : 'finished';

        return $channels->map(function (NotificationChannel $channel) use ($ownerType, $ownerId, $ownerColumn, $type, $event): int {
            return RunFinalization::query()->firstOrCreate([
                'deduplication_key' => "{$ownerType}:{$ownerId}:{$event}-notification:channel:{$channel->id}",
            ], [
                $ownerColumn => $ownerId,
                'notification_channel_id' => $channel->id,
                'type' => $type,
                'status' => RunFinalization::STATUS_PENDING,
                'available_at' => now(),
            ])->id;
        })->all();
    }
}
