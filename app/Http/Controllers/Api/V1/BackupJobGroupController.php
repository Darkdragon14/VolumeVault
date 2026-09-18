<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Backup\CreateBackupGroupRun;
use App\Actions\Backup\CreateBackupJobGroup;
use App\Actions\Backup\DeleteBackupJobGroup;
use App\Actions\Backup\ResumeBackupJobGroup;
use App\Actions\Backup\UpdateBackupJobGroup;
use App\Actions\Runs\DispatchQueuedRun;
use App\Http\Controllers\Controller;
use App\Http\Requests\BackupJobGroupRequest;
use App\Models\BackupGroupRun;
use App\Models\BackupJob;
use App\Models\BackupJobGroup;
use App\Services\Agents\AgentExecution;
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

    public function store(BackupJobGroupRequest $request, CreateBackupJobGroup $createBackupJobGroup): JsonResponse
    {
        $group = $createBackupJobGroup->handle(
            $this->payload($request),
            'Backup group created via API.',
            ['created_by' => $request->user()->id],
        );

        return response()->json(['data' => $this->serializeGroup($group->loadCount('members')->load('notificationChannels'))], 201);
    }

    public function show(BackupJobGroup $backupGroup): JsonResponse
    {
        return response()->json(['data' => $this->serializeGroup($backupGroup->loadCount('members')->load(['notificationChannels', 'members.dockerHost']), withMembers: true)]);
    }

    public function update(BackupJobGroupRequest $request, BackupJobGroup $backupGroup, UpdateBackupJobGroup $updateBackupJobGroup): JsonResponse
    {
        $backupGroup = $updateBackupJobGroup->handle($backupGroup, $this->updatePayload($request));

        return response()->json(['data' => $this->serializeGroup($backupGroup->loadCount('members')->load('notificationChannels'))]);
    }

    public function destroy(BackupJobGroup $backupGroup, DeleteBackupJobGroup $deleteBackupJobGroup): JsonResponse
    {
        $deleteBackupJobGroup->handle($backupGroup);

        return response()->json(status: 204);
    }

    public function runNow(Request $request, BackupJobGroup $backupGroup, CreateBackupGroupRun $createBackupGroupRun, DispatchQueuedRun $dispatchQueuedRun): JsonResponse
    {
        $run = $createBackupGroupRun->handle($backupGroup, BackupGroupRun::TRIGGER_MANUAL, $request->user());
        $dispatchQueuedRun->handle($run);

        // Surface the documented aggregate key (null for a freshly created run) so the
        // 202 payload matches the other group-run responses instead of omitting it.
        $run->loadTotalBackupSize();

        return response()->json(['data' => $run], 202);
    }

    public function pause(Request $request, BackupJobGroup $backupGroup): JsonResponse
    {
        // Conditional update so it serializes with RunBackupGroup's ACTIVE→RUNNING
        // flip (see the web controller), preventing a pause-race un-pause.
        $paused = BackupJobGroup::query()
            ->whereKey($backupGroup->id)
            ->where('status', '!=', BackupJobGroup::STATUS_RUNNING)
            ->update([
                'status' => BackupJobGroup::STATUS_PAUSED,
                'pause_reason' => $request->input('pause_reason', 'Paused manually via API.'),
            ]);

        if ($paused === 0) {
            throw ValidationException::withMessages(['group' => 'A running group cannot be paused.']);
        }

        return response()->json(['data' => $this->serializeGroup($backupGroup->fresh()->loadCount('members')->load('notificationChannels'))]);
    }

    public function resume(BackupJobGroup $backupGroup, ResumeBackupJobGroup $resumeBackupJobGroup): JsonResponse
    {
        $resumeBackupJobGroup->handle($backupGroup);

        return response()->json(['data' => $this->serializeGroup($backupGroup->fresh()->loadCount('members')->load('notificationChannels'))]);
    }

    public function toggleNotifications(Request $request, BackupJobGroup $backupGroup, UpdateBackupJobGroup $updateBackupJobGroup): JsonResponse
    {
        // Require the flag explicitly: Request::boolean() defaults a missing key to
        // false, so an empty or mistyped payload would silently disable monitoring.
        $validated = $request->validate([
            'notifications_enabled' => ['required', 'boolean'],
        ]);

        $updateBackupJobGroup->setNotificationsEnabled($backupGroup, (bool) $validated['notifications_enabled']);

        return response()->json(['data' => $this->serializeGroup($backupGroup->fresh()->loadCount('members')->load('notificationChannels'))]);
    }

    private function payload(BackupJobGroupRequest $request, ?string $status = BackupJobGroup::STATUS_ACTIVE, ?BackupJobGroup $group = null): array
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
            'notifications_enabled' => $request->has('notifications_enabled') ? $request->boolean('notifications_enabled') : (bool) ($group?->notifications_enabled ?? true),
            'next_run_at' => $this->scheduleCalculator->nextRunAt($scheduleType, $scheduleConfig, null, $timezone),
            ...($request->has('notification_channel_ids') ? [
                'notification_channel_ids' => $request->input('notification_channel_ids', []),
            ] : []),
        ];
    }

    private function updatePayload(BackupJobGroupRequest $request): array
    {
        $payload = [
            'name' => $request->input('name'),
            'schedule_type' => $request->input('schedule_type'),
            'schedule_config' => $request->normalizedScheduleConfig(),
            'timezone' => $request->filled('timezone') ? $request->input('timezone') : null,
            'failure_policy' => $request->input('failure_policy'),
        ];

        if ($request->has('notifications_enabled')) {
            $payload['notifications_enabled'] = $request->boolean('notifications_enabled');
        }

        if ($request->has('notification_channel_ids')) {
            $payload['notification_channel_ids'] = $request->input('notification_channel_ids');
        }

        return $payload;
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
                'docker_host_id' => $member->docker_host_id,
                'docker_host' => $member->dockerHost ? app(AgentExecution::class)->summary($member->dockerHost) : null,
                'source_type' => $member->sourceType(),
                'source_label' => $member->sourceName(),
                'backup_destination_id' => $member->backup_destination_id,
                'status' => $member->status,
                'last_success_at' => $member->last_success_at,
                'last_error' => $member->last_error,
            ])->values()->all();

            // The show endpoint documents "member jobs and recent group runs"; include
            // the latter so the response matches the OpenAPI contract.
            $data['recent_group_runs'] = $group->groupRuns()->withTotalBackupSize()->limit(10)->get()->map(fn (BackupGroupRun $run): array => [
                'id' => $run->id,
                'status' => $run->status,
                'trigger' => $run->trigger,
                'scheduled_for' => $run->scheduled_for,
                'total_members' => $run->total_members,
                'succeeded_members' => $run->succeeded_members,
                'failed_members' => $run->failed_members,
                'started_at' => $run->started_at,
                'finished_at' => $run->finished_at,
                'duration_seconds' => $run->duration_seconds,
                'total_backup_size_bytes' => $run->total_backup_size_bytes,
            ])->values()->all();
        }

        return $data;
    }
}
