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
        $job = BackupJob::create($this->payload($request));
        $this->syncNotificationChannels($job, $request, true);
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
            'runs' => $this->paginateForInertia($backupJob->runs(), $perPage, null, 'runs_page'),
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
        $backupJob->update($this->payload($request, $backupJob->status, $backupJob));
        $this->syncNotificationChannels($backupJob, $request, false);
        $this->syncAlertConfigs($backupJob, $request);

        return redirect()->route('backup-jobs.index')->with('success', 'Backup job updated.');
    }

    public function destroy(BackupJob $backupJob)
    {
        $backupJob->delete();

        return redirect()->route('backup-jobs.index')->with('success', 'Backup job deleted.');
    }

    public function runNow(BackupJob $backupJob, CreateBackupRun $createBackupRun)
    {
        $run = $createBackupRun->handle($backupJob, BackupRun::TRIGGER_MANUAL);
        RunBackupJob::dispatch($run->id);

        return redirect()->route('backup-runs.show', $run)->with('success', 'Backup run queued.');
    }

    public function pause(Request $request, BackupJob $backupJob)
    {
        if ($backupJob->status === BackupJob::STATUS_RUNNING) {
            throw ValidationException::withMessages(['job' => 'A running job cannot be paused.']);
        }

        $backupJob->forceFill([
            'status' => BackupJob::STATUS_PAUSED,
            'pause_reason' => $request->input('pause_reason', 'Paused manually.'),
        ])->save();

        return back()->with('success', 'Backup job paused.');
    }

    public function resume(BackupJob $backupJob)
    {
        $backupJob->forceFill([
            'status' => BackupJob::STATUS_ACTIVE,
            'pause_reason' => null,
            'last_error' => null,
            'last_error_at' => null,
            'next_run_at' => $this->scheduleCalculator->nextRunAt($backupJob->schedule_type, $backupJob->schedule_config ?? [], null, $backupJob->timezone),
        ])->save();

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

    private function payload(BackupJobRequest $request, ?string $status = BackupJob::STATUS_ACTIVE, ?BackupJob $job = null): array
    {
        $scheduleType = $request->input('schedule_type');
        $scheduleConfig = $request->normalizedScheduleConfig();
        $timezone = $request->filled('timezone') ? $request->input('timezone') : null;
        $backupExcludeRegexp = trim((string) $request->input('backup_exclude_regexp', ''));
        $backupFilenameTemplate = trim((string) $request->input('backup_filename_template', ''));
        $sourceType = $request->input('source_type', BackupJob::SOURCE_TYPE_DOCKER_VOLUME);
        $isHostPath = $sourceType === BackupJob::SOURCE_TYPE_HOST_PATH;

        return [
            'name' => $request->input('name'),
            'source_type' => $sourceType,
            'volume_name' => $isHostPath ? null : $request->input('volume_name'),
            'host_path' => $isHostPath ? $request->input('host_path') : null,
            'backup_destination_id' => $request->integer('backup_destination_id'),
            'schedule_type' => $scheduleType,
            'schedule_config' => $scheduleConfig,
            'cron_expression' => $this->scheduleCalculator->cronExpression($scheduleType, $scheduleConfig),
            'timezone' => $timezone,
            'status' => $status ?: BackupJob::STATUS_ACTIVE,
            'notifications_enabled' => $request->has('notifications_enabled') ? $request->boolean('notifications_enabled') : (bool) ($job?->notifications_enabled ?? true),
            'use_custom_alert_settings' => $request->has('use_custom_alert_settings') ? $request->boolean('use_custom_alert_settings') : (bool) ($job?->use_custom_alert_settings ?? false),
            'alert_notifications_enabled' => $request->has('alert_notifications_enabled') ? $request->boolean('alert_notifications_enabled') : (bool) ($job?->alert_notifications_enabled ?? true),
            'next_run_at' => $this->scheduleCalculator->nextRunAt($scheduleType, $scheduleConfig, null, $timezone),
            'retention_days' => $request->input('retention_days'),
            'retention_count' => $request->input('retention_count'),
            'backup_exclude_regexp' => $backupExcludeRegexp !== '' ? $backupExcludeRegexp : null,
            'backup_filename_template' => $backupFilenameTemplate !== '' ? $backupFilenameTemplate : null,
            'stop_containers_before_backup' => $request->boolean('stop_containers_before_backup'),
            'stop_container_names' => $isHostPath && $request->boolean('stop_containers_before_backup')
                ? array_values(array_filter((array) $request->input('stop_container_names', [])))
                : null,
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
