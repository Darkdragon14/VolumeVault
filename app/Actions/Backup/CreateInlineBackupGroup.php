<?php

namespace App\Actions\Backup;

use App\Models\ActivityLog;
use App\Models\BackupJobGroup;
use App\Services\Scheduling\BackupScheduleCalculator;

class CreateInlineBackupGroup
{
    public function __construct(private readonly BackupScheduleCalculator $scheduleCalculator) {}

    public function handle(array $attributes, string $activityDescription): BackupJobGroup
    {
        $scheduleType = $attributes['schedule_type'] ?? BackupJobGroup::SCHEDULE_DAILY;
        $scheduleConfig = $this->scheduleCalculator->normalize($scheduleType, (array) ($attributes['schedule_config'] ?? []));
        $timezone = ! empty($attributes['timezone']) ? $attributes['timezone'] : null;

        $group = BackupJobGroup::create([
            'name' => $attributes['name'] ?? 'Backup group',
            'schedule_type' => $scheduleType,
            'schedule_config' => $scheduleConfig,
            'cron_expression' => $this->scheduleCalculator->cronExpression($scheduleType, $scheduleConfig),
            'timezone' => $timezone,
            'status' => BackupJobGroup::STATUS_ACTIVE,
            'failure_policy' => $attributes['failure_policy'] ?? BackupJobGroup::FAILURE_POLICY_CONTINUE,
            'notifications_enabled' => array_key_exists('notifications_enabled', $attributes) ? (bool) $attributes['notifications_enabled'] : true,
            'next_run_at' => $this->scheduleCalculator->nextRunAt($scheduleType, $scheduleConfig, null, $timezone),
        ]);

        $group->notificationChannels()->sync($this->notificationChannelIds($attributes));
        ActivityLog::record('backup_group_created', $activityDescription, $group);

        return $group;
    }

    public function notificationChannelIds(array $attributes): array
    {
        return collect($attributes['notification_channel_ids'] ?? [])
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->values()
            ->all();
    }
}
