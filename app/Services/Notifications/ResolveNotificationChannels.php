<?php

namespace App\Services\Notifications;

use App\Models\AlertRule;
use App\Models\BackupJob;
use App\Models\BackupJobGroup;
use App\Models\NotificationChannel;
use Illuminate\Database\Eloquent\Collection;

class ResolveNotificationChannels
{
    /** @return Collection<int, NotificationChannel> */
    public function forJob(BackupJob $job): Collection
    {
        if (! $job->notifications_enabled) {
            return new Collection;
        }

        return $job->notificationChannels()
            ->where('is_active', true)
            ->get();
    }

    /** @return Collection<int, NotificationChannel> */
    public function forGroup(BackupJobGroup $group): Collection
    {
        if (! $group->notifications_enabled) {
            return new Collection;
        }

        return $group->notificationChannels()
            ->where('is_active', true)
            ->get();
    }

    /**
     * Channels used for proactive alerts raised on a group member job. The group
     * owns notifications, so a member's alert is delivered through the group's
     * channels (gated by the same notifications toggle) rather than the member's
     * own — which are empty for grouped jobs.
     *
     * @return Collection<int, NotificationChannel>
     */
    public function forGroupAlerts(BackupJobGroup $group): Collection
    {
        if (! $group->notifications_enabled) {
            return new Collection;
        }

        return $group->notificationChannels()
            ->where('is_active', true)
            ->get();
    }

    /** @return Collection<int, NotificationChannel> */
    public function forJobAlerts(BackupJob $job): Collection
    {
        if (! $job->alert_notifications_enabled) {
            return new Collection;
        }

        return $job->notificationChannels()
            ->where('is_active', true)
            ->get();
    }

    /** @return Collection<int, NotificationChannel> */
    public function forAlertRule(AlertRule $rule): Collection
    {
        return $rule->notificationChannels()
            ->where('is_active', true)
            ->get();
    }
}
