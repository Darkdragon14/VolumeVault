<?php

namespace App\Http\Controllers;

use App\Actions\Backup\WithDockerLabelMutationLocks;
use App\Http\Requests\UpdateDockerLabelBackupSettingRequest;
use App\Models\BackupDestination;
use App\Models\DockerLabelBackupSetting;
use App\Models\NotificationChannel;
use App\Services\Scheduling\BackupScheduleCalculator;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class DockerLabelBackupSettingController extends Controller
{
    public function edit(): Response
    {
        $settings = DockerLabelBackupSetting::current();

        return Inertia::render('Settings/DockerLabelBackups', [
            'settings' => [
                'enabled' => $settings->enabled,
                'backup_destination_id' => $settings->backup_destination_id,
                ...$settings->resolvedDefaults(),
                'last_sync_error' => $settings->last_sync_error,
                'last_synced_at' => $settings->last_synced_at,
            ],
            'destinations' => BackupDestination::query()->where('is_active', true)->orderBy('name')->get()->map->safeForFrontend(),
            'notificationChannels' => NotificationChannel::query()->orderBy('name')->get()->map->safeForFrontend(),
            'timezones' => \DateTimeZone::listIdentifiers(),
        ]);
    }

    public function update(UpdateDockerLabelBackupSettingRequest $request, BackupScheduleCalculator $scheduleCalculator, WithDockerLabelMutationLocks $withLocks)
    {
        $defaults = $request->defaults();
        $defaults['schedule_config'] = $scheduleCalculator->normalize($defaults['schedule_type'], $defaults['schedule_config']);

        $current = DockerLabelBackupSetting::current();
        $destinationId = $request->integer('backup_destination_id') ?: null;
        $channelIds = $defaults['notification_channel_ids'] ?? [];
        $withLocks->handle([$current->backup_destination_id, $destinationId], function ($destinations, ?DockerLabelBackupSetting $settings, $jobs, $volumes, $channels) use ($request, $defaults, $destinationId, $channelIds): void {
            if ($request->boolean('enabled') && ! $destinations->get($destinationId)?->is_active) {
                throw ValidationException::withMessages([
                    'backup_destination_id' => 'The default destination must still exist and be active.',
                ]);
            }

            if ($channels->keys()->map(fn ($id): int => (int) $id)->sort()->values()->all()
                !== collect($channelIds)->map(fn ($id): int => (int) $id)->sort()->values()->all()) {
                throw ValidationException::withMessages([
                    'notification_channel_ids' => 'A selected notification channel no longer exists.',
                ]);
            }

            $settings?->update([
                'enabled' => $request->boolean('enabled'),
                'backup_destination_id' => $destinationId,
                'defaults' => $defaults,
            ]);
        }, notificationChannelIds: $channelIds);

        return redirect()->route('settings.docker-label-backups.edit')->with('success', 'Docker label backup settings updated.');
    }
}
