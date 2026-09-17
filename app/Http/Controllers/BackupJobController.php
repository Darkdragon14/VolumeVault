<?php

namespace App\Http\Controllers;

use App\Actions\Alerts\EnsureAlertRules;
use App\Actions\Alerts\SyncBackupJobAlertSettings;
use App\Actions\Backup\ApplyBackupJobSort;
use App\Actions\Backup\BackupJobDeletionRejected;
use App\Actions\Backup\CreateBackupRun;
use App\Actions\Backup\CreateInlineBackupGroup;
use App\Actions\Backup\DeleteBackupJob;
use App\Actions\Backup\GuardManualBackupJobMutation;
use App\Actions\Backup\ResumeBackupJob;
use App\Actions\Backup\RetryDockerLabelMutation;
use App\Actions\Docker\ListDockerContainers;
use App\Actions\Runs\DispatchQueuedRun;
use App\Concerns\PaginateWithPreference;
use App\Enums\AlertType;
use App\Http\Requests\BackupJobRequest;
use App\Models\ActivityLog;
use App\Models\AlertRule;
use App\Models\BackupDestination;
use App\Models\BackupJob;
use App\Models\BackupJobGroup;
use App\Models\BackupRun;
use App\Models\DockerVolume;
use App\Models\JobAlertConfig;
use App\Models\NotificationChannel;
use App\Services\Scheduling\BackupScheduleCalculator;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class BackupJobController extends Controller
{
    use PaginateWithPreference;

    public function __construct(
        private readonly BackupScheduleCalculator $scheduleCalculator,
        private readonly EnsureAlertRules $ensureAlertRules,
        private readonly ApplyBackupJobSort $applyBackupJobSort,
        private readonly GuardManualBackupJobMutation $guardManualMutation,
        private readonly SyncBackupJobAlertSettings $syncAlertSettings,
        private readonly CreateInlineBackupGroup $createInlineBackupGroup,
        private readonly DeleteBackupJob $deleteBackupJob,
    ) {}

    public function index(Request $request): Response
    {
        $perPage = $this->perPageForRequest($request);

        $query = BackupJob::with(['destination', 'notificationChannels']);

        if ($search = $request->input('search')) {
            $query->where(function ($q) use ($search): void {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhereHas('destination', fn ($q) => $q->where('name', 'like', "%{$search}%"));
            });
        }

        if ($status = $request->input('status')) {
            $query->where('status', $status);
        }

        if ($destination = $request->input('destination')) {
            $query->whereHas('destination', fn ($q) => $q->where('name', $destination));
        }

        ($this->applyBackupJobSort)($query, $request->query('sort'), $request->query('direction'));

        return Inertia::render('BackupJobs/Index', [
            'jobs' => $this->paginateForInertia($query, $perPage, fn (BackupJob $job): array => $this->serializeJob($job)),
            'defaultPerPage' => $request->user()->default_per_page ?? 10,
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('BackupJobs/Form', $this->formProps());
    }

    public function store(BackupJobRequest $request)
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
                    ? $this->createInlineBackupGroup->handle($newGroup, 'Backup group created.')
                    : $group;
                $payload = $this->payload($request, BackupJob::STATUS_ACTIVE, null, $group);
                $job = BackupJob::create($payload);
                $job->notificationChannels()->sync($channelIds);
                $this->syncAlertSettings->handle($job, $request);
                ActivityLog::record('backup_job_created', 'Backup job created.', $job);

                return $job;
            },
            $group !== null ? [$group->id] : [],
        );

        return redirect()->route('backup-jobs.index')->with('success', 'Backup job created.');
    }

    public function show(Request $request, BackupJob $backupJob): Response
    {
        $backupJob->load(['destination', 'notificationChannels']);
        $perPage = $this->perPageForRequest($request);

        return Inertia::render('BackupJobs/Show', [
            'job' => $this->serializeJob($backupJob),
            'lastSuccessfulBackup' => $backupJob->runs()
                ->where('status', BackupRun::STATUS_SUCCESS)
                ->orderByDesc('finished_at')
                ->orderByDesc('created_at')
                ->first(['id', 'finished_at', 'backup_key', 'backup_size_bytes']),
            'runs' => $this->paginateForInertia($backupJob->runs()->with('initiatedBy:id,name,email'), $perPage, null, 'runs_page'),
            'restoreRuns' => $this->paginateForInertia($backupJob->restoreRuns()->with('initiatedBy:id,name,email'), $perPage, null, 'restores_page'),
        ]);
    }

    public function edit(BackupJob $backupJob): Response
    {
        abort_if($this->guardManualMutation->isReadOnly($backupJob), 403, 'Docker label managed jobs are read-only.');

        $backupJob->load(['destination', 'notificationChannels', 'alertConfigs']);

        return Inertia::render('BackupJobs/Form', [
            ...$this->formProps(),
            'job' => $this->serializeJob($backupJob),
        ]);
    }

    public function update(BackupJobRequest $request, BackupJob $backupJob)
    {
        abort_if($this->guardManualMutation->isReadOnly($backupJob), 403, 'Docker label managed jobs are read-only.');
        $group = $this->resolveExistingGroup($request);
        $newGroup = $request->isNewGroupMode() ? (array) $request->input('new_group', []) : null;
        $channelIds = ! $group && ! $newGroup && $request->has('notification_channel_ids') ? $this->notificationChannelIds($request) : [];
        $inlineGroupChannelIds = $newGroup ? $this->createInlineBackupGroup->notificationChannelIds($newGroup) : [];

        for ($attempt = 0; $attempt < 3; $attempt++) {
            $current = BackupJob::query()->findOrFail($backupJob->id);
            abort_if($this->guardManualMutation->isReadOnly($current), 403, 'Docker label managed jobs are read-only.');
            $destinationIds = [$current->backup_destination_id, $request->integer('backup_destination_id')];
            $newVolumeName = $request->input('source_type') === BackupJob::SOURCE_TYPE_DOCKER_VOLUME ? $request->input('volume_name') : null;
            $volumeNames = collect([$current->volume_name, $newVolumeName])->filter()->all();
            $references = [
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
                            'destination_id' => (int) $lockedJob->backup_destination_id,
                            'backup_job_group_id' => $lockedJob->backup_job_group_id !== null ? (int) $lockedJob->backup_job_group_id : null,
                            'source_type' => $lockedJob->sourceType(),
                            'volume_name' => $lockedJob->volume_name,
                            'host_path' => $lockedJob->host_path,
                        ];

                        if ($this->guardManualMutation->isReadOnly($lockedJob)) {
                            abort(403, 'Docker label managed jobs are read-only.');
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
                            ? $this->createInlineBackupGroup->handle($newGroup, 'Backup group created.')
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
                );
                break;
            } catch (RetryDockerLabelMutation) {
                if ($attempt === 2) {
                    throw new RetryDockerLabelMutation('Backup job references kept changing concurrently.');
                }
            }
        }

        return redirect()->route('backup-jobs.index')->with('success', 'Backup job updated.');
    }

    public function destroy(BackupJob $backupJob)
    {
        try {
            $this->deleteBackupJob->handle($backupJob);
        } catch (BackupJobDeletionRejected $exception) {
            if ($exception->reason === BackupJobDeletionRejected::READ_ONLY) {
                abort(403, $exception->getMessage());
            }

            return back()->with('error', $exception->getMessage());
        }

        return redirect()->route('backup-jobs.index')->with('success', 'Backup job deleted.');
    }

    public function runNow(Request $request, BackupJob $backupJob, CreateBackupRun $createBackupRun, DispatchQueuedRun $dispatchQueuedRun)
    {
        $run = $createBackupRun->handle($backupJob, BackupRun::TRIGGER_MANUAL, $request->user());
        $dispatchQueuedRun->handle($run);

        return redirect()->route('backup-runs.show', $run)->with('success', 'Backup run queued.');
    }

    public function pause(Request $request, BackupJob $backupJob)
    {
        // Conditional update, not read-then-write: RunBackup flips the job
        // non-paused -> running with a matching `where status != paused` guard when
        // a queued run starts, so pausing only from a non-running state serializes
        // with it — the worker can no longer clobber a pause applied while the run
        // was still queued.
        $paused = BackupJob::query()
            ->whereKey($backupJob->id)
            ->where('status', '!=', BackupJob::STATUS_RUNNING)
            ->update([
                'status' => BackupJob::STATUS_PAUSED,
                'pause_reason' => $request->input('pause_reason', 'Paused manually.'),
            ]);

        if ($paused === 0) {
            // Flash rather than throw a validation error: the jobs index (where a
            // stale Pause button lives) renders only flash banners, so an error-bag
            // message would leave the user with no visible explanation.
            return back()->with('error', 'A running job cannot be paused.');
        }

        return back()->with('success', 'Backup job paused.');
    }

    public function resume(BackupJob $backupJob, ResumeBackupJob $resumeBackupJob)
    {
        try {
            $resumeBackupJob->handle($backupJob);
        } catch (ValidationException $exception) {
            return back()->with('error', $exception->errors()['job'][0]);
        }

        return back()->with('success', 'Backup job resumed.');
    }

    private function formProps(): array
    {
        $this->ensureAlertRules->handle();

        return [
            'job' => null,
            'volumes' => DockerVolume::where('exists', true)->orderBy('name')->get(['name']),
            'containers' => $this->dockerContainers(),
            'destinations' => BackupDestination::where('is_active', true)->orderBy('name')->get()->map->safeForFrontend(),
            'notificationChannels' => NotificationChannel::with('backupJobs')->orderBy('name')->get()->map->safeForFrontend(),
            'defaultNotificationChannelIds' => $this->defaultNotificationChannelIds(),
            'alertRules' => AlertRule::where('type', '!=', AlertType::DestinationStorageLimit->value)
                ->orderBy('id')
                ->get()
                ->map(fn (AlertRule $rule): array => $this->serializeAlertRule($rule)),
            'timezones' => \DateTimeZone::listIdentifiers(),
            'appTimezone' => config('app.timezone'),
            'groups' => BackupJobGroup::orderBy('name')->get(['id', 'name']),
        ];
    }

    /**
     * Running/known Docker containers offered in the host-path "stop containers"
     * picker. Returns an empty list when Docker is unreachable so the form still
     * loads — the picker simply shows nothing to select.
     */
    private function dockerContainers(): array
    {
        try {
            return app(ListDockerContainers::class)->handle();
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * Resolve the group a job form submission targets: none (standalone job), an
     * existing group, or a freshly created one for the inline "create group" flow.
     */
    /**
     * Whether the request changes the job's backup source (type, volume or host
     * path). A source change is refused while a run is in flight, since RunBackup
     * reloads the job before mounting and would otherwise back up the new source
     * under the lock held for the old one.
     */
    private function changesSource(BackupJobRequest $request, BackupJob $job): bool
    {
        return (string) $request->input('source_type') !== (string) $job->source_type
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

        // Member job: the group owns the schedule + notifications. Its schedule
        // columns mirror the group's (must stay valid/non-null) but next_run_at is
        // null so the standalone dispatcher never picks it up.
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

        // Standalone job (unchanged behaviour).
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
            'destination' => $job->destination?->safeForFrontend(),
            'notification_channel_ids' => $job->notificationChannels->pluck('id')->values()->all(),
            'alert_configs' => $job->alertConfigs->map(fn (JobAlertConfig $config): array => [
                'alert_rule_id' => $config->alert_rule_id,
                'enabled' => $config->enabled,
                'config' => $config->config ?? [],
            ])->values()->all(),
            'schedule_summary' => $this->scheduleCalculator->summary($job->schedule_type, $job->schedule_config ?? []),
        ];
    }

    private function serializeAlertRule(AlertRule $rule): array
    {
        return [
            'id' => $rule->id,
            'type' => $rule->type->value,
            'enabled' => $rule->enabled,
            'config' => $rule->config ?? [],
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
