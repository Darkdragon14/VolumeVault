<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Backup\CreateBackupRun;
use App\Http\Controllers\Controller;
use App\Http\Requests\BackupJobRequest;
use App\Jobs\RunBackupJob;
use App\Models\ActivityLog;
use App\Models\BackupJob;
use App\Models\BackupRun;
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
            'data' => BackupJob::with('destination')
                ->latest()
                ->get()
                ->map(fn (BackupJob $job) => $this->serializeJob($job)),
        ]);
    }

    public function store(BackupJobRequest $request): JsonResponse
    {
        $job = BackupJob::create($this->payload($request));

        ActivityLog::record('backup_job_created', 'Backup job created via API.', $job, [
            'created_by' => $request->user()->id,
        ]);

        return response()->json(['data' => $this->serializeJob($job->load('destination'))], 201);
    }

    public function show(BackupJob $backupJob): JsonResponse
    {
        $backupJob->load('destination');

        return response()->json([
            'data' => [
                ...$this->serializeJob($backupJob),
                'runs' => $backupJob->runs()->limit(50)->get(),
            ],
        ]);
    }

    public function update(BackupJobRequest $request, BackupJob $backupJob): JsonResponse
    {
        $backupJob->update($this->payload($request, $backupJob->status));

        return response()->json(['data' => $this->serializeJob($backupJob->fresh('destination'))]);
    }

    public function destroy(BackupJob $backupJob): JsonResponse
    {
        $backupJob->delete();

        return response()->json(status: 204);
    }

    public function runNow(BackupJob $backupJob, CreateBackupRun $createBackupRun): JsonResponse
    {
        $run = $createBackupRun->handle($backupJob, BackupRun::TRIGGER_MANUAL);
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

        return response()->json(['data' => $this->serializeJob($backupJob->fresh('destination'))]);
    }

    public function resume(BackupJob $backupJob): JsonResponse
    {
        $backupJob->forceFill([
            'status' => BackupJob::STATUS_ACTIVE,
            'pause_reason' => null,
            'last_error' => null,
            'next_run_at' => $this->scheduleCalculator->nextRunAt($backupJob->schedule_type, $backupJob->schedule_config ?? []),
        ])->save();

        return response()->json(['data' => $this->serializeJob($backupJob->fresh('destination'))]);
    }

    public function backups(BackupJob $backupJob, ListBackupObjects $listBackupObjects): JsonResponse
    {
        $backupJob->load('destination');

        return response()->json([
            'data' => $listBackupObjects->handle($backupJob->destination),
        ]);
    }

    private function payload(BackupJobRequest $request, ?string $status = BackupJob::STATUS_ACTIVE): array
    {
        $scheduleType = $request->input('schedule_type');
        $scheduleConfig = $request->normalizedScheduleConfig();
        $backupExcludeRegexp = trim((string) $request->input('backup_exclude_regexp', ''));

        return [
            'name' => $request->input('name'),
            'volume_name' => $request->input('volume_name'),
            'backup_destination_id' => $request->integer('backup_destination_id'),
            'schedule_type' => $scheduleType,
            'schedule_config' => $scheduleConfig,
            'cron_expression' => $this->scheduleCalculator->cronExpression($scheduleType, $scheduleConfig),
            'status' => $status ?: BackupJob::STATUS_ACTIVE,
            'next_run_at' => $this->scheduleCalculator->nextRunAt($scheduleType, $scheduleConfig),
            'retention_days' => $request->input('retention_days'),
            'retention_count' => $request->input('retention_count'),
            'backup_exclude_regexp' => $backupExcludeRegexp !== '' ? $backupExcludeRegexp : null,
            'stop_containers_before_backup' => $request->boolean('stop_containers_before_backup'),
        ];
    }

    private function serializeJob(BackupJob $job): array
    {
        return [
            ...$job->toArray(),
            'destination' => $job->destination?->safeForFrontend(),
            'schedule_summary' => $this->scheduleCalculator->summary($job->schedule_type, $job->schedule_config ?? []),
        ];
    }
}
