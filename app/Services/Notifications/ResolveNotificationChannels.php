<?php

namespace App\Services\Notifications;

use App\Models\BackupJob;
use App\Models\NotificationChannel;
use Illuminate\Database\Eloquent\Collection;

class ResolveNotificationChannels
{
    /** @return Collection<int, NotificationChannel> */
    public function forJob(BackupJob $job): Collection
    {
        return NotificationChannel::query()
            ->where('is_active', true)
            ->where(function ($query) use ($job): void {
                $query->where('scope', NotificationChannel::SCOPE_ALL)
                    ->orWhere(function ($query) use ($job): void {
                        $query->where('scope', NotificationChannel::SCOPE_SPECIFIC)
                            ->whereHas('backupJobs', fn ($query) => $query->whereKey($job->id));
                    });
            })
            ->get();
    }
}
