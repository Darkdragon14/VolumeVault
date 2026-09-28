<?php

namespace App\Actions\Notifications;

use App\Models\DockerLabelBackupSetting;
use App\Models\NotificationChannel;
use App\Models\RunFinalization;
use Closure;
use Illuminate\Support\Facades\DB;

class MutateNotificationChannel
{
    /** @param array<string, mixed> $attributes */
    public function create(array $attributes): NotificationChannel
    {
        return $this->withChannelLocks(function () use ($attributes): NotificationChannel {
            if ($attributes['is_default'] ?? false) {
                NotificationChannel::query()->update(['is_default' => false]);
            }

            return NotificationChannel::query()->create($attributes);
        });
    }

    /**
     * @param  array<string, mixed>|Closure(NotificationChannel): array<string, mixed>  $attributes
     */
    public function update(NotificationChannel $channel, array|Closure $attributes): NotificationChannel
    {
        return $this->withChannelLocks(function ($channels) use ($channel, $attributes): NotificationChannel {
            $locked = $channels->find($channel->id);

            if (! $locked) {
                $locked = NotificationChannel::query()->findOrFail($channel->id);
            }

            if ($this->hasOutstandingFinalizations($locked)) {
                throw new NotificationChannelMutationBlocked;
            }

            $attributes = $attributes instanceof Closure ? $attributes($locked) : $attributes;

            if ($attributes['is_default'] ?? $locked->is_default) {
                NotificationChannel::query()->whereKeyNot($locked->id)->update(['is_default' => false]);
            }

            $locked->update($attributes);

            return $locked;
        });
    }

    public function assertMutable(NotificationChannel $channel): void
    {
        if ($this->hasOutstandingFinalizations($channel)) {
            throw new NotificationChannelMutationBlocked;
        }
    }

    private function hasOutstandingFinalizations(NotificationChannel $channel): bool
    {
        return RunFinalization::query()
            ->whereBelongsTo($channel)
            ->where('type', RunFinalization::TYPE_FINISHED_NOTIFICATION)
            ->outstanding()
            ->exists();
    }

    private function withChannelLocks(Closure $callback): mixed
    {
        DockerLabelBackupSetting::current();

        return DB::transaction(function () use ($callback): mixed {
            DockerLabelBackupSetting::query()->orderBy('id')->lockForUpdate()->get();
            $channels = NotificationChannel::query()->orderBy('id')->lockForUpdate()->get();

            return $callback($channels);
        }, attempts: 3);
    }
}
