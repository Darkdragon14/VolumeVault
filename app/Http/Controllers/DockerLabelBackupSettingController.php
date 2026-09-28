<?php

namespace App\Http\Controllers;

use App\Actions\Backup\WithDockerLabelMutationLocks;
use App\Http\Requests\UpdateDockerLabelBackupSettingRequest;
use App\Models\BackupDestination;
use App\Models\DockerHost;
use App\Models\DockerLabelBackupSetting;
use App\Models\NotificationChannel;
use App\Services\Agents\AgentExecution;
use App\Services\Scheduling\BackupScheduleCalculator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class DockerLabelBackupSettingController extends Controller
{
    public function edit(Request $request): Response
    {
        return Inertia::render('Settings/DockerLabelBackups', $this->data($request));
    }

    public function show(Request $request): JsonResponse
    {
        return response()->json($this->data($request));
    }

    private function data(Request $request): array
    {
        $request->validate(['docker_host_id' => ['sometimes', 'integer', 'exists:docker_hosts,id']]);
        $hostId = $request->integer('docker_host_id', DockerHost::LOCAL_ID);
        $settings = DockerLabelBackupSetting::current($hostId);

        return [
            'hosts' => DockerHost::query()->orderBy('name')->get()->map(fn (DockerHost $host): array => [
                ...app(AgentExecution::class)->summary($host),
                'supports_docker_labels' => $host->isLocal() || in_array('docker-labels-v1', $host->agent_capabilities ?? [], true),
            ]),
            'settings' => [
                'docker_host_id' => $hostId,
                'enabled' => $settings->enabled,
                'backup_destination_id' => $settings->backup_destination_id,
                ...$settings->resolvedDefaults(),
                'last_sync_error' => $settings->last_sync_error,
                'last_synced_at' => $settings->last_synced_at,
            ],
            'destinations' => BackupDestination::query()->where('is_active', true)
                ->where(fn ($query) => $query->whereNull('docker_host_id')->orWhere('docker_host_id', $hostId))
                ->orderBy('name')->get()->map->safeForFrontend(),
            'notificationChannels' => NotificationChannel::query()->orderBy('name')->get()->map->safeForFrontend(),
            'timezones' => \DateTimeZone::listIdentifiers(),
        ];
    }

    public function update(UpdateDockerLabelBackupSettingRequest $request, BackupScheduleCalculator $scheduleCalculator, WithDockerLabelMutationLocks $withLocks)
    {
        $defaults = $request->defaults();
        $defaults['schedule_config'] = $scheduleCalculator->normalize($defaults['schedule_type'], $defaults['schedule_config']);

        $hostId = $request->integer('docker_host_id', DockerHost::LOCAL_ID);
        $current = DockerLabelBackupSetting::current($hostId);
        $destinationId = $request->integer('backup_destination_id') ?: null;
        $channelIds = $defaults['notification_channel_ids'] ?? [];
        $withLocks->handleOnHost([$current->backup_destination_id, $destinationId], function ($destinations, ?DockerLabelBackupSetting $settings, $jobs, $volumes, $channels) use ($request, $defaults, $destinationId, $channelIds, $hostId): void {
            $destination = $destinations->get($destinationId);
            if ($destination?->isHostBound() && $destination->docker_host_id !== $hostId) {
                throw ValidationException::withMessages(['backup_destination_id' => 'The destination must belong to the selected Docker host or be shared.']);
            }
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
        }, notificationChannelIds: $channelIds, dockerHostId: $hostId);

        if ($request->is('api/*')) {
            return response()->json($this->data($request));
        }

        return redirect()->route('settings.docker-label-backups.edit', $hostId === DockerHost::LOCAL_ID ? [] : ['docker_host_id' => $hostId])->with('success', 'Docker label backup settings updated.');
    }
}
