<?php

namespace App\Actions\Backup;

use App\Models\BackupJobGroup;
use App\Models\NotificationChannel;
use App\Services\Scheduling\BackupScheduleCalculator;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class UpdateBackupJobGroup
{
    public function __construct(
        private readonly BackupScheduleCalculator $scheduleCalculator,
        private readonly WithBackupGroupMutationLocks $withGroupLocks,
    ) {}

    /**
     * @param  array{
     *     name: string,
     *     schedule_type: string,
     *     schedule_config: array,
     *     timezone: ?string,
     *     failure_policy: string,
     *     notifications_enabled?: bool,
     *     notification_channel_ids?: array<int, int|string>|null
     * }  $attributes
     */
    public function handle(BackupJobGroup $group, array $attributes): BackupJobGroup
    {
        return $this->withGroupLocks->handle(
            [$group->id],
            function (Collection $groups) use ($group, $attributes): BackupJobGroup {
                $lockedGroup = $groups->get($group->id);

                if (! $lockedGroup instanceof BackupJobGroup) {
                    throw (new ModelNotFoundException)->setModel(BackupJobGroup::class, [$group->id]);
                }

                $this->assertNoOutstandingFinalizations($lockedGroup);

                $update = [
                    'name' => $attributes['name'],
                    'schedule_type' => $attributes['schedule_type'],
                    'schedule_config' => $attributes['schedule_config'],
                    'cron_expression' => $this->scheduleCalculator->cronExpression(
                        $attributes['schedule_type'],
                        $attributes['schedule_config'],
                    ),
                    'timezone' => $attributes['timezone'],
                    'failure_policy' => $attributes['failure_policy'],
                    'next_run_at' => $this->scheduleCalculator->nextRunAt(
                        $attributes['schedule_type'],
                        $attributes['schedule_config'],
                        null,
                        $attributes['timezone'],
                    ),
                ];

                if (array_key_exists('notifications_enabled', $attributes)) {
                    $update['notifications_enabled'] = $attributes['notifications_enabled'];
                }

                $lockedGroup->update($update);

                $lockedGroup->members()
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get();

                if (array_key_exists('notification_channel_ids', $attributes)) {
                    $channelIds = collect($attributes['notification_channel_ids'] ?? [])
                        ->map(fn ($id): int => (int) $id)
                        ->unique()
                        ->sort()
                        ->values()
                        ->all();
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

                    $lockedGroup->notificationChannels()->sync($channelIds);
                }

                $lockedGroup->members()->update([
                    'schedule_type' => $lockedGroup->schedule_type,
                    'schedule_config' => json_encode($lockedGroup->schedule_config),
                    'cron_expression' => $lockedGroup->cron_expression,
                    'timezone' => $lockedGroup->timezone,
                    'next_run_at' => null,
                ]);

                return $lockedGroup;
            },
        );
    }

    public function setNotificationsEnabled(BackupJobGroup $group, bool $enabled): BackupJobGroup
    {
        return $this->withGroupLocks->handle([$group->id], function (Collection $groups) use ($group, $enabled): BackupJobGroup {
            $lockedGroup = $groups->get($group->id);

            if (! $lockedGroup instanceof BackupJobGroup) {
                throw (new ModelNotFoundException)->setModel(BackupJobGroup::class, [$group->id]);
            }

            $this->assertNoOutstandingFinalizations($lockedGroup);
            $lockedGroup->forceFill(['notifications_enabled' => $enabled])->save();

            return $lockedGroup;
        });
    }

    private function assertNoOutstandingFinalizations(BackupJobGroup $group): void
    {
        if ($group->hasOutstandingFinalizations()) {
            throw ValidationException::withMessages([
                'group' => 'This group still has notification finalization work pending. Wait for it to finish before changing it.',
            ]);
        }
    }
}
