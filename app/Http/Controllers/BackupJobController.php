<?php

namespace App\Http\Controllers;

use App\Actions\Alerts\EnsureAlertRules;
use App\Actions\Backup\CreateBackupRun;
use App\Actions\Docker\ListDockerContainers;
use App\Concerns\PaginateWithPreference;
use App\Enums\AlertType;
use App\Http\Requests\BackupJobRequest;
use App\Jobs\RunBackupJob;
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
use Inertia\Inertia;
use Inertia\Response;

class BackupJobController extends Controller
{
    use PaginateWithPreference;

    public function __construct(
        private readonly BackupScheduleCalculator $scheduleCalculator,
        private readonly EnsureAlertRules $ensureAlertRules,
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

        $query->latest();

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
        $group = $this->resolveGroup($request);
        $job = BackupJob::create($this->payload($request, BackupJob::STATUS_ACTIVE, null, $group));

        // A grouped job delegates notifications to its group; only a standalone
        // job carries its own channels.
        if (! $group) {
            $this->syncNotificationChannels($job, $request, true);
        }

        $this->syncAlertConfigs($job, $request);

        ActivityLog::record('backup_job_created', 'Backup job created.', $job);

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
        $backupJob->load(['destination', 'notificationChannels', 'alertConfigs']);

        return Inertia::render('BackupJobs/Form', [
            ...$this->formProps(),
            'job' => $this->serializeJob($backupJob),
        ]);
    }

    public function update(BackupJobRequest $request, BackupJob $backupJob)
    {
        if ($this->changesSource($request, $backupJob) && $backupJob->hasRunInProgress()) {
            return back()->withErrors(['source_type' => 'This job has a backup run in progress; wait for it to finish before changing its source.']);
        }

        $group = $this->resolveGroup($request);
        $backupJob->update($this->payload($request, $backupJob->status, $backupJob, $group));

        if (! $group) {
            $this->syncNotificationChannels($backupJob, $request, false);
        }

        $this->syncAlertConfigs($backupJob, $request);

        return redirect()->route('backup-jobs.index')->with('success', 'Backup job updated.');
    }

    public function destroy(BackupJob $backupJob)
    {
        if ($backupJob->hasRunInProgress()) {
            return back()->with('error', 'This job has a backup run in progress. Wait for it to finish before deleting it.');
        }

        $backupJob->delete();

        return redirect()->route('backup-jobs.index')->with('success', 'Backup job deleted.');
    }

    public function runNow(Request $request, BackupJob $backupJob, CreateBackupRun $createBackupRun)
    {
        $run = $createBackupRun->handle($backupJob, BackupRun::TRIGGER_MANUAL, $request->user());
        RunBackupJob::dispatch($run->id);

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

    public function resume(BackupJob $backupJob)
    {
        // Atomic conditional update, not read-then-write: a worker can flip the job
        // to running between a stale read and the save. Resuming only from a
        // non-running state (0 rows => running) refuses that, so a stale Resume can
        // never flip running -> active (which would let a pause slip in mid-run).
        // A group member keeps next_run_at null (its group owns the schedule).
        $resumed = BackupJob::query()
            ->whereKey($backupJob->id)
            ->where('status', '!=', BackupJob::STATUS_RUNNING)
            ->update([
                'status' => BackupJob::STATUS_ACTIVE,
                'pause_reason' => null,
                'last_error' => null,
                'last_error_at' => null,
                'next_run_at' => $backupJob->isGroupMember()
                    ? null
                    : $this->scheduleCalculator->nextRunAt($backupJob->schedule_type, $backupJob->schedule_config ?? [], null, $backupJob->timezone),
            ]);

        if ($resumed === 0) {
            return back()->with('error', 'This job is currently running. Wait for the run to finish before resuming it.');
        }

        $this->resumeErroredGroup($backupJob->fresh());

        return back()->with('success', 'Backup job resumed.');
    }

    /**
     * A group member is only dispatched while its group is active. If a member
     * failed and left the group in error, resuming the member alone would leave the
     * group unscheduled — bring the group back to active too. A paused group is left
     * untouched (that is a deliberate group-level action, not a member failure).
     */
    private function resumeErroredGroup(BackupJob $backupJob): void
    {
        $group = $backupJob->isGroupMember() ? $backupJob->group : null;

        if ($group === null) {
            return;
        }

        // Atomic conditional update: only flip from error, so a pause landing
        // between reading the status and saving is not overwritten (a direct group
        // resume is already atomic). Recompute the next slot from now, like a direct
        // group resume, so an overdue next_run_at does not fire the group at once.
        BackupJobGroup::query()
            ->whereKey($group->id)
            ->where('status', BackupJobGroup::STATUS_ERROR)
            ->update([
                'status' => BackupJobGroup::STATUS_ACTIVE,
                'last_error' => null,
                'last_error_at' => null,
                'next_run_at' => $this->scheduleCalculator->nextRunAt($group->schedule_type, $group->schedule_config ?? [], null, $group->timezone),
            ]);
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

        ActivityLog::record('backup_group_created', 'Backup group created.', $group);

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
            'use_custom_alert_settings' => $request->has('use_custom_alert_settings') ? $request->boolean('use_custom_alert_settings') : (bool) ($job?->use_custom_alert_settings ?? false),
            'alert_notifications_enabled' => $request->has('alert_notifications_enabled') ? $request->boolean('alert_notifications_enabled') : (bool) ($job?->alert_notifications_enabled ?? true),
            'retention_days' => $request->input('retention_days'),
            'retention_count' => $request->input('retention_count'),
            'backup_exclude_regexp' => $backupExcludeRegexp !== '' ? $backupExcludeRegexp : null,
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

    private function syncAlertConfigs(BackupJob $job, BackupJobRequest $request): void
    {
        if ($request->has('use_custom_alert_settings') && ! $request->boolean('use_custom_alert_settings')) {
            $job->alertConfigs()->delete();

            return;
        }

        if (! $job->use_custom_alert_settings) {
            return;
        }

        if (! $request->has('alert_configs')) {
            return;
        }

        collect($request->input('alert_configs', []))
            ->each(function (array $config) use ($job): void {
                $job->alertConfigs()->updateOrCreate(
                    ['alert_rule_id' => (int) $config['alert_rule_id']],
                    [
                        'enabled' => array_key_exists('enabled', $config) && $config['enabled'] !== null ? (bool) $config['enabled'] : null,
                        'config' => $this->jobAlertConfigPayload($config['config'] ?? []),
                    ],
                );
            });
    }

    private function jobAlertConfigPayload(array $config): array
    {
        return collect($config)
            ->only([
                'cooldown_minutes',
                'reminder_enabled',
                'backup_too_old_days',
                'job_never_succeeded_min_runs',
                'job_in_error_days',
                'backup_size_out_of_range_min_bytes',
                'backup_size_out_of_range_max_bytes',
            ])
            ->all();
    }
}
