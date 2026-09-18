<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Alerts\SyncBackupJobAlertSettings;
use App\Actions\Backup\ApplyBackupJobSort;
use App\Actions\Backup\BackupJobDeletionRejected;
use App\Actions\Backup\CreateBackupRun;
use App\Actions\Backup\CreateInlineBackupGroup;
use App\Actions\Backup\DeleteBackupJob;
use App\Actions\Backup\GuardManualBackupJobMutation;
use App\Actions\Backup\ResumeBackupJob;
use App\Actions\Backup\RetryDockerLabelMutation;
use App\Actions\Restore\ResolveRestoreDestination;
use App\Actions\Runs\DispatchQueuedRun;
use App\Http\Controllers\Controller;
use App\Http\Requests\BackupJobRequest;
use App\Models\ActivityLog;
use App\Models\BackupJob;
use App\Models\BackupJobGroup;
use App\Models\BackupRun;
use App\Models\DockerHost;
use App\Models\JobAlertConfig;
use App\Models\NotificationChannel;
use App\Services\BackupDestinations\ListBackupObjects;
use App\Services\Docker\LocalDockerExecution;
use App\Services\Scheduling\BackupScheduleCalculator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Throwable;

class BackupJobController extends Controller
{
    public function __construct(
        private readonly BackupScheduleCalculator $scheduleCalculator,
        private readonly ApplyBackupJobSort $applyBackupJobSort,
        private readonly GuardManualBackupJobMutation $guardManualMutation,
        private readonly SyncBackupJobAlertSettings $syncAlertSettings,
        private readonly CreateInlineBackupGroup $createInlineBackupGroup,
        private readonly DeleteBackupJob $deleteBackupJob,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $query = BackupJob::with(['destination', 'notificationChannels', 'alertConfigs']);
        ($this->applyBackupJobSort)($query, $request->query('sort'), $request->query('direction'));

        return response()->json([
            'data' => $query
                ->get()
                ->map(fn (BackupJob $job) => $this->serializeJob($job)),
        ]);
    }

    public function store(BackupJobRequest $request): JsonResponse
    {
        $group = $this->resolveExistingGroup($request);
        $newGroup = $request->isNewGroupMode() ? (array) $request->input('new_group', []) : null;
        $volumeNames = $request->input('source_type') === BackupJob::SOURCE_TYPE_DOCKER_VOLUME ? [$request->input('volume_name')] : [];
        $channelIds = ($group || $newGroup) ? [] : ($request->has('notification_channel_ids')
            ? $this->notificationChannelIds($request)
            : $this->defaultNotificationChannelIds());
        $inlineGroupChannelIds = $newGroup ? $this->createInlineBackupGroup->notificationChannelIds($newGroup) : [];
        $job = $this->guardManualMutation->handle(
            [$request->integer('backup_destination_id')],
            $volumeNames,
            $request->integer('backup_destination_id'),
            $volumeNames[0] ?? null,
            [...$channelIds, ...$inlineGroupChannelIds],
            function ($destinations, $settings, $managedJobs, $volumes, $channels, $groups) use ($group, $newGroup, $channelIds, $request): BackupJob {
                if ($group !== null) {
                    $group = $groups->get($group->id);

                    if ($group === null) {
                        throw ValidationException::withMessages(['backup_job_group_id' => 'The selected backup group no longer exists.']);
                    }
                }

                $this->guardManualMutation->guardGroupMembershipChange(null, $group?->id, $newGroup !== null, $groups);

                $group = $newGroup
                    ? $this->createInlineBackupGroup->handle($newGroup, 'Backup group created via API.')
                    : $group;
                $payload = $this->payload($request, BackupJob::STATUS_ACTIVE, null, $group);
                $job = BackupJob::create($payload);
                $job->notificationChannels()->sync($channelIds);
                $this->syncAlertSettings->handle($job, $request);
                ActivityLog::record('backup_job_created', 'Backup job created via API.', $job, [
                    'created_by' => $request->user()->id,
                ]);

                return $job;
            },
            $group !== null ? [$group->id] : [],
            dockerHostId: $request->integer('docker_host_id'),
        );

        return response()->json(['data' => $this->serializeJob($job->load(['destination', 'notificationChannels', 'alertConfigs']))], 201);
    }

    public function show(BackupJob $backupJob): JsonResponse
    {
        $backupJob->load(['destination', 'notificationChannels', 'alertConfigs']);

        return response()->json([
            'data' => [
                ...$this->serializeJob($backupJob),
                'runs' => $backupJob->runs()->limit(50)->get(),
            ],
        ]);
    }

    public function update(BackupJobRequest $request, BackupJob $backupJob): JsonResponse
    {
        if ($this->guardManualMutation->isReadOnly($backupJob)) {
            throw ValidationException::withMessages(['job' => 'Docker label managed jobs are read-only.']);
        }

        $group = $this->resolveExistingGroup($request);
        $newGroup = $request->isNewGroupMode() ? (array) $request->input('new_group', []) : null;
        $channelIds = ! $group && ! $newGroup && $request->has('notification_channel_ids') ? $this->notificationChannelIds($request) : [];
        $inlineGroupChannelIds = $newGroup ? $this->createInlineBackupGroup->notificationChannelIds($newGroup) : [];

        for ($attempt = 0; $attempt < 3; $attempt++) {
            $current = BackupJob::query()->findOrFail($backupJob->id);

            if ($this->guardManualMutation->isReadOnly($current)) {
                throw ValidationException::withMessages(['job' => 'Docker label managed jobs are read-only.']);
            }

            $destinationIds = [$current->backup_destination_id, $request->integer('backup_destination_id')];
            $newVolumeName = $request->input('source_type') === BackupJob::SOURCE_TYPE_DOCKER_VOLUME ? $request->input('volume_name') : null;
            $volumeNames = collect([$current->volume_name, $newVolumeName])->filter()->all();
            $references = [
                'docker_host_id' => (int) $current->docker_host_id,
                'destination_id' => (int) $current->backup_destination_id,
                'backup_job_group_id' => $current->backup_job_group_id !== null ? (int) $current->backup_job_group_id : null,
                'source_type' => $current->sourceType(),
                'volume_name' => $current->volume_name,
                'host_path' => $current->host_path,
            ];

            try {
                $backupJob = $this->guardManualMutation->handle(
                    $destinationIds,
                    $volumeNames,
                    $request->integer('backup_destination_id'),
                    $newVolumeName,
                    [...$channelIds, ...$inlineGroupChannelIds],
                    function ($destinations, $settings, $managedJobs, $volumes, $channels, $groups, $explicitJobs) use ($backupJob, $request, $group, $newGroup, $channelIds, $references): BackupJob {
                        $lockedJob = $explicitJobs->get($backupJob->id);

                        if ($lockedJob === null) {
                            abort(404);
                        }
                        $lockedReferences = [
                            'docker_host_id' => (int) $lockedJob->docker_host_id,
                            'destination_id' => (int) $lockedJob->backup_destination_id,
                            'backup_job_group_id' => $lockedJob->backup_job_group_id !== null ? (int) $lockedJob->backup_job_group_id : null,
                            'source_type' => $lockedJob->sourceType(),
                            'volume_name' => $lockedJob->volume_name,
                            'host_path' => $lockedJob->host_path,
                        ];

                        if ($this->guardManualMutation->isReadOnly($lockedJob)) {
                            throw ValidationException::withMessages(['job' => 'Docker label managed jobs are read-only.']);
                        }

                        if ($lockedReferences !== $references) {
                            throw new RetryDockerLabelMutation('Backup job references changed before they were locked.');
                        }

                        if ($this->changesSource($request, $lockedJob) && ($lockedJob->hasRunInProgress() || $lockedJob->hasOutstandingFinalizations())) {
                            throw ValidationException::withMessages([
                                'source_type' => 'This job has a backup run in progress; wait for it to finish before changing its source.',
                            ]);
                        }

                        if ($group !== null) {
                            $group = $groups->get($group->id);

                            if ($group === null) {
                                throw ValidationException::withMessages(['backup_job_group_id' => 'The selected backup group no longer exists.']);
                            }
                        }

                        $this->guardManualMutation->guardGroupMembershipChange(
                            $lockedJob,
                            $group?->id,
                            $newGroup !== null,
                            $groups,
                        );

                        $group = $newGroup
                            ? $this->createInlineBackupGroup->handle($newGroup, 'Backup group created via API.')
                            : $group;
                        $lockedJob->update($this->payload($request, $lockedJob->status, $lockedJob, $group));

                        if (! $group && $request->has('notification_channel_ids')) {
                            $lockedJob->notificationChannels()->sync($channelIds);
                        }

                        $this->syncAlertSettings->handle($lockedJob, $request);

                        return $lockedJob;
                    },
                    [$references['backup_job_group_id'], $group?->id],
                    [$backupJob->id],
                    $request->integer('docker_host_id'),
                );
                break;
            } catch (RetryDockerLabelMutation) {
                if ($attempt === 2) {
                    throw new RetryDockerLabelMutation('Backup job references kept changing concurrently.');
                }
            }
        }

        return response()->json(['data' => $this->serializeJob($backupJob->fresh(['destination', 'notificationChannels', 'alertConfigs']))]);
    }

    public function destroy(BackupJob $backupJob): JsonResponse
    {
        try {
            $this->deleteBackupJob->handle($backupJob);
        } catch (BackupJobDeletionRejected $exception) {
            throw ValidationException::withMessages(['job' => $exception->getMessage()]);
        }

        return response()->json(status: 204);
    }

    public function runNow(Request $request, BackupJob $backupJob, CreateBackupRun $createBackupRun, DispatchQueuedRun $dispatchQueuedRun): JsonResponse
    {
        $run = $createBackupRun->handle($backupJob, BackupRun::TRIGGER_MANUAL, $request->user());
        $dispatchQueuedRun->handle($run);

        return response()->json(['data' => $run], 202);
    }

    public function pause(Request $request, BackupJob $backupJob): JsonResponse
    {
        // Conditional update so it serializes with RunBackup's non-paused -> running
        // flip (see the web controller): a pause applied while a run is queued is no
        // longer clobbered when the worker starts.
        $paused = BackupJob::query()
            ->whereKey($backupJob->id)
            ->where('status', '!=', BackupJob::STATUS_RUNNING)
            ->update([
                'status' => BackupJob::STATUS_PAUSED,
                'pause_reason' => $request->input('pause_reason', 'Paused manually via API.'),
            ]);

        if ($paused === 0) {
            throw ValidationException::withMessages(['job' => 'A running job cannot be paused.']);
        }

        return response()->json(['data' => $this->serializeJob($backupJob->fresh(['destination', 'notificationChannels']))]);
    }

    public function resume(BackupJob $backupJob, ResumeBackupJob $resumeBackupJob): JsonResponse
    {
        $resumeBackupJob->handle($backupJob);

        return response()->json(['data' => $this->serializeJob($backupJob->fresh(['destination', 'notificationChannels']))]);
    }

    public function backups(Request $request, BackupJob $backupJob, ListBackupObjects $listBackupObjects, ResolveRestoreDestination $resolveRestoreDestination): JsonResponse
    {
        $validated = $request->validate(['backup_run_id' => ['nullable', 'integer']]);
        $destination = $resolveRestoreDestination->handle(
            $backupJob,
            isset($validated['backup_run_id']) ? (int) $validated['backup_run_id'] : null,
        );
        $run = $resolveRestoreDestination->backupRun(
            $backupJob,
            isset($validated['backup_run_id']) ? (int) $validated['backup_run_id'] : null,
        );

        if (ListBackupObjects::isRunUnverifiable($destination, $run)) {
            return response()->json(['message' => ListBackupObjects::UNVERIFIABLE_RUN_MESSAGE], 422);
        }

        if ($destination->isHostBound() && (int) $destination->docker_host_id !== DockerHost::LOCAL_ID) {
            return response()->json(['data' => $resolveRestoreDestination->knownRemoteBackups($backupJob, $destination, $run)]);
        }

        if ($destination->isHostBound()) {
            LocalDockerExecution::validate();
        }

        try {
            $backups = $listBackupObjects->handleForRun($destination, $run);
        } catch (Throwable) {
            return response()->json(['message' => 'Unable to list backups from this destination.'], 502);
        }

        return response()->json(['data' => $backups]);
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
        return $request->integer('docker_host_id') !== (int) $job->docker_host_id
            || (string) $request->input('source_type') !== (string) $job->source_type
            || (string) $request->input('volume_name') !== (string) $job->volume_name
            || (string) $request->input('host_path') !== (string) $job->host_path;
    }

    private function resolveExistingGroup(BackupJobRequest $request): ?BackupJobGroup
    {
        if (! $request->isGroupMode() || $request->isNewGroupMode()) {
            return null;
        }

        return BackupJobGroup::find($request->integer('backup_job_group_id'));
    }

    private function payload(BackupJobRequest $request, ?string $status = BackupJob::STATUS_ACTIVE, ?BackupJob $job = null, ?BackupJobGroup $group = null): array
    {
        $backupExcludeRegexp = trim((string) $request->input('backup_exclude_regexp', ''));
        $backupFilterMode = $request->input('backup_filter_mode') === BackupJob::FILTER_MODE_INCLUDE
            ? BackupJob::FILTER_MODE_INCLUDE
            : BackupJob::FILTER_MODE_EXCLUDE;
        $backupIncludePaths = trim((string) $request->input('backup_include_paths', ''));
        $backupFilenameTemplate = trim((string) $request->input('backup_filename_template', ''));
        $sourceType = $request->input('source_type', BackupJob::SOURCE_TYPE_DOCKER_VOLUME);
        $isHostPath = $sourceType === BackupJob::SOURCE_TYPE_HOST_PATH;

        $base = [
            'docker_host_id' => $request->integer('docker_host_id'),
            'name' => $request->input('name'),
            'source_type' => $sourceType,
            'volume_name' => $isHostPath ? null : $request->input('volume_name'),
            'host_path' => $isHostPath ? $request->input('host_path') : null,
            'backup_destination_id' => $request->integer('backup_destination_id'),
            'status' => $status ?: BackupJob::STATUS_ACTIVE,
            ...$this->syncAlertSettings->payload($request, $job),
            'retention_days' => $request->input('retention_days'),
            'retention_count' => $request->input('retention_count'),
            'backup_exclude_regexp' => $backupExcludeRegexp !== '' ? $backupExcludeRegexp : null,
            'backup_filter_mode' => $backupFilterMode,
            'backup_include_paths' => $backupIncludePaths !== '' ? $backupIncludePaths : null,
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
        $job->loadMissing('notificationChannels', 'alertConfigs');

        return [
            ...$job->toArray(),
            'destination' => $job->destination ? [...$job->destination->safeForFrontend(), 'docker_host_id' => $job->destination->docker_host_id] : null,
            'notification_channel_ids' => $job->notificationChannels->pluck('id')->values()->all(),
            'alert_configs' => $job->alertConfigs->map(fn (JobAlertConfig $config): array => [
                'alert_rule_id' => $config->alert_rule_id,
                'enabled' => $config->enabled,
                'config' => $config->config ?? [],
            ])->values()->all(),
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
