<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Backup\CreateBackupRun;
use App\Http\Controllers\Controller;
use App\Http\Requests\BackupJobRequest;
use App\Jobs\RunBackupJob;
use App\Models\ActivityLog;
use App\Models\BackupJob;
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
        $job = BackupJob::create($this->payload($request));
        $this->syncNotificationChannels($job, $request, true);

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
        $backupJob->update($this->payload($request, $backupJob->status, $backupJob));
        $this->syncNotificationChannels($backupJob, $request, false);

        return response()->json(['data' => $this->serializeJob($backupJob->fresh(['destination', 'notificationChannels']))]);
    }

    public function destroy(BackupJob $backupJob): JsonResponse
    {
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
        $backupJob->forceFill([
            'status' => BackupJob::STATUS_ACTIVE,
            'pause_reason' => null,
            'last_error' => null,
            'next_run_at' => $this->scheduleCalculator->nextRunAt($backupJob->schedule_type, $backupJob->schedule_config ?? [], null, $backupJob->timezone),
        ])->save();

        return response()->json(['data' => $this->serializeJob($backupJob->fresh(['destination', 'notificationChannels']))]);
    }

    public function backups(BackupJob $backupJob, ListBackupObjects $listBackupObjects): JsonResponse
    {
        $backupJob->load('destination');

        return response()->json([
            'data' => $listBackupObjects->handle($backupJob->destination),
        ]);
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
