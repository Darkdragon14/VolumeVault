<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Backup\CreateBackupGroupRun;
use App\Http\Controllers\Controller;
use App\Http\Requests\BackupJobGroupRequest;
use App\Jobs\RunBackupGroupJob;
use App\Models\ActivityLog;
use App\Models\BackupGroupRun;
use App\Models\BackupJob;
use App\Models\BackupJobGroup;
use App\Services\Scheduling\BackupScheduleCalculator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class BackupJobGroupController extends Controller
{
    public function __construct(private readonly BackupScheduleCalculator $scheduleCalculator) {}

    public function index(): JsonResponse
    {
        return response()->json([
            'data' => BackupJobGroup::withCount('members')->with('notificationChannels')
                ->latest()
                ->get()
                ->map(fn (BackupJobGroup $group) => $this->serializeGroup($group)),
        ]);
    }

    public function store(BackupJobGroupRequest $request): JsonResponse
    {
        $group = BackupJobGroup::create($this->payload($request));
        $this->syncNotificationChannels($group, $request);

        ActivityLog::record('backup_group_created', 'Backup group created via API.', $group, [
            'created_by' => $request->user()->id,
        ]);

        return response()->json(['data' => $this->serializeGroup($group->loadCount('members')->load('notificationChannels'))], 201);
    }

    public function show(BackupJobGroup $backupGroup): JsonResponse
    {
        return response()->json(['data' => $this->serializeGroup($backupGroup->loadCount('members')->load(['notificationChannels', 'members']), withMembers: true)]);
    }

    public function update(BackupJobGroupRequest $request, BackupJobGroup $backupGroup): JsonResponse
    {
        $backupGroup->update($this->payload($request, $backupGroup->status));
        $this->syncNotificationChannels($backupGroup, $request);
        $this->syncMemberSchedules($backupGroup);

        return response()->json(['data' => $this->serializeGroup($backupGroup->fresh()->loadCount('members')->load('notificationChannels'))]);
    }

    public function destroy(BackupJobGroup $backupGroup): JsonResponse
    {
        if ($backupGroup->members()->exists()) {
            throw ValidationException::withMessages([
                'group' => 'Remove or reassign this group\'s jobs before deleting it.',
            ]);
        }

        $backupGroup->delete();

        return response()->json(status: 204);
    }

    public function runNow(Request $request, BackupJobGroup $backupGroup, CreateBackupGroupRun $createBackupGroupRun): JsonResponse
    {
        $run = $createBackupGroupRun->handle($backupGroup, BackupGroupRun::TRIGGER_MANUAL, $request->user());
        RunBackupGroupJob::dispatch($run->id);

        return response()->json(['data' => $run], 202);
    }

    public function pause(Request $request, BackupJobGroup $backupGroup): JsonResponse
    {
        if ($backupGroup->status === BackupJobGroup::STATUS_RUNNING) {
            throw ValidationException::withMessages(['group' => 'A running group cannot be paused.']);
        }

        $backupGroup->forceFill([
            'status' => BackupJobGroup::STATUS_PAUSED,
            'pause_reason' => $request->input('pause_reason', 'Paused manually via API.'),
        ])->save();

        return response()->json(['data' => $this->serializeGroup($backupGroup->fresh()->loadCount('members')->load('notificationChannels'))]);
    }

    public function resume(BackupJobGroup $backupGroup): JsonResponse
    {
        $backupGroup->forceFill([
            'status' => BackupJobGroup::STATUS_ACTIVE,
            'pause_reason' => null,
            'last_error' => null,
            'last_error_at' => null,
            'next_run_at' => $this->scheduleCalculator->nextRunAt($backupGroup->schedule_type, $backupGroup->schedule_config ?? [], null, $backupGroup->timezone),
        ])->save();

        return response()->json(['data' => $this->serializeGroup($backupGroup->fresh()->loadCount('members')->load('notificationChannels'))]);
    }

    public function toggleNotifications(Request $request, BackupJobGroup $backupGroup): JsonResponse
    {
        $backupGroup->forceFill([
            'notifications_enabled' => $request->boolean('notifications_enabled'),
        ])->save();

        return response()->json(['data' => $this->serializeGroup($backupGroup->fresh()->loadCount('members')->load('notificationChannels'))]);
    }

    private function payload(BackupJobGroupRequest $request, ?string $status = BackupJobGroup::STATUS_ACTIVE): array
    {
        $scheduleType = $request->input('schedule_type');
        $scheduleConfig = $request->normalizedScheduleConfig();
        $timezone = $request->filled('timezone') ? $request->input('timezone') : null;

        return [
            'name' => $request->input('name'),
            'schedule_type' => $scheduleType,
            'schedule_config' => $scheduleConfig,
            'cron_expression' => $this->scheduleCalculator->cronExpression($scheduleType, $scheduleConfig),
            'timezone' => $timezone,
            'status' => $status ?: BackupJobGroup::STATUS_ACTIVE,
            'failure_policy' => $request->input('failure_policy', BackupJobGroup::FAILURE_POLICY_CONTINUE),
            'notifications_enabled' => $request->has('notifications_enabled') ? $request->boolean('notifications_enabled') : true,
            'next_run_at' => $this->scheduleCalculator->nextRunAt($scheduleType, $scheduleConfig, null, $timezone),
        ];
    }

    private function syncNotificationChannels(BackupJobGroup $group, BackupJobGroupRequest $request): void
    {
        if ($request->has('notification_channel_ids')) {
            $group->notificationChannels()->sync(
                collect($request->input('notification_channel_ids', []))
                    ->map(fn ($id): int => (int) $id)
                    ->unique()
                    ->values()
                    ->all(),
            );
        }
    }

    private function syncMemberSchedules(BackupJobGroup $group): void
    {
        $group->members()->update([
            'schedule_type' => $group->schedule_type,
            'schedule_config' => json_encode($group->schedule_config),
            'cron_expression' => $group->cron_expression,
            'timezone' => $group->timezone,
            'next_run_at' => null,
        ]);
    }

    private function serializeGroup(BackupJobGroup $group, bool $withMembers = false): array
    {
        $data = [
            ...$group->toArray(),
            'notification_channel_ids' => $group->relationLoaded('notificationChannels')
                ? $group->notificationChannels->pluck('id')->values()->all()
                : $group->notificationChannels()->pluck('notification_channels.id')->values()->all(),
            'members_count' => $group->members_count ?? $group->members()->count(),
            'schedule_summary' => $this->scheduleCalculator->summary($group->schedule_type, $group->schedule_config ?? []),
        ];

        if ($withMembers) {
            $data['members'] = $group->members->map(fn (BackupJob $member): array => [
                'id' => $member->id,
                'name' => $member->name,
                'source_type' => $member->sourceType(),
                'source_label' => $member->sourceName(),
                'backup_destination_id' => $member->backup_destination_id,
                'status' => $member->status,
                'last_success_at' => $member->last_success_at,
                'last_error' => $member->last_error,
            ])->values()->all();
        }

        return $data;
    }
}
