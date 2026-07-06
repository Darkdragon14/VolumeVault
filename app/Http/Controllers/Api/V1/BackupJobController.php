<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Backup\CreateBackupRun;
use App\Http\Controllers\Controller;
use App\Http\Requests\BackupJobRequest;
use App\Jobs\RunBackupJob;
use App\Models\ActivityLog;
use App\Models\BackupJob;
use App\Models\BackupJobGroup;
use App\Models\BackupRun;
use App\Models\NotificationChannel;
use App\Services\BackupDestinations\ListBackupObjects;
use App\Services\Scheduling\BackupScheduleCalculator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class BackupJobController extends Controller
{
    public function __construct(private readonly BackupScheduleCalculator $scheduleCalculator) {}

    public function index(): JsonResponse
    {
        return response()->json([
            'data' => BackupJob::with(['destination', 'notificationChannels'])
                ->latest()
                ->get()
                ->map(fn (BackupJob $job) => $this->serializeJob($job)),
        ]);
    }

    public function store(BackupJobRequest $request): JsonResponse
    {
        $group = $this->resolveGroup($request);
        $job = BackupJob::create($this->payload($request, BackupJob::STATUS_ACTIVE, null, $group));

        if (! $group) {
            $this->syncNotificationChannels($job, $request, true);
        }

        ActivityLog::record('backup_job_created', 'Backup job created via API.', $job, [
            'created_by' => $request->user()->id,
        ]);

        return response()->json(['data' => $this->serializeJob($job->load(['destination', 'notificationChannels']))], 201);
    }

    public function show(BackupJob $backupJob): JsonResponse
    {
        $backupJob->load(['destination', 'notificationChannels']);

        return response()->json([
            'data' => [
                ...$this->serializeJob($backupJob),
                'runs' => $backupJob->runs()->limit(50)->get(),
            ],
        ]);
    }

    public function update(BackupJobRequest $request, BackupJob $backupJob): JsonResponse
    {
        if ($this->changesSource($request, $backupJob) && $backupJob->hasRunInProgress()) {
            throw ValidationException::withMessages([
                'source_type' => 'This job has a backup run in progress; wait for it to finish before changing its source.',
            ]);
        }

        $group = $this->resolveGroup($request);
        $backupJob->update($this->payload($request, $backupJob->status, $backupJob, $group));

        if (! $group) {
            $this->syncNotificationChannels($backupJob, $request, false);
        }

        return response()->json(['data' => $this->serializeJob($backupJob->fresh(['destination', 'notificationChannels']))]);
    }

    public function destroy(BackupJob $backupJob): JsonResponse
    {
        if ($backupJob->hasRunInProgress()) {
            throw ValidationException::withMessages([
                'job' => 'This job has a backup run in progress. Wait for it to finish before deleting it.',
            ]);
        }

        $backupJob->delete();

        return response()->json(status: 204);
    }

    public function runNow(Request $request, BackupJob $backupJob, CreateBackupRun $createBackupRun): JsonResponse
    {
        $run = $createBackupRun->handle($backupJob, BackupRun::TRIGGER_MANUAL, $request->user());
        RunBackupJob::dispatch($run->id);

        return response()->json(['data' => $run], 202);
    }

    public function pause(Request $request, BackupJob $backupJob): JsonResponse
    {
        if ($backupJob->status === BackupJob::STATUS_RUNNING) {
            throw ValidationException::withMessages(['job' => 'A running job cannot be paused.']);
        }

        $backupJob->forceFill([
            'status' => BackupJob::STATUS_PAUSED,
            'pause_reason' => $request->input('pause_reason', 'Paused manually via API.'),
        ])->save();

        return response()->json(['data' => $this->serializeJob($backupJob->fresh(['destination', 'notificationChannels']))]);
    }

    public function resume(BackupJob $backupJob): JsonResponse
    {
        // A running job is owned by its worker; resuming it (running -> active) would
        // let a stale Pause slip in mid-run and be overwritten at the end. Refuse.
        if ($backupJob->status === BackupJob::STATUS_RUNNING) {
            throw ValidationException::withMessages([
                'job' => 'This job is currently running. Wait for the run to finish before resuming it.',
            ]);
        }

        $backupJob->forceFill([
            'status' => BackupJob::STATUS_ACTIVE,
            'pause_reason' => null,
            'last_error' => null,
            'last_error_at' => null,
            // A group member is scheduled by its group, never dispatched standalone,
            // so it must keep next_run_at null (resuming re-enables it in the group).
            'next_run_at' => $backupJob->isGroupMember()
                ? null
                : $this->scheduleCalculator->nextRunAt($backupJob->schedule_type, $backupJob->schedule_config ?? [], null, $backupJob->timezone),
        ])->save();

        // A group member is only dispatched while its group is active. If a member
        // failed and left the group in error, resuming the member alone would leave
        // the group unscheduled — bring the group back to active too. A paused group
        // is left untouched (a deliberate group-level action, not a member failure).
        $group = $backupJob->isGroupMember() ? $backupJob->group : null;

        if ($group && $group->status === BackupJobGroup::STATUS_ERROR) {
            $group->forceFill([
                'status' => BackupJobGroup::STATUS_ACTIVE,
                'last_error' => null,
                'last_error_at' => null,
            ])->save();
        }

        return response()->json(['data' => $this->serializeJob($backupJob->fresh(['destination', 'notificationChannels']))]);
    }

    public function backups(BackupJob $backupJob, ListBackupObjects $listBackupObjects): JsonResponse
    {
        $backupJob->load('destination');

        return response()->json([
            'data' => $listBackupObjects->handle($backupJob->destination),
        ]);
    }

    /**
     * Resolve the group a job request targets: none (standalone), an existing
     * group, or one created inline (planning_mode=group). Mirrors the web flow so
     * the API can attach jobs to groups too.
     */
    /**
     * Whether the request changes the job's backup source (type, volume or host
     * path). Refused while a run is in flight — see the web controller for why.
     */
    private function changesSource(BackupJobRequest $request, BackupJob $job): bool
    {
        return (string) $request->input('source_type') !== (string) $job->source_type
            || (string) $request->input('volume_name') !== (string) $job->volume_name
            || (string) $request->input('host_path') !== (string) $job->host_path;
    }

    private function resolveGroup(BackupJobRequest $request): ?BackupJobGroup
    {
        if (! $request->isGroupMode()) {
            return null;
        }

        if ($request->isNewGroupMode()) {
            return $this->createGroupFromRequest($request);
        }

        return BackupJobGroup::find($request->integer('backup_job_group_id'));
    }

    private function createGroupFromRequest(BackupJobRequest $request): BackupJobGroup
    {
        $newGroup = (array) $request->input('new_group', []);
        $scheduleType = $newGroup['schedule_type'] ?? BackupJobGroup::SCHEDULE_DAILY;
        $scheduleConfig = $this->scheduleCalculator->normalize($scheduleType, (array) ($newGroup['schedule_config'] ?? []));
        $timezone = ! empty($newGroup['timezone']) ? $newGroup['timezone'] : null;

        $group = BackupJobGroup::create([
            'name' => $newGroup['name'] ?? 'Backup group',
            'schedule_type' => $scheduleType,
            'schedule_config' => $scheduleConfig,
            'cron_expression' => $this->scheduleCalculator->cronExpression($scheduleType, $scheduleConfig),
            'timezone' => $timezone,
            'status' => BackupJobGroup::STATUS_ACTIVE,
            'failure_policy' => $newGroup['failure_policy'] ?? BackupJobGroup::FAILURE_POLICY_CONTINUE,
            'notifications_enabled' => array_key_exists('notifications_enabled', $newGroup) ? (bool) $newGroup['notifications_enabled'] : true,
            'next_run_at' => $this->scheduleCalculator->nextRunAt($scheduleType, $scheduleConfig, null, $timezone),
        ]);

        $group->notificationChannels()->sync(
            collect($newGroup['notification_channel_ids'] ?? [])
                ->map(fn ($id): int => (int) $id)
                ->unique()
                ->values()
                ->all(),
        );

        ActivityLog::record('backup_group_created', 'Backup group created via API.', $group);

        return $group;
    }

    private function payload(BackupJobRequest $request, ?string $status = BackupJob::STATUS_ACTIVE, ?BackupJob $job = null, ?BackupJobGroup $group = null): array
    {
        $backupExcludeRegexp = trim((string) $request->input('backup_exclude_regexp', ''));
        $backupFilenameTemplate = trim((string) $request->input('backup_filename_template', ''));
        $sourceType = $request->input('source_type', BackupJob::SOURCE_TYPE_DOCKER_VOLUME);
        $isHostPath = $sourceType === BackupJob::SOURCE_TYPE_HOST_PATH;

        $base = [
            'name' => $request->input('name'),
            'source_type' => $sourceType,
            'volume_name' => $isHostPath ? null : $request->input('volume_name'),
            'host_path' => $isHostPath ? $request->input('host_path') : null,
            'backup_destination_id' => $request->integer('backup_destination_id'),
            'status' => $status ?: BackupJob::STATUS_ACTIVE,
            'retention_days' => $request->input('retention_days'),
            'retention_count' => $request->input('retention_count'),
            'backup_exclude_regexp' => $backupExcludeRegexp !== '' ? $backupExcludeRegexp : null,
            'backup_filename_template' => $backupFilenameTemplate !== '' ? $backupFilenameTemplate : null,
            'stop_containers_before_backup' => $request->boolean('stop_containers_before_backup'),
            'stop_container_names' => $isHostPath && $request->boolean('stop_containers_before_backup')
                ? array_values(array_filter((array) $request->input('stop_container_names', [])))
                : null,
        ];

        // Member job: the group owns the schedule and notifications.
        if ($group) {
            return [
                ...$base,
                'backup_job_group_id' => $group->id,
                'schedule_type' => $group->schedule_type,
                'schedule_config' => $group->schedule_config,
                'cron_expression' => $group->cron_expression,
                'timezone' => $group->timezone,
                'next_run_at' => null,
                'notifications_enabled' => false,
            ];
        }

        $scheduleType = $request->input('schedule_type');
        $scheduleConfig = $request->normalizedScheduleConfig();
        $timezone = $request->filled('timezone') ? $request->input('timezone') : null;

        return [
            ...$base,
            'backup_job_group_id' => null,
            'schedule_type' => $scheduleType,
            'schedule_config' => $scheduleConfig,
            'cron_expression' => $this->scheduleCalculator->cronExpression($scheduleType, $scheduleConfig),
            'timezone' => $timezone,
            'next_run_at' => $this->scheduleCalculator->nextRunAt($scheduleType, $scheduleConfig, null, $timezone),
            'notifications_enabled' => $request->has('notifications_enabled') ? $request->boolean('notifications_enabled') : (bool) ($job?->notifications_enabled ?? true),
        ];
    }

    private function serializeJob(BackupJob $job): array
    {
        $job->loadMissing('notificationChannels');

        return [
            ...$job->toArray(),
            'destination' => $job->destination?->safeForFrontend(),
            'notification_channel_ids' => $job->notificationChannels->pluck('id')->values()->all(),
            'schedule_summary' => $this->scheduleCalculator->summary($job->schedule_type, $job->schedule_config ?? []),
        ];
    }

    private function syncNotificationChannels(BackupJob $job, BackupJobRequest $request, bool $creating): void
    {
        if ($request->has('notification_channel_ids')) {
            $job->notificationChannels()->sync($this->notificationChannelIds($request));

            return;
        }

        if ($creating) {
            $job->notificationChannels()->sync($this->defaultNotificationChannelIds());
        }
    }

    private function notificationChannelIds(BackupJobRequest $request): array
    {
        return collect($request->input('notification_channel_ids', []))
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->values()
            ->all();
    }

    private function defaultNotificationChannelIds(): array
    {
        $id = NotificationChannel::where('is_default', true)->orderBy('id')->value('id');

        return $id ? [(int) $id] : [];
    }
}
